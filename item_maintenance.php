<?php
ob_start(); // Prevent header redirect failures
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ics_accountability.php';
require_once __DIR__ . '/includes/maintenance_safety.php';
require_once __DIR__ . '/includes/p72_batches.php';
require_once __DIR__ . '/includes/p72_evidence.php';
require_once __DIR__ . '/includes/p72_picker.php';

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
    $allocationId=(int)($_POST['allocation_id']??0);
    $savedPhoto=null;
    try {
        if(!ctype_digit($rawQty) || strlen($rawQty)>9 || (int)$rawQty<1 || strlen($description)<3 || !in_array($condition,['for_repair','damaged','serviceable','damaged_to_repair','repair_to_damaged'],true))
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
        $available=p71_availability($pdo,$icsItemId,$state);
        $verifiedAllocation=p72_validate_batch($pdo,$icsItemId,$allocationId,$qty);
        if($available['custody_inconsistent'])throw new RuntimeException('Outstanding borrowed units exceed the recorded serviceable balance. Supply Officer reconciliation is required before further maintenance changes.');
        $pools=['serviceable'=>$state['qty_serviceable'],'repair'=>$state['qty_for_repair'],'damaged'=>$state['qty_damaged']];
        $repairDate=(string)($_POST['repair_date']??'');
        if($condition==='serviceable'){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$repairDate);
            if(!$date || $date->format('Y-m-d')!==$repairDate || $repairDate>date('Y-m-d'))
                throw new RuntimeException('Enter the actual repair completion date (not in the future).');
        }
        if(in_array($condition,['for_repair','damaged'],true) && $qty>$available['serviceable'])
            throw new RuntimeException('Only '.$available['serviceable'].' unborrowed and unreserved serviceable unit(s) can be changed.');
        if($condition==='damaged_to_repair' && $qty>$available['damaged'])
            throw new RuntimeException('Only '.$available['damaged'].' damaged unit(s) without active condemnation review can be repaired.');
        if($condition==='serviceable') {
            if($qty>$pools['repair'])throw new RuntimeException('Only '.$pools['repair'].' unit(s) currently need repair.');
            $pools['repair']-=$qty;$pools['serviceable']+=$qty;
            p71_close_repair_logs($pdo,$icsItemId,$qty,'Repaired: '.$description,$repairDate);
            $stmt=$pdo->prepare("INSERT INTO repair_logs (ics_item_id,reported_by,issue,action_taken,date_reported,date_repaired,quantity_affected,condition_status) VALUES (?,?,?,'Returned to Serviceable Status',CURRENT_DATE(),?,?,'serviceable')");
            $stmt->execute([$icsItemId,$uid,$description,$repairDate,$qty]);
        } elseif ($condition==='damaged_to_repair') {
            $pools['damaged']-=$qty; $pools['repair']+=$qty;
            $pdo->prepare("INSERT INTO repair_logs(ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES(?,?,?,'Damaged equipment moved into repair',CURRENT_DATE(),?,'for_repair')")->execute([$icsItemId,$uid,$description,$qty]);
        } elseif ($condition==='repair_to_damaged') {
            if($qty>$pools['repair'])throw new RuntimeException('Only '.$pools['repair'].' unit(s) are currently under repair.');
            $pools['repair']-=$qty; $pools['damaged']+=$qty;
            p71_close_repair_logs($pdo,$icsItemId,$qty,'Repair unsuccessful: '.$description);
            $pdo->prepare("INSERT INTO repair_logs(ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES(?,?,?,'Repair unsuccessful; remains damaged',CURRENT_DATE(),?,'damaged')")->execute([$icsItemId,$uid,$description,$qty]);
        } else {
            if($qty>$pools['serviceable'])throw new RuntimeException('Only '.$pools['serviceable'].' serviceable unit(s) are available to report.');
            $pools['serviceable']-=$qty;
            $pools[$condition==='for_repair'?'repair':'damaged']+=$qty;
            $stmt=$pdo->prepare("INSERT INTO repair_logs (ics_item_id,reported_by,issue,action_taken,date_reported,quantity_affected,condition_status) VALUES (?,?,?,'Teacher recorded condition change',CURRENT_DATE(),?,?)");
            $stmt->execute([$icsItemId,$uid,$description,$qty,$condition]);
        }
        // Chronological event log is append-only, unlike the legacy repair_logs lifecycle rows.
        $legacyId=(int)$pdo->lastInsertId();
        $from=in_array($condition,['serviceable','repair_to_damaged'],true)?'for_repair':($condition==='damaged_to_repair'?'damaged':'serviceable');
        $to=$condition==='serviceable'?'serviceable':($condition==='for_repair'||$condition==='damaged_to_repair'?'for_repair':'damaged');
        $savedPhoto=p72_save_evidence($_FILES['maintenance_photo']??null);
        $pdo->prepare('INSERT INTO maintenance_events(ics_item_id,actor_user_id,action_code,from_condition,to_condition,quantity,description,work_date,allocation_id,photo_file,legacy_repair_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
             ->execute([$icsItemId,$uid,$condition,$from,$to,$qty,$description,$condition==='serviceable'?$repairDate:null,$verifiedAllocation,$savedPhoto,$legacyId?:null]);
        ics31_save($pdo,$icsItemId,$pools);
        log_action($uid,'Updated Item Maintenance Status','Maintenance',$icsItemId,"Recorded maintenance transition ($condition), $qty unit(s): $description");
        $pdo->commit();
        header('Location: item_maintenance.php?success=1');exit;
    } catch(Throwable $ex) {
        if(db()->inTransaction())db()->rollBack();
        p72_delete_evidence($savedPhoto);
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
    LEFT JOIN courses cr ON cr.course_id=ii.course_id
    JOIN ics_condition_balances cb ON cb.ics_item_id=ii.ics_item_id
    WHERE c.teacher_id=? AND c.status='active' AND i.item_category IN ('equipment','non_consumable')
      AND (cb.qty_serviceable+cb.qty_for_repair+cb.qty_damaged)>0
    ORDER BY c.date_issued DESC,ii.ics_item_id DESC
");
$stmt->execute([$uid]);
$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($items as &$i){$i['maintenance_available']=p71_availability(db(),(int)$i['ics_item_id'],$i);$i['repair_qty']=$i['qty_for_repair'];$i['damaged_qty']=$i['qty_damaged'];$i['currently_accountable']=(int)$i['qty_serviceable']+(int)$i['qty_for_repair']+(int)$i['qty_damaged'];}unset($i);
$maintenanceBatches=p72_batch_map(db(),array_column($items,'ics_item_id'));

// New immutable events, newest first; historical pre-7.2 repair entries remain available.
$ev=db()->prepare("SELECT ev.*,i.item_name,i.unit,c.ics_number,u.full_name AS actor_name,rb.batch_number,rb.batch_id AS receipt_batch_id,d.receiving_number
    FROM maintenance_events ev JOIN ics_items ii ON ii.ics_item_id=ev.ics_item_id
    JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
    LEFT JOIN users u ON u.user_id=ev.actor_user_id
    LEFT JOIN ics_batch_allocations a ON a.allocation_id=ev.allocation_id
    LEFT JOIN inventory_receipt_batches rb ON rb.batch_id=a.batch_id
    LEFT JOIN delivery_items di ON di.delivery_item_id=rb.delivery_item_id
    LEFT JOIN deliveries d ON d.delivery_id=di.delivery_id
    WHERE c.teacher_id=? ORDER BY ev.created_at DESC,ev.event_id DESC");
$ev->execute([$uid]);$maintenanceEvents=$ev->fetchAll(PDO::FETCH_ASSOC);
$legacy=db()->prepare("SELECT r.*,i.item_name,i.unit,c.ics_number FROM repair_logs r
 JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id
 JOIN inventory_items i ON i.item_id=ii.item_id
 LEFT JOIN maintenance_events ev ON ev.legacy_repair_id=r.repair_id
 WHERE c.teacher_id=? AND ev.event_id IS NULL ORDER BY r.date_reported DESC,r.repair_id DESC");
$legacy->execute([$uid]);$olderRepairLogs=$legacy->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Item Maintenance & Quantity Tracking';
$active = 'item_maintenance.php';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <div>
        <h2>Item Maintenance & Repairs</h2>
        <p class="legacy-027">Choose an accountable item below to report damage, start maintenance, or record a completed repair.</p>
    </div>

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

<!-- Phase 7.2c: Inventory-style records; compact rows on desktop and labeled cards on phones.
     This is the same server-rendered data, exact ICS item IDs and maintenance POST handler. -->
<section class="card p72b-equipment p72c-browser" id="p72bEquipment" aria-labelledby="p72bEquipmentTitle">
    <div class="p72c-heading">
        <div>
            <h3 id="p72bEquipmentTitle">Accountable Equipment</h3>
            <p>Search an ICS item and select <strong>Maintain item</strong> to report an issue or repair.</p>
        </div>
        <span class="p72c-heading-total"><?= count($items) ?> record<?= count($items)===1?'':'s' ?></span>
    </div>
    <div class="p72b-equipment-filters p72c-filters" role="search" aria-label="Find accountable equipment">
        <label for="p72bItemSearch">Search equipment, ICS, course or batch
            <input id="p72bItemSearch" class="input" type="search" placeholder="Search records..." autocomplete="off">
        </label>
        <label for="p72bConditionFilter">Stock / condition
            <select id="p72bConditionFilter" class="input">
                <option value="all">All conditions</option>
                <option value="serviceable">Available serviceable</option>
                <option value="repair">For repair</option>
                <option value="damaged">Damaged</option>
                <option value="borrowed">Borrowed / on loan</option>
            </select>
        </label>
        <label for="p72bItemSort">Quick sort
            <select id="p72bItemSort" class="input">
                <option value="default">Default order</option>
                <option value="az">Item name A–Z</option>
                <option value="za">Item name Z–A</option>
            </select>
        </label>
        <label for="p72cPageSize">Show
            <select id="p72cPageSize" class="input"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select>
        </label>
    </div>
    <p id="p72bItemResults" class="p72b-results-caption p72c-results" role="status" aria-live="polite"></p>
    <div class="p72c-table-area">
        <table class="p72c-table" aria-label="Accountable equipment and condition breakdown">
            <thead><tr>
                <th scope="col">Item</th>
                <th scope="col">ICS / Course</th>
                <th scope="col">Original ICS</th>
                <th scope="col">Quantity / Condition</th>
                <th scope="col">Batch / Custody</th>
                <th scope="col">Action</th>
            </tr></thead>
            <tbody id="p72bItemList">
            <?php foreach($items as $index => $item):
                $stock=maintenance_stock_quantities($item);
                $available=$item['maintenance_available'];
                $batchLabels=array_column($maintenanceBatches[(int)$item['ics_item_id']]??[],'label');
                $searchTerms=implode(' ',[$item['item_name'],$item['description'],$item['ics_number'],$item['course_name']??'',implode(' ',$batchLabels)]);
                $canMaintain= !$item['needs_review'] && !$available['custody_inconsistent'] && ((int)$available['serviceable'] > 0 || (int)$item['repair_qty'] > 0 || (int)$available['damaged'] > 0);
            ?>
            <tr class="p72c-record" data-index="<?= (int)$index ?>"
                data-name="<?= e(mb_strtolower((string)$item['item_name'])) ?>"
                data-search="<?= e(mb_strtolower($searchTerms)) ?>"
                data-serviceable="<?= (int)$available['serviceable'] ?>"
                data-repair="<?= (int)$item['repair_qty'] ?>"
                data-damaged="<?= (int)$available['damaged'] ?>"
                data-borrowed="<?= (int)$available['borrowed'] ?>">
                <td data-label="Item" class="p72c-name-cell">
                    <div class="p72c-name"><strong><?= e($item['item_name']) ?></strong>
                        <?php if(trim((string)$item['description'])!==''):?><small><?= e($item['description']) ?></small><?php endif; ?>
                    </div>
                </td>
                <td data-label="ICS / Course">
                    <div class="p72c-ics"><strong><?= e($item['ics_number']) ?></strong><small><?= e($item['course_name']?:'Unclassified') ?></small></div>
                </td>
                <td data-label="Original ICS"><span class="p72c-number"><strong><?= (int)$item['qty_issued'] ?></strong> <?= e($item['unit']) ?></span></td>
                <td data-label="Quantity / Condition">
                    <div class="p72c-qty">
                        <strong>On Teacher Record: <?= (int)$item['currently_accountable'] ?></strong>
                        <div class="p72c-statuses">
                            <span class="p72c-pill p72c-pill-serviceable">Serviceable <?= (int)$stock['serviceable'] ?></span>
                            <span class="p72c-pill p72c-pill-repair">For Repair <?= (int)$stock['repair'] ?></span>
                            <span class="p72c-pill p72c-pill-damaged">Damaged <?= (int)$stock['damaged'] ?></span>
                            <span class="p72c-pill p72c-pill-borrowed">Borrowed <?= (int)$available['borrowed'] ?></span>
                        </div>
                        <small>Retrieved <?= (int)$item['qty_returned'] ?> · Disposed <?= (int)$stock['disposed'] ?></small>
                    </div>
                </td>
                <td data-label="Batch / Custody">
                    <div class="p72c-custody">
                        <span><?= count($batchLabels) ?> receiving batch<?= count($batchLabels)===1?'':'es' ?></span>
                        <small>Source attribution optional</small>
                        <?php if((int)$available['borrowed'] > 0):?>
                            <span class="p72c-on-loan"><?= (int)$available['borrowed'] ?> on loan (not eligible for maintenance)</span>
                        <?php endif; ?>
                        <?php if((int)$available['reserved'] > 0):?>
                            <span class="p72c-warning"><?= (int)$available['reserved'] ?> reserved</span>
                        <?php endif; ?>
                        <?php if($item['needs_review'] || $available['custody_inconsistent']):?>
                            <span class="p72c-warning">Requires Supply Officer reconciliation</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td data-label="Action" class="p72c-action-cell">
                    <button class="btn btn-outline p72c-maintain-btn" type="button"
                        data-maintain-id="<?= (int)$item['ics_item_id'] ?>"
                        <?= $canMaintain?'':'disabled title="No eligible quantity available; verification may be required"' ?>>Maintain item</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p id="p72bItemEmpty" class="p72b-empty" hidden>No accountable equipment matches these filters.</p>
    <?php if (!$items): ?><p class="p72b-empty">No equipment is currently assigned to your active ICS.</p><?php endif; ?>
    <nav class="p72b-pagination p72c-pagination" aria-label="Equipment list pages">
        <button type="button" id="p72bItemPrev" class="btn btn-outline">← Previous</button>
        <span id="p72bPageIndicator" aria-live="polite"></span>
        <button type="button" id="p72bItemNext" class="btn btn-outline">Next →</button>
    </nav>
</section>
<script src="<?= e(url('assets/js/phase7_maintenance_list.js')) ?>"></script>

<!-- Searchable chronological maintenance timeline; all historical entries remain retained. -->
<section class="card p72-history" id="maintenanceHistory" aria-label="Maintenance history">
 <div class="record-section-header"><div><h3>Chronological Maintenance History</h3><p>Every action remains available for review, including older maintenance logs.</p></div></div>
 <div class="p72-history-filters">
  <label>Search item, ICS, batch, notes or teacher<input id="p72-history-search" class="input" type="search" placeholder="Search past repairs and damage reports..."></label>
  <label>Action<select id="p72-history-action" class="input"><option value="">All actions</option><option value="for_repair">Needs repair</option><option value="damaged">Reported damaged</option><option value="damaged_to_repair">Damaged to repair</option><option value="serviceable">Repair completed</option><option value="repair_to_damaged">Repair unsuccessful</option><option value="legacy">Older records</option></select></label>
  <label>Order<select id="p72-history-order" class="input"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label>
 </div>
 <p class="field-help" id="p72-history-count" aria-live="polite"></p>
 <div id="p72-history-list" class="p72-history-list">
 <?php foreach($maintenanceEvents as $ev):
 $batchLabel=$ev['allocation_id'] ? ($ev['receiving_number'].' · '.($ev['batch_number']?:'Batch #'.$ev['receipt_batch_id'])) : 'Unknown / not sure';
 $actionLabels=['for_repair'=>'Needs repair','damaged'=>'Damage reported','damaged_to_repair'=>'Damaged → For repair','repair_to_damaged'=>'Repair unsuccessful','serviceable'=>'Repair completed'];
 $search=implode(' ',[$ev['item_name'],$ev['ics_number'],$ev['description'],$batchLabel,$ev['actor_name'],$ev['action_code']]);
 ?>
 <article class="p72-history-event" data-sort="<?= e($ev['created_at'].'-'.str_pad((string)$ev['event_id'],12,'0',STR_PAD_LEFT)) ?>" data-action="<?= e($ev['action_code']) ?>" data-search="<?= e(mb_strtolower($search)) ?>">
  <div class="p72-history-head"><div><strong><?= e($ev['item_name']) ?></strong><small><?= e($ev['ics_number']) ?> · <?= (int)$ev['quantity'] ?> <?= e($ev['unit']) ?></small></div><span class="p72-history-state"><?= e($actionLabels[$ev['action_code']]??$ev['action_code']) ?></span></div>
  <div class="p72-history-transition"><?= e(ucwords(str_replace('_',' ',$ev['from_condition']))) ?> → <strong><?= e(ucwords(str_replace('_',' ',$ev['to_condition']))) ?></strong></div>
  <p><?= nl2br(e($ev['description'])) ?></p>
  <div class="p72-history-meta"><span><strong>When:</strong> <?= e(date('M j, Y · g:i A',strtotime((string)$ev['created_at']))) ?></span><span><strong>By:</strong> <?= e($ev['actor_name']?:'Teacher') ?></span><span><strong>Source:</strong> <?= e($batchLabel) ?></span><?php if($ev['work_date']):?><span><strong>Work completed:</strong> <?= e($ev['work_date']) ?></span><?php endif; ?></div>
  <?php if($ev['photo_file']): ?><a class="p72-evidence-link" target="_blank" rel="noopener" href="<?= e(url('phase7_evidence.php?kind=maintenance&id='.(int)$ev['event_id'])) ?>">View attached photo ↗</a><?php endif; ?>
 </article>
 <?php endforeach; ?>
 <?php foreach($olderRepairLogs as $ev):
 $legacySearch=implode(' ',[$ev['item_name'],$ev['ics_number'],$ev['issue'],$ev['action_taken'],$ev['condition_status']]);?>
 <article class="p72-history-event p72-history-legacy" data-sort="<?= e($ev['date_reported'].'-'.str_pad((string)$ev['repair_id'],12,'0',STR_PAD_LEFT)) ?>" data-action="legacy" data-search="<?= e(mb_strtolower($legacySearch)) ?>">
  <div class="p72-history-head"><div><strong><?= e($ev['item_name']) ?></strong><small><?= e($ev['ics_number']) ?> · <?= (int)$ev['quantity_affected'] ?> <?= e($ev['unit']) ?></small></div><span class="p72-history-state">Earlier repair record</span></div>
  <p><?= nl2br(e($ev['issue'])) ?></p><p class="field-help"><?= e($ev['action_taken']?:str_replace('_',' ',(string)$ev['condition_status'])) ?></p>
  <div class="p72-history-meta"><span><?= e($ev['date_reported']) ?></span><span>Legacy record · original action time or batch may be unavailable</span></div>
 </article><?php endforeach; ?>
 </div>
 <p id="p72-history-empty" class="field-help" hidden>No matching history records.</p>
 <nav class="p72-history-pages"><button type="button" id="p72-history-prev" class="btn btn-outline">Previous</button><span id="p72-history-page"></span><button type="button" id="p72-history-next" class="btn btn-outline">Next</button></nav>
</section>
<script src="<?= e(url('assets/js/phase7_history.js')) ?>"></script>

<!-- Guided maintenance form: existing POST names and server validation retained. -->
<div id="reportModal" class="modal-overlay p72b-maintenance-modal" hidden>
    <section class="guided-modal">
        <div class="eyebrow">Equipment maintenance</div>
        <h3>Record a maintenance action</h3>
        <p class="modal-intro">Record the issue or repair for the equipment selected from your accountable inventory.</p>
        <form method="POST" id="maintenanceForm" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">1</span><h4>Selected equipment</h4></div>
                <div class="field">
                    <div class="p72b-selected-equipment" id="p72bSelectedEquipment" aria-live="polite">
                        <strong id="p72bSelectedItemName">Select an accountable item</strong>
                        <span id="p72bSelectedItemMeta"></span>
                    </div>
                    <button type="button" class="btn btn-outline p72b-change-item" onclick="chooseAnotherMaintenanceItem()">Choose another item</button>
                    <label for="ics_select" class="visually-hidden">Selected ICS equipment item</label>
                    <select name="ics_item_id" id="ics_select" onchange="updateLiveItemDetails()" class="input p72-native-select" aria-describedby="equipmentHelp">
                        <option value="">Select an accountable item</option>
                        <?php foreach ($items as $mi):
                            // Use the same breakdown as the table and POST validation.
                            $stock = maintenance_stock_quantities($mi);
                            $serv_qty = $stock['serviceable'];
                        ?>
                            <option value="<?= (int)$mi['ics_item_id'] ?>" data-name="<?= e($mi['item_name']) ?>" data-ics="<?= e($mi['ics_number']) ?>" data-course="<?= e($mi['course_name']?:'Unclassified') ?>" data-batches="<?= e(implode(' · ',array_column($maintenanceBatches[(int)$mi['ics_item_id']]??[],'label'))) ?>" data-issued="<?= (int)$mi['currently_accountable'] ?>" data-serviceable="<?= (int)$mi['maintenance_available']['serviceable'] ?>" data-recorded-serviceable="<?= (int)$stock['serviceable'] ?>" data-borrowed="<?= (int)$mi['maintenance_available']['borrowed'] ?>" data-reserved="<?= (int)$mi['maintenance_available']['reserved'] ?>" data-repair="<?= (int)$mi['repair_qty'] ?>" data-damaged="<?= (int)$mi['maintenance_available']['damaged'] ?>" data-condemned="<?= (int)$mi['approved_condemned'] ?>" data-review="<?= (int)$mi['needs_review'] ?>" data-unit="<?= e($mi['unit']) ?>">
                                <?= e($mi['item_name']) ?> — <?= e($mi['ics_number']) ?><?= $mi['needs_review'] ? ' (Verification Required)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-help" id="equipmentHelp">The chosen item is linked to its exact ICS record. Change the selection using the accountable equipment list.</small>
                </div>

                <?php if (!$items): ?><p class="form-notice">You have no equipment in an active ICS. Contact your Supply Officer before submitting a maintenance record.</p><?php endif; ?>
                <div id="live_status_card" class="equipment-snapshot" hidden>
                    <div class="snapshot-heading">Current stock <strong id="badge_total">0</strong></div>
                    <div class="quantity-tiles">
                        <div><span>Serviceable</span><strong id="badge_serviceable" class="text-success">0</strong></div>
                        <div><span>For repair</span><strong id="badge_repair" class="text-warning">0</strong></div>
                        <div><span>Damaged</span><strong id="badge_damaged" class="text-danger">0</strong></div>
                        <div class="p72e-borrowed-tile"><span>Borrowed</span><strong id="badge_borrowed">0</strong></div>
                        <div><span>Disposed</span><strong id="badge_condemned">0</strong></div>
                    </div>
                    <div class="p72e-availability" id="p72eAvailability" aria-live="polite"></div>
                    <p class="p72e-count-note">Borrowed units are already included in the Serviceable total, but cannot be changed until the return is verified.</p>
                </div>
            </section>
            <section class="form-section">
                <div class="section-heading"><span class="step-number">2</span><h4>What would you like to do?</h4></div>
                <fieldset class="action-choice-set">
                    <legend class="visually-hidden">Maintenance action</legend>
                    <div class="action-choices">
                        <label class="action-choice"><input type="radio" name="item_condition" value="for_repair" checked onchange="adjustQuantityLimits()"><span><strong>Needs repair</strong><small>Move working units to maintenance.</small></span></label>
                        <label class="action-choice"><input type="radio" name="item_condition" value="damaged" onchange="adjustQuantityLimits()"><span><strong>Report damage</strong><small>Record damage only; condemnation is a separate request.</small></span></label>
                        <label class="action-choice"><input type="radio" name="item_condition" value="damaged_to_repair" onchange="adjustQuantityLimits()"><span><strong>Repair damaged item</strong><small>Move damaged units into repair.</small></span></label>
                        <label class="action-choice"><input type="radio" name="item_condition" value="repair_to_damaged" onchange="adjustQuantityLimits()"><span><strong>Repair unsuccessful</strong><small>Move repair units to Damaged without submitting condemnation.</small></span></label>
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
                <div class="field p72-source-field"><label for="maintenance_batch">Source batch (optional)</label><select id="maintenance_batch" name="allocation_id" class="input" data-p72-source-for="ics_select"><option value="0">Unknown / not sure</option></select><small class="field-help">Choose a known receiving batch only when you can identify it. Unknown is acceptable; this declaration does not move stock.</small></div>
                <div class="field" id="repairDateField" hidden><label for="repair_date">Repair completed on <span class="required-mark">*</span></label><input type="date" class="input" name="repair_date" id="repair_date" max="<?= e(date('Y-m-d')) ?>"></div>
                <div class="field"><label for="maintenance_photo">Supporting photo (optional)</label><input type="file" class="input" name="maintenance_photo" id="maintenance_photo" accept="image/jpeg,image/png,image/webp"><small class="field-help">JPG, PNG or WebP, maximum 5 MB. Only authorized users can view the attachment.</small></div>
                <div class="field"><label for="maintenanceNotes" id="maintenanceNotesLabel">Issue description <span class="required-mark">*</span></label><textarea id="maintenanceNotes" name="issue_description" rows="3" required class="input" placeholder="Describe the fault, when it started, and any checks you have made."></textarea></div>
            </section>
            <div class="form-review" id="maintenanceReview" aria-live="polite">Your record will appear in the maintenance log after submission.</div>
            <div class="modal-actions"><button type="button" onclick="closeReportModal()" class="btn btn-outline">Cancel</button><button type="submit" name="report_issue" id="submit_btn" class="btn btn-primary" disabled>Save maintenance record</button></div>
        </form>
    </section>
</div>
<script>
function openReportModal(icsItemId) {
    const form=document.getElementById('maintenanceForm');
    const select=document.getElementById('ics_select');
    const target=String(icsItemId ?? '');
    if (!target || !Array.from(select.options).some(o=>o.value===target)) return;
    form.reset(); // Clear any unfinished action for the previously selected item.
    select.value=target;
    select.dispatchEvent(new Event('change',{bubbles:true})); // Refresh both balances and optional batch choices.
    updateLiveItemDetails();
    TNTSUI.open('reportModal');
}
function closeReportModal() { TNTSUI.close('reportModal'); }
function chooseAnotherMaintenanceItem() {
    closeReportModal();
    document.getElementById('p72bEquipment').scrollIntoView({behavior:'smooth',block:'start'});
    document.getElementById('p72bItemSearch').focus({preventScroll:true});
}
function updateLiveItemDetails() {
    const select = document.getElementById('ics_select');
    const card = document.getElementById('live_status_card');
    card.hidden = !select.value;
    if (select.value) {
        const option = select.options[select.selectedIndex];
        document.getElementById('p72bSelectedItemName').textContent=option.dataset.name || option.textContent.trim();
        document.getElementById('p72bSelectedItemMeta').textContent=(option.dataset.ics||'')+' · '+(option.dataset.course||'Unclassified');
        document.getElementById('badge_total').textContent = option.dataset.issued + ' ' + option.dataset.unit + ' currently accountable';
        ['repair', 'damaged', 'condemned', 'borrowed'].forEach(key => {
            document.getElementById('badge_' + key).textContent = option.dataset[key] || '0';
        });
        // Show the total Serviceable balance (same as Inventory), not the
        // smaller quantity eligible for a new maintenance action.
        document.getElementById('badge_serviceable').textContent = option.dataset.recordedServiceable || '0';
        const eligible = Number(option.dataset.serviceable || 0);
        const reserved = Number(option.dataset.reserved || 0);
        const availability = document.getElementById('p72eAvailability');
        availability.textContent = eligible + ' ' + (option.dataset.unit || 'unit(s)') + ' available for a new repair/damage report' + (reserved > 0 ? ' · ' + reserved + ' reserved' : '');
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
    const damagedAvailable = option ? Number(option.dataset.damaged) : 0;
    actions.forEach(action => {
        action.disabled = !option || review || (action.value === 'serviceable' || action.value === 'repair_to_damaged' ? repair : action.value === 'damaged_to_repair' ? damagedAvailable : working) <= 0;
        action.closest('label').classList.toggle('choice-disabled', action.disabled);
    });
    const selected = actions.find(action => action.checked);
    if (!selected || selected.disabled) {
        actions.forEach(action => action.checked = false);
        const firstAvailable = actions.find(action => !action.disabled);
        if (firstAvailable) firstAvailable.checked = true;
    }
    const active = actions.find(action => action.checked && !action.disabled);
    const conditionMax = active ? (active.value === 'serviceable' || active.value === 'repair_to_damaged' ? repair : active.value === 'damaged_to_repair' ? damagedAvailable : working) : 0;
    const batchOpt = document.getElementById('maintenance_batch').selectedOptions[0];
    const batchLimit = batchOpt && batchOpt.value !== '0' ? Number(batchOpt.dataset.remaining || 0) : Infinity;
    const maximum = Math.min(conditionMax,batchLimit);
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
    const dateField=document.getElementById('repairDateField'),dateInput=document.getElementById('repair_date');
    dateField.hidden=!completed;dateInput.required=!!completed;
    if(!completed)dateInput.value='';
    document.getElementById('maintenanceNotesLabel').innerHTML = (completed ? 'Repair work completed' : 'Issue description') + ' <span class="required-mark">*</span>';
    document.getElementById('maintenanceNotes').placeholder = completed ? 'Describe the repair and checks performed before returning these units to service.' : 'Describe the fault, when it started, and any checks you have made.';
    document.getElementById('maintenanceActionHelp').textContent = !option ? 'Select equipment to see which actions are available.' : !active ? 'No units are available for a new issue or repair completion.' : completed ? 'Only units currently marked for repair can be returned to service.' : damaged ? 'Damage is recorded separately. Request condemnation from the Condemnation page if appropriate.' : active.value === 'damaged_to_repair' ? 'Only damaged units without pending condemnation authorization can enter repair.' : active.value === 'repair_to_damaged' ? 'An unsuccessful repair remains damaged; no condemnation request is created.' : 'Only unborrowed and unreserved working units can be moved to maintenance.';
    document.getElementById('maintenanceLimit').textContent = maximum ? 'You can record up to ' + maximum + ' ' + option.dataset.unit + '.' : 'No units available for this action.';
    updateMaintenanceQuantity();
}
document.getElementById('repair_date').addEventListener('change',updateMaintenanceQuantity);
document.getElementById('maintenance_batch').addEventListener('change',adjustQuantityLimits);
document.getElementById('maintenance_batch').addEventListener('p72-sources-updated',adjustQuantityLimits);
function updateMaintenanceQuantity() {
    const qty = document.getElementById('affected_qty_input');
    const action = document.querySelector('#maintenanceForm input[name="item_condition"]:checked');
    const valid = !qty.disabled && Number.isInteger(Number(qty.value)) && Number(qty.value) >= 1 && Number(qty.value) <= Number(qty.max);
    qty.setCustomValidity(valid || qty.disabled ? '' : 'Enter a whole number from 1 to ' + qty.max + '.');
    const dateInput=document.getElementById('repair_date');
    const completed=action && action.value==='serviceable';
    document.getElementById('submit_btn').disabled = !valid || !action || action.disabled || (completed && (!dateInput.value || dateInput.value>dateInput.max));
    const labels = { for_repair: 'Report for repair', damaged: 'Report damaged equipment', serviceable: 'Record completed repair', damaged_to_repair: 'Move damaged units to repair', repair_to_damaged: 'Mark repair unsuccessful' };
    document.getElementById('submit_btn').textContent = action && !action.disabled ? labels[action.value] : 'Save maintenance record';
    document.getElementById('maintenanceReview').textContent = valid && action ? Number(qty.value) + ' unit(s) will be recorded as ' + ({ for_repair: 'for repair', damaged: 'damaged (no automatic condemnation)', serviceable: 'repaired and returned to service', damaged_to_repair: 'damaged → for repair', repair_to_damaged: 'for repair → damaged' })[action.value] + '.' : 'Choose an available action and enter a valid quantity to continue.';
}
</script>
<script>window.TNTSPhase7Batches = <?= json_encode($maintenanceBatches,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(url('assets/js/phase7_picker.js')) ?>"></script>

<?php 
require_once __DIR__ . '/includes/footer.php'; 
ob_end_flush();
?>