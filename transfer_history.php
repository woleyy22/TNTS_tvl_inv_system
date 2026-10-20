<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=require_role(['teacher','supply_officer','tvl_head']);
$pdo=db();$uid=(int)$user['id'];$role=$user['role'];
$search=trim((string)($_GET['search']??''));$status=(string)($_GET['status']??'');
$direction=strtolower((string)($_GET['order']??'desc'))==='asc'?'ASC':'DESC';
$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
$valid=['requested','accepted','awaiting_finalization','declined','rejected','cancelled','completed'];
if(!in_array($status,$valid,true))$status='';
$conditions=[];$params=[];
if($role==='teacher'){$conditions[]='(r.from_teacher_id=? OR r.to_teacher_id=?)';array_push($params,$uid,$uid);}
if($search!==''){$conditions[]='(inv.item_name LIKE ? OR sender.full_name LIKE ? OR recipient.full_name LIKE ? OR source_ics.ics_number LIKE ? OR recipient_ics.ics_number LIKE ?)';$term='%'.$search.'%';for($i=0;$i<5;$i++)$params[]=$term;}
if($status!==''){$conditions[]='r.status=?';$params[]=$status;}
foreach([['from',$from,'>='],['to',$to,'<=']] as $f){if($f[1]!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$f[1])){$conditions[]='DATE(r.created_at) '.$f[2].' ?';$params[]=$f[1];}}
$sql="SELECT r.*,inv.item_name, inv.unit, sender.full_name AS sender_name, recipient.full_name AS recipient_name,
 source_ics.ics_number AS source_ics_no, recipient_ics.ics_number AS recipient_ics_no,
 b.batch_number,b.expiration_date,m.movement_id,m.created_at AS finalized_at
 FROM transfer_requests r
 JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id
 JOIN inventory_items inv ON inv.item_id=ii.item_id
 JOIN ics source_ics ON source_ics.ics_id=ii.ics_id
 JOIN users sender ON sender.user_id=r.from_teacher_id
 JOIN users recipient ON recipient.user_id=r.to_teacher_id
 LEFT JOIN ics_batch_allocations a ON a.allocation_id=r.allocation_id
 LEFT JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id
 LEFT JOIN ics_transfer_movements m ON m.transfer_request_id=r.request_id
 LEFT JOIN ics_items recipient_line ON recipient_line.ics_item_id=m.recipient_ics_item_id
 LEFT JOIN ics recipient_ics ON recipient_ics.ics_id=recipient_line.ics_id";
if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);
$sql.=' ORDER BY r.created_at '.$direction.', r.request_id '.$direction.' LIMIT 300';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$events=[];if($rows){$ids=array_map('intval',array_column($rows,'request_id'));$q=$pdo->prepare('SELECT ev.*,u.full_name AS actor FROM transfer_request_events ev JOIN users u ON u.user_id=ev.actor_user_id WHERE ev.transfer_request_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY ev.occurred_at,ev.event_id');$q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $event){$events[(int)$event['transfer_request_id']][]=$event;}}
$pageTitle='Transfer History';$active='transfer_history.php';include __DIR__.'/includes/header.php';
?>
<div class="page-head"><h1>Transfer History</h1><p>Search requests, review decisions, and open linked original or recipient ICS records. Read-only.</p></div>
<div class="card"><form method="get" class="p54-filters">
<label>Search name, item, or ICS<input name="search" value="<?= e($search) ?>" placeholder="Teacher, item or ICS number"></label>
<label>Status<select name="status"><option value="">All statuses</option><?php foreach($valid as $v):?><option value="<?= e($v) ?>" <?= $status===$v?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$v))) ?></option><?php endforeach; ?></select></label>
<label>Requested from<input type="date" name="from" value="<?= e($from) ?>"></label><label>Requested to<input type="date" name="to" value="<?= e($to) ?>"></label>
<label>Order<select name="order"><option value="desc" <?= $direction==='DESC'?'selected':'' ?>>Newest first</option><option value="asc" <?= $direction==='ASC'?'selected':'' ?>>Oldest first</option></select></label>
<button type="submit" class="btn btn-primary">Apply filters</button><a class="btn" href="transfer_history.php">Reset</a>
</form></div>
<div class="card"><h3>Transfer records (<?= count($rows) ?>)</h3><p class="muted">Displaying up to 300 matching requests. Transfer-origin ICS documents do not represent additional central issues.</p><div class="table-wrap"><table class="table"><thead><tr><th>Request / date</th><th>Item / quantity</th><th>From → To</th><th>ICS references</th><th>Batch</th><th>Status</th><th>Decision trail</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td>#<?= (int)$r['request_id'] ?><br><?= e($r['created_at']) ?></td><td><?= e($r['item_name']) ?><br><?= (int)$r['quantity'].' '.e($r['unit']) ?></td><td><?= e($r['sender_name'].' → '.$r['recipient_name']) ?></td><td>Original: <?= e($r['source_ics_no']) ?><?php if($r['recipient_ics_no']):?><br>Recipient: <?= e($r['recipient_ics_no']) ?><?php endif; ?></td><td><?= e($r['batch_number']??($r['legacy_unattributed']?'Legacy/unattributed':'Not specified')) ?><?php if($r['expiration_date']):?><br><?= e($r['expiration_date']) ?><?php endif; ?></td><td><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></td><td><details><summary>View events (<?= count($events[(int)$r['request_id']]??[]) ?>)</summary><?php foreach($events[(int)$r['request_id']]??[] as $ev):?><p class="p54-event"><strong><?= e($ev['event_type']) ?></strong> · <?= e($ev['occurred_at']) ?><br><?= e($ev['actor']) ?><?= $ev['notes']?' — '.e($ev['notes']):'' ?></p><?php endforeach; ?></details></td></tr><?php endforeach; ?>
<?php if(!$rows):?><tr><td colspan="7">No matching transfers.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__.'/includes/footer.php'; ?>
