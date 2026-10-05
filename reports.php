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
    $whereClauses = [];
    $params = [];

    if ($role === 'teacher') {
        $whereClauses[] = "c.teacher_id = ?";
        $params[] = $uid;
        $whereClauses[] = "c.status = 'active'";
    }

    if (!empty($search_query)) {
        $whereClauses[] = "(c.ics_number LIKE ? OR u.full_name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    if ($filter_course_id > 0) {
        $whereClauses[] = "c.course_id = ?";
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
        SELECT c.*, u.full_name as teacher_name, cr.course_name 
        FROM ics c
        JOIN users u ON c.teacher_id = u.user_id
        JOIN courses cr ON c.course_id = cr.course_id
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
               COALESCE(SUM(ii.qty_issued), 0) as total_units_issued,
               COALESCE(SUM(ii.qty_issued * i.unit_cost), 0) as total_monetary_value,
               COALESCE(SUM(
                   LEAST(ii.qty_issued, 
                       GREATEST(0, ii.qty_issued 
                           - (SELECT COALESCE(SUM(quantity_affected), 0) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair')
                           - (SELECT COALESCE(SUM(quantity_affected), 0) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged')
                           - (SELECT COALESCE(SUM(quantity), 0) FROM condemnations co WHERE co.ics_item_id = ii.ics_item_id AND co.status = 'approved')
                       )
                   )
               ), 0) as serviceable_units,
               COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii2 ON r.ics_item_id = ii2.ics_item_id JOIN ics c2 ON ii2.ics_id = c2.ics_id WHERE c2.course_id = cr.course_id AND c2.status = 'active' AND r.condition_status = 'for_repair'), 0) as repair_units,
               COALESCE((SELECT SUM(r.quantity_affected) FROM repair_logs r JOIN ics_items ii2 ON r.ics_item_id = ii2.ics_item_id JOIN ics c2 ON ii2.ics_id = c2.ics_id WHERE c2.course_id = cr.course_id AND c2.status = 'active' AND r.condition_status = 'damaged'), 0) as damaged_units
        FROM courses cr
        LEFT JOIN ics c ON cr.course_id = c.course_id AND c.status = 'active'
        LEFT JOIN ics_items ii ON c.ics_id = ii.ics_id
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

<div class="content-header" style="margin-bottom: 20px;">
    <h2>Official Reports & Document Generation</h2>
    <p style="color: #666; margin: 0;">Generate and print government-compliant COA forms (ICS, IAR, Condemnation Forms, and Summaries).</p>
</div>

<!-- Tab Navigation -->
<div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    <a href="reports.php?tab=ics" style="padding: 8px 16px; background: <?= $tab === 'ics' ? '#800000' : '#f3f4f6' ?>; color: <?= $tab === 'ics' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold;">Inventory Custodian Slips (ICS)</a>
    <?php if ($role !== 'teacher'): ?>
        <a href="reports.php?tab=iar" style="padding: 8px 16px; background: <?= $tab === 'iar' ? '#800000' : '#f3f4f6' ?>; color: <?= $tab === 'iar' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold;">Inspection & Acceptance Reports (IAR)</a>
    <?php endif; ?>
    <a href="reports.php?tab=condemn" style="padding: 8px 16px; background: <?= $tab === 'condemn' ? '#800000' : '#f3f4f6' ?>; color: <?= $tab === 'condemn' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold;">Condemnation Forms</a>
    <a href="reports.php?tab=summary" style="padding: 8px 16px; background: <?= $tab === 'summary' ? '#800000' : '#f3f4f6' ?>; color: <?= $tab === 'summary' ? '#fff' : '#333' ?>; text-decoration: none; border-radius: 4px; font-weight: bold;">Course Summary Report</a>
</div>

<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <?php if ($tab === 'ics'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Inventory Custodian Slips (ICS)</h3>
        </div>

        <!-- Filter & Search Bar for ICS -->
        <form method="GET" action="reports.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 15px; background: #f9fafb; padding: 12px; border-radius: 6px; border: 1px solid #eee;">
            <input type="hidden" name="tab" value="ics">
            <div style="flex: 1; min-width: 180px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="ICS No. or Teacher Name..." style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 160px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Filter by Course</label>
                <select name="course_id" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="0">-- All Courses --</option>
                    <?php foreach ($all_courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $filter_course_id === (int)$c['course_id'] ? 'selected' : '' ?>><?= e($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Date Issued From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Date Issued To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div>
                <button type="submit" style="padding: 7px 14px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
                <a href="reports.php?tab=ics" style="padding: 7px 12px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; font-size: 0.9em; margin-left: 4px;">Reset</a>
            </div>
        </form>

        <table style="width: 100%; border-collapse: collapse; margin-top: 15px; text-align: left;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                    <th style="padding: 12px;">ICS Number</th>
                    <th style="padding: 12px;">Teacher Accountable</th>
                    <th style="padding: 12px;">Course / Elective</th>
                    <th style="padding: 12px;">Date Issued</th>
                    <th style="padding: 12px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #666;">No ICS records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px; font-weight: bold; color: #800000;"><?= e($r['ics_number']) ?></td>
                        <td style="padding: 12px;"><?= e($r['teacher_name']) ?></td>
                        <td style="padding: 12px;"><?= e($r['course_name']) ?></td>
                        <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($r['date_issued']))) ?></td>
                        <td style="padding: 12px; text-align: center;">
                            <a href="print_ics.php?id=<?= $r['ics_id'] ?>" target="_blank" style="padding: 6px 12px; background: #374151; color: white; text-decoration: none; border-radius: 4px; font-size: 0.85em; font-weight: bold;">🖨 Print ICS PDF</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'iar' && $role !== 'teacher'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Inspection and Acceptance Reports (IAR)</h3>
        </div>

        <!-- Filter & Search Bar for IAR -->
        <form method="GET" action="reports.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 15px; background: #f9fafb; padding: 12px; border-radius: 6px; border: 1px solid #eee;">
            <input type="hidden" name="tab" value="iar">
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Supplier, PO, or DR No..." style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Date Received From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Date Received To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div>
                <button type="submit" style="padding: 7px 14px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
                <a href="reports.php?tab=iar" style="padding: 7px 12px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; font-size: 0.9em; margin-left: 4px;">Reset</a>
            </div>
        </form>

        <table style="width: 100%; border-collapse: collapse; margin-top: 15px; text-align: left;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                    <th style="padding: 12px;">PO Number</th>
                    <th style="padding: 12px;">DR Number</th>
                    <th style="padding: 12px;">Supplier</th>
                    <th style="padding: 12px;">Date Received</th>
                    <th style="padding: 12px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #666;">No delivery records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px; font-weight: bold;"><?= e($r['po_number'] ?? 'N/A') ?></td>
                        <td style="padding: 12px; font-weight: bold; color: #800000;"><?= e($r['dr_number']) ?></td>
                        <td style="padding: 12px;"><?= e($r['supplier_name']) ?></td>
                        <td style="padding: 12px;"><?= e(date('M d, Y', strtotime($r['date_received']))) ?></td>
                        <td style="padding: 12px; text-align: center;">
                            <a href="print_iar.php?id=<?= $r['delivery_id'] ?>" target="_blank" style="padding: 6px 12px; background: #374151; color: white; text-decoration: none; border-radius: 4px; font-size: 0.85em; font-weight: bold;">🖨 Print IAR PDF</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'condemn'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Condemnation & Disposal Forms</h3>
        </div>

        <!-- Filter & Search Bar for Condemnation -->
        <form method="GET" action="reports.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 15px; background: #f9fafb; padding: 12px; border-radius: 6px; border: 1px solid #eee;">
            <input type="hidden" name="tab" value="condemn">
            <div style="flex: 1; min-width: 180px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Search Keyword</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Form No., Item, or Requester..." style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Filter Status</label>
                <select name="status" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- All Statuses --</option>
                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Approved From</label>
                <input type="date" name="date_from" value="<?= e($filter_date_from) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Approved To</label>
                <input type="date" name="date_to" value="<?= e($filter_date_to) ?>" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div>
                <button type="submit" style="padding: 7px 14px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
                <a href="reports.php?tab=condemn" style="padding: 7px 12px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; font-size: 0.9em; margin-left: 4px;">Reset</a>
            </div>
        </form>

        <table style="width: 100%; border-collapse: collapse; margin-top: 15px; text-align: left;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                    <th style="padding: 12px;">Form Number</th>
                    <th style="padding: 12px;">Item Name</th>
                    <th style="padding: 12px;">Qty</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" style="padding: 20px; text-align: center; color: #666;">No condemnation records found.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px; font-weight: bold; color: #800000;"><?= e($r['form_number']) ?></td>
                        <td style="padding: 12px;"><?= e($r['item_name']) ?></td>
                        <td style="padding: 12px; font-weight: bold;"><?= e($r['quantity']) ?> <?= e($r['unit']) ?></td>
                        <td style="padding: 12px;">
                            <span style="padding: 3px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; background: <?= $r['status'] === 'approved' ? '#d1fae5; color: #065f46;' : '#fef3c7; color: #92400e;' ?>;">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <?php if ($r['status'] === 'approved'): ?>
                                <a href="print_condemn.php?id=<?= $r['condemnation_id'] ?>" target="_blank" style="padding: 6px 12px; background: #374151; color: white; text-decoration: none; border-radius: 4px; font-size: 0.85em; font-weight: bold;">🖨 Print Form</a>
                            <?php else: ?>
                                <span style="color: #666; font-size: 0.85em;">Pending Approval</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'summary'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">TVL Course & Elective Inventory Summary</h3>
            <a href="print_course_summary.php?course_id=<?= $filter_course_id ?>&search=<?= urlencode($search_query) ?>" target="_blank" style="padding: 6px 14px; background: #800000; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 0.9em;">
                🖨 Print Official Course Summary
            </a>
        </div>

        <!-- Filter & Search Bar for Course Summary -->
        <form method="GET" action="reports.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 15px; background: #f9fafb; padding: 12px; border-radius: 6px; border: 1px solid #eee;">
            <input type="hidden" name="tab" value="summary">
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Search Course Name</label>
                <input type="text" name="search" value="<?= e($search_query) ?>" placeholder="Search Elective Name..." style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.85em; font-weight: bold; margin-bottom: 4px;">Select Course</label>
                <select name="course_id" style="width: 100%; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="0">-- All TVL / TechPro Electives --</option>
                    <?php foreach ($all_courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $filter_course_id === (int)$c['course_id'] ? 'selected' : '' ?>>
                            <?= e($c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" style="padding: 7px 14px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
                <a href="reports.php?tab=summary" style="padding: 7px 12px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; font-size: 0.9em; margin-left: 4px;">Reset</a>
            </div>
        </form>

        <table style="width: 100%; border-collapse: collapse; margin-top: 10px; text-align: left;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #eee;">
                    <th style="padding: 12px;">Course / Elective</th>
                    <th style="padding: 12px; text-align: center;">Active ICS</th>
                    <th style="padding: 12px; text-align: center;">Total Units</th>
                    <th style="padding: 12px; text-align: center; color: #10b981;">Serviceable</th>
                    <th style="padding: 12px; text-align: center; color: #d97706;">For Repair</th>
                    <th style="padding: 12px; text-align: center; color: #dc2626;">Damaged</th>
                    <th style="padding: 12px; text-align: right;">Total Asset Value</th>
                    <th style="padding: 12px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summary_records)): ?>
                    <tr><td colspan="8" style="padding: 20px; text-align: center; color: #666;">No course inventory records found.</td></tr>
                <?php else: 
                    $grand_units = 0;
                    $grand_value = 0;
                    foreach ($summary_records as $r): 
                        $grand_units += $r['total_units_issued'];
                        $grand_value += $r['total_monetary_value'];
                ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px; font-weight: bold; color: #1f2937;"><?= e($r['course_name']) ?></td>
                        <td style="padding: 12px; text-align: center;"><?= e($r['total_ics_count']) ?> Slips</td>
                        <td style="padding: 12px; text-align: center; font-weight: bold;"><?= e($r['total_units_issued']) ?> units</td>
                        <td style="padding: 12px; text-align: center; color: #10b981; font-weight: bold;"><?= e($r['serviceable_units']) ?></td>
                        <td style="padding: 12px; text-align: center; color: #d97706; font-weight: bold;"><?= e($r['repair_units']) ?></td>
                        <td style="padding: 12px; text-align: center; color: #dc2626; font-weight: bold;"><?= e($r['damaged_units']) ?></td>
                        <td style="padding: 12px; text-align: right; font-weight: bold; color: #800000;">₱<?= number_format($r['total_monetary_value'], 2) ?></td>
                        <td style="padding: 12px; text-align: center;">
                            <a href="print_course_summary.php?course_id=<?= $r['course_id'] ?>" target="_blank" style="padding: 4px 10px; background: #374151; color: white; text-decoration: none; border-radius: 4px; font-size: 0.85em; font-weight: bold;">Print PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background: #f9fafb; font-weight: bold; border-top: 2px solid #ccc;">
                    <td style="padding: 12px;">TOTAL PROGRAM INVENTORY</td>
                    <td style="padding: 12px;"></td>
                    <td style="padding: 12px; text-align: center; color: #800000;"><?= number_format($grand_units) ?> units</td>
                    <td colspan="3"></td>
                    <td style="padding: 12px; text-align: right; color: #800000;">₱<?= number_format($grand_value, 2) ?></td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>