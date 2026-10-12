<?php
// Personal profile helpers. Identity always comes from the authenticated session.
function profile_post_text(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

function profile_length(string $text): int
{
    $length = preg_match_all('/./us', $text);
    return $length === false ? strlen($text) : $length;
}

function profile_age(?string $birthday): ?int
{
    if (!$birthday) { return null; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthday);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $birthday || $date > new DateTimeImmutable('today')) {
        return null;
    }
    return $date->diff(new DateTimeImmutable('today'))->y;
}

function profile_read(int $userId): array
{
    // Do not load photo bytes into ordinary pages.
    $st = db()->prepare('SELECT u.user_id, u.full_name, u.email, u.role, u.created_at,
        p.suffix, p.gender, p.birthday, p.religion, p.photo_version
        FROM users u LEFT JOIN user_profiles p ON p.user_id = u.user_id WHERE u.user_id = ?');
    $st->execute([$userId]);
    $profile = $st->fetch();
    if (!$profile) { throw new RuntimeException('Account not found.'); }
    return $profile;
}

function profile_display_name(array $profile): string
{
    return trim(($profile['full_name'] ?? '') . ' ' . ($profile['suffix'] ?? ''));
}

function profile_avatar(array $profile, string $class = 'user-avatar'): string
{
    if (!empty($profile['photo_version'])) {
        return '<img class="' . e($class) . '" src="' . e(url('account_photo.php?v=' . $profile['photo_version'])) . '" alt="Your profile picture" width="96" height="96">';
    }
    $name = $profile['full_name'] ?? '';
    preg_match('/^./us', $name, $initial);
    return '<span class="' . e($class) . '" aria-hidden="true">' . e(strtoupper($initial[0] ?? '?')) . '</span>';
}

function profile_photo_upload(): ?array
{
    if (!isset($_FILES['profile_photo']) || ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    $file = $_FILES['profile_photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The photo could not be uploaded. Choose a JPG, PNG or WebP image up to 2 MB.');
    }
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Choose a photo no larger than 2 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$info || !in_array($info['mime'] ?? '', $allowed, true) || $info[0] > 6000 || $info[1] > 6000) {
        throw new InvalidArgumentException('Use a valid JPG, PNG or WebP image, at most 6000 pixels on each side.');
    }
    $data = file_get_contents($file['tmp_name']);
    if ($data === false || strlen($data) > 2 * 1024 * 1024) { throw new InvalidArgumentException('The photo could not be read or exceeds 2 MB.'); }
    return ['data' => $data, 'mime' => $info['mime'], 'version' => bin2hex(random_bytes(16))];
}
