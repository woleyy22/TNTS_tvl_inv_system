<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer', 'tvl_head']);
$pdo = db();
$myId = (int)$me['id'];
if ($me['role']==='tvl_head') {
    $readonly = $pdo->query("SELECT t.track_name,t.track_code,t.is_active,c.course_name FROM academic_tracks t LEFT JOIN courses c ON c.track_id=t.track_id ORDER BY t.track_name,c.course_name")->fetchAll(PDO::FETCH_ASSOC);
    $pageTitle='Academic Tracks & Courses (View Only)'; $active='courses.php';
    include __DIR__.'/includes/header.php';
    echo '<div class="card"><h2>Academic Tracks &amp; Courses</h2><p>View-only access for TVL Head.</p><div class="table-wrap"><table class="table"><thead><tr><th>Track</th><th>Code</th><th>Course</th><th>Status</th></tr></thead><tbody>';
    foreach($readonly as $line) echo '<tr><td>'.e($line['track_name']).'</td><td>'.e($line['track_code']).'</td><td>'.e($line['course_name']??'—').'</td><td>'.($line['is_active']?'Active':'Inactive').'</td></tr>';
    echo '</tbody></table></div></div>';
    include __DIR__.'/includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $trackId = (int)($_POST['track_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    try {
        if (in_array($action, ['add_track', 'rename_track'], true)) {
            if ($name === '' || ($action === 'add_track' && $code === '')) throw new RuntimeException('Track name and code are required.');
            if ($action === 'add_track') {
                $pdo->prepare('INSERT INTO academic_tracks (track_name,track_code) VALUES (?,?)')->execute([$name,$code]);
                $id=(int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE academic_tracks SET track_name=? WHERE track_id=?')->execute([$name,$id]);
            }
            log_action($myId,$action,'Academic Tracks',$id,$name);
            flash('success','Track saved.');
        } elseif ($action === 'toggle_track') {
            $pdo->prepare('UPDATE academic_tracks SET is_active=1-is_active WHERE track_id=?')->execute([$id]);
            log_action($myId,$action,'Academic Tracks',$id,null);
            flash('success','Track availability updated.');
        } elseif (in_array($action,['add_course','rename_course'],true)) {
            if ($name === '') throw new RuntimeException('Course name is required.');
            if ($trackId < 1 || !(int)db_scalar('SELECT COUNT(*) FROM academic_tracks WHERE track_id=?',[$trackId])) throw new RuntimeException('Select a valid track.');
            $st=$pdo->prepare('SELECT COUNT(*) FROM courses WHERE track_id=? AND LOWER(course_name)=LOWER(?) AND course_id<>?');
            $st->execute([$trackId,$name,$action==='rename_course'?$id:0]);
            if ((int)$st->fetchColumn()) throw new RuntimeException('This course already exists within the selected track.');
            if ($action === 'rename_course') {
                $old=$pdo->prepare('SELECT track_id FROM courses WHERE course_id=?'); $old->execute([$id]);
                $oldTrack=$old->fetchColumn();
                if ($oldTrack !== false && (int)$oldTrack !== $trackId) {
                    $usage=(int)db_scalar('SELECT COUNT(*) FROM ics WHERE course_id=?',[$id]);
                    if ($usage) throw new RuntimeException('This course has historical ICS records. Rename it, but do not move it between tracks.');
                }
            }
            if ($action === 'add_course') {
                $pdo->prepare('INSERT INTO courses(course_name,track_id) VALUES (?,?)')->execute([$name,$trackId]);
                $id=(int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE courses SET course_name=?,track_id=? WHERE course_id=?')->execute([$name,$trackId,$id]);
            }
            log_action($myId,$action,'Courses',$id,$name);
            flash('success','Course saved.');
        } elseif ($action === 'delete_course') {
            $inUse=(int)db_scalar('SELECT COUNT(*) FROM teacher_courses WHERE course_id=?',[$id])+(int)db_scalar('SELECT COUNT(*) FROM ics WHERE course_id=?',[$id]);
            if ($inUse) throw new RuntimeException('This course is used in teacher assignments or historical ICS records. It cannot be deleted.');
            $pdo->prepare('DELETE FROM courses WHERE course_id=?')->execute([$id]);
            log_action($myId,'delete','Courses',$id,null);
            flash('success','Unused course removed.');
        }
    } catch (PDOException $e) {
        flash('error','Unable to save. Check for duplicate names/codes or whether Phase 1 SQL has been applied.');
    } catch (RuntimeException $e) { flash('error',$e->getMessage()); }
    redirect('courses.php');
}
$tracks=$pdo->query('SELECT t.*, (SELECT COUNT(*) FROM courses c WHERE c.track_id=t.track_id) AS courses_count FROM academic_tracks t ORDER BY t.track_name')->fetchAll();
$courses=$pdo->query('SELECT c.course_id,c.course_name,c.track_id,t.track_name, (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id=c.course_id) AS teachers, (SELECT COUNT(*) FROM ics i WHERE i.course_id=c.course_id) AS ics_count FROM courses c LEFT JOIN academic_tracks t ON c.track_id=t.track_id ORDER BY t.track_name,c.course_name')->fetchAll();
$pageTitle='Academic Tracks & Courses'; $active='courses.php';
include __DIR__.'/includes/header.php';
?>
<div class="page-head"><h1>Academic Tracks &amp; Courses</h1><p>Manage the school track structure. TVL is active; other tracks such as STEM, HUMSS, ABM or Tech-Pro can be created when needed.</p></div>
<div class="card"><h3>Add a track</h3><form method="post" class="filters"><?= csrf_field() ?><input type="hidden" name="action" value="add_track"><input class="input" name="name" maxlength="100" placeholder="Track name (e.g., Academic Track)" required><input class="input" name="code" maxlength="30" placeholder="Unique code (e.g., ACAD)" required><button class="btn btn-primary">Add Track</button></form></div>
<div class="card"><h3>Tracks (<?= count($tracks) ?>)</h3><div class="table-wrap"><table class="table"><thead><tr><th>Track</th><th>Code</th><th>Courses</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($tracks as $t): ?><tr><td><form method="post" class="inline-edit"><?= csrf_field() ?><input type="hidden" name="action" value="rename_track"><input type="hidden" name="id" value="<?= (int)$t['track_id'] ?>"><input class="input" name="name" value="<?= e($t['track_name']) ?>" required><button class="btn btn-outline btn-sm">Save</button></form></td><td><?= e($t['track_code']) ?></td><td><?= (int)$t['courses_count'] ?></td><td><?= $t['is_active']?'Active':'Inactive' ?></td><td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_track"><input type="hidden" name="id" value="<?= (int)$t['track_id'] ?>"><button class="btn btn-outline btn-sm"><?= $t['is_active']?'Deactivate':'Activate' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><h3>Add a course under a track</h3><form method="post" class="filters"><?= csrf_field() ?><input type="hidden" name="action" value="add_course"><select name="track_id" class="input" required><option value="">Choose track</option><?php foreach($tracks as $t): if(!$t['is_active']) continue; ?><option value="<?= (int)$t['track_id'] ?>"><?= e($t['track_name']) ?></option><?php endforeach; ?></select><input class="input" name="name" maxlength="100" placeholder="Course / specialization name" required><button class="btn btn-primary">Add Course</button></form></div>
<div class="card"><h3>Courses (<?= count($courses) ?>)</h3><div class="table-wrap"><table class="table"><thead><tr><th>Course / Specialization</th><th>Track</th><th>Teachers</th><th>ICS</th><th>Action</th></tr></thead><tbody><?php foreach($courses as $c): ?><tr><td colspan="2"><form class="inline-edit" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rename_course"><input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>"><input class="input" name="name" value="<?= e($c['course_name']) ?>" required><select class="input" name="track_id" required><?php foreach($tracks as $t): ?><option value="<?= (int)$t['track_id'] ?>" <?= (int)$c['track_id']===(int)$t['track_id']?'selected':'' ?>><?= e($t['track_name']) ?></option><?php endforeach; ?></select><button class="btn btn-outline btn-sm">Save</button></form></td><td><?= (int)$c['teachers'] ?></td><td><?= (int)$c['ics_count'] ?></td><td><form method="post" class="inline" onsubmit="return confirm('Delete this unused course?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_course"><input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>"><button class="btn btn-danger btn-sm" <?= ((int)$c['teachers']+(int)$c['ics_count'])?'disabled title="Course in use"':'' ?>>Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__.'/includes/footer.php'; ?>
