<?php
/** Phase 7.1 physical custody guards; invoke while ICS item row is locked FOR UPDATE. */
function p71_quantity(PDO $pdo, string $sql, array $args): int {
    $s=$pdo->prepare($sql);$s->execute($args);return (int)$s->fetchColumn();
}
function p71_availability(PDO $pdo, int $icsItemId, array $state): array {
    // These quantities cannot be reclassified by the ICS holder while outside custody
    // or while a pending release / transfer has reserved them.
    $borrowed=p71_quantity($pdo,"SELECT COALESCE(SUM(quantity),0) FROM borrow_logs WHERE ics_item_id=? AND status='borrowed'",[$icsItemId]);
    $queued=p71_quantity($pdo,"SELECT COALESCE(SUM(quantity),0) FROM borrow_requests WHERE ics_item_id=? AND status IN ('requested','approved')",[$icsItemId]);
    $transfers=p71_quantity($pdo,"SELECT COALESCE(SUM(quantity),0) FROM transfer_requests WHERE ics_item_id=? AND status IN ('requested','accepted','awaiting_finalization')",[$icsItemId]);
    $heldDamaged=p71_quantity($pdo,"SELECT COALESCE(SUM(quantity),0) FROM condemnations WHERE ics_item_id=? AND (status='requested' OR (status='approved' AND outcome IS NULL))",[$icsItemId]);
    return [
      'borrowed'=>$borrowed,'reserved'=>$queued+$transfers,
      'custody_inconsistent'=>$borrowed>(int)$state['qty_serviceable'],
      'reservation_inconsistent'=>($borrowed+$queued+$transfers)>(int)$state['qty_serviceable'],
      'serviceable'=>max(0,(int)$state['qty_serviceable']-$borrowed-$queued-$transfers),
      'damaged'=>$borrowed>(int)$state['qty_serviceable'] ? 0 : max(0,(int)$state['qty_damaged']-$heldDamaged),
      'pending_condemnation'=>$heldDamaged,
    ];
}
function p71_cf_number(PDO $pdo): string {
    // Race-safe sequence; unlike COUNT(*) it won't reuse numbers after record changes.
    $year=(int)date('Y');
    $s=$pdo->prepare('INSERT INTO condemnation_number_sequences(issue_year,last_number) VALUES(?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1)');
    $s->execute([$year]);
    $number=(int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if($number<1)throw new RuntimeException('Could not allocate a condemnation form number.');
    return sprintf('CF-%d-%03d',$year,$number);
}

/** Keep legacy open repair rows in step with current condition balances. */
function p71_close_repair_logs(PDO $pdo, int $icsItemId, int $qty, string $description, ?string $repairedDate=null): void {
    $stmt=$pdo->prepare("SELECT repair_id,quantity_affected FROM repair_logs WHERE ics_item_id=? AND condition_status='for_repair' ORDER BY repair_id FOR UPDATE");
    $stmt->execute([$icsItemId]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        if($qty<=0)break;
        $old=(int)$row['quantity_affected'];$take=min($qty,$old);
        if($take===$old){
            $pdo->prepare("UPDATE repair_logs SET condition_status='resolved',action_taken=?,date_repaired=? WHERE repair_id=?")
              ->execute([$description,$repairedDate,$row['repair_id']]);
        }else{
            $pdo->prepare('UPDATE repair_logs SET quantity_affected=? WHERE repair_id=?')->execute([$old-$take,$row['repair_id']]);
        }
        $qty-=$take;
    }
}
