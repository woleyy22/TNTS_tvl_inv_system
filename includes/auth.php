<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function current_user(): ?array
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

// Page needs a logged-in user. Returns the user, or sends them to the login page.
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }

    // automatic logout after a period of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        flash('error', 'You were logged out because of inactivity.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();

    // make sure the account is still active (for example, a teacher who left)
    $st = db()->prepare('SELECT status FROM users WHERE user_id = ?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'active') {
        session_unset();
        session_destroy();
        session_start();
        flash('error', 'Your account is no longer active.');
        redirect('login.php');
    }
    return $u;
}

// Page is only for certain roles. Others see an "Access denied" page.
function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        $pageTitle = 'Access denied';
        $active = '';
        include __DIR__ . '/header.php';
        echo '<div class="card"><h3>Access denied</h3>'
           . '<p class="muted">Your role does not have access to this page.</p></div>';
        include __DIR__ . '/footer.php';
        exit;
    }
    return $u;
}
