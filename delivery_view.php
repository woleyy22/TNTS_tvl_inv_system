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

<div class="content-header legacy-026">
    <h2>Delivery #<?= e($delivery_id) ?> - <?= e($delivery['supplier_name']) ?></h2>
    <a href="receiving.php" class="legacy-155">&larr; Back to List</a>
</div>

<div class="legacy-156">
    <!-- Left Column: Details & Items -->
    <div class="legacy-157">
        <div class="card legacy-158">
            <h3 class="legacy-159">Delivery Details</h3>
            <p><strong>Date Received:</strong> <?= e(date('M d, Y', strtotime($delivery['date_received']))) ?></p>
            <p><strong>PO Number:</strong> <?= e($delivery['po_number'] ?: 'N/A') ?></p>
            <p><strong>DR Number:</strong> <?= e($delivery['dr_number'] ?: 'N/A') ?></p>
            <p><strong>Status:</strong> <span class="legacy-160 <?= $delivery['status'] === 'accepted' ? 'text-success' : 'text-warning' ?>"><?= e(str_replace('_', ' ', $delivery['status'])) ?></span></p>
        </div>

        <div class="card legacy-030">
            <h3 class="legacy-159">Items in this Delivery</h3>
            
            <?php if ($delivery['status'] === 'pending_inspection' && $user['role'] === 'supply_officer'): ?>
                <form method="POST" class="legacy-161">
                    <h4 class="legacy-159">+ Add Item Line</h4>
                    <div class="legacy-162">
                        <input type="text" name="item_name" placeholder="Item Name *" required class="legacy-163">
                        <input type="text" name="description" placeholder="Description / Specs" class="legacy-163">
                        <input type="text" name="unit" placeholder="Unit (e.g. pcs, set) *" required class="legacy-164">
                    </div>
                    <div class="legacy-061">
                        <input type="number" name="qty_delivered" placeholder="Qty Delivered *" required min="1" class="legacy-164">
                        <input type="number" step="0.01" name="unit_cost" placeholder="Unit Cost" required min="0" class="legacy-164">
                        <button type="submit" name="add_item" class="legacy-165">Add Item</button>
                    </div>
                </form>
            <?php endif; ?>

            <table class="legacy-031">
                <tr class="legacy-055">
                    <th class="legacy-166">Item</th>
                    <th class="legacy-166">Unit</th>
                    <th class="legacy-166">Qty Delivered</th>
                    <th class="legacy-167">Qty Accepted</th>
                    <th class="legacy-166">Cost</th>
                </tr>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" class="legacy-168">No items added yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr class="legacy-036">
                            <td class="legacy-166">
                                <strong><?= e($item['item_name']) ?></strong><br>
                                <small class="legacy-037"><?= e($item['description']) ?></small>
                            </td>
                            <td class="legacy-166"><?= e($item['unit']) ?></td>
                            <td class="legacy-166"><?= e($item['qty_delivered']) ?></td>
                            <td class="legacy-169 <?= $item['qty_accepted'] > 0 ? 'text-success' : 'muted' ?>">
                                <?= e($item['qty_accepted']) ?>
                            </td>
                            <td class="legacy-166">₱<?= e(number_format($item['unit_cost'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
            
            <?php if (!empty($items) && $delivery['status'] === 'pending_inspection'): ?>
                <div class="legacy-170">
                    <a href="inspection.php?id=<?= $delivery_id ?>" class="legacy-171">Proceed to Inspection (IAR) &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Attachments -->
    <div class="legacy-172">
        <div class="card legacy-030">
            <h3 class="legacy-159">Attachments (DR Photos)</h3>
            
            <!-- Removed the status check so you can upload anytime -->
            <form method="POST" enctype="multipart/form-data" class="legacy-045">
                <input type="file" name="dr_file" accept=".jpg,.jpeg,.png,.pdf" required class="legacy-173">
                <button type="submit" name="upload_attachment" class="legacy-174">Upload Receipt</button>
            </form>

            <?php if (empty($attachments)): ?>
                <p class="legacy-175">No attachments yet.</p>
            <?php else: ?>
                <ul class="legacy-176">
                <?php foreach ($attachments as $att): ?>
                    <li class="legacy-177">
                        <a href="<?= e(url($att['file_path'])) ?>" target="_blank" class="legacy-178">
                            <?= e($att['original_filename']) ?>
                        </a>
                        <br><small class="legacy-179">Uploaded: <?= e(date('M d, Y', strtotime($att['uploaded_at']))) ?></small>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>