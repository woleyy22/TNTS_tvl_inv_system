<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/notifications.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$user=require_login();$uid=(int)$user['id'];$pdo=db();
if(!tnts_notify_ready($pdo)){
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Install the notifications database migration first.']);exit;
}
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrf_check();
        $action=(string)($_POST['action']??'');
        if($action==='read'){
            $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
            if(!$id||$id<1)throw new InvalidArgumentException('Invalid notification.');
            $st=$pdo->prepare('UPDATE user_notifications SET read_at=COALESCE(read_at,NOW()) WHERE notification_id=? AND recipient_user_id=?');
            $st->execute([$id,$uid]);
        }elseif($action==='read_all'){
            $st=$pdo->prepare('UPDATE user_notifications SET read_at=NOW() WHERE recipient_user_id=? AND read_at IS NULL AND cleared_at IS NULL');
            $st->execute([$uid]);
        }elseif($action==='clear_read'){
            // Dismiss read alerts from the bell preview only. Full Notification History
            // continues showing them; unread alerts are never dismissed here.
            $st=$pdo->prepare('UPDATE user_notifications SET cleared_at=NOW() WHERE recipient_user_id=? AND read_at IS NOT NULL AND cleared_at IS NULL');
            $st->execute([$uid]);
            $cleared=$st->rowCount();
            echo json_encode(['ok'=>true,'cleared'=>$cleared,'unread'=>tnts_notify_count($pdo,$uid)]);exit;
        }else{throw new InvalidArgumentException('Invalid action.');}
        echo json_encode(['ok'=>true,'unread'=>tnts_notify_count($pdo,$uid)]);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='GET'){
        http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed']);exit;
    }
    tnts_notify_sync($pdo,$user);
    $mode=(string)($_GET['mode']??'bell');
    if(!in_array($mode,['bell','history'],true))throw new InvalidArgumentException('Invalid notification view.');
    $limit=$mode==='history'?30:8;
    $offset=$mode==='history'?max(0,min(100000,(int)($_GET['offset']??0))):0;
    $unread=$mode==='history' && ($_GET['unread']??'')==='1';
    $entries=tnts_notify_list($pdo,$uid,$limit,$offset,$unread,$mode==='bell');
    $st=$pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE recipient_user_id=?'.($mode==='bell'?' AND cleared_at IS NULL':'').($unread?' AND read_at IS NULL':''));
    $st->execute([$uid]);$total=(int)$st->fetchColumn();
    foreach($entries as &$n){$n['href']=url($n['target_path']);unset($n['target_path']);}unset($n);
    // A stable cursor lets the browser distinguish genuinely NEW records from polling/redraws.
    $lastIdStmt=$pdo->prepare('SELECT COALESCE(MAX(notification_id),0) FROM user_notifications WHERE recipient_user_id=?');
    $lastIdStmt->execute([$uid]);$latestId=(int)$lastIdStmt->fetchColumn();
    echo json_encode(['ok'=>true,'unread'=>tnts_notify_count($pdo,$uid),'total'=>$total,'latest_id'=>$latestId,'entries'=>$entries],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}catch(Throwable $e){
    error_log('TNTS notifications: '.$e->getMessage());
    http_response_code($e instanceof InvalidArgumentException?400:500);
    echo json_encode(['ok'=>false,'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Notifications could not be loaded. Please check the migration.']);exit;
}
