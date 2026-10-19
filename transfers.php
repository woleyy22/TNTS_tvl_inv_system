<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['supply_officer'])) {
    die("Unauthorized access. Transfer operations are restricted to Supply Officer and TVL Head.");
}

$role = $user['role'];
$uid = (int)($user['user_id'] ?? $user['id']);

$error = '';
$success = '';

// Handle Reassignment / Transfer Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_transfer'])) {
    $from_teacher_id = (int)$_POST['from_teacher_id'];
    $to_teacher_id   = (int)$_POST['to_teacher_id'];
    $course_id       = (int)$_POST['course_id'];
    $transfer_reason = trim($_POST['transfer_reason']);
    $deactivate_old  = isset($_POST['deactivate_outgoing']);
    csrf_check();

    if (!$from_teacher_id || !$to_teacher_id || !$course_id || empty($transfer_reason)) {
        $error = "All fields are required for item transfer execution.";
    } elseif ($from_teacher_id === $to_teacher_id) {
        $error = "Outgoing and incoming receiving teachers must be different.";
    } else {
        $from_assignment = db()->prepare("SELECT 1 FROM teacher_courses WHERE user_id = ? AND course_id = ?");
        $from_assignment->execute([$from_teacher_id, $course_id]);
        if (!$from_assignment->fetchColumn()) {
            $error = 'Selected course is not assigned to the outgoing teacher.';
        }
        // Verify incoming teacher handles the same course
        $tc_check = db()->prepare("SELECT 1 FROM teacher_courses WHERE user_id = ? AND course_id = ?");
        $tc_check->execute([$to_teacher_id, $course_id]);
        if ($error === '' && !$tc_check->fetch()) {
            $error = "The receiving teacher is not assigned to this elective/course.";
        } else {
            // Fetch active ICS items for the outgoing teacher under this specific course
            $items_stmt = db()->prepare("
                SELECT ii.ics_item_id, ii.item_id, ii.qty_issued, ii.item_condition, i.ics_id, i.ics_number
                FROM ics_items ii
                JOIN ics i ON ii.ics_id = i.ics_id
                WHERE i.teacher_id = ? AND i.course_id = ? AND i.status = 'active'
            ");
            $items_stmt->execute([$from_teacher_id, $course_id]);
            $active_items = $items_stmt->fetchAll();
            $to_check = db()->prepare("SELECT COUNT(*) FROM users WHERE user_id = ? AND role = 'teacher' AND status = 'active'");
            $to_check->execute([$to_teacher_id]);
            if ((int)$to_check->fetchColumn() !== 1) {
                $error = 'Receiving teacher must be an active teacher.';
            }
            $from_check = db()->prepare("SELECT COUNT(*) FROM users WHERE user_id = ? AND role = 'teacher' AND status = 'active'");
            $from_check->execute([$from_teacher_id]);
            if ((int)$from_check->fetchColumn() !== 1) {
                $error = 'Outgoing teacher must be active.';
            }

            if ($error !== '') {
                // Reject invalid teacher selection.
            } elseif (empty($active_items)) {
                $error = "No active ICS items found for this teacher/course. Choose a course shown for this teacher with an active ICS; archived ICS items cannot be transferred.";
            } else {
                db()->beginTransaction();
                try {
                    // Lock both user records and all source ICS rows against concurrent changes.
                    $lockUser = db()->prepare('SELECT user_id FROM users WHERE user_id IN (?, ?) ORDER BY user_id FOR UPDATE');
                    $lockUser->execute([$from_teacher_id, $to_teacher_id]);
                    $lock = db()->prepare("SELECT ics_id FROM ics WHERE teacher_id = ? AND course_id = ? AND status = 'active' FOR UPDATE");
                    $lock->execute([$from_teacher_id, $course_id]);
                    $sourceIds = $lock->fetchAll(PDO::FETCH_COLUMN);
                    if (!$sourceIds) { throw new RuntimeException('The source ICS was already resolved. Reload the page.'); }
                    // Re-read source rows under the locked ICS headers; never rely on a pre-transaction snapshot.
                    $items_stmt->execute([$from_teacher_id, $course_id]);
                    $active_items = $items_stmt->fetchAll();
                    $pending = db()->prepare("SELECT COUNT(*) FROM ics_items ii JOIN ics c ON c.ics_id = ii.ics_id WHERE c.teacher_id = ? AND c.course_id = ? AND c.status = 'active' AND (EXISTS (SELECT 1 FROM borrow_logs b WHERE b.ics_item_id = ii.ics_item_id AND b.status = 'borrowed') OR EXISTS (SELECT 1 FROM condemnations co WHERE co.ics_item_id = ii.ics_item_id AND (co.status = 'requested' OR (co.status = 'approved' AND (co.outcome IS NULL OR co.outcome <> 'disposed')))))");
                    $pending->execute([$from_teacher_id, $course_id]);
                    if ((int)$pending->fetchColumn() > 0) {
                        throw new RuntimeException('A borrowed item, pending condemnation, or approved condemnation without confirmed disposal must be resolved first.');
                    }
                    $disposed_stmt = db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations WHERE ics_item_id = ? AND status = 'approved' AND outcome = 'disposed'");
                    $transferable = [];
                    foreach ($active_items as $item) {
                        $disposed_stmt->execute([$item['ics_item_id']]);
                        $disposed = (int)$disposed_stmt->fetchColumn();
                        $remaining = (int)$item['qty_issued'] - $disposed;
                        if ($remaining < 0) throw new RuntimeException('Disposal quantities exceed the issued quantity; reconciliation required.');
                        if ($remaining > 0) {
                            $item['transfer_qty'] = $remaining;
                            $transferable[] = $item;
                        }
                    }
                    if (!$transferable) {
                        throw new RuntimeException('No remaining units can be transferred. Resolve the original ICS using the approved accountability process.');
                    }
                    // Allocate new ICS once, only for the remaining physical units.
                    $new_ics_num = generate_next_ics_number(date('Y-m-d'));
                    $new_ics_stmt = db()->prepare("INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status) VALUES (?, ?, ?, ?, CURDATE(), 'active')");
                    $new_ics_stmt->execute([$new_ics_num, $to_teacher_id, $course_id, $uid]);
                    $new_ics_id = (int)db()->lastInsertId();
                    $ins_ii = db()->prepare("INSERT INTO ics_items (ics_id, item_id, qty_issued, item_condition) VALUES (?, ?, ?, ?)");
                    $log_tr = db()->prepare("INSERT INTO transfers (ics_item_id, from_teacher, to_teacher, decided_by, carried_out_by, new_ics_id, date_transferred, reason, transferred_qty) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
                    foreach ($transferable as $item) {
                        $ins_ii->execute([$new_ics_id, $item['item_id'], $item['transfer_qty'], $item['item_condition']]);
                        $log_tr->execute([$item['ics_item_id'], $from_teacher_id, $to_teacher_id, $uid, $uid, $new_ics_id, $transfer_reason, $item['transfer_qty']]);
                    }

                    // 3. Close the old ICS headers for this course
                    $close_ics = db()->prepare("UPDATE ics SET status = 'closed' WHERE teacher_id = ? AND course_id = ? AND status = 'active'");
                    $close_ics->execute([$from_teacher_id, $course_id]);

                    // 4. Optionally deactivate outgoing teacher account
                    if ($deactivate_old) {
                        $check = db()->prepare("SELECT COUNT(*) FROM ics WHERE teacher_id = ? AND status = 'active'");
                        $check->execute([$from_teacher_id]);
                        if ((int)$check->fetchColumn() > 0) {
                            throw new RuntimeException('The outgoing teacher still has active ICS in other courses. Deactivation was blocked; resolve all courses first, then deactivate from Accounts.');
                        }
                        db()->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ? AND role = 'teacher'")->execute([$from_teacher_id]);
                    }

                    // 5. Write to central audit log
                    db()->prepare("INSERT INTO history_logs (user_id, action, module, details) VALUES (?, 'Executed Teacher Transfer', 'Transfers', ?)")
                        ->execute([$uid, "Transferred items from Teacher ID $from_teacher_id to $to_teacher_id for Course ID $course_id. New ICS: $new_ics_num"]);

                    db()->commit();
                    $success = "Property transfer completed successfully! New ICS ($new_ics_num) generated for the receiving teacher.";
                } catch (Exception $e) {
                    db()->rollBack();
                    $error = "System error during property transfer: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch active teachers
$teachers = db()->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name")->fetchAll();

// Fetch courses
$courses = db()->query("SELECT course_id, course_name, program FROM courses ORDER BY course_name")->fetchAll();

// Actual active source ICS assignments; distinct from historical/closed ICS.
$source_course_rows = db()->query("SELECT DISTINCT c.teacher_id AS user_id, c.course_id FROM ics c JOIN ics_items ii ON ii.ics_id=c.ics_id JOIN users u ON u.user_id=c.teacher_id WHERE c.status='active' AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
$teacher_course_rows = db()->query("SELECT tc.user_id, tc.course_id FROM teacher_courses tc JOIN users u ON u.user_id=tc.user_id WHERE u.role='teacher' AND u.status='active'")->fetchAll(PDO::FETCH_ASSOC);

// Fetch transfer logs history
$transfer_history = db()->query("
    SELECT t.*, 
           u_from.full_name as outgoing_teacher, 
           u_to.full_name as receiving_teacher, 
           u_dec.full_name as executor,
           t.transferred_qty, inv.item_name, inv.unit
    FROM transfers t
    JOIN users u_from ON t.from_teacher = u_from.user_id
    JOIN users u_to ON t.to_teacher = u_to.user_id
    JOIN users u_dec ON t.carried_out_by = u_dec.user_id
    JOIN ics_items ii ON t.ics_item_id = ii.ics_item_id
    JOIN inventory_items inv ON ii.item_id = inv.item_id
    ORDER BY t.date_transferred DESC
")->fetchAll();

$pageTitle = 'Teacher Property Transfer Module';
$active = 'transfers';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Teacher Property Transfer Module</h2>
        <p class="legacy-027">Reassign accountable ICS items when a teacher leaves or changes courses (Same-course transfers only).</p>
    </div>
    <button onclick="openTransferModal()" class="legacy-180">+ Execute New Property Transfer</button>
</div>

<?php if ($error): ?>
    <div class="legacy-181"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="legacy-182"><?= e($success) ?></div>
<?php endif; ?>

<!-- Audit Trail of Previous Transfers -->
<div class="card legacy-030">
    <h3 class="legacy-183">Property Transfer Log History</h3>
    <table class="legacy-031">
        <thead>
            <tr class="legacy-032">
                <th class="legacy-033">Transferred Item</th>
                <th class="legacy-033">Outgoing Teacher</th>
                <th class="legacy-033">Receiving Teacher</th>
                <th class="legacy-033">Reason / Notes</th>
                <th class="legacy-033">Processed By</th>
                <th class="legacy-033">Date Transferred</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transfer_history)): ?>
                <tr>
                    <td colspan="6" class="legacy-035">No property transfers recorded yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($transfer_history as $th): ?>
                    <tr class="legacy-036">
                        <td class="legacy-033">
                            <strong><?= e($th['item_name']) ?></strong><br>
                            <small class="legacy-037"><?= e($th['transferred_qty']) ?> <?= e($th['unit']) ?></small>
                        </td>
                        <td class="legacy-094"><?= e($th['outgoing_teacher']) ?></td>
                        <td class="legacy-092"><?= e($th['receiving_teacher']) ?></td>
                        <td class="legacy-033"><?= e($th['reason']) ?></td>
                        <td class="legacy-033"><?= e($th['executor']) ?></td>
                        <td class="legacy-033"><?= e(date('M d, Y h:i A', strtotime($th['date_transferred']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Form for Executing Transfer -->
<div id="transferModal" class="legacy-184 modal-overlay" hidden>
    <div class="legacy-185">
        <h3 class="legacy-044">Execute Accountable Property Transfer</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="legacy-045">
                <label class="legacy-046">Select Course / Elective *</label>
                <select name="course_id" id="transfer_course" required class="legacy-047">
                    <option value="">-- Choose Elective Course --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>"><?= e($c['course_name']) ?> (<?= e($c['program']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-045">
                <label class="legacy-046">Outgoing Teacher (Relinquishing Property) *</label>
                <select name="from_teacher_id" id="transfer_from" required class="legacy-047">
                    <option value="">-- Select Outgoing Teacher --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-045">
                <label class="legacy-046">Receiving Teacher (Same Elective) *</label>
                <select name="to_teacher_id" id="transfer_to" required class="legacy-047">
                    <option value="">-- Select Receiving Teacher --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-045">
                <label class="legacy-046">Reason for Transfer *</label>
                <input type="text" name="transfer_reason" required placeholder="e.g. Teacher resignation / Reassignment of load" class="legacy-047">
            </div>

            <div class="legacy-050">
                <label class="legacy-186">
                    <input type="checkbox" name="deactivate_outgoing" value="1">
                    <span class="legacy-187">Deactivate Outgoing Teacher Account (Retains audit history)</span>
                </label>
            </div>

            <div class="legacy-051">
                <button type="button" onclick="closeTransferModal()" class="legacy-052">Cancel</button>
                <button type="submit" name="execute_transfer" class="legacy-028">Confirm & Reissue ICS</button>
            </div>
        </form>
    </div>
</div>

<script>
const sourceCourses = <?= json_encode($source_course_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const teacherCourses = <?= json_encode($teacher_course_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const fromEl = document.getElementById('transfer_from');
const courseEl = document.getElementById('transfer_course');
const toEl = document.getElementById('transfer_to');
function refreshTransferForm() {
    const from = Number(fromEl.value);
    [...courseEl.options].forEach(opt => {
        if (!opt.value) return;
        opt.hidden = opt.disabled = !sourceCourses.some(x => Number(x.user_id) === from && Number(x.course_id) === Number(opt.value));
    });
    if (courseEl.selectedOptions[0]?.disabled) courseEl.value = '';
    const available = [...courseEl.options].filter(o => o.value && !o.disabled);
    if (!courseEl.value && available.length === 1) courseEl.value = available[0].value;
    const course = Number(courseEl.value);
    [...toEl.options].forEach(opt => {
        if (!opt.value) return;
        opt.hidden = opt.disabled = Number(opt.value) === from || !teacherCourses.some(x => Number(x.user_id) === Number(opt.value) && Number(x.course_id) === course);
    });
    if (toEl.selectedOptions[0]?.disabled) toEl.value = '';
}
fromEl.addEventListener('change', refreshTransferForm);
courseEl.addEventListener('change', refreshTransferForm);
refreshTransferForm();
function openTransferModal() {
    TNTSUI.open('transferModal');
}
function closeTransferModal() {
    TNTSUI.close('transferModal');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>