<?php
ob_start(); // Prevent header redirect failures
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ics_accountability.php';

$user = current_user();
if (!$user || $user['role'] !== 'teacher') {
    die("Unauthorized access. This page is for teachers only.");
}

$uid = (int)($user['user_id'] ?? $user['id']);

// Approved condemnation quantities overlap the retained damaged repair logs.
// Split that pool for display instead of deducting disposed units twice.
// This follows the aggregate convention also used by borrow.php.
function maintenance_stock_quantities(array $item): array
{
    return [
        'serviceable' => (int)$item['qty_serviceable'],
        'repair' => (int)$item['qty_for_repair'],
        'damaged' => (int)$item['qty_damaged'],
        'disposed' => (int)$item['approved_condemned'],
    ];
}

// Update current custody AND legacy repair history in one database transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    csrf_check();
    $icsItemId=(int)($_POST['ics_item_id']??0);
    $rawQty=(string)($_POST['affected_qty']??'');
    $condition=(string)($_POST['item_condition']??'');
    $description=trim((string)($_POST['issue_description']??''));
    try {
        if(!ctype_digit($rawQty) || strlen($rawQty)>9 || (int)$rawQty<1 || strlen($description)<3 || !in_array($condition,['for_repair','damaged','serviceable'],true))
            throw new RuntimeException('Choose an item, enter a positive whole-number quantity and describe the action.');
        $qty=(int)$rawQty;
        $pdo=db();$pdo->beginTransaction();
        $st=$pdo->prepare("SELECT c.teacher_id,c.status FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.ics_item_id=? FOR UPDATE");
        $st->execute([$icsItemId]);$record=$st->fetch(PDO::FETCH_ASSOC);
        if(!$record || (int)$record['teacher_id']!==$uid || $record['status']!=='active')
            throw new RuntimeException('You cannot modify equipment that is no longer on your active ICS.');
        $state=ics31_state($pdo,$icsItemId,true);
        if(!$state['is_equipment'] || $state['remaining_qty']<1)throw new RuntimeException('Only equipment still under your accountability can be reported.');
        if($state['needs_review'])throw new RuntimeException('The Supply Officer must verify this ICS balance before maintenance changes can be recorded.');
        $pools=['serviceable'=>$state['qty_serviceable'],'repair'=>$state['qty_for_repair'],'damaged'=>$state['qty_damaged']];
        if($condition==='serviceable') {
            if($qty>$pools['repair'])throw new RuntimeException('Only '.$pools['repair'].' unit(s) currently need repair.');
            $pools['repair']-=$qty;$pools['serviceable']+=$qty;
            // Reduce legacy open-repair stacks too; other v5.6 modules still read them.
            $open=$pdo->prepare("SELECT repair_id,quantity_affected FROM repair_logs WHERE ics_item_id=? AND condition_status='for_repair' ORDER BY repair_id FOR UPDATE");
            $open->execute([$icsItemId]);$toResolve=$qty;
            foreach($open->fetchAll(PDO::FETCH_ASSOC) as $rep){
                if(!$toResolve)break;
                $take=min($toResolve,(int)$rep['quantity_affected']);
                if($take===(int)$rep['quantity_affected'])$pdo->prepare("UPDATE repair_logs SET condition_status='resolved',action_taken=? WHERE repair_id=?")->execute(['Repaired: '.$description,$rep['repair_id']]);
                else $pdo->prepare('UPDATE repair_logs SET quantity_affected=quantity_affected-? WHERE repair_id=?')->execute([$take,$rep['repair_id']]);
                $toResolve-=$take;
            }
            $stmt=$pdo->prepare("INSERT INTO repair_logs (ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES (?,?,?,'Returned to Serviceable Status',CURRENT_DATE(),?,'serviceable')");
            $stmt->execute([$icsItemId,$uid,$description,$qty]);
        } else {
            if($qty>$pools['serviceable'])throw new RuntimeException('Only '.$pools['serviceable'].' serviceable unit(s) are available to report.');
            $pools['serviceable']-=$qty;
            $pools[$condition==='for_repair'?'repair':'damaged']+=$qty;
            $stmt=$pdo->prepare("INSERT INTO repair_logs (ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES (?,?,?,'Pending maintenance review',CURRENT_DATE(),?,?)");
            $stmt->execute([$icsItemId,$uid,$description,$qty,$condition]);
            if($condition==='damaged') {
                $formNumber=generate_document_number('condemnation');
                $pdo->prepare("INSERT INTO condemnations (form_number,ics_item_id,quantity,reason,requested_by,status,outcome) VALUES (?,?,?,?,?,'requested',NULL)")->execute([$formNumber,$icsItemId,$qty,$description,$uid]);
            }
        }
        ics31_save($pdo,$icsItemId,$pools);
        log_action($uid,'Updated Item Maintenance Status','Maintenance',$icsItemId,"Changed $qty accountable unit(s) to $condition: $description");
        $pdo->commit();
        header('Location: item_maintenance.php?success=1');exit;
    } catch(Throwable $ex) {
        if(db()->inTransaction())db()->rollBack();
        header('Location: item_maintenance.php?error='.urlencode($ex->getMessage()));exit;
    }
}

// Fetch teacher's accountable items
$stmt = db()->prepare("
    SELECT ii.*,i.item_name,i.description,i.unit,c.ics_number,cr.course_name,
     cb.qty_serviceable,cb.qty_for_repair,cb.qty_damaged,cb.needs_review,
     COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0) AS approved_condemned
    FROM ics_items ii JOIN ics c ON ii.ics_id=c.ics_id
    JOIN inventory_items i ON i.item_id=ii.item_id
    LEFT JOIN courses cr ON cr.course_id=c.course_id
    JOIN ics_condition_balances cb ON cb.ics_item_id=ii.ics_item_id
    WHERE c.teacher_id=? AND c.status='active' AND i.item_category IN ('equipment','non_consumable')
      AND (cb.qty_serviceable+cb.qty_for_repair+cb.qty_damaged)>0
    ORDER BY c.date_issued DESC,ii.ics_item_id DESC
");
$stmt->execute([$uid]);
$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($items as &$i){$i['repair_qty']=$i['qty_for_repair'];$i['damaged_qty']=$i['qty_damaged'];$i['currently_accountable']=(int)$i['qty_serviceable']+(int)$i['qty_for_repair']+(int)$i['qty_damaged'];}unset($i);

// Fetch repair logs (Ordering active "for_repair" items to the top for UX)
$stmt_repairs = db()->prepare("
    SELECT r.*, i.item_name, i.unit, c.ics_number 
    FROM repair_logs r
    JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id
    JOIN ics c ON ii.ics_id = c.ics_id
    JOIN inventory_items i ON ii.item_id = i.item_id
    WHERE c.teacher_id = ?
    ORDER BY 
        CASE WHEN r.condition_status = 'for_repair' THEN 1 ELSE 2 END ASC, 
        r.date_reported DESC
");
$stmt_repairs->execute([$uid]);
$repair_logs = $stmt_repairs->fetchAll();

$pageTitle = 'Item Maintenance & Quantity Tracking';
$active = 'item_maintenance.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Item Maintenance & Repairs</h2>
        <p class="legacy-027">Review operational quantities and report equipment issues or restore repaired equipment.</p>
    </div>
    <button onclick="openReportModal()" class="legacy-188">+ Report Issue / Log Repair</button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="legacy-189">
        ✓ Maintenance action logged successfully! Condition breakdown and inventory counts have been updated.
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="legacy-190">
        ✕ <?= e($_GET['error']) ?>
    </div>
<?php endif; ?>

<!-- Section 1: Detailed Quantity Breakdown by Condition -->
<div class="card legacy-191">
    <h3 class="legacy-192">Accountable Inventory Quantity Breakdown</h3>
    <p class="field-help">Original ICS issued = already retrieved + disposed + current teacher accountability. Only current accountable equipment may be reported for maintenance.</p>
    <table class="legacy-031">
        <thead>
            <tr class="legacy-032">
                <th class="legacy-033">ICS No. / Course</th>
                <th class="legacy-033">Item Details</th>
                <th class="legacy-033">Original ICS</th><th class="legacy-033">Already retrieved</th><th class="legacy-033">Currently accountable</th>
                <th class="legacy-087">Serviceable</th>
                <th class="legacy-088">For Repair</th>
                <th class="legacy-089">Damaged</th>
                <th class="legacy-033">Disposed</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="9" class="legacy-035">No equipment currently under your active accountability.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): 
                    $stock = maintenance_stock_quantities($item);
                    $serviceable_qty = $stock['serviceable'];
                ?>
                    <tr class="legacy-036">
                        <td class="legacy-033">
                            <strong><?= e($item['ics_number']) ?></strong><br>
                            <small class="legacy-037"><?= e($item['course_name']) ?></small>
                        </td>
                        <td class="legacy-033">
                            <strong><?= e($item['item_name']) ?></strong><br>
                            <small class="legacy-037"><?= e($item['description']) ?></small>
                        </td>
                        <td class="legacy-091"><?= e($item['qty_issued']) ?> <?= e($item['unit']) ?></td><td class="legacy-033"><?= (int)$item['qty_returned'] ?></td><td class="legacy-033"><strong><?= (int)$item['currently_accountable'] ?></strong></td>
                        <td class="legacy-092"><?= $serviceable_qty ?><?php if($item['needs_review']):?><br><small style="color:#a15f00">Awaiting custodian verification</small><?php endif;?></td>
                        <td class="legacy-093"><?= (int)$item['repair_qty'] ?></td>
                        <td class="legacy-094"><?= $stock['damaged'] ?></td>
                        <td class="legacy-033"><?= $stock['disposed'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Section 2: Maintenance & Repair History Logs -->
<div class="card legacy-030">
    <h3 class="legacy-192">Reported Maintenance & Repair History</h3>
    <table class="legacy-031">
        <thead>
            <tr class="legacy-032">
                <th class="legacy-033">ICS No. / Item</th>
                <th class="legacy-033">Affected Qty & Status</th>
                <th class="legacy-033">Reported Issue / Notes</th>
                <th class="legacy-033">Action Status</th>
                <th class="legacy-033">Date Reported</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($repair_logs)): ?>
                <tr>
                    <td colspan="5" class="legacy-035">No maintenance issues reported.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($repair_logs as $log): 
                    // UX Tweak: Highlight active repair groups
                    $isActiveRepair = ($log['condition_status'] === 'for_repair');
                    $rowBg = $isActiveRepair ? '#fef3c7' : 'transparent';
                ?>
                    <tr class="legacy-193 <?= $isActiveRepair ? 'repair-active' : '' ?>">
                        <td class="legacy-033">
                            <strong><?= e($log['item_name']) ?></strong><br>
                            <small class="legacy-037">ICS: <?= e($log['ics_number']) ?></small>
                        </td>
                        <td class="legacy-194 <?= in_array($log['condition_status'], ['serviceable','resolved'], true) ? 'text-success' : ($log['condition_status'] === 'for_repair' ? 'text-warning' : 'text-danger') ?>">
                            <?= e($log['quantity_affected']) ?> <?= e($log['unit']) ?> 
                            <span class="legacy-195">
                                <?= $isActiveRepair ? '⚠️ ACTIVE REPAIR' : e(str_replace('_', ' ', $log['condition_status'])) ?>
                            </span>
                        </td>
                        <td class="legacy-033"><?= e($log['issue']) ?></td>
                        <td class="legacy-033"><?= e($log['action_taken']) ?></td>
                        <td class="legacy-033"><?= e(date('M d, Y', strtotime($log['date_reported']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Guided maintenance form: existing POST names and server validation retained. -->
<div id="reportModal" class="modal-overlay" hidden>
    <section class="guided-modal">
        <div class="eyebrow">Equipment maintenance</div>
        <h3>Report an issue or record a repair</h3>
        <p class="modal-intro">Choose your equipment, tell us what happened, and enter the number of units affected.</p>
        <form method="POST" id="maintenanceForm">
            <?= csrf_field() ?>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">1</span><h4>Choose equipment</h4></div>
                <div class="field">
                    <label for="ics_select">Equipment from your active ICS <span class="required-mark">*</span></label>
                    <select name="ics_item_id" id="ics_select" onchange="updateLiveItemDetails()" required class="input" data-initial-focus aria-describedby="equipmentHelp">
                        <option value="">Select an accountable item</option>
                        <?php foreach ($items as $mi):
                            // Use the same breakdown as the table and POST validation.
                            $stock = maintenance_stock_quantities($mi);
                            $serv_qty = $stock['serviceable'];
                        ?>
                            <option value="<?= (int)$mi['ics_item_id'] ?>" data-issued="<?= (int)$mi['currently_accountable'] ?>" data-serviceable="<?= (int)$serv_qty ?>" data-repair="<?= (int)$mi['repair_qty'] ?>" data-damaged="<?= $stock['damaged'] ?>" data-condemned="<?= (int)$mi['approved_condemned'] ?>" data-review="<?= (int)$mi['needs_review'] ?>" data-unit="<?= e($mi['unit']) ?>">
                                <?= e($mi['item_name']) ?> — <?= e($mi['ics_number']) ?><?= $mi['needs_review'] ? ' (Verification Required)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-help" id="equipmentHelp">Only equipment assigned to your active custody records is listed.</small>
                </div>
                <?php if (!$items): ?><p class="form-notice">You have no equipment in an active ICS. Contact your Supply Officer before submitting a maintenance record.</p><?php endif; ?>
                <div id="live_status_card" class="equipment-snapshot" hidden>
                    <div class="snapshot-heading">Current stock <strong id="badge_total">0</strong></div>
                    <div class="quantity-tiles">
                        <div><span>Serviceable</span><strong id="badge_serviceable" class="text-success">0</strong></div>
                        <div><span>For repair</span><strong id="badge_repair" class="text-warning">0</strong></div>
                        <div><span>Damaged</span><strong id="badge_damaged" class="text-danger">0</strong></div>
                        <div><span>Disposed</span><strong id="badge_condemned">0</strong></div>
                    </div>
                </div>
            </section>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">2</span><h4>What would you like to do?</h4></div>
                <fieldset class="action-choice-set">
                    <legend class="visually-hidden">Maintenance action</legend>
                    <div class="action-choices">
                        <label class="action-choice"><input type="radio" name="item_condition" value="for_repair" checked onchange="adjustQuantityLimits()"><span><strong>Needs repair</strong><small>Move working units to maintenance.</small></span></label>
                        <label class="action-choice"><input type="radio" name="item_condition" value="damaged" onchange="adjustQuantityLimits()"><span><strong>Damaged</strong><small>Request condemnation review.</small></span></label>
                        <label class="action-choice"><input type="radio" name="item_condition" value="serviceable" onchange="adjustQuantityLimits()"><span><strong>Repair completed</strong><small>Return repaired units to service.</small></span></label>
                    </div>
                </fieldset>
                <p id="maintenanceActionHelp" class="field-help">Select equipment to see which actions are available.</p>
                <div class="quantity-entry">
                    <div class="field"><label for="affected_qty_input">Number of units <span class="required-mark">*</span></label><input type="number" name="affected_qty" id="affected_qty_input" min="1" step="1" value="1" required class="input" oninput="updateMaintenanceQuantity()" aria-describedby="maintenanceLimit"></div>
                    <p id="maintenanceLimit" class="quantity-limit" aria-live="polite">Choose equipment first.</p>
                </div>
            </section>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">3</span><h4>Add details</h4></div>
                <div class="field"><label for="maintenanceNotes" id="maintenanceNotesLabel">Issue description <span class="required-mark">*</span></label><textarea id="maintenanceNotes" name="issue_description" rows="3" required class="input" placeholder="Describe the fault, when it started, and any checks you have made."></textarea></div>
            </section>
            <div class="form-review" id="maintenanceReview" aria-live="polite">Your record will appear in the maintenance log after submission.</div>
            <div class="modal-actions"><button type="button" onclick="closeReportModal()" class="btn btn-outline">Cancel</button><button type="submit" name="report_issue" id="submit_btn" class="btn btn-primary" disabled>Save maintenance record</button></div>
        </form>
    </section>
</div>
<script>
function openReportModal() {
    updateLiveItemDetails();
    TNTSUI.open('reportModal');
}
function closeReportModal() { TNTSUI.close('reportModal'); }
function updateLiveItemDetails() {
    const select = document.getElementById('ics_select');
    const card = document.getElementById('live_status_card');
    card.hidden = !select.value;
    if (select.value) {
        const option = select.options[select.selectedIndex];
        document.getElementById('badge_total').textContent = option.dataset.issued + ' ' + option.dataset.unit + ' currently accountable';
        ['serviceable', 'repair', 'damaged', 'condemned'].forEach(key => {
            document.getElementById('badge_' + key).textContent = option.dataset[key] || '0';
        });
    }
    adjustQuantityLimits();
}
function adjustQuantityLimits() {
    const select = document.getElementById('ics_select');
    const option = select.value ? select.options[select.selectedIndex] : null;
    const actions = Array.from(document.querySelectorAll('#maintenanceForm input[name="item_condition"]'));
    const working = option ? Number(option.dataset.serviceable) : 0;
    const repair = option ? Number(option.dataset.repair) : 0;
    const review = option && option.dataset.review === '1';
    actions.forEach(action => {
        action.disabled = !option || review || (action.value === 'serviceable' ? repair : working) <= 0;
        action.closest('label').classList.toggle('choice-disabled', action.disabled);
    });
    const selected = actions.find(action => action.checked);
    if (!selected || selected.disabled) {
        actions.forEach(action => action.checked = false);
        const firstAvailable = actions.find(action => !action.disabled);
        if (firstAvailable) firstAvailable.checked = true;
    }
    const active = actions.find(action => action.checked && !action.disabled);
    const maximum = active ? (active.value === 'serviceable' ? repair : working) : 0;
    const quantity = document.getElementById('affected_qty_input');
    quantity.disabled = !maximum;
    if (maximum) {
        quantity.max = String(maximum);
        quantity.value = String(Math.max(1, Math.min(maximum, Number(quantity.value) || 1)));
    } else {
        quantity.removeAttribute('max'); quantity.value = '1';
    }
    const completed = active && active.value === 'serviceable';
    const damaged = active && active.value === 'damaged';
    document.getElementById('maintenanceNotesLabel').innerHTML = (completed ? 'Repair work completed' : 'Issue description') + ' <span class="required-mark">*</span>';
    document.getElementById('maintenanceNotes').placeholder = completed ? 'Describe the repair and checks performed before returning these units to service.' : 'Describe the fault, when it started, and any checks you have made.';
    document.getElementById('maintenanceActionHelp').textContent = !option ? 'Select equipment to see which actions are available.' : !active ? 'No units are available for a new issue or repair completion.' : completed ? 'Only units currently marked for repair can be returned to service.' : damaged ? 'This action automatically queues a condemnation request for Custodian review.' : 'Only available working units can be moved to maintenance.';
    document.getElementById('maintenanceLimit').textContent = maximum ? 'You can record up to ' + maximum + ' ' + option.dataset.unit + '.' : 'No units available for this action.';
    updateMaintenanceQuantity();
}
function updateMaintenanceQuantity() {
    const qty = document.getElementById('affected_qty_input');
    const action = document.querySelector('#maintenanceForm input[name="item_condition"]:checked');
    const valid = !qty.disabled && Number.isInteger(Number(qty.value)) && Number(qty.value) >= 1 && Number(qty.value) <= Number(qty.max);
    qty.setCustomValidity(valid || qty.disabled ? '' : 'Enter a whole number from 1 to ' + qty.max + '.');
    document.getElementById('submit_btn').disabled = !valid || !action || action.disabled;
    const labels = { for_repair: 'Report for repair', damaged: 'Report damaged equipment', serviceable: 'Record completed repair' };
    document.getElementById('submit_btn').textContent = action && !action.disabled ? labels[action.value] : 'Save maintenance record';
    document.getElementById('maintenanceReview').textContent = valid && action ? Number(qty.value) + ' unit(s) will be recorded as ' + ({ for_repair: 'for repair', damaged: 'damaged, pending condemnation review', serviceable: 'repaired and returned to service' })[action.value] + '.' : 'Choose an available action and enter a valid quantity to continue.';
}
</script>

<?php 
require_once __DIR__ . '/includes/footer.php'; 
ob_end_flush();
?>