<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/functions.php';
$u=require_role(['supply_officer']);$err='';$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['p2wdcsrf']??'',(string)($_POST['csrf']??'')))throw new RuntimeException('Form expired. Refresh before submitting.');
  $id=(int)($_POST['item_id']??0);$qty=(int)($_POST['quantity']??0);$type=(string)($_POST['withdrawal_type']??'');$reason=trim((string)($_POST['reason']??''));$dest=trim((string)($_POST['destination']??''));$doc=trim((string)($_POST['document_reference']??''));$approved=isset($_POST['approved_document_on_file'])?1:0;
  if($qty<1||!in_array($type,['donated','returned_to_supplier','disposed','other'],true)||mb_strlen($reason)<8)throw new RuntimeException('Valid quantity, disposition type, and a reason of at least 8 characters are required.');
  if(!$approved)throw new RuntimeException('Confirm that the required paper authorization is already on file.');
  db()->beginTransaction();
  // serialize all disposition operations, and prevent simultaneously changing unissued balances.
  db()->query('SELECT lock_id FROM inventory_aggregation_lock WHERE lock_id=1 FOR UPDATE')->fetchColumn();
  $st=db()->prepare('SELECT item_name,quantity FROM inventory_items WHERE item_id=? FOR UPDATE');$st->execute([$id]);$item=$st->fetch();if(!$item)throw new RuntimeException('Item not found.');
  $st=db()->prepare("SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=? AND c.status='active' AND ii.transfer_origin_request_id IS NULL");$st->execute([$id]);$issued=(int)$st->fetchColumn();
  $st=db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_withdrawals WHERE item_id=?');$st->execute([$id]);$withdrawn=(int)$st->fetchColumn();
  $free=(int)$item['quantity']-$issued-$withdrawn;if($qty>$free)throw new RuntimeException('Insufficient unissued stock. Available: '.max(0,$free).'. Issued items must be retrieved and reconciled first.');
  db()->prepare('INSERT INTO inventory_withdrawals(item_id,quantity,withdrawal_type,reason,destination,document_reference,approved_document_on_file,recorded_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$qty,$type,$reason,$dest?:null,$doc?:null,$approved,(int)$u['id']]);
  $wid=(int)db()->lastInsertId();db()->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES(?,'Inventory Withdrawal','Inventory',?,?)")->execute([(int)$u['id'],$id,"Withdrawal #$wid ($type), quantity $qty, reason: $reason, reference: $doc"]);
  db()->commit();$msg='Withdrawal recorded. The original delivery and inventory receipt remain unchanged.';
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}
$_SESSION['p2wdcsrf']=bin2hex(random_bytes(16));$token=$_SESSION['p2wdcsrf'];
$items=db()->query("SELECT i.item_id,i.item_name,i.unit,i.quantity,COALESCE((SELECT SUM(ii.qty_issued) FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=i.item_id AND c.status='active' AND ii.transfer_origin_request_id IS NULL),0) issued,COALESCE((SELECT SUM(w.quantity) FROM inventory_withdrawals w WHERE w.item_id=i.item_id),0) withdrawn FROM inventory_items i ORDER BY i.item_name")->fetchAll();
$history=db()->query('SELECT w.*,i.item_name,u.full_name FROM inventory_withdrawals w JOIN inventory_items i ON i.item_id=w.item_id JOIN users u ON u.user_id=w.recorded_by ORDER BY w.withdrawal_id DESC LIMIT 100')->fetchAll();
$pageTitle='Stock Withdrawal';$active='inventory';require __DIR__.'/includes/header.php'; ?>
<div class="content-header"><h2>Withdraw Inventory / Property Disposition</h2><p>For authorized donations, returns to supplier, and other documented removals from <strong>unissued stock only</strong>.</p></div>
<?php if($err): ?><div class="card" role="alert" style="color:#a52424"><?= e($err) ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="color:#23682c"><?= e($msg) ?></div><?php endif; ?>
<div class="card"><h3>New withdrawal</h3><p style="color:#8a5122">An issued or borrowed item cannot be withdrawn here. Use ICS retrieval and clearance before disposing of assigned property. Confirm the Principal/Supply Officer paper approval where required.</p>
<form method="post" id="p2withdraw" style="max-width:680px;display:grid;gap:12px"><input type="hidden" name="csrf" value="<?= e($token) ?>">
<label>Item<select class="legacy-047" name="item_id" required><option value="">Choose available inventory</option><?php foreach($items as $i): $free=max(0,(int)$i['quantity']-(int)$i['issued']-(int)$i['withdrawn']); if(!$free)continue; ?><option value="<?= (int)$i['item_id'] ?>"><?= e($i['item_name']) ?> — <?= $free ?> <?= e($i['unit']) ?> unissued</option><?php endforeach; ?></select></label>
<label>Quantity<input class="legacy-047" type="number" name="quantity" min="1" required></label>
<label>Disposition reason<select class="legacy-047" name="withdrawal_type" required><option value="donated">Donated to another school</option><option value="returned_to_supplier">Returned to supplier / source</option><option value="disposed">Authorized disposal (unissued stock only)</option><option value="other">Other</option></select></label>
<label>Destination / Receiving organization<input class="legacy-047" name="destination" maxlength="200"></label>
<label>Approval / document reference<input class="legacy-047" name="document_reference" maxlength="120" placeholder="e.g. signed transfer letter number"></label>
<label>Explanation<textarea class="legacy-047" name="reason" rows="3" minlength="8" required></textarea></label>
<label><input type="checkbox" name="approved_document_on_file" value="1" required> The appropriate signed authorization has been obtained and is on file.</label>
<button class="legacy-028">Record authorized withdrawal</button></form></div>
<div class="card" style="margin-top:16px;overflow-x:auto"><h3>Withdrawal history</h3><table class="legacy-085"><thead><tr><th>Date</th><th>Item</th><th>Quantity</th><th>Action</th><th>Destination</th><th>Reference</th><th>Recorded by</th></tr></thead><tbody><?php foreach($history as $h): ?><tr><td><?= e($h['created_at']) ?></td><td><?= e($h['item_name']) ?></td><td><?= (int)$h['quantity'] ?></td><td><?= e(str_replace('_',' ',$h['withdrawal_type'])) ?><div style="font-size:.8em"><?= e($h['reason']) ?></div></td><td><?= e($h['destination']??'—') ?></td><td><?= e($h['document_reference']??'—') ?></td><td><?= e($h['full_name']) ?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="7">No withdrawals recorded.</td></tr><?php endif; ?></tbody></table></div><p><a href="<?= e(url('inventory.php')) ?>">← Back to Inventory</a></p>
<script>document.getElementById('p2withdraw')?.addEventListener('submit',e=>{if(!confirm('Confirm inventory withdrawal? This will reduce available unissued stock and create an audit record.'))e.preventDefault()});</script>
<?php require __DIR__.'/includes/footer.php'; ?>
