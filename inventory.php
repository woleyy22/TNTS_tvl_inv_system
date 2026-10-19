<?php
require_once __DIR__.'/includes/db.php'; require_once __DIR__.'/includes/auth.php'; require_once __DIR__.'/includes/functions.php';
$user=require_login();$role=$user['role'];$uid=(int)$user['id'];
$isAdmin=in_array($role,['supply_officer','tvl_head'],true);
$catLabels=['equipment'=>'Equipment','non_consumable'=>'Non-Consumable','consumable'=>'Consumable','expiring_material'=>'Expiring Material'];
$category=trim((string)($_GET['category']??''));if(!isset($catLabels[$category]))$category='';
$search=trim((string)($_GET['search']??''));$view=($_GET['view']??'central')==='issued'?'issued':'central';
$po=trim((string)($_GET['po_number']??''));$dr=trim((string)($_GET['dr_number']??''));$supplier=trim((string)($_GET['supplier_name']??''));
$course=(int)($_GET['course_id']??0);$teacher=(int)($_GET['teacher_id']??0);
$classification=(int)($_GET['classification_id']??0);
// Dates are supplied as ISO YYYY-MM-DD. Invalid/inverted ranges must not silently become unfiltered reports.
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$dateFilterError='';
foreach (['From'=>$dateFrom, 'To'=>$dateTo] as $name=>$value) {
  if ($value!=='') {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d')!==$value) $dateFilterError='Please enter valid From and To dates.';
  }
}
if (!$dateFilterError && $dateFrom!=='' && $dateTo!=='' && $dateFrom>$dateTo) {
  $dateFilterError='The From date must be on or before the To date.';
}
$classifications=db()->query('SELECT classification_id,classification_name FROM item_classifications ORDER BY classification_name')->fetchAll();
$items=[];$params=[];$where=[];
if($role==='teacher'||$view==='issued'){
  $sql="SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,i.item_id,i.item_name,i.description,i.unit,i.item_category,i.low_stock_threshold,c.ics_number,c.date_issued,rd.first_received,rd.last_received,co.course_name,u.full_name AS teacher_name,
    COALESCE(b.qty_for_repair,0) repair_qty, COALESCE(b.qty_serviceable,0) serviceable_balance, COALESCE(b.needs_review,0) needs_review,
    COALESCE(b.qty_damaged,0) damaged_qty,
    COALESCE((SELECT SUM(quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0) disposed_qty
    FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=c.course_id LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id LEFT JOIN (SELECT b.inventory_item_id, MIN(d.date_received) AS first_received, MAX(d.date_received) AS last_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id GROUP BY b.inventory_item_id) rd ON rd.inventory_item_id=i.item_id WHERE c.status='active' AND (i.item_category IN ('consumable','expiring_material') OR ii.qty_issued>ii.qty_returned+COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0))";
  if($role==='teacher'){$sql.=' AND c.teacher_id=?';$params[]=$uid;}
  elseif($teacher){$sql.=' AND c.teacher_id=?';$params[]=$teacher;}
  if($course){$sql.=' AND c.course_id=?';$params[]=$course;}
  if($category){$sql.=' AND i.item_category=?';$params[]=$category;}
  if($search!==''){$sql.=' AND (i.item_name LIKE ? OR c.ics_number LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%');}
  // In the teacher/ICS view, date filters refer to the ICS issuance date.
  if ($dateFrom!=='') {$sql.=' AND c.date_issued>=?';$params[]=$dateFrom;}
  if ($dateTo!=='') {$sql.=' AND c.date_issued<=?';$params[]=$dateTo;}
  $sql.=' ORDER BY c.date_issued DESC,ii.ics_item_id DESC';
} else {
  $sql="SELECT i.*,cl.classification_name,rd.first_received,rd.last_received,
  (SELECT COUNT(*) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id) batch_count,
  (SELECT MIN(b.expiration_date) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id AND b.expiration_date IS NOT NULL AND b.expiration_date>=CURDATE()) next_expiry,
  (SELECT COUNT(*) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id AND b.expiration_date IS NOT NULL AND b.expiration_date<CURDATE()) expired_batches,
  COALESCE((SELECT SUM(ii.qty_issued) FROM ics_items ii WHERE ii.item_id=i.item_id),0) gross_issued,
  COALESCE((SELECT SUM(ii.qty_issued-ii.qty_returned) FROM ics_items ii WHERE ii.item_id=i.item_id),0) active_balance,
  COALESCE((SELECT SUM(x.quantity) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=i.item_id AND c.status='closed' AND x.status='approved' AND x.outcome='disposed'),0) closed_disposals,
  COALESCE((SELECT SUM(x.quantity) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id WHERE ii.item_id=i.item_id AND x.status='approved' AND x.outcome='disposed'),0) disposed,
  COALESCE((SELECT SUM(b.qty_for_repair) FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.item_id=i.item_id),0) repair_qty,
  COALESCE((SELECT SUM(b.qty_damaged) FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.item_id=i.item_id),0) damaged_qty,
  COALESCE((SELECT SUM(rl.qty_for_repair+rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) returned_unserviceable,
  COALESCE((SELECT SUM(rl.qty_for_repair) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) central_repair,
  COALESCE((SELECT SUM(rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) central_damaged,
  COALESCE((SELECT SUM(w.quantity) FROM inventory_withdrawals w WHERE w.item_id=i.item_id),0) withdrawn
  FROM inventory_items i LEFT JOIN item_classifications cl ON cl.classification_id=i.classification_id LEFT JOIN (SELECT b.inventory_item_id, MIN(d.date_received) AS first_received, MAX(d.date_received) AS last_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id GROUP BY b.inventory_item_id) rd ON rd.inventory_item_id=i.item_id WHERE 1=1";
  if($category){$sql.=' AND i.item_category=?';$params[]=$category;}
  if($classification){$sql.=' AND i.classification_id=?';$params[]=$classification;}
  if($search!==''){$sql.=' AND (i.item_name LIKE ? OR i.description LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%');}
  foreach(['po_number'=>$po,'dr_number'=>$dr,'supplier_name'=>$supplier] as $field=>$value){if($value!==''){$sql.=" AND EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id AND d.$field=?)";$params[]=$value;}}
  // A date-range match means ANY delivery of this master item arrived during the range.
  // It does not trim the item's all-time accepted quantity or invent batch-to-ICS allocation.
  if ($dateFrom!=='' || $dateTo!=='') {
    $sql.=' AND EXISTS (SELECT 1 FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id WHERE b.inventory_item_id=i.item_id';
    if ($dateFrom!=='') {$sql.=' AND d.date_received>=?';$params[]=$dateFrom;}
    if ($dateTo!=='') {$sql.=' AND d.date_received<=?';$params[]=$dateTo;}
    $sql.=')';
  }
  $sql.=' ORDER BY i.item_name,i.item_id';
}
if ($dateFilterError==='') {
  $st=db()->prepare($sql);$st->execute($params);$items=$st->fetchAll();
}
foreach($items as &$i){
 $i['category_label']=$catLabels[$i['item_category']]??'Unknown';$i['is_supply']=in_array($i['item_category'],['consumable','expiring_material'],true);
 if(isset($i['gross_issued'])){
  $i['net_issued']=$i['is_supply']?(int)$i['gross_issued']:max(0,(int)$i['active_balance']-(int)$i['disposed']);
  $i['available']=max(0,(int)$i['quantity']-(int)$i['withdrawn']-($i['is_supply']?(int)$i['gross_issued']:((int)$i['active_balance']))-(int)$i['returned_unserviceable']);
  $i['serviceable']=max(0,(int)$i['net_issued']-(int)$i['repair_qty']-(int)$i['damaged_qty']);
  $i['low_stock']=$i['low_stock_threshold']!==null && $i['available']<=(int)$i['low_stock_threshold'];
 }else{
  $i['net_issued']=$i['is_supply']?(int)$i['qty_issued']:max(0,(int)$i['qty_issued']-(int)$i['qty_returned']-(int)$i['disposed_qty']);
  $i['serviceable']=$i['is_supply']?0:(int)$i['serviceable_balance'];
 }
}unset($i);
$teachers=[];$courses=[];
if($isAdmin){$teachers=db()->query("SELECT user_id,full_name FROM users WHERE role='teacher' ORDER BY full_name")->fetchAll();$courses=db()->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll();}
$pageTitle='Inventory Management';$active='inventory';require __DIR__.'/includes/header.php';
?>
<style>
/* Inventory-specific UI: isolated from shared modals and shared table sorter. */
.p34-header{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}
.p34-actions{display:flex;flex-wrap:wrap;gap:9px}
.p34-sub{font-size:.81rem;color:#687587;line-height:1.45}
.p34-filter{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
.p34-filter .p34-field{display:grid;gap:5px;min-width:125px}
.p34-filter label{font-size:.81rem;font-weight:700;color:#344054}
.p34-filter input,.p34-filter select{width:100%;min-width:125px;max-width:230px}
.p34-filter input[type="date"]{min-width:160px}
.p34-filter-actions{display:flex;align-items:center;gap:9px}
.p34-filter-note{margin:10px 0 0;font-size:.78rem;color:#667085}
.p34-validation{padding:12px 15px;border:1px solid #f5b1ad;background:#fff0ef;color:#92211e;border-radius:9px;margin-bottom:12px}
.p34-wrap{overflow-x:auto}
.p34-table{width:100%;border-collapse:separate;border-spacing:0;min-width:850px}
.p34-table th,.p34-table td{text-align:left;vertical-align:middle;padding:13px 10px;border-bottom:1px solid #e7ebf0}
.p34-table th{background:#f5f6f9;font-size:.73rem;color:#657187;font-weight:700;white-space:normal}
.p34-record{cursor:pointer;transition:background .12s ease}
.p34-record:hover,.p34-record:focus-within{background:#f8f6f8}
.p34-record.p34-open{background:#faf4f6}
.p34-sort{border:0!important;background:transparent!important;box-shadow:none!important;color:inherit!important;font:inherit!important;font-weight:700!important;display:inline-flex;align-items:center;gap:6px;text-align:left;cursor:pointer;padding:4px 0!important}
.p34-sort:hover,.p34-sort:focus-visible{color:#811633!important}
.p34-sort .p34-indicator{font-size:1rem;min-width:12px;color:#8a92a0}
.p34-sort[data-direction="asc"] .p34-indicator,.p34-sort[data-direction="desc"] .p34-indicator{color:#861334}
.p34-sort:focus-visible,.p34-view:focus-visible{outline:2px solid #861334;outline-offset:3px}
.p34-strong{font-weight:750;color:#263248}
.p34-cell-main{display:grid;gap:4px;min-width:105px}
.p34-muted{color:#687587;font-size:.8rem;line-height:1.42}
.p34-badge{display:inline-flex;gap:4px;align-items:center;justify-content:center;border-radius:99px;padding:4px 9px;font-size:.76rem;font-weight:750;line-height:1.3;background:#f1f3f7;color:#475467;white-space:nowrap}
.p34-ok{background:#e2f5e9;color:#19673e}
.p34-repair{background:#fff0cf;color:#8b5312}
.p34-damaged{background:#ffe3e2;color:#a12429}
.p34-neutral{background:#e9edf3;color:#465365}
.p34-alert{background:#fff0cb;color:#804a06}
.p34-pills{display:flex;gap:5px;flex-wrap:wrap;margin:4px 0}
.p34-view{border:1px solid #d2d6de;background:#fff;color:#712038;border-radius:8px;padding:8px 11px;cursor:pointer;font-size:.79rem;font-weight:750;white-space:nowrap}
.p34-view:hover,.p34-view[aria-expanded="true"]{border-color:#8e1e40;background:#fbedf1}
.p34-details[hidden]{display:none!important}
.p34-details>td{padding:0 12px 15px!important;background:#faf8fa;border-bottom:2px solid #dfc9d1!important}
.p34-panel{border:1px solid #e8d8dd;border-radius:12px;background:#fff;padding:18px;margin-top:10px}
.p34-panel-head{display:flex;align-items:start;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.p34-panel-head h3{margin:0 0 5px;font-size:1.06rem;color:#80213c}
/* Phase 3.5: straightforward pill summary matching the requested compact layout.
   Works for central stock and teacher ICS detail rows without changing values. */
.p34-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px 12px;margin:12px 0 18px;align-items:stretch}
.p34-panel .p34-stat{
  box-sizing:border-box;min-width:0;min-height:42px;padding:8px 12px;
  display:flex;flex-wrap:wrap;align-items:center;justify-content:center;align-content:center;
  gap:0 5px;text-align:center;border:1px solid #cbd1db;border-radius:999px;
  background:#f7f8fa;color:#344054;font-size:.8rem;line-height:1.35;
}
.p34-panel .p34-stat .p34-muted{color:inherit;font-size:inherit;line-height:inherit;font-weight:500;overflow-wrap:break-word}
.p34-panel .p34-stat strong{font-size:inherit;font-weight:800;color:inherit;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.p34-panel .p34-stat.p34-stat-ok{background:#edf8f0;border-color:#b8d9c4;color:#176338}
.p34-panel .p34-stat.p34-stat-repair{background:#fff6e3;border-color:#e9d39f;color:#865110}
.p34-panel .p34-stat.p34-stat-damaged{background:#ffefef;border-color:#edc3c3;color:#a32931}
.p34-dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:9px 15px;margin:8px 0 0}
.p34-dl>div{border-bottom:1px solid #ebedf1;padding-bottom:7px;min-width:0}
.p34-dl dt{font-size:.77rem;color:#667085;font-weight:650}
.p34-dl dd{margin:4px 0 0;color:#344054;font-size:.88rem;overflow-wrap:anywhere}
.p34-note{background:#f4f6f8;color:#596576;padding:10px 12px;border-radius:8px;font-size:.78rem;margin-top:12px}
.p34-panel-footer{margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.p34-link{font-size:.86rem;font-weight:700;color:#821634}
.p34-empty{text-align:center;color:#667085;padding:24px!important}
@media(max-width:1000px){.p34-stat-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:700px){.p34-panel{padding:13px}.p34-filter .p34-field{min-width:140px;flex:1}.p34-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:440px){.p34-stat-grid{grid-template-columns:1fr}.p34-panel .p34-stat{min-height:36px}}
@media(prefers-reduced-motion:reduce){.p34-record{transition:none}}
</style>
<?php $issuedView=($role==='teacher'||$view==='issued'); $colspan=$issuedView?7:8; ?>
<div class="content-header p34-header">
  <div><h2>Inventory Management</h2><p class="p34-sub">Category-specific stock and accountability overview</p></div>
  <?php if($isAdmin): ?><div class="p34-actions"><a class="legacy-028" href="<?= e(url('inventory_categories.php')) ?>">Manage classifications</a><a class="legacy-028" href="<?= e(url('inventory_withdrawals.php')) ?>">Withdraw / disposition</a></div><?php endif; ?>
</div>
<?php if($isAdmin): ?>
<nav class="p34-actions" style="margin-bottom:14px" aria-label="Inventory views">
  <a class="view-tab <?= $view==='central'?'is-active':'' ?>" href="<?= e(url('inventory.php?view=central')) ?>">Central Stock</a>
  <a class="view-tab <?= $view==='issued'?'is-active':'' ?>" href="<?= e(url('inventory.php?view=issued')) ?>">Teacher ICS Inventory</a>
</nav>
<?php endif; ?>
<div class="card">
  <?php if($dateFilterError): ?><div role="alert" class="p34-validation"><?= e($dateFilterError) ?></div><?php endif; ?>
  <form method="get" class="p34-filter">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="p34-field"><label for="p34-search">Search</label><input class="legacy-047" id="p34-search" name="search" value="<?= e($search) ?>" placeholder="Item or ICS number"></div>
    <div class="p34-field"><label for="p34-category">Tracking category</label><select class="legacy-047" id="p34-category" name="category"><option value="">All categories</option><?php foreach($catLabels as $key=>$label): ?><option value="<?= e($key) ?>" <?= $category===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <?php if($isAdmin&&!$issuedView): ?>
      <div class="p34-field"><label for="p34-class">Classification</label><select class="legacy-047" id="p34-class" name="classification_id"><option value="">All classifications</option><?php foreach($classifications as $cl): ?><option value="<?= (int)$cl['classification_id'] ?>" <?= $classification===(int)$cl['classification_id']?'selected':'' ?>><?= e($cl['classification_name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if($isAdmin&&$issuedView): ?>
      <div class="p34-field"><label for="p34-teacher">Teacher</label><select class="legacy-047" id="p34-teacher" name="teacher_id"><option value="">All teachers</option><?php foreach($teachers as $t): ?><option value="<?= (int)$t['user_id'] ?>" <?= $teacher===(int)$t['user_id']?'selected':'' ?>><?= e($t['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="p34-field"><label for="p34-course">Course</label><select class="legacy-047" id="p34-course" name="course_id"><option value="">All courses</option><?php foreach($courses as $c): ?><option value="<?= (int)$c['course_id'] ?>" <?= $course===(int)$c['course_id']?'selected':'' ?>><?= e($c['course_name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="p34-field"><label for="p34-from"><?= $issuedView?'Issued from':'Delivered from' ?></label><input class="legacy-047" type="date" id="p34-from" name="date_from" value="<?= e($dateFrom) ?>"></div>
    <div class="p34-field"><label for="p34-to"><?= $issuedView?'Issued through':'Delivered through' ?></label><input class="legacy-047" type="date" id="p34-to" name="date_to" value="<?= e($dateTo) ?>"></div>
    <div class="p34-filter-actions"><button class="legacy-028" type="submit">Apply filters</button><a class="p34-view" href="<?= e(url('inventory.php?view='.$view)) ?>">Reset</a></div>
  </form>
  <p class="p34-filter-note">
    <?php if($issuedView): ?>Date range filters by <strong>Date Issued</strong> on an active ICS.
    <?php else: ?>Date range finds items linked to <strong>at least one delivery received within the selected dates</strong>. Stock totals remain all-time totals, not just quantities from the selected deliveries.<?php endif; ?>
  </p>
</div>
<div class="card p34-wrap" style="margin-top:16px">
  <table class="p34-table" id="p34InventoryTable">
    <thead><tr>
      <th><button type="button" class="p34-sort" data-key="item" data-type="text" data-direction="none">Item <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <th><button type="button" class="p34-sort" data-key="category" data-type="text" data-direction="none">Tracking category <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php if($issuedView): ?>
        <th><button type="button" class="p34-sort" data-key="ics" data-type="text" data-direction="none">ICS / Teacher <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="date" data-type="date" data-direction="none">Date Issued <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="accepted" data-type="number" data-direction="none">Original ICS <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="qty" data-type="number" data-direction="none">Quantity / Condition <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php else: ?>
        <th><button type="button" class="p34-sort" data-key="date" data-type="date" data-direction="none">Latest Arrival <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="accepted" data-type="number" data-direction="none">Accepted <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="issued" data-type="number" data-direction="none">Issued <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="qty" data-type="number" data-direction="none">Unissued <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="condition" data-type="number" data-direction="none" title="Sort by attention needed: low stock or non-serviceable counts">Condition / Stock Alerts <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php endif; ?>
      <th>Details</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $i):
      $rowKey=$issuedView?'ics-'.$i['ics_item_id']:'stock-'.$i['item_id'];
      $sortCondition=$issuedView?((int)$i['repair_qty']+(int)$i['damaged_qty']):($i['is_supply']?($i['low_stock']?1:0):((int)$i['repair_qty']+(int)$i['damaged_qty']+(int)$i['central_repair']+(int)$i['central_damaged']));
    ?>
      <tr class="p34-record" data-item="<?= e($i['item_name']) ?>" data-category="<?= e($i['category_label']) ?>" data-ics="<?= e($issuedView?$i['ics_number'].' '.$i['teacher_name']:'') ?>" data-date="<?= e($issuedView?($i['date_issued']??''):($i['last_received']??'')) ?>" data-accepted="<?= $issuedView?(int)$i['qty_issued']:(int)$i['quantity'] ?>" data-issued="<?= $issuedView?(int)$i['net_issued']:(int)$i['net_issued'] ?>" data-qty="<?= (int)($issuedView?$i['net_issued']:$i['available']) ?>" data-condition="<?= $sortCondition ?>">
        <td><div class="p34-cell-main"><strong class="p34-strong"><?= e($i['item_name']) ?></strong><span class="p34-muted"><?= e($i['description']??'') ?></span><?php if(!empty($i['classification_name'])): ?><span class="p34-badge"><?= e($i['classification_name']) ?></span><?php endif; ?></div></td>
        <td><span class="p34-badge"><?= e($i['category_label']) ?></span></td>
        <?php if($issuedView): ?>
          <td><div class="p34-cell-main"><span><?= e($i['ics_number']) ?></span><span class="p34-muted"><?= e($i['teacher_name']) ?> · <?= e($i['course_name']??'') ?></span></div></td>
          <td><?= e($i['date_issued']??'—') ?></td>
          <td><?= (int)$i['qty_issued'] ?> <?= e($i['unit']) ?></td>
          <td>
            <?php if($i['is_supply']): ?><span class="p34-badge p34-neutral">Distributed: <?= (int)$i['qty_issued'] ?></span>
            <?php else: ?>
              <div><strong class="p34-strong">On Teacher Record: <?= (int)$i['net_issued'] ?></strong></div>
              <div class="p34-pills"><span class="p34-badge p34-ok">Serviceable <?= (int)$i['serviceable'] ?></span><span class="p34-badge p34-repair">For Repair <?= (int)$i['repair_qty'] ?></span><span class="p34-badge p34-damaged">Damaged <?= (int)$i['damaged_qty'] ?></span></div>
              <div class="p34-muted">Retrieved <?= (int)$i['qty_returned'] ?> · Disposed <?= (int)$i['disposed_qty'] ?></div>
              <?php if(!empty($i['needs_review'])): ?><span class="p34-badge p34-alert">Needs physical verification</span><?php endif; ?>
            <?php endif; ?>
          </td>
        <?php else: ?>
          <td><div class="p34-cell-main"><?= e($i['last_received']??'—') ?><?php if(!empty($i['first_received'])&&$i['first_received']!==$i['last_received']): ?><span class="p34-muted">First: <?= e($i['first_received']) ?></span><?php endif; ?></div></td>
          <td><?= (int)$i['quantity'] ?> <?= e($i['unit']) ?></td>
          <td><?= (int)$i['net_issued'] ?></td>
          <td><strong class="p34-strong"><?= (int)$i['available'] ?></strong></td>
          <td>
            <?php if($i['is_supply']): ?>
              <div class="p34-pills"><span class="p34-badge p34-neutral">Distributed <?= (int)$i['gross_issued'] ?></span><?php if($i['low_stock']): ?><span class="p34-badge p34-alert">Low Stock</span><?php endif; ?><?php if((int)$i['expired_batches']>0): ?><span class="p34-badge p34-damaged">Expired batch on file</span><?php endif; ?></div>
              <?php if($i['next_expiry']): ?><div class="p34-muted">Next dated batch: <?= e($i['next_expiry']) ?></div><?php endif; ?>
            <?php else: ?>
              <div class="p34-pills"><span class="p34-badge p34-ok">Issued Serviceable <?= (int)$i['serviceable'] ?></span><span class="p34-badge p34-repair">Teacher Repair <?= (int)$i['repair_qty'] ?></span><span class="p34-badge p34-damaged">Teacher Damaged <?= (int)$i['damaged_qty'] ?></span></div>
              <div class="p34-pills"><span class="p34-badge p34-repair">Central Repair <?= (int)$i['central_repair'] ?></span><span class="p34-badge p34-damaged">Central Damaged <?= (int)$i['central_damaged'] ?></span><span class="p34-badge p34-neutral">Disposed <?= (int)$i['disposed'] ?></span></div>
            <?php endif; ?>
            <?php if((int)$i['withdrawn']>0): ?><div class="p34-muted">Withdrawn from unissued: <?= (int)$i['withdrawn'] ?></div><?php endif; ?>
          </td>
        <?php endif; ?>
        <td><button type="button" class="p34-view" aria-expanded="false" aria-controls="details-<?= e($rowKey) ?>">View details ▾</button></td>
      </tr>
      <tr class="p34-details" id="details-<?= e($rowKey) ?>" hidden>
        <td colspan="<?= $colspan ?>">
          <section class="p34-panel" aria-label="Details for <?= e($i['item_name']) ?>">
            <div class="p34-panel-head"><div><h3><?= e($i['item_name']) ?> — Item Details</h3><span class="p34-sub"><?= e($i['description']??'No description') ?> · <?= e($i['category_label']) ?> · Unit: <?= e($i['unit']) ?></span></div><button class="p34-view p34-close" type="button">Close details ↑</button></div>
            <?php if($issuedView): ?>
              <div class="p34-stat-grid">
                <div class="p34-stat"><span class="p34-muted">Originally Issued</span><strong><?= (int)$i['qty_issued'] ?></strong></div>
                <?php if(!$i['is_supply']): ?>
                  <div class="p34-stat"><span class="p34-muted">On Teacher Record</span><strong><?= (int)$i['net_issued'] ?></strong></div>
                  <div class="p34-stat"><span class="p34-muted">Already Retrieved</span><strong><?= (int)$i['qty_returned'] ?></strong></div>
                  <div class="p34-stat"><span class="p34-muted">Disposed</span><strong><?= (int)$i['disposed_qty'] ?></strong></div>
                  <div class="p34-stat p34-stat-ok"><span class="p34-muted">Serviceable</span><strong><?= (int)$i['serviceable'] ?></strong></div>
                  <div class="p34-stat p34-stat-repair"><span class="p34-muted">For Repair</span><strong><?= (int)$i['repair_qty'] ?></strong></div>
                  <div class="p34-stat p34-stat-damaged"><span class="p34-muted">Damaged</span><strong><?= (int)$i['damaged_qty'] ?></strong></div>
                <?php else: ?><div class="p34-stat"><span class="p34-muted">Supply Distribution</span><strong>Recorded as issued</strong></div><?php endif; ?>
              </div>
              <dl class="p34-dl"><div><dt>ICS No.</dt><dd><?= e($i['ics_number']) ?></dd></div><div><dt>Accountable Teacher</dt><dd><?= e($i['teacher_name']) ?></dd></div><div><dt>Course / Subject</dt><dd><?= e($i['course_name']??'—') ?></dd></div><div><dt>Date Issued</dt><dd><?= e($i['date_issued']??'—') ?></dd></div><div><dt>Item first recorded arrival</dt><dd><?= e($i['first_received']??'—') ?></dd></div><div><dt>Item latest recorded arrival</dt><dd><?= e($i['last_received']??'—') ?></dd></div></dl>
              <?php if(!empty($i['needs_review'])&&!$i['is_supply']): ?><div class="p34-note" role="note">Condition balance requires physical verification. Use the ICS Condition Balance Review screen before relying on this record for a new issue or transfer.</div><?php endif; ?>
            <?php else: ?>
              <div class="p34-stat-grid">
                <div class="p34-stat"><span class="p34-muted">Accepted (All deliveries)</span><strong><?= (int)$i['quantity'] ?></strong></div>
                <div class="p34-stat"><span class="p34-muted">Currently Issued / Distributed</span><strong><?= (int)$i['net_issued'] ?></strong></div>
                <div class="p34-stat"><span class="p34-muted">Withdrawn</span><strong><?= (int)$i['withdrawn'] ?></strong></div>
                <div class="p34-stat"><span class="p34-muted">Disposed</span><strong><?= (int)$i['disposed'] ?></strong></div>
                <div class="p34-stat p34-stat-ok"><span class="p34-muted">Available Unissued</span><strong><?= (int)$i['available'] ?></strong></div>
                <?php if(!$i['is_supply']): ?>
                  <div class="p34-stat p34-stat-ok"><span class="p34-muted">Serviceable with Teachers</span><strong><?= (int)$i['serviceable'] ?></strong></div>
                  <div class="p34-stat p34-stat-repair"><span class="p34-muted">For Repair with Teachers</span><strong><?= (int)$i['repair_qty'] ?></strong></div>
                  <div class="p34-stat p34-stat-repair"><span class="p34-muted">For Repair in Central Custody</span><strong><?= (int)$i['central_repair'] ?></strong></div>
                  <div class="p34-stat p34-stat-damaged"><span class="p34-muted">Damaged with Teachers</span><strong><?= (int)$i['damaged_qty'] ?></strong></div>
                  <div class="p34-stat p34-stat-damaged"><span class="p34-muted">Damaged in Central Custody</span><strong><?= (int)$i['central_damaged'] ?></strong></div>
                <?php else: ?>
                  <div class="p34-stat"><span class="p34-muted">Low-stock threshold</span><strong><?= $i['low_stock_threshold']===null?'Not set':(int)$i['low_stock_threshold'] ?></strong></div>
                  <?php if($i['next_expiry']): ?><div class="p34-stat"><span class="p34-muted">Next dated batch</span><strong><?= e($i['next_expiry']) ?></strong></div><?php endif; ?>
                <?php endif; ?>
              </div>
              <dl class="p34-dl"><div><dt>First recorded arrival</dt><dd><?= e($i['first_received']??'—') ?></dd></div><div><dt>Latest recorded arrival</dt><dd><?= e($i['last_received']??'—') ?></dd></div><div><dt>Recorded delivery batches</dt><dd><?= (int)$i['batch_count'] ?></dd></div><?php if(!empty($i['classification_name'])): ?><div><dt>Classification</dt><dd><?= e($i['classification_name']) ?></dd></div><?php endif; ?></dl>
              <div class="p34-panel-footer"><a class="p34-link" href="<?= e(url('inventory_batches.php?item_id='.(int)$i['item_id'])) ?>">View delivery batches &amp; arrival history →</a></div>
            <?php endif; ?>
            <?php if(!empty($i['first_received'])||!empty($i['last_received'])): ?><div class="p34-note">Arrival dates identify deliveries linked to this inventory item, not necessarily the exact batch issued to an individual teacher.</div><?php endif; ?>
          </section>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$items): ?><tr><td colspan="<?= $colspan ?>" class="p34-empty"><?= $dateFilterError?'Correct the date range and try again.':'No items matched your filters.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  'use strict';
  const table=document.getElementById('p34InventoryTable');
  if(!table) return;
  const tbody=table.tBodies[0];
  const rows=Array.from(tbody.querySelectorAll(':scope > tr.p34-record'));
  const original=rows.map((row,index)=>({row,detail:row.nextElementSibling,index}));
  const buttons=Array.from(table.querySelectorAll('.p34-sort'));
  const num=v=>{const n=Number(v);return Number.isFinite(n)?n:0;};
  function closeRow(row) {
    const detail=row.nextElementSibling;
    const button=row.querySelector('.p34-view[aria-expanded]');
    if(!detail||!button)return;
    detail.hidden=true;row.classList.remove('p34-open');button.setAttribute('aria-expanded','false');button.textContent='View details ▾';
  }
  function toggleRow(row,forceClose=false) {
    const detail=row.nextElementSibling;
    const button=row.querySelector('.p34-view[aria-expanded]');
    if(!detail||!button)return;
    const open=!forceClose&&detail.hidden;
    // Keep one detail row open at a time, reducing scrolling and accidental edits.
    rows.forEach(other=>{if(other!==row)closeRow(other);});
    detail.hidden=!open;row.classList.toggle('p34-open',open);
    button.setAttribute('aria-expanded',String(open));button.textContent=open?'Hide details ▴':'View details ▾';
  }
  rows.forEach(row=>{
    row.querySelector('.p34-view[aria-expanded]')?.addEventListener('click',()=>toggleRow(row));
    row.addEventListener('click',ev=>{if(ev.target.closest('button,a,input,select,textarea'))return;toggleRow(row);});
    row.nextElementSibling?.querySelector('.p34-close')?.addEventListener('click',()=>{
      closeRow(row);row.querySelector('.p34-view[aria-expanded]')?.focus();
    });
  });
  buttons.forEach(button=>button.addEventListener('click',()=>{
    const key=button.dataset.key;
    const type=button.dataset.type;
    const next=button.dataset.direction==='none'?'asc':button.dataset.direction==='asc'?'desc':'none';
    buttons.forEach(b=>{
      b.dataset.direction='none';b.querySelector('.p34-indicator').textContent='-';b.parentElement.removeAttribute('aria-sort');
      b.setAttribute('aria-label','Sort '+b.textContent.trim()+' ascending');
    });
    const sorted=original.slice();
    if(next!=='none'){
      const sign=next==='asc'?1:-1;
      sorted.sort((a,b)=>{
        const va=a.row.dataset[key]||'',vb=b.row.dataset[key]||'';
        if(va===''||vb===''){if(va===vb)return a.index-b.index;return va===''?1:-1;}
        const compared=type==='number'?num(va)-num(vb):va.localeCompare(vb,undefined,{numeric:type!=='date',sensitivity:'base'});
        return sign*compared||a.index-b.index;
      });
      button.parentElement.setAttribute('aria-sort',next==='asc'?'ascending':'descending');
    }
    const fragment=document.createDocumentFragment();
    sorted.forEach(({row,detail})=>{fragment.appendChild(row);if(detail)fragment.appendChild(detail);});
    tbody.appendChild(fragment);
    button.dataset.direction=next;
    button.querySelector('.p34-indicator').textContent=next==='asc'?'↑':next==='desc'?'↓':'-';
    button.setAttribute('aria-label',next==='asc'?'Sorted ascending; click for descending':next==='desc'?'Sorted descending; click for default':'Sort ascending');
  }));
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
