<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/transfer_foundation.php';
require_once __DIR__.'/includes/transfer_finalization.php';
require_once __DIR__.'/includes/transfer_evidence_store.php';
$user=require_role(['teacher','supply_officer','tvl_head']);$uid=(int)$user['id'];$role=$user['role'];$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST') {
 csrf_check();
 if($role==='tvl_head'){http_response_code(403);exit('Transfer management is read-only for TVL Head.');}
 $savedHandoverPhoto=null;
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
  if($action==='verify_finalize') {
   $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($role!=='supply_officer'||!$requestId)throw new InvalidArgumentException('Only the Supply Officer may verify and finalize a transfer.');
   if((string)($_POST['physical_handover_verified']??'')!=='1')throw new InvalidArgumentException('Confirm the physical handover before finalizing.');
   $note=trim((string)($_POST['decision_note']??''));
   $result=tnts53_verify_finalize($pdo,(int)$requestId,$uid,$note);
   flash('success','Transfer verified and finalized. Recipient ICS: '.$result['ics_number'].'.');
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
   $pdo->commit();flash('success',$action==='accept'?'Transfer accepted. Awaiting Supply Officer verification and finalization. ICS accountability remains unchanged.':'Transfer #'.$requestId.' updated to '.str_replace('_',' ',$newStatus).'. ICS custody remains unchanged until finalized.');
   redirect('transfers.php');
  }
  if($action!=='request') throw new InvalidArgumentException('Invalid transfer action.');
  $transferMethod=(string)($_POST['transfer_method']??'teacher');
  if(!in_array($transferMethod,['teacher','assisted'],true))throw new InvalidArgumentException('Invalid transfer method.');
  $assisted=$transferMethod==='assisted';
  if($assisted && $role!=='supply_officer')throw new InvalidArgumentException('Only the Supply Officer may record an assisted transfer.');
  if($assisted && (string)($_POST['handover_confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm that the original holder released the property and the recipient acknowledged receipt.');
  $handoverRemarks=trim((string)($_POST['handover_remarks']??''));
  if(mb_strlen($handoverRemarks)>500)throw new InvalidArgumentException('Handover remarks may not exceed 500 characters.');

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
  if($assisted && !$chosen && $handoverRemarks==='')throw new InvalidArgumentException('Provide a note describing the review of the historical source batch.');
  if($assisted)$savedHandoverPhoto=tnts519_save_transfer_photo($_FILES['handover_photo']??null);
  $st=$pdo->prepare("INSERT INTO transfer_requests (ics_item_id,allocation_id,from_teacher_id,to_teacher_id,quantity,declared_unused_qty,declared_consumed_qty,reason,requested_by,status,legacy_unattributed,transfer_method,handover_confirmed_at,handover_confirmed_by,handover_remarks,handover_photo_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

  $st->execute([$line,$allocation,$from,$recipient,$qty,$declared,$consumed,$reason,$uid,$assisted?'accepted':'requested',$chosen?0:1,$transferMethod,$assisted?date('Y-m-d H:i:s'):null,$assisted?$uid:null,$assisted?($handoverRemarks?:null):null,$savedHandoverPhoto]);$requestId=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO transfer_request_events(transfer_request_id,actor_user_id,event_type,notes) VALUES (?,?, 'requested',?)")->execute([$requestId,$uid,($assisted?'Assisted transfer initiated with documented physical handover.':'Request created; awaiting recipient acceptance.')]);
  $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,'Transfer Requested','Transfers',?,?)")->execute([$uid,$requestId,'Requested '.$qty.' '.$item['unit'].' of '.$item['item_name'].' for teacher #'.$recipient]);
  if($assisted){
   $ackNote='Physical handover confirmed: source holder released property and receiving teacher acknowledged receipt.';
   if($handoverRemarks!=='')$ackNote.=' Notes: '.$handoverRemarks;
   $pdo->prepare("INSERT INTO transfer_request_events(transfer_request_id,actor_user_id,event_type,notes) VALUES (?,?,'assisted_acknowledgment',?)")->execute([$requestId,$uid,$ackNote]);
   $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,'Transfer Handover Confirmed','Transfers',?,?)")->execute([$uid,$requestId,$ackNote]);
   $result=tnts53_verify_finalize_locked($pdo,$requestId,$uid,$handoverRemarks?:'Handover confirmed by Supply Officer.');
   $pdo->commit();flash('success','Assisted handover finalized. Recipient ICS: '.$result['ics_number'].'. Both holders will be notified when notifications are enabled.');
  }else{
   $pdo->commit();flash('success','Transfer request saved. The receiving teacher must accept before the Supply Officer finalizes.');
  }
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();tnts519_delete_transfer_photo($savedHandoverPhoto);flash('error',($ex instanceof InvalidArgumentException||($ex instanceof RuntimeException&&!($ex instanceof PDOException)))?$ex->getMessage():'Unable to save or finalize the transfer. Your records were not changed.');}
 redirect('transfers.php');
}
$teachers=$pdo->query("SELECT user_id,full_name FROM users WHERE role='teacher' AND status='active' AND registration_status='approved' ORDER BY full_name")->fetchAll();
$items=$pdo->query("SELECT i.item_id,ii.ics_item_id,ii.qty_issued,ii.qty_returned,i.item_name,i.item_category,i.unit,c.ics_number,c.teacher_id,u.full_name AS holder FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id WHERE c.status='active' ORDER BY u.full_name,i.item_name")->fetchAll();
if($role==='teacher')$items=array_values(array_filter($items,fn($v)=>(int)$v['teacher_id']===$uid));
$source=[];foreach($items as $v){try{$detail=tnts51_state($pdo,(int)$v['ics_item_id']);$batches=tnts51_batches($pdo,(int)$v['ics_item_id']);$source[]=['row'=>$v,'detail'=>$detail,'batches'=>$batches];}catch(Throwable $e){/* broken historical row omitted from request list; audit separately */}}
$sql="SELECT r.*,i.item_name,i.unit,i.item_category,c.ics_number,u.full_name from_name,v.full_name to_name,requester.full_name requested_by_name,b.batch_number,b.expiration_date FROM transfer_requests r JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=r.from_teacher_id JOIN users v ON v.user_id=r.to_teacher_id JOIN users requester ON requester.user_id=r.requested_by LEFT JOIN ics_batch_allocations a ON a.allocation_id=r.allocation_id LEFT JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id";
if($role==='teacher'){$sql.=' WHERE r.from_teacher_id=? OR r.to_teacher_id=?';$st=$pdo->prepare($sql.' ORDER BY r.created_at DESC,r.request_id DESC');$st->execute([$uid,$uid]);}else{$st=$pdo->query($sql.' ORDER BY r.created_at DESC,r.request_id DESC');}
$transferGroups=[];
foreach($source as $src){
 $r=$src['row'];$key=(int)$r['item_id'];
 if(!isset($transferGroups[$key]))$transferGroups[$key]=['name'=>$r['item_name'],'category'=>$r['item_category'],'unit'=>$r['unit'],'holders'=>[],'eligible'=>0,'accountable'=>0];
 $transferGroups[$key]['holders'][]=$src;
 $transferGroups[$key]['eligible']+=max(0,(int)$src['detail']['eligible_max']);
 $transferGroups[$key]['accountable']+=max(0,(int)$src['detail']['custody_remaining']);
}
$requests=$st->fetchAll();$pageTitle='Property Transfers';$active='transfers.php';include __DIR__.'/includes/header.php';
?>
<style>
.p3815-tools{display:grid;grid-template-columns:minmax(180px,2fr) minmax(145px,1fr) minmax(180px,1fr) auto;gap:12px;align-items:end;margin:18px 0;}
.p3815-tools label{display:grid;gap:6px;font-size:.83rem;font-weight:700;color:#43506a;min-width:0;}
.p3815-tools input,.p3815-tools select{width:100%;min-width:0;border:1px solid #d0d9e5;border-radius:8px;padding:10px;background:#fff;color:#243249;font:inherit;}
.p51-scroll{max-width:100%;overflow-x:auto;}
.p51-scroll table{min-width:0!important;width:100%;table-layout:auto;}
.p51-scroll th,.p51-scroll td{padding:11px 8px!important;font-size:.84rem;overflow-wrap:anywhere;}
.p3815-info{margin-top:7px;font-size:.8rem;max-width:240px;}.p3815-info summary{cursor:pointer;color:#8b153b;font-weight:700;}.p3815-info p{margin:6px 0;line-height:1.4;}
.p3815-footer{display:flex;gap:12px;align-items:center;justify-content:flex-end;margin-top:15px;flex-wrap:wrap;font-size:.85rem;}
.p3815-footer #p3815-count{margin-right:auto;color:#586680;}
@media(max-width:900px){.p3815-tools{grid-template-columns:repeat(2,minmax(0,1fr));}.p51-scroll table{min-width:760px!important;}}
@media(max-width:560px){.p3815-tools{grid-template-columns:minmax(0,1fr);}}

.p3816-browser{margin:12px 0 14px;min-width:0}.p3816-search{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px;margin-bottom:12px}.p3816-search label,.p3816-teacher-choice label{display:grid;gap:6px;min-width:0}.p3816-search input,.p3816-search select,.p3816-teacher-choice input,.p3816-teacher-choice select{width:100%;min-width:0}
.p3816-list{border:1px solid #dce3ed;border-radius:12px;overflow:hidden;max-height:380px;overflow-y:auto;background:#fff}.p3816-item+ .p3816-item{border-top:1px solid #edf0f5}.p3816-item-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;text-align:left;gap:12px;border:0;background:#fff;padding:13px 14px;color:#243249;cursor:pointer;font:inherit}.p3816-item-toggle:hover,.p3816-item-toggle[aria-expanded="true"]{background:#f8f0f3}.p3816-item-toggle>span:first-child,.p3816-holder>span:first-child{display:grid;gap:4px;min-width:0}.p3816-item-toggle small,.p3816-holder small{color:#6b7890;font-size:.78rem}.p3816-item-metrics{display:flex;gap:16px;flex-wrap:wrap;font-size:.82rem;color:#506079}.p3816-chevron{font-size:1.4rem;color:#87163a}.p3816-holder-list{padding:5px 12px 12px;background:#fcfbfd}.p3816-holder{display:grid;grid-template-columns:minmax(130px,1.3fr) minmax(110px,1fr) minmax(95px,1fr) auto;align-items:center;gap:10px;border:1px solid #e0e5ee;border-radius:9px;padding:10px;margin-top:7px;background:#fff;font-size:.84rem}.p3816-holder.is-selected{border-color:#901b42;background:#fff5f8}.p3816-holder .btn{white-space:nowrap}.p3816-selection-label{display:grid;gap:6px;margin-top:12px}.p3816-selection-label select{width:100%;min-width:0}.p3816-teacher-choice{display:grid;gap:9px;min-width:0}.p3816-help{color:#69768b}.p3816-item[hidden],.p3816-holder-list[hidden]{display:none!important}
.t18-teacher-field{display:grid;gap:6px;min-width:0}.t18-teacher-picker{position:relative;min-width:0}.t18-teacher-picker input[type=search]{width:100%;min-width:0}.t18-teacher-picker input:disabled{background:#f4f6f8;color:#6e7889;cursor:not-allowed}.t18-teacher-options{position:absolute;z-index:65;left:0;right:0;top:calc(100% + 5px);max-height:248px;overflow:auto;padding:5px;border:1px solid #d7dce6;border-radius:10px;background:#fff;box-shadow:0 14px 32px #11182726}.t18-teacher-options[hidden]{display:none!important}.t18-teacher-option{display:block;width:100%;text-align:left;padding:10px 12px;color:#26364e;background:#fff;border:0;border-radius:7px;cursor:pointer;font:inherit;font-size:.84rem}.t18-teacher-option:hover,.t18-teacher-option[aria-selected=true],.t18-teacher-option:focus-visible{background:#f7e9ee;color:#771632}.t18-teacher-empty{padding:11px;color:#617086;font-size:.8rem}.t18-teacher-picker input[aria-invalid=true]{border-color:#b52942}
@media(max-width:760px){.p3816-search{grid-template-columns:1fr}.p3816-item-toggle{align-items:flex-start}.p3816-item-metrics{flex-direction:column;gap:3px}.p3816-holder{grid-template-columns:minmax(0,1fr) auto}.p3816-holder>span:nth-child(2),.p3816-holder>span:nth-child(3){grid-column:1}.p3816-holder .btn{grid-row:1/4;grid-column:2}}

.t519-methods{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0}
.t519-methods label{display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #d8dee9;background:#fff;border-radius:10px;cursor:pointer;font-size:.88rem}
.t519-methods label:has(input:checked){border-color:#8b153b;background:#fff5f8}
.t519-methods small{display:block;color:#58677b;margin-top:5px;line-height:1.4}
.t519-methods input{width:auto!important;accent-color:#86143a;margin-top:3px}
.t519-assist{padding:15px;border-radius:12px;border:1px solid #ead5dd;background:#fffcfd;display:grid;gap:13px}
.t519-assist[hidden]{display:none!important}
.t519-confirm-note p{margin:5px 0 0;color:#56657b;font-size:.84rem}
.t519-check{display:flex!important;align-items:flex-start;gap:11px;line-height:1.5;background:#fff;padding:12px;border:1px solid #ebdce1;border-radius:8px}
.t519-check input{width:auto!important;min-width:18px;min-height:18px;accent-color:#881c3b}
.t519-assist-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.t519-assist-fields label{display:grid;gap:6px;min-width:0;font-size:.84rem}
.t519-assist-fields input,.t519-assist-fields textarea{width:100%;min-width:0}
.t519-assist-fields small{color:#697689}
@media(max-width:760px){.t519-methods,.t519-assist-fields{grid-template-columns:1fr}}
/* Transfer form alignment: keep input groups top-aligned at default browser zoom. */
.p511-form .p511-columns{align-items:start;gap:16px}
.p511-form .p511-columns > *{min-width:0}
.p511-form .p3819b-batch-choice,.p511-form .t18-teacher-field{display:grid;grid-template-rows:auto auto;align-content:start;gap:8px;min-width:0}
.p511-form .p3819b-batch-choice label,.p511-form .t18-teacher-field > label{margin:0;min-width:0;font-weight:600;line-height:1.35;display:block}
.p511-form .p3819b-batch-choice select,.p511-form #p3816TeacherSearch{display:block;width:100%;min-width:0;height:46px;min-height:46px;margin:0;padding:10px 12px;box-sizing:border-box}
.p511-form .p3816-teacher-choice{align-content:start;gap:9px}
.p511-form .p3816-help{display:block;line-height:1.45}
.p511-form .p511-qty{grid-template-columns:minmax(0,1fr) minmax(0,1.8fr);align-items:start;gap:16px}
.p511-form .p511-qty > label,.p511-form .p511-range-wrap{display:grid;grid-template-rows:auto 46px auto;align-content:start;gap:8px;min-width:0;margin:0}
.p511-form .p511-qty > label{grid-template-rows:auto 46px}
.p511-form .p511-qty > label input{display:block;box-sizing:border-box;height:46px;min-height:46px;margin:0;padding:10px 12px}
.p511-form .p511-range-wrap > label{display:block;margin:0;line-height:1.35}
.p511-form #p511Range{height:46px;margin:0;width:100%;align-self:center;box-sizing:border-box;padding:0 2px;background:transparent;border:0}
.p511-form #p511Limit{display:block;font-size:.79rem;color:#64748b;line-height:1.4}
@media(max-width:720px){.p511-form .p511-qty{grid-template-columns:minmax(0,1fr)}.p511-form .p511-step{min-width:0}}
</style>
<div class="p51-shell">
<h1>Property Transfers</h1><p class="muted">Choose a teacher-to-teacher request or an officer-assisted handover. Transfers update ICS accountability without issuing stock again.</p>
<?php if($role==='tvl_head'): ?><div class="card">Read-only: view transfer requests and history. Inventory editing is not available.</div><?php endif; ?>
<?php if($role!=='tvl_head'): ?>
<details class="card p51-create p511-create" open><summary><strong>+ New Transfer Request</strong><span class="muted">Select item → choose teacher → verify quantities</span></summary>
<form method="post" enctype="multipart/form-data" class="p51-form p511-form" id="p51Form"><?= csrf_field() ?><input type="hidden" name="action" value="request" />
<div class="p511-step"><div class="p511-heading"><span>1</span><strong>Choose property to transfer</strong></div>
<div class="p3816-browser" id="p3816Browser">
 <div class="p3816-search"><label>Search accountable items<input type="search" id="p3816ItemSearch" placeholder="Search item, teacher, ICS number or category" autocomplete="off"></label><label>Category<select id="p3816Category"><option value="">All categories</option><option value="equipment">Equipment</option><option value="non_consumable">Non Consumable</option><option value="consumable">Consumable</option><option value="expiring_material">Expiring Material</option></select></label></div>
 <div class="p3816-list" id="p3816List">
 <?php foreach($transferGroups as $group): ?>
 <section class="p3816-item" data-category="<?= e($group['category']) ?>" data-query="<?= e(mb_strtolower($group['name'].' '.$group['category'].' '.implode(' ',array_map(fn($x)=>$x['row']['holder'].' '.$x['row']['ics_number'],$group['holders'])))) ?>">
 <button class="p3816-item-toggle" type="button" aria-expanded="false"><span><strong><?= e($group['name']) ?></strong><small><?= e(ucwords(str_replace('_',' ',$group['category']))) ?></small></span><span class="p3816-item-metrics"><span>Accountable: <strong><?= (int)$group['accountable'].' '.e($group['unit']) ?></strong></span><span>Eligible: <strong><?= (int)$group['eligible'].' '.e($group['unit']) ?></strong></span><span><?= count($group['holders']) ?> ICS holder(s)</span></span><span class="p3816-chevron">⌄</span></button>
 <div class="p3816-holder-list" hidden>
 <?php foreach($group['holders'] as $entry):$h=$entry['row'];$d=$entry['detail'];$can=(int)$d['eligible_max']; ?>
 <div class="p3816-holder" data-ics-id="<?= (int)$h['ics_item_id'] ?>"><span><strong><?= e($h['holder']) ?></strong><small><strong><?= e($h['ics_number']) ?></strong></small></span><span>Accountable: <?= (int)$d['custody_remaining'].' '.e($h['unit']) ?></span><span>Eligible: <strong><?= max(0,$can).' '.e($h['unit']) ?></strong></span><button type="button" class="btn <?= $can>0?'btn-primary':'btn-outline' ?> p3816-select-holder" data-line="<?= (int)$h['ics_item_id'] ?>" <?= $can<=0?'disabled title="No eligible unreserved quantity"':'' ?>><?= $can>0?'Select ICS':'Unavailable' ?></button></div>
 <?php endforeach; ?>
 </div></section>
 <?php endforeach; ?>
 </div><p id="p3816ItemEmpty" class="muted" hidden>No matching items or ICS holders.</p>
 </div>
 <label class="p3816-selection-label">Selected ICS Item<select name="ics_item_id" id="p51Line" required aria-label="Selected ICS item"><option value="">Select an ICS holder from the table above</option><?php foreach($source as $v):$r=$v['row'];?><option value="<?= (int)$r['ics_item_id'] ?>" data-from="<?= (int)$r['teacher_id'] ?>"><?= e($r['holder'].' — '.$r['item_name'].' — '.$r['ics_number']) ?></option><?php endforeach; ?></select></label>
<input type="hidden" id="p51From" name="from_teacher_id" />
<div id="p51Eligibility" class="p511-summary" role="status">Choose an item to see the quantity available for transfer, source batch, and category rules.</div></div>
<div class="p511-step"><div class="p511-heading"><span>2</span><strong>Recipient and source batch</strong></div>
<div class="p511-columns"><div class="p3816-teacher-choice"><div class="t18-teacher-field"><label for="p3816TeacherSearch">Receiving Teacher *</label><div class="t18-teacher-picker"><input type="search" id="p3816TeacherSearch" placeholder="Search and select a teacher..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="t18TeacherSuggestions" required disabled><input type="hidden" name="to_teacher_id" id="p3816Recipient" value=""><div id="t18TeacherSuggestions" class="t18-teacher-options" role="listbox" hidden></div></div></div><small class="p3816-help" id="p3816RecipientHint">Select the source ICS holder first. The holder cannot receive their own transfer.</small></div>
<div class="p3819b-batch-choice"><label for="p51Allocation">Source Batch</label><select name="allocation_id" id="p51Allocation"><option value="">Choose item first</option></select></div></div>
<div id="p511BatchHint" class="p511-hint">For known batches, select the matching original ICS allocation. Legacy items require Supply Officer review.</div></div>
<div class="p511-step"><div class="p511-heading"><span>3</span><strong>Transfer method</strong></div>
 <div class="t519-methods">
 <label><input type="radio" name="transfer_method" value="teacher" checked><span><strong>Teacher-to-Teacher</strong><small>The receiving teacher accepts this request before the Supply Officer finalizes it.</small></span></label>
 <?php if($role==='supply_officer'): ?><label><input type="radio" name="transfer_method" value="assisted"><span><strong>Supply Officer-Assisted Handover</strong><small>Use only after the property was physically handed over and both teachers acknowledged it.</small></span></label><?php endif; ?>
 </div>
 <?php if($role==='supply_officer'): ?>
 <section class="t519-assist" id="t519Assist" hidden aria-label="Assisted handover confirmation">
  <div class="t519-confirm-note"><strong>Physical acknowledgment</strong><p>No reference number or photo is required. Confirm the real handover; the system records who finalized it and when.</p></div>
  <label class="t519-check"><input type="checkbox" name="handover_confirmed" value="1" id="t519Confirmed"><span>I confirm that the <strong>original ICS holder released the property</strong> and the <strong>receiving teacher acknowledged physical receipt</strong>.</span></label>
  <div class="t519-assist-fields"><label>Supporting photo (optional)<input type="file" name="handover_photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP, maximum 5 MB.</small></label><label>Handover remarks (optional)<textarea name="handover_remarks" maxlength="500" rows="2" placeholder="Any additional handover or source-batch details..."></textarea></label></div>
 </section>
 <?php endif; ?>
 </div>
<div class="p511-step"><div class="p511-heading"><span>4</span><strong>Count quantity and submit</strong></div>
<div class="p511-qty"><label>Quantity to transfer<input type="number" name="quantity" id="p51Qty" min="1" step="1" required placeholder="Enter quantity"></label><div class="p511-range-wrap"><label for="p511Range">Adjust quantity</label><input id="p511Range" type="range" min="1" max="1" value="1" disabled><span id="p511Limit">Select an item</span></div></div>
<div id="p511Supply" class="p511-supply" hidden><p><strong>For supplies:</strong> Count the unused items physically remaining. Already consumed is optional. These declarations do not change stock until later verification.</p><div class="p511-columns"><label>Unused physical quantity (required for supplies)<input type="number" name="unused_qty" id="p51Unused" min="0" step="1" placeholder="Physically counted amount"></label><label>Already consumed (optional)<input type="number" name="consumed_qty" id="p51Consumed" min="0" step="1" placeholder="If known"></label></div><p id="p511Remainder" class="p511-hint"></p></div>
<label>Reason for transfer<textarea name="reason" maxlength="500" rows="3" required placeholder="Why is the property being transferred?"></textarea></label>
<div class="p511-warning">Teacher-to-teacher transfers require recipient acceptance. Assisted transfers finalize after the Supply Officer confirms the physical handover. Stock is checked again before any ICS change.</div>
<button type="submit" class="btn btn-primary" id="t519Submit">Submit Transfer Request</button></div>
</form></details>
<?php endif; ?>
<div class="card tnts-m28b-transfer-requests" id="tn-transfer-requests"><h2>Transfer Requests</h2>
<div class="p3815-tools" role="search" aria-label="Filter transfer requests">
<label>Search records<input type="search" id="p3815-search" placeholder="Item, ICS, teacher, batch, reason"></label>
<label>Status<select id="p3815-status"><option value="">All statuses</option><?php foreach(['requested','accepted','awaiting_finalization','declined','rejected','cancelled','completed'] as $s): ?><option value="<?= e($s) ?>"><?= e(ucwords(str_replace('_',' ',$s))) ?></option><?php endforeach; ?></select></label>
<label>Order<select id="p3815-sort"><option value="default">Default (newest)</option><option value="oldest">Oldest first</option><option value="item_asc">Item A–Z</option><option value="item_desc">Item Z–A</option><option value="qty_asc">Quantity ascending</option><option value="qty_desc">Quantity descending</option></select></label>
<button type="button" class="btn btn-outline" id="p3815-reset">Reset</button>
</div>
<p class="muted">Receiving teacher: accept or decline. Once accepted, the Supply Officer verifies and finalizes the transfer. ICS accountability changes only after completion.</p><div class="p51-scroll"><table><thead><tr><th>Requested</th><th>Item / ICS</th><th>From → To</th><th>Quantity</th><th>Status</th><th>Next action</th></tr></thead><tbody>
<?php foreach($requests as $r):?><tr class="p3815-record" data-transfer-id="<?= (int)$r['request_id'] ?>" data-time="<?= e($r['created_at']) ?>" data-status="<?= e($r['status']) ?>" data-item="<?= e(mb_strtolower((string)$r['item_name'])) ?>" data-qty="<?= (int)$r['quantity'] ?>" data-query="<?= e(mb_strtolower(implode(' ',[(string)$r['item_name'],(string)$r['ics_number'],(string)$r['from_name'],(string)$r['to_name'],(string)($r['batch_number']??''),(string)$r['reason']]))) ?>"><td><?= e($r['created_at']) ?></td><td><strong><?= e($r['item_name']) ?></strong><br><small><?= e($r['ics_number']) ?></small><details class="p3815-info"><summary>Batch &amp; declarations</summary><p><strong>Batch:</strong> <?= e($r['batch_number']??($r['legacy_unattributed']?'Legacy / Unattributed':'Unspecified')) ?></p><p><strong>Unused / consumed:</strong> <?= $r['declared_unused_qty']===null?'—':(int)$r['declared_unused_qty'] ?> / <?= $r['declared_consumed_qty']===null?'—':(int)$r['declared_consumed_qty'] ?></p><p><strong>Purpose:</strong> <?= e($r['reason']) ?></p><p><strong>Method:</strong> <?= ($r['transfer_method']??'teacher')==='assisted'?'Supply Officer-Assisted':'Teacher-to-Teacher' ?></p></details></td><td><?= e($r['from_name'].' → '.$r['to_name']) ?></td><td><?= (int)$r['quantity'].' '.e($r['unit']) ?></td><td><span class="p51-status"><?= e($r['status']==='accepted'&&$role==='teacher'?'Awaiting Supply Officer approval':ucwords(str_replace('_',' ',$r['status']))) ?></span></td><td>
<?php $actions=[];
if($role==='teacher'&&(int)$r['to_teacher_id']===$uid&&$r['status']==='requested')$actions=['accept'=>'Accept','decline'=>'Decline'];
elseif($role==='supply_officer'&&$r['status']==='accepted')$actions=['verify_finalize'=>'Verify & Finalize','reject'=>'Reject'];
elseif($role==='supply_officer'&&$r['status']==='requested')$actions=['reject'=>'Reject','cancel'=>'Cancel'];
elseif($r['status']==='requested'&&($role==='supply_officer'||(int)$r['requested_by']===$uid||(int)$r['from_teacher_id']===$uid))$actions=['cancel'=>'Cancel'];
?>
<?php if($actions):?>
<?php if($r['status']==='requested' && $role==='supply_officer'): ?><small class="t18-waiting">Awaiting <strong><?= e($r['to_name']) ?></strong>'s acceptance</small><?php endif; ?>
<button type="button" class="btn btn-outline t18-open-actions" data-dialog="t18-actions-<?= (int)$r['request_id'] ?>" aria-haspopup="dialog">Review actions</button>
<dialog class="t18-action-dialog" id="t18-actions-<?= (int)$r['request_id'] ?>" aria-label="Transfer #<?= (int)$r['request_id'] ?> review actions">
<div class="t18-dialog-head"><div><strong>Transfer #<?= (int)$r['request_id'] ?></strong><small><?= e($r['item_name'].' · '.$r['from_name'].' → '.$r['to_name']) ?></small></div><button type="button" class="t18-close-actions btn btn-outline" aria-label="Close actions">✕</button></div>
<p class="t18-help">Decisions and reasons are saved in the transfer history. Receiving teachers accept normal requests. The Supply Officer verifies and finalizes accepted transfers in one action.</p>
<div class="p52-action-list"><?php foreach($actions as $action=>$title):?>
<form method="post" class="p52-action-form" onsubmit="return confirm('<?= e($title) ?> transfer #<?= (int)$r['request_id'] ?>? <?= $action==='verify_finalize'?'This will immediately move ICS accountability.':'This decision will be recorded without moving ICS stock yet.' ?>');">
<?= csrf_field() ?><input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
<label class="p52-notes"><?= $action==='decline'||$action==='reject'?'Reason (required)':'Notes'.($action==='verify_finalize'?' (required for legacy items)':' (optional)') ?><textarea name="decision_note" rows="2" maxlength="1000" <?= in_array($action,['decline','reject'],true)?'required':'' ?> placeholder="Decision explanation"></textarea></label>
<?php if($action==='verify_finalize'): ?><label class="t519-check"><input type="checkbox" name="physical_handover_verified" value="1" required><span>I verified the physical handover and the receiving teacher's acknowledgment.</span></label><?php endif; ?>
<button type="submit" class="btn <?= in_array($action,['decline','reject','cancel'],true)?'p52-danger':'btn-primary' ?>"><?= e($title) ?></button>
</form><?php endforeach;?></div></dialog>
<?php elseif($r['status']==='awaiting_finalization' && $role==='supply_officer'): ?><form method="post" onsubmit="return confirm('Finalize this verified transfer? This will move official ICS accountability and cannot be undone here.');"><?= csrf_field() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>"><button type="submit" class="btn btn-primary">Finalize &amp; Create ICS</button></form><?php elseif($r['status']==='awaiting_finalization'): ?><small>Verified — awaiting Supply Officer finalization</small>
<?php elseif($r['status']==='accepted' && $role==='teacher'): ?>
<small class="t25-awaiting">✓ Recipient accepted. <strong>Awaiting Supply Officer verification and finalization.</strong><span>No ICS accountability changes until completed.</span></small>
<?php elseif($r['status']==='requested' && $role==='teacher'): ?>
<small class="t18-waiting">Awaiting <strong><?= e($r['to_name']) ?></strong>'s acceptance</small>
<?php elseif($r['status']==='requested' && $role==='supply_officer'): ?>
<small class="t18-waiting">Awaiting <strong><?= e($r['to_name']) ?></strong>'s acceptance</small>
<?php else: ?><small>—</small><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$requests):?><tr><td colspan="6">No transfer requests recorded yet.</td></tr><?php endif; ?></tbody></table></div><div class="p3815-footer"><span id="p3815-count" aria-live="polite"></span><button type="button" class="btn btn-outline" id="p3815-prev">Previous</button><span id="p3815-page"></span><button type="button" class="btn btn-outline" id="p3815-next">Next</button></div></div>
</div>
<style>
.t18-waiting{display:block;font-size:.76rem;line-height:1.45;color:#55647a;margin-bottom:8px;max-width:180px}
.t18-action-dialog{position:fixed;inset:0;margin:auto;width:min(94vw,520px);max-height:88dvh;overflow:auto;border:1px solid #e4dce1;border-radius:15px;padding:22px;background:#fff;color:#24324b;box-shadow:0 20px 65px #19071642}
.t18-action-dialog::backdrop{background:rgba(18,22,32,.58)}
.t18-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid #e7e9ee}
.t18-dialog-head>div{display:grid;gap:4px}.t18-dialog-head small{color:#64748b}
.t18-action-dialog .p52-action-list{display:grid;gap:12px;margin-top:13px}
.t18-action-dialog .p52-action-form{margin:0;padding:12px;border:1px solid #e6e8ee;border-radius:10px;display:grid;gap:9px}
.t18-action-dialog .p52-notes,.t18-action-dialog textarea{display:grid;gap:7px;width:100%;min-width:0}
.t18-action-dialog textarea{padding:10px;border:1px solid #cbd5e1;border-radius:8px}
.t18-help{font-size:.8rem;color:#64748b;line-height:1.5;margin:12px 0 0}
@media(max-width:760px){.t18-waiting{max-width:none}.t18-action-dialog{padding:14px}}
</style>
<script>
document.addEventListener('click',function(e){
 const opener=e.target.closest('.t18-open-actions');if(opener){const d=document.getElementById(opener.dataset.dialog);if(d?.showModal)d.showModal();return;}
 const close=e.target.closest('.t18-close-actions');if(close){close.closest('dialog')?.close();}
});
</script>
<script>
(function(){
 const search=document.getElementById('p3815-search'),status=document.getElementById('p3815-status'),sort=document.getElementById('p3815-sort');
 const rows=Array.from(document.querySelectorAll('.p3815-record')),tbody=rows[0]?.parentElement;
 const count=document.getElementById('p3815-count'),pageLabel=document.getElementById('p3815-page'),prev=document.getElementById('p3815-prev'),next=document.getElementById('p3815-next');
 if(!search||!tbody)return;
 let page=1;const perPage=10;
 function update(){
  const q=search.value.trim().toLocaleLowerCase(),s=status.value;
  const visible=rows.filter(r=>(!q||r.dataset.query.includes(q))&&(!s||r.dataset.status===s));
  const mode=sort.value;
  visible.sort((a,b)=>{switch(mode){case 'oldest':return a.dataset.time.localeCompare(b.dataset.time);case 'item_asc':return a.dataset.item.localeCompare(b.dataset.item);case 'item_desc':return b.dataset.item.localeCompare(a.dataset.item);case 'qty_asc':return (+a.dataset.qty)-(+b.dataset.qty);case 'qty_desc':return (+b.dataset.qty)-(+a.dataset.qty);default:return b.dataset.time.localeCompare(a.dataset.time);}});
  const pages=Math.max(1,Math.ceil(visible.length/perPage));page=Math.min(page,pages);
  rows.forEach(r=>r.hidden=true);
  visible.forEach((r,i)=>{tbody.appendChild(r);r.hidden=!(i>=(page-1)*perPage&&i<page*perPage);});
  count.textContent=`${visible.length} matching request(s)`;pageLabel.textContent=`Page ${page} of ${pages}`;
  prev.disabled=page<=1;next.disabled=page>=pages;
 }
 [search,status,sort].forEach(el=>el.addEventListener(el===search?'input':'change',()=>{page=1;update();}));
 document.getElementById('p3815-reset').addEventListener('click',()=>{search.value='';status.value='';sort.value='default';page=1;update();});
 prev.addEventListener('click',()=>{page--;update();});next.addEventListener('click',()=>{page++;update();});update();
 const requestedId=Number(new URLSearchParams(window.location.search).get('open'));
 if(Number.isSafeInteger(requestedId)&&requestedId>0){
   const target=rows.find(r=>Number(r.dataset.transferId)===requestedId);
   if(target){
     // Deep links always take precedence over the viewer's previous search/status filters.
     search.value='';status.value='';sort.value='default';
     const sorted=rows.slice().sort((a,b)=>b.dataset.time.localeCompare(a.dataset.time));
     page=Math.floor(sorted.indexOf(target)/perPage)+1;
     update();
     target.querySelector('.p3815-info')?.setAttribute('open','');
     target.classList.add('t25-target-transfer');
     target.setAttribute('tabindex','-1');
     window.requestAnimationFrame(()=>{
       target.scrollIntoView({behavior:'auto',block:'center'});
       target.focus({preventScroll:true});
       // The recipient can act here immediately, without hunting for Review actions.
       const actionButton=target.querySelector('.t18-open-actions');
       if(actionButton && actionButton.closest('tr')===target &&
          ['requested','accepted'].includes(target.dataset.status) && actionButton.dataset.dialog){
         const dialog=document.getElementById(actionButton.dataset.dialog);
         if(dialog && dialog.showModal)dialog.showModal();
       }
     });
   }
 }
})();
</script>
<script>
// Searchable transfer item browser and receiving-teacher picker.
(function(){
 const root=document.getElementById('p3816Browser'),itemSearch=document.getElementById('p3816ItemSearch'),category=document.getElementById('p3816Category');
 const line=document.getElementById('p51Line'),teacherSearch=document.getElementById('p3816TeacherSearch'),recipient=document.getElementById('p3816Recipient'),options=document.getElementById('t18TeacherSuggestions'),hint=document.getElementById('p3816RecipientHint');
 if(!root||!line||!recipient||!teacherSearch||!options)return;
 const groups=[...root.querySelectorAll('.p3816-item')];
 const teachers=<?= json_encode(array_map(fn($t)=>['id'=>(int)$t['user_id'],'name'=>(string)$t['full_name']], $teachers),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
 let matches=[],activeIndex=0;
 function searchItems(){const q=itemSearch.value.trim().toLocaleLowerCase(),cat=category.value;let visible=0;groups.forEach(g=>{const ok=(!q||g.dataset.query.includes(q))&&(!cat||g.dataset.category===cat);g.hidden=!ok;if(ok)visible++;});document.getElementById('p3816ItemEmpty').hidden=visible>0;}
 function closeOptions(){options.hidden=true;teacherSearch.setAttribute('aria-expanded','false');}
 function pickTeacher(teacher){recipient.value=String(teacher.id);teacherSearch.value=teacher.name;teacherSearch.setCustomValidity('');teacherSearch.removeAttribute('aria-invalid');hint.textContent='Selected: '+teacher.name+'. The current ICS holder is excluded.';closeOptions();}
 function renderOptions(){
   if(teacherSearch.disabled){closeOptions();return;}
   const from=Number(line.selectedOptions[0]?.dataset.from||0),q=teacherSearch.value.trim().toLocaleLowerCase();
   matches=teachers.filter(t=>t.id!==from&&(!q||t.name.toLocaleLowerCase().includes(q))).slice(0,12);
   activeIndex=0;options.replaceChildren();
   if(!matches.length){const empty=document.createElement('div');empty.className='t18-teacher-empty';empty.textContent='No eligible teachers match your search.';options.appendChild(empty);}
   matches.forEach((t,i)=>{const button=document.createElement('button');button.type='button';button.className='t18-teacher-option';button.textContent=t.name;button.setAttribute('role','option');button.setAttribute('aria-selected',String(i===activeIndex));button.addEventListener('click',()=>pickTeacher(t));options.appendChild(button);});
   options.hidden=false;teacherSearch.setAttribute('aria-expanded','true');
 }
 function updateHighlight(){options.querySelectorAll('.t18-teacher-option').forEach((b,i)=>{b.setAttribute('aria-selected',String(i===activeIndex));if(i===activeIndex)b.scrollIntoView({block:'nearest'});});}
 function refreshRecipient(){
   const from=line.selectedOptions[0]?.dataset.from||'';
   recipient.value='';teacherSearch.value='';teacherSearch.disabled=!from;teacherSearch.setCustomValidity('');teacherSearch.removeAttribute('aria-invalid');closeOptions();
   hint.textContent=from?'Search and choose a teacher from the suggestions. The current ICS holder is excluded.':'Select an ICS holder first.';
 }
 groups.forEach(g=>{const toggle=g.querySelector('.p3816-item-toggle'),list=g.querySelector('.p3816-holder-list');toggle.addEventListener('click',()=>{const opened=toggle.getAttribute('aria-expanded')==='true';groups.forEach(other=>{other.querySelector('.p3816-item-toggle').setAttribute('aria-expanded','false');other.querySelector('.p3816-holder-list').hidden=true;});toggle.setAttribute('aria-expanded',String(!opened));list.hidden=opened;});g.querySelectorAll('.p3816-select-holder').forEach(btn=>btn.addEventListener('click',()=>{line.value=btn.dataset.line;line.dispatchEvent(new Event('change',{bubbles:true}));root.querySelectorAll('.p3816-holder').forEach(h=>h.classList.remove('is-selected'));btn.closest('.p3816-holder').classList.add('is-selected');document.getElementById('p51Eligibility').scrollIntoView({behavior:'smooth',block:'nearest'});}));});
 itemSearch.addEventListener('input',searchItems);category.addEventListener('change',searchItems);
 line.addEventListener('change',()=>{refreshRecipient();root.querySelectorAll('.p3816-holder').forEach(h=>h.classList.toggle('is-selected',h.dataset.icsId===line.value));});
 teacherSearch.addEventListener('input',()=>{recipient.value='';teacherSearch.setCustomValidity('');teacherSearch.removeAttribute('aria-invalid');hint.textContent='Choose a teacher from the suggestions to confirm the recipient.';renderOptions();});
 teacherSearch.addEventListener('focus',renderOptions);
 teacherSearch.addEventListener('keydown',e=>{
   if(e.key==='Escape'){closeOptions();return;}
   if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(options.hidden)renderOptions();if(matches.length){activeIndex=(activeIndex+(e.key==='ArrowDown'?1:-1)+matches.length)%matches.length;updateHighlight();}}
   if(e.key==='Enter'&&!options.hidden){e.preventDefault();if(matches[activeIndex])pickTeacher(matches[activeIndex]);}
 });
 document.addEventListener('pointerdown',e=>{if(!e.target.closest('.t18-teacher-picker'))closeOptions();});
 const form=document.getElementById('p51Form');form?.addEventListener('submit',e=>{if(!line.value)return;if(!recipient.value){e.preventDefault();teacherSearch.setCustomValidity('Select an eligible teacher from the suggestions.');teacherSearch.setAttribute('aria-invalid','true');teacherSearch.reportValidity();teacherSearch.focus();renderOptions();}});
 refreshRecipient();
})();
const p51Data=<?= json_encode(array_reduce($source,function($a,$x){$d=$x['detail'];$a[(int)$x['row']['ics_item_id']]=['from'=>(int)$x['row']['teacher_id'],'category'=>$x['row']['item_category'],'unit'=>$x['row']['unit'],'eligible'=>(int)$d['eligible_max'],'custody'=>(int)$d['custody_remaining'],'batches'=>array_map(fn($b)=>['allocation'=>(int)$b['allocation_id'],'batch'=>(string)($b['batch_number']?:('Batch #'.$b['batch_id'])),'remaining'=>max(0,(int)$b['qty_issued']-(int)$b['qty_returned']-(int)$b['qty_transferred_out']-(int)$b['reserved']),'expiration'=>$b['expiration_date'],'course'=>$b['course_name']],$x['batches'])];return $a;},[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const line=document.getElementById('p51Line'), batch=document.getElementById('p51Allocation'), qty=document.getElementById('p51Qty'),range=document.getElementById('p511Range'),limit=document.getElementById('p511Limit'),unused=document.getElementById('p51Unused'),used=document.getElementById('p51Consumed');
let selected=null;
function p511Limit(){if(!selected)return 0;let b=selected.batches.find(x=>String(x.allocation)===batch.value);return Math.max(0,Math.min(selected.eligible,b?b.remaining:selected.batches.length?0:selected.eligible));}
function p511Refresh(){let d=selected,max=p511Limit(),supply=d&&['consumable','expiring_material'].includes(d.category);document.getElementById('p511Supply').hidden=!supply;qty.max=max;range.max=Math.max(1,max);range.disabled=max<1;qty.disabled=max<1;if(max>=1){let val=Math.min(max,Math.max(1,Number(qty.value)||1));qty.value=val;range.value=val;}else{qty.value='';range.value=1;}limit.textContent=d?('Maximum selectable: '+max+' '+(d.unit||'units')):'Select an item';document.getElementById('p511Remainder').textContent=supply?'Quantity to transfer must not exceed your counted unused stock and must fit the verified eligible balance.':'';}
if(line){line.addEventListener('change',()=>{selected=p51Data[line.value]||null;document.getElementById('p51From').value=selected?.from||'';let d=selected;document.getElementById('p51Eligibility').textContent=d?('Currently accountable: '+d.custody+' '+(d.unit||'units')+'  •  Eligible for request: '+d.eligible+'  •  Category: '+d.category):'Select an ICS item to see its eligibility.';batch.replaceChildren(new Option('Choose source batch...',''));if(d){d.batches.forEach(b=>{let expired=b.expiration&&b.expiration<new Date().toISOString().slice(0,10);let option=new Option(b.batch+' · '+b.remaining+' remaining'+(b.expiration?' · Expiry '+b.expiration:'')+(b.course?' · '+b.course:''),b.allocation);option.disabled=expired||b.remaining<1;batch.add(option);});if(!d.batches.length){let opt=new Option('Legacy / unattributed — Supply Officer review','');batch.add(opt);document.getElementById('p511BatchHint').textContent='Legacy ICS: no verified receiving batch is recorded. Teachers cannot request an unattributed transfer. Supply Officer may create a reviewed request for reusable equipment/non-consumables; unverified legacy supplies remain blocked.';}else{document.getElementById('p511BatchHint').textContent='Select the batch that physically supplied the item. Expired or exhausted batches are disabled.';}}unused.value='';used.value='';p511Refresh();});batch.addEventListener('change',p511Refresh);qty.addEventListener('input',()=>{let max=p511Limit();if(max>0){let v=Math.min(max,Math.max(1,Number(qty.value)||1));range.value=v;}});qty.addEventListener('change',p511Refresh);range.addEventListener('input',()=>{qty.value=range.value;});document.getElementById('p51Form').addEventListener('submit',e=>{let d=selected;if(!d)return;if(['consumable','expiring_material'].includes(d.category)){let n=Number(unused.value);if(unused.value===''||!Number.isInteger(n)||n<Number(qty.value)){e.preventDefault();unused.setCustomValidity('Enter the physically unused quantity; it must cover the transfer amount.');unused.reportValidity();}else unused.setCustomValidity('');}});unused.addEventListener('input',()=>unused.setCustomValidity(''));}
</script>
<script>
(()=>{
 const form=document.getElementById('p51Form');if(!form)return;
 const modes=[...form.querySelectorAll('input[name="transfer_method"]')];
 const assisted=document.getElementById('t519Assist');const confirmed=document.getElementById('t519Confirmed');
 const submit=document.getElementById('t519Submit');
 function refresh(){const isAssisted=modes.some(m=>m.checked&&m.value==='assisted');if(assisted)assisted.hidden=!isAssisted;if(confirmed)confirmed.required=isAssisted;if(submit)submit.textContent=isAssisted?'Review & Finalize Assisted Transfer':'Submit Transfer Request';}
 modes.forEach(m=>m.addEventListener('change',refresh));refresh();
 form.addEventListener('submit',e=>{
   if(e.defaultPrevented)return;
   const isAssisted=modes.some(m=>m.checked&&m.value==='assisted');if(!isAssisted)return;
   const source=form.querySelector('#p51Line')?.selectedOptions[0]?.textContent?.trim()||'–';
   const recipient=form.querySelector('#p3816TeacherSearch')?.value?.trim()||'–';
   const batch=form.querySelector('#p51Allocation')?.selectedOptions[0]?.textContent?.trim()||'Unspecified';
   const qty=form.querySelector('#p51Qty')?.value||'–';
   const message=`Verify this handover before finalizing:\n\nFrom / item: ${source}\nReceiving teacher: ${recipient}\nQuantity: ${qty}\nSource batch: ${batch}\n\nBoth teachers must have acknowledged the physical handover. Finalization changes ICS accountability immediately and cannot be undone here. Proceed?`;
   if(!window.confirm(message))e.preventDefault();
 });
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>

<script>
(()=>{const update=(el)=>{if(!el)return;const min=Number(el.min)||0,max=Number(el.max)||1,value=Number(el.value)||min;const percent=Math.max(0,Math.min(100,(value-min)/(max-min||1)*100));el.style.setProperty('--slider-progress',percent+'%')};const bind=(id)=>{const el=document.getElementById(id);if(!el)return;['input','change'].forEach(name=>el.addEventListener(name,()=>update(el)));new MutationObserver(()=>update(el)).observe(el,{attributes:true,attributeFilter:['max','min','disabled']});update(el)};bind('p6-range');bind('p511Range');})();
</script>