<?php
/** Authenticated access to an optional transfer handover photo. */
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
$user=require_role(['supply_officer','tvl_head','teacher']);
$uid=(int)$user['id'];$role=(string)$user['role'];
$id=filter_var($_GET['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);exit('Photo not found.');}
$pdo=db();
$s=$pdo->prepare('SELECT from_teacher_id,to_teacher_id,handover_photo_path FROM transfer_requests WHERE request_id=?');
$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
if(!$row || ($role==='teacher' && $uid!==(int)$row['from_teacher_id'] && $uid!==(int)$row['to_teacher_id'])){
 http_response_code(404);exit('Photo not found.');
}
$name=(string)($row['handover_photo_path']??'');
if(!preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/D',$name)){http_response_code(404);exit('Photo not found.');}
$file=__DIR__.'/storage/private_transfer_evidence/'.$name;
if(!is_file($file)){http_response_code(404);exit('Photo not found.');}
$types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
$ext=pathinfo($name,PATHINFO_EXTENSION);
header('Content-Type: '.$types[$ext]);
header('Content-Length: '.filesize($file));
header('Content-Disposition: inline; filename="transfer-evidence.'.$ext.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
