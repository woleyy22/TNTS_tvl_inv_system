<?php
/** Read-only Phase 7.1 preflight. Run: php tests/phase7_1_preflight.php */
require_once dirname(__DIR__).'/includes/db.php';
$pdo=db();$bad=0;
function check7(bool $ok,string $message):void {global $bad;echo ($ok?'PASS: ':'FAIL: ').$message.PHP_EOL;if(!$ok)$bad++;}
try {
 $cols=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='condemnations'")->fetchAll(PDO::FETCH_COLUMN);
 foreach(['authorization_note','authorization_on_file','decision_note','authorized_at','disposed_at','disposal_recorded_by','disposal_note'] as $name)check7(in_array($name,$cols,true),'condemnations.'.$name.' migration installed');
 $seq=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='condemnation_number_sequences'")->fetchColumn();
 check7((int)$seq===1,'Permanent CF numbering sequence table installed');
 $records=$pdo->query("SELECT ii.ics_item_id,c.ics_number,c.status, i.item_name,b.qty_serviceable,
       COALESCE((SELECT SUM(l.quantity) FROM borrow_logs l WHERE l.ics_item_id=ii.ics_item_id AND l.status='borrowed'),0) AS borrowed
       FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id
       JOIN inventory_items i ON i.item_id=ii.item_id
       LEFT JOIN ics_condition_balances b ON b.ics_item_id=ii.ics_item_id
       WHERE EXISTS(SELECT 1 FROM borrow_logs l WHERE l.ics_item_id=ii.ics_item_id AND l.status='borrowed')")->fetchAll(PDO::FETCH_ASSOC);
 $count=0;
 foreach($records as $row){
   $badLine=$row['status']!=='active' || (int)$row['borrowed']>(int)$row['qty_serviceable'];
   if($badLine){$count++;echo 'REVIEW: '.$row['ics_number'].' / ICS item #'.$row['ics_item_id'].' ('. $row['item_name'].'): active borrowed '.$row['borrowed'].', serviceable '.(int)$row['qty_serviceable'].', ICS '.$row['status'].PHP_EOL;}
 }
 echo 'INFO: Active borrow/custody lines requiring manual reconciliation = '.$count.PHP_EOL;
 $st=$pdo->query("SELECT COUNT(*) FROM condemnations WHERE status='approved' AND outcome IS NULL");
 echo 'INFO: Approved condemnation requests awaiting physical disposal = '.(int)$st->fetchColumn().PHP_EOL;
 echo 'INFO: This script never changes inventory, approval decisions, or database rows.'.PHP_EOL;
 if($bad){echo 'Migration incomplete. Import database/phase7_1_maintenance_condemnation.sql on a BACKUP database before using the patch.'.PHP_EOL;exit(1);}
 echo 'All Phase 7.1 installation checks passed. REVIEW messages are data-quality warnings and should be resolved before disposition.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: Unable to complete preflight: '.$e->getMessage().PHP_EOL);exit(1);}
