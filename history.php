<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer', 'tvl_head']);
$pdo = db();

$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
$moduleF = trim(isset($_GET['module']) ? $_GET['module'] : '');

$sql = "SELECT h.*, u.full_name, u.role
        FROM history_logs h LEFT JOIN users u ON u.user_id = h.user_id
        WHERE 1 = 1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR h.action LIKE ? OR h.details LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($moduleF !== '') {
    $sql .= ' AND h.module = ?';
    $params[] = $moduleF;
}
$sql .= ' ORDER BY h.created_at DESC, h.log_id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

$modules = $pdo->query('SELECT DISTINCT module FROM history_logs ORDER BY module')->fetchAll();

$pageTitle = 'History Logs';
$active = 'history.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>History Logs</h1>
    <p>A record of who did what in the system. Showing the latest 200 entries.</p>
</div>

<div class="card">
    <form method="get" class="filters">
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by user, action or details">
        <select class="input" name="module">
            <option value="">All modules</option>
            <?php foreach ($modules as $m): ?>
                <option value="<?= e($m['module']) ?>"<?= $moduleF === $m['module'] ? ' selected' : '' ?>><?= e($m['module']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="6" class="muted">No log entries found.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $r): ?>
                <tr>
                    <td><?= e(fmt_datetime($r['created_at'])) ?></td>
                    <td><?= e($r['full_name'] ? $r['full_name'] : 'Unknown') ?></td>
                    <td><?= e($r['role'] ? role_label($r['role']) : '-') ?></td>
                    <td><?= e($r['action']) ?></td>
                    <td><?= e($r['module']) ?></td>
                    <td><?= e($r['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
