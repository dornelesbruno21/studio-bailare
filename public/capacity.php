<?php
if(!defined('BAILARE_PIX')){http_response_code(404);exit;}
function capacitySchema(PDO $pdo): void {$pdo->exec('CREATE TABLE IF NOT EXISTS bailare_capacity (event_id INTEGER NOT NULL, event_date VARCHAR(10) NOT NULL, extra INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(event_id,event_date))'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));}
function capacityUsed(PDO $pdo,int $event,string $date): int {
    $q=$pdo->prepare("SELECT quantity,validity_dates,validity_text FROM bailare_orders WHERE event_id=? AND status IN ('pending','paid')");$q->execute([$event]);$used=0;
    foreach($q as $o){try{$dates=ticketOrderDates($o);}catch(Throwable $e){$dates=[$date];}if(in_array($date,$dates,true))$used+=(int)$o['quantity'];}return $used;
}
function capacityLimit(PDO $pdo,int $event,string $date): int {$q=$pdo->prepare('SELECT extra FROM bailare_capacity WHERE event_id=? AND event_date=?');$q->execute([$event,$date]);return 200+min(50,max(0,(int)$q->fetchColumn()));}
function capacityLock(PDO $pdo,int $event): void {
    if(!$pdo->inTransaction())throw new RuntimeException('Reserva exige transação.');
    $q=$pdo->prepare("UPDATE bailare_records SET updated_at=updated_at WHERE id=? AND kind='events'");$q->execute([$event]);
}
function capacityCheck(PDO $pdo,int $event,string $date,int $quantity): void {
    $left=max(0,capacityLimit($pdo,$event,$date)-capacityUsed($pdo,$event,$date));
    if($quantity>$left)throw new RuntimeException($left===0?'Ingressos esgotados para este dia.':'Restam '.$left.' ingresso(s) para este dia. Ajuste a quantidade.');
}
function capacityAdmin(PDO $pdo,array $user): never {
    if(!in_array($user['role'],['owner','manager'],true)){http_response_code(403);pixPage('Acesso restrito');notice('Somente a Gestão libera ingressos extras.');pixFinish();}
    $message='';try{
        pixSchema($pdo);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            checkCsrf();$id=(int)value('event_id',20,true);$date=value('event_date',10,true);
            if(value('action',30)!=='release_50'||value('confirmed',1)!=='1')throw new RuntimeException('Confirme a liberação.');
            $pdo->beginTransaction();capacityLock($pdo,$id);$event=findRecord($pdo,'events',$id);
            if(!$event||!in_array($date,pixSaleDates($event['data']),true))throw new RuntimeException('Evento ou data indisponível.');
            if(capacityLimit($pdo,$id,$date)!==200)throw new RuntimeException('Os 50 extras já foram liberados para esse dia.');
            $pdo->prepare('INSERT INTO bailare_capacity(event_id,event_date,extra) VALUES(?,?,50)')->execute([$id,$date]);audit($pdo,(int)$user['id'],'release_50',$id);$pdo->commit();$message='Limite ampliado para 250 somente no dia '.date('d/m/Y',strtotime($date)).'.';
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message=$e instanceof PDOException?'Não foi possível liberar. Reabra a página e confira o limite.':$e->getMessage();}
    pixPage('Lotação por dia');echo '<a href="admin.php?payments=1">← Pedidos e pagamentos</a><p>200 por dia. A Gestão pode liberar mais 50 uma única vez por data. Pedidos pendentes e pagos ocupam vagas; cancelados não. Confira o banco antes de cancelar: o código Pix antigo continua pagável.</p>';if($message)notice($message);
    try{foreach(records($pdo,'events') as $event)foreach(pixSaleDates($event['data']) as $date){$id=$event['id'];$limit=capacityLimit($pdo,$id,$date);$used=capacityUsed($pdo,$id,$date);echo '<section class="card"><h2>'.h($event['data']['title']).'</h2><p>'.h(date('d/m/Y',strtotime($date))).' · '.$used.' comprometidos / '.$limit.' liberados · '.max(0,$limit-$used).' disponíveis</p>';if($limit===200)echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="release_50"><input type="hidden" name="event_id" value="'.$id.'"><input type="hidden" name="event_date" value="'.h($date).'"><label><input type="checkbox" name="confirmed" value="1" required> Confirmo que o local comporta 250 pessoas nesta data.</label><button class="primary">Liberar mais 50 neste dia</button></form>';else echo '<p>Os 50 extras já estão liberados.</p>';echo '</section>';}}catch(Throwable $e){notice('Não foi possível consultar a lotação.');}pixFinish();
}
