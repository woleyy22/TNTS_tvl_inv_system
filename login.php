<?php
require_once __DIR__ . '/includes/auth.php';

$entering = current_user() && isset($_GET['enter']) && $_GET['enter'] === '1';
if (current_user() && !$entering) {
    redirect('dashboard.php');
}
if ($entering) {
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Opening workspace | TNTS</title><meta http-equiv="refresh" content="2;url=<?= e(url('dashboard.php')) ?>"><style>
    body{margin:0;font-family:system-ui,Arial,sans-serif;color:#20283d;background:#40162b;min-height:100dvh;display:grid;place-items:center;background-image:linear-gradient(145deg,rgba(249,250,250,.3),rgba(82,0,34,.58)),radial-gradient(ellipse at 20% 20%,rgba(170,73,102,.5),transparent 70%),url('assets/images/login-background.png');background-size:cover;background-position:center}
    .box{padding:35px 40px;text-align:center;background:#fffffffa;border-radius:21px;width:min(380px,calc(100% - 65px));box-shadow:0 20px 60px #1909154a;border-top:7px solid #801c3d}.box img{width:80px;height:80px;object-fit:contain}.box h1{font-size:23px;margin:14px 0 8px}.box p{font-size:13px;color:#66758d}.spin{width:24px;height:24px;border:3px solid #e9d9df;border-top-color:#801c3d;border-radius:50%;margin:25px auto 0;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.spin{animation:none}}
    </style></head><body><main class="box" role="status" aria-live="polite"><img src="<?= e(url('assets/images/school-logo.png')) ?>" alt="TNTS logo"><h1>Welcome to TNTS Inventory</h1><p>Opening your workspace securely…</p><div class="spin" aria-hidden="true"></div><noscript><p><a href="<?= e(url('dashboard.php')) ?>">Continue to Dashboard</a></p></noscript></main><script>setTimeout(function(){location.replace(<?= json_encode(url('dashboard.php')) ?>)},1250);</script></body></html>
    <?php
    exit;
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
    } elseif ($row['status'] !== 'active' || $row['registration_status'] !== 'approved') {
        $error = 'This account is not active or is awaiting approval. Please contact the Supply Officer or TVL Head.';
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
        redirect('login.php?enter=1');
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
    <section class="login-intro" aria-label="About this workspace"><div class="eyebrow">TVL / TechPro Workspace</div><h2>Organized assets.<br>Accountable teams.</h2><p>A central workspace for equipment, deliveries, custody records, and maintenance.</p><div class="login-feature">Inventory &amp; custody tracking</div><div class="login-feature">Equipment condition &amp; repair logs</div><div class="login-feature">School reports &amp; documentation</div><nav class="tnts-login-contact" aria-label="School contacts"><a href="https://www.facebook.com/p/DepEd-Tanza-National-Trade-School-Main-100063649497153/" target="_blank" rel="noopener noreferrer">School Facebook ↗</a><a href="mailto:tnts.inventorysystem@gmail.com">Email support ↗</a></nav></section>
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

        <form method="post" autocomplete="on" id="tnts-login-form">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email Address</label>
                <input class="input" type="email" id="email" name="email" autocomplete="username" value="<?= e($email) ?>" placeholder="you@gmail.com or you@deped.gov.ph" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" type="password" id="password" name="password" autocomplete="current-password" placeholder="Your password" required>
            </div>
            <label class="password-visibility-option" for="loginShowPassword"><input type="checkbox" id="loginShowPassword"> Show password</label>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
        </form>
        <p class="login-help">New teacher? <a id="p421RegisterLink" href="<?= e(url('register.php')) ?>">Create a teacher account</a></p>
        <div class="tnts-login-card-links"><a href="https://www.facebook.com/p/DepEd-Tanza-National-Trade-School-Main-100063649497153/" target="_blank" rel="noopener noreferrer">Facebook</a><span aria-hidden="true">·</span><a href="mailto:tnts.inventorysystem@gmail.com">Contact TNTS Inventory</a></div>
    </div>
    <div class="auth-submit-overlay" id="authSubmitting" hidden role="status"><div class="auth-submit-box"><span class="auth-wheel"></span><span>Checking your credentials…</span></div></div>
    <div class="p421-loading" id="p421Loading" aria-hidden="true"><div class="p421-loading-inner"><span class="p421-spinner" aria-hidden="true"></span><span>Opening registration…</span></div></div>
<script>
    const p421Link = document.getElementById('p421RegisterLink');
    if (p421Link) p421Link.addEventListener('click', function(event) {
        // Preserve ctrl-click, middle-click, keyboard shortcuts, and reduced-motion navigation.
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        event.preventDefault();
        if (document.body.classList.contains('p421-transitioning')) return;
        document.body.classList.add('p421-transitioning');
        window.setTimeout(() => {
            document.body.classList.add('p421-loading-active');
            document.getElementById('p421Loading').setAttribute('aria-hidden', 'false');
        }, 540);
        window.setTimeout(() => { window.location.assign(p421Link.href); }, 1050);
    });
    document.getElementById('tnts-login-form').addEventListener('submit', function(event) {
        if (!this.checkValidity()) return;
        document.getElementById('authSubmitting').hidden = false;
    });
    window.addEventListener('pageshow', () => {document.getElementById('authSubmitting').hidden = true;});
    document.getElementById('loginShowPassword').addEventListener('change', function () {
        document.getElementById('password').type = this.checked ? 'text' : 'password';
    });
</script>
</body>
</html>
