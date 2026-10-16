<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/inventory_aggregation.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['supply_officer', 'tvl_head'], true)) {
    die("Unauthorized access. Only the Supply Officer or TVL Head can inspect deliveries.");
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

$inspection_error = '';
$existing_masters = db()->query('SELECT item_id, item_name, description, unit, item_type, quantity FROM inventory_items ORDER BY item_name, item_id')->fetchAll(PDO::FETCH_ASSOC);

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
            
            if ($qty_accepted < 0 || $qty_accepted > (int)$item['qty_delivered']) {
                throw new RuntimeException('Accepted quantity is invalid for item: ' . $item['item_name']);
            }
            if ($qty_accepted < $item['qty_delivered']) {
                $all_accepted = false;
            }
            
            // Update delivery_items with the accepted amount
            $stmt = db()->prepare("UPDATE delivery_items SET qty_accepted = ? WHERE delivery_item_id = ?");
            $stmt->execute([$qty_accepted, $item_id]);
            
            // If we accepted at least 1, push it to the main inventory_items table!
            if ($qty_accepted > 0) {
                // Classification is internal inventory metadata, NOT a required delivery field.
                // It must be chosen explicitly at inspection, never guessed from unit cost.
                $item_type = (string)($_POST['inventory_type'][$item_id] ?? '');
                if (!in_array($item_type, ['equipment', 'supply'], true)) {
                    throw new RuntimeException('Select Equipment or Supply for accepted item: ' . $item['item_name']);
                }

                // A single master item may be referenced by several accepted delivery batches.
                $masterId = aggregate_accepted_delivery_item(db(), $item, $qty_accepted, $item_type,
                    (string)($_POST['master_choice'][$item_id] ?? 'auto'));

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
        $inspection_error = $e->getMessage();
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

<?php if ($inspection_error): ?><div class="legacy-181" role="alert" style="padding:12px;margin-bottom:16px;color:#8b0000;background:#ffe3e3;border-radius:6px;"><?= e($inspection_error) ?></div><?php endif; ?>
<div class="card legacy-064">
    <form method="POST" id="inspection_form">
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

        <div class="legacy-074">Exact matches automatically join an existing inventory item. If a similar item already exists, explicitly select it or choose to create a separate item. Costs remain recorded per delivery.</div>
        <h3 class="legacy-066">Items to Accept</h3>
        <p class="legacy-067">Verify accepted quantities. Classify each accepted item for internal inventory tracking; Equipment/Supply does not need to appear on the delivery record or source Excel file.</p>

        <table class="legacy-068 tnts-inspection-table">
            <tr class="legacy-069">
                <th class="legacy-070">Item Name</th>
                <th class="legacy-070">Qty Delivered</th>
                <th class="legacy-071">Qty Accepted *</th>
                <th class="legacy-071">Inventory Classification *</th><th class="legacy-071">Inventory Matching</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="legacy-072">
                        <strong><?= e($item['item_name']) ?></strong><br>
                        <small class="legacy-037"><?= e($item['unit']) ?> | Cost: <?= e(display_unit_cost($item['unit_cost'])) ?></small>
                    </td>
                    <td class="legacy-072">
                        <?= e($item['qty_delivered']) ?>
                    </td>
                    <td class="legacy-072">
                        <input type="number" name="qty_accepted[<?= $item['delivery_item_id'] ?>]" 
                               value="<?= e($_POST['qty_accepted'][$item['delivery_item_id']] ?? $item['qty_delivered']) ?>" 
                               max="<?= e($item['qty_delivered']) ?>" min="0" required 
                               class="legacy-073">
                    </td>
                    <td class="legacy-072">
                        <select name="inventory_type[<?= (int)$item['delivery_item_id'] ?>]" class="legacy-073 inspection-type" aria-label="Inventory classification for <?= e($item['item_name']) ?>">
                            <option value="">Select for accepted items</option>
                            <option value="equipment" <?= ($_POST['inventory_type'][$item['delivery_item_id']] ?? '') === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                            <option value="supply" <?= ($_POST['inventory_type'][$item['delivery_item_id']] ?? '') === 'supply' ? 'selected' : '' ?>>Supply</option>
                        </select>
                    </td>
                    <td class="legacy-072">
                       <select name="master_choice[<?= (int)$item['delivery_item_id'] ?>]" class="legacy-073">
                         <option value="auto">Automatic match (exact only)</option>
                         <option value="new" <?= ($_POST['master_choice'][$item['delivery_item_id']] ?? '') === 'new' ? 'selected' : '' ?>>Create separate inventory item</option>
                         <?php foreach ($existing_masters as $master): ?>
                           <?php if (strcasecmp(trim((string)$master['item_name']), trim((string)$item['item_name'])) === 0 && strcasecmp(trim((string)$master['unit']), trim((string)$item['unit'])) === 0): ?>
                             <option value="<?= (int)$master['item_id'] ?>" <?= (string)($_POST['master_choice'][$item['delivery_item_id']] ?? '') === (string)$master['item_id'] ? 'selected' : '' ?>>Match #<?= (int)$master['item_id'] ?> — <?= e($master['description'] ?: 'No description') ?> (<?= e($master['item_type']) ?>)</option>
                           <?php endif; ?>
                         <?php endforeach; ?>
                       </select>
                       <small class="legacy-037">Different specs require your confirmation. Different units or classifications cannot be combined.</small>
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

<script>
const inspectionForm = document.getElementById('inspection_form');
inspectionForm.addEventListener('submit', e => {
  let invalid = false;
  inspectionForm.querySelectorAll('.inspection-type').forEach(select => {
    const row = select.closest('tr');
    const qty = Number(row.querySelector('input[name^="qty_accepted"]')?.value || 0);
    select.style.borderColor = '';
    if (qty > 0 && !select.value) { select.style.borderColor = '#b91c1c'; invalid = true; }
  });
  if (invalid) { e.preventDefault(); alert('Select Equipment or Supply for each accepted item before confirming inspection.'); }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>