<?php
require_once __DIR__.'/../includes/portal_ui.php';
$admin=require_role(ROLE_ADMIN);$pdo=db();$errors=[];
if(is_post()){
 require_csrf();$id=positive_int($_POST['id']??0);$action=get_post('action');$resolution=clean_text(get_post('resolution'),1000);
 try{
  if(!$id||!in_array($action,['resolve','dismiss'],true))throw new DomainException('Thao tác không hợp lệ.');
  $s=$pdo->prepare('UPDATE bf_user_reports SET status=?,resolution=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status="open"');$s->execute([$action==='resolve'?'resolved':'dismissed',$resolution?:null,(int)$admin['id'],$id]);
  bf_audit_log(
    $action === 'resolve' ? 'report.resolve' : 'report.dismiss',
    'user_report',
    $id,
    ['resolution' => $resolution ?: null]
  );
  if(!$s->rowCount())throw new DomainException('Báo cáo không còn chờ xử lý.');
  set_flash('success','Đã cập nhật trạng thái báo cáo.');redirect('admin/moderation.php',303);
 }catch(DomainException $e){$errors[]=$e->getMessage();}catch(Throwable $e){error_log('[moderation] '.$e->getMessage());$errors[]='Không cập nhật được báo cáo.';}
}
$rows=[];
try{$s=$pdo->query('SELECT r.*,u.full_name reporter_name,v.full_name reported_name,a.full_name reviewer_name FROM bf_user_reports r JOIN users u ON u.id=r.reporter_id JOIN users v ON v.id=r.reported_id LEFT JOIN users a ON a.id=r.reviewed_by ORDER BY (r.status="open") DESC,r.id DESC LIMIT 200');$rows=$s->fetchAll();}catch(Throwable $e){$errors[]='Chưa có bảng báo cáo. Hãy chạy database/20261029_social_meetings.sql.';}
$flash = get_flash();
$pageTitle = 'Kiểm duyệt nội dung - ' . APP_NAME;
$portalRole = ROLE_ADMIN;
$topbarTitle = 'Không gian quản trị';
$extraCss = ['css/bf-admin-moderation.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($admin, 'moderation') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>

<main class="content mod-content">
  <div class="mod-crumb"><a href="<?= e(app_url('admin/dashboard.php')) ?>">Tổng quan</a> / <strong>Kiểm duyệt nội dung</strong></div>
  <div class="mod-heading">
    <div>
      <h1>Kiểm duyệt cộng đồng</h1>
      <p>Tiếp nhận, xem xét và xử lý các báo cáo vi phạm tiêu chuẩn cộng đồng.</p>
    </div>
    <a class="btn subtle" href="<?= e(app_url('admin/users.php')) ?>">Quản lý tài khoản →</a>
  </div>

  <?php foreach ($errors as $er): ?>
    <div class="alert error" role="alert"><?= e($er) ?></div>
  <?php endforeach; ?>
  <?php if ($flash): ?>
    <div class="alert success" role="status"><?= e((string)($flash['message'] ?? '')) ?></div>
  <?php endif; ?>

  <section class="mod-card">
    <h2>Danh sách báo cáo (tối đa 200 gần nhất)</h2>
    <?php if (!$rows): ?>
      <p class="mod-meta" style="padding: 24px 0; justify-content: center;">Chưa có báo cáo người dùng nào cần xử lý.</p>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <article class="mod-item">
        <div style="flex: 1; min-width: 250px;">
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <strong>#<?= (int)$r['id'] ?> · <?= e($r['reporter_name']) ?> báo cáo <?= e($r['reported_name']) ?></strong>
            <span class="mod-status-chip <?= e($r['status']) ?>"><?= e($r['status']) ?></span>
          </div>
          <div class="mod-reason"><?= e($r['reason']) ?></div>
          <div class="mod-meta">
            <time><?= e($r['created_at']) ?></time>
            <?php if ($r['resolution']): ?>
              <span>· Ghi chú: <strong><?= e($r['resolution']) ?></strong> (bởi <?= e($r['reviewer_name'] ?? 'BQT') ?>)</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($r['status'] === 'open'): ?>
          <form method="post" class="mod-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Ghi chú xử lý</label>
            <input class="input" style="width: 100%; height: 38px; border: 1px solid #dce2ed; border-radius: 8px; padding: 0 10px; margin-bottom: 10px; font-size: 13px; box-sizing: border-box;" name="resolution" maxlength="1000" placeholder="Kết quả xem xét...">
            <div style="display: flex; gap: 8px;">
              <button class="btn sm" type="submit" name="action" value="resolve">Đã xử lý</button>
              <button class="btn subtle sm" type="submit" name="action" value="dismiss">Bỏ qua</button>
            </div>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
