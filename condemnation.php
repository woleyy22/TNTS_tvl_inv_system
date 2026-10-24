<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ics_accountability.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$role = $user['role'];
$uid = (int)($user['user_id'] ?? $user['id']);

$request_error = '';
// 1. Handle Teacher Requesting Condemnation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_condemnation'])) {
    if ($role !== 'teacher') {
        die("Only teachers can request condemnation for their items.");
    }

    csrf_check();
    $ics_item_id = (int)($_POST['ics_item_id'] ?? 0);
    $raw_quantity = trim((string)($_POST['quantity'] ?? ''));
    $quantity = filter_var($raw_quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (!$ics_item_id || $quantity === false || $reason === '') {
        $request_error = 'Select an accountable item, enter a positive whole-number quantity, and provide a reason.';
    } else {
        try {
            db()->beginTransaction();
            // Serialize requests for the same ICS item to prevent two simultaneous over-requests.
            $check=db()->prepare("SELECT ii.ics_item_id FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.ics_item_id=? AND c.teacher_id=? AND c.status='active' FOR UPDATE");
            $check->execute([$ics_item_id,$uid]);
            if(!$check->fetchColumn())throw new RuntimeException('Item is no longer under your active accountability.');
            $balance=ics31_state(db(),$ics_item_id,true);
            if(!$balance['is_equipment'] || $balance['needs_review'])throw new RuntimeException('This equipment requires Supply Officer verification first.');
            $sum=db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations WHERE ics_item_id=? AND status='requested'");
            $sum->execute([$ics_item_id]);$pending=(int)$sum->fetchColumn();
            $remaining=max(0,$balance['remaining_qty']-$pending);
            if($quantity>$remaining)throw new RuntimeException("Quantity exceeds current available accountability ($remaining remaining).");
            $form_number=generate_document_number('condemnation');
            db()->prepare("INSERT INTO condemnations (form_number,ics_item_id,quantity,reason,requested_by,status,outcome) VALUES (?,?,?,?,?,'requested',NULL)")
              ->execute([$form_number,$ics_item_id,$quantity,$reason,$uid]);
            $new_id=(int)db()->lastInsertId();
            // Keep existing damaged equipment damaged; only move working units when newly reported as damaged.
            $unclaimedDamaged=max(0,$balance['qty_damaged']-$pending);
            $newDamage=max(0,$quantity-$unclaimedDamaged);
            if($newDamage>$balance['qty_serviceable'])throw new RuntimeException('Insufficient serviceable/damaged equipment for this request.');
            if($newDamage>0){
                ics31_save(db(),$ics_item_id,[
                  'serviceable'=>$balance['qty_serviceable']-$newDamage,
                  'repair'=>$balance['qty_for_repair'],
                  'damaged'=>$balance['qty_damaged']+$newDamage,
                ]);
                db()->prepare("INSERT INTO repair_logs (ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES (?, ?, ?, 'Pending condemnation review', CURRENT_DATE(), ?, 'damaged')")
                  ->execute([$ics_item_id,$uid,'Condemnation request #'.$new_id.': '.$reason,$newDamage]);
            }
            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Requested Condemnation', 'Condemnation', ?, ?)")
                ->execute([$uid, $new_id, "Requested condemnation for qty $quantity. Reason: $reason"]);
            db()->commit();
            header('Location: condemnation.php?requested=1'); exit;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $request_error = $e->getMessage();
        }
    }
}

// 2. Handle Admin (Supply Officer / Property Custodian) Approving/Rejecting Condemnation
// Inside condemnation.php POST handler for Supply Officer approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_condemnation'])) {
    if ($role !== 'supply_officer') {
        die("Unauthorized access. Only Property Custodians can approve condemnations.");
    }

    csrf_check();
    $condemnation_id = (int)$_POST['condemnation_id'];
    $decision = $_POST['decision'] ?? $_POST['action_condemnation'];

    if (!in_array($decision, ['approved', 'rejected'])) {
        die("Invalid decision action.");
    }

    db()->beginTransaction();
    try {
        // Fetch existing condemnation record and verify status is still 'requested'
        $stmt_check = db()->prepare("SELECT status,ics_item_id,quantity FROM condemnations WHERE condemnation_id = ? FOR UPDATE");
        $stmt_check->execute([$condemnation_id]);
        $existing = $stmt_check->fetch();

        if (!$existing) {
            throw new Exception("Condemnation record not found.");
        }

        if ($existing['status'] !== 'requested') {
            throw new Exception("This condemnation request has already been finalized ('{$existing['status']}') and cannot be modified.");
        }

        // Approval confirms physical disposal only after the custodian's explicit confirmation.
        if ($decision === 'approved' && !isset($_POST['confirm_disposed'])) {
            throw new RuntimeException('Confirm that these units were physically disposed before recording disposal.');
        }
        $outcome = $decision === 'approved' ? 'disposed' : null;
        if($decision==='approved') {
            $state=ics31_state(db(),(int)$existing['ics_item_id'],true);
            $disposeQty=(int)$existing['quantity'];
            if($disposeQty>$state['remaining_qty'])throw new RuntimeException('Cannot dispose of units already returned or previously disposed.');
            $take=['damaged'=>$state['qty_damaged'],'serviceable'=>$state['qty_serviceable'],'repair'=>$state['qty_for_repair']];
            $remainingDispose=$disposeQty;
            foreach(['damaged','serviceable','repair'] as $key){$deduct=min($take[$key],$remainingDispose);$take[$key]-=$deduct;$remainingDispose-=$deduct;}
            if($remainingDispose)throw new RuntimeException('Not enough equipment remains accountable for this disposition.');
            ics31_save(db(),(int)$existing['ics_item_id'],[
                'serviceable'=>$take['serviceable'], 'repair'=>$take['repair'], 'damaged'=>$take['damaged']
            ]);
        }

        $stmt = db()->prepare("
            UPDATE condemnations 
            SET status = ?, approved_by = ?, date_approved = NOW(), outcome = ? 
            WHERE condemnation_id = ?
        ");
        $stmt->execute([$decision, $uid, $outcome, $condemnation_id]);

        // Audit Trail
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, ?, 'Condemnation', ?, ?)")
            ->execute([$uid, ucfirst($decision) . ' Condemnation', $condemnation_id, "Condemnation record #{$condemnation_id} marked as {$decision}."]);

        db()->commit();
        header("Location: condemnation.php?updated=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        header("Location: condemnation.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// Fetch condemnations based on role
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT co.*, i.item_name, i.unit, c.ics_number, u.full_name as approver_name
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        LEFT JOIN users u ON co.approved_by = u.user_id
        WHERE co.requested_by = ?
        ORDER BY co.date_approved DESC
    ");
    $stmt->execute([$uid]);
    $condemnations = $stmt->fetchAll();

    // Fetch items eligible for condemnation request
    $stmt_items = db()->prepare("
        SELECT ii.ics_item_id,i.item_name,i.unit,ii.qty_issued,ii.item_condition,c.ics_number,
          (cb.qty_serviceable+cb.qty_for_repair+cb.qty_damaged) AS accountable_qty, cb.qty_serviceable,cb.qty_damaged,cb.needs_review,
          COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id=ii.ics_item_id AND status='requested'),0) AS pending_qty
        FROM ics_items ii JOIN ics c ON ii.ics_id=c.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
        JOIN ics_condition_balances cb ON cb.ics_item_id=ii.ics_item_id
        WHERE c.teacher_id=? AND c.status='active' AND i.item_category IN ('equipment','non_consumable')
    ");
    $stmt_items->execute([$uid]);
    $my_items = $stmt_items->fetchAll();
} else {
    $stmt = db()->query("
        SELECT co.*, i.item_name, i.unit, c.ics_number, req_user.full_name as requester_name, app_user.full_name as approver_name
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users req_user ON co.requested_by = req_user.user_id
        LEFT JOIN users app_user ON co.approved_by = app_user.user_id
        ORDER BY co.status = 'requested' DESC, co.date_approved DESC
    ");
    $condemnations = $stmt->fetchAll();
}

$pageTitle = 'Condemnation Requests';
$active = 'condemnation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Condemnation & Disposal Management</h2>
        <p class="legacy-027">Review equipment condemnation requests, reasons and recorded decisions.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <button onclick="openCondemnModal()" class="legacy-028">+ Request Condemnation</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['requested'])): ?>
    <div class="legacy-029">
        Condemnation request submitted successfully. Waiting for Property Custodian approval.
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="legacy-029">
        Condemnation request status updated successfully.
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?><div class="alert alert-error" role="alert"><?= e((string)$_GET['error']) ?></div><?php endif; ?>
<?php if ($request_error): ?><div class="legacy-181" role="alert" style="padding:12px;margin-bottom:12px;background:#ffe3e3;color:#900;border-radius:6px;"><?= e($request_error) ?></div><?php endif; ?>
<!-- Record-table styling is included in assets/css/style.css. -->
<section class="card record-card" aria-label="Condemnation requests">
    <div class="record-section-header"><div><h3>Condemnation records</h3><p>Choose a record to review its full reason and decisions.</p></div><label class="record-pagesize">Rows per page <select id="cond-page-size" class="input"><option value="10">10 rows</option><option value="20">20 rows</option><option value="50">50 rows</option></select></label></div>
    <div class="record-filters">
      <div class="field record-search"><label for="cond-search">Search CF number, item, ICS or teacher</label><input id="cond-search" class="input" type="search" autocomplete="off" placeholder="Search condemnation records"></div>
      <div class="field"><label for="cond-status">Status</label><select id="cond-status" class="input"><option value="">All statuses</option><option value="requested">Requested</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
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
             $requestDate=(string)($con['date_requested']??'');
             $searchText=implode(' ',[$formLabel,$con['item_name']??'',$con['ics_number']??'',$teacherName,$con['reason']??'']);
          ?>
          <tr class="record-main" data-record-id="<?= $rid ?>" data-status="<?= e($status) ?>" data-search="<?= e(function_exists('mb_strtolower') ? mb_strtolower($searchText) : strtolower($searchText)) ?>" data-date="<?= e($requestDate) ?>" data-item="<?= e(function_exists('mb_strtolower') ? mb_strtolower((string)$con['item_name']) : strtolower((string)$con['item_name'])) ?>" data-qty="<?= (int)$con['quantity'] ?>">
            <td><strong><?= e($con['item_name']) ?></strong><small>CF: <?= e($formLabel) ?></small></td>
            <td><strong><?= e($teacherName) ?></strong><small><?= e($con['ics_number']) ?></small></td>
            <td><?= (int)$con['quantity'] ?> <?= e($con['unit']??'') ?></td>
            <td><span class="status-badge <?= $status === 'approved' ? 'status-success' : ($status === 'rejected' ? 'status-danger' : 'status-warning') ?>"><?= e(ucfirst($status)) ?></span></td>
            <td><button type="button" class="btn btn-outline btn-sm record-expand" data-target="cond-detail-<?= $rid ?>" aria-expanded="false" aria-controls="cond-detail-<?= $rid ?>">View Details <span aria-hidden="true">⌄</span></button></td>
          </tr>
          <tr class="record-detail" id="cond-detail-<?= $rid ?>" data-detail-for="<?= $rid ?>" hidden><td colspan="5"><div class="record-detail-panel">
            <div class="record-detail-head"><h4><?= e($con['item_name']) ?> · <?= e($formLabel) ?></h4><button type="button" class="btn btn-outline btn-sm record-close" data-target="cond-detail-<?= $rid ?>">Close details ↑</button></div>
            <div class="record-facts">
              <div><span>ICS / Holder</span><strong><?= e($con['ics_number']) ?> · <?= e($teacherName) ?></strong></div>
              <div><span>Requested quantity</span><strong><?= (int)$con['quantity'] ?> <?= e($con['unit']??'') ?></strong></div>
              <div><span>Date requested</span><strong><?= $requestDate ? e(date('M j, Y · g:i A',strtotime($requestDate))) : '–' ?></strong></div>
              <div><span>Decision / Disposition</span><strong><?= e(ucfirst($status)) ?><?= !empty($con['outcome'])?' · '.e(ucfirst((string)$con['outcome'])):'' ?></strong></div>
            </div>
            <div class="record-note"><strong>Reason for condemnation</strong><p><em><?= nl2br(e($con['reason'])) ?></em></p></div>
            <?php if (!empty($con['approver_name'])): ?><p class="record-audit"><strong>Reviewed by <?= e($con['approver_name']) ?></strong><?= !empty($con['date_approved'])?' · '.e(date('M j, Y',strtotime((string)$con['date_approved']))):'' ?></p><?php endif; ?>
            <?php if ($role === 'supply_officer' && $status === 'requested'): ?>
              <form method="post" class="record-decision" onsubmit="return confirm('Record this condemnation decision?');">
                <?= csrf_field() ?><input type="hidden" name="condemnation_id" value="<?= $rid ?>">
                <div class="record-disposition"><label><input type="checkbox" name="confirm_disposed" value="1"> I confirm the units have physically been disposed of.</label><p>Under the current process, approving this record also records the physical disposal. Do not approve until disposal has actually occurred and the required school authorization is on file.</p></div>
                <div class="record-decision-actions"><button type="submit" name="action_condemnation" value="approved" class="btn btn-primary">Approve & Record Disposal</button><button type="submit" name="action_condemnation" value="rejected" class="btn btn-outline">Reject Request</button></div>
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
        <form method="POST" id="condemn_request_form">
            <?= csrf_field() ?>
            <div class="legacy-045">
                <label class="legacy-046">Select Accountable Item *</label>
                <select name="ics_item_id" id="condemn_item" required class="legacy-047">
                    <option value="">-- Choose Damaged / Broken Item --</option>
                    <?php foreach ($my_items as $mi): 
                        $avail_condemn = $mi['needs_review'] ? 0 : max(0,(int)$mi['accountable_qty']-(int)$mi['pending_qty']);
                        if ($avail_condemn > 0):
                    ?>
                        <option value="<?= $mi['ics_item_id'] ?>" data-max="<?= (int)$avail_condemn ?>" <?= (int)($_POST['ics_item_id'] ?? 0) === (int)$mi['ics_item_id'] ? 'selected' : '' ?>>
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Available: <?= $avail_condemn ?> <?= e($mi['unit']) ?> [<?= e($mi['item_condition']) ?>]
                        </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div class="legacy-048">
                <div class="legacy-049">
                    <label class="legacy-046">Quantity *</label>
                    <input type="number" name="quantity" id="condemn_qty" min="1" step="1" value="<?= e($_POST['quantity'] ?? '1') ?>" required class="legacy-047">
                    <small id="condemn_qty_hint" style="display:block;margin-top:4px;">Select an eligible item to see the maximum quantity.</small>
                </div>
                <div class="legacy-049"><label class="legacy-046">Form number</label><p class="muted">Generated automatically after submission.</p></div>
            </div>

            <div class="legacy-050">
                <label class="legacy-046">Reason for Condemnation / Disposal *</label>
                <textarea name="reason" rows="3" required placeholder="Describe why this equipment is beyond repair..." class="legacy-047"><?= e($_POST['reason'] ?? '') ?></textarea>
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
 const max = Number(condemItem.selectedOptions[0]?.dataset.max || 0);
 condemQty.max = max ? String(max) : '';
 condemHint.textContent = max ? `Maximum available for this request: ${max}` : 'Select an eligible item first.';
 if (max && Number(condemQty.value) > max) condemQty.value = String(max);
}
condemItem.addEventListener('change', updateCondemnLimit);
document.getElementById('condemn_request_form').addEventListener('submit', event => {
 const max = Number(condemItem.selectedOptions[0]?.dataset.max || 0);
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>