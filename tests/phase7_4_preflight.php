<?php
/** Read-only final Phase 7 reconciliation: php tests/phase7_4_preflight.php */
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/phase7_reconciliation.php';
try {
    $result=p74_reconcile(db());$summary=$result['summary'];
    echo 'INFO: Equipment ICS lines checked = '.$summary['equipment_lines'].PHP_EOL;
    echo 'INFO: Condemnation records checked = '.$summary['cf_records'].PHP_EOL;
    foreach($result['issues'] as $issue) {
        printf("%s: %s / %s (#%d): %s\n",
          $issue['level']==='blocker'?'REVIEW REQUIRED':'NOTICE',
          $issue['ics'],$issue['item'],$issue['ics_item_id'],$issue['message']);
    }
    echo 'INFO: Blockers = '.$summary['blockers'].'; review notices = '.$summary['review'].PHP_EOL;
    echo 'INFO: Read-only check; no database rows or quantities were changed.'.PHP_EOL;
    if($summary['blockers']>0){
       echo 'Phase 7.4 requires manual reconciliation for the affected records before final acceptance.'.PHP_EOL;exit(1);
    }
    if($summary['review']>0)echo 'CHECK: No blocking inconsistencies; review historical documentation notices.'.PHP_EOL;
    else echo 'PASS: All Phase 7.4 quantity/custody reconciliation checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
