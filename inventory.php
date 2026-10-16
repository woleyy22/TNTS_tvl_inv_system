<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$role = $user['role'];
$active_user_id = $user['user_id'] ?? $user['id'];

// Determine view mode for Admin / TVL Head ('central' or 'issued')
$view_mode = $_GET['view'] ?? 'central';

// Capture filter parameters
$filter_po       = trim($_GET['po_number'] ?? '');
$filter_dr       = trim($_GET['dr_number'] ?? '');
$filter_supplier = trim($_GET['supplier_name'] ?? '');
$filter_course   = trim($_GET['course_id'] ?? '');
$filter_teacher  = trim($_GET['teacher_id'] ?? '');
$filter_date_from= trim($_GET['date_from'] ?? '');
$filter_date_to  = trim($_GET['date_to'] ?? '');
$search_query    = trim($_GET['search'] ?? '');

if ($role === 'teacher') {
    // Teachers see only their assigned items with dynamic condition breakdowns
    $stmt = db()->prepare("
        SELECT ii.*, i.item_name, i.description, i.unit, i.unit_cost, i.item_type, c.ics_number, c.date_issued, cr.course_name,
               u.full_name as teacher_name,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair'), 0) as repair_qty,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged'), 0) as damaged_qty,
               COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status = 'approved'), 0) as approved_condemned
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN users u ON c.teacher_id = u.user_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN courses cr ON c.course_id = cr.course_id
        WHERE c.teacher_id = ? AND c.status = 'active' AND u.status = 'active'
        ORDER BY c.date_issued DESC
    ");
    $stmt->execute([$active_user_id]);
    $inventory = $stmt->fetchAll();
} else {
    // Fetch dropdown filter options for Admin / TVL Head
    $po_list = db()->query("SELECT DISTINCT po_number FROM deliveries WHERE po_number IS NOT NULL AND po_number != '' ORDER BY po_number")->fetchAll(PDO::FETCH_COLUMN);
    $dr_list = db()->query("SELECT DISTINCT dr_number FROM deliveries WHERE dr_number IS NOT NULL AND dr_number != '' ORDER BY dr_number")->fetchAll(PDO::FETCH_COLUMN);
    $supplier_list = db()->query("SELECT DISTINCT supplier_name FROM deliveries WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY supplier_name")->fetchAll(PDO::FETCH_COLUMN);
    $course_list = db()->query("SELECT course_id, course_name FROM courses ORDER BY course_name")->fetchAll();
    $teacher_list = db()->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name")->fetchAll();

    $whereClauses = [];
    $params = [];

    if ($view_mode === 'issued') {
        // Query for Items Issued to Teachers
        $whereClauses[] = "c.status = 'active' AND u.status = 'active'";

        if (!empty($filter_course)) {
            $whereClauses[] = "c.course_id = ?";
            $params[] = $filter_course;
        }
        if (!empty($filter_teacher)) {
            $whereClauses[] = "c.teacher_id = ?";
            $params[] = $filter_teacher;
        }
        if (!empty($filter_date_from)) {
            $whereClauses[] = "c.date_issued >= ?";
            $params[] = $filter_date_from;
        }
        if (!empty($filter_date_to)) {
            $whereClauses[] = "c.date_issued <= ?";
            $params[] = $filter_date_to;
        }
        if (!empty($search_query)) {
            $whereClauses[] = "(i.item_name LIKE ? OR c.ics_number LIKE ?)";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
        }

        $whereSql = "WHERE " . implode(" AND ", $whereClauses);

        $sql = "
            SELECT ii.*, i.item_name, i.description, i.unit, i.item_type, c.ics_number, c.date_issued, 
                   u.full_name as teacher_name, cr.course_name,
                   COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair'), 0) as repair_qty,
                   COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged'), 0) as damaged_qty,
                   COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status = 'approved'), 0) as approved_condemned
            FROM ics_items ii
            JOIN ics c ON ii.ics_id = c.ics_id
            JOIN users u ON c.teacher_id = u.user_id
            JOIN courses cr ON c.course_id = cr.course_id
            JOIN inventory_items i ON ii.item_id = i.item_id
            $whereSql
            ORDER BY c.date_issued DESC
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $inventory = $stmt->fetchAll();

    } else {
        // Central Inventory Overview Query
        if (!empty($filter_po)) {
            $whereClauses[] = "EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.po_number=?)";
            $params[] = $filter_po;
        }
        if (!empty($filter_dr)) {
            $whereClauses[] = "EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.dr_number=?)";
            $params[] = $filter_dr;
        }
        if (!empty($filter_supplier)) {
            $whereClauses[] = "EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.supplier_name=?)";
            $params[] = $filter_supplier;
        }
        if (!empty($filter_date_from)) {
            $whereClauses[] = "EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.date_received>=?)";
            $params[] = $filter_date_from;
        }
        if (!empty($filter_date_to)) {
            $whereClauses[] = "EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.date_received<=?)";
            $params[] = $filter_date_to;
        }
        if (!empty($search_query)) {
            $whereClauses[] = "(i.item_name LIKE ? OR i.description LIKE ?)";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sql = "
            SELECT i.*, (SELECT COUNT(*) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id) AS batch_count,
                   (SELECT MAX(d.date_received) FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id) AS date_received,
                   COALESCE((SELECT SUM(ii.qty_issued) FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active'), 0) - COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON ii.ics_item_id=co.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=i.item_id AND c.status='active' AND co.status='approved' AND co.outcome='disposed'),0) as total_issued,
                   COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active' AND r.condition_status = 'for_repair'), 0) as total_repair,
                   COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active' AND r.condition_status = 'damaged'), 0) as total_damaged,
                   COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND co.status = 'approved' AND co.outcome = 'disposed'), 0) as total_condemned
            FROM inventory_items i
            $whereSql
            ORDER BY i.item_name ASC
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $inventory = $stmt->fetchAll();
    }
}

$pageTitle = 'Inventory Management';
$active = 'inventory';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-026">
    <h2>Inventory Management</h2>
    <?php if ($role !== 'teacher'): ?>
        <div>
            <a href="<?= e(url('inventory.php?view=central')) ?>" class="view-tab <?= $view_mode === 'central' ? 'is-active' : '' ?>">Central Stock Overview</a>
            <a href="<?= e(url('inventory.php?view=issued')) ?>" class="view-tab <?= $view_mode === 'issued' ? 'is-active' : '' ?>">Issued Items by Teacher / Course</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($role !== 'teacher'): ?>
<!-- Filter Section -->
<div class="card legacy-077">
    <form method="GET" action="<?= e(url('inventory.php')) ?>" class="legacy-078">
        <input type="hidden" name="view" value="<?= e($view_mode) ?>">
        
        <div class="legacy-079">
            <label class="legacy-080">Search</label>
            <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="<?= $view_mode === 'issued' ? 'Item name or ICS No...' : 'Item name or description...' ?>" class="legacy-047">
        </div>

        <?php if ($view_mode === 'issued'): ?>
            <!-- Issued Items Filters: Course & Teacher -->
            <div class="legacy-081">
                <label class="legacy-080">Filter by Course</label>
                <select name="course_id" class="legacy-047">
                    <option value="">-- All Courses --</option>
                    <?php foreach ($course_list as $crs): ?>
                        <option value="<?= e($crs['course_id']) ?>" <?= $filter_course == $crs['course_id'] ? 'selected' : '' ?>><?= e($crs['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-081">
                <label class="legacy-080">Filter by Teacher</label>
                <select name="teacher_id" class="legacy-047">
                    <option value="">-- All Teachers --</option>
                    <?php foreach ($teacher_list as $tch): ?>
                        <option value="<?= e($tch['user_id']) ?>" <?= $filter_teacher == $tch['user_id'] ? 'selected' : '' ?>><?= e($tch['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <!-- Central Inventory Filters: PO, DR, Supplier -->
            <div class="legacy-082">
                <label class="legacy-080">PO No.</label>
                <select name="po_number" class="legacy-047">
                    <option value="">-- All PO --</option>
                    <?php foreach ($po_list as $po): ?>
                        <option value="<?= e($po) ?>" <?= $filter_po === $po ? 'selected' : '' ?>><?= e($po) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-082">
                <label class="legacy-080">DR No.</label>
                <select name="dr_number" class="legacy-047">
                    <option value="">-- All DR --</option>
                    <?php foreach ($dr_list as $dr): ?>
                        <option value="<?= e($dr) ?>" <?= $filter_dr === $dr ? 'selected' : '' ?>><?= e($dr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="legacy-083">
                <label class="legacy-080">Supplier</label>
                <select name="supplier_name" class="legacy-047">
                    <option value="">-- All Suppliers --</option>
                    <?php foreach ($supplier_list as $sup): ?>
                        <option value="<?= e($sup) ?>" <?= $filter_supplier === $sup ? 'selected' : '' ?>><?= e($sup) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="legacy-082">
            <label class="legacy-080"><?= $view_mode === 'issued' ? 'Issued From' : 'Arrival From' ?></label>
            <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" class="legacy-047">
        </div>

        <div class="legacy-082">
            <label class="legacy-080"><?= $view_mode === 'issued' ? 'Issued To' : 'Arrival To' ?></label>
            <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" class="legacy-047">
        </div>

        <div>
            <button type="submit" class="legacy-028">Filter</button>
            <a href="<?= e(url('inventory.php?view=' . $view_mode)) ?>" class="legacy-084">Reset</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card legacy-030">
    <table class="legacy-085 js-sortable-table">
        <thead>
            <tr class="legacy-086">
                <?php if ($role === 'teacher' || $view_mode === 'issued'): ?>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort ICS Number / Course: default order. Activate for ascending." title="Sort ICS Number / Course: default order" aria-pressed="false"><span class="tnts-sort-title">ICS Number / Course</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Accountable Teacher: default order. Activate for ascending." title="Sort Accountable Teacher: default order" aria-pressed="false"><span class="tnts-sort-title">Accountable Teacher</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Item Details: default order. Activate for ascending." title="Sort Item Details: default order" aria-pressed="false"><span class="tnts-sort-title">Item Details</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Type: default order. Activate for ascending." title="Sort Type: default order" aria-pressed="false"><span class="tnts-sort-title">Type</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Total Issued: default order. Activate for ascending." title="Sort Total Issued: default order" aria-pressed="false"><span class="tnts-sort-title">Total Issued</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-087"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Serviceable: default order. Activate for ascending." title="Sort Serviceable: default order" aria-pressed="false"><span class="tnts-sort-title">Serviceable</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-088"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort For Repair: default order. Activate for ascending." title="Sort For Repair: default order" aria-pressed="false"><span class="tnts-sort-title">For Repair</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-089"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Damaged / Disposed: default order. Activate for ascending." title="Sort Damaged / Disposed: default order" aria-pressed="false"><span class="tnts-sort-title">Damaged / Disposed</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Date Issued: default order. Activate for ascending." title="Sort Date Issued: default order" aria-pressed="false"><span class="tnts-sort-title">Date Issued</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                <?php else: ?>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Item Details &amp; Reference: default order. Activate for ascending." title="Sort Item Details &amp; Reference: default order" aria-pressed="false"><span class="tnts-sort-title">Item Details & Reference</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Type: default order. Activate for ascending." title="Sort Type: default order" aria-pressed="false"><span class="tnts-sort-title">Type</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Total Accepted: default order. Activate for ascending." title="Sort Total Accepted: default order" aria-pressed="false"><span class="tnts-sort-title">Total Accepted</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-087"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Serviceable: default order. Activate for ascending." title="Sort Serviceable: default order" aria-pressed="false"><span class="tnts-sort-title">Serviceable</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-088"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort For Repair: default order. Activate for ascending." title="Sort For Repair: default order" aria-pressed="false"><span class="tnts-sort-title">For Repair</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-089"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Damaged / Condemned: default order. Activate for ascending." title="Sort Damaged / Condemned: default order" aria-pressed="false"><span class="tnts-sort-title">Damaged / Condemned</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-090"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Unissued Stock: default order. Activate for ascending." title="Sort Unissued Stock: default order" aria-pressed="false"><span class="tnts-sort-title">Unissued Stock</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inventory)): ?>
                <tr>
                    <td colspan="9" class="legacy-035">No inventory records found matching your filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($inventory as $item): ?>
                    <tr class="legacy-036">
                        <?php if ($role === 'teacher' || $view_mode === 'issued'): 
                            $serviceable_qty = max(0, $item['qty_issued'] - $item['repair_qty'] - $item['damaged_qty']);
                        ?>
                            <td class="legacy-033">
                                <strong><?= e($item['ics_number']) ?></strong><br>
                                <small class="legacy-037"><?= e($item['course_name']) ?></small>
                            </td>
                            <td class="legacy-038"><?= e($item['teacher_name'] ?? $user['full_name'] ?? 'N/A') ?></td>
                            <td class="legacy-033">
                                <strong><?= e($item['item_name']) ?></strong><br>
                                <small class="legacy-037"><?= e($item['description']) ?></small>
                            </td>
                            <td><span class="item-type-badge item-type-<?= e($item['item_type']) ?>"><?= $item['item_type'] === 'supply' ? 'Supply' : 'Equipment' ?></span></td>
                            <td class="legacy-091"><?= e($item['qty_issued']) ?> <?= e($item['unit']) ?></td>
                            <td class="legacy-092"><?= $serviceable_qty ?></td>
                            <td class="legacy-093"><?= e($item['repair_qty']) ?></td>
                            <td class="legacy-094">
                                <?= e($item['damaged_qty']) ?> 
                                <?php if ($item['approved_condemned'] > 0): ?>
                                    <small>(<?= e($item['approved_condemned']) ?> disposed)</small>
                                <?php endif; ?>
                            </td>
                            <td class="legacy-033"><?= e(fmt_datetime($item['date_issued'])) ?></td>
                        <?php else: 
                            $total_issued = $item['total_issued'];
                            $repair_issued = $item['total_repair'];
                            $damaged_issued = $item['total_damaged'];
                            $serviceable_issued = max(0, $total_issued - $repair_issued - $damaged_issued);
                            $unissued = max(0, $item['quantity'] - $total_issued - $item['total_condemned']);
                        ?>
                            <td class="legacy-033">
                                <strong><?= e($item['item_name']) ?></strong><br>
                                <small class="legacy-037"><?= e($item['description']) ?></small><br>
                                <small class="legacy-095">
                                    Latest arrival: <?= e($item['date_received'] ?? 'N/A') ?> | <?= (int)$item['batch_count'] ?> delivery batch(es)
                                    | <a href="<?= e(url('inventory_batches.php?item_id=' . (int)$item['item_id'])) ?>">View delivery breakdown</a>
                                </small>
                            </td>
                            <td><span class="item-type-badge item-type-<?= e($item['item_type']) ?>"><?= $item['item_type'] === 'supply' ? 'Supply' : 'Equipment' ?></span></td>
                            <td class="legacy-091"><?= e($item['quantity']) ?> <?= e($item['unit']) ?></td>
                            <td class="legacy-092"><?= $serviceable_issued ?></td>
                            <td class="legacy-093"><?= $repair_issued ?></td>
                            <td class="legacy-094">
                                <?= $damaged_issued ?>
                                <?php if ($item['total_condemned'] > 0): ?>
                                    <small>(<?= e($item['total_condemned']) ?> condemned)</small>
                                <?php endif; ?>
                            </td>
                            <td class="legacy-096"><?= $unissued ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>