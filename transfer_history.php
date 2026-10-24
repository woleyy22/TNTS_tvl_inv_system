<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=require_role(['teacher','supply_officer','tvl_head']);
$pdo=db();$uid=(int)$user['id'];$role=$user['role'];
$search=trim((string)($_GET['search']??''));$status=(string)($_GET['status']??'');
$direction=strtolower((string)($_GET['order']??'desc'))==='asc'?'ASC':'DESC';
$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
$valid=['requested','accepted','awaiting_finalization','declined','rejected','cancelled','completed'];
if(!in_array($status,$valid,true))$status='';
$conditions=[];$params=[];
if($role==='teacher'){$conditions[]='(r.from_teacher_id=? OR r.to_teacher_id=?)';array_push($params,$uid,$uid);}
if($search!==''){$conditions[]='(inv.item_name LIKE ? OR sender.full_name LIKE ? OR recipient.full_name LIKE ? OR source_ics.ics_number LIKE ? OR recipient_ics.ics_number LIKE ?)';$term='%'.$search.'%';for($i=0;$i<5;$i++)$params[]=$term;}
if($status!==''){$conditions[]='r.status=?';$params[]=$status;}
foreach([['from',$from,'>='],['to',$to,'<=']] as $f){if($f[1]!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$f[1])){$conditions[]='DATE(r.created_at) '.$f[2].' ?';$params[]=$f[1];}}
$sql="SELECT r.*,inv.item_name, inv.unit, sender.full_name AS sender_name, recipient.full_name AS recipient_name,
 source_ics.ics_number AS source_ics_no, source_ics.ics_id AS source_ics_id, recipient_ics.ics_number AS recipient_ics_no, recipient_ics.ics_id AS recipient_ics_id,
 b.batch_number,b.expiration_date,m.movement_id,m.created_at AS finalized_at
 FROM transfer_requests r
 JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id
 JOIN inventory_items inv ON inv.item_id=ii.item_id
 JOIN ics source_ics ON source_ics.ics_id=ii.ics_id
 JOIN users sender ON sender.user_id=r.from_teacher_id
 JOIN users recipient ON recipient.user_id=r.to_teacher_id
 LEFT JOIN ics_batch_allocations a ON a.allocation_id=r.allocation_id
 LEFT JOIN inventory_receipt_batches b ON b.batch_id=a.batch_id
 LEFT JOIN ics_transfer_movements m ON m.transfer_request_id=r.request_id
 LEFT JOIN ics_items recipient_line ON recipient_line.ics_item_id=m.recipient_ics_item_id
 LEFT JOIN ics recipient_ics ON recipient_ics.ics_id=recipient_line.ics_id";
if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);
$sql.=' ORDER BY r.created_at '.$direction.', r.request_id '.$direction.' LIMIT 300';
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$events=[];if($rows){$ids=array_map('intval',array_column($rows,'request_id'));$q=$pdo->prepare('SELECT ev.*,u.full_name AS actor FROM transfer_request_events ev JOIN users u ON u.user_id=ev.actor_user_id WHERE ev.transfer_request_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY ev.occurred_at,ev.event_id');$q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $event){$events[(int)$event['transfer_request_id']][]=$event;}}
$pageTitle='Transfer History';$active='transfer_history.php';include __DIR__.'/includes/header.php';
?>
<div class="page-head"><h1>Transfer History</h1><p>Search requests, review decisions, and open linked original or recipient ICS records. Read-only.</p></div>
<div class="card"><form method="get" class="p54-filters">
<label>Search name, item, or ICS<input name="search" value="<?= e($search) ?>" placeholder="Teacher, item or ICS number"></label>
<label>Status<select name="status"><option value="">All statuses</option><?php foreach($valid as $v):?><option value="<?= e($v) ?>" <?= $status===$v?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$v))) ?></option><?php endforeach; ?></select></label>
<label>Requested from<input type="date" name="from" value="<?= e($from) ?>"></label><label>Requested to<input type="date" name="to" value="<?= e($to) ?>"></label>
<label>Order<select name="order"><option value="desc" <?= $direction==='DESC'?'selected':'' ?>>Newest first</option><option value="asc" <?= $direction==='ASC'?'selected':'' ?>>Oldest first</option></select></label>
<button type="submit" class="btn btn-primary">Apply filters</button><a class="btn" href="transfer_history.php">Reset</a>
</form></div>
<style>
/* Details are their own full-width table row, not squeezed into an action cell. */
.t18-history-card{min-width:0;max-width:100%;overflow:hidden}
.t18-history-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.t18-history-head>div{display:grid;gap:4px}
.t18-history-head strong{font-size:.94rem;color:#293650}.t18-history-head small{color:#69788d;font-size:.76rem}
.t18-history-head label{display:grid;gap:4px;font-size:.72rem;color:#5f6e81;font-weight:650}
.t18-history-head select{padding:8px 11px;border-radius:8px;border:1px solid #d4dce8;background:#fff;color:#24344e}
.t18-history-scroll{width:100%;max-width:100%;overflow-x:auto;border-radius:11px}
.t18-history{table-layout:fixed;width:100%;min-width:0;border-collapse:collapse;font-size:.8rem}
.t18-history th,.t18-history td{padding:12px 10px;text-align:left;vertical-align:middle;border-bottom:1px solid #e9eef3;white-space:normal;overflow-wrap:anywhere}
.t18-history th{background:#f4f6fa;color:#66738b;font-size:.66rem;letter-spacing:.035em;text-transform:uppercase}
.t18-history th button{border:0;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer;text-transform:inherit;letter-spacing:inherit}
.t18-history th button:hover{color:#8a1b40}
.t18-history .t18-muted{font-size:.73rem;color:#64748b;line-height:1.5}
.t18-history .t18-stack{display:grid;gap:4px}.t18-history .t18-main{font-weight:750;color:#22314b}
.t18-history-toggle{border:1px solid #e7c3cf;border-radius:8px;padding:8px 10px;color:#842341;background:#fff;cursor:pointer;font-weight:750;font-size:.73rem;white-space:normal}
.t18-history-toggle:hover,.t18-history-toggle[aria-expanded=true]{background:#fff0f5;border-color:#9b3455}
.t18-detail-row[hidden],.t18-history-main[hidden]{display:none!important}
.t18-detail-row>td{padding:0!important;border-bottom:1px solid #dbe2eb;background:#f8f9fc}
.t18-detail-panel{padding:18px 20px;margin:0;border-left:3px solid #851a3f;display:grid;gap:15px;color:#293b52;min-width:0}
.t18-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}
.t18-detail-grid>div{display:grid;gap:5px;min-width:0}
.t18-detail-label{font-size:.72rem;font-weight:700;color:#657389}
.t18-detail-value{font-size:.82rem;color:#26384f;overflow-wrap:anywhere}
.t18-detail-notes{border:1px solid #e5e7ef;padding:11px 13px;border-radius:8px;background:#fff;font-size:.82rem;line-height:1.5;overflow-wrap:anywhere}
.t18-events{display:grid;gap:9px}.t18-events h3{font-size:.83rem;margin:0}
.t18-event{border:1px solid #e3e8f0;border-radius:10px;background:#fff;padding:11px 14px;display:grid;gap:6px;font-size:.79rem;overflow-wrap:anywhere}
.t18-event-head{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:6px}
.t18-event-head strong{color:#7e1839}.t18-event-author{font-weight:750;color:#25344b}
.t18-event-note{color:#52637b;line-height:1.5;white-space:pre-wrap;font-style:italic}
.t18-history-pages{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:15px;font-size:.77rem;color:#65758a}
.t18-history-pages>div{display:flex;gap:8px}
.t18-history-pages button{padding:9px 12px;border:1px solid #dbe2e9;color:#7e1c39;border-radius:8px;background:#fff;font-weight:650;cursor:pointer}
.t18-history-pages button:disabled{opacity:.45;cursor:not-allowed}
@media(max-width:760px){.t18-history{min-width:690px}.t18-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:420px){.t18-detail-grid{grid-template-columns:minmax(0,1fr)}.t18-detail-panel{padding:12px}}
</style>
<div class="card t18-history-card">
 <div class="t18-history-head">
   <div><strong>Transfer records</strong><small><?= count($rows) ?> matching requests loaded (up to 300). Transfer-origin ICS records do not represent additional central stock issues.</small></div>
   <label>Rows per page<select id="t18HistorySize"><option value="10">10 rows</option><option value="25">25 rows</option><option value="50">50 rows</option><option value="all">All loaded</option></select></label>
 </div>
 <div class="t18-history-scroll"><table class="t18-history" aria-label="Transfer records and history"><thead><tr>
   <?php foreach ([['date','Requested'],['item','Item / Qty'],['people','From → To'],['ics','ICS References'],['status','Status']] as [$key,$label]): ?>
   <th><button type="button" data-t18-sort="<?= e($key) ?>" data-direction="none"><?= e($label) ?> <span aria-hidden="true" class="t18-sort-indicator">–</span></button></th>
   <?php endforeach; ?><th>Details</th>
 </tr></thead><tbody id="t18HistoryBody">
 <?php foreach($rows as $r):$id=(int)$r['request_id'];$recordEvents=$events[$id]??[]; ?>
 <tr class="t18-history-main" data-t18-main data-date="<?= e($r['created_at']) ?>" data-item="<?= e($r['item_name']) ?>" data-people="<?= e($r['sender_name'].' '.$r['recipient_name']) ?>" data-ics="<?= e($r['source_ics_no'].' '.($r['recipient_ics_no']??'')) ?>" data-status="<?= e($r['status']) ?>" data-id="<?= $id ?>">
  <td><div class="t18-stack"><span class="t18-main">#<?= $id ?></span><span class="t18-muted"><?= e($r['created_at']) ?></span></div></td>
  <td><div class="t18-stack"><span class="t18-main"><?= e($r['item_name']) ?></span><span class="t18-muted"><?= (int)$r['quantity'] ?> <?= e($r['unit']?:'') ?></span></div></td>
  <td><span class="t18-main"><?= e($r['sender_name']) ?></span><div class="t18-muted">→ <?= e($r['recipient_name']) ?></div></td>
  <td><div class="t18-stack"><span class="t18-main"><?= e($r['source_ics_no']) ?></span><?php if($r['recipient_ics_no']): ?><span class="t18-muted">Recipient: <?= e($r['recipient_ics_no']) ?></span><?php endif; ?></div></td>
  <td><span class="tnts-grid-pill tnts-grid-pill--<?= e($r['status']) ?>"><?= e($role==='teacher' && $r['status']==='accepted'?'Awaiting Supply Officer approval':ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
  <td><button type="button" class="t18-history-toggle" data-t18-toggle="<?= $id ?>" aria-controls="t18HistoryDetails<?= $id ?>" aria-expanded="false">View details ▾</button></td>
 </tr>
 <tr class="t18-detail-row" data-t18-detail="<?= $id ?>" id="t18HistoryDetails<?= $id ?>" hidden><td colspan="6">
  <section class="t18-detail-panel" aria-label="Details for transfer #<?= $id ?>">
   <div class="t18-detail-grid">
    <div><span class="t18-detail-label">Property / quantity</span><strong class="t18-detail-value"><?= e($r['item_name']) ?> · <?= (int)$r['quantity'] ?> <?= e($r['unit']?:'unit(s)') ?></strong></div>
    <div><span class="t18-detail-label">From (ICS holder)</span><strong class="t18-detail-value"><?= e($r['sender_name']) ?></strong></div>
    <div><span class="t18-detail-label">Receiving teacher</span><strong class="t18-detail-value"><?= e($r['recipient_name']) ?></strong></div>
    <div><span class="t18-detail-label">Original ICS</span><strong class="t18-detail-value"><?= e($r['source_ics_no']) ?></strong></div>
    <div><span class="t18-detail-label">Recipient ICS</span><strong class="t18-detail-value"><?= e($r['recipient_ics_no']?:'–') ?></strong></div>
    <div><span class="t18-detail-label">Source batch</span><span class="t18-detail-value"><?= e($r['batch_number']?:($r['legacy_unattributed']?'Unattributed historical stock':'Not specified')) ?></span></div>
    <div><span class="t18-detail-label">Unused / consumed declarations</span><span class="t18-detail-value"><?= $r['declared_unused_qty']===null?'–':(int)$r['declared_unused_qty'] ?> / <?= $r['declared_consumed_qty']===null?'–':(int)$r['declared_consumed_qty'] ?></span></div>
    <div><span class="t18-detail-label">Expiry date</span><span class="t18-detail-value"><?= e($r['expiration_date']?:'–') ?></span></div>
    <div><span class="t18-detail-label">Current status</span><span class="t18-detail-value"><?= e($role==='teacher' && $r['status']==='accepted'?'Awaiting Supply Officer approval — verification and finalization required':ucwords(str_replace('_',' ',$r['status']))) ?></span></div>
    <div><span class="t18-detail-label">Transfer method</span><strong class="t18-detail-value"><?= ($r['transfer_method']??'teacher')==='assisted'?'Supply Officer-Assisted':'Teacher-to-Teacher' ?></strong></div>
    <?php if(($r['transfer_method']??'teacher')==='assisted'): ?>
    <div><span class="t18-detail-label">Physical handover confirmation</span><span class="t18-detail-value"><?= e(fmt_datetime($r['handover_confirmed_at'])) ?></span></div>
    <?php endif; ?>
   </div>
   <div class="t18-detail-notes"><span class="t18-detail-label">Reason for transfer</span><div><em><?= e($r['reason']?:'No reason recorded') ?></em></div></div>
   <?php if(($r['transfer_method']??'teacher')==='assisted'): ?>
   <div class="t18-detail-notes"><span class="t18-detail-label">Supply Officer handover remarks</span><div><em><?= e($r['handover_remarks']?:'No additional remarks.') ?></em></div>
   <?php if(!empty($r['handover_photo_path'])): ?><p><a href="<?= e(url('transfer_evidence.php?request_id='.$id)) ?>" target="_blank" rel="noopener noreferrer">View optional handover photo ↗</a></p><?php endif; ?>
   </div>
   <?php endif; ?>
   <div class="t18-events"><h3>Action history &amp; remarks (<?= count($recordEvents) ?>)</h3>
    <?php foreach($recordEvents as $event): ?>
      <div class="t18-event"><div class="t18-event-head"><strong><?= e(ucwords(str_replace('_',' ',$event['event_type']))) ?></strong><span class="t18-muted"><?= e($event['occurred_at']) ?></span></div><div>By <span class="t18-event-author"><?= e($event['actor']) ?></span></div>
      <?php if(trim((string)($event['notes']??''))!==''): ?><div class="t18-event-note">“<?= e($event['notes']) ?>”</div><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if(!$recordEvents): ?><p class="t18-muted">No decision events recorded.</p><?php endif; ?>
   </div>
  </section>
 </td></tr>
 <?php endforeach; ?>
 <?php if(!$rows): ?><tr><td colspan="6" class="tnts-grid-empty">No matching transfers.</td></tr><?php endif; ?>
 </tbody></table></div>
 <div class="t18-history-pages"><span id="t18HistoryCount"></span><div><button type="button" id="t18HistoryPrev">← Previous</button><button type="button" id="t18HistoryNext">Next →</button></div></div>
</div>
<script>
(function(){
 const tbody=document.getElementById('t18HistoryBody'),select=document.getElementById('t18HistorySize'),previous=document.getElementById('t18HistoryPrev'),next=document.getElementById('t18HistoryNext'),info=document.getElementById('t18HistoryCount');
 if(!tbody)return;
 const pairs=[...tbody.querySelectorAll('tr[data-t18-main]')].map((main,index)=>({main,detail:main.nextElementSibling,index}));
 const sortButtons=[...document.querySelectorAll('[data-t18-sort]')];
 let page=1,sortKey=null,direction='none',openId=Number(new URLSearchParams(location.search).get('open'))||null;
 function render(){
   const sorted=pairs.slice();
   if(sortKey&&direction!=='none')sorted.sort((a,b)=>{const aVal=a.main.dataset[sortKey]||'',bVal=b.main.dataset[sortKey]||'';let compare=aVal.localeCompare(bVal,undefined,{numeric:true,sensitivity:'base'});return (direction==='desc'?-1:1)*compare||a.index-b.index;});
   const size=select.value==='all'?Infinity:Math.max(1,Number(select.value)||10),pages=Math.max(1,Math.ceil(sorted.length/size));page=Math.min(Math.max(1,page),pages);
   const start=Number.isFinite(size)?(page-1)*size:0;
   const frag=document.createDocumentFragment();
   sorted.forEach((pair,i)=>{
     const shown=i>=start&&i<start+size;
     pair.main.hidden=!shown;pair.detail.hidden=!shown||Number(pair.main.dataset.id)!==openId;
     pair.main.querySelector('[data-t18-toggle]').setAttribute('aria-expanded',String(!pair.detail.hidden));
     frag.append(pair.main,pair.detail);
   });
   tbody.appendChild(frag);
   info.textContent=sorted.length?'Showing '+(start+1)+'–'+Math.min(start+size,sorted.length)+' of '+sorted.length+' records':'No matching records';
   previous.disabled=page===1;next.disabled=page>=pages;
 }
 tbody.addEventListener('click',e=>{const button=e.target.closest('[data-t18-toggle]');if(!button)return;const id=Number(button.dataset.t18Toggle);openId=openId===id?null:id;render();});
 sortButtons.forEach(b=>b.addEventListener('click',()=>{const mode=b.dataset.direction==='none'?'asc':b.dataset.direction==='asc'?'desc':'none';sortButtons.forEach(item=>{item.dataset.direction='none';item.querySelector('.t18-sort-indicator').textContent='–';item.closest('th').removeAttribute('aria-sort');});b.dataset.direction=mode;b.querySelector('.t18-sort-indicator').textContent=mode==='asc'?'↑':mode==='desc'?'↓':'–';sortKey=mode==='none'?null:b.dataset.t18Sort;direction=mode;if(mode!=='none')b.closest('th').setAttribute('aria-sort',mode==='asc'?'ascending':'descending');page=1;render();}));
 if(openId){const targetIndex=pairs.findIndex(p=>Number(p.main.dataset.id)===openId);if(targetIndex>=0)page=Math.floor(targetIndex/10)+1;}
 select.addEventListener('change',()=>{page=1;render();});previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});render();
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
