<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['supply_officer', 'tvl_head'])) {
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

    if (!$from_teacher_id || !$to_teacher_id || !$course_id || empty($transfer_reason)) {
        $error = "All fields are required for item transfer execution.";
    } elseif ($from_teacher_id === $to_teacher_id) {
        $error = "Outgoing and incoming receiving teachers must be different.";
    } else {
        // Verify incoming teacher handles the same course
        $tc_check = db()->prepare("SELECT 1 FROM teacher_courses WHERE user_id = ? AND course_id = ?");
        $tc_check->execute([$to_teacher_id, $course_id]);
        if (!$tc_check->fetch()) {
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

            if (empty($active_items)) {
                $error = "No active accountable ICS items found for the outgoing teacher under the selected course.";
            } else {
                db()->beginTransaction();
                try {
                    // 1. Create new ICS for the receiving teacher
                    $new_ics_num = 'ICS-TR-' . date('Y') . '-' . rand(1000, 9999);
                    $new_ics_stmt = db()->prepare("
                        INSERT INTO ics (ics_number, teacher_id, course_id, issued_by, date_issued, status)
                        VALUES (?, ?, ?, ?, NOW(), 'active')
                    ");
                    $new_ics_stmt->execute([$new_ics_num, $to_teacher_id, $course_id, $uid]);
                    $new_ics_id = db()->lastInsertId();

                    // 2. Transfer items & record transfer history
                    // Inside the transfer execution loop in transfers.php:
                foreach ($active_items as $item) {
                    // 1. Insert line item into new ICS, preserving the existing item_condition
                    $ins_ii = db()->prepare("
                        INSERT INTO ics_items (ics_id, item_id, qty_issued, item_condition)
                        VALUES (?, ?, ?, ?)
                    ");
                    $ins_ii->execute([$new_ics_id, $item['item_id'], $item['qty_issued'], $item['item_condition']]);
                    $new_ics_item_id = db()->lastInsertId();

                    // 2. Re-link active repair and maintenance logs to the new ics_item_id
                    $migrate_repairs = db()->prepare("
                        UPDATE repair_logs 
                        SET ics_item_id = ? 
                        WHERE ics_item_id = ?
                    ");
                    $migrate_repairs->execute([$new_ics_item_id, $item['ics_item_id']]);

                    // 3. Re-link active pending condemnations to the new ics_item_id
                    $migrate_condemnations = db()->prepare("
                        UPDATE condemnations 
                        SET ics_item_id = ? 
                        WHERE ics_item_id = ? AND status = 'requested'
                    ");
                    $migrate_condemnations->execute([$new_ics_item_id, $item['ics_item_id']]);

                    // 4. Log the property transfer record
                    $log_tr = db()->prepare("
                        INSERT INTO transfers (ics_item_id, from_teacher, to_teacher, decided_by, carried_out_by, new_ics_id, date_transferred, reason)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $log_tr->execute([$item['ics_item_id'], $from_teacher_id, $to_teacher_id, $uid, $uid, $new_ics_id, $transfer_reason]);
                }

                    // 3. Close the old ICS headers for this course
                    $close_ics = db()->prepare("UPDATE ics SET status = 'transferred' WHERE teacher_id = ? AND course_id = ? AND status = 'active'");
                    $close_ics->execute([$from_teacher_id, $course_id]);

                    // 4. Optionally deactivate outgoing teacher account
                    if ($deactivate_old) {
                        $deact = db()->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ? AND role = 'teacher'");
                        $deact->execute([$from_teacher_id]);
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

// Fetch transfer logs history
$transfer_history = db()->query("
    SELECT t.*, 
           u_from.full_name as outgoing_teacher, 
           u_to.full_name as receiving_teacher, 
           u_dec.full_name as executor,
           ii.qty_issued, inv.item_name, inv.unit
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
                            <small class="legacy-037"><?= e($th['qty_issued']) ?> <?= e($th['unit']) ?></small>
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
            <div class="legacy-045">
                <label class="legacy-046">Select Course / Elective *</label>
                <select name="course_id" required class="legacy-047">
                    <option value="">-- Choose Elective Course --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>"><?= e($c['course_name']) ?> (<?= e($c['program']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-045">
                <label class="legacy-046">Outgoing Teacher (Relinquishing Property) *</label>
                <select name="from_teacher_id" required class="legacy-047">
                    <option value="">-- Select Outgoing Teacher --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['user_id'] ?>"><?= e($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-045">
                <label class="legacy-046">Receiving Teacher (Same Elective) *</label>
                <select name="to_teacher_id" required class="legacy-047">
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
                    <input type="checkbox" name="deactivate_outgoing" value="1" checked>
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
function openTransferModal() {
    TNTSUI.open('transferModal');
}
function closeTransferModal() {
    TNTSUI.close('transferModal');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>