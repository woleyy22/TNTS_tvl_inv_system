<?php
// TNTS Phase 4.3 SMTP delivery adapter; no credentials are stored here.
require_once __DIR__ . '/auth.php';
function ev_mail_settings(): array {
    // File outside the public XAMPP htdocs directory: C:/xampp/tnts-mail-config.php
    $config = dirname(__DIR__, 3) . '/tnts-mail-config.php';
    if (!is_file($config)) throw new RuntimeException('SMTP is not configured. Ask the system administrator to complete mail setup.');
    $cfg = require $config;
    if (!is_array($cfg) || empty($cfg['username']) || empty($cfg['app_password'])) {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }
    return $cfg;
}
function ev_mail_is_configured(): bool {
    return is_file(dirname(__DIR__, 3) . '/tnts-mail-config.php');
}
function ev_send_code(string $email, string $code): void {
    $cfg = ev_mail_settings();
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('PHPMailer is not installed. Run Composer setup first.');
    require_once $autoload;
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['app_password'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($cfg['username'], 'TNTS Inventory System');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Your TNTS Inventory verification code';
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fb = 'https://www.facebook.com/p/DepEd-Tanza-National-Trade-School-Main-100063649497153/';
        // CID image remains visible in most mail clients without exposing a localhost URL.
        $logoPath = dirname(__DIR__) . '/assets/images/school-logo.png';
        $logo = '';
        if (is_file($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'tnts-school-logo', 'tnts-logo.png', 'base64', 'image/png');
            $logo = '<img src="cid:tnts-school-logo" alt="TNTS school logo" width="80" height="80" style="width:80px;height:80px;object-fit:contain;display:block;margin:0 auto 12px">';
        }
        $mail->Body = '<!doctype html><html><head><meta charset="UTF-8"></head>'
            .'<body style="margin:0;padding:35px 12px;background:#f3f3f6;font-family:Arial,sans-serif;color:#19243c">'
            .'<div style="max-width:530px;margin:0 auto;background:white;border-radius:18px;overflow:hidden;border:1px solid #e9e6ea">'
            .'<div style="height:7px;background:#7b1837"></div><div style="padding:35px 30px 27px;text-align:center">'
            .$logo
            .'<div style="font-weight:750;color:#7b1837;font-size:16px">TNTS INVENTORY SYSTEM</div>'
            .'<h1 style="font-size:27px;margin:23px 0 12px;line-height:1.2">Verify your email address</h1>'
            .'<p style="color:#586176;line-height:1.7;font-size:15px;margin:0">Use the verification code below to finish confirming your TNTS Inventory account.</p>'
            .'<div style="margin:28px auto 14px;padding:18px 8px;background:#faf0f4;border:1px solid #e4c7d0;border-radius:12px;letter-spacing:9px;font-size:33px;font-weight:750;color:#68142f">'.$safeCode.'</div>'
            .'<p style="color:#725a67;font-size:13px">This code expires in <strong>10 minutes</strong>.</p>'
            .'<p style="font-size:13px;line-height:1.6;color:#717887;margin-top:30px">If you did not register for TNTS Inventory, you can safely ignore this message.</p>'
            .'</div><div style="padding:22px;text-align:center;background:#faf9fb;border-top:1px solid #eeebef">'
            .'<a href="'.$fb.'" style="display:inline-block;background:#7b1837;color:#fff;text-decoration:none;padding:11px 22px;border-radius:9px;font-size:13px;font-weight:bold">Visit the school Facebook page</a>'
            .'<p style="margin:16px 0 0;color:#737988;font-size:12px">tnts.inventorysystem@gmail.com</p>'
            .'</div></div></body></html>';
        $mail->AltBody = "TNTS Inventory System\n\nYour email verification code: {$code}\nExpires in 10 minutes.\nIf you did not register, ignore this message.\nSchool Facebook: {$fb}";
        $mail->send();
    } catch (\Throwable $e) {
        error_log('TNTS SMTP delivery failed: ' . $e->getMessage());
        throw new RuntimeException('Verification email could not be sent. Check SMTP setup or try again later.');
    }
}
function ev_issue(PDO $pdo, int $userId, string $email, bool $isResend=false): void {
    $st=$pdo->prepare("SELECT verification_id,sent_at FROM account_verification_codes WHERE user_id=? ORDER BY verification_id DESC LIMIT 1 FOR UPDATE");
    $st->execute([$userId]); $last=$st->fetch();
    if($last && (time()-strtotime($last['sent_at'])) < 60) throw new RuntimeException('Please wait 60 seconds before requesting another code.');
    $st=$pdo->prepare("SELECT COUNT(*) FROM account_verification_codes WHERE user_id=? AND sent_at >= DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $st->execute([$userId]);
    if((int)$st->fetchColumn()>=5) throw new RuntimeException('Too many codes requested. Please try again after an hour.');
    $code=(string)random_int(100000,999999);
    $pdo->prepare('UPDATE account_verification_codes SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
    $pdo->prepare('INSERT INTO account_verification_codes(user_id,code_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')->execute([$userId,password_hash($code,PASSWORD_DEFAULT)]);
    // Actual authenticated email delivery; keep plaintext codes out of the database.
    ev_send_code($email, $code);
}
function ev_verify(PDO $pdo,int $userId,string $code): bool {
    $st=$pdo->prepare('SELECT verification_id,code_hash,attempt_count,expires_at,used_at FROM account_verification_codes WHERE user_id=? ORDER BY verification_id DESC LIMIT 1 FOR UPDATE');
    $st->execute([$userId]);$r=$st->fetch();
    if(!$r || $r['used_at']!==null || strtotime($r['expires_at'])<=time() || (int)$r['attempt_count']>=5) return false;
    $pdo->prepare('UPDATE account_verification_codes SET attempt_count=attempt_count+1 WHERE verification_id=?')->execute([$r['verification_id']]);
    if(!preg_match('/^\d{6}$/D',$code) || !password_verify($code,$r['code_hash'])) return false;
    $pdo->prepare('UPDATE account_verification_codes SET used_at=NOW() WHERE verification_id=?')->execute([$r['verification_id']]);
    $pdo->prepare('UPDATE users SET email_verified_at=NOW() WHERE user_id=? AND email_verified_at IS NULL AND registration_status=\'pending\'')->execute([$userId]);
    return true;
}
