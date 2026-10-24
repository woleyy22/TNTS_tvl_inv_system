<?php
/** Read-only check: php tests/phase7_2_preflight.php */
require_once dirname(__DIR__).'/includes/db.php';
$pdo=db();$errors=0;
function p72_check(bool $ok,string $label):void{global $errors;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$errors++;}
try {
 $tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_events'")->fetchAll(PDO::FETCH_COLUMN);
 p72_check(in_array('maintenance_events',$tables,true),'Immutable maintenance_events table installed');
 $c=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='condemnations'")->fetchAll(PDO::FETCH_COLUMN);
 foreach(['allocation_id','request_photo','authorization_document'] as $field)p72_check(in_array($field,$c,true),'condemnations.'.$field.' installed');
 if(!$errors){
   $bad=$pdo->query('SELECT COUNT(*) FROM maintenance_events ev LEFT JOIN ics_batch_allocations a ON a.allocation_id=ev.allocation_id AND a.ics_item_id=ev.ics_item_id WHERE ev.allocation_id IS NOT NULL AND a.allocation_id IS NULL')->fetchColumn();
   p72_check((int)$bad===0,'Maintenance source allocation references correspond to the correct ICS');
   $bad=$pdo->query('SELECT COUNT(*) FROM condemnations co LEFT JOIN ics_batch_allocations a ON a.allocation_id=co.allocation_id AND a.ics_item_id=co.ics_item_id WHERE co.allocation_id IS NOT NULL AND a.allocation_id IS NULL')->fetchColumn();
   p72_check((int)$bad===0,'Condemnation source allocation references correspond to the correct ICS');
   echo 'INFO: Recorded maintenance events = '.$pdo->query('SELECT COUNT(*) FROM maintenance_events')->fetchColumn().PHP_EOL;
 }
 echo 'INFO: Read-only test. Does not update quantities, images or transactions.'.PHP_EOL;
 if($errors)exit(1);
 echo 'All Phase 7.2 installation checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
