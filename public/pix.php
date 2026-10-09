<?php
// Loaded only by the authenticated application bootstrap, never directly.
if (!defined('BAILARE_PIX')) { http_response_code(404); exit; }
const PIX_KEY = 'pix@example.invalid';
const PIX_NAME = 'ESTUDIO DEMONSTRACAO';
const PIX_CITY = 'CIDADE DEMO';
function pixFields(string $payload): array {
    $out=[];for($i=0;$i<strlen($payload);){if(!preg_match('/^[0-9]{4}$/D',substr($payload,$i,4)))return [];$id=substr($payload,$i,2);$n=(int)substr($payload,$i+2,2);$i+=4;if($i+$n>strlen($payload))return [];$out[$id]=substr($payload,$i,$n);$i+=$n;}return $out;
}
function pixRecipient(string $payload): string {
    $fields=pixFields($payload);$account=pixFields($fields['26'] ?? '');$key=$account['01'] ?? '';
    if($key==='')return 'Confira o recebedor no aplicativo do banco antes de pagar.';
    $bank=$key===PIX_KEY?'Pix':($key==='legacy@example.invalid'?'Pix — cobrança anterior':'instituição a conferir no banco');
    return ($fields['59'] ?? 'Recebedor a conferir').' · '.$bank.' · Chave: '.$key;
}
function pixMoney(int $c): string { return 'R$ '.number_format($c/100,2,',','.'); }
function pixAlertsReady(): bool {
    return defined('ORDER_ALERT_TO') && function_exists('orderAlertSend') && function_exists('orderAlertActions');
}
function pixSendOrderAlert(PDO $pdo,int $id,bool $retry=false): string {
    // Optional notifications must not interrupt a committed purchase or the order list.
    if(!pixAlertsReady())return '[PEDIDOS-01] Alerta indisponível: atualize tickets.php. O pedido foi preservado.';
    try{return orderAlertSend($pdo,$id,$retry);}
    catch(Throwable $e){return '[PEDIDOS-02] Alerta não concluído. O pedido foi preservado; confira pela Gestão.';}
}
function pixWhatsapp(string $raw): string {
    $raw=trim($raw);if($raw==='')return '';
    if(strlen($raw)>30||!preg_match('/^\+?[0-9 ()\-]+$/D',$raw))throw new RuntimeException('Informe um WhatsApp válido com DDD, por exemplo (51) 99999-9999.');
    $digits=preg_replace('/\D/','',$raw);
    if(in_array(strlen($digits),[12,13],true)&&str_starts_with($digits,'55'))$digits=substr($digits,2);
    if(!preg_match('/^[1-9][0-9](?:[2-5][0-9]{7}|9[0-9]{8})$/D',$digits))throw new RuntimeException('Informe um WhatsApp brasileiro com DDD e número completo.');
    return '+55'.$digits;
}
function pixValidity(array $d): string {
    $date=substr($d['starts'] ?? '',0,10);$second=$d['second_date'] ?? '';
    $format=fn($v)=>implode('/',array_reverse(explode('-',$v)));
    if($date!=='' && $second!=='')return 'Escolha '.$format($date).' ou '.$format($second).'. Cada ingresso vale somente para o dia escolhido. Para participar dos dois dias, faça uma compra para cada dia.';
    return $date!==''?'Cada ingresso é válido para '.$format($date).'.':'Data de validade a definir.';
}
function pixCents(string $s): int {
    if (!preg_match('/^([0-9]{1,6})(?:\.([0-9]{1,2}))?$/D',$s,$m)) return 0;
    return (int)$m[1]*100+(int)str_pad($m[2] ?? '',2,'0');
}
function pixCrc(string $s): string {
    $crc=0xffff;
    for($i=0;$i<strlen($s);$i++){ $crc^=ord($s[$i])<<8;for($j=0;$j<8;$j++)$crc=(($crc&0x8000)?($crc<<1)^0x1021:$crc<<1)&0xffff; }
    return strtoupper(str_pad(dechex($crc),4,'0',STR_PAD_LEFT));
}
function pixTlv(string $id,string $s): string { if(strlen($s)>99)throw new RuntimeException('Campo Pix inválido.');return $id.str_pad((string)strlen($s),2,'0',STR_PAD_LEFT).$s; }
function pixPayload(int $total,string $txid): string {
    if($total<1||!preg_match('/^[A-Za-z0-9]{1,25}$/D',$txid))throw new RuntimeException('Pedido inválido.');
    $s=pixTlv('00','01').pixTlv('26',pixTlv('00','br.gov.bcb.pix').pixTlv('01',PIX_KEY)).pixTlv('52','0000').pixTlv('53','986').pixTlv('54',sprintf('%d.%02d',intdiv($total,100),$total%100)).pixTlv('58','BR').pixTlv('59',PIX_NAME).pixTlv('60',PIX_CITY).pixTlv('62',pixTlv('05',$txid)).'6304';
    return $s.pixCrc($s);
}
function pixSchema(PDO $pdo): void {
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bailare_orders (id $id, event_id INTEGER NOT NULL, event_title VARCHAR(160) NOT NULL, buyer_name VARCHAR(160) NOT NULL, buyer_email VARCHAR(200) NOT NULL, quantity INTEGER NOT NULL, unit_cents INTEGER NOT NULL, total_cents INTEGER NOT NULL, status VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, txid VARCHAR(25) NOT NULL UNIQUE, payload TEXT NOT NULL, ip_hash VARCHAR(64) NOT NULL, created_at VARCHAR(30) NOT NULL, paid_at VARCHAR(30), paid_by INTEGER, payment_ref VARCHAR(40) UNIQUE, validity_text TEXT)".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    $columns=$pdo->query($mysql?'SHOW COLUMNS FROM bailare_orders':'PRAGMA table_info(bailare_orders)')->fetchAll();
    if(!in_array('validity_text',array_column($columns,$mysql?'Field':'name'),true)){
        try{$pdo->exec('ALTER TABLE bailare_orders ADD COLUMN validity_text TEXT');}
        catch(PDOException $e){$pdo->query('SELECT validity_text FROM bailare_orders LIMIT 1');}
    }
    ticketSchema($pdo);
    capacitySchema($pdo);
    demoSchema($pdo);
    foreach(['archived_at'=>'VARCHAR(30)','void_reason'=>'VARCHAR(300)','buyer_whatsapp'=>'VARCHAR(20)'] as $column=>$type)if(!in_array($column,array_column($columns,$mysql?'Field':'name'),true)){
        try{$pdo->exec('ALTER TABLE bailare_orders ADD COLUMN '.$column.' '.$type);}catch(PDOException $e){$pdo->query('SELECT '.$column.' FROM bailare_orders LIMIT 1');}
    }
}
function pixPage(string $title): void {
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    pageStart($title);echo '<main class="auth pix-page"><p class="eyebrow">Ingressos · Pix</p><h1>'.h($title).'</h1>';
}
function pixFinish(): never { echo '</main><script src="qrcode.js" defer></script><script src="pix.js" defer></script>';pageEnd();exit; }
function pixOpenEvent(?array $event): bool {
    return false; // Portfolio edition: real purchases are deliberately disabled.
    $d=$event['data'] ?? [];
    return ($d['status'] ?? '')==='published' && ($d['ticket_state'] ?? '')==='pix' && pixCents((string)($d['price'] ?? ''))>0 && count(pixSaleDates($d))>0;
}
function pixSaleDates(array $d): array {
    $dates=[substr($d['starts'] ?? '',0,10)];if(!empty($d['second_date']))$dates[]=$d['second_date'];
    return array_values(array_filter(array_unique($dates),function($date){$v=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $v&&$v->format('Y-m-d')===$date&&$date>=date('Y-m-d');}));
}
function pixChosenDate(array $d,string $chosen): string {
    if(!in_array($chosen,pixSaleDates($d),true))throw new RuntimeException('Escolha uma data disponível para este evento.');
    return $chosen;
}
function pixPublic(PDO $pdo): never {
    $error='';$token=(string)($_GET['pedido'] ?? '');
    try {
        // Public traffic must not run database migrations.
        $pdo->query('SELECT id FROM bailare_orders LIMIT 1');
        if($token!==''){
            if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Pedido não encontrado.');
            $q=$pdo->prepare('SELECT * FROM bailare_orders WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$order=$q->fetch();
            if(!$order){http_response_code(404);throw new RuntimeException('Pedido não encontrado.');}
            pixPage('Seu pedido');if(!empty($order['validity_text']))echo '<p class="notice">'.h($order['validity_text']).'</p>';echo '<h2>'.h($order['event_title']).'</h2><p>Pedido '.h($order['txid']).'</p><p>'.(int)$order['quantity'].' ingresso(s) × '.pixMoney((int)$order['unit_cents']).'</p><h2>Total: '.pixMoney((int)$order['total_cents']).'</h2>';
            if($order['status']==='paid'){notice('Pagamento confirmado pela Gestão.','success');ticketCards($pdo,(int)$order['id']);}
            elseif(in_array($order['status'],['cancelled','void_test'],true)){notice('Pedido cancelado ou teste invalidado. Não pague este código. Se já pagou, entre em contato com o estúdio.');}
            else {echo '<p class="notice">Aguardando pagamento e conferência manual. Se já pagou, não pague novamente.</p><div id="pix-qr" aria-label="QR Code para pagamento Pix"></div><label>Pix Copia e Cola<textarea id="pix-code" readonly rows="5">'.h($order['payload']).'</textarea></label><button type="button" class="primary" id="pix-copy">Copiar código Pix</button><p id="copy-status" role="status"></p><p>Recebedor: '.h(pixRecipient($order['payload'])).'</p><p>Confira o nome, a instituição e o valor no aplicativo antes de confirmar. Se algum dado não coincidir, não pague e procure o estúdio.</p><p>A geração do QR Code não confirma pagamento nem reserva assento. A Gestão verifica o recebimento na conta indicada neste pedido antes de aprovar. Guarde este link: após a confirmação, os ingressos ficarão disponíveis aqui. Se o envio de e-mail estiver ativado, também serão enviados links para seus ingressos.</p>';}
            echo '<a class="button secondary" href="admin.php?pedido='.h($token).'">Atualizar situação</a><p class="hint">Este link é privado. Compartilhe somente com quem participará deste pedido.</p>';pixFinish();
        }
        $eventId=filter_var($_GET['ticket'] ?? '',FILTER_VALIDATE_INT);$event=$eventId?findRecord($pdo,'events',(int)$eventId):null;
        if(!pixOpenEvent($event)){http_response_code(409);throw new RuntimeException('Ingressos indisponíveis. Aguarde a divulgação do preço e a abertura das vendas.');}
        $d=$event['data'];$unit=pixCents($d['price']);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            checkCsrf();$nonce=value('order_nonce',64,true);
            if(!preg_match('/^[a-f0-9]{64}$/D',$nonce)||!hash_equals($_SESSION['order_nonce_'.$eventId] ?? '',$nonce))throw new RuntimeException('Reabra o formulário para criar seu pedido.');
            $q=$pdo->prepare('SELECT id FROM bailare_orders WHERE token_hash=?');$q->execute([hash('sha256',$nonce)]);
            if($q->fetch())go('admin.php?pedido='.$nonce);
            $quantity=filter_var($_POST['quantity'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
            if(!$quantity)throw new RuntimeException('Escolha de 1 a 20 ingressos por pedido.');
            if((string)($_POST['unit_cents'] ?? '')!==(string)$unit)throw new RuntimeException('O preço mudou. Confira o novo total antes de continuar.');
            $chosen=pixChosenDate($d,value('selected_date',10,true));
            $name=value('buyer_name',160,true);$email=value('buyer_email',200,true);
            $whatsapp=pixWhatsapp(value('buyer_whatsapp',30,true));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
            $ip=hash('sha256',$_SERVER['REMOTE_ADDR'] ?? '');$q=$pdo->prepare('SELECT COUNT(*) FROM bailare_orders WHERE ip_hash=? AND created_at>?');$q->execute([$ip,date('c',time()-600)]);
            if((int)$q->fetchColumn()>=10){http_response_code(429);throw new RuntimeException('Muitos pedidos recentes. Aguarde alguns minutos antes de criar outro.');}
            $txid='B'.strtoupper(bin2hex(random_bytes(10)));$total=$unit*$quantity;$payload=pixPayload($total,$txid);
            $dates=[$chosen];$validity='Dia escolhido: '.date('d/m/Y',strtotime($chosen)).'. Cada ingresso vale somente para esta data. O outro dia exige uma nova compra.';
            $pdo->beginTransaction();capacityLock($pdo,(int)$eventId);
            $duplicate=$pdo->prepare('SELECT id FROM bailare_orders WHERE token_hash=?');$duplicate->execute([hash('sha256',$nonce)]);
            if($duplicate->fetch()){$pdo->commit();go('admin.php?pedido='.$nonce);}
            $current=findRecord($pdo,'events',(int)$eventId);
            if(!pixOpenEvent($current)||pixCents($current['data']['price'])!==$unit)throw new RuntimeException('As vendas ou o preço mudaram. Reabra a compra.');
            pixChosenDate($current['data'],$chosen);capacityCheck($pdo,(int)$eventId,$chosen,(int)$quantity);
            $q=$pdo->prepare('INSERT INTO bailare_orders(event_id,event_title,buyer_name,buyer_email,quantity,unit_cents,total_cents,status,token_hash,txid,payload,ip_hash,created_at,validity_text,validity_dates,buyer_whatsapp) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            try{$q->execute([$eventId,$d['title'],$name,$email,$quantity,$unit,$total,'pending',hash('sha256',$nonce),$txid,$payload,$ip,date('c'),$validity,json_encode($dates),$whatsapp]);}catch(PDOException $e){$dup=$pdo->prepare('SELECT id FROM bailare_orders WHERE token_hash=?');$dup->execute([hash('sha256',$nonce)]);if(!$dup->fetch())throw $e;}
            $created=$pdo->prepare('SELECT id FROM bailare_orders WHERE token_hash=?');$created->execute([hash('sha256',$nonce)]);$createdId=(int)$created->fetchColumn();
            $pdo->commit();pixSendOrderAlert($pdo,$createdId);go('admin.php?pedido='.$nonce);
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Bilheteria em preparação. Tente novamente mais tarde.';}
    pixPage('Ingressos juntos, um só Pix.');if($error)notice($error);
    if(isset($event)&&pixOpenEvent($event)){
        if($_SERVER['REQUEST_METHOD']==='GET' && isset($_SESSION['order_nonce_'.$eventId])){
            $used=$pdo->prepare('SELECT id FROM bailare_orders WHERE token_hash=?');$used->execute([hash('sha256',$_SESSION['order_nonce_'.$eventId])]);
            if($used->fetch())unset($_SESSION['order_nonce_'.$eventId]);
        }
        $_SESSION['order_nonce_'.$eventId] ??= bin2hex(random_bytes(32));
        echo '<p class="notice">'.h(pixValidity($event['data'])).'</p>';echo '<h2>'.h($event['data']['title']).'</h2><p>Valor por ingresso: '.pixMoney($unit).'</p><form method="post" id="pix-order" data-unit="'.$unit.'">'.csrf().'<input type="hidden" name="order_nonce" value="'.h($_SESSION['order_nonce_'.$eventId]).'"><input type="hidden" name="unit_cents" value="'.$unit.'">';
        input('buyer_name','Nome do comprador / responsável','text',$_POST['buyer_name'] ?? '',true,'maxlength="160" autocomplete="name"');input('buyer_email','E-mail para contato sobre o pedido','email',$_POST['buyer_email'] ?? '',true,'maxlength="200" autocomplete="email"');
        input('buyer_whatsapp','WhatsApp com DDD','tel',$_POST['buyer_whatsapp'] ?? '',true,'maxlength="30" autocomplete="tel" inputmode="tel" placeholder="(51) 99999-9999"');
        echo '<p class="hint">O WhatsApp do adulto responsável é obrigatório para que a Gestão possa entrar em contato caso haja algum problema com o pagamento deste pedido. O número fica disponível somente para a Gestão e não aparece no ingresso público. Os ingressos continuam sendo enviados por e-mail após a confirmação do pagamento. Nenhuma mensagem automática de WhatsApp será enviada.</p>';
        echo '<label>Dia da apresentação <span aria-label="obrigatório">*</span><select name="selected_date" required><option value="">Escolha o dia</option>';
        foreach(pixSaleDates($event['data']) as $date){$time=$date===substr($event['data']['starts'],0,10)?substr($event['data']['starts'],11,5):($event['data']['second_time'] ?? '');echo '<option value="'.h($date).'" '.(($_POST['selected_date'] ?? '')===$date?'selected':'').'>'.h(date('d/m/Y',strtotime($date)).($time!==''?' às '.$time:'')).'</option>';}
        echo '</select></label><p class="hint">Todos os ingressos deste pedido serão para o dia selecionado. Para ir ao outro dia, faça um novo pedido. Pedidos aguardando conferência ocupam vagas até serem confirmados ou cancelados pela Gestão.</p>';
        try{foreach(pixSaleDates($event['data']) as $saleDay)echo '<p>'.h(date('d/m/Y',strtotime($saleDay))).': '.max(0,capacityLimit($pdo,(int)$eventId,$saleDay)-capacityUsed($pdo,(int)$eventId,$saleDay)).' ingresso(s) disponíveis. A disponibilidade é conferida novamente ao gerar o pedido.</p>';}catch(Throwable $e){echo '<p>Disponibilidade será verificada ao gerar o pedido.</p>';}
        input('quantity','Quantidade de ingressos','number',$_POST['quantity'] ?? '1',true,'min="1" max="20" step="1" id="pix-quantity"');
        echo '<p id="pix-total" role="status">Total: '.pixMoney($unit).'</p><p class="hint">Informe os dados do adulto responsável, não da criança. Nome e e-mail são usados pela Gestão para atendimento e conferência. Quando habilitado, o envio dos ingressos é feito pela hospedagem ao e-mail informado, após a confirmação manual. Guarde o link do pedido para acessar os ingressos mesmo se o e-mail não chegar.</p><button class="primary">Gerar pedido e Pix</button></form>';
    }
    pixFinish();
}
function pixAdmin(PDO $pdo,array $user): never {
    if(!in_array($user['role'],['owner','manager'],true)){http_response_code(403);pageStart('Acesso restrito');echo '<main class="auth"><h1>Acesso restrito à Gestão.</h1><a href="admin.php">Voltar</a></main>';pageEnd();exit;}
    $error='';$success='';
    try {
        pixSchema($pdo);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(value('action',30)==='archive_cancelled'){
                checkCsrf();if(value('confirm_delete',1)!=='1')throw new RuntimeException('Confirme a retirada dos cancelados.');
                $pdo->beginTransaction();$pdo->prepare("UPDATE bailare_orders SET archived_at=? WHERE status='cancelled' AND archived_at IS NULL")->execute([date('c')]);audit($pdo,(int)$user['id'],'archive_cancelled',0);$pdo->commit();$_SESSION['flash']='Pedidos cancelados retirados da lista. O histórico foi preservado.';go('admin.php?payments=1');
            }
            checkCsrf();$id=(int)($_POST['order_id'] ?? 0);$q=$pdo->prepare('SELECT * FROM bailare_orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();
            if(value('action',30)==='send_order_alert'){
                if(!$o||$o['status']!=='pending'||value('confirm_send',1)!=='1')throw new RuntimeException('Confirme o envio do alerta de um pedido pendente.');
                $_SESSION['flash']=pixSendOrderAlert($pdo,$id,true);audit($pdo,(int)$user['id'],'send_order_alert',$id);go('admin.php?payments=1&q='.rawurlencode($o['txid']));
            }
            if(in_array(value('action',30),['archive_order','restore_order','void_test'],true)){
                pixManageOrder($pdo,$user,$id,value('action',30));$_SESSION['flash']='Pedido atualizado. Nenhum estorno bancário foi realizado.';go('admin.php?payments=1');
            }
            if(value('action',30)==='send_tickets'){
                if(!$o||$o['status']!=='paid'||value('confirm_send',1)!=='1')throw new RuntimeException('Confirme o envio de um pedido pago.');
                $pdo->beginTransaction();
                $lock=$pdo->prepare("UPDATE bailare_orders SET status=status WHERE id=? AND status='paid'");$lock->execute([$id]);
                ticketIssue($pdo,$o);audit($pdo,(int)$user['id'],'send_tickets',$id);$pdo->commit();
                $_SESSION['flash']=ticketSend($pdo,$id,true);go('admin.php?payments=1');
            }
            if(!$o||$o['status']!=='pending')throw new RuntimeException('Este pedido não está aguardando confirmação.');
            $action=value('action',30,true);$pdo->beginTransaction();
            if($action==='confirm_pix'){
                if(($_POST['verified'] ?? '')!=='1'||pixCents(str_replace(',','.',value('received',20,true)))!==(int)$o['total_cents'])throw new RuntimeException('Confira na conta recebedora deste pedido o recebimento do valor integral antes de confirmar.');
                $ref=strtoupper(value('payment_ref',40));if($ref==='')$ref=null;elseif(!preg_match('/^E[A-Z0-9]{31}$/D',$ref))throw new RuntimeException('O código Pix é opcional. Deixe vazio ou informe o EndToEnd completo de 32 caracteres, iniciado por E.');
                $q=$pdo->prepare("UPDATE bailare_orders SET status='paid',paid_at=?,paid_by=?,payment_ref=? WHERE id=? AND status='pending'");$q->execute([date('c'),$user['id'],$ref,$id]);
            }elseif($action==='cancel_pix'){$q=$pdo->prepare("UPDATE bailare_orders SET status='cancelled' WHERE id=? AND status='pending'");$q->execute([$id]);}
            else throw new RuntimeException('Operação inválida.');
            if($q->rowCount()!==1)throw new RuntimeException('Pedido já atualizado. Recarregue a página.');
            if($action==='confirm_pix')ticketIssue($pdo,$o);
            audit($pdo,(int)$user['id'],$action,$id);$pdo->commit();$success='Pedido atualizado. Nenhuma transferência ou devolução é feita por este painel.';
            if($action==='confirm_pix')$success.=' '.ticketSend($pdo,$id);
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível atualizar. Verifique se o identificador Pix já foi utilizado em outro pedido.';}
    pixPage('Pedidos e pagamentos');echo '<a href="admin.php">← Voltar à administração</a><p>Somente a Gestão pode consultar estes dados. Novos pedidos: recebimentos em '.h(PIX_NAME).' · Pix · '.h(PIX_KEY).'.</p><p>As vendas só abrem nos eventos publicados com preço definido e opção Pix selecionada. Os códigos não expiram automaticamente; cancelar aqui não invalida um Pix já copiado e não devolve dinheiro.</p>';
    echo '<p><a class="button secondary" href="admin.php?capacity=1">Lotação por dia</a> <a class="button secondary" href="admin.php?demo_send=1">Enviar ingresso de teste</a> <a class="button secondary" href="admin.php?mailsettings=1">Configurar e-mail dos ingressos</a> <a class="button secondary" href="admin.php?checkin=1">Portaria</a></p>';
    if(pixAlertsReady())echo '<p class="hint">Novos pedidos geram alerta para '.h(ORDER_ALERT_TO).', usando o SMTP configurado e ativado. O aviso não confirma pagamento. Confira também este painel: mensagens podem atrasar ou falhar. Falhas podem ser reenviadas nos detalhes do pedido.</p>';
    else notice('[PEDIDOS-01] Módulo de alertas desatualizado. Envie o tickets.php da mesma atualização do pix.php. Os pedidos continuam disponíveis abaixo; o alerta por e-mail não está disponível.');
    if(isset($_SESSION['flash'])){notice($_SESSION['flash'],'success');unset($_SESSION['flash']);}
    if($error)notice($error);if($success)notice($success,'success');
    try{
        $totals=$pdo->query("SELECT COUNT(*) AS orders_count, COALESCE(SUM(total_cents),0) AS total,COALESCE(SUM(quantity),0) AS tickets FROM bailare_orders WHERE status='paid'")->fetch();
        echo '<section class="card"><h2>Confirmado: '.pixMoney((int)$totals['total']).'</h2><p>'.(int)$totals['orders_count'].' pedido(s) · '.(int)$totals['tickets'].' ingresso(s). Totais registrados manualmente, não saldo bancário.</p></section>';
        echo '<details><summary>Limpar pedidos cancelados</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="archive_cancelled"><label class="check-label"><input type="checkbox" name="confirm_delete" value="1" required> Retirar todos os cancelados da lista principal, preservando o histórico.</label><button class="secondary">Excluir cancelados da lista</button></form></details>';
        $archived=($_GET['archived'] ?? '')==='1';$filter=(string)($_GET['status'] ?? '');if(!in_array($filter,['pending','paid','cancelled','void_test'],true))$filter='';
        $search=substr(trim((string)($_GET['q'] ?? '')),0,160);
        echo '<form method="get" class="order-filters"><input type="hidden" name="payments" value="1"><label>Buscar comprador ou pedido<input name="q" value="'.h($search).'" maxlength="160"></label>';
        selectField('status','Situação',[''=>'Todas','pending'=>'Aguardando conferência','paid'=>'Pagos','cancelled'=>'Cancelados','void_test'=>'Testes invalidados'],$filter);selectField('archived','Lista',['0'=>'Pedidos visíveis','1'=>'Excluídos da lista'],$archived?'1':'0');echo '<button class="secondary">Filtrar</button></form>';
        $where=$archived?'archived_at IS NOT NULL':'archived_at IS NULL';$args=[];if($filter!==''){$where.=' AND status=?';$args[]=$filter;}if($search!==''){$where.=' AND (buyer_name LIKE ? OR txid LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        $page=max(1,min(100000,(int)($_GET['page'] ?? 1)));$offset=($page-1)*30;$q=$pdo->prepare('SELECT * FROM bailare_orders WHERE '.$where.' ORDER BY id DESC LIMIT 30 OFFSET '.$offset);$q->execute($args);$rows=$q->fetchAll();
        if(!$rows)echo '<p>Nenhum pedido nesta página.</p>';
        foreach($rows as $o){
            $statusLabel=['pending'=>'Aguardando conferência','paid'=>'Pago','cancelled'=>'Cancelado','void_test'=>'Teste invalidado'][$o['status']] ?? 'Consultar';
            try{$dates=implode(' e ',array_map(fn($d)=>date('d/m/Y',strtotime($d)),ticketOrderDates($o)));}catch(Throwable $e){$dates='Conferir data';}
            echo '<details class="order-row"><summary><strong>'.h($o['buyer_name']).'</strong><span>'.h($dates).' · '.(int)$o['quantity'].' ingresso(s)</span><b>'.pixMoney((int)$o['total_cents']).'</b><span class="badge">'.h($statusLabel).'</span><span>Conferir / detalhes ▾</span></summary><div class="order-body"><h2>'.h($o['event_title']).'</h2><p>'.h($o['txid']).' · '.h($o['created_at']).'</p><p>'.h($o['buyer_email']).'</p><p>'.h($o['validity_text']).'</p><p class="hint">Recebedor deste pedido: '.h(pixRecipient($o['payload'])).'</p>';
            if($o['status']==='pending'){echo '<details><summary>Conferir recebimento</summary><form method="post">'.csrf().'<input type="hidden" name="action" value="confirm_pix"><input type="hidden" name="order_id" value="'.(int)$o['id'].'">';input('received','Valor recebido na conta deste pedido (R$)','number','',true,'step="0.01" min="0.01"');input('payment_ref','Código Pix do comprovante (opcional)','text','',false,'maxlength="32"');echo '<p class="hint">Pode deixar vazio. Sem este código, o sistema não consegue detectar se o mesmo recebimento foi usado em outro pedido: confira isso na conta recebedora deste pedido antes de confirmar.</p>';echo '<label><input type="checkbox" name="verified" value="1" required> Conferi na conta recebedora deste pedido o valor, o pagador e a referência deste pedido; não estou usando apenas um comprovante enviado.</label><button class="primary">Confirmar pagamento manualmente</button></form></details><details><summary>Cancelar pedido pendente</summary><p>Não faça isso se o pagamento já foi recebido. Este botão não efetua estorno.</p><form method="post">'.csrf().'<input type="hidden" name="action" value="cancel_pix"><input type="hidden" name="order_id" value="'.(int)$o['id'].'"><button class="secondary">Confirmar cancelamento</button></form></details>';}
            elseif($o['status']==='paid')echo '<p>Conferido em '.h($o['paid_at']).' · usuário #'.(int)$o['paid_by'].'<br>Pix: '.h($o['payment_ref'] ?: 'não informado — conferência manual').'</p>';
            if($o['status']==='paid')ticketMailActions($pdo,$o);
            echo '<p>WhatsApp: '.h(($o['buyer_whatsapp'] ?? '') ?: 'Não informado').'</p>';
            if(pixAlertsReady()){
                try{orderAlertActions($pdo,$o);}
                catch(Throwable $e){notice('[PEDIDOS-02] Não foi possível consultar o alerta deste pedido. A conferência de pagamento continua disponível.');}
            }
            pixOrderRemovalForm($o);echo '</div></details>';
        }
        $query='admin.php?'.http_build_query(['payments'=>1,'archived'=>$archived?1:0,'status'=>$filter,'q'=>$search]);echo '<div class="actions">';if($page>1)echo '<a href="'.h($query.'&page='.($page-1)).'">← Anterior</a>';if(count($rows)===30)echo '<a href="'.h($query.'&page='.($page+1)).'">Próxima →</a>';echo '</div>';
    }catch(Throwable $e){notice('Não foi possível carregar os pedidos. Consulte a configuração do banco.');}
    pixFinish();
}
function pixManageOrder(PDO $pdo,array $user,int $id,string $action): void {
    if(!in_array($user['role'],['owner','manager'],true))throw new RuntimeException('Somente a Gestão pode excluir pedidos.');
    if(value('confirm_delete',1)!=='1')throw new RuntimeException('Confirme a operação.');
    $q=$pdo->prepare('SELECT event_id FROM bailare_orders WHERE id=?');$q->execute([$id]);$event=$q->fetchColumn();if($event===false)throw new RuntimeException('Pedido não encontrado.');
    $pdo->beginTransaction();capacityLock($pdo,(int)$event);
    $pdo->prepare('UPDATE bailare_orders SET status=status WHERE id=?')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM bailare_orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();
    if($action==='void_test'){
        if(!in_array($o['status'],['pending','paid','cancelled'],true)||value('test_confirm',1)!=='1'||!hash_equals($o['txid'],value('confirm_txid',25,true)))throw new RuntimeException('Confirme que é teste e digite o código exato do pedido.');
        $reason=value('reason',300,true);
        $q=$pdo->prepare('SELECT COUNT(*) FROM bailare_entries e JOIN bailare_tickets t ON t.id=e.ticket_id WHERE t.order_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Pedido com entrada registrada não pode ser invalidado como teste.');
        $pdo->prepare("UPDATE bailare_orders SET status='void_test',archived_at=?,void_reason=? WHERE id=?")->execute([date('c'),$reason,$id]);
    }elseif($action==='archive_order'){
        if($o['status']==='pending')throw new RuntimeException('Cancele o pedido não pago ou invalide o teste antes de retirá-lo da lista.');
        $pdo->prepare('UPDATE bailare_orders SET archived_at=? WHERE id=?')->execute([date('c'),$id]);
    }elseif($action==='restore_order')$pdo->prepare('UPDATE bailare_orders SET archived_at=NULL WHERE id=?')->execute([$id]);
    else throw new RuntimeException('Operação inválida.');
    audit($pdo,(int)$user['id'],$action,$id);$pdo->commit();
}
function pixOrderRemovalForm(array $o): void {
    $id=(int)$o['id'];
    echo '<details><summary>Excluir / organizar pedido</summary><p>Retirar da lista preserva o histórico e não altera ingressos pagos nem vagas. Invalidar teste bloqueia seus ingressos e libera vagas, mas não devolve dinheiro. Nunca use para esconder uma venda real.</p>';
    if($o['status']!=='pending')echo '<form method="post">'.csrf().'<input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="action" value="'.(!empty($o['archived_at'])?'restore_order':'archive_order').'"><label class="check-label"><input type="checkbox" name="confirm_delete" value="1" required> Confirmo a alteração da lista, preservando o histórico.</label><button class="secondary">'.(!empty($o['archived_at'])?'Mostrar novamente na lista':'Excluir da lista').'</button></form>';
    if($o['status']!=='void_test'){
        echo '<form method="post">'.csrf().'<input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="action" value="void_test">';input('confirm_txid','Digite o código do pedido para invalidar o teste','text','',true,'maxlength="25" autocomplete="off"');input('reason','Motivo da invalidação do teste','text','',true,'maxlength="300"');echo '<label class="check-label"><input type="checkbox" name="test_confirm" value="1" required> É um teste identificado, não uma venda real. Entendo que não há estorno automático.</label><label class="check-label"><input type="checkbox" name="confirm_delete" value="1" required> Confirmo bloquear todos os ingressos deste pedido e devolver suas vagas.</label><button class="secondary">Invalidar teste e liberar vagas</button></form>';
    }elseif(!empty($o['void_reason']))echo '<p>Motivo: '.h($o['void_reason']).'</p>';
    echo '</details>';
}
