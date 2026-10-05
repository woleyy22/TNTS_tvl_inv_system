<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$ics_id = (int)($_GET['id'] ?? 0);

// Fetch ICS details
$stmt = db()->prepare("
    SELECT c.*, u.full_name as teacher_name, u.role as teacher_role, cr.course_name
    FROM ics c
    JOIN users u ON c.teacher_id = u.user_id
    JOIN courses cr ON c.course_id = cr.course_id
    WHERE c.ics_id = ?
");
$stmt->execute([$ics_id]);
$ics = $stmt->fetch();

if (!$ics) {
    die("ICS record not found.");
}

// Fetch ICS items safely without requiring missing columns
$stmt_items = db()->prepare("
    SELECT ii.*, i.item_name, i.description, i.unit, i.unit_cost
    FROM ics_items ii
    JOIN inventory_items i ON ii.item_id = i.item_id
    WHERE ii.ics_id = ?
");
$stmt_items->execute([$ics_id]);
$items = $stmt_items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ICS - <?= e($ics['ics_number']) ?></title>
    <style>
        @page {
            size: portrait;
            margin: 15mm;
        }
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #000;
            box-sizing: border-box;
        }
        .top-appendix {
            text-align: right;
            font-style: italic;
            font-size: 10pt;
            padding: 4px 8px 0 0;
            font-weight: bold;
        }
        .form-title {
            text-align: center;
            font-weight: bold;
            font-size: 14pt;
            letter-spacing: 1px;
            margin: 10px 0 15px 0;
        }
        .meta-header {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        .meta-header td {
            padding: 4px 8px;
            font-size: 10pt;
            vertical-align: bottom;
        }
        .meta-line {
            border-bottom: 1px solid #000;
            display: inline-block;
            font-weight: bold;
            padding: 0 5px;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 5px;
            font-size: 9.5pt;
            vertical-align: top;
        }
        .items-table th {
            text-align: center;
            font-weight: bold;
        }
        .item-row td {
            height: 380px; /* Standard Appendix 59 form height */
        }
        
        /* Bottom Signatory Box */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
        }
        .footer-table td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            width: 50%;
            vertical-align: top;
            padding: 8px;
            font-size: 9.5pt;
        }
        .ack-text {
            font-size: 8.5pt;
            text-align: justify;
            margin-bottom: 10px;
            line-height: 1.2;
        }
        .sig-block {
            text-align: center;
            margin-top: 25px;
        }
        .sig-name {
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
            display: block;
        }
        .sig-label {
            font-size: 8.5pt;
            display: block;
            margin-top: 2px;
        }
        .sig-date {
            border-top: 1px solid #000;
            width: 60%;
            margin: 15px auto 0 auto;
            display: block;
            padding-top: 2px;
            font-size: 9pt;
        }

        /* Screen Only Action Bar */
        @media print {
            .no-print { display: none !important; }
            .container { border: 2px solid #000; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="max-width: 800px; margin: 10px auto; text-align: right;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #800000; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨 Print Appendix 59 ICS</button>
        <a href="<?= e(url('reports.php?tab=ics')) ?>" style="padding: 8px 16px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; margin-left: 5px; font-weight: bold;">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 59</div>
        <div class="form-title">INVENTORY CUSTODIAN SLIP</div>

        <!-- Entity & Header Info -->
        <table class="meta-header">
            <tr>
                <td style="width: 60%;">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line" style="width: 70%;">DIVISION OF CAVITE-TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td style="width: 40%; text-align: right;">
                    <strong>ICS No :</strong> 
                    <span class="meta-line" style="width: 65%; text-align: center;"><?= e($ics['ics_number']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Fund Cluster :</strong> 
                    <span class="meta-line" style="width: 70%;"><?= !empty($ics['fund_cluster']) ? e($ics['fund_cluster']) : '' ?></span>
                </td>
                <td></td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 8%;" rowspan="2">Quantity</th>
                    <th style="width: 8%;" rowspan="2">Unit</th>
                    <th style="width: 24%;" colspan="2">Amount</th>
                    <th style="width: 32%;" rowspan="2">Description</th>
                    <th style="width: 14%;" rowspan="2">Inventory Item No.</th>
                    <th style="width: 14%;" rowspan="2">Estimated Useful Life</th>
                </tr>
                <tr>
                    <th style="width: 12%;">Unit Cost</th>
                    <th style="width: 12%;">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <!-- Quantity -->
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['qty_issued']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Unit -->
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['unit']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Unit Cost -->
                    <td style="text-align: right;">
                        <?php foreach ($items as $item): ?>
                            <div>₱<?= number_format($item['unit_cost'], 2) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Total Cost -->
                    <td style="text-align: right;">
                        <?php foreach ($items as $item): ?>
                            <div>₱<?= number_format($item['qty_issued'] * $item['unit_cost'], 2) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Description -->
                    <td>
                        <?php foreach ($items as $item): ?>
                            <div style="margin-bottom: 8px;">
                                <strong><?= e($item['item_name']) ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <br><small><?= e($item['description']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Inventory Item No. (Displays nothing if empty) -->
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= !empty($item['inventory_item_no']) ? e($item['inventory_item_no']) : '' ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Estimated Useful Life (Displays nothing if empty) -->
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= !empty($item['estimated_useful_life']) ? e($item['estimated_useful_life']) : '' ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Footer / Signatories Box with Perfectly Aligned Headings -->
        <table class="footer-table">
            <!-- Row 1: Legal Acknowledgment Paragraph on Right, Empty Space on Left -->
            <tr>
                <td style="border-bottom: none;"></td>
                <td style="border-bottom: none;">
                    <div class="ack-text">
                        I acknowledge to have received from the Property Custodian, the following property for which I am responsible, subject to the provisions of the Accounting Law, and which will be used in the premises of TANZA NATIONAL TRADE SCHOOL.
                    </div>
                </td>
            </tr>
            <!-- Row 2: Headings and Signatures Horizontally Aligned -->
            <tr>
                <td style="border-top: none; border-bottom: 1px solid #000;">
                    <div><strong>Received from:</strong></div>
                    
                    <div class="sig-block">
                        <span class="sig-name">ARNOLD G. ANGELES</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 3px;">SUPPLY OFFICER I</span>
                        <span class="sig-label">Position/Office</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($ics['date_issued']))) ?>
                        </div>
                        <span class="sig-label">Date</span>
                    </div>
                </td>

                <td style="border-top: none; border-bottom: 1px solid #000;">
                    <div><strong>Received by:</strong></div>

                    <div class="sig-block">
                        <span class="sig-name"><?= e($ics['teacher_name']) ?></span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 3px;">
                            <?= e(strtoupper($ics['teacher_role'] === 'teacher' ? 'TEACHER / TVL FACULTY' : $ics['teacher_role'])) ?>
                        </span>
                        <span class="sig-label">Position/Office</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($ics['teacher_signed_date'] ?? $ics['date_issued']))) ?>
                        </div>
                        <span class="sig-label">Date</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>