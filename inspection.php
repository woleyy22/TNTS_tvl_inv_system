<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || $user['role'] !== 'supply_officer') {
    die("Unauthorized access. Only the Supply Officer can inspect deliveries.");
}

$delivery_id = (int)($_GET['id'] ?? 0);

// Fetch pending delivery
$stmt = db()->prepare("SELECT * FROM deliveries WHERE delivery_id = ? AND status = 'pending_inspection'");
$stmt->execute([$delivery_id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery not found, or it has already been inspected.");
}

// Fetch items
$stmt = db()->prepare("SELECT * FROM delivery_items WHERE delivery_id = ?");
$stmt->execute([$delivery_id]);
$items = $stmt->fetchAll();

if (empty($items)) {
    die("Cannot inspect a delivery with no items. Please go back and add items first.");
}

// Handle Inspection Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iar_number = generate_document_number('iar');
    $date_inspected = $_POST['date_inspected'] ?? date('Y-m-d');
    $result = $_POST['result'] ?? 'complete';
    $remarks = trim($_POST['remarks'] ?? '');
    $active_user_id = $user['id'] ?? $user['user_id'];
    
    // We use a database transaction to ensure everything saves successfully together
    db()->beginTransaction();
    try {
        // 1. Create the Inspection Report
        $stmt = db()->prepare("INSERT INTO inspection_reports (delivery_id, iar_number, inspected_by, date_inspected, result, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$delivery_id, $iar_number, $active_user_id, $date_inspected, $result, $remarks]);
        
        // 2. Loop through each item to accept it into INVENTORY
        $all_accepted = true;
        foreach ($items as $item) {
            $item_id = $item['delivery_item_id'];
            $qty_accepted = (int)($_POST['qty_accepted'][$item_id] ?? 0);
            
            if ($qty_accepted < $item['qty_delivered']) {
                $all_accepted = false;
            }
            
            // Update delivery_items with the accepted amount
            $stmt = db()->prepare("UPDATE delivery_items SET qty_accepted = ? WHERE delivery_item_id = ?");
            $stmt->execute([$qty_accepted, $item_id]);
            
            // If we accepted at least 1, push it to the main inventory_items table!
            if ($qty_accepted > 0) {
                // Heuristic: If it costs more than 10,000, it's equipment. Otherwise, supply.
                $item_type = ($item['unit_cost'] >= 10000) ? 'equipment' : 'supply'; 
                
                $stmt = db()->prepare("
                    INSERT INTO inventory_items (delivery_item_id, item_name, description, item_type, unit, unit_cost, quantity) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $item_id, 
                    $item['item_name'], 
                    $item['description'], 
                    $item_type,
                    $item['unit'], 
                    $item['unit_cost'], 
                    $qty_accepted
                ]);
            }
        }
        
        // 3. Update delivery status
        $new_status = $all_accepted ? 'accepted' : 'partially_accepted';
        $stmt = db()->prepare("UPDATE deliveries SET status = ? WHERE delivery_id = ?");
        $stmt->execute([$new_status, $delivery_id]);
        
        // 4. Log the action
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Inspected Delivery', 'Inspection', ?, ?)")
            ->execute([$active_user_id, $delivery_id, "IAR processed. Status updated to $new_status."]);
            
        db()->commit();
        
        // Go back to the delivery view, which will now show it as Accepted!
        header("Location: delivery_view.php?id=" . $delivery_id);
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        die("Error processing inspection: " . $e->getMessage());
    }
}

$pageTitle = 'Inspection & Acceptance (IAR)';
$active = 'receiving';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-050">
    <h2>Create Inspection & Acceptance Report (IAR)</h2>
    <p class="legacy-037">Delivery #<?= e($delivery_id) ?> - <?= e($delivery['supplier_name']) ?></p>
</div>

<div class="card legacy-064">
    <form method="POST">
        <div class="legacy-065">
            <div class="legacy-049">
                <label class="legacy-046">IAR Number (Optional)</label>
                <input type="text" name="iar_number" placeholder="e.g. IAR-2026-001" class="legacy-047">
            </div>
            <div class="legacy-049">
                <label class="legacy-046">Date Inspected *</label>
                <input type="date" name="date_inspected" value="<?= date('Y-m-d') ?>" required class="legacy-047">
            </div>
            <div class="legacy-049">
                <label class="legacy-046">Overall Result *</label>
                <select name="result" required class="legacy-047">
                    <option value="complete">Complete Delivery</option>
                    <option value="incomplete">Incomplete Delivery</option>
                </select>
            </div>
        </div>

        <h3 class="legacy-066">Items to Accept</h3>
        <p class="legacy-067">Verify the quantity accepted for each item. Accepted items will be added to the official inventory.</p>

        <table class="legacy-068">
            <tr class="legacy-069">
                <th class="legacy-070">Item Name</th>
                <th class="legacy-070">Qty Delivered</th>
                <th class="legacy-071">Qty Accepted *</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="legacy-072">
                        <strong><?= e($item['item_name']) ?></strong><br>
                        <small class="legacy-037"><?= e($item['unit']) ?> | Cost: ₱<?= e(number_format($item['unit_cost'], 2)) ?></small>
                    </td>
                    <td class="legacy-072">
                        <?= e($item['qty_delivered']) ?>
                    </td>
                    <td class="legacy-072">
                        <input type="number" name="qty_accepted[<?= $item['delivery_item_id'] ?>]" 
                               value="<?= e($item['qty_delivered']) ?>" 
                               max="<?= e($item['qty_delivered']) ?>" min="0" required 
                               class="legacy-073">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="legacy-050">
            <label class="legacy-046">Inspection Remarks / Notes (Optional)</label>
            <textarea name="remarks" rows="3" placeholder="Note any missing items, damages, or notes for the supplier here..." class="legacy-047"></textarea>
        </div>

        <div class="legacy-074">
            <strong>Signatories:</strong> This IAR will be recorded under your name (<strong><?= e($user['name'] ?? $user['full_name'] ?? 'Admin') ?></strong>). As confirmed, Mr. Arnold G. Angeles (Supply Officer) and Mr. Reynald Ace (Property Custodian) will sign the physical PDF generation of this form.
        </div>

        <button type="submit" class="legacy-075">Confirm Inspection & Add to Inventory</button>
        <a href="delivery_view.php?id=<?= $delivery_id ?>" class="legacy-076">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>