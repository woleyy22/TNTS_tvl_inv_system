<?php
require_once __DIR__.'/includes/email_verification.php';
if(current_user())redirect('dashboard.php');
if(!ev_mail_is_configured()){http_response_code(503);exit('SMTP has not been configured by the administrator.');}
$pdo=db();$error='';$message='';
$id=(int)($_SESSION['pending_email_user_id']??0);
if($id<=0){redirect('register.php');}
$st=$pdo->prepare("SELECT user_id,email,email_verified_at,registration_status FROM users WHERE user_id=? AND registration_source='self_registration'");$st->execute([$id]);$user=$st->fetch();
if(!$user){unset($_SESSION['pending_email_user_id']);redirect('register.php');}
if($user['email_verified_at']!==null){$message='Email verification complete. Your account is awaiting approval by the Supply Officer or TVL Head.';}
elseif($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$action=(string)($_POST['action']??'verify');
 try{
  $pdo->beginTransaction();
  if($action==='resend') {ev_issue($pdo,$id,$user['email'],true);$message='A new verification code has been emailed to you.';}
  elseif($action==='verify'){
   if(!ev_verify($pdo,$id,trim((string)($_POST['code']??'')))){
    $error='Incorrect, expired, or used code. After five attempts, request a new code.';
   } else {
    $message='Email verified successfully. Your account still needs administrator approval.';$user['email_verified_at']=date('Y-m-d H:i:s');
   }
  } else throw new RuntimeException('Invalid action.');
  $pdo->commit();
 } catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Unable to process code.';}
}

$lastSent = null;
if ($user['email_verified_at'] === null) {
 $query = $pdo->prepare('SELECT sent_at FROM account_verification_codes WHERE user_id=? ORDER BY verification_id DESC LIMIT 1');
 $query->execute([$id]);
 $lastSent = $query->fetchColumn() ?: null;
}
$retryAt = $lastSent ? (strtotime($lastSent) + 60) : 0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify email | TNTS Inventory</title><link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<style>
.ev-page{min-height:100dvh;padding:25px 15px;display:grid;place-items:center;background:#40162b;background-image:linear-gradient(145deg,rgba(249,250,250,.3),rgba(82,0,34,.58)),radial-gradient(ellipse at 20% 20%,rgba(170,73,102,.5),transparent 70%),url('assets/images/login-background.png');background-size:cover;background-position:center;background-attachment:fixed}
.ev-card{width:min(510px,100%);background:rgba(255,255,255,.97);border-radius:21px;box-shadow:0 20px 60px #1909154a;overflow:hidden;color:#1b2740}
.ev-stripe{height:7px;background:linear-gradient(90deg,#61132f,#9e375d)}.ev-content{padding:34px 37px 24px;text-align:center}.ev-logo{width:85px;height:85px;object-fit:contain;margin:auto auto 8px;display:block}.ev-brand{font-size:13px;font-weight:800;letter-spacing:1px;color:#801c3d}.ev-card h1{font-size:29px;letter-spacing:-.7px;line-height:1.2;margin:18px 0 10px;color:#20283d}.ev-muted{font-size:14px;line-height:1.7;color:#657187}.ev-address{display:block;margin:10px auto 22px;padding:9px 12px;background:#f6f3f6;border-radius:10px;font-size:14px;overflow-wrap:anywhere}.ev-code-label{display:block;text-align:left;font-size:13px;font-weight:700;margin:22px 0 7px}.ev-code{width:100%;box-sizing:border-box;border:1.5px solid #d4cad0;border-radius:12px;padding:15px 12px;font-size:25px;letter-spacing:8px;text-align:center;font-weight:750;color:#521329;background:#fff}.ev-code:focus{outline:3px solid #aa4d7330;border-color:#893958}.ev-btn{display:block;box-sizing:border-box;width:100%;border:0;border-radius:11px;padding:14px 18px;font-weight:750;cursor:pointer;background:#7d1738;color:#fff;font-size:14px;transition:transform .18s,background .18s}.ev-btn:hover{background:#5f0f2a;transform:translateY(-1px)}.ev-btn:disabled{opacity:.48;cursor:not-allowed;transform:none}.ev-outline{margin-top:12px;background:transparent;border:1.5px solid #dbc6d0;color:#772a47}.ev-outline:hover{background:#faf0f4;color:#68213d}.ev-alert{padding:13px;border-radius:10px;line-height:1.5;text-align:left;font-size:13px;margin:13px 0}.ev-good{color:#195d3b;background:#e7f5ed}.ev-bad{color:#a12832;background:#fff0f1}.ev-foot{padding:21px 25px;text-align:center;border-top:1px solid #ece7eb;background:#faf9fa;font-size:13px}.ev-foot a{color:#70203e;text-decoration:none;font-weight:700}.ev-foot-links{display:flex;justify-content:center;gap:16px;flex-wrap:wrap;margin-bottom:10px}.ev-foot p{font-size:11px;color:#8b8490;margin:0}.ev-tip{margin-top:15px;color:#8b8390;font-size:12px;line-height:1.5}@media(max-width:550px){.ev-content{padding:25px 20px}.ev-card h1{font-size:25px}}@media(prefers-reduced-motion:reduce){.ev-btn{transition:none}.ev-btn:hover{transform:none}}
</style></head><body style="margin:0"><main class="ev-page"><section class="ev-card"><div class="ev-stripe"></div><div class="ev-content"><img class="ev-logo" src="<?= e(url('assets/images/school-logo.png')) ?>" alt="TNTS school logo"><div class="ev-brand">TNTS INVENTORY SYSTEM</div>
<?php if ($user['email_verified_at'] !== null): ?>
<h1>Email verified!</h1><p class="ev-muted">Your email is verified. Your account is now awaiting approval by the Supply Officer or TVL Head.</p><div class="ev-alert ev-good" role="status">Verification complete. You will be able to sign in after an administrator approves your account.</div><a class="ev-btn" style="text-decoration:none" href="<?= e(url('login.php')) ?>">Back to Login</a>
<?php else: ?>
<h1>Verify your email</h1><p class="ev-muted">We sent a six-digit verification code to your email address.</p><strong class="ev-address"><?= e($user['email']) ?></strong>
<?php if($error): ?><div class="ev-alert ev-bad" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="ev-alert ev-good" role="status"><?= e($message) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><label class="ev-code-label" for="ev-code">Verification code</label><input class="ev-code" type="text" id="ev-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus><p class="ev-muted" style="font-size:12px;margin:12px 0 22px">The code expires in 10 minutes.</p><button class="ev-btn" type="submit">Verify my email →</button></form>
<form method="post" id="ev-resend-form"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="ev-btn ev-outline" id="ev-resend" type="submit">Resend verification code</button></form><p id="ev-cooldown" class="ev-tip" role="status"></p><p class="ev-tip">Didn't receive it? Check your Spam or Promotions folder before requesting another code.</p>
<?php endif; ?></div><div class="ev-foot"><div class="ev-foot-links"><a href="<?= e(url('login.php')) ?>">Back to Login</a><a href="https://www.facebook.com/p/DepEd-Tanza-National-Trade-School-Main-100063649497153/" target="_blank" rel="noopener noreferrer">School Facebook</a></div><p>TNTS Inventory System · tnts.inventorysystem@gmail.com</p></div></section></main>
<script>(function(){const button=document.getElementById('ev-resend'),label=document.getElementById('ev-cooldown');if(!button||!label)return;const until=<?= (int)$retryAt ?>*1000;function update(){const left=Math.max(0,Math.ceil((until-Date.now())/1000));button.disabled=left>0;label.textContent=left?'You can request another code in '+left+' seconds.':'';}update();setInterval(update,1000);})();</script></body></html>
