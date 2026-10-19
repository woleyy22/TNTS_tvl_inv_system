<?php
/** TNTS v5.7 / Phase 3.1. Current physical custody is separate from historical ICS issuance. */
function ics31_is_equipment(string $category): bool {
    return in_array($category, ['equipment', 'non_consumable'], true);
}
function ics31_state(PDO $pdo, int $icsItemId, bool $lock = false): array {
    $stmt = $pdo->prepare('SELECT ii.ics_item_id, ii.ics_id, ii.qty_issued, ii.qty_returned, i.item_id, i.item_name, i.unit, i.item_category, '
      . 'b.qty_serviceable, b.qty_for_repair, b.qty_damaged, b.needs_review, '
      . "COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0) AS disposed_qty "
      . 'FROM ics_items ii JOIN inventory_items i ON i.item_id=ii.item_id '
      . 'LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id WHERE ii.ics_item_id=?'
      . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$icsItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('ICS item does not exist.');
    $row['is_equipment'] = ics31_is_equipment($row['item_category']);
    $row['disposed_qty'] = (int)$row['disposed_qty'];
    $row['remaining_qty'] = max(0, (int)$row['qty_issued'] - (int)$row['qty_returned'] - $row['disposed_qty']);
    foreach (['qty_serviceable', 'qty_for_repair', 'qty_damaged', 'needs_review'] as $k) $row[$k] = (int)($row[$k] ?? 0);
    if ($row['is_equipment'] && $row['qty_serviceable']+$row['qty_for_repair']+$row['qty_damaged'] !== $row['remaining_qty']) {
        throw new RuntimeException('Condition totals require reconciliation for '.$row['item_name'].'. Please contact the Supply Officer.');
    }
    return $row;
}
/** Deduct physically received units from current teacher custody, preferring matching condition. */
function ics31_retrieve_balances(array $state, array $received): array {
    $pools = ['serviceable'=>$state['qty_serviceable'], 'repair'=>$state['qty_for_repair'], 'damaged'=>$state['qty_damaged']];
    $need = array_sum($received);
    if ($need < 1 || $need > $state['remaining_qty']) throw new RuntimeException('Retrieval exceeds current teacher accountability.');
    $wasReclassified = false;
    foreach (['serviceable','repair','damaged'] as $type) {
        $toTake = $received[$type];
        $order = array_values(array_unique([$type, 'serviceable', 'repair', 'damaged']));
        foreach ($order as $pool) {
            $take = min($toTake, $pools[$pool]);
            if ($take && $pool !== $type) $wasReclassified = true;
            $pools[$pool] -= $take;
            $toTake -= $take;
            if ($toTake===0) break;
        }
        if ($toTake>0) throw new RuntimeException('Condition reconciliation failed. Review accountable quantities.');
    }
    return [$pools,$wasReclassified];
}
function ics31_save(PDO $pdo, int $id, array $pools, ?int $review = null): void {
    $sql = 'UPDATE ics_condition_balances SET qty_serviceable=?,qty_for_repair=?,qty_damaged=?';
    $args = [(int)$pools['serviceable'], (int)$pools['repair'], (int)$pools['damaged']];
    if ($review!==null) { $sql.=',needs_review=?'; $args[]=$review; }
    $sql.=' WHERE ics_item_id=?'; $args[]=$id;
    $pdo->prepare($sql)->execute($args);
}
