<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/phase7_reconciliation.php';
$user=require_role(['supply_officer','tvl_head']);
$result=p74_reconcile(db());$sum=$result['summary'];
$pageTitle='Phase 7 Reconciliation';$active='reports';
require_once __DIR__.'/includes/header.php';
?>
<div class="content-header p74-title"><div>
  <h2>Equipment & Custody Reconciliation</h2>
  <p>Read-only checks across ICS conditions, borrowings, transfers, condemnation requests and known receiving batches. Nothing is changed automatically.</p>
</div><a class="btn btn-outline" href="<?=e(url('reports.php'))?>">Back to Reports</a></div>
<section class="p74-summary" aria-label="Reconciliation summary">
  <div class="card"><small>Equipment ICS lines</small><strong><?=number_format($sum['equipment_lines'])?></strong></div>
  <div class="card"><small>Condemnation records</small><strong><?=number_format($sum['cf_records'])?></strong></div>
  <div class="card"><small>Blocking discrepancies</small><strong class="p74-red"><?=number_format($sum['blockers'])?></strong></div>
  <div class="card"><small>Records to review</small><strong class="p74-amber"><?=number_format($sum['review'])?></strong></div>
</section>
<section class="card p74-audit" aria-labelledby="p74-audit-title">
 <div class="record-section-header"><div><h3 id="p74-audit-title">Reconciliation findings</h3>
 <p>Resolve blocking differences before physical disposal, return or transfer. Documentation notices need staff review, not automated edits.</p></div></div>
 <div class="p74-controls">
 <label for="p74-search">Search ICS, equipment, teacher or issue<input class="input" id="p74-search" type="search" placeholder="Search findings..." autocomplete="off"></label>
 <label for="p74-severity">Severity<select id="p74-severity" class="input"><option value="">All findings</option><option value="blocker">Blocking discrepancy</option><option value="review">Needs review</option></select></label>
 </div>
 <p id="p74-count" class="record-results" aria-live="polite"></p>
 <div class="p74-table-wrap"><table class="table p74-table"><thead><tr><th>Severity</th><th>ICS / Custodian</th><th>Equipment</th><th>Finding</th><th>View record</th></tr></thead><tbody>
 <?php foreach($result['issues'] as $issue): ?>
 <tr class="p74-finding" data-severity="<?=e($issue['level'])?>" data-search="<?=e(strtolower(implode(' ',[$issue['ics'],$issue['teacher'],$issue['item'],$issue['message'],$issue['code']])))?>">
  <td data-label="Severity"><span class="p74-label p74-label-<?=e($issue['level'])?>"><?= $issue['level']==='blocker'?'Blocking':'Review' ?></span></td>
  <td data-label="ICS / Custodian"><strong><?=e($issue['ics'])?></strong><small><?=e($issue['teacher'])?></small></td>
  <td data-label="Equipment"><strong><?=e($issue['item'])?></strong></td>
  <td data-label="Finding"><?=e($issue['message'])?></td>
  <td data-label="View record">
  <?php if(str_starts_with($issue['code'],'cf_') || in_array($issue['code'],['authorization_metadata','disposal_metadata','disposal_before_authorization'],true)): ?>
   <a class="btn btn-outline" href="<?=e(url('condemnation.php'))?>">View CF records</a>
  <?php elseif($user['role']==='supply_officer' && $issue['code']==='flagged_balance'): ?>
   <a class="btn btn-outline" href="<?=e(url('ics_reconcile.php?ics_item_id='.(int)$issue['ics_item_id']))?>">Condition review</a>
  <?php elseif($user['role']==='supply_officer'): ?>
   <a class="btn btn-outline" href="<?=e(url('ics_returns.php?search='.rawurlencode($issue['ics'])))?>">Open ICS record</a>
  <?php else: ?>
   <a class="btn btn-outline" href="<?=e(url('inventory.php?view=issued&search='.rawurlencode($issue['ics'])))?>">View inventory</a>
  <?php endif; ?>
  </td>
 </tr>
 <?php endforeach;?></tbody></table></div>
 <p id="p74-empty" class="p74-empty" <?= $result['issues']?'hidden':'' ?>><?= $result['issues']?'No findings match your filters.':'No exceptions found in the current database.' ?></p>
</section>
<p class="p74-footnote">For flagged historical records, confirm physical custody and supporting paperwork before editing any balances. The TVL Head has view-only access.</p>
<script src="<?=e(url('assets/js/phase7_reconciliation.js'))?>" defer></script>
<?php require_once __DIR__.'/includes/footer.php';?>
