<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$user = current_user();
if (!$user || $user['role'] !== 'supply_officer') {
    http_response_code(403);
    die('Only the Supply Officer can manage measurement units.');
}
if (empty($_SESSION['units_csrf'])) {
    $_SESSION['units_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['units_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        die('Invalid request token. Refresh the page and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['unit_name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 20 || !preg_match('/^[\pL\pN][\pL\pN .()\/-]*$/u', $name)) {
                throw new RuntimeException('Enter a valid unit name (1–20 characters).');
            }
            $existing = db()->prepare('SELECT unit_id FROM units WHERE LOWER(unit_name) = LOWER(?)');
            $existing->execute([$name]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('That measurement unit already exists. Activate it instead.');
            }
            db()->prepare('INSERT INTO units (unit_name, is_active, sort_order) VALUES (?, 1, 100)')->execute([$name]);
            $details = 'Created unit: ' . $name;
        } elseif ($action === 'toggle') {
            $id = filter_var($_POST['unit_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) { throw new RuntimeException('Invalid unit.'); }
            $st = db()->prepare('SELECT unit_name, is_active FROM units WHERE unit_id = ?');
            $st->execute([$id]);
            $unit = $st->fetch();
            if (!$unit) { throw new RuntimeException('Unit not found.'); }
            $new_active = $unit['is_active'] ? 0 : 1;
            db()->prepare('UPDATE units SET is_active = ? WHERE unit_id = ?')->execute([$new_active, $id]);
            $details = ($new_active ? 'Activated: ' : 'Deactivated: ') . $unit['unit_name'];
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['unit_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Invalid unit.');
            $check = db()->prepare('SELECT unit_name FROM units WHERE unit_id = ?');
            $check->execute([$id]);
            $name = $check->fetchColumn();
            if ($name === false) throw new RuntimeException('Unit not found.');
            foreach (['delivery_items', 'inventory_items'] as $table) {
                $reference = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE unit = ?");
                $reference->execute([$name]);
                if ((int)$reference->fetchColumn() > 0) {
                    throw new RuntimeException('This unit is used in existing records. Deactivate it instead to preserve history.');
                }
            }
            db()->prepare('DELETE FROM units WHERE unit_id = ?')->execute([$id]);
            $details = 'Deleted unused unit: ' . $name;
        } else {
            throw new RuntimeException('Unknown action.');
        }
        db()->prepare("INSERT INTO history_logs (user_id, action, module, details) VALUES (?, 'Manage Units', 'Receiving', ?)")
            ->execute([$user['id'] ?? $user['user_id'], $details]);
        header('Location: units.php?updated=1');
        exit;
    } catch (Throwable $ex) {
        $error = $ex instanceof PDOException ? 'Unable to save the unit. Check for duplicate names.' : $ex->getMessage();
    }
}
$units = db()->query('SELECT unit_id, unit_name, is_active FROM units ORDER BY sort_order, unit_name')->fetchAll();
$pageTitle = 'Measurement Units';
$active = 'units.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="content-header"><h2>Measurement Units</h2><a class="btn btn-outline btn-sm" href="<?= e(url('receiving.php')) ?>" onclick="if (history.length > 1 && document.referrer.startsWith(location.origin)) { history.back(); return false; }">← Back</a></div>
<p>Use approved units in Receiving. Deactivating a unit removes it from future selections without changing historical deliveries or inventory quantities.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Unit list updated.</div><?php endif; ?>
<div class="card" style="margin-bottom:20px;padding:20px">
    <h3>Add a unit</h3>
    <form method="post" style="display:flex;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['units_csrf']) ?>">
        <input type="hidden" name="action" value="add">
        <input type="text" name="unit_name" maxlength="20" placeholder="e.g. Meter" required class="legacy-047" style="max-width:260px">
        <button type="submit" class="btn btn-primary">Add Unit</button>
    </form>
</div>
<div class="card" style="padding:20px">
    <table class="legacy-031"><thead><tr><th>Unit</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($units as $unit): ?>
        <tr>
            <td><?= e($unit['unit_name']) ?></td>
            <td><?= $unit['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="tnts-unit-action-cell">
                <div class="tnts-unit-actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['units_csrf']) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="unit_id" value="<?= (int)$unit['unit_id'] ?>">
                    <button type="submit" class="btn btn-sm tnts-unit-toggle" onclick="return confirm('Change availability of this unit for future deliveries?')">
                        <?= $unit['is_active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
                <form method="post" class="unit-delete-form" onsubmit="return confirm('Permanently delete this unit? This is allowed only if it is unused.');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['units_csrf']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="unit_id" value="<?= (int)$unit['unit_id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
