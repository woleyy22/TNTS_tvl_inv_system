<?php
/** Phase 4.4: one independent verified approval from Supply Officer OR TVL Head. */
require_once __DIR__ . '/includes/auth.php';
$viewer = require_role(['supply_officer', 'tvl_head']);
$pdo = db();
$actorId = (int)$viewer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['user_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    try {
        if (!in_array($action, ['approve', 'reject'], true) || $targetId < 1) {
            throw new RuntimeException('Invalid registration action.');
        }
        if (strlen($notes) > 500) {
            throw new RuntimeException('Review notes cannot exceed 500 characters.');
        }
        if ($action === 'reject' && $notes === '') {
            throw new RuntimeException('Please provide a reason for rejection.');
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT user_id, full_name, email, role, status, registration_status, registration_source, email_verified_at FROM users WHERE user_id=? FOR UPDATE");
        $st->execute([$targetId]);
        $target = $st->fetch();
        if (!$target || $target['role'] !== 'teacher' || $target['registration_source'] !== 'self_registration') {
            throw new RuntimeException('Only teacher self-registrations can be reviewed here.');
        }
        if ($target['registration_status'] !== 'pending') {
            throw new RuntimeException('This registration has already been reviewed. Refresh the page.');
        }
        if ($target['email_verified_at'] === null) {
            throw new RuntimeException('Email verification is required before review.');
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $access = $action === 'approve' ? 'active' : 'inactive';
        $update = $pdo->prepare('UPDATE users SET registration_status=?, status=?, approved_by=?, approved_at=NOW() WHERE user_id=? AND registration_status=?');
        $update->execute([$status, $access, $actorId, $targetId, 'pending']);
        if ($update->rowCount() !== 1) { throw new RuntimeException('Registration changed while you were reviewing it.'); }
        $history = $pdo->prepare('INSERT INTO account_approval_history (target_user_id, actor_user_id, action, notes) VALUES (?,?,?,?)');
        $history->execute([$targetId, $actorId, $action, $notes !== '' ? $notes : null]);
        log_action($actorId, $action, 'Account Approvals', $targetId, ucfirst($action) . ' teacher registration: ' . $target['email']);
        $pdo->commit();
        flash('success', $action === 'approve' ? 'Teacher account approved and activated.' : 'Registration rejected. Access remains disabled.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof PDOException ? 'Database error while saving the decision. Please check the migration.' : $e->getMessage());
    }
    redirect('approvals.php');
}

$focusUser = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$filter = (string)($_GET['status'] ?? 'history');
if (!in_array($filter, ['history','pending','unverified'], true)) $filter='history';
$search = trim((string)($_GET['q'] ?? ''));
$level = (string)($_GET['level'] ?? '');
if (!in_array($level, ['', 'JHS', 'SHS', 'JHS_SHS'], true)) $level='';
$dateFrom = trim((string)($_GET['registered_from'] ?? ''));
$dateTo = trim((string)($_GET['registered_to'] ?? ''));
foreach (['dateFrom','dateTo'] as $field) {
    if ($$field !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$field) || !checkdate((int)substr($$field,5,2),(int)substr($$field,8,2),(int)substr($$field,0,4)))) $$field='';
}
$direction = strtoupper((string)($_GET['sort'] ?? 'DESC'));
if (!in_array($direction, ['ASC','DESC'], true)) $direction='DESC';
// A notification opens the named account; a finished request lands in its decision history.
if ($focusUser && $filter === 'pending') {
    $check=$pdo->prepare("SELECT registration_status FROM users WHERE user_id=? AND role='teacher'");
    $check->execute([$focusUser]);
    if ($check->fetchColumn() !== 'pending') $filter='history';
}
if ($focusUser) { $search=''; $level=''; $dateFrom=''; $dateTo=''; }
$pendingCount=(int)db_scalar("SELECT COUNT(*) FROM users WHERE role='teacher' AND registration_source='self_registration' AND registration_status='pending' AND email_verified_at IS NOT NULL");
$awaitingCount=(int)db_scalar("SELECT COUNT(*) FROM users WHERE role='teacher' AND registration_source='self_registration' AND registration_status='pending' AND email_verified_at IS NULL");

$registrations=[];
$historyEvents=[];
if ($filter !== 'history') {
    $where="u.role='teacher' AND u.registration_source='self_registration' AND u.registration_status='pending' AND u.email_verified_at IS ".($filter==='pending'?'NOT NULL':'NULL');
    $params=[];
    if ($focusUser) { $where.=' AND u.user_id=:focus_user'; $params['focus_user']=$focusUser; }
    if($search!==''){$where.=' AND (u.full_name LIKE :n OR u.email LIKE :e)';$params['n']='%'.$search.'%';$params['e']='%'.$search.'%';}
    if($level!==''){$where.=' AND p.teaching_level=:lev';$params['lev']=$level;}
    if($dateFrom!==''){$where.=' AND u.created_at >= :df';$params['df']=$dateFrom.' 00:00:00';}
    if($dateTo!==''){$where.=' AND u.created_at < :dt';$params['dt']=date('Y-m-d',strtotime($dateTo.' +1 day')).' 00:00:00';}
    $query="SELECT u.user_id,u.full_name,u.email,u.registration_status,u.email_verified_at,u.created_at,
      p.teaching_position,p.position_office,p.teaching_level,
      (SELECT GROUP_CONCAT(c.course_name ORDER BY c.course_name SEPARATOR ', ') FROM teacher_courses tc JOIN courses c ON c.course_id=tc.course_id WHERE tc.user_id=u.user_id) courses
      FROM users u LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE $where ORDER BY u.created_at $direction,u.user_id $direction LIMIT 300";
    $st=$pdo->prepare($query);$st->execute($params);$registrations=$st->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Decisions on existing users plus snapshots for accounts that have been deleted.
    $base=' WHERE 1=1';$params=[];
    if ($focusUser) { $base.=' AND u.user_id=:focus_user'; $params['focus_user']=$focusUser; }
    if($search!==''){$base.=' AND (u.full_name LIKE :n OR u.email LIKE :e)';$params['n']='%'.$search.'%';$params['e']='%'.$search.'%';}
    if($level!==''){$base.=' AND p.teaching_level=:lev';$params['lev']=$level;}
    if($dateFrom!==''){$base.=' AND u.created_at >= :df';$params['df']=$dateFrom.' 00:00:00';}
    if($dateTo!==''){$base.=' AND u.created_at < :dt';$params['dt']=date('Y-m-d',strtotime($dateTo.' +1 day')).' 00:00:00';}
    $query="SELECT u.user_id,u.full_name,u.email,u.created_at registered_at,p.teaching_level,
      h.action event_type,h.created_at occurred_at,h.notes event_notes,actor.full_name actor_name
      FROM account_approval_history h JOIN users u ON u.user_id=h.target_user_id
      LEFT JOIN user_profiles p ON p.user_id=u.user_id
      LEFT JOIN users actor ON actor.user_id=h.actor_user_id $base";
    $st=$pdo->prepare($query);$st->execute($params);$historyEvents=$st->fetchAll(PDO::FETCH_ASSOC);
    $archiveWhere=' WHERE 1=1';$archiveParams=[];
    if ($focusUser) { $archiveWhere.=' AND a.former_user_id=:focus_user'; $archiveParams['focus_user']=$focusUser; }
    if($search!==''){$archiveWhere.=' AND (a.full_name_snapshot LIKE :n OR a.email_snapshot LIKE :e)';$archiveParams['n']='%'.$search.'%';$archiveParams['e']='%'.$search.'%';}
    if($level!==''){$archiveWhere.=' AND a.teaching_level_snapshot=:lev';$archiveParams['lev']=$level;}
    if($dateFrom!==''){$archiveWhere.=' AND a.registered_at_snapshot >= :df';$archiveParams['df']=$dateFrom.' 00:00:00';}
    if($dateTo!==''){$archiveWhere.=' AND a.registered_at_snapshot < :dt';$archiveParams['dt']=date('Y-m-d',strtotime($dateTo.' +1 day')).' 00:00:00';}
    $query="SELECT a.former_user_id user_id,a.full_name_snapshot full_name,a.email_snapshot email,
      a.registered_at_snapshot registered_at,a.teaching_level_snapshot teaching_level,
      a.event_type,a.occurred_at,a.event_notes,actor.full_name actor_name
      FROM account_registration_audit a LEFT JOIN users actor ON actor.user_id=a.actor_user_id $archiveWhere";
    $st=$pdo->prepare($query);$st->execute($archiveParams);
    $historyEvents=array_merge($historyEvents,$st->fetchAll(PDO::FETCH_ASSOC));
    // Sort on registration date as requested; use event timestamp for stable ties.
    usort($historyEvents,static function($a,$b)use($direction){
        $cmp=strcmp((string)($a['registered_at']??''),(string)($b['registered_at']??''));
        if($cmp===0)$cmp=strcmp((string)$a['occurred_at'],(string)$b['occurred_at']);
        return $direction==='ASC'?$cmp:-$cmp;
    });
    $historyEvents=array_slice($historyEvents,0,400);
}
$pageTitle='Registration Approvals';$active='approvals.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>Teacher Registration Approvals</h1><p class="p44-review-intro">Supply Officer or TVL Head may approve an email-verified teacher. One decision is sufficient. Past decisions and deletions are recorded in history.</p></div>
<div class="p44-review-stats"><a class="p442-stat" href="<?= e(url('approvals.php?status=pending')) ?>">Ready for review <strong><?= $pendingCount ?></strong></a><a class="p442-stat" href="<?= e(url('approvals.php?status=unverified')) ?>">Awaiting email <strong><?= $awaitingCount ?></strong></a></div>
<div class="card"><form method="get" class="p442-filters">
<div class="p442-field"><label for="p442-search">Teacher / Email</label><input class="input" id="p442-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Name or email"></div>
<div class="p442-field"><label for="p442-level">Teaching level</label><select class="input" id="p442-level" name="level"><option value="">All levels</option><?php foreach(['JHS'=>'Junior High','SHS'=>'Senior High','JHS_SHS'=>'Both'] as $key=>$caption): ?><option value="<?= e($key) ?>"<?= $level===$key?' selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></div>
<div class="p442-field"><label for="p442-status">View</label><select class="input" id="p442-status" name="status"><?php foreach(['history'=>'Decision & Deletion History','pending'=>'Pending Review','unverified'=>'Awaiting Email'] as $key=>$caption): ?><option value="<?= e($key) ?>"<?= $filter===$key?' selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></div>
<div class="p442-field"><label for="p442-from">Registered from</label><input class="input" id="p442-from" name="registered_from" type="date" value="<?= e($dateFrom) ?>"></div>
<div class="p442-field"><label for="p442-to">Registered to</label><input class="input" id="p442-to" name="registered_to" type="date" value="<?= e($dateTo) ?>"></div>
<div class="p442-field"><label for="p442-sort">Registered date order</label><select class="input" id="p442-sort" name="sort"><option value="DESC"<?= $direction==='DESC'?' selected':'' ?>>Newest first ↓</option><option value="ASC"<?= $direction==='ASC'?' selected':'' ?>>Oldest first ↑</option></select></div>
<div class="p442-filter-actions"><button class="btn btn-primary" type="submit">Apply Filters</button><a class="btn btn-outline" href="<?= e(url('approvals.php')) ?>">Reset</a></div></form>
<div class="table-wrap tnts-review-table-wrap"><table class="table tnts-review-table" id="registration-review-table"><thead>
<tr><th>Teacher / Email</th><th><?= $filter==='history'?'Decision':'Registered' ?></th><th><?= $filter==='history'?'Performed by':'Verification' ?></th><th>Details</th></tr>
</thead><tbody>
<?php if($filter==='history'): ?>
<?php if(!$historyEvents): ?><tr><td colspan="4" class="muted">No decision or deletion history matches your filters.</td></tr><?php endif; ?>
<?php foreach($historyEvents as $index=>$ev): $detailId='reg-history-'.$index; ?>
<tr class="tnts-review-main" data-notify-user="<?= (int)($ev['user_id']??0) ?>"><td><strong><?= e($ev['full_name']) ?></strong><small class="tnts-review-subline"><?= e($ev['email']) ?></small></td>
<td><span class="p44-pill <?= $ev['event_type']==='reject'?'rejected':'verified' ?>"><?= e(ucfirst($ev['event_type'])) ?></span><small class="tnts-review-subline"><?= e($ev['occurred_at']) ?></small></td>
<td><?= e($ev['actor_name'] ?: 'Former administrator') ?></td>
<td><button type="button" class="btn btn-outline btn-sm tnts-review-toggle" data-target="<?= e($detailId) ?>" aria-controls="<?= e($detailId) ?>" aria-expanded="false">View Details</button></td></tr>
<tr id="<?= e($detailId) ?>" class="tnts-review-detail" hidden><td colspan="4"><div class="tnts-review-panel">
<div class="tnts-review-panel-header"><strong><?= e($ev['full_name']) ?> — <?= e(ucfirst($ev['event_type'])) ?></strong><button type="button" class="btn btn-outline btn-sm tnts-review-close" data-target="<?= e($detailId) ?>">Close</button></div>
<dl class="tnts-review-facts"><div><dt>Registered</dt><dd><?= e($ev['registered_at'] ?: '–') ?></dd></div><div><dt>Teaching level</dt><dd><?= e($ev['teaching_level'] ?: '–') ?></dd></div><div><dt>Performed by</dt><dd><?= e($ev['actor_name'] ?: 'Former administrator') ?></dd></div><div><dt>Date / time</dt><dd><?= e($ev['occurred_at']) ?></dd></div></dl>
<div class="tnts-review-note"><span>Decision / audit note</span><em><?= e($ev['event_notes'] ?: 'No note recorded.') ?></em></div>
</div></td></tr>
<?php endforeach; ?>
<?php else: ?>
<?php if(!$registrations): ?><tr><td colspan="4" class="muted">No registrations match your filters.</td></tr><?php endif; ?>
<?php foreach($registrations as $r): $detailId='reg-request-'.(int)$r['user_id']; ?>
<tr class="tnts-review-main" data-notify-user="<?= (int)$r['user_id'] ?>"><td><strong><?= e($r['full_name']) ?></strong><small class="tnts-review-subline"><?= e($r['email']) ?></small></td>
<td><?= e($r['created_at']) ?></td>
<td><span class="pill <?= $filter==='pending'?'green':'orange' ?>"><?= $filter==='pending'?'Email verified':'Awaiting verification' ?></span></td>
<td><button type="button" class="btn btn-outline btn-sm tnts-review-toggle" data-target="<?= e($detailId) ?>" aria-controls="<?= e($detailId) ?>" aria-expanded="false">View Details</button></td></tr>
<tr id="<?= e($detailId) ?>" class="tnts-review-detail" hidden><td colspan="4"><div class="tnts-review-panel">
<div class="tnts-review-panel-header"><strong><?= e($r['full_name']) ?> — Registration Details</strong><button type="button" class="btn btn-outline btn-sm tnts-review-close" data-target="<?= e($detailId) ?>">Close</button></div>
<dl class="tnts-review-facts"><div><dt>Teaching position</dt><dd><?= e($r['teaching_position'] ?: '–') ?></dd></div><div><dt>Office</dt><dd><?= e($r['position_office'] ?: '–') ?></dd></div><div><dt>Teaching level</dt><dd><?= e($r['teaching_level'] ?: '–') ?></dd></div><div><dt>Courses</dt><dd><?= e($r['courses'] ?: 'No course assigned') ?></dd></div><div><dt>Requested at</dt><dd><?= e($r['created_at']) ?></dd></div><div><dt>Email</dt><dd><?= e($r['email']) ?></dd></div></dl>
<?php if($filter==='pending'): ?>
<div class="tnts-review-action-panel"><strong>Review verified registration</strong><div class="tnts-review-actions"><form method="post" onsubmit="return confirm('Approve and activate this verified account?')"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>"><button type="submit" class="btn btn-primary btn-sm">Approve</button></form><button type="button" class="btn btn-sm p44-reject-btn" onclick="tntsOpenReject(<?= (int)$r['user_id'] ?>, <?= htmlspecialchars(json_encode($r['email'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),ENT_QUOTES,'UTF-8') ?>)">Reject…</button></div></div>
<?php else: ?><p class="muted">This registration cannot be reviewed until the teacher verifies their email.</p><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div></div>
<dialog id="p44-reject-dialog" class="p44-review-dialog"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" id="p44-reject-id"><h3>Reject teacher registration?</h3><p>Account: <strong id="p44-reject-email"></strong></p><label for="p44-review-notes">Reason for rejection (required)</label><textarea name="notes" id="p44-review-notes" maxlength="500" required></textarea><div class="actions"><button class="btn btn-outline" type="button" onclick="document.getElementById('p44-reject-dialog').close()">Cancel</button><button class="btn p44-reject-btn" type="submit">Confirm rejection</button></div></form></dialog>
<script>function tntsOpenReject(id,email){const d=document.getElementById('p44-reject-dialog');document.getElementById('p44-reject-id').value=id;document.getElementById('p44-reject-email').textContent=email;document.getElementById('p44-review-notes').value='';d.showModal();}</script>
<script src="<?= e(url('assets/js/review_details.js')) ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
