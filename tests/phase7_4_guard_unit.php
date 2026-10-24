<?php
/** Offline unit checks for custody arithmetic; does not connect to MySQL. */
require_once dirname(__DIR__).'/includes/maintenance_safety.php';
final class P74FakeStatement extends PDOStatement {
    public function __construct(private readonly int $value) {}
    public function execute(?array $params = null):bool { return true; }
    public function fetchColumn(int $column=0):mixed { return $this->value; }
}
final class P74FakePDO extends PDO {
    public function __construct(private readonly array $quantities) {}
    public function prepare(string $query, array $options=[]):PDOStatement|false {
        if(str_contains($query,'FROM borrow_logs'))return new P74FakeStatement($this->quantities['borrowed']??0);
        if(str_contains($query,'FROM borrow_requests'))return new P74FakeStatement($this->quantities['queue']??0);
        if(str_contains($query,'FROM transfer_requests'))return new P74FakeStatement($this->quantities['transfer']??0);
        if(str_contains($query,'FROM condemnations'))return new P74FakeStatement($this->quantities['cf']??0);
        throw new RuntimeException('Unexpected SQL in guard unit test.');
    }
}
function p74_assert(bool $condition, string $label):void {
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}
try {
    $normal=p71_availability(new P74FakePDO(['borrowed'=>2,'queue'=>1,'transfer'=>1,'cf'=>1]),1,['qty_serviceable'=>5,'qty_damaged'=>2]);
    p74_assert($normal['serviceable']===1,'Five serviceable minus two borrowed and two reservations gives one available.');
    p74_assert($normal['damaged']===1,'One outstanding CF holds one of two damaged units.');
    p74_assert(!$normal['reservation_inconsistent'],'A legitimate partially borrowed ICS remains valid.');
    $invalid=p71_availability(new P74FakePDO(['borrowed'=>4,'queue'=>2,'transfer'=>1,'cf'=>0]),1,['qty_serviceable'=>5,'qty_damaged'=>0]);
    p74_assert($invalid['reservation_inconsistent']===true,'Over-reservations are flagged without modifying records.');
    p74_assert($invalid['serviceable']===0,'Unsafe availability is clamped to zero.');
    echo 'All offline Phase 7.4 guard unit tests passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
