<?php
/** TNTS v5.7 Phase 3.9 — development/test data reset. REMOVE after use. */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$user = require_role(['supply_officer']);

// The order is child-before-parent, based on the v5.7 Phase 3.7 SQL foreign keys.
$deleteOrder = [
    'attachments', 'borrow_logs', 'transfers', 'repair_logs', 'condemnations',
    'ics_return_lines', 'ics_condition_balances', 'ics_returns', 'ics_items', 'ics',
    'central_condition_disposals', 'central_condition_restorations',
    'inventory_withdrawals', 'inventory_master_keys', 'inventory_receipt_batches',
    'inspection_reports', 'inventory_items', 'delivery_items', 'deliveries',
    'ics_number_sequences', 'receiving_number_sequences', 'history_logs'
];
$preserve = [
    'users', 'user_profiles', 'academic_tracks', 'courses', 'teacher_courses',
    'item_classifications', 'units', 'inventory_aggregation_lock'
];
$expected = array_merge($deleteOrder, $preserve);
$database = (string) db()->query('SELECT DATABASE()')->fetchColumn();
$allTables = db()->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
$actual = array_map(static function ($r) { return $r[0]; }, $allTables);
$missing = array_values(array_diff($expected, $actual));
$extra = array_values(array_diff($actual, $expected));
$preflightProblems = [];
if ($missing) $preflightProblems[] = 'Missing expected tables: ' . implode(', ', $missing);
if ($extra) $preflightProblems[] = 'Unexpected tables detected; verify schema before resetting: ' . implode(', ', $extra);

// Refuse a reset if preserved tables have foreign keys into tables being deleted.
if (!$preflightProblems) {
    $sql = 'SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL';
    foreach (db()->query($sql) as $fk) {
        if (in_array($fk['TABLE_NAME'], $preserve, true) && in_array($fk['REFERENCED_TABLE_NAME'], $deleteOrder, true)) {
            $preflightProblems[] = "Preserved table {$fk['TABLE_NAME']} depends on deleted table {$fk['REFERENCED_TABLE_NAME']}.";
        }
    }
}
$counts = [];
if (!$missing) foreach ($deleteOrder as $table) {
    $counts[$table] = (int) db()->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
$error = '';
$finished = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($preflightProblems) {
        $error = 'Reset blocked because schema preflight did not pass.';
    } elseif (($database !== 'tnts_inventory') || ($_POST['database_name'] ?? '') !== $database) {
        $error = 'Database name mismatch. This reset is only built for the TNTS v5.7 database named tnts_inventory.';
    } elseif (($_POST['confirmation'] ?? '') !== 'RESET TEST DATA' || empty($_POST['backed_up'])) {
        $error = 'Export a verified backup, tick the backup checkbox, and type RESET TEST DATA exactly.';
    } else {
        try {
            db()->beginTransaction();
            // Foreign-key checks remain enabled. DELETEs are reversible on rollback for InnoDB.
            foreach ($deleteOrder as $table) db()->exec('DELETE FROM `' . $table . '`');
            db()->commit();
            $finished = true;
            $counts = array_fill_keys($deleteOrder, 0);
            // We deliberately DO NOT reset AUTO_INCREMENT: this avoids nontransactional DDL.
            // REC and ICS sequence rows were deleted; document numbers restart at 001.
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $error = 'Reset failed and changes were rolled back. Technical detail: ' . $ex->getMessage();
        }
    }
}
$pageTitle = 'Phase 3.9 Test Data Reset';
$active = 'inventory';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.p39-wrap{max-width:1040px;margin:0 auto}.p39-card{background:#fff;border:1px solid #dfe3ea;border-radius:14px;padding:22px;margin:16px 0}.p39-muted{color:#617088;font-size:13px}.p39-table{width:100%;border-collapse:collapse}.p39-table td,.p39-table th{padding:9px 12px;border-bottom:1px solid #e9ecf1;text-align:left}.p39-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.p39-warn{background:#fff1e6;color:#843c0d;padding:14px;border-radius:9px}.p39-error{background:#ffeded;color:#8c1919;padding:12px;border-radius:9px}.p39-ok{background:#e4f6eb;color:#115b36;padding:14px;border-radius:9px}.p39-btn{background:#870900;border:0;color:#fff;padding:12px 20px;border-radius:8px;cursor:pointer}.p39-btn:disabled{opacity:.55}.p39-input{padding:11px;width:100%;max-width:350px;border:1px solid #bcc7d6;border-radius:7px}
</style>
<div class="p39-wrap">
<p><a href="<?= e(url('inventory.php')) ?>">← Back to Inventory</a></p>
<h2>Phase 3.9 — Controlled Test Data Reset</h2>
<p class="p39-muted">Development-only utility. No automatic reset occurs when opening this page.</p>
<?php if ($finished): ?><div class="p39-ok"><strong>Test records cleared.</strong> Preserved accounts, profiles, tracks, courses, teacher assignments, classifications and units remain. REC and ICS document sequences restart at 001. Delete this PHP file from the web server now.</div><?php endif; ?>
<?php if ($error): ?><div class="p39-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($preflightProblems): ?><div class="p39-error"><strong>Reset unavailable:</strong><ul><?php foreach ($preflightProblems as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="p39-card">
<h3>What will be preserved</h3>
<div class="p39-grid">
<?php foreach ($preserve as $table): ?><div>✓ <?= e($table) ?></div><?php endforeach; ?>
</div>
<p class="p39-muted">The reset does not alter the application files, authentication settings, or unit/classification catalogs.</p>
</div>
<div class="p39-card">
<h3>Records scheduled for deletion</h3>
<div style="overflow-x:auto"><table class="p39-table"><thead><tr><th>Table</th><th>Current rows</th></tr></thead><tbody>
<?php foreach ($counts as $table => $count): ?><tr><td><?= e($table) ?></td><td><?= (int) $count ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="p39-muted">The associated uploaded receipt/image files on disk are NOT automatically removed; back them up and review separately before manual cleanup.</p>
</div>
<div class="p39-card">
<h3>Confirm test reset</h3>
<div class="p39-warn"><strong>Destructive operation:</strong> This permanently deletes the listed records from the selected database. First export the database in phpMyAdmin and keep a copy outside htdocs. Do not run against real school records.</div>
<p>Connected database: <strong><?= e($database) ?></strong></p>
<form method="post" onsubmit="return confirm('FINAL CONFIRMATION: delete ALL testing transactions from <?= e($database) ?>? Accounts and master configuration remain.');">
<?= csrf_field() ?>
<p><label><input type="checkbox" name="backed_up" value="1" required> I have exported and checked a backup and confirm this database contains only demonstration transactions.</label></p>
<p><label>Type the database name to confirm<br><input class="p39-input" type="text" name="database_name" autocomplete="off" required placeholder="tnts_inventory"></label></p>
<p><label>Type RESET TEST DATA exactly<br><input class="p39-input" type="text" name="confirmation" autocomplete="off" required placeholder="RESET TEST DATA"></label></p>
<button class="p39-btn" type="submit" <?= ($preflightProblems || $finished) ? 'disabled' : '' ?>>Reset Test Transactions</button>
</form>
</div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
