<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$condemnation_id = (int)($_GET['id'] ?? 0);

// Fetch condemnation, item, teacher, and ICS details
$stmt = db()->prepare("
    SELECT co.*, ii.qty_issued, i.item_name, i.description, i.unit, i.unit_cost, c.ics_number, 
           req.full_name as requester_name, app.full_name as approver_name
    FROM condemnations co
    JOIN ics_items ii ON co.ics_item_id = ii.ics_item_id
    JOIN ics c ON ii.ics_id = c.ics_id
    JOIN inventory_items i ON ii.item_id = i.item_id
    JOIN users req ON co.requested_by = req.user_id
    LEFT JOIN users app ON co.approved_by = app.user_id
    WHERE co.condemnation_id = ?
");
$stmt->execute([$condemnation_id]);
$record = $stmt->fetch();

if (!$record) {
    die("Condemnation record not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Condemnation Form - <?= e($record['form_number']) ?></title>
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
            font-size: 13pt;
            letter-spacing: 0.5px;
            margin: 5px 0 15px 0;
            text-transform: uppercase;
        }
        .meta-header {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        .meta-header td {
            padding: 4px 8px;
            font-size: 9.5pt;
            vertical-align: top;
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
            padding: 6px;
            font-size: 9.5pt;
            vertical-align: top;
        }
        .items-table th {
            text-align: center;
            font-weight: bold;
            background: #f9f9f9;
        }
        .item-row td {
            height: 250px;
        }

        /* Footer Signatories (3 Columns) */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
        }
        .footer-table td {
            border: 1px solid #000;
            width: 33.33%;
            vertical-align: top;
            padding: 8px;
            font-size: 9pt;
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
            font-size: 8pt;
            display: block;
            margin-top: 2px;
        }
        .sig-date {
            border-top: 1px solid #000;
            width: 70%;
            margin: 12px auto 0 auto;
            display: block;
            padding-top: 2px;
            font-size: 8.5pt;
        }

        @media print {
            .no-print { display: none !important; }
            .container { border: 2px solid #000; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="max-width: 800px; margin: 10px auto; text-align: right;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #800000; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨 Print Condemnation Form</button>
        <a href="<?= e(url('reports.php?tab=condemn')) ?>" style="padding: 8px 16px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; margin-left: 5px; font-weight: bold;">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 65</div>
        <div class="form-title">REPORT OF UNSERVICEABLE PROPERTY & CONDEMNATION</div>

        <!-- Meta Header Details -->
        <table class="meta-header">
            <tr>
                <td style="width: 60%;">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line" style="width: 70%;">TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td style="width: 40%;">
                    <strong>Form No.:</strong> 
                    <span class="meta-line" style="width: 60%;"><?= e($record['form_number']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Accountable Officer:</strong> 
                    <span class="meta-line" style="width: 60%;"><?= e($record['requester_name']) ?></span>
                </td>
                <td>
                    <strong>Date Requested:</strong> 
                    <span class="meta-line" style="width: 50%;"><?= e(date('m/d/Y', strtotime($record['date_approved'] ?? 'now'))) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Reference ICS No.:</strong> 
                    <span class="meta-line" style="width: 65%;"><?= e($record['ics_number']) ?></span>
                </td>
                <td>
                    <strong>Status:</strong> 
                    <span class="meta-line" style="width: 60%; text-transform: uppercase;"><?= e($record['status']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 10%;">Qty</th>
                    <th style="width: 10%;">Unit</th>
                    <th style="width: 35%;">Item Description</th>
                    <th style="width: 15%;">Unit Cost</th>
                    <th style="width: 30%;">Reason for Condemnation</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <td style="text-align: center; font-weight: bold; color: #b91c1c;"><?= e($record['quantity']) ?></td>
                    <td style="text-align: center;"><?= e($record['unit']) ?></td>
                    <td>
                        <strong><?= e($record['item_name']) ?></strong>
                        <?php if (!empty($record['description'])): ?>
                            <br><small><?= e($record['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">₱<?= number_format($record['unit_cost'], 2) ?></td>
                    <td style="color: #b91c1c;">
                        <?= e($record['reason']) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Signatories Footer (3 Signatories) -->
        <table class="footer-table">
            <tr>
                <!-- Requisitioner / Teacher -->
                <td>
                    <div><strong>Requested & Certified Damaged:</strong></div>

                    <div class="sig-block">
                        <span class="sig-name"><?= e($record['requester_name']) ?></span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 2px;">ACCOUNTABLE TEACHER</span>
                        <span class="sig-label">TVL Faculty</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($record['date_approved'] ?? 'now'))) ?>
                        </div>
                        <span class="sig-label">Date Requested</span>
                    </div>
                </td>

                <!-- Inspection Officer / Property Custodian (Sir Ace) -->
                <td>
                    <div><strong>Verified & Inspected:</strong></div>

                    <div class="sig-block">
                        <span class="sig-name">REYNALD ACE</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 2px;">PROPERTY CUSTODIAN</span>
                        <span class="sig-label">Inspection Officer</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($record['date_approved'] ?? 'now'))) ?>
                        </div>
                        <span class="sig-label">Date Inspected</span>
                    </div>
                </td>

                <!-- Approval (Arnold G. Angeles) -->
                <td>
                    <div><strong>Approved for Disposal:</strong></div>

                    <div class="sig-block">
                        <span class="sig-name">ARNOLD G. ANGELES</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 2px;">SUPPLY OFFICER I</span>
                        <span class="sig-label">Supply Office / Final Approval</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($record['date_approved'] ?? 'now'))) ?>
                        </div>
                        <span class="sig-label">Date Approved</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>