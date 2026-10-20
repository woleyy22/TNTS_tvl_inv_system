<?php
require_once __DIR__.'/includes/auth.php';
$me=require_role(['teacher']);
$pdo=db();$uid=(int)$me['id'];
$filter=(string)($_GET['status']??'all');if(!in_array($filter,['all','expired','soon','usable','unknown'],true))$filter='all';
$search=trim((string)($_GET['search']??''));
$sql="SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,i.item_name,i.description,i.unit,i.item_category,i.low_stock_threshold,c.ics_number,c.date_issued,a.qty_issued AS batch_qty,a.qty_returned AS batch_returned,b.batch_id,b.batch_number,b.expiration_date,d.receiving_number,d.date_received,co.course_name AS batch_course,ico.course_name AS ics_course
 FROM ics_batch_allocations a JOIN ics_items ii ON ii.ics_item_id=a.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id LEFT JOIN courses co ON co.course_id=b.course_id LEFT JOIN courses ico ON ico.course_id=ii.course_id WHERE c.teacher_id=? AND c.status='active'";
$params=[$uid];if($search!==''){$sql.=' AND (i.item_name LIKE ? OR c.ics_number LIKE ? OR b.batch_number LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
$sql.=' ORDER BY CASE WHEN b.expiration_date IS NULL THEN 1 ELSE 0 END,b.expiration_date ASC,c.date_issued DESC';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$today=date('Y-m-d');$soon=date('Y-m-d',strtotime('+30 days'));$filtered=[];$counts=['all'=>0,'expired'=>0,'soon'=>0,'usable'=>0,'unknown'=>0];
foreach($rows as $r){$qty=max(0,(int)$r['batch_qty']-(int)$r['batch_returned']);if($qty===0)continue;$expiry=$r['expiration_date'];$status=$expiry===null?'unknown':($expiry<$today?'expired':($expiry<=$soon?'soon':'usable'));$r['qty_held']=$qty;$r['expiry_status']=$status;$counts['all']+=$qty;$counts[$status]+=$qty;if($filter==='all'||$filter===$status)$filtered[]=$r;}
$legacySt=$pdo->prepare('SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,i.item_name,i.unit,c.ics_number,COALESCE((SELECT SUM(a.qty_issued) FROM ics_batch_allocations a WHERE a.ics_item_id=ii.ics_item_id),0) attributed FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id WHERE c.teacher_id=? AND c.status=\'active\'');$legacySt->execute([$uid]);$legacy=$legacySt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='My Batches & Expiry';$active='teacher_expiry.php';require __DIR__.'/includes/header.php';
?>
<div class="content-header"><h2>My Batches &amp; Expiry</h2><p>Trace each source batch assigned to your active ICS records. Expired materials are not automatically disposed.</p></div>
<div class="p473-tabs">
<?php foreach(['all'=>'All batches','expired'=>'Expired','soon'=>'Expiring in 30 days','usable'=>'Usable dated','unknown'=>'Expiry not recorded'] as $key=>$label): ?><a class="p473-tab <?= $filter===$key?'is-active':'' ?>" href="<?= e(url('teacher_expiry.php?status='.$key)) ?>"><?= e($label) ?> <strong><?= (int)$counts[$key] ?></strong></a><?php endforeach; ?>
</div>
<div class="card"><form method="get" class="p473-search"><input type="hidden" name="status" value="<?= e($filter) ?>"><label for="batchSearch">Search item, ICS or batch</label><input id="batchSearch" class="input" name="search" value="<?= e($search) ?>" placeholder="e.g. Flour, ICS-2026-001"><button class="btn btn-primary">Search</button><a class="btn btn-outline" href="<?= e(url('teacher_expiry.php')) ?>">Reset</a></form>
<div class="table-wrap"><table class="table p473-expiry"><thead><tr><th>Item / Description</th><th>ICS &amp; Course</th><th>Receiving No. / Delivery</th><th>Batch No.</th><th>Held Qty</th><th>Expiry</th><th>Status</th><th>Central low-stock threshold</th><th>Details</th></tr></thead><tbody>
<?php foreach($filtered as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong><small><?= e($r['description']?:$r['item_category']) ?></small></td><td><?= e($r['ics_number']) ?><small><?= e($r['ics_course']?:'Unclassified') ?></small></td><td><?= e($r['receiving_number']) ?><small><?= e($r['date_received']) ?></small></td><td><?= e($r['batch_number']?:('Batch #'.$r['batch_id'])) ?><small><?= e($r['batch_course']?:'Unclassified batch') ?></small></td><td><?= (int)$r['qty_held'] ?> <?= e($r['unit']) ?></td><td><?= e($r['expiration_date']?:'Not recorded') ?></td><td><span class="p473-state p473-<?= e($r['expiry_status']) ?>"><?= e(ucfirst($r['expiry_status'])) ?></span></td><td><?= $r['low_stock_threshold']===null?'Not set':(int)$r['low_stock_threshold'] ?></td><td><a href="<?= e(url('ics_item_batches.php?ics_item_id='.$r['ics_item_id'])) ?>">View</a></td></tr><?php endforeach; ?>
<?php if(!$filtered): ?><tr><td colspan="9">No assigned batches match this filter.</td></tr><?php endif; ?>
</tbody></table></div>
<?php $unattributed=[];foreach($legacy as $old){$balance=max(0,(int)$old['qty_issued']-(int)$old['qty_returned']-(int)$old['attributed']);if($balance>0)$unattributed[]=$old; } ?>
<?php if($unattributed): ?><div class="p473-legacy"><strong>Historical ICS items without verified batch sources</strong><p>These items still appear in your inventory. Their receiving batches cannot be reconstructed safely.</p><?php foreach($unattributed as $old): ?><p><?= e($old['item_name']) ?> — <?= e($old['ics_number']) ?>: <?= max(0,(int)$old['qty_issued']-(int)$old['qty_returned']-(int)$old['attributed']) ?> <?= e($old['unit']) ?> <a href="<?= e(url('ics_item_batches.php?ics_item_id='.$old['ics_item_id'])) ?>">Details</a></p><?php endforeach; ?></div><?php endif; ?>
</div>
<p class="p473-note">Low-stock threshold is a central-inventory alert, not an expiry condition. Expired quantities cannot be newly issued and remain subject to the school's documented disposal process.</p>
<?php require __DIR__.'/includes/footer.php'; ?>
