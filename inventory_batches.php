<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/batch_accounting.php';
$user=require_role(['supply_officer','tvl_head']);
$id=(int)($_GET['item_id']??0);
$st=db()->prepare('SELECT * FROM inventory_items WHERE item_id=?');$st->execute([$id]);$master=$st->fetch(PDO::FETCH_ASSOC);
if(!$master){http_response_code(404);exit('Inventory item not found.');}
$st=db()->prepare('SELECT b.batch_id,b.received_qty,b.batch_number,b.expiration_date,co.course_name,di.description,di.unit,di.unit_cost,di.qty_accepted,d.receiving_number,d.dr_number,d.po_number,d.supplier_name,d.date_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id LEFT JOIN courses co ON co.course_id=b.course_id WHERE b.inventory_item_id=? ORDER BY d.date_received DESC,b.batch_id DESC');
$st->execute([$id]);$batches=$st->fetchAll(PDO::FETCH_ASSOC);
$batchState=tnts_batch_issueable(db(),$id,PHP_INT_MAX,in_array($master['item_category'],['consumable','expiring_material'],true))['batches'];
$indexed=[];foreach($batchState as $b){$indexed[(int)$b['batch_id']]=$b;}
$holdersQ=db()->prepare("SELECT a.batch_id,c.ics_number,u.full_name,ii.course_id,a.qty_issued,a.qty_returned,a.qty_serviceable_returned,co.course_name AS issued_course FROM ics_batch_allocations a JOIN ics_items ii ON ii.ics_item_id=a.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=ii.course_id JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id WHERE b.inventory_item_id=? ORDER BY c.date_issued DESC,c.ics_id DESC");
$holdersQ->execute([$id]);$batchHolders=[];
foreach($holdersQ->fetchAll(PDO::FETCH_ASSOC) as $r){$batchHolders[(int)$r['batch_id']][]=$r;}

$pageTitle='Delivery Batch & Expiry Details';$active='inventory';require __DIR__.'/includes/header.php'; ?>
<div class="content-header"><h2>Delivery &amp; Expiry: <?= e($master['item_name']) ?></h2><p>Master #<?= $id ?> · Total accepted <?= (int)$master['quantity'] ?> <?= e($master['unit']) ?> · Low-stock threshold <?= $master['low_stock_threshold']===null?'Not set':(int)$master['low_stock_threshold'] ?></p></div>
<div class="card"><p><a href="<?= e(url('inventory.php?view=central')) ?>">← Central Inventory</a><?php if($user['role']==='supply_officer'): ?> · <a href="<?= e(url('inventory_categories.php')) ?>">Manage Batches &amp; Expiry</a><?php endif; ?></p>
<div style="overflow-x:auto"><table class="p471-batch-table"><thead><tr><th>Item / Description</th><th>Receiving No.</th><th>Delivery Date</th><th>Batch No.</th><th>Course Purpose</th><th>Quantity Accepted</th><th>Batch Remaining (before reservations)</th><th>Legacy / Withdrawal Reserved</th><th>Usable for ICS</th><th>Expiry</th><th>Status</th><th>Supplier / DR / PO</th><th>Unit Cost</th></tr></thead><tbody>
<?php foreach($batches as $b):$expired=$b['expiration_date']&&$b['expiration_date']<date('Y-m-d');$soon=$b['expiration_date']&&$b['expiration_date']>=date('Y-m-d')&&$b['expiration_date']<=date('Y-m-d',strtotime('+30 days'));?><tr>
<td><strong><?= e($master['item_name']) ?></strong><br><?= e($b['description']??'') ?></td><td><?= e($b['receiving_number']) ?></td><td><?= e($b['date_received']) ?></td><td><?= e($b['batch_number']??'—') ?></td><td><?= e($b['course_name']??'Unclassified') ?></td><td><?= (int)$b['received_qty'] ?> <?= e($b['unit']) ?></td><td><?= (int)($indexed[(int)$b['batch_id']]['remaining_raw']??0) ?> <?= e($b['unit']) ?></td><td><?= (int)($indexed[(int)$b['batch_id']]['legacy_reserved']??0) ?></td><td><strong><?= (int)($indexed[(int)$b['batch_id']]['available']??0) ?></strong></td><td><?= e($b['expiration_date']??'Not recorded') ?></td><td><span class="p471-status <?= $expired?'expired':($soon?'soon':($b['expiration_date']?'ok':'')) ?>"><?= $expired?'Expired':($soon?'Expiring soon':($b['expiration_date']?'Dated / usable':'Unknown expiry')) ?></span></td><td><?= e($b['supplier_name']) ?><br><small><?= e($b['dr_number']?:'—') ?> / <?= e($b['po_number']?:'—') ?></small></td><td><?= e(display_unit_cost($b['unit_cost'])) ?></td></tr>
<?php if(!empty($batchHolders[(int)$b['batch_id']])): ?><tr><td colspan="13" style="background:#f8fafc"><strong>ICS allocations from this batch</strong><ul><?php foreach($batchHolders[(int)$b['batch_id']] as $holding): ?><li><?= e($holding['ics_number']) ?> — <?= e($holding['full_name']) ?> — issued <?= (int)$holding['qty_issued'] ?>, returned <?= (int)$holding['qty_returned'] ?> (usable returned <?= (int)$holding['qty_serviceable_returned'] ?>), course <?= e($holding['issued_course']?:'Unclassified') ?></li><?php endforeach ?></ul></td></tr><?php endif; ?>
<?php endforeach; ?>
<?php if(!$batches):?><tr><td colspan="13">No linked batches.</td></tr><?php endif;?></tbody></table></div>
<p class="p471-note">New ICS issues have recorded source-batch allocations and verified returns. Older ICS issues cannot be reliably assigned to specific historical batches, so they remain unattributed and reduce available quantities conservatively. Usable-for-ICS is conservatively reduced for historical unallocated issues and withdrawals. Nonserviceable central conditions also impose an aggregate limit when issuing. Expired means unavailable for issue—not automatically officially disposed.</p></div>
<?php require __DIR__.'/includes/footer.php'; ?>
