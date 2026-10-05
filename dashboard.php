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
$uid = (int)($user['user_id'] ?? $user['id']);
$pdo = db();

$pageTitle = 'Dashboard';
$active = 'dashboard';

// ---------- stat cards: [label, value, small text, icon, color] ----------
$cards = [];

if ($isTeacher) {
    $mine = (int)db_scalar(
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.teacher_id = ? AND i.status = 'active'", [$uid]);
    $repair = (int)db_scalar(
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.teacher_id = ? AND i.status = 'active' AND ii.item_condition = 'for_repair'", [$uid]);
    $damaged = (int)db_scalar(
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.teacher_id = ? AND i.status = 'active' AND ii.item_condition = 'damaged'", [$uid]);
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
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id WHERE i.status = 'active'");
    $icsCount = (int)db_scalar("SELECT COUNT(*) FROM ics WHERE status = 'active'");
    $repair = (int)db_scalar(
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
         WHERE i.status = 'active' AND ii.item_condition = 'for_repair'");
    $damaged = (int)db_scalar(
        "SELECT COALESCE(SUM(ii.qty_issued),0) FROM ics_items ii JOIN ics i ON i.ics_id = ii.ics_id
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
        "SELECT inv.item_name, ii.qty_issued, ii.item_condition, i.ics_number
         FROM ics_items ii
         JOIN ics i ON i.ics_id = ii.ics_id
         JOIN inventory_items inv ON inv.item_id = ii.item_id
         WHERE i.teacher_id = ? AND i.status = 'active'
         ORDER BY i.date_issued DESC, ii.ics_item_id DESC LIMIT 10");
    $st->execute([$uid]);
    $myItems = $st->fetchAll();
} else {
    $byCourse = $pdo->query(
        "SELECT c.course_name, COALESCE(SUM(ii.qty_issued),0) AS qty
         FROM courses c
         LEFT JOIN ics i ON i.course_id = c.course_id AND i.status = 'active'
         LEFT JOIN ics_items ii ON ii.ics_id = i.ics_id
         GROUP BY c.course_id, c.course_name
         ORDER BY qty DESC, c.course_name ASC LIMIT 10")->fetchAll();

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
<div class="page-head">
    <h1>Dashboard</h1>
    <p>Welcome back, <?= e($user['full_name'] ?? 'User') ?></p>
</div>

<div class="stats">
    <?php foreach ($cards as $c): ?>
        <div class="card stat">
            <div>
                <div class="stat-label"><?= e($c[0]) ?></div>
                <div class="stat-value <?= e($c[4]) ?>"><?= e($c[1]) ?></div>
                <div class="stat-sub"><?= e($c[2]) ?></div>
            </div>
            <div class="stat-icon <?= e($c[4]) ?>"><?= icon($c[3], 24) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($isTeacher): ?>
    <div class="card">
        <h3>My issued items</h3>
        <?php if (!$myItems): ?>
            <p class="muted">No items have been issued to you yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Item</th><th>Qty</th><th>Condition</th><th>ICS No.</th></tr></thead>
                    <tbody>
                    <?php foreach ($myItems as $r): ?>
                        <tr>
                            <td><?= e($r['item_name']) ?></td>
                            <td><?= e($r['qty_issued']) ?></td>
                            <td><?= condition_pill($r['item_condition']) ?></td>
                            <td><?= e($r['ics_number']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="grid-2">
        <div class="card">
            <h3>Items issued by course</h3>
            <?php if (!$byCourse): ?>
                <p class="muted">No courses yet. Add them on the Courses page.</p>
            <?php else: ?>
                <?php foreach ($byCourse as $r): ?>
                    <div class="bar-row">
                        <div class="bar-label"><?= e($r['course_name']) ?></div>
                        <div class="bar-track"><div class="bar-fill" style="width: <?= (int)round(((int)$r['qty'] / $maxQty) * 100) ?>%"></div></div>
                        <div class="bar-value"><?= e($r['qty']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Frequently borrowed items</h3>
            <?php if (!$borrowedTop): ?>
                <p class="muted">No borrowing recorded yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Item</th><th>Times borrowed</th></tr></thead>
                    <tbody>
                    <?php foreach ($borrowedTop as $r): ?>
                        <tr><td><?= e($r['item_name']) ?></td><td><?= e($r['times']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
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