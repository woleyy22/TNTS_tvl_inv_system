<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer', 'tvl_head']);
$myId = (int)$me['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $name = trim(isset($_POST['course_name']) ? $_POST['course_name'] : '');

    if ($action === 'add' || $action === 'rename') {
        if ($name === '') {
            flash('error', 'Course name is required.');
        } else {
            $st = $pdo->prepare('SELECT course_id FROM courses WHERE course_name = ? AND course_id <> ?');
            $st->execute([$name, $action === 'rename' ? $id : 0]);
            if ($st->fetch()) {
                flash('error', 'That course already exists.');
            } elseif ($action === 'add') {
                $pdo->prepare('INSERT INTO courses (course_name) VALUES (?)')->execute([$name]);
                log_action($myId, 'create', 'Courses', (int)$pdo->lastInsertId(), $name);
                flash('success', 'Course added.');
            } else {
                $pdo->prepare('UPDATE courses SET course_name = ? WHERE course_id = ?')->execute([$name, $id]);
                log_action($myId, 'update', 'Courses', $id, $name);
                flash('success', 'Course renamed.');
            }
        }
    } elseif ($action === 'delete') {
        $inUse = (int)db_scalar('SELECT COUNT(*) FROM teacher_courses WHERE course_id = ?', [$id])
               + (int)db_scalar('SELECT COUNT(*) FROM ics WHERE course_id = ?', [$id]);
        if ($inUse > 0) {
            flash('error', 'This course is in use by teachers or ICS records, so it cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM courses WHERE course_id = ?')->execute([$id]);
            log_action($myId, 'delete', 'Courses', $id, null);
            flash('success', 'Course deleted.');
        }
    }
    redirect('courses.php');
}

$courses = $pdo->query(
    'SELECT c.course_id, c.course_name,
            (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id = c.course_id) AS teachers,
            (SELECT COUNT(*) FROM ics i WHERE i.course_id = c.course_id) AS ics_count
     FROM courses c ORDER BY c.course_name ASC')->fetchAll();

$pageTitle = 'Courses';
$active = 'courses.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Courses</h1>
    <p>The electives offered this year. Teachers are assigned to courses on the Accounts page.</p>
</div>

<div class="card">
    <h3>Add course</h3>
    <form method="post" class="filters">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input class="input" name="course_name" placeholder="Course name (for example, Cookery)" required>
        <button class="btn btn-primary" type="submit">Add course</button>
    </form>
</div>

<div class="card">
    <h3>All courses (<?= count($courses) ?>)</h3>
    <?php if (!$courses): ?>
        <p class="muted">No courses yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Course name</th><th>Teachers</th><th>ICS records</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($courses as $c): ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-edit">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>">
                                <input class="input" name="course_name" value="<?= e($c['course_name']) ?>" required>
                                <button class="btn btn-outline btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?= (int)$c['teachers'] ?></td>
                        <td><?= (int)$c['ics_count'] ?></td>
                        <td class="row-actions">
                            <form method="post" class="inline" onsubmit="return confirm('Delete this course?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['course_id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
