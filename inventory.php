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
$filter_po = $_GET['po_number'] ?? '';
$filter_dr = $_GET['dr_number'] ?? '';
$filter_supplier = $_GET['supplier_name'] ?? '';
$filter_course = $_GET['course_id'] ?? '';
$filter_teacher = $_GET['teacher_id'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';

if ($role === 'teacher') {
    // Teachers see only their assigned items with precise condition breakdowns
    $stmt = db()->prepare("
        SELECT ii.*, i.item_name, i.description, i.unit, i.unit_cost, c.ics_number, c.date_issued, cr.course_name,
               u.full_name as teacher_name,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair'), 0) as repair_qty,
               COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged'), 0) as damaged_qty,
               COALESCE((SELECT SUM(quantity) FROM condemnations WHERE ics_item_id = ii.ics_item_id AND status = 'approved'), 0) as approved_condemned
        FROM ics_items ii
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN users u ON c.teacher_id = u.user_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN courses cr ON c.course_id = cr.course_id
        WHERE c.teacher_id = ? AND c.status = 'active'
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

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sql = "
            SELECT ii.*, i.item_name, i.description, i.unit, c.ics_number, c.date_issued, 
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
            $whereClauses[] = "d.po_number = ?";
            $params[] = $filter_po;
        }
        if (!empty($filter_dr)) {
            $whereClauses[] = "d.dr_number = ?";
            $params[] = $filter_dr;
        }
        if (!empty($filter_supplier)) {
            $whereClauses[] = "d.supplier_name = ?";
            $params[] = $filter_supplier;
        }
        if (!empty($filter_date_from)) {
            $whereClauses[] = "d.date_received >= ?";
            $params[] = $filter_date_from;
        }
        if (!empty($filter_date_to)) {
            $whereClauses[] = "d.date_received <= ?";
            $params[] = $filter_date_to;
        }
        if (!empty($search_query)) {
            $whereClauses[] = "(i.item_name LIKE ? OR i.description LIKE ?)";
            $params[] = "%$search_query%";
            $params[] = "%$search_query%";
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sql = "
            SELECT i.*, d.po_number, d.dr_number, d.supplier_name, d.date_received,
                   COALESCE((SELECT SUM(qty_issued) FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active'), 0) as total_issued,
                   COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active' AND r.condition_status = 'for_repair'), 0) as total_repair,
                   COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii ON r.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active' AND r.condition_status = 'damaged'), 0) as total_damaged,
                   COALESCE((SELECT SUM(co.quantity) FROM condemnations co JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active' AND co.status = 'approved'), 0) as total_condemned
            FROM inventory_items i
            LEFT JOIN delivery_items di ON i.delivery_item_id = di.delivery_item_id
            LEFT JOIN deliveries d ON di.delivery_id = d.delivery_id
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

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Inventory Management</h2>
    <?php if ($role !== 'teacher'): ?>
        <div>
            <a href="<?= e(url('inventory.php?view=central')) ?>" style="padding: 8px 14px; background: <?= $view_mode === 'central' ? '#800000' : '#e5e7eb' ?>; color: <?= $view_mode === 'central' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold; margin-right: 5px;">Central Stock Overview</a>
            <a href="<?= e(url('inventory.php?view=issued')) ?>" style="padding: 8px 14px; background: <?= $view_mode === 'issued' ? '#800000' : '#e5e7eb' ?>; color: <?= $view_mode === 'issued' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold;">Issued Items by Teacher / Course</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($role !== 'teacher'): ?>
<!-- Filter Section -->
<div class="card" style="background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <form method="GET" action="<?= e(url('inventory.php')) ?>" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
        <input type="hidden" name="view" value="<?= e($view_mode) ?>">
        
        <div style="flex: 1; min-width: 180px;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">Search</label>
            <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="<?= $view_mode === 'issued' ? 'Item name or ICS No...' : 'Item name or description...' ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <?php if ($view_mode === 'issued'): ?>
            <!-- Issued Items Filters: Course & Teacher -->
            <div style="flex: 1; min-width: 160px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">Filter by Course</label>
                <select name="course_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All Courses --</option>
                    <?php foreach ($course_list as $crs): ?>
                        <option value="<?= e($crs['course_id']) ?>" <?= $filter_course == $crs['course_id'] ? 'selected' : '' ?>><?= e($crs['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 160px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">Filter by Teacher</label>
                <select name="teacher_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All Teachers --</option>
                    <?php foreach ($teacher_list as $tch): ?>
                        <option value="<?= e($tch['user_id']) ?>" <?= $filter_teacher == $tch['user_id'] ? 'selected' : '' ?>><?= e($tch['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <!-- Central Inventory Filters: PO, DR, Supplier -->
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">PO No.</label>
                <select name="po_number" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All PO --</option>
                    <?php foreach ($po_list as $po): ?>
                        <option value="<?= e($po) ?>" <?= $filter_po === $po ? 'selected' : '' ?>><?= e($po) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">DR No.</label>
                <select name="dr_number" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All DR --</option>
                    <?php foreach ($dr_list as $dr): ?>
                        <option value="<?= e($dr) ?>" <?= $filter_dr === $dr ? 'selected' : '' ?>><?= e($dr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 150px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;">Supplier</label>
                <select name="supplier_name" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All Suppliers --</option>
                    <?php foreach ($supplier_list as $sup): ?>
                        <option value="<?= e($sup) ?>" <?= $filter_supplier === $sup ? 'selected' : '' ?>><?= e($sup) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div style="flex: 1; min-width: 130px;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;"><?= $view_mode === 'issued' ? 'Issued From' : 'Arrival From' ?></label>
            <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <div style="flex: 1; min-width: 130px;">
            <label style="display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em;"><?= $view_mode === 'issued' ? 'Issued To' : 'Arrival To' ?></label>
            <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        </div>

        <div>
            <button type="submit" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
            <a href="<?= e(url('inventory.php?view=' . $view_mode)) ?>" style="padding: 8px 12px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; margin-left: 5px; display: inline-block; font-size: 0.9em;">Reset</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 10px;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; background: #f9fafb;">
                <?php if ($role === 'teacher' || $view_mode === 'issued'): ?>
                    <th style="padding: 12px;">ICS Number / Course</th>
                    <th style="padding: 12px;">Accountable Teacher</th>
                    <th style="padding: 12px;">Item Details</th>
                    <th style="padding: 12px;">Total Issued</th>
                    <th style="padding: 12px; color: #10b981;">Serviceable</th>
                    <th style="padding: 12px; color: #d97706;">For Repair</th>
                    <th style="padding: 12px; color: #dc2626;">Damaged / Disposed</th>
                    <th style="padding: 12px;">Date Issued</th>
                <?php else: ?>
                    <th style="padding: 12px;">Item Details & Reference</th>
                    <th style="padding: 12px;">Total Accepted</th>
                    <th style="padding: 12px; color: #10b981;">Serviceable</th>
                    <th style="padding: 12px; color: #d97706;">For Repair</th>
                    <th style="padding: 12px; color: #dc2626;">Damaged / Condemned</th>
                    <th style="padding: 12px; color: #3b82f6;">Unissued Stock</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inventory)): ?>
                <tr>
                    <td colspan="8" style="padding: 20px; text-align: center; color: #666;">No inventory records found matching your filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($inventory as $item): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <?php if ($role === 'teacher' || $view_mode === 'issued'): 
                            $serviceable_qty = max(0, $item['qty_issued'] - $item['repair_qty'] - $item['damaged_qty'] - $item['approved_condemned']);
                        ?>
                            <td style="padding: 12px;">
                                <strong><?= e($item['ics_number']) ?></strong><br>
                                <small style="color: #666;"><?= e($item['course_name']) ?></small>
                            </td>
                            <td style="padding: 12px; font-weight: bold; color: #800000;"><?= e($item['teacher_name'] ?? $user['full_name'] ?? 'N/A') ?></td>
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
                            <td style="padding: 12px;"><?= e(fmt_datetime($item['date_issued'])) ?></td>
                        <?php else: 
                            $total_issued = $item['total_issued'];
                            $repair_issued = $item['total_repair'];
                            $damaged_issued = $item['total_damaged'] + $item['total_condemned'];
                            $serviceable_issued = max(0, $total_issued - $repair_issued - $damaged_issued);
                            $unissued = max(0, $item['quantity'] - $total_issued);
                        ?>
                            <td style="padding: 12px;">
                                <strong><?= e($item['item_name']) ?></strong><br>
                                <small style="color: #666;"><?= e($item['description']) ?></small><br>
                                <small style="color: #888; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 3px;">
                                    Arrived: <?= e($item['date_received'] ?? 'N/A') ?> | PO: <?= e($item['po_number'] ?? 'N/A') ?> | DR: <?= e($item['dr_number'] ?? 'N/A') ?> | Supplier: <?= e($item['supplier_name'] ?? 'N/A') ?>
                                </small>
                            </td>
                            <td style="padding: 12px; font-weight: bold;"><?= e($item['quantity']) ?> <?= e($item['unit']) ?></td>
                            <td style="padding: 12px; color: #10b981; font-weight: bold;"><?= $serviceable_issued ?></td>
                            <td style="padding: 12px; color: #d97706; font-weight: bold;"><?= $repair_issued ?></td>
                            <td style="padding: 12px; color: #dc2626; font-weight: bold;">
                                <?= $damaged_issued ?>
                                <?php if ($item['total_condemned'] > 0): ?>
                                    <small>(<?= e($item['total_condemned']) ?> condemned)</small>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; font-weight: bold; color: #3b82f6;"><?= $unissued ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>