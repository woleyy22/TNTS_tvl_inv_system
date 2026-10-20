<?php
/** Phase 5.3: atomic custody movement. Must be called by a Supply Officer only. */
require_once __DIR__.'/transfer_foundation.php';
require_once __DIR__.'/functions.php';
function tnts53_finalize(PDO $pdo,int $requestId,int $actorId): array {
 if($pdo->inTransaction())throw new LogicException('Transfer finalization requires its own transaction.');
 $pdo->beginTransaction();
 try {
  $st=$pdo->prepare('SELECT * FROM transfer_requests WHERE request_id=? FOR UPDATE');$st->execute([$requestId]);$r=$st->fetch(PDO::FETCH_ASSOC);
  if(!$r||$r['status']!=='awaiting_finalization')throw new RuntimeException('Only verified transfers awaiting finalization can be completed.');
  if((int)$r['quantity']<1)throw new RuntimeException('Invalid transfer quantity.');
  // Lock source line and authoritative custody state before creating the new ICS.
  $source=tnts51_state($pdo,(int)$r['ics_item_id'],true);
  $qty=(int)$r['quantity'];
  if($source['ics_status']!=='active'||(int)$source['teacher_id']!==(int)$r['from_teacher_id'])throw new RuntimeException('Original custodian has changed.');
  $available=$source['is_supply']?(int)$source['custody_remaining']:(int)$source['state']['qty_serviceable'];
  $reserved=(int)$source['reserved_qty']-$qty;
  if($available-(int)$source['borrowed_qty']-$reserved<$qty)throw new RuntimeException('Available accountable quantity is insufficient.');
  if($source['state']['needs_review'])throw new RuntimeException('Reconcile the original ICS condition first.');
  $target=$pdo->prepare("SELECT u.user_id,u.full_name,p.teaching_position,p.position_office FROM users u LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE u.user_id=? AND u.role='teacher' AND u.status='active' AND u.registration_status='approved' FOR UPDATE");
  $target->execute([(int)$r['to_teacher_id']]);$person=$target->fetch(PDO::FETCH_ASSOC);
  if(!$person||$person['user_id']==$r['from_teacher_id'])throw new RuntimeException('Receiving teacher is unavailable.');
  $sourceCourse=$pdo->prepare('SELECT ii.item_id,ii.course_id FROM ics_items ii WHERE ii.ics_item_id=? FOR UPDATE');$sourceCourse->execute([(int)$r['ics_item_id']]);$line=$sourceCourse->fetch(PDO::FETCH_ASSOC);
  $allocId=null;$batchId=null;
  if($r['allocation_id']!==null){
   $st=$pdo->prepare('SELECT a.*,b.expiration_date FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id WHERE a.allocation_id=? AND a.ics_item_id=? FOR UPDATE');
   $st->execute([(int)$r['allocation_id'],(int)$r['ics_item_id']]);$a=$st->fetch(PDO::FETCH_ASSOC);
   if(!$a||$qty>(int)$a['qty_issued']-(int)$a['qty_returned']-(int)$a['qty_transferred_out'])throw new RuntimeException('Source batch allocation is insufficient.');
   if($a['expiration_date']!==null&&$a['expiration_date']<date('Y-m-d'))throw new RuntimeException('Source batch expired; finalization blocked.');
   $batchId=(int)$a['batch_id'];$allocId=(int)$a['allocation_id'];
  }elseif(!$r['legacy_unattributed']||$source['is_supply'])throw new RuntimeException('A verified source batch is required for this transfer.');
  if($source['is_supply']&&($r['declared_unused_qty']===null||(int)$r['declared_unused_qty']<$qty))throw new RuntimeException('Unused physical supply declaration insufficient.');
  $po=trim(implode(' / ',array_filter([(string)($person['teaching_position']??''),(string)($person['position_office']??'')])));
  if(!$po)$po='Teacher / Office not yet recorded';
  $today=date('Y-m-d');$newNo=generate_next_ics_number($today);
  $st=$pdo->prepare("INSERT INTO ics(ics_number,teacher_id,course_id,issued_by,date_issued,status,recipient_name_snapshot,recipient_position_office_snapshot) VALUES (?,?,NULL,?,?,'active',?,?)");
  $st->execute([$newNo,(int)$r['to_teacher_id'],$actorId,$today,$person['full_name'],$po]);$newIcsId=(int)$pdo->lastInsertId();
  $st=$pdo->prepare("INSERT INTO ics_items(ics_id,item_id,qty_issued,item_condition,course_id,transfer_origin_request_id) VALUES (?,?,?,'serviceable',?,?)");
  $st->execute([$newIcsId,(int)$line['item_id'],$qty,$line['course_id'],$requestId]);$newLineId=(int)$pdo->lastInsertId();
  if(!$source['is_supply']){
   $pdo->prepare('UPDATE ics_condition_balances SET qty_serviceable=qty_serviceable-? WHERE ics_item_id=? AND qty_serviceable>=?')->execute([$qty,(int)$r['ics_item_id'],$qty]);
   $pdo->prepare('INSERT INTO ics_condition_balances(ics_item_id,qty_serviceable,qty_for_repair,qty_damaged,needs_review) VALUES (?,?,0,0,0)')->execute([$newLineId,$qty]);
  }
  $newAlloc=null;
  if($batchId!==null){
   $pdo->prepare('UPDATE ics_batch_allocations SET qty_transferred_out=qty_transferred_out+? WHERE allocation_id=?')->execute([$qty,$allocId]);
   $pdo->prepare('INSERT INTO ics_batch_allocations(ics_item_id,batch_id,qty_issued) VALUES (?,?,?)')->execute([$newLineId,$batchId,$qty]);$newAlloc=(int)$pdo->lastInsertId();
  }
  $pdo->prepare('UPDATE ics_items SET qty_transferred_out=qty_transferred_out+? WHERE ics_item_id=?')->execute([$qty,(int)$r['ics_item_id']]);
  $pdo->prepare('INSERT INTO ics_transfer_movements(transfer_request_id,source_ics_item_id,recipient_ics_item_id,source_allocation_id,recipient_allocation_id,transferred_qty,recorded_by) VALUES (?,?,?,?,?,?,?)')->execute([$requestId,(int)$r['ics_item_id'],$newLineId,$allocId,$newAlloc,$qty,$actorId]);
  $st=$pdo->prepare("UPDATE transfer_requests SET status='completed',updated_at=NOW() WHERE request_id=? AND status='awaiting_finalization'");$st->execute([$requestId]);if($st->rowCount()!==1)throw new RuntimeException('Transfer was already processed.');
  $pdo->prepare("INSERT INTO transfer_request_events(transfer_request_id,actor_user_id,event_type,notes) VALUES (?,?,'finalize',?)")->execute([$requestId,$actorId,'New ICS '.$newNo.'; original ICS preserved.']);
  $pdo->prepare('INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,?,?,?,?)')->execute([$actorId,'Transfer Finalized','Transfers',$requestId,'Custody moved to '.$newNo.' ('.$qty.' units); original issuance retained.']);
  $pdo->commit();return ['ics_id'=>$newIcsId,'ics_number'=>$newNo,'ics_item_id'=>$newLineId];
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
