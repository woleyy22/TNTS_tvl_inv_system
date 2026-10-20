<?php
/** TNTS 4.7.2: batch balances, locked when issuing / retrieving. */
function tnts_batch_balances(PDO $pdo, int $itemId, bool $lock=false): array {
    $sql='SELECT b.batch_id,b.inventory_item_id,b.batch_number,b.expiration_date,b.received_qty,b.course_id,d.receiving_number,d.date_received,co.course_name FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id LEFT JOIN courses co ON co.course_id=b.course_id WHERE b.inventory_item_id=? ORDER BY CASE WHEN b.expiration_date IS NULL THEN 1 ELSE 0 END,b.expiration_date,d.date_received,b.batch_id';
    if ($lock) $sql.=' FOR UPDATE';
    $st=$pdo->prepare($sql);$st->execute([$itemId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $aggregate=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN a.qty_issued ELSE 0 END),0) gross,COALESCE(SUM(a.qty_serviceable_returned),0) good_returns FROM ics_batch_allocations a JOIN ics_items ii ON ii.ics_item_id=a.ics_item_id WHERE a.batch_id=?');
    foreach($rows as &$b){$aggregate->execute([(int)$b['batch_id']]);$a=$aggregate->fetch(PDO::FETCH_ASSOC);$b['gross_allocated']=(int)$a['gross'];$b['good_returned']=(int)$a['good_returns'];$b['remaining_raw']=max(0,(int)$b['received_qty']-$b['gross_allocated']+$b['good_returned']);$b['expired']=$b['expiration_date']!==null && $b['expiration_date']<date('Y-m-d');}unset($b);
    return $rows;
}
/** Reserve older unallocated issues and withdrawals conservatively from FIFO batches. */
function tnts_batch_issueable(PDO $pdo,int $itemId,int $masterAvailable,bool $isSupply,bool $lock=false): array {
    $rows=tnts_batch_balances($pdo,$itemId,$lock);
    $s=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued ELSE 0 END),0) gross,COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued-ii.qty_returned ELSE 0 END),0) active FROM ics_items ii WHERE ii.item_id=?');$s->execute([$itemId]);$whole=$s->fetch(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN a.qty_issued ELSE 0 END),0) gross,COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN a.qty_issued-a.qty_returned ELSE 0 END),0) active FROM ics_batch_allocations a JOIN ics_items ii ON ii.ics_item_id=a.ics_item_id WHERE ii.item_id=?');$s->execute([$itemId]);$linked=$s->fetch(PDO::FETCH_ASSOC);
    // Historical ICS had no source-batch identity, including items later returned.
    // Reserve their full original issue quantity rather than guessing where returned
    // physical units belong; a later supervised reconciliation can assign a batch.
    $unattributed=max(0,(int)$whole['gross']-(int)$linked['gross']);
    $s=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_withdrawals WHERE item_id=?');$s->execute([$itemId]);$unattributed+=(int)$s->fetchColumn();
    // Unknown historical allocations might have originated from ANY batch.
    // For issue safety, reserve usable batches FIRST, then expired batches.
    // This deliberately avoids overstating usable stock on dated inventory.
    $priority=array_keys($rows);
    usort($priority,static function(int $a,int $b) use($rows):int {
        $aExpired=$rows[$a]['expired']?1:0;
        $bExpired=$rows[$b]['expired']?1:0;
        return ($aExpired<=>$bExpired)?:($a<=>$b);
    });
    foreach($rows as &$b){$b['legacy_reserved']=0;}unset($b);
    foreach($priority as $index){
        $held=min($unattributed,$rows[$index]['remaining_raw']);
        $unattributed-=$held;
        $rows[$index]['legacy_reserved']=$held;
    }
    foreach($rows as &$b){$b['available']=$b['expired']?0:max(0,$b['remaining_raw']-$b['legacy_reserved']);}unset($b);
    // Master check remains authoritative for non-batch inventory condition balances.
    return ['batches'=>$rows,'cap'=>max(0,$masterAvailable),'unattributed_remainder'=>$unattributed];
}
