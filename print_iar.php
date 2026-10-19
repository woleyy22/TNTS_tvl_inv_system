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
$stmtIar=db()->prepare('SELECT r.*,u.full_name AS inspector_name FROM inspection_reports r LEFT JOIN users u ON u.user_id=r.inspected_by WHERE r.delivery_id=? ORDER BY r.iar_id DESC LIMIT 1');
$stmtIar->execute([$delivery_id]);$iar=$stmtIar->fetch(PDO::FETCH_ASSOC) ?: [];
$isComplete=($delivery['status']==='accepted');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IAR - PO: <?= e($delivery['po_number'] ?? 'N/A') ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="print-document print-iar">
<div class="print-brand no-print"><?= school_logo() ?><span>Tanza National Trade School · TVL / TechPro</span></div>

    <div class="no-print legacy-001">
        <button onclick="window.print()" class="legacy-002">🖨 Print Appendix 62 IAR</button>
        <a href="<?= e(url('reports.php?tab=iar')) ?>" class="legacy-003">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 62</div>
        <div class="form-title">INSPECTION AND ACCEPTANCE REPORT</div>

        <!-- Meta Header Details -->
        <table class="meta-header">
            <tr>
                <td class="legacy-004">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line legacy-005">TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td class="legacy-006">
                    <strong>Fund Cluster:</strong> 
                    <span class="meta-line legacy-007">101</span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Supplier:</strong> 
                    <span class="meta-line legacy-008"><?= e($delivery['supplier_name']) ?></span>
                </td>
                <td>
                    <strong>IAR No.:</strong> 
                    <span class="meta-line legacy-009"><?= e($iar['iar_number']??'Pending IAR') ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>P.O. No. / Date:</strong> 
                    <span class="meta-line legacy-009"><?= e($delivery['po_number'] ?? 'N/A') ?></span>
                </td>
                <td>
                    <strong>Date:</strong> 
                    <span class="meta-line legacy-005"><?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Requisitioning Office/Dept:</strong> 
                    <span class="meta-line legacy-010">TVL Department</span>
                </td>
                <td>
                    <strong>Invoice / DR No.:</strong> 
                    <span class="meta-line legacy-004"><?= e($delivery['dr_number']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="legacy-011">Item No.</th>
                    <th class="legacy-006">Description</th>
                    <th class="legacy-012">Unit</th>
                    <th class="legacy-012">Qty Delivered</th>
                    <th class="legacy-012">Qty Accepted</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <td class="legacy-013">
                        <?php foreach ($items as $idx => $item): ?>
                            <div><?= $idx + 1 ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach ($items as $item): ?>
                            <div class="legacy-014">
                                <strong><?= e($item['item_name']) ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <br><small><?= e($item['description']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['unit']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['qty_delivered']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div class="legacy-015"><?= e($item['qty_accepted']) ?></div>
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
                    <div class="legacy-016">
                        Date Inspected: <u><?= e(!empty($iar['date_inspected'])?date('m/d/Y',strtotime($iar['date_inspected'])):'__________') ?></u>
                    </div>
                    <div class="legacy-017">
                        [<?= $isComplete?'✓':' ' ?>] Inspected, verified and found in order as to quantity and specifications.
                    </div>

                    <div class="sig-block">
                        <span class="sig-name"><?= e($iar['inspector_name']??'____________________') ?></span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span class="legacy-018">PROPERTY CUSTODIAN</span>
                        <span class="sig-label">Inspection Officer / Chair</span>
                        
                        <div class="sig-date">
                            <?= e(!empty($iar['date_inspected'])?date('m/d/Y',strtotime($iar['date_inspected'])):'__________') ?>
                        </div>
                        <span class="sig-label">Date Inspected</span>
                    </div>
                </td>

                <!-- Acceptance Section (Arnold G. Angeles) -->
                <td>
                    <div><strong>ACCEPTANCE</strong></div>
                    <div class="legacy-016">
                        Date Received: <u><?= e(date('m/d/Y', strtotime($delivery['date_received']))) ?></u>
                    </div>
                    <div class="legacy-017">
                        [<?= $isComplete?'✓':' ' ?>] Complete Delivery & Accepted<br>
                        [<?= !$isComplete?'✓':' ' ?>] Partial Delivery (Please see remarks)
                    </div>

                    <div class="sig-block">
                        <span class="sig-name"><?= e($delivery['receiver_name']??'____________________') ?></span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span class="legacy-018">SUPPLY OFFICER I</span>
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