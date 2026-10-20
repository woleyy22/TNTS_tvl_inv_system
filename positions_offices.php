<?php
require_once __DIR__.'/includes/auth.php';
$me=require_role(['supply_officer','tvl_head']);
$pdo=db(); $canEdit=$me['role']==='supply_officer';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canEdit){http_response_code(403);exit('View-only access.');}
  csrf_check();
  $type=(string)($_POST['type']??'');$action=(string)($_POST['action']??'');
  $table=$type==='position'?'teaching_positions':($type==='office'?'school_offices':null);
  if(!$table){flash('error','Invalid list.');redirect('positions_offices.php');}
  try{
    if($action==='add'){
      $value=trim((string)($_POST['name']??''));
      if($value==='' || mb_strlen($value)>100)throw new RuntimeException('Enter a name of 1–100 characters.');
      $nameCol=$type==='position'?'position_name':'office_name';
      $st=$pdo->prepare("INSERT INTO $table ($nameCol,active,sort_order) VALUES (?,1,100)");$st->execute([$value]);
      log_action((int)$me['id'],'add', 'Personnel Lists', (int)$pdo->lastInsertId(),$type.': '.$value);
    }elseif($action==='toggle'){
      $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
      if(!$id)throw new RuntimeException('Invalid entry.');
      $col=$type==='position'?'position_id':'office_id';
      $pdo->prepare("UPDATE $table SET active=IF(active=1,0,1) WHERE $col=?")->execute([$id]);
      log_action((int)$me['id'],'toggle','Personnel Lists',$id,$type);
    }else{throw new RuntimeException('Unknown action.');}
    flash('success','Personnel list updated.');
  }catch(Throwable $e){flash('error','Unable to update: '.$e->getMessage());}
  redirect('positions_offices.php');
}
$positions=$pdo->query('SELECT position_id AS id,position_name AS name,active FROM teaching_positions ORDER BY sort_order,position_name')->fetchAll(PDO::FETCH_ASSOC);
$offices=$pdo->query('SELECT office_id AS id,office_name AS name,active FROM school_offices ORDER BY sort_order,office_name')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Manage Positions & Offices';$active='positions_offices.php';require __DIR__.'/includes/header.php';
?>
<div class="content-header"><h2>Positions &amp; Offices</h2><p>Approved choices for personnel profiles and future ICS issuance. Existing signed ICS snapshots never change when these lists are updated.</p></div>
<div class="p473-directory">
<?php foreach([['position','Teaching Positions',$positions],['office','Offices / Departments',$offices]] as $set): ?>
<section class="card"><h3><?= e($set[1]) ?></h3>
<?php if($canEdit): ?><form class="p473-add" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="type" value="<?= e($set[0]) ?>"><input class="input" name="name" maxlength="100" placeholder="Add <?= e(strtolower($set[1])) ?>" required><button class="btn btn-primary">Add</button></form><?php endif; ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Status</th><?php if($canEdit): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach($set[2] as $line): ?><tr><td><?= e($line['name']) ?></td><td><?= (int)$line['active']?'Active':'Inactive' ?></td><?php if($canEdit): ?><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="type" value="<?= e($set[0]) ?>"><input type="hidden" name="id" value="<?= (int)$line['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= (int)$line['active']?'Deactivate':'Reactivate' ?></button></form></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div></section><?php endforeach; ?>
</div>
<p class="p473-note">Deactivation removes an entry from future selection but does not change existing personnel profiles. Official personnel changes still require the existing review process.</p>
<?php require __DIR__.'/includes/footer.php'; ?>
