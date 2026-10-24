<?php
/** Optional source declaration. ICS identity is required; batch knowledge is not. */
function p72_batch_map(PDO $pdo,array $ids): array {
 $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));if(!$ids)return [];
 $q=implode(',',array_fill(0,count($ids),'?'));
 $st=$pdo->prepare("SELECT a.ics_item_id,a.allocation_id,a.batch_id,a.qty_issued,a.qty_returned,a.qty_transferred_out,b.batch_number,b.expiration_date,d.receiving_number,d.date_received
    FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id
    JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id
    WHERE a.ics_item_id IN ($q) ORDER BY d.date_received DESC,a.allocation_id DESC");
 $st->execute($ids);$map=[];
 while($row=$st->fetch(PDO::FETCH_ASSOC)){
   $row['remaining_recorded']=max(0,(int)$row['qty_issued']-(int)$row['qty_returned']-(int)$row['qty_transferred_out']);
   $row['label']=($row['receiving_number']?:'Receiving unknown').' · '.($row['batch_number']?:'Batch #'.$row['batch_id']).' · '.($row['date_received']?:'Date unknown');
   $map[(int)$row['ics_item_id']][]=$row;
 }
 return $map;
}
function p72_validate_batch(PDO $pdo,int $icsItemId,int $allocationId,int $qty): ?int {
 if($allocationId===0)return null;
 if($allocationId<0)throw new RuntimeException('Invalid source batch selection.');
 $st=$pdo->prepare('SELECT qty_issued,qty_returned,qty_transferred_out FROM ics_batch_allocations WHERE allocation_id=? AND ics_item_id=? FOR UPDATE');
 $st->execute([$allocationId,$icsItemId]);$row=$st->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('The selected batch does not belong to this ICS item.');
 $remain=max(0,(int)$row['qty_issued']-(int)$row['qty_returned']-(int)$row['qty_transferred_out']);
 if($qty>$remain)throw new RuntimeException('That source batch does not have enough currently unreturned units. Choose another batch or Unknown / not sure.');
 // This declares possible physical origin; it does NOT move or adjust batch quantities.
 return $allocationId;
}
