<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/functions.php';
$u=require_role(['supply_officer']);$err='';$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(empty($_POST['csrf'])||!hash_equals($_SESSION['p2csrf']??'',$_POST['csrf']))throw new RuntimeException('Invalid form session. Refresh the page.');
  if(($_POST['action']??'')==='add'){
   $name=trim((string)($_POST['name']??''));if(mb_strlen($name)<2||mb_strlen($name)>100)throw new RuntimeException('Classification name must be 2–100 characters.');
   db()->prepare('INSERT INTO item_classifications(classification_name) VALUES(?)')->execute([$name]);$msg='Classification added.';
  }elseif(($_POST['action']??'')==='toggle'){
   $id=(int)($_POST['id']??0);$st=db()->prepare('UPDATE item_classifications SET is_active=IF(is_active=1,0,1) WHERE classification_id=?');$st->execute([$id]);$msg='Classification updated.';
  }elseif(($_POST['action']??'')==='item'){
   $id=(int)($_POST['item_id']??0);$threshold=trim((string)($_POST['threshold']??''));$cls=(int)($_POST['classification_id']??0);
   if($threshold!==''&&(!ctype_digit($threshold)||(int)$threshold>100000000))throw new RuntimeException('Threshold must be a whole number.');
   if($cls){$chk=db()->prepare('SELECT 1 FROM item_classifications WHERE classification_id=?');$chk->execute([$cls]);if(!$chk->fetchColumn())throw new RuntimeException('Invalid classification.');}
   db()->prepare('UPDATE inventory_items SET classification_id=?,low_stock_threshold=? WHERE item_id=?')->execute([$cls?:null,$threshold===''?null:(int)$threshold,$id]);$msg='Inventory preferences updated.';
  }
 }catch(Throwable $e){$err=$e->getMessage();}
}
$_SESSION['p2csrf']=bin2hex(random_bytes(16));$token=$_SESSION['p2csrf'];
$classes=db()->query('SELECT * FROM item_classifications ORDER BY classification_name')->fetchAll();$items=db()->query('SELECT item_id,item_name,item_category,classification_id,low_stock_threshold FROM inventory_items ORDER BY item_name,item_id')->fetchAll();
$pageTitle='Inventory Classifications';$active='inventory';require __DIR__.'/includes/header.php'; ?>
<div class="content-header"><h2>Inventory Classifications & Stock Settings</h2><p>Tracking categories control item behavior; these custom classifications organize items.</p></div>
<?php if($err): ?><div class="card" role="alert" style="color:#a52424"><?= e($err) ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="color:#24632d"><?= e($msg) ?></div><?php endif; ?>
<div class="card"><h3>Add classification</h3><form method="post" style="display:flex;gap:10px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e($token) ?>"><input type="hidden" name="action" value="add"><input class="legacy-047" name="name" placeholder="e.g. Welding Tools" maxlength="100" required><button class="legacy-028">Add classification</button></form><div style="overflow:auto;margin-top:12px"><table class="legacy-085"><thead><tr><th>Classification</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($classes as $c): ?><tr><td><?= e($c['classification_name']) ?></td><td><?= $c['is_active']?'Active':'Inactive' ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['classification_id'] ?>"><button class="legacy-028"><?= $c['is_active']?'Deactivate':'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card" style="margin-top:18px"><h3>Item classifications & low-stock thresholds</h3><p style="font-size:.88rem;color:#667085">These settings do not change past deliveries or item quantities. For changing an item's tracking category after issuance, use an approved data correction rather than a silent reclassification.</p><div style="overflow-x:auto"><table class="legacy-085"><thead><tr><th>Item</th><th>Tracking category</th><th>Custom classification</th><th>Low stock threshold</th><th>Save</th></tr></thead><tbody><?php foreach($items as $i): ?><tr><form method="post"><input type="hidden" name="csrf" value="<?= e($token) ?>"><input type="hidden" name="action" value="item"><input type="hidden" name="item_id" value="<?= (int)$i['item_id'] ?>"><td><?= e($i['item_name']) ?></td><td><?= e(str_replace('_',' ',ucwords($i['item_category'],'_'))) ?></td><td><select class="legacy-047" name="classification_id"><option value="">None</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['classification_id'] ?>" <?= (int)$i['classification_id']===(int)$c['classification_id']?'selected':'' ?>><?= e($c['classification_name']) ?></option><?php endforeach; ?></select></td><td><input class="legacy-047" name="threshold" type="number" min="0" max="100000000" value="<?= e($i['low_stock_threshold']??'') ?>" placeholder="Optional"></td><td><button class="legacy-028">Save</button></td></form></tr><?php endforeach; ?></tbody></table></div></div><p><a href="<?= e(url('inventory.php')) ?>">← Back to Inventory</a></p>
<?php require __DIR__.'/includes/footer.php'; ?>
