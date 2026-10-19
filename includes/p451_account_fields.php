<?php
// Shared form fields for Add Account and per-row Edit. Data comes from whitelisted server values.
$gv=static function($key,$default='')use($v){return isset($v[$key]) && !is_array($v[$key]) ? (string)$v[$key] : $default;};
$roleValue=$gv('role','teacher');
$chosenCourses=isset($v['courses'])&&is_array($v['courses'])?array_map('intval',$v['courses']):[];
?>
<div class="p451-form-grid">
 <div class="field"><label for="<?= e($prefix) ?>-name">Full name *</label><input class="input" id="<?= e($prefix) ?>-name" name="full_name" required maxlength="100" value="<?= e($gv('full_name')) ?>"></div>
 <div class="field"><label for="<?= e($prefix) ?>-email">Email address *</label><input class="input" id="<?= e($prefix) ?>-email" name="email" type="email" required maxlength="100" value="<?= e($gv('email')) ?>"></div>
 <div class="field"><label for="<?= e($prefix) ?>-role">Role *</label><select class="input" id="<?= e($prefix) ?>-role" name="role"><?php foreach($roles as $r): ?><option value="<?= e($r) ?>" <?= $roleValue===$r?'selected':'' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></div>
 <div class="field"><label for="<?= e($prefix) ?>-pwd"><?= ($v['user_id']??0)>0?'Reset password (leave blank to keep)':'Initial password *' ?></label><input class="input" id="<?= e($prefix) ?>-pwd" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" <?= ($v['user_id']??0)>0?'':'required' ?>><label class="check p451-password-label"><input type="checkbox" data-password-toggle> Show password</label></div>
 <div class="field"><label for="<?= e($prefix) ?>-suffix">Suffix</label><input class="input" id="<?= e($prefix) ?>-suffix" maxlength="20" name="suffix" value="<?= e($gv('suffix')) ?>"></div>
 <div class="field"><label for="<?= e($prefix) ?>-gender">Gender (optional)</label><input class="input" id="<?= e($prefix) ?>-gender" maxlength="50" name="gender" value="<?= e($gv('gender')) ?>"></div>
 <div class="field"><label for="<?= e($prefix) ?>-birthday">Birthday (optional)</label><input class="input" type="date" id="<?= e($prefix) ?>-birthday" name="birthday" value="<?= e($gv('birthday')) ?>"></div>
 <div class="field"><label for="<?= e($prefix) ?>-religion">Religion (optional)</label><input class="input" id="<?= e($prefix) ?>-religion" maxlength="100" name="religion" value="<?= e($gv('religion')) ?>"></div>
</div>
<fieldset class="p451-teacher-settings" data-teacher-settings><legend>Teacher details & assignments</legend><p class="muted">Supply Officer may update these directly. Official personnel changes are recorded; existing ICS snapshots remain unchanged.</p>
<div class="p451-form-grid">
<div class="field"><label for="<?= e($prefix) ?>-position">Teaching position</label><select name="teaching_position" id="<?= e($prefix) ?>-position" class="input"><option value="">Not recorded</option><?php foreach($positions as $p): ?><option value="<?= e($p) ?>" <?= $gv('teaching_position')===$p?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="<?= e($prefix) ?>-office">Office / Department</label><input class="input" id="<?= e($prefix) ?>-office" name="position_office" maxlength="150" value="<?= e($gv('position_office')) ?>"></div>
<div class="field"><label for="<?= e($prefix) ?>-level">Teaching level</label><select class="input" id="<?= e($prefix) ?>-level" name="teaching_level"><option value="">Not recorded</option><option value="JHS" <?= $gv('teaching_level')==='JHS'?'selected':'' ?>>Junior High (JHS)</option><option value="SHS" <?= $gv('teaching_level')==='SHS'?'selected':'' ?>>Senior High (SHS)</option><option value="JHS_SHS" <?= $gv('teaching_level')==='JHS_SHS'?'selected':'' ?>>Both JHS / SHS</option></select></div>
</div>
<div class="field"><label>Courses handled (optional)</label><div class="p451-course-grid"><?php foreach($allCourses as $course): ?><label class="check"><input type="checkbox" name="courses[]" value="<?= (int)$course['course_id'] ?>" <?= in_array((int)$course['course_id'],$chosenCourses,true)?'checked':'' ?>><span><?= e($course['course_name']) ?></span></label><?php endforeach; ?></div><?php if(!$allCourses): ?><p class="muted">No courses configured.</p><?php endif; ?></div>
</fieldset>
