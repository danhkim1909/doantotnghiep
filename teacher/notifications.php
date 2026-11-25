<?php
/** Teacher inbox for student discussions and lesson-related problem reports. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/teacher_notifications.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/portal_ui.php';
$user = require_role(ROLE_TEACHER);
$teacherId = (int) $user['id'];
$pdo = db();
header('Cache-Control: private, no-store, max-age=0');
$error = '';
$missingTable = false;

if (is_post()) {
    require_csrf();
    $action = get_post('action');
    try {
        if ($action === 'open') {
            $id = positive_int($_POST['notification_id'] ?? null);
            $stmt = $pdo->prepare('SELECT n.id, n.lesson_id, n.discussion_id
                FROM bf_teacher_notifications n
                JOIN lessons l ON l.id = n.lesson_id
                JOIN course_chapters ch ON ch.id = l.chapter_id
                JOIN courses c ON c.id = ch.course_id AND c.teacher_id = n.teacher_id
                WHERE n.id = ? AND n.teacher_id = ? LIMIT 1');
            $stmt->execute([$id, $teacherId]);
            $row = $stmt->fetch();
            if (!$row) { http_response_code(404); exit('Thông báo không tồn tại hoặc bạn không được quyền truy cập.'); }
            $mark = $pdo->prepare('UPDATE bf_teacher_notifications SET is_read = 1 WHERE id = ? AND teacher_id = ?');
            $mark->execute([$id, $teacherId]);
            redirect('discussions.php?lesson=' . (int)$row['lesson_id'] . '&message=' . (int)$row['discussion_id'] . '#discussion-' . (int)$row['discussion_id'], 303);
        }
        if ($action === 'read_all') {
            $stmt = $pdo->prepare('UPDATE bf_teacher_notifications n
                JOIN lessons l ON l.id = n.lesson_id
                JOIN course_chapters ch ON ch.id = l.chapter_id
                JOIN courses c ON c.id = ch.course_id AND c.teacher_id = n.teacher_id
                SET n.is_read = 1 WHERE n.teacher_id = ? AND n.is_read = 0');
            $stmt->execute([$teacherId]);
            set_flash('success', 'Đã đánh dấu các thông báo là đã đọc.');
            redirect('teacher/notifications.php', 303);
        }
        $error = 'Thao tác không hợp lệ.';
    } catch (Throwable $ex) {
        error_log('[BrightFuture teacher notifications] ' . $ex->getMessage());
        $error = 'Không xử lý được thông báo. Hãy kiểm tra bảng dữ liệu thông báo.';
    }
}

$rows = [];
$unread = 0;
try {
    $stmt = $pdo->prepare('SELECT n.id,n.is_read,n.created_at,n.kind,n.discussion_id,
        l.title AS lesson_title,c.title AS course_title,u.full_name AS student_name,d.body
        FROM bf_teacher_notifications n
        JOIN lessons l ON l.id = n.lesson_id
        JOIN course_chapters ch ON ch.id = l.chapter_id
        JOIN courses c ON c.id = ch.course_id AND c.teacher_id = n.teacher_id
        JOIN users u ON u.id = n.sender_id
        JOIN bf_lesson_discussions d ON d.id = n.discussion_id AND d.lesson_id = n.lesson_id
        WHERE n.teacher_id = ? ORDER BY n.is_read ASC, n.id DESC LIMIT 100');
    $stmt->execute([$teacherId]);
    $rows = $stmt->fetchAll();
    $unread = bf_teacher_unread_count($pdo, $teacherId);
} catch (Throwable $ex) {
    error_log('[BrightFuture teacher notifications] ' . $ex->getMessage());
    $missingTable = true;
    $error = 'Chưa cài đặt thông báo bài học. Hãy chạy php scripts/migrate.php migrate trên đúng database.';
}
$flash = get_flash();
$pageTitle = 'Thông báo bài học';
$activeTab = 'notifications';
$extraCss = ['css/bf-notifications.css'];
$portalRoleTitle = 'Không gian giảng dạy';
require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, $activeTab);
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
<main class="content container noti-content">
<div class="bf-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 6px;">Thông báo bài học</h1>
        <p class="bf-subtle" style="color: #64748b; font-size: 14px; margin: 0;">Câu hỏi, thảo luận và báo lỗi của học viên trong khóa học bạn phụ trách.</p>
    </div>
    <span class="pill <?= $unread > 0 ? 'red' : '' ?>"><?= $unread ?> chưa đọc</span>
</div>
<?php if ($error): ?><div class="alert error" role="alert" style="margin-bottom: 20px;"><?=e($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert good" role="status" style="margin-bottom: 20px;"><?=e((string)($flash['message']??''))?></div><?php endif; ?>
<div class="toolbar" style="margin-bottom: 18px;"><span class="subtle" style="color: #64748b; font-size: 13px;">Hiển thị tối đa 100 thông báo mới nhất (chưa đọc được ưu tiên)</span>
<form method="post" style="margin: 0;"><?=csrf_field()?><input type="hidden" name="action" value="read_all"><button type="submit" <?= $unread===0||$missingTable?'disabled':'' ?>>Đánh dấu đã đọc tất cả</button></form></div>
<?php if (!$rows && !$missingTable): ?><div class="empty" style="text-align: center; color: #64748b; padding: 40px 0;">Chưa có thông báo. Khi học viên gửi thảo luận hoặc báo lỗi bài học, thông báo sẽ xuất hiện ở đây.</div><?php endif; ?>
<?php foreach($rows as $n): ?><article class="item <?= (int)$n['is_read']===0?'unread':'' ?>"><div>
<div class="meta"><span class="pill <?= $n['kind']==='issue'?'red':'' ?>"><?= $n['kind']==='issue'?'Báo lỗi bài học':'Thảo luận mới' ?></span><?php if ((int)$n['is_read']===0): ?><b>● Chưa đọc</b><?php endif; ?><small><?=e((string)$n['created_at'])?></small></div>
<h2><?=e($n['student_name'])?> · <?=e($n['lesson_title'])?></h2>
<p class="subtle">Khóa học: <?=e($n['course_title'])?></p>
<p class="excerpt"><?=e(truncate_text((string)$n['body'], 240))?></p></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="open"><input type="hidden" name="notification_id" value="<?=(int)$n['id']?>"><button class="open" type="submit"><?=bf_icon('arrow')?> Xem thảo luận</button></form></article><?php endforeach; ?>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
