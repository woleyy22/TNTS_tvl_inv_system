<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ics_accountability.php';

$user = require_login();
$role = $user['role'];
$uid = (int)$user['id'];

// The same stock calculation is used by the dropdown and the POST validation.
function borrow_stock_quantities(array $item): array
{
    $serviceable=max(0,(int)($item['current_serviceable']??0));
    if(!in_array($item['item_category']??'', ['equipment','non_consumable'],true) || !empty($item['needs_review']))$serviceable=0;
    return ['serviceable_qty'=>$serviceable,
      'lendable_qty'=>max(0,$serviceable-max(0,(int)$item['borrowed_qty']))];
}

$lendingColumns="
 COALESCE((SELECT cb.qty_serviceable FROM ics_condition_balances cb WHERE cb.ics_item_id=ii.ics_item_id),0) AS current_serviceable,
 COALESCE((SELECT cb.qty_for_repair FROM ics_condition_balances cb WHERE cb.ics_item_id=ii.ics_item_id),0) AS repair_qty,
 COALESCE((SELECT cb.qty_damaged FROM ics_condition_balances cb WHERE cb.ics_item_id=ii.ics_item_id),0) AS damaged_qty,
 COALESCE((SELECT cb.needs_review FROM ics_condition_balances cb WHERE cb.ics_item_id=ii.ics_item_id),0) AS needs_review,
 COALESCE((SELECT SUM(co.quantity) FROM condemnations co WHERE co.ics_item_id=ii.ics_item_id AND co.status='approved' AND co.outcome='disposed'),0) AS approved_condemned,
 COALESCE((SELECT SUM(b.quantity) FROM borrow_logs b WHERE b.ics_item_id=ii.ics_item_id AND b.status='borrowed'),0) AS borrowed_qty";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (isset($_POST['borrow_item'])) {
            if ($role !== 'teacher') { throw new InvalidArgumentException('Only teachers can lend equipment.'); }
            $ics_item_id = filter_var($_POST['ics_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $borrower_id = filter_var($_POST['borrower_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $purpose = isset($_POST['program_purpose']) && is_string($_POST['program_purpose']) ? trim($_POST['program_purpose']) : '';
            $purposeLength = preg_match_all('/./us', $purpose);
            $date_borrowed = isset($_POST['date_borrowed']) && is_string($_POST['date_borrowed']) ? $_POST['date_borrowed'] : '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_borrowed);
            if (!$ics_item_id || !$borrower_id || !$quantity || $purpose === '' || ($purposeLength === false || $purposeLength > 200) || !$date || $date->format('Y-m-d') !== $date_borrowed) {
                throw new InvalidArgumentException('Choose equipment and a teacher, enter a whole-number quantity, a valid date and a purpose of up to 200 characters.');
            }
            if ($borrower_id === $uid) { throw new InvalidArgumentException('Choose another teacher as the borrower.'); }
            $st = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'teacher' AND status = 'active'");
            $st->execute([$borrower_id]);
            if (!$st->fetch()) { throw new InvalidArgumentException('The selected teacher is no longer available. Refresh the page and choose another teacher.'); }
            db()->beginTransaction();
            // Serialize simultaneous lending requests for the same accountable item.
            $lock = db()->prepare("SELECT ii.ics_item_id FROM ics_items ii JOIN ics c ON c.ics_id = ii.ics_id
                WHERE ii.ics_item_id = ? AND c.teacher_id = ? AND c.status = 'active' FOR UPDATE");
            $lock->execute([$ics_item_id, $uid]);
            if (!$lock->fetch()) { throw new InvalidArgumentException('This item is no longer assigned to your active ICS.'); }
            $check = db()->prepare("SELECT ii.qty_issued,ii.item_condition,i.item_category,$lendingColumns FROM ics_items ii JOIN inventory_items i ON i.item_id=ii.item_id WHERE ii.ics_item_id = ?");
            $check->execute([$ics_item_id]);
            $state=ics31_state(db(),(int)$ics_item_id,true);
            if(!$state['is_equipment'] || $state['needs_review'])throw new InvalidArgumentException('Item is not eligible for lending until the Supply Officer verifies its condition.');
            $available = borrow_stock_quantities($check->fetch())['lendable_qty'];
            if ($quantity > $available) { throw new InvalidArgumentException("Only $available serviceable unit(s) are currently available to lend. Refresh the form and reduce the quantity."); }
            db()->prepare("INSERT INTO borrow_logs (ics_item_id, borrower_id, quantity, program_purpose, date_borrowed, status) VALUES (?, ?, ?, ?, ?, 'borrowed')")
                ->execute([$ics_item_id, $borrower_id, $quantity, $purpose, $date_borrowed]);
            $borrowId = (int)db()->lastInsertId();
            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Lent Equipment', 'Borrow', ?, ?)")
                ->execute([$uid, $borrowId, "Lent qty $quantity for $purpose"]);
            db()->commit();
            redirect('borrow.php?success=1');
        } elseif (isset($_POST['return_item'])) {
            $borrowId = filter_var($_POST['borrow_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$borrowId) { throw new InvalidArgumentException('Choose a valid loan record.'); }
            db()->beginTransaction();
            $st = db()->prepare("SELECT b.borrow_id, b.status, b.borrower_id, c.teacher_id FROM borrow_logs b
                JOIN ics_items ii ON ii.ics_item_id = b.ics_item_id JOIN ics c ON c.ics_id = ii.ics_id
                WHERE b.borrow_id = ? FOR UPDATE");
            $st->execute([$borrowId]);
            $loan = $st->fetch();
            if (!$loan || ($role === 'teacher' && (int)$loan['teacher_id'] !== $uid && (int)$loan['borrower_id'] !== $uid)) {
                throw new InvalidArgumentException('You cannot update this loan record.');
            }
            if ($loan['status'] !== 'borrowed') { throw new InvalidArgumentException('This equipment has already been marked as returned.'); }
            db()->prepare("UPDATE borrow_logs SET status = 'returned', date_returned = NOW() WHERE borrow_id = ?")->execute([$borrowId]);
            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Returned Equipment', 'Borrow', ?, ?)")
                ->execute([$uid, $borrowId, 'Item returned from borrower.']);
            db()->commit();
            redirect('borrow.php?returned=1');
        } else { throw new InvalidArgumentException('Choose a valid lending action.'); }
    } catch (Throwable $ex) {
        if (db()->inTransaction()) { db()->rollBack(); }
        flash('error', $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'The loan could not be saved. Please refresh and try again.');
        redirect('borrow.php');
    }
}

$teachers = db()->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name")->fetchAll();
$my_lendable_items = [];
if ($role === 'teacher') {
    $stmt = db()->prepare("SELECT ii.ics_item_id, ii.item_condition, i.item_name, i.unit,i.item_category, ii.qty_issued, c.ics_number, $lendingColumns
        FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id JOIN inventory_items i ON ii.item_id = i.item_id
        WHERE c.teacher_id = ? AND c.status = 'active' AND i.item_category IN ('equipment','non_consumable') ORDER BY i.item_name, c.ics_number");
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $item) {
        $item = array_merge($item, borrow_stock_quantities($item));
        if ($item['lendable_qty'] > 0) { $my_lendable_items[] = $item; }
    }
}

// Fetch active borrow logs
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT b.*, i.item_name, i.unit, c.ics_number, u.full_name as borrower_name, lender_user.full_name as lender_name
        FROM borrow_logs b
        JOIN ics_items ii ON b.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users u ON b.borrower_id = u.user_id
        JOIN users lender_user ON c.teacher_id = lender_user.user_id
        WHERE c.teacher_id = ? OR b.borrower_id = ?
        ORDER BY b.date_borrowed DESC
    ");
    $stmt->execute([$uid, $uid]);
    $borrow_logs = $stmt->fetchAll();
} else {
    $stmt = db()->query("
        SELECT b.*, i.item_name, i.unit, c.ics_number, u.full_name as borrower_name, lender_user.full_name as lender_name
        FROM borrow_logs b
        JOIN ics_items ii ON b.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users u ON b.borrower_id = u.user_id
        JOIN users lender_user ON c.teacher_id = lender_user.user_id
        ORDER BY b.date_borrowed DESC
    ");
    $borrow_logs = $stmt->fetchAll();
}

$pageTitle = 'Borrow & Return Log';
$active = 'borrow';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Borrow & Return Log</h2>
        <p class="legacy-027">Track equipment lent out during school programs while maintaining primary accountability.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <button onclick="openBorrowModal()" class="legacy-028">+ Lend Item</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="legacy-029">
        Equipment lending recorded successfully! Note: You remain accountable on your ICS.
    </div>
<?php endif; ?>

<?php if (isset($_GET['returned'])): ?>
    <div class="legacy-029">
        Equipment successfully marked as returned!
    </div>
<?php endif; ?>

<div class="card legacy-030">
    <table class="legacy-031">
        <thead>
            <tr class="legacy-032">
                <th class="legacy-033">Item Details</th>
                <th class="legacy-033">Lender (ICS Holder)</th>
                <th class="legacy-033">Borrower</th>
                <th class="legacy-033">Qty</th>
                <th class="legacy-033">Program / Purpose</th>
                <th class="legacy-033">Date Borrowed</th>
                <th class="legacy-033">Status</th>
                <th class="legacy-034">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($borrow_logs)): ?>
                <tr>
                    <td colspan="8" class="legacy-035">No borrow or lend records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($borrow_logs as $log): ?>
                    <tr class="legacy-036">
                        <td class="legacy-033">
                            <strong><?= e($log['item_name']) ?></strong><br>
                            <small class="legacy-037">ICS: <?= e($log['ics_number']) ?></small>
                        </td>
                        <td class="legacy-033"><?= e($log['lender_name']) ?></td>
                        <td class="legacy-038"><?= e($log['borrower_name']) ?></td>
                        <td class="legacy-033"><?= e($log['quantity']) ?> <?= e($log['unit']) ?></td>
                        <td class="legacy-033"><?= e($log['program_purpose']) ?></td>
                        <td class="legacy-033"><?= e(date('M d, Y', strtotime($log['date_borrowed']))) ?></td>
                        <td class="legacy-033">
                            <span class="status-badge <?= $log['status'] === 'borrowed' ? 'status-warning' : 'status-success' ?>">
                                <?= e(ucfirst($log['status'])) ?>
                            </span>
                        </td>
                        <td class="legacy-034">
                            <?php if ($log['status'] === 'borrowed'): ?>
                                <form method="POST" class="legacy-039">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="borrow_id" value="<?= $log['borrow_id'] ?>">
                                    <button type="submit" name="return_item" onclick="return confirm('Mark this item as returned?')" class="legacy-040">
                                        Mark Returned
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="legacy-041"><?= e(date('M d, Y', strtotime($log['date_returned']))) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Lend Item Modal -->
<?php if ($role === 'teacher'): ?>
<div id="borrowModal" class="modal-overlay" hidden>
    <section class="guided-modal">
        <div class="eyebrow">Borrow &amp; return</div>
        <h3>Lend equipment to a teacher</h3>
        <p class="modal-intro">Record who will use the equipment, how many units they need, and the purpose of the loan.</p>
        <form method="POST" id="lendingForm">
            <?= csrf_field() ?>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">1</span><h4>Select equipment</h4></div>
                <div class="field"><label for="lendItem">Equipment from your active ICS <span class="required-mark">*</span></label>
                    <select name="ics_item_id" id="lendItem" required class="input" onchange="updateLendingDetails()" data-initial-focus aria-describedby="lendEquipmentHelp">
                        <option value="">Select equipment to lend</option>
                        <?php foreach ($my_lendable_items as $mi):
                            $avail = $mi['lendable_qty'];
                            if ($avail > 0):
                        ?>
                            <option value="<?= (int)$mi['ics_item_id'] ?>" data-available="<?= (int)$avail ?>" data-issued="<?= (int)$mi['qty_issued'] ?>" data-serviceable="<?= (int)$mi['serviceable_qty'] ?>" data-borrowed="<?= (int)$mi['borrowed_qty'] ?>" data-unit="<?= e($mi['unit']) ?>" data-item="<?= e($mi['item_name']) ?>" data-ics="<?= e($mi['ics_number']) ?>"><?= e($mi['item_name']) ?> — <?= e($mi['ics_number']) ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                    <small id="lendEquipmentHelp" class="field-help">Only serviceable units that are not already on loan are available.</small>
                </div>
                <div id="lendAvailability" class="equipment-snapshot" hidden><div class="snapshot-heading"><span id="lendReference"></span><strong id="lendAvailable"></strong></div><p class="field-help" id="lendAvailabilityNote"></p></div>
                <p class="form-notice" id="lendEmpty" hidden>No serviceable equipment is currently available to lend from your active custody records.</p>
            </section>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">2</span><h4>Choose the borrower and quantity</h4></div>
                <div class="field"><label for="lendTeacher">Borrowing teacher <span class="required-mark">*</span></label>
                    <select name="borrower_id" id="lendTeacher" required class="input" onchange="updateLendingSummary()">
                        <option value="">Select a teacher</option>
                        <?php foreach ($teachers as $t): if ($t['user_id'] != $uid): ?>
                            <option value="<?= (int)$t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                    <small class="field-help">Your own account is excluded from this list.</small>
                </div>
                <p class="form-notice" id="lendNoTeachers" hidden>No other active teacher is available. Contact your Supply Officer.</p>
                <div class="guided-form-grid">
                    <div class="field"><label for="lendQuantity">Units to lend <span class="required-mark">*</span></label><input type="number" name="quantity" id="lendQuantity" min="1" step="1" value="1" required class="input" oninput="updateLendingSummary()" aria-describedby="lendQuantityHelp"><small id="lendQuantityHelp" class="field-help">Choose equipment to see the limit.</small></div>
                    <div class="field"><label for="lendDate">Date borrowed <span class="required-mark">*</span></label><input type="date" name="date_borrowed" id="lendDate" value="<?= date('Y-m-d') ?>" required class="input"></div>
                </div>
            </section>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">3</span><h4>Describe the purpose</h4></div>
                <div class="field"><label for="lendPurpose">Program or purpose <span class="required-mark">*</span></label><textarea name="program_purpose" id="lendPurpose" rows="2" maxlength="200" required class="input" placeholder="For example: equipment demonstration for the TLE school fair."></textarea></div>
            </section>
            <div class="form-review" id="lendSummary" aria-live="polite">Select equipment and a borrowing teacher to review this loan.</div>
            <div class="modal-actions"><button type="button" onclick="closeBorrowModal()" class="btn btn-outline">Cancel</button><button type="submit" name="borrow_item" id="lendSubmit" class="btn btn-primary" disabled>Confirm equipment loan</button></div>
        </form>
    </section>
</div>
<script>
function openBorrowModal() { updateLendingDetails(); TNTSUI.open('borrowModal'); }
function closeBorrowModal() { TNTSUI.close('borrowModal'); }
function updateLendingDetails() {
    const item = document.getElementById('lendItem');
    const option = item.value ? item.options[item.selectedIndex] : null;
    const quantity = document.getElementById('lendQuantity');
    document.getElementById('lendAvailability').hidden = !option;
    document.getElementById('lendEmpty').hidden = item.options.length > 1;
    document.getElementById('lendNoTeachers').hidden = document.getElementById('lendTeacher').options.length > 1;
    quantity.disabled = !option;
    if (option) {
        const maximum = Number(option.dataset.available);
        quantity.max = String(maximum);
        quantity.value = String(Math.max(1, Math.min(maximum, Number(quantity.value) || 1)));
        document.getElementById('lendReference').textContent = 'ICS: ' + option.dataset.ics;
        document.getElementById('lendAvailable').textContent = maximum + ' ' + option.dataset.unit + ' serviceable & available';
        document.getElementById('lendAvailabilityNote').textContent = option.dataset.issued + ' issued · ' + option.dataset.serviceable + ' serviceable · ' + option.dataset.borrowed + ' currently on loan';
        document.getElementById('lendQuantityHelp').textContent = 'Enter 1 to ' + maximum + ' ' + option.dataset.unit + '.';
    } else {
        quantity.removeAttribute('max');
        document.getElementById('lendQuantityHelp').textContent = 'Choose equipment to see the limit.';
    }
    updateLendingSummary();
}
function updateLendingSummary() {
    const item = document.getElementById('lendItem');
    const teacher = document.getElementById('lendTeacher');
    const quantity = document.getElementById('lendQuantity');
    const valid = !!item.value && Number.isInteger(Number(quantity.value)) && Number(quantity.value) >= 1 && Number(quantity.value) <= Number(quantity.max);
    quantity.setCustomValidity(valid || !item.value ? '' : 'Enter a whole number from 1 to ' + quantity.max + '.');
    document.getElementById('lendSubmit').disabled = !valid || !teacher.value;
    document.getElementById('lendSummary').textContent = valid && teacher.value ? 'Lend ' + Number(quantity.value) + ' ' + item.options[item.selectedIndex].dataset.unit + ' of ' + item.options[item.selectedIndex].dataset.item + ' to ' + teacher.options[teacher.selectedIndex].textContent.trim() + '.' : 'Select equipment, a teacher, and a valid quantity to review this loan.';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>