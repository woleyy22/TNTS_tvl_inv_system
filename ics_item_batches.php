<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$me=require_login();
$lineId=(int)($_GET['ics_item_id']??0);
if($lineId<=0){http_response_code(400);exit('Select a valid ICS item.');}
$st=db()->prepare('SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,ii.course_id,i.item_name,i.description,i.unit,i.item_category,i.low_stock_threshold,c.ics_number,c.date_issued,c.teacher_id,c.recipient_name_snapshot,u.full_name AS teacher_name,co.course_name FROM ics_items ii JOIN inventory_items i ON i.item_id=ii.item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=ii.course_id WHERE ii.ics_item_id=?');
$st->execute([$lineId]);$icsBatchItem=$st->fetch(PDO::FETCH_ASSOC);
if(!$icsBatchItem){http_response_code(404);exit('ICS item not found.');}
if($me['role']==='teacher' && (int)$icsBatchItem['teacher_id']!==(int)$me['id']){http_response_code(403);exit('You can only view your own ICS allocations.');}
if(!in_array($me['role'],['teacher','supply_officer','tvl_head'],true)){http_response_code(403);exit('Not authorized.');}
$st=db()->prepare('SELECT a.allocation_id,a.qty_issued,a.qty_returned,a.qty_transferred_out,a.qty_serviceable_returned,b.batch_id AS source_batch_id,b.batch_number,b.expiration_date,b.received_qty,d.receiving_number,d.date_received,d.supplier_name,di.description AS delivery_description,co.course_name AS delivery_course FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id LEFT JOIN courses co ON co.course_id=b.course_id WHERE a.ics_item_id=? ORDER BY CASE WHEN b.expiration_date IS NULL THEN 1 ELSE 0 END,b.expiration_date,b.batch_id');
$st->execute([$lineId]);$allocations=$st->fetchAll(PDO::FETCH_ASSOC);
$known=array_sum(array_map(static fn($b)=>(int)$b['qty_issued'],$allocations));
$today=date('Y-m-d');$warningCutoff=date('Y-m-d',strtotime('+30 days'));
$pageTitle='ICS Batch & Expiry Details';$active='inventory';require __DIR__.'/includes/header.php';
?>
<div class="content-header"><h2>ICS Item — Batch &amp; Expiry Details</h2><p>Trace the receiving batches that supplied <?= e($icsBatchItem['item_name']) ?> on <?= e($icsBatchItem['ics_number']) ?>.</p></div>
<div class="card">
<p><a href="<?= e(url('inventory.php?view=issued')) ?>">← Back to Accountable Inventory</a></p>
<div style="display:flex;flex-wrap:wrap;gap:18px;margin:12px 0 20px">
<div><small>Item</small><br><strong><?= e($icsBatchItem['item_name']) ?></strong><br><small><?= e($icsBatchItem['description']?:'No additional description') ?></small></div>
<div><small>ICS / Teacher</small><br><strong><?= e($icsBatchItem['ics_number']) ?></strong><br><?= e($icsBatchItem['teacher_name']) ?></div>
<div><small>Issued</small><br><strong><?= (int)$icsBatchItem['qty_issued'] ?> <?= e($icsBatchItem['unit']) ?></strong></div>
<div><small>ICS Course Classification</small><br><strong><?= e($icsBatchItem['course_name']?:'Unclassified') ?></strong></div>
<div><small>Low-stock Threshold (Central)</small><br><strong><?= $icsBatchItem['low_stock_threshold']===null?'Not set':(int)$icsBatchItem['low_stock_threshold'] ?></strong></div>
</div>
<div style="overflow-x:auto"><table class="p471-batch-table" style="width:100%;min-width:1050px;border-collapse:collapse"><thead><tr><th>Item / Description</th><th>Receiving Number</th><th>Delivery Date</th><th>Batch Number</th><th>Batch Course Purpose</th><th>Qty Issued from Batch</th><th>Verified Returned</th><th>Current Unreturned Qty</th><th>Expiry</th><th>Expiry Status</th></tr></thead><tbody>
<?php foreach($allocations as $a):
$expiry=$a['expiration_date'];$expired=$expiry!==null&&$expiry<$today;$soon=$expiry!==null&&!$expired&&$expiry<=$warningCutoff;
$remaining=max(0,(int)$a['qty_issued']-(int)$a['qty_returned']-(int)$a['qty_transferred_out']-(int)$a['qty_transferred_out']);
?><tr>
<td><strong><?= e($icsBatchItem['item_name']) ?></strong><br><small><?= e($a['delivery_description']?:$icsBatchItem['description']?:'—') ?></small></td>
<td><?= e($a['receiving_number']) ?></td><td><?= e($a['date_received']) ?></td>
<td><?= e($a['batch_number']?:('Batch #'.$a['source_batch_id'])) ?></td>
<td><?= e($a['delivery_course']?:'Unclassified') ?></td>
<td><?= (int)$a['qty_issued'] ?> <?= e($icsBatchItem['unit']) ?></td>
<td><?= (int)$a['qty_returned'] ?> · Transferred <?= (int)$a['qty_transferred_out'] ?> (<?= (int)$a['qty_serviceable_returned'] ?> serviceable)</td>
<td><?= $remaining ?></td><td><?= e($expiry?:'Not recorded') ?></td>
<td><span class="p471-status <?= $expired?'expired':($soon?'soon':'ok') ?>"><?= $expired?'Expired — not usable':($soon?'Expiring soon':($expiry?'Usable':'Expiry not recorded')) ?></span></td>
</tr><?php endforeach; ?>
<?php if(!$allocations): ?><tr><td colspan="10">Historical ICS record: no verified source-batch allocation exists. The item remains accountable, but its original receiving batch cannot be inferred reliably.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if($known>0 && $known<(int)$icsBatchItem['qty_issued']): ?><p class="p471-note">Only <?= $known ?> of the <?= (int)$icsBatchItem['qty_issued'] ?> originally issued units have a recorded source batch. The balance remains unattributed.</p><?php endif; ?>
<p class="p471-note">Expiry is assessed from the delivery batch. An expired batch is automatically blocked from further issuance; it is <strong>not automatically disposed</strong>. Expired or damaged items must follow the authorized condemnation/disposal process.</p>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
