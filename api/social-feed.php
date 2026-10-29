<?php
require_once __DIR__.'/../includes/social.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $u=require_login();$uid=(int)$u['id'];$id=positive_int($_GET['id']??0);$kind=get_query('kind');$pdo=db();
 if(!$id||!in_array($kind,['group','direct'],true)){http_response_code(400);echo json_encode(['ok'=>false]);exit;}
 if($kind==='direct'){
  if(!bf_social_friend($pdo,$uid,$id)){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
  $s=$pdo->prepare('SELECT m.id,m.sender_id,u.full_name sender,m.body,m.created_at FROM bf_direct_messages m JOIN users u ON u.id=m.sender_id WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) ORDER BY m.id DESC LIMIT 100');$s->execute([$uid,$id,$id,$uid]);
 }else{
  if(!bf_social_group_member($pdo,$id,$uid)){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
  $s=$pdo->prepare('SELECT m.id,m.sender_id,u.full_name sender,m.body,m.created_at FROM bf_group_messages m JOIN users u ON u.id=m.sender_id WHERE m.group_id=? ORDER BY m.id DESC LIMIT 100');$s->execute([$id]);
 }
 $msgs=array_reverse($s->fetchAll());foreach($msgs as &$m){$m['mine']=(int)$m['sender_id']===$uid;unset($m['sender_id']);}unset($m);
 echo json_encode(['ok'=>true,'messages'=>$msgs],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log('[social-feed] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false]);}
