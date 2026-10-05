<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || $user['role'] !== 'teacher') {
    die("Unauthorized access. This page is for teachers only.");
}

$uid = (int)($user['user_id'] ?? $user['id']);

// Handle Issue / Repair Reporting & Automatic Condemnation Trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    $ics_item_id = (int)$_POST['ics_item_id'];
    $affected_qty = (int)$_POST['affected_qty'];
    $new_condition = trim($_POST['item_condition']);
    $issue_description = trim($_POST['issue_description']);

    if (!$ics_item_id || $affected_qty <= 0 || empty($new_condition) || empty($issue_description)) {
        die("All fields are required and affected quantity must be at least 1.");
    }

    // Verify item belongs to teacher's active ICS
    $check = db()->prepare("
        SELECT ii.*, c.ics_number 
        FROM ics_items ii 
        JOIN ics c ON ii.ics_id = c.ics_id 
        WHERE ii.ics_item_id = ? AND c.teacher_id = ? AND c.status = 'active'
    ");
    $check->execute([$ics_item_id, $uid]);
    $itemData = $check->fetch();
    
    if ($itemData) {
        if ($affected_qty > $itemData['qty_issued']) {
            die("Error: Affected quantity cannot exceed total issued quantity (" . $itemData['qty_issued'] . ").");
        }

        db()->beginTransaction();
        try {
            // 1. Log the repair/issue with specific quantity in repair_logs
            $stmt = db()->prepare("
                INSERT INTO repair_logs (ics_item_id, reported_by, issue, action_taken, quantity_affected, condition_status, date_reported) 
                VALUES (?, ?, ?, 'Pending custodian review', ?, ?, NOW())
            ");
            $stmt->execute([$ics_item_id, $uid, $issue_description, $affected_qty, $new_condition]);

            // 2. AUTOMATIC CONDEMNATION TRIGGER: If marked as damaged, auto-queue it for condemnation!
            if ($new_condition === 'damaged') {
                $form_number = 'CF-' . date('Y') . '-' . rand(100, 999);
                $stmt_cond = db()->prepare("
                    INSERT INTO condemnations (form_number, ics_item_id, quantity, reason, requested_by, status, outcome) 
                    VALUES (?, ?, ?, ?, ?, 'requested', 'Pending Custodian Review')
                ");
                $stmt_cond->execute([$form_number, $ics_item_id, $affected_qty, $issue_description, $uid]);
            }

            // 3. Log history audit trail
            db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Reported Item Issue', 'Maintenance', ?, ?)")
                ->execute([$uid, $ics_item_id, "Reported $affected_qty unit(s) as $new_condition: $issue_description"]);

            db()->commit();
            header("Location: item_maintenance.php?success=1");
            exit;
        } catch (Exception $e) {
            db()->rollBack();
            die("Error logging issue: " . $e->getMessage());
        }
    } else {
        die("Invalid item selection.");
    }
}

// Fetch teacher's accountable items with accurate condition quantity calculations
$stmt = db()->prepare("
    SELECT ii.*, i.item_name, i.description, i.unit, c.ics_number, cr.course_name,
           COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair'), 0) as repair_qty,
           COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged'), 0) as damaged_qty,
           COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status = 'approved'), 0) as approved_condemned
    FROM ics_items ii
    JOIN ics c ON ii.ics_id = c.ics_id
    JOIN inventory_items i ON ii.item_id = i.item_id
    JOIN courses cr ON c.course_id = cr.course_id
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY c.date_issued DESC
");
$stmt->execute([$uid]);
$items = $stmt->fetchAll();

// Fetch repair/issue logs for this teacher's items
$stmt_repairs = db()->prepare("
    SELECT r.*, i.item_name, i.unit, c.ics_number 
    FROM repair_logs r
    JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id
    JOIN ics c ON ii.ics_id = c.ics_id
    JOIN inventory_items i ON ii.item_id = i.item_id
    WHERE c.teacher_id = ?
    ORDER BY r.date_reported DESC
");
$stmt_repairs->execute([$uid]);
$repair_logs = $stmt_repairs->fetchAll();

$pageTitle = 'Item Maintenance & Quantity Tracking';
$active = 'maintenance';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2>Item Maintenance & Repairs</h2>
        <p style="color: #666; margin: 0;">Review operational quantities and report equipment issues with precise counts.</p>
    </div>
    <button onclick="openReportModal()" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">+ Report Issue / Log Repair</button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        Issue successfully logged! If marked as damaged, it was automatically queued for condemnation review.
    </div>
<?php endif; ?>

<!-- Section 1: Detailed Quantity Breakdown by Condition -->
<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px;">
    <h3 style="margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px;">Accountable Inventory Quantity Breakdown</h3>
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                <th style="padding: 12px;">ICS No. / Course</th>
                <th style="padding: 12px;">Item Details</th>
                <th style="padding: 12px;">Total Issued</th>
                <th style="padding: 12px; color: #10b981;">Serviceable</th>
                <th style="padding: 12px; color: #d97706;">For Repair</th>
                <th style="padding: 12px; color: #dc2626;">Damaged / Disposed</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="6" style="padding: 20px; text-align: center; color: #666;">No items assigned to your account.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): 
                    $serviceable_qty = max(0, $item['qty_issued'] - $item['repair_qty'] - $item['damaged_qty'] - $item['approved_condemned']);
                ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= e($item['ics_number']) ?></strong><br>
                            <small style="color: #666;"><?= e($item['course_name']) ?></small>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?= e($item['item_name']) ?></strong><br>
                            <small style="color: #666;"><?= e($item['description']) ?></small>
                        </td>
                        <td style="padding: 12px; font-weight: bold;"><?= e($item['qty_issued']) ?> <?= e($item['unit']) ?></td>
                        <td style="padding: 12px; color: #10b981; font-weight: bold;"><?= $serviceable_qty ?></td>
                        <td style="padding: 12px; color: #d97706; font-weight: bold;"><?= e($item['repair_qty']) ?></td>
                        <td style="padding: 12px; color: #dc2626; font-weight: bold;">
                            <?= e($item['damaged_qty']) ?> 
                            <?php if ($item['approved_condemned'] > 0): ?>
                                <small>(<?= $item['approved_condemned'] ?> disposed)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Section 2: Active Maintenance & Repair History -->
<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <h3 style="margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px;">Reported Maintenance & Repair History</h3>
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                <th style="padding: 12px;">ICS No. / Item</th>
                <th style="padding: 12px;">Affected Qty & Status</th>
                <th style="padding: 12px;">Reported Issue</th>
                <th style="padding: 12px;">Action Status</th>
                <th style="padding: 12px;">Date Reported</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($repair_logs)): ?>
                <tr>
                    <td colspan="5" style="padding: 20px; text-align: center; color: #666;">No maintenance issues reported. All equipment running smoothly.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($repair_logs as $log): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= e($log['item_name']) ?></strong><br>
                            <small style="color: #666;">ICS: <?= e($log['ics_number']) ?></small>
                        </td>
                        <td style="padding: 12px; font-weight: bold; color: #b45309;">
                            <?= e($log['quantity_affected']) ?> <?= e($log['unit']) ?> 
                            <span style="font-size: 0.8em; display: block; color: #666; text-transform: uppercase;"><?= e(str_replace('_', ' ', $log['condition_status'])) ?></span>
                        </td>
                        <td style="padding: 12px; color: #b91c1c;"><?= e($log['issue']) ?></td>
                        <td style="padding: 12px;"><?= e($log['action_taken']) ?></td>
                        <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($log['date_reported']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Report Issue Modal with Quantity Input -->
<div id="reportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
    <div style="background: white; padding: 25px; border-radius: 8px; width: 450px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; color: #800000;">Report Equipment Issue / Log Repair</h3>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Select Equipment *</label>
                <select name="ics_item_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- Choose Accountable Item --</option>
                    <?php foreach ($items as $mi): ?>
                        <option value="<?= $mi['ics_item_id'] ?>">
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>) - Total Issued: <?= e($mi['qty_issued']) ?> <?= e($mi['unit']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Quantity Affected *</label>
                <input type="number" name="affected_qty" min="1" value="1" required style="width: 100%
                ; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" placeholder="How many units have an issue?">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Condition Status *</label>
                <select name="item_condition" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="for_repair">For Repair (Under Maintenance)</option>
                    <option value="damaged">Damaged (Auto-queue for Condemnation)</option>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Issue Description / Maintenance Notes *</label>
                <textarea name="issue_description" rows="3" required placeholder="Describe what is wrong with these specific units..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeReportModal()" style="padding: 8px 16px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" name="report_issue" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReportModal() {
    document.getElementById('reportModal').style.display = 'flex';
}
function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>