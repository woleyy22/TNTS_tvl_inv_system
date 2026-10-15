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

// Teacher-to-course assignments drive both the UI and server validation.
$teacher_course_rows = db()->query("SELECT tc.user_id, tc.course_id FROM teacher_courses tc JOIN users u ON u.user_id = tc.user_id WHERE u.role = 'teacher' AND u.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
// Fetch available inventory (items with unissued stock > 0)
$stmt = db()->query("
    SELECT i.*, 
           (i.quantity - COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON ii.ics_item_id = co.ics_item_id WHERE ii.item_id = i.item_id AND co.status = 'approved' AND co.outcome = 'disposed'), 0) - COALESCE((SELECT SUM(ii.qty_issued - COALESCE((SELECT SUM(co2.quantity) FROM condemnations co2 WHERE co2.ics_item_id = ii.ics_item_id AND co2.status = 'approved' AND co2.outcome = 'disposed'),0)) FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active'), 0)) as available_qty
    FROM inventory_items i
    HAVING available_qty > 0
    ORDER BY i.item_name
");
$inventory = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
    $active_user_id = $user['id'] ?? $user['user_id'];
    
    if (!$teacher_id || !$course_id) {
        header('Location: ics_issue.php?error=' . urlencode('Select an active teacher and one of their assigned courses.')); exit;
    }
    $assignment = db()->prepare("SELECT 1 FROM teacher_courses tc JOIN users u ON u.user_id=tc.user_id WHERE tc.user_id=? AND tc.course_id=? AND u.role='teacher' AND u.status='active'");
    $assignment->execute([$teacher_id, $course_id]);
    if (!$assignment->fetchColumn()) {
        header('Location: ics_issue.php?error=' . urlencode('This course is not assigned to the selected active teacher.')); exit;
    }
    
    // Check if at least one item was selected
    $items_to_issue = [];
    foreach (($_POST['issue_qty'] ?? []) as $item_id => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $items_to_issue[$item_id] = $qty;
        }
    }
    
    if (empty($items_to_issue)) {
        header('Location: ics_issue.php?error=' . urlencode('Select at least one item and enter a quantity.')); exit;
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
                             WHERE ii.item_id = i.item_id AND c.status = 'active'), 0) AS total_issued,
                   COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON ii.ics_item_id=co.ics_item_id WHERE ii.item_id=i.item_id AND co.status='approved' AND co.outcome='disposed'),0) AS disposed_all,
                   COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON ii.ics_item_id=co.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=i.item_id AND c.status='active' AND co.status='approved' AND co.outcome='disposed'),0) AS disposed_active
            FROM inventory_items i
            WHERE i.item_id = ?
            FOR UPDATE
        ");
        $stmt_check->execute([$item_id]);
        $stock = $stmt_check->fetch();

        if (!$stock) {
            throw new Exception("Invalid inventory item selected.");
        }

        $available_qty = $stock['quantity'] - $stock['disposed_all'] - ($stock['total_issued'] - $stock['disposed_active']);
        if ($qty_to_issue > $available_qty) {
            throw new Exception("Cannot issue {$qty_to_issue} unit(s) of '{$stock['item_name']}'. Only {$available_qty} unit(s) available in central inventory.");
        }
    }

    // Reserve a unique number in the same transaction as the ICS and its items.
    $ics_number = generate_next_ics_number($date_issued);

    // Insert master ICS record
    $stmt_ics = db()->prepare("INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status) VALUES (?, ?, ?, ?, ?, 'active')");
    $stmt_ics->execute([$ics_number, $teacher_id, $course_id, $active_user_id, $date_issued]);
    $ics_id = db()->lastInsertId();

    // Insert line items
    $insert_item = db()->prepare("INSERT INTO ics_items (ics_id, item_id, qty_issued, item_condition) VALUES (?, ?, ?, 'serviceable')");
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

<?php if (!empty($_GET['error'])): ?><div class="legacy-181" role="alert"><?= e($_GET['error']) ?></div><?php endif; ?>
<form method="POST" class="card legacy-148">
    
    <div class="legacy-149">
        <div class="legacy-049">
            <label class="legacy-046">ICS Number</label>
            <div class="legacy-047">Automatically generated when the ICS is issued</div>
        </div>
        <div class="legacy-049">
            <label class="legacy-046">Date Issued *</label>
            <input type="date" name="date_issued" value="<?= date('Y-m-d') ?>" required class="legacy-047">
        </div>
    </div>

    <div class="legacy-150">
        <div class="legacy-049">
            <label class="legacy-046">Assign To Teacher *</label>
            <select name="teacher_id" id="ics_teacher" required class="legacy-047">
                <option value="">-- Select Teacher --</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="legacy-049">
            <label class="legacy-046">For Course / Subject *</label>
            <select name="course_id" id="ics_course" required class="legacy-047">
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
                        <small class="legacy-037"><?= e(display_unit_cost($item['unit_cost'])) ?> / <?= e($item['unit']) ?></small>
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

<script>
const icsAssignments = <?= json_encode($teacher_course_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const icsTeacher = document.getElementById('ics_teacher');
const icsCourse = document.getElementById('ics_course');
function refreshIcsCourses() {
    const teacher = Number(icsTeacher.value);
    for (const opt of icsCourse.options) {
        if (!opt.value) continue;
        opt.hidden = !icsAssignments.some(row => Number(row.user_id) === teacher && Number(row.course_id) === Number(opt.value));
        opt.disabled = opt.hidden;
    }
    if (icsCourse.selectedOptions[0]?.disabled) icsCourse.value = '';
    const eligible = [...icsCourse.options].filter(o => o.value && !o.disabled);
    if (eligible.length === 1) icsCourse.value = eligible[0].value;
}
icsTeacher.addEventListener('change', refreshIcsCourses);
refreshIcsCourses();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>