<?php
/** Read-only review of immutable Phase 7.2 maintenance events. */
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=require_role(['supply_officer','tvl_head','teacher']);
$uid=(int)$user['id'];$teacher=$user['role']==='teacher';
$query="SELECT ev.*,i.item_name,i.unit,c.ics_number,c.teacher_id,holder.full_name AS teacher_name,
 actor.full_name AS actor_name,rb.batch_number,rb.batch_id AS source_batch_id,d.receiving_number
 FROM maintenance_events ev JOIN ics_items ii ON ii.ics_item_id=ev.ics_item_id
 JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id
 LEFT JOIN users holder ON holder.user_id=c.teacher_id
 LEFT JOIN users actor ON actor.user_id=ev.actor_user_id
 LEFT JOIN ics_batch_allocations a ON a.allocation_id=ev.allocation_id
 LEFT JOIN inventory_receipt_batches rb ON rb.batch_id=a.batch_id
 LEFT JOIN delivery_items di ON di.delivery_item_id=rb.delivery_item_id
 LEFT JOIN deliveries d ON d.delivery_id=di.delivery_id";
if($teacher)$query.=' WHERE c.teacher_id=?';
$query.=' ORDER BY ev.created_at DESC,ev.event_id DESC LIMIT 5000';
$st=db()->prepare($query);$st->execute($teacher?[$uid]:[]);$events=$st->fetchAll(PDO::FETCH_ASSOC);
$actions=['for_repair'=>'Needs repair','damaged'=>'Damage reported','damaged_to_repair'=>'Damaged to repair','repair_to_damaged'=>'Repair unsuccessful','serviceable'=>'Repair completed'];
$pageTitle='Maintenance Event History';$active='item_maintenance.php';require __DIR__.'/includes/header.php';
?>
<div class="content-header"><div><h2>Maintenance Event History</h2><p>Search permanent equipment condition updates. Source batches are declared when known, not automatically assumed.</p></div></div>
<section class="card p72-history" id="maintenanceHistory">
 <div class="p72-history-filters">
  <label>Search item, teacher, ICS, batch or notes<input type="search" id="p72-history-search" class="input" placeholder="Search event history"></label>
  <label>Action<select id="p72-history-action" class="input"><option value="">All actions</option><?php foreach($actions as $code=>$label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label>Order<select id="p72-history-order" class="input"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label>
 </div>
 <p id="p72-history-count" class="field-help" aria-live="polite"></p>
 <div class="p72-history-list" id="p72-history-list">
 <?php foreach($events as $event):
  $source=$event['allocation_id']?($event['receiving_number'].' · '.($event['batch_number']?:'Batch #'.$event['source_batch_id'])):'Unknown / not sure';
  $search=implode(' ',[$event['item_name'],$event['ics_number'],$event['teacher_name'],$event['actor_name'],$source,$event['description'],$event['action_code']]);
 ?>
  <article class="p72-history-event" id="maintenance-event-<?= (int)$event['event_id'] ?>" data-event-id="<?= (int)$event['event_id'] ?>" data-action="<?= e($event['action_code']) ?>" data-search="<?= e(mb_strtolower($search)) ?>" data-sort="<?= e($event['created_at'].'-'.str_pad((string)$event['event_id'],12,'0',STR_PAD_LEFT)) ?>">
   <div class="p72-history-head"><div><strong><?= e($event['item_name']) ?> · <?= e($event['ics_number']) ?></strong><small><?= e($event['teacher_name']) ?> · <?= (int)$event['quantity'] ?> <?= e($event['unit']) ?></small></div><span class="p72-history-state"><?= e($actions[$event['action_code']]??$event['action_code']) ?></span></div>
   <div class="p72-history-transition"><?= e(str_replace('_',' ',$event['from_condition'])) ?> → <?= e(str_replace('_',' ',$event['to_condition'])) ?></div>
   <p><?= nl2br(e($event['description'])) ?></p>
   <div class="p72-history-meta"><span><?= e($event['created_at']) ?></span><span>Recorded by <?= e($event['actor_name']?:'Teacher') ?></span><span>Source: <?= e($source) ?></span><?php if($event['work_date']): ?><span>Work date <?= e($event['work_date']) ?></span><?php endif; ?></div>
   <?php if($event['photo_file']): ?><a class="p72-evidence-link" href="<?= e(url('phase7_evidence.php?kind=maintenance&id='.(int)$event['event_id'])) ?>" target="_blank" rel="noopener">View supporting photo ↗</a><?php endif; ?>
  </article>
 <?php endforeach; ?>
 </div>
 <p id="p72-history-empty" class="field-help" hidden>No matching events.</p>
 <nav class="p72-history-pages"><button type="button" class="btn btn-outline" id="p72-history-prev">Previous</button><span id="p72-history-page"></span><button type="button" class="btn btn-outline" id="p72-history-next">Next</button></nav>
</section>
<script src="<?= e(url('assets/js/phase7_history.js')) ?>"></script>
<?php require __DIR__.'/includes/footer.php'; ?>
