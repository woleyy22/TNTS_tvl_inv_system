<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || $user['role'] !== 'supply_officer') {
    die("Unauthorized access. Only the Supply Officer can issue ICS.");
}

// Fetch active teachers
$stmt = db()->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name");
$teachers = $stmt->fetchAll();

// Fetch courses
$stmt = db()->query("SELECT course_id, course_name FROM courses ORDER BY course_name");
$courses = $stmt->fetchAll();

// Fetch available inventory (items with unissued stock > 0)
$stmt = db()->query("
    SELECT i.*, 
           (i.quantity - COALESCE((SELECT SUM(qty_issued) FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active'), 0)) as available_qty
    FROM inventory_items i
    HAVING available_qty > 0
    ORDER BY i.item_name
");
$inventory = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $ics_number = trim($_POST['ics_number'] ?? '') ?: generate_document_number('ics');
    $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
    $active_user_id = $user['id'] ?? $user['user_id'];
    
    if (!$teacher_id || !$course_id || empty($ics_number)) {
        die("Teacher, Course, and ICS Number are required.");
    }
    
    // Check if at least one item was selected
    $items_to_issue = [];
    foreach ($_POST['issue_qty'] as $item_id => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $items_to_issue[$item_id] = $qty;
        }
    }
    
    if (empty($items_to_issue)) {
        die("You must issue at least one item.");
    }

    // Inside ics_issue.php POST handler
db()->beginTransaction();
try {
    // Validate each requested item quantity against live unissued stock
    foreach ($items_to_issue as $item_id => $qty_to_issue) {
        $stmt_check = db()->prepare("
            SELECT i.item_name, i.quantity,
                   COALESCE((SELECT SUM(ii.qty_issued) 
                             FROM ics_items ii 
                             JOIN ics c ON ii.ics_id = c.ics_id 
                             WHERE ii.item_id = i.item_id AND c.status = 'active'), 0) AS total_issued
            FROM inventory_items i
            WHERE i.item_id = ?
            FOR UPDATE
        ");
        $stmt_check->execute([$item_id]);
        $stock = $stmt_check->fetch();

        if (!$stock) {
            throw new Exception("Invalid inventory item selected.");
        }

        $available_qty = $stock['quantity'] - $stock['total_issued'];
        if ($qty_to_issue > $available_qty) {
            throw new Exception("Cannot issue {$qty_to_issue} unit(s) of '{$stock['item_name']}'. Only {$available_qty} unit(s) available in central inventory.");
        }
    }

    // Insert master ICS record
    $stmt_ics = db()->prepare("INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status) VALUES (?, ?, ?, ?, ?, 'active')");
    $stmt_ics->execute([$ics_number, $teacher_id, $course_id, $active_user_id, $date_issued]);
    $ics_id = db()->lastInsertId();

    // Insert line items
    $insert_item = db()->prepare("INSERT INTO ics_items (ics_id, item_id, qty_issued, condition) VALUES (?, ?, ?, 'serviceable')");
    foreach ($items_to_issue as $item_id => $qty_to_issue) {
        $insert_item->execute([$ics_id, $item_id, $qty_to_issue]);
    }

    db()->commit();
    header("Location: inventory.php?success=1");
    exit;
} catch (Exception $e) {
    db()->rollBack();
    header("Location: ics_issue.php?error=" . urlencode($e->getMessage()));
    exit;
}
}

$pageTitle = 'Issue ICS';
$active = 'ics';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-050">
    <h2>Issue Inventory Custodian Slip (ICS)</h2>
    <p class="legacy-037">Assign available inventory items to a specific teacher and course.</p>
</div>

<form method="POST" class="card legacy-148">
    
    <div class="legacy-149">
        <div class="legacy-049">
            <label class="legacy-046">ICS Number *</label>
            <input type="text" name="ics_number" placeholder="e.g. ICS-2026-001" required class="legacy-047">
        </div>
        <div class="legacy-049">
            <label class="legacy-046">Date Issued *</label>
            <input type="date" name="date_issued" value="<?= date('Y-m-d') ?>" required class="legacy-047">
        </div>
    </div>

    <div class="legacy-150">
        <div class="legacy-049">
            <label class="legacy-046">Assign To Teacher *</label>
            <select name="teacher_id" required class="legacy-047">
                <option value="">-- Select Teacher --</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="legacy-049">
            <label class="legacy-046">For Course / Subject *</label>
            <select name="course_id" required class="legacy-047">
                <option value="">-- Select Course --</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['course_id'] ?>"><?= e($c['course_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="legacy-151">Available Items in Supply Office</h3>
    <table class="legacy-068">
        <tr class="legacy-069">
            <th class="legacy-070">Item Name</th>
            <th class="legacy-070">Type</th>
            <th class="legacy-070">Available Stock</th>
            <th class="legacy-152">Qty to Issue</th>
        </tr>
        <?php if (empty($inventory)): ?>
            <tr><td colspan="4" class="legacy-035">No unissued items available in the supply office.</td></tr>
        <?php else: ?>
            <?php foreach ($inventory as $item): ?>
                <tr>
                    <td class="legacy-072">
                        <strong><?= e($item['item_name']) ?></strong><br>
                        <small class="legacy-037">₱<?= e(number_format($item['unit_cost'], 2)) ?> / <?= e($item['unit']) ?></small>
                    </td>
                    <td class="legacy-072"><?= e(ucfirst($item['item_type'])) ?></td>
                    <td class="legacy-153">
                        <?= e($item['available_qty']) ?> <?= e($item['unit']) ?>
                    </td>
                    <td class="legacy-072">
                        <input type="number" name="issue_qty[<?= $item['item_id'] ?>]" 
                               max="<?= $item['available_qty'] ?>" min="0" value="0"
                               class="legacy-073">
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <button type="submit" class="legacy-154">Issue ICS to Teacher</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>