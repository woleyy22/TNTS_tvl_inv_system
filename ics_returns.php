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
            throw new RuntimeException('ICS return history migration is still required. Ask the administrator to run phase3_2_v57.sql before recording returns.');
        }
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM ics WHERE ics_id=? FOR UPDATE');$st->execute([$icsId]);$ics=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ics || $ics['status']!=='active') throw new RuntimeException('This ICS is no longer active. Refresh before trying again.');
        $st=$pdo->prepare('SELECT ii.ics_item_id FROM ics_items ii WHERE ii.ics_id=? ORDER BY ii.ics_item_id FOR UPDATE');
        $st->execute([$icsId]);$ids=$st->fetchAll(PDO::FETCH_COLUMN);
        $st=$pdo->prepare("SELECT COUNT(*) FROM borrow_logs b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id WHERE ii.ics_id=? AND b.status='borrowed'");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Outstanding borrowed items must be returned and verified first.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM condemnations x JOIN ics_items ii ON ii.ics_item_id=x.ics_item_id WHERE ii.ics_id=? AND x.status='requested'");$st->execute([$icsId]);
        if((int)$st->fetchColumn()) throw new RuntimeException('Resolve pending condemnation requests before retrieval or closure.');
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
            $batchRows=$pdo->prepare('SELECT allocation_id,qty_issued,qty_returned FROM ics_batch_allocations WHERE ics_item_id=? ORDER BY allocation_id FOR UPDATE');
            $batchRows->execute([(int)$u['id']]);$allocations=$batchRows->fetchAll(PDO::FETCH_ASSOC);
            if($allocations){
                $selected=null;
                foreach($allocations as $allocation){if((int)$allocation['allocation_id']===$selectedReturnAllocation){$selected=$allocation;break;}}
                if(!$selected) throw new RuntimeException('Select the original source batch being physically returned.');
                $capacity=(int)$selected['qty_issued']-(int)$selected['qty_returned'];
                if($u['qty']>$capacity) throw new RuntimeException('This return exceeds the remaining quantity for its selected batch. Record one batch per retrieval.');
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
$records=$pdo->query("SELECT c.*,u.full_name,co.course_name FROM ics c JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=c.course_id WHERE c.status='active' ORDER BY c.date_issued DESC,c.ics_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$getItems=$pdo->prepare('SELECT ii.ics_item_id FROM ics_items ii WHERE ii.ics_id=? ORDER BY ii.ics_item_id');
$batchAllocQuery=$pdo->prepare('SELECT a.allocation_id,a.qty_issued,a.qty_returned,b.batch_number,b.expiration_date FROM ics_batch_allocations a JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id WHERE a.ics_item_id=? ORDER BY a.allocation_id');

$pageTitle='Retrieve / Close ICS';$active='ics';include __DIR__.'/includes/header.php';
?>
<style>
.ics31-wrap{max-width:1150px}.ics31-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}.ics31-panel{border:1px solid #dce1e9;border-radius:13px;margin:14px 0;background:#fff;overflow:hidden}.ics31-panel summary{cursor:pointer;padding:17px 18px;font-weight:650;color:#17253d}.ics31-panel[open] summary{border-bottom:1px solid #e7e9ef}.ics31-content{padding:18px}.ics31-table-wrap{overflow-x:auto}.ics31-table{width:100%;border-collapse:collapse;min-width:760px}.ics31-table th,.ics31-table td{text-align:left;padding:10px 8px;border-bottom:1px solid #e7ecf2;font-size:.86rem;vertical-align:middle}.ics31-table th{background:#f5f7fa;color:#475569;font-size:.75rem}.ics31-muted{font-size:.79rem;color:#64748b}.ics31-input{width:76px!important;max-width:76px;padding:9px 6px;border:1px solid #cbd5e1;border-radius:7px;text-align:center}.ics31-info{padding:12px;border-radius:8px;background:#f1f5f9;color:#27364a;margin:12px 0}.ics31-warn{background:#fff4db;color:#825100;padding:10px;border-radius:7px;margin:10px 0}.ics31-error{color:#a11b1b;font-size:.83rem;font-weight:600;margin:9px 0}.ics31-bad{border-color:#c22828!important;background:#fff0f0}.ics31-summary{display:flex;flex-wrap:wrap;gap:18px;padding:13px 0}.ics31-summary strong{font-size:1.05rem;color:#17253d}.ics31-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ics31-note{width:min(100%,560px);padding:10px;border:1px solid #cbd5e1;border-radius:7px}.ics31-dialog{width:min(94vw,550px);border:0;border-radius:15px;padding:25px;box-shadow:0 16px 70px #0004}.ics31-dialog::backdrop{background:#17213299}.ics31-dialog h3{margin:0 0 12px}.ics31-dialog .ics31-actions{justify-content:flex-end;margin-top:20px}.ics31-pill{display:inline-block;border-radius:99px;padding:2px 8px;background:#e7f4eb;color:#24633b;font-size:.75rem}.ics31-pill.repair{background:#fff0d0;color:#854d0e}.ics31-pill.damaged{background:#ffe5e5;color:#a52828}
.ics31-record-conditions{display:flex;flex-direction:column;align-items:flex-start;gap:4px;margin-top:6px}
.ics31-count{font-size:.75rem;display:inline-flex;gap:3px;white-space:nowrap;border-radius:20px;padding:3px 8px;font-weight:650;background:#e7f4eb;color:#21633c}
.ics31-count.repair{background:#fff0d0;color:#854d0e}
.ics31-count.damaged{background:#ffe5e5;color:#a52828}
.ics31-record-total{color:#17253d}
.ics31-after{font-size:.74rem;margin-top:7px;color:#64748b}
.ics31-entry-grid{display:grid;grid-template-columns:repeat(3,minmax(78px,1fr));gap:7px;min-width:285px}
.ics31-return-field{display:flex;align-items:center;flex-direction:column;gap:6px;font-size:.8rem}
.ics31-return-field .ics31-count{justify-content:center;font-size:.7rem;padding:3px 7px}
.ics31-return-field .ics31-input{width:72px!important;max-width:72px}
@media(max-width:940px){.ics31-content{padding:12px}.ics31-table th,.ics31-table td{padding:9px 7px}}
</style>
<div class="ics31-wrap">
<div class="ics31-tabs"><a class="legacy-028" href="<?=e(url('ics_issue.php'))?>">Issue ICS</a><a class="legacy-028" href="<?=e(url('ics_returns.php'))?>">Retrieve / Close ICS</a></div>
<div class="page-head"><h1>Retrieve / Close ICS</h1><p>Verify the units physically received, choose their condition, and review what remains on the teacher record before confirming.</p></div>
<div class="ics31-info"><strong>How this works:</strong> Issued quantity never changes. Disposed units are already accounted for. The <strong>On Teacher Record</strong> column shows the current quantity and its recorded conditions before this retrieval. Select the condition verified at handover, even if it differs from the prior record. Distributed consumables are closed administratively, never returned to stock automatically.</div>
<?php if(!$records):?><div class="card">No active ICS records.</div><?php endif;?>
<?php foreach($records as $c):
$getItems->execute([(int)$c['ics_id']]);$lineIds=$getItems->fetchAll(PDO::FETCH_COLUMN);$states=[];$hasEquipment=false;$unresolved=false;$hasSupply=false;
foreach($lineIds as $lineId){$s=ics31_state($pdo,(int)$lineId);$batchAllocQuery->execute([(int)$lineId]);$s['batch_allocations']=$batchAllocQuery->fetchAll(PDO::FETCH_ASSOC);$states[]=$s;if($s['is_equipment']){$hasEquipment=true;$unresolved=$unresolved||$s['remaining_qty']>0;}else $hasSupply=true;}
$onlySupply= !$hasEquipment && $hasSupply;
?>
<details class="ics31-panel" <?= isset($_GET['open']) && (int)$_GET['open']===(int)$c['ics_id']?'open':'' ?>><summary><?=e($c['ics_number'])?> — <?=e($c['full_name'])?> · <?=e($c['date_issued'])?> · <?=e($c['course_name']??'')?></summary>
<div class="ics31-content"><form method="post" class="ics31-form" data-ics="<?=e($c['ics_number'])?>">
<?=csrf_field()?><input type="hidden" name="ics_id" value="<?=(int)$c['ics_id']?>"><input type="hidden" name="operation" value="retrieve" class="ics31-operation">
<div class="ics31-table-wrap"><table class="ics31-table"><thead><tr><th>Item</th><th>Originally Issued</th><th>Previously Retrieved</th><th>Disposed</th><th>On Teacher Record</th><th>Verified Return Conditions</th></tr></thead><tbody>
<?php foreach($states as $s): $id=(int)$s['ics_item_id']; ?>
<tr class="ics31-line" data-limit="<?=$s['remaining_qty']?>" data-item="<?=e($s['item_name'])?>" data-equipment="<?=$s['is_equipment']?'1':'0'?>">
<td><strong><?=e($s['item_name'])?></strong><div class="ics31-muted"><?=e(ucwords(str_replace('_',' ',$s['item_category'])))?></div><?php if($s['is_equipment'] && count($s['batch_allocations'])>0): ?><div class="ics31-muted" style="margin-top:9px"><label>Batch being returned <select name="return_batch[<?= $id ?>]" style="display:block;max-width:225px;padding:6px"><option value="">Select source batch</option><?php foreach($s['batch_allocations'] as $ba):$unreturned=(int)$ba['qty_issued']-(int)$ba['qty_returned'];?><option value="<?= (int)$ba['allocation_id'] ?>" <?= $unreturned<=0?'disabled':'' ?> <?= count($s['batch_allocations'])===1?'selected':'' ?>><?= e($ba['batch_number']?:'Batch #'.$ba['allocation_id']) ?> · <?= $unreturned ?> left · <?= e($ba['expiration_date']?:'No expiry') ?></option><?php endforeach;?></select></label></div><?php endif; ?>
<?php if($s['needs_review']):?><div class="ics31-warn">Balance needs review. <a href="<?=e(url('ics_reconcile.php?ics_item_id='.$id))?>">Verify now</a></div><?php endif;?></td>
<td><?=(int)$s['qty_issued']?> <?=e($s['unit'])?></td>
<td><?=(int)$s['qty_returned']?></td>
<td><?=$s['disposed_qty']?></td>
<td>
<?php if($s['is_equipment']):?>
<strong class="ics31-record-total"><?=$s['remaining_qty']?> <?=e($s['unit'])?></strong>
<div class="ics31-record-conditions" aria-label="Currently on teacher record by condition">
<span class="ics31-count svc">Serviceable: <strong><?=$s['qty_serviceable']?></strong></span>
<span class="ics31-count repair">For Repair: <strong><?=$s['qty_for_repair']?></strong></span>
<span class="ics31-count damaged">Damaged: <strong><?=$s['qty_damaged']?></strong></span>
</div>
<div class="ics31-after">After planned retrieval: <strong class="ics31-after-count"><?=$s['remaining_qty']?></strong></div>
<?php else:?><strong>Distributed</strong><div class="ics31-muted">No physical retrieval required</div><?php endif;?></td>
<td><?php if($s['is_equipment']):?>
<div class="ics31-entry-grid">
<?php foreach([['field'=>'svc','title'=>'Serviceable','cls'=>'svc'],['field'=>'repair','title'=>'For Repair','cls'=>'repair'],['field'=>'damaged','title'=>'Damaged','cls'=>'damaged']] as $returnField):?>
<label class="ics31-return-field"><span class="ics31-count <?=e($returnField['cls'])?>"><?=e($returnField['title'])?></span>
<input class="ics31-input" name="<?=e($returnField['field'])?>[<?=$id?>]" type="number" inputmode="numeric" min="0" max="<?=$s['remaining_qty']?>" step="1" value="0" <?= $s['needs_review']||$s['remaining_qty']===0?'disabled':''?> aria-label="<?=e($s['item_name'].' returned as '.$returnField['title'])?>"></label>
<?php endforeach;?></div>
<?php else:?><span class="ics31-muted">Not applicable to distributed supplies</span><?php endif;?></td>
</tr>
<?php endforeach;?></tbody></table></div>
<div class="ics31-summary"><div>Physical units to retrieve: <strong class="ics31-total">0</strong></div><div>Equipment on Teacher Record afterward: <strong class="ics31-left"><?=array_sum(array_map(fn($s)=>$s['is_equipment']?$s['remaining_qty']:0,$states))?></strong></div></div>
<div class="ics31-error" role="alert" aria-live="polite"></div>
<div class="ics31-actions"><label for="reason-<?=$c['ics_id']?>">Verification notes <span aria-hidden="true">*</span></label><input class="ics31-note" id="reason-<?=$c['ics_id']?>" name="reason" maxlength="500" placeholder="What was physically inspected, condition changes, reference..." required></div>
<p><a target="_blank" rel="noopener" href="<?=e(url('print_ics.php?id='.(int)$c['ics_id']))?>">View original signed-format ICS (Appendix 59)</a></p>
<div class="ics31-actions">
<?php if($hasEquipment && $unresolved):?><button type="button" class="legacy-028 ics31-submit" data-operation="retrieve">Review physical retrieval</button>
<?php elseif($onlySupply):?><button type="button" class="legacy-028 ics31-submit" data-operation="close_supply">Review administrative closure</button>
<?php else:?><button type="button" class="legacy-028 ics31-submit" data-operation="close_supply">Review final administrative closure</button><?php endif;?></div>
</form></div></details>
<?php endforeach;?></div>
<dialog id="ics31Confirm" class="ics31-dialog" aria-labelledby="ics31ConfirmTitle"><h3 id="ics31ConfirmTitle">Verify retrieval</h3><div id="ics31ConfirmBody"></div><p class="ics31-warn">This action saves physical custody and cannot be undone from this screen. Check the items before confirming.</p><div class="ics31-actions"><button type="button" class="p2-btn" id="ics31Cancel">Cancel</button><button type="button" class="legacy-028" id="ics31Accept">Confirm and save</button></div></dialog>
<script>
(()=>{const modal=document.getElementById('ics31Confirm'),panel=document.getElementById('ics31ConfirmBody');let selected=null;
const validInt=v=>/^\d+$/.test(v)&&Number.isSafeInteger(Number(v));
function check(form,operation){let total=0,left=0,errors=[];const items=[];form.querySelectorAll('.ics31-line').forEach(row=>{if(row.dataset.equipment!=='1')return;const max=Number(row.dataset.limit);let counts=[0,0,0];const inputs=[...row.querySelectorAll('.ics31-input')];inputs.forEach((input,k)=>{input.classList.remove('ics31-bad');if(input.disabled){if(max>0)errors.push('Verify the historical condition balance for '+row.dataset.item+' first.');return;}if(!validInt(input.value)){errors.push('Use nonnegative whole numbers for '+row.dataset.item+'.');input.classList.add('ics31-bad');}counts[k]=Number(input.value)||0;});const sum=counts.reduce((a,b)=>a+b,0);const after=row.querySelector('.ics31-after-count');if(after)after.textContent=String(Math.max(0,max-sum));if(sum>max){errors.push(row.dataset.item+': '+sum+' entered, only '+max+' remain.');inputs.forEach(i=>i.classList.add('ics31-bad'));}total+=sum;left+=max-sum;if(sum>0)items.push({name:row.dataset.item,counts,sum});});form.querySelector('.ics31-total').textContent=total;form.querySelector('.ics31-left').textContent=Math.max(0,left);if(operation==='retrieve'&&total===0)errors.push('Enter at least one physical return.');if(operation==='close_supply'&&left>0)errors.push('Equipment must be retrieved before administrative closure.');if(form.elements.reason.value.trim().length<5)errors.push('Add at least 5 characters of verification notes.');form.querySelector('.ics31-error').textContent=errors[0]||'';return {ok:errors.length===0,items,total,left};}
document.querySelectorAll('.ics31-form').forEach(form=>{form.querySelectorAll('input').forEach(el=>el.addEventListener('input',()=>check(form,form.querySelector('.ics31-operation').value)));form.querySelectorAll('.ics31-submit').forEach(btn=>btn.addEventListener('click',()=>{const op=btn.dataset.operation;form.querySelector('.ics31-operation').value=op;const result=check(form,op);if(!result.ok)return;selected=form;panel.replaceChildren();const heading=document.createElement('p');heading.textContent=form.dataset.ics+' — '+(op==='close_supply'?'Close distributed supplies; no physical items returned':'Retrieve '+result.total+' item(s); '+result.left+' remain(s) on the teacher record');panel.append(heading);const ul=document.createElement('ul');result.items.forEach(item=>{const li=document.createElement('li');li.textContent=item.name+': '+item.counts[0]+' serviceable, '+item.counts[1]+' repair, '+item.counts[2]+' damaged';ul.append(li);});panel.append(ul);modal.showModal();}));});
document.getElementById('ics31Cancel').addEventListener('click',()=>modal.close());document.getElementById('ics31Accept').addEventListener('click',()=>{if(!selected)return;const result=check(selected,selected.querySelector('.ics31-operation').value);if(!result.ok){modal.close();return;}const submit=document.getElementById('ics31Accept');submit.disabled=true;const hidden=document.createElement('input');hidden.type='hidden';hidden.name='confirmed';hidden.value='1';selected.append(hidden);selected.submit();});})();
</script>
<?php include __DIR__.'/includes/footer.php';
