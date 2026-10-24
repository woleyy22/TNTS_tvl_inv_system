<?php
/** TNTS notification read model: existing committed event/history records are the authoritative source.
 * This module never accepts an outcome/approval state from a notification action.
 * It inserts only alerts addressed to the logged-in recipient, guarded by unique event keys.
 */
require_once __DIR__ . '/db.php';

function tnts_notify_ready(PDO $pdo): bool {
    try {
        return (bool)$pdo->query("SELECT 1 FROM notification_configuration WHERE config_id=1")->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function tnts_notify_record(PDO $pdo, int $uid, string $key, string $kind, string $title, string $body, string $path, string $occurred): void {
    // Only hardcoded internal destinations are ever supplied by this module.
    $allowed = ['borrow.php','transfers.php','transfer_history.php','approvals.php','personnel_reviews.php','condemnation.php','ics_issue.php','inventory.php','ics_returns.php','account.php'];
    $page = explode('?', $path, 2)[0];
    if (!in_array($page, $allowed, true)) return;
    // Refresh presentation of previously recorded events without generating duplicates or clearing read state.
    $st = $pdo->prepare('INSERT INTO user_notifications (recipient_user_id,event_key,kind,title,message,target_path,occurred_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),message=VALUES(message),target_path=VALUES(target_path)');
    $st->execute([$uid, substr($key,0,100), substr($kind,0,35), substr($title,0,180), substr($body,0,2000), $path, $occurred]);
}

function tnts_notify_sync(PDO $pdo, array $user): void {
    if (!tnts_notify_ready($pdo)) return;
    $uid=(int)$user['id'];
    $role=(string)$user['role'];
    $reviewer=in_array($role,['supply_officer','tvl_head'],true);
    $officer=($role==='supply_officer');
    $config=$pdo->query('SELECT enabled_since,ics_baseline_id FROM notification_configuration WHERE config_id=1')->fetch(PDO::FETCH_ASSOC);
    $since=(string)$config['enabled_since'];
    $icsBaseline=(int)$config['ics_baseline_id'];
    // A bounded lookback avoids querying years of history on every header poll. To avoid dropped
    // events the bound is generous for the TNTS school's expected transaction volume.
    $run=function(string $sql, array $params, callable $fn) use($pdo) {
        $st=$pdo->prepare($sql);$st->execute($params);
        while($r=$st->fetch(PDO::FETCH_ASSOC)) $fn($r);
    };
    $save=function(string $key,string $kind,string $title,string $body,string $path,string $time)use($pdo,$uid){
        tnts_notify_record($pdo,$uid,$key,$kind,$title,$body,$path,$time);
    };
    $name=static function($v){return trim((string)$v)!==''?(string)$v:'Unknown teacher';};

    // Borrowing: one concise sentence for the action, purpose and any unique decision note.
    $run("SELECT e.event_id,e.action,e.note,e.created_at,br.request_id,br.lender_id,br.borrower_id,br.quantity,br.purpose,br.expected_return_date,
       i.item_name, a.full_name actor_name,l.full_name lender_name,b.full_name borrower_name
       FROM borrow_request_events e JOIN borrow_requests br ON br.request_id=e.request_id
       JOIN ics_items ii ON ii.ics_item_id=br.ics_item_id JOIN inventory_items i ON i.item_id=ii.item_id
       LEFT JOIN users a ON a.user_id=e.actor_id LEFT JOIN users l ON l.user_id=br.lender_id LEFT JOIN users b ON b.user_id=br.borrower_id
       WHERE e.created_at>=? AND (br.lender_id=? OR br.borrower_id=?) AND e.actor_id<>?
       ORDER BY e.event_id DESC LIMIT 1500",[$since,$uid,$uid,$uid], function($r)use($save){
         $action=strtolower(trim((string)$r['action']));
         $label=ucwords(str_replace('_',' ',$action));
         $item=(int)$r['quantity'].' × '.$r['item_name'];
         $who=trim((string)$r['actor_name']);
         $people=$r['lender_name'].' → '.$r['borrower_name'];
         if($action==='requested'){
            $title='Borrowing request';
            $body=$r['borrower_name'].' requested '.$item.' from '.$r['lender_name'].'.';
         }elseif($action==='approved'){
            $title='Borrowing approved';
            $body=$item.' for '.$r['borrower_name'].' was approved by '.$r['lender_name'].'.';
         }elseif($action==='released'){
            $title='Borrowing released';
            $body=$item.' was released to '.$r['borrower_name'].' by '.$r['lender_name'].'.';
         }else{
            $title='Borrowing · '.$label;
            $body=$item.' · '.$people.'.';
         }
         $purpose=trim((string)$r['purpose']);
         if($purpose!=='' && in_array($action,['requested','approved','released','declined','rejected'],true))$body.=' Purpose: '.$purpose.'.';
         $note=trim((string)$r['note']);
         if($action==='requested' && !empty($r['expected_return_date'])){
            $due=(string)$r['expected_return_date'];
            // Older request audit notes contain the return time; keep it once.
            if(preg_match('/\bdue\s+([0-9]{4}-[0-9]{2}-[0-9]{2})\s+([0-9]{2}:[0-9]{2})/i',$note,$dueMatch) && $dueMatch[1]===$due){$due.=' '.$dueMatch[2];}
            $body.=' Due: '.$due.'.';
         }
         // Some older event handlers copied quantity, due date and purpose into the note itself.
         if($action==='requested' && preg_match('/^quantity\s*:\s*\d+\s*[;,].*\bpurpose\s*:/i',$note))$note='';
         if($note!=='' && strcasecmp(trim($note," .\t\n"),trim($purpose," .\t\n"))!==0){
            $body.=' '.(preg_match('/^(reason|notes?|remark|issue)\s*:/i',$note)?'':'Note: ').$note;
            if(!preg_match('/[.!?]$/u',$body))$body.='.';
         }
         $save('borrow:event:'.$r['event_id'],'borrowing',$title,$body,'borrow.php?request_id='.(int)$r['request_id'],$r['created_at']);
    });

    // Property transfers: status-specific next steps and role-aware deep links.
    $run("SELECT e.event_id,e.event_type,e.notes,e.occurred_at, r.request_id,r.from_teacher_id,r.to_teacher_id,r.requested_by,
       r.reason,r.quantity,r.transfer_method, i.item_name,i.unit,a.full_name actor_name,s.full_name sender_name,t.full_name recipient_name
       FROM transfer_request_events e JOIN transfer_requests r ON r.request_id=e.transfer_request_id
       JOIN ics_items ii ON ii.ics_item_id=r.ics_item_id JOIN inventory_items i ON i.item_id=ii.item_id
       LEFT JOIN users a ON a.user_id=e.actor_user_id LEFT JOIN users s ON s.user_id=r.from_teacher_id LEFT JOIN users t ON t.user_id=r.to_teacher_id
       WHERE e.occurred_at>=? AND (r.from_teacher_id=? OR r.to_teacher_id=? OR r.requested_by=? OR ?=1)
         AND e.actor_user_id<>? ORDER BY e.event_id DESC LIMIT 1500",[$since,$uid,$uid,$uid,$officer?1:0,$uid],function($r)use($save,$uid,$officer){
         $event=strtolower((string)$r['event_type']);
         $item=(int)$r['quantity'].' '.trim((string)$r['unit']).' of '.$r['item_name'];
         $route=$r['sender_name'].' → '.$r['recipient_name'];
         $context=$item.' · '.$route.'.';
         $reason=trim((string)$r['reason']);
         $note=trim((string)$r['notes']);
         $requested= $event==='requested' && $r['transfer_method']!=='assisted';
         $pendingOfficer= in_array($event,['accept','verify'],true);
         $path=($requested || $pendingOfficer) ? 'transfers.php?open='.(int)$r['request_id'] : 'transfer_history.php?open='.(int)$r['request_id'];
         switch($event){
           case 'requested':
             if($r['transfer_method']==='assisted'){
               $title='Assisted transfer recorded';
               $body=$context.' Supply Officer documented the physical handover.';
               $path='transfer_history.php?open='.(int)$r['request_id'];
               break;
             }
             $title='Property transfer requested';
             $body=$context;
             if($reason!=='')$body.=' Reason: '.$reason.'.';
             if((int)$r['to_teacher_id']===$uid)$body.=' Accept or decline in Property Transfers.';
             else $body.=' Awaiting '.$r['recipient_name'].'’s response.';
             break;
           case 'accept':
             $title='Transfer accepted — awaiting Supply Officer';
             $body=$r['recipient_name'].' accepted '.$item.' from '.$r['sender_name'].'.';
             $body.=$officer?' Verify and finalize the transfer.':' Awaiting Supply Officer verification and finalization.';
             if($note!=='')$body.=' Note: '.$note.'.';
             break;
           case 'decline':case 'reject':case 'cancel':
             $state=$event==='decline'?'declined':($event==='reject'?'rejected':'cancelled');
             $title='Property transfer '.ucfirst($state);
             $body=$context;
             if($note!=='')$body.=' Reason: '.$note.'.';
             break;
           case 'verify':
             $title='Transfer verified';
             $body=$context.' Supply Officer verified the handover.';
             if($officer)$body.=' Finalization is the next step.';
             break;
           case 'finalize':
             $title='Property transfer completed';
             $body=$context.' Recipient ICS accountability updated.';
             // The finalization event carries the authoritative new ICS number.
             if(preg_match('/\b(ICS-[A-Za-z0-9-]+)\b/i',$note,$found))$body.=' New ICS: '.$found[1].'.';
             break;
           case 'assisted_acknowledgment':
             $title='Handover acknowledged';
             $body=$context.' Supply Officer recorded both teachers’ acknowledgment.';
             break;
           default:
             $title='Property transfer updated';
             $body=$context;
             if($note!=='')$body.=' Note: '.$note.'.';
         }
         $save('transfer:event:'.$r['event_id'],'transfer',$title,$body,$path,$r['occurred_at']);
    });

    // Actionable verified registrations remain visible to reviewers even when submitted before installation.
    if($reviewer){
      $run("SELECT u.user_id,u.full_name,u.email,COALESCE(u.email_verified_at,u.created_at) occurred_at
         FROM users u WHERE u.role='teacher' AND u.registration_source='self_registration' AND u.registration_status='pending'
         AND u.email_verified_at IS NOT NULL
         ORDER BY u.user_id DESC LIMIT 1000",[], function($r)use($save){
           $save('registration:pending:'.$r['user_id'],'accounts','Registration needs review',
             $r['full_name'].' ('.$r['email'].') submitted a verified registration.','approvals.php?status=pending&user_id='.(int)$r['user_id'],$r['occurred_at']);
         });
    }
    // Approval decisions: notify the account owner (only if the account still exists).
    $run("SELECT h.approval_id,h.action,h.notes,h.created_at,u.full_name,actor.full_name reviewer
       FROM account_approval_history h JOIN users u ON u.user_id=h.target_user_id
       LEFT JOIN users actor ON actor.user_id=h.actor_user_id WHERE h.created_at>=? AND h.target_user_id=?
       ORDER BY h.approval_id DESC LIMIT 1000",[$since,$uid],function($r)use($save){
         $body='Your teacher registration was '.($r['action']==='approve'?'approved':'rejected').'.';
         if($r['notes'])$body.=' Reason: '.$r['notes'].'.';
         if($r['reviewer'])$body.=' Reviewed by '.$r['reviewer'].'.';
         $save('registration:decision:'.$r['approval_id'],'accounts','Registration · '.ucfirst($r['action']),$body,'account.php#officialInformation',$r['created_at']);
    });

    // Personnel and official-profile requests. Reviewer sees submissions; requester sees decisions.
    $run("SELECT c.change_id,c.user_id,c.request_type,c.requested_at,c.status,c.reviewed_at,c.review_notes,u.full_name
       FROM account_change_requests c JOIN users u ON u.user_id=c.user_id
       WHERE (c.requested_at>=? OR c.reviewed_at>=? OR (c.status='pending' AND ?=1)) AND (c.user_id=? OR ?=1)
       ORDER BY c.change_id DESC LIMIT 1200",[$since,$since,$reviewer?1:0,$uid,$reviewer?1:0],function($r)use($save,$reviewer,$uid,$since){
         $type=str_replace('_',' ',(string)$r['request_type']);
         if($reviewer && ((string)$r['requested_at'] >=$since || $r['status']==='pending') && (int)$r['user_id']!==$uid){
            $save('personnel:request:'.$r['change_id'],'accounts','Personnel change requested',
               $r['full_name'].' requested a '.$type.' update.','personnel_reviews.php?open='.(int)$r['change_id'],$r['requested_at']);
         }
         if((int)$r['user_id']===$uid && $r['status']!=='pending' && !empty($r['reviewed_at']) && (string)$r['reviewed_at'] >=$since){
            $msg='Your '.$type.' request was '.$r['status'].'.';
            if(trim((string)$r['review_notes'])!=='')$msg.=' Reason: '.$r['review_notes'].'.';
            $save('personnel:decision:'.$r['change_id'],'accounts','Personnel change · '.ucfirst($r['status']),$msg,'account.php#officialInformation',$r['reviewed_at']);
         }
    });

    // Condemnation requests: still-pending requests remain actionable after installation.
    $run("SELECT c.condemnation_id,c.quantity,c.reason,c.requested_by,c.created_at,i.item_name,u.full_name requester_name
        FROM condemnations c JOIN ics_items ii ON ii.ics_item_id=c.ics_item_id
        JOIN inventory_items i ON i.item_id=ii.item_id LEFT JOIN users u ON u.user_id=c.requested_by
        WHERE (c.created_at>=? OR c.status='requested') AND ?=1 AND c.requested_by<>? ORDER BY c.condemnation_id DESC LIMIT 1200",
        [$since,$officer?1:0,$uid],function($r)use($save){
          $save('condemnation:requested:'.$r['condemnation_id'],'condemnation','Condemnation request',
              $r['requester_name'].' requested review for '.$r['quantity'].' × '.$r['item_name'].'. Reason: '.$r['reason'],
              'condemnation.php?request_id='.(int)$r['condemnation_id'],$r['created_at']);
        });
    // Decisions use the COMMITTED audit event time (not the date-only condemnation field).
    $run("SELECT h.log_id,h.action,h.details,h.created_at,c.condemnation_id,c.status,c.outcome,c.reason,c.quantity,
         i.item_name, reviewer.full_name reviewer_name
        FROM history_logs h JOIN condemnations c ON c.condemnation_id=h.record_id
        JOIN ics_items ii ON ii.ics_item_id=c.ics_item_id JOIN inventory_items i ON i.item_id=ii.item_id
        LEFT JOIN users reviewer ON reviewer.user_id=h.user_id
        WHERE h.module='Condemnation' AND h.created_at>=? AND c.requested_by=?
          AND (h.action='Approved Condemnation' OR h.action='Rejected Condemnation')
        ORDER BY h.log_id DESC LIMIT 1000",[$since,$uid],function($r)use($save){
          $body=$r['quantity'].' × '.$r['item_name'].' · '.$r['status'].'. Request reason: '.$r['reason'].'.';
          if($r['outcome'])$body.=' Recorded outcome: '.$r['outcome'].'.';
          if($r['reviewer_name'])$body.=' Reviewed by '.$r['reviewer_name'].'.';
          $save('condemnation:audit:'.$r['log_id'],'condemnation','Condemnation · '.ucfirst($r['status']),
            $body,'condemnation.php?request_id='.(int)$r['condemnation_id'],$r['created_at']);
        });

    // ICS issued to a teacher; a date-only legacy field gives the best available event date.
    $run("SELECT c.ics_id,c.ics_number,c.teacher_id,c.issued_by,c.date_issued,u.full_name issuer
      FROM ics c LEFT JOIN users u ON u.user_id=c.issued_by WHERE c.teacher_id=? AND c.ics_id>? AND c.issued_by<>?
      ORDER BY c.ics_id DESC LIMIT 500",[$uid,$icsBaseline,$uid],function($r)use($save){
       $save('ics:issued:'.$r['ics_id'],'ics','New ICS issued',
          'ICS '.$r['ics_number'].' was issued to your account'.($r['issuer']?' by '.$r['issuer']:'').'. Review your accountable inventory.',
          'inventory.php?view=issued&search='.rawurlencode((string)$r['ics_number']).'#p34InventoryTable',$r['date_issued'].' 12:00:00');
    });
    // Verified ICS returns / closure: teacher whose ICS was reconciled.
    $run("SELECT ret.return_id,ret.returned_at,ret.reason,c.ics_id,c.ics_number,c.teacher_id,ret.verified_by,u.full_name verifier
        FROM ics_returns ret JOIN ics c ON c.ics_id=ret.ics_id LEFT JOIN users u ON u.user_id=ret.verified_by
        WHERE ret.returned_at>=? AND c.teacher_id=? AND ret.verified_by<>? ORDER BY ret.return_id DESC LIMIT 700",
        [$since,$uid,$uid],function($r)use($save){
          $save('ics:return:'.$r['return_id'],'ics','ICS return recorded',
            'A return was verified for '.$r['ics_number'].'. Notes: '.$r['reason'].($r['verifier']?' By '.$r['verifier'].'.':''),
            'inventory.php?view=issued&search='.rawurlencode((string)$r['ics_number']).'#p34InventoryTable',$r['returned_at']);
        });
}

function tnts_notify_list(PDO $pdo,int $uid,int $limit=12,int $offset=0,bool $unreadOnly=false,bool $forBell=false): array {
    // Cleared alerts are dismissed from the bell preview only. Full history
    // retains every event, including previously cleared read notifications.
    $sql='SELECT notification_id,kind,title,message,target_path,occurred_at,read_at FROM user_notifications WHERE recipient_user_id=?';
    if($forBell)$sql.=' AND cleared_at IS NULL';
    if($unreadOnly)$sql.=' AND read_at IS NULL';
    $sql.=' ORDER BY occurred_at DESC,notification_id DESC LIMIT '.max(1,min(100,$limit)).' OFFSET '.max(0,$offset);
    $st=$pdo->prepare($sql);$st->execute([$uid]);return $st->fetchAll(PDO::FETCH_ASSOC);
}
function tnts_notify_count(PDO $pdo,int $uid): int {
    $st=$pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE recipient_user_id=? AND read_at IS NULL AND cleared_at IS NULL');
    $st->execute([$uid]);return (int)$st->fetchColumn();
}
