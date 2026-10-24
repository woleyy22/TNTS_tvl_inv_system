<?php
/** READ-ONLY: php tests/phase7_3_preflight.php */
require_once dirname(__DIR__).'/includes/db.php';
$pdo=db();$errors=0;
function p73_check(bool $passed,string $message):void{
    global $errors;
    echo ($passed?'PASS: ':'FAIL: ').$message.PHP_EOL;
    if(!$passed)$errors++;
}
try {
    $cols=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='condemnations'")->fetchAll(PDO::FETCH_COLUMN);
    foreach(['authorization_reference','authorization_date','disposal_date','disposal_method','disposal_reference','disposal_document'] as $col){
        p73_check(in_array($col,$cols,true),'condemnations.'.$col.' migration installed');
    }
    if($errors){echo 'Import the Phase 7.3 SQL migration ONCE, after the earlier migrations.'.PHP_EOL;exit(1);}
    $bad=$pdo->query("SELECT COUNT(*) FROM condemnations c JOIN ics_items ii ON ii.ics_item_id=c.ics_item_id LEFT JOIN ics_batch_allocations al ON al.allocation_id=c.allocation_id AND al.ics_item_id=ii.ics_item_id WHERE c.allocation_id IS NOT NULL AND al.allocation_id IS NULL")->fetchColumn();
    p73_check((int)$bad===0,'Every declared CF batch belongs to its ICS item');
    $bad=$pdo->query("SELECT COUNT(*) FROM (SELECT c.allocation_id,SUM(c.quantity) AS claim,MAX(al.qty_issued-al.qty_returned-al.qty_transferred_out) AS unreturned FROM condemnations c JOIN ics_batch_allocations al ON al.allocation_id=c.allocation_id WHERE c.status IN ('requested','approved') AND c.allocation_id IS NOT NULL GROUP BY c.allocation_id HAVING claim>unreturned) q")->fetchColumn();
    p73_check((int)$bad===0,'No source batch is overclaimed by CF declarations');
    $bad=$pdo->query("SELECT COUNT(*) FROM (SELECT cb.ics_item_id,cb.qty_damaged AS damaged,COALESCE(SUM(c.quantity),0) AS held FROM ics_condition_balances cb LEFT JOIN condemnations c ON c.ics_item_id=cb.ics_item_id AND (c.status='requested' OR (c.status='approved' AND c.outcome IS NULL)) GROUP BY cb.ics_item_id HAVING held>damaged) q")->fetchColumn();
    p73_check((int)$bad===0,'No pending CF quantity exceeds its damaged condition balance');
    $bad=$pdo->query("SELECT COUNT(*) FROM condemnations WHERE status='approved' AND outcome='disposed' AND (disposed_at IS NULL OR disposal_recorded_by IS NULL)")->fetchColumn();
    p73_check((int)$bad===0,'Disposed CF records retain disposal timestamp and actor');
    echo 'INFO: Requested CFs = '.$pdo->query("SELECT COUNT(*) FROM condemnations WHERE status='requested'")->fetchColumn().PHP_EOL;
    echo 'INFO: Authorized, awaiting disposal = '.$pdo->query("SELECT COUNT(*) FROM condemnations WHERE status='approved' AND outcome IS NULL")->fetchColumn().PHP_EOL;
    echo 'INFO: Physically disposed = '.$pdo->query("SELECT COUNT(*) FROM condemnations WHERE status='approved' AND outcome='disposed'")->fetchColumn().PHP_EOL;
    echo 'INFO: Read-only preflight. No quantities or records are modified.'.PHP_EOL;
    if($errors)exit(1);
    echo 'All Phase 7.3 preflight checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
