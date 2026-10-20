<?php
/** Phase 5.1 — request-only eligibility. No ICS quantities are updated here. */
require_once __DIR__.'/ics_accountability.php';
function tnts51_state(PDO $pdo,int $lineId,bool $lock=false): array {
 $sql="SELECT ii.ics_item_id,ii.qty_issued,ii.qty_returned,ii.course_id,i.item_id,i.item_name,i.unit,i.item_category,c.ics_number,c.teacher_id,c.status AS ics_status, u.full_name AS teacher_name, COALESCE((SELECT SUM(b.quantity) FROM borrow_logs b WHERE b.ics_item_id=ii.ics_item_id AND b.status='borrowed'),0) AS borrowed_qty,COALESCE((SELECT SUM(t.quantity) FROM transfer_requests t WHERE t.ics_item_id=ii.ics_item_id AND t.status IN ('requested','accepted','awaiting_finalization')),0) AS reserved_qty FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN users u ON u.user_id=c.teacher_id JOIN inventory_items i ON i.item_id=ii.item_id WHERE ii.ics_item_id=?".($lock?' FOR UPDATE':'');
 $st=$pdo->prepare($sql);$st->execute([$lineId]);$r=$st->fetch(PDO::FETCH_ASSOC);
 if(!$r) throw new InvalidArgumentException('ICS line does not exist.');
 $r['state']=ics31_state($pdo,$lineId,$lock);
 $r['is_supply']=in_array($r['item_category'],['consumable','expiring_material'],true);
 $r['custody_remaining']=(int)$r['state']['remaining_qty'];
 $r['reserved_qty']=(int)$r['reserved_qty'];
 $r['borrowed_qty']=(int)$r['borrowed_qty'];
 $r['eligible_max']=max(0,($r['is_supply']?$r['custody_remaining']:(int)$r['state']['qty_serviceable'])-$r['borrowed_qty']-$r['reserved_qty']);
 if($r['state']['needs_review']) $r['eligible_max']=0;
 return $r;
}
function tnts51_batches(PDO $pdo,int $lineId): array {
 $st=$pdo->prepare("SELECT a.allocation_id,a.batch_id,a.qty_issued,a.qty_returned,a.qty_transferred_out,b.batch_number,b.expiration_date,b.course_id,d.receiving_number,co.course_name,COALESCE((SELECT SUM(r.quantity) FROM transfer_requests r WHERE r.ics_item_id=a.ics_item_id AND r.allocation_id=a.allocation_id AND r.status IN ('requested','accepted','awaiting_finalization')),0) AS reserved FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id LEFT JOIN courses co ON co.course_id=b.course_id WHERE a.ics_item_id=? ORDER BY b.expiration_date,b.batch_id");
 $st->execute([$lineId]);return $st->fetchAll(PDO::FETCH_ASSOC);
}
