<?php
/** Teacher inbox for student discussions and lesson-related problem reports. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/teacher_notifications.php';
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
    $error = 'Chưa có dữ liệu thông báo. Hãy nhập file database/migrations/20261107_teacher_lesson_notifications.sql trong phpMyAdmin.';
}
$flash = get_flash();
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Thông báo bài học | BrightFuture</title>
<style>
:root{--primary:#4f46e5;--border:#e2e8f0;--fg:#19253b;--muted:#64748b}*{box-sizing:border-box}body{margin:0;background:#f6f8fc;color:var(--fg);font:14px/1.6 'Segoe UI',Arial,sans-serif}a{color:var(--primary);text-decoration:none}button{font:inherit;cursor:pointer}.wrap{max-width:1010px;margin:32px auto;padding:0 18px 55px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;margin:0 0 23px}.top h1{font-size:27px;margin:4px 0}.subtle{color:var(--muted);margin:0}.pill{display:inline-block;background:#eef2ff;color:#3730a3;padding:3px 10px;border-radius:99px;font-weight:700;font-size:12px}.pill.red{background:#fff1f2;color:#be123c}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}.toolbar button,.open{border:0;border-radius:9px;background:var(--primary);color:#fff;padding:10px 15px;font-weight:700}.toolbar button:disabled{opacity:.5;cursor:not-allowed}.item{padding:19px 22px;border:1px solid var(--border);border-radius:15px;background:white;margin:11px 0;display:flex;gap:20px;justify-content:space-between;align-items:center}.item.unread{border-color:#a5b4fc;background:#f9faff}.item h2{font-size:17px;line-height:1.4;margin:7px 0 5px}.item p{margin:5px 0;overflow-wrap:anywhere}.item small{color:var(--muted)}.meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.excerpt{max-width:720px;white-space:pre-wrap;color:#475569}.empty,.alert{background:white;border:1px solid var(--border);padding:25px;border-radius:14px}.alert{padding:13px 17px;margin:15px 0}.alert.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.alert.good{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.back{display:inline-block;margin-bottom:10px}@media(max-width:620px){.item{flex-direction:column;align-items:stretch}.item form{text-align:right}}
</style>
</head><body><main class="wrap">
<a class="back" href="<?=e(app_url('teacher/dashboard.php'))?>">← Tổng quan giảng dạy</a>
<div class="top"><div><h1>🔔 Thông báo bài học</h1><p class="subtle">Câu hỏi, thảo luận và báo lỗi của học viên trong khóa học bạn phụ trách.</p></div><span class="pill red"><?= $unread ?> chưa đọc</span></div>
<?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert good" role="status"><?=e((string)($flash['message']??''))?></div><?php endif; ?>
<div class="toolbar"><span class="subtle">Hiển thị tối đa 100 thông báo mới nhất (chưa đọc được ưu tiên)</span>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="read_all"><button type="submit" <?= $unread===0||$missingTable?'disabled':'' ?>>Đánh dấu đã đọc tất cả</button></form></div>
<?php if (!$rows && !$missingTable): ?><div class="empty">Chưa có thông báo. Khi học viên gửi thảo luận hoặc báo lỗi bài học, thông báo sẽ xuất hiện ở đây.</div><?php endif; ?>
<?php foreach($rows as $n): ?><article class="item <?= (int)$n['is_read']===0?'unread':'' ?>"><div>
<div class="meta"><span class="pill <?= $n['kind']==='issue'?'red':'' ?>"><?= $n['kind']==='issue'?'Báo lỗi bài học':'Thảo luận mới' ?></span><?php if ((int)$n['is_read']===0): ?><b>● Chưa đọc</b><?php endif; ?><small><?=e((string)$n['created_at'])?></small></div>
<h2><?=e($n['student_name'])?> · <?=e($n['lesson_title'])?></h2>
<p class="subtle">Khóa học: <?=e($n['course_title'])?></p>
<p class="excerpt"><?=e(truncate_text((string)$n['body'], 240))?></p></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="open"><input type="hidden" name="notification_id" value="<?=(int)$n['id']?>"><button class="open" type="submit">Xem thảo luận →</button></form></article><?php endforeach; ?>
</main></body></html>
