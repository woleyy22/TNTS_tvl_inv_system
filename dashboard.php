<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$isTeacher = ($user['role'] === 'teacher');
$isHead = ($user['role'] === 'tvl_head');
$mySpecializations = [];
if ($isTeacher) {
    $courseStmt = db()->prepare("SELECT c.course_name FROM teacher_courses tc JOIN courses c ON c.course_id = tc.course_id WHERE tc.user_id = ? ORDER BY c.course_name");
    $courseStmt->execute([(int)($user['user_id'] ?? $user['id'])]);
    $mySpecializations = $courseStmt->fetchAll(PDO::FETCH_COLUMN);
}

$uid = (int)($user['user_id'] ?? $user['id']);
$pdo = db();

$pageTitle = 'Dashboard';
$active = 'dashboard';

// ---------- stat cards: [label, value, small text, icon, color] ----------
$cards = [];

if ($isTeacher) {
    // Phase 5.4: account for both originally issued and transfer-origin ICS lines.
    // Condition balances are current physical custody, not historical issue totals.
    $custodyStmt = $pdo->prepare("SELECT ii.ics_item_id, ii.qty_issued, ii.qty_returned,
            ii.qty_transferred_out, inv.item_category,
            COALESCE(b.qty_serviceable,0) AS serviceable,
            COALESCE(b.qty_for_repair,0) AS for_repair,
            COALESCE(b.qty_damaged,0) AS damaged_qty,
            COALESCE((SELECT SUM(c.quantity) FROM condemnations c
              WHERE c.ics_item_id=ii.ics_item_id AND c.status='approved' AND c.outcome='disposed'),0) AS disposed
        FROM ics_items ii JOIN ics i ON i.ics_id=ii.ics_id
        JOIN inventory_items inv ON inv.item_id=ii.item_id
        LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id
        WHERE i.teacher_id=? AND i.status='active'");
    $custodyStmt->execute([$uid]);
    $mine=0; $repair=0; $damaged=0;
    foreach ($custodyStmt->fetchAll(PDO::FETCH_ASSOC) as $custody) {
        $held=max(0,(int)$custody['qty_issued']-(int)$custody['qty_returned']
                      -(int)$custody['qty_transferred_out']-(int)$custody['disposed']);
        $mine += $held;
        if(in_array($custody['item_category'],['equipment','non_consumable'],true)) {
            $repair += (int)$custody['for_repair'];
            $damaged += (int)$custody['damaged_qty'];
        }
    }
    $borrowed = (int)db_scalar(
        "SELECT COALESCE(SUM(quantity),0) FROM borrow_logs WHERE borrower_id = ? AND status = 'borrowed'", [$uid]);

    $cards[] = ['My items', $mine, 'Issued to you on ICS', 'box', 'red'];
    $cards[] = ['For repair', $repair, 'Items you are repairing', 'alert', 'orange'];
    $cards[] = ['Damaged', $damaged, 'Waiting for condemnation', 'alert', 'purple'];
    $cards[] = ['Borrowed by me', $borrowed, 'Not yet returned', 'clip', 'green'];
} else {
    $onRecord = (int)db_scalar('SELECT COALESCE(SUM(quantity),0) FROM inventory_items');
    $unique = (int)db_scalar('SELECT COUNT(*) FROM inventory_items');
    $issued = (int)db_scalar(
        "SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued ELSE 0 END),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id WHERE i.status = 'active'");
    $icsCount = (int)db_scalar("SELECT COUNT(*) FROM ics WHERE status = 'active'");
    $repair = (int)db_scalar(
        "SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued ELSE 0 END),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.status = 'active' AND ii.item_condition = 'for_repair'");
    $damaged = (int)db_scalar(
        "SELECT COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued ELSE 0 END),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.status = 'active' AND ii.item_condition = 'damaged'");
    $pendingDeliveries = (int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE status <> 'accepted'");
    
    // Check if condemnations table exists before querying to prevent crashes
    $pendingCondemn = 0;
    try {
        $pendingCondemn = (int)db_scalar("SELECT COUNT(*) FROM condemnations WHERE status = 'requested'");
    } catch (Throwable $e) {
        $pendingCondemn = 0;
    }

    $cards[] = ['Items on record', $onRecord, $unique . ' unique items', 'box', 'red'];
    $cards[] = ['Issued to teachers', $issued, $icsCount . ' active ICS', 'check', 'green'];
    $cards[] = ['For repair / damaged', $repair + $damaged, $repair . ' for repair, ' . $damaged . ' damaged', 'alert', 'orange'];
    $cards[] = ['Pending', $pendingDeliveries + $pendingCondemn, $pendingDeliveries . ' deliveries, ' . $pendingCondemn . ' condemnations', 'clip', 'purple'];
}

// ---------- panels ----------
$byCourse = [];
$borrowedTop = [];
$recent = [];
$myItems = [];

if ($isTeacher) {
    $st = $pdo->prepare(
        "SELECT inv.item_name, ii.qty_issued, ii.qty_returned, ii.qty_transferred_out, ii.item_condition, i.ics_number
         FROM ics_items ii
         JOIN ics i ON i.ics_id = ii.ics_id
         JOIN inventory_items inv ON inv.item_id = ii.item_id
         WHERE i.teacher_id = ? AND i.status = 'active'
         ORDER BY i.date_issued DESC, ii.ics_item_id DESC LIMIT 10");
    $st->execute([$uid]);
    $myItems = $st->fetchAll();
} else {
    $byCourse = $pdo->query(
        "SELECT COALESCE(c.course_name,'Unclassified') AS course_name,
                SUM(GREATEST(0,ii.qty_issued-ii.qty_returned-ii.qty_transferred_out-
                    COALESCE((SELECT SUM(cd.quantity) FROM condemnations cd WHERE cd.ics_item_id=ii.ics_item_id AND cd.status='approved' AND cd.outcome='disposed'),0))) AS qty
         FROM ics_items ii JOIN ics i ON i.ics_id=ii.ics_id
         LEFT JOIN courses c ON c.course_id=COALESCE(ii.course_id,i.course_id)
         WHERE i.status='active'
         GROUP BY COALESCE(c.course_name,'Unclassified') ORDER BY qty DESC,course_name LIMIT 10")->fetchAll();

    try {
        $borrowedTop = $pdo->query(
            "SELECT inv.item_name, COUNT(*) AS times
             FROM borrow_logs b
             JOIN ics_items ii ON ii.ics_item_id = b.ics_item_id
             JOIN inventory_items inv ON inv.item_id = ii.item_id
             GROUP BY inv.item_id, inv.item_name
             ORDER BY times DESC, inv.item_name ASC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        $borrowedTop = [];
    }

    $recent = $pdo->query(
        "SELECT h.action, h.module, h.created_at, u.full_name
         FROM history_logs h LEFT JOIN users u ON u.user_id = h.user_id
         ORDER BY h.created_at DESC, h.log_id DESC LIMIT 6")->fetchAll();
}

$dashDetails = [];
if (!$isTeacher) {
    $dashDetails['inventory'] = $pdo->query("SELECT item_name,item_category,quantity,unit FROM inventory_items ORDER BY item_name LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $dashDetails['ics'] = $pdo->query("SELECT i.ics_number,u.full_name,COUNT(ii.ics_item_id) AS item_types,SUM(GREATEST(0,ii.qty_issued-ii.qty_returned-ii.qty_transferred_out)) AS held FROM ics i JOIN users u ON u.user_id=i.teacher_id LEFT JOIN ics_items ii ON ii.ics_id=i.ics_id WHERE i.status='active' GROUP BY i.ics_id,i.ics_number,u.full_name ORDER BY i.date_issued DESC,i.ics_id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $dashDetails['borrow'] = $pdo->query("SELECT inv.item_name,br.quantity,br.returned_qty,br.expected_return_date,br.status,borrower.full_name AS borrower,holder.full_name AS holder FROM borrow_requests br JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id JOIN inventory_items inv ON inv.item_id=ii.item_id JOIN users borrower ON borrower.user_id=br.borrower_id JOIN users holder ON holder.user_id=br.lender_id WHERE br.status IN ('borrowed','partially_returned') ORDER BY br.released_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    // Count actual released borrow requests, not requests that were only submitted or declined.
    $dashDetails['frequently_borrowed'] = $pdo->query(
        "SELECT inv.item_name, inv.unit, COUNT(*) AS times_borrowed,
                SUM(br.quantity) AS total_units_borrowed,
                MAX(br.released_at) AS last_borrowed
         FROM borrow_requests br
         JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id
         JOIN inventory_items inv ON inv.item_id=ii.item_id
         WHERE br.released_at IS NOT NULL
         GROUP BY inv.item_id,inv.item_name,inv.unit
         ORDER BY times_borrowed DESC,total_units_borrowed DESC,inv.item_name ASC
         LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $dashDetails['deliveries'] = $pdo->query("SELECT receiving_number,supplier_name,status,date_received FROM deliveries ORDER BY date_received DESC,delivery_id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $alerts = [
        ['label'=>'Pending borrow requests','count'=>(int)db_scalar("SELECT COUNT(*) FROM borrow_requests WHERE status='requested'"),'url'=>'borrow.php#p6-history'],
        ['label'=>'Pending returns to verify','count'=>(int)db_scalar("SELECT COUNT(*) FROM borrow_return_requests WHERE status='pending'"),'url'=>'borrow.php#p6-history'],
        ['label'=>'Property transfer requests','count'=>(int)db_scalar("SELECT COUNT(*) FROM transfer_requests WHERE status IN ('requested','accepted','awaiting_finalization')"),'url'=>'transfers.php'],
        ['label'=>'Condemnation requests','count'=>(int)db_scalar("SELECT COUNT(*) FROM condemnations WHERE status='requested'"),'url'=>'condemnation.php'],
        ['label'=>'Deliveries pending inspection','count'=>(int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE status='pending_inspection'"),'url'=>'receiving.php'],
        ['label'=>'Batches already expired','count'=>(int)db_scalar("SELECT COUNT(*) FROM inventory_receipt_batches WHERE expiration_date IS NOT NULL AND expiration_date < CURDATE()"),'url'=>'inventory_batches.php'],
        ['label'=>'Account changes pending','count'=>(int)db_scalar("SELECT COUNT(*) FROM account_change_requests WHERE status='pending'"),'url'=>'personnel_reviews.php'],
    ];
    $borrowUnits=(int)db_scalar("SELECT COALESCE(SUM(GREATEST(0,quantity-returned_qty)),0) FROM borrow_requests WHERE status IN ('borrowed','partially_returned')");
    $deliveryCount=(int)db_scalar("SELECT COUNT(*) FROM deliveries WHERE status IN ('accepted','partially_accepted')");
    $actionCount=array_sum(array_column($alerts,'count'));
    $dashboardPanels = [
       'inventory'=>['Total Inventory',$onRecord,$unique.' item types','inventory.php'],
       'ics'=>['ICS Items',$issued,$icsCount.' active ICS','ics_issue.php'],
       'borrow'=>['Borrowed Items',$borrowUnits,'Active borrowed units','borrow.php'],
       'deliveries'=>['Received Deliveries',$deliveryCount,'Accepted / partially accepted','receiving.php'],
       'attention'=>['Needs Attention',$actionCount,'Unresolved actions','dashboard.php'],
    ];
}


// Phase 3.8.30: read-only dashboard summaries for teachers and TVL Head.
// Statuses and permissions are unchanged; every actionable link points to a
// module the current role already has access to.
$rolePanels = [];
$roleDetails = [];
$teacherActivity = [];
if ($isTeacher) {
    $teacherActivityStmt=$pdo->prepare("SELECT action,module,created_at FROM history_logs WHERE user_id=? ORDER BY created_at DESC,log_id DESC LIMIT 6");
    $teacherActivityStmt->execute([$uid]);
    $teacherActivity=$teacherActivityStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeMyICS = (int) db_scalar("SELECT COUNT(*) FROM ics WHERE teacher_id=? AND status='active'", [$uid]);
    $borrowedNow = (int) db_scalar("SELECT COALESCE(SUM(GREATEST(0,quantity-returned_qty)),0) FROM borrow_requests WHERE borrower_id=? AND status IN ('borrowed','partially_returned')",[$uid]);
    $pendingBorrow = (int) db_scalar("SELECT COUNT(*) FROM borrow_requests WHERE lender_id=? AND status='requested'",[$uid]);
    $pendingReturns = (int) db_scalar("SELECT COUNT(*) FROM borrow_return_requests rr JOIN borrow_requests br ON br.request_id=rr.request_id WHERE br.lender_id=? AND rr.status='pending'",[$uid]);
    $pendingTransfers = (int) db_scalar("SELECT COUNT(*) FROM transfer_requests WHERE to_teacher_id=? AND status='requested'",[$uid]);
    $awaitingOfficer = (int) db_scalar("SELECT COUNT(*) FROM transfer_requests WHERE (to_teacher_id=? OR from_teacher_id=?) AND status IN ('accepted','awaiting_finalization')",[$uid,$uid]);
    $actionTotal = $pendingBorrow+$pendingReturns+$pendingTransfers;
    $rolePanels = [
      'inventory' => ['My ICS Items',$mine,$activeMyICS.' active ICS','inventory.php?view=issued','▣'],
      'borrow'    => ['Borrowed by Me',$borrowedNow,'Units not yet returned','borrow.php','⇄'],
      'deliveries'=> ['For Repair',$repair,'Units awaiting repair','item_maintenance.php','⚒'],
      'ics'       => ['Damaged',$damaged,'Units recorded as damaged','item_maintenance.php','!'],
      'attention' => ['Needs My Action',$actionTotal,'Requests awaiting your response','borrow.php','✓'],
    ];
    $roleDetails['inventory'] = $pdo->prepare(
        "SELECT i.ics_number,inv.item_name,inv.unit,
          GREATEST(0,ii.qty_issued-ii.qty_returned-ii.qty_transferred_out-
            COALESCE((SELECT SUM(c.quantity) FROM condemnations c WHERE c.ics_item_id=ii.ics_item_id AND c.status='approved' AND c.outcome='disposed'),0)) AS held
         FROM ics_items ii JOIN ics i ON i.ics_id=ii.ics_id
         JOIN inventory_items inv ON inv.item_id=ii.item_id
         WHERE i.teacher_id=? AND i.status='active' ORDER BY i.date_issued DESC,ii.ics_item_id DESC LIMIT 8");
    $roleDetails['inventory']->execute([$uid]);
    $roleDetails['inventory']=$roleDetails['inventory']->fetchAll(PDO::FETCH_ASSOC);
    $roleDetails['borrow'] = $pdo->prepare(
        "SELECT br.request_id,inv.item_name,inv.unit,br.quantity,br.returned_qty,br.expected_return_date,br.status,u.full_name AS holder
         FROM borrow_requests br JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id
         JOIN inventory_items inv ON inv.item_id=ii.item_id JOIN users u ON u.user_id=br.lender_id
         WHERE br.borrower_id=? AND br.status IN ('borrowed','partially_returned')
         ORDER BY br.released_at DESC,br.request_id DESC LIMIT 8");
    $roleDetails['borrow']->execute([$uid]);
    $roleDetails['borrow']=$roleDetails['borrow']->fetchAll(PDO::FETCH_ASSOC);
    $conditionStmt = $pdo->prepare(
        "SELECT inv.item_name,inv.unit,i.ics_number,b.qty_for_repair,b.qty_damaged
         FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id
         JOIN ics i ON i.ics_id=ii.ics_id JOIN inventory_items inv ON inv.item_id=ii.item_id
         WHERE i.teacher_id=? AND i.status='active'
           AND inv.item_category IN ('equipment','non_consumable')
           AND (b.qty_for_repair>0 OR b.qty_damaged>0)
         ORDER BY i.date_issued DESC,ii.ics_item_id DESC LIMIT 20");
    $conditionStmt->execute([$uid]);
    $conditionRows=$conditionStmt->fetchAll(PDO::FETCH_ASSOC);
    $roleDetails['deliveries']=array_values(array_filter($conditionRows,static fn($r)=>(int)$r['qty_for_repair']>0));
    $roleDetails['ics']=array_values(array_filter($conditionRows,static fn($r)=>(int)$r['qty_damaged']>0));
    $roleDetails['attention']=[
       ['label'=>'Borrow requests to review','count'=>$pendingBorrow,'url'=>'borrow.php'],
       ['label'=>'Borrow returns to verify','count'=>$pendingReturns,'url'=>'borrow.php'],
       ['label'=>'Transfers awaiting your acceptance','count'=>$pendingTransfers,'url'=>'transfers.php'],
       ['label'=>'Accepted transfers awaiting Supply Officer','count'=>$awaitingOfficer,'url'=>'transfers.php'],
    ];
} elseif ($isHead) {
    $headItems = (int) db_scalar('SELECT COALESCE(SUM(quantity),0) FROM inventory_items');
    $headTypes = (int) db_scalar('SELECT COUNT(*) FROM inventory_items');
    $headICS = (int) db_scalar("SELECT COUNT(*) FROM ics WHERE status='active'");
    $headTeachers = (int) db_scalar("SELECT COUNT(DISTINCT teacher_id) FROM ics WHERE status='active'");
    $pendingRegistrations=(int) db_scalar("SELECT COUNT(*) FROM users WHERE role='teacher' AND registration_source='self_registration' AND registration_status='pending' AND email_verified_at IS NOT NULL");
    $pendingPersonnel=(int) db_scalar("SELECT COUNT(*) FROM account_change_requests WHERE status='pending' AND request_type IN ('profile_official','email_change')");
    $headAttention=(int) db_scalar("SELECT COALESCE(SUM(b.qty_for_repair+b.qty_damaged),0) FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id JOIN ics i ON i.ics_id=ii.ics_id WHERE i.status='active'");
    $headPendingTransfers=(int) db_scalar("SELECT COUNT(*) FROM transfer_requests WHERE status IN ('requested','accepted','awaiting_finalization')");
    $headPendingDeliveries=(int) db_scalar("SELECT COUNT(*) FROM deliveries WHERE status='pending_inspection'");
    $headPendingCondemnations=(int) db_scalar("SELECT COUNT(*) FROM condemnations WHERE status='requested'");
    $headOpen=$headPendingTransfers+$headPendingDeliveries+$headPendingCondemnations;
    $rolePanels = [
      'inventory' => ['Inventory on Record',$headItems,$headTypes.' item types','inventory.php','▣'],
      'ics'       => ['Active ICS',$headICS,$headTeachers.' accountable teachers','reports.php?tab=ics','▤'],
      'borrow'    => ['Account Reviews',$pendingRegistrations+$pendingPersonnel,$pendingRegistrations.' registrations · '.$pendingPersonnel.' personnel','approvals.php?status=pending','✓'],
      'deliveries'=> ['Condition Alerts',$headAttention,'Repair and damaged units','inventory.php?view=issued','!'],
      'attention' => ['Open Operations',$headOpen,'Requests needing follow-up','transfers.php','⇄'],
    ];
    $roleDetails['inventory']=$pdo->query("SELECT item_name,item_category,quantity,unit FROM inventory_items ORDER BY item_name LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $roleDetails['ics']=$pdo->query("SELECT i.ics_number,u.full_name,i.date_issued,COUNT(ii.ics_item_id) AS types
       FROM ics i JOIN users u ON u.user_id=i.teacher_id LEFT JOIN ics_items ii ON ii.ics_id=i.ics_id
       WHERE i.status='active' GROUP BY i.ics_id,i.ics_number,u.full_name,i.date_issued
       ORDER BY i.date_issued DESC,i.ics_id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $roleDetails['borrow']=[
      ['label'=>'Verified registrations awaiting review','count'=>$pendingRegistrations,'url'=>'approvals.php?status=pending'],
      ['label'=>'Personnel changes awaiting review','count'=>$pendingPersonnel,'url'=>'personnel_reviews.php'],
    ];
    $roleDetails['deliveries']=$pdo->query("SELECT inv.item_name,inv.unit,COALESCE(SUM(b.qty_for_repair),0) AS repair,
       COALESCE(SUM(b.qty_damaged),0) AS damaged
       FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id
       JOIN ics i ON i.ics_id=ii.ics_id JOIN inventory_items inv ON inv.item_id=ii.item_id
       WHERE i.status='active' AND (b.qty_for_repair>0 OR b.qty_damaged>0)
       GROUP BY inv.item_id,inv.item_name,inv.unit ORDER BY damaged DESC,repair DESC,inv.item_name LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $roleDetails['attention']=[
      ['label'=>'Property transfers in progress','count'=>$headPendingTransfers,'url'=>'transfers.php'],
      ['label'=>'Deliveries awaiting inspection','count'=>$headPendingDeliveries,'url'=>'receiving.php'],
      ['label'=>'Condemnation requests','count'=>$headPendingCondemnations,'url'=>'condemnation.php'],
    ];
}

$maxQty = 1;
foreach ($byCourse as $r) {
    if ((int)$r['qty'] > $maxQty) { $maxQty = (int)$r['qty']; }
}

function condition_pill(string $c): string
{
    if ($c === 'for_repair') { return '<span class="pill orange">For repair</span>'; }
    if ($c === 'damaged')    { return '<span class="pill red">Damaged</span>'; }
    return '<span class="pill green">Serviceable</span>';
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-head p382-page-head">
    <span class="p382-eyebrow">TNTS · INVENTORY OVERVIEW</span>
    <h1>Dashboard</h1>
    <p>Welcome back, <?= e(($isTeacher || $isHead) ? ($user['name'] ?? $user['full_name'] ?? 'User') : ($user['full_name'] ?? 'User')) ?>. Here’s your inventory at a glance.</p>
</div>
<?php if ($isTeacher): ?>
<div class="teacher-course-banner"><strong>Assigned course / specialization:</strong> <?= e($mySpecializations ? implode(', ', $mySpecializations) : 'No course assigned') ?></div>
<?php endif; ?>

<?php if (!$isTeacher && !$isHead): ?>
<div class="p38-dashboard" id="p38Dashboard">
  <div class="p38-dashboard-cards" aria-label="Dashboard summary panels">
   <?php foreach ($dashboardPanels as $key=>$panel): ?>
   <button type="button" class="p38-summary p387-theme-<?= e($key) ?>" data-panel="<?= e($key) ?>" aria-controls="p38-expanded" aria-pressed="false">
    <span class="p382-card-top"><span class="p382-mini-icon" aria-hidden="true"><?= ['inventory'=>'▣','ics'=>'▤','borrow'=>'⇄','deliveries'=>'⇣','attention'=>'!'][$key] ?></span><span class="p38-summary-arrow" aria-hidden="true">↗</span></span>
    <span class="p38-summary-label"><?= e($panel[0]) ?></span><strong><?= e($panel[1]) ?></strong><small><?= e($panel[2]) ?></small>
   </button>
   <?php endforeach; ?>
  </div>
  <section class="card p38-expanded" id="p38-expanded" aria-live="polite" aria-label="Selected dashboard details" tabindex="-1">
  <?php foreach ($dashboardPanels as $key=>$panel): ?>
    <div class="p38-panel" data-details="<?= e($key) ?>" hidden>
      <div class="p38-panel-head"><div><span class="p382-panel-kicker">EXPANDED OVERVIEW</span><h2><?= e($panel[0]) ?></h2><p><?= e($panel[2]) ?></p></div><a class="btn btn-outline" href="<?= e(url($panel[3])) ?>"><?= $key==='attention'?'Dashboard':'View full records' ?> →</a></div>
      <?php if ($key==='attention'): ?>
       <div class="p38-alert-list"><?php foreach ($alerts as $alert): ?>
        <a href="<?= e(url($alert['url'])) ?>" class="p38-alert-row"><span><?= e($alert['label']) ?></span><strong><?= e($alert['count']) ?></strong><span aria-hidden="true">→</span></a>
       <?php endforeach; ?></div>
      <?php elseif ($key==='inventory'): ?>
       <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Category</th><th>Accepted units</th></tr></thead><tbody><?php foreach ($dashDetails['inventory'] as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e(ucwords(str_replace('_',' ',$r['item_category']))) ?></td><td><?= e($r['quantity']) ?> <?= e($r['unit']??'') ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php elseif ($key==='ics'): ?>
       <div class="table-wrap"><table class="table"><thead><tr><th>ICS</th><th>Holder</th><th>Item types</th><th>On record</th></tr></thead><tbody><?php foreach ($dashDetails['ics'] as $r): ?><tr><td><strong><?= e($r['ics_number']) ?></strong></td><td><strong><?= e($r['full_name']) ?></strong></td><td><?= e($r['item_types']) ?></td><td><?= e($r['held']??0) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php elseif ($key==='borrow'): ?>
       <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Holder → Borrower</th><th>Outstanding</th><th>Due</th><th>Status</th></tr></thead><tbody><?php foreach ($dashDetails['borrow'] as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e($r['holder']) ?> → <?= e($r['borrower']) ?></td><td><?= e((int)$r['quantity']-(int)$r['returned_qty']) ?></td><td><?= e($r['expected_return_date']) ?></td><td><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></td></tr><?php endforeach; ?></tbody></table></div>
       <div class="p382-section-head"><div><h3>Frequently Borrowed Items</h3><p>Ranked by completed releases, including loans later returned.</p></div><span class="p382-section-label">Top 8</span></div>
       <?php if (empty($dashDetails['frequently_borrowed'])): ?>
          <p class="p382-empty">No released borrowing records yet.</p>
       <?php else: ?>
       <div class="table-wrap"><table class="table p382-frequency-table"><thead><tr><th>Rank</th><th>Item</th><th>Times borrowed</th><th>Total units borrowed</th><th>Last borrowed</th></tr></thead><tbody>
       <?php foreach ($dashDetails['frequently_borrowed'] as $idx=>$f): ?><tr><td><span class="p382-rank"><?= e($idx+1) ?></span></td><td><strong><?= e($f['item_name']) ?></strong></td><td><strong><?= e($f['times_borrowed']) ?></strong></td><td><?= e($f['total_units_borrowed']) ?> <?= e($f['unit'] ?: '') ?></td><td><?= e($f['last_borrowed'] ?: '–') ?></td></tr><?php endforeach; ?>
       </tbody></table></div><?php endif; ?>
      <?php elseif ($key==='deliveries'): ?>
       <div class="table-wrap"><table class="table"><thead><tr><th>Receiving no.</th><th>Supplier</th><th>Date</th><th>Status</th></tr></thead><tbody><?php foreach ($dashDetails['deliveries'] as $r): ?><tr><td><strong><?= e($r['receiving_number']) ?></strong></td><td><?= e($r['supplier_name']?:'–') ?></td><td><?= e($r['date_received']) ?></td><td><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </section>
</div>
<?php else: ?>
<div class="p38-dashboard p3830-role-dashboard" id="p38Dashboard" data-dashboard-role="<?= $isTeacher?'teacher':'tvl_head' ?>">
  <div class="p38-dashboard-cards" aria-label="Your dashboard summaries">
  <?php foreach ($rolePanels as $key=>$panel): ?>
    <button type="button" class="p38-summary p387-theme-<?= e($key) ?>" data-panel="<?= e($key) ?>" aria-controls="p38-expanded" aria-pressed="false">
      <span class="p382-card-top"><span class="p382-mini-icon" aria-hidden="true"><?= e($panel[4]) ?></span><span class="p38-summary-arrow" aria-hidden="true">↗</span></span>
      <span class="p38-summary-label"><?= e($panel[0]) ?></span><strong><?= e($panel[1]) ?></strong><small><?= e($panel[2]) ?></small>
    </button>
  <?php endforeach; ?>
  </div>
  <section class="card p38-expanded" id="p38-expanded" aria-label="Selected dashboard details" tabindex="-1" aria-live="polite">
    <?php foreach ($rolePanels as $key=>$panel): ?>
    <div class="p38-panel" data-details="<?= e($key) ?>" hidden>
      <div class="p38-panel-head"><div><span class="p382-panel-kicker"><?= $isTeacher?'MY ACCOUNTABILITY':'TVL DEPARTMENT OVERVIEW' ?></span><h2><?= e($panel[0]) ?></h2><p><?= e($panel[2]) ?></p></div>
         <a class="btn btn-outline" href="<?= e(url($panel[3])) ?>">View full records →</a></div>
      <?php $rows=$roleDetails[$key]??[]; ?>
      <?php if (!$rows): ?>
        <p class="p382-empty">No matching records at this time.</p>
      <?php elseif ($key==='attention' || ($isHead && $key==='borrow')): ?>
        <div class="p38-alert-list">
          <?php foreach ($rows as $r): ?><a class="p38-alert-row" href="<?= e(url($r['url'])) ?>"><span><?= e($r['label']) ?></span><strong><?= e($r['count']) ?></strong><span aria-hidden="true">→</span></a><?php endforeach; ?>
        </div>
      <?php elseif ($isTeacher && $key==='inventory'): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>ICS</th><th>Item</th><th>Accountable units</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['ics_number']) ?></strong></td><td><?= e($r['item_name']) ?></td><td><?= e($r['held']) ?> <?= e($r['unit']??'') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php elseif ($isTeacher && $key==='borrow'): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Holder</th><th>Not returned</th><th>Due</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e($r['holder']) ?></td><td><?= e(max(0,(int)$r['quantity']-(int)$r['returned_qty'])) ?> <?= e($r['unit']??'') ?></td><td><?= e($r['expected_return_date']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php elseif ($isTeacher && ($key==='deliveries' || $key==='ics')): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>ICS</th><th>Units</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e($r['ics_number']) ?></td><td><?= e($key==='deliveries'?$r['qty_for_repair']:$r['qty_damaged']) ?> <?= e($r['unit']??'') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php elseif ($isHead && $key==='inventory'): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Category</th><th>Accepted units</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e(ucwords(str_replace('_',' ',$r['item_category']))) ?></td><td><?= e($r['quantity']) ?> <?= e($r['unit']??'') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php elseif ($isHead && $key==='ics'): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>ICS No.</th><th>Teacher</th><th>Date issued</th><th>Item types</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['ics_number']) ?></strong></td><td><?= e($r['full_name']) ?></td><td><?= e($r['date_issued']) ?></td><td><?= e($r['types']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php elseif ($isHead && $key==='deliveries'): ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>For repair</th><th>Damaged</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['item_name']) ?></strong></td><td><?= e($r['repair']) ?> <?= e($r['unit']??'') ?></td><td><?= e($r['damaged']) ?> <?= e($r['unit']??'') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
</div>
<?php endif; ?>
<script>
(()=>{
  'use strict';
  const root=document.getElementById('p38Dashboard');
  if(!root)return;
  const dashboardRole=root.dataset.dashboardRole||'';
  const slotKey=dashboardRole?'tnts.dashboard.'+dashboardRole+'.slots.v2':'tnts.dashboard.slots.v2';
  const selectedKey=dashboardRole?'tnts.dashboard.'+dashboardRole+'.selected':'tnts.dashboard.selected';
  // Role dashboards: mobile records get labels without duplicating data or inputs.
  if(dashboardRole){
    root.querySelectorAll('.p38-panel table.table').forEach(table=>{
      const headers=Array.from(table.querySelectorAll('thead th')).map(th=>th.textContent.trim());
      table.querySelectorAll('tbody tr').forEach(tr=>{
        Array.from(tr.children).forEach((td,i)=>{
          if(td.tagName==='TD' && headers[i]) td.dataset.label=headers[i];
        });
      });
    });
  }
  const rail=root.querySelector('.p38-dashboard-cards');
  const detail=root.querySelector('.p38-expanded');
  const cards=Array.from(rail.querySelectorAll('.p38-summary'));
  const panels=Array.from(detail.querySelectorAll('.p38-panel'));
  const byCard=new Map(cards.map(card=>[card.dataset.panel,card]));
  const byPanel=new Map(panels.map(panel=>[panel.dataset.details,panel]));
  const keys=cards.map(card=>card.dataset.panel);
  const mainIndex=keys.length-1;
  let slots=keys.slice();
  let moving=false;

  // Unlike hiding/reordering by fixed category, we retain the exact four
  // visible positions and exchange the clicked slot with the expanded slot.
  try {
    const saved=JSON.parse(sessionStorage.getItem(slotKey)||'null');
    if(Array.isArray(saved)&&saved.length===keys.length&&
       saved.every(key=>byCard.has(key))&&new Set(saved).size===keys.length) {
      slots=saved;
    } else {
      const oldSelected=sessionStorage.getItem(selectedKey);
      const index=slots.indexOf(oldSelected);
      if(index>=0&&index!==mainIndex){
        [slots[index],slots[mainIndex]]=[slots[mainIndex],slots[index]];
      }
    }
  }catch(_error){}

  const prefersReduced=()=>window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const hasAnimations=typeof Element!=='undefined'&&typeof Element.prototype.animate==='function';

  function display(){
    // Move the existing buttons, never replace their listeners or state.
    slots.forEach(key=>rail.appendChild(byCard.get(key)));
    const selected=slots[mainIndex];
    cards.forEach(card=>{
      const isMain=card.dataset.panel===selected;
      card.hidden=isMain;
      card.setAttribute('aria-pressed',String(isMain));
    });
    panels.forEach(panel=>{panel.hidden=panel.dataset.details!==selected;});
    // Keep the expanded panel's accent synchronized with the active card,
    // regardless of the order in which summary cards are swapped.
    detail.classList.remove(...keys.map(key=>'p387-theme-'+key));
    detail.classList.add('p387-theme-'+selected);
    try {
      sessionStorage.setItem(slotKey,JSON.stringify(slots));
      sessionStorage.setItem(selectedKey,selected);
    }catch(_error){}
  }

  function swapSelection(selected){
    const index=slots.indexOf(selected);
    if(index<0||index===mainIndex)return false;
    [slots[index],slots[mainIndex]]=[slots[mainIndex],slots[index]];
    display();
    return true;
  }

  function miniCopy(key){
    const clone=byCard.get(key).cloneNode(true);
    clone.removeAttribute('hidden');
    clone.removeAttribute('aria-controls');
    clone.removeAttribute('aria-pressed');
    clone.removeAttribute('data-panel');
    clone.setAttribute('tabindex','-1');
    clone.classList.add('p384-flight-mini');
    return clone;
  }

  function detailsCopy(key,mainRect){
    const pane=byPanel.get(key).cloneNode(true);
    pane.removeAttribute('hidden');
    pane.removeAttribute('data-details');
    pane.classList.add('p384-flight-content');
    pane.style.width=Math.max(250,mainRect.width-44)+'px';
    return pane;
  }

  function createShell(rect,miniKey,detailKey,mainRect,direction){
    const shell=document.createElement('div');
    shell.className='p384-flight-shell p384-flight-'+direction+' p387-theme-'+miniKey;
    if(dashboardRole) shell.classList.add('p3830-flight-'+dashboardRole);
    shell.setAttribute('aria-hidden','true');
    shell.inert=true;
    Object.assign(shell.style,{
      top:rect.top+'px',left:rect.left+'px',width:rect.width+'px',height:rect.height+'px'
    });
    const mini=miniCopy(miniKey);
    const contents=detailsCopy(detailKey,mainRect);
    shell.append(mini,contents);
    document.body.appendChild(shell);
    return {shell,mini,contents};
  }

  function boxes(from,to,duration,entering){
    // Fixed-position overlays move and resize. Real grid elements do not move
    // until the animation is mostly complete, preventing layout jitter.
    return [
      {top:from.top+'px',left:from.left+'px',width:from.width+'px',height:from.height+'px',
       borderRadius:from.width<to.width?'17px':'19px',opacity:1,offset:0},
      {top:(from.top+(to.top-from.top)*.58)+'px',left:(from.left+(to.left-from.left)*.58)+'px',
       width:(from.width+(to.width-from.width)*.58)+'px',height:(from.height+(to.height-from.height)*.58)+'px',
       opacity:1,offset:.84},
      {top:to.top+'px',left:to.left+'px',width:to.width+'px',height:to.height+'px',
       borderRadius:entering?'19px':'17px',opacity:0,offset:1}
    ];
  }

  function animateClick(selected,clicked,keyboard){
    if(moving||selected===slots[mainIndex])return;
    const rectSmall=clicked.getBoundingClientRect();
    const rectBig=detail.getBoundingClientRect();
    if(prefersReduced()||!hasAnimations||rectSmall.width<100||rectBig.width<200){
      swapSelection(selected);
      if(keyboard)detail.focus({preventScroll:true});
      return;
    }
    moving=true;
    detail.setAttribute('aria-busy','true');
    const oldMain=slots[mainIndex];
    const duration=760;
    let outgoing=null,incoming=null;
    let switched=false;
    let switchTimer=0;

    const reveal=()=>{
      if(switched)return;
      switched=true;
      swapSelection(selected);
      detail.style.visibility='';
      byCard.get(oldMain).style.visibility='';
      if(keyboard)detail.focus({preventScroll:true});
    };
    const clean=()=>{
      window.clearTimeout(switchTimer);
      reveal();
      clicked.style.visibility='';
      detail.style.visibility='';
      byCard.get(oldMain).style.visibility='';
      if(outgoing)outgoing.shell.remove();
      if(incoming)incoming.shell.remove();
      detail.removeAttribute('aria-busy');
      moving=false;
    };
    try{
      outgoing=createShell(rectBig,oldMain,oldMain,rectBig,'out');
      incoming=createShell(rectSmall,selected,selected,rectBig,'in');
      // The live panel stays in the layout but visually yields to the
      // two traveling copies. No document reflow occurs during the flight.
      detail.style.visibility='hidden';
      clicked.style.visibility='hidden';
      const animations=[
        outgoing.shell.animate(boxes(rectBig,rectSmall,duration,false),
          {duration,easing:'cubic-bezier(.22,.75,.18,1)',fill:'forwards'}),
        incoming.shell.animate(boxes(rectSmall,rectBig,duration,true),
          {duration,easing:'cubic-bezier(.22,.75,.18,1)',fill:'forwards'}),
        outgoing.contents.animate([{opacity:1,offset:0},{opacity:1,offset:.30},{opacity:0,offset:.73},{opacity:0,offset:1}],{duration,fill:'forwards'}),
        outgoing.mini.animate([{opacity:0,offset:0},{opacity:0,offset:.38},{opacity:1,offset:.75},{opacity:1,offset:1}],{duration,fill:'forwards'}),
        incoming.mini.animate([{opacity:1,offset:0},{opacity:1,offset:.58},{opacity:0,offset:.89},{opacity:0,offset:1}],{duration,fill:'forwards'}),
        incoming.contents.animate([{opacity:0,offset:0},{opacity:0,offset:.37},{opacity:1,offset:.78},{opacity:1,offset:1}],{duration,fill:'forwards'}),
        // A very light shared blur at the midpoint creates a soft handoff,
        // without moving the live grid or obscuring the end positions.
        outgoing.shell.animate([
          {filter:'blur(0px)',offset:0},
          {filter:'blur(1.7px)',offset:.45},
          {filter:'blur(0px)',offset:1}
        ],{duration,easing:'ease-in-out',fill:'forwards'}),
        incoming.shell.animate([
          {filter:'blur(0px)',offset:0},
          {filter:'blur(1.7px)',offset:.45},
          {filter:'blur(0px)',offset:1}
        ],{duration,easing:'ease-in-out',fill:'forwards'})
      ];
      // Commit the swap behind the overlays near the end of their journey.
      switchTimer=window.setTimeout(reveal,Math.round(duration*.87));
      Promise.all(animations.map(a=>a.finished)).then(clean,clean);
    }catch(_error){clean();}
  }

  cards.forEach(card=>card.addEventListener('click',event=>{
    animateClick(card.dataset.panel,card,event.detail===0);
  }));
  display();
})();
</script>

<?php if ($isTeacher): ?>
    <div class="card p386-accent-panel p3830-below">
      <h3>My Recent Activity</h3>
      <?php if (!$teacherActivity): ?><p class="muted">Your recent account actions will appear here.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Action</th><th>Module</th></tr></thead><tbody>
      <?php foreach ($teacherActivity as $r): ?><tr><td><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['module']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <div class="p3830-quicklinks"><a class="btn btn-outline" href="<?= e(url('borrow.php')) ?>">Borrow & Return →</a><a class="btn btn-outline" href="<?= e(url('transfers.php')) ?>">Property Transfers →</a><a class="btn btn-outline" href="<?= e(url('item_maintenance.php')) ?>">Maintenance →</a></div>
    </div>
<?php elseif ($isHead): ?>
    <div class="card p386-accent-panel p3830-below">
      <h3>Recent Department Activity</h3>
      <?php if (!$recent): ?><p class="muted">No recent department activity.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Staff member</th><th>Action</th><th>Module</th></tr></thead><tbody>
      <?php foreach ($recent as $r): ?><tr><td><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e($r['full_name'] ?: 'Unknown') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['module']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <p class="more"><a href="<?= e(url('history.php')) ?>">View authorized history logs →</a></p>
    </div>
<?php else: ?>
    <div class="card p386-accent-panel">
      <h3>Items issued by course</h3>
      <?php if (!$byCourse): ?><p class="muted">No course allocations recorded.</p><?php else: ?>
      <?php foreach ($byCourse as $r): ?>
        <div class="bar-row"><div class="bar-label"><?= e($r['course_name']) ?></div><div class="bar-track"><div class="bar-fill" style="width:<?= max(0,min(100,(int)round(((int)$r['qty']/$maxQty)*100))) ?>%"></div></div><div class="bar-value"><?= e($r['qty']) ?></div></div>
      <?php endforeach; ?><?php endif; ?>
    </div>

    <div class="card p386-accent-panel">
        <h3>Recent activity</h3>
        <?php if (!$recent): ?>
            <p class="muted">No activity yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?= e(fmt_datetime($r['created_at'])) ?></td>
                            <td><?= e($r['full_name'] ? $r['full_name'] : 'Unknown') ?></td>
                            <td><?= e($r['action']) ?></td>
                            <td><?= e($r['module']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="more"><a href="<?= e(url('history.php')) ?>">View all history logs &rarr;</a></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>