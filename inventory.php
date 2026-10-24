<?php
require_once __DIR__.'/includes/db.php'; require_once __DIR__.'/includes/auth.php'; require_once __DIR__.'/includes/functions.php'; require_once __DIR__.'/includes/batch_accounting.php';
$user=require_login();$role=$user['role'];$uid=(int)$user['id'];
$isAdmin=in_array($role,['supply_officer','tvl_head'],true);
$catLabels=['equipment'=>'Equipment','non_consumable'=>'Non-Consumable','consumable'=>'Consumable','expiring_material'=>'Expiring Material'];
$category=trim((string)($_GET['category']??''));if(!isset($catLabels[$category]))$category='';
$search=trim((string)($_GET['search']??''));$view=($_GET['view']??'central')==='issued'?'issued':'central';
$po=trim((string)($_GET['po_number']??''));$dr=trim((string)($_GET['dr_number']??''));$supplier=trim((string)($_GET['supplier_name']??''));
$course=(int)($_GET['course_id']??0);$teacher=(int)($_GET['teacher_id']??0);
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
// Classify an individual ICS item without changing its document or accountability.
$courseMessage = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_item_course') {
  csrf_check();
  $lineId=filter_var($_POST['ics_item_id']??null,FILTER_VALIDATE_INT);
  $courseRaw=(string)($_POST['course_id']??'');
  $newCourse=$courseRaw===''?null:filter_var($courseRaw,FILTER_VALIDATE_INT);
  if (!$lineId || ($courseRaw!=='' && !$newCourse)) { $courseMessage='Invalid ICS item or course.'; }
  elseif (!in_array($role,['teacher','supply_officer'],true)) { http_response_code(403); $courseMessage='You cannot modify item classifications.'; }
  else {
    try {
      db()->beginTransaction();
      $st=db()->prepare('SELECT ii.course_id,c.teacher_id,c.ics_number FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.ics_item_id=? AND c.status=\'active\' FOR UPDATE');
      $st->execute([$lineId]);$record=$st->fetch(PDO::FETCH_ASSOC);
      if (!$record || ($role==='teacher' && (int)$record['teacher_id']!==$uid)) throw new RuntimeException('Active ICS item not found or access denied.');
      if ($newCourse!==null) {
        $check=db()->prepare('SELECT 1 FROM courses WHERE course_id=?');$check->execute([$newCourse]);
        if (!$check->fetchColumn()) throw new RuntimeException('Selected course does not exist.');
      }
      $oldCourse=$record['course_id']===null?null:(int)$record['course_id'];
      if ($oldCourse!==$newCourse) {
        $update=db()->prepare('UPDATE ics_items SET course_id=? WHERE ics_item_id=?');$update->execute([$newCourse,$lineId]);
        $log=db()->prepare('INSERT INTO history_logs (user_id,action,module,record_id,details,created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $log->execute([$uid,'update_item_course','ics_items',$lineId,'ICS '.$record['ics_number'].' item '.$lineId.': course '.($oldCourse??'none').' -> '.($newCourse??'none')]);
      }
      db()->commit();
      header('Location: '.url('inventory.php?view=issued&course_updated=1'));exit;
    } catch (Throwable $ex) { if(db()->inTransaction())db()->rollBack();$courseMessage=$ex->getMessage(); }
  }
}
$items=[];$params=[];$where=[];
if($role==='teacher'||$view==='issued'){
  $sql="SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,ii.qty_transferred_out,i.item_id,i.item_name,i.description,i.unit,i.item_category,i.low_stock_threshold,c.ics_number,c.date_issued,rd.first_received,rd.last_received,co.course_name,ii.course_id AS item_course_id,c.teacher_id, c.recipient_position_office_snapshot AS position_office,u.full_name AS teacher_name,
    COALESCE(b.qty_for_repair,0) repair_qty, COALESCE(b.qty_serviceable,0) serviceable_balance, COALESCE(b.needs_review,0) needs_review,
    COALESCE(b.qty_damaged,0) damaged_qty,
    COALESCE((SELECT SUM(quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0) disposed_qty
    FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=ii.course_id LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id LEFT JOIN (SELECT b.inventory_item_id, MIN(d.date_received) AS first_received, MAX(d.date_received) AS last_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id GROUP BY b.inventory_item_id) rd ON rd.inventory_item_id=i.item_id WHERE c.status='active' AND (i.item_category IN ('consumable','expiring_material') OR ii.qty_issued>ii.qty_returned+ii.qty_transferred_out+COALESCE((SELECT SUM(x.quantity) FROM condemnations x WHERE x.ics_item_id=ii.ics_item_id AND x.status='approved' AND x.outcome='disposed'),0))";
  if($role==='teacher'){$sql.=' AND c.teacher_id=?';$params[]=$uid;}
  elseif($teacher){$sql.=' AND c.teacher_id=?';$params[]=$teacher;}
  if($course){$sql.=' AND ii.course_id=?';$params[]=$course;}
  if($category){$sql.=' AND i.item_category=?';$params[]=$category;}
  if($search!==''){$sql.=' AND (i.item_name LIKE ? OR c.ics_number LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%');}
  // In the teacher/ICS view, date filters refer to the ICS issuance date.
  if ($dateFrom!=='') {$sql.=' AND c.date_issued>=?';$params[]=$dateFrom;}
  if ($dateTo!=='') {$sql.=' AND c.date_issued<=?';$params[]=$dateTo;}
  $sql.=' ORDER BY c.date_issued DESC,ii.ics_item_id DESC';
} else {
  $sql="SELECT i.*,rd.first_received,rd.last_received,
  COALESCE((SELECT GROUP_CONCAT(DISTINCT crs.course_name ORDER BY crs.course_name SEPARATOR ', ') FROM inventory_receipt_batches rb LEFT JOIN courses crs ON crs.course_id=rb.course_id WHERE rb.inventory_item_id=i.item_id), 'Unclassified') AS course_name,
  (SELECT COUNT(*) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id) batch_count,
  (SELECT MIN(b.expiration_date) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id AND b.expiration_date IS NOT NULL AND b.expiration_date>=CURDATE()) next_expiry,
  (SELECT COUNT(*) FROM inventory_receipt_batches b WHERE b.inventory_item_id=i.item_id AND b.expiration_date IS NOT NULL AND b.expiration_date<CURDATE()) expired_batches,
  COALESCE((SELECT SUM(ii.qty_issued) FROM ics_items ii WHERE ii.item_id=i.item_id AND ii.transfer_origin_request_id IS NULL),0) gross_issued,
  COALESCE((SELECT SUM(ii.qty_issued-ii.qty_returned) FROM ics_items ii WHERE ii.item_id=i.item_id AND ii.transfer_origin_request_id IS NULL),0) active_balance,
  COALESCE((SELECT SUM(x.quantity) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE ii.item_id=i.item_id AND c.status='closed' AND x.status='approved' AND x.outcome='disposed'),0) closed_disposals,
  COALESCE((SELECT SUM(x.quantity) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id WHERE ii.item_id=i.item_id AND x.status='approved' AND x.outcome='disposed'),0) disposed,
  COALESCE((SELECT SUM(b.qty_for_repair) FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.item_id=i.item_id),0) repair_qty,
  COALESCE((SELECT SUM(b.qty_damaged) FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.item_id=i.item_id),0) damaged_qty,
  COALESCE((SELECT SUM(rl.qty_for_repair+rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) returned_unserviceable,
  COALESCE((SELECT SUM(rl.qty_for_repair) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) central_repair,
  COALESCE((SELECT SUM(rl.qty_damaged) FROM ics_return_lines rl JOIN ics_items ii ON ii.ics_item_id=rl.ics_item_id WHERE ii.item_id=i.item_id),0) central_damaged,
  COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='for_repair'),0) restored_repair,
  COALESCE((SELECT SUM(a.quantity) FROM central_condition_restorations a WHERE a.item_id=i.item_id AND a.from_condition='damaged'),0) restored_damaged,
  COALESCE((SELECT SUM(z.quantity) FROM central_condition_disposals z WHERE z.item_id=i.item_id),0) central_disposed,
  COALESCE((SELECT SUM(w.quantity) FROM inventory_withdrawals w WHERE w.item_id=i.item_id),0) withdrawn
  FROM inventory_items i LEFT JOIN (SELECT b.inventory_item_id, MIN(d.date_received) AS first_received, MAX(d.date_received) AS last_received FROM inventory_receipt_batches b JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id GROUP BY b.inventory_item_id) rd ON rd.inventory_item_id=i.item_id WHERE 1=1";
  if($category){$sql.=' AND i.item_category=?';$params[]=$category;}
  if($course){$sql.=' AND EXISTS (SELECT 1 FROM inventory_receipt_batches rb WHERE rb.inventory_item_id=i.item_id AND rb.course_id=?)';$params[]=$course;}
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
  $i['central_repair']=max(0,(int)$i['central_repair']-(int)$i['restored_repair']);
  $i['central_damaged']=max(0,(int)$i['central_damaged']-(int)$i['restored_damaged']-(int)$i['central_disposed']);
  $i['returned_unserviceable']=$i['central_repair']+$i['central_damaged'];
  $i['available']=max(0,(int)$i['quantity']-(int)$i['withdrawn']-($i['is_supply']?(int)$i['gross_issued']:((int)$i['active_balance']))-(int)$i['returned_unserviceable']-(int)$i['central_disposed']);
  // Available central stock is restricted to identifiable, unexpired receiving batches.
  // Existing unallocated ICS and withdrawals remain conservatively reserved.
  $batchState=tnts_batch_issueable(db(),(int)$i['item_id'],(int)$i['available'],$i['is_supply']);
  $i['batch_usable']=array_sum(array_column($batchState['batches'],'available'));
  $i['available']=min((int)$i['available'],(int)$i['batch_usable']);
  $i['expired_held_qty']=array_sum(array_map(static fn($b)=>$b['expired']?max(0,$b['remaining_raw']-$b['legacy_reserved']):0,$batchState['batches']));
  $i['serviceable']=max(0,(int)$i['net_issued']-(int)$i['repair_qty']-(int)$i['damaged_qty']);
  $i['disposed']+=(int)$i['central_disposed'];
  $i['low_stock']=$i['low_stock_threshold']!==null && $i['available']<=(int)$i['low_stock_threshold'];
 }else{
  $i['net_issued']=$i['is_supply']?(int)$i['qty_issued']:max(0,(int)$i['qty_issued']-(int)$i['qty_returned']-(int)$i['qty_transferred_out']-(int)$i['disposed_qty']);
  $i['serviceable']=$i['is_supply']?0:(int)$i['serviceable_balance'];
 }
}unset($i);
// Phase 4.7.3 revised: expiry totals for recorded, outstanding ICS allocations.
// This is status visibility only: never mark these quantities as formally disposed.
if ($role==='teacher' || $view==='issued') {
    $allocationTotals=[];
    if ($items) {
        $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['ics_item_id'],$items)));
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $expirySql="SELECT a.ics_item_id,
          SUM(GREATEST(0,a.qty_issued-a.qty_returned-a.qty_transferred_out)) AS allocated_held,
          SUM(CASE WHEN b.expiration_date < CURDATE() THEN GREATEST(0,a.qty_issued-a.qty_returned-a.qty_transferred_out) ELSE 0 END) AS qty_expired,
          SUM(CASE WHEN b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN GREATEST(0,a.qty_issued-a.qty_returned-a.qty_transferred_out) ELSE 0 END) AS qty_soon,
          SUM(CASE WHEN b.expiration_date IS NULL OR b.expiration_date >= CURDATE() THEN GREATEST(0,a.qty_issued-a.qty_returned-a.qty_transferred_out) ELSE 0 END) AS qty_not_expired,
          SUM(CASE WHEN b.expiration_date IS NULL THEN GREATEST(0,a.qty_issued-a.qty_returned-a.qty_transferred_out) ELSE 0 END) AS qty_no_expiry
          FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id
          WHERE a.ics_item_id IN ($placeholders) GROUP BY a.ics_item_id";
        $stExpiry=db()->prepare($expirySql);$stExpiry->execute($ids);
        foreach($stExpiry->fetchAll(PDO::FETCH_ASSOC) as $total){$allocationTotals[(int)$total['ics_item_id']]=$total;}
    }
    // Live borrowed quantities only. Completed/returned loans do not count.
    $borrowedByIcs=[];
    if (!empty($ids)) {
        $stBorrowed=db()->prepare("SELECT ics_item_id, COALESCE(SUM(quantity),0) AS borrowed_qty
            FROM borrow_logs WHERE status='borrowed' AND ics_item_id IN ($placeholders)
            GROUP BY ics_item_id");
        $stBorrowed->execute($ids);
        foreach($stBorrowed->fetchAll(PDO::FETCH_ASSOC) as $loan){
            $borrowedByIcs[(int)$loan['ics_item_id']]=(int)$loan['borrowed_qty'];
        }
    }
    foreach($items as &$icsRow){
        $v=$allocationTotals[(int)$icsRow['ics_item_id']]??[];
        $icsRow['borrowed_qty']=(int)($borrowedByIcs[(int)$icsRow['ics_item_id']]??0);
        $icsRow['expiry_expired']=(int)($v['qty_expired']??0);
        $icsRow['expiry_soon']=(int)($v['qty_soon']??0);
        $icsRow['expiry_not_expired']=(int)($v['qty_not_expired']??0);
        $icsRow['expiry_unknown']=(int)($v['qty_no_expiry']??0);
        $icsRow['expiry_unattributed']=max(0,(int)$icsRow['qty_issued']-(int)$icsRow['qty_returned']-(int)$icsRow['qty_transferred_out']-(int)($v['allocated_held']??0));
    } unset($icsRow);
}
$teachers=[];$courses=[];
if($isAdmin){$teachers=db()->query("SELECT user_id,full_name FROM users WHERE role='teacher' ORDER BY full_name")->fetchAll();$courses=db()->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll();}
if (!$isAdmin) $courses=db()->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll();
$pageTitle='Inventory Management';$active='inventory';require __DIR__.'/includes/header.php';
?>
<?php if($courseMessage!==''): ?><div class="p34-validation" role="alert"><?= e($courseMessage) ?></div><?php endif; ?>
<?php if(isset($_GET['course_updated'])): ?><div class="card" role="status">Course classification updated; ICS accountability unchanged.</div><?php endif; ?>
<style>
.p412-classify{margin:16px 0;padding:14px;border:1px solid #e2e7f0;border-radius:9px;display:flex;flex-wrap:wrap;gap:12px;align-items:end}.p412-classify label{display:grid;gap:5px;flex:1;min-width:220px}.p412-classify select{padding:9px;max-width:100%}.p412-classify small{width:100%;color:#667085}
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
/* Phase 6: Borrowed uses a purple status without altering serviceable counts. */
.p34-panel .p34-stat.p34-stat-borrowed{background:#f1ebfc;border-color:#d4c2f0;color:#63358e}
.p34-panel .p34-stat.p34-stat-borrowed .p34-muted,.p34-panel .p34-stat.p34-stat-borrowed strong{color:#63358e}
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
/* Phase 3.8.10 — default-zoom-first inventory summary and controls. */
.p3810-inventory{min-width:0!important;overflow-x:auto;padding:18px!important}
.p3810-inventory .p34-table{min-width:0;table-layout:fixed;font-size:clamp(11px,.86vw,13px)}
.p3810-inventory .p34-table th,.p3810-inventory .p34-table td{padding:11px 9px;white-space:normal;overflow-wrap:anywhere}
.p3810-inventory .p34-table thead th:last-child,.p3810-inventory .p34-table .p34-record>td:last-child{width:120px}
.p3810-inventory .p34-table th:first-child{width:18%}
.p3810-inventory .p34-table th:nth-child(2){width:15%}
.p3810-inventory .p34-table th:nth-child(3){width:17%}
.p3810-inventory .p34-table .p34-badge{max-width:100%;white-space:normal;text-align:center;padding:4px 7px;font-size:10px}
.p3810-inventory .p34-table .p34-details>td{padding:0 10px 13px!important}
.p3810-inventory .p34-table .p34-details .p34-panel{min-width:0;overflow-wrap:anywhere}
.p3810-condition-cell .p34-pills{margin-bottom:5px}
.p3810-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.p3810-toolbar-title{display:grid;gap:2px;color:#28344a}
.p3810-toolbar-controls{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap}
.p3810-toolbar-controls label{font-size:11px;font-weight:700;color:#657187}
.p3810-toolbar-controls select{border:1px solid #d6dce6;background:white;color:#334155;border-radius:8px;padding:8px 9px;max-width:185px;min-width:0;font-size:12px}
.p3810-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 0;flex-wrap:wrap}
.p3810-pager-buttons{display:flex;gap:7px}
.p3810-pager-buttons button:disabled{opacity:.43;cursor:not-allowed}
@media(max-width:1050px){.p3810-inventory .p34-table{min-width:640px}.p3810-inventory .p34-table th,.p3810-inventory .p34-table td{padding:10px 6px}.p3810-toolbar-controls{justify-content:flex-start}}
@media(max-width:720px){.p3810-inventory .p34-table{min-width:690px}.p3810-inventory{padding:12px!important}.p3810-toolbar{align-items:start}.p3810-toolbar-controls{width:100%;justify-content:start}}
</style>
<?php $issuedView=($role==='teacher'||$view==='issued'); $colspan=$issuedView?8:7; ?>
<div class="content-header p34-header">
  <div><h2>Inventory Management</h2><p class="p34-sub">Category-specific stock and accountability overview</p></div>
  <?php if($role==='supply_officer'): ?><div class="p34-actions"><a class="legacy-028" href="<?= e(url('inventory_categories.php')) ?>">Manage Batches &amp; Expiry</a><a class="legacy-028" href="<?= e(url('central_stock_conditions.php')) ?>">Manage central conditions</a><a class="legacy-028" href="<?= e(url('inventory_withdrawals.php')) ?>">Withdraw / disposition</a></div><?php endif; ?>
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
    <?php if($isAdmin&&$issuedView): ?>
      <div class="p34-field"><label for="p34-teacher">Teacher</label><select class="legacy-047" id="p34-teacher" name="teacher_id"><option value="">All teachers</option><?php foreach($teachers as $t): ?><option value="<?= (int)$t['user_id'] ?>" <?= $teacher===(int)$t['user_id']?'selected':'' ?>><?= e($t['full_name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
    <div class="p34-field"><label for="p34-course">Course classification</label><select class="legacy-047" id="p34-course" name="course_id"><option value="">All courses</option><?php foreach($courses as $crs): ?><option value="<?= (int)$crs['course_id'] ?>" <?= $course===(int)$crs['course_id']?'selected':'' ?>><?= e($crs['course_name']) ?></option><?php endforeach; ?></select></div>
    <div class="p34-field"><label for="p34-from"><?= $issuedView?'Issued from':'Delivered from' ?></label><input class="legacy-047" type="date" id="p34-from" name="date_from" value="<?= e($dateFrom) ?>"></div>
    <div class="p34-field"><label for="p34-to"><?= $issuedView?'Issued through':'Delivered through' ?></label><input class="legacy-047" type="date" id="p34-to" name="date_to" value="<?= e($dateTo) ?>"></div>
    <div class="p34-filter-actions"><button class="legacy-028" type="submit">Apply filters</button><a class="p34-view" href="<?= e(url('inventory.php?view='.$view)) ?>">Reset</a></div>
  </form>
  <p class="p34-filter-note">
    <?php if($issuedView): ?>Date range filters by <strong>Date Issued</strong> on an active ICS.
    <?php else: ?>Date range finds items linked to <strong>at least one delivery received within the selected dates</strong>. Stock totals remain all-time totals, not just quantities from the selected deliveries.<?php endif; ?>
  </p>
</div>
<div class="card p34-wrap p3810-inventory" style="margin-top:16px">
  <div class="p3810-toolbar" aria-label="Inventory table controls">
    <div class="p3810-toolbar-title"><strong>Inventory records</strong><span class="p34-muted" id="p3810-result-count" aria-live="polite"></span></div>
    <div class="p3810-toolbar-controls">
      <label for="p3810-alert-filter">Stock / condition</label>
      <select id="p3810-alert-filter" aria-label="Filter by condition or stock alert">
        <option value="">All conditions</option>
        <option value="expired">Expired batches</option>
        <option value="damaged">Damaged</option>
        <option value="repair">For repair</option>
        <option value="low">Low stock</option>
        <option value="soon">Expiring soon</option>
        <option value="normal">No alerts</option>
      </select>
      <label for="p3810-sort-select">Quick sort</label>
      <select id="p3810-sort-select" aria-label="Quick inventory sort">
        <option value="default">Default order</option>
        <option value="alert-desc">Most urgent alerts</option>
        <option value="issued-desc">Issued: highest first</option>
        <option value="issued-asc">Issued: lowest first</option>
        <option value="qty-desc">Quantity: highest first</option>
        <option value="item-asc">Item: A to Z</option>
      </select>
      <label for="p3810-page-size">Show</label>
      <select id="p3810-page-size" aria-label="Rows per page"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="all">All</option></select>
    </div>
  </div>
  <table class="p34-table" id="p34InventoryTable">
    <thead><tr>
      <th><button type="button" class="p34-sort" data-key="item" data-type="text" data-direction="none">Item <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <th><button type="button" class="p34-sort" data-key="category" data-type="text" data-direction="none">Tracking category <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <th><button type="button" class="p34-sort" data-key="course" data-type="text" data-direction="none">Course <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php if($issuedView): ?>
        <th><button type="button" class="p34-sort" data-key="ics" data-type="text" data-direction="none">ICS / Teacher <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="date" data-type="date" data-direction="none">Date Issued <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="accepted" data-type="number" data-direction="none">Original ICS <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="qty" data-type="number" data-direction="none">Quantity / Condition <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php else: ?>
        <th><button type="button" class="p34-sort" data-key="accepted" data-type="number" data-direction="none">Accepted <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="qty" data-type="number" data-direction="none">Unissued <span class="p34-indicator" aria-hidden="true">-</span></button></th>
        <th><button type="button" class="p34-sort" data-key="condition" data-type="number" data-direction="none" title="Sort by alert severity. Use Quick sort for issued quantity.">Condition / Stock Alerts <span class="p34-indicator" aria-hidden="true">-</span></button></th>
      <?php endif; ?>
      <th>Details</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $i):
      $rowKey=$issuedView?'ics-'.$i['ics_item_id']:'stock-'.$i['item_id'];
      // Presentation-only alert ranking, preserving the established stock calculations.
      $alertFlags=[];
      $soonLimit=(new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
      if($issuedView){
        if((int)($i['expiry_expired']??0)>0) $alertFlags[]='expired';
        if((int)$i['damaged_qty']>0) $alertFlags[]='damaged';
        if((int)$i['repair_qty']>0) $alertFlags[]='repair';
        if((int)($i['expiry_soon']??0)>0) $alertFlags[]='soon';
        if(!empty($i['needs_review'])) $alertFlags[]='repair';
      }else{
        if((int)$i['expired_batches']>0) $alertFlags[]='expired';
        if((int)$i['damaged_qty']+(int)$i['central_damaged']>0) $alertFlags[]='damaged';
        if((int)$i['repair_qty']+(int)$i['central_repair']>0) $alertFlags[]='repair';
        if($i['low_stock']) $alertFlags[]='low';
        if(!empty($i['next_expiry']) && $i['next_expiry'] <= $soonLimit) $alertFlags[]='soon';
      }
      $alertFlags=array_values(array_unique($alertFlags));
      if(!$alertFlags) $alertFlags[]='normal';
      $severityMap=['expired'=>5,'damaged'=>4,'low'=>3,'repair'=>2,'soon'=>1,'normal'=>0];
      $sortCondition=max(array_map(static fn($f)=>$severityMap[$f]??0,$alertFlags));
    ?>
      <tr class="p34-record" data-item="<?= e($i['item_name']) ?>" data-category="<?= e($i['category_label']) ?>" data-course="<?= e($i['course_name']??'Unclassified') ?>" data-ics="<?= e($issuedView?$i['ics_number'].' '.$i['teacher_name']:'') ?>" data-date="<?= e($issuedView?($i['date_issued']??''):($i['last_received']??'')) ?>" data-accepted="<?= $issuedView?(int)$i['qty_issued']:(int)$i['quantity'] ?>" data-issued="<?= $issuedView?(int)$i['net_issued']:(int)$i['net_issued'] ?>" data-qty="<?= (int)($issuedView?$i['net_issued']:$i['available']) ?>" data-condition="<?= $sortCondition ?>" data-alert-types="<?= e(implode(' ',$alertFlags)) ?>">
        <td><div class="p34-cell-main"><strong class="p34-strong"><?= e($i['item_name']) ?></strong><?php if(trim((string)($i['description']??''))!==''): ?><span class="p34-muted"><?= e($i['description']) ?></span><?php endif; ?></div></td>
        <td><span class="p34-badge"><?= e($i['category_label']) ?></span></td>
        <td><span class="p34-badge p34-neutral" title="Course is an optional internal purpose, not the inventory tracking category."><?= e($i['course_name']??'Unclassified') ?></span></td>
        <?php if($issuedView): ?>
          <td><div class="p34-cell-main"><strong class="p34-strong"><?= e($i['ics_number']) ?></strong><strong class="p34-strong"><?= e($i['teacher_name']) ?></strong><span class="p34-muted"><?= e($i['position_office']??'Office not yet recorded') ?></span></div></td>
          <td><?= e($i['date_issued']??'—') ?></td>
          <td><?= (int)$i['qty_issued'] ?> <?= e($i['unit']) ?></td>
          <td>
            <?php if($i['is_supply']): ?><span class="p34-badge p34-neutral">Distributed: <?= (int)$i['qty_issued'] ?></span>
              <?php if((int)$i['expiry_expired']>0): ?><span class="p473-status-pill p473-pill-expired">Expired <?= (int)$i['expiry_expired'] ?> <?= e($i['unit']) ?></span><?php endif; ?>
              <?php if((int)$i['expiry_soon']>0): ?><span class="p473-status-pill p473-pill-soon">Expiring soon <?= (int)$i['expiry_soon'] ?> <?= e($i['unit']) ?></span><?php endif; ?>
              <?php if((int)$i['expiry_not_expired']>0): ?><span class="p473-status-pill p473-pill-usable">Not expired <?= (int)$i['expiry_not_expired'] ?></span><?php endif; ?>
              <?php if((int)$i['expiry_unattributed']>0): ?><span class="p473-status-pill p473-pill-unknown">Batch unverified <?= (int)$i['expiry_unattributed'] ?></span><?php endif; ?>
            <?php else: ?>
              <div><strong class="p34-strong">On Teacher Record: <?= (int)$i['net_issued'] ?></strong></div>
              <div class="p34-pills"><span class="p34-badge p34-ok">Serviceable <?= (int)$i['serviceable'] ?></span><span class="p34-badge p34-repair">For Repair <?= (int)$i['repair_qty'] ?></span><span class="p34-badge p34-damaged">Damaged <?= (int)$i['damaged_qty'] ?></span></div>
              <div class="p34-muted">Retrieved <?= (int)$i['qty_returned'] ?> · Disposed <?= (int)$i['disposed_qty'] ?></div>
              <?php if(!empty($i['needs_review'])): ?><span class="p34-badge p34-alert">Needs physical verification</span><?php endif; ?>
            <?php endif; ?>
          </td>
        <?php else: ?>
          <td><strong class="p34-strong"><?= (int)$i['quantity'] ?></strong> <span class="p34-muted"><?= e($i['unit']) ?></span></td>
          <td><strong class="p34-strong"><?= (int)$i['available'] ?></strong> <span class="p34-muted"><?= e($i['unit']) ?></span></td>
          <td class="p3810-condition-cell">
            <div class="p34-pills">
              <?php if(in_array('expired',$alertFlags,true)): ?><span class="p34-badge p34-damaged">Expired batch</span><?php endif; ?>
              <?php if(in_array('damaged',$alertFlags,true)): ?><span class="p34-badge p34-damaged">Damaged <?= (int)$i['damaged_qty']+(int)$i['central_damaged'] ?></span><?php endif; ?>
              <?php if(in_array('repair',$alertFlags,true)): ?><span class="p34-badge p34-repair">For repair <?= (int)$i['repair_qty']+(int)$i['central_repair'] ?></span><?php endif; ?>
              <?php if(in_array('low',$alertFlags,true)): ?><span class="p34-badge p34-alert">Low stock</span><?php endif; ?>
              <?php if(in_array('soon',$alertFlags,true)): ?><span class="p34-badge p34-alert">Expiring soon</span><?php endif; ?>
              <?php if($alertFlags===['normal']): ?><span class="p34-badge p34-ok">No alerts</span><?php endif; ?>
            </div>
            <span class="p34-muted">Issued: <?= (int)$i['net_issued'] ?> · Latest: <?= e($i['last_received']??'–') ?></span>
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
                  <div class="p34-stat p34-stat-borrowed"><span class="p34-muted">Borrowed</span><strong><?= (int)($i['borrowed_qty']??0) ?></strong></div>
                <?php else: ?>
                  <div class="p34-stat"><span class="p34-muted">Supply Distribution</span><strong>Recorded as issued</strong></div>
                  <div class="p34-stat"><span class="p34-muted">Expired — still recorded</span><strong><?= (int)$i['expiry_expired'] ?></strong></div>
                  <div class="p34-stat"><span class="p34-muted">Expiring within 30 days</span><strong><?= (int)$i['expiry_soon'] ?></strong></div>
                  <div class="p34-stat p34-stat-ok"><span class="p34-muted">Not expired (known batches)</span><strong><?= (int)$i['expiry_not_expired'] ?></strong></div>
                  <?php if($i['expiry_unattributed']>0): ?><div class="p34-stat"><span class="p34-muted">Unattributed legacy quantity</span><strong><?= (int)$i['expiry_unattributed'] ?></strong></div><?php endif; ?>
                <?php endif; ?>
              </div>
              <dl class="p34-dl"><div><dt>ICS No.</dt><dd><?= e($i['ics_number']) ?></dd></div><div><dt>Accountable Teacher</dt><dd><?= e($i['teacher_name']) ?></dd></div><div><dt>Position / Office</dt><dd><?= e($i['position_office']??'—') ?></dd></div><div><dt>Internal Course (optional)</dt><dd><?= e($i['course_name']??'Unclassified') ?></dd></div><div><dt>Date Issued</dt><dd><?= e($i['date_issued']??'—') ?></dd></div><div><dt>Item first recorded arrival</dt><dd><?= e($i['first_received']??'—') ?></dd></div><div><dt>Item latest recorded arrival</dt><dd><?= e($i['last_received']??'—') ?></dd></div></dl>
              <?php if(in_array($role,['teacher','supply_officer'],true)): ?>
              <form method="post" class="p412-classify" aria-label="Change optional course classification">
                <?= csrf_field() ?><input type="hidden" name="action" value="change_item_course">
                <input type="hidden" name="ics_item_id" value="<?= (int)$i['ics_item_id'] ?>">
                <label>Internal Course Classification (optional)
                  <select name="course_id"><option value="">Unclassified / No course</option>
                    <?php foreach($courses as $c): ?><option value="<?= (int)$c['course_id'] ?>" <?= (int)($i['item_course_id']??0)===(int)$c['course_id']?'selected':'' ?>><?= e($c['course_name']) ?></option><?php endforeach; ?>
                  </select>
                </label>
                <button type="submit" class="p34-view">Save Classification</button>
                <small>Changes only this item's internal course. Does not change the ICS, custody, or quantity.</small>
              </form>
              <?php endif; ?>
              <div class="p34-panel-footer"><a class="p34-link" href="<?= e(url('ics_item_batches.php?ics_item_id='.(int)$i['ics_item_id'])) ?>">View this ICS item's delivery batches &amp; expiry →</a></div>
              <?php if($i['is_supply']): ?><p class="p473-inline-note">Expiry is calculated per outstanding recorded batch, not the entire ICS. Expired items remain recorded and require authorized disposal; unknown/legacy sources are not assumed safe.</p><?php endif; ?>
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
              <dl class="p34-dl"><div><dt>First recorded arrival</dt><dd><?= e($i['first_received']??'—') ?></dd></div><div><dt>Latest recorded arrival</dt><dd><?= e($i['last_received']??'—') ?></dd></div><div><dt>Recorded delivery batches</dt><dd><?= (int)$i['batch_count'] ?></dd></div></dl>
              <p class="p34-note">Available Unissued counts only non-expired batch stock after recorded issuances, returns and legacy reservations. Expired stock is blocked from issuance, not marked disposed.</p><div class="p34-panel-footer"><a class="p34-link" href="<?= e(url('inventory_batches.php?item_id='.(int)$i['item_id'])) ?>">View delivery batches, courses &amp; expiry →</a></div>
            <?php endif; ?>
            <?php if(!empty($i['first_received'])||!empty($i['last_received'])): ?><div class="p34-note">Master arrival dates are delivery-level information. Open the batch details to see known allocations; historical ICS without allocation records remain explicitly unattributed.</div><?php endif; ?>
          </section>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$items): ?><tr><td colspan="<?= $colspan ?>" class="p34-empty"><?= $dateFilterError?'Correct the date range and try again.':'No items matched your filters.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="p3810-pager">
    <span id="p3810-page-label" class="p34-muted" aria-live="polite"></span>
    <div class="p3810-pager-buttons"><button type="button" class="p34-view" id="p3810-prev">← Previous</button><button type="button" class="p34-view" id="p3810-next">Next →</button></div>
  </div>
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
  const alertFilter=document.getElementById('p3810-alert-filter');
  const quickSort=document.getElementById('p3810-sort-select');
  const sizeSelect=document.getElementById('p3810-page-size');
  const countLabel=document.getElementById('p3810-result-count');
  const pageLabel=document.getElementById('p3810-page-label');
  const prev=document.getElementById('p3810-prev');
  const next=document.getElementById('p3810-next');
  let activeSort=null, direction='none', page=1;
  const compare=(a,b,key,type,sign)=>{
    const va=a.row.dataset[key]||'',vb=b.row.dataset[key]||'';
    if(va===''||vb==='')return va===vb?a.index-b.index:va===''?1:-1;
    const diff=type==='number'?num(va)-num(vb):va.localeCompare(vb,undefined,{numeric:type!=='date',sensitivity:'base'});
    return sign*diff||a.index-b.index;
  };
  function resetHeaders(){
    buttons.forEach(b=>{
      b.dataset.direction='none';b.querySelector('.p34-indicator').textContent='–';
      b.closest('th').removeAttribute('aria-sort');
    });
  }
  function render(){
    const filter=alertFilter?.value||'';
    const matching=original.filter(o=>!filter||(o.row.dataset.alertTypes||'').split(' ').includes(filter));
    const sorted=matching.slice();
    if(activeSort&&direction!=='none') sorted.sort((a,b)=>compare(a,b,activeSort.key,activeSort.type,direction==='asc'?1:-1));
    const limit=sizeSelect?.value==='all'?Infinity:Math.max(1,Number(sizeSelect?.value)||10);
    const pages=Math.max(1,Math.ceil(sorted.length/limit));
    page=Math.min(Math.max(1,page),pages);
    const start=Number.isFinite(limit)?(page-1)*limit:0;
    const visible=new Set(sorted.slice(start,start+limit));
    const fragment=document.createDocumentFragment();
    // Keep a stable DOM order and always move detail rows together with their parent.
    sorted.forEach(o=>{
      const show=visible.has(o);
      o.row.hidden=!show;
      if(o.detail){o.detail.hidden=!show||o.row.querySelector('.p34-view[aria-expanded]')?.getAttribute('aria-expanded')!=='true';}
      fragment.appendChild(o.row);
      if(o.detail)fragment.appendChild(o.detail);
    });
    original.filter(o=>!sorted.includes(o)).forEach(o=>{
      o.row.hidden=true;
      if(o.detail)o.detail.hidden=true;
      fragment.appendChild(o.row);
      if(o.detail)fragment.appendChild(o.detail);
    });
    tbody.appendChild(fragment);
    let empty=tbody.querySelector('.p3810-no-matches');
    if(!empty){empty=document.createElement('tr');empty.className='p3810-no-matches';
      const td=document.createElement('td');td.colSpan=table.tHead.rows[0].cells.length;
      td.className='p34-empty';td.textContent='No records match this condition filter.';empty.appendChild(td);tbody.appendChild(empty);
    }
    empty.hidden=matching.length!==0||original.length===0;
    if(countLabel)countLabel.textContent=matching.length+' of '+original.length+' records';
    if(pageLabel)pageLabel.textContent=matching.length?('Showing '+(start+1)+'–'+Math.min(start+limit,matching.length)+' of '+matching.length):'No matching records';
    if(prev)prev.disabled=page<=1;
    if(next)next.disabled=page>=pages;
  }
  buttons.forEach(button=>button.addEventListener('click',()=>{
    const nextDirection=button.dataset.direction==='none'?'asc':button.dataset.direction==='asc'?'desc':'none';
    resetHeaders();
    activeSort=nextDirection==='none'?null:{key:button.dataset.key,type:button.dataset.type};
    direction=nextDirection;
    button.dataset.direction=direction;
    button.querySelector('.p34-indicator').textContent=direction==='asc'?'↑':direction==='desc'?'↓':'–';
    if(direction!=='none')button.closest('th').setAttribute('aria-sort',direction==='asc'?'ascending':'descending');
    if(quickSort)quickSort.value='default';
    page=1;render();
  }));
  alertFilter?.addEventListener('change',()=>{page=1;rows.forEach(closeRow);render();});
  quickSort?.addEventListener('change',()=>{
    resetHeaders();
    const choice=quickSort.value;
    const sorts={'alert-desc':['condition','number','desc'],'issued-desc':['issued','number','desc'],'issued-asc':['issued','number','asc'],'qty-desc':['qty','number','desc'],'item-asc':['item','text','asc']};
    const chosen=sorts[choice];
    activeSort=chosen?{key:chosen[0],type:chosen[1]}:null;
    direction=chosen?chosen[2]:'none';
    page=1;render();
  });
  sizeSelect?.addEventListener('change',()=>{rows.forEach(closeRow);page=1;render();});
  prev?.addEventListener('click',()=>{rows.forEach(closeRow);page=Math.max(1,page-1);render();});
  next?.addEventListener('click',()=>{rows.forEach(closeRow);page+=1;render();});
  render();
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
