<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile.php';
$me = require_login();
$uid = (int)$me['id'];
$pageTitle = 'My Account';
$active = 'account.php';
try {
    $profile = profile_read($uid);
} catch (PDOException $ex) {
    http_response_code(503);
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><h1>My Account</h1><p>Account settings are temporarily unavailable. Please contact the Supply Officer.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
$values = $profile;
$profileErrors = [];
$passwordErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = profile_post_text('action');
    if ($action === 'profile') {
        foreach (['full_name', 'suffix', 'gender', 'birthday', 'religion'] as $key) { $values[$key] = profile_post_text($key); }
        if ($values['full_name'] === '' || profile_length($values['full_name']) > 100) { $profileErrors[] = 'Enter your full name using 1–100 characters.'; }
        foreach (['suffix' => 20, 'gender' => 50, 'religion' => 100] as $key => $maximum) {
            if (profile_length($values[$key]) > $maximum) { $profileErrors[] = ucfirst($key) . ' must be at most ' . $maximum . ' characters.'; }
        }
        if ($values['birthday'] !== '' && (profile_age($values['birthday']) === null || $values['birthday'] < '1900-01-01')) {
            $profileErrors[] = 'Enter a valid birthday between January 1, 1900 and today.';
        }
        $photo = null;
        try { $photo = profile_photo_upload(); } catch (InvalidArgumentException $ex) { $profileErrors[] = $ex->getMessage(); }
        $removePhoto = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';
        if ($photo && $removePhoto) { $profileErrors[] = 'Choose either a new photo or Remove current photo.'; }
        if (!$profileErrors) {
            try {
                db()->beginTransaction();
                db()->prepare('UPDATE users SET full_name = ? WHERE user_id = ?')->execute([$values['full_name'], $uid]);
                db()->prepare('INSERT INTO user_profiles (user_id, suffix, gender, birthday, religion) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE suffix = VALUES(suffix), gender = VALUES(gender), birthday = VALUES(birthday), religion = VALUES(religion)')
                    ->execute([$uid, $values['suffix'] ?: null, $values['gender'] ?: null, $values['birthday'] ?: null, $values['religion'] ?: null]);
                if ($photo) {
                    db()->prepare('UPDATE user_profiles SET photo_data = ?, photo_mime = ?, photo_version = ? WHERE user_id = ?')->execute([$photo['data'], $photo['mime'], $photo['version'], $uid]);
                } elseif ($removePhoto) {
                    db()->prepare('UPDATE user_profiles SET photo_data = NULL, photo_mime = NULL, photo_version = NULL WHERE user_id = ?')->execute([$uid]);
                }
                db()->commit();
                $_SESSION['user']['name'] = $values['full_name'];
                log_action($uid, 'Updated own profile', 'My Account', $uid, 'Personal profile updated.');
                flash('success', 'Your profile has been updated.');
                redirect('account.php');
            } catch (Throwable $ex) {
                if (db()->inTransaction()) { db()->rollBack(); }
                $profileErrors[] = 'Your profile could not be saved. Please try again.';
            }
        }
    } elseif ($action === 'password') {
        $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
        $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        if ($current === '') { $passwordErrors[] = 'Enter your current password.'; }
        if (profile_length($new) < 8) { $passwordErrors[] = 'Use a new password with at least 8 characters.'; }
        if (strlen($new) > 72 || strpos($new, "\0") !== false) { $passwordErrors[] = 'This password is too long or contains an unsupported character. Please use a shorter passphrase.'; }
        if ($new !== $confirm) { $passwordErrors[] = 'The new passwords do not match.'; }
        if (!$passwordErrors) {
            try {
                db()->beginTransaction();
                $st = db()->prepare('SELECT password_hash FROM users WHERE user_id = ? FOR UPDATE');
                $st->execute([$uid]);
                $existing = $st->fetch();
                if (!$existing || !password_verify($current, $existing['password_hash'])) {
                    db()->rollBack();
                    $passwordErrors[] = 'Your current password is incorrect.';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    if ($hash === false) { throw new RuntimeException('Could not create password hash.'); }
                    db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$hash, $uid]);
                    db()->commit();
                    session_regenerate_id(true);
                    log_action($uid, 'Changed own password', 'My Account', $uid, 'Password changed.');
                    flash('success', 'Your password has been changed. Use the new password the next time you sign in.');
                    redirect('account.php');
                }
            } catch (Throwable $ex) {
                if (db()->inTransaction()) { db()->rollBack(); }
                $passwordErrors[] = 'Your password could not be changed. Please try again.';
            }
        }
        unset($current, $new, $confirm);
    } else {
        $profileErrors[] = 'The form could not be submitted. Refresh the page and try again.';
    }
}
$assignedCourses = [];
if ($profile['role'] === 'teacher') {
    $courseStmt = db()->prepare('SELECT c.course_name FROM teacher_courses tc JOIN courses c ON c.course_id = tc.course_id WHERE tc.user_id = ? ORDER BY c.course_name');
    $courseStmt->execute([$uid]);
    $assignedCourses = $courseStmt->fetchAll(PDO::FETCH_COLUMN);
}
$age = profile_age($values['birthday']);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>My Account</h1><p>Manage your personal information, profile picture and password.</p></div>
<div class="profile-layout">
    <aside class="card profile-overview">
        <?= profile_avatar($profile, 'profile-avatar-large') ?>
        <h2><?= e(profile_display_name($profile)) ?></h2>
        <span class="pill gray"><?= e(role_label($profile['role'])) ?></span>
        <p class="profile-email"><?= e($profile['email']) ?></p>
        <?php if ($profile['role'] === 'teacher'): ?>
          <div class="account-specialization" aria-label="Assigned courses and specializations">
            <strong>Assigned Course / Specialization</strong>
            <?php if ($assignedCourses): ?>
              <ul><?php foreach ($assignedCourses as $courseName): ?><li><?= e($courseName) ?></li><?php endforeach; ?></ul>
            <?php else: ?><p>No course assigned yet. Contact the Supply Officer.</p><?php endif; ?>
          </div>
        <?php endif; ?>
        <dl class="profile-facts"><dt>Account created</dt><dd><?= e(date('M d, Y', strtotime($profile['created_at']))) ?></dd><dt>Age</dt><dd><?= $age === null ? 'Add your birthday' : e($age) . ' years old' ?></dd></dl>
        <p class="field-help">Personal details are optional. You can update or clear them whenever you need.</p>
    </aside>
    <div class="profile-content">
        <section class="card">
            <div class="profile-section-heading"><div class="eyebrow">Personal details</div><h2>Edit your profile</h2></div>
            <?php if ($profileErrors): ?><div class="alert alert-error" role="alert"><ul class="form-errors"><?php foreach ($profileErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" action="<?= e(url('account.php')) ?>" enctype="multipart/form-data" id="profileForm">
                <?= csrf_field() ?><input type="hidden" name="action" value="profile"><input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                <div class="profile-photo-field">
                    <div class="profile-photo-preview" id="photoPreview"><?= profile_avatar($profile, 'profile-avatar-large') ?></div>
                    <div class="field"><label for="profilePhoto">Profile picture</label><input class="input" type="file" id="profilePhoto" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="photoHelp"><small id="photoHelp" class="field-help">JPG, PNG or WebP · Up to 2 MB · Maximum 6000 × 6000 pixels.</small>
                    <?php if ($profile['photo_version']): ?><label class="check profile-remove-photo"><input type="checkbox" name="remove_photo" id="removePhoto" value="1"> Remove current photo</label><?php endif; ?>
                    <p id="photoError" class="field-error" role="alert" hidden></p></div>
                </div>
                <div class="guided-form-grid">
                    <div class="field"><label for="profileName">Full name <span class="required-mark">*</span></label><input class="input" id="profileName" name="full_name" value="<?= e($values['full_name']) ?>" maxlength="100" required autocomplete="name"></div>
                    <div class="field"><label for="profileSuffix">Suffix <span class="optional-label">Optional</span></label><input class="input" id="profileSuffix" name="suffix" value="<?= e($values['suffix']) ?>" maxlength="20" placeholder="e.g. Jr., Sr., III" autocomplete="honorific-suffix"></div>
                    <div class="field"><label for="profileGender">Gender <span class="optional-label">Optional</span></label><input class="input" id="profileGender" name="gender" value="<?= e($values['gender']) ?>" maxlength="50" list="genderSuggestions" placeholder="Enter your gender"><datalist id="genderSuggestions"><option value="Female"><option value="Male"><option value="Non-binary"><option value="Prefer not to say"></datalist></div>
                    <div class="field"><label for="profileBirthday">Birthday <span class="optional-label">Optional</span></label><input class="input" type="date" id="profileBirthday" name="birthday" value="<?= e($values['birthday']) ?>" min="1900-01-01" max="<?= date('Y-m-d') ?>" autocomplete="bday"></div>
                    <div class="field"><label for="profileAge">Age</label><input class="input" id="profileAge" value="<?= $age === null ? '' : e($age) ?>" readonly placeholder="Calculated from birthday" aria-describedby="ageHelp"><small class="field-help" id="ageHelp">Calculated automatically from your birthday.</small></div>
                    <div class="field"><label for="profileReligion">Religion <span class="optional-label">Optional</span></label><input class="input" id="profileReligion" name="religion" value="<?= e($values['religion']) ?>" maxlength="100" placeholder="Enter a religion or leave blank"></div>
                </div>
                <div class="profile-form-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
            </form>
        </section>
        <section class="card" id="passwordSettings">
            <div class="profile-section-heading"><div class="eyebrow">Security</div><h2>Change password</h2><p class="field-help">Confirm your current password to set a new one.</p></div>
            <?php if ($passwordErrors): ?><div class="alert alert-error" role="alert"><ul class="form-errors"><?php foreach ($passwordErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" action="<?= e(url('account.php')) ?>#passwordSettings" id="passwordForm">
                <?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="field"><label for="currentPassword">Current password</label><input class="input" type="password" id="currentPassword" name="current_password" autocomplete="current-password" required></div>
                <div class="guided-form-grid"><div class="field"><label for="newPassword">New password</label><input class="input" type="password" id="newPassword" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required aria-describedby="passwordHelp"></div><div class="field"><label for="confirmPassword">Confirm new password</label><input class="input" type="password" id="confirmPassword" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required></div></div>
                <p class="field-help" id="passwordHelp">Use at least 8 characters. A longer, unique passphrase is recommended.</p>
                <label class="check profile-show-password"><input type="checkbox" id="showPasswords"> Show passwords</label>
                <div class="profile-form-actions"><button class="btn btn-primary" type="submit">Update password</button></div>
            </form>
        </section>
    </div>
</div>
<script defer src="<?= e(url('assets/js/account.js')) ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
