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

    $stmt = db()->prepare("
        INSERT INTO deliveries (po_number, dr_number, supplier_name, date_received, received_by, status) 
        VALUES (?, ?, ?, ?, ?, 'pending_inspection')
    ");
    $stmt->execute([$po_number, $dr_number, $supplier_name, $date_received, $active_user_id]);
    
    $delivery_id = db()->lastInsertId();

    // Log the action
    db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Created Delivery', 'Receiving', ?, ?)")
        ->execute([$active_user_id, $delivery_id, "Logged new delivery from $supplier_name"]);

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
        <table class="legacy-031">
            <thead>
                <tr class="legacy-055">
                    <th class="legacy-033">ID</th>
                    <th class="legacy-033">Supplier</th>
                    <th class="legacy-033">Date Received</th>
                    <th class="legacy-033">PO / DR #</th>
                    <th class="legacy-033">Status</th>
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
                            <td class="legacy-033">#<?= e($d['delivery_id']) ?></td>
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