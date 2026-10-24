<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/borrow_workflow.php';
$user=require_login();$uid=(int)$user['id'];$role=$user['role'];$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
 if($role==='tvl_head'){http_response_code(403);exit('View-only access.');}
 csrf_check();
 try{
  $action=(string)($_POST['action']??'');$rid=(int)($_POST['request_id']??0);
  $pdo->beginTransaction();
  if($action==='request'){
   $line=(int)($_POST['ics_item_id']??0);$qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$purpose=trim((string)($_POST['purpose']??''));$due=(string)($_POST['expected_return_date']??'');$dueTime=(string)($_POST['expected_return_time']??'');
   if(!$line||!$qty||mb_strlen($purpose)>250||$purpose===''||!borrow6_date($due)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dueTime)||($due.' '.$dueTime)<date('Y-m-d H:i'))throw new InvalidArgumentException('Choose an item, a valid quantity, a purpose and a future return date and time.');
   $s=borrow6_state($pdo,$line,true);$borrower=$role==='teacher'?$uid:(int)($_POST['borrower_id']??0);
   if($borrower===(int)$s['teacher_id'])throw new InvalidArgumentException('Cannot borrow from your own ICS.');
   $check=$pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id=? AND role='teacher' AND status='active' AND registration_status='approved'");$check->execute([$borrower]);if(!$check->fetchColumn())throw new InvalidArgumentException('Borrower must be an active approved teacher.');
   if($qty>$s['available'])throw new InvalidArgumentException('Only '.$s['available'].' currently available to borrow.');
   $pdo->prepare('INSERT INTO borrow_requests(ics_item_id,lender_id,borrower_id,quantity,purpose,expected_return_date,expected_return_time) VALUES(?,?,?,?,?,?,?)')->execute([$line,$s['teacher_id'],$borrower,$qty,$purpose,$due,$dueTime]);
   $rid=(int)$pdo->lastInsertId();borrow6_event($pdo,$rid,$uid,'Requested','Quantity '.$qty.'; due '.$due.' '.$dueTime.'; purpose: '.$purpose);
  }else{
   $st=$pdo->prepare('SELECT * FROM borrow_requests WHERE request_id=? FOR UPDATE');$st->execute([$rid]);$r=$st->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Request not found.');
   $isHolder=(int)$r['lender_id']===$uid;$isBorrower=(int)$r['borrower_id']===$uid;$isAdmin=$role==='supply_officer';
   $actorAllowed=$isHolder||$isAdmin;
   if($action==='cancel'){
    if(!($isBorrower||$isAdmin)||$r['status']!=='requested')throw new InvalidArgumentException('Only the requester can cancel an open request.');
    $pdo->prepare("UPDATE borrow_requests SET status='cancelled',completed_at=NOW() WHERE request_id=?")->execute([$rid]);borrow6_event($pdo,$rid,$uid,'Cancelled');
   }elseif($action==='approve'||$action==='decline'){
    if(!$actorAllowed||$r['status']!=='requested')throw new InvalidArgumentException('Only the accountable holder can decide a pending request.');
    $s=borrow6_state($pdo,(int)$r['ics_item_id'],true);
    if($action==='approve'){
     // This request is already reserved; exclude it from queued quantity when checking.
     if((int)$r['quantity']>($s['available']+(int)$r['quantity']))throw new InvalidArgumentException('Not enough units remain available.');
     $pdo->prepare("UPDATE borrow_requests SET status='approved',approved_at=NOW(),reviewed_by=? WHERE request_id=?")->execute([$uid,$rid]);borrow6_event($pdo,$rid,$uid,'Approved');
    }else{
     $reason=trim((string)($_POST['notes']??''));if($reason==='')throw new InvalidArgumentException('Provide a reason for declining.');
     $pdo->prepare("UPDATE borrow_requests SET status='declined',completed_at=NOW(),reviewed_by=?,notes=? WHERE request_id=?")->execute([$uid,mb_substr($reason,0,500),$rid]);borrow6_event($pdo,$rid,$uid,'Declined',$reason);
    }
   }elseif($action==='release'){
    if(!$actorAllowed||$r['status']!=='approved')throw new InvalidArgumentException('Only the accountable holder can release an approved request.');
    $s=borrow6_state($pdo,(int)$r['ics_item_id'],true);
    if((int)$r['quantity']>$s['available']+(int)$r['quantity'])throw new InvalidArgumentException('Insufficient serviceable stock for release.');
    $pdo->prepare("INSERT INTO borrow_logs(ics_item_id,borrower_id,quantity,program_purpose,date_borrowed,status) VALUES(?,?,?,?,CURDATE(),'borrowed')")->execute([$r['ics_item_id'],$r['borrower_id'],$r['quantity'],mb_substr($r['purpose'],0,200)]);
    $bid=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE borrow_requests SET status='borrowed',borrow_id=?,released_at=NOW() WHERE request_id=?")->execute([$bid,$rid]);borrow6_event($pdo,$rid,$uid,'Released','Loan #'.$bid);
   }elseif($action==='return_request'){
    if(!$isBorrower||!in_array($r['status'],['borrowed','partially_returned'],true))throw new InvalidArgumentException('Only the borrower may submit an active return.');
    $outstanding=(int)$r['quantity']-(int)$r['returned_qty'];
    $srv=(int)($_POST['return_serviceable']??0);$rep=(int)($_POST['return_repair']??0);$dam=(int)($_POST['return_damaged']??0);$total=$srv+$rep+$dam;
    if($srv<0||$rep<0||$dam<0||$total<1||$total>$outstanding)throw new InvalidArgumentException('Return quantities must be positive and no greater than the amount outstanding.');
    if(borrow6_scalar($pdo,"SELECT COUNT(*) FROM borrow_return_requests WHERE request_id=? AND status='pending'",[$rid]))throw new InvalidArgumentException('A return is already awaiting verification.');
    $pdo->prepare('INSERT INTO borrow_return_requests(request_id,quantity,qty_serviceable,qty_for_repair,qty_damaged) VALUES(?,?,?,?,?)')->execute([$rid,$total,$srv,$rep,$dam]);borrow6_event($pdo,$rid,$uid,'Return submitted','Qty '.$total.'; serviceable '.$srv.'; for repair '.$rep.'; damaged '.$dam.'; note: '.mb_substr(trim((string)($_POST['return_note']??'')),0,230));
   }elseif($action==='verify_return'||$action==='reject_return'){
    if(!$actorAllowed||!in_array($r['status'],['borrowed','partially_returned'],true))throw new InvalidArgumentException('Only the holder can verify an active return.');
    $returnId=(int)($_POST['return_id']??0);$rs=$pdo->prepare("SELECT * FROM borrow_return_requests WHERE return_id=? AND request_id=? FOR UPDATE");$rs->execute([$returnId,$rid]);$ret=$rs->fetch(PDO::FETCH_ASSOC);
    if(!$ret||$ret['status']!=='pending')throw new InvalidArgumentException('Return is no longer pending.');
    if($action==='reject_return'){
     $note=trim((string)($_POST['notes']??''));if($note==='')throw new InvalidArgumentException('Provide a reason for rejecting the return.');
     $pdo->prepare("UPDATE borrow_return_requests SET status='rejected',reviewed_by=?,reviewed_at=NOW(),notes=? WHERE return_id=?")->execute([$uid,mb_substr($note,0,500),$returnId]);borrow6_event($pdo,$rid,$uid,'Return rejected',$note);
    }else{
     $state=ics31_state($pdo,(int)$r['ics_item_id'],true);$bad=(int)$ret['qty_for_repair']+(int)$ret['qty_damaged'];
     if($bad>0){
      if($state['qty_serviceable']<$bad)throw new InvalidArgumentException('Insufficient serviceable balance to reclassify damaged returns. Contact Supply Officer.');
      $pdo->prepare('UPDATE ics_condition_balances SET qty_serviceable=qty_serviceable-?,qty_for_repair=qty_for_repair+?,qty_damaged=qty_damaged+? WHERE ics_item_id=?')->execute([$bad,$ret['qty_for_repair'],$ret['qty_damaged'],$r['ics_item_id']]);
     }
     $newReturned=(int)$r['returned_qty']+(int)$ret['quantity'];$outstanding=(int)$r['quantity']-$newReturned;
     if($outstanding<0)throw new InvalidArgumentException('Return exceeds borrowed balance.');
     $pdo->prepare("UPDATE borrow_return_requests SET status='verified',reviewed_by=?,reviewed_at=NOW() WHERE return_id=?")->execute([$uid,$returnId]);
     $pdo->prepare("UPDATE borrow_requests SET returned_qty=?,status=?,completed_at=IF(?=0,NOW(),NULL) WHERE request_id=?")->execute([$newReturned,$outstanding===0?'returned':'partially_returned',$outstanding,$rid]);
     $pdo->prepare("UPDATE borrow_logs SET quantity=?,status=?,date_returned=IF(?=0,CURDATE(),NULL) WHERE borrow_id=?")->execute([$outstanding,$outstanding===0?'returned':'borrowed',$outstanding,$r['borrow_id']]);
     borrow6_event($pdo,$rid,$uid,'Return verified','Qty '.$ret['quantity'].'; serviceable '.$ret['qty_serviceable'].'; repair '.$ret['qty_for_repair'].'; damaged '.$ret['qty_damaged']);
    }
   }else throw new InvalidArgumentException('Unknown borrowing action.');
  }
  $pdo->commit();flash('success','Borrowing action saved.');redirect('borrow.php');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'Could not process request. Review data and try again.');redirect('borrow.php');}
}
$teachers=$pdo->query("SELECT user_id,full_name FROM users WHERE role='teacher' AND status='active' AND registration_status='approved' ORDER BY full_name")->fetchAll();
$lines=$pdo->query("SELECT ii.ics_item_id,i.item_id,i.item_name,i.item_category,i.unit,c.ics_number,c.date_issued,c.teacher_id,u.full_name AS holder_name,co.course_name FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=c.teacher_id JOIN inventory_items i ON i.item_id=ii.item_id LEFT JOIN courses co ON co.course_id=ii.course_id WHERE c.status='active' AND i.item_category IN('equipment','non_consumable') ORDER BY i.item_name,u.full_name,c.ics_number")->fetchAll();
$items=[];foreach($lines as $line){try{$state=borrow6_state($pdo,(int)$line['ics_item_id']);}catch(Throwable $ex){continue;}$line=array_merge($line,['accountable'=>$state['condition']['remaining_qty'],'serviceable'=>$state['serviceable'],'borrowed'=>$state['borrowed'],'reserved'=>$state['queued']+$state['transfer_reserved'],'available'=>$state['available']]);$id=(int)$line['item_id'];if(!isset($items[$id]))$items[$id]=['item'=>$line,'lines'=>[],'accountable'=>0,'serviceable'=>0,'borrowed'=>0,'reserved'=>0,'available'=>0];foreach(['accountable','serviceable','borrowed','reserved','available'] as $k)$items[$id][$k]+=$line[$k];$items[$id]['lines'][]=$line;}
$sql="SELECT br.*, i.item_name,i.unit,c.ics_number,lu.full_name lender_name,bu.full_name borrower_name FROM borrow_requests br JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users lu ON lu.user_id=br.lender_id JOIN users bu ON bu.user_id=br.borrower_id";
$args=[];if($role==='teacher'){$sql.=' WHERE br.borrower_id=? OR br.lender_id=?';$args=[$uid,$uid];}$sql.=' ORDER BY br.requested_at DESC,br.request_id DESC';$st=$pdo->prepare($sql);$st->execute($args);$requests=$st->fetchAll();
$eventMap=[];
if($requests){$ids=array_map('intval',array_column($requests,'request_id'));$qs=implode(',',array_fill(0,count($ids),'?'));$ev=$pdo->prepare("SELECT e.request_id,e.action,e.note,e.created_at,u.full_name AS actor_name FROM borrow_request_events e JOIN users u ON u.user_id=e.actor_id WHERE e.request_id IN ($qs) ORDER BY e.created_at,e.event_id");$ev->execute($ids);foreach($ev->fetchAll(PDO::FETCH_ASSOC) as $event){$eventMap[$event['request_id']][]=$event;}}
$pendingReturns=$pdo->query("SELECT r.*,br.lender_id,br.borrower_id FROM borrow_return_requests r JOIN borrow_requests br ON br.request_id=r.request_id WHERE r.status='pending'")->fetchAll();$returnMap=[];foreach($pendingReturns as $r)$returnMap[$r['request_id']]=$r;
$pageTitle='Borrow & Return';$active='borrow';require __DIR__.'/includes/header.php';
?>
<div class="p6-screen">
<header class="p6-heading"><div><h2>Borrow &amp; Return</h2><p>Browse equipment currently accountable to teachers. ICS ownership stays unchanged while borrowed.</p></div><a class="btn btn-outline" href="#p6-history">Borrowing records ↓</a></header>
<div class="p6-stats"><div><small>Total serviceable</small><strong><?=array_sum(array_column($items,'serviceable'))?></strong></div><div><small>Available to borrow</small><strong><?=array_sum(array_column($items,'available'))?></strong></div><div><small>Currently borrowed</small><strong><?=array_sum(array_column($items,'borrowed'))?></strong></div></div>
<section class="card p6-panel"><div class="p6-panel-title"><h3>Issued item inventory</h3><span>Expand an item to see its ICS holders and borrowable quantities.</span></div>
<div class="p6-filters"><label>Search <input id="p6-search" placeholder="Item, teacher, ICS number" class="input"></label><label>Category <select id="p6-cat" class="input"><option value="">All categories</option><option value="equipment">Equipment</option><option value="non_consumable">Non-Consumable</option></select></label><label>Availability <select id="p6-av" class="input"><option value="">All</option><option value="yes">Available</option><option value="no">Unavailable</option></select></label><label>Course <select id="p6-course" class="input"><option value="">All courses</option><option value="none">Unclassified</option><?php foreach($pdo->query('SELECT course_name FROM courses ORDER BY course_name')->fetchAll() as $c):?><option value="<?=e($c['course_name'])?>"><?=e($c['course_name'])?></option><?php endforeach;?></select></label></div>
<div class="p6-scroll"><table class="p6-table" id="p6-items"><thead><tr><?php foreach(['Item','Category','Accountable','Serviceable','Borrowed','Available'] as $col):?><th><button type="button" class="p6-sort" data-sort="<?=e($col)?>"><?=e($col)?> <span class="p6ui-sort-indicator" aria-hidden="true">–</span></button></th><?php endforeach;?><th>Details</th></tr></thead><tbody><?php foreach($items as $entry):$item=$entry['item'];$courses=array_values(array_unique(array_map(static fn($l)=>$l['course_name']?:'Unclassified',$entry['lines'])));$courseText=implode(', ',$courses);?><tr class="p6-item-row" data-category="<?=e($item['item_category'])?>" data-course="<?=e($courseText)?>" data-search="<?=e(strtolower($item['item_name'].' '.implode(' ',array_column($entry['lines'],'holder_name')).' '.implode(' ',array_column($entry['lines'],'ics_number'))))?>" data-available="<?=$entry['available']?>"><td><strong><?=e($item['item_name'])?></strong></td><td><?=e(ucwords(str_replace('_',' ',$item['item_category'])))?></td><td><?=$entry['accountable']?> <?=e($item['unit'])?></td><td><?=$entry['serviceable']?></td><td><?=$entry['borrowed']?></td><td><strong class="p6-green"><?=$entry['available']?></strong></td><td><button type="button" class="p6-open btn btn-outline" aria-expanded="false">View details ▾</button></td></tr><tr class="p6-detail-row" hidden><td colspan="7"><div class="p6-details"><h4><?=e($item['item_name'])?> — accountable teachers</h4><div class="p6-holder-grid"><?php foreach($entry['lines'] as $h):?><div class="p6-holder"><div class="p6-holder-top"><strong><?=e($h['holder_name'])?></strong><span class="p6-pill"><?=$h['available']?> borrowable</span></div><small><?=e($h['ics_number'])?> · <?=e($h['course_name']?:'Unclassified')?></small><p>Accountable <b><?=$h['accountable']?></b> · Serviceable <b><?=$h['serviceable']?></b> · Borrowed <b><?=$h['borrowed']?></b> · Reserved <b><?=$h['reserved']?></b></p><?php if($role==='teacher'&&$uid===(int)$h['teacher_id']):?><small>Your own ICS — you cannot borrow from yourself.</small><?php elseif($role==='tvl_head'):?><small>View only</small><?php elseif($h['available']>0):?><button type="button" class="btn btn-primary p6-request-btn" data-line="<?=$h['ics_item_id']?>" data-name="<?=e($item['item_name'])?>" data-holder="<?=e($h['holder_name'])?>" data-ics="<?=e($h['ics_number'])?>" data-max="<?=$h['available']?>">Request to borrow</button><?php else:?><small>Not currently available.</small><?php endif;?></div><?php endforeach;?></div></div></td></tr><?php endforeach;?></tbody></table></div>
<?php if(!$items):?><p>No active equipment or non-consumable items are currently accountable to teachers.</p><?php endif;?></section>
<section class="card p6-panel" id="p6-request" hidden><h3>Send Borrow Request</h3><p id="p6-selected"></p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="request"><input type="hidden" name="ics_item_id" id="p6-line"><div class="p6-form-grid"><?php if($role==='supply_officer'):?><label>Borrower <select class="input" name="borrower_id" required><option value="">Choose teacher</option><?php foreach($teachers as $t):?><option value="<?=$t['user_id']?>"><?=e($t['full_name'])?></option><?php endforeach;?></select></label><?php endif;?><label>Quantity to borrow <input class="input" type="number" min="1" name="quantity" id="p6-qty" required value="1"></label><label>Expected return date <input class="input" type="date" name="expected_return_date" min="<?=date('Y-m-d')?>" required></label><label>Expected return time <input class="input" type="time" name="expected_return_time" required></label></div><label class="p6-range-label">Quantity slider <input type="range" min="1" value="1" id="p6-range"><small id="p6-range-status"></small></label><label>Purpose <textarea class="input" name="purpose" maxlength="250" rows="2" required placeholder="e.g. Laboratory demonstration"></textarea></label><div class="p6-actions"><button class="btn btn-primary">Send Borrow Request</button><button class="btn btn-outline" type="button" id="p6-cancel">Cancel</button></div></form></section>
<section class="card p6-panel" id="p6-history">
 <div class="p6-panel-title"><h3>Requests, Active Loans &amp; Returns</h3><span>Purpose, approvals, corrections and verified returns remain available in Details.</span></div>
 <div class="p3813-controls">
 <label>Search<input class="input" id="p3813-search" placeholder="Item, ICS, borrower, holder, purpose"></label>
 <label>Status<select class="input" id="p3813-status"><option value="">All statuses</option><?php foreach(['requested','approved','borrowed','partially_returned','returned','declined','cancelled'] as $status):?><option value="<?=$status?>"><?=e(ucwords(str_replace('_',' ',$status)))?></option><?php endforeach;?></select></label>
 <label>From<input class="input" type="date" id="p3813-from"></label>
 <label>To<input class="input" type="date" id="p3813-to"></label>
 <label>Sort<select class="input" id="p3813-sort"><option value="default">Default (newest)</option><option value="new">Newest first</option><option value="old">Oldest first</option><option value="due_asc">Due date ↑</option><option value="due_desc">Due date ↓</option><option value="item_asc">Item A–Z</option><option value="item_desc">Item Z–A</option></select></label>
 <button class="btn btn-outline" id="p3813-reset" type="button">Reset</button>
 </div>
 <div class="p6-scroll"><table class="p6-table p3813-records" id="p6-records"><thead><tr>
 <th>Item / ICS</th><th>Holder → Borrower</th><th>Qty / Returned</th><th>Expected Return</th><th>Status</th><th>Details / Actions</th>
 </tr></thead><tbody>
 <?php foreach($requests as $r):
 $isHolder=(int)$r['lender_id']===$uid;$isBorrower=(int)$r['borrower_id']===$uid;$canHolder=$isHolder||$role==='supply_officer';
 $out=max(0,(int)$r['quantity']-(int)$r['returned_qty']);$pending=$returnMap[$r['request_id']]??null;
 $dueTime=isset($r['expected_return_time'])&&$r['expected_return_time']!==null?substr((string)$r['expected_return_time'],0,5):'';
 $dueDatetime=$r['expected_return_date'].($dueTime?' '.$dueTime:'');
 $overdue=in_array($r['status'],['borrowed','partially_returned'],true)&&($dueTime?$dueDatetime<date('Y-m-d H:i'):$r['expected_return_date']<date('Y-m-d'));
 ?>
 <tr class="p6-record p3813-record" id="borrow-request-<?=$r['request_id']?>" data-request="<?=$r['request_id']?>" data-requested="<?=e(substr($r['requested_at'],0,10))?>" data-due="<?=e($dueDatetime)?>" data-item="<?=e(strtolower($r['item_name']))?>" data-status="<?=e($r['status'])?>" data-search="<?=e(strtolower($r['item_name'].' '.$r['ics_number'].' '.$r['lender_name'].' '.$r['borrower_name'].' '.$r['purpose'].' '.($r['notes']??'')))?>">
 <td><strong><?=e($r['item_name'])?></strong><small class="p3813-sub"><?=e($r['ics_number'])?></small></td>
 <td><strong><?=e($r['lender_name'])?></strong> → <?=e($r['borrower_name'])?></td>
 <td><?=$r['quantity']?> / <?=$r['returned_qty']?> <?=e($r['unit'])?></td>
 <td><?=e($r['expected_return_date'])?><?php if($dueTime):?><small class="p3813-sub"><?=e(date('g:i A',strtotime($dueTime)))?></small><?php else:?><small class="p3813-sub">Time not recorded</small><?php endif;?><?php if($overdue):?><span class="p6-overdue">Overdue</span><?php endif;?></td>
 <td><span class="p6-pill"><?=e(ucwords(str_replace('_',' ',$r['status'])))?></span></td>
 <td><button type="button" class="btn btn-outline p3813-toggle" aria-expanded="false" aria-controls="borrow-details-<?=$r['request_id']?>">View Details ▾</button></td>
 </tr>
 <tr class="p3813-detail" id="borrow-details-<?=$r['request_id']?>" hidden><td colspan="6">
 <div class="p3813-detail-inner">
 <div class="p3813-detail-grid">
 <div><small>Borrowing purpose</small><p><?=nl2br(e($r['purpose']?:'–'))?></p></div>
 <div><small>Requested</small><p><?=e(date('M j, Y g:i A',strtotime($r['requested_at'])))?></p></div>
 <div><small>ICS holder</small><p><?=e($r['lender_name'])?></p></div>
 <div><small>Borrower</small><p><?=e($r['borrower_name'])?></p></div>
 <div><small>Outstanding quantity</small><p><?=$out?> <?=e($r['unit'])?></p></div>
 <div><small>Decision remarks</small><p><?=nl2br(e($r['notes']?:'–'))?></p></div>
 </div>
 <h4>Action history &amp; remarks</h4>
 <div class="p3813-events"><?php foreach(($eventMap[$r['request_id']]??[]) as $ev):
 $eventNote=trim((string)($ev['note']??''));
 $qtyLabel='';
 if(preg_match('/\b(?:Qty|Quantity)\s*[:#]?\s*(\d+)\b/i',$eventNote,$qmatch)){$qtyLabel=$qmatch[1].' '.($r['unit']?:'units');}
 ?><div class="p3813-event">
 <div class="p3813-event-heading"><strong class="p3813-event-action"><?=e($ev['action'])?></strong><time datetime="<?=e(str_replace(' ','T',$ev['created_at']))?>"><?=e(date('M j, Y · g:i A',strtotime($ev['created_at'])))?></time></div>
 <div class="p3813-event-by">By <strong><?=e($ev['actor_name'])?></strong><?php if($qtyLabel!==''):?><span class="p3813-event-qty">Qty: <?=e($qtyLabel)?></span><?php endif;?></div>
 <?php if($eventNote!==''):?><p class="p3813-event-note"><span>Note:</span> <em><?=nl2br(e($eventNote))?></em></p><?php endif;?></div><?php endforeach;?><?php if(empty($eventMap[$r['request_id']])):?><p>– No recorded events.</p><?php endif;?></div>
 <?php if($pending):?><p class="p3813-pending">Pending return: <?=$pending['quantity']?> units — Serviceable <?=$pending['qty_serviceable']?> · For repair <?=$pending['qty_for_repair']?> · Damaged <?=$pending['qty_damaged']?></p><?php endif;?>
 <h4>Available actions</h4>
 <div class="p6-inline-actions"><?php if($r['status']==='requested'&&$canHolder):?><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><button name="action" value="approve" class="btn btn-primary">Approve</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><input class="input" name="notes" placeholder="Decline reason" required><button name="action" value="decline" class="btn btn-outline">Decline</button></form><?php elseif($r['status']==='requested'&&($isBorrower||$role==='supply_officer')):?><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><button name="action" value="cancel" class="btn btn-outline">Cancel</button></form><?php endif;?><?php if($r['status']==='approved'&&$canHolder):?><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><button name="action" value="release" class="btn btn-primary">Confirm Release</button></form><?php endif;?><?php if(in_array($r['status'],['borrowed','partially_returned'],true)&&$isBorrower&&!$pending):?><details><summary>Return items</summary><form method="post" class="p6-return-form"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><label>Serviceable <input class="input" type="number" min="0" max="<?=$out?>" value="<?=$out?>" name="return_serviceable"></label><label>For repair <input class="input" type="number" min="0" max="<?=$out?>" value="0" name="return_repair"></label><label>Damaged <input class="input" type="number" min="0" max="<?=$out?>" value="0" name="return_damaged"></label><small>Maximum outstanding: <?=$out?></small><label>Return condition notes (optional)<textarea class="input" name="return_note" maxlength="230" rows="2" placeholder="Describe any damage or quantity issue"></textarea></label><button class="btn btn-primary" name="action" value="return_request">Submit for verification</button></form></details><?php endif;?><?php if($pending):?><?php if($canHolder):?><details><summary>Verify return (<?=$pending['quantity']?>)</summary><p>Serviceable <?=$pending['qty_serviceable']?> · Repair <?=$pending['qty_for_repair']?> · Damaged <?=$pending['qty_damaged']?></p><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><input type="hidden" name="return_id" value="<?=$pending['return_id']?>"><button name="action" value="verify_return" class="btn btn-primary">Verify Return</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['request_id']?>"><input type="hidden" name="return_id" value="<?=$pending['return_id']?>"><input class="input" name="notes" placeholder="Reason" required><button name="action" value="reject_return" class="btn btn-outline">Reject</button></form></details><?php else:?><small>Return awaiting holder verification</small><?php endif;?><?php endif;?></div>
 </div></td></tr>
 <?php endforeach;?></tbody></table></div>
 <div class="p3813-footer"><span id="p3813-count" aria-live="polite"></span><div class="p3813-pages"><button type="button" class="btn btn-outline" id="p3813-prev">Previous</button><span id="p3813-page"></span><button type="button" class="btn btn-outline" id="p3813-next">Next</button></div></div>
 <?php if(!$requests):?><p>No borrowing requests yet.</p><?php endif;?>
 </section>
</div>
<script>
(()=>{const $=id=>document.getElementById(id),rows=[...document.querySelectorAll('.p6-item-row')],search=$('p6-search'),category=$('p6-cat'),availability=$('p6-av'),course=$('p6-course');let sortKey='',sortDir=1;function filter(){rows.forEach(r=>{const match=r.dataset.search.includes(search.value.toLowerCase())&&(!category.value||r.dataset.category===category.value)&&(!availability.value||(availability.value==='yes'?+r.dataset.available>0:+r.dataset.available===0))&&(!course.value||(course.value==='none'?r.dataset.course.includes('Unclassified'):r.dataset.course.includes(course.value)));r.hidden=!match;if(!match)r.nextElementSibling.hidden=true;});} [search,category,availability,course].forEach(el=>el.addEventListener('input',filter));rows.forEach(r=>r.querySelector('.p6-open').addEventListener('click',()=>{const d=r.nextElementSibling;d.hidden=!d.hidden;r.querySelector('.p6-open').setAttribute('aria-expanded',String(!d.hidden));}));document.querySelectorAll('.p6-sort').forEach(btn=>btn.addEventListener('click',()=>{sortDir=sortKey===btn.dataset.sort?-sortDir:1;sortKey=btn.dataset.sort;const ix=[...btn.closest('tr').children].indexOf(btn.closest('th'));rows.sort((a,b)=>{const A=a.cells[ix].innerText.trim(),B=b.cells[ix].innerText.trim();return sortDir*(Number.isNaN(+A)||Number.isNaN(+B)?A.localeCompare(B):(+A)-(+B));});const tbody=$('p6-items').tBodies[0];rows.forEach(r=>{tbody.append(r,r.nextElementSibling);});}));const qty=$('p6-qty'),range=$('p6-range');document.querySelectorAll('.p6-request-btn').forEach(b=>b.addEventListener('click',()=>{$('p6-request').hidden=false;$('p6-line').value=b.dataset.line;$('p6-selected').textContent=b.dataset.name+' · '+b.dataset.holder+' · '+b.dataset.ics+' · '+b.dataset.max+' available';qty.max=b.dataset.max;range.max=b.dataset.max;qty.value=range.value=1;$('p6-range-status').textContent='1 of '+b.dataset.max;$('p6-request').scrollIntoView({behavior:'smooth',block:'center'});}));range.addEventListener('input',()=>{qty.value=range.value;$('p6-range-status').textContent=range.value+' of '+range.max;});qty.addEventListener('input',()=>{range.value=qty.value;$('p6-range-status').textContent=qty.value+' of '+range.max;});$('p6-cancel').addEventListener('click',()=>$('p6-request').hidden=true);})();
</script>
<script>
// Phase 6 visual refinements: a dash is neutral, arrows mark active direction.
(()=>{let active=null,dir='asc';document.querySelectorAll('#p6-items .p6-sort').forEach(btn=>{btn.setAttribute('aria-label','Sort by '+btn.dataset.sort);btn.addEventListener('click',()=>{if(active===btn){dir=dir==='asc'?'desc':'asc';}else{active=btn;dir='asc';}document.querySelectorAll('#p6-items .p6-sort').forEach(b=>{const indicator=b.querySelector('.p6ui-sort-indicator');if(indicator)indicator.textContent=b===active?(dir==='asc'?'↑':'↓'):'–';b.setAttribute('aria-sort',b===active?(dir==='asc'?'ascending':'descending'):'none');});});});})();
</script>
<style>
.p3813-controls{display:grid;grid-template-columns:minmax(190px,2fr) repeat(4,minmax(115px,1fr)) auto;gap:10px;align-items:end;margin:14px 0 16px}.p3813-controls label{font-size:12px;font-weight:650;display:grid;gap:5px;min-width:0}.p3813-controls .input{width:100%;min-width:0}.p3813-records{width:100%;font-size:13px}.p3813-records th,.p3813-records td{padding:11px 9px;vertical-align:top}.p3813-records .p3813-sub{display:block;color:#697387;margin-top:4px}.p3813-detail>td{background:#f8f9fc;padding:16px!important}.p3813-detail-inner{border-left:3px solid #781b3b;padding:4px 8px 6px 16px}.p3813-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 18px}.p3813-detail-grid small{color:#6b7280;font-weight:650}.p3813-detail-grid p{margin:4px 0;overflow-wrap:anywhere}.p3813-detail-inner h4{margin:14px 0 7px}.p3813-events{max-height:240px;overflow:auto;border:1px solid #e5e8ef;border-radius:9px;background:white}.p3813-event{padding:9px 12px;border-bottom:1px solid #edf0f3}.p3813-event:last-child{border-bottom:none}.p3813-event span{font-size:12px;color:#65718a;margin-left:9px}.p3813-event p{margin:5px 0 0;white-space:pre-line;overflow-wrap:anywhere}.p3813-pending{padding:10px;background:#fff5e6;border-radius:8px}.p3813-footer,.p3813-pages{display:flex;gap:9px;align-items:center;justify-content:space-between;margin-top:12px}.p3813-pages{justify-content:end;margin:0}.p3813-toggle{white-space:nowrap}.p3813-records .p6-inline-actions{display:flex;flex-wrap:wrap;gap:10px}.p3813-records .p6-inline-actions form{max-width:100%} .p3813-records .p6-return-form{max-width:550px}
@media(max-width:1160px){.p3813-controls{grid-template-columns:repeat(3,minmax(0,1fr))}.p3813-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.p3813-controls{grid-template-columns:repeat(2,minmax(0,1fr))}.p3813-controls label:first-child{grid-column:1/-1}.p3813-detail-grid{grid-template-columns:1fr}.p3813-records{min-width:740px}.p3813-footer{flex-wrap:wrap}.p3813-detail>td{padding:10px!important}}

/* Phase 3.8.13b: compact borrowing tables at normal browser zoom */
.p6-screen,.p6-screen .p6-panel{min-width:0;max-width:100%}
.p6-screen .p6-scroll{max-width:100%;min-width:0;overflow-x:auto}
.p6-screen #p6-items,.p6-screen #p6-records{min-width:0;width:100%;table-layout:fixed}
.p6-screen #p6-items th,.p6-screen #p6-items td{padding:10px 7px;white-space:normal;overflow-wrap:anywhere;vertical-align:middle}
.p6-screen #p6-items th{font-size:10px;line-height:1.35}
.p6-screen #p6-items td{font-size:12px}
.p6-screen #p6-items th:nth-child(1){width:21%}
.p6-screen #p6-items th:nth-child(2){width:17%}
.p6-screen #p6-items th:nth-child(3){width:15%}
.p6-screen #p6-items th:nth-child(4){width:13%}
.p6-screen #p6-items th:nth-child(5){width:11%}
.p6-screen #p6-items th:nth-child(6){width:11%}
.p6-screen #p6-items th:nth-child(7){width:12%}
.p6-screen #p6-items .p6-open{white-space:normal;line-height:1.35;padding:7px 6px}
.p6-screen #p6-records th,.p6-screen #p6-records td{overflow-wrap:anywhere}
.p6-screen #p6-records th:nth-child(1){width:17%}
.p6-screen #p6-records th:nth-child(2){width:22%}
.p6-screen #p6-records th:nth-child(3){width:15%}
.p6-screen #p6-records th:nth-child(4){width:19%}
.p6-screen #p6-records th:nth-child(5){width:12%}
.p6-screen #p6-records th:nth-child(6){width:15%}
.p3813-event{padding:12px 14px}
.p3813-event-heading{display:flex;justify-content:space-between;gap:6px 15px;align-items:baseline;flex-wrap:wrap}
.p3813-event-action{color:#671831;font-size:13px}
.p3813-event-heading time{font-size:11px;color:#66748a;font-weight:600}
.p3813-event-by{margin-top:6px;color:#68758a;font-size:12px}
.p3813-event-by strong{color:#25324b;font-style:italic}
.p3813-event-qty{display:inline-block;margin-left:12px;color:#671831;font-size:11px;font-weight:700;background:#f9ecf0;border-radius:6px;padding:2px 7px}
.p3813-event p.p3813-event-note{margin:8px 0 0;background:#f8f9fc;border-left:2px solid #c995a7;border-radius:0 6px 6px 0;padding:8px 10px;line-height:1.5;color:#354158}
.p3813-event-note span{font-weight:700;font-size:11px;color:#68758a;font-style:normal}
.p3813-event-note em{font-style:italic;overflow-wrap:anywhere}
@media(max-width:820px){.p6-screen #p6-items,.p6-screen #p6-records{min-width:680px;table-layout:auto}.p6-screen #p6-items th,.p6-screen #p6-items td{padding:10px 8px}}
</style>
<script src="assets/js/borrow_tables.js" defer></script>
<?php require __DIR__.'/includes/footer.php'; ?>

<script>
(()=>{const update=(el)=>{if(!el)return;const min=Number(el.min)||0,max=Number(el.max)||1,value=Number(el.value)||min;const percent=Math.max(0,Math.min(100,(value-min)/(max-min||1)*100));el.style.setProperty('--slider-progress',percent+'%')};const bind=(id)=>{const el=document.getElementById(id);if(!el)return;['input','change'].forEach(name=>el.addEventListener(name,()=>update(el)));new MutationObserver(()=>update(el)).observe(el,{attributes:true,attributeFilter:['max','min','disabled']});update(el)};bind('p6-range');bind('p511Range');})();
</script>