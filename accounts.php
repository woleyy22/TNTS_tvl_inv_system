<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_role(['supply_officer']);
$myId = (int)$me['id'];
$pdo = db();

$validRoles = ['supply_officer', 'tvl_head', 'teacher'];
$allCourses = $pdo->query('SELECT course_id, course_name FROM courses ORDER BY course_name')->fetchAll();
$validCourseIds = [];
foreach ($allCourses as $c) { $validCourseIds[] = (int)$c['course_id']; }

$errors = [];
$form = ['id' => 0, 'full_name' => '', 'email' => '', 'role' => 'teacher', 'courses' => []];

// ---------- handle form posts ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // activate / deactivate
    if ($action === 'toggle') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id === $myId) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $st = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE user_id = ?");
            $st->execute([$id]);
            log_action($myId, 'change status', 'Accounts', $id, null);
            flash('success', 'Account status updated.');
        }
        redirect('accounts.php');
    }

    // add or edit
    if ($action === 'save') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        $name = trim(isset($_POST['full_name']) ? $_POST['full_name'] : '');
        $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $picked = isset($_POST['courses']) && is_array($_POST['courses']) ? array_map('intval', $_POST['courses']) : [];

        $form = ['id' => $id, 'full_name' => $name, 'email' => $email, 'role' => $role, 'courses' => $picked];

        if ($name === '') { $errors[] = 'Full name is required.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
        if (!in_array($role, $validRoles, true)) { $errors[] = 'Choose a role.'; }
        if ($id === 0 && strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
        if ($id > 0 && $password !== '' && strlen($password) < 8) { $errors[] = 'New password must be at least 8 characters.'; }
        if ($id === $myId && $role !== 'supply_officer') { $errors[] = 'You cannot change your own role.'; }

        $st = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
        $st->execute([$email, $id]);
        if ($st->fetch()) { $errors[] = 'That email address is already used by another account.'; }

        if (!$errors) {
            if ($id === 0) {
                $st = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?,?,?,?,?)');
                $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'active']);
                $id = (int)$pdo->lastInsertId();
                log_action($myId, 'create', 'Accounts', $id, $name . ' (' . role_label($role) . ')');
            } else {
                $st = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ? WHERE user_id = ?');
                $st->execute([$name, $email, $role, $id]);
                if ($password !== '') {
                    $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                    $st->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                log_action($myId, 'update', 'Accounts', $id, $name);
            }

            // courses are only for teachers
            $pdo->prepare('DELETE FROM teacher_courses WHERE user_id = ?')->execute([$id]);
            if ($role === 'teacher') {
                $ins = $pdo->prepare('INSERT IGNORE INTO teacher_courses (user_id, course_id) VALUES (?, ?)');
                foreach ($picked as $cid) {
                    if (in_array($cid, $validCourseIds, true)) { $ins->execute([$id, $cid]); }
                }
            }
            flash('success', 'Account saved.');
            redirect('accounts.php');
        }
    }
} elseif (isset($_GET['edit'])) {
    // load an account into the form
    $editId = (int)$_GET['edit'];
    $st = $pdo->prepare('SELECT user_id, full_name, email, role FROM users WHERE user_id = ?');
    $st->execute([$editId]);
    $u = $st->fetch();
    if ($u) {
        $st = $pdo->prepare('SELECT course_id FROM teacher_courses WHERE user_id = ?');
        $st->execute([$editId]);
        $mine = [];
        foreach ($st->fetchAll() as $r) { $mine[] = (int)$r['course_id']; }
        $form = ['id' => (int)$u['user_id'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role'], 'courses' => $mine];
    }
}

// ---------- list with search / filter ----------
$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
$roleF = isset($_GET['role']) ? $_GET['role'] : '';

$sql = "SELECT u.*,
        (SELECT GROUP_CONCAT(c.course_name ORDER BY c.course_name SEPARATOR ', ')
           FROM teacher_courses tc JOIN courses c ON c.course_id = tc.course_id
          WHERE tc.user_id = u.user_id) AS course_list
        FROM users u WHERE 1 = 1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($roleF, $validRoles, true)) {
    $sql .= ' AND u.role = ?';
    $params[] = $roleF;
}
$sql .= ' ORDER BY u.role ASC, u.full_name ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

$total = (int)db_scalar('SELECT COUNT(*) FROM users');
$cntOfficer = (int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'supply_officer'");
$cntHead = (int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'tvl_head'");
$cntTeacher = (int)db_scalar("SELECT COUNT(*) FROM users WHERE role = 'teacher'");

$pageTitle = 'Accounts';
$active = 'accounts.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Account Management</h1>
    <p>Create accounts, assign roles and courses, and activate or deactivate users.</p>
</div>

<div class="stats">
    <div class="card stat"><div><div class="stat-label">Total accounts</div><div class="stat-value red"><?= $total ?></div></div><div class="stat-icon red"><?= icon('users', 24) ?></div></div>
    <div class="card stat"><div><div class="stat-label">Supply Officers</div><div class="stat-value purple"><?= $cntOfficer ?></div></div><div class="stat-icon purple"><?= icon('clip', 24) ?></div></div>
    <div class="card stat"><div><div class="stat-label">TVL Head</div><div class="stat-value orange"><?= $cntHead ?></div></div><div class="stat-icon orange"><?= icon('book', 24) ?></div></div>
    <div class="card stat"><div><div class="stat-label">Teachers</div><div class="stat-value green"><?= $cntTeacher ?></div></div><div class="stat-icon green"><?= icon('check', 24) ?></div></div>
</div>

<div class="card">
    <h3><?= $form['id'] > 0 ? 'Edit account' : 'Add account' ?></h3>
    <?php foreach ($errors as $er): ?>
        <div class="alert alert-error"><?= e($er) ?></div>
    <?php endforeach; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="form-grid">
            <div class="field">
                <label for="full_name">Full name</label>
                <input class="input" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input class="input" type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select class="input" id="role" name="role">
                    <?php foreach ($validRoles as $r): ?>
                        <option value="<?= e($r) ?>"<?= $form['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="password"><?= $form['id'] > 0 ? 'New password (leave blank to keep)' : 'Password (at least 8 characters)' ?></label>
                <input class="input" type="password" id="password" name="password" <?= $form['id'] > 0 ? '' : 'required' ?>>
            </div>
        </div>

        <div class="field" id="courseBox">
            <label>Courses handled (teachers only)</label>
            <?php if (!$allCourses): ?>
                <p class="muted">No courses yet. Add them on the Courses page first.</p>
            <?php else: ?>
                <div class="check-grid">
                    <?php foreach ($allCourses as $c): ?>
                        <label class="check">
                            <input type="checkbox" name="courses[]" value="<?= (int)$c['course_id'] ?>"<?= in_array((int)$c['course_id'], $form['courses'], true) ? ' checked' : '' ?>>
                            <span><?= e($c['course_name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $form['id'] > 0 ? 'Save changes' : 'Add account' ?></button>
            <?php if ($form['id'] > 0): ?>
                <a class="btn btn-outline" href="<?= e(url('accounts.php')) ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <form method="get" class="filters">
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or email">
        <select class="input" name="role">
            <option value="">All roles</option>
            <?php foreach ($validRoles as $r): ?>
                <option value="<?= e($r) ?>"<?= $roleF === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Courses</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="6" class="muted">No accounts found.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(role_label($u['role'])) ?></td>
                    <td><?= e($u['course_list'] ? $u['course_list'] : '-') ?></td>
                    <td><?= $u['status'] === 'active' ? '<span class="pill green">Active</span>' : '<span class="pill gray">Inactive</span>' ?></td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('accounts.php?edit=' . (int)$u['user_id'])) ?>">Edit</a>
                        <?php if ((int)$u['user_id'] !== $myId): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Change this account\'s status?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$u['user_id'] ?>">
                                <button class="btn btn-outline btn-sm" type="submit"><?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function () {
        var sel = document.getElementById('role');
        var box = document.getElementById('courseBox');
        function sync() { box.style.display = (sel.value === 'teacher') ? 'block' : 'none'; }
        sel.addEventListener('change', sync);
        sync();
    })();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
