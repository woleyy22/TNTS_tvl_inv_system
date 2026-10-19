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
// All inventory is listed for clarity. Only serviceable central quantity may be issued.
$stockSql = "SELECT i.*,
 COALESCE((SELECT SUM(w.quantity) FROM inventory_withdrawals w WHERE w.item_id=i.item_id),0) withdrawn,
 COALESCE((SELECT SUM(ii.qty_issued) FROM ics_items ii WHERE ii.item_id=i.item_id),0) gross_issued,
 COALESCE((SELECT SUM(ii.qty_issued-ii.qty_returned) FROM ics_items ii WHERE ii.item_id=i.item_id),0) active_balance,
 COALESCE((SELECT SUM(x.quantity) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id WHERE ii.item_id=i.item_id AND x.status='approved' AND x.outcome='disposed'),0) disposed,
 COALESCE((SELECT SUM(rl.qty_for_repair) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) returned_repair,
 COALESCE((SELECT SUM(rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) returned_damaged,
 COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='for_repair'),0) restored_repair,
 COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='damaged'),0) restored_damaged,
 COALESCE((SELECT SUM(z.quantity) FROM central_condition_disposals z WHERE z.item_id=i.item_id),0) central_disposed
 FROM inventory_items i";
$inventory = db()->query($stockSql . ' ORDER BY i.item_name, i.item_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($inventory as &$item) {
    $supply = in_array($item['item_category'], ['consumable','expiring_material'], true);
    $item['central_repair'] = max(0, (int)$item['returned_repair']-(int)$item['restored_repair']);
    $item['central_damaged'] = max(0, (int)$item['returned_damaged']-(int)$item['restored_damaged']-(int)$item['central_disposed']);
    $item['available_qty'] = max(0, (int)$item['quantity']-(int)$item['withdrawn'] - ($supply?(int)$item['gross_issued']:((int)$item['active_balance']+(int)$item['central_repair']+(int)$item['central_damaged']+(int)$item['central_disposed'])));
}
unset($item);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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
    // Save each item category before inserting ICS lines. Do not run extra
    // queries between an INSERT and reading its auto-increment ID.
    $categoryByItem = [];
    // Validate each requested item quantity against live unissued stock
    foreach ($items_to_issue as $item_id => $qty_to_issue) {
        $stmt_check = db()->prepare($stockSql . ' WHERE i.item_id=? FOR UPDATE');
        $stmt_check->execute([$item_id]); $stock=$stmt_check->fetch(PDO::FETCH_ASSOC);
        if (!$stock) throw new Exception('Invalid inventory item selected.');
        $categoryByItem[(int)$item_id] = (string)$stock['item_category'];
        $supply = in_array($stock['item_category'], ['consumable','expiring_material'], true);
        $centralRepair=max(0,(int)$stock['returned_repair']-(int)$stock['restored_repair']);
        $centralDamaged=max(0,(int)$stock['returned_damaged']-(int)$stock['restored_damaged']-(int)$stock['central_disposed']);
        $used=$supply?(int)$stock['gross_issued']:((int)$stock['active_balance']+$centralRepair+$centralDamaged+(int)$stock['central_disposed']);
        $available_qty=max(0,(int)$stock['quantity']-(int)$stock['withdrawn']-$used);
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
    $newBalance=db()->prepare('INSERT INTO ics_condition_balances (ics_item_id,qty_serviceable,qty_for_repair,qty_damaged,needs_review) VALUES (?, ?, 0, 0, 0)');
    foreach ($items_to_issue as $item_id => $qty_to_issue) {
        $insert_item->execute([$ics_id, $item_id, $qty_to_issue]);
        // The FK must point to THIS newly inserted ICS line, not to a stale
        // lastInsertId() value from a later statement (or another line).
        $newIcsItemId = (int) db()->lastInsertId();
        if ($newIcsItemId <= 0) {
            throw new RuntimeException('Could not identify the new ICS item. Issuance has been canceled safely.');
        }
        if (in_array($categoryByItem[(int)$item_id], ['equipment','non_consumable'], true)) {
            $newBalance->execute([$newIcsItemId, $qty_to_issue]);
        }
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

<div class="card" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px"><a class="legacy-028" href="<?= e(url('ics_issue.php')) ?>">Issue ICS</a><a href="<?= e(url('ics_returns.php')) ?>" class="legacy-028">Retrieve / Close ICS</a></div>
<div class="content-header legacy-050">
    <h2>Issue Inventory Custodian Slip (ICS)</h2>
    <p class="legacy-037">Assign available inventory items to a specific teacher and course.</p>
</div>

<?php if (!empty($_GET['error'])): ?><div class="legacy-181" role="alert"><?= e($_GET['error']) ?></div><?php endif; ?>
<form method="POST" class="card legacy-148">
    <?= csrf_field() ?>
    
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
            <input type="search" id="p37TeacherSearch" placeholder="Search teacher name..." autocomplete="off" class="legacy-047" aria-label="Search teachers" style="margin-bottom:7px">
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

    <h3 class="legacy-151">Issue from Serviceable Central Stock</h3>
    <p style="color:#64748b;font-size:13px">Only serviceable central inventory can be issued. For Repair and Damaged quantities are shown for reference and require a verified restoration first.</p>
    <div class="p36-filters">
      <label>Search<input type="search" id="p36Search" placeholder="Item or category"></label>
      <label>Show<select id="p36Filter"><option value="all">All items</option><option value="available">Available to issue</option><option value="repair">Central For Repair</option><option value="damaged">Central Damaged</option></select></label>
      <label>Sort<select id="p36Sort"><option value="name_asc">Name A–Z</option><option value="name_desc">Name Z–A</option><option value="qty_desc">Available high–low</option><option value="qty_asc">Available low–high</option></select></label>
    </div>
    <div style="overflow-x:auto"><table class="legacy-068" id="p36Items">
        <thead><tr class="legacy-069"><th class="legacy-070">Item Name</th><th class="legacy-070">Category</th><th class="legacy-070">Available for ICS</th><th class="legacy-070">Central For Repair / Damaged</th><th class="legacy-152">Qty to Issue</th></tr></thead>
        <tbody>
        <?php foreach ($inventory as $item): ?>
          <tr class="p36-item" data-name="<?= e(mb_strtolower($item['item_name'])) ?>" data-cat="<?= e(mb_strtolower($item['item_category'])) ?>" data-qty="<?= (int)$item['available_qty'] ?>" data-repair="<?= (int)$item['central_repair'] ?>" data-damaged="<?= (int)$item['central_damaged'] ?>">
            <td class="legacy-072"><strong><?= e($item['item_name']) ?></strong><br><small class="legacy-037"><?= e($item['unit']) ?></small></td>
            <td class="legacy-072"><?= e(ucwords(str_replace('_',' ',$item['item_category']))) ?></td>
            <td class="legacy-153"><strong style="color:#167349"><?= (int)$item['available_qty'] ?></strong> <?= e($item['unit']) ?></td>
            <td class="legacy-072"><span class="p36-repair"><?= (int)$item['central_repair'] ?> repair</span> <span class="p36-damaged"><?= (int)$item['central_damaged'] ?> damaged</span></td>
            <td class="legacy-072"><input type="number" name="issue_qty[<?= (int)$item['item_id'] ?>]" max="<?= (int)$item['available_qty'] ?>" min="0" step="1" value="0" <?= (int)$item['available_qty']===0?'disabled':'' ?> class="legacy-073" aria-label="Quantity of <?= e($item['item_name']) ?> to issue"></td>
          </tr>
        <?php endforeach; ?>
        <tr id="p36Empty" hidden><td colspan="5">No items match this filter.</td></tr>
        </tbody>
    </table></div>
    <button type="submit" class="legacy-154">Issue ICS to Teacher</button>
</form>

<style>.p36-filters{display:flex;flex-wrap:wrap;gap:12px;margin:14px 0}.p36-filters label{display:grid;gap:5px;color:#475569;font-size:12px}.p36-filters input,.p36-filters select{padding:9px;border:1px solid #d7dce5;border-radius:7px;min-width:160px;background:#fff}.p36-repair,.p36-damaged{display:inline-block;border-radius:99px;padding:4px 8px;font-size:12px;margin:2px}.p36-repair{background:#fff1d5;color:#925508}.p36-damaged{background:#ffe5e5;color:#a22222}</style>
<script>
const p36Rows=[...document.querySelectorAll('#p36Items .p36-item')];
function p36Refresh(){const q=document.querySelector('#p36Search').value.toLowerCase(), f=document.querySelector('#p36Filter').value, sort=document.querySelector('#p36Sort').value, body=document.querySelector('#p36Items tbody');let n=0;
 p36Rows.sort((a,b)=>sort==='name_asc'?a.dataset.name.localeCompare(b.dataset.name):sort==='name_desc'?b.dataset.name.localeCompare(a.dataset.name):sort==='qty_desc'?Number(b.dataset.qty)-Number(a.dataset.qty):Number(a.dataset.qty)-Number(b.dataset.qty));
 for(const row of p36Rows){const ok=(row.dataset.name.includes(q)||row.dataset.cat.includes(q))&&(f==='all'||(f==='available'&&Number(row.dataset.qty)>0)||(f==='repair'&&Number(row.dataset.repair)>0)||(f==='damaged'&&Number(row.dataset.damaged)>0));row.hidden=!ok;if(ok)n++;body.appendChild(row);}document.querySelector('#p36Empty').hidden=n!==0;}
['p36Search','p36Filter','p36Sort'].forEach(id=>document.getElementById(id).addEventListener('input',p36Refresh));p36Refresh();
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
const p37TeacherSearch=document.getElementById('p37TeacherSearch');p37TeacherSearch.addEventListener('input',()=>{const needle=p37TeacherSearch.value.trim().toLocaleLowerCase();for(const opt of icsTeacher.options){if(!opt.value)continue;opt.hidden=!opt.textContent.toLocaleLowerCase().includes(needle);}if(icsTeacher.selectedOptions[0]?.hidden){icsTeacher.value='';refreshIcsCourses();}});
refreshIcsCourses();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>