<?php
// Account management — retain the existing account permissions and transaction handlers
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer','tvl_head']);
$myId=(int)$me['id'];
$isOfficer=$me['role']==='supply_officer';
$pdo=db();
$roles=['supply_officer','tvl_head','teacher'];
$levels=['JHS','SHS','JHS_SHS'];
$allCourses=$pdo->query('SELECT course_id,course_name FROM courses ORDER BY course_name')->fetchAll(PDO::FETCH_ASSOC);
$courseIds=array_map('intval',array_column($allCourses,'course_id'));
$positions=$pdo->query('SELECT position_name FROM teaching_positions WHERE active=1 ORDER BY sort_order,position_name')->fetchAll(PDO::FETCH_COLUMN);
$problems=[]; $openEdit=0; $openAdd=false; $posted=[];
function p451_course_ids($posted,$allowed) {
    if (!is_array($posted)) throw new RuntimeException('Invalid course selection.');
    $ids=array_values(array_unique(array_map('intval',$posted)));
    foreach($ids as $id) if(!in_array($id,$allowed,true)) throw new RuntimeException('Choose a valid course.');
    return $ids;
}
function p451_course_update(PDO $pdo,int $uid,array $ids,int $actor) {
    $st=$pdo->prepare('SELECT course_id FROM teacher_courses WHERE user_id=? ORDER BY course_id');
    $st->execute([$uid]); $before=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
    sort($before); sort($ids);
    if ($before===$ids) return false;
    $pdo->prepare('DELETE FROM teacher_courses WHERE user_id=?')->execute([$uid]);
    $ins=$pdo->prepare('INSERT INTO teacher_courses(user_id,course_id) VALUES (?,?)');
    foreach($ids as $id) $ins->execute([$uid,$id]);
    // Transaction log: if the write fails, the course update must also fail.
    $log=$pdo->prepare('INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,?,?,?,?)');
    $log->execute([$actor,'Updated assigned courses','Accounts',$uid,'Course IDs: ['.implode(',',$before).'] → ['.implode(',',$ids).']']);
    return true;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$isOfficer) { http_response_code(403); exit('Account editing is restricted to the Supply Officer.'); }
    csrf_check();
    $action=(string)($_POST['action']??'');
    // Prevent deactivation until all accountable ICS slips have been resolved.
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $myId) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT role, status, registration_status FROM users WHERE user_id = ? FOR UPDATE');
                $st->execute([$id]);
                $target = $st->fetch();
                if (!$target) { throw new RuntimeException('Account not found.'); }
                if ($target['status'] === 'active' && $target['role'] === 'teacher') {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM ics WHERE teacher_id = ? AND status = 'active'");
                    $st->execute([$id]);
                    $outstanding = (int)$st->fetchColumn();
                    if ($outstanding > 0) {
                        throw new RuntimeException("Cannot deactivate: {$outstanding} active ICS document(s) remain. Transfer or return the accountable items first.");
                    }
                }
                if ($target['status'] !== 'active' && $target['registration_status'] !== 'approved') { throw new RuntimeException('Pending or rejected registrations cannot be reactivated. Use Registration Approvals first.'); }
                $next = $target['status'] === 'active' ? 'inactive' : 'active';
                $st = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?');
                $st->execute([$next, $id]);
                log_action($myId, 'change status', 'Accounts', $id, 'Changed to ' . $next);
                $pdo->commit();
                flash('success', 'Account status updated.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                flash('error', $e->getMessage());
            }
        }
        redirect('accounts.php');
    }


    // Deletes a rejected/unused registration only when no operational history exists.
    // Review decisions are snapshotted in an append-only account_registration_audit table.
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $confirmation = trim((string)($_POST['confirm_email'] ?? ''));
        try {
            if ($id < 1 || $id === $myId) throw new RuntimeException('You cannot delete this account.');
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT user_id, full_name, email, role, status, registration_status, registration_source, email_verified_at, approved_at, approved_by FROM users WHERE user_id=? FOR UPDATE');
            $st->execute([$id]); $target = $st->fetch(PDO::FETCH_ASSOC);
            if (!$target) throw new RuntimeException('Account not found.');
            if ($confirmation !== $target['email']) throw new RuntimeException('Confirmation email did not match.');
            // Status alone is not a deletion blocker. Only accounts with no protected
            // property or operational records can be removed, regardless of approval state.
            // Supply Officer and TVL Head accounts are protected from deletion here.
            if ($target['role'] !== 'teacher') {
                throw new RuntimeException('Only unused teacher accounts can be deleted. Administrative accounts must be preserved.');
            }
            // FK relationship inspection is conservative: newly added modules automatically block deletion.
            $references=$pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME='user_id'")->fetchAll(PDO::FETCH_ASSOC);
            $safe=['user_profiles'=>['user_id'], 'teacher_courses'=>['user_id'], 'account_verification_codes'=>['user_id'], 'account_approval_history'=>['target_user_id']];
            foreach($references as $ref){
                $table=$ref['TABLE_NAME'];$column=$ref['COLUMN_NAME'];
                if(isset($safe[$table]) && in_array($column,$safe[$table],true))continue;
                $check=$pdo->prepare('SELECT 1 FROM `'.str_replace('`','``',$table).'` WHERE `'.str_replace('`','``',$column).'`=? LIMIT 1');
                $check->execute([$id]);
                if($check->fetchColumn())throw new RuntimeException('This account has linked transaction, activity or audit records and cannot be deleted. Deactivate instead.');
            }
            // Defensive checks for historical schemas lacking foreign keys.
            foreach(['account_change_requests'=>['user_id','reviewed_by'],'account_approval_history'=>['actor_user_id']] as $table=>$columns){
                $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute([$table]);
                if(!$exists->fetchColumn())continue;
                foreach($columns as $column){$check=$pdo->prepare('SELECT 1 FROM `'.$table.'` WHERE `'.$column.'`=? LIMIT 1');$check->execute([$id]);if($check->fetchColumn())throw new RuntimeException('Account has audit or profile-change activity; deactivate instead.');}
            }
            // Preserve account creation date and personnel context for the history filters.
            $profileStmt=$pdo->prepare('SELECT teaching_level FROM user_profiles WHERE user_id=?');
            $profileStmt->execute([$id]);
            $registeredStmt=$pdo->prepare('SELECT created_at FROM users WHERE user_id=?');
            $registeredStmt->execute([$id]);
            $registeredAt=$registeredStmt->fetchColumn();
            $teachingLevel=$profileStmt->fetchColumn() ?: null;
            // Archive every review (including the rejection reason) before removing FK-bound rows.
            $archive=$pdo->prepare('INSERT INTO account_registration_audit (former_user_id, full_name_snapshot, email_snapshot, event_type, actor_user_id, event_notes, occurred_at, registered_at_snapshot, teaching_level_snapshot) VALUES (?,?,?,?,?,?,?,?,?)');
            $history=$pdo->prepare('SELECT h.action,h.actor_user_id,h.notes,h.created_at FROM account_approval_history h WHERE h.target_user_id=? ORDER BY h.approval_id');
            $history->execute([$id]);
            foreach($history->fetchAll(PDO::FETCH_ASSOC) as $h){
                $archive->execute([$id,$target['full_name'],$target['email'],$h['action'],(int)$h['actor_user_id'],$h['notes'],$h['created_at'],$registeredAt,$teachingLevel]);
            }
            $archive->execute([$id,$target['full_name'],$target['email'],'deleted',$myId,'Removed unused account; review history retained.',date('Y-m-d H:i:s'),$registeredAt,$teachingLevel]);
            // Clean only registration-specific dependent rows.
            $pdo->prepare('DELETE FROM account_approval_history WHERE target_user_id=?')->execute([$id]);
            foreach(['account_verification_codes','teacher_courses','user_profiles'] as $table){
                $present=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$present->execute([$table]);
                if($present->fetchColumn())$pdo->prepare('DELETE FROM `'.$table.'` WHERE user_id=?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]);
            log_action($myId,'delete','Accounts',$id,'Deleted unused teacher account: '.$target['email'].' (review history archived)');
            $pdo->commit();flash('success','Unused account deleted; registration decisions and deletion recorded in history.');
        }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();flash('error',$ex instanceof PDOException?'Deletion blocked by a linked database record. The account was not removed.':$ex->getMessage());}
        redirect('accounts.php');
    }

    if ($action==='save') {
        $id=max(0,(int)($_POST['id']??0));
        $openEdit=$id; $openAdd=($id===0);
        $posted=$_POST;
        try {
            $name=trim((string)($_POST['full_name']??''));
            $email=strtolower(trim((string)($_POST['email']??'')));
            $role=(string)($_POST['role']??'teacher');
            $pwd=(string)($_POST['password']??'');
            $pos=trim((string)($_POST['teaching_position']??''));
            $office=trim((string)($_POST['position_office']??''));
            $level=(string)($_POST['teaching_level']??'');
            $suffix=trim((string)($_POST['suffix']??''));
            $gender=trim((string)($_POST['gender']??''));
            $birthday=trim((string)($_POST['birthday']??''));
            $religion=trim((string)($_POST['religion']??''));
            $picked=p451_course_ids($_POST['courses']??[],$courseIds);
            if($name===''||(function_exists('mb_strlen')?mb_strlen($name):strlen($name))>100)throw new RuntimeException('Name must be 1–100 characters.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100)throw new RuntimeException('Enter a valid email (100 characters maximum).');
            if(!in_array($role,$roles,true))throw new RuntimeException('Invalid account role.');
            if($id===0 && strlen($pwd)<8)throw new RuntimeException('Password must contain at least 8 characters.');
            if($pwd!=='' && (strlen($pwd)<8||strlen($pwd)>72||strpos($pwd,"\0")!==false))throw new RuntimeException('Password must be 8–72 characters.');
            if(strlen($suffix)>20||strlen($gender)>50||strlen($religion)>100)throw new RuntimeException('Personal details exceed their allowed lengths.');
            if($birthday!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$birthday) || strtotime($birthday)===false || $birthday<'1900-01-01' || $birthday>date('Y-m-d')))throw new RuntimeException('Enter a valid birthday.');
            if($role==='teacher') {
                if($pos!=='' && !in_array($pos,$positions,true))throw new RuntimeException('Choose a valid teaching position.');
                if(strlen($office)>150)throw new RuntimeException('Office must be at most 150 characters.');
                if($level!=='' && !in_array($level,$levels,true))throw new RuntimeException('Choose a valid teaching level.');
            }else{$picked=[];$pos='';$office='';$level='';}
            $pdo->beginTransaction();
            $st=$pdo->prepare('SELECT user_id FROM users WHERE email=? AND user_id<>? LIMIT 1');$st->execute([$email,$id]);
            if($st->fetchColumn())throw new RuntimeException('This email already belongs to another account.');
            if($id) {
                $st=$pdo->prepare('SELECT user_id,role,full_name,email,registration_status FROM users WHERE user_id=? FOR UPDATE');
                $st->execute([$id]);$current=$st->fetch(PDO::FETCH_ASSOC);
                if(!$current)throw new RuntimeException('Account not found.');
                if($id===$myId && $role!=='supply_officer')throw new RuntimeException('You cannot change your own role.');
                if($current['registration_status']!=='approved' && $role!=='teacher')throw new RuntimeException('Unapproved registrations must remain Teachers until reviewed.');
                if($current['role']==='teacher' && $role!=='teacher'){
                    $st=$pdo->prepare('SELECT COUNT(*) FROM ics WHERE teacher_id=?');$st->execute([$id]);
                    if((int)$st->fetchColumn())throw new RuntimeException('This teacher has ICS history; their role cannot be changed.');
                }
                $pdo->prepare('UPDATE users SET full_name=?,email=?,role=? WHERE user_id=?')->execute([$name,$email,$role,$id]);
                if($pwd!=='')$pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($pwd,PASSWORD_DEFAULT),$id]);
            }else{
                $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role,status,registration_status,registration_source) VALUES (?,?,?,?, 'active','approved','admin_created')")
                    ->execute([$name,$email,password_hash($pwd,PASSWORD_DEFAULT),$role]);
                $id=(int)$pdo->lastInsertId();
            }
            $prevPersonnel=$pdo->prepare('SELECT teaching_position,position_office,teaching_level FROM user_profiles WHERE user_id=?');
            $prevPersonnel->execute([$id]); $beforePersonnel=$prevPersonnel->fetch(PDO::FETCH_ASSOC) ?: [];
            $personnelChanged=($beforePersonnel && (($beforePersonnel['teaching_position']??'')!==$pos || ($beforePersonnel['position_office']??'')!==$office || ($beforePersonnel['teaching_level']??'')!==$level));
            $pdo->prepare('INSERT INTO user_profiles(user_id,suffix,gender,birthday,religion,teaching_position,position_office,teaching_level) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE suffix=VALUES(suffix),gender=VALUES(gender),birthday=VALUES(birthday),religion=VALUES(religion),teaching_position=VALUES(teaching_position),position_office=VALUES(position_office),teaching_level=VALUES(teaching_level)')
                ->execute([$id,$suffix?:null,$gender?:null,$birthday?:null,$religion?:null,$pos?:null,$office?:null,$level?:null]);
            p451_course_update($pdo,$id,$picked,$myId);
            // An authorized direct personnel edit supersedes an older pending request.
            if ($personnelChanged) {
                $pdo->prepare("UPDATE account_change_requests SET status='rejected', reviewed_by=?,reviewed_at=NOW(),review_notes='Superseded by a direct Supply Officer personnel edit' WHERE user_id=? AND request_type='profile_official' AND status='pending'")
                    ->execute([$myId,$id]);
            }
            // Preserve original ICS recipient snapshots without modification.
            $log=$pdo->prepare('INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES (?,?,?,?,?)');
            $log->execute([$myId,$openAdd?'Created account':'Updated account','Accounts',$id,'Account profile edited: '.$name.'; role '.$role.'; saved personnel and courses; existing ICS snapshots unchanged.']);
            $pdo->commit();
            if($id===$myId)$_SESSION['user']['name']=$name;
            flash('success',$openAdd?'Account created.':'Account changes saved.');
            redirect('accounts.php');
        }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$problems[]=$ex instanceof PDOException?'Unable to save account; check for duplicate email or database constraints.':$ex->getMessage();}
    }
}
// Whitelisted, parameterized filters; sorted columns never come directly from user input.
$q=trim((string)($_GET['q']??''));$roleF=(string)($_GET['role']??'');$statusF=(string)($_GET['status']??'');
$approvalF=(string)($_GET['approval']??'');$levelF=(string)($_GET['level']??'');
$courseF=(int)($_GET['course']??0);$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
// Three-state account sorting: default (-) -> ascending -> descending -> default.
$sort=(string)($_GET['sort']??'');$dir=strtolower((string)($_GET['dir']??''));
$columns=['name'=>'u.full_name','email'=>'u.email','role'=>'u.role','courses'=>'course_list','level'=>'p.teaching_level','status'=>'u.status','approval'=>'u.registration_status','registered'=>'u.created_at'];
if(!isset($columns[$sort]) || !in_array($dir,['asc','desc'],true)){$sort='';$dir='';}
$sql="SELECT u.user_id,u.full_name,u.email,u.role,u.status,u.registration_status,u.registration_source,u.email_verified_at,u.created_at, p.teaching_position,p.position_office,p.teaching_level,p.suffix,p.gender,p.birthday,p.religion, (SELECT GROUP_CONCAT(c.course_name ORDER BY c.course_name SEPARATOR ', ') FROM teacher_courses tc JOIN courses c ON c.course_id=tc.course_id WHERE tc.user_id=u.user_id) AS course_list FROM users u LEFT JOIN user_profiles p ON p.user_id=u.user_id WHERE 1=1";
$args=[];
if($q!==''){$sql.=' AND (u.full_name LIKE ? OR u.email LIKE ?)';$args[]='%'.$q.'%';$args[]='%'.$q.'%';}
if(in_array($roleF,$roles,true)){$sql.=' AND u.role=?';$args[]=$roleF;}
if(in_array($statusF,['active','inactive'],true)){$sql.=' AND u.status=?';$args[]=$statusF;}
if(in_array($approvalF,['pending','approved','rejected'],true)){$sql.=' AND u.registration_status=?';$args[]=$approvalF;}
if(in_array($levelF,$levels,true)){$sql.=' AND p.teaching_level=?';$args[]=$levelF;}
if(in_array($courseF,$courseIds,true)){$sql.=' AND EXISTS (SELECT 1 FROM teacher_courses tc2 WHERE tc2.user_id=u.user_id AND tc2.course_id=?)';$args[]=$courseF;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$sql.=' AND u.created_at>=?';$args[]=$from.' 00:00:00';}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$sql.=' AND u.created_at<?';$args[]=date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00';}
$sql.=$sort!=='' ? ' ORDER BY '.$columns[$sort].' '.strtoupper($dir).',u.user_id ASC' : ' ORDER BY u.full_name ASC,u.user_id ASC';
$st=$pdo->prepare($sql);$st->execute($args);$users=$st->fetchAll(PDO::FETCH_ASSOC);
$allAssigned=[];
if($users){$ids=array_map('intval',array_column($users,'user_id'));$placeholders=implode(',',array_fill(0,count($ids),'?'));
 $st=$pdo->prepare('SELECT user_id,course_id FROM teacher_courses WHERE user_id IN ('.$placeholders.')');$st->execute($ids);
 foreach($st->fetchAll(PDO::FETCH_ASSOC) as $entry)$allAssigned[(int)$entry['user_id']][]=(int)$entry['course_id'];
}
$pendingCount=(int)db_scalar("SELECT COUNT(*) FROM users WHERE role='teacher' AND registration_source='self_registration' AND registration_status='pending' AND email_verified_at IS NOT NULL");
$pageTitle='Accounts';$active='accounts.php';
function p451_sort_button($key,$label,$sort,$dir) {
 $active=$sort===$key && in_array($dir,['asc','desc'],true);
 $direction=$active?$dir:'none';
 $symbol=$direction==='asc'?'↑':($direction==='desc'?'↓':'–');
 return '<button type="button" class="p451-sort p451-browser-sort" data-key="'.e($key).'" data-direction="'.e($direction).'" aria-label="Sort '.e($label).' ascending">'.e($label).'<span class="p451-sort-indicator" aria-hidden="true">'.$symbol.'</span></button>';
}
require __DIR__.'/includes/header.php';
?>
<!-- Record-table styling is included in assets/css/style.css. -->
<div class="page-head p451-page-head"><div><h1>Account Management</h1><p>Review accounts, personnel and course assignments. Course changes do not require approval.</p></div><div class="p451-head-actions"><a class="btn btn-outline" href="<?= e(url('approvals.php')) ?>">Registration Approvals (<?= $pendingCount ?>)</a><?php if($isOfficer): ?><button type="button" class="btn btn-primary" id="p451-add-toggle" aria-expanded="false" aria-controls="p451-add-panel">+ Add Account</button><?php endif; ?></div></div>
<?php if(!$isOfficer): ?><div class="alert alert-info">Read-only access: TVL Head can view all school accounts and review registrations or personnel requests, but cannot edit or delete accounts here.</div><?php endif; ?>
<?php if($problems): ?><div class="alert alert-error" role="alert"><?php foreach($problems as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if($isOfficer): ?>
<section class="card p451-add-panel" id="p451-add-panel" <?= !$openAdd?'hidden':'' ?>>
  <div class="p451-section-head"><div><h3>Add a new account</h3><p class="muted">For administrator-created accounts. Teacher self-registration remains available separately.</p></div><button type="button" class="btn btn-outline p451-close-add">Close</button></div>
  <form method="post" autocomplete="off" class="p451-account-form" data-role-form><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
    <?php $v=$openAdd?$posted:[]; $prefix='new'; include __DIR__.'/includes/p451_account_fields.php'; ?>
    <div class="p451-form-actions"><button class="btn btn-primary" type="submit">Create account</button><button type="button" class="btn btn-outline p451-close-add">Cancel</button></div>
  </form>
</section>
<?php endif; ?>
<section class="card p451-list-card p3822-account-browser"><form method="get" class="p451-filters p3822-filters" id="p3822-account-filters" aria-label="Account filters">
<div class="field p451-search"><label for="p451-search">Search accounts</label><input id="p451-search" class="input" name="q" value="<?= e($q) ?>" type="search" placeholder="Search accounts"></div>
<div class="field"><label for="p451-role">Role</label><select class="input" id="p451-role" name="role"><option value="">All roles</option><?php foreach($roles as $r): ?><option value="<?= e($r) ?>" <?= $roleF===$r?'selected':'' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="p451-status">Access</label><select class="input" id="p451-status" name="status"><option value="">Any status</option><option value="active" <?= $statusF==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $statusF==='inactive'?'selected':'' ?>>Inactive</option></select></div>
<div class="field"><label for="p451-approval">Registration</label><select class="input" id="p451-approval" name="approval"><option value="">All decisions</option><?php foreach(['pending','approved','rejected'] as $value): ?><option value="<?= e($value) ?>" <?= $approvalF===$value?'selected':'' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
<details class="p3822-advanced" id="p3822-more-filters"><summary>More filters <span aria-hidden="true">⌄</span></summary><div class="p3822-advanced-grid"><div class="field"><label for="p451-level">Teaching level</label><select class="input" name="level" id="p451-level"><option value="">All levels</option><?php foreach($levels as $value): ?><option value="<?= e($value) ?>" <?= $levelF===$value?'selected':'' ?>><?= e($value==='JHS_SHS'?'Both JHS / SHS':$value) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="p451-course">Assigned course</label><select class="input" name="course" id="p451-course"><option value="">All courses</option><?php foreach($allCourses as $course): ?><option value="<?= (int)$course['course_id'] ?>" <?= $courseF===(int)$course['course_id']?'selected':'' ?>><?= e($course['course_name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="p451-from">Registered from</label><input class="input" type="date" id="p451-from" name="from" value="<?= e($from) ?>"></div>
<div class="field"><label for="p451-to">Registered through</label><input class="input" type="date" id="p451-to" name="to" value="<?= e($to) ?>"></div>
</div></details>
<div class="p451-filter-actions"><button type="submit" class="btn btn-primary" id="p3822-apply">Apply filters</button><a id="p3822-reset" href="<?= e(url('accounts.php')) ?>" class="btn btn-outline">Reset</a></div>

</form>
<div class="p451-table-heading p3822-list-toolbar"><div class="p3822-heading"><strong>School accounts</strong><span class="muted" id="p3822-account-count" aria-live="polite"><?= count($users) ?> matching account(s)</span></div><div class="p3822-table-actions"><label class="p3822-toolbar-select"><span>Quick sort</span><select id="p3822-quick-sort" class="input"><option value="default">Default order</option><option value="name:asc">Account A–Z</option><option value="name:desc">Account Z–A</option><option value="role:asc">Role A–Z</option><option value="role:desc">Role Z–A</option><option value="courses:desc">Most assigned courses</option><option value="courses:asc">Fewest assigned courses</option><option value="status:asc">Access A–Z</option><option value="status:desc">Access Z–A</option><option value="approval:asc">Registration A–Z</option><option value="approval:desc">Registration Z–A</option></select></label><label class="record-pagesize"><span>Show</span><select id="account-page-size" class="input"><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></label></div></div>
<div class="table-wrap record-table-wrap"><table class="table p451-table record-table accounts-compact-table" id="accounts-list"><thead><tr>
<th><?= p451_sort_button('name','Account',$sort,$dir) ?></th><th><?= p451_sort_button('role','Role',$sort,$dir) ?></th><th><?= p451_sort_button('courses','Courses',$sort,$dir) ?></th><th><?= p451_sort_button('status','Access',$sort,$dir) ?></th><th><?= p451_sort_button('approval','Registration',$sort,$dir) ?></th><th>Details</th>
</tr></thead><tbody>
<tr class="p3822-empty" hidden><td colspan="6" class="muted">No accounts match these filters.</td></tr>
<?php foreach($users as $u): $uid=(int)$u['user_id']; ?>
<tr class="p451-main-row" data-account-id="<?= $uid ?>" id="account-row-<?= $uid ?>" data-name="<?= e($u['full_name']) ?>" data-email="<?= e($u['email']) ?>" data-role="<?= e($u['role']) ?>" data-status="<?= e($u['status']) ?>" data-approval="<?= e($u['registration_status']) ?>" data-level="<?= e($u['teaching_level']??'') ?>" data-courses="<?= e(implode(',',array_map('intval',$allAssigned[$uid]??[]))) ?>" data-course-count="<?= count($allAssigned[$uid]??[]) ?>" data-registered="<?= e(substr((string)$u['created_at'],0,10)) ?>">
 <td><strong><?= e($u['full_name']) ?></strong><small class="record-subline"><?= e($u['email']) ?></small></td>
 <td><?= e(role_label($u['role'])) ?></td>
 <td><span class="record-value"><?= $u['role']==='teacher' ? count($allAssigned[$uid]??[]).' assigned' : '–' ?></span></td>
 <td><span class="pill <?= $u['status']==='active'?'green':'gray' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
 <td><span class="pill <?= $u['registration_status']==='approved'?'green':($u['registration_status']==='pending'?'orange':'red') ?>"><?= e(ucfirst($u['registration_status'])) ?></span></td>
 <td><button type="button" class="btn btn-outline btn-sm p451-expand" aria-expanded="false" aria-controls="p451-detail-<?= $uid ?>" data-target="p451-detail-<?= $uid ?>">View Details <span aria-hidden="true">⌄</span></button></td>
</tr>
<tr id="p451-detail-<?= $uid ?>" class="p451-detail-row" data-account-detail="<?= $uid ?>" hidden><td colspan="6"><div class="p451-expanded record-detail-panel">
 <div class="p451-expanded-header"><h3><?= e($u['full_name']) ?></h3><button type="button" class="btn btn-outline btn-sm p451-close-row" data-target="p451-detail-<?= $uid ?>">Close details ↑</button></div>
 <div class="record-facts">
  <div><span>Email</span><strong><?= e($u['email']) ?></strong></div>
  <div><span>Teaching level</span><strong><?= e($u['teaching_level']==='JHS_SHS'?'Both JHS / SHS':($u['teaching_level']?:'–')) ?></strong></div>
  <div><span>Teaching position / office</span><strong><?= e($u['teaching_position']?:'–') ?><?= $u['position_office']?' · '.e($u['position_office']):'' ?></strong></div>
  <div><span>Registered</span><strong><?= e(date('M j, Y · g:i A',strtotime($u['created_at']))) ?></strong></div>
 </div>
 <div class="record-note"><strong>Assigned courses</strong><p><?= e($u['course_list']?:'None recorded') ?></p></div>
 <?php if($isOfficer): ?>
  <div class="record-account-actions">
   <?php if($uid!==$myId): ?><form method="post" class="inline" onsubmit="return confirm('<?= $u['status']==='active'?'Deactivate':'Reactivate' ?> this account?')"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $uid ?>"><button class="btn btn-sm p451-toggle" type="submit"><?= $u['status']==='active'?'Deactivate':'Reactivate' ?></button></form>
     <?php if($u['role']==='teacher'): ?><button type="button" class="btn btn-sm p451-delete" data-delete-id="<?= $uid ?>" data-delete-email="<?= e($u['email']) ?>">Delete…</button><?php endif; ?>
   <?php endif; ?>
  </div>
  <details class="record-edit-section" <?= $openEdit===$uid?'open':'' ?>><summary>Edit account information <span aria-hidden="true">⌄</span></summary>
   <form method="post" autocomplete="off" class="p451-account-form" data-role-form><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $uid ?>">
    <?php $v=$u;$v['courses']=$allAssigned[$uid]??[];if($openEdit===$uid)$v=array_merge($v,$posted);$prefix='edit-'.$uid;include __DIR__.'/includes/p451_account_fields.php'; ?>
    <div class="p451-form-actions"><button type="submit" class="btn btn-primary">Save changes</button><button type="button" class="btn btn-outline p451-close-row" data-target="p451-detail-<?= $uid ?>">Cancel</button></div>
   </form>
  </details>
 <?php else: ?><p class="muted">This account is available for viewing only.</p><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="record-pagination" aria-label="Account pages"><button type="button" class="btn btn-outline btn-sm" id="accounts-prev">Previous</button><span id="accounts-pages-label"></span><button type="button" class="btn btn-outline btn-sm" id="accounts-next">Next</button></div>
<script>
(function(){
 'use strict';
 const table=document.getElementById('accounts-list');
 const form=document.getElementById('p3822-account-filters');
 if(!table||!form)return;
 const tbody=table.tBodies[0];
 const rows=Array.from(tbody.querySelectorAll('tr[data-account-id]'));
 const originals=rows.map((row,index)=>({row,detail:document.getElementById('p451-detail-'+row.dataset.accountId),index}));
 const buttons=Array.from(table.querySelectorAll('.p451-browser-sort'));
 const quick=document.getElementById('p3822-quick-sort');
 const pageSize=document.getElementById('account-page-size');
 const label=document.getElementById('accounts-pages-label');
 const prev=document.getElementById('accounts-prev');
 const next=document.getElementById('accounts-next');
 const count=document.getElementById('p3822-account-count');
 const empty=tbody.querySelector('.p3822-empty');
 const reset=document.getElementById('p3822-reset');
 const advanced=document.getElementById('p3822-more-filters');
 const filters={};
 ['q','role','status','approval','level','course','from','to'].forEach(key=>filters[key]=form.elements.namedItem(key));
 let page=1,sortKey=<?= json_encode($sort) ?>,sortDirection=<?= json_encode($dir?:'none') ?>;
 const read=key=>String(filters[key]?.value||'').trim();
 const norm=value=>String(value||'').toLocaleLowerCase();
 function eligible(row){
   const d=row.dataset;
   const needle=norm(read('q'));
   if(needle && !norm(d.name+' '+d.email).includes(needle))return false;
   if(read('role') && d.role!==read('role'))return false;
   if(read('status') && d.status!==read('status'))return false;
   if(read('approval') && d.approval!==read('approval'))return false;
   if(read('level') && d.level!==read('level'))return false;
   if(read('course') && !String(d.courses||'').split(',').includes(read('course')))return false;
   if(read('from') && d.registered<read('from'))return false;
   if(read('to') && d.registered>read('to'))return false;
   return true;
 }
 function compare(a,b){
   if(sortDirection==='none'||!sortKey)return a.index-b.index;
   let va=a.row.dataset[sortKey]||'',vb=b.row.dataset[sortKey]||'';
   if(sortKey==='courses'){va=Number(a.row.dataset.courseCount||0);vb=Number(b.row.dataset.courseCount||0);}
   const sign=sortDirection==='asc'?1:-1;
   return sign*(typeof va==='number'?va-vb:String(va).localeCompare(String(vb),undefined,{numeric:true,sensitivity:'base'}))||a.index-b.index;
 }
 function closeInvisible(record){
   if(!record.detail)return;
   record.detail.hidden=true;
   const button=record.row.querySelector('.p451-expand');
   if(button)button.setAttribute('aria-expanded','false');
 }
 function render(){
   const sorted=originals.slice().sort(compare);
   const matching=sorted.filter(r=>eligible(r.row));
   const total=Math.max(1,Math.ceil(matching.length/Number(pageSize.value||10)));
   page=Math.max(1,Math.min(page,total));
   const start=(page-1)*Number(pageSize.value||10);
   const visibleIds=new Set(matching.slice(start,start+Number(pageSize.value||10)).map(x=>x.index));
   const frag=document.createDocumentFragment();
   sorted.forEach(record=>{
     const visible=visibleIds.has(record.index);
     record.row.hidden=!visible;
     if(!visible)closeInvisible(record);
     frag.appendChild(record.row);
     if(record.detail)frag.appendChild(record.detail);
   });
   if(empty){empty.hidden=matching.length>0;frag.appendChild(empty);}
   tbody.appendChild(frag);
   count.textContent=matching.length+' matching account(s)';
   label.textContent='Page '+page+' of '+total;
   prev.disabled=page<=1;next.disabled=page>=total;
 }
 function reflectSort(){
   buttons.forEach(button=>{
     const active=sortKey===button.dataset.key?sortDirection:'none';
     button.dataset.direction=active;
     button.querySelector('.p451-sort-indicator').textContent=active==='asc'?'↑':active==='desc'?'↓':'–';
     button.closest('th').removeAttribute('aria-sort');
     if(active!=='none')button.closest('th').setAttribute('aria-sort',active==='asc'?'ascending':'descending');
     button.setAttribute('aria-label','Sort '+button.textContent.replace(/[↑↓–]/g,'').trim()+': '+(active==='none'?'default':active)+'. Click to '+(active==='none'?'sort ascending':active==='asc'?'sort descending':'return to default'));
   });
   const option=sortDirection==='none'?'default':sortKey+':'+sortDirection;
   quick.value=Array.from(quick.options).some(o=>o.value===option)?option:'default';
 }
 function setSort(key,direction){sortKey=direction==='none'?'':key;sortDirection=direction;page=1;reflectSort();render();}
 buttons.forEach(button=>button.addEventListener('click',()=>{
   const next=sortKey!==button.dataset.key?'asc':sortDirection==='asc'?'desc':sortDirection==='desc'?'none':'asc';
   setSort(button.dataset.key,next);
 }));
 quick.addEventListener('change',()=>{
   const [key,direction]=quick.value==='default'?['','none']:quick.value.split(':');setSort(key,direction);
 });
 pageSize.addEventListener('change',()=>{page=1;render();});
 prev.addEventListener('click',()=>{page--;render();});
 next.addEventListener('click',()=>{page++;render();});
 // Keep GET form fallback for disabled JS, but filter locally when JS is active.
 form.addEventListener('submit',event=>{event.preventDefault();page=1;render();});
 ['role','status','approval','level','course','from','to'].forEach(key=>filters[key]?.addEventListener('change',()=>{page=1;render();}));
 filters.q?.addEventListener('input',()=>{page=1;render();});
 if(['level','course','from','to'].some(key=>read(key)!==''))advanced.open=true;
 reset.addEventListener('click',event=>{
   // If this page was previously narrowed by a server-side URL query, reload once
   // to restore accounts that were never included in this response.
   const query=new URLSearchParams(location.search);
   if(Array.from(query.keys()).some(k=>['q','role','status','approval','level','course','from','to','sort','dir'].includes(k)))return;
   event.preventDefault();form.reset();advanced.open=false;setSort('','none');
 });
 const target=new URLSearchParams(location.search).get('account') || <?= json_encode($openEdit>0?(string)$openEdit:'') ?>;
 if(target){const position=originals.findIndex(r=>r.row.dataset.accountId===target);if(position>=0){page=Math.floor(position/Number(pageSize.value||10))+1;render();const detail=originals[position].detail;if(detail){detail.hidden=false;originals[position].row.querySelector('.p451-expand')?.setAttribute('aria-expanded','true');}}}
 reflectSort();render();
})();
</script>
</section>
<?php if($isOfficer): ?>
<dialog id="tnts-delete-dialog" class="p451-delete-dialog"><form method="post" class="p451-delete-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="tnts-delete-id"><h3>Permanently delete account?</h3><p>Only teachers without protected ICS, inventory, transaction or audit records may be deleted. Approval status alone is not a blocker. This cannot be undone.</p><p>Confirm by typing <strong id="tnts-delete-email-label"></strong></p><input class="input" type="email" name="confirm_email" id="tnts-delete-confirm" autocomplete="off" required><div class="p451-form-actions"><button class="btn btn-outline" type="button" id="p451-cancel-delete">Cancel</button><button type="submit" class="btn p451-delete">Permanently Delete</button></div></form></dialog>
<?php endif; ?>
<script>
(function(){
  const addBtn=document.getElementById('p451-add-toggle'), panel=document.getElementById('p451-add-panel');
  function showAdd(show){if(!panel)return;panel.hidden=!show;if(addBtn)addBtn.setAttribute('aria-expanded',String(show));if(show)panel.scrollIntoView({behavior:'smooth',block:'start'});}
  if(addBtn)addBtn.addEventListener('click',()=>showAdd(panel.hidden));
  document.querySelectorAll('.p451-close-add').forEach(b=>b.addEventListener('click',()=>showAdd(false)));
  function detail(id,show){let row=document.getElementById(id);if(!row)return;
   document.querySelectorAll('.p451-detail-row').forEach(r=>{if(r!==row)r.hidden=true});
   document.querySelectorAll('.p451-expand').forEach(b=>b.setAttribute('aria-expanded',String(b.dataset.target===id&&show)));
   row.hidden=!show;if(show)row.scrollIntoView({block:'nearest',behavior:'smooth'});
  }
  document.querySelectorAll('.p451-expand').forEach(b=>b.addEventListener('click',()=>detail(b.dataset.target,document.getElementById(b.dataset.target).hidden)));
  document.querySelectorAll('.p451-close-row').forEach(b=>b.addEventListener('click',()=>detail(b.dataset.target,false)));
  document.querySelectorAll('[data-role-form]').forEach(form=>{
   const role=form.querySelector('[name="role"]'),section=form.querySelector('[data-teacher-settings]');
   function update(){if(section&&role)section.hidden=role.value!=='teacher';}
   if(role)role.addEventListener('change',update);update();
   const reveal=form.querySelector('[data-password-toggle]'),password=form.querySelector('[name="password"]');
   if(reveal&&password)reveal.addEventListener('change',()=>{password.type=reveal.checked?'text':'password';});
  });
  const dialog=document.getElementById('tnts-delete-dialog');
  document.querySelectorAll('[data-delete-id]').forEach(b=>b.addEventListener('click',()=>{
    if(!dialog)return;document.getElementById('tnts-delete-id').value=b.dataset.deleteId;
    document.getElementById('tnts-delete-email-label').textContent=b.dataset.deleteEmail;
    document.getElementById('tnts-delete-confirm').value='';dialog.showModal();
  }));
  document.getElementById('p451-cancel-delete')?.addEventListener('click',()=>dialog.close());
  <?php if($openEdit>0): ?>detail('p451-detail-<?= (int)$openEdit ?>',true);<?php endif; ?>
  <?php if($openAdd): ?>showAdd(true);<?php endif; ?>
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
