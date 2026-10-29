<?php
/** BrightFuture: friend invitations, direct and group messages (PHP 8.0 compatible). */
require_once __DIR__.'/includes/portal_ui.php';
$user=require_login(); $uid=(int)$user['id']; $pdo=db(); $errors=[];
$tab=get_query('tab','friends'); if(!in_array($tab,['friends','chat','groups','search'],true))$tab='friends';
$peer=positive_int($_GET['peer']??0);$group=positive_int($_GET['group']??0); $query=clean_text(get_query('q'),100);
function bf_social_go(string $url,string $msg):void {set_flash('success',$msg);redirect($url,303);}
function bf_social_target(PDO $pdo,int $target):bool { $st=$pdo->prepare('SELECT 1 FROM users WHERE id=?');$st->execute([$target]);return (bool)$st->fetchColumn();}
if(is_post()){
 require_csrf();$action=get_post('action');$target=positive_int($_POST['target']??0);$g=positive_int($_POST['group']??0);
 try{
  if(in_array($action,['request','accept','reject','unfriend','block','unblock','report','direct'],true) && (!$target||$target===$uid||!bf_social_target($pdo,$target)))throw new DomainException('Người dùng không hợp lệ.');
  if($action==='request'){
   if(bf_social_blocked($pdo,$uid,$target))throw new DomainException('Không thể gửi lời mời đến tài khoản này.');
   [$low,$high]=bf_pair($uid,$target);
   $s=$pdo->prepare('INSERT INTO bf_friendships (user_low,user_high,requested_by,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE requested_by=IF(status="accepted",requested_by,VALUES(requested_by)),status=IF(status="accepted",status,"pending")');$s->execute([$low,$high,$uid,'pending']);
   bf_social_go('social.php?tab=search&q='.rawurlencode($query),'Đã gửi lời mời kết bạn.');
  }elseif(in_array($action,['accept','reject','unfriend'],true)){
   [$low,$high]=bf_pair($uid,$target);
   if($action==='accept'){$s=$pdo->prepare('UPDATE bf_friendships SET status="accepted" WHERE user_low=? AND user_high=? AND requested_by=? AND status="pending"');$s->execute([$low,$high,$target]);}
   elseif($action==='reject'){$s=$pdo->prepare('DELETE FROM bf_friendships WHERE user_low=? AND user_high=? AND requested_by=? AND status="pending"');$s->execute([$low,$high,$target]);}
   else{$s=$pdo->prepare('DELETE FROM bf_friendships WHERE user_low=? AND user_high=? AND status="accepted"');$s->execute([$low,$high]);}
   if(!$s->rowCount())throw new DomainException('Lời mời hoặc quan hệ bạn bè không còn hợp lệ.');
   bf_social_go('social.php?tab=friends','Đã cập nhật danh sách bạn bè.');
  }elseif($action==='block'){
   $pdo->prepare('INSERT IGNORE INTO bf_blocks (blocker_id,blocked_id) VALUES (?,?)')->execute([$uid,$target]);
   [$low,$high]=bf_pair($uid,$target);$pdo->prepare('DELETE FROM bf_friendships WHERE user_low=? AND user_high=?')->execute([$low,$high]);
   bf_social_go('social.php?tab=friends','Đã chặn người dùng.');
  }elseif($action==='unblock'){
   $pdo->prepare('DELETE FROM bf_blocks WHERE blocker_id=? AND blocked_id=?')->execute([$uid,$target]);
   bf_social_go('social.php?tab=friends','Đã bỏ chặn.');
  }elseif($action==='report'){
   $reason=clean_text(get_post('reason'),1000);
   if(bf_social_len($reason)<8)throw new DomainException('Nội dung báo cáo cần tối thiểu 8 ký tự.');
   $pdo->prepare('INSERT INTO bf_user_reports(reporter_id,reported_id,reason) VALUES (?,?,?)')->execute([$uid,$target,$reason]);
   bf_social_go('social.php?tab=chat&peer='.$target,'Báo cáo đã được gửi tới quản trị viên.');
  }elseif($action==='direct'){
   if(!bf_social_friend($pdo,$uid,$target))throw new DomainException('Chỉ được nhắn tin với bạn bè đã xác nhận.');
   $text=trim((string)($_POST['body']??''));if($text===''||bf_social_len($text)>2000)throw new DomainException('Tin nhắn phải có từ 1 đến 2.000 ký tự.');
   $pdo->prepare('INSERT INTO bf_direct_messages (sender_id,receiver_id,body) VALUES (?,?,?)')->execute([$uid,$target,$text]);
   redirect('social.php?tab=chat&peer='.$target,303);
  }elseif($action==='create_group'){
   $title=clean_text(get_post('title'),120); if(bf_social_len($title)<3)throw new DomainException('Tên nhóm cần ít nhất 3 ký tự.');
   $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO bf_chat_groups(title,owner_id) VALUES (?,?)')->execute([$title,$uid]);$g=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO bf_chat_members(group_id,user_id) VALUES (?,?)')->execute([$g,$uid]);$pdo->commit();}catch(Throwable $ex){$pdo->rollBack();throw $ex;}
   bf_social_go('social.php?tab=groups&group='.$g,'Đã tạo nhóm chat mới.');
  }elseif($action==='invite'){
   if(!bf_social_group_member($pdo,$g,$uid))throw new DomainException('Bạn không thuộc nhóm này.');
   if(!bf_social_friend($pdo,$uid,$target))throw new DomainException('Chỉ được mời bạn bè vào nhóm.');
   $pdo->prepare('INSERT IGNORE INTO bf_chat_members(group_id,user_id) VALUES (?,?)')->execute([$g,$target]);
   bf_social_go('social.php?tab=groups&group='.$g,'Đã thêm thành viên vào nhóm.');
  }elseif($action==='leave'){
   if(!bf_social_group_member($pdo,$g,$uid))throw new DomainException('Bạn chưa tham gia nhóm.');
   $s=$pdo->prepare('SELECT owner_id FROM bf_chat_groups WHERE id=?');$s->execute([$g]);
   if((int)$s->fetchColumn()===$uid)throw new DomainException('Trưởng nhóm không thể rời nhóm. Hãy xóa nhóm nếu không sử dụng.');
   $pdo->prepare('DELETE FROM bf_chat_members WHERE group_id=? AND user_id=?')->execute([$g,$uid]);
   bf_social_go('social.php?tab=groups','Đã rời nhóm chat.');
  }elseif($action==='remove_member'){
   $s=$pdo->prepare('SELECT owner_id FROM bf_chat_groups WHERE id=?');$s->execute([$g]);if((int)$s->fetchColumn()!==$uid || $target===$uid)throw new DomainException('Chỉ trưởng nhóm mới được xóa thành viên.');
   $pdo->prepare('DELETE FROM bf_chat_members WHERE group_id=? AND user_id=?')->execute([$g,$target]);
   bf_social_go('social.php?tab=groups&group='.$g,'Đã xóa thành viên.');
  }elseif($action==='delete_group'){
   $s=$pdo->prepare('DELETE FROM bf_chat_groups WHERE id=? AND owner_id=?');$s->execute([$g,$uid]);
   if(!$s->rowCount())throw new DomainException('Chỉ trưởng nhóm được xóa nhóm.');
   bf_social_go('social.php?tab=groups','Đã xóa nhóm chat.');
  }elseif($action==='group_send'){
   if(!bf_social_group_member($pdo,$g,$uid))throw new DomainException('Bạn không thuộc nhóm chat này.');
   $text=trim((string)($_POST['body']??''));if($text===''||bf_social_len($text)>2000)throw new DomainException('Tin nhắn phải có từ 1 đến 2.000 ký tự.');
   $pdo->prepare('INSERT INTO bf_group_messages(group_id,sender_id,body) VALUES(?,?,?)')->execute([$g,$uid,$text]);
   redirect('social.php?tab=groups&group='.$g,303);
  }else{throw new DomainException('Thao tác không hợp lệ.');}
 }catch(DomainException $ex){$errors[]=$ex->getMessage();}catch(Throwable $ex){error_log('[social] '.$ex->getMessage());$errors[]='Không xử lý được thao tác. Hãy kiểm tra file SQL cập nhật và kết nối database.';}
}
$friendRows=[];$received=[];$sent=[];$groups=[];$blocks=[];$found=[];$chatRows=[];$groupRows=[];$members=[];$selected=null;$selectedGroup=null;
try{
 $s=$pdo->prepare('SELECT u.id,u.full_name,u.role,f.status,f.requested_by FROM bf_friendships f JOIN users u ON u.id=IF(f.user_low=?,f.user_high,f.user_low) WHERE (f.user_low=? OR f.user_high=?) ORDER BY f.updated_at DESC');$s->execute([$uid,$uid,$uid]);foreach($s->fetchAll() as $r){if($r['status']==='accepted')$friendRows[]=$r;elseif((int)$r['requested_by']===$uid)$sent[]=$r;else $received[]=$r;}
 $s=$pdo->prepare('SELECT u.id,u.full_name FROM bf_blocks b JOIN users u ON u.id=b.blocked_id WHERE b.blocker_id=?');$s->execute([$uid]);$blocks=$s->fetchAll();
 $s=$pdo->prepare('SELECT g.id,g.title,g.owner_id,COUNT(m2.user_id) AS total FROM bf_chat_groups g JOIN bf_chat_members m ON m.group_id=g.id AND m.user_id=? LEFT JOIN bf_chat_members m2 ON m2.group_id=g.id GROUP BY g.id,g.title,g.owner_id ORDER BY g.id DESC');$s->execute([$uid]);$groups=$s->fetchAll();
 if($query!==''){$s=$pdo->prepare('SELECT id,full_name,role FROM users WHERE id<>? AND (full_name LIKE ? OR email LIKE ?) AND NOT EXISTS (SELECT 1 FROM bf_blocks b WHERE (b.blocker_id=? AND b.blocked_id=users.id) OR (b.blocked_id=? AND b.blocker_id=users.id)) ORDER BY full_name LIMIT 30');$s->execute([$uid,'%'.$query.'%','%'.$query.'%',$uid,$uid]);$found=$s->fetchAll();}
 if($peer && bf_social_friend($pdo,$uid,$peer)){
  $s=$pdo->prepare('SELECT id,full_name,role FROM users WHERE id=?');$s->execute([$peer]);$selected=$s->fetch()?:null;
  $s=$pdo->prepare('SELECT m.*,u.full_name FROM bf_direct_messages m JOIN users u ON u.id=m.sender_id WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?) ORDER BY m.id DESC LIMIT 100');$s->execute([$uid,$peer,$peer,$uid]);$chatRows=array_reverse($s->fetchAll());
 }
 if($group && bf_social_group_member($pdo,$group,$uid)){
  $s=$pdo->prepare('SELECT * FROM bf_chat_groups WHERE id=?');$s->execute([$group]);$selectedGroup=$s->fetch()?:null;
  $s=$pdo->prepare('SELECT u.id,u.full_name FROM bf_chat_members m JOIN users u ON u.id=m.user_id WHERE m.group_id=? ORDER BY u.full_name');$s->execute([$group]);$members=$s->fetchAll();
  $s=$pdo->prepare('SELECT m.*,u.full_name FROM bf_group_messages m JOIN users u ON u.id=m.sender_id WHERE m.group_id=? ORDER BY m.id DESC LIMIT 100');$s->execute([$group]);$groupRows=array_reverse($s->fetchAll());
 }
}catch(Throwable $ex){error_log('[social read] '.$ex->getMessage());$errors[]='Chức năng cộng đồng chưa được cài database. Vui lòng nhập database/20261029_social_meetings.sql trong phpMyAdmin.';}
$flash=get_flash();bf_portal_begin($user,'social','Bạn bè & tin nhắn');
?>
<header class="bf-header"><div><h1>Cộng đồng BrightFuture</h1><p>Kết bạn, trao đổi kiến thức lập trình và học nhóm cùng nhau.</p></div><a class="bf-btn" href="<?=e(app_url('rooms.php'))?>">◉ Mở phòng gọi nhóm</a></header>
<?php foreach($errors as $er):?><div class="bf-note bf-error" role="alert"><?=e($er)?></div><?php endforeach?>
<?php if($flash):?><div class="bf-note <?=($flash['type']??'')==='success'?'bf-success':'bf-error'?>"><?=e($flash['message']??'')?></div><?php endif?>
<div class="bf-tabs"><a class="<?=$tab==='friends'?'active':''?>" href="?tab=friends">Bạn bè (<?=count($friendRows)?>)<?=count($received)?' · '.count($received).' lời mời':''?></a><a class="<?=$tab==='chat'?'active':''?>" href="?tab=chat">Nhắn tin</a><a class="<?=$tab==='groups'?'active':''?>" href="?tab=groups">Nhóm chat (<?=count($groups)?>)</a><a class="<?=$tab==='search'?'active':''?>" href="?tab=search">Tìm người dùng</a></div>
<?php if($tab==='friends'):?>
<div class="bf-grid"><section class="bf-panel"><h2>Lời mời nhận được</h2><?php if(!$received):?><p class="bf-subtle">Chưa có lời mời.</p><?php endif?><?php foreach($received as $p):?><div class="bf-row"><div><strong><?=e($p['full_name'])?></strong><small><?=e(bf_social_role($p['role']))?></small></div><div class="bf-actions"><?php foreach(['accept'=>'Chấp nhận','reject'=>'Từ chối'] as $a=>$label):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="<?=$a?>"><input type="hidden" name="target" value="<?=(int)$p['id']?>"><button class="<?=$a==='accept'?'bf-btn':'bf-muted'?>" type="submit"><?=$label?></button></form><?php endforeach?></div></div><?php endforeach?><h2 style="margin-top:25px">Lời mời đã gửi</h2><?php if(!$sent):?><p class="bf-subtle">Không có lời mời đang chờ.</p><?php endif?><?php foreach($sent as $p):?><div class="bf-row"><span><?=e($p['full_name'])?></span><span class="bf-chip">Đang chờ</span></div><?php endforeach?></section>
<section class="bf-panel"><h2>Danh sách bạn bè</h2><?php if(!$friendRows):?><p class="bf-empty">Chưa có bạn bè. Hãy tìm và gửi lời mời kết bạn.</p><a class="bf-btn" href="?tab=search">Tìm bạn bè</a><?php endif?><?php foreach($friendRows as $p):?><div class="bf-row"><div><strong><?=e($p['full_name'])?></strong><small><?=e(bf_social_role($p['role']))?></small></div><div class="bf-actions"><a class="bf-btn" href="?tab=chat&peer=<?=(int)$p['id']?>">Nhắn tin</a><form method="post" onsubmit="return confirm('Hủy kết bạn?')"><?=csrf_field()?><input type="hidden" name="action" value="unfriend"><input type="hidden" name="target" value="<?=(int)$p['id']?>"><button class="bf-muted" type="submit">Hủy kết bạn</button></form></div></div><?php endforeach?><h2 style="margin-top:25px">Đã chặn</h2><?php foreach($blocks as $p):?><form class="bf-row" method="post"><?=csrf_field()?><span><?=e($p['full_name'])?></span><input type="hidden" name="action" value="unblock"><input type="hidden" name="target" value="<?=(int)$p['id']?>"><button type="submit" class="bf-muted">Bỏ chặn</button></form><?php endforeach?><?php if(!$blocks):?><p class="bf-subtle">Không có tài khoản nào bị chặn.</p><?php endif?></section></div>
<?php elseif($tab==='search'):?>
<section class="bf-panel"><h2>Tìm người dùng</h2><form class="bf-form" method="get"><input type="hidden" name="tab" value="search"><input class="bf-input" type="search" name="q" value="<?=e($query)?>" placeholder="Nhập tên hoặc email người dùng..." maxlength="100" required><button class="bf-btn">Tìm kiếm</button></form><?php if($query!==''):?> <p class="bf-subtle" style="margin-top:12px">Tìm thấy <?=count($found)?> kết quả (hiển thị tối đa 30).</p><?php endif?><?php foreach($found as $p):?><?php $isFriend=false;$requested=false;$pending=false;foreach($friendRows as $f)if($f['id']===$p['id'])$isFriend=true;foreach($received as $f)if($f['id']===$p['id'])$requested=true;foreach($sent as $f)if($f['id']===$p['id'])$pending=true;?><div class="bf-row"><div><strong><?=e($p['full_name'])?></strong><small><?=e(bf_social_role($p['role']))?></small></div><?php if($isFriend):?><a class="bf-btn" href="?tab=chat&peer=<?=(int)$p['id']?>">Nhắn tin</a><?php elseif($requested):?><span class="bf-chip">Đã gửi lời mời cho bạn</span><?php elseif($pending):?><span class="bf-chip">Đang chờ</span><?php else:?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="request"><input type="hidden" name="target" value="<?=(int)$p['id']?>"><button class="bf-btn">+ Kết bạn</button></form><?php endif?></div><?php endforeach?></section>
<?php elseif($tab==='chat'):?>
<div class="bf-grid"><section class="bf-panel"><h2>Chọn bạn để nhắn tin</h2><?php foreach($friendRows as $p):?><a class="bf-row" href="?tab=chat&peer=<?=(int)$p['id']?>"><span><?=e($p['full_name'])?></span><span>→</span></a><?php endforeach?><?php if(!$friendRows):?><p class="bf-subtle">Hãy kết bạn trước khi nhắn tin.</p><?php endif?></section><section class="bf-panel"><?php if(!$selected):?><div class="bf-empty">Chọn một người bạn trong danh sách bên trái.</div><?php else:?><div class="bf-row"><h2><?=e($selected['full_name'])?></h2><div class="bf-actions"><form method="post" onsubmit="return confirm('Chặn người dùng này?')"><?=csrf_field()?><input type="hidden" name="action" value="block"><input type="hidden" name="target" value="<?=$peer?>"><button class="bf-muted bf-danger">Chặn</button></form><button type="button" class="bf-muted" onclick="document.getElementById('reportForm').hidden=!document.getElementById('reportForm').hidden">Báo cáo</button></div></div><form id="reportForm" hidden method="post" class="bf-note"><?=csrf_field()?><input type="hidden" name="action" value="report"><input type="hidden" name="target" value="<?=$peer?>"><label for="reason">Lý do báo cáo</label><textarea required id="reason" name="reason" class="bf-input" minlength="8" maxlength="1000"></textarea><button class="bf-btn">Gửi báo cáo</button></form><div class="bf-chat" id="chatWindow" data-kind="direct" data-id="<?=$peer?>"><?php foreach($chatRows as $m):?><div class="bf-bubble <?=(int)$m['sender_id']===$uid?'mine':''?>"><small><?=e($m['full_name'])?> · <?=e($m['created_at'])?></small><?=e($m['body'])?></div><?php endforeach?></div><form class="bf-message-form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="direct"><input type="hidden" name="target" value="<?=$peer?>"><input class="bf-input" required maxlength="2000" name="body" placeholder="Nhập tin nhắn..."><button class="bf-btn">Gửi →</button></form><?php endif?></section></div>
<?php elseif($tab==='groups'):?>
<div class="bf-grid"><section class="bf-panel"><h2>Nhóm của tôi</h2><?php foreach($groups as $gr):?><a class="bf-row" href="?tab=groups&group=<?=(int)$gr['id']?>"><div><strong><?=e($gr['title'])?></strong><small><?=$gr['total']?> thành viên</small></div><span>→</span></a><?php endforeach?><h2 style="margin-top:22px">+ Tạo nhóm chat</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create_group"><label class="bf-field">Tên nhóm<input class="bf-input" name="title" minlength="3" maxlength="120" required placeholder="Ví dụ: Nhóm học Python"></label><button class="bf-btn">Tạo nhóm</button></form></section><section class="bf-panel"><?php if(!$selectedGroup):?><div class="bf-empty">Chọn nhóm để xem nội dung hoặc tạo nhóm mới.</div><?php else:?><div class="bf-row"><div><h2><?=e($selectedGroup['title'])?></h2><small><?=count($members)?> thành viên</small></div><?php if((int)$selectedGroup['owner_id']===$uid):?><form method="post" onsubmit="return confirm('Xóa toàn bộ nhóm và tin nhắn?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group" value="<?=$group?>"><button class="bf-muted bf-danger">Xóa nhóm</button></form><?php else:?><form method="post" onsubmit="return confirm('Rời khỏi nhóm?')"><?=csrf_field()?><input type="hidden" name="action" value="leave"><input type="hidden" name="group" value="<?=$group?>"><button class="bf-muted">Rời nhóm</button></form><?php endif?></div>
<div class="bf-chat" id="chatWindow" data-kind="group" data-id="<?=$group?>"><?php foreach($groupRows as $m):?><div class="bf-bubble <?=(int)$m['sender_id']===$uid?'mine':''?>"><small><?=e($m['full_name'])?> · <?=e($m['created_at'])?></small><?=e($m['body'])?></div><?php endforeach?></div><form class="bf-message-form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="group_send"><input type="hidden" name="group" value="<?=$group?>"><input name="body" required class="bf-input" maxlength="2000" placeholder="Nhập tin nhắn nhóm..."><button class="bf-btn">Gửi →</button></form><h2 style="margin-top:25px">Thành viên</h2><?php foreach($members as $m):?><div class="bf-row"><span><?=e($m['full_name'])?></span><?php if((int)$m['id']===(int)$selectedGroup['owner_id']):?><span class="bf-chip">Trưởng nhóm</span><?php elseif((int)$selectedGroup['owner_id']===$uid):?><form method="post" onsubmit="return confirm('Xóa thành viên khỏi nhóm?')"><?=csrf_field()?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="group" value="<?=$group?>"><input type="hidden" name="target" value="<?=(int)$m['id']?>"><button class="bf-muted bf-danger">Xóa</button></form><?php endif?></div><?php endforeach?><form method="post" class="bf-form" style="margin-top:17px"><?=csrf_field()?><input type="hidden" name="action" value="invite"><input type="hidden" name="group" value="<?=$group?>"><select class="bf-input" name="target" required><option value="">Mời một người bạn...</option><?php foreach($friendRows as $f):?><?php $memberIds=array_column($members,'id');if(in_array($f['id'],$memberIds))continue;?><option value="<?=(int)$f['id']?>"><?=e($f['full_name'])?></option><?php endforeach?></select><button class="bf-btn">+ Thêm thành viên</button></form><?php endif?></section></div>
<?php endif?>
<script>document.addEventListener('DOMContentLoaded',()=>{const box=document.getElementById('chatWindow');if(!box)return;box.scrollTop=box.scrollHeight;let latest=0;const types={direct:'peer',group:'group'};const params=()=>new URLSearchParams({kind:box.dataset.kind,id:box.dataset.id});const escDate=s=>String(s||'').slice(0,19);let working=false;async function refresh(){if(working||document.hidden)return;working=true;try{const r=await fetch('<?=e(app_url('api/social-feed.php'))?>?'+params(),{credentials:'same-origin',headers:{'Accept':'application/json'}});if(!r.ok)return;const json=await r.json();if(!json.ok)return;const last=box.scrollHeight-box.scrollTop-box.clientHeight<75;box.replaceChildren();for(const m of json.messages){const div=document.createElement('div');div.className='bf-bubble'+(m.mine?' mine':'');const meta=document.createElement('small');meta.textContent=m.sender+' · '+escDate(m.created_at);div.append(meta,document.createTextNode(m.body));box.append(div)}if(last)box.scrollTop=box.scrollHeight;}catch(e){}finally{working=false}}setInterval(refresh,7000);});</script>
<?php bf_portal_end(); ?>
