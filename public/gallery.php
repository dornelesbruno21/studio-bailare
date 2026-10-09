<?php
if(!defined('BAILARE_PIX')){http_response_code(404);exit;}
function galleryDir(): string {global $privateDir;return $privateDir.'/gallery';}
function galleryFile(array $d): ?string {if(!preg_match('/^[a-f0-9]{32}\.jpg$/D',$d['file'] ?? ''))return null;return galleryDir().'/'.$d['file'];}
function galleryPublic(): array {
    global $pdo;$items=[];foreach(records($pdo,'gallery') as $r)if(($r['data']['status'] ?? '')==='published')$items[]=['id'=>$r['id'],'caption'=>$r['data']['caption'] ?? '','url'=>'admin.php?photo='.$r['id']];return $items;
}
function galleryServe(PDO $pdo): never {
    $r=findRecord($pdo,'gallery',(int)($_GET['photo'] ?? 0));$f=$r?galleryFile($r['data']):null;
    if(!$r||($r['data']['status'] ?? '')!=='published'||!$f||!is_file($f)){http_response_code(404);exit;}
    header('Content-Type: image/jpeg');header('Content-Length: '.filesize($f));header('Content-Disposition: inline; filename="foto.jpg"');readfile($f);exit;
}
function galleryEncode(string $source,string $dest): void {
    if(!extension_loaded('gd'))throw new RuntimeException('Ative a extensão GD no PHP da hospedagem antes de enviar fotos.');
    $size=filesize($source);if(!$size||$size>5*1024*1024)throw new RuntimeException('Envie uma foto de até 5 MB.');
    $info=@getimagesize($source);if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]>6000||$info[1]>6000||$info[0]*$info[1]>12000000)throw new RuntimeException('Use JPEG, PNG ou WebP de até 12 megapixels e 6000 pixels por lado.');
    $img=@imagecreatefromstring(file_get_contents($source));if(!$img)throw new RuntimeException('Foto inválida.');
    $scale=min(1,2000/max($info[0],$info[1]));$out=imagecreatetruecolor(max(1,(int)($info[0]*$scale)),max(1,(int)($info[1]*$scale)));
    try{imagefill($out,0,0,imagecolorallocate($out,255,255,255));imagecopyresampled($out,$img,0,0,0,0,imagesx($out),imagesy($out),$info[0],$info[1]);if(!imagejpeg($out,$dest,85))throw new RuntimeException('Não foi possível salvar a imagem.');chmod($dest,0600);}finally{imagedestroy($img);imagedestroy($out);}
}
function galleryAdmin(PDO $pdo,array $user): never {
    if(!allowed($user,'gallery')){http_response_code(403);pixPage('Acesso restrito');notice('Galeria não liberada para este perfil.');pixFinish();}
    $message='';$created=null;
    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            checkCsrf();$action=value('action',30,true);
            if($action==='upload_photo'){
                if(value('publish_consent',1)!=='1')throw new RuntimeException('Confirme a autorização para publicar a foto no site público.');
                $caption=value('caption',200,true);$upload=$_FILES['photo'] ?? [];
                if(($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name'] ?? ''))throw new RuntimeException('Selecione uma foto válida, respeitando o limite de upload da hospedagem.');
                $dir=galleryDir();if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Não foi possível preparar a pasta privada de fotos.');
                $name=bin2hex(random_bytes(16)).'.jpg';$created=$dir.'/'.$name;galleryEncode($upload['tmp_name'],$created);
                $pdo->beginTransaction();$pdo->prepare('INSERT INTO bailare_records(kind,data,created_at,updated_at) VALUES(?,?,?,?)')->execute(['gallery',json_encode(['caption'=>$caption,'file'=>$name,'status'=>'published'],JSON_UNESCAPED_UNICODE),date('c'),date('c')]);$id=(int)$pdo->lastInsertId();audit($pdo,(int)$user['id'],'publish_photo',$id);$pdo->commit();$created=null;$message='Foto publicada na galeria do site.';
            }elseif($action==='hide_photo'){
                $id=(int)value('photo_id',20,true);$r=findRecord($pdo,'gallery',$id);if(!$r)throw new RuntimeException('Foto não encontrada.');$d=$r['data'];$d['status']='draft';
                $pdo->beginTransaction();$pdo->prepare('UPDATE bailare_records SET data=?,updated_at=? WHERE id=? AND kind=?')->execute([json_encode($d,JSON_UNESCAPED_UNICODE),date('c'),$id,'gallery']);audit($pdo,(int)$user['id'],'hide_photo',$id);$pdo->commit();$message='Foto retirada do site; arquivo preservado no armazenamento privado.';
            }else throw new RuntimeException('Operação inválida.');
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($created&&is_file($created))unlink($created);$message=$e instanceof PDOException?'Não foi possível salvar a foto no banco.':$e->getMessage();}
    pixPage('Galeria de fotos');echo '<a href="admin.php">← Administração</a><p>As fotos publicadas são visíveis a qualquer visitante. Publique somente imagens autorizadas, especialmente quando houver crianças. A imagem é convertida em JPEG e os metadados originais são removidos.</p>';if($message)notice($message);
    echo '<form method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="action" value="upload_photo">';input('caption','Legenda / descrição da foto','text','',true,'maxlength="200"');echo '<label>Foto (JPEG, PNG ou WebP; até 5 MB e 12 megapixels)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></label><label><input type="checkbox" name="publish_consent" value="1" required> Tenho autorização para publicar esta imagem no site público.</label><button class="primary">Publicar foto</button></form>';
    foreach(records($pdo,'gallery') as $r){$d=$r['data'];echo '<section class="card"><h2>'.h($d['caption']).'</h2>';if($d['status']==='published')echo '<img src="admin.php?photo='.$r['id'].'" alt="'.h($d['caption']).'" width="240"><form method="post">'.csrf().'<input type="hidden" name="action" value="hide_photo"><input type="hidden" name="photo_id" value="'.$r['id'].'"><button class="secondary">Retirar foto do site</button></form>';else echo '<p>Retirada do site. Para publicar novamente, envie a foto pelo formulário.</p>';echo '</section>';}
    pixFinish();
}
