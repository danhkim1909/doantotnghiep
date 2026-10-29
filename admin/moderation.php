<?php
require_once __DIR__.'/../includes/portal_ui.php';
$admin=require_role(ROLE_ADMIN);$pdo=db();$errors=[];
if(is_post()){
 require_csrf();$id=positive_int($_POST['id']??0);$action=get_post('action');$resolution=clean_text(get_post('resolution'),1000);
 try{
  if(!$id||!in_array($action,['resolve','dismiss'],true))throw new DomainException('Thao tác không hợp lệ.');
  $s=$pdo->prepare('UPDATE bf_user_reports SET status=?,resolution=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status="open"');$s->execute([$action==='resolve'?'resolved':'dismissed',$resolution?:null,(int)$admin['id'],$id]);
  if(!$s->rowCount())throw new DomainException('Báo cáo không còn chờ xử lý.');
  set_flash('success','Đã cập nhật trạng thái báo cáo.');redirect('admin/moderation.php',303);
 }catch(DomainException $e){$errors[]=$e->getMessage();}catch(Throwable $e){error_log('[moderation] '.$e->getMessage());$errors[]='Không cập nhật được báo cáo.';}
}
$rows=[];
try{$s=$pdo->query('SELECT r.*,u.full_name reporter_name,v.full_name reported_name,a.full_name reviewer_name FROM bf_user_reports r JOIN users u ON u.id=r.reporter_id JOIN users v ON v.id=r.reported_id LEFT JOIN users a ON a.id=r.reviewed_by ORDER BY (r.status="open") DESC,r.id DESC LIMIT 200');$rows=$s->fetchAll();}catch(Throwable $e){$errors[]='Chưa có bảng báo cáo. Hãy chạy database/20261029_social_meetings.sql.';}
$flash=get_flash();bf_portal_begin($admin,'reports','Xử lý báo cáo');
?>
<header class="bf-header"><div><h1>Kiểm duyệt cộng đồng</h1><p>Tiếp nhận, xem xét và ghi nhận kết quả báo cáo người dùng.</p></div><a class="bf-muted" href="<?=e(app_url('admin/users.php'))?>">Quản lý tài khoản →</a></header>
<?php foreach($errors as $er):?><div class="bf-note bf-error"><?=e($er)?></div><?php endforeach?><?php if($flash):?><div class="bf-note bf-success"><?=e($flash['message']??'')?></div><?php endif?>
<section class="bf-panel"><h2>Danh sách báo cáo (tối đa 200 gần nhất)</h2><?php foreach($rows as $r):?><article class="bf-row" style="align-items:flex-start;flex-wrap:wrap"><div style="flex:1;min-width:250px"><strong>#<?=(int)$r['id']?> · <?=e($r['reporter_name'])?> báo cáo <?=e($r['reported_name'])?></strong><p style="white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0"><?=e($r['reason'])?></p><small><?=e($r['created_at'])?> · Trạng thái: <b><?=e($r['status'])?></b></small><?php if($r['resolution']):?><small>Ghi chú xử lý: <?=e($r['resolution'])?> · <?=e($r['reviewer_name']??'')?></small><?php endif?></div><?php if($r['status']==='open'):?><form method="post" style="min-width:280px;max-width:350px;flex:1"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><label class="bf-field">Ghi chú xử lý<input class="bf-input" name="resolution" maxlength="1000" placeholder="Kết quả xem xét..."></label><div class="bf-actions"><button class="bf-btn" name="action" value="resolve">Đã xử lý</button><button class="bf-muted" name="action" value="dismiss">Bỏ qua</button></div></form><?php endif?></article><?php endforeach?><?php if(!$rows):?><div class="bf-empty">Chưa có báo cáo người dùng.</div><?php endif?></section>
<?php bf_portal_end(); ?>
