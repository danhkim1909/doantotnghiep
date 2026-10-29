<?php
require_once __DIR__.'/../includes/social.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function bf_room_json(int $status,array $body):void {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
 $u=require_login();$uid=(int)$u['id'];$pdo=db();$room=positive_int($_REQUEST['room']??0);
 $s=$pdo->prepare('SELECT host_id,status FROM bf_meeting_rooms WHERE id=?');$s->execute([$room]);$r=$s->fetch();
 if(!$r||$r['status']!=='open')bf_room_json(410,['ok'=>false,'message'=>'Phòng đã kết thúc.']);
 $s=$pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');$s->execute([$room,$uid]);if(!$s->fetchColumn())bf_room_json(403,['ok'=>false,'message'=>'Bạn chưa tham gia phòng.']);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  require_csrf();$action=get_post('action');
  if($action==='signal'){
   $to=positive_int($_POST['to']??0);$kind=get_post('kind');$payload=(string)($_POST['payload']??'');
   if($to===$uid||!in_array($kind,['offer','answer','candidate'],true)||strlen($payload)>50000||!is_array(json_decode($payload,true)))bf_room_json(400,['ok'=>false]);
   $s=$pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');$s->execute([$room,$to]);if(!$s->fetchColumn())bf_room_json(403,['ok'=>false]);
   $pdo->prepare('INSERT INTO bf_meeting_signals(room_id,sender_id,receiver_id,kind,payload) VALUES (?,?,?,?,?)')->execute([$room,$uid,$to,$kind,$payload]);
  }elseif($action==='chat'){
   $body=trim((string)($_POST['body']??''));if($body===''||bf_social_len($body)>2000)bf_room_json(400,['ok'=>false]);
   $pdo->prepare('INSERT INTO bf_meeting_chat(room_id,user_id,body) VALUES (?,?,?)')->execute([$room,$uid,$body]);
  }elseif($action==='kick'){
   $to=positive_int($_POST['to']??0);if((int)$r['host_id']!==$uid||$to===$uid)bf_room_json(403,['ok'=>false]);
   $pdo->prepare('DELETE FROM bf_meeting_participants WHERE room_id=? AND user_id=?')->execute([$room,$to]);
  }elseif($action==='heartbeat'){}else bf_room_json(400,['ok'=>false]);
  $pdo->prepare('UPDATE bf_meeting_participants SET last_seen=NOW() WHERE room_id=? AND user_id=?')->execute([$room,$uid]);
  bf_room_json(200,['ok'=>true]);
 }
 $after=filter_var($_GET['after']??0,FILTER_VALIDATE_INT);$chatAfter=filter_var($_GET['chat_after']??0,FILTER_VALIDATE_INT);
 $after=max(0,(int)$after);$chatAfter=max(0,(int)$chatAfter);
 $pdo->prepare('UPDATE bf_meeting_participants SET last_seen=NOW() WHERE room_id=? AND user_id=?')->execute([$room,$uid]);
 $s=$pdo->prepare('SELECT p.user_id AS id,u.full_name FROM bf_meeting_participants p JOIN users u ON u.id=p.user_id WHERE p.room_id=? AND p.last_seen>=NOW()-INTERVAL 25 SECOND ORDER BY p.user_id LIMIT 7');$s->execute([$room]);$participants=$s->fetchAll();
 $s=$pdo->prepare('SELECT id,sender_id,kind,payload FROM bf_meeting_signals WHERE room_id=? AND receiver_id=? AND id>? ORDER BY id LIMIT 100');$s->execute([$room,$uid,$after]);$signals=$s->fetchAll();
 $s=$pdo->prepare('SELECT m.id,u.full_name sender,m.body,m.created_at FROM bf_meeting_chat m JOIN users u ON u.id=m.user_id WHERE m.room_id=? AND m.id>? ORDER BY m.id LIMIT 100');$s->execute([$room,$chatAfter]);$messages=$s->fetchAll();
 bf_room_json(200,['ok'=>true,'participants'=>$participants,'signals'=>$signals,'messages'=>$messages]);
}catch(Throwable $ex){error_log('[room-events] '.$ex->getMessage());bf_room_json(500,['ok'=>false,'message'=>'Không thể cập nhật dữ liệu phòng họp.']);}
