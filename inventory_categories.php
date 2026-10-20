<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$me=require_role(['supply_officer']);
$uid=(int)$me['id'];
$error='';$message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $action=(string)($_POST['action']??'');
    if ($action==='save_threshold') {
      $id=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT);
      $raw=trim((string)($_POST['threshold']??''));
      if (!$id || ($raw!==''&&(!ctype_digit($raw)||(int)$raw>100000000))) throw new RuntimeException('Enter a valid non-negative low-stock threshold.');
      $row=db()->prepare('SELECT low_stock_threshold FROM inventory_items WHERE item_id=?');$row->execute([$id]);$old=$row->fetchColumn();
      if($old===false) throw new RuntimeException('Inventory item not found.');
      $new=$raw===''?null:(int)$raw;
      db()->prepare('UPDATE inventory_items SET low_stock_threshold=? WHERE item_id=?')->execute([$new,$id]);
      if(($old===null?null:(int)$old)!==$new) log_action($uid,'update_low_stock','Inventory',$id,'Low-stock threshold: '.($old??'unset').' -> '.($new??'unset'));
      $message='Low-stock threshold saved.';
    } elseif ($action==='save_batch') {
      $batchId=filter_var($_POST['batch_id']??null,FILTER_VALIDATE_INT);
      $courseRaw=trim((string)($_POST['course_id']??''));
      $courseId=null;
      if($courseRaw!=='') {
        if(!ctype_digit($courseRaw)||(int)$courseRaw<1) throw new RuntimeException('Invalid course selection.');
        $courseId=(int)$courseRaw;
      }
      $batchNo=trim((string)($_POST['batch_number']??''));
      $expiry=trim((string)($_POST['expiration_date']??''));
      if(!$batchId || strlen($batchNo)>80) throw new RuntimeException('Invalid batch number.');
      if($expiry!=='') {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);
        if(!$d||$d->format('Y-m-d')!==$expiry) throw new RuntimeException('Enter a valid expiry date.');
      }
      db()->beginTransaction();
      $st=db()->prepare('SELECT b.batch_id,b.batch_number,b.expiration_date,b.course_id,i.item_name FROM inventory_receipt_batches b JOIN inventory_items i ON i.item_id=b.inventory_item_id WHERE b.batch_id=? FOR UPDATE');
      $st->execute([$batchId]);$old=$st->fetch(PDO::FETCH_ASSOC);
      if(!$old) throw new RuntimeException('Batch not found.');
      if($courseId!==null){$st=db()->prepare('SELECT 1 FROM courses WHERE course_id=?');$st->execute([$courseId]);if(!$st->fetchColumn())throw new RuntimeException('Course no longer exists.');}
      $newExpiry=$expiry?:null;$newBatch=$batchNo?:null;
      db()->prepare('UPDATE inventory_receipt_batches SET batch_number=?,expiration_date=?,course_id=? WHERE batch_id=?')->execute([$newBatch,$newExpiry,$courseId,$batchId]);
      $changes=[];
      if(($old['batch_number']??null)!==$newBatch)$changes[]='batch number';
      if(($old['expiration_date']??null)!==$newExpiry)$changes[]='expiry';
      if(($old['course_id']===null?null:(int)$old['course_id'])!==$courseId)$changes[]='course '.($old['course_id']??'none').' -> '.($courseId??'none');
      if($changes)log_action($uid,'update_receipt_batch','Inventory Batches',$batchId,$old['item_name'].' batch #'.$batchId.': '.implode(', ',$changes));
      db()->commit();
      $message='Batch metadata saved. Historical ICS records are unchanged.';
    } else throw new RuntimeException('Unknown action.');
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e->getMessage();}
}
$courses=db()->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll(PDO::FETCH_ASSOC);
$items=db()->query('SELECT item_id,item_name,item_category,unit,low_stock_threshold FROM inventory_items ORDER BY item_name,item_id')->fetchAll(PDO::FETCH_ASSOC);
$batches=db()->query('SELECT b.batch_id,b.batch_number,b.expiration_date,b.course_id,b.received_qty,i.item_id,i.item_name,i.unit,d.receiving_number,d.date_received,di.qty_accepted FROM inventory_receipt_batches b JOIN inventory_items i ON i.item_id=b.inventory_item_id JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id ORDER BY d.date_received DESC,b.batch_id DESC')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Manage Batches & Expiry';$active='inventory';require __DIR__.'/includes/header.php'; ?>
<div class="content-header"><h2>Manage Batches &amp; Expiry</h2><p>Receipt-batch course purposes and dates, plus central inventory low-stock thresholds.</p></div>
<?php if($error):?><div class="card" role="alert" style="color:#9f2222"><?= e($error) ?></div><?php endif;?>
<?php if($message):?><div class="card" role="status" style="color:#1b6a45"><?= e($message) ?></div><?php endif;?>
<div class="card">
 <h3>Delivery batches</h3>
 <p class="p471-note">Courses are optional per receipt batch, independent of teachers' course assignments. Editing a batch does not change its accepted quantity or retroactively assign historical ICS units to that batch. Expired batches are flagged here; official disposal is a separate approved transaction.</p>
 <div style="overflow-x:auto"><table class="p471-batch-table"><thead><tr><th>Item / Unit</th><th>Receiving No. / Date</th><th>Accepted</th><th>Batch Number</th><th>Course Classification</th><th>Expiration Date</th><th>Status</th><th>Save</th></tr></thead><tbody>
 <?php foreach($batches as $b): $form='batch-'.(int)$b['batch_id'];$expired=$b['expiration_date']&&$b['expiration_date']<date('Y-m-d');$soon=$b['expiration_date']&&$b['expiration_date']>=date('Y-m-d')&&$b['expiration_date']<=date('Y-m-d',strtotime('+30 days')); ?>
 <tr>
 <td><strong><?= e($b['item_name']) ?></strong><br><small><?= e($b['unit']) ?></small></td><td><?= e($b['receiving_number']) ?><br><small><?= e($b['date_received']) ?></small></td><td><?= (int)$b['received_qty'] ?> <?= e($b['unit']) ?></td>
 <td><input class="legacy-047" form="<?= e($form) ?>" name="batch_number" maxlength="80" value="<?= e($b['batch_number']??'') ?>" placeholder="Optional"></td>
 <td><select class="legacy-047" form="<?= e($form) ?>" name="course_id"><option value="">Unclassified</option><?php foreach($courses as $c):?><option value="<?= (int)$c['course_id'] ?>" <?= (int)($b['course_id']??0)===(int)$c['course_id']?'selected':'' ?>><?= e($c['course_name']) ?></option><?php endforeach;?></select></td>
 <td><input class="legacy-047" form="<?= e($form) ?>" name="expiration_date" type="date" value="<?= e($b['expiration_date']??'') ?>"></td>
 <td><span class="p471-status <?= $expired?'expired':($soon?'soon':($b['expiration_date']?'ok':'')) ?>"><?= $expired?'Expired — block from use':($soon?'Expiring soon':($b['expiration_date']?'Dated / usable':'Not recorded')) ?></span></td>
 <td><form method="post" id="<?= e($form) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_batch"><input type="hidden" name="batch_id" value="<?= (int)$b['batch_id'] ?>"><button class="legacy-028" type="submit">Save</button></form></td>
 </tr><?php endforeach;?>
 <?php if(!$batches):?><tr><td colspan="8">No accepted delivery batches yet.</td></tr><?php endif;?></tbody></table></div>
</div>
<div class="card" style="margin-top:18px"><h3>Low-stock thresholds</h3><p class="p471-note">A low-stock threshold applies to the shared inventory item, not each receipt batch. Quantities are not edited here.</p>
<div style="overflow-x:auto"><table class="legacy-085"><thead><tr><th>Item</th><th>Category</th><th>Low-stock threshold</th><th>Save</th></tr></thead><tbody>
<?php foreach($items as $i):$form='threshold-'.(int)$i['item_id'];?><tr><td><?= e($i['item_name']) ?></td><td><?= e(ucwords(str_replace('_',' ',$i['item_category']))) ?></td><td><input class="legacy-047" form="<?= e($form) ?>" type="number" min="0" max="100000000" name="threshold" placeholder="Optional" value="<?= e($i['low_stock_threshold']??'') ?>"></td><td><form method="post" id="<?= e($form) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_threshold"><input type="hidden" name="item_id" value="<?= (int)$i['item_id'] ?>"><button class="legacy-028" type="submit">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<p><a href="<?= e(url('inventory.php?view=central')) ?>">← Back to Central Inventory</a></p>
<?php require __DIR__.'/includes/footer.php'; ?>
