<?php
ob_start(); // Prevent header redirect failures
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user || $user['role'] !== 'teacher') {
    die("Unauthorized access. This page is for teachers only.");
}

$uid = (int)($user['user_id'] ?? $user['id']);

// Handle Issue Reporting AND Condition Resolution (Server-Side Validation First)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    $ics_item_id       = (int)$_POST['ics_item_id'];
    $affected_qty      = (int)$_POST['affected_qty'];
    $new_condition     = trim($_POST['item_condition']);
    $issue_description = trim($_POST['issue_description']);

    if (!$ics_item_id || $affected_qty <= 0 || empty($new_condition) || empty($issue_description)) {
        header("Location: item_maintenance.php?error=" . urlencode("All fields are required and affected quantity must be at least 1."));
        exit;
    }

    // Fetch item details and active condition counts BEFORE opening a transaction
    $check = db()->prepare("
        SELECT ii.*, c.ics_number,
               COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status = 'approved'), 0) as approved_condemned,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs WHERE ics_item_id = ii.ics_item_id AND condition_status = 'for_repair'), 0) as current_repair_qty,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs WHERE ics_item_id = ii.ics_item_id AND condition_status = 'damaged'), 0) as current_damaged_qty
        FROM ics_items ii 
        JOIN ics c ON ii.ics_id = c.ics_id 
        WHERE ii.ics_item_id = ? AND c.teacher_id = ? AND c.status = 'active'
    ");
    $check->execute([$ics_item_id, $uid]);
    $itemData = $check->fetch();
    
    if (!$itemData) {
        header("Location: item_maintenance.php?error=" . urlencode("Invalid item selection."));
        exit;
    }

    $serviceable_qty = $itemData['qty_issued'] - $itemData['current_repair_qty'] - $itemData['current_damaged_qty'] - $itemData['approved_condemned'];

    // STRICT VALIDATION CHECKS
    if ($new_condition === 'serviceable') {
        // Block: Prevent restoring items unless they are specifically marked 'For Repair'
        if ($itemData['current_repair_qty'] < $affected_qty) {
            header("Location: item_maintenance.php?error=" . urlencode("Action Blocked: You can only restore items currently marked as 'For Repair'. Damaged items are locked for Condemnation review."));
            exit;
        }
    } else {
        // Block: Prevent reporting issues if there isn't enough serviceable stock
        if ($serviceable_qty < $affected_qty) {
            header("Location: item_maintenance.php?error=" . urlencode("Action Blocked: You do not have enough Serviceable units available to report."));
            exit;
        }
    }

    // EXECUTE DB WRITES ONLY IF ALL VALIDATIONS PASS
    db()->beginTransaction();
    try {
        if ($new_condition === 'serviceable') {
            // Resolve open repair logs for this item
            $stmt_resolve = db()->prepare("
                UPDATE repair_logs 
                SET action_taken = ?, condition_status = 'resolved'
                WHERE ics_item_id = ? AND condition_status = 'for_repair'
            ");
            $stmt_resolve->execute(["Repaired/Serviced: " . $issue_description, $ics_item_id]);

            // Log restoration record
            $stmt_log = db()->prepare("
                INSERT INTO repair_logs (ics_item_id, reported_by, issue, action_taken, quantity_affected, condition_status, date_reported) 
                VALUES (?, ?, ?, 'Returned to Serviceable Status', ?, 'serviceable', NOW())
            ");
            $stmt_log->execute([$ics_item_id, $uid, "Equipment restored/repaired", $affected_qty]);

        } else {
            // Log issue for repair or damaged
            $stmt = db()->prepare("
                INSERT INTO repair_logs (ics_item_id, reported_by, issue, action_taken, quantity_affected, condition_status, date_reported) 
                VALUES (?, ?, ?, 'Pending custodian review', ?, ?, NOW())
            ");
            $stmt->execute([$ics_item_id, $uid, $issue_description, $affected_qty, $new_condition]);

            // Auto-queue condemnation if marked damaged
            if ($new_condition === 'damaged') {
                $form_number = 'CF-' . date('Y') . '-' . rand(100, 999);
                $stmt_cond = db()->prepare("
                    INSERT INTO condemnations (form_number, ics_item_id, quantity, reason, requested_by, status, outcome) 
                    VALUES (?, ?, ?, ?, ?, 'requested', 'Pending Custodian Review')
                ");
                $stmt_cond->execute([$form_number, $ics_item_id, $affected_qty, $issue_description, $uid]);
            }
        }

        // Audit Trail
        db()->prepare("INSERT INTO history_logs (user_id, action, module, record_id, details) VALUES (?, 'Updated Item Maintenance Status', 'Maintenance', ?, ?)")
            ->execute([$uid, $ics_item_id, "Updated $affected_qty unit(s) status to $new_condition: $issue_description"]);

        db()->commit();
        header("Location: item_maintenance.php?success=1");
        exit;
    } catch (Exception $e) {
        db()->rollBack();
        header("Location: item_maintenance.php?error=" . urlencode("Error processing request: " . $e->getMessage()));
        exit;
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
        <p style="color: #666; margin: 0;">Review operational quantities and report equipment issues or restore repaired equipment.</p>
    </div>
    <button onclick="openReportModal()" style="padding: 10px 18px; background: #800000; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.15);">+ Report Issue / Log Repair</button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid #10b981;">
        ✓ Maintenance action logged successfully! Condition breakdown and inventory counts have been updated.
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border-left: 4px solid #dc2626;">
        ✕ <?= e($_GET['error']) ?>
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
                                <small>(<?= e($item['approved_condemned']) ?> disposed)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Section 2: Maintenance & Repair History Logs -->
<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <h3 style="margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px;">Reported Maintenance & Repair History</h3>
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                <th style="padding: 12px;">ICS No. / Item</th>
                <th style="padding: 12px;">Affected Qty & Status</th>
                <th style="padding: 12px;">Reported Issue / Notes</th>
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
                        <td style="padding: 12px; font-weight: bold; color: <?= $log['condition_status'] === 'serviceable' || $log['condition_status'] === 'resolved' ? '#10b981' : ($log['condition_status'] === 'for_repair' ? '#b45309' : '#dc2626') ?>;">
                            <?= e($log['quantity_affected']) ?> <?= e($log['unit']) ?> 
                            <span style="font-size: 0.8em; display: block; color: #666; text-transform: uppercase;"><?= e(str_replace('_', ' ', $log['condition_status'])) ?></span>
                        </td>
                        <td style="padding: 12px;"><?= e($log['issue']) ?></td>
                        <td style="padding: 12px;"><?= e($log['action_taken']) ?></td>
                        <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($log['date_reported']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Popup -->
<div id="reportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999;">
    <div style="background: white; padding: 25px; border-radius: 8px; width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-top: 0; color: #800000; border-bottom: 2px solid #800000; padding-bottom: 8px;">Report Issue / Log Repair</h3>
        
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Select Equipment *</label>
                <select name="ics_item_id" id="ics_select" onchange="updateLiveItemDetails()" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em;">
                    <option value="">-- Choose Accountable Item --</option>
                    <?php foreach ($items as $mi): 
                        $serv_qty = max(0, $mi['qty_issued'] - $mi['repair_qty'] - $mi['damaged_qty'] - $mi['approved_condemned']);
                    ?>
                        <option value="<?= $mi['ics_item_id'] ?>" 
                                data-issued="<?= $mi['qty_issued'] ?>"
                                data-serviceable="<?= $serv_qty ?>"
                                data-repair="<?= $mi['repair_qty'] ?>"
                                data-damaged="<?= $mi['damaged_qty'] ?>"
                                data-condemned="<?= $mi['approved_condemned'] ?>"
                                data-unit="<?= e($mi['unit']) ?>">
                            <?= e($mi['item_name']) ?> (ICS: <?= e($mi['ics_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Live Condition Breakdown Box -->
            <div id="live_status_card" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 15px;">
                <div style="font-size: 0.85em; font-weight: bold; color: #475569; margin-bottom: 8px; text-transform: uppercase;">Current Quantity Status Breakdown:</div>
                <div style="display: flex; justify-content: space-between; text-align: center;">
                    <div>
                        <div style="font-size: 0.75em; color: #64748b;">Total</div>
                        <div id="badge_total" style="font-weight: bold; font-size: 1.1em; color: #0f172a;">0</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75em; color: #10b981; font-weight: bold;">Serviceable</div>
                        <div id="badge_serviceable" style="font-weight: bold; font-size: 1.1em; color: #10b981;">0</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75em; color: #d97706; font-weight: bold;">For Repair</div>
                        <div id="badge_repair" style="font-weight: bold; font-size: 1.1em; color: #d97706;">0</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75em; color: #dc2626; font-weight: bold;">Damaged</div>
                        <div id="badge_damaged" style="font-weight: bold; font-size: 1.1em; color: #dc2626;">0</div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Condition Status *</label>
                <select name="item_condition" id="condition_select" onchange="adjustQuantityLimits()" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="for_repair">For Repair (Under Maintenance)</option>
                    <option value="damaged">Damaged (Auto-queue for Condemnation)</option>
                    <option value="serviceable">Serviceable (Repaired / Working Fine)</option>
                </select>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Quantity Affected *</label>
                <input type="number" name="affected_qty" id="affected_qty_input" min="1" value="1" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" placeholder="How many units have an issue or were fixed?">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Issue Description / Maintenance Notes *</label>
                <textarea name="issue_description" rows="3" required placeholder="Describe what is wrong or what repair work was done..." style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeReportModal()" style="padding: 8px 16px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="submit" name="report_issue" id="submit_btn" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Submit Log</button>
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

function updateLiveItemDetails() {
    const select = document.getElementById('ics_select');
    const liveCard = document.getElementById('live_status_card');
    
    if (!select.value) {
        liveCard.style.display = 'none';
        return;
    }

    const selectedOption = select.options[select.selectedIndex];
    const issued = parseInt(selectedOption.getAttribute('data-issued') || '0');
    const serviceable = parseInt(selectedOption.getAttribute('data-serviceable') || '0');
    const repair = parseInt(selectedOption.getAttribute('data-repair') || '0');
    const damaged = parseInt(selectedOption.getAttribute('data-damaged') || '0');
    const unit = selectedOption.getAttribute('data-unit');

    document.getElementById('badge_total').innerText = issued + ' ' + unit;
    document.getElementById('badge_serviceable').innerText = serviceable;
    document.getElementById('badge_repair').innerText = repair;
    document.getElementById('badge_damaged').innerText = damaged;

    liveCard.style.display = 'block';
    adjustQuantityLimits();
}

function adjustQuantityLimits() {
    const select = document.getElementById('ics_select');
    const conditionSelect = document.getElementById('condition_select');
    const qtyInput = document.getElementById('affected_qty_input');
    const serviceableOpt = conditionSelect.querySelector('option[value="serviceable"]');
    
    if (!select.value) return;

    const selectedOption = select.options[select.selectedIndex];
    const repair = parseInt(selectedOption.getAttribute('data-repair') || '0');
    const serviceable = parseInt(selectedOption.getAttribute('data-serviceable') || '0');

    // UI Lock: If there are NO items currently 'For Repair', completely disable the Serviceable option
    if (repair <= 0) {
        serviceableOpt.disabled = true;
        serviceableOpt.innerText = "Serviceable (Locked - Nothing For Repair)";
        if (conditionSelect.value === 'serviceable') {
            conditionSelect.value = 'for_repair'; // Auto-switch away if they were hovering on it
        }
    } else {
        serviceableOpt.disabled = false;
        serviceableOpt.innerText = "Serviceable (Repaired / Working Fine)";
    }

    // Set strict Max limits based on what they are trying to do
    if (conditionSelect.value === 'serviceable') {
        qtyInput.max = repair; // Can only restore up to how many are currently broken
        if (parseInt(qtyInput.value) > repair) qtyInput.value = repair;
    } else {
        qtyInput.max = serviceable; // Can only report issues on currently healthy stock
        if (parseInt(qtyInput.value) > serviceable) qtyInput.value = serviceable;
    }
}
</script>

<?php 
require_once __DIR__ . '/includes/footer.php'; 
ob_end_flush();
?>