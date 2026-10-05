<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Must be logged in and not a teacher
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
if ($user['role'] === 'teacher') {
    header('Location: dashboard.php');
    exit;
}

$delivery_id = (int)($_GET['id'] ?? 0);
if (!$delivery_id) {
    die("Invalid delivery ID.");
}

// Fetch the current delivery record
$stmt = db()->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
$stmt->execute([$delivery_id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found.");
}

// Safely grab the user ID
$active_user_id = $user['id'] ?? $user['user_id'];

// Handle Add Item form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    if ($user['role'] !== 'supply_officer') die("Unauthorized.");
    
    $item_name = trim($_POST['item_name']);
    $description = trim($_POST['description']);
    $unit = trim($_POST['unit']);
    $qty_delivered = (int)$_POST['qty_delivered'];
    $unit_cost = (float)$_POST['unit_cost'];
    
    if ($item_name && $qty_delivered > 0) {
        $stmt = db()->prepare("
            INSERT INTO delivery_items 
            (delivery_id, item_name, description, unit, qty_delivered, qty_accepted, unit_cost) 
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$delivery_id, $item_name, $description, $unit, $qty_delivered, $unit_cost]);
        
        // Log action (using $active_user_id)
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Added Item', 'Receiving', ?, ?)")
            ->execute([$active_user_id, $delivery_id, "Added item $item_name to Delivery #$delivery_id"]);
            
        // Refresh the page
        header("Location: delivery_view.php?id=" . $delivery_id);
        exit;
    }
}

// Handle Upload Attachment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_attachment'])) {
    if (isset($_FILES['dr_file']) && $_FILES['dr_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $tmp_name = $_FILES['dr_file']['tmp_name'];
        $orig_name = basename($_FILES['dr_file']['name']);
        
        // Basic security check for file type
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($ext, $allowed)) {
            $new_name = 'dr_' . $delivery_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                // Fixed: using $active_user_id here
                $stmt = db()->prepare("INSERT INTO attachments (record_type, record_id, file_path, original_filename, uploaded_by) VALUES ('delivery', ?, ?, ?, ?)");
                $stmt->execute([$delivery_id, 'uploads/' . $new_name, $orig_name, $active_user_id]);
                
                header("Location: delivery_view.php?id=" . $delivery_id);
                exit;
            }
        }
    }
}
// Fetch Items for this delivery
$stmt = db()->prepare("SELECT * FROM delivery_items WHERE delivery_id = ?");
$stmt->execute([$delivery_id]);
$items = $stmt->fetchAll();

// Fetch Attachments for this delivery
$stmt = db()->prepare("SELECT * FROM attachments WHERE record_type = 'delivery' AND record_id = ?");
$stmt->execute([$delivery_id]);
$attachments = $stmt->fetchAll();

$pageTitle = 'View Delivery #' . $delivery_id;
$active = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Delivery #<?= e($delivery_id) ?> - <?= e($delivery['supplier_name']) ?></h2>
    <a href="receiving.php" style="padding: 8px 16px; background: #6b7280; color: white; text-decoration: none; border-radius: 4px;">&larr; Back to List</a>
</div>

<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <!-- Left Column: Details & Items -->
    <div style="flex: 2; min-width: 60%;">
        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Delivery Details</h3>
            <p><strong>Date Received:</strong> <?= e(date('M d, Y', strtotime($delivery['date_received']))) ?></p>
            <p><strong>PO Number:</strong> <?= e($delivery['po_number'] ?: 'N/A') ?></p>
            <p><strong>DR Number:</strong> <?= e($delivery['dr_number'] ?: 'N/A') ?></p>
            <p><strong>Status:</strong> <span style="text-transform: uppercase; font-weight: bold; color: <?= $delivery['status'] === 'accepted' ? '#10b981' : '#f59e0b' ?>;"><?= e(str_replace('_', ' ', $delivery['status'])) ?></span></p>
        </div>

        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0;">Items in this Delivery</h3>
            
            <?php if ($delivery['status'] === 'pending_inspection' && $user['role'] === 'supply_officer'): ?>
                <form method="POST" style="margin-bottom: 20px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb;">
                    <h4 style="margin-top: 0;">+ Add Item Line</h4>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                        <input type="text" name="item_name" placeholder="Item Name *" required style="flex: 2; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <input type="text" name="description" placeholder="Description / Specs" style="flex: 2; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <input type="text" name="unit" placeholder="Unit (e.g. pcs, set) *" required style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <input type="number" name="qty_delivered" placeholder="Qty Delivered *" required min="1" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <input type="number" step="0.01" name="unit_cost" placeholder="Unit Cost" required min="0" style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <button type="submit" name="add_item" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Item</button>
                    </div>
                </form>
            <?php endif; ?>

            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <tr style="border-bottom: 2px solid #eee;">
                    <th style="padding: 10px;">Item</th>
                    <th style="padding: 10px;">Unit</th>
                    <th style="padding: 10px;">Qty Delivered</th>
                    <th style="padding: 10px; color: #10b981;">Qty Accepted</th>
                    <th style="padding: 10px;">Cost</th>
                </tr>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" style="padding: 10px; text-align: center; color: #666;">No items added yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;">
                                <strong><?= e($item['item_name']) ?></strong><br>
                                <small style="color: #666;"><?= e($item['description']) ?></small>
                            </td>
                            <td style="padding: 10px;"><?= e($item['unit']) ?></td>
                            <td style="padding: 10px;"><?= e($item['qty_delivered']) ?></td>
                            <td style="padding: 10px; font-weight: bold; color: <?= $item['qty_accepted'] > 0 ? '#10b981' : '#666' ?>;">
                                <?= e($item['qty_accepted']) ?>
                            </td>
                            <td style="padding: 10px;">₱<?= e(number_format($item['unit_cost'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
            
            <?php if (!empty($items) && $delivery['status'] === 'pending_inspection'): ?>
                <div style="margin-top: 20px;">
                    <a href="inspection.php?id=<?= $delivery_id ?>" style="padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 4px; display: inline-block;">Proceed to Inspection (IAR) &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Attachments -->
    <div style="flex: 1; min-width: 30%;">
        <div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0;">Attachments (DR Photos)</h3>
            
            <!-- Removed the status check so you can upload anytime -->
            <form method="POST" enctype="multipart/form-data" style="margin-bottom: 15px;">
                <input type="file" name="dr_file" accept=".jpg,.jpeg,.png,.pdf" required style="margin-bottom: 10px; width: 100%;">
                <button type="submit" name="upload_attachment" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; width: 100%;">Upload Receipt</button>
            </form>

            <?php if (empty($attachments)): ?>
                <p style="color: #666; font-size: 0.9em;">No attachments yet.</p>
            <?php else: ?>
                <ul style="list-style: none; padding: 0;">
                <?php foreach ($attachments as $att): ?>
                    <li style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                        <a href="<?= e(url($att['file_path'])) ?>" target="_blank" style="color: #800000; text-decoration: none; font-weight: bold;">
                            <?= e($att['original_filename']) ?>
                        </a>
                        <br><small style="color: #999;">Uploaded: <?= e(date('M d, Y', strtotime($att['uploaded_at']))) ?></small>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>