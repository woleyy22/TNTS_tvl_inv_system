<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Fetch central inventory items
// We also calculate how many are currently issued on an active ICS
$stmt = db()->query("
    SELECT i.*, 
           COALESCE((SELECT SUM(qty_issued) FROM ics_items ii JOIN ics c ON ii.ics_id = c.ics_id WHERE ii.item_id = i.item_id AND c.status = 'active'), 0) as total_issued
    FROM inventory_items i
    ORDER BY i.item_name ASC
");
$inventory = $stmt->fetchAll();

$pageTitle = 'Central Inventory';
$active = 'inventory';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Central Inventory</h2>
    <?php if ($user['role'] === 'supply_officer'): ?>
        <a href="receiving.php" style="padding: 8px 16px; background: #800000; color: white; text-decoration: none; border-radius: 4px;">+ Receive New Delivery</a>
    <?php endif; ?>
</div>

<div class="card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <p style="color: #666; margin-top: 0;">This shows all equipment and supplies that have passed inspection and are ready to be issued.</p>
    
    <table style="width: 100%; border-collapse: collapse; text-align: left; margin-top: 20px;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; background: #f9fafb;">
                <th style="padding: 12px;">Item Details</th>
                <th style="padding: 12px;">Type</th>
                <th style="padding: 12px;">Unit Cost</th>
                <th style="padding: 12px;">Total Accepted</th>
                <th style="padding: 12px;">Currently Issued (ICS)</th>
                <th style="padding: 12px;">Unissued Stock</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inventory)): ?>
                <tr>
                    <td colspan="6" style="padding: 20px; text-align: center; color: #666;">No items in central inventory yet. Accept a delivery first.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($inventory as $item): 
                    $unissued = $item['quantity'] - $item['total_issued'];
                ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= e($item['item_name']) ?></strong><br>
                            <small style="color: #666;"><?= e($item['description']) ?></small>
                        </td>
                        <td style="padding: 12px;">
                            <span style="text-transform: capitalize; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 4px; font-size: 0.85em;">
                                <?= e($item['item_type']) ?>
                            </span>
                        </td>
                        <td style="padding: 12px;">₱<?= e(number_format($item['unit_cost'], 2)) ?></td>
                        <td style="padding: 12px; font-weight: bold;"><?= e($item['quantity']) ?> <?= e($item['unit']) ?></td>
                        <td style="padding: 12px; color: #800000;"><?= e($item['total_issued']) ?> <?= e($item['unit']) ?></td>
                        <td style="padding: 12px;">
                            <?php if ($unissued > 0): ?>
                                <span style="color: #10b981; font-weight: bold;"><?= e($unissued) ?> <?= e($item['unit']) ?></span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: bold;">0 <?= e($item['unit']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>