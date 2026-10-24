<?php
/** Phase 7.3: conservative source declarations and paper-authorized disposal checks. */
function p73_valid_date(string $raw): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date !== false && $date->format('Y-m-d') === $raw && $raw <= date('Y-m-d');
}
/** Declared batch claims: requested/authorized reservations plus historical physical disposals.
 * Unknown batch isn't counted here because TNTS cannot identify its physical batch.
 */
function p73_batch_claims(PDO $pdo, array $icsIds): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$icsIds))));
    if(!$ids)return [];
    $args=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT allocation_id,COALESCE(SUM(quantity),0) AS claimed
       FROM condemnations WHERE ics_item_id IN ($args) AND allocation_id IS NOT NULL
       AND status IN ('requested','approved') GROUP BY allocation_id");
    $st->execute($ids);
    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[(int)$row['allocation_id']]=(int)$row['claimed'];
    return $rows;
}
/** Caller MUST lock its ICS item with FOR UPDATE before invoking this function.
 * The lock serializes CF requests against each other for the same accountable ICS line.
 * Batch is only a teacher-declared provenance; no batch issue/stock counter is moved.
 */
function p73_guard_source_claim(PDO $pdo,int $icsItemId,?int $allocationId,int $quantity,int $ignoreCondemnationId=0): void {
    if($allocationId===null)return;
    $st=$pdo->prepare('SELECT qty_issued,qty_returned,qty_transferred_out FROM ics_batch_allocations WHERE ics_item_id=? AND allocation_id=? FOR UPDATE');
    $st->execute([$icsItemId,$allocationId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('The declared source batch is no longer linked to this ICS item.');
    $unreturned=(int)$row['qty_issued']-(int)$row['qty_returned']-(int)$row['qty_transferred_out'];
    if($unreturned<0)throw new RuntimeException('The source batch quantities need reconciliation.');
    $q=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations
      WHERE ics_item_id=? AND allocation_id=? AND condemnation_id<>?
        AND status IN ('requested','approved')");
    $q->execute([$icsItemId,$allocationId,$ignoreCondemnationId]);
    $claimed=(int)$q->fetchColumn();
    if($claimed+$quantity>$unreturned)
        throw new RuntimeException('This source batch has insufficient unclaimed units for another CF. Choose a different known batch or Unknown / not sure.');
}
/** Condemnation holds must not exceed the presently damaged accountable units. */
function p73_validate_pending(PDO $pdo,int $icsItemId,array $state): void {
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations
      WHERE ics_item_id=? AND (status='requested' OR (status='approved' AND outcome IS NULL))");
    $stmt->execute([$icsItemId]);
    if((int)$stmt->fetchColumn()>(int)$state['qty_damaged'])
        throw new RuntimeException('Pending condemnation claims exceed damaged units. Reconcile this ICS before authorizing or disposing.');
}
