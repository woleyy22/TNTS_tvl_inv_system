<?php
require_once __DIR__.'/ics_accountability.php';
function borrow6_state(PDO $pdo,int $lineId,bool $lock=false):array {
 $stmt=$pdo->prepare("SELECT ii.ics_item_id, ii.item_id, c.teacher_id, c.status AS ics_status, c.ics_number, i.item_name,i.unit,i.item_category,co.course_name,u.full_name AS holder_name FROM ics_items ii JOIN ics c ON c.ics_id=ii.ics_id JOIN inventory_items i ON i.item_id=ii.item_id JOIN users u ON u.user_id=c.teacher_id LEFT JOIN courses co ON co.course_id=ii.course_id WHERE ii.ics_item_id=?".($lock?' FOR UPDATE':''));
 $stmt->execute([$lineId]);$r=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$r)throw new InvalidArgumentException('Accountability record could not be found.');
 $r['condition']=ics31_state($pdo,$lineId,$lock);
 $r['borrowed']=(int)borrow6_scalar($pdo,"SELECT COALESCE(SUM(quantity),0) FROM borrow_logs WHERE ics_item_id=? AND status='borrowed'",[$lineId]);
 $r['queued']=(int)borrow6_scalar($pdo,"SELECT COALESCE(SUM(quantity),0) FROM borrow_requests WHERE ics_item_id=? AND status IN ('requested','approved')",[$lineId]);
 $r['transfer_reserved']=(int)borrow6_scalar($pdo,"SELECT COALESCE(SUM(quantity),0) FROM transfer_requests WHERE ics_item_id=? AND status IN ('requested','accepted','awaiting_finalization')",[$lineId]);
 $r['serviceable']=(int)$r['condition']['qty_serviceable'];
 $r['available']=max(0,$r['serviceable']-$r['borrowed']-$r['queued']-$r['transfer_reserved']);
 if($r['ics_status']!=='active'||$r['condition']['needs_review']||!in_array($r['item_category'],['equipment','non_consumable'],true))$r['available']=0;
 return $r;
}
function borrow6_scalar(PDO $pdo,string $sql,array $params=[]):int {$s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();}
function borrow6_event(PDO $pdo,int $id,int $actor,string $action,string $note=''):void {
 $pdo->prepare('INSERT INTO borrow_request_events(request_id,actor_id,action,note) VALUES(?,?,?,?)')->execute([$id,$actor,$action,$note]);
 $pdo->prepare('INSERT INTO history_logs(user_id,action,module,record_id,details) VALUES(?,?,?,?,?)')->execute([$actor,'Borrow: '.$action,'Borrow',$id,$note]);
}
function borrow6_date($s):bool {if(!is_string($s))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d!==false&&$d->format('Y-m-d')===$s;}
