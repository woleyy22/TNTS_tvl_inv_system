<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || $user['role'] !== 'supply_officer') {
    die("Unauthorized access. Only the Supply Officer can issue ICS.");
}

// Fetch active teachers
$stmt = db()->query("SELECT u.user_id, u.full_name, COALESCE(p.teaching_position,'') AS teaching_position, COALESCE(p.position_office,'') AS position_office FROM users u LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE u.role = 'teacher' AND u.status = 'active' AND u.registration_status='approved' ORDER BY u.full_name");
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
    $course_id = null; // Optional classification; assigned later per individual ICS item.
    $date_issued = $_POST['date_issued'] ?? date('Y-m-d');
    $active_user_id = $user['id'] ?? $user['user_id'];
    
    if (!$teacher_id) {
        header('Location: ics_issue.php?error=' . urlencode('Select an active teacher.')); exit;
    }
    // Course classification is optional; do not assign the teacher's first course.

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

    // Freeze the approved recipient's official details on the historical ICS.
    $personStmt = db()->prepare("SELECT u.full_name, p.teaching_position, p.position_office FROM users u LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE u.user_id=? AND u.role='teacher' AND u.status='active' AND u.registration_status='approved'");
    $personStmt->execute([$teacher_id]);
    $person = $personStmt->fetch(PDO::FETCH_ASSOC);
    if (!$person) throw new RuntimeException('Teacher account is no longer eligible for issuance.');
    $recipientPosition = trim((string)($person['teaching_position'] ?? ''));
    $recipientOffice = trim((string)($person['position_office'] ?? ''));
    // Pre-existing accounts without official profile data keep a neutral historical label.
    $recipientLine = trim(implode(' / ', array_filter([$recipientPosition, $recipientOffice], fn($v) => $v !== '')));
    if ($recipientLine === '') $recipientLine = 'Teacher / Office not yet recorded';

    // Reserve a unique number in the same transaction as the ICS and its items.
    $ics_number = generate_next_ics_number($date_issued);

    // Insert master ICS record
    $stmt_ics = db()->prepare("INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status, recipient_name_snapshot, recipient_position_office_snapshot) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)");
    $stmt_ics->execute([$ics_number, $teacher_id, $course_id, $active_user_id, $date_issued, $person['full_name'], $recipientLine]);
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
    <p class="legacy-037">Assign serviceable equipment to an accountable teacher. The recipient’s Position / Office will be saved on the ICS.</p>
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

    <div class="p411-recipient-grid">
      <div class="legacy-049 p411-picker">
        <label class="legacy-046" for="p411TeacherInput">Assign to Teacher *</label>
        <input type="search" id="p411TeacherInput" class="legacy-047" placeholder="Search and select a teacher..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="p411TeacherSuggestions" required>
        <input type="hidden" name="teacher_id" id="ics_teacher" value="">
        <div id="p411TeacherSuggestions" class="p411-options" role="listbox" hidden></div>
        <p id="p411TeacherHelp" class="p411-help">Choose a name from the suggestions; typing alone does not select a teacher.</p>
      </div>
      <div class="legacy-049">
        <label class="legacy-046" for="p411OfficialPosition">Position / Office (ICS recipient)</label>
        <input id="p411OfficialPosition" class="legacy-047" type="text" readonly placeholder="Select a teacher to display their official Position / Office">
        <p class="p411-help">This is saved as a historical snapshot when the ICS is issued.</p>
      </div>
    </div>
    <!-- Internal course relation is retained for the existing schema and reports.
         The official ICS recipient displays Position / Office instead. -->
    <select name="course_id" id="ics_course" hidden aria-label="Optional legacy course selection">
      <option value="">-- Select Course --</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['course_id'] ?>"><?= e($c['course_name']) ?></option>
      <?php endforeach; ?>
    </select>

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

<style>.p36-filters{display:flex;flex-wrap:wrap;gap:12px;margin:14px 0}.p36-filters label{display:grid;gap:5px;color:#475569;font-size:12px}.p36-filters input,.p36-filters select{padding:9px;border:1px solid #d7dce5;border-radius:7px;min-width:160px;background:#fff}.p36-repair,.p36-damaged{display:inline-block;border-radius:99px;padding:4px 8px;font-size:12px;margin:2px}.p36-repair{background:#fff1d5;color:#925508}.p36-damaged{background:#ffe5e5;color:#a22222}.p411-recipient-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin-bottom:20px}.p411-picker{position:relative;min-width:0}.p411-picker input,.p411-recipient-grid input{width:100%;box-sizing:border-box}.p411-options{position:absolute;z-index:40;top:69px;left:0;right:0;background:#fff;border:1px solid #d9e0e9;border-radius:9px;box-shadow:0 12px 30px rgba(15,23,42,.15);max-height:265px;overflow:auto;padding:5px}.p411-option{display:flex;flex-direction:column;gap:3px;text-align:left;width:100%;border:0;border-radius:6px;background:#fff;padding:10px 11px;cursor:pointer;color:#182b47}.p411-option small{color:#64748b;font-size:11px}.p411-option:hover,.p411-option[aria-selected="true"]{background:#f7e8ed}.p411-empty{padding:12px;color:#64748b}.p411-help{font-size:11px;color:#6b7280;margin:6px 0 0}@media(max-width:750px){.p411-recipient-grid{grid-template-columns:1fr}}
</style>
<script>
const p36Rows=[...document.querySelectorAll('#p36Items .p36-item')];
function p36Refresh(){const q=document.querySelector('#p36Search').value.toLowerCase(), f=document.querySelector('#p36Filter').value, sort=document.querySelector('#p36Sort').value, body=document.querySelector('#p36Items tbody');let n=0;
 p36Rows.sort((a,b)=>sort==='name_asc'?a.dataset.name.localeCompare(b.dataset.name):sort==='name_desc'?b.dataset.name.localeCompare(a.dataset.name):sort==='qty_desc'?Number(b.dataset.qty)-Number(a.dataset.qty):Number(a.dataset.qty)-Number(b.dataset.qty));
 for(const row of p36Rows){const ok=(row.dataset.name.includes(q)||row.dataset.cat.includes(q))&&(f==='all'||(f==='available'&&Number(row.dataset.qty)>0)||(f==='repair'&&Number(row.dataset.repair)>0)||(f==='damaged'&&Number(row.dataset.damaged)>0));row.hidden=!ok;if(ok)n++;body.appendChild(row);}document.querySelector('#p36Empty').hidden=n!==0;}
['p36Search','p36Filter','p36Sort'].forEach(id=>document.getElementById(id).addEventListener('input',p36Refresh));p36Refresh();
const icsAssignments = <?= json_encode($teacher_course_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const p411Teachers = <?= json_encode(array_map(static function($t){return ['id'=>(int)$t['user_id'],'name'=>(string)$t['full_name'],'position'=>(string)$t['teaching_position'],'office'=>(string)$t['position_office']];},$teachers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const icsTeacher = document.getElementById('ics_teacher');
const icsCourse = document.getElementById('ics_course');
const p411Input = document.getElementById('p411TeacherInput');
const p411Options = document.getElementById('p411TeacherSuggestions');
const p411Official = document.getElementById('p411OfficialPosition');
let p411Matches = [], p411Active = -1;
function p411SyncCourse(id) {
  // ICS no longer requires a course; each ICS item can be classified later.
  icsCourse.value='';
}
function p411Reset(){
  icsTeacher.value=''; icsCourse.value=''; p411Official.value='';
}
function p411Select(t){
  icsTeacher.value=String(t.id);
  p411Input.value=t.name;
  p411Official.value=[t.position,t.office].filter(Boolean).join(' / ') || 'Teacher / Office not yet recorded';
  p411SyncCourse(t.id);
  p411Close();
}
function p411Close(){p411Options.hidden=true;p411Input.setAttribute('aria-expanded','false');p411Active=-1;}
function p411Render(){
  const q=p411Input.value.trim().toLocaleLowerCase();
  p411Matches=p411Teachers.filter(t=>t.name.toLocaleLowerCase().includes(q)).slice(0,12);
  p411Options.replaceChildren();
  p411Matches.forEach((t,i)=>{
    const option=document.createElement('button');option.type='button';option.className='p411-option';option.setAttribute('role','option');
    option.setAttribute('aria-selected',String(i===p411Active));
    const name=document.createElement('strong');name.textContent=t.name;option.append(name);
    const detail=document.createElement('small');detail.textContent=[t.position,t.office].filter(Boolean).join(' / ')||'Official position not yet recorded';option.append(detail);
    option.addEventListener('mousedown',e=>e.preventDefault());
    option.addEventListener('click',()=>p411Select(t));p411Options.append(option);
  });
  if(!p411Matches.length){const none=document.createElement('div');none.className='p411-empty';none.textContent='No matching teachers';p411Options.append(none);}
  p411Options.hidden=false;p411Input.setAttribute('aria-expanded','true');
}
p411Input.addEventListener('input',()=>{p411Reset();p411Active=-1;p411Render();});
p411Input.addEventListener('focus',p411Render);
p411Input.addEventListener('keydown',e=>{
  if(e.key==='Escape'){p411Close();return;}
  if(e.key==='ArrowDown'||e.key==='ArrowUp'){
    e.preventDefault();if(p411Options.hidden)p411Render();
    p411Active=Math.max(0,Math.min(p411Matches.length-1,p411Active+(e.key==='ArrowDown'?1:-1)));
    [...p411Options.querySelectorAll('.p411-option')].forEach((o,i)=>o.setAttribute('aria-selected',String(i===p411Active)));
  }
  if(e.key==='Enter'&&!p411Options.hidden){e.preventDefault();if(p411Matches[p411Active])p411Select(p411Matches[p411Active]);else if(p411Matches.length===1)p411Select(p411Matches[0]);}
});
document.addEventListener('click',e=>{if(!e.target.closest('.p411-picker'))p411Close();});
p411Input.closest('form').addEventListener('submit',e=>{
  if(!icsTeacher.value){e.preventDefault();p411Input.setCustomValidity('Select a teacher from the dropdown.');p411Input.reportValidity();return;}
  p411Input.setCustomValidity('');
});
p411Input.addEventListener('input',()=>p411Input.setCustomValidity(''));

</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>