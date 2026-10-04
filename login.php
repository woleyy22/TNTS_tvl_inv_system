<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        $error = 'Invalid email or password.';
    } elseif ($row['status'] !== 'active') {
        $error = 'This account is inactive. Please contact the Supply Officer.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (int)$row['user_id'],
            'name'  => $row['full_name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ];
        $_SESSION['last_activity'] = time();
        log_action((int)$row['user_id'], 'login', 'Accounts', (int)$row['user_id'], null);
        redirect('dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-logo"><?= icon('cap', 34) ?></div>
        <h1><?= e(APP_NAME) ?></h1>
        <p class="login-sub">TVL Facilities &amp; Laboratories</p>

        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email Address</label>
                <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="you@tnts.edu.ph" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" type="password" id="password" name="password" placeholder="Your password" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
