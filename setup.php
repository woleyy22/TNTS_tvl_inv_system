<?php
// One-time page: creates the FIRST Supply Officer account.
// It refuses to run once any user exists. Delete this file after you use it.
require_once __DIR__ . '/includes/auth.php';

$count = (int)db_scalar('SELECT COUNT(*) FROM users');
$done = false;
$errors = [];
$name = '';
$email = '';

if ($count === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim(isset($_POST['full_name']) ? $_POST['full_name'] : '');
    $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($name === '') { $errors[] = 'Full name is required.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
    if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }

    if (!$errors) {
        $st = db()->prepare('INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?,?,?,?,?)');
        $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'supply_officer', 'active']);
        log_action((int)db()->lastInsertId(), 'create', 'Accounts', (int)db()->lastInsertId(), 'First Supply Officer account created');
        $done = true;
        $count = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>First-time setup | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-logo"><?= icon('cap', 34) ?></div>
        <h1>First-time setup</h1>

        <?php if ($done): ?>
            <div class="alert alert-success">The Supply Officer account was created.</div>
            <p class="muted">For safety, delete <code>setup.php</code> from the project folder now.</p>
            <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Go to login</a>
        <?php elseif ($count > 0): ?>
            <p class="login-sub">Setup is already done. Delete <code>setup.php</code> from the project folder.</p>
            <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Go to login</a>
        <?php else: ?>
            <p class="login-sub">Create the first Supply Officer (admin) account.</p>
            <?php foreach ($errors as $er): ?>
                <div class="alert alert-error"><?= e($er) ?></div>
            <?php endforeach; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="full_name">Full name</label>
                    <input class="input" id="full_name" name="full_name" value="<?= e($name) ?>" required>
                </div>
                <div class="field">
                    <label for="email">Email address</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" required>
                </div>
                <div class="field">
                    <label for="password">Password (at least 8 characters)</label>
                    <input class="input" type="password" id="password" name="password" required>
                </div>
                <button class="btn btn-primary btn-block" type="submit">Create account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
