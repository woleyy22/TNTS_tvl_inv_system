<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer', 'tvl_head']);
$actorId = (int)$me['id'];
$pageTitle = 'Personnel Changes'; $active = 'personnel_reviews.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $changeId = filter_var($_POST['change_id'] ?? '', FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (!$changeId || !in_array($decision, ['approved', 'rejected'], true)) {
        flash('error', 'Invalid personnel review request.'); redirect('personnel_reviews.php');
    }
    if ($decision === 'rejected' && $notes === '') {
        flash('error', 'Enter a reason when rejecting a request.'); redirect('personnel_reviews.php');
    }
    if (mb_strlen($notes) > 500) {
        flash('error', 'Review notes must be at most 500 characters.'); redirect('personnel_reviews.php');
    }
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT r.change_id,r.user_id,r.requested_payload,r.status,u.role,u.registration_status,u.status AS user_status,u.email_verified_at FROM account_change_requests r JOIN users u ON u.user_id=r.user_id WHERE r.change_id=? AND r.request_type='profile_official' FOR UPDATE");
        $q->execute([$changeId]); $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'pending') throw new RuntimeException('Request no longer pending. Refresh the page.');
        if ($request['role'] !== 'teacher' || $request['registration_status'] !== 'approved' || $request['user_status'] !== 'active') throw new RuntimeException('Teacher account must be active and approved before official changes can be applied.');
        // Older approved legacy users may have no historical email-verification timestamp.
        if ($request['email_verified_at'] === null && $decision === 'approved') throw new RuntimeException('Email ownership must be verified before changing official personnel details.');
        $payload = json_decode($request['requested_payload'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Invalid request details.');
        if ($decision === 'approved') {
            $position = (string)($payload['teaching_position'] ?? '');
            $office = trim((string)($payload['position_office'] ?? ''));
            $level = (string)($payload['teaching_level'] ?? '');
            $check = $pdo->prepare('SELECT 1 FROM teaching_positions WHERE position_name=? AND active=1'); $check->execute([$position]);
            if (!$check->fetchColumn() || $office === '' || mb_strlen($office) > 150 || !in_array($level, ['JHS','SHS','JHS_SHS'], true)) throw new RuntimeException('Requested official fields are no longer valid. Reject and ask the teacher to submit again.');
            $pdo->prepare('INSERT INTO user_profiles(user_id,teaching_position,position_office,teaching_level) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE teaching_position=VALUES(teaching_position),position_office=VALUES(position_office),teaching_level=VALUES(teaching_level)')->execute([(int)$request['user_id'], $position, $office, $level]);
        }
        $pdo->prepare('UPDATE account_change_requests SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE change_id=? AND status=\'pending\'')->execute([$decision,$actorId,$notes ?: null,$changeId]);
        $pdo->commit();
        log_action($actorId, ucfirst($decision) . ' official personnel update', 'Accounts', (int)$request['user_id'], 'Change request #' . $changeId . ($notes ? ': ' . $notes : ''));
        flash('success', 'Personnel change request ' . $decision . '. Existing ICS print snapshots were not altered.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Unable to review this request.');
    }
    redirect('personnel_reviews.php');
}
$view = $_GET['view'] ?? 'pending';
if (!in_array($view, ['pending','approved','rejected','all'], true)) $view = 'pending';
$term = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($term) > 100) $term = mb_substr($term, 0, 100);
$where = ["r.request_type='profile_official'"]; $params = [];
if ($view !== 'all') { $where[] = 'r.status=?'; $params[]=$view; }
if ($term !== '') { $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; $params[]='%'.$term.'%';$params[]='%'.$term.'%'; }
$sql = 'SELECT r.change_id,r.user_id,r.requested_payload,r.status,r.requested_at,r.reviewed_at,r.review_notes,u.full_name,u.email,reviewer.full_name AS reviewer_name,p.teaching_position AS old_position,p.position_office AS old_office,p.teaching_level AS old_level FROM account_change_requests r JOIN users u ON u.user_id=r.user_id LEFT JOIN users reviewer ON reviewer.user_id=r.reviewed_by LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE '.implode(' AND ',$where).' ORDER BY r.requested_at DESC,r.change_id DESC LIMIT 150';
$st=$pdo->prepare($sql);$st->execute($params);$requests=$st->fetchAll(PDO::FETCH_ASSOC);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>Personnel Change Requests</h1><p>Review teachers' official Position / Office and teaching level. Either Supply Officer or TVL Head can decide; original ICS snapshots remain unchanged.</p></div>
<div class="card">
<form method="get" class="p45-filter">
 <div class="field"><label for="p45search">Search teacher or email</label><input class="input" id="p45search" name="q" value="<?= e($term) ?>" placeholder="Name or email"></div>
 <div class="field"><label for="p45view">Status</label><select class="input" name="view" id="p45view"><?php foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All requests'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $view===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
 <button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline" href="<?= e(url('personnel_reviews.php')) ?>">Reset</a>
</form>
<div class="table-wrap"><table class="table"><thead><tr><th>Teacher / Date</th><th>Current official details</th><th>Requested details</th><th>Review</th></tr></thead><tbody>
<?php if (!$requests): ?><tr><td colspan="4">No requests match these filters.</td></tr><?php endif; ?>
<?php foreach($requests as $req): $new=json_decode($req['requested_payload'],true);if(!is_array($new))$new=[]; ?>
<tr><td><strong><?= e($req['full_name']) ?></strong><br><small><?= e($req['email']) ?></small><br><small>Requested <?= e($req['requested_at']) ?></small></td>
<td><?= e($req['old_position'] ?: 'Not recorded') ?><br><?= e($req['old_office'] ?: 'Not recorded') ?><br><small><?= e($req['old_level'] ?: 'Not recorded') ?></small></td>
<td><strong><?= e($new['teaching_position'] ?? 'Not recorded') ?></strong><br><?= e($new['position_office'] ?? 'Not recorded') ?><br><small><?= e($new['teaching_level'] ?? 'Not recorded') ?></small></td>
<td><?php if ($req['status']==='pending'): ?>
<form method="post" class="p45-decision" onsubmit="return confirm('Confirm this personnel decision?');"><?= csrf_field() ?><input type="hidden" name="change_id" value="<?= (int)$req['change_id'] ?>"><input class="input" type="text" name="notes" maxlength="500" placeholder="Reason (required if rejecting)"><button type="submit" name="decision" value="approved" class="btn btn-primary">Approve</button><button type="submit" name="decision" value="rejected" class="btn btn-danger">Reject</button></form>
<?php else: ?><strong class="p45-state <?= e($req['status']) ?>"><?= e(ucfirst($req['status'])) ?></strong><br><small><?= e($req['reviewer_name'] ?: 'Reviewer unavailable') ?> · <?= e($req['reviewed_at'] ?: '') ?></small><?php if ($req['review_notes']): ?><p><?= e($req['review_notes']) ?></p><?php endif; ?><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
