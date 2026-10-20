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
$tab = $_GET['tab'] ?? 'ics'; // 'ics', 'iar', 'condemn', 'summary'

// Shared filter parameters
$search_query = trim($_GET['search'] ?? '');
$filter_course_id = (int)($_GET['course_id'] ?? 0);
$filter_status = trim($_GET['status'] ?? '');
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Fetch all courses for dropdown filters
$all_courses = db()->query("SELECT course_id, course_name FROM courses ORDER BY course_name ASC")->fetchAll();

// Fetch data based on active tab and role with search/filtering
if ($tab === 'ics') {
    $ics_view = ($_GET['ics_view'] ?? 'active') === 'archive' && $role !== 'teacher' ? 'archive' : 'active';
    $whereClauses = [];
    $whereClauses[] = $ics_view === 'archive' ? "(c.status = 'closed' OR u.status = 'inactive')" : "c.status = 'active' AND u.status = 'active'";
    $params = [];

    if ($role === 'teacher') {
        $whereClauses[] = "c.teacher_id = ?";
        $params[] = $uid;

    }

    if (!empty($search_query)) {
        $whereClauses[] = "(c.ics_number LIKE ? OR u.full_name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    if ($filter_course_id > 0) {
        $whereClauses[] = "EXISTS (SELECT 1 FROM ics_items line_filter WHERE line_filter.ics_id=c.ics_id AND line_filter.course_id=?)";
        $params[] = $filter_course_id;
    }
    if (!empty($filter_date_from)) {
        $whereClauses[] = "c.date_issued >= ?";
        $params[] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $whereClauses[] = "c.date_issued <= ?";
        $params[] = $filter_date_to;
    }

    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    $stmt = db()->prepare("
        SELECT c.*, u.full_name as teacher_name, COALESCE((SELECT GROUP_CONCAT(DISTINCT crs.course_name ORDER BY crs.course_name SEPARATOR ', ') FROM ics_items AS item_line LEFT JOIN courses AS crs ON crs.course_id = item_line.course_id WHERE item_line.ics_id = c.ics_id), 'Unclassified') AS course_name 
        FROM ics c
        JOIN users u ON c.teacher_id = u.user_id
        
        $whereSql
        ORDER BY c.date_issued DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

} elseif ($tab === 'iar') {
    $whereClauses = [];
    $params = [];

    if (!empty($search_query)) {
        $whereClauses[] = "(po_number LIKE ? OR dr_number LIKE ? OR supplier_name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    if (!empty($filter_date_from)) {
        $whereClauses[] = "date_received >= ?";
        $params[] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $whereClauses[] = "date_received <= ?";
        $params[] = $filter_date_to;
    }

    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    $stmt = db()->prepare("SELECT * FROM deliveries $whereSql ORDER BY date_received DESC");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

} elseif ($tab === 'condemn') {
    $whereClauses = [];
    $params = [];

    if ($role === 'teacher') {
        $whereClauses[] = "co.requested_by = ?";
        $params[] = $uid;
    }

    if (!empty($search_query)) {
        $whereClauses[] = "(co.form_number LIKE ? OR i.item_name LIKE ? OR u.full_name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    if (!empty($filter_status)) {
        $whereClauses[] = "co.status = ?";
        $params[] = $filter_status;
    }
    if (!empty($filter_date_from)) {
        $whereClauses[] = "co.date_approved >= ?";
        $params[] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $whereClauses[] = "co.date_approved <= ?";
        $params[] = $filter_date_to;
    }

    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    $stmt = db()->prepare("
        SELECT co.*, i.item_name, i.unit, c.ics_number, u.full_name as requester_name 
        FROM condemnations co
        JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
        JOIN ics c ON ii.ics_id = c.ics_id
        JOIN inventory_items i ON ii.item_id = i.item_id
        JOIN users u ON co.requested_by = u.user_id
        $whereSql
        ORDER BY co.date_approved DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

} else {
    // Summary Report by Course
    $whereClauses = [];
    $params = [];

    if ($filter_course_id > 0) {
        $whereClauses[] = "cr.course_id = ?";
        $params[] = $filter_course_id;
    }
    if (!empty($search_query)) {
        $whereClauses[] = "cr.course_name LIKE ?";
        $params[] = "%$search_query%";
    }

    $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

    $sql = "
        SELECT cr.course_id, cr.course_name,
               COUNT(DISTINCT c.ics_id) as total_ics_count,
               COUNT(ii.ics_item_id) as total_line_items,
               COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued ELSE 0 END), 0) as total_units_issued,
               COALESCE(SUM(CASE WHEN ii.transfer_origin_request_id IS NULL THEN ii.qty_issued * i.unit_cost ELSE 0 END), 0) as total_monetary_value,
               SUM(CASE WHEN ii.ics_item_id IS NOT NULL AND i.unit_cost IS NULL THEN 1 ELSE 0 END) as unpriced_lines,
               COALESCE(SUM(
                   LEAST(ii.qty_issued, 
                       GREATEST(0, ii.qty_issued 
                           - (SELECT COALESCE(SUM(quantity_affected), 0) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair')
                           - (SELECT COALESCE(SUM(quantity_affected), 0) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged')
                           - (SELECT COALESCE(SUM(quantity), 0) FROM condemnations co WHERE co.ics_item_id = ii.ics_item_id AND co.status = 'approved')
                       )
                   )
               ), 0) as serviceable_units,
               COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii2 ON r.ics_item_id = ii2.ics_item_id JOIN ics c2 ON ii2.ics_id = c2.ics_id WHERE ii2.course_id = cr.course_id AND c2.status = 'active' AND r.condition_status = 'for_repair'), 0) as repair_units,
               COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii2 ON r.ics_item_id = ii2.ics_item_id JOIN ics c2 ON ii2.ics_id = c2.ics_id WHERE ii2.course_id = cr.course_id AND c2.status = 'active' AND r.condition_status = 'damaged'), 0) as damaged_units
        FROM courses cr
        LEFT JOIN ics_items ii ON cr.course_id = ii.course_id AND EXISTS (SELECT 1 FROM ics c0 WHERE c0.ics_id=ii.ics_id AND c0.status='active')
        LEFT JOIN ics c ON c.ics_id = ii.ics_id
        LEFT JOIN inventory_items i ON ii.item_id = i.item_id
        $whereSql
        GROUP BY cr.course_id, cr.course_name
        ORDER BY cr.course_name ASC
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $summary_records = $stmt->fetchAll();
}

$pageTitle = 'Official Reports & Printable Forms';
$active = 'reports';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header legacy-050">
    <h2>Official Reports & Document Generation</h2>
    <p class="legacy-027">Generate and print government-compliant COA forms (ICS, IAR, Condemnation Forms, and Summaries).</p>
</div>

<!-- Tab Navigation -->
<div class="legacy-120">
    <a href="reports.php?tab=ics" class="view-tab <?= $tab === 'ics' ? 'is-active' : '' ?>">Inventory Custodian Slips (ICS)</a>
    <?php if ($role !== 'teacher'): ?>
        <a href="reports.php?tab=iar" class="view-tab <?= $tab === 'iar' ? 'is-active' : '' ?>">Inspection & Acceptance Reports (IAR)</a>
    <?php endif; ?>
    <a href="reports.php?tab=condemn" class="view-tab <?= $tab === 'condemn' ? 'is-active' : '' ?>">Condemnation Forms</a>
    <a href="reports.php?tab=summary" class="view-tab <?= $tab === 'summary' ? 'is-active' : '' ?>">Course Summary Report</a>
</div>

<div class="card legacy-030">

    <?php if ($tab === 'ics'): ?>
        <div class="legacy-121">
            <h3 class="legacy-122">Inventory Custodian Slips (ICS) — <?= $ics_view === 'archive' ? 'Historical Records' : 'Active Accountability' ?></h3>
        </div>

        <!-- Filter & Search Bar for ICS -->
        <form method="GET" action="reports.php" class="legacy-123">
            <input type="hidden" name="tab" value="ics">
            <?php if ($role !== 'teacher'): ?>
            <div><label class="legacy-124">Document View</label>
            <select name="ics_view" class="legacy-125">
                <option value="active" <?= $ics_view === 'active' ? 'selected' : '' ?>>Active ICS</option>
                <option value="archive" <?= $ics_view === 'archive' ? 'selected' : '' ?>>Archived / Inactive Teacher</option>
            </select></div>
            <?php endif; ?>
            <div class="legacy-079">
                <label class="legacy-124">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="ICS No. or Teacher Name..." class="legacy-125">
            </div>
            <div class="legacy-081">
                <label class="legacy-124">Filter by Course</label>
                <select name="course_id" class="legacy-125">
                    <option value="0">-- All Courses --</option>
                    <?php foreach ($all_courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $filter_course_id === (int)$c['course_id'] ? 'selected' : '' ?>><?= e($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="legacy-082">
                <label class="legacy-124">Date Issued From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" class="legacy-125">
            </div>
            <div class="legacy-082">
                <label class="legacy-124">Date Issued To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" class="legacy-125">
            </div>
            <div>
                <button type="submit" class="legacy-126">Filter</button>
                <a href="reports.php?tab=ics" class="legacy-127">Reset</a>
            </div>
        </form>

        <table class="legacy-128">
            <thead>
                <tr class="legacy-032">
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort ICS Number: default order. Activate for ascending." title="Sort ICS Number: default order" aria-pressed="false"><span class="tnts-sort-title">ICS Number</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Teacher Accountable: default order. Activate for ascending." title="Sort Teacher Accountable: default order" aria-pressed="false"><span class="tnts-sort-title">Teacher Accountable</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Course / Elective: default order. Activate for ascending." title="Sort Course / Elective: default order" aria-pressed="false"><span class="tnts-sort-title">Course / Elective</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Date Issued: default order. Activate for ascending." title="Sort Date Issued: default order" aria-pressed="false"><span class="tnts-sort-title">Date Issued</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" class="legacy-035">No ICS records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr class="legacy-036">
                        <td class="legacy-038"><?= e($r['ics_number']) ?></td>
                        <td class="legacy-033"><?= e($r['teacher_name']) ?></td>
                        <td class="legacy-033"><?= e($r['course_name']??'Per-item / Unclassified') ?><?= $ics_view === 'archive' ? ' (Archived)' : '' ?></td>
                        <td class="legacy-033"><?= e(date('M d, Y', strtotime($r['date_issued']))) ?></td>
                        <td class="legacy-034">
                            <a href="print_ics.php?id=<?= $r['ics_id'] ?>" target="_blank" class="legacy-129">🖨 Print ICS PDF</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'iar' && $role !== 'teacher'): ?>
        <div class="legacy-121">
            <h3 class="legacy-122">Inspection and Acceptance Reports (IAR)</h3>
        </div>

        <!-- Filter & Search Bar for IAR -->
        <form method="GET" action="reports.php" class="legacy-123">
            <input type="hidden" name="tab" value="iar">
            <div class="legacy-130">
                <label class="legacy-124">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Supplier, PO, or DR No..." class="legacy-125">
            </div>
            <div class="legacy-131">
                <label class="legacy-124">Date Received From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" class="legacy-125">
            </div>
            <div class="legacy-131">
                <label class="legacy-124">Date Received To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" class="legacy-125">
            </div>
            <div>
                <button type="submit" class="legacy-126">Filter</button>
                <a href="reports.php?tab=iar" class="legacy-127">Reset</a>
            </div>
        </form>

        <table class="legacy-128">
            <thead>
                <tr class="legacy-032">
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort PO Number: default order. Activate for ascending." title="Sort PO Number: default order" aria-pressed="false"><span class="tnts-sort-title">PO Number</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort DR Number: default order. Activate for ascending." title="Sort DR Number: default order" aria-pressed="false"><span class="tnts-sort-title">DR Number</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Supplier: default order. Activate for ascending." title="Sort Supplier: default order" aria-pressed="false"><span class="tnts-sort-title">Supplier</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Date Received: default order. Activate for ascending." title="Sort Date Received: default order" aria-pressed="false"><span class="tnts-sort-title">Date Received</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" class="legacy-035">No delivery records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr class="legacy-036">
                        <td class="legacy-091"><?= e($r['po_number'] ?? 'N/A') ?></td>
                        <td class="legacy-038"><?= e($r['dr_number']) ?></td>
                        <td class="legacy-033"><?= e($r['supplier_name']) ?></td>
                        <td class="legacy-033"><?= e(date('M d, Y', strtotime($r['date_received']))) ?></td>
                        <td class="legacy-034">
                            <a href="print_iar.php?id=<?= $r['delivery_id'] ?>" target="_blank" class="legacy-129">🖨 Print IAR PDF</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'condemn'): ?>
        <div class="legacy-121">
            <h3 class="legacy-122">Condemnation & Disposal Forms</h3>
        </div>

        <!-- Filter & Search Bar for Condemnation -->
        <form method="GET" action="reports.php" class="legacy-123">
            <input type="hidden" name="tab" value="condemn">
            <div class="legacy-079">
                <label class="legacy-124">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Form No., Item, or Requester..." class="legacy-125">
            </div>
            <div class="legacy-082">
                <label class="legacy-124">Filter Status</label>
                <select name="status" class="legacy-125">
                    <option value="">-- All Statuses --</option>
                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="legacy-082">
                <label class="legacy-124">Approved From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" class="legacy-125">
            </div>
            <div class="legacy-082">
                <label class="legacy-124">Approved To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" class="legacy-125">
            </div>
            <div>
                <button type="submit" class="legacy-126">Filter</button>
                <a href="reports.php?tab=condemn" class="legacy-127">Reset</a>
            </div>
        </form>

        <table class="legacy-128">
            <thead>
                <tr class="legacy-032">
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Form Number: default order. Activate for ascending." title="Sort Form Number: default order" aria-pressed="false"><span class="tnts-sort-title">Form Number</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Item Name: default order. Activate for ascending." title="Sort Item Name: default order" aria-pressed="false"><span class="tnts-sort-title">Item Name</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Qty: default order. Activate for ascending." title="Sort Qty: default order" aria-pressed="false"><span class="tnts-sort-title">Qty</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Status: default order. Activate for ascending." title="Sort Status: default order" aria-pressed="false"><span class="tnts-sort-title">Status</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" class="legacy-035">No condemnation records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr class="legacy-036">
                        <td class="legacy-038"><?= e($r['form_number']) ?></td>
                        <td class="legacy-033"><?= e($r['item_name']) ?></td>
                        <td class="legacy-091"><?= e($r['quantity']) ?> <?= e($r['unit']) ?></td>
                        <td class="legacy-033">
                            <span class="status-badge <?= $r['status'] === 'approved' ? 'status-success' : 'status-warning' ?>">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>
                        </td>
                        <td class="legacy-034">
                            <?php if ($r['status'] === 'approved'): ?>
                                <a href="print_condemn.php?id=<?= $r['condemnation_id'] ?>" target="_blank" class="legacy-129">🖨 Print Form</a>
                            <?php else: ?>
                                <span class="legacy-041">Pending Approval</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'summary'): ?>
        <div class="legacy-121">
            <h3 class="legacy-122">TVL Course & Elective Inventory Summary</h3>
            <a href="print_course_summary.php?course_id=<?= $filter_course_id ?>&search=<?= urlencode($search_query) ?>" target="_blank" class="legacy-132">
                🖨 Print Official Course Summary
            </a>
        </div>

        <!-- Filter & Search Bar for Course Summary -->
        <form method="GET" action="reports.php" class="legacy-123">
            <input type="hidden" name="tab" value="summary">
            <div class="legacy-130">
                <label class="legacy-124">Search Course Name</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Search Elective Name..." class="legacy-125">
            </div>
            <div class="legacy-130">
                <label class="legacy-124">Select Course</label>
                <select name="course_id" class="legacy-125">
                    <option value="0">-- All TVL / TechPro Electives --</option>
                    <?php foreach ($all_courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $filter_course_id === (int)$c['course_id'] ? 'selected' : '' ?>>
                            <?= e($c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="legacy-126">Filter</button>
                <a href="reports.php?tab=summary" class="legacy-127">Reset</a>
            </div>
        </form>

        <table class="legacy-133">
            <thead>
                <tr class="legacy-032">
                    <th class="legacy-033"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Course / Elective: default order. Activate for ascending." title="Sort Course / Elective: default order" aria-pressed="false"><span class="tnts-sort-title">Course / Elective</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Active ICS: default order. Activate for ascending." title="Sort Active ICS: default order" aria-pressed="false"><span class="tnts-sort-title">Active ICS</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Total Units: default order. Activate for ascending." title="Sort Total Units: default order" aria-pressed="false"><span class="tnts-sort-title">Total Units</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-134"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Serviceable: default order. Activate for ascending." title="Sort Serviceable: default order" aria-pressed="false"><span class="tnts-sort-title">Serviceable</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-135"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort For Repair: default order. Activate for ascending." title="Sort For Repair: default order" aria-pressed="false"><span class="tnts-sort-title">For Repair</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-136"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Damaged: default order. Activate for ascending." title="Sort Damaged: default order" aria-pressed="false"><span class="tnts-sort-title">Damaged</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-137"><button type="button" class="tnts-sort-toggle" data-sort-state="none" aria-label="Sort Total Asset Value: default order. Activate for ascending." title="Sort Total Asset Value: default order" aria-pressed="false"><span class="tnts-sort-title">Total Asset Value</span><span class="tnts-sort-icon" aria-hidden="true">−</span></button></th>
                    <th class="legacy-034">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summary_records)): ?>
                    <tr><td colspan="8" class="legacy-035">No course inventory records found.</td></tr>
                <?php else: 
                    $grand_units = 0;
                    $grand_value = 0;
                    $grand_unpriced = 0;
                    foreach ($summary_records as $r): 
                        $grand_units += $r['total_units_issued'];
                        $grand_value += $r['total_monetary_value'];
                        $grand_unpriced += (int)$r['unpriced_lines'];
                ?>
                    <tr class="legacy-036">
                        <td class="legacy-138"><?= e($r['course_name']) ?></td>
                        <td class="legacy-034"><?= e($r['total_ics_count']) ?> Slips</td>
                        <td class="legacy-139"><?= e($r['total_units_issued']) ?> units</td>
                        <td class="legacy-140"><?= e($r['serviceable_units']) ?></td>
                        <td class="legacy-141"><?= e($r['repair_units']) ?></td>
                        <td class="legacy-142"><?= e($r['damaged_units']) ?></td>
                        <td class="legacy-143">₱<?= number_format($r['total_monetary_value'], 2) ?><?= (int)$r['unpriced_lines'] ? ' (partial: ' . (int)$r['unpriced_lines'] . ' unpriced)' : '' ?></td>
                        <td class="legacy-034">
                            <a href="print_course_summary.php?course_id=<?= $r['course_id'] ?>" target="_blank" class="legacy-144">Print PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="legacy-145">
                    <td class="legacy-033">TOTAL PROGRAM INVENTORY</td>
                    <td class="legacy-033"></td>
                    <td class="legacy-146"><?= number_format($grand_units) ?> units</td>
                    <td colspan="3"></td>
                    <td class="legacy-147">₱<?= number_format($grand_value, 2) ?><?= $grand_unpriced ? ' (partial: ' . $grand_unpriced . ' unpriced)' : '' ?></td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>