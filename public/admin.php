<?php
declare(strict_types=1);
// Studio Bailare — painel PHP 8.2. Segredos ficam fora da pasta pública.
ini_set('display_errors', '0');
date_default_timezone_set('America/Sao_Paulo');
$local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$local && ($_SERVER['HTTPS'] ?? '') !== 'on' && (int)($_SERVER['SERVER_PORT'] ?? 0) !== 443) {
    header('Location: https://studio.example.invalid' . ($_SERVER['REQUEST_URI'] ?? '/admin.php'), true, 302); exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_name('bailare_admin');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>!$local, 'httponly'=>true, 'samesite'=>'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$privateDir = $local && getenv('BAILARE_PRIVATE_DIR') ? getenv('BAILARE_PRIVATE_DIR') : dirname(__DIR__) . '/bailare-private';
$configPath = $privateDir . '/config.php';
const SETUP_HASH = '__SETUP_HASH__';
const SETUP_EXPIRES = 0; // Substituído pelo empacotador de publicação.
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $url='admin.php'): never { header('Location: ' . $url, true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function checkCsrf(): void { if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('Sua sessão expirou. Recarregue a página e tente novamente.'); } }
function value(string $name, int $max=200, bool $required=false): string {
    $v = trim((string)($_POST[$name] ?? ''));
    if (($required && $v==='') || strlen($v)>$max) throw new RuntimeException('Preencha os campos obrigatórios e respeite o tamanho máximo.');
    return $v;
}
function passwordInput(string $name='password'): string {
    $p = (string)($_POST[$name] ?? '');
    if (strlen($p)<12 || strlen($p)>72) throw new RuntimeException('A senha deve ter entre 12 e 72 caracteres.');
    return $p;
}
function connect(array $cfg): PDO {
    $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['password'] ?? null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function schema(PDO $pdo): void {
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_users (id $id, username VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, permissions TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(30) NOT NULL)".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_records (id $id, kind VARCHAR(20) NOT NULL, data TEXT NOT NULL, updated_at VARCHAR(30) NOT NULL, created_at VARCHAR(30) NOT NULL)".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_attempts (bucket VARCHAR(64) PRIMARY KEY, failures INTEGER NOT NULL, locked_until INTEGER NOT NULL)".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_audit (id $id, user_id INTEGER NOT NULL, action VARCHAR(60) NOT NULL, subject_id INTEGER NOT NULL, created_at VARCHAR(30) NOT NULL)".$tail);
}
function audit(PDO $pdo, int $uid, string $action, int $id): void { $pdo->prepare('INSERT INTO bailare_audit (user_id,action,subject_id,created_at) VALUES (?,?,?,?)')->execute([$uid,$action,$id,date('c')]); }
function pageStart(string $title): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.h($title).' · Bailare</title><link rel="stylesheet" href="admin.css?v=20260928"></head><body><header class="bar"><a class="brand" href="/">Studio <em>Bailare</em></a><a href="/">Voltar ao site ↗</a></header>'; }
function pageEnd(): void { echo '<footer class="foot">Studio Bailare · Área restrita da equipe</footer></body></html>'; }
function input(string $name, string $label, string $type='text', $val='', bool $required=false, string $extra=''): void {
    echo '<label>'.h($label).($required?' <span aria-label="obrigatório">*</span>':'').'<input name="'.h($name).'" type="'.h($type).'" value="'.h($val).'" '.($required?'required ':'').$extra.'></label>';
}
function selectField(string $name, string $label, array $options, $current): void {
    echo '<label>'.h($label).'<select name="'.h($name).'">';
    foreach ($options as $k=>$v) echo '<option value="'.h($k).'" '.((string)$current===(string)$k?'selected':'').'>'.h($v).'</option>';
    echo '</select></label>';
}
function textArea(string $name, string $label, string $val, int $max=4000): void { echo '<label class="wide">'.h($label).'<textarea name="'.h($name).'" maxlength="'.$max.'" rows="4">'.h($val).'</textarea></label>'; }
function notice(string $message, string $type='error'): void { echo '<p role="alert" class="notice '.h($type).'">'.h($message).'</p>'; }
function jsonReply($data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

// Antes da instalação, nenhuma rota expõe dados ou permite criar uma conta sem o código único.
if (!is_file($configPath)) {
    if (($_GET['api'] ?? '')==='public') jsonReply(['events'=>[], 'classes'=>[], 'reminders'=>[], 'contact'=>null, 'ready'=>false]);
    $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            checkCsrf();
            if (($_POST['action'] ?? '')==='unlock_setup') {
                if (time()>SETUP_EXPIRES || !hash_equals(SETUP_HASH, hash('sha256', value('setup_code', 100, true)))) throw new RuntimeException('Código de instalação inválido ou expirado.');
                session_regenerate_id(true); $_SESSION['setup_until']=time()+3600; go('admin.php?setup=1');
            }
            if (($_POST['action'] ?? '')==='install' && ($_SESSION['setup_until'] ?? 0)>time()) {
                $pass=passwordInput();
                if (!hash_equals($pass, (string)($_POST['password_confirm'] ?? ''))) throw new RuntimeException('As senhas de administrador não coincidem.');
                $username=strtolower(value('username',80,true));
                if (!preg_match('/^[a-z0-9._-]{3,80}$/',$username)) throw new RuntimeException('Use um login com pelo menos 3 letras, números, ponto ou traço.');
                $dbPass=(string)($_POST['db_password'] ?? '');
                if ($dbPass==='') throw new RuntimeException('Informe a senha do banco criado na hospedagem.');
                $cfg=['dsn'=>'mysql:host=localhost;dbname=bailare_demo;charset=utf8mb4','user'=>'bailare_demo','password'=>$dbPass];
                if (!is_dir($privateDir) && !mkdir($privateDir,0700,true)) throw new RuntimeException('Não foi possível preparar a pasta privada.');
                $lock=fopen($privateDir.'/install.lock','c');
                if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) throw new RuntimeException('Uma instalação já está em andamento. Aguarde.');
                if (is_file($configPath)) go();
                try { $pdo=connect($cfg); } catch (Throwable $e) { throw new RuntimeException('Não foi possível conectar ao banco. Confira a senha; os demais dados já estão configurados.'); }
                schema($pdo);
                if ((int)$pdo->query('SELECT COUNT(*) FROM bailare_users')->fetchColumn()>0) throw new RuntimeException('Já existem usuários neste banco. A configuração precisa ser recuperada antes de continuar.');
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO bailare_users(username,name,password_hash,role,permissions,active,created_at) VALUES(?,?,?,?,?,1,?)')->execute([$username,'Gestão principal',password_hash($pass,PASSWORD_DEFAULT),'owner','[]',date('c')]);
                $file=fopen($configPath,'x');
                if (!$file) throw new RuntimeException('Não foi possível salvar a configuração privada.');
                $configContents="<?php\nreturn ".var_export($cfg,true).";\n";
                if (fwrite($file,$configContents)!==strlen($configContents)) { fclose($file); unlink($configPath); throw new RuntimeException('Falha ao gravar a configuração.'); }
                fclose($file); chmod($configPath,0600); $pdo->commit();
                flock($lock,LOCK_UN); fclose($lock);
                unset($_SESSION['setup_until']); $_SESSION['flash']='Instalação concluída. Entre com o login e a senha que acabou de criar.'; go();
            }
        } catch (Throwable $e) { if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); $error=($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Não foi possível concluir a instalação. Verifique a configuração do banco.'; }
    }
    pageStart('Ativar administração');
    echo '<main class="auth"><p class="eyebrow">Administração</p><h1>Vamos abrir as portas.</h1>';
    if ($error) notice($error);
    if (($_SESSION['setup_until'] ?? 0)>time()) {
        echo '<p>Conecte o banco de dados e crie seu acesso de Gestão. Depois você poderá cadastrar os logins da equipe e liberar as áreas de cada pessoa da Secretaria.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="install"><div class="hint">Banco: bailare_demo<br>Servidor: localhost</div>';
        input('db_password','Senha do banco de dados','password','',true,'autocomplete="off"');
        input('username','Seu login de administrador','text','admin',true,'autocomplete="username" maxlength="80"');
        input('password','Crie sua senha de administrador','password','',true,'minlength="12" maxlength="72" autocomplete="new-password"');
        input('password_confirm','Repita a senha de administrador','password','',true,'minlength="12" maxlength="72" autocomplete="new-password"');
        echo '<p class="hint">Use pelo menos 12 caracteres. A senha do painel pode ser diferente da senha do banco.</p><button class="primary">Ativar administração</button></form>';
    } else {
        echo '<p>O acesso administrativo aguarda a ativação pelo responsável do estúdio.</p><details><summary>Sou o responsável pela instalação</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="unlock_setup">';
        input('setup_code','Código de instalação','password','',true,'autocomplete="off"'); echo '<button class="primary">Continuar instalação</button></form></details>';
    }
    echo '</main>'; pageEnd(); exit;
}
try { $pdo=connect(require $configPath); } catch (Throwable $e) {
    if (isset($_GET['api'])) jsonReply(['error'=>'Serviço temporariamente indisponível.'],503);
    http_response_code(503); pageStart('Indisponível'); echo '<main class="auth"><h1>Voltamos em instantes.</h1><p>Não foi possível acessar os dados agora. Tente novamente mais tarde.</p></main>'; pageEnd(); exit;
}
define('BAILARE_PIX',true);
require __DIR__.'/pix.php';
require __DIR__.'/tickets.php';
require __DIR__.'/capacity.php';
require __DIR__.'/gallery.php';
require __DIR__.'/backup.php';
require __DIR__.'/demo.php';
require __DIR__.'/remember.php';
if(isset($_GET['demo']))demoPublic($pdo);
if(isset($_GET['photo']))galleryServe($pdo);
if(isset($_GET['ingresso']))ticketPublic($pdo);
if(isset($_GET['ticket'])||isset($_GET['pedido']))pixPublic($pdo);
$roles=['secretary'=>'Secretaria · acesso personalizado','manager'=>'Gestão · acesso completo','owner'=>'Gestão principal · acesso completo'];
$sections=['students'=>'Alunos','teachers'=>'Professores','classes'=>'Aulas','events'=>'Eventos e ingressos','reminders'=>'Lembretes','contact'=>'Contato','gallery'=>'Galeria de fotos','checkin'=>'Portaria · validar ingressos','users'=>'Acessos da equipe','backups'=>'Backups'];
function allowed(array $user,string $kind): bool { return in_array($user['role'],['owner','manager'],true) || ($user['role']==='secretary' && !in_array($kind,['users','backups'],true) && in_array($kind,json_decode($user['permissions'] ?? '[]',true) ?: [],true)); }
function records(PDO $pdo,string $kind): array { $q=$pdo->prepare('SELECT id,data,updated_at FROM bailare_records WHERE kind=? ORDER BY id DESC');$q->execute([$kind]); return array_values(array_filter(array_map(fn($r)=>['id'=>(int)$r['id'],'data'=>json_decode($r['data'],true),'updated_at'=>$r['updated_at']],$q->fetchAll()),fn($r)=>($r['data']['status'] ?? '')!=='deleted')); }
function findRecord(PDO $pdo,string $kind,int $id): ?array { $q=$pdo->prepare('SELECT id,data FROM bailare_records WHERE id=? AND kind=?');$q->execute([$id,$kind]);$r=$q->fetch();if(!$r)return null;$data=json_decode($r['data'],true);return ($data['status'] ?? '')==='deleted' ? null : ['id'=>(int)$r['id'],'data'=>$data]; }
function publicData(PDO $pdo): array {
    $out=['ready'=>true,'events'=>[],'classes'=>[],'reminders'=>[],'contact'=>null,'gallery'=>galleryPublic()];
    $fields=['events'=>['title','starts','second_date','second_time','ticket_validity','location','description','price','ticket_url','ticket_state'],'classes'=>['title','weekday','time','location','modality','start_date','end_date'],'reminders'=>['title','date','description'],'contact'=>['phone','email','instagram']];
    foreach ($fields as $kind=>$keys) {
        foreach (records($pdo,$kind) as $r) {
            if (($r['data']['status'] ?? '')!=='published') continue;
            $item=['id'=>$r['id']]; foreach($keys as $key) $item[$key]=$r['data'][$key] ?? '';
            if($kind==='events')$item['ticket_validity']='choose_date';
            if ($kind==='events' && ($item['price']==='' || ($item['ticket_state']==='link' && $item['ticket_url']===''))) $item['ticket_state']='closed';
            if ($kind==='contact') { $out['contact']=$item; break; } else $out[$kind][]=$item;
        }
    }
    usort($out['events'],fn($a,$b)=>strcmp($a['starts'],$b['starts']));
    usort($out['classes'],fn($a,$b)=>[$a['weekday'],$a['time']]<=>[$b['weekday'],$b['time']]);
    usort($out['reminders'],fn($a,$b)=>strcmp($a['date'],$b['date'])); return $out;
}
if (($_GET['api'] ?? '')==='public') jsonReply(publicData($pdo));
$user=null;
if (isset($_SESSION['user_id'])) {
    if (time()-($_SESSION['last_seen'] ?? 0)>1800 || (isset($_SESSION['remember_until']) && $_SESSION['remember_until']<=time())) unset($_SESSION['user_id']);
    else { $q=$pdo->prepare('SELECT id,username,name,role,permissions,active,password_hash FROM bailare_users WHERE id=?');$q->execute([$_SESSION['user_id']]);$user=$q->fetch() ?: null; if (!$user || !$user['active'] || !hash_equals($_SESSION['auth_stamp'] ?? '', hash('sha256',$user['password_hash']))) {unset($_SESSION['user_id']);$user=null;} else $_SESSION['last_seen']=time(); }
}
if(!$user)$user=rememberRestore($pdo);
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='logout' && hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) {
    rememberForget($pdo);$_SESSION=[];session_destroy();setcookie(session_name(),'',time()-3600,'/','',!$local,true);go();
}
$error='';
if (!$user && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        checkCsrf();
        if (($_POST['action'] ?? '')!=='login') throw new RuntimeException('Entre novamente para continuar.');
        $username=strtolower(value('username',80,true)); $password=(string)($_POST['password'] ?? '');
        $buckets=[hash('sha256','user:'.$username),hash('sha256','ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'))];
        foreach ($buckets as $bucket) { $q=$pdo->prepare('SELECT failures,locked_until FROM bailare_attempts WHERE bucket=?');$q->execute([$bucket]);$attempt=$q->fetch();if($attempt && (int)$attempt['locked_until']>time()) throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.'); }
        $q=$pdo->prepare('SELECT * FROM bailare_users WHERE username=?');$q->execute([$username]);$found=$q->fetch();
        $valid=password_verify($password,$found['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$found || !$found['active'] || !$valid) {
            foreach ($buckets as $bucket) {
                try {$pdo->prepare('INSERT INTO bailare_attempts(bucket,failures,locked_until) VALUES(?,0,0)')->execute([$bucket]);} catch(PDOException $e) { if(!in_array($e->getCode(),['23000','23505'],true)) throw $e; }
                $pdo->prepare('UPDATE bailare_attempts SET failures=CASE WHEN locked_until>0 AND locked_until<? THEN 1 ELSE failures+1 END, locked_until=0 WHERE bucket=?')->execute([time(),$bucket]);
                $pdo->prepare('UPDATE bailare_attempts SET locked_until=? WHERE bucket=? AND failures>=8')->execute([time()+900,$bucket]);
            }
            throw new RuntimeException('Login ou senha incorretos.');
        }
        $pdo->prepare('DELETE FROM bailare_attempts WHERE bucket=?')->execute([$buckets[0]]);
        rememberForget($pdo);unset($_SESSION['remember_until']);
        if(($_POST['remember'] ?? '')==='1')rememberIssue($pdo,$found);
        session_regenerate_id(true); $_SESSION['user_id']=(int)$found['id']; $_SESSION['auth_stamp']=hash('sha256',$found['password_hash']); $_SESSION['last_seen']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32)); audit($pdo,(int)$found['id'],'login',(int)$found['id']);
        $scanKey=isset($_GET['checkdemo'])?'checkdemo':'checkin';$scan=(string)($_GET[$scanKey] ?? '');go(preg_match('/^[a-f0-9]{64}$/D',$scan)?'admin.php?'.$scanKey.'='.$scan:'admin.php');
    } catch(Throwable $e) { $error=($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Não foi possível entrar agora.'; }
}
if (!$user) {
    if (isset($_GET['api'])) jsonReply(['error'=>'Entre para acessar estes dados.'],401);
    pageStart('Entrar'); echo '<main class="auth"><p class="eyebrow">Equipe Bailare</p><h1>Seu espaço de trabalho.</h1><p>Entre com seu login. As ferramentas disponíveis dependem do perfil atribuído à sua conta.</p>';
    if(isset($_SESSION['flash'])) {notice($_SESSION['flash'],'success');unset($_SESSION['flash']);} if($error) notice($error);
    echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="login">'; input('username','Login','text','',true,'autocomplete="username" maxlength="80"');input('password','Senha','password','',true,'autocomplete="current-password" maxlength="72"');echo '<label class="check-label"><input type="checkbox" name="remember" value="1"> Manter conectado neste aparelho por 7 dias</label><p class="hint">Use apenas em aparelho da equipe, protegido por senha. Ao terminar em aparelho compartilhado, clique em Sair. Para lembrar a senha, use o gerenciador do navegador; o site não a salva no aparelho.</p><button class="primary">Entrar</button></form><p class="hint">Precisa de acesso ou esqueceu a senha? Fale com a Gestão do estúdio.</p><div class="profile-notes"><p><b>Secretaria</b><br>Áreas liberadas pela Gestão.</p><p><b>Gestão</b><br>Acesso completo e controle das permissões.</p></div></main>';pageEnd();exit;
}
if(isset($_GET['checkin'])||($_GET['section'] ?? '')==='checkin')ticketCheckin($pdo,$user);
if(isset($_GET['mailsettings']))ticketMailAdmin($pdo,$user);
if(isset($_GET['capacity']))capacityAdmin($pdo,$user);
if(isset($_GET['demo_send']))demoAdmin($pdo,$user);
if(isset($_GET['checkdemo']))demoCheck($pdo,$user);
if(($_GET['section'] ?? '')==='backups')backupAdmin($pdo,$user);
if(($_GET['section'] ?? '')==='gallery')galleryAdmin($pdo,$user);
if(isset($_GET['payments']))pixAdmin($pdo,$user);
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='logout' && hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) {$_SESSION=[];session_destroy();setcookie(session_name(),'',time()-3600,'/','',!$local,true);go();}
$available=array_keys(array_filter($sections,fn($label,$key)=>allowed($user,$key),ARRAY_FILTER_USE_BOTH));
$section=(string)($_GET['section'] ?? ($available[0] ?? ''));
if (!$available && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='logout') {checkCsrf();$_SESSION=[];session_destroy();go();}
if (!isset($sections[$section]) || !allowed($user,$section)) { http_response_code(403);pageStart('Acesso restrito');echo '<main class="auth"><h1>Acesso restrito.</h1><p>Seu perfil não tem permissão para esta área. A Gestão pode liberar suas ferramentas em Acessos da equipe.</p><a class="button" href="admin.php">Voltar ao painel</a><form method="post">'.csrf().'<input type="hidden" name="action" value="logout"><button class="secondary">Sair da conta</button></form></main>';pageEnd();exit; }
if (isset($_GET['api'])) jsonReply(['error'=>'Rota não encontrada.'],404);
function dateValue(string $field,bool $required=false,bool $time=false): string {
    $v=value($field,30,$required);if($v==='')return '';$fmt=$time?'Y-m-d\TH:i':'Y-m-d';$d=DateTimeImmutable::createFromFormat('!'.$fmt,$v);if(!$d || $d->format($fmt)!==$v)throw new RuntimeException('Informe uma data válida.');return $v;
}
function emailValue(string $field): string { $v=value($field,200);if($v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');return $v; }
function httpsUrl(string $field): string { $v=value($field,1000);if($v!=='' && (!filter_var($v,FILTER_VALIDATE_URL) || strtolower((string)parse_url($v,PHP_URL_SCHEME))!=='https'))throw new RuntimeException('Use um endereço completo começando com https://.');return $v; }
function recordInput(string $kind, PDO $pdo): array {
    $status=value('status',20,true);$options=in_array($kind,['students','teachers'])?['active','inactive']:['draft','published'];if(!in_array($status,$options,true))throw new RuntimeException('Situação inválida.');$d=['status'=>$status];
    if ($kind==='students') {
        $d+=['name'=>value('name',160,true),'birthdate'=>dateValue('birthdate'),'guardian'=>value('guardian',160,true),'phone'=>value('phone',40,true),'email'=>emailValue('email'),'class'=>value('class',160),'teacher_id'=>value('teacher_id',20)];
        if ($d['birthdate']>date('Y-m-d'))throw new RuntimeException('A data de nascimento não pode estar no futuro.');
        if($d['teacher_id']!=='' && (!ctype_digit($d['teacher_id']) || !findRecord($pdo,'teachers',(int)$d['teacher_id'])))throw new RuntimeException('Selecione um professor válido.');
    } elseif($kind==='teachers') $d+=['name'=>value('name',160,true),'email'=>emailValue('email'),'phone'=>value('phone',40),'modalities'=>value('modalities',200)];
    elseif($kind==='classes') { $d+=['title'=>value('title',160,true),'weekday'=>value('weekday',1,true),'time'=>value('time',5,true),'location'=>value('location',200),'modality'=>value('modality',80),'start_date'=>dateValue('start_date',true),'end_date'=>dateValue('end_date',true)];if(!preg_match('/^[0-6]$/',$d['weekday']) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$d['time']) || $d['end_date']<$d['start_date'])throw new RuntimeException('Confira o dia, horário e o período das aulas.'); }
    elseif($kind==='events') {
        $price=value('price',20);if($price!==''){ $price=str_replace(',','.',$price);if(!preg_match('/^\d{1,6}(\.\d{1,2})?$/',$price)||(float)$price<=0)throw new RuntimeException('Informe um preço maior que zero ou deixe em branco.');$price=number_format((float)$price,2,'.',''); }
        $eventDate=dateValue('event_date');$eventTime=value('event_time',5);if($eventTime!=='' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$eventTime))throw new RuntimeException('Informe um horário válido.');if($eventTime!=='' && $eventDate==='')throw new RuntimeException('Informe a data do evento para definir o horário.');
        $secondDate=dateValue('second_date');$secondTime=value('second_time',5);$validity=value('ticket_validity',20) ?: 'single_date';
        if(!in_array($validity,['single_date','both_dates','choose_date'],true))throw new RuntimeException('Validade do ingresso inválida.');
        if($secondDate!=='' && ($eventDate==='' || $secondDate<=$eventDate))throw new RuntimeException('A segunda data deve ser posterior à primeira data.');
        if($secondTime!=='' && ($secondDate==='' || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$secondTime)))throw new RuntimeException('Confira a segunda data e seu horário.');
        if($validity==='both_dates' && $secondDate==='')throw new RuntimeException('Informe as duas datas para um ingresso válido nos dois dias.');
        $d+=['title'=>value('title',160,true),'starts'=>$eventDate.($eventTime!==''?'T'.$eventTime:''),'second_date'=>$secondDate,'second_time'=>$secondTime,'ticket_validity'=>'choose_date','location'=>value('location',200),'description'=>value('description',4000),'price'=>$price,'ticket_state'=>value('ticket_state',20,true),'ticket_url'=>httpsUrl('ticket_url')];
        if(!in_array($d['ticket_state'],['closed','link','pix'],true))throw new RuntimeException('Situação dos ingressos inválida.');
        if($d['ticket_state']==='pix' && $price==='')throw new RuntimeException('Defina o preço antes de abrir as vendas por Pix.');
        if($d['ticket_state']==='link' && ($price==='' || $d['ticket_url']===''))throw new RuntimeException('Para abrir vendas por link, informe preço e endereço da bilheteria. Caso contrário, mantenha as vendas fechadas.');
    } elseif($kind==='reminders') $d+=['title'=>value('title',160,true),'date'=>dateValue('date'),'description'=>value('description',2000)];
    elseif($kind==='contact') $d+=['phone'=>value('phone',40),'email'=>emailValue('email'),'instagram'=>httpsUrl('instagram')];
    return $d;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        checkCsrf();$action=value('action',40,true);
        if($action==='delete_record' && in_array($section,['students','teachers','classes','reminders','contact'],true)) {
            if(!in_array($user['role'],['owner','manager'],true))throw new RuntimeException('Somente a Gestão pode excluir definitivamente.');
            if(value('confirm_delete',1)!=='1')throw new RuntimeException('Confirme a exclusão definitiva.');
            $id=(int)($_POST['id'] ?? 0);$pdo->beginTransaction();
            $pdo->prepare('UPDATE bailare_records SET updated_at=updated_at WHERE id=? AND kind=?')->execute([$id,$section]);
            if(!findRecord($pdo,$section,$id))throw new RuntimeException('Registro não encontrado.');
            if($section==='teachers')foreach(records($pdo,'students') as $student)if((int)($student['data']['teacher_id'] ?? 0)===$id)throw new RuntimeException('Desvincule este professor dos alunos antes de excluir.');
            $pdo->prepare('DELETE FROM bailare_records WHERE id=? AND kind=?')->execute([$id,$section]);
            audit($pdo,(int)$user['id'],'delete_'.$section,$id);$pdo->commit();$_SESSION['flash']='Registro excluído definitivamente do cadastro. Cópias de segurança anteriores não foram alteradas.';go('admin.php?section='.$section);
        }
        if($action==='logout'){$_SESSION=[];session_destroy();setcookie(session_name(),'',time()-3600,'/','',!$local,true);go();}
        if($action==='delete_event' && $section==='events') {
            if(value('confirm_delete',1)!=='1')throw new RuntimeException('Marque a confirmação antes de excluir o evento.');
            $id=(int)($_POST['id'] ?? 0);$existing=findRecord($pdo,'events',$id);
            if(!$existing)throw new RuntimeException('Evento não encontrado ou já excluído.');
            $data=$existing['data'];$data['status']='deleted';$data['ticket_state']='closed';$data['deleted_at']=date('c');$data['deleted_by']=(int)$user['id'];
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE bailare_records SET data=?,updated_at=? WHERE id=? AND kind=?')->execute([json_encode($data,JSON_UNESCAPED_UNICODE),date('c'),$id,'events']);
            audit($pdo,(int)$user['id'],'delete_event',$id);$pdo->commit();
            $_SESSION['flash']='Evento excluído da lista e do site. Pedidos e pagamentos anteriores foram preservados.';go('admin.php?section=events');
        }
        if($action==='save_record' && $section!=='users') {
            $id=(int)($_POST['id'] ?? 0);$data=recordInput($section,$pdo);$existing=$id?findRecord($pdo,$section,$id):null;if($id && !$existing)throw new RuntimeException('Registro não encontrado.');
            if($section==='contact' && !$id && records($pdo,'contact'))throw new RuntimeException('Edite o contato já cadastrado.');
            $pdo->beginTransaction();
            if($id) $pdo->prepare('UPDATE bailare_records SET data=?,updated_at=? WHERE id=? AND kind=?')->execute([json_encode($data,JSON_UNESCAPED_UNICODE),date('c'),$id,$section]);
            else {$pdo->prepare('INSERT INTO bailare_records(kind,data,updated_at,created_at) VALUES(?,?,?,?)')->execute([$section,json_encode($data,JSON_UNESCAPED_UNICODE),date('c'),date('c')]);$id=(int)$pdo->lastInsertId();}
            audit($pdo,(int)$user['id'],'save_'.$section,$id);$pdo->commit();$_SESSION['flash']='Registro salvo com sucesso.';go('admin.php?section='.$section);
        }
        if($action==='save_user' && $section==='users' && in_array($user['role'],['owner','manager'],true)) {
            rememberSchema($pdo);
            $id=(int)($_POST['id'] ?? 0);$username=strtolower(value('username',80,true));$name=value('name',160,true);$role=value('role',20,true);$active=value('active',1,true)==='1'?1:0;
            if(!isset($roles[$role]) || !preg_match('/^[a-z0-9._-]{3,80}$/',$username))throw new RuntimeException('Login ou perfil inválido.');
            $q=$pdo->prepare('SELECT * FROM bailare_users WHERE id=?');$q->execute([$id]);$old=$q->fetch();if($id && !$old)throw new RuntimeException('Conta não encontrada.');
            if($id===(int)$user['id'] && (!in_array($role,['owner','manager'],true) || !$active))throw new RuntimeException('Você não pode desativar nem reduzir o acesso da própria conta.');
            $requested=$_POST['permissions'] ?? [];if(!is_array($requested))throw new RuntimeException('Permissões inválidas.');$permissions=json_encode(array_values(array_intersect($requested,['students','teachers','classes','events','reminders','contact','gallery','checkin'])));
            $hash=$old['password_hash'] ?? '';
            if(!$id || ($_POST['password'] ?? '')!=='')$hash=password_hash(passwordInput(),PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            if($id)$pdo->prepare('UPDATE bailare_users SET username=?,name=?,role=?,permissions=?,active=?,password_hash=? WHERE id=?')->execute([$username,$name,$role,$permissions,$active,$hash,$id]);
            else {$pdo->prepare('INSERT INTO bailare_users(username,name,role,permissions,active,password_hash,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$username,$name,$role,$permissions,$active,$hash,date('c')]);$id=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM bailare_remember WHERE user_id=?')->execute([$id]);
            audit($pdo,(int)$user['id'],'save_user',$id);$pdo->commit();$_SESSION['flash']='Acesso salvo com sucesso. Dispositivos lembrados desta conta foram revogados.';go('admin.php?section=users');
        }
        throw new RuntimeException('Operação não permitida para este perfil.');
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof PDOException ? 'Não foi possível salvar. Verifique se o login já está em uso e tente novamente.' : ($e instanceof RuntimeException?$e->getMessage():'Não foi possível salvar agora.');}
}
pageStart($sections[$section]);
echo '<div class="layout"><aside class="sidebar"><p class="eyebrow">'.h($roles[$user['role']]).'</p><h2>'.h($user['name']).'</h2><nav aria-label="Administração">';
foreach($sections as $key=>$label)if(allowed($user,$key))echo '<a class="'.($section===$key?'active':'').'" href="admin.php?section='.$key.'">'.h($label).'<span>→</span></a>';
if(in_array($user['role'],['owner','manager'],true))echo '<a href="admin.php?payments=1">Pedidos e pagamentos<span>→</span></a>';
echo '</nav><form method="post">'.csrf().'<input type="hidden" name="action" value="logout"><button class="secondary">Sair da conta</button></form></aside><main class="workspace"><p class="eyebrow">Painel do estúdio</p><h1>'.h($sections[$section]).'</h1>';
$intros=['students'=>'Cadastre os alunos e os contatos de seus responsáveis. Estes dados são privados.','teachers'=>'Organize os contatos e modalidades da equipe. Cadastrar um professor não cria um login automaticamente.','events'=>'Prepare os eventos e controle a divulgação dos ingressos. Preço vazio mantém as vendas fechadas.','classes'=>'Cadastre os horários que devem aparecer no calendário público.','reminders'=>'Publique avisos para as famílias e a equipe.','contact'=>'Defina os canais públicos de atendimento do estúdio.','users'=>'Crie logins individuais e escolha o que cada pessoa pode acessar.'];
echo '<p class="lead">'.h($intros[$section]).'</p>';
if(isset($_SESSION['flash'])){notice($_SESSION['flash'],'success');unset($_SESSION['flash']);}if($error)notice($error);
$editId=(int)($_GET['edit'] ?? 0);$editing=$editId>0 || isset($_GET['new']) || $error;
if($section==='users') {
    if($editing){$q=$pdo->prepare('SELECT id,name,username,role,permissions,active FROM bailare_users WHERE id=?');$q->execute([$editId]);$d=$q->fetch() ?: [];if($error)$d=array_merge($d,$_POST);echo '<section class="card"><h2>'.($editId?'Editar acesso':'Novo acesso').'</h2><form method="post" class="form-grid">'.csrf().'<input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="'.$editId.'">';input('name','Nome','text',$d['name'] ?? '',true,'maxlength="160"');input('username','Login','text',$d['username'] ?? '',true,'pattern="[a-z0-9._-]{3,80}" maxlength="80" autocomplete="off"');selectField('role','Perfil',$roles,$d['role'] ?? 'secretary');selectField('active','Situação',['1'=>'Ativo','0'=>'Desativado'],$d['active'] ?? 1);input('password',$editId?'Nova senha (deixe vazio para manter)':'Senha inicial','password','',!$editId,'minlength="12" maxlength="72" autocomplete="new-password"');
        $perms=is_array($d['permissions'] ?? null)?$d['permissions']:json_decode($d['permissions'] ?? '["students","teachers"]',true);
        echo '<fieldset class="wide permission-list"><legend>Áreas liberadas para a Secretaria</legend><p>Marque as áreas que esta pessoa pode consultar e editar. A Gestão tem acesso completo, independentemente destas opções.</p>';
        foreach($sections as $key=>$label)if(!in_array($key,['users','backups'],true))echo '<label><input type="checkbox" name="permissions[]" value="'.$key.'" '.(in_array($key,$perms ?: [],true)?'checked':'').'> '.h($label).'</label>';
        echo '</fieldset><div class="actions wide"><button class="primary">Salvar acesso</button><a class="button secondary" href="admin.php?section=users">Cancelar</a></div></form></section>';}
    else {echo '<a class="button primary" href="admin.php?section=users&new=1">+ Novo acesso</a><div class="record-list">';foreach($pdo->query('SELECT id,name,username,role,active FROM bailare_users ORDER BY id') as $r)echo '<article class="record"><div><h3>'.h($r['name']).'</h3><p>'.h($r['username']).' · '.h($roles[$r['role']]).'</p><span class="badge">'.($r['active']?'Ativo':'Desativado').'</span></div><a class="button secondary" href="admin.php?section=users&edit='.(int)$r['id'].'">Editar</a></article>';echo '</div>';}
} else {
    $items=records($pdo,$section);
    if($editing) {
        $rec=$editId?findRecord($pdo,$section,$editId):null;$d=$rec['data'] ?? [];if($error)$d=array_merge($d,$_POST);
        echo '<section class="card"><h2>'.($editId?'Editar registro':'Novo registro').'</h2><form method="post" class="form-grid">'.csrf().'<input type="hidden" name="action" value="save_record"><input type="hidden" name="id" value="'.$editId.'">';
        if($section==='students') {input('name','Nome do aluno','text',$d['name'] ?? '',true,'maxlength="160"');input('birthdate','Data de nascimento','date',$d['birthdate'] ?? '');input('guardian','Nome do responsável','text',$d['guardian'] ?? '',true,'maxlength="160"');input('phone','Telefone do responsável','tel',$d['phone'] ?? '',true,'maxlength="40"');input('email','E-mail do responsável','email',$d['email'] ?? '',false,'maxlength="200"');input('class','Turma / modalidade','text',$d['class'] ?? '',false,'maxlength="160"');$opts=[''=>'Sem professor vinculado'];foreach(records($pdo,'teachers') as $t)$opts[$t['id']]=$t['data']['name'];selectField('teacher_id','Professor',$opts,$d['teacher_id'] ?? '');}
        elseif($section==='teachers'){input('name','Nome do professor','text',$d['name'] ?? '',true,'maxlength="160"');input('phone','Telefone','tel',$d['phone'] ?? '',false,'maxlength="40"');input('email','E-mail','email',$d['email'] ?? '',false,'maxlength="200"');input('modalities','Modalidades','text',$d['modalities'] ?? '',false,'maxlength="200"');}
        elseif($section==='classes'){input('title','Nome da aula / turma','text',$d['title'] ?? '',true,'maxlength="160"');selectField('weekday','Dia da semana',['1'=>'Segunda','2'=>'Terça','3'=>'Quarta','4'=>'Quinta','5'=>'Sexta','6'=>'Sábado','0'=>'Domingo'],$d['weekday'] ?? (isset($_GET['date'])?(string)date('w',strtotime((string)$_GET['date']) ?: time()):'1'));input('time','Horário','time',$d['time'] ?? '',true);input('location','Sala / local','text',$d['location'] ?? '',false,'maxlength="200"');input('modality','Modalidade','text',$d['modality'] ?? '',false,'maxlength="80"');input('start_date','Primeira data do período','date',$d['start_date'] ?? $_GET['date'] ?? date('Y-m-d'),true);input('end_date','Última data do período','date',$d['end_date'] ?? date('Y').'-12-31',true);echo '<p class="hint wide">A aula aparecerá toda semana no dia selecionado, dentro desse período. O calendário já inclui todos os dias até dezembro.</p>';}
elseif($section==='events'){input('title','Nome do evento','text',$d['title'] ?? '',true,'maxlength="160"');input('event_date','Data (opcional)','date',$d['event_date'] ?? substr($d['starts'] ?? ($_GET['date'] ?? ''),0,10));input('event_time','Horário (opcional)','time',$d['event_time'] ?? substr($d['starts'] ?? '',11,5));input('second_date','Segunda data (opcional)','date',$d['second_date'] ?? '');input('second_time','Horário da segunda data (opcional)','time',$d['second_time'] ?? '');echo '<input type="hidden" name="ticket_validity" value="choose_date">';echo '<p class="hint wide">O preço é por pessoa e por dia. O comprador escolhe uma data para todos os ingressos do pedido. Para participar das duas apresentações, precisa fazer uma compra para cada dia. Pedidos antigos mantêm a validade registrada na compra.</p>';input('location','Local','text',$d['location'] ?? '',false,'maxlength="200"');input('price','Preço do ingresso (R$) — opcional','number',$d['price'] ?? '',false,'min="0.01" max="999999.99" step="0.01"');selectField('ticket_state','Ingressos',['closed'=>'Vendas fechadas / a definir','link'=>'Vendas por link externo','pix'=>'Pix Pix — conferência manual'],$d['ticket_state'] ?? 'closed');input('ticket_url','Link da bilheteria (opcional)','url',$d['ticket_url'] ?? '',false,'placeholder="https://" maxlength="1000"');textArea('description','Descrição',$d['description'] ?? '');echo '<p class="hint wide">Deixe o preço vazio para manter as vendas fechadas. Antes de abrir Pix, a Gestão deve acessar Pedidos e pagamentos para preparar o banco. O comprador escolhe a quantidade e paga o total em um único Pix. A confirmação é manual pela Gestão.</p>';}
        elseif($section==='reminders'){input('title','Título','text',$d['title'] ?? '',true,'maxlength="160"');input('date','Data (opcional)','date',$d['date'] ?? $_GET['date'] ?? '');textArea('description','Mensagem',$d['description'] ?? '',2000);}
        elseif($section==='contact'){input('phone','WhatsApp com DDD','tel',$d['phone'] ?? '',false,'maxlength="40"');input('email','E-mail público','email',$d['email'] ?? '',false,'maxlength="200"');input('instagram','Link do Instagram','url',$d['instagram'] ?? '',false,'placeholder="https://" maxlength="1000"');}
        selectField('status','Situação',in_array($section,['students','teachers'])?['active'=>'Ativo','inactive'=>'Inativo']:['draft'=>'Rascunho — não aparece no site','published'=>'Publicado — visível no site'],$d['status'] ?? (in_array($section,['students','teachers'])?'active':'draft'));
        echo '<div class="actions wide"><button class="primary">Salvar registro</button><a class="button secondary" href="admin.php?section='.$section.'">Cancelar</a></div></form></section>';
        if($rec)echo '<div id="excluir"></div>';
        if($section==='events' && $rec) {
            echo '<section class="card"><h2>Excluir evento</h2><p>Excluir <strong>'.h($rec['data']['title']).'</strong> da lista, do calendário e do site? Novas compras serão encerradas. Pedidos e pagamentos anteriores permanecem no histórico. A exclusão não cancela pedidos nem devolve pagamentos; um Pix já gerado ainda pode ser pago.</p><form method="post" action="admin.php?section=events&amp;edit='.$editId.'">'.csrf().'<input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="'.$editId.'"><fieldset class="permission-list"><legend>Confirmação</legend><label><input type="checkbox" name="confirm_delete" value="1" required> Confirmo que quero excluir este evento.</label></fieldset><button class="secondary" type="submit">Excluir evento</button></form></section>';
        }
        if($rec && in_array($section,['students','teachers','classes','reminders','contact'],true) && in_array($user['role'],['owner','manager'],true))echo '<section class="card"><h2>Excluir registro</h2><p>Exclui definitivamente este cadastro. Esta ação não pode ser desfeita pelo painel; backups anteriores podem conter uma cópia.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="delete_record"><input type="hidden" name="id" value="'.$editId.'"><label class="check-label"><input type="checkbox" name="confirm_delete" value="1" required> Confirmo a exclusão definitiva deste registro.</label><button class="secondary">Excluir registro definitivamente</button></form></section>';
    } else {
        if($section!=='contact'||!$items)echo '<a class="button primary" href="admin.php?section='.$section.'&new=1">+ Novo registro</a>';
        if(in_array($section,['classes','events','reminders'],true)) {
            $year=(int)date('Y');$month=max(1,min(12,(int)($_GET['month'] ?? date('n'))));$first=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));$months=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
            echo '<section class="card"><div class="calendar-bar"><a class="button secondary" href="admin.php?section='.$section.'&month='.max(1,$month-1).'" aria-label="Mês anterior">←</a><h2>'.h($months[$month-1]).' '.$year.'</h2><a class="button secondary" href="admin.php?section='.$section.'&month='.min(12,$month+1).'" aria-label="Próximo mês">→</a></div><p class="hint">Selecione um dia para adicionar um registro. Todos os meses do ano já estão disponíveis.</p><div class="admin-calendar">';
            foreach(['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $day)echo '<b>'.$day.'</b>';
            for($i=0;$i<(int)$first->format('w');$i++)echo '<span></span>';
            for($day=1;$day<=(int)$first->format('t');$day++){ $date=sprintf('%04d-%02d-%02d',$year,$month,$day);$count=0;foreach($items as $r){$d=$r['data'];if($section==='classes'){if($date>=($d['start_date'] ?? '') && $date<=($d['end_date'] ?? '') && (int)date('w',strtotime($date))===(int)$d['weekday'])$count++;}elseif(substr($d[$section==='events'?'starts':'date'] ?? '',0,10)===$date || ($section==='events' && ($d['second_date'] ?? '')===$date))$count++;}echo '<a href="admin.php?section='.$section.'&new=1&date='.$date.'" class="'.($date===date('Y-m-d')?'today':'').'" aria-label="Adicionar em '.h($date).'">'.$day.($count?'<small>'.$count.' registro(s)</small>':'').'</a>';}
            echo '</div></section>';
        }
        if(in_array($section,['students','teachers'])){echo '<form method="get" class="search"><input type="hidden" name="section" value="'.$section.'"><label>Buscar por nome<input type="search" name="q" value="'.h($_GET['q'] ?? '').'" maxlength="160"></label><button class="secondary">Buscar</button></form>';}
        $search=trim((string)($_GET['q'] ?? ''));if($search!=='')$items=array_values(array_filter($items,fn($r)=>stripos($r['data']['name'] ?? '',$search)!==false));
        echo '<div class="record-list">';if(!$items)echo '<div class="empty"><h2>Nenhum registro por aqui.</h2><p>'.($search?'Tente outro nome.':'Comece adicionando o primeiro registro.').'</p></div>';
        foreach($items as $r){$d=$r['data'];$statusLabels=['active'=>'Ativo','inactive'=>'Inativo','draft'=>'Rascunho','published'=>'Publicado'];echo '<article class="record"><div><h3>'.h($d['name'] ?? $d['title'] ?? 'Canais de atendimento').'</h3>';
            if($section==='students')echo '<p>Responsável: '.h($d['guardian']).' · '.h($d['phone']).'</p><p>'.h($d['class']).'</p>';
            elseif($section==='teachers')echo '<p>'.h($d['modalities']).' · '.h($d['phone']).'</p>';
            elseif($section==='events')echo '<p>'.h($d['starts']?str_replace('T',' ',$d['starts']):'Data a definir').(!empty($d['second_date'])?' e '.h($d['second_date']).(!empty($d['second_time'])?' '.h($d['second_time']):''):'').' · '.h($d['location']).'</p><p>'.h(pixValidity($d)).'</p><p>Ingresso: '.($d['price']===''?'valor a definir':'R$ '.h(number_format((float)$d['price'],2,',','.'))).' · '.($d['ticket_state']==='pix'?'Pix manual':($d['ticket_state']==='link'?'link de venda':'vendas fechadas')).'</p>';
            elseif($section==='classes')echo '<p>'.h(['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'][(int)$d['weekday']]).' · '.h($d['time']).' · '.h($d['location']).'</p>';
            elseif($section==='reminders')echo '<p>'.h($d['description']).'</p>';
            else echo '<p>'.h($d['phone']).' · '.h($d['email']).'</p>';
            echo '<span class="badge">'.h($statusLabels[$d['status']] ?? $d['status']).'</span></div><div class="actions"><a class="button secondary" href="admin.php?section='.$section.'&edit='.$r['id'].'">Editar</a>';
            if($section==='events'||in_array($user['role'],['owner','manager'],true))echo '<a class="button secondary" href="admin.php?section='.$section.'&edit='.$r['id'].'#excluir">Excluir</a>';
            echo '</div></article>';
        }echo '</div>';
    }
}
echo '</main></div>';pageEnd();
