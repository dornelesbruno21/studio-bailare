<?php
if(!defined('BAILARE_PIX')){http_response_code(404);exit;}
function backupFolder(): string {global $privateDir;$dir=$privateDir.'/backups';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('BACKUP_DIR');return $dir;}
function backupState(): array {$file=backupFolder().'/status.json';return is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];}
function backupWriteState(array $data): void {$file=backupFolder().'/status.json';$tmp=tempnam(backupFolder(),'state-');chmod($tmp,0600);if(file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$file))throw new RuntimeException('BACKUP_STATE');}
function backupRun(PDO $pdo,bool $force=false): array {
    global $privateDir;$dir=backupFolder();$lock=fopen($dir.'/backup.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Já existe um backup em execução.');
    $zip=null;$temp=null;$dump=null;$start=time();$s=backupState();$oldMask=umask(0077);
    try{
        if(!$force && time()-(int)($s['last_success'] ?? 0)<15*86400)return $s;
        if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão ZIP no PHP da hospedagem.');
        if(count(glob($dir.'/bailare-*.zip'))>=24)throw new RuntimeException('Já existem 24 cópias. Baixe e organize os backups antigos antes de continuar. Nenhum arquivo foi apagado.');
        $guard=function()use($start){if(time()-$start>40)throw new RuntimeException('Backup excedeu o tempo seguro da hospedagem.');};
        $dump=tempnam($dir,'db-');chmod($dump,0600);$out=fopen($dump,'wb');$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $tables=$mysql?$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN):$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $tables=array_values(array_filter($tables,fn($name)=>preg_match('/^bailare_[a-z_]+$/D',$name)));
        if($mysql)$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        try{
            fwrite($out,"-- Studio Bailare: restaure em banco vazio e privado.\n");
            foreach($tables as $table){$guard();$schema=$mysql?$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]:$pdo->query("SELECT sql FROM sqlite_master WHERE name=".$pdo->quote($table))->fetchColumn();fwrite($out,$schema.";\n");
                $q=$pdo->query('SELECT * FROM `'.$table.'`');foreach($q as $row){$guard();$columns=array_map(fn($k)=>'`'.$k.'`',array_keys($row));$values=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($row));if(fwrite($out,'INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n")===false)throw new RuntimeException('Sem espaço para exportar banco.');if(ftell($out)>50*1024*1024)throw new RuntimeException('Banco excede 50 MB para esta rotina.');}
            }$pdo->commit();
        }finally{if($pdo->inTransaction())$pdo->rollBack();fclose($out);}
        $name='bailare-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';$temp=$dir.'/'.$name.'.partial';$zip=new ZipArchive();if($zip->open($temp,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Não foi possível criar ZIP.');
        $zip->addFile($dump,'database.sql');$total=filesize($dump);
        $add=function(string $file,string $dest)use(&$total,$zip,$guard){$guard();if(is_link($file))throw new RuntimeException('Link simbólico não permitido no backup.');if(!is_file($file))return;$total+=filesize($file);if($total>200*1024*1024)throw new RuntimeException('Backup excede 200 MB para esta rotina.');if(!$zip->addFile($file,$dest))throw new RuntimeException('Falha ao adicionar arquivo.');};
        foreach(['admin.php','pix.php','tickets.php','capacity.php','gallery.php','backup.php','backup-cron.php','demo.php','remember.php','scanner.js','jsQR.js','jsQR-LICENSE.txt','admin.css','pix.js','tickets.js','qrcode.js','qrcode-LICENSE.txt','index.html','.htaccess'] as $file)$add(__DIR__.'/'.$file,'www/'.$file);
        foreach(['mailer','assets'] as $sub)if(is_dir(__DIR__.'/'.$sub)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/'.$sub,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$add($file->getPathname(),'www/'.$sub.'/'.str_replace('\\','/',substr($file->getPathname(),strlen(__DIR__.'/'.$sub)+1)));}
        foreach(['config.php','smtp.json','backup-cron.json'] as $file)$add($privateDir.'/'.$file,'private/'.$file);
        if(is_dir(galleryDir()))foreach(glob(galleryDir().'/*.jpg') as $file)$add($file,'private/gallery/'.basename($file));
        $zip->addFromString('README.txt',"Backup privado Studio Bailare. Contém dados pessoais, senhas e chaves de ingressos. Não publique este ZIP.\nRestaure database.sql em banco vazio compatível. Arquivos www ficam na pasta pública; private deve ficar fora dela. Ajuste config.php ao ambiente. Teste em ambiente isolado antes de substituir produção.\nFotos são copiadas após o snapshot do banco; evite editar a galeria durante o backup.\n");
        if(!$zip->close())throw new RuntimeException('Falha ao finalizar ZIP.');$zip=null;$guard();
        $verify=new ZipArchive();if($verify->open($temp,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('ZIP inválido.');if($verify->locateName('database.sql')===false){$verify->close();throw new RuntimeException('Banco ausente no ZIP.');}$verify->close();
        if(!rename($temp,$dir.'/'.$name))throw new RuntimeException('Falha ao concluir arquivo.');$temp=null;chmod($dir.'/'.$name,0600);
        $s=['last_success'=>time(),'last_file'=>$name,'last_attempt'=>time(),'result'=>'success'];backupWriteState($s);return $s;
    }catch(Throwable $e){$s['last_attempt']=time();$s['result']='failed';backupWriteState($s);throw $e;}
    finally{if($zip)$zip->close();if($temp&&is_file($temp))unlink($temp);if($dump&&is_file($dump))unlink($dump);flock($lock,LOCK_UN);fclose($lock);umask($oldMask);}
}
function backupAdmin(PDO $pdo,array $user): never {
    if(!in_array($user['role'],['owner','manager'],true)){http_response_code(403);pixPage('Acesso restrito');notice('Backups são restritos à Gestão.');pixFinish();}
    global $privateDir;$message='';
    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            checkCsrf();$action=value('action',30,true);
            if($action==='download_backup'){
                $name=value('file',100,true);if(!preg_match('/^bailare-\d{8}-\d{6}-[a-f0-9]{6}\.zip$/D',$name)||!is_file(backupFolder().'/'.$name))throw new RuntimeException('Backup não encontrado.');
                audit($pdo,(int)$user['id'],'download_backup',0);header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');readfile(backupFolder().'/'.$name);exit;
            }elseif($action==='run_backup'){$s=backupRun($pdo,true);audit($pdo,(int)$user['id'],'run_backup',0);$message='Backup concluído. Baixe uma cópia e guarde fora da hospedagem.';}
            elseif($action==='save_cron'){
                $key=value('cron_header',200,true);if(strlen($key)<24||!preg_match('/^[A-Za-z0-9_-]+$/D',$key))throw new RuntimeException('Copie o código X-CRON-AUTH do agendador da hospedagem.');
                $file=$privateDir.'/backup-cron.json';$tmp=tempnam($privateDir,'cron-');chmod($tmp,0600);file_put_contents($tmp,json_encode(['hash'=>hash('sha256',$key)]),LOCK_EX);if(!rename($tmp,$file))throw new RuntimeException('Não foi possível salvar o agendamento.');audit($pdo,(int)$user['id'],'save_backup_cron',0);$message='Código salvo. Ainda é necessário cadastrar e testar a tarefa diária no painel hospedagem.';
            }else throw new RuntimeException('Operação inválida.');
        }
    }catch(Throwable $e){$message=$e instanceof PDOException?'Falha ao exportar o banco.':($e instanceof RuntimeException?$e->getMessage():'Falha no backup; confira extensões e permissões da hospedagem.');}
    pixPage('Backups privados');echo '<a href="admin.php">← Administração</a>';if($message)notice($message);
    try{$s=backupState();echo '<p>Último backup: '.(!empty($s['last_success'])?h(date('d/m/Y H:i',(int)$s['last_success'])):'ainda não executado').'. Última tentativa: '.h($s['result'] ?? 'nenhuma').'</p>';}catch(Throwable $e){notice('Pasta privada de backup indisponível.');}
    echo '<p>Inclui banco do aplicativo, arquivos do site, fotos e configurações privadas. Os ZIPs contêm senhas e dados pessoais: nunca os publique. Guarde também uma cópia fora da hospedagem. Até 24 cópias, sem apagar automaticamente as anteriores; depois disso a rotina para e exige organizar o armazenamento. Limites desta rotina: 200 MB de arquivos, 50 MB de SQL e 40 segundos.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="run_backup"><button class="primary">Gerar backup agora</button></form><h2>Automático a cada 15 dias</h2><p>No Cronjob da hospedagem, programe uma chamada diária às 03:00 para <code>https://studio.example.invalid/backup-cron.php</code>. A rotina só cria uma cópia quando passam 15 dias desde o último sucesso. Não depende do seu computador. Copie o código do cabeçalho X-CRON-AUTH do painel abaixo; não envie pelo chat.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="save_cron">';input('cron_header','Código X-CRON-AUTH da hospedagem','password','',true,'maxlength="200" autocomplete="new-password"');echo '<button class="secondary">Salvar código do agendador</button></form><p>Salvar o código não cria a tarefa na hospedagem. Verifique o histórico de execução no painel da hospedagem.</p>';
    try{foreach(array_reverse(glob(backupFolder().'/bailare-*.zip')) as $f)echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="download_backup"><input type="hidden" name="file" value="'.h(basename($f)).'"><button class="secondary">Baixar '.h(basename($f)).'</button></form>';}catch(Throwable $e){}pixFinish();
}
