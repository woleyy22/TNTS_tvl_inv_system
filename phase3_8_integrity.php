<?php
/** TNTS v5.7 Phase 3.8: READ-ONLY diagnostics. No INSERT/UPDATE/DELETE/DDL. */
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user = require_login();
if (($user['role'] ?? '') !== 'supply_officer') { http_response_code(403); exit('Supply Officer only.'); }
$checks = [];
function audit38(PDO $pdo, array &$checks, string $label, string $sql, string $severity, string $explanation): void {
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $checks[] = compact('label','rows','severity','explanation') + ['error'=>null];
    } catch (Throwable $e) {
        $checks[] = compact('label','severity','explanation') + ['rows'=>[], 'error'=>'Unable to execute check: '.$e->getMessage()];
    }
}
$pdo=db();
audit38($pdo,$checks,'Equipment: original ICS quantity vs movement',
"SELECT c.ics_number, i.item_name, ii.ics_item_id, ii.qty_issued AS issued, ii.qty_returned AS retrieved,
 COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0) AS disposed,
 GREATEST(0,ii.qty_issued-ii.qty_returned-COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0)) AS accountable,
 COALESCE(b.qty_serviceable,0) AS serviceable, COALESCE(b.qty_for_repair,0) AS for_repair, COALESCE(b.qty_damaged,0) AS damaged,
 COALESCE(b.needs_review,0) AS needs_review
 FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
 LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id
 WHERE i.item_category IN ('equipment','non_consumable') AND
 (ii.qty_returned + COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0)>ii.qty_issued
 OR b.ics_item_id IS NULL OR COALESCE(b.needs_review,0)=1
 OR (COALESCE(b.qty_serviceable,0)+COALESCE(b.qty_for_repair,0)+COALESCE(b.qty_damaged,0)) <> GREATEST(0,ii.qty_issued-ii.qty_returned-COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0))) ORDER BY c.ics_number,ii.ics_item_id",
'warning','Each equipment ICS condition balance must match the remaining accountable quantity. Legacy records requiring review are listed.');
audit38($pdo,$checks,'Return-line totals vs retrieved quantities',
"SELECT ii.ics_item_id,c.ics_number,i.item_name,ii.qty_returned AS recorded_retrieved,
 COALESCE(SUM(rl.qty_serviceable+rl.qty_for_repair+rl.qty_damaged),0) AS lines_total
 FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
 LEFT JOIN ics_return_lines rl ON rl.ics_item_id=ii.ics_item_id
 WHERE i.item_category IN ('equipment','non_consumable') GROUP BY ii.ics_item_id,c.ics_number,i.item_name,ii.qty_returned
 HAVING recorded_retrieved <> lines_total ORDER BY ii.ics_item_id",
'review','Old retrievals may predate condition-aware return lines. These require examination, not automatic fixes.');
audit38($pdo,$checks,'Document numbers: duplicates',
"SELECT 'ICS' AS document_type,ics_number AS number,COUNT(*) AS occurrences FROM ics GROUP BY ics_number HAVING COUNT(*)>1
 UNION ALL SELECT 'REC',receiving_number,COUNT(*) FROM deliveries GROUP BY receiving_number HAVING COUNT(*)>1
 UNION ALL SELECT 'CF',form_number,COUNT(*) FROM condemnations WHERE form_number IS NOT NULL GROUP BY form_number HAVING COUNT(*)>1",
'error','Repeated official document identifiers must be investigated.');
audit38($pdo,$checks,'Approval and direct-disposal audit coverage',
"SELECT d.disposal_id,i.item_name,d.quantity,d.document_reference,d.disposed_at
 FROM central_condition_disposals d JOIN inventory_items i ON i.item_id=d.item_id ORDER BY d.disposal_id DESC",
'info','Direct central disposals are separate from the current CF register; Phase 7 must unify the printable register. Listed for traceability, not counted as an error.');
audit38($pdo,$checks,'Central stock: raw condition movement balances',
"SELECT i.item_id,i.item_name,i.quantity AS accepted,
 COALESCE((SELECT SUM(rl.qty_for_repair) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) AS repair_returns,
 COALESCE((SELECT SUM(r.quantity) FROM central_condition_restorations r WHERE r.item_id=i.item_id AND r.from_condition='for_repair'),0) AS repair_restored,
 COALESCE((SELECT SUM(rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) AS damaged_returns,
 COALESCE((SELECT SUM(r.quantity) FROM central_condition_restorations r WHERE r.item_id=i.item_id AND r.from_condition='damaged'),0) AS damaged_restored,
 COALESCE((SELECT SUM(d.quantity) FROM central_condition_disposals d WHERE d.item_id=i.item_id),0) AS central_disposed
 FROM inventory_items i WHERE i.item_category IN ('equipment','non_consumable') ORDER BY i.item_name,i.item_id",
'info','Movement audit only. Do not sum all these columns as additional stock; returns and restorations are transactions.');
audit38($pdo,$checks,'Delivery accepted quantity vs linked inventory batch',
"SELECT di.delivery_item_id,d.receiving_number,di.item_name,di.qty_accepted,
 COUNT(b.batch_id) AS linked_batches
 FROM delivery_items di JOIN deliveries d ON d.delivery_id=di.delivery_id
 LEFT JOIN inventory_receipt_batches b ON b.delivery_item_id=di.delivery_item_id
 WHERE di.qty_accepted>0 GROUP BY di.delivery_item_id,d.receiving_number,di.item_name,di.qty_accepted
 HAVING linked_batches <> 1 ORDER BY di.delivery_item_id",
'warning','An accepted line normally maps to one inventory receipt batch; inspect missing or multiple links.');
audit38($pdo,$checks,'Orphaned ICS references',
"SELECT ii.ics_item_id,ii.ics_id,ii.item_id FROM ics_items ii LEFT JOIN ics c ON c.ics_id=ii.ics_id LEFT JOIN inventory_items i ON i.item_id=ii.item_id WHERE c.ics_id IS NULL OR i.item_id IS NULL",
'error','ICS items must point to an existing ICS and inventory master.');
$pageTitle='Phase 3.8 — Integrity Audit'; $active='inventory'; require_once __DIR__.'/includes/header.php';
?>
<style>.audit38{max-width:1120px}.audit38 h1{font-size:26px}.audit38 section{background:#fff;border:1px solid #e0e5ed;border-radius:12px;margin:18px 0;padding:18px}.audit38 .muted{color:#64748b;font-size:13px}.audit38 table{border-collapse:collapse;width:100%;font-size:13px}.audit38 th,.audit38 td{border-bottom:1px solid #e3e7ee;padding:9px;text-align:left;white-space:nowrap}.audit38 .scroll{overflow:auto}.audit38 .flag{display:inline-block;border-radius:999px;background:#f2f4f7;padding:4px 10px;font-size:12px;font-weight:700}.audit38 .error{color:#a52b2b}.audit38 .warning{color:#985b00}.audit38 .info{color:#28674c}</style>
<div class="audit38"><p><a href="<?= e(url('inventory.php')) ?>">← Back to Inventory</a></p><h1>Phase 3.8 — Inventory Integrity Audit</h1><p class="muted">Read-only diagnostics. This page never updates, repairs, removes, or resets any records. Run against a backup/test database first.</p>
<?php foreach($checks as $check): ?><section><h2><?= e($check['label']) ?> <span class="flag <?= e($check['severity']) ?>"><?= count($check['rows']) ?> row(s)</span></h2><p class="muted"><?= e($check['explanation']) ?></p>
<?php if($check['error']): ?><p class="error"><?= e($check['error']) ?></p><?php elseif(!$check['rows']): ?><p class="muted">No matching records.</p><?php else: ?><div class="scroll"><table><thead><tr><?php foreach(array_keys($check['rows'][0]) as $col): ?><th><?= e(str_replace('_',' ',ucwords($col,'_'))) ?></th><?php endforeach;?></tr></thead><tbody><?php foreach($check['rows'] as $row):?><tr><?php foreach($row as $value):?><td><?= e((string)($value??'—')) ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endforeach;?></div>
<?php require_once __DIR__.'/includes/footer.php'; ?>
