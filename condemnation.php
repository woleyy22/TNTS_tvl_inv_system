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

// 1. Handle Teacher Requesting Condemnation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_condemnation'])) {
    if ($role !== 'teacher') {
        die("Only teachers can request condemnation for their items.");
    }

    $ics_item_id = (int)$_POST['ics_item_id'];
    $quantity = (int)$_POST['quantity'];
    $reason = trim($_POST['reason']);
    $form_number = trim($_POST['form_number']) ?: ('CF-' . date('Y') . '-' . rand(100, 999));

    if (!$ics_item_id || $quantity <= 0 || empty($reason)) {
        die("All fields are required and quantity must be at least 1.");
    }

    // Verify item belongs to this teacher's active ICS and is marked damaged or for repair
    $check = db()->prepare("
        SELECT ii.qty_issued, ii.item_condition,
               COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status <> 'rejected'), 0) as already_condemned
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        WHERE ii.ics_item_id = ? AND c.teacher_id = ? AND c.status = 'active'
    ");
    $check->execute([$ics_item_id, $uid]);
    $itemData = $check->fetch();

    if (!$itemData) {
        die("Invalid item selection or you are not the accountable holder.");
    }

    $max_condemnable = $itemData['qty_issued'] - $itemData['already_condemned'];
    if ($quantity > $max_condemnable) {
        die("Quantity exceeds available items ($max_condemnable available for condemnation request).");
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("
            INSERT INTO condemnations (form_number, ics_item_id, quantity, reason, requested_by, status, outcome)
            VALUES (?, ?, ?, ?, ?, 'requested', 'Pending Custodian Review')
        ");
        $stmt->execute([$form_number, $ics_item_id, $quantity, $reason, $uid]);

        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Requested Condemnation', 'Condemnation', ?, ?)")
            ->execute([$uid, db()->lastInsertId(), "Requested condemnation for qty $quantity. Reason: $reason"]);

        db()->commit();
        header("Location: condemnation.php?requested=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        die("Error submitting request: " . $e->getMessage());
    }
}

// 2. Handle Admin (Supply Officer / Property Custodian) Approving/Rejecting Condemnation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_condemnation'])) {
    if ($role !== 'supply_officer') {
        die("Only the Property Custodian / Supply Officer can approve condemnations.");
    }

    $condemnation_id = (int)$_POST['condemnation_id'];
    $decision = $_POST['decision'] ?? $_POST['action_condemnation']; // Catches either field name

    if (!in_array($decision, ['approved', 'rejected'])) {
        die("Invalid decision.");
    }

    db()->beginTransaction();
    try {
        $status = ($decision === 'approved') ? 'approved' : 'rejected';
        $outcome = ($decision === 'approved') ? 'Approved for disposal and inventory adjustment' : 'Rejected by Custodian';

        $stmt = db()->prepare("
            UPDATE condemnations 
            SET status = ?, approved_by = ?, date_approved = NOW(), outcome = ? 
            WHERE condemnation_id = ?
        ");
        $stmt->execute([$status, $uid, $outcome, $condemnation_id]);

        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, ?, 'Condemnation', ?, ?)")
            ->execute([$uid, ucfirst($decision) . ' Condemnation', $condemnation_id, "Condemnation request #$condemnation_id was $decision."]);

        db()->commit();
        header("Location: condemnation.php?updated=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        die("Error updating condemnation: " . $e->getMessage());
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

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>Condemnation & Disposal Management</h2>
        <p style="color: #666; margin: 0;">Formalize the disposal of unserviceable equipment approved by the Property Custodian.</p>
    </div>
    <?php if ($role === 'teacher'): ?>
        <button onclick="openCondemnModal()" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">+ Request Condemnation</button>
    <?php endif; ?>
</div>

<?php if (isset($_GET['requested'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        Condemnation request submitted successfully. Waiting for Property Custodian approval.
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        Condemnation request status updated successfully.
    </div>
<?php endif; ?>

<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                <th style="padding: 12px;">Form No. / Item</th>
                <?php if ($role !== 'teacher'): ?>
                    <th style="padding: 12px;">Requested By</th>
                <?php endif; ?>
                <th style="padding: 12px;">Qty</th>
                <th style="padding: 12px;">Reason for Disposal</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Outcome / Approver</th>
                <?php if ($role === 'supply_officer'): ?>
                    <th style="padding: 12px; text-align: center;">Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($condemnations)): ?>
                <tr>
                    <td colspan="7" style="padding: 20px; text-align: center; color: #666;">No condemnation records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($condemnations as $con): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= e($con['form_number']) ?></strong><br>
                            <small style="color: #666;"><?= e($con['item_name']) ?> (ICS: <?= e($con['ics_number']) ?>)</small>
                        </td>
                        <?php if ($role !== 'teacher'): ?>
                            <td style="padding: 12px;"><?= e($con['requester_name']) ?></td>
                        <?php endif; ?>
                        <td style="padding: 12px; font-weight: bold;"><?= e($con['quantity']) ?> <?= e($con['unit']) ?></td>
                        <td style="padding: 12px;"><?= e($con['reason']) ?></td>
                        <td style="padding: 12px;">
                            <span style="padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold;
                                background: <?= $con['status'] === 'approved' ? '#d1fae5; color: #065f46;' : ($con['status'] === 'rejected' ? '#fee2e2; color: #991b1b;' : '#fef3c7; color: #92400e;') ?>;">
                                <?= e(ucfirst($con['status'])) ?>
                            </span>
                        </td>
                        <td style="padding: 12px;">
                            <?= e($con['outcome']) ?><br>
                            <small style="color: #666;"><?= $con['approver_name'] ? 'Approved by: ' . e($con['approver_name']) : '' ?></small>
                        </td>
                        <?php if ($role === 'supply_officer'): ?>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($con['status'] === 'requested'): ?>
                                    <form method="POST" style="display: inline-flex; gap: 5px;">
                                        <input type="hidden" name="condemnation_id" value="<?= $con['condemnation_id'] ?>">
                                        <button type="submit" name="action_condemnation" value="approved" style="padding: 4px 8px; background: #10b981; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em;">Approve</button>
                                        <button type="submit" name="action_condemnation" value="rejected" style="padding: 4px 8px; background: #ef4444; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em;">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #666; font-size: 0.85em;">Resolved</span>
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
<div id="condemnModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
    <div style="background: white; padding: 25px; border-radius: 8px; width: 450px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; color: #800000;">Request Item Condemnation</h3>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Select Accountable Item *</label>
                <select name="ics_item_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- Choose Damaged / Broken Item --</option>
                    <?php foreach ($my_items as $mi): 
                        $avail_condemn = $mi['qty_issued'] - $mi['condemned_qty'];
                        if ($avail_condemn > 0):
                    ?>
                        <option value="<?= $mi['ics_item_id'] ?>">
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Available: <?= $avail_condemn ?> <?= e($mi['unit']) ?> [<?= e($mi['item_condition']) ?>]
                        </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Quantity *</label>
                    <input type="number" name="quantity" min="1" value="1" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Form Number (Opt.)</label>
                    <input type="text" name="form_number" placeholder="e.g. CF-2026-001" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Reason for Condemnation / Disposal *</label>
                <textarea name="reason" rows="3" required placeholder="Describe why this equipment is beyond repair..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeCondemnModal()" style="padding: 8px 16px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" name="request_condemnation" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCondemnModal() {
    document.getElementById('condemnModal').style.display = 'flex';
}
function closeCondemnModal() {
    document.getElementById('condemnModal').style.display = 'none';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>