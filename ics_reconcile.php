<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/ics_accountability.php';
require_once __DIR__.'/includes/maintenance_safety.php';
$me=require_role(['supply_officer']);$pdo=db();$userId=(int)$me['id'];
$error='';$itemId=(int)($_POST['ics_item_id']??$_GET['ics_item_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 try {
  $notes=trim((string)($_POST['notes']??''));
  if(strlen($notes)<8)throw new RuntimeException('Enter at least eight characters of physical verification notes.');
  $numbers=[];
  foreach(['serviceable','repair','damaged'] as $key) {
   $v=(string)($_POST[$key]??'');
   if(!ctype_digit($v)||strlen($v)>9)throw new RuntimeException('Enter a nonnegative whole-number condition quantity.');
   $numbers[$key]=(int)$v;
  }
  $pdo->beginTransaction();
  $state=ics31_state($pdo,$itemId,true);
  if(!$state['is_equipment'])throw new RuntimeException('Supply items do not have equipment conditions.');
  if(array_sum($numbers)!==$state['remaining_qty'])throw new RuntimeException('Condition totals must equal '.$state['remaining_qty'].' units currently with the teacher.');
  $availability=p71_availability($pdo,$itemId,$state);
  $serviceableHeld=(int)$availability['borrowed']+(int)$availability['reserved'];
  if($numbers['serviceable']<$serviceableHeld)
      throw new RuntimeException('Verified serviceable units cannot be fewer than '.$serviceableHeld.' borrowed/reserved units. Resolve those requests first.');
  if($numbers['damaged']<(int)$availability['pending_condemnation'])
      throw new RuntimeException('Verified damaged units cannot be fewer than '.(int)$availability['pending_condemnation'].' units held by open condemnation requests. Resolve those CFs first.');
  $before='serviceable '.$state['qty_serviceable'].', for repair '.$state['qty_for_repair'].', damaged '.$state['qty_damaged'];
  ics31_save($pdo,$itemId,$numbers,0);
  log_action($userId,'Reconciled condition balance','ICS',$state['ics_id'],$state['item_name'].': '.$before.' -> serviceable '.$numbers['serviceable'].', for repair '.$numbers['repair'].', damaged '.$numbers['damaged'].'. Verification: '.$notes);
  $pdo->commit();flash('success','Condition balance verified and saved. Original ICS and retrieval history are unchanged.');redirect('ics_returns.php');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$queue=$pdo->query("SELECT b.ics_item_id,c.ics_number,i.item_name,u.full_name,ii.qty_issued,ii.qty_returned FROM ics_condition_balances b JOIN ics_items ii ON ii.ics_item_id=b.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id WHERE b.needs_review=1 ORDER BY c.date_issued DESC")->fetchAll(PDO::FETCH_ASSOC);
$state=$itemId?ics31_state($pdo,$itemId):null;
$pageTitle='ICS Balance Review';$active='ics';require __DIR__.'/includes/header.php';
?>
<style>.v31-review{max-width:850px}.v31-review label{display:block;font-weight:600;margin:14px 0 5px}.v31-review input[type=number],.v31-review textarea{border:1px solid #cbd5e1;border-radius:8px;padding:10px;width:100%;max-width:390px}.v31-summary{display:grid;grid-template-columns:repeat(3,minmax(100px,1fr));gap:12px}.v31-summary>div{background:#f4f6fa;padding:15px;border-radius:8px}.v31-warning{padding:13px;border-radius:8px;background:#fff6db;margin:14px 0}.v31-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}</style>
<div class="v31-review"><div class="page-head"><h1>ICS Condition Balance Review</h1><p>For historical records whose repair log and retrieval conditions may not agree. Verify the physical units still held by the teacher; do not guess.</p></div>
<p><a href="<?=e(url('ics_returns.php'))?>">← Back to Retrieve / Close ICS</a></p>
<?php if($error):?><div class="v31-warning" role="alert"><?=e($error)?></div><?php endif;?>
<div class="card"><h3>Records awaiting verification</h3>
<?php if(!$queue):?><p>No historical balance discrepancies are currently flagged.</p><?php else:?><ul><?php foreach($queue as $q):?><li><a href="<?=e(url('ics_reconcile.php?ics_item_id='.(int)$q['ics_item_id']))?>"><?=e($q['ics_number'].' · '.$q['item_name'].' · '.$q['full_name'])?></a></li><?php endforeach;?></ul><?php endif;?></div>
<?php if($state):?>
<div class="card" style="margin-top:16px"><h3><?=e($state['item_name'])?></h3><p class="v31-warning">Physically verify the items still held by the teacher. The saved breakdown is only an estimate for older records.</p>
<div class="v31-summary"><div><small>Originally issued</small><h2><?=$state['qty_issued']?></h2></div><div><small>Already retrieved + disposed</small><h2><?=$state['qty_returned']+$state['disposed_qty']?></h2></div><div><small>Currently accountable</small><h2><?=$state['remaining_qty']?></h2></div></div>
<p>Estimated saved balance: <?=$state['qty_serviceable']?> serviceable · <?=$state['qty_for_repair']?> for repair · <?=$state['qty_damaged']?> damaged.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="ics_item_id" value="<?=$itemId?>">
<div class="v31-grid"><div><label for="serviceable">Verified serviceable</label><input id="serviceable" name="serviceable" type="number" min="0" step="1" value="<?=$state['qty_serviceable']?>" required></div><div><label for="repair">Verified for repair</label><input id="repair" name="repair" type="number" min="0" step="1" value="<?=$state['qty_for_repair']?>" required></div><div><label for="damaged">Verified damaged</label><input id="damaged" name="damaged" type="number" min="0" step="1" value="<?=$state['qty_damaged']?>" required></div></div>
<p>These three counts must total <strong><?=$state['remaining_qty']?></strong>.</p>
<label for="notes">Physical verification details / supporting reference</label><textarea id="notes" name="notes" rows="3" maxlength="500" required placeholder="Describe actual remaining items and evidence"></textarea><p><button class="legacy-028">Save verified balance</button></p></form></div>
<?php endif;?></div>
<?php require __DIR__.'/includes/footer.php';?>
