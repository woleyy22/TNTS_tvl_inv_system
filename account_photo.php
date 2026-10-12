<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_login();
$st = db()->prepare('SELECT photo_data, photo_mime FROM user_profiles WHERE user_id = ?');
$st->execute([(int)$me['id']]);
$photo = $st->fetch();
if (!$photo || !$photo['photo_data'] || !in_array($photo['photo_mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $photo['photo_mime']);
header('Content-Length: ' . strlen($photo['photo_data']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'");
echo $photo['photo_data'];
exit;
