<?php
/** Student notifications: replies from teachers to the student's lesson questions. */
require_once __DIR__ . '/../includes/student_notifications.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/portal_ui.php';
$user = require_role(ROLE_STUDENT);
$studentId = (int)$user['id'];
$pdo = db();
header('Cache-Control: private, no-store, max-age=0');
$error = '';
$missingTable = false;

if (is_post()) {
    require_csrf();
    $action = get_post('action');
    try {
        if ($action === 'open') {
            $notificationId = positive_int($_POST['notification_id'] ?? null);
            $stmt = $pdo->prepare('SELECT n.id,n.lesson_id,n.discussion_id,n.reply_id
                FROM bf_student_notifications n
                JOIN bf_lesson_discussions d ON d.id=n.discussion_id AND d.author_id=n.student_id AND d.lesson_id=n.lesson_id
                JOIN bf_lesson_discussion_replies r ON r.id=n.reply_id AND r.discussion_id=n.discussion_id
                WHERE n.id=? AND n.student_id=? LIMIT 1');
            $stmt->execute([$notificationId, $studentId]);
            $row = $stmt->fetch();
            if (!$row) { http_response_code(404); exit('Thông báo không tồn tại hoặc bạn không có quyền xem.'); }
            $pdo->prepare('UPDATE bf_student_notifications SET is_read=1 WHERE id=? AND student_id=?')
                ->execute([$notificationId, $studentId]);
            redirect('discussions.php?lesson='.(int)$row['lesson_id'].'&message='.(int)$row['discussion_id'].'#reply-'.(int)$row['reply_id'], 303);
        }
        if ($action === 'read_all') {
            $pdo->prepare('UPDATE bf_student_notifications SET is_read=1 WHERE student_id=? AND is_read=0')->execute([$studentId]);
            set_flash('success', 'Đã đánh dấu tất cả thông báo là đã đọc.');
            redirect('student/notifications.php', 303);
        }
        $error = 'Thao tác không hợp lệ.';
    } catch (Throwable $ex) {
        error_log('[BrightFuture student notification action] ' . $ex->getMessage());
        $error = 'Không thực hiện được thao tác. Hãy kiểm tra trạng thái migration thông báo.';
    }
}

$rows = [];
$unread = 0;
try {
    $stmt = $pdo->prepare('SELECT n.id,n.is_read,n.created_at,n.reply_id,n.discussion_id,
        l.title AS lesson_title,c.title AS course_title,u.full_name AS teacher_name,r.body AS reply_body,d.body AS question_body
        FROM bf_student_notifications n
        JOIN bf_lesson_discussions d ON d.id=n.discussion_id AND d.author_id=n.student_id AND d.lesson_id=n.lesson_id
        JOIN bf_lesson_discussion_replies r ON r.id=n.reply_id AND r.discussion_id=n.discussion_id AND r.teacher_id=n.teacher_id
        JOIN lessons l ON l.id=n.lesson_id
        JOIN course_chapters ch ON ch.id=l.chapter_id
        JOIN courses c ON c.id=ch.course_id
        JOIN users u ON u.id=n.teacher_id
        WHERE n.student_id=? ORDER BY n.is_read ASC,n.id DESC LIMIT 100');
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll();
    $unread = bf_student_unread_count($pdo, $studentId);
} catch (Throwable $ex) {
    error_log('[BrightFuture student notification list] ' . $ex->getMessage());
    $missingTable = true;
    $error = 'Chưa cài đặt thông báo phản hồi. Hãy chạy php scripts/migrate.php migrate trên đúng database.';
}
$flash = get_flash();
$pageTitle = 'Thông báo phản hồi';
$activeTab = 'notifications';
$extraCss = ['css/bf-notifications.css'];
$portalRoleTitle = 'Không gian học tập';
require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, $activeTab);
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
<main class="content container">
<header class="bf-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 6px;">Phản hồi từ giảng viên</h1>
        <p style="color: #64748b; font-size: 14px; margin: 0;">Các câu hỏi bạn đã gửi và lời giải đáp trực tiếp từ giảng viên.</p>
    </div>
    <span class="bf-notice-pill <?= $unread > 0 ? 'danger' : '' ?>"><?= $unread ?> chưa đọc</span>
</header>
<?php if ($error): ?><div class="bf-note bf-error" role="alert" style="margin-bottom: 20px;"><?=e($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="bf-note" role="status" style="margin-bottom: 20px;"><?=e((string)($flash['message']??''))?></div><?php endif; ?>
<section class="bf-panel" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px;">
  <div class="bf-actions" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <h2 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0;">Danh sách thông báo</h2>
    <form method="post" style="margin: 0;"><?=csrf_field()?><input type="hidden" name="action" value="read_all"><button type="submit" class="bf-btn" style="height: 38px; padding: 0 16px; border-radius: 10px; background: #f1f5f9; color: #334155; font-size: 13px; font-weight: 600; border: 1px solid #cbd5e1; cursor: pointer;" <?=$unread===0||$missingTable?'disabled':''?>>Đánh dấu đã đọc tất cả</button></form>
  </div>
  <?php if (!$rows && !$missingTable): ?><p class="bf-subtle" style="color: #64748b; font-size: 14px; text-align: center; padding: 40px 0;">Chưa có phản hồi nào. Khi giảng viên trả lời câu hỏi của bạn, thông báo sẽ xuất hiện ở đây.</p><?php endif; ?>
  <?php foreach($rows as $n): ?>
    <article class="bf-notice <?=(int)$n['is_read']===0?'unread':''?>">
      <div><div class="bf-notice-meta"><span class="bf-chip"><?=(int)$n['is_read']===0?'● Chưa đọc':'Đã đọc'?></span><time><?=e($n['created_at'])?></time></div>
        <h2>Giảng viên <?=e($n['teacher_name'])?> đã trả lời · <?=e($n['lesson_title'])?></h2>
        <p class="bf-subtle">Khóa học: <?=e($n['course_title'])?></p>
        <p class="bf-notice-preview"><strong>Câu hỏi của bạn:</strong> <?=e(truncate_text((string)$n['question_body'],180))?></p>
        <p class="bf-notice-preview"><strong>Phản hồi:</strong> <?=e(truncate_text((string)$n['reply_body'],260))?></p>
      </div>
      <form method="post" class="bf-notice-btn"><?=csrf_field()?><input type="hidden" name="action" value="open"><input type="hidden" name="notification_id" value="<?=(int)$n['id']?>"><button class="bf-btn" type="submit"><?=bf_icon('arrow')?> Xem câu trả lời</button></form>
    </article>
  <?php endforeach; ?>
</section>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
