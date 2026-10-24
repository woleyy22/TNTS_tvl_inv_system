<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/functions.php';
$user=require_role(['supply_officer','tvl_head']);$pdo=db();
$checks=[
 ['Completed request without movement',"SELECT r.request_id AS reference FROM transfer_requests r LEFT JOIN ics_transfer_movements m ON m.transfer_request_id=r.request_id WHERE r.status='completed' AND m.movement_id IS NULL"],
 ['Movement for non-completed request',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN transfer_requests r ON r.request_id=m.transfer_request_id WHERE r.status<>'completed'"],
 ['Movement quantity differs from request',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN transfer_requests r ON r.request_id=m.transfer_request_id WHERE m.transferred_qty<>r.quantity"],
 ['Recipient ICS origin mismatch',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN ics_items ii ON ii.ics_item_id=m.recipient_ics_item_id WHERE ii.transfer_origin_request_id<>m.transfer_request_id OR ii.transfer_origin_request_id IS NULL OR ii.qty_issued<>m.transferred_qty"],
 ['Teacher mismatch after transfer',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN transfer_requests r ON r.request_id=m.transfer_request_id JOIN ics_items ii ON ii.ics_item_id=m.recipient_ics_item_id JOIN ics i ON i.ics_id=ii.ics_id WHERE i.teacher_id<>r.to_teacher_id"],
 ['Item mismatch after transfer',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN ics_items s ON s.ics_item_id=m.source_ics_item_id JOIN ics_items d ON d.ics_item_id=m.recipient_ics_item_id WHERE s.item_id<>d.item_id"],
 ['Source transferred out differs from ledger',"SELECT ii.ics_item_id AS reference FROM ics_items ii WHERE ii.qty_transferred_out<>(SELECT COALESCE(SUM(m.transferred_qty),0) FROM ics_transfer_movements m WHERE m.source_ics_item_id=ii.ics_item_id)"],
 ['Source batch transferred out differs from ledger',"SELECT a.allocation_id AS reference FROM ics_batch_allocations a WHERE a.qty_transferred_out<>(SELECT COALESCE(SUM(m.transferred_qty),0) FROM ics_transfer_movements m WHERE m.source_allocation_id=a.allocation_id)"],
 ['Transferred quantity exceeds source ICS',"SELECT ii.ics_item_id AS reference FROM ics_items ii WHERE ii.qty_transferred_out+ii.qty_returned>ii.qty_issued"],
 ['Missing recipient batch allocation',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m WHERE m.source_allocation_id IS NOT NULL AND m.recipient_allocation_id IS NULL"],
 ['Transfer batch mismatch',"SELECT m.transfer_request_id AS reference FROM ics_transfer_movements m JOIN ics_batch_allocations s ON s.allocation_id=m.source_allocation_id JOIN ics_batch_allocations d ON d.allocation_id=m.recipient_allocation_id WHERE s.batch_id<>d.batch_id OR d.ics_item_id<>m.recipient_ics_item_id OR d.qty_issued<>m.transferred_qty"]
];
$issues=[];$errors=[];foreach($checks as [$label,$sql]) {try{$rows=$pdo->query($sql.' LIMIT 101')->fetchAll(PDO::FETCH_COLUMN);foreach($rows as $reference)$issues[]=['label'=>$label,'reference'=>$reference];}catch(Throwable $e){$errors[]=$label.' — query could not be completed';}}
$totals=$pdo->query("SELECT COUNT(*) AS all_requests, COALESCE(SUM(status='completed'),0) AS completed FROM transfer_requests")->fetch(PDO::FETCH_ASSOC);
$movements=(int)$pdo->query('SELECT COUNT(*) FROM ics_transfer_movements')->fetchColumn();
$pageTitle='Transfer Reconciliation';$active='transfer_audit.php';include __DIR__.'/includes/header.php';
?>
<div class="page-head"><h1>Transfer Integrity Check</h1><p>Checks whether completed property transfers match their receiving ICS records, quantities and batch movements. This page never changes inventory.</p></div>
<div class="card"><div class="p54-summary"><div><strong><?= (int)$totals['all_requests'] ?></strong><span>All requests</span></div><div><strong><?= (int)$totals['completed'] ?></strong><span>Completed</span></div><div><strong><?= $movements ?></strong><span>Movement records</span></div><div><strong><?= count($issues) ?></strong><span>Findings</span></div></div>
<?php if($errors):?><p class="alert alert-danger"><strong>Audit incomplete:</strong> <?= e(implode('; ',$errors)) ?>. Do not treat this as a clean audit.</p><?php elseif(!$issues):?><p class="muted">No contradictions found by these transfer-specific checks. This is not a complete system certification.</p><?php endif; ?></div>
<div class="card"><h3>Transfer findings</h3><div class="table-wrap"><table class="table"><thead><tr><th>Finding</th><th>Reference</th></tr></thead><tbody><?php foreach($issues as $x):?><tr><td><?= e($x['label']) ?></td><td><?= e($x['reference']) ?></td></tr><?php endforeach; ?><?php if(!$issues):?><tr><td colspan="2">No findings.</td></tr><?php endif; ?></tbody></table></div><p class="muted">Older records without identified source batches and expired items require separate review. Any disposal must follow the school’s approved documentation process.</p></div>
<?php include __DIR__.'/includes/footer.php'; ?>
