<?php
/** Simple small-group WebRTC meeting room; signaling is stored in MySQL. */
require_once __DIR__.'/includes/portal_ui.php';
$user=require_login();$uid=(int)$user['id'];$pdo=db();$errors=[];$roomCode=strtoupper(trim(get_query('room')));$room=null;$participants=[];
if(is_post()){
 require_csrf();$action=get_post('action');
 try{
  if($action==='create'){
   $title=clean_text(get_post('title'),150);$password=(string)($_POST['password']??'');
   if(bf_social_len($title)<3)throw new DomainException('Tên phòng cần ít nhất 3 ký tự.');
   if(strlen($password)>100)throw new DomainException('Mật khẩu phòng quá dài.');
   $code=strtoupper(bin2hex(random_bytes(6)));$pdo->beginTransaction();
   try{$pdo->prepare('INSERT INTO bf_meeting_rooms(code,title,host_id,password_hash) VALUES (?,?,?,?)')->execute([$code,$title,$uid,$password===''?null:password_hash($password,PASSWORD_DEFAULT)]);$rid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO bf_meeting_participants(room_id,user_id) VALUES (?,?)')->execute([$rid,$uid]);$pdo->commit();}
   catch(Throwable $ex){$pdo->rollBack();throw $ex;}
   redirect('rooms.php?room='.$code,303);
  }elseif($action==='join'){
   $code=strtoupper(trim(get_post('code')));$password=(string)($_POST['password']??'');
   $s=$pdo->prepare('SELECT id,password_hash,status FROM bf_meeting_rooms WHERE code=? LIMIT 1');$s->execute([$code]);$found=$s->fetch();
   if(!$found||$found['status']!=='open')throw new DomainException('Không tìm thấy phòng đang hoạt động.');
   if($found['password_hash']&&!password_verify($password,$found['password_hash']))throw new DomainException('Mật khẩu phòng không đúng.');
   $rid=(int)$found['id'];
   $check=$pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');$check->execute([$rid,$uid]);
   if(!$check->fetchColumn()){
     $count=$pdo->prepare('SELECT COUNT(*) FROM bf_meeting_participants WHERE room_id=? AND last_seen>=NOW()-INTERVAL 25 SECOND');$count->execute([$rid]);
     if((int)$count->fetchColumn()>=6)throw new DomainException('Phòng đã đủ 6 thành viên đang hoạt động.');
   }
   $pdo->prepare('INSERT INTO bf_meeting_participants(room_id,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE last_seen=NOW()')->execute([$rid,$uid]);
   redirect('rooms.php?room='.$code,303);
  }elseif(in_array($action,['leave','end'],true)){
   $rid=positive_int($_POST['room_id']??0);$s=$pdo->prepare('SELECT host_id FROM bf_meeting_rooms WHERE id=?');$s->execute([$rid]);$host=(int)$s->fetchColumn();
   if($action==='end'&&$host===$uid){$pdo->prepare('UPDATE bf_meeting_rooms SET status="ended",ended_at=NOW() WHERE id=?')->execute([$rid]);$pdo->prepare('DELETE FROM bf_meeting_participants WHERE room_id=?')->execute([$rid]);}
   elseif($action==='leave'){$pdo->prepare('DELETE FROM bf_meeting_participants WHERE room_id=? AND user_id=?')->execute([$rid,$uid]);}
   else throw new DomainException('Bạn không được phép kết thúc phòng.');
   redirect('rooms.php',303);
  }else throw new DomainException('Thao tác không hợp lệ.');
 }catch(DomainException $ex){$errors[]=$ex->getMessage();}catch(Throwable $ex){error_log('[rooms] '.$ex->getMessage());$errors[]='Không thể xử lý phòng họp. Hãy nhập SQL bổ sung trước khi sử dụng.';}
}
try{
 if(preg_match('/^[A-F0-9]{12}$/',$roomCode)){
  $s=$pdo->prepare('SELECT r.*,u.full_name AS host_name FROM bf_meeting_rooms r JOIN users u ON u.id=r.host_id WHERE r.code=?');$s->execute([$roomCode]);$room=$s->fetch()?:null;
  if($room && $room['status']==='open'){
   $s=$pdo->prepare('SELECT 1 FROM bf_meeting_participants WHERE room_id=? AND user_id=?');$s->execute([(int)$room['id'],$uid]);
   if(!$s->fetchColumn()){$errors[]='Bạn cần tham gia phòng trước khi xem nội dung. Nhập mật khẩu phòng (nếu có).';$room=null;}
  }elseif($room){$errors[]='Cuộc họp đã kết thúc.';$room=null;}
 }
 $s=$pdo->prepare('SELECT r.code,r.title,r.created_at FROM bf_meeting_rooms r JOIN bf_meeting_participants p ON p.room_id=r.id WHERE p.user_id=? AND r.status="open" ORDER BY r.id DESC LIMIT 20');$s->execute([$uid]);$myRooms=$s->fetchAll();
}catch(Throwable $ex){error_log('[rooms read] '.$ex->getMessage());$errors[]='Chức năng phòng họp chưa có dữ liệu. Vui lòng nhập database/20261029_social_meetings.sql.';$myRooms=[];}
bf_portal_begin($user,'rooms','Phòng gọi nhóm');
?>
<header class="bf-header"><div><h1>Phòng gọi Video / Audio</h1><p>Học tập cùng bạn bè qua âm thanh, hình ảnh, chia sẻ màn hình và chat.</p></div><a class="bf-muted" href="<?=e(app_url('social.php'))?>">← Trở về cộng đồng</a></header>
<?php foreach($errors as $error):?><div class="bf-note bf-error" role="alert"><?=e($error)?></div><?php endforeach?>
<?php if($room):?>
<div class="bf-header"><div><h2 style="margin:0"><?=e($room['title'])?></h2><p class="bf-subtle">Chủ phòng: <?=e($room['host_name'])?> · Mã phòng: <strong><?=e($room['code'])?></strong> · Hỗ trợ tối đa 6 tài khoản đang hoạt động</p></div><div class="bf-actions"><button type="button" class="bf-muted" id="copyRoom">Sao chép liên kết phòng</button><form method="post" onsubmit="return confirm('<?=((int)$room['host_id']===$uid?'Kết thúc cuộc họp cho tất cả mọi người?':'Rời phòng họp?')?>')"><?=csrf_field()?><input type="hidden" name="room_id" value="<?=(int)$room['id']?>"><input type="hidden" name="action" value="<?=((int)$room['host_id']===$uid?'end':'leave')?>"><button class="bf-muted bf-danger"><?=((int)$room['host_id']===$uid?'Kết thúc phòng':'Rời phòng')?></button></form></div></div>
<div class="bf-note">Trình duyệt sẽ yêu cầu quyền micro/camera. Gọi video cần <b>localhost</b> hoặc HTTPS; kết nối WebRTC trực tiếp có thể bị giới hạn bởi mạng/NAT. Chức năng ghi âm/ghi hình cuộc họp chưa được hỗ trợ.</div>
<div class="bf-cols"><section class="bf-panel"><div id="callStatus" class="bf-subtle" aria-live="polite">Đang khởi tạo cuộc họp...</div><div class="bf-call-bar"><button id="startCall" class="bf-btn">Bật camera / micro</button><button id="toggleMic" class="bf-muted" disabled>Micro</button><button id="toggleCamera" class="bf-muted" disabled>Camera</button><button id="shareScreen" class="bf-muted" disabled>Chia sẻ màn hình</button></div><div id="videoGrid" class="bf-call-grid"><div class="bf-video-wrap" id="localTile"><div class="bf-placeholder">Bật camera để bắt đầu</div><video id="localVideo" autoplay muted playsinline></video><label>Bạn (<?=e($user['full_name'])?>)</label></div></div><h3>Thành viên đang kết nối</h3><p id="participantsText" class="bf-subtle">Đang cập nhật...</p></section><section class="bf-panel"><h2>Chat trong phòng</h2><div class="bf-room-chat" id="roomMessages" aria-live="polite"></div><form id="roomMessageForm" class="bf-message-form"><input class="bf-input" maxlength="2000" required id="roomMessageInput" placeholder="Nhắn tin trong phòng..."><button class="bf-btn">Gửi</button></form></section></div>
<script>window.BF_ROOM_CONFIG={id:<?=(int)$room['id']?>,uid:<?=$uid?>,host:<?=(int)$room['host_id']?>,endpoint:<?=json_encode(app_url('api/room-events.php'))?>,csrf:<?=json_encode(csrf_token())?>,link:<?=json_encode(app_url('rooms.php?room='.$room['code']))?>};</script><script src="<?=e(app_url('js/bf-meeting.js'))?>" defer></script>
<?php else:?>
<div class="bf-grid"><section class="bf-panel"><h2>Tạo phòng mới</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><label class="bf-field">Tên phòng<input name="title" class="bf-input" required minlength="3" maxlength="150" placeholder="Ví dụ: Thảo luận đồ án Python"></label><label class="bf-field">Mật khẩu phòng (không bắt buộc)<input name="password" class="bf-input" maxlength="100" type="password" autocomplete="new-password" placeholder="Để trống nếu không đặt mật khẩu"></label><button class="bf-btn">+ Tạo phòng họp</button></form></section><section class="bf-panel"><h2>Tham gia phòng đang có</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="join"><label class="bf-field">Mã phòng<input name="code" class="bf-input" value="<?=e($roomCode)?>" required minlength="12" maxlength="12" placeholder="Mã gồm 12 ký tự"></label><label class="bf-field">Mật khẩu (nếu có)<input name="password" type="password" class="bf-input" autocomplete="off" maxlength="100"></label><button class="bf-btn">→ Tham gia phòng</button></form><h2 style="margin-top:28px">Phòng đã tham gia</h2><?php foreach($myRooms as $r):?><a href="?room=<?=e($r['code'])?>" class="bf-row"><div><strong><?=e($r['title'])?></strong><small><?=e($r['code'])?></small></div><span>→</span></a><?php endforeach?><?php if(!$myRooms):?><p class="bf-subtle">Bạn chưa tham gia phòng họp nào đang mở.</p><?php endif?></section></div>
<?php endif?>
<?php bf_portal_end(); ?>
