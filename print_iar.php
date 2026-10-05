<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    redirect('login.php');
}

$delivery_id = (int)($_GET['id'] ?? 0);

// Fetch delivery & inspection details
$stmt = db()->prepare("
    SELECT d.*, u.full_name as receiver_name
    FROM deliveries d
    LEFT JOIN users u ON d.received_by = u.user_id
    WHERE d.delivery_id = ?
");
$stmt->execute([$delivery_id]);
$delivery = $stmt->fetch();

if (!$delivery) {
    die("Delivery / IAR record not found.");
}

// Fetch delivered item lines
$stmt_items = db()->prepare("
    SELECT * FROM delivery_items WHERE delivery_id = ?
");
$stmt_items->execute([$delivery_id]);
$items = $stmt_items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IAR - PO: <?= e($delivery['po_number'] ?? 'N/A') ?></title>
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
            margin: 5px 0 15px 0;
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
            height: 320px; /* Standard paper form height */
        }

        /* Bottom Dual Approval Box */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid #000;
        }
        .footer-table td {
            border: 1px solid #000;
            width: 50%;
            vertical-align: top;
            padding: 10px;
            font-size: 9.5pt;
        }
        .sig-block {
            text-align: center;
            margin-top: 35px;
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

        @media print {
            .no-print { display: none !important; }
            .container { border: 2px solid #000; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="max-width: 800px; margin: 10px auto; text-align: right;">
        <button onclick="window.print()" style="padding: 8px 16px; background: #800000; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨 Print Appendix 62 IAR</button>
        <a href="<?= e(url('reports.php?tab=iar')) ?>" style="padding: 8px 16px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 4px; margin-left: 5px; font-weight: bold;">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 62</div>
        <div class="form-title">INSPECTION AND ACCEPTANCE REPORT</div>

        <!-- Meta Header Details -->
        <table class="meta-header">
            <tr>
                <td style="width: 55%;">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line" style="width: 70%;">TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td style="width: 45%;">
                    <strong>Fund Cluster:</strong> 
                    <span class="meta-line" style="width: 60%;">101</span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Supplier:</strong> 
                    <span class="meta-line" style="width: 75%;"><?= e($delivery['supplier_name']) ?></span>
                </td>
                <td>
                    <strong>IAR No.:</strong> 
                    <span class="meta-line" style="width: 65%;">IAR-<?= date('Y', strtotime($delivery['date_received'])) ?>-<?= sprintf('%03d', $delivery['delivery_id']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>P.O. No. / Date:</strong> 
                    <span class="meta-line" style="width: 65%;"><?= e($delivery['po_number'] ?? 'N/A') ?></span>
                </td>
                <td>
                    <strong>Date:</strong> 
                    <span class="meta-line" style="width: 70%;"><?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Requisitioning Office/Dept:</strong> 
                    <span class="meta-line" style="width: 50%;">TVL Department</span>
                </td>
                <td>
                    <strong>Invoice / DR No.:</strong> 
                    <span class="meta-line" style="width: 55%;"><?= e($delivery['dr_number']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 10%;">Item No.</th>
                    <th style="width: 45%;">Description</th>
                    <th style="width: 15%;">Unit</th>
                    <th style="width: 15%;">Qty Delivered</th>
                    <th style="width: 15%;">Qty Accepted</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <td style="text-align: center;">
                        <?php foreach ($items as $idx => $item): ?>
                            <div><?= $idx + 1 ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach ($items as $item): ?>
                            <div style="margin-bottom: 6px;">
                                <strong><?= e($item['item_name']) ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <br><small><?= e($item['description']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['unit']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['qty_delivered']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php foreach ($items as $item): ?>
                            <div style="font-weight: bold; color: #065f46;"><?= e($item['qty_accepted']) ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </tbody>
        </table>

       <!-- Inspection & Acceptance Signatory Footer -->
        <table class="footer-table">
            <tr>
                <!-- Inspection Section (Sir Ace) -->
                <td>
                    <div><strong>INSPECTION</strong></div>
                    <div style="margin-top: 8px; font-size: 9pt;">
                        Date Inspected: <u><?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?></u>
                    </div>
                    <div style="margin-top: 10px; font-size: 8.5pt; text-align: justify;">
                        [✓] Inspected, verified and found in order as to quantity and specifications.
                    </div>

                    <div class="sig-block">
                        <span class="sig-name">REYNALD ACE</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 2px;">PROPERTY CUSTODIAN</span>
                        <span class="sig-label">Inspection Officer / Chair</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?>
                        </div>
                        <span class="sig-label">Date Inspected</span>
                    </div>
                </td>

                <!-- Acceptance Section (Arnold G. Angeles) -->
                <td>
                    <div><strong>ACCEPTANCE</strong></div>
                    <div style="margin-top: 8px; font-size: 9pt;">
                        Date Received: <u><?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?></u>
                    </div>
                    <div style="margin-top: 10px; font-size: 8.5pt; text-align: justify;">
                        [✓] Complete Delivery & Accepted<br>
                        [ &nbsp; ] Partial Delivery (Please see remarks)
                    </div>

                    <div class="sig-block">
                        <span class="sig-name">ARNOLD G. ANGELES</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span style="font-weight: bold; display: block; margin-top: 2px;">SUPPLY OFFICER I</span>
                        <span class="sig-label">Property Custodian / Supply Office</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?>
                        </div>
                        <span class="sig-label">Date Received</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>