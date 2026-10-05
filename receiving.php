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
        <a href="?action=add" class="btn btn-primary" style="padding: 8px 16px; background: #800000; color: white; text-decoration: none; border-radius: 4px;">+ Log New Delivery</a>
    <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
    <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 20px;">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 2px solid #eee;">
                    <th style="padding: 12px;">ID</th>
                    <th style="padding: 12px;">Supplier</th>
                    <th style="padding: 12px;">Date Received</th>
                    <th style="padding: 12px;">PO / DR #</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px;">Action</th>
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
                        <td colspan="6" style="padding: 20px; text-align: center; color: #666;">No deliveries logged yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deliveries as $d): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">#<?= e($d['delivery_id']) ?></td>
                            <td style="padding: 12px;"><strong><?= e($d['supplier_name']) ?></strong></td>
                            <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($d['date_received']))) ?></td>
                            <td style="padding: 12px;">
                                PO: <?= e($d['po_number'] ?: 'N/A') ?><br>
                                DR: <?= e($d['dr_number'] ?: 'N/A') ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php
                                    $badgeColor = '#f59e0b'; // yellow for pending
                                    if ($d['status'] === 'accepted') $badgeColor = '#10b981'; // green
                                    if ($d['status'] === 'partially_accepted') $badgeColor = '#3b82f6'; // blue
                                ?>
                                <span style="background: <?= $badgeColor ?>; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; text-transform: uppercase;">
                                    <?= e(str_replace('_', ' ', $d['status'])) ?>
                                </span>
                            </td>
                            <td style="padding: 12px;">
                                <a href="delivery_view.php?id=<?= $d['delivery_id'] ?>" style="color: #800000; font-weight: bold; text-decoration: none;">View / Inspect &rarr;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($action === 'add' && $user['role'] === 'supply_officer'): ?>
    <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 20px; max-width: 600px;">
        <form method="POST" action="?action=add">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Supplier Name *</label>
                <input type="text" name="supplier_name" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Date Received *</label>
                <input type="date" name="date_received" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">PO Number (Optional)</label>
                    <input type="text" name="po_number" placeholder="e.g. PO-2026-001" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">DR Number (Optional)</label>
                    <input type="text" name="dr_number" placeholder="Delivery Receipt #" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" style="padding: 10px 20px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer;">Save & Add Items</button>
                <a href="receiving.php" style="padding: 10px 20px; background: #ccc; color: #333; text-decoration: none; border-radius: 4px;">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>