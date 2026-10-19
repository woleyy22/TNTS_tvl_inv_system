<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=require_login();
if ($user['role']!=='supply_officer') { http_response_code(403); die('Only the Supply Officer may restore central stock conditions.'); }
$uid=(int)($user['id']??$user['user_id']);
function p36_counts(array $r): array {
 $repair=max(0,(int)$r['return_repair']-(int)$r['restore_repair']);
 $damaged=max(0,(int)$r['return_damaged']-(int)$r['restore_damaged']-(int)$r['central_disposed']);
 $available=max(0,(int)$r['quantity']-(int)$r['withdrawn']-(int)$r['active_balance']-$repair-$damaged-(int)$r['central_disposed']);
 return ['repair'=>$repair,'damaged'=>$damaged,'available'=>$available];
}
$select="SELECT i.item_id,i.item_name,i.unit,i.item_category,i.quantity,
 COALESCE((SELECT SUM(rl.qty_for_repair) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) return_repair,
 COALESCE((SELECT SUM(rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) return_damaged,
 COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='for_repair'),0) restore_repair,
 COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='damaged'),0) restore_damaged,
 COALESCE((SELECT SUM(z.quantity) FROM central_condition_disposals z WHERE z.item_id=i.item_id),0) central_disposed,
 COALESCE((SELECT SUM(ii.qty_issued-ii.qty_returned) FROM ics_items ii WHERE ii.item_id=i.item_id),0) active_balance,
 COALESCE((SELECT SUM(w.quantity) FROM inventory_withdrawals w WHERE w.item_id=i.item_id),0) withdrawn
 FROM inventory_items i WHERE i.item_category IN ('equipment','non_consumable')";
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 $itemId=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT);
 $quantity=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT);
 $condition=(string)($_POST['from_condition']??'');
 $notes=trim((string)($_POST['notes']??''));
 $action=(string)($_POST['action']??'restore');
 $document=trim((string)($_POST['document_reference']??''));
 $approved=!empty($_POST['approved_document_on_file']);
 if(!$itemId||!$quantity||$quantity<1||!in_array($condition,['for_repair','damaged'],true)||mb_strlen($notes)<10||!in_array($action,['restore','dispose'],true)){$error='Select an item and condition, enter a positive whole-unit quantity, and provide at least 10 characters of inspection/repair notes.';}
 else try {
   db()->beginTransaction();
   // Lock master item to serialize restoration with ICS issuance.
   $lock=db()->prepare('SELECT item_id FROM inventory_items WHERE item_id=? FOR UPDATE');$lock->execute([$itemId]);
   if(!$lock->fetchColumn())throw new RuntimeException('Item not found.');
   $q=db()->prepare($select.' AND i.item_id=?');$q->execute([$itemId]);$item=$q->fetch(PDO::FETCH_ASSOC);
   if(!$item)throw new RuntimeException('This item is not eligible for central condition management.');
   $counts=p36_counts($item);
   if($action==='dispose' && ($condition!=='damaged'||!$approved||$document===''||mb_strlen($document)>120)) throw new RuntimeException('Central disposal requires Damaged status, a supporting document reference, and confirmation of signed approval on file.');
   $remaining=$counts[$condition==='for_repair'?'repair':'damaged'];
   if($quantity>$remaining)throw new RuntimeException("Only {$remaining} unit(s) remain in that central condition.");
   if($action==='dispose'){
     $ins=db()->prepare('INSERT INTO central_condition_disposals (item_id,quantity,reason,document_reference,recorded_by) VALUES (?,?,?,?,?)');
     $ins->execute([$itemId,$quantity,$notes,$document,$uid]);
     $activity='Disposed Central Damaged Stock';
     $details="{$quantity} damaged unit(s) disposed; approval reference: {$document}. {$notes}";
   } else {
     $ins=db()->prepare('INSERT INTO central_condition_restorations (item_id,from_condition,quantity,notes,restored_by) VALUES (?,?,?,?,?)');
     $ins->execute([$itemId,$condition,$quantity,$notes,$uid]);
     $activity='Restored Central Stock';
     $details="{$quantity} unit(s) {$condition} → serviceable. {$notes}";
   }
   $log=db()->prepare("INSERT INTO history_logs (user_id,action,module,record_id,details) VALUES (?,?,'Maintenance',?,?)");
   $log->execute([$uid,$activity,$itemId,$details]);
   db()->commit();
   header('Location: central_stock_conditions.php?success='.($action==='dispose'?'disposed':'restored'));exit;
 } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e->getMessage();}
}
$rows=db()->query($select.' ORDER BY i.item_name,i.item_id')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Manage Central Stock Conditions';$active='inventory';require_once __DIR__.'/includes/header.php';
?>
<style>.p36-wrap{max-width:1050px}.p36-card{background:white;border:1px solid #dfe3ea;border-radius:14px;padding:20px;margin:16px 0}.p36-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.p36-grid label{display:grid;gap:6px;font-size:13px;font-weight:600}.p36-grid input,.p36-grid select,.p36-grid textarea{width:100%;padding:10px;border:1px solid #cad2dd;border-radius:7px}.p36-table{width:100%;border-collapse:collapse}.p36-table th,.p36-table td{padding:11px;border-bottom:1px solid #e8eaf0;text-align:left}.p36-note{color:#64748b;font-size:13px}.p36-btn{border:0;background:#870900;color:white;border-radius:7px;padding:10px 15px;cursor:pointer}.p36-error{background:#ffe6e6;color:#9b1515;padding:14px}.p36-success{background:#e2f8ec;color:#166c42;padding:14px}.p36-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.p36-toolbar input{padding:10px;border:1px solid #cad2dd;border-radius:8px;max-width:330px;width:100%}.p36-tag{display:inline-block;border-radius:18px;padding:4px 10px;font-size:12px;font-weight:700}.p36-ok{background:#e8f6ee;color:#166534}.p36-warn{background:#fff1d6;color:#925500}.p36-bad{background:#fee9e9;color:#a51c30}.p36-form-desc{border-left:3px solid #8c1434;padding-left:12px;color:#566479;font-size:13px}</style>
<div class="p36-wrap"><h2>Central Stock Condition Management</h2><p class="p36-note">Restore physically verified central For Repair or Damaged equipment to Serviceable. Only Serviceable central stock can be issued through ICS.</p><p><a href="<?= e(url('inventory.php')) ?>">← Back to Inventory</a></p>
<?php if($error):?><p class="p36-error" role="alert"><?= e($error) ?></p><?php endif;?>
<?php if(isset($_GET['success'])):?><p class="p36-success" role="status"><?= $_GET['success']==='disposed'?'Authorized damaged-stock disposition recorded.':'Condition restoration recorded; eligible stock updated.' ?></p><?php endif;?>
<div class="p36-card"><h3>Central condition balances</h3><div class="p36-toolbar"><input id="p36Search" type="search" placeholder="Search inventory items..." aria-label="Search central items"></div><div style="overflow-x:auto"><table class="p36-table"><thead><tr><th>Item</th><th>Available for ICS</th><th>For Repair</th><th>Damaged</th></tr></thead><tbody>
<?php foreach($rows as $r): $c=p36_counts($r);?><tr class="p36-stock-row"><td><?= e($r['item_name']) ?></td><td><span class="p36-tag p36-ok"><?= $c['available'] ?> <?= e($r['unit']) ?></span></td><td><span class="p36-tag p36-warn"><?= $c['repair'] ?></span></td><td><span class="p36-tag p36-bad"><?= $c['damaged'] ?></span></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="p36-card">
  <h3>Verify repair / restore to Serviceable</h3>
  <form method="post" id="p36Restore">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="restore">
    <div class="p36-grid">
      <label>
        Item
        <select name="item_id" id="p36Item" required>
          <option value="">Select equipment</option>
          <?php foreach($rows as $r): $c=p36_counts($r);if(!$c['repair']&&!$c['damaged'])continue;?>
            <option value="<?= (int)$r['item_id'] ?>" data-repair="<?= $c['repair'] ?>" data-damaged="<?= $c['damaged'] ?>"><?= e($r['item_name']) ?></option>
          <?php endforeach;?>
        </select>
      </label>
      <label>
        Previously recorded condition
        <select name="from_condition" id="p36Condition" required>
          <option value="for_repair">For Repair</option>
          <option value="damaged">Damaged</option>
        </select>
      </label>
      <label>
        <span>Quantity restored (maximum: <span id="p36Max">0</span>)</span>
        <input name="quantity" id="p36Qty" type="number" min="1" step="1" required>
      </label>
    </div>
    <p>
      <label style="display:block;font-weight:600">Inspection / repair notes (required)<br>
        <textarea name="notes" rows="3" minlength="10" maxlength="1000" required style="width:100%;max-width:760px;padding:10px" placeholder="Describe repair performed or verification of serviceability"></textarea>
      </label>
    </p>
    <p class="p36-note">This action only changes the condition of existing central stock. It does not create new stock or reverse an official disposal.</p>
    <button class="p36-btn" type="submit">Confirm restoration to Serviceable</button>
  </form>
</div>

<div class="p36-card">
  <h3>Dispose of Damaged Stock (Authorized)</h3>
  <p class="p36-form-desc">For units already returned to the Supply Office as Damaged. This immediately records a final disposition, without a separate pending condemnation request. A signed school approval must already exist; the Report of Damaged School Property alone is not authorization.</p>
  <form method="post" id="p36Dispose">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="dispose">
    <input type="hidden" name="from_condition" value="damaged">
    <div class="p36-grid">
      <label>
        Damaged equipment
        <select name="item_id" id="p36DisposeItem" required>
          <option value="">Select damaged equipment</option>
          <?php foreach($rows as $r):$c=p36_counts($r);if(!$c['damaged'])continue;?>
            <option value="<?= (int)$r['item_id'] ?>" data-max="<?= $c['damaged'] ?>"><?= e($r['item_name']) ?> (<?= $c['damaged'] ?> damaged)</option>
          <?php endforeach;?>
        </select>
      </label>
      <label>
        <span>Quantity to dispose (maximum: <span id="p36DisposeMax">0</span>)</span>
        <input type="number" name="quantity" min="1" step="1" required id="p36DisposeQty">
      </label>
      <label>
        Signed approval / document reference
        <input name="document_reference" maxlength="120" required placeholder="Approval memo / official reference">
      </label>
    </div>
    <p>
      <label style="display:block;font-weight:600">Disposition reason / notes (required)<br>
        <textarea name="notes" rows="3" minlength="10" maxlength="1000" required style="width:100%;max-width:760px;padding:10px" placeholder="Explain the damage and authorized disposal"></textarea>
      </label>
    </p>
    <p>
      <label><input type="checkbox" name="approved_document_on_file" value="1" required> I confirm that the school's signed disposal approval is on file and these physical units are approved for disposal.</label>
    </p>
    <button type="submit" class="p36-btn" style="background:#9a2433">Record Approved Disposal</button>
  </form>
</div>
</div>

<script>const p36Item=document.getElementById('p36Item'),p36Condition=document.getElementById('p36Condition'),p36Qty=document.getElementById('p36Qty');function p36Update(){let opt=p36Item.selectedOptions[0],max=Number(opt?.dataset[p36Condition.value==='for_repair'?'repair':'damaged']||0);document.getElementById('p36Max').textContent=max;p36Qty.max=max;p36Qty.disabled=max===0;if(max===0)p36Qty.value='';else if(Number(p36Qty.value)>max)p36Qty.value=max;}p36Item.addEventListener('change',p36Update);p36Condition.addEventListener('change',p36Update);p36Update();const p36DI=document.getElementById('p36DisposeItem'),p36DQ=document.getElementById('p36DisposeQty');function p36DU(){const max=Number(p36DI.selectedOptions[0]?.dataset.max||0);document.getElementById('p36DisposeMax').textContent=max;p36DQ.max=max;p36DQ.disabled=max===0;if(Number(p36DQ.value)>max||max===0)p36DQ.value='';}p36DI.addEventListener('change',p36DU);p36DU();document.getElementById('p36Search').addEventListener('input',e=>{const q=e.target.value.toLocaleLowerCase();document.querySelectorAll('.p36-stock-row').forEach(row=>row.hidden=!row.cells[0].textContent.toLocaleLowerCase().includes(q));});document.getElementById('p36Dispose').addEventListener('submit',e=>{if(!confirm('Permanently record the selected DAMAGED units as DISPOSED? This cannot be restored through ordinary maintenance and must have signed authorization on file.'))e.preventDefault();});document.getElementById('p36Restore').addEventListener('submit',e=>{if(!confirm('Confirm that the selected quantity was physically inspected and is now serviceable? This action will be permanently logged.'))e.preventDefault()});</script>
<?php require_once __DIR__.'/includes/footer.php'; ?>
