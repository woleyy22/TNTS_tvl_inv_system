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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Condemnation Form - <?= e($record['form_number']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="print-document print-condemn">
<div class="print-brand no-print"><?= school_logo() ?><span>Tanza National Trade School · TVL / TechPro</span></div>

    <div class="no-print legacy-001">
        <button onclick="window.print()" class="legacy-002">🖨 Print Condemnation Form</button>
        <a href="<?= e(url('reports.php?tab=condemn')) ?>" class="legacy-003">Back to Reports</a>
    </div>

    <div class="container">
        <div class="top-appendix">Appendix 65</div>
        <div class="form-title">REPORT OF UNSERVICEABLE PROPERTY & CONDEMNATION</div>

        <!-- Meta Header Details -->
        <table class="meta-header">
            <tr>
                <td class="legacy-007">
                    <strong>Entity Name:</strong> 
                    <span class="meta-line legacy-005">TANZA NATIONAL TRADE SCHOOL</span>
                </td>
                <td class="legacy-019">
                    <strong>Form No.:</strong> 
                    <span class="meta-line legacy-007"><?= e($record['form_number']) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Accountable Officer:</strong> 
                    <span class="meta-line legacy-007"><?= e($record['requester_name']) ?></span>
                </td>
                <td>
                    <strong>Date Requested:</strong> 
                    <span class="meta-line legacy-010"><?= e(date('m/d/Y', strtotime($record['date_approved'] ?? 'now'))) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>Reference ICS No.:</strong> 
                    <span class="meta-line legacy-009"><?= e($record['ics_number']) ?></span>
                </td>
                <td>
                    <strong>Status:</strong> 
                    <span class="meta-line legacy-020"><?= e($record['status']) ?></span>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="legacy-011">Qty</th>
                    <th class="legacy-011">Unit</th>
                    <th class="legacy-021">Item Description</th>
                    <th class="legacy-012">Unit Cost</th>
                    <th class="legacy-022">Reason for Condemnation</th>
                </tr>
            </thead>
            <tbody>
                <tr class="item-row">
                    <td class="legacy-023"><?= e($record['quantity']) ?></td>
                    <td class="legacy-013"><?= e($record['unit']) ?></td>
                    <td>
                        <strong><?= e($record['item_name']) ?></strong>
                        <?php if (!empty($record['description'])): ?>
                            <br><small><?= e($record['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="legacy-024"><?= e(display_unit_cost($record['unit_cost'])) ?></td>
                    <td class="legacy-025">
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
                        <span class="legacy-018">ACCOUNTABLE TEACHER</span>
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
                        <span class="legacy-018">PROPERTY CUSTODIAN</span>
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
                        <span class="legacy-018">SUPPLY OFFICER I</span>
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