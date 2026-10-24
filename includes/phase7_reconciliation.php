<?php
/**
 * Phase 7.4: read-only consistency review. This module NEVER adjusts quantities.
 * Returns a summary and a list of actionable exceptions for officers and TVL oversight.
 */
function p74_reconcile(PDO $pdo): array {
    $records=$pdo->query("SELECT ii.ics_item_id,ii.ics_id,ii.qty_issued,ii.qty_returned,ii.qty_transferred_out,
       c.ics_number,c.status AS ics_status,u.full_name AS custodian,i.item_name,i.item_category,
       cb.qty_serviceable,cb.qty_for_repair,cb.qty_damaged,cb.needs_review,cb.ics_item_id AS balance_id
       FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id
       JOIN users u ON u.user_id=c.teacher_id JOIN inventory_items i ON i.item_id=ii.item_id
       LEFT JOIN ics_condition_balances cb ON cb.ics_item_id=ii.ics_item_id
       WHERE i.item_category IN ('equipment','non_consumable') ORDER BY c.ics_number,ii.ics_item_id")->fetchAll(PDO::FETCH_ASSOC);
    $aggregates=[];
    $definitions=[
      'borrowed'=>"SELECT ics_item_id,SUM(quantity) amount FROM borrow_logs WHERE status='borrowed' GROUP BY ics_item_id",
      'borrow_reserved'=>"SELECT ics_item_id,SUM(quantity) amount FROM borrow_requests WHERE status IN ('requested','approved') GROUP BY ics_item_id",
      'transfer_reserved'=>"SELECT ics_item_id,SUM(quantity) amount FROM transfer_requests WHERE status IN ('requested','accepted','awaiting_finalization') GROUP BY ics_item_id",
      'pending_cf'=>"SELECT ics_item_id,SUM(quantity) amount FROM condemnations WHERE status='requested' OR (status='approved' AND outcome IS NULL) GROUP BY ics_item_id",
      'disposed'=>"SELECT ics_item_id,SUM(quantity) amount FROM condemnations WHERE status='approved' AND outcome='disposed' GROUP BY ics_item_id",
    ];
    foreach($definitions as $key=>$query){
      $aggregates[$key]=[];
      foreach($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) as $row)$aggregates[$key][(int)$row['ics_item_id']]=(int)$row['amount'];
    }
    $issues=[];
    $add=static function(string $level,string $code,int $line,string $ics,string $item,string $teacher,string $message) use (&$issues):void {
       $issues[]=['level'=>$level,'code'=>$code,'ics_item_id'=>$line,'ics'=>$ics,'item'=>$item,'teacher'=>$teacher,'message'=>$message];
    };
    foreach($records as $r){
      $id=(int)$r['ics_item_id'];$ics=(string)$r['ics_number'];$item=(string)$r['item_name'];$teacher=(string)$r['custodian'];
      $qty=(int)$r['qty_issued']-(int)$r['qty_returned']-(int)$r['qty_transferred_out']-($aggregates['disposed'][$id]??0);
      $borrowed=$aggregates['borrowed'][$id]??0;$reserve=($aggregates['borrow_reserved'][$id]??0)+($aggregates['transfer_reserved'][$id]??0);
      $pending=$aggregates['pending_cf'][$id]??0;
      if($qty<0)$add('blocker','negative_custody',$id,$ics,$item,$teacher,'Retrieved, transferred and disposed quantities exceed the original ICS issuance.');
      if($r['balance_id']===null){
        if($qty>0)$add('blocker','missing_balance',$id,$ics,$item,$teacher,'Active equipment has no condition balance.');
        continue;
      }
      $srv=(int)$r['qty_serviceable'];$rep=(int)$r['qty_for_repair'];$dam=(int)$r['qty_damaged'];
      if($srv+$rep+$dam!==$qty)$add('blocker','condition_total',$id,$ics,$item,$teacher,
        'Condition total '.($srv+$rep+$dam).' does not match current ICS accountability '.$qty.'.');
      if($borrowed>$srv)$add('blocker','borrowed_over_serviceable',$id,$ics,$item,$teacher,
        $borrowed.' borrowed unit(s) exceed '.$srv.' recorded serviceable unit(s).');
      if($borrowed+$reserve>$srv)$add('blocker','reservations_over_serviceable',$id,$ics,$item,$teacher,
        ($borrowed+$reserve).' borrowed/reserved unit(s) exceed '.$srv.' serviceable unit(s).');
      if($pending>$dam)$add('blocker','cf_over_damaged',$id,$ics,$item,$teacher,
        $pending.' units held by outstanding CF requests exceed '.$dam.' damaged units.');
      if((int)$r['needs_review']===1)$add('review','flagged_balance',$id,$ics,$item,$teacher,'ICS condition balance is marked for physical verification.');
      if($r['ics_status']==='closed'&&($borrowed>0||$reserve>0||$pending>0))
        $add('blocker','closed_active_custody',$id,$ics,$item,$teacher,'Closed ICS still has borrowed, reserved or undischarged CF units.');
    }
    // Source-batch attribution is optional, but any known attribution must be consistent.
    $batchRows=$pdo->query("SELECT a.allocation_id,a.ics_item_id,a.qty_issued,a.qty_returned,a.qty_transferred_out,
      COALESCE(SUM(CASE WHEN c.status IN ('requested','approved') THEN c.quantity ELSE 0 END),0) AS cf_claimed
      FROM ics_batch_allocations a LEFT JOIN condemnations c ON c.allocation_id=a.allocation_id
      GROUP BY a.allocation_id,a.ics_item_id,a.qty_issued,a.qty_returned,a.qty_transferred_out")->fetchAll(PDO::FETCH_ASSOC);
    $byId=[];foreach($records as $r)$byId[(int)$r['ics_item_id']]=$r;
    foreach($batchRows as $r){
      $remaining=(int)$r['qty_issued']-(int)$r['qty_returned']-(int)$r['qty_transferred_out'];
      if($remaining>=0 && (int)$r['cf_claimed']<=$remaining)continue;
      $info=$byId[(int)$r['ics_item_id']]??null;
      $add('blocker','batch_overdrawn',(int)$r['ics_item_id'],(string)($info['ics_number']??'Unknown'),
          (string)($info['item_name']??'Unknown'),(string)($info['custodian']??''),
          'Source allocation #'.$r['allocation_id'].' has '.$remaining.' unreturned but '.(int)$r['cf_claimed'].' claimed by CF records.');
    }
    $cfRows=$pdo->query("SELECT co.condemnation_id,co.ics_item_id,co.status,co.outcome,co.authorization_on_file,
       co.authorization_reference,co.authorization_date,co.authorized_at,co.disposed_at,
       co.disposal_recorded_by,co.disposal_date,co.disposal_method,co.disposal_reference,
       co.allocation_id,al.ics_item_id AS batch_owner FROM condemnations co
       LEFT JOIN ics_batch_allocations al ON al.allocation_id=co.allocation_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cfRows as $c){
      $id=(int)$c['ics_item_id'];$info=$byId[$id]??null;
      $addCf=static function(string $severity,string $code,string $message)use($add,$id,$info,$c):void {
        $add($severity,$code,$id,(string)($info['ics_number']??'Unknown'),(string)($info['item_name']??'Unknown'),
          (string)($info['custodian']??''),'CF #'.$c['condemnation_id'].': '.$message);
      };
      if($c['allocation_id']!==null && (int)$c['batch_owner']!==$id)
        $addCf('blocker','cf_batch_wrong_ics','Source batch belongs to another ICS item.');
      if($c['status']==='approved' && (!(int)$c['authorization_on_file'] || !$c['authorization_reference'] || !$c['authorization_date'] || !$c['authorized_at']))
        $addCf('review','authorization_metadata','Signed school authorization evidence/reference/date is incomplete.');
      if($c['outcome']==='disposed' && (!$c['disposed_at']||!$c['disposal_recorded_by']||!$c['disposal_date']||!$c['disposal_method']||!$c['disposal_reference']))
        $addCf('review','disposal_metadata','Physical disposal evidence fields are incomplete (historical record may need review).');
      if($c['outcome']==='disposed' && $c['authorization_date'] && $c['disposal_date'] && $c['disposal_date']<$c['authorization_date'])
        $addCf('blocker','disposal_before_authorization','Recorded disposal date precedes authorization.');
    }
    $summary=['equipment_lines'=>count($records),'cf_records'=>count($cfRows),'issues'=>count($issues),'blockers'=>0,'review'=>0];
    foreach($issues as $r){if($r['level']==='blocker')$summary['blockers']++;else $summary['review']++;}
    return ['summary'=>$summary,'issues'=>$issues];
}
