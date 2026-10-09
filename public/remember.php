<?php
if(!defined('BAILARE_PIX')){http_response_code(404);exit;}
// Only a random bearer token reaches the cookie; passwords never do.
function rememberSchema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS bailare_remember (token_hash VARCHAR(64) PRIMARY KEY,user_id INTEGER NOT NULL,auth_stamp VARCHAR(64) NOT NULL,expires_at INTEGER NOT NULL)'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
}
function rememberCookie(string $token,int $expires): void {
    global $local;
    setcookie('bailare_device',$token,['expires'=>$expires,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']);
}
function rememberForget(PDO $pdo): void {
    $token=(string)($_COOKIE['bailare_device'] ?? '');
    if(preg_match('/^[a-f0-9]{64}$/D',$token)){
        try{$pdo->prepare('DELETE FROM bailare_remember WHERE token_hash=?')->execute([hash('sha256',$token)]);}catch(PDOException $e){}
    }
    rememberCookie('',time()-3600);unset($_COOKIE['bailare_device']);
}
function rememberIssue(PDO $pdo,array $user): void {
    rememberSchema($pdo);rememberForget($pdo);
    $pdo->prepare('DELETE FROM bailare_remember WHERE expires_at<?')->execute([time()]);
    $token=bin2hex(random_bytes(32));$until=time()+7*86400;
    $pdo->prepare('INSERT INTO bailare_remember(token_hash,user_id,auth_stamp,expires_at) VALUES(?,?,?,?)')->execute([hash('sha256',$token),$user['id'],hash('sha256',$user['password_hash']),$until]);
    rememberCookie($token,$until);$_SESSION['remember_until']=$until;
}
function rememberRestore(PDO $pdo): ?array {
    $token=(string)($_COOKIE['bailare_device'] ?? '');
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
    try{
        $q=$pdo->prepare('SELECT u.*,r.auth_stamp AS device_stamp,r.expires_at FROM bailare_remember r JOIN bailare_users u ON u.id=r.user_id WHERE r.token_hash=?');$q->execute([hash('sha256',$token)]);$u=$q->fetch();
        if(!$u||!$u['active']||(int)$u['expires_at']<=time()||!hash_equals($u['device_stamp'],hash('sha256',$u['password_hash']))){rememberForget($pdo);return null;}
        session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['auth_stamp']=hash('sha256',$u['password_hash']);$_SESSION['last_seen']=time();$_SESSION['remember_until']=(int)$u['expires_at'];$_SESSION['csrf']=bin2hex(random_bytes(32));return $u;
    }catch(PDOException $e){rememberCookie('',time()-3600);return null;}
}
