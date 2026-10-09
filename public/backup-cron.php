<?php
declare(strict_types=1);
ini_set('display_errors','0');date_default_timezone_set('America/Sao_Paulo');header('Cache-Control: no-store');
$privateDir=dirname(__DIR__).'/bailare-private';
try{
    $file=$privateDir.'/backup-cron.json';$c=is_file($file)?json_decode(file_get_contents($file),true):[];
    $provided=(string)($_SERVER['HTTP_X_CRON_AUTH'] ?? '');
    if(($_SERVER['HTTPS'] ?? '')!=='on'||$provided===''||empty($c['hash'])||!hash_equals($c['hash'],hash('sha256',$provided))){http_response_code(403);exit('Acesso negado.');}
    define('BAILARE_PIX',true);require __DIR__.'/gallery.php';require __DIR__.'/backup.php';
    $cfg=require $privateDir.'/config.php';$pdo=new PDO($cfg['dsn'],$cfg['user'] ?? null,$cfg['password'] ?? null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    backupRun($pdo);echo 'Rotina concluída.';
}catch(Throwable $e){http_response_code(500);echo 'Backup não concluído. Consulte a Gestão.';}
