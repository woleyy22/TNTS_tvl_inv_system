<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Must be logged in
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Block teachers from accessing this module
if ($user['role'] === 'teacher') {
    header('Location: dashboard.php');
    exit;
}

$action = $_GET['action'] ?? 'list';
if ($user['role'] === 'tvl_head' && $action !== 'list') { http_response_code(403); exit('TVL Head access is view-only.'); }

// Process new delivery form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    if ($user['role'] !== 'supply_officer') {
        die("Only the Supply Officer can add deliveries.");
    }

    $po_number = trim($_POST['po_number'] ?? '');
    $dr_number = trim($_POST['dr_number'] ?? '');
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $date_received = trim($_POST['date_received'] ?? date('Y-m-d'));
    
    // Safely grab the user ID whether your login script called it 'id' or 'user_id'
    $active_user_id = $user['id'] ?? $user['user_id'];

    if (empty($supplier_name) || empty($date_received)) {
        die("Supplier Name and Date Received are required.");
    }

    // Permanent document number, reserved transactionally for the actual received date.
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_received);
    if (!$date || $date->format('Y-m-d') !== $date_received) {
        die('Invalid Date Received.');
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $year = (int)$date->format('Y');
        $seed = $pdo->prepare('INSERT INTO receiving_number_sequences (receive_year, last_number) VALUES (?,0) ON DUPLICATE KEY UPDATE receive_year=receive_year');
        $seed->execute([$year]);
        $lock = $pdo->prepare('SELECT last_number FROM receiving_number_sequences WHERE receive_year=? FOR UPDATE');
        $lock->execute([$year]);
        $next = (int)$lock->fetchColumn() + 1;
        $number = sprintf('REC-%04d-%03d', $year, $next);
        $pdo->prepare('UPDATE receiving_number_sequences SET last_number=? WHERE receive_year=?')->execute([$next,$year]);
        $stmt = $pdo->prepare("INSERT INTO deliveries (receiving_number, po_number, dr_number, supplier_name, date_received, received_by, status)
                               VALUES (?, ?, ?, ?, ?, ?, 'pending_inspection')");
        $stmt->execute([$number, $po_number, $dr_number, $supplier_name, $date_received, $active_user_id]);
        $delivery_id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Created Delivery', 'Receiving', ?, ?)")
            ->execute([$active_user_id, $delivery_id, 'Receiving '.$number.' from '.$supplier_name]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Receiving create failed: '.$error->getMessage());
        http_response_code(500);
        die('Could not save delivery. Verify the Phase 2.1 database migration is installed and try again.');
    }

    // Redirect to the view page where they will add individual items to this delivery
    header("Location: delivery_view.php?id=" . $delivery_id);
    exit;
}

// Set up page layout variables
$pageTitle = $action === 'add' ? 'New Delivery' : 'Receiving & Deliveries';
$active = 'receiving'; 
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header">
    <h2><?= e($pageTitle) ?></h2>
    <?php if ($action === 'list' && $user['role'] === 'supply_officer'): ?>
        <a href="?action=add" class="btn btn-primary legacy-053">+ Log New Delivery</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
    <div class="card legacy-054">
        <table class="legacy-031 js-sortable-table">
            <thead>
                <tr class="legacy-055">
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Record No.: default order. Activate for ascending." title="Sort Record No.: default order" aria-pressed="false"><span class="tnts-sort-title">Record No.</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Supplier: default order. Activate for ascending." title="Sort Supplier: default order" aria-pressed="false"><span class="tnts-sort-title">Supplier</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Date Received: default order. Activate for ascending." title="Sort Date Received: default order" aria-pressed="false"><span class="tnts-sort-title">Date Received</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort PO / DR #: default order. Activate for ascending." title="Sort PO / DR #: default order" aria-pressed="false"><span class="tnts-sort-title">PO / DR #</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Status: default order. Activate for ascending." title="Sort Status: default order" aria-pressed="false"><span class="tnts-sort-title">Status</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all deliveries, newest first
                $stmt = db()->query("
                    SELECT d.*, u.full_name as receiver_name 
                    FROM deliveries d 
                    JOIN users u ON d.received_by = u.user_id 
                    ORDER BY d.created_at DESC
                ");
                $deliveries = $stmt->fetchAll();

                if (empty($deliveries)): ?>
                    <tr>
                        <td colspan="6" class="legacy-035">No deliveries logged yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deliveries as $d): ?>
                        <tr class="legacy-036">
                            <td class="legacy-033"><?= e($d['receiving_number']) ?></td>
                            <td class="legacy-033"><strong><?= e($d['supplier_name']) ?></strong></td>
                            <td class="legacy-033"><?= e(date('M d, Y', strtotime($d['date_received']))) ?></td>
                            <td class="legacy-033">
                                PO: <?= e($d['po_number'] ?: 'N/A') ?><br>
                                DR: <?= e($d['dr_number'] ?: 'N/A') ?>
                            </td>
                            <td class="legacy-033">
                                <?php
                                    $badgeColor = '#f59e0b'; // yellow for pending
                                    if ($d['status'] === 'accepted') $badgeColor = '#10b981'; // green
                                    if ($d['status'] === 'partially_accepted') $badgeColor = '#3b82f6'; // blue
                                ?>
                                <span class="legacy-056 status-badge status-<?= e($d['status']) ?>">
                                    <?= e(str_replace('_', ' ', $d['status'])) ?>
                                </span>
                            </td>
                            <td class="legacy-033">
                                <a href="delivery_view.php?id=<?= $d['delivery_id'] ?>" class="legacy-057">View / Inspect &rarr;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($action === 'add' && $user['role'] === 'supply_officer'): ?>
    <div class="card legacy-058">
        <form method="POST" action="?action=add">
            <div class="legacy-045">
                <label class="legacy-059">Supplier Name *</label>
                <input type="text" name="supplier_name" required class="legacy-047">
            </div>
            
            <div class="legacy-045">
                <label class="legacy-059">Date Received *</label>
                <input type="date" name="date_received" value="<?= date('Y-m-d') ?>" required class="legacy-047">
            </div>

            <div class="legacy-060">
                <div class="legacy-049">
                    <label class="legacy-059">PO Number (Optional)</label>
                    <input type="text" name="po_number" placeholder="e.g. PO-2026-001" class="legacy-047">
                </div>
                <div class="legacy-049">
                    <label class="legacy-059">DR Number (Optional)</label>
                    <input type="text" name="dr_number" placeholder="Delivery Receipt #" class="legacy-047">
                </div>
            </div>

            <div class="legacy-061">
                <button type="submit" class="legacy-062">Save & Add Items</button>
                <a href="receiving.php" class="legacy-063">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>