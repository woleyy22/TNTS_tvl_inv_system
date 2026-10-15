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
    <section class="login-intro" aria-label="About this workspace"><div class="eyebrow">TVL / TechPro Workspace</div><h2>Organized assets.<br>Accountable teams.</h2><p>A central workspace for equipment, deliveries, custody records, and maintenance.</p><div class="login-feature">Inventory &amp; custody tracking</div><div class="login-feature">Equipment condition &amp; repair logs</div><div class="login-feature">School reports &amp; documentation</div></section>
    <div class="login-card">
        <div class="login-logo"><?= school_logo() ?></div>
        <div class="eyebrow login-kicker">Tanza National Trade School</div>
        <h1>Welcome back</h1>
        <p class="login-sub">Sign in to your TVL / TechPro inventory workspace.</p>

        <?php foreach (get_flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email Address</label>
                <input class="input" type="email" id="email" name="email" autocomplete="username" value="<?= e($email) ?>" placeholder="you@tnts.edu.ph" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" type="password" id="password" name="password" autocomplete="current-password" placeholder="Your password" required>
            </div>
            <label class="password-visibility-option" for="loginShowPassword"><input type="checkbox" id="loginShowPassword"> Show password</label>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
        <p class="login-help">Need access? Contact your Supply Officer.</p>
    </div>
<script>
    document.getElementById('loginShowPassword').addEventListener('change', function () {
        document.getElementById('password').type = this.checked ? 'text' : 'password';
    });
</script>
</body>
</html>
