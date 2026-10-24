<?php
/** Offline fixture validation for the read-only Phase 7 audit, no MySQL required. */
require_once dirname(__DIR__).'/includes/phase7_reconciliation.php';
final class P74AuditStatement extends PDOStatement {
    public function __construct(private readonly array $rows) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, ...$args):array { return $this->rows; }
}
final class P74AuditPDO extends PDO {
    public function __construct(private readonly int $serviceable) {}
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs):PDOStatement|false {
        if(str_contains($query,'FROM ics_items ii JOIN ics c'))return new P74AuditStatement([[
            'ics_item_id'=>9,'ics_id'=>1,'qty_issued'=>5,'qty_returned'=>0,'qty_transferred_out'=>0,
            'ics_number'=>'ICS-TEST-001','ics_status'=>'active','custodian'=>'Teacher Test','item_name'=>'Electric Fan',
            'item_category'=>'equipment','qty_serviceable'=>$this->serviceable,'qty_for_repair'=>5-$this->serviceable,
            'qty_damaged'=>0,'needs_review'=>0,'balance_id'=>9,
        ]]);
        if(str_contains($query,'FROM borrow_logs'))return new P74AuditStatement([['ics_item_id'=>9,'amount'=>2]]);
        if(str_contains($query,'FROM borrow_requests'))return new P74AuditStatement([['ics_item_id'=>9,'amount'=>1]]);
        if(str_contains($query,'FROM transfer_requests'))return new P74AuditStatement([]);
        if(str_contains($query,'FROM condemnations WHERE'))return new P74AuditStatement([]);
        if(str_contains($query,'FROM ics_batch_allocations a'))return new P74AuditStatement([]);
        if(str_contains($query,'FROM condemnations co'))return new P74AuditStatement([]);
        throw new RuntimeException('Unexpected query in audit unit test: '.substr($query,0,80));
    }
}
try {
    $valid=p74_reconcile(new P74AuditPDO(5));
    if($valid['summary']['blockers']!==0)throw new RuntimeException('Valid partially borrowed record produced blockers.');
    echo 'PASS: Valid active loan with one pending reservation reconciles cleanly.'.PHP_EOL;
    $bad=p74_reconcile(new P74AuditPDO(2));
    if($bad['summary']['blockers']<1)throw new RuntimeException('Over-reserved record was not flagged.');
    $codes=array_column($bad['issues'],'code');
    if(!in_array('reservations_over_serviceable',$codes,true))throw new RuntimeException('Reservation discrepancy missing.');
    echo 'PASS: Oversubscribed serviceable custody is reported as blocking.'.PHP_EOL;
    echo 'All offline Phase 7.4 reconciliation fixtures passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
