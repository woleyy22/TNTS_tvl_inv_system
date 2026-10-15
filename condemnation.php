<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$role = $user['role'];
$uid = (int)($user['user_id'] ?? $user['id']);

$request_error = '';
// 1. Handle Teacher Requesting Condemnation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_condemnation'])) {
    if ($role !== 'teacher') {
        die("Only teachers can request condemnation for their items.");
    }

    csrf_check();
    $ics_item_id = (int)($_POST['ics_item_id'] ?? 0);
    $raw_quantity = trim((string)($_POST['quantity'] ?? ''));
    $quantity = filter_var($raw_quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (!$ics_item_id || $quantity === false || $reason === '') {
        $request_error = 'Select an accountable item, enter a positive whole-number quantity, and provide a reason.';
    } else {
        try {
            db()->beginTransaction();
            // Serialize requests for the same ICS item to prevent two simultaneous over-requests.
            $check = db()->prepare("SELECT ii.qty_issued, ii.item_condition FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.ics_item_id=? AND c.teacher_id=? AND c.status='active' FOR UPDATE");
            $check->execute([$ics_item_id, $uid]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('The selected item is no longer accountable to your active ICS.');
            $sum = db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations WHERE ics_item_id=? AND status <> 'rejected'");
            $sum->execute([$ics_item_id]);
            $remaining = (int)$row['qty_issued'] - (int)$sum->fetchColumn();
            if ($quantity > $remaining) throw new RuntimeException("Quantity exceeds the available balance ($remaining remaining).");
            $form_number = generate_document_number('condemnation');
            db()->prepare("INSERT INTO condemnations (form_number, ics_item_id, quantity, reason, requested_by, status, outcome) VALUES (?, ?, ?, ?, ?, 'requested', NULL)")
                ->execute([$form_number, $ics_item_id, $quantity, $reason, $uid]);
            $new_id = (int)db()->lastInsertId();
            // Mark precisely the requested quantity damaged in the condition ledger.
            // Do not change the whole ICS line when only some units are affected.
            db()->prepare("INSERT INTO repair_logs (ics_item_id, reported_by, issue, action_taken, date_reported, quantity_affected, condition_status) VALUES (?, ?, ?, 'Pending condemnation review', CURRENT_DATE(), ?, 'damaged')")
                ->execute([$ics_item_id, $uid, 'Condemnation request #' . $new_id . ': ' . $reason, $quantity]);
            if ($quantity === (int)$row['qty_issued'] && $remaining === (int)$row['qty_issued']) {
                db()->prepare("UPDATE ics_items SET item_condition = 'damaged' WHERE ics_item_id = ?")->execute([$ics_item_id]);
            }
            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Requested Condemnation', 'Condemnation', ?, ?)")
                ->execute([$uid, $new_id, "Requested condemnation for qty $quantity. Reason: $reason"]);
            db()->commit();
            header('Location: condemnation.php?requested=1'); exit;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $request_error = $e->getMessage();
        }
    }
}

// 2. Handle Admin (Supply Officer / Property Custodian) Approving/Rejecting Condemnation
// Inside condemnation.php POST handler for Supply Officer approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_condemnation'])) {
    if ($role !== 'supply_officer') {
        die("Unauthorized access. Only Property Custodians can approve condemnations.");
    }

    $condemnation_id = (int)$_POST['condemnation_id'];
    $decision = $_POST['decision'] ?? $_POST['action_condemnation'];

    if (!in_array($decision, ['approved', 'rejected'])) {
        die("Invalid decision action.");
    }

    db()->beginTransaction();
    try {
        // Fetch existing condemnation record and verify status is still 'requested'
        $stmt_check = db()->prepare("SELECT status FROM condemnations WHERE condemnation_id = ? FOR UPDATE");
        $stmt_check->execute([$condemnation_id]);
        $existing = $stmt_check->fetch();

        if (!$existing) {
            throw new Exception("Condemnation record not found.");
        }

        if ($existing['status'] !== 'requested') {
            throw new Exception("This condemnation request has already been finalized ('{$existing['status']}') and cannot be modified.");
        }

        // Approval confirms physical disposal only after the custodian's explicit confirmation.
        if ($decision === 'approved' && !isset($_POST['confirm_disposed'])) {
            throw new RuntimeException('Confirm that these units were physically disposed before recording disposal.');
        }
        $outcome = $decision === 'approved' ? 'disposed' : null;

        $stmt = db()->prepare("
            UPDATE condemnations 
            SET status = ?, approved_by = ?, date_approved = NOW(), outcome = ? 
            WHERE condemnation_id = ?
        ");
        $stmt->execute([$decision, $uid, $outcome, $condemnation_id]);

        // Audit Trail
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, ?, 'Condemnation', ?, ?)")
            ->execute([$uid, ucfirst($decision) . ' Condemnation', $condemnation_id, "Condemnation record #{$condemnation_id} marked as {$decision}."]);

        db()->commit();
        header("Location: condemnation.php?updated=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        header("Location: condemnation.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// Fetch condemnations based on role
if ($role === 'teacher') {
    $stmt = db()->prepare("
        SELECT co.*, i.item_name, i.unit, c.ics_number, u.full_name as approver_name
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        LEFT JOIN users u ON co.approved_by = u.user_id
        WHERE co.requested_by = ?
        ORDER BY co.date_approved DESC
    ");
    $stmt->execute([$uid]);
    $condemnations = $stmt->fetchAll();

    // Fetch items eligible for condemnation request
    $stmt_items = db()->prepare("
        SELECT ii.ics_item_id, i.item_name, i.unit, ii.qty_issued, ii.item_condition, c.ics_number,
               COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status <> 'rejected'), 0) as condemned_qty
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        WHERE c.teacher_id = ? AND c.status = 'active'
    ");
    $stmt_items->execute([$uid]);
    $my_items = $stmt_items->fetchAll();
} else {
    $stmt = db()->query("
        SELECT co.*, i.item_name, i.unit, c.ics_number, req_user.full_name as requester_name, app_user.full_name as approver_name
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users req_user ON co.requested_by = req_user.user_id
        LEFT JOIN users app_user ON co.approved_by = app_user.user_id
        ORDER BY co.status = 'requested' DESC, co.date_approved DESC
    ");
    $condemnations = $stmt->fetchAll();
}

$pageTitle = 'Condemnation Module';
$active = 'condemnation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Condemnation & Disposal Management</h2>
        <p class="legacy-027">Formalize the disposal of unserviceable equipment approved by the Property Custodian.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <button onclick="openCondemnModal()" class="legacy-028">+ Request Condemnation</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['requested'])): ?>
    <div class="legacy-029">
        Condemnation request submitted successfully. Waiting for Property Custodian approval.
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="legacy-029">
        Condemnation request status updated successfully.
    </div>
<?php endif; ?>

<?php if ($request_error): ?><div class="legacy-181" role="alert" style="padding:12px;margin-bottom:12px;background:#ffe3e3;color:#900;border-radius:6px;"><?= e($request_error) ?></div><?php endif; ?>
<div class="card legacy-030">
    <table class="legacy-031">
        <thead>
            <tr class="legacy-032">
                <th class="legacy-033">Form No. / Item</th>
                <?php if ($role !== 'teacher'): ?>
                    <th class="legacy-033">Requested By</th>
                <?php endif; ?>
                <th class="legacy-033">Qty</th>
                <th class="legacy-033">Reason for Disposal</th>
                <th class="legacy-033">Status</th>
                <th class="legacy-033">Outcome / Approver</th>
                <?php if ($role === 'supply_officer'): ?>
                    <th class="legacy-034">Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($condemnations)): ?>
                <tr>
                    <td colspan="7" class="legacy-035">No condemnation records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($condemnations as $con): ?>
                    <tr class="legacy-036">
                        <td class="legacy-033">
                            <strong><?= e($con['form_number']) ?></strong><br>
                            <small class="legacy-037"><?= e($con['item_name']) ?> (ICS: <?= e($con['ics_number']) ?>)</small>
                        </td>
                        <?php if ($role !== 'teacher'): ?>
                            <td class="legacy-033"><?= e($con['requester_name']) ?></td>
                        <?php endif; ?>
                        <td class="legacy-091"><?= e($con['quantity']) ?> <?= e($con['unit']) ?></td>
                        <td class="legacy-033"><?= e($con['reason']) ?></td>
                        <td class="legacy-033">
                            <span class="status-badge <?= $con['status'] === 'approved' ? 'status-success' : ($con['status'] === 'rejected' ? 'status-danger' : 'status-warning') ?>">
                                <?= e(ucfirst($con['status'])) ?>
                            </span>
                        </td>
                        <td class="legacy-033">
                            <?= e($con['outcome']) ?><br>
                            <small class="legacy-037"><?= $con['approver_name'] ? 'Approved by: ' . e($con['approver_name']) : '' ?></small>
                        </td>
                        <?php if ($role === 'supply_officer'): ?>
                            <td class="legacy-034">
                                <?php if ($con['status'] === 'requested'): ?>
                                    <form method="POST" class="legacy-211">
                                        <input type="hidden" name="condemnation_id" value="<?= $con['condemnation_id'] ?>">
                                        <label><input type="checkbox" name="confirm_disposed" value="1"> I confirm these units have been physically disposed of</label><button type="submit" name="action_condemnation" value="approved" class="legacy-212">Approve</button>
                                        <button type="submit" name="action_condemnation" value="rejected" class="legacy-213">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="legacy-041">Resolved</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Request Condemnation Modal (Teacher) -->
<?php if ($role === 'teacher'): ?>
<div id="condemnModal" class="legacy-042 modal-overlay" hidden>
    <div class="legacy-043">
        <h3 class="legacy-044">Request Item Condemnation</h3>
        <form method="POST" id="condemn_request_form">
            <?= csrf_field() ?>
            <div class="legacy-045">
                <label class="legacy-046">Select Accountable Item *</label>
                <select name="ics_item_id" id="condemn_item" required class="legacy-047">
                    <option value="">-- Choose Damaged / Broken Item --</option>
                    <?php foreach ($my_items as $mi): 
                        $avail_condemn = $mi['qty_issued'] - $mi['condemned_qty'];
                        if ($avail_condemn > 0):
                    ?>
                        <option value="<?= $mi['ics_item_id'] ?>" data-max="<?= (int)$avail_condemn ?>" <?= (int)($_POST['ics_item_id'] ?? 0) === (int)$mi['ics_item_id'] ? 'selected' : '' ?>>
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Available: <?= $avail_condemn ?> <?= e($mi['unit']) ?> [<?= e($mi['item_condition']) ?>]
                        </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div class="legacy-048">
                <div class="legacy-049">
                    <label class="legacy-046">Quantity *</label>
                    <input type="number" name="quantity" id="condemn_qty" min="1" step="1" value="<?= e($_POST['quantity'] ?? '1') ?>" required class="legacy-047">
                    <small id="condemn_qty_hint" style="display:block;margin-top:4px;">Select an eligible item to see the maximum quantity.</small>
                </div>
                <div class="legacy-049">
                    <label class="legacy-046">Form Number (Opt.)</label>
                    <input type="text" name="form_number" placeholder="e.g. CF-2026-001" class="legacy-047">
                </div>
            </div>

            <div class="legacy-050">
                <label class="legacy-046">Reason for Condemnation / Disposal *</label>
                <textarea name="reason" rows="3" required placeholder="Describe why this equipment is beyond repair..." class="legacy-047"><?= e($_POST['reason'] ?? '') ?></textarea>
            </div>

            <div class="legacy-051">
                <button type="button" onclick="closeCondemnModal()" class="legacy-052">Cancel</button>
                <button type="submit" name="request_condemnation" class="legacy-028">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
const condemItem = document.getElementById('condemn_item');
const condemQty = document.getElementById('condemn_qty');
const condemHint = document.getElementById('condemn_qty_hint');
function updateCondemnLimit() {
 const max = Number(condemItem.selectedOptions[0]?.dataset.max || 0);
 condemQty.max = max ? String(max) : '';
 condemHint.textContent = max ? `Maximum available for this request: ${max}` : 'Select an eligible item first.';
 if (max && Number(condemQty.value) > max) condemQty.value = String(max);
}
condemItem.addEventListener('change', updateCondemnLimit);
document.getElementById('condemn_request_form').addEventListener('submit', event => {
 const max = Number(condemItem.selectedOptions[0]?.dataset.max || 0);
 const qty = Number(condemQty.value);
 if (!max || !Number.isInteger(qty) || qty < 1 || qty > max) {
  event.preventDefault();
  condemHint.style.color = '#b91c1c';
  condemHint.textContent = `Enter a whole number between 1 and ${max}.`;
  condemQty.focus();
 }
});
updateCondemnLimit();
<?php if ($request_error): ?>
document.addEventListener('DOMContentLoaded', () => openCondemnModal());
<?php endif; ?>
function openCondemnModal() {
    TNTSUI.open('condemnModal');
}
function closeCondemnModal() {
    TNTSUI.close('condemnModal');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>