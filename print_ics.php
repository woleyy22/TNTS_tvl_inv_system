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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ICS - <?= e($ics['ics_number']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="print-document print-ics">
<div class="print-brand no-print"><?= school_logo() ?><span>Tanza National Trade School · TVL / TechPro</span></div>

    <div class="no-print legacy-001">
        <button onclick="window.print()" class="legacy-002">🖨 Print Appendix 59 ICS</button>
        <a href="<?= e(url('reports.php?tab=ics')) ?>" class="legacy-003">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 59</div>
        <div class="form-title">INVENTORY CUSTODIAN SLIP</div>

        <!-- Entity & Header Info -->
        <table class="meta-header">
            <tr>
                <td class="legacy-007">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line legacy-005">DIVISION OF CAVITE-TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td class="legacy-112">
                    <strong>ICS No :</strong> 
                    <span class="meta-line legacy-113"><?= e($ics['ics_number']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Fund Cluster :</strong> 
                    <span class="meta-line legacy-005"><?= !empty($ics['fund_cluster']) ? e($ics['fund_cluster']) : '' ?></span>
                </td>
                <td></td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th rowspan="2" class="legacy-103">Quantity</th>
                    <th rowspan="2" class="legacy-103">Unit</th>
                    <th colspan="2" class="legacy-114">Amount</th>
                    <th rowspan="2" class="legacy-115">Description</th>
                    <th rowspan="2" class="legacy-098">Inventory Item No.</th>
                    <th rowspan="2" class="legacy-098">Estimated Useful Life</th>
                </tr>
                <tr>
                    <th class="legacy-100">Unit Cost</th>
                    <th class="legacy-100">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <!-- Quantity -->
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['qty_issued']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Unit -->
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div><?= e($item['unit']) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Unit Cost -->
                    <td class="legacy-024">
                        <?php foreach ($items as $item): ?>
                            <div>₱<?= number_format($item['unit_cost'], 2) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Total Cost -->
                    <td class="legacy-024">
                        <?php foreach ($items as $item): ?>
                            <div>₱<?= number_format($item['qty_issued'] * $item['unit_cost'], 2) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Description -->
                    <td>
                        <?php foreach ($items as $item): ?>
                            <div class="legacy-116">
                                <strong><?= e($item['item_name']) ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <br><small><?= e($item['description']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Inventory Item No. (Displays nothing if empty) -->
                    <td class="legacy-013">
                        <?php foreach ($items as $item): ?>
                            <div><?= !empty($item['inventory_item_no']) ? e($item['inventory_item_no']) : '' ?></div>
                        <?php endforeach; ?>
                    </td>
                    <!-- Estimated Useful Life (Displays nothing if empty) -->
                    <td class="legacy-013">
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
                <td class="legacy-117"></td>
                <td class="legacy-117">
                    <div class="ack-text">
                        I acknowledge to have received from the Property Custodian, the following property for which I am responsible, subject to the provisions of the Accounting Law, and which will be used in the premises of TANZA NATIONAL TRADE SCHOOL.
                    </div>
                </td>
            </tr>
            <!-- Row 2: Headings and Signatures Horizontally Aligned -->
            <tr>
                <td class="legacy-118">
                    <div><strong>Received from:</strong></div>
                    
                    <div class="sig-block">
                        <span class="sig-name">ARNOLD G. ANGELES</span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span class="legacy-119">SUPPLY OFFICER I</span>
                        <span class="sig-label">Position/Office</span>
                        
                        <div class="sig-date">
                            <?= e(date('m/d/Y', strtotime($ics['date_issued']))) ?>
                        </div>
                        <span class="sig-label">Date</span>
                    </div>
                </td>

                <td class="legacy-118">
                    <div><strong>Received by:</strong></div>

                    <div class="sig-block">
                        <span class="sig-name"><?= e($ics['teacher_name']) ?></span>
                        <span class="sig-label">Signature Over Printed Name</span>
                        <span class="legacy-119">
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