<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=current_user(); if (!$user || !in_array($user['role'],['supply_officer','tvl_head'],true)) { http_response_code(403); exit('Access denied'); }
$id=(int)($_GET['item_id'] ?? 0);
$st=db()->prepare('SELECT * FROM inventory_items WHERE item_id=?');$st->execute([$id]);$master=$st->fetch(PDO::FETCH_ASSOC);
if (!$master) {http_response_code(404); exit('Inventory item not found');}
$st=db()->prepare('SELECT b.batch_id,di.*,d.dr_number,d.po_number,d.supplier_name,d.date_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=? ORDER BY d.date_received,b.batch_id');
$st->execute([$id]);$batches=$st->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Inventory Delivery Breakdown';$active='inventory';require_once __DIR__.'/includes/header.php';
?>
<div class="content-header"><h2>Delivery Breakdown: <?= e($master['item_name']) ?></h2><p>Master #<?= $id ?> — <?= e($master['quantity']) ?> <?= e($master['unit']) ?> accepted in total</p></div>
<div class="card" style="overflow-x:auto"><p><a href="<?= e(url('inventory.php?view=central')) ?>">&larr; Back to Inventory</a></p>
<table class="legacy-085"><thead><tr><th>Date</th><th>DR / PO</th><th>Supplier</th><th>Description</th><th>Accepted</th><th>Unit</th><th>Unit cost</th><th>Line value</th></tr></thead><tbody>
<?php foreach($batches as $b): ?><tr><td><?= e($b['date_received']) ?></td><td><?= e($b['dr_number'] ?: '—') ?> / <?= e($b['po_number'] ?: '—') ?></td><td><?= e($b['supplier_name']) ?></td><td><?= e($b['description']) ?></td><td><?= (int)$b['qty_accepted'] ?></td><td><?= e($b['unit']) ?></td><td><?= e(display_unit_cost($b['unit_cost'])) ?></td><td><?= e(display_line_cost($b['unit_cost'],$b['qty_accepted'])) ?></td></tr><?php endforeach; ?>
<?php if (!$batches): ?><tr><td colspan="8">No linked delivery batches.</td></tr><?php endif; ?></tbody></table>
<p><small>Prices are per receipt batch. Unknown costs are not treated as zero. Historical issuances remain linked to the master inventory item.</small></p></div>
<?php require_once __DIR__.'/includes/footer.php'; ?>
