<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$course_id = (int)($_GET['course_id'] ?? 0);

// Fetch course details if specific course selected
$course_info = null;
if ($course_id > 0) {
    $stmt = db()->prepare("SELECT * FROM courses WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $course_info = $stmt->fetch();
}

// Query detailed items under selected course or all courses
$where_sql = ($course_id > 0) ? "WHERE c.course_id = $course_id AND c.status = 'active'" : "WHERE c.status = 'active'";

$sql = "
    SELECT ii.*, i.item_name, i.description, i.unit, i.unit_cost, c.ics_number, c.date_issued,
           u.full_name as teacher_name, cr.course_name,
           COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'for_repair'), 0) as repair_qty,
           COALESCE((SELECT SUM(quantity_affected) FROM repair_logs r WHERE r.ics_item_id = ii.ics_item_id AND r.condition_status = 'damaged'), 0) as damaged_qty,
           COALESCE((SELECT SUM(quantity) FROM condemnations co WHERE co.ics_item_id = ii.ics_item_id AND co.status = 'approved'), 0) as approved_condemned
    FROM ics_items ii
    JOIN ics c ON ii.ics_id = c.ics_id
    JOIN inventory_items i ON ii.item_id = i.item_id
    JOIN courses cr ON c.course_id = cr.course_id
    JOIN users u ON c.teacher_id = u.user_id
    $where_sql
    ORDER BY cr.course_name ASC, i.item_name ASC
";
$items = db()->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TVL Course Inventory Summary Report</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body onload="window.print()">
<div class="print-brand no-print"><?= school_logo() ?><span>Tanza National Trade School · TVL / TechPro</span></div>

    <div class="no-print legacy-097">
        <button onclick="window.print()" class="legacy-028">🖨 Print Report PDF</button>
        <a href="<?= e(url('reports.php?tab=summary')) ?>" class="legacy-003">Back to Reports</a>
    </div>

    <div class="container">
        <!-- Official DepEd Header -->
        <div class="header">
            <h5>Republic of the Philippines</h5>
            <h4>DEPARTMENT OF EDUCATION</h4>
            <h4>Region IV-A CALABARZON — Division of Cavite</h4>
            <h3>TANZA NATIONAL TRADE SCHOOL</h3>
            <p>Centennial II, Tanza, Cavite</p>
        </div>

        <div class="title-block">
            TVL / TECHPRO COURSE INVENTORY & PROPERTY SUMMARY REPORT
        </div>

        <table class="meta-info">
            <tr>
                <td><strong>Target Course / Elective:</strong> <?= $course_info ? e($course_info['course_name']) : 'ALL TVL & TECHPRO ELECTIVES' ?></td>
                <td class="legacy-024"><strong>Date Generated:</strong> <?= date('F d, Y') ?></td>
            </tr>
        </table>

        <!-- Summary Table -->
        <table class="summary-table">
            <thead>
                <tr>
                    <th class="legacy-098">Course</th>
                    <th class="legacy-099">Item Name & Description</th>
                    <th class="legacy-100">ICS No.</th>
                    <th class="legacy-012">Accountable Teacher</th>
                    <th class="legacy-101">Total Qty</th>
                    <th class="legacy-101">Serv.</th>
                    <th class="legacy-101">Repair</th>
                    <th class="legacy-101">Dmg.</th>
                    <th class="legacy-102">Unit Cost</th>
                    <th class="legacy-103">Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="10" class="legacy-104">No active inventory records found for this course selection.</td>
                    </tr>
                <?php else: 
                    $total_qty = 0;
                    $total_serv = 0;
                    $total_rep = 0;
                    $total_dmg = 0;
                    $grand_value = 0;

                    foreach ($items as $item):
                        $serviceable = max(0, $item['qty_issued'] - $item['repair_qty'] - $item['damaged_qty'] - $item['approved_condemned']);
                        $line_total = $item['qty_issued'] * $item['unit_cost'];

                        $total_qty += $item['qty_issued'];
                        $total_serv += $serviceable;
                        $total_rep += $item['repair_qty'];
                        $total_dmg += ($item['damaged_qty'] + $item['approved_condemned']);
                        $grand_value += $line_total;
                ?>
                    <tr>
                        <td><strong><?= e($item['course_name']) ?></strong></td>
                        <td>
                            <strong><?= e($item['item_name']) ?></strong>
                            <?php if (!empty($item['description'])): ?>
                                <br><small class="legacy-105"><?= e($item['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="legacy-106"><?= e($item['ics_number']) ?></td>
                        <td><?= e($item['teacher_name']) ?></td>
                        <td class="legacy-107"><?= e($item['qty_issued']) ?></td>
                        <td class="legacy-107"><?= $serviceable ?></td>
                        <td class="legacy-107"><?= e($item['repair_qty']) ?></td>
                        <td class="legacy-107"><?= e($item['damaged_qty'] + $item['approved_condemned']) ?></td>
                        <td class="legacy-024">₱<?= number_format($item['unit_cost'], 2) ?></td>
                        <td class="legacy-108">₱<?= number_format($line_total, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Total Row -->
                <tr class="legacy-109">
                    <td colspan="4" class="legacy-024">PROGRAM TOTALS:</td>
                    <td class="legacy-013"><?= number_format($total_qty) ?></td>
                    <td class="legacy-013"><?= number_format($total_serv) ?></td>
                    <td class="legacy-013"><?= number_format($total_rep) ?></td>
                    <td class="legacy-013"><?= number_format($total_dmg) ?></td>
                    <td></td>
                    <td class="legacy-110">₱<?= number_format($grand_value, 2) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Signatories Footer -->
        <table class="sign-table">
            <tr>
                <td>
                    Prepared & Certified Correct by:
                    <span class="sig-name">ARNOLD G. ANGELES</span>
                    <span class="sig-title">Supply Officer I / Property Custodian</span>
                    <span class="legacy-111">Tanza National Trade School</span>
                </td>
                <td>
                    Verified & Reviewed by:
                    <span class="sig-name">MS. PROFETA</span>
                    <span class="sig-title">TVL / TechPro Subject Group Head</span>
                    <span class="legacy-111">Tanza National Trade School</span>
                </td>
                <td>
                    Approved by:
                    <span class="sig-name">ROLANDO P. DILIDILI, Ed.D.</span>
                    <span class="sig-title">School Principal IV</span>
                    <span class="legacy-111">Tanza National Trade School</span>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>