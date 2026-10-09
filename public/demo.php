<?php
if(!defined('BAILARE_PIX')){http_response_code(404);exit;}
function demoSchema(PDO $pdo): void {$pdo->exec('CREATE TABLE IF NOT EXISTS bailare_demos (token VARCHAR(64) PRIMARY KEY, event_title VARCHAR(160) NOT NULL, event_date VARCHAR(10) NOT NULL, expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0)'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));}
function demoGet(PDO $pdo,string $token): ?array {if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;$q=$pdo->prepare('SELECT * FROM bailare_demos WHERE token=? AND expires_at>?');$q->execute([$token,time()]);return $q->fetch() ?: null;}
function demoPublic(PDO $pdo): never {
    try{$d=demoGet($pdo,(string)($_GET['demo'] ?? ''));}catch(Throwable $e){$d=null;}
    if(!$d){http_response_code(404);pixPage('Teste indisponível');notice('Este teste não existe ou expirou.');pixFinish();}
    pixPage('TESTE — não é ingresso');echo '<p class="notice">SEM VALIDADE PARA ENTRADA. Não houve compra nem confirmação de pagamento.</p><h2>'.h($d['event_title']).'</h2><p>Dia simulado: '.h($d['event_date']).'</p><div class="ticket-qr" data-code="'.h(TICKET_ORIGIN.'/admin.php?checkdemo='.$d['token']).'"></div><p>Leia este QR com a câmera de outro celular. A equipe deve entrar com login para simular a validação. Link válido por 48 horas.</p>';ticketFinish();
}
function demoCheck(PDO $pdo,array $user): never {
    if(!allowed($user,'checkin')){http_response_code(403);pixPage('Acesso restrito');notice('Acesso à Portaria necessário.');pixFinish();}
    $message='';$d=demoGet($pdo,(string)($_GET['checkdemo'] ?? ''));
    try{if(!$d)throw new RuntimeException('Teste expirado ou inexistente.');if($_SERVER['REQUEST_METHOD']==='POST'){checkCsrf();if(value('simulated_date',10)!==$d['event_date'])throw new RuntimeException('BLOQUEADO: este QR não vale para o dia simulado.');$q=$pdo->prepare('UPDATE bailare_demos SET used=1 WHERE token=? AND used=0 AND expires_at>?');$q->execute([$d['token'],time()]);$message=$q->rowCount()===1?'SIMULAÇÃO APROVADA. Nenhuma entrada real registrada.':'BLOQUEADO: teste já utilizado. Nenhuma entrada real registrada.';}}catch(Throwable $e){$message=$e instanceof PDOException?'Teste indisponível.':$e->getMessage();}
    pixPage('Portaria — somente simulação');echo '<p class="notice">Este QR nunca libera entrada no espetáculo nem ocupa vagas.</p>';if($message)notice($message);
    if($d){echo '<form method="post">'.csrf();input('simulated_date','Data a simular (teste sem entrada real)','date',$d['event_date'],true);echo '<button class="primary">Simular validação do teste</button></form>';}
    pixFinish();
}
function demoAdmin(PDO $pdo,array $user): never {
    if(!in_array($user['role'],['owner','manager'],true)){http_response_code(403);pixPage('Acesso restrito');notice('Somente a Gestão envia testes.');pixFinish();}
    $message='';try{demoSchema($pdo);if($_SERVER['REQUEST_METHOD']==='POST'){
        checkCsrf();if(value('confirm_demo',1)!=='1')throw new RuntimeException('Confirme o envio do teste.');$email=value('demo_email',200,true);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
        if(time()-(int)($_SESSION['demo_sent_at'] ?? 0)<60)throw new RuntimeException('Aguarde um minuto entre testes.');
        $event=findRecord($pdo,'events',(int)value('event_id',20,true));$day=value('event_date',10,true);if(!$event||!in_array($day,pixSaleDates($event['data']),true))throw new RuntimeException('Escolha um evento e uma data disponível.');
        $_SESSION['demo_sent_at']=time();$token=bin2hex(random_bytes(32));$pdo->prepare('INSERT INTO bailare_demos(token,event_title,event_date,expires_at) VALUES(?,?,?,?)')->execute([$token,$event['data']['title'],$day,time()+48*3600]);
        ticketSmtp(ticketConfig(),$email,'TESTE de ingresso — sem validade · Studio Bailare',"Este é somente um teste, sem pagamento e sem validade para entrar no espetáculo.\nEvento: ".$event['data']['title']."\nDia simulado: ".$day."\nAbra para ver o QR de teste: ".TICKET_ORIGIN.'/admin.php?demo='.$token."\nO link expira em 48 horas.\n");audit($pdo,(int)$user['id'],'send_demo',0);$message='E-mail de teste aceito pelo servidor. Nenhuma venda ou pagamento registrado.';
    }}catch(Throwable $e){$message=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Teste não enviado. Confira o SMTP e a atualização dos arquivos.';}
    pixPage('Enviar ingresso de teste');echo '<a href="admin.php?payments=1">← Pagamentos</a><p>O QR de teste tem uma rota separada: nunca pode liberar entrada real. Use-o para conferir e-mail, abertura no celular, login e simulação de data/repetição.</p>';if($message)notice($message);
    foreach(records($pdo,'events') as $event){$dates=pixSaleDates($event['data']);if(!$dates)continue;echo '<section class="card"><h2>'.h($event['data']['title']).'</h2><form method="post">'.csrf().'<input type="hidden" name="event_id" value="'.$event['id'].'">';input('demo_email','E-mail autorizado para teste','email','',true,'maxlength="200"');selectField('event_date','Dia a simular',array_combine($dates,$dates),$dates[0]);echo '<label><input type="checkbox" name="confirm_demo" value="1" required> Autorizo enviar este teste sem validade ao destinatário informado.</label><button class="primary">Enviar teste com QR</button></form></section>';}
    pixFinish();
}
