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
    $ics_number = trim($_POST['ics_number'] ?? '');
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

    db()->beginTransaction();
    try {
        // 1. Create the master ICS record
        $stmt = db()->prepare("INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$ics_number, $teacher_id, $course_id, $active_user_id, $date_issued]);
        $ics_id = db()->lastInsertId();
        
        // 2. Add the selected items to this ICS
        $insert_item = db()->prepare("INSERT INTO ics_items (ics_id, item_id, qty_issued, item_condition) VALUES (?, ?, ?, 'serviceable')");
        foreach ($items_to_issue as $item_id => $qty) {
            $insert_item->execute([$ics_id, $item_id, $qty]);
        }
        
        // 3. Log the action
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Issued ICS', 'ICS', ?, ?)")
            ->execute([$active_user_id, $ics_id, "Issued ICS #$ics_number to Teacher ID $teacher_id"]);
            
        db()->commit();
        
        // Temporarily redirecting back to inventory. 
        // Later, we will redirect this to an ICS PDF Generation page.
        header("Location: inventory.php?success=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        die("Error issuing ICS: " . $e->getMessage());
    }
}

$pageTitle = 'Issue ICS';
$active = 'ics';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="margin-bottom: 20px;">
    <h2>Issue Inventory Custodian Slip (ICS)</h2>
    <p style="color: #666;">Assign available inventory items to a specific teacher and course.</p>
</div>

<form method="POST" class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 900px;">
    
    <div style="display: flex; gap: 20px; margin-bottom: 20px; background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid #eee;">
        <div style="flex: 1;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px;">ICS Number *</label>
            <input type="text" name="ics_number" placeholder="e.g. ICS-2026-001" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>
        <div style="flex: 1;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Date Issued *</label>
            <input type="date" name="date_issued" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>
    </div>

    <div style="display: flex; gap: 20px; margin-bottom: 30px;">
        <div style="flex: 1;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Assign To Teacher *</label>
            <select name="teacher_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">-- Select Teacher --</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px;">For Course / Subject *</label>
            <select name="course_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">-- Select Course --</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['course_id'] ?>"><?= e($c['course_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 style="border-bottom: 2px solid #eee; padding-bottom: 10px;">Available Items in Supply Office</h3>
    <table style="width: 100%; border-collapse: collapse; text-align: left; margin-bottom: 20px;">
        <tr style="background: #f9fafb;">
            <th style="padding: 10px; border-bottom: 1px solid #ddd;">Item Name</th>
            <th style="padding: 10px; border-bottom: 1px solid #ddd;">Type</th>
            <th style="padding: 10px; border-bottom: 1px solid #ddd;">Available Stock</th>
            <th style="padding: 10px; border-bottom: 1px solid #ddd; width: 120px;">Qty to Issue</th>
        </tr>
        <?php if (empty($inventory)): ?>
            <tr><td colspan="4" style="padding: 20px; text-align: center; color: #666;">No unissued items available in the supply office.</td></tr>
        <?php else: ?>
            <?php foreach ($inventory as $item): ?>
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <strong><?= e($item['item_name']) ?></strong><br>
                        <small style="color: #666;">₱<?= e(number_format($item['unit_cost'], 2)) ?> / <?= e($item['unit']) ?></small>
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;"><?= e(ucfirst($item['item_type'])) ?></td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; color: #10b981; font-weight: bold;">
                        <?= e($item['available_qty']) ?> <?= e($item['unit']) ?>
                    </td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <input type="number" name="issue_qty[<?= $item['item_id'] ?>]" 
                               max="<?= $item['available_qty'] ?>" min="0" value="0"
                               style="width: 80px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;">
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <button type="submit" style="padding: 12px 24px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1.1em;">Issue ICS to Teacher</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>