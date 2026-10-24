<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/ics_accountability.php';
require_once __DIR__.'/includes/batch_accounting.php';
$me = require_role(['supply_officer']);
$pdo=db(); $actor=(int)$me['id'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $icsId=(int)($_POST['ics_id']??0);
    $action=(string)($_POST['operation']??'');
    $reason=trim((string)($_POST['reason']??''));
    try {
        if(($_POST['confirmed']??'')!=='1') throw new RuntimeException('Review and confirm the retrieval summary before saving.');
        if ($icsId<=0 || !in_array($action,['retrieve','close_supply'],true) || strlen($reason)<5)
            throw new RuntimeException('Select an ICS and enter verification notes (at least 5 characters).');
        // Phase 3.2 prerequisite: the old unique index allowed one return
        // per ICS, which is incompatible with repeated partial retrievals.
        $indexCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ics_returns'
          AND INDEX_NAME = 'uq_return_ics' AND NON_UNIQUE = 0");
        if ((int)$indexCheck->fetchColumn() > 0) {
            throw new RuntimeException('ICS return history migration is still required. Contact the administrator to complete the required return-history database update before recording returns.');
        }
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM ics WHERE ics_id=? FOR UPDATE');$st->execute([$icsId]);$ics=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ics || $ics['status']!=='active') throw new RuntimeException('This ICS is no longer active. Refresh before trying again.');
        $st=$pdo->prepare('SELECT ii.ics_item_id FROM ics_items ii WHERE ii.ics_id=? ORDER BY ii.ics_item_id FOR UPDATE');
        $st->execute([$icsId]);$ids=$st->fetchAll(PDO::FETCH_COLUMN);
        $st=$pdo->prepare("SELECT COUNT(*) FROM borrow_logs b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.ics_id=? AND b.status='borrowed'");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Outstanding borrowed items must be returned and verified first.');
        // A physical retrieval/close must not invalidate another open custody workflow.
        // Checks happen after the ICS header and item rows are locked in this transaction.
        $st=$pdo->prepare("SELECT COUNT(*) FROM borrow_requests br JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id WHERE ii.ics_id=? AND br.status IN ('requested','approved','borrowed','partially_returned')");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Resolve active borrowing requests, releases, and returns before retrieving or closing this ICS.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM transfer_requests tr JOIN ics_items ii ON ii.ics_item_id=tr.ics_item_id WHERE ii.ics_id=? AND tr.status IN ('requested','accepted','awaiting_finalization')");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Resolve pending property transfers before retrieving or closing this ICS.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id WHERE ii.ics_id=? AND (x.status='requested' OR (x.status='approved' AND x.outcome IS NULL))");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Resolve requested or authorized-but-not-disposed condemnation records before retrieving or closing this ICS.');
        $updates=[]; $returned=0; $settled=true; $hasEquipment=false; $reclassified=false;
        foreach($ids as $id) {
            $state=ics31_state($pdo,(int)$id,true);
            if(!$state['is_equipment']) continue;
            $hasEquipment=true;
            if ($state['needs_review']) throw new RuntimeException('Review the current condition balance of '.$state['item_name'].' before retrieving more units.');
            $received=[];
            foreach (['serviceable'=>'svc','repair'=>'repair','damaged'=>'damaged'] as $key=>$field) {
                $raw=(string)($_POST[$field][$id]??'0');
                if(!ctype_digit($raw) || strlen($raw)>9) throw new RuntimeException('Enter nonnegative whole numbers for '.$state['item_name'].'.');
                $received[$key]=(int)$raw;
            }
            $qty=array_sum($received);
            if($action==='close_supply' && $qty) throw new RuntimeException('Administrative closure cannot physically retrieve equipment.');
            if($qty>$state['remaining_qty']) throw new RuntimeException('Only '.$state['remaining_qty'].' unit(s) of '.$state['item_name'].' remain with the teacher.');
            if($action==='retrieve' && $qty>0) {
                [$pools,$changed]=ics31_retrieve_balances($state,$received);
                $updates[]=['id'=>$id,'qty'=>$qty,'qty_by_condition'=>$received,'pools'=>$pools];
                $returned+=$qty;
                $reclassified=$reclassified||$changed;
            }
            if($state['remaining_qty']-$qty>0) $settled=false;
        }
        if($action==='retrieve' && $returned===0) throw new RuntimeException('Enter at least one physical equipment return. For distributed supplies, use Administrative Close instead.');
        if($action==='close_supply' && !$settled) throw new RuntimeException('Equipment remains with the teacher. Retrieve it before closing this ICS.');
        $pdo->prepare('INSERT INTO ics_returns (ics_id,verified_by,reason,verified_serviceable) VALUES (?,?,?,0)')->execute([$icsId,$actor,$reason]);
        $returnId=(int)$pdo->lastInsertId();
        $add=$pdo->prepare('INSERT INTO ics_return_lines (return_id,ics_item_id,qty_serviceable,qty_for_repair,qty_damaged) VALUES (?,?,?,?,?)');
        foreach($updates as $u) {
            $pdo->prepare('UPDATE ics_items SET qty_returned=qty_returned+? WHERE ics_item_id=?')->execute([$u['qty'],$u['id']]);
            $add->execute([$returnId,$u['id'],$u['qty_by_condition']['serviceable'],$u['qty_by_condition']['repair'],$u['qty_by_condition']['damaged']]);
            // Attribute returns to source batches for post-4.7.2 issues only.
            $selectedReturnAllocation=(int)($_POST['return_batch'][$u['id']]??0);
            $batchRows=$pdo->prepare('SELECT allocation_id,qty_issued,qty_returned,qty_transferred_out FROM ics_batch_allocations WHERE ics_item_id=? ORDER BY allocation_id FOR UPDATE');
            $batchRows->execute([(int)$u['id']]);$allocations=$batchRows->fetchAll(PDO::FETCH_ASSOC);
            if($allocations){
                $selected=null;
                foreach($allocations as $allocation){if((int)$allocation['allocation_id']===$selectedReturnAllocation){$selected=$allocation;break;}}
                if(!$selected) throw new RuntimeException('Select the original source batch being physically returned.');
                // Disposed CF units stay in the historic batch allocation; they can
                // never be returned as physical equipment a second time.
                $cf=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM condemnations WHERE ics_item_id=? AND allocation_id=? AND status IN ('requested','approved')");
                $cf->execute([(int)$u['id'],$selectedReturnAllocation]);
                $cfClaimed=(int)$cf->fetchColumn();
                $capacity=(int)$selected['qty_issued']-(int)$selected['qty_returned']-(int)$selected['qty_transferred_out']-$cfClaimed;
                if($capacity<0)throw new RuntimeException('Source batch accountability is inconsistent with past transfers/disposals. Reconcile before retrieval.');
                if($u['qty']>$capacity) throw new RuntimeException('The source batch has only '.$capacity.' physically returnable unit(s) after transfer and condemnation records. Record one batch per retrieval.');
                $batchLine=$pdo->prepare('INSERT INTO ics_batch_return_lines (return_id,allocation_id,qty_serviceable,qty_for_repair,qty_damaged) VALUES (?,?,?,?,?)');
                $batchUpdate=$pdo->prepare('UPDATE ics_batch_allocations SET qty_returned=qty_returned+?,qty_serviceable_returned=qty_serviceable_returned+? WHERE allocation_id=?');
                $batchUpdate->execute([$u['qty'],$u['qty_by_condition']['serviceable'],$selectedReturnAllocation]);
                $batchLine->execute([$returnId,$selectedReturnAllocation,$u['qty_by_condition']['serviceable'],$u['qty_by_condition']['repair'],$u['qty_by_condition']['damaged']]);
            }
            // Legacy unallocated items retain their historical non-batch return accounting.
            ics31_save($pdo,(int)$u['id'],$u['pools']);
        }
        if($settled) $pdo->prepare("UPDATE ics SET status='closed' WHERE ics_id=?")->execute([$icsId]);
        $summary=$action==='close_supply'?'Distributed supplies administratively reconciled. ':'Physically retrieved '.$returned.' unit(s). ';
        if($reclassified) $summary.='Some verified return conditions differed from previously recorded conditions. ';
        $summary.=($settled?'ICS closed. ':'ICS remains active. ').$reason;
        log_action($actor,'ICS Reconciliation','ICS',$icsId,$summary);
        $pdo->commit();
        flash('success',$settled?'ICS reconciled and closed.':'Partial retrieval saved. Remaining equipment stays with the teacher.');
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error',$e->getMessage());
    }
    redirect('ics_returns.php');
}
// Phase 3.8.12: searchable ICS register. The POST reconciliation path above is unchanged.
$ics12Search=substr(trim((string)($_GET['search']??'')),0,120);
$ics12Status=(string)($_GET['status']??'active');
if(!in_array($ics12Status,['active','closed','all'],true))$ics12Status='active';
$ics12Teacher=max(0,(int)($_GET['teacher']??0));
$ics12Course=max(0,(int)($_GET['course']??0));
$ics12Type=(string)($_GET['type']??'');
$ics12Types=['equipment','non_consumable','consumable','expiring_material'];
if(!in_array($ics12Type,$ics12Types,true))$ics12Type='';
$ics12From=(string)($_GET['from']??'');$ics12To=(string)($_GET['to']??'');
$ics12ValidDate=static function(string $s):bool{
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$s,$matches))return false;
    return checkdate((int)$matches[2],(int)$matches[3],(int)$matches[1]);
};
if(!$ics12ValidDate($ics12From))$ics12From='';
if(!$ics12ValidDate($ics12To))$ics12To='';
$ics12Order=(string)($_GET['sort']??'newest');
$ics12Orders=[
    'newest'=>'c.date_issued DESC,c.ics_id DESC',
    'oldest'=>'c.date_issued ASC,c.ics_id ASC',
    'date_desc'=>'c.date_issued DESC,c.ics_id DESC',
    'number_asc'=>'c.ics_number ASC,c.ics_id ASC',
    'number_desc'=>'c.ics_number DESC,c.ics_id DESC',
    'teacher_asc'=>'u.full_name ASC,c.ics_id DESC',
    'teacher_desc'=>'u.full_name DESC,c.ics_id DESC',
    'type_asc'=>'item_types ASC,c.ics_id DESC',
    'type_desc'=>'item_types DESC,c.ics_id DESC',
    'status_asc'=>'c.status ASC,c.ics_id DESC',
    'status_desc'=>'c.status DESC,c.ics_id DESC',
];
if(!isset($ics12Orders[$ics12Order]))$ics12Order='newest';
$ics12Where=[];$ics12Params=[];
if($ics12Status!=='all'){$ics12Where[]='c.status=?';$ics12Params[]=$ics12Status;}
if($ics12Search!==''){
    $ics12Where[]='(c.ics_number LIKE ? OR u.full_name LIKE ? OR EXISTS (SELECT 1 FROM ics_items si JOIN inventory_items inv ON inv.item_id=si.item_id WHERE si.ics_id=c.ics_id AND inv.item_name LIKE ?))';
    $term='%'.$ics12Search.'%';array_push($ics12Params,$term,$term,$term);
}
if($ics12Teacher){$ics12Where[]='c.teacher_id=?';$ics12Params[]=$ics12Teacher;}
if($ics12Course){$ics12Where[]='(c.course_id=? OR EXISTS (SELECT 1 FROM ics_items si WHERE si.ics_id=c.ics_id AND si.course_id=?))';array_push($ics12Params,$ics12Course,$ics12Course);}
if($ics12Type){$ics12Where[]='EXISTS (SELECT 1 FROM ics_items si JOIN inventory_items inv ON inv.item_id=si.item_id WHERE si.ics_id=c.ics_id AND inv.item_category=?)';$ics12Params[]=$ics12Type;}
if($ics12From){$ics12Where[]='c.date_issued >= ?';$ics12Params[]=$ics12From;}
if($ics12To){$ics12Where[]='c.date_issued <= ?';$ics12Params[]=$ics12To;}
$ics12Base=' FROM ics c JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=c.course_id';
$ics12SqlWhere=$ics12Where?' WHERE '.implode(' AND ',$ics12Where):'';
$ics12CountStmt=$pdo->prepare('SELECT COUNT(*)'.$ics12Base.$ics12SqlWhere);
$ics12CountStmt->execute($ics12Params);
$ics12Total=(int)$ics12CountStmt->fetchColumn();
$ics12PerPage=10;$ics12Pages=max(1,(int)ceil($ics12Total/$ics12PerPage));
$ics12Page=min($ics12Pages,max(1,(int)($_GET['page']??1)));
$ics12Offset=($ics12Page-1)*$ics12PerPage;
$ics12Query='SELECT c.ics_id,c.ics_number,c.teacher_id,c.date_issued,c.status,u.full_name,co.course_name,
    (SELECT COUNT(*) FROM ics_items si WHERE si.ics_id=c.ics_id) AS item_count,
    (SELECT GROUP_CONCAT(DISTINCT REPLACE(inv.item_category,"_"," ") ORDER BY inv.item_category SEPARATOR ", ") FROM ics_items si JOIN inventory_items inv ON inv.item_id=si.item_id WHERE si.ics_id=c.ics_id) AS item_types'
    .$ics12Base.$ics12SqlWhere.' ORDER BY '.$ics12Orders[$ics12Order].' LIMIT '.(int)$ics12PerPage.' OFFSET '.(int)$ics12Offset;
$ics12Statement=$pdo->prepare($ics12Query);$ics12Statement->execute($ics12Params);
$records=$ics12Statement->fetchAll(PDO::FETCH_ASSOC);
$ics12Teachers=$pdo->query('SELECT DISTINCT u.user_id,u.full_name FROM ics c JOIN users u ON u.user_id=c.teacher_id ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC);
$ics12Courses=$pdo->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll(PDO::FETCH_ASSOC);
$ics12Filters=['search'=>$ics12Search,'status'=>$ics12Status,'teacher'=>$ics12Teacher?:null,'course'=>$ics12Course?:null,'type'=>$ics12Type?:null,'from'=>$ics12From?:null,'to'=>$ics12To?:null,'sort'=>$ics12Order];
$ics12Url=static function(array $changes=[])use($ics12Filters):string{
    $query=array_filter(array_merge($ics12Filters,$changes),static fn($v)=>$v!==null&&$v!=='');
    return url('ics_returns.php?'.http_build_query($query));
};
// Header sorting cycles: neutral → ascending → descending → neutral.
$ics12SortNext=static function(string $column)use($ics12Order,$ics12Url):array{
    $pairs=['number'=>['number_asc','number_desc'],'teacher'=>['teacher_asc','teacher_desc'],
        'date'=>['oldest','date_desc'],'type'=>['type_asc','type_desc'],'status'=>['status_asc','status_desc']];
    [$asc,$desc]=$pairs[$column];
    if($ics12Order===$asc){$next=$desc;$symbol='↑';}
    elseif($ics12Order===$desc){$next='newest';$symbol='↓';}
    else{$next=$asc;$symbol='–';}
    return [$ics12Url(['sort'=>$next,'page'=>1,'open'=>null]),$symbol];
};
$ics12Open=max(0,(int)($_GET['open']??0));
$ics12Selected=null;
foreach($records as $c){if((int)$c['ics_id']===$ics12Open){$ics12Selected=$c;break;}}
// Support direct links from activity pages/notifications even when the record is
// outside the currently displayed page or doesn't match current filters.
if($ics12Open && !$ics12Selected){
    $lookup=$pdo->prepare('SELECT c.ics_id,c.ics_number,c.teacher_id,c.date_issued,c.status,u.full_name,co.course_name FROM ics c JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=c.course_id WHERE c.ics_id=?');
    $lookup->execute([$ics12Open]);$ics12Selected=$lookup->fetch(PDO::FETCH_ASSOC)?:null;
}

$ics12States=[];$ics12HasEquipment=false;$ics12Unresolved=false;$ics12HasSupply=false;$ics12ReturnHistory=[];
if($ics12Selected){
    $getItems=$pdo->prepare('SELECT ii.ics_item_id FROM ics_items ii WHERE ii.ics_id=? ORDER BY ii.ics_item_id');
    $getItems->execute([$ics12Open]);
    $batchAllocQuery=$pdo->prepare('SELECT a.allocation_id,a.qty_issued,a.qty_returned,b.batch_number,b.expiration_date FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id WHERE a.ics_item_id=? ORDER BY a.allocation_id');
    foreach($getItems->fetchAll(PDO::FETCH_COLUMN) as $lineId){
        $state=ics31_state($pdo,(int)$lineId);
        $batchAllocQuery->execute([(int)$lineId]);$state['batch_allocations']=$batchAllocQuery->fetchAll(PDO::FETCH_ASSOC);
        $ics12States[]=$state;
        if($state['is_equipment']){$ics12HasEquipment=true;$ics12Unresolved=$ics12Unresolved||$state['remaining_qty']>0;}
        else $ics12HasSupply=true;
    }
    $ics12Returns=$pdo->prepare('SELECT r.returned_at,r.reason,u.full_name AS verifier FROM ics_returns r JOIN users u ON u.user_id=r.verified_by WHERE r.ics_id=? ORDER BY r.returned_at DESC,r.return_id DESC');
    $ics12Returns->execute([$ics12Open]);$ics12ReturnHistory=$ics12Returns->fetchAll(PDO::FETCH_ASSOC);
}
$ics12OnlySupply=!$ics12HasEquipment&&$ics12HasSupply;
$pageTitle='Retrieve / Close ICS';$active='ics';include __DIR__.'/includes/header.php';
?>
<style>
/* ICS record browser, scoped so other TNTS tables and forms are unaffected. */
.ics12-wrap{width:100%;max-width:1400px;min-width:0;margin:0 auto}
.ics12-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.ics12-info{margin:12px 0 18px;border:1px solid #dbe3ed;border-radius:12px;background:#fff;overflow:hidden}
.ics12-info>summary{padding:12px 16px;cursor:pointer;font-weight:650;color:#293a53}
.ics12-info>p{margin:0;padding:3px 16px 16px;color:#485974;line-height:1.65;font-size:.88rem}
.ics12-filter-card,.ics12-browser,.ics12-selected{background:#fff;border:1px solid #e0e5ed;border-radius:15px;box-shadow:0 7px 28px rgba(19,34,57,.045);margin-bottom:16px;min-width:0;overflow:hidden}
.ics12-filter-card{padding:18px}
.ics12-filters{display:grid;grid-template-columns:minmax(180px,2fr) repeat(3,minmax(135px,1fr));gap:12px;align-items:end}
.ics12-filters label{font-size:.75rem;color:#41516b;font-weight:650;display:flex;flex-direction:column;gap:6px;min-width:0}
.ics12-filters input,.ics12-filters select{width:100%;min-width:0;min-height:40px;border:1px solid #d7deeb;border-radius:8px;background:#fff;color:#24334b;padding:9px 11px;font:inherit;font-weight:450}
.ics12-filter-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}
.ics12-apply{border:0;border-radius:8px;background:#870000;color:#fff;font-weight:700;padding:10px 17px;cursor:pointer}
.ics12-reset{display:inline-block;color:#801d38;font-weight:650;text-decoration:none;padding:9px}
.ics12-browser{border-top:1px solid #e0e5ed}
.ics12-list-head{padding:16px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.ics12-list-head h2{font-size:1.03rem;margin:0;color:#1e2d48}
.ics12-list-head p{font-size:.76rem;margin:4px 0 0;color:#61718a}
.ics12-scroll{overflow-x:auto;max-width:100%}
.ics12-list{width:100%;border-collapse:collapse;text-align:left;table-layout:fixed}
.ics12-sort{border:0;background:transparent;color:inherit;display:inline-flex;align-items:center;gap:5px;text-align:left;text-decoration:none;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer}.ics12-sort span{color:#8b2441;font-size:.81rem;font-weight:800}.ics12-sort:hover{text-decoration:underline}
.ics12-list th{background:#f4f6fa;color:#5c6980;font-size:.67rem;letter-spacing:.045em;text-transform:uppercase;padding:12px 10px;border-bottom:1px solid #e1e8f0}
.ics12-list td{padding:13px 10px;border-bottom:1px solid #edf0f5;font-size:.79rem;color:#33435d;vertical-align:middle;overflow-wrap:anywhere}
.ics12-list tbody tr:last-child td{border-bottom:none}
.ics12-list tr.is-selected{background:#f8edf2}
.ics12-primary{font-weight:750;color:#172641}
.ics12-secondary{display:block;margin-top:4px;color:#64738b;font-size:.72rem}
.ics12-status{display:inline-flex;padding:5px 10px;border-radius:999px;font-weight:750;font-size:.69rem;white-space:nowrap;background:#e5f5ed;color:#1b7651}
.ics12-status.closed{background:#e9eef4;color:#475569}
.ics12-open-link{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dfbdca;border-radius:8px;text-decoration:none;color:#841c3c;white-space:nowrap;padding:8px 10px;font-weight:700;font-size:.72rem}
.ics12-open-link:hover,.ics12-open-link:focus-visible{background:#f9edf1}
.ics12-pager{padding:12px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #edf0f5;color:#63728b;font-size:.78rem}
.ics12-pager nav{display:flex;gap:8px;align-items:center}
.ics12-pager a,.ics12-pager span{padding:7px 10px;border:1px solid #dce2ec;border-radius:8px;color:#35445b;text-decoration:none}
.ics12-pager span{opacity:.45}
.ics12-selected{border-top:1px solid #e0e5ed;padding:18px}
.ics12-selected-head{display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.ics12-selected h2{margin:0;font-size:1.15rem;color:#1b2c49}
.ics12-placeholder{padding:24px;text-align:center;color:#697990}
.ics31-content{padding:0}.ics31-table-wrap{overflow-x:auto;width:100%}.ics31-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:630px}
.ics31-table th,.ics31-table td{text-align:left;padding:11px 9px;border-bottom:1px solid #e7ecf2;font-size:.79rem;vertical-align:top;overflow-wrap:anywhere}
.ics31-table th{background:#f5f7fa;color:#475569;font-size:.68rem;letter-spacing:.03em;text-transform:uppercase}
.ics31-muted{font-size:.75rem;color:#64748b}.ics31-input{width:66px!important;max-width:66px;padding:8px 4px;border:1px solid #cbd5e1;border-radius:7px;text-align:center}
.ics31-warn{background:#fff4db;color:#825100;padding:10px;border-radius:7px;margin:10px 0}.ics31-error{color:#a11b1b;font-size:.83rem;font-weight:650;margin:9px 0}
.ics31-bad{border-color:#c22828!important;background:#fff0f0}.ics31-summary{display:flex;flex-wrap:wrap;gap:18px;padding:13px 0}.ics31-summary strong{font-size:1.05rem;color:#17253d}
.ics31-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ics31-note{width:min(100%,560px);padding:10px;border:1px solid #cbd5e1;border-radius:7px}
.ics31-dialog{width:min(94vw,550px);border:0;border-radius:15px;padding:25px;box-shadow:0 16px 70px #0004}.ics31-dialog::backdrop{background:#17213299}.ics31-dialog h3{margin:0 0 12px}.ics31-dialog .ics31-actions{justify-content:flex-end;margin-top:20px}
.ics31-record-conditions{display:flex;flex-direction:column;align-items:flex-start;gap:4px;margin-top:6px}
.ics31-count{font-size:.71rem;display:inline-flex;gap:3px;white-space:nowrap;border-radius:20px;padding:3px 7px;font-weight:650;background:#e7f4eb;color:#21633c}
.ics31-count.repair{background:#fff0d0;color:#854d0e}.ics31-count.damaged{background:#ffe5e5;color:#a52828}
.ics31-record-total{color:#17253d}.ics31-after{font-size:.71rem;margin-top:7px;color:#64748b}
.ics31-entry-grid{display:grid;grid-template-columns:repeat(3,minmax(65px,1fr));gap:5px;min-width:208px}
.ics31-return-field{display:flex;align-items:center;flex-direction:column;gap:5px;font-size:.76rem}
.ics31-return-field .ics31-count{font-size:.64rem;padding:3px 5px}.ics31-return-field .ics31-input{width:62px!important;max-width:62px}
.ics12-history{margin-top:15px;border-top:1px solid #e7eaf0;padding-top:12px}
.ics12-history summary{font-weight:700;cursor:pointer;color:#24364d}.ics12-history li{font-size:.78rem;line-height:1.6;margin:8px 0;color:#41526b}
.ics12-empty{padding:24px;text-align:center;color:#61718a}
@media(max-width:1150px){.ics12-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.ics12-filters label:first-child{grid-column:span 2}}
@media(max-width:750px){.ics12-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.ics12-list{min-width:660px}.ics12-selected{padding:12px}.ics12-filter-card{padding:12px}}
@media(max-width:480px){.ics12-filters{grid-template-columns:1fr}.ics12-filters label:first-child{grid-column:auto}}
</style>
<div class="ics12-wrap">
<style>
.ics18-tabs{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:0 0 20px;padding:0;border:0;background:transparent;box-shadow:none}
.ics18-tabs a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:10px 16px;border-radius:8px;border:1px solid #ddc1ca;background:#fff;color:#7b1738;text-decoration:none;font-weight:700;font-size:.83rem;line-height:1.2;transition:background .18s,border-color .18s,color .18s}
.ics18-tabs a:hover,.ics18-tabs a:focus-visible{background:#fff1f5;border-color:#8b2142}
.ics18-tabs a[aria-current="page"]{color:#fff;background:#800000;border-color:#800000}
@media(max-width:460px){.ics18-tabs a{flex:1 1 auto;padding:10px 12px}}
</style>
<nav class="ics18-tabs" aria-label="Inventory Custodian Slip pages">
 <a href="<?= e(url('ics_issue.php')) ?>">Issue ICS</a>
 <a href="<?= e(url('ics_returns.php')) ?>" aria-current="page">Retrieve / Close ICS</a>
</nav>
<div class="page-head"><h1>Retrieve / Close ICS</h1><p>Find an ICS by teacher, number or item. Select one record to review its quantities and verify a return.</p></div>
<details class="ics12-info"><summary>How retrieval and administrative closure work</summary><p>Issued quantity never changes. Disposed units are already accounted for. The <strong>On Teacher Record</strong> quantity and conditions reflect the record before the current retrieval. Verify the physical condition at handover. Distributed consumables are closed administratively and are never automatically returned to stock.</p></details>
<div class="ics12-filter-card">
<form method="get" class="ics12-filters" aria-label="Filter ICS records">
<label>Search ICS, holder or item<input type="search" name="search" value="<?=e($ics12Search)?>" placeholder="ICS number, teacher or item" maxlength="120"></label>
<label>Status<select name="status"><option value="active" <?=$ics12Status==='active'?'selected':''?>>Active</option><option value="closed" <?=$ics12Status==='closed'?'selected':''?>>Closed</option><option value="all" <?=$ics12Status==='all'?'selected':''?>>All statuses</option></select></label>
<label>Teacher / Holder<select name="teacher"><option value="0">All teachers</option><?php foreach($ics12Teachers as $t):?><option value="<?=(int)$t['user_id']?>" <?=$ics12Teacher===(int)$t['user_id']?'selected':''?>><?=e($t['full_name'])?></option><?php endforeach;?></select></label>
<label>ICS item type<select name="type"><option value="">All types</option><?php foreach($ics12Types as $type):?><option value="<?=e($type)?>" <?=$ics12Type===$type?'selected':''?>><?=e(ucwords(str_replace('_',' ',$type)))?></option><?php endforeach;?></select></label>
<label>Course<select name="course"><option value="0">All courses</option><?php foreach($ics12Courses as $co):?><option value="<?=(int)$co['course_id']?>" <?=$ics12Course===(int)$co['course_id']?'selected':''?>><?=e($co['course_name'])?></option><?php endforeach;?></select></label>
<label>Issued from<input type="date" name="from" value="<?=e($ics12From)?>"></label>
<label>Issued through<input type="date" name="to" value="<?=e($ics12To)?>"></label>
<label>Sort records<select name="sort"><option value="newest" <?=$ics12Order==='newest'?'selected':''?>>Newest issued</option><option value="oldest" <?=$ics12Order==='oldest'?'selected':''?>>Oldest issued</option><option value="number_asc" <?=$ics12Order==='number_asc'?'selected':''?>>ICS number A–Z</option><option value="number_desc" <?=$ics12Order==='number_desc'?'selected':''?>>ICS number Z–A</option><option value="teacher_asc" <?=$ics12Order==='teacher_asc'?'selected':''?>>Teacher A–Z</option><option value="teacher_desc" <?=$ics12Order==='teacher_desc'?'selected':''?>>Teacher Z–A</option></select></label>
<div class="ics12-filter-actions"><button type="submit" class="ics12-apply">Apply filters</button><a class="ics12-reset" href="<?=e(url('ics_returns.php'))?>">Reset</a></div>
</form></div>
<section class="ics12-browser" aria-label="ICS records">
<div class="ics12-list-head"><div><h2>ICS Records</h2><p><?=number_format($ics12Total)?> matching document<?= $ics12Total===1?'':'s'?> · <?= $ics12PerPage ?> per page</p></div><span class="ics31-muted">Select a record to see the full breakdown and verification form</span></div>
<div class="ics12-scroll"><table class="ics12-list"><thead><tr>
<?php foreach([['number','ICS Number','21%'],['teacher','Teacher / Holder','23%'],['date','Issued Date','13%'],['type','Item Type(s)','19%'],['status','Status','10%']] as $head):[$sortHref,$sortSymbol]=$ics12SortNext($head[0]);?>
<th style="width:<?=e($head[2])?>"><a class="ics12-sort" href="<?=e($sortHref)?>" title="Sort <?=e($head[1])?>; currently <?=e($sortSymbol==='–'?'default':($sortSymbol==='↑'?'ascending':'descending'))?>"><?=e($head[1])?> <span aria-hidden="true"><?=e($sortSymbol)?></span></a></th>
<?php endforeach;?><th style="width:14%">Action</th></tr></thead><tbody>
<?php foreach($records as $c):?>
<tr class="<?= $ics12Open===(int)$c['ics_id']?'is-selected':'' ?>">
<td><strong class="ics12-primary"><?=e($c['ics_number'])?></strong><span class="ics12-secondary"><?= (int)$c['item_count'] ?> item line<?= (int)$c['item_count']===1?'':'s' ?></span></td>
<td><strong class="ics12-primary"><?=e($c['full_name'])?></strong><span class="ics12-secondary"><?=e($c['course_name']?:'Course per item / Unclassified')?></span></td>
<td><?=e($c['date_issued'])?></td><td><?=e($c['item_types']?:'–')?></td><td><span class="ics12-status <?=e($c['status'])?>"><?=e(ucfirst($c['status']))?></span></td>
<td><a class="ics12-open-link" href="<?=e($ics12Url(['page'=>$ics12Page,'open'=>(int)$c['ics_id']]))?>#ics12-detail"><?= $c['status']==='active'?'Review ICS':'View record' ?> →</a></td>
</tr>
<?php endforeach;?>
<?php if(!$records):?><tr><td colspan="6" class="ics12-empty">No ICS records match the selected filters. Try resetting your search.</td></tr><?php endif;?>
</tbody></table></div>
<div class="ics12-pager"><span><?php if($ics12Total):?>Showing <?=($ics12Offset+1)?>–<?=min($ics12Offset+$ics12PerPage,$ics12Total)?> of <?=number_format($ics12Total)?><?php else:?>No records found<?php endif;?></span><nav aria-label="ICS pages">
<?php if($ics12Page>1):?><a href="<?=e($ics12Url(['page'=>$ics12Page-1,'open'=>null]))?>">← Previous</a><?php else:?><span>← Previous</span><?php endif;?>
<span>Page <?=$ics12Page?> / <?=$ics12Pages?></span>
<?php if($ics12Page<$ics12Pages):?><a href="<?=e($ics12Url(['page'=>$ics12Page+1,'open'=>null]))?>">Next →</a><?php else:?><span>Next →</span><?php endif;?></nav></div>
</section>
<section class="ics12-selected" id="ics12-detail" aria-label="Selected ICS detail">
<?php if(!$ics12Selected):?>
<div class="ics12-placeholder"><strong>Choose an ICS record above</strong><p>Its item balances, batch selections, and available actions will appear here. Only one ICS is loaded for review at a time.</p></div>
<?php else:?>
<div class="ics12-selected-head"><div><h2><?=e($ics12Selected['ics_number'])?> — <?=e($ics12Selected['full_name'])?></h2><p class="ics31-muted">Issued <?=e($ics12Selected['date_issued'])?> · <?=e($ics12Selected['course_name']?:'Courses listed per item')?> · <span class="ics12-status <?=e($ics12Selected['status'])?>"><?=e(ucfirst($ics12Selected['status']))?></span></p></div><a class="ics12-open-link" target="_blank" rel="noopener" href="<?=e(url('print_ics.php?id='.(int)$ics12Selected['ics_id']))?>">View original ICS ↗</a></div>
<div class="ics31-content">
<?php if($ics12Selected['status']==='active'):?><form method="post" class="ics31-form" data-ics="<?=e($ics12Selected['ics_number'])?>">
<?=csrf_field()?><input type="hidden" name="ics_id" value="<?=(int)$ics12Selected['ics_id']?>"><input type="hidden" name="operation" value="retrieve" class="ics31-operation">
<?php else:?><p class="ics31-muted">This ICS is closed. Its record is read-only; no new retrieval can be submitted.</p><?php endif;?>
<div class="ics31-table-wrap"><table class="ics31-table"><thead><tr><th style="width:24%">Item / source batch</th><th style="width:18%">Issued / Retrieved / Disposed</th><th style="width:23%">On Teacher Record</th><th style="width:35%">Verified Return Conditions</th></tr></thead><tbody>
<?php foreach($ics12States as $s):$id=(int)$s['ics_item_id']; ?>
<tr class="ics31-line" data-limit="<?=$s['remaining_qty']?>" data-item="<?=e($s['item_name'])?>" data-equipment="<?=$s['is_equipment']?'1':'0'?>">
<td><strong><?=e($s['item_name'])?></strong><div class="ics31-muted"><?=e(ucwords(str_replace('_',' ',$s['item_category'])))?></div>
<?php if($ics12Selected['status']==='active' && $s['is_equipment'] && count($s['batch_allocations'])>0):?>
<div class="ics31-muted" style="margin-top:9px"><label>Batch being returned<select name="return_batch[<?=$id?>]" style="display:block;max-width:100%;padding:7px"><option value="">Select source batch</option>
<?php foreach($s['batch_allocations'] as $ba):$unreturned=(int)$ba['qty_issued']-(int)$ba['qty_returned'];?><option data-capacity="<?=$unreturned?>" value="<?=(int)$ba['allocation_id']?>" <?=$unreturned<=0?'disabled':''?> <?=count($s['batch_allocations'])===1?'selected':''?>><?=e($ba['batch_number']?:'Batch #'.$ba['allocation_id'])?> · <?=$unreturned?> left · <?=e($ba['expiration_date']?:'No expiry')?></option><?php endforeach;?></select></label></div>
<?php endif;?>
<?php if($s['needs_review'] && $ics12Selected['status']==='active'):?><div class="ics31-warn">Balance needs review. <a href="<?=e(url('ics_reconcile.php?ics_item_id='.$id))?>">Verify now</a></div><?php endif;?></td>
<td><div class="ics31-muted">Issued</div><strong><?=(int)$s['qty_issued']?> <?=e($s['unit'])?></strong><div class="ics31-muted">Retrieved: <?=(int)$s['qty_returned']?></div><div class="ics31-muted">Disposed: <?=(int)$s['disposed_qty']?></div></td>
<td><?php if($s['is_equipment']):?><strong class="ics31-record-total"><?=$s['remaining_qty']?> <?=e($s['unit'])?></strong><div class="ics31-record-conditions"><span class="ics31-count">Serviceable: <strong><?=$s['qty_serviceable']?></strong></span><span class="ics31-count repair">For Repair: <strong><?=$s['qty_for_repair']?></strong></span><span class="ics31-count damaged">Damaged: <strong><?=$s['qty_damaged']?></strong></span></div>
<?php if($ics12Selected['status']==='active'):?><div class="ics31-after">After planned retrieval: <strong class="ics31-after-count"><?=$s['remaining_qty']?></strong></div><?php endif;?>
<?php else:?><strong>Distributed</strong><div class="ics31-muted">No physical retrieval required</div><?php endif;?></td>
<td><?php if($ics12Selected['status']==='active' && $s['is_equipment']):?><div class="ics31-entry-grid">
<?php foreach([['field'=>'svc','title'=>'Serviceable','cls'=>''],['field'=>'repair','title'=>'For Repair','cls'=>'repair'],['field'=>'damaged','title'=>'Damaged','cls'=>'damaged']] as $field):?>
<label class="ics31-return-field"><span class="ics31-count <?=e($field['cls'])?>"><?=e($field['title'])?></span><input class="ics31-input" name="<?=e($field['field'])?>[<?=$id?>]" type="number" inputmode="numeric" min="0" max="<?=$s['remaining_qty']?>" step="1" value="0" <?=$s['needs_review']||$s['remaining_qty']===0?'disabled':''?> aria-label="<?=e($s['item_name'].' returned as '.$field['title'])?>"></label>
<?php endforeach;?></div>
<?php else:?><span class="ics31-muted"><?= $ics12Selected['status']==='closed'?'Record closed':'Not applicable to distributed supplies' ?></span><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if($ics12Selected['status']==='active'):?>
<div class="ics31-summary"><div>Physical units to retrieve: <strong class="ics31-total">0</strong></div><div>Equipment on Teacher Record afterward: <strong class="ics31-left"><?=array_sum(array_map(static fn($s)=>$s['is_equipment']?$s['remaining_qty']:0,$ics12States))?></strong></div></div>
<div class="ics31-error" role="alert" aria-live="polite"></div>
<div class="ics31-actions"><label for="reason-<?=$ics12Open?>">Verification notes *</label><input class="ics31-note" id="reason-<?=$ics12Open?>" name="reason" maxlength="500" placeholder="What was inspected, changes, reference number..." required></div>
<div class="ics31-actions" style="margin-top:14px">
<?php if($ics12HasEquipment&&$ics12Unresolved):?><button type="button" class="legacy-028 ics31-submit" data-operation="retrieve">Review physical retrieval</button>
<?php elseif($ics12OnlySupply):?><button type="button" class="legacy-028 ics31-submit" data-operation="close_supply">Review administrative closure</button>
<?php else:?><button type="button" class="legacy-028 ics31-submit" data-operation="close_supply">Review final administrative closure</button><?php endif;?></div>
</form><?php endif;?>
<?php if($ics12ReturnHistory):?><details class="ics12-history"><summary>Previous retrievals and verification notes (<?=count($ics12ReturnHistory)?>)</summary><ul><?php foreach($ics12ReturnHistory as $entry):?><li><strong><?=e($entry['returned_at'])?></strong> · <?=e($entry['verifier'])?><div><?=e($entry['reason'])?></div></li><?php endforeach;?></ul></details><?php endif;?>
</div>
<?php endif;?>
</section>
</div>
<dialog id="ics31Confirm" class="ics31-dialog" aria-labelledby="ics31ConfirmTitle"><h3 id="ics31ConfirmTitle">Verify retrieval</h3><div id="ics31ConfirmBody"></div><p class="ics31-warn">This action saves physical custody and cannot be undone from this screen. Check the items before confirming.</p><div class="ics31-actions"><button type="button" class="p2-btn" id="ics31Cancel">Cancel</button><button type="button" class="legacy-028" id="ics31Accept">Confirm and save</button></div></dialog>
<script>
(()=>{const modal=document.getElementById('ics31Confirm'),panel=document.getElementById('ics31ConfirmBody');let selected=null;
const validInt=v=>/^\d+$/.test(v)&&Number.isSafeInteger(Number(v));
function check(form,operation){let total=0,left=0,errors=[];const items=[];form.querySelectorAll('.ics31-line').forEach(row=>{if(row.dataset.equipment!=='1')return;const max=Number(row.dataset.limit);let counts=[0,0,0];const inputs=[...row.querySelectorAll('.ics31-input')];inputs.forEach((input,k)=>{input.classList.remove('ics31-bad');if(input.disabled){if(max>0)errors.push('Verify the historical condition balance for '+row.dataset.item+' first.');return;}if(!validInt(input.value)){errors.push('Use nonnegative whole numbers for '+row.dataset.item+'.');input.classList.add('ics31-bad');}counts[k]=Number(input.value)||0;});const sum=counts.reduce((a,b)=>a+b,0);const after=row.querySelector('.ics31-after-count');if(after)after.textContent=String(Math.max(0,max-sum));if(sum>max){errors.push(row.dataset.item+': '+sum+' entered, only '+max+' remain.');inputs.forEach(i=>i.classList.add('ics31-bad'));}const batch=row.querySelector('select[name^="return_batch"]');if(sum>0&&batch){const opt=batch.selectedOptions[0];if(!batch.value||!opt||opt.disabled)errors.push('Select an available source batch for '+row.dataset.item+'.');else if(sum>Number(opt.dataset.capacity||0))errors.push('Selected batch cannot supply '+sum+' returned unit(s) of '+row.dataset.item+'.');}total+=sum;left+=max-sum;if(sum>0)items.push({name:row.dataset.item,counts,sum});});form.querySelector('.ics31-total').textContent=total;form.querySelector('.ics31-left').textContent=Math.max(0,left);if(operation==='retrieve'&&total===0)errors.push('Enter at least one physical return.');if(operation==='close_supply'&&left>0)errors.push('Equipment must be retrieved before administrative closure.');if(form.elements.reason.value.trim().length<5)errors.push('Add at least 5 characters of verification notes.');form.querySelector('.ics31-error').textContent=errors[0]||'';return {ok:errors.length===0,items,total,left};}
document.querySelectorAll('.ics31-form').forEach(form=>{form.querySelectorAll('input,select').forEach(el=>{el.addEventListener('input',()=>check(form,form.querySelector('.ics31-operation').value));el.addEventListener('change',()=>check(form,form.querySelector('.ics31-operation').value));});form.querySelectorAll('.ics31-submit').forEach(btn=>btn.addEventListener('click',()=>{const op=btn.dataset.operation;form.querySelector('.ics31-operation').value=op;const result=check(form,op);if(!result.ok)return;selected=form;panel.replaceChildren();const heading=document.createElement('p');heading.textContent=form.dataset.ics+' — '+(op==='close_supply'?'Close distributed supplies; no physical items returned':'Retrieve '+result.total+' item(s); '+result.left+' remain(s) on the teacher record');panel.append(heading);const ul=document.createElement('ul');result.items.forEach(item=>{const li=document.createElement('li');li.textContent=item.name+': '+item.counts[0]+' serviceable, '+item.counts[1]+' repair, '+item.counts[2]+' damaged';ul.append(li);});panel.append(ul);modal.showModal();}));});
document.getElementById('ics31Cancel').addEventListener('click',()=>modal.close());document.getElementById('ics31Accept').addEventListener('click',()=>{if(!selected)return;const result=check(selected,selected.querySelector('.ics31-operation').value);if(!result.ok){modal.close();return;}const submit=document.getElementById('ics31Accept');submit.disabled=true;const hidden=document.createElement('input');hidden.type='hidden';hidden.name='confirmed';hidden.value='1';selected.append(hidden);selected.submit();});})();
</script>
<?php include __DIR__.'/includes/footer.php';
