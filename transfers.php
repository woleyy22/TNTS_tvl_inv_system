<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/transfer_foundation.php';
$user=require_role(['teacher','supply_officer','tvl_head']);$uid=(int)$user['id'];$role=$user['role'];$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST') {
 csrf_check();
 if($role==='tvl_head'){http_response_code(403);exit('Transfer management is read-only for TVL Head.');}
 try {
  $action=(string)($_POST['action']??'');
  if($action==='finalize') {
   require_once __DIR__.'/includes/transfer_finalization.php';
   $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($role!=='supply_officer'||!$requestId)throw new InvalidArgumentException('Only Supply Officer can finalize a verified transfer.');
   $result=tnts53_finalize($pdo,(int)$requestId,$uid);
   flash('success','Transfer finalized. Recipient ICS: '.$result['ics_number'].'. Original issuance preserved.');
   redirect('transfers.php');
  }
  if(in_array($action,['accept','decline','verify','reject','cancel'],true)) {
   $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$requestId)throw new InvalidArgumentException('Select a valid transfer request.');
   $note=trim((string)($_POST['decision_note']??''));
   if(mb_strlen($note)>1000)throw new InvalidArgumentException('Notes must not exceed 1000 characters.');
   if(in_array($action,['decline','reject'],true)&&$note==='')throw new InvalidArgumentException('A reason is required for declining or rejecting a request.');
   $pdo->beginTransaction();
   $q=$pdo->prepare('SELECT * FROM transfer_requests WHERE request_id=? FOR UPDATE');$q->execute([$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);
   if(!$request)throw new InvalidArgumentException('Transfer request not found.');
   $oldStatus=$request['status'];$newStatus='';
   if($action==='accept'||$action==='decline') {
    if($role!=='teacher'||$uid!==(int)$request['to_teacher_id'])throw new InvalidArgumentException('Only the designated receiving teacher may respond.');
    if($oldStatus!=='requested')throw new InvalidArgumentException('This request is no longer awaiting recipient response.');
    $newStatus=$action==='accept'?'accepted':'declined';
   }elseif($action==='verify'||$action==='reject') {
    if($role!=='supply_officer')throw new InvalidArgumentException('Only a Supply Officer can verify or reject transfers.');
    if($action==='verify'&&$oldStatus!=='accepted')throw new InvalidArgumentException('Recipient acceptance is required before verification.');
    if($action==='reject'&&!in_array($oldStatus,['requested','accepted'],true))throw new InvalidArgumentException('Only open requests can be rejected.');
    $newStatus=$action==='verify'?'awaiting_finalization':'rejected';
   }else {
    if($oldStatus!=='requested')throw new InvalidArgumentException('Only requests awaiting recipient response may be cancelled.');
    if($role!=='supply_officer'&&$uid!==(int)$request['requested_by']&&$uid!==(int)$request['from_teacher_id'])throw new InvalidArgumentException('You cannot cancel this request.');
    $newStatus='cancelled';
   }
   if($action==='verify') {
    $item=tnts51_state($pdo,(int)$request['ics_item_id'],true);
    if($item['ics_status']!=='active'||(int)$item['teacher_id']!==(int)$request['from_teacher_id'])throw new InvalidArgumentException('Source ICS accountability has changed; do not verify.');
    if((int)$item['state']['needs_review']===1)throw new InvalidArgumentException('Source ICS condition balance requires review first.');
    $target=$pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND role='teacher' AND status='active' AND registration_status='approved'");
    $target->execute([(int)$request['to_teacher_id']]);if(!$target->fetchColumn())throw new InvalidArgumentException('Receiving teacher is no longer active and approved.');
    // Eligible maximum already excludes this request's reservation, so add its quantity back when checking.
    if((int)$item['eligible_max']<0)throw new InvalidArgumentException('Source quantities require review.');
    if((int)$item['custody_remaining'] < (int)$request['quantity'])throw new InvalidArgumentException('Insufficient accountable quantity.');
    if($item['is_supply']) {
     if($request['declared_unused_qty']===null||(int)$request['declared_unused_qty']<(int)$request['quantity'])throw new InvalidArgumentException('Verified unused supply quantity must cover the request.');
     if($request['legacy_unattributed'])throw new InvalidArgumentException('Legacy supply batches must be reconciled before verification.');
    }
    if($request['allocation_id']!==null){
     $found=false;foreach(tnts51_batches($pdo,(int)$request['ics_item_id']) as $b){if((int)$b['allocation_id']===(int)$request['allocation_id']){
      $found=true;
      if($b['expiration_date']!==null&&$b['expiration_date']<date('Y-m-d'))throw new InvalidArgumentException('Source batch has expired since the request was made.');
      if((int)$b['qty_issued']-(int)$b['qty_returned']-(int)$b['qty_transferred_out']<(int)$request['quantity'])throw new InvalidArgumentException('Not enough remaining source batch quantity.');
      break;
     }}if(!$found)throw new InvalidArgumentException('Original source batch allocation is no longer available.');
    }elseif(!$request['legacy_unattributed'])throw new InvalidArgumentException('Missing source batch reference.');
    if($request['legacy_unattributed']&&$note==='')throw new InvalidArgumentException('Supply Officer must document the legacy batch review in verification notes.');
   }
   $up=$pdo->prepare('UPDATE transfer_requests SET status=?,updated_at=NOW() WHERE request_id=? AND status=?');
   $up->execute([$newStatus,$requestId,$oldStatus]);if($up->rowCount()!==1)throw new RuntimeException('The request was updated by someone else.');
   $pdo->prepare('INSERT INTO transfer_request_events(transfer_request_id,actor_user_id,event_type,notes) VALUES (?,?,?,?)')->execute([$requestId,$uid,$action,$note?:null]);
   $pdo->prepare('INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,?,?,?,?)')->execute([$uid,'Transfer '.ucfirst($action),'Transfers',$requestId,'Transfer request #'.$requestId.' '.$oldStatus.' → '.$newStatus.($note?' | '.$note:'')]);
   $pdo->commit();flash('success','Transfer #'.$requestId.' updated to '.str_replace('_',' ',$newStatus).'. ICS custody remains unchanged until finalized.');
   redirect('transfers.php');
  }
  if($action!=='request') throw new InvalidArgumentException('Invalid transfer action.');
  $line=filter_var($_POST['ics_item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $recipient=filter_var($_POST['to_teacher_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $from=filter_var($_POST['from_teacher_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $allocation=(($_POST['allocation_id']??'')==='')?null:filter_var($_POST['allocation_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $reason=trim((string)($_POST['reason']??''));
  $declared=(($_POST['unused_qty']??'')==='')?null:filter_var($_POST['unused_qty'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  $consumed=(($_POST['consumed_qty']??'')==='')?null:filter_var($_POST['consumed_qty'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  if(!$line||!$recipient||!$from||!$qty||$reason===''||mb_strlen($reason)>500||$allocation===false||$declared===false||$consumed===false) throw new InvalidArgumentException('Provide a valid item, teachers, quantity, and reason (maximum 500 characters).');
  $pdo->beginTransaction();
  $item=tnts51_state($pdo,(int)$line,true);
  if($item['ics_status']!=='active'||(int)$item['teacher_id']!==$from)throw new InvalidArgumentException('The item is not currently held by this teacher.');
  if($role==='teacher'&&$from!==$uid)throw new InvalidArgumentException('You may request transfers only for your own ICS items.');
  if($recipient===$from)throw new InvalidArgumentException('Choose a different receiving teacher.');
  $st=$pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND role='teacher' AND status='active' AND registration_status='approved'");$st->execute([$recipient]);if(!$st->fetchColumn())throw new InvalidArgumentException('Receiving teacher is unavailable or not approved.');
  if($qty>$item['eligible_max'])throw new InvalidArgumentException('Requested quantity exceeds eligible unreserved accountability ('.$item['eligible_max'].').');
  if($item['is_supply']&&$declared===null)throw new InvalidArgumentException('Count and declare unused physical quantity for a supply transfer.');
  if($item['is_supply']&&($declared>$item['custody_remaining']||$declared<$qty+$item['reserved_qty']))throw new InvalidArgumentException('Declared unused quantity does not support this request and pending reservations.');
  if($item['is_supply']&&$consumed!==null&&$consumed+$declared>$item['custody_remaining'])throw new InvalidArgumentException('Declared consumed plus unused exceeds current recorded custody.');
  $all=tnts51_batches($pdo,(int)$line);$chosen=null;
  if($allocation!==null){foreach($all as $a){if((int)$a['allocation_id']===$allocation){$chosen=$a;break;}}if(!$chosen)throw new InvalidArgumentException('Selected batch does not belong to this ICS item.');}
  if($chosen===null&&$all)throw new InvalidArgumentException('Select a source batch to preserve batch identity.');
  if($chosen){$batchLeft=max(0,(int)$chosen['qty_issued']-(int)$chosen['qty_returned']-(int)$chosen['qty_transferred_out']-(int)$chosen['reserved']);if($qty>$batchLeft)throw new InvalidArgumentException('Not enough unreserved quantity remains in the selected batch.');if($chosen['expiration_date']!==null&&$chosen['expiration_date']<date('Y-m-d'))throw new InvalidArgumentException('Expired batches cannot be transferred as usable stock.');}
  if(!$chosen&&$item['is_supply'])throw new InvalidArgumentException('Consumables require a verified batch before transfer; contact Supply Officer for legacy reconciliation.');
  if(!$chosen&&$role!=='supply_officer')throw new InvalidArgumentException('Historical ICS without a source batch requires Supply Officer review and request creation.');
  $st=$pdo->prepare("INSERT INTO transfer_requests (ics_item_id,allocation_id,from_teacher_id,to_teacher_id,quantity,declared_unused_qty,declared_consumed_qty,reason,requested_by,status,legacy_unattributed) VALUES (?,?,?,?,?,?,?,?,?,'requested',?)");
  $st->execute([$line,$allocation,$from,$recipient,$qty,$declared,$consumed,$reason,$uid,$chosen?0:1]);$requestId=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO transfer_request_events(transfer_request_id,actor_user_id,event_type,notes) VALUES (?,?, 'requested',?)")->execute([$requestId,$uid,'Request created, no change to ICS balances.']);
  $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,'Transfer Requested','Transfers',?,?)")->execute([$uid,$requestId,'Requested '.$qty.' '.$item['unit'].' of '.$item['item_name'].' for teacher #'.$recipient]);
  $pdo->commit();flash('success','Transfer request saved. Accountability has NOT moved; acceptance/finalization is not enabled yet.');
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();flash('error',$ex instanceof InvalidArgumentException?$ex->getMessage():'Unable to save transfer request. Check migration and try again.');}
 redirect('transfers.php');
}
$teachers=$pdo->query("SELECT user_id,full_name FROM users WHERE role='teacher' AND status='active' AND registration_status='approved' ORDER BY full_name")->fetchAll();
$items=$pdo->query("SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,i.item_name,i.item_category,i.unit,c.ics_number,c.teacher_id,u.full_name AS holder FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id WHERE c.status='active' ORDER BY u.full_name,i.item_name")->fetchAll();
if($role==='teacher')$items=array_values(array_filter($items,fn($v)=>(int)$v['teacher_id']===$uid));
$source=[];foreach($items as $v){try{$detail=tnts51_state($pdo,(int)$v['ics_item_id']);$batches=tnts51_batches($pdo,(int)$v['ics_item_id']);$source[]=['row'=>$v,'detail'=>$detail,'batches'=>$batches];}catch(Throwable $e){/* broken historical row omitted from request list; audit separately */}}
$sql="SELECT r.*,i.item_name,i.unit,i.item_category,c.ics_number,u.full_name from_name,v.full_name to_name,requester.full_name requested_by_name,b.batch_number,b.expiration_date FROM transfer_requests r JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=r.from_teacher_id JOIN users v ON v.user_id=r.to_teacher_id JOIN users requester ON requester.user_id=r.requested_by LEFT JOIN ics_batch_allocations a ON a.allocation_id=r.allocation_id LEFT JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id";
if($role==='teacher'){$sql.=' WHERE r.from_teacher_id=? OR r.to_teacher_id=?';$st=$pdo->prepare($sql.' ORDER BY r.created_at DESC,r.request_id DESC');$st->execute([$uid,$uid]);}else{$st=$pdo->query($sql.' ORDER BY r.created_at DESC,r.request_id DESC');}
$requests=$st->fetchAll();$pageTitle='Property Transfers';$active='transfers.php';include __DIR__.'/includes/header.php';
?>
<div class="p51-shell">
<h1>Property Transfers</h1><p class="muted">Phase 5.3 · Recipient accepts, Supply Officer verifies and finalizes to create a linked ICS without reissuing central stock.</p>
<?php if($role==='tvl_head'): ?><div class="card">Read-only: view transfer requests and history. Inventory editing is not available.</div><?php endif; ?>
<?php if($role!=='tvl_head'): ?>
<details class="card p51-create p511-create" open><summary><strong>+ New Transfer Request</strong><span class="muted">Select item → choose teacher → verify quantities</span></summary>
<form method="post" class="p51-form p511-form" id="p51Form"><?= csrf_field() ?><input type="hidden" name="action" value="request" />
<div class="p511-step"><div class="p511-heading"><span>1</span><strong>Choose property to transfer</strong></div>
<label>ICS Item / Current Holder<select name="ics_item_id" id="p51Line" required><option value="">Select an accountable ICS item...</option><?php foreach($source as $v):$r=$v['row'];?><option value="<?= (int)$r['ics_item_id'] ?>" data-from="<?= (int)$r['teacher_id'] ?>"><?= e($r['holder'].' — '.$r['item_name'].' — '.$r['ics_number'].' ('.$r['item_category'].')') ?></option><?php endforeach; ?></select></label>
<input type="hidden" id="p51From" name="from_teacher_id" />
<div id="p51Eligibility" class="p511-summary" role="status">Choose an item to see the quantity available for transfer, source batch, and category rules.</div></div>
<div class="p511-step"><div class="p511-heading"><span>2</span><strong>Recipient and source batch</strong></div>
<div class="p511-columns"><label>Receiving Teacher<select name="to_teacher_id" required><option value="">Choose another teacher...</option><?php foreach($teachers as $t):?><option value="<?= (int)$t['user_id'] ?>"><?= e($t['full_name']) ?></option><?php endforeach; ?></select></label>
<label>Source Batch<select name="allocation_id" id="p51Allocation"><option value="">Choose item first</option></select></label></div>
<div id="p511BatchHint" class="p511-hint">For known batches, select the matching original ICS allocation. Legacy items require Supply Officer review.</div></div>
<div class="p511-step"><div class="p511-heading"><span>3</span><strong>Count quantity and request transfer</strong></div>
<div class="p511-qty"><label>Quantity to transfer<input type="number" name="quantity" id="p51Qty" min="1" step="1" required placeholder="Enter quantity"></label><div class="p511-range-wrap"><label for="p511Range">Adjust quantity</label><input id="p511Range" type="range" min="1" max="1" value="1" disabled><span id="p511Limit">Select an item</span></div></div>
<div id="p511Supply" class="p511-supply" hidden><p><strong>For supplies:</strong> Count the unused items physically remaining. Already consumed is optional. These declarations do not change stock until later verification.</p><div class="p511-columns"><label>Unused physical quantity (required for supplies)<input type="number" name="unused_qty" id="p51Unused" min="0" step="1" placeholder="Physically counted amount"></label><label>Already consumed (optional)<input type="number" name="consumed_qty" id="p51Consumed" min="0" step="1" placeholder="If known"></label></div><p id="p511Remainder" class="p511-hint"></p></div>
<label>Reason for transfer<textarea name="reason" maxlength="500" rows="3" required placeholder="Why is the property being transferred?"></textarea></label>
<div class="p511-warning">Submitting creates a request. Recipient acceptance and Supply Officer verification follow; actual ICS movement happens only in Phase 5.3.</div>
<button type="submit" class="btn btn-primary">Submit Transfer Request</button></div>
</form></details>
<?php endif; ?>
<div class="card"><h2>Transfer Requests</h2><p class="muted">Receiving teacher: Accept or Decline. Supply Officer: Verify or Reject. A verified transfer can be finalized by the Supply Officer; original ICS issuance is preserved.</p><div class="p51-scroll"><table><thead><tr><th>Requested</th><th>Item / ICS</th><th>From → To</th><th>Quantity</th><th>Batch</th><th>Declared unused / consumed</th><th>Status</th><th>Next action</th></tr></thead><tbody>
<?php foreach($requests as $r):?><tr><td><?= e($r['created_at']) ?></td><td><strong><?= e($r['item_name']) ?></strong><br><small><?= e($r['ics_number']) ?></small></td><td><?= e($r['from_name'].' → '.$r['to_name']) ?></td><td><?= (int)$r['quantity'].' '.e($r['unit']) ?></td><td><?= e($r['batch_number']??($r['legacy_unattributed']?'Legacy / Unattributed':'Unspecified')) ?></td><td><?= $r['declared_unused_qty']===null?'—':(int)$r['declared_unused_qty'] ?> / <?= $r['declared_consumed_qty']===null?'—':(int)$r['declared_consumed_qty'] ?></td><td><span class="p51-status"><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></span></td><td>
<?php $actions=[];
if($role==='teacher'&&(int)$r['to_teacher_id']===$uid&&$r['status']==='requested')$actions=['accept'=>'Accept','decline'=>'Decline'];
elseif($role==='supply_officer'&&$r['status']==='accepted')$actions=['verify'=>'Verify','reject'=>'Reject'];
elseif($role==='supply_officer'&&$r['status']==='requested')$actions=['reject'=>'Reject','cancel'=>'Cancel'];
elseif($r['status']==='requested'&&($role==='supply_officer'||(int)$r['requested_by']===$uid||(int)$r['from_teacher_id']===$uid))$actions=['cancel'=>'Cancel'];
?>
<?php if($actions):?>
<details class="p52-actions"><summary>Review actions</summary>
<div class="p52-action-list"><?php foreach($actions as $action=>$title):?>
<form method="post" class="p52-action-form" onsubmit="return confirm('<?= e($title) ?> transfer request #<?= (int)$r['request_id'] ?>? Accountability will NOT move yet.');">
<?= csrf_field() ?><input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="p52-notes"><?= $action==='decline'||$action==='reject'?'Reason (required)':'Notes'.($action==='verify'?' (required for legacy items)':' (optional)') ?><textarea name="decision_note" rows="2" maxlength="1000" <?= in_array($action,['decline','reject'],true)?'required':'' ?> placeholder="Decision explanation"></textarea></label>
<button type="submit" class="btn <?= in_array($action,['decline','reject','cancel'],true)?'p52-danger':'btn-primary' ?>"><?= e($title) ?></button>
</form><?php endforeach;?></div></details>
<?php elseif($r['status']==='awaiting_finalization' && $role==='supply_officer'): ?><form method="post" onsubmit="return confirm('Finalize this verified transfer? This will move official ICS accountability and cannot be undone here.');"><?= csrf_field() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>"><button type="submit" class="btn btn-primary">Finalize &amp; Create ICS</button></form><?php elseif($r['status']==='awaiting_finalization'): ?><small>Verified — awaiting Supply Officer finalization</small>
<?php else: ?><small>—</small><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$requests):?><tr><td colspan="8">No transfer requests recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<script>
const p51Data=<?= json_encode(array_reduce($source,function($a,$x){$d=$x['detail'];$a[(int)$x['row']['ics_item_id']]=['from'=>(int)$x['row']['teacher_id'],'category'=>$x['row']['item_category'],'unit'=>$x['row']['unit'],'eligible'=>(int)$d['eligible_max'],'custody'=>(int)$d['custody_remaining'],'batches'=>array_map(fn($b)=>['allocation'=>(int)$b['allocation_id'],'batch'=>(string)($b['batch_number']?:('Batch #'.$b['batch_id'])),'remaining'=>max(0,(int)$b['qty_issued']-(int)$b['qty_returned']-(int)$b['qty_transferred_out']-(int)$b['reserved']),'expiration'=>$b['expiration_date'],'course'=>$b['course_name']],$x['batches'])];return $a;},[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const line=document.getElementById('p51Line'), batch=document.getElementById('p51Allocation'), qty=document.getElementById('p51Qty'),range=document.getElementById('p511Range'),limit=document.getElementById('p511Limit'),unused=document.getElementById('p51Unused'),used=document.getElementById('p51Consumed');
let selected=null;
function p511Limit(){if(!selected)return 0;let b=selected.batches.find(x=>String(x.allocation)===batch.value);return Math.max(0,Math.min(selected.eligible,b?b.remaining:selected.batches.length?0:selected.eligible));}
function p511Refresh(){let d=selected,max=p511Limit(),supply=d&&['consumable','expiring_material'].includes(d.category);document.getElementById('p511Supply').hidden=!supply;qty.max=max;range.max=Math.max(1,max);range.disabled=max<1;qty.disabled=max<1;if(max>=1){let val=Math.min(max,Math.max(1,Number(qty.value)||1));qty.value=val;range.value=val;}else{qty.value='';range.value=1;}limit.textContent=d?('Maximum selectable: '+max+' '+(d.unit||'units')):'Select an item';document.getElementById('p511Remainder').textContent=supply?'Quantity to transfer must not exceed your counted unused stock and must fit the verified eligible balance.':'';}
if(line){line.addEventListener('change',()=>{selected=p51Data[line.value]||null;document.getElementById('p51From').value=selected?.from||'';let d=selected;document.getElementById('p51Eligibility').textContent=d?('Currently accountable: '+d.custody+' '+(d.unit||'units')+'  •  Eligible for request: '+d.eligible+'  •  Category: '+d.category):'Select an ICS item to see its eligibility.';batch.replaceChildren(new Option('Choose source batch...',''));if(d){d.batches.forEach(b=>{let expired=b.expiration&&b.expiration<new Date().toISOString().slice(0,10);let option=new Option(b.batch+' · '+b.remaining+' remaining'+(b.expiration?' · Expiry '+b.expiration:'')+(b.course?' · '+b.course:''),b.allocation);option.disabled=expired||b.remaining<1;batch.add(option);});if(!d.batches.length){let opt=new Option('Legacy / unattributed — Supply Officer review','');batch.add(opt);document.getElementById('p511BatchHint').textContent='Legacy ICS: no verified receiving batch is recorded. Teachers cannot request an unattributed transfer. Supply Officer may create a reviewed request for reusable equipment/non-consumables; unverified legacy supplies remain blocked.';}else{document.getElementById('p511BatchHint').textContent='Select the batch that physically supplied the item. Expired or exhausted batches are disabled.';}}unused.value='';used.value='';p511Refresh();});batch.addEventListener('change',p511Refresh);qty.addEventListener('input',()=>{let max=p511Limit();if(max>0){let v=Math.min(max,Math.max(1,Number(qty.value)||1));range.value=v;}});qty.addEventListener('change',p511Refresh);range.addEventListener('input',()=>{qty.value=range.value;});document.getElementById('p51Form').addEventListener('submit',e=>{let d=selected;if(!d)return;if(['consumable','expiring_material'].includes(d.category)){let n=Number(unused.value);if(unused.value===''||!Number.isInteger(n)||n<Number(qty.value)){e.preventDefault();unused.setCustomValidity('Enter the physically unused quantity; it must cover the transfer amount.');unused.reportValidity();}else unused.setCustomValidity('');}});unused.addEventListener('input',()=>unused.setCustomValidity(''));}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>

<script>
(()=>{const update=(el)=>{if(!el)return;const min=Number(el.min)||0,max=Number(el.max)||1,value=Number(el.value)||min;const percent=Math.max(0,Math.min(100,(value-min)/(max-min||1)*100));el.style.setProperty('--slider-progress',percent+'%')};const bind=(id)=>{const el=document.getElementById(id);if(!el)return;['input','change'].forEach(name=>el.addEventListener(name,()=>update(el)));new MutationObserver(()=>update(el)).observe(el,{attributes:true,attributeFilter:['max','min','disabled']});update(el)};bind('p6-range');bind('p511Range');})();
</script>