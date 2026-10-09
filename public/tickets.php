<?php
if (!defined('BAILARE_PIX')) { http_response_code(404); exit; }
const TICKET_ORIGIN='https://studio.example.invalid';
const TICKET_SENDER='ingressos@example.invalid';
const ORDER_ALERT_TO='gestao@example.invalid';
function ticketSchema(PDO $pdo): void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_tickets (id $id, order_id INTEGER NOT NULL, ordinal INTEGER NOT NULL, token VARCHAR(64) NOT NULL UNIQUE, dates TEXT NOT NULL, UNIQUE(order_id,ordinal))".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_entries (ticket_id INTEGER NOT NULL, entry_date VARCHAR(10) NOT NULL, entered_at VARCHAR(30) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(ticket_id,entry_date))".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_mail (order_id INTEGER PRIMARY KEY, status VARCHAR(20) NOT NULL, attempted_at INTEGER NOT NULL DEFAULT 0, sent_at VARCHAR(30))".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_order_alerts (order_id INTEGER PRIMARY KEY, status VARCHAR(20) NOT NULL, attempted_at INTEGER NOT NULL DEFAULT 0, sent_at VARCHAR(30))".$tail);
    $cols=$pdo->query($mysql?'SHOW COLUMNS FROM bailare_orders':'PRAGMA table_info(bailare_orders)')->fetchAll();
    if(!in_array('validity_dates',array_column($cols,$mysql?'Field':'name'),true)) {
        try{$pdo->exec('ALTER TABLE bailare_orders ADD COLUMN validity_dates TEXT');}catch(PDOException $e){$pdo->query('SELECT validity_dates FROM bailare_orders LIMIT 1');}
    }
}
function ticketDates(array $event): array {
    $dates=[substr($event['starts'] ?? '',0,10)];
    if(($event['ticket_validity'] ?? '')==='both_dates')$dates[]=$event['second_date'] ?? '';
    return ticketValidDates($dates);
}
function ticketValidDates(array $dates): array {
    foreach($dates as $d){$v=is_string($d)?DateTimeImmutable::createFromFormat('!Y-m-d',$d):false;if(!$v||$v->format('Y-m-d')!==$d)throw new RuntimeException('Defina as datas válidas do evento antes de emitir ingressos.');}
    if(!$dates||count($dates)>2)throw new RuntimeException('Datas do ingresso inválidas.');
    return array_values(array_unique($dates));
}
function ticketOrderDates(array $o): array {
    if(!empty($o['validity_dates']))return ticketValidDates(json_decode($o['validity_dates'],true,512,JSON_THROW_ON_ERROR));
    // Legacy orders use their saved validity, never the current (possibly edited) event.
    preg_match_all('~\b(\d{2})/(\d{2})/(\d{4})\b~',$o['validity_text'] ?? '',$m,PREG_SET_ORDER);
    return ticketValidDates(array_map(fn($d)=>$d[3].'-'.$d[2].'-'.$d[1],$m));
}
function ticketIssue(PDO $pdo,array $o): void {
    $q=$pdo->prepare('SELECT COUNT(*) FROM bailare_tickets WHERE order_id=?');$q->execute([$o['id']]);$count=(int)$q->fetchColumn();
    if($count===(int)$o['quantity'])return;
    if($count!==0)throw new RuntimeException('Emissão incompleta. Procure a Gestão.');
    $dates=json_encode(ticketOrderDates($o));
    $q=$pdo->prepare('INSERT INTO bailare_tickets(order_id,ordinal,token,dates) VALUES(?,?,?,?)');
    for($i=1;$i<=(int)$o['quantity'];$i++)$q->execute([$o['id'],$i,bin2hex(random_bytes(32)),$dates]);
    $pdo->prepare("INSERT INTO bailare_mail(order_id,status) VALUES(?,'pending')")->execute([$o['id']]);
}
function ticketList(PDO $pdo,int $order): array {$q=$pdo->prepare('SELECT * FROM bailare_tickets WHERE order_id=? ORDER BY ordinal');$q->execute([$order]);return $q->fetchAll();}
function ticketUrl(array $t): string {return TICKET_ORIGIN.'/admin.php?ingresso='.$t['token'];}
function ticketCards(PDO $pdo,int $order): void {
    $tickets=ticketList($pdo,$order);
    if(!$tickets){echo '<p>A Gestão ainda precisa emitir os ingressos deste pedido.</p>';return;}
    foreach($tickets as $t)echo '<p><a class="button primary" href="'.h(ticketUrl($t)).'">Abrir ingresso '.(int)$t['ordinal'].' e QR Code</a></p>';
}
function ticketFind(PDO $pdo,string $token): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
    $q=$pdo->prepare('SELECT t.*,o.event_title,o.status AS order_status FROM bailare_tickets t JOIN bailare_orders o ON o.id=t.order_id WHERE t.token=?');$q->execute([$token]);return $q->fetch() ?: null;
}
function ticketPublic(PDO $pdo): never {
    try {
        $t=ticketFind($pdo,(string)($_GET['ingresso'] ?? ''));
        if(!$t||$t['order_status']!=='paid'){http_response_code(404);throw new RuntimeException('Ingresso indisponível. Consulte a Gestão.');}
        pixPage('Seu ingresso');
        echo '<h2>'.h($t['event_title']).'</h2><p>Ingresso '.(int)$t['ordinal'].' · identificação #'.(int)$t['id'].'</p><p>Válido em: '.h(implode(' e ',array_map(fn($d)=>date('d/m/Y',strtotime($d)),json_decode($t['dates'],true)))).'</p><div class="ticket-qr" data-code="'.h(TICKET_ORIGIN.'/admin.php?checkin='.$t['token']).'"></div><p>Apresente este QR Code na entrada. Uma entrada por data. Este código não é um Pix.</p><p class="hint">Guarde este link ou uma captura do QR Code. Não publique nem compartilhe com outras pessoas: quem apresentar primeiro poderá usar o ingresso.</p>';
    }catch(Throwable $e){http_response_code(404);pixPage('Ingresso indisponível');notice('Ingresso indisponível. Consulte a Gestão.');}
    ticketFinish();
}
function ticketFinish(): never {echo '</main><script src="qrcode.js" defer></script><script src="tickets.js?v=1" defer></script>';pageEnd();exit;}
function ticketCheckin(PDO $pdo,array $user): never {
    if(!allowed($user,'checkin')){http_response_code(403);pixPage('Acesso restrito');notice('A Gestão precisa liberar a permissão Portaria para este usuário.');pixFinish();}
    $token=(string)($_GET['checkin'] ?? '');$message='';$success=false;$t=null;
    try {
        if($token!=='1' && $token!==''){
            $t=ticketFind($pdo,$token);if(!$t||$t['order_status']!=='paid')throw new RuntimeException('Ingresso inválido ou pagamento não confirmado.');
            if($_SERVER['REQUEST_METHOD']==='POST'){
                checkCsrf();if(value('action',30)!=='enter_ticket'||value('confirm_entry',1)!=='1')throw new RuntimeException('Confirme a entrada antes de registrar.');
                if(!in_array(date('Y-m-d'),json_decode($t['dates'],true),true))throw new RuntimeException('Este ingresso não é válido para hoje.');
                $pdo->beginTransaction();
                $lock=$pdo->prepare('UPDATE bailare_orders SET status=status WHERE id=?');$lock->execute([$t['order_id']]);
                $t=ticketFind($pdo,$token);if(!$t||$t['order_status']!=='paid')throw new RuntimeException('Ingresso invalidado. Não libere a entrada.');
                $q=$pdo->prepare('INSERT INTO bailare_entries(ticket_id,entry_date,entered_at,user_id) VALUES(?,?,?,?)');
                $q->execute([$t['id'],date('Y-m-d'),date('c'),$user['id']]);audit($pdo,(int)$user['id'],'ticket_entry',(int)$t['id']);$pdo->commit();$message='Entrada registrada. Pode liberar este ingresso.';$success=true;
            }
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message=$e instanceof PDOException?'Entrada não registrada: ingresso já utilizado hoje ou serviço indisponível.':$e->getMessage();}
    pixPage('Portaria · validar ingresso');echo '<a href="admin.php">← Administração</a><p>Entre uma vez no aparelho da portaria. A câmera consulta cada QR; confirme a entrada após conferir a pessoa. A leitura sozinha não registra entrada. Na tela de login, use Manter conectado apenas no aparelho da equipe.</p>';
    if($message)notice($message,$success?'success':'error');
    if($t&&$t['order_status']==='paid'){
        echo '<h2>'.h($t['event_title']).'</h2><p>Ingresso #'.(int)$t['id'].' · '.h(implode(' e ',json_decode($t['dates'],true))).'</p>';
        $q=$pdo->prepare('SELECT entered_at FROM bailare_entries WHERE ticket_id=? AND entry_date=?');$q->execute([$t['id'],date('Y-m-d')]);$entered=$q->fetchColumn();
        if($entered)notice('Já utilizado hoje: '.$entered);
        elseif(in_array(date('Y-m-d'),json_decode($t['dates'],true),true))echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="enter_ticket"><label><input type="checkbox" name="confirm_entry" value="1" required> Estou conferindo este ingresso na entrada.</label><button class="primary">Registrar entrada de hoje</button></form>';
        else notice('Não é válido para hoje. Nenhuma entrada pode ser registrada.');
    }
    echo '<form method="get"><label>Código do ingresso (64 caracteres do link)<input name="checkin" required pattern="[a-f0-9]{64}" maxlength="64" autocomplete="off"></label><button class="secondary">Consultar ingresso</button></form>';
    echo '<section class="card" id="door-scanner"><h2>Ler próximo ingresso</h2><p>A imagem é processada neste aparelho, sem gravação ou envio de vídeo. Permita somente a câmera; não usamos microfone.</p><video id="door-video" playsinline muted hidden></video><button type="button" id="door-start" class="primary">Abrir câmera para ler QR</button><button type="button" id="door-stop" class="secondary" hidden>Parar câmera</button><p id="door-status" role="status" aria-live="polite"></p><p class="hint">Se o navegador não suportar a leitura, use a câmera do celular para abrir o link no mesmo navegador conectado, ou cole o código acima. Requer internet.</p></section></main><script src="jsQR.js" defer></script><script src="scanner.js?v=20260928" defer></script>';pageEnd();exit;
}
function ticketConfigPath(): string {global $privateDir;return $privateDir.'/smtp.json';}
function orderAlertSend(PDO $pdo,int $id,bool $retry=false,?callable $transport=null): string {
    // Called only after purchase commit. Email failure must never undo an order.
    try{
        $q=$pdo->prepare('SELECT * FROM bailare_orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();
        if(!$o||$o['status']!=='pending')return 'Alerta não enviado: pedido não está pendente.';
        try{$pdo->prepare("INSERT INTO bailare_order_alerts(order_id,status) VALUES(?,'pending')")->execute([$id]);}
        catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}
        $cfg=ticketConfig();if(empty($cfg['enabled']))return 'Alerta pendente: envio de e-mail desativado.';
        $sql="UPDATE bailare_order_alerts SET status='sending',attempted_at=? WHERE order_id=? AND (status='pending'".($retry?" OR (status IN ('failed','sent','sending') AND attempted_at<?)":'').')';
        $args=[time(),$id];if($retry)$args[]=time()-120;$q=$pdo->prepare($sql);$q->execute($args);
        if(!$q->rowCount())return 'Alerta já enviado ou em andamento. Aguarde 2 minutos antes de reenviar.';
        try{
            $body="NOVO PEDIDO — PAGAMENTO AINDA NÃO CONFIRMADO\n\n";
            $body.='Pedido: '.$o['txid']."\nEvento: ".$o['event_title']."\n".$o['validity_text']."\nComprador: ".$o['buyer_name']."\nQuantidade: ".$o['quantity']."\nTotal esperado: ".pixMoney((int)$o['total_cents'])."\nCriado em: ".$o['created_at']."\n\n";
            $body.="Este aviso informa apenas que o pedido foi criado; não comprova pagamento. Confira o recebimento na conta bancária antes de confirmar na Gestão. Não se baseie apenas em comprovante enviado pelo comprador.\n\n";
            $body.='Abrir pedido na Gestão (exige login): '.TICKET_ORIGIN.'/admin.php?payments=1&q='.rawurlencode($o['txid'])."\n\nNenhum pagamento foi confirmado e nenhum ingresso foi emitido por este alerta.\n";
            ($transport ?? 'ticketSmtp')($cfg,ORDER_ALERT_TO,'Novo pedido '.$o['txid'].' · Bailare — conferir pagamento',$body);
            $pdo->prepare("UPDATE bailare_order_alerts SET status='sent',sent_at=? WHERE order_id=?")->execute([date('c'),$id]);return 'Alerta aceito pelo servidor para '.ORDER_ALERT_TO.'.';
        }catch(Throwable $e){$pdo->prepare("UPDATE bailare_order_alerts SET status='failed' WHERE order_id=?")->execute([$id]);return 'Envio do alerta não confirmado. O pedido foi preservado.';}
    }catch(Throwable $e){return 'Alerta não concluído. O pedido foi preservado; confira as configurações e tente pela Gestão.';}
}
function orderAlertActions(PDO $pdo,array $o): void {
    $q=$pdo->prepare('SELECT status,sent_at FROM bailare_order_alerts WHERE order_id=?');$q->execute([$o['id']]);$alert=$q->fetch();
    echo '<p>Alerta à Gestão: '.h(['pending'=>'Pendente','sending'=>'Em andamento ou resultado incerto','sent'=>'Aceito pelo servidor','failed'=>'Envio não confirmado'][$alert['status'] ?? ''] ?? 'Sem envio registrado (pedido anterior à atualização ou falha inicial)').'</p>';
    if($o['status']==='pending')echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="send_order_alert"><input type="hidden" name="order_id" value="'.(int)$o['id'].'"><label class="check-label"><input type="checkbox" name="confirm_send" value="1" required> Enviar alerta para '.h(ORDER_ALERT_TO).' (pode repetir mensagem anterior).</label><button class="secondary">Enviar / reenviar alerta à Gestão</button></form>';
}
function ticketConfig(): array {$p=ticketConfigPath();return is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[];}
function ticketSaveConfig(array $c): void {
    $path=ticketConfigPath();$dir=realpath(dirname($path));$public=realpath(__DIR__);
    if(!$dir||str_starts_with(str_replace('\\','/',$dir).'/',str_replace('\\','/',$public).'/'))throw new RuntimeException('A configuração precisa ficar fora da pasta pública.');
    $temp=tempnam($dir,'smtp-');if(!$temp)throw new RuntimeException('Não foi possível criar a configuração privada.');
    try{chmod($temp,0600);if(file_put_contents($temp,json_encode($c,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($temp,$path))throw new RuntimeException('Não foi possível salvar a configuração privada.');}finally{if(is_file($temp))unlink($temp);}
}
function ticketSmtp(array $cfg,string $to,string $subject,string $body): void {
    throw new RuntimeException('Envio real desativado na versão de portfólio.');
    if(empty($cfg['password']))throw new RuntimeException('Configure a senha SMTP primeiro.');
    foreach(['Exception.php','SMTP.php','PHPMailer.php'] as $file)if(!is_readable(__DIR__.'/mailer/'.$file))throw new RuntimeException('MAILER_FILES_MISSING');
    if(!extension_loaded('openssl')||!function_exists('stream_socket_client'))throw new RuntimeException('SMTP_TLS_UNAVAILABLE');
    require_once __DIR__.'/mailer/Exception.php';require_once __DIR__.'/mailer/SMTP.php';require_once __DIR__.'/mailer/PHPMailer.php';
    $mail=new PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host='smtp.example.invalid';$mail->Port=465;
    $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;$mail->SMTPAuth=true;$mail->Username=TICKET_SENDER;$mail->Password=$cfg['password'];
    $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
    $mail->Timeout=15;$mail->Timelimit=20;$mail->SMTPDebug=0;$mail->CharSet='UTF-8';$mail->setFrom(TICKET_SENDER,'Studio Bailare');$mail->addAddress($to);$mail->Subject=$subject;$mail->Body=$body;$mail->send();
}
function ticketSend(PDO $pdo,int $orderId,bool $retry=false,?callable $transport=null): string {
    try{$cfg=ticketConfig();}catch(Throwable $e){return 'Pagamento preservado. Revise a configuração privada de e-mail antes de reenviar.';}
    if(empty($cfg['enabled']))return 'E-mail pendente: envio automático ainda desativado.';
    $q=$pdo->prepare("SELECT * FROM bailare_orders WHERE id=? AND status='paid'");$q->execute([$orderId]);$o=$q->fetch();if(!$o)throw new RuntimeException('Pedido não está pago.');
    $tickets=ticketList($pdo,$orderId);if(count($tickets)!==(int)$o['quantity'])throw new RuntimeException('Emita os ingressos antes do envio.');
    $q=$pdo->prepare("UPDATE bailare_mail SET status='sending',attempted_at=? WHERE order_id=? AND (status IN ('pending','failed')".($retry?" OR status='sent' OR (status='sending' AND attempted_at<?)":'').')');
    $args=[time(),$orderId];if($retry)$args[]=time()-120;$q->execute($args);
    if(!$q->rowCount())return 'Envio já realizado ou em andamento. Aguarde antes de reenviar.';
    try {
        $body="Pagamento confirmado pela Gestão do Studio Bailare.\n\n".$o['event_title']."\n".$o['validity_text']."\nQuantidade: ".$o['quantity']."\nTotal: ".pixMoney((int)$o['total_cents'])."\n\nAbra cada ingresso para visualizar e salvar seu QR Code:\n";
        foreach($tickets as $t)$body.='Ingresso '.$t['ordinal'].': '.ticketUrl($t)."\n\n";
        $body.="Uma entrada por ingresso em cada data indicada. Os links são privados. Não compartilhe publicamente. O QR de entrada não é um código de pagamento.\n";
        ($transport ?? 'ticketSmtp')($cfg,$o['buyer_email'],'Seus ingressos · Studio Bailare',$body);
        $pdo->prepare("UPDATE bailare_mail SET status='sent',sent_at=? WHERE order_id=?")->execute([date('c'),$orderId]);return 'E-mail aceito pelo servidor. A entrega na caixa de entrada ainda depende do provedor do destinatário.';
    }catch(Throwable $e){$pdo->prepare("UPDATE bailare_mail SET status='failed' WHERE order_id=?")->execute([$orderId]);return 'Pagamento preservado. Envio não confirmado; confira a caixa de e-mail antes de tentar novamente.';}
}
function ticketSetupDiagnostic(Throwable $e,string $stage): string {
    // Never display raw library/server exceptions: they may contain credentials or paths.
    $raw=$e->getMessage();
    $safe=[
        'INVALID_TEST_EMAIL'=>'[EMAIL-17] Informe um e-mail válido para receber o teste.',
        'Configure a senha SMTP primeiro.'=>'[EMAIL-01] Nenhuma senha de e-mail foi carregada. Salve a configuração antes de enviar o teste.',
        'Informe a senha da conta de e-mail.'=>'[EMAIL-02] Preencha a senha da conta de e-mail e salve novamente.',
        'MAILER_FILES_MISSING'=>'[EMAIL-03] Faltam arquivos da biblioteca. Envie a pasta mailer completa para /www/mailer, com Exception.php, SMTP.php e PHPMailer.php.',
        'SMTP_TLS_UNAVAILABLE'=>'[EMAIL-04] O PHP não disponibiliza OpenSSL ou conexões SMTP por stream_socket_client. Peça à hospedagem para verificar essas funções sem desativar a segurança.',
        'Aguarde um minuto entre testes.'=>'[EMAIL-05] Aguarde um minuto antes de tentar enviar outro teste.',
        'Envie e confirme o recebimento do teste primeiro.'=>'[EMAIL-06] Envie o teste e confirme seu recebimento no Webmail antes de ativar.',
        'Sua sessão expirou. Recarregue a página e tente novamente.'=>'[EMAIL-07] Sua sessão expirou. Abra a configuração novamente e repita a operação.'
    ];
    if(isset($safe[$raw]))return $safe[$raw];
    if($stage==='smtp'){
        if(stripos($raw,'authenticate')!==false)return '[EMAIL-08] O servidor SMTP recusou a autenticação. Confira o acesso da conta ingressos@example.invalid no Webmail e a senha salva no site.';
        if(stripos($raw,'connect')!==false)return '[EMAIL-09] Não foi possível estabelecer a conexão SMTP segura. A hospedagem precisa verificar acesso a smtp.example.invalid:465 e certificados TLS.';
        return '[EMAIL-10] O teste SMTP não foi confirmado. Confira se chegou no Webmail antes de repetir; pode haver recusa do remetente, destinatário ou limite do provedor.';
    }
    return [
        'database'=>'[EMAIL-11] Falha ao preparar as tabelas de ingressos no banco. Confira a atualização de pix.php e tickets.php e as permissões de criação/alteração de tabelas.',
        'read'=>'[EMAIL-12] Não foi possível ler a configuração privada smtp.json. É preciso verificar as permissões e a integridade desse arquivo, sem publicá-lo.',
        'save'=>'[EMAIL-13] Não foi possível gravar a configuração na pasta bailare-private, fora de /www. Verifique proprietário, permissão de escrita e espaço da hospedagem. Não use permissão 777.',
        'save_test'=>'[EMAIL-14] O SMTP aceitou o teste, mas não foi possível salvar o resultado na pasta privada. Confira o Webmail e a permissão de escrita antes de repetir.',
        'audit'=>'[EMAIL-15] A operação anterior terminou, mas houve falha ao registrar o histórico no banco. Reabra a página para conferir o estado antes de repetir.',
    ][$stage] ?? '[EMAIL-16] Operação inválida ou dados incompletos. Reabra a configuração e tente novamente.';
}
function ticketMailAdmin(PDO $pdo,array $user): never {
    if(!in_array($user['role'],['owner','manager'],true)){http_response_code(403);pixPage('Acesso restrito');notice('Somente a Gestão configura e-mails.');pixFinish();}
    $message='';$c=[];$stage='database';
    try {
        pixSchema($pdo);$stage='read';$c=ticketConfig();
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $stage='validation';
            checkCsrf();$action=value('action',30,true);
            if($action==='save_smtp'){
                $p=(string)($_POST['smtp_password'] ?? '');if($p==='')$p=$c['password'] ?? '';if($p===''||strlen($p)>512)throw new RuntimeException('Informe a senha da conta de e-mail.');
                $next=['password'=>$p,'enabled'=>false,'tested'=>false];$stage='save';ticketSaveConfig($next);$c=$next;$message='Configuração salva em arquivo privado. Agora envie o teste para a própria conta.';
            }elseif($action==='test_smtp'){
                $testTo=value('test_email',200) ?: TICKET_SENDER;
                if(!filter_var($testTo,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$testTo))throw new RuntimeException('INVALID_TEST_EMAIL');
                if(time()-(int)($_SESSION['mail_test_at'] ?? 0)<60)throw new RuntimeException('Aguarde um minuto entre testes.');$_SESSION['mail_test_at']=time();
                $stage='smtp';ticketSmtp($c,$testTo,'Teste de envio · Studio Bailare','Este é um teste de envio de e-mail do Studio Bailare. Nenhum pagamento foi confirmado e esta mensagem não é um ingresso. Se você recebeu esta mensagem, informe à Gestão que o teste chegou.');
                $next=$c;$next['tested']=true;$stage='save_test';ticketSaveConfig($next);$c=$next;$message='Teste aceito pelo servidor. Confira a caixa de entrada e o spam de '.$testTo.'.';
            }elseif($action==='enable_smtp'){
                if(empty($c['tested'])||value('received_test',1)!=='1')throw new RuntimeException('Envie e confirme o recebimento do teste primeiro.');$next=$c;$next['enabled']=true;$stage='save';ticketSaveConfig($next);$c=$next;$message='Envio automático ativado para novas confirmações. Pedidos anteriores exigem envio manual.';
            }elseif($action==='disable_smtp'){$next=$c;$next['enabled']=false;$stage='save';ticketSaveConfig($next);$c=$next;$message='Envio automático desativado.';}
            else throw new RuntimeException('Operação inválida.');
            $stage='audit';audit($pdo,(int)$user['id'],$action,0);
        }
    }catch(Throwable $e){$message=ticketSetupDiagnostic($e,$stage);}
    pixPage('E-mail dos ingressos');echo '<a href="admin.php?payments=1">← Pedidos e pagamentos</a>';if($message)notice($message);
    echo '<p>Remetente: '.h(TICKET_SENDER).'<br>Servidor: smtp.example.invalid · porta 465 · SSL/TLS verificado</p><p>Envio automático: <strong>'.(!empty($c['enabled'])?'ativado':'desativado').'</strong></p><p>A mensagem contém links privados para abrir os ingressos e seus QR Codes; não há anexo PDF. Falhas não desfazem pagamentos. Não usamos serviços externos de QR Code.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="save_smtp">';
    input('smtp_password','Senha do e-mail (vazio mantém a senha salva)','password','',empty($c['password']),'maxlength="512" autocomplete="new-password"');echo '<button class="primary">Salvar configuração (desativa envio até novo teste)</button></form><form method="post">'.csrf().'<input type="hidden" name="action" value="test_smtp">';
    input('test_email','Destinatário do e-mail de teste','email',TICKET_SENDER,true,'maxlength="200" autocomplete="email"');
    echo '<p class="hint">Envia somente uma mensagem de teste, sem ingresso nem confirmação de pagamento. Use um endereço seu ou autorizado.</p><button class="secondary">Enviar e-mail de teste</button></form><form method="post">'.csrf().'<input type="hidden" name="action" value="enable_smtp"><label><input type="checkbox" name="received_test" value="1" required> Confirmei o recebimento do teste e quero ativar o envio.</label><button class="primary">Ativar envio automático</button></form><form method="post">'.csrf().'<input type="hidden" name="action" value="disable_smtp"><button class="secondary">Desativar envio automático</button></form>';
    pixFinish();
}
function ticketMailActions(PDO $pdo,array $o): void {
    $q=$pdo->prepare('SELECT status,sent_at FROM bailare_mail WHERE order_id=?');$q->execute([$o['id']]);$mail=$q->fetch();
    echo '<p>E-mail: '.h(['pending'=>'Pendente','sending'=>'Em andamento ou resultado incerto','sent'=>'Aceito pelo servidor','failed'=>'Envio não confirmado'][$mail['status'] ?? ''] ?? 'Ingressos ainda não emitidos').'</p><form method="post">'.csrf().'<input type="hidden" name="order_id" value="'.(int)$o['id'].'"><input type="hidden" name="action" value="send_tickets"><label><input type="checkbox" name="confirm_send" value="1" required> Enviar os ingressos para '.h($o['buyer_email']).' (pode repetir mensagem anterior).</label><button class="secondary">Emitir / reenviar ingressos</button></form>';
    ticketCards($pdo,(int)$o['id']);
}
