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
    <title>TVL Course Inventory Summary Report</title>
    <style>
        @page {
            size: portrait;
            margin: 12mm;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            line-height: 1.2;
        }
        .header h5 { margin: 0; font-size: 10pt; font-weight: normal; }
        .header h4 { margin: 2px 0; font-size: 11pt; font-weight: bold; }
        .header h3 { margin: 3px 0; font-size: 13pt; font-weight: bold; text-transform: uppercase; }
        .header p { margin: 0; font-size: 9pt; font-style: italic; }
        
        .title-block {
            text-align: center;
            font-weight: bold;
            font-size: 12pt;
            text-transform: uppercase;
            margin: 15px 0 10px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 5px 0;
        }
        
        .meta-info {
            width: 100%;
            margin-bottom: 10px;
            font-size: 9.5pt;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            font-size: 9pt;
            vertical-align: middle;
        }
        .summary-table th {
            background: #f2f2f2;
            text-align: center;
            font-weight: bold;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 35px;
            page-break-inside: avoid;
        }
        .sign-table td {
            width: 33%;
            vertical-align: top;
            text-align: center;
            padding: 5px;
            font-size: 9pt;
        }
        .sig-name {
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
            display: block;
            margin-top: 35px;
        }
        .sig-title {
            font-size: 8.5pt;
            display: block;
        }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="max-width: 820px; margin: 10px auto; text-align: right;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #800000; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨 Print Report PDF</button>
        <a href="<?= e(url('reports.php?tab=summary')) ?>" style="padding: 8px 16px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; margin-left: 5px; font-weight: bold;">Back to Reports</a>
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
                <td style="text-align: right;"><strong>Date Generated:</strong> <?= date('F d, Y') ?></td>
            </tr>
        </table>

        <!-- Summary Table -->
        <table class="summary-table">
            <thead>
                <tr>
                    <th style="width: 14%;">Course</th>
                    <th style="width: 20%;">Item Name & Description</th>
                    <th style="width: 12%;">ICS No.</th>
                    <th style="width: 15%;">Accountable Teacher</th>
                    <th style="width: 6%;">Total Qty</th>
                    <th style="width: 6%;">Serv.</th>
                    <th style="width: 6%;">Repair</th>
                    <th style="width: 6%;">Dmg.</th>
                    <th style="width: 7%;">Unit Cost</th>
                    <th style="width: 8%;">Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 15px;">No active inventory records found for this course selection.</td>
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
                                <br><small style="color: #444;"><?= e($item['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-size: 8.5pt;"><?= e($item['ics_number']) ?></td>
                        <td><?= e($item['teacher_name']) ?></td>
                        <td style="text-align: center; font-weight: bold;"><?= e($item['qty_issued']) ?></td>
                        <td style="text-align: center; font-weight: bold;"><?= $serviceable ?></td>
                        <td style="text-align: center; font-weight: bold;"><?= e($item['repair_qty']) ?></td>
                        <td style="text-align: center; font-weight: bold;"><?= e($item['damaged_qty'] + $item['approved_condemned']) ?></td>
                        <td style="text-align: right;">₱<?= number_format($item['unit_cost'], 2) ?></td>
                        <td style="text-align: right; font-weight: bold;">₱<?= number_format($line_total, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Total Row -->
                <tr style="background: #f2f2f2; font-weight: bold;">
                    <td colspan="4" style="text-align: right;">PROGRAM TOTALS:</td>
                    <td style="text-align: center;"><?= number_format($total_qty) ?></td>
                    <td style="text-align: center;"><?= number_format($total_serv) ?></td>
                    <td style="text-align: center;"><?= number_format($total_rep) ?></td>
                    <td style="text-align: center;"><?= number_format($total_dmg) ?></td>
                    <td></td>
                    <td style="text-align: right; color: #800000;">₱<?= number_format($grand_value, 2) ?></td>
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
                    <span style="font-size: 8pt;">Tanza National Trade School</span>
                </td>
                <td>
                    Verified & Reviewed by:
                    <span class="sig-name">MS. PROFETA</span>
                    <span class="sig-title">TVL / TechPro Subject Group Head</span>
                    <span style="font-size: 8pt;">Tanza National Trade School</span>
                </td>
                <td>
                    Approved by:
                    <span class="sig-name">ROLANDO P. DILIDILI, Ed.D.</span>
                    <span class="sig-title">School Principal IV</span>
                    <span style="font-size: 8pt;">Tanza National Trade School</span>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>