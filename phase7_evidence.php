<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
$user=require_login();
$role=(string)$user['role'];$uid=(int)($user['user_id']??$user['id']);
$kind=(string)($_GET['kind']??'');$id=(int)($_GET['id']??0);
if($id<1||!in_array($kind,['maintenance','request_photo','authorization','disposal'],true)){http_response_code(400);exit('Invalid evidence request.');}
$pdo=db();
if($kind==='maintenance'){
 $st=$pdo->prepare('SELECT ev.photo_file AS file,c.teacher_id FROM maintenance_events ev JOIN ics_items ii ON ii.ics_item_id=ev.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE ev.event_id=?');
}else{
 $column=$kind==='authorization'?'authorization_document':($kind==='disposal'?'disposal_document':'request_photo');
 $st=$pdo->prepare("SELECT co.$column AS file,c.teacher_id FROM condemnations co JOIN ics_items ii ON ii.ics_item_id=co.ics_item_id JOIN ics c ON c.ics_id=ii.ics_id WHERE co.condemnation_id=?");
}
$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row||($role==='teacher'&&(int)$row['teacher_id']!==$uid)||!in_array($role,['supply_officer','tvl_head','teacher'],true)){http_response_code(404);exit('Evidence not found.');}
$file=(string)($row['file']??'');
if(!preg_match('/^[a-f0-9]{40}\.(jpg|png|webp|pdf)$/D',$file)){http_response_code(404);exit('No evidence attached.');}
$path=__DIR__.'/storage/private_phase7_evidence/'.$file;
if(!is_file($path)){http_response_code(404);exit('Evidence unavailable.');}
$ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));$types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
header('Content-Type: '.$types[$ext]);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="TNTS-evidence.'.$ext.'"');header('Content-Length: '.filesize($path));readfile($path);
