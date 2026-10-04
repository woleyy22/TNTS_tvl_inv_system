<?php
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
if ($u) {
    log_action((int)$u['id'], 'logout', 'Accounts', (int)$u['id'], null);
}
session_unset();
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php');
