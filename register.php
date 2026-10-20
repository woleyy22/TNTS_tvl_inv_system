<?php
require_once __DIR__.'/includes/email_verification.php';
if (current_user()) redirect('dashboard.php');
$pdo=db(); $errors=[]; $success=false;
if(!ev_mail_is_configured()){http_response_code(503);exit('SMTP has not been configured by the administrator.');}
$tracks=$pdo->query('SELECT track_id,track_name FROM academic_tracks WHERE is_active=1 ORDER BY track_name')->fetchAll();
$courses=$pdo->query('SELECT c.course_id,c.course_name,c.track_id FROM courses c JOIN academic_tracks t ON t.track_id=c.track_id WHERE t.is_active=1 ORDER BY c.course_name')->fetchAll();
$positions=$pdo->query('SELECT position_name FROM teaching_positions WHERE active=1 ORDER BY sort_order,position_name')->fetchAll(PDO::FETCH_COLUMN);
$officeChoices=$pdo->query('SELECT office_name FROM school_offices WHERE active=1 ORDER BY sort_order,office_name')->fetchAll(PDO::FETCH_COLUMN);
$v=['full_name'=>'','email'=>'','teaching_position'=>'','position_office'=>'','teaching_level'=>'','track_id'=>''];$selected=[];
if($_SERVER['REQUEST_METHOD']==='POST') {
 csrf_check();
 foreach($v as $k=>$unused) $v[$k]=trim((string)($_POST[$k]??''));
 $v['email']=strtolower($v['email']);
 $rawCourses=$_POST['course_ids']??[]; if(!is_array($rawCourses)) $rawCourses=[];
 $selected=array_values(array_unique(array_map('intval',$rawCourses)));
 $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm_password']??'');
 if(mb_strlen($v['full_name'])<3||mb_strlen($v['full_name'])>100) $errors[]='Enter a full name (3–100 characters).';
 if(!filter_var($v['email'],FILTER_VALIDATE_EMAIL)||strlen($v['email'])>100) $errors[]='Enter a valid email address.';
 // Development: Gmail and DepEd accounts only. Phase 4.3 will verify ownership.
 if(!preg_match('/^[^@\s]+@(gmail\.com|deped\.gov\.ph)$/i',$v['email'])) $errors[]='During development, use Gmail or an official @deped.gov.ph address.';
 if(strlen($password)<10||strlen($password)>72||!preg_match('/[A-Za-z]/',$password)||!preg_match('/\d/',$password)) $errors[]='Password must be 10–72 characters and contain letters and numbers.';
 if(!hash_equals($password,$confirm)) $errors[]='Passwords do not match.';
 if(!in_array($v['teaching_position'],$positions,true)) $errors[]='Select a teaching position.';
 if($v['position_office']===''||mb_strlen($v['position_office'])>150) $errors[]='Enter your Position / Office (up to 150 characters).';
 if(!in_array($v['teaching_level'],['JHS','SHS','JHS_SHS'],true)) $errors[]='Select your teaching level.';
 $track=(int)$v['track_id']; $trackIds=array_map('intval',array_column($tracks,'track_id'));
 if($track && !in_array($track,$trackIds,true)) $errors[]='Select a valid academic track.';
 $validCourseIds=[];foreach($courses as $c) if(!$track||(int)$c['track_id']===$track) $validCourseIds[]=(int)$c['course_id'];
 if(count($selected)>15||count(array_diff($selected,$validCourseIds))>0) $errors[]='Select valid courses from your chosen track (maximum 15).';
 if(!$errors) {
  try {
   $pdo->beginTransaction();
   $check=$pdo->prepare('SELECT user_id FROM users WHERE email=? LIMIT 1');$check->execute([$v['email']]);
   if($check->fetch()) throw new RuntimeException('An account already uses that email.');
   $st=$pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,status,registration_status,registration_source,email_verified_at) VALUES(?, ?, ?, 'teacher', 'inactive', 'pending', 'self_registration', NULL)");
   $st->execute([$v['full_name'],$v['email'],password_hash($password,PASSWORD_DEFAULT)]);
   $id=(int)$pdo->lastInsertId();
   $st=$pdo->prepare('INSERT INTO user_profiles(user_id,teaching_position,position_office,teaching_level) VALUES(?,?,?,?)');
   $st->execute([$id,$v['teaching_position'],$v['position_office'],$v['teaching_level']]);
   $st=$pdo->prepare('INSERT INTO teacher_courses(user_id,course_id) VALUES(?,?)'); foreach($selected as $cid) $st->execute([$id,$cid]);
   ev_issue($pdo,$id,$v['email']);
   $pdo->commit(); $_SESSION['pending_email_user_id']=$id; $success=true;
  } catch(Throwable $ex) {
   if($pdo->inTransaction())$pdo->rollBack();
   $errors[]=$ex instanceof RuntimeException?$ex->getMessage():'Registration could not be saved. Please try again.';
  }
 }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Teacher registration | <?= e(APP_NAME) ?></title><link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>"><style>
.r42-page{min-height:100vh;background:#f4f5f9;padding:30px 15px;display:grid;place-items:center}.r42-card{width:min(850px,100%);background:#fff;border:1px solid #dfe4ed;box-shadow:0 12px 34px #1b274015;border-radius:18px;padding:30px;color:#182842}.r42-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.r42-card h1{margin:12px 0 5px;color:#182842}.r42-muted{color:#66758d;font-size:14px;line-height:1.55}.r42-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.r42-field{display:flex;flex-direction:column;gap:7px;font-size:13px;font-weight:650}.r42-field input,.r42-field select{width:100%;border:1px solid #ccd5e2;border-radius:9px;padding:12px;background:#fff;color:#182842;min-width:0}.r42-field input:focus,.r42-field select:focus{outline:2px solid #92213f44;border-color:#851632}.r42-section{border-top:1px solid #e5e8ef;padding-top:17px;margin-top:19px}.r42-section h2{font-size:16px;margin:0 0 12px}.r42-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:12px;border:1px solid #dce2ec;border-radius:10px;max-height:185px;overflow-y:auto}.r42-checks label{font-size:13px;display:flex;align-items:flex-start;gap:8px;line-height:1.4}.r42-btn{background:#850b16;color:white;border:0;border-radius:9px;padding:12px 20px;cursor:pointer;font-weight:700}.r42-alert{padding:14px;border-radius:10px;background:#ffecee;color:#991b1b;margin:15px 0}.r42-success{background:#e6f7ed;color:#145635}.r42-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:20px}.r42-pass{display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px}.r42-pass input{width:auto}@media(max-width:620px){.r42-grid,.r42-checks{grid-template-columns:1fr}.r42-card{padding:20px}}

/* Phase 4.3.3: match verification card while keeping existing form and your custom background. */
.r42-page{min-height:100dvh;padding:30px 15px;background-color:#40162b;background-image:linear-gradient(145deg,rgba(249,250,250,.3),rgba(82,0,34,.58)),radial-gradient(ellipse at 20% 20%,rgba(170,73,102,.5),transparent 70%),url('assets/images/login-background.png');background-size:cover;background-position:center;background-attachment:fixed}
.r42-card{position:relative;overflow:hidden;width:min(850px,100%);background:rgba(255,255,255,.97);border:0;border-radius:21px;box-shadow:0 20px 60px #1909154a;padding:35px 38px}.r42-card:before{content:'';height:7px;position:absolute;top:0;left:0;right:0;background:linear-gradient(90deg,#61132f,#9e375d)}.r42-top strong{color:#801c3d;font-size:12px;letter-spacing:.5px}.r42-top a{color:#772a47;font-size:13px;font-weight:700;text-decoration:none}.r42-card h1{font-size:29px;letter-spacing:-.6px;color:#20283d}.r42-field input,.r42-field select{border:1.5px solid #d4cad0;border-radius:11px;padding:13px}.r42-field input:focus,.r42-field select:focus{outline:3px solid #aa4d7330;border-color:#893958}.r42-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;background:#7d1738;color:#fff;border-radius:11px;padding:14px 20px;font-size:14px}.r42-btn:hover{background:#5f0f2a;color:white}.r42-section h2{color:#20283d}.r42-loading{position:fixed;inset:0;z-index:100;display:grid;place-items:center;background:#28111cb0;backdrop-filter:blur(5px)}.r42-loading[hidden]{display:none}.r42-loading-inner{display:flex;align-items:center;gap:15px;background:white;color:#651531;font-weight:700;padding:25px 30px;border-radius:16px;box-shadow:0 20px 60px #1909154a}.r42-wheel{width:23px;height:23px;border-radius:50%;border:3px solid #e6ced8;border-top-color:#7d1738;animation:r42-wheel .8s linear infinite}@keyframes r42-wheel{to{transform:rotate(360deg)}}@media(max-width:620px){.r42-card{padding:25px 21px}}@media(prefers-reduced-motion:reduce){.r42-wheel{animation:none}}
</style></head><body class="r42-page-body"><main class="r42-page"><section class="r42-card"><div class="r42-top"><strong>TNTS · Teacher Registration</strong><a href="<?= e(url('login.php')) ?>">← Back to login</a></div>
<?php if($success): ?><div style="text-align:center;padding:16px 0"><img src="<?= e(url('assets/images/school-logo.png')) ?>" alt="TNTS school logo" style="display:block;margin:0 auto 12px;width:85px;height:85px;object-fit:contain"><h1>Check your email</h1><p class="r42-muted">We sent a six-digit verification code to <strong><?= e($v['email']) ?></strong>.</p></div><div class="r42-alert r42-success">Your teacher account has been saved but is <b>not yet active</b>. Please verify your email using the six-digit code, then wait for administrator approval.</div><p><a id="r42-verify-link" class="r42-btn" href="<?= e(url('verify_email.php')) ?>">Enter verification code →</a></p><a href="<?= e(url('login.php')) ?>">Return to login</a>
<?php else: ?><h1>Create teacher account</h1><p class="r42-muted">Register with Gmail for development or your official DepEd address. A six-digit verification code will be emailed to you after signup. Your account stays inactive until email verification and administrator approval.</p>
<?php if($errors): ?><div class="r42-alert" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" id="r42-form" autocomplete="on"><?= csrf_field() ?>
<div class="r42-section"><h2>Account information</h2><div class="r42-grid">
<label class="r42-field">Full name *<input name="full_name" maxlength="100" autocomplete="name" required value="<?= e($v['full_name']) ?>"></label>
<label class="r42-field">Email address *<input type="email" name="email" maxlength="100" autocomplete="email" required placeholder="name@gmail.com" value="<?= e($v['email']) ?>"></label>
<label class="r42-field">Password *<input id="r42password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<label class="r42-field">Confirm password *<input id="r42confirm" name="confirm_password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
</div><label class="r42-pass"><input id="r42show" type="checkbox"> Show passwords</label><p class="r42-muted">At least 10 characters, including a letter and a number.</p></div>
<div class="r42-section"><h2>Official teaching information</h2><div class="r42-grid">
<label class="r42-field">Teaching position *<select name="teaching_position" required><option value="">Select position</option><?php foreach($positions as $p): ?><option value="<?= e($p) ?>" <?= $v['teaching_position']===$p?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?></select></label>
<label class="r42-field">Position / Office *<input list="p473RegisterOffices" name="position_office" maxlength="150" required value="<?= e($v['position_office']) ?>" placeholder="TVL Department / School Office"><datalist id="p473RegisterOffices"><?php foreach($officeChoices as $officeChoice): ?><option value="<?= e($officeChoice) ?>"><?php endforeach; ?></datalist></label>
<label class="r42-field">Teaching level *<select name="teaching_level" required><option value="">Select level</option><option value="JHS" <?= $v['teaching_level']==='JHS'?'selected':'' ?>>Junior High School</option><option value="SHS" <?= $v['teaching_level']==='SHS'?'selected':'' ?>>Senior High School</option><option value="JHS_SHS" <?= $v['teaching_level']==='JHS_SHS'?'selected':'' ?>>Both JHS and SHS</option></select></label>
<label class="r42-field">Academic track (optional)<select name="track_id" id="r42track"><option value="">No track selected</option><?php foreach($tracks as $t): ?><option value="<?= (int)$t['track_id'] ?>" <?= (int)$v['track_id']===(int)$t['track_id']?'selected':'' ?>><?= e($t['track_name']) ?></option><?php endforeach; ?></select></label>
</div></div>
<div class="r42-section"><h2>Assigned courses (optional)</h2><p class="r42-muted">Select courses you teach. These are teaching assignments, not fixed classifications for ICS items.</p><div class="r42-checks" id="r42courses"><?php foreach($courses as $c): ?><label data-track="<?= (int)$c['track_id'] ?>"><input type="checkbox" name="course_ids[]" value="<?= (int)$c['course_id'] ?>" <?= in_array((int)$c['course_id'],$selected,true)?'checked':'' ?>><?= e($c['course_name']) ?></label><?php endforeach; ?></div></div>
<div class="r42-foot"><span class="r42-muted">Accounts start as Pending and Inactive.</span><button class="r42-btn" type="submit">Submit registration</button></div></form><?php endif; ?></section></main><div class="r42-loading" id="r42-loading" hidden role="status"><div class="r42-loading-inner"><span class="r42-wheel"></span><span id="r42-loading-text">Opening verification…</span></div></div>
<script>const p=document.getElementById('r42password'),c=document.getElementById('r42confirm'),s=document.getElementById('r42show');if(s)s.addEventListener('change',()=>{p.type=c.type=s.checked?'text':'password'});const t=document.getElementById('r42track');if(t){function f(){const val=t.value;document.querySelectorAll('#r42courses label').forEach(x=>{const show=!val||x.dataset.track===val;x.hidden=!show;const ck=x.querySelector('input');if(!show)ck.checked=false})}t.addEventListener('change',f);f()}
(function(){
 const overlay=document.getElementById('r42-loading'),label=document.getElementById('r42-loading-text'),form=document.getElementById('r42-form'),next=document.getElementById('r42-verify-link');
 if(form)form.addEventListener('submit',function(){if(form.checkValidity()){label.textContent='Creating your account and sending your code…';overlay.hidden=false;}});
 if(next)next.addEventListener('click',function(event){if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;event.preventDefault();overlay.hidden=false;setTimeout(()=>location.assign(next.href),580);});
 window.addEventListener('pageshow',()=>{overlay.hidden=true;});
})();
</script></body></html>
