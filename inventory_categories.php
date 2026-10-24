<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$me=require_role(['supply_officer']);
$uid=(int)$me['id'];
$error='';$message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $action=(string)($_POST['action']??'');
    if ($action==='save_threshold') {
      $id=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT);
      $raw=trim((string)($_POST['threshold']??''));
      if (!$id || ($raw!==''&&(!ctype_digit($raw)||(int)$raw>100000000))) throw new RuntimeException('Enter a valid non-negative low-stock threshold.');
      $row=db()->prepare('SELECT low_stock_threshold FROM inventory_items WHERE item_id=?');$row->execute([$id]);$old=$row->fetchColumn();
      if($old===false) throw new RuntimeException('Inventory item not found.');
      $new=$raw===''?null:(int)$raw;
      db()->prepare('UPDATE inventory_items SET low_stock_threshold=? WHERE item_id=?')->execute([$new,$id]);
      if(($old===null?null:(int)$old)!==$new) log_action($uid,'update_low_stock','Inventory',$id,'Low-stock threshold: '.($old??'unset').' -> '.($new??'unset'));
      $message='Low-stock threshold saved.';
    } elseif ($action==='save_batch') {
      $batchId=filter_var($_POST['batch_id']??null,FILTER_VALIDATE_INT);
      $courseRaw=trim((string)($_POST['course_id']??''));
      $courseId=null;
      if($courseRaw!=='') {
        if(!ctype_digit($courseRaw)||(int)$courseRaw<1) throw new RuntimeException('Invalid course selection.');
        $courseId=(int)$courseRaw;
      }
      $batchNo=trim((string)($_POST['batch_number']??''));
      $expiry=trim((string)($_POST['expiration_date']??''));
      if(!$batchId || strlen($batchNo)>80) throw new RuntimeException('Invalid batch number.');
      if($expiry!=='') {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);
        if(!$d||$d->format('Y-m-d')!==$expiry) throw new RuntimeException('Enter a valid expiry date.');
      }
      db()->beginTransaction();
      $st=db()->prepare('SELECT b.batch_id,b.batch_number,b.expiration_date,b.course_id,i.item_name FROM inventory_receipt_batches b JOIN inventory_items i ON i.item_id=b.inventory_item_id WHERE b.batch_id=? FOR UPDATE');
      $st->execute([$batchId]);$old=$st->fetch(PDO::FETCH_ASSOC);
      if(!$old) throw new RuntimeException('Batch not found.');
      if($courseId!==null){$st=db()->prepare('SELECT 1 FROM courses WHERE course_id=?');$st->execute([$courseId]);if(!$st->fetchColumn())throw new RuntimeException('Course no longer exists.');}
      $newExpiry=$expiry?:null;$newBatch=$batchNo?:null;
      db()->prepare('UPDATE inventory_receipt_batches SET batch_number=?,expiration_date=?,course_id=? WHERE batch_id=?')->execute([$newBatch,$newExpiry,$courseId,$batchId]);
      $changes=[];
      if(($old['batch_number']??null)!==$newBatch)$changes[]='batch number';
      if(($old['expiration_date']??null)!==$newExpiry)$changes[]='expiry';
      if(($old['course_id']===null?null:(int)$old['course_id'])!==$courseId)$changes[]='course '.($old['course_id']??'none').' -> '.($courseId??'none');
      if($changes)log_action($uid,'update_receipt_batch','Inventory Batches',$batchId,$old['item_name'].' batch #'.$batchId.': '.implode(', ',$changes));
      db()->commit();
      $message='Batch metadata saved. Historical ICS records are unchanged.';
    } else throw new RuntimeException('Unknown action.');
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e->getMessage();}
}
$courses=db()->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll(PDO::FETCH_ASSOC);
$items=db()->query('SELECT item_id,item_name,item_category,unit,low_stock_threshold FROM inventory_items ORDER BY item_name,item_id')->fetchAll(PDO::FETCH_ASSOC);
$batches=db()->query('SELECT b.batch_id,b.batch_number,b.expiration_date,b.course_id,b.received_qty,i.item_id,i.item_name,i.unit,d.receiving_number,d.date_received,d.supplier_name,di.qty_accepted FROM inventory_receipt_batches b JOIN inventory_items i ON i.item_id=b.inventory_item_id JOIN delivery_items di ON di.delivery_item_id=b.delivery_item_id JOIN deliveries d ON d.delivery_id=di.delivery_id ORDER BY d.date_received DESC,b.batch_id DESC')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Manage Batches & Expiry';$active='inventory';require __DIR__.'/includes/header.php'; ?>
<div class="content-header"><h2>Manage Batches &amp; Expiry</h2><p>Classify receipt batches, record expiry dates, and configure low-stock thresholds.</p></div>
<?php if($error):?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif;?>
<?php if($message):?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif;?>
<section class="card tnts-admin-card" data-tnts-admin-grid>
  <div class="tnts-admin-heading"><h3>Delivery batches</h3></div>
  <p class="tnts-admin-hint">Batch classification does not change accepted quantities or historical ICS assignments. Expired stock is blocked from new issuance, not automatically disposed.</p>
  <div class="tnts-admin-toolbar">
    <label class="tnts-admin-search">Search batch<input type="search" data-admin-filter="search" placeholder="Item, receipt, batch or supplier" autocomplete="off"></label>
    <label class="tnts-admin-short">Expiry status<select data-admin-filter="status"><option value="">All statuses</option><option value="expired">Expired</option><option value="soon">Expiring soon</option><option value="dated">Dated / usable</option><option value="undated">Not recorded</option></select></label>
    <label class="tnts-admin-short">Course<select data-admin-filter="course"><option value="">All courses</option><option value="none">Unclassified</option><?php foreach($courses as $c):?><option value="<?= (int)$c['course_id'] ?>"><?= e($c['course_name']) ?></option><?php endforeach;?></select></label>
    <label class="tnts-admin-page-size">Rows per page<select data-admin-filter="page-size"><option value="10">10</option><option value="25">25</option><option value="all">All</option></select></label>
    <button type="button" class="tnts-admin-reset" data-admin-reset>Reset filters</button>
  </div>
  <div class="tnts-admin-table-wrap">
    <table class="tnts-admin-table tnts-admin-batch-table" data-admin-table aria-label="Delivery batches">
      <colgroup><col style="width:25%"><col style="width:16%"><col style="width:22%"><col style="width:15%"><col style="width:14%"><col style="width:8%"></colgroup>
      <thead><tr>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="name">Item / Receipt <span>–</span></button></th>
        <th>Batch number</th>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="course">Course <span>–</span></button></th>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="expiry" data-admin-type="date">Expiration <span>–</span></button></th>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="status">Status <span>–</span></button></th>
        <th>Save</th>
      </tr></thead>
      <tbody>
      <?php foreach($batches as $b):
        $form='batch-'.(int)$b['batch_id'];
        $expired=$b['expiration_date']&&$b['expiration_date']<date('Y-m-d');
        $soon=$b['expiration_date']&&$b['expiration_date']>=date('Y-m-d')&&$b['expiration_date']<=date('Y-m-d',strtotime('+30 days'));
        $expiryStatus=$expired?'expired':($soon?'soon':($b['expiration_date']?'dated':'undated'));
        $courseLabel='Unclassified';
        foreach($courses as $course){if((int)$course['course_id']===(int)($b['course_id']??0)){$courseLabel=$course['course_name'];break;}}
        $searchText=implode(' ',[$b['item_name'],$b['unit'],$b['receiving_number'],$b['supplier_name']??'',$b['batch_number']??'',$b['date_received'],$courseLabel]);
      ?>
      <tr data-admin-row data-search="<?= e($searchText) ?>" data-status="<?= e($expiryStatus) ?>" data-course="<?= $b['course_id']===null?'none':(int)$b['course_id'] ?>" data-sort-name="<?= e($b['item_name']) ?>" data-sort-course="<?= e($courseLabel) ?>" data-sort-expiry="<?= e($b['expiration_date']??'') ?>" data-sort-status="<?= e($expiryStatus) ?>">
        <td><span class="tnts-admin-name"><?= e($b['item_name']) ?></span> <span class="tnts-admin-muted"><?= e($b['unit']) ?></span>
          <span class="tnts-admin-meta"><strong><?= e($b['receiving_number']) ?></strong> · <?= e($b['date_received']) ?></span>
          <span class="tnts-admin-meta">Accepted: <?= (int)$b['received_qty'] ?> <?= e($b['unit']) ?> · <?= e($b['supplier_name']?:'–') ?></span>
        </td>
        <td><input class="tnts-admin-input" form="<?= e($form) ?>" name="batch_number" maxlength="80" value="<?= e($b['batch_number']??'') ?>" placeholder="Optional" aria-label="Batch number for <?= e($b['item_name']) ?>"></td>
        <td><select class="tnts-admin-select" form="<?= e($form) ?>" name="course_id" aria-label="Course for <?= e($b['item_name']) ?>"><option value="">Unclassified</option><?php foreach($courses as $c):?><option value="<?= (int)$c['course_id'] ?>" <?= (int)($b['course_id']??0)===(int)$c['course_id']?'selected':'' ?>><?= e($c['course_name']) ?></option><?php endforeach;?></select></td>
        <td><input class="tnts-admin-input" form="<?= e($form) ?>" name="expiration_date" type="date" value="<?= e($b['expiration_date']??'') ?>" aria-label="Expiration date for <?= e($b['item_name']) ?>"></td>
        <td><span class="tnts-admin-palette is-<?= e($expiryStatus==='undated'?'unknown':$expiryStatus) ?>"><?= $expired?'Expired':($soon?'Expiring soon':($b['expiration_date']?'Dated / usable':'Not recorded')) ?></span></td>
        <td><form method="post" id="<?= e($form) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_batch"><input type="hidden" name="batch_id" value="<?= (int)$b['batch_id'] ?>"><button class="tnts-admin-save" type="submit">Save</button></form></td>
      </tr><?php endforeach;?>
      </tbody>
    </table>
  </div>
  <div class="tnts-admin-pager"><span data-admin-count></span><div class="tnts-admin-pager-buttons"><button type="button" data-admin-prev>Previous</button><span data-admin-page></span><button type="button" data-admin-next>Next</button></div></div>
</section>
<section class="card tnts-admin-card" data-tnts-admin-grid style="margin-top:18px">
  <div class="tnts-admin-heading"><h3>Low-stock thresholds</h3></div>
  <p class="tnts-admin-hint">These thresholds apply to each shared inventory item, not to individual receipt batches. Quantities are not edited here.</p>
  <div class="tnts-admin-toolbar">
    <label class="tnts-admin-search">Search item<input type="search" data-admin-filter="search" placeholder="Item or category" autocomplete="off"></label>
    <label class="tnts-admin-short">Category<select data-admin-filter="category"><option value="">All categories</option><option value="equipment">Equipment</option><option value="non_consumable">Non Consumable</option><option value="consumable">Consumable</option><option value="expiring_material">Expiring Material</option></select></label>
    <label class="tnts-admin-page-size">Rows per page<select data-admin-filter="page-size"><option value="10">10</option><option value="25">25</option><option value="all">All</option></select></label>
    <button type="button" class="tnts-admin-reset" data-admin-reset>Reset filters</button>
  </div>
  <div class="tnts-admin-table-wrap">
    <table class="tnts-admin-table tnts-admin-threshold-table" data-admin-table aria-label="Low-stock thresholds">
      <colgroup><col style="width:38%"><col style="width:28%"><col style="width:23%"><col style="width:11%"></colgroup>
      <thead><tr>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="name">Item <span>–</span></button></th>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="category">Category <span>–</span></button></th>
        <th><button type="button" class="tnts-admin-sort" data-admin-sort="threshold" data-admin-type="number">Low-stock threshold <span>–</span></button></th>
        <th>Save</th>
      </tr></thead><tbody>
      <?php foreach($items as $i):$form='threshold-'.(int)$i['item_id'];$categoryLabel=ucwords(str_replace('_',' ',$i['item_category'])); ?>
      <tr data-admin-row data-search="<?= e($i['item_name'].' '.$categoryLabel) ?>" data-category="<?= e($i['item_category']) ?>" data-sort-name="<?= e($i['item_name']) ?>" data-sort-category="<?= e($categoryLabel) ?>" data-sort-threshold="<?= e($i['low_stock_threshold']??'') ?>">
        <td class="tnts-admin-name"><?= e($i['item_name']) ?></td>
        <td><?= e($categoryLabel) ?></td>
        <td><input class="tnts-admin-input" form="<?= e($form) ?>" type="number" min="0" max="100000000" name="threshold" placeholder="Not set" value="<?= e($i['low_stock_threshold']??'') ?>" aria-label="Low-stock threshold for <?= e($i['item_name']) ?>"></td>
        <td><form method="post" id="<?= e($form) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_threshold"><input type="hidden" name="item_id" value="<?= (int)$i['item_id'] ?>"><button class="tnts-admin-save" type="submit">Save</button></form></td>
      </tr><?php endforeach;?></tbody>
    </table>
  </div>
  <div class="tnts-admin-pager"><span data-admin-count></span><div class="tnts-admin-pager-buttons"><button type="button" data-admin-prev>Previous</button><span data-admin-page></span><button type="button" data-admin-next>Next</button></div></div>
</section>
<p><a href="<?= e(url('inventory.php?view=central')) ?>">← Back to Central Inventory</a></p>
<script defer src="<?= e(url('assets/js/admin_tables.js')) ?>"></script>
<?php require __DIR__.'/includes/footer.php'; ?>
