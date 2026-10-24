<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ics_accountability.php';
require_once __DIR__ . '/includes/maintenance_safety.php';
require_once __DIR__ . '/includes/p72_batches.php';
require_once __DIR__ . '/includes/p72_evidence.php';
require_once __DIR__ . '/includes/p72_picker.php';
require_once __DIR__ . '/includes/p73_condemnation.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$role = $user['role'];
$uid = (int)($user['user_id'] ?? $user['id']);

$request_error = '';
// Phase 7.1: a damage record is independent from a formal condemnation request.
// Only already-damaged units in the teacher's physical accountability may be requested.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_condemnation'])) {
    if ($role !== 'teacher') { http_response_code(403); exit('Only teachers may request condemnation for their ICS items.'); }
    csrf_check();
    $ics_item_id=(int)($_POST['ics_item_id']??0);
    $quantity=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $reason=trim((string)($_POST['reason']??''));
    $allocationId=(int)($_POST['allocation_id']??0);
    $requestPhoto=null;
    if (!$ics_item_id || $quantity === false || mb_strlen($reason)<8 || mb_strlen($reason)>2000) {
        $request_error='Choose a damaged item, enter a valid quantity, and explain why it cannot be repaired (at least 8 characters).';
    } else {
        $pdo=db();
        try {
            $pdo->beginTransaction();
            // All maintenance, transfer and condemnation edits synchronize on this ICS row.
            $stmt=$pdo->prepare("SELECT ii.ics_item_id FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.ics_item_id=? AND c.teacher_id=? AND c.status='active' FOR UPDATE");
            $stmt->execute([$ics_item_id,$uid]);
            if(!$stmt->fetchColumn())throw new RuntimeException('The item is no longer on your active ICS.');
            $balance=ics31_state($pdo,$ics_item_id,true);
            if(!$balance['is_equipment'] || $balance['needs_review'])throw new RuntimeException('This item requires Supply Officer reconciliation first.');
            $free=p71_availability($pdo,$ics_item_id,$balance);
            if($free['custody_inconsistent'] || $free['reservation_inconsistent'])throw new RuntimeException('Outstanding loans or reservations conflict with serviceable units. Reconcile custody with the Supply Officer before requesting condemnation.');
            if($quantity>$free['damaged'])throw new RuntimeException('Only '.$free['damaged'].' damaged unit(s) are free for a new condemnation request. Report damage in Maintenance first.');
            $verifiedAllocation=p72_validate_batch($pdo,$ics_item_id,$allocationId,$quantity);
            p73_guard_source_claim($pdo,$ics_item_id,$verifiedAllocation,$quantity);
            $requestPhoto=p72_save_evidence($_FILES['request_photo']??null);
            $form=p71_cf_number($pdo);
            $pdo->prepare("INSERT INTO condemnations(form_number,ics_item_id,allocation_id,request_photo,quantity,reason,requested_by,status,outcome) VALUES(?,?,?,?,?,?,?,'requested',NULL)")
                ->execute([$form,$ics_item_id,$verifiedAllocation,$requestPhoto,$quantity,$reason,$uid]);
            $newId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES(?,'Requested Condemnation','Condemnation',?,?)")
                ->execute([$uid,$newId,"$form: requested $quantity damaged unit(s); reason: $reason"]);
            $pdo->commit(); header('Location: condemnation.php?requested=1'); exit;
        } catch(Throwable $ex) {
            if($pdo->inTransaction())$pdo->rollBack();
            p72_delete_evidence($requestPhoto);
            $request_error=$ex->getMessage();
        }
    }
}

// School paper authorization is recorded by the Supply Officer, NOT inferred from
// a teacher's damage report. Approval does not change any quantity or mean disposal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_condemnation'])) {
    if ($role !== 'supply_officer') {http_response_code(403);exit('Only the Supply Officer may record authorization decisions.');}
    csrf_check();
    $id=(int)($_POST['condemnation_id']??0);
    $decision=(string)($_POST['action_condemnation']??'');
    $note=trim((string)($_POST['decision_note']??''));
    $paperNote=trim((string)($_POST['authorization_note']??''));
    $savedAuthorization=null;
    $authorizationRef=trim((string)($_POST['authorization_reference']??''));
    $authorizationDate=trim((string)($_POST['authorization_date']??''));
    if(!in_array($decision,['approved','rejected'],true)){http_response_code(400);exit('Invalid decision.');}
    $pdo=db();
    try {
        if($id<1)throw new RuntimeException('Choose a valid condemnation record.');
        if($decision==='approved' && (empty($_POST['authorization_on_file']) || mb_strlen($paperNote)<8 || mb_strlen($paperNote)>500))
            throw new RuntimeException('Confirm the signed school authorization is on file and describe the actual document/date.');
        if($decision==='approved' && (mb_strlen($authorizationRef)<3 || mb_strlen($authorizationRef)>120 || !p73_valid_date($authorizationDate)))
            throw new RuntimeException('Enter the signed school authorization reference and valid date (not in the future).');
        if($decision==='rejected' && mb_strlen($note)<3)throw new RuntimeException('Give a reason for rejecting the request.');
        $pdo->beginTransaction();
        // Fetch ID first, then lock the same ICS record used by maintenance and borrowing.
        $lookup=$pdo->prepare('SELECT ics_item_id FROM condemnations WHERE condemnation_id=?');$lookup->execute([$id]);
        $lineId=(int)$lookup->fetchColumn();if(!$lineId)throw new RuntimeException('Condemnation request not found.');
        $pdo->prepare('SELECT ics_item_id FROM ics_items WHERE ics_item_id=? FOR UPDATE')->execute([$lineId]);
        $q=$pdo->prepare('SELECT * FROM condemnations WHERE condemnation_id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || $row['status']!=='requested')throw new RuntimeException('This request has already been reviewed.');
        $state=ics31_state($pdo,$lineId,true);
        $custody=p71_availability($pdo,$lineId,$state);
        if($decision==='approved' && ($custody['custody_inconsistent'] || $custody['reservation_inconsistent']))
            throw new RuntimeException('Borrowed and serviceable balances conflict. Reconcile outstanding loans before authorizing this request.');
        if($decision==='approved' && (!$state['is_equipment'] || $state['needs_review'] || $state['qty_damaged']<(int)$row['quantity']))
            throw new RuntimeException('The damaged balance must be reconciled before recording authorization.');
        if($decision==='approved'){
            p73_validate_pending($pdo,$lineId,$state);
            p73_guard_source_claim($pdo,$lineId,$row['allocation_id']===null?null:(int)$row['allocation_id'],(int)$row['quantity'],(int)$row['condemnation_id']);
            $savedAuthorization=p72_save_evidence($_FILES['authorization_document']??null,true);
        }
        $pdo->prepare("UPDATE condemnations SET status=?,approved_by=?,date_approved=CURRENT_DATE(),decision_note=?,authorization_note=?,authorization_on_file=?,authorization_document=?,authorized_at=?,authorization_reference=?,authorization_date=? WHERE condemnation_id=?")
            ->execute([$decision,$uid,($note?:null),$decision==='approved'?$paperNote:null,$decision==='approved'?1:0,$savedAuthorization,$decision==='approved'?date('Y-m-d H:i:s'):null,$decision==='approved'?$authorizationRef:null,$decision==='approved'?$authorizationDate:null,$id]);
        $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES(?,?,'Condemnation',?,?)")
            ->execute([$uid,$decision==='approved'?'Approved Condemnation':'Rejected Condemnation',$id,
              $decision==='approved'?'Signed school authorization '.$authorizationRef.' dated '.$authorizationDate.' recorded; physical disposal is still pending. '.$paperNote:'Rejected: '.$note]);
        $pdo->commit();header('Location: condemnation.php?updated=1');exit;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();p72_delete_evidence($savedAuthorization);header('Location: condemnation.php?error='.urlencode($e->getMessage()));exit;}
}

// Separate physical event: recorded only after school authorization and an actual disposal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_disposal'])) {
    if($role!=='supply_officer'){http_response_code(403);exit('Only the Supply Officer can record disposal.');}
    csrf_check();
    $id=(int)($_POST['condemnation_id']??0);
    $note=trim((string)($_POST['disposal_note']??''));
    $disposalDate=trim((string)($_POST['disposal_date']??''));
    $disposalMethod=(string)($_POST['disposal_method']??'');
    $disposalReference=trim((string)($_POST['disposal_reference']??''));
    $savedDisposalEvidence=null;
    $pdo=db();
    try {
        if($id<1 || empty($_POST['confirm_disposed']) || mb_strlen($note)<8 || mb_strlen($note)>500 || !p73_valid_date($disposalDate) || !in_array($disposalMethod,['turned_over','recycled','destroyed','other'],true) || mb_strlen($disposalReference)<3 || mb_strlen($disposalReference)>120)
            throw new RuntimeException('Confirm physical disposal and record when/how the item was disposed.');
        $pdo->beginTransaction();
        $lookup=$pdo->prepare('SELECT ics_item_id FROM condemnations WHERE condemnation_id=?');$lookup->execute([$id]);
        $lineId=(int)$lookup->fetchColumn();if(!$lineId)throw new RuntimeException('Record not found.');
        $pdo->prepare('SELECT ics_item_id FROM ics_items WHERE ics_item_id=? FOR UPDATE')->execute([$lineId]);
        $q=$pdo->prepare('SELECT * FROM condemnations WHERE condemnation_id=? FOR UPDATE');$q->execute([$id]);$record=$q->fetch(PDO::FETCH_ASSOC);
        if(!$record || $record['status']!=='approved' || $record['outcome']!==null || !(int)$record['authorization_on_file'])
            throw new RuntimeException('Physical disposal needs an approved request with authorization on file, not already disposed.');
        $state=ics31_state($pdo,$lineId,true);
        $qty=(int)$record['quantity'];
        if(!$state['is_equipment'] || $state['needs_review'] || $qty>$state['qty_damaged'])
            throw new RuntimeException('Damaged units are unavailable for disposal. Reconcile this ICS first.');
        // No borrowed units may be reclassified/disposed. Borrowed physical units
        // are in the serviceable pool, not the separately reserved damaged pool.
        $avail=p71_availability($pdo,$lineId,$state);
        if($avail['custody_inconsistent'] || $avail['reservation_inconsistent'])
            throw new RuntimeException('Outstanding borrowed or reserved units conflict with the serviceable balance. Reconcile custody first.');
        if($record['authorization_date'] && $disposalDate<(string)$record['authorization_date'])
            throw new RuntimeException('Physical disposal cannot be dated before the recorded school authorization.');
        p73_validate_pending($pdo,$lineId,$state);
        p73_guard_source_claim($pdo,$lineId,$record['allocation_id']===null?null:(int)$record['allocation_id'],$qty,$id);
        $savedDisposalEvidence=p72_save_evidence($_FILES['disposal_document']??null,true);
        ics31_save($pdo,$lineId,[
            'serviceable'=>$state['qty_serviceable'], 'repair'=>$state['qty_for_repair'], 'damaged'=>$state['qty_damaged']-$qty
        ]);
        $pdo->prepare("UPDATE condemnations SET outcome='disposed',disposed_at=NOW(),disposal_recorded_by=?,disposal_note=?,disposal_date=?,disposal_method=?,disposal_reference=?,disposal_document=? WHERE condemnation_id=? AND status='approved' AND outcome IS NULL")
            ->execute([$uid,$note,$disposalDate,$disposalMethod,$disposalReference,$savedDisposalEvidence,$id]);
        // Validate the final live condition balance against the newly recorded disposal.
        ics31_state($pdo,$lineId,true);
        $pdo->prepare("INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES(?,'Disposed Condemnation','Condemnation',?,?)")
            ->execute([$uid,$id,"$qty damaged unit(s) physically disposed on $disposalDate by $disposalMethod; reference $disposalReference. $note"]);
        $pdo->commit();header('Location: condemnation.php?updated=1');exit;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();p72_delete_evidence($savedDisposalEvidence);header('Location: condemnation.php?error='.urlencode($e->getMessage()));exit;}
}

// Fetch condemnations based on role
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT co.*, i.item_name, i.unit, c.ics_number, u.full_name as approver_name,rb.batch_number,rb.batch_id AS source_batch_id,d.receiving_number
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        LEFT JOIN ics_batch_allocations al ON al.allocation_id=co.allocation_id
        LEFT JOIN inventory_receipt_batches rb ON rb.batch_id=al.batch_id
        LEFT JOIN delivery_items di ON di.delivery_item_id=rb.delivery_item_id
        LEFT JOIN deliveries d ON d.delivery_id=di.delivery_id
        LEFT JOIN users u ON co.approved_by = u.user_id
        WHERE co.requested_by = ?
        ORDER BY co.date_approved DESC, co.condemnation_id DESC
    ");
    $stmt->execute([$uid]);
    $condemnations = $stmt->fetchAll();

    // Fetch items eligible for condemnation request
    $stmt_items = db()->prepare("
        SELECT ii.ics_item_id,i.item_name,i.unit,ii.qty_issued,ii.item_condition,c.ics_number,
          (cb.qty_serviceable+cb.qty_for_repair+cb.qty_damaged) AS accountable_qty, cb.qty_serviceable,cb.qty_damaged,cb.needs_review,
          COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id=ii.ics_item_id AND (status='requested' OR (status='approved' AND outcome IS NULL))),0) AS pending_qty
        FROM ics_items ii JOIN ics c ON ii.ics_id=c.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
        JOIN ics_condition_balances cb ON cb.ics_item_id=ii.ics_item_id
        WHERE c.teacher_id=? AND c.status='active' AND i.item_category IN ('equipment','non_consumable')
    ");
    $stmt_items->execute([$uid]);
    $my_items = $stmt_items->fetchAll();
    $condemnBatches=p72_batch_map(db(),array_column($my_items,'ics_item_id'));
    $condemnBatchClaims=p73_batch_claims(db(),array_column($my_items,'ics_item_id'));
    foreach($condemnBatches as &$batchRows){foreach($batchRows as &$br){
        $br['remaining_recorded']=max(0,(int)$br['remaining_recorded']-(int)($condemnBatchClaims[(int)$br['allocation_id']]??0));
        $br['remaining_label']='available for new CF declarations';
    }unset($br);}unset($batchRows);
} else {
    $stmt = db()->query("
        SELECT co.*, i.item_name, i.unit, c.ics_number, req_user.full_name as requester_name, app_user.full_name as approver_name,rb.batch_number,rb.batch_id AS source_batch_id,d.receiving_number
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        LEFT JOIN ics_batch_allocations al ON al.allocation_id=co.allocation_id
        LEFT JOIN inventory_receipt_batches rb ON rb.batch_id=al.batch_id
        LEFT JOIN delivery_items di ON di.delivery_item_id=rb.delivery_item_id
        LEFT JOIN deliveries d ON d.delivery_id=di.delivery_id
        JOIN users req_user ON co.requested_by = req_user.user_id
        LEFT JOIN users app_user ON co.approved_by = app_user.user_id
        ORDER BY co.status = 'requested' DESC, co.date_approved DESC, co.condemnation_id DESC
    ");
    $condemnations = $stmt->fetchAll();
}

$condemnEvents=[];
$condemnIds=array_values(array_unique(array_map('intval',array_column($condemnations,'condemnation_id'))));
if($condemnIds){
 $args=implode(',',array_fill(0,count($condemnIds),'?'));
 $eventSql="SELECT h.log_id,h.record_id,h.action,h.details,h.created_at,u.full_name AS actor_name FROM history_logs h LEFT JOIN users u ON u.user_id=h.user_id WHERE h.module='Condemnation' AND h.record_id IN ($args) ORDER BY h.created_at,h.log_id";
 $h=db()->prepare($eventSql);$h->execute($condemnIds);
 while($event=$h->fetch(PDO::FETCH_ASSOC))$condemnEvents[(int)$event['record_id']][]=$event;
}
$pageTitle = 'Condemnation Requests';
$active = 'condemnation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Condemnation & Disposal Management</h2>
        <p class="legacy-027">Damage reporting, school authorization, and physical disposal are three separate steps.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <a class="btn btn-outline" href="#p73-eligible">Choose damaged equipment ↓</a>
    <?php else: ?>
        <a class="btn btn-outline" href="<?= e(url('phase7_reconciliation.php')) ?>">Phase 7 reconciliation</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['requested'])): ?>
    <div class="legacy-029">
        Condemnation request submitted. Awaiting school paper authorization review; no item has been disposed.
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="legacy-029">
        Condemnation request status updated successfully.
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?><div class="alert alert-error" role="alert"><?= e((string)$_GET['error']) ?></div><?php endif; ?>
<?php if ($request_error): ?><div class="legacy-181" role="alert" style="padding:12px;margin-bottom:12px;background:#ffe3e3;color:#900;border-radius:6px;"><?= e($request_error) ?></div><?php endif; ?>
<?php if($role === 'teacher'): ?>
<section class="card p73-eligible-browser" id="p73-eligible" aria-label="Select damaged equipment for condemnation">
 <div class="record-section-header"><div><h3>Damaged Equipment</h3><p>Find the exact ICS item, then request condemnation. Only damaged units not already reserved by a CF can be requested.</p></div><span class="p73-count" id="p73-eligible-count" aria-live="polite"></span></div>
 <div class="p73-eligible-filters"><label>Search equipment, ICS or batch<input type="search" class="input" id="p73-eligible-search" autocomplete="off" placeholder="Item name, ICS or receiving batch..."></label><label>Sort<select id="p73-eligible-sort" class="input"><option value="default">Default order</option><option value="az">Item A–Z</option><option value="qty">Most damaged available</option></select></label></div>
 <div class="table-wrap p73-eligible-table-wrap"><table class="table p73-eligible-table" id="p73-eligible-table"><thead><tr><th>Item</th><th>ICS / Course</th><th>Condition</th><th>Source</th><th>Action</th></tr></thead><tbody id="p73-eligible-list">
 <?php foreach($my_items as $mi):
  $freeDamaged= !empty($mi['needs_review'])?0:max(0,(int)$mi['qty_damaged']-(int)$mi['pending_qty']);
  if($freeDamaged<=0)continue;
  $itemId=(int)$mi['ics_item_id'];$sourceLabels=implode(' ',array_column($condemnBatches[$itemId]??[],'label'));
  $searchText=function_exists('mb_strtolower')?mb_strtolower($mi['item_name'].' '.$mi['ics_number'].' '.$sourceLabels):strtolower($mi['item_name'].' '.$mi['ics_number'].' '.$sourceLabels);
 ?>
 <tr class="p73-eligible-record" data-name="<?= e($mi['item_name']) ?>" data-free="<?= $freeDamaged ?>" data-search="<?= e($searchText) ?>">
  <td class="p73-eligible-name" data-label="Item"><strong><?= e($mi['item_name']) ?></strong><small><?= e($mi['unit']??'Unit') ?></small></td>
  <td data-label="ICS / Course"><strong><?= e($mi['ics_number']) ?></strong><small>Course / unclassified</small></td>
  <td data-label="Quantity / Condition"><span class="p73-damaged-chip">Damaged <?= (int)$mi['qty_damaged'] ?></span><small>Eligible <?= $freeDamaged ?> · Pending CF <?= (int)$mi['pending_qty'] ?></small></td>
  <td data-label="Batch / Source"><small><?= count($condemnBatches[$itemId]??[]) ?> recorded source batch(es)</small><small>Source optional</small></td>
  <td class="p73-eligible-action" data-label="Action"><button type="button" class="btn btn-primary p73-request-action" data-request-ics="<?= $itemId ?>">Request condemnation</button></td>
 </tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <p class="record-empty" id="p73-eligible-empty" hidden>No eligible damaged items match the search. Report damage in Maintenance first, or check pending requests.</p>
 <nav class="record-pagination"><button type="button" class="btn btn-outline" id="p73-eligible-prev">Previous</button><span id="p73-eligible-page">Page 1</span><button type="button" class="btn btn-outline" id="p73-eligible-next">Next</button></nav>
</section>
<?php endif; ?>

<!-- Record-table styling is included in assets/css/style.css. -->
<section class="card record-card" aria-label="Condemnation requests">
    <div class="record-section-header"><div><h3>Condemnation records</h3><p>Choose a record to review its full reason and decisions.</p></div><label class="record-pagesize">Rows per page <select id="cond-page-size" class="input"><option value="10">10 rows</option><option value="20">20 rows</option><option value="50">50 rows</option></select></label></div>
    <div class="record-filters">
      <div class="field record-search"><label for="cond-search">Search CF number, item, ICS or teacher</label><input id="cond-search" class="input" type="search" autocomplete="off" placeholder="Search condemnation records"></div>
      <div class="field"><label for="cond-status">Status</label><select id="cond-status" class="input"><option value="">All statuses</option><option value="requested">Requested</option><option value="authorized">Authorized — awaiting disposal</option><option value="disposed">Disposed</option><option value="rejected">Rejected</option></select></div>
      <div class="field"><label for="cond-order">Sort records</label><select id="cond-order" class="input"><option value="default">Default (pending first)</option><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="item-asc">Item A–Z</option><option value="item-desc">Item Z–A</option><option value="qty-desc">Highest quantity</option></select></div>
      <div class="record-filter-reset"><button type="button" class="btn btn-outline" id="cond-reset">Reset</button></div>
    </div>
    <p class="record-results" id="cond-count" aria-live="polite"></p>
    <div class="table-wrap record-table-wrap">
      <table class="table record-table condemnation-table" id="cond-table">
        <thead><tr><th>CF / Item</th><th>Teacher / ICS</th><th>Quantity</th><th>Status</th><th>Details</th></tr></thead>
        <tbody>
          <?php if (!$condemnations): ?><tr class="record-empty-database"><td colspan="5">No condemnation records found.</td></tr><?php endif; ?>
          <?php foreach ($condemnations as $con):
             $rid=(int)$con['condemnation_id'];
             $teacherName=$role === 'teacher' ? (string)($user['name'] ?? '') : (string)($con['requester_name'] ?? '');
             $formLabel=trim((string)($con['form_number']??'')) ?: 'Not assigned';
             $status=(string)$con['status'];
             $displayStatus=$status==='approved'?(($con['outcome']??'')==='disposed'?'disposed':'authorized'):$status;
             $requestDate=(string)($con['date_requested']??'');
             $searchText=implode(' ',[$formLabel,$con['item_name']??'',$con['ics_number']??'',$teacherName,$con['reason']??'']);
          ?>
          <tr class="record-main" data-record-id="<?= $rid ?>" data-status="<?= e($displayStatus) ?>" data-search="<?= e(function_exists('mb_strtolower') ? mb_strtolower($searchText) : strtolower($searchText)) ?>" data-date="<?= e($requestDate) ?>" data-item="<?= e(function_exists('mb_strtolower') ? mb_strtolower((string)$con['item_name']) : strtolower((string)$con['item_name'])) ?>" data-qty="<?= (int)$con['quantity'] ?>">
            <td data-label="CF / Item"><strong><?= e($con['item_name']) ?></strong><small>CF: <?= e($formLabel) ?></small></td>
            <td data-label="Teacher / ICS"><strong><?= e($teacherName) ?></strong><small><?= e($con['ics_number']) ?></small></td>
            <td data-label="Quantity"><?= (int)$con['quantity'] ?> <?= e($con['unit']??'') ?></td>
            <td data-label="Status"><span class="status-badge <?= $displayStatus === 'disposed' ? 'status-success' : ($displayStatus === 'rejected' ? 'status-danger' : 'status-warning') ?>"><?= $displayStatus==='authorized'?'Authorized — Awaiting Disposal':e(ucfirst($displayStatus)) ?></span></td>
            <td data-label="Details"><button type="button" class="btn btn-outline btn-sm record-expand" data-target="cond-detail-<?= $rid ?>" aria-expanded="false" aria-controls="cond-detail-<?= $rid ?>">View Details <span aria-hidden="true">⌄</span></button></td>
          </tr>
          <tr class="record-detail" id="cond-detail-<?= $rid ?>" data-detail-for="<?= $rid ?>" hidden><td colspan="5"><div class="record-detail-panel">
            <div class="record-detail-head"><h4><?= e($con['item_name']) ?> · <?= e($formLabel) ?></h4><button type="button" class="btn btn-outline btn-sm record-close" data-target="cond-detail-<?= $rid ?>">Close details ↑</button></div>
            <div class="record-facts">
              <div><span>ICS / Holder</span><strong><?= e($con['ics_number']) ?> · <?= e($teacherName) ?></strong></div>
              <div><span>Requested quantity</span><strong><?= (int)$con['quantity'] ?> <?= e($con['unit']??'') ?></strong></div>
              <div><span>Date requested</span><strong><?= $requestDate ? e(date('M j, Y · g:i A',strtotime($requestDate))) : '–' ?></strong></div>
              <div><span>Authorization / Disposal</span><strong><?= $status==='approved' ? (($con['outcome']??'')==='disposed'?'Authorized · Disposed':'Authorized · Awaiting physical disposal') : e(ucfirst($status)) ?></strong></div>
            </div>
            <div class="p73-stage-bar" aria-label="Condemnation stages"><span class="<?= $status==='requested'?'p73-stage-current':'p73-stage-done' ?>">1. Requested</span><span class="<?= $status==='approved'?'p73-stage-done':'' ?>">2. School authorization</span><span class="<?= ($con['outcome']??'')==='disposed'?'p73-stage-done':'' ?>">3. Physical disposal</span></div>
            <div class="record-note"><strong>Source batch:</strong> <?= $con['allocation_id'] ? e(($con['receiving_number']?:'Receiving unknown').' · '.($con['batch_number']?:'Batch #'.$con['source_batch_id'])) : 'Unknown / not sure (not attributed)' ?></div>
            <?php if($con['request_photo']): ?><p><a class="p72-evidence-link" href="<?= e(url('phase7_evidence.php?kind=request_photo&id='.$rid)) ?>" target="_blank" rel="noopener">View teacher supporting photo ↗</a></p><?php endif; ?>
            <?php if($con['authorization_document']): ?><p><a class="p72-evidence-link" href="<?= e(url('phase7_evidence.php?kind=authorization&id='.$rid)) ?>" target="_blank" rel="noopener">View school authorization attachment ↗</a></p><?php endif; ?>
            <div class="record-note"><strong>Reason for condemnation</strong><p><em><?= nl2br(e($con['reason'])) ?></em></p></div>
            <section class="p72-cond-history" aria-label="Condemnation record timeline"><h5>Chronological CF history</h5>
             <?php foreach($condemnEvents[$rid]??[] as $entry): ?>
              <div class="p72-cond-event"><strong><?= e($entry['action']) ?></strong><small><?= e($entry['created_at']) ?> · <?= e($entry['actor_name']?:'Recorded by system') ?></small><p><?= e($entry['details']?:'No additional notes.') ?></p></div>
             <?php endforeach; ?>
             <?php if(empty($condemnEvents[$rid])): ?><p class="field-help">No earlier audit entries for this request.</p><?php endif; ?>
            </section>
            <?php if (!empty($con['approver_name'])): ?><p class="record-audit"><strong>Reviewed by <?= e($con['approver_name']) ?></strong><?= !empty($con['date_approved'])?' · '.e(date('M j, Y',strtotime((string)$con['date_approved']))):'' ?></p><?php endif; ?>
            <?php if (!empty($con['authorization_reference'])): ?><div class="record-note"><strong>Signed school authorization</strong><p>Reference: <?= e($con['authorization_reference']) ?> · Date: <?= e($con['authorization_date']??'') ?></p></div><?php endif; ?>
            <?php if (!empty($con['authorization_on_file'])): ?><div class="record-note"><strong>School authorization on file</strong><p><?= e($con['authorization_note']??'') ?></p></div><?php endif; ?>
            <?php if (!empty($con['decision_note'])): ?><div class="record-note"><strong>Decision remarks</strong><p><?= e($con['decision_note']) ?></p></div><?php endif; ?>
            <?php if (!empty($con['disposal_document'])): ?><p><a class="p72-evidence-link" href="<?= e(url('phase7_evidence.php?kind=disposal&id='.$rid)) ?>" target="_blank" rel="noopener">View physical disposal evidence ↗</a></p><?php endif; ?>
            <?php if (!empty($con['disposal_reference'])): ?><div class="record-note"><strong>Physical disposal record</strong><p>Date: <?= e($con['disposal_date']??'') ?> · Method: <?= e(str_replace('_',' ',ucfirst((string)($con['disposal_method']??'')))) ?> · Reference: <?= e($con['disposal_reference']) ?></p></div><?php endif; ?>
            <?php if (!empty($con['disposal_note'])): ?><div class="record-note"><strong>Physical disposal recorded</strong><p><?= e($con['disposal_note']) ?><?= !empty($con['disposed_at'])?' · '.e($con['disposed_at']):'' ?></p></div><?php endif; ?>
            <?php if ($role === 'supply_officer' && $status === 'requested'): ?>
              <form method="post" class="record-decision" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="condemnation_id" value="<?= $rid ?>">
                <p><strong>Authorization is NOT disposal.</strong> Record the signed school paperwork first. Actual disposal will be a separate step.</p>
                <div class="p73-form-grid"><label class="field"><span>Signed school authorization reference *</span><input class="input" name="authorization_reference" maxlength="120" placeholder="e.g. School approval / document number"></label><label class="field"><span>Authorization date *</span><input type="date" class="input" name="authorization_date" max="<?= date('Y-m-d') ?>"></label></div>
                <label class="field"><span>Authorization document description (required to approve)</span><textarea name="authorization_note" rows="2" maxlength="500" class="input" placeholder="Example: Signed school disposal approval dated 24 October 2026"></textarea></label>
                <label class="field"><span>Attach signed school authorization (optional copy)</span><input class="input" type="file" name="authorization_document" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="field-help">JPG, PNG, WebP or PDF up to 5 MB; securely stored. A file does not replace verifying signed paperwork.</small></label>
                <label class="field"><input type="checkbox" name="authorization_on_file" value="1"> I verified the actual signed school authorization is on file.</label>
                <label class="field"><span>Decision remarks / rejection reason</span><textarea name="decision_note" rows="2" maxlength="500" class="input" placeholder="Required for rejection"></textarea></label>
                <div class="record-decision-actions"><button type="submit" name="action_condemnation" value="approved" class="btn btn-primary">Record Authorization</button><button type="submit" name="action_condemnation" value="rejected" class="btn btn-outline">Reject Request</button></div>
              </form>
            <?php elseif ($role === 'supply_officer' && $status === 'approved' && empty($con['outcome'])): ?>
              <form method="post" class="record-decision" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="condemnation_id" value="<?= $rid ?>">
                <p>Authorization is recorded. <strong>Only proceed after the units have actually been disposed.</strong></p>
                <div class="p73-form-grid"><label class="field"><span>Actual physical disposal date *</span><input class="input" type="date" name="disposal_date" required max="<?= date('Y-m-d') ?>"></label><label class="field"><span>Disposal method *</span><select class="input" name="disposal_method" required><option value="">Choose method</option><option value="turned_over">Turned over to authorized office</option><option value="recycled">Recycled</option><option value="destroyed">Destroyed</option><option value="other">Other documented method</option></select></label></div>
                <label class="field"><span>Physical disposal / turnover reference *</span><input type="text" class="input" name="disposal_reference" maxlength="120" required placeholder="Paper record, turnover reference or receipt"></label>
                <label class="field"><span>Optional disposal evidence (photo or PDF)</span><input class="input" type="file" name="disposal_document" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="field-help">Up to 5 MB. Restricted to authorized TNTS accounts.</small></label>
                <label class="field"><span>Physical disposal notes</span><textarea name="disposal_note" class="input" maxlength="500" rows="3" required placeholder="When, where and how the authorized units were physically disposed"></textarea></label>
                <label><input type="checkbox" name="confirm_disposed" value="1" required> I confirm the approved units were physically disposed and the paper record is on file.</label>
                <div class="record-decision-actions"><button type="submit" name="record_disposal" value="1" class="btn btn-primary">Record Physical Disposal</button></div>
              </form>
            <?php endif; ?>
          </div></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p id="cond-empty" class="record-empty" hidden>No records match your filters. Try clearing the search or changing the status.</p>
    <nav class="record-pagination" aria-label="Condemnation pages"><button type="button" class="btn btn-outline btn-sm" id="cond-prev">Previous</button><span id="cond-page-label"></span><button type="button" class="btn btn-outline btn-sm" id="cond-next">Next</button></nav>
</section>
<script>
(function(){
  const table=document.getElementById('cond-table'); if(!table)return;
  const bodies=[...table.querySelectorAll('tr.record-main')];
  const state={page:1};
  const input=document.getElementById('cond-search'),status=document.getElementById('cond-status'),order=document.getElementById('cond-order'),size=document.getElementById('cond-page-size');
  const label=document.getElementById('cond-page-label'),count=document.getElementById('cond-count'),empty=document.getElementById('cond-empty');
  function render(){
    const query=input.value.trim().toLocaleLowerCase(),type=status.value;
    const visible=bodies.filter(r=>(!query||r.dataset.search.includes(query))&&(!type||r.dataset.status===type));
    const mode=order.value;
    if(mode!=='default')visible.sort((a,b)=>{
      let cmp=0;
      if(mode==='newest'||mode==='oldest')cmp=a.dataset.date.localeCompare(b.dataset.date);
      else if(mode.startsWith('item'))cmp=a.dataset.item.localeCompare(b.dataset.item);
      else if(mode==='qty-desc')cmp=Number(a.dataset.qty)-Number(b.dataset.qty);
      if(mode==='newest'||mode==='item-desc'||mode==='qty-desc')cmp=-cmp;
      return cmp;
    });
    const per=Number(size.value)||10,pages=Math.max(1,Math.ceil(visible.length/per));state.page=Math.min(state.page,pages);
    const displayed=visible.slice((state.page-1)*per,state.page*per);let target=table.querySelector('tbody');
    const all = new Set(displayed);
    for(const row of bodies){
      const detail=document.getElementById('cond-detail-'+row.dataset.recordId);
      row.hidden=!all.has(row);if(!all.has(row))detail.hidden=true;
    }
    // Move parent/detail together so expanded content always belongs to its record.
    for(const row of visible){target.appendChild(row);target.appendChild(document.getElementById('cond-detail-'+row.dataset.recordId));}
    count.textContent=visible.length+' matching record(s)';empty.hidden=visible.length!==0;
    label.textContent='Page '+state.page+' of '+pages;
    document.getElementById('cond-prev').disabled=state.page<=1;
    document.getElementById('cond-next').disabled=state.page>=pages;
    document.querySelectorAll('.record-expand').forEach(b=>b.setAttribute('aria-expanded',String(!document.getElementById(b.dataset.target).hidden)));
  }
  document.querySelectorAll('.record-expand').forEach(button=>button.addEventListener('click',()=>{
    const target=document.getElementById(button.dataset.target),show=target.hidden;
    document.querySelectorAll('tr.record-detail').forEach(r=>r.hidden=true);
    document.querySelectorAll('.record-expand').forEach(b=>b.setAttribute('aria-expanded','false'));
    target.hidden=!show;button.setAttribute('aria-expanded',String(show));
  }));
  document.querySelectorAll('.record-close').forEach(b=>b.addEventListener('click',()=>{document.getElementById(b.dataset.target).hidden=true;document.querySelector('[data-target="'+b.dataset.target+'"].record-expand')?.setAttribute('aria-expanded','false');}));
  [input,status,order,size].forEach(el=>el.addEventListener(el===input?'input':'change',()=>{state.page=1;render();}));
  document.getElementById('cond-reset').addEventListener('click',()=>{input.value='';status.value='';order.value='default';size.value='10';state.page=1;render();});
  document.getElementById('cond-prev').addEventListener('click',()=>{state.page--;render();});
  document.getElementById('cond-next').addEventListener('click',()=>{state.page++;render();});
  render();
  const requestedId=new URLSearchParams(window.location.search).get('request_id');
  if(requestedId && /^\d+$/.test(requestedId)){
    const hit=bodies.find(r=>r.dataset.recordId===requestedId);
    if(hit){
      input.value='';status.value='';order.value='default';
      const index=bodies.indexOf(hit);state.page=Math.floor(index/(Number(size.value)||10))+1;
      render();
      const detail=document.getElementById('cond-detail-'+requestedId);
      if(detail){detail.hidden=false;hit.querySelector('.record-expand')?.setAttribute('aria-expanded','true');}
      hit.classList.add('tn-focus-record');
      requestAnimationFrame(()=>hit.scrollIntoView({block:'center',behavior:'auto'}));
    }
  }
})();
</script>

<!-- Request Condemnation Modal (Teacher) -->
<?php if ($role === 'teacher'): ?>
<div id="condemnModal" class="legacy-042 modal-overlay" hidden>
    <div class="legacy-043">
        <h3 class="legacy-044">Request Item Condemnation</h3>
        <form method="POST" id="condemn_request_form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="legacy-045">
                <label class="legacy-046">Selected damaged equipment *</label>
                <div class="p73-selected-item" id="p73-selected-item" aria-live="polite">Choose an item from Damaged Equipment first.</div>
                <button type="button" class="btn btn-outline p73-change-item" id="p73-change-item">Choose another item</button>
                <select name="ics_item_id" id="condemn_item" class="input p73-native-select" aria-label="Selected ICS equipment" required>
                    <option value="">-- Choose an already-damaged item --</option>
                    <?php foreach ($my_items as $mi): 
                        $avail_condemn = $mi['needs_review'] ? 0 : max(0,(int)$mi['qty_damaged']-(int)$mi['pending_qty']);
                        if ($avail_condemn > 0):
                    ?>
                        <option value="<?= (int)$mi['ics_item_id'] ?>" data-name="<?= e($mi['item_name']) ?>" data-ics="<?= e($mi['ics_number']) ?>" data-course="" data-batches="<?= e(implode(' · ',array_column($condemnBatches[(int)$mi['ics_item_id']]??[],'label'))) ?>" data-serviceable="<?= (int)$mi['qty_serviceable'] ?>" data-repair="0" data-damaged="<?= (int)$avail_condemn ?>" data-max="<?= (int)$avail_condemn ?>" <?= (int)($_POST['ics_item_id'] ?? 0) === (int)$mi['ics_item_id'] ? 'selected' : '' ?>>
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Damaged available: <?= $avail_condemn ?> <?= e($mi['unit']) ?>
                        </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div class="field p72-source-field"><label for="condemnation_batch">Receiving batch (optional)</label><select id="condemnation_batch" name="allocation_id" class="input" data-p72-source-for="condemn_item"><option value="0">Unknown / not sure</option></select><small class="field-help">Only select a known physical source; unknown is valid. This will not change batch quantities.</small></div>
            <div class="legacy-048">
                <div class="legacy-049">
                    <label class="legacy-046">Quantity *</label>
                    <input type="number" name="quantity" id="condemn_qty" min="1" step="1" value="<?= e($_POST['quantity'] ?? '1') ?>" required class="legacy-047">
                    <small id="condemn_qty_hint" style="display:block;margin-top:4px;">Select an eligible item to see the maximum quantity.</small>
                </div>
                <div class="legacy-049"><label class="legacy-046">CF number</label><p class="muted">Generated automatically after submission.</p></div>
            </div>

            <div class="field"><label for="p72-request-photo">Damage photo (optional)</label><input type="file" id="p72-request-photo" name="request_photo" class="input" accept="image/jpeg,image/png,image/webp"><small class="field-help">JPG, PNG or WebP up to 5 MB. Visible only to authorized users.</small></div>
            <div class="legacy-050">
                <label class="legacy-046">Why is this damaged item beyond repair? *</label>
                <textarea name="reason" rows="3" maxlength="2000" required placeholder="Describe why this equipment is beyond repair..." class="legacy-047"><?= e($_POST['reason'] ?? '') ?></textarea>
            </div>

            <div class="legacy-051">
                <button type="button" onclick="closeCondemnModal()" class="legacy-052">Cancel</button>
                <button type="submit" name="request_condemnation" class="legacy-028">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
const condemItem = document.getElementById('condemn_item');
const condemQty = document.getElementById('condemn_qty');
const condemHint = document.getElementById('condemn_qty_hint');
function updateCondemnLimit() {
 const conditionMax = Number(condemItem.selectedOptions[0]?.dataset.max || 0);
 const option=document.getElementById('condemnation_batch').selectedOptions[0];
 const batchMax=option&&option.value!=='0'?Number(option.dataset.remaining||0):Infinity;
 const max=Math.min(conditionMax,batchMax);
 condemQty.max = max ? String(max) : '';
 condemHint.textContent = max ? `Maximum available for this request: ${max}` : 'Select an eligible item first.';
 if (max && Number(condemQty.value) > max) condemQty.value = String(max);
}
condemItem.addEventListener('change', updateCondemnLimit);
document.getElementById('condemnation_batch').addEventListener('change', updateCondemnLimit);
document.getElementById('condemnation_batch').addEventListener('p72-sources-updated',updateCondemnLimit);
document.getElementById('condemn_request_form').addEventListener('submit', event => {
 const max = Number(condemQty.max || 0);
 const qty = Number(condemQty.value);
 if (!max || !Number.isInteger(qty) || qty < 1 || qty > max) {
  event.preventDefault();
  condemHint.style.color = '#b91c1c';
  condemHint.textContent = `Enter a whole number between 1 and ${max}.`;
  condemQty.focus();
 }
});
updateCondemnLimit();
<?php if ($request_error): ?>
document.addEventListener('DOMContentLoaded', () => openCondemnModal());
<?php endif; ?>
function openCondemnModal() {
    TNTSUI.open('condemnModal');
}
function closeCondemnModal() {
    TNTSUI.close('condemnModal');
}
</script>
<?php endif; ?>

<?php if($role==='teacher'): ?><script src="<?= e(url('assets/js/phase7_condemnation_list.js')) ?>" defer></script><script>window.TNTSPhase7Batches = <?= json_encode($condemnBatches,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;</script><script src="<?= e(url('assets/js/phase7_picker.js')) ?>"></script><?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>