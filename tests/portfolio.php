<?php
declare(strict_types=1);
define('BAILARE_PIX',true);
require __DIR__.'/../public/pix.php';
require __DIR__.'/../public/tickets.php';
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "OK $label\n";}
check(!pixOpenEvent(['data'=>['status'=>'published','ticket_state'=>'pix','price'=>'36.00','starts'=>'2099-12-20T18:30']]),'compras bloqueadas');
check(str_ends_with(PIX_KEY,'@example.invalid'),'chave fictícia');
check(TICKET_ORIGIN==='https://studio.example.invalid','origem fictícia');
check(str_ends_with(TICKET_SENDER,'@example.invalid')&&str_ends_with(ORDER_ALERT_TO,'@example.invalid'),'e-mails fictícios');
$blocked=false;
try{ticketSmtp([],'nobody@example.invalid','Teste','Não enviar');}
catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'desativado');}
check($blocked,'SMTP bloqueado antes de conectar');
$app=file_get_contents(__DIR__.'/../public/app.js');
check(!str_contains($app,"fetch('admin.php?api=public'"),'prévia sem consulta ao backend real');
echo "6 verificações aprovadas; sem conexão externa ou alteração de banco.\n";
