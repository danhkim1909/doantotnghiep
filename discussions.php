<?php
/** Lesson discussions and inline teacher replies. PHP 8.0+ / PDO MySQL. */
require_once __DIR__ . '/includes/portal_ui.php';

$user = require_login();
$uid = (int) $user['id'];
$lid = positive_int($_GET['lesson'] ?? $_POST['lesson'] ?? 0);
$pdo = db();
$errors = [];
header('Cache-Control: private, no-store, max-age=0');
if (!$lid) { http_response_code(400); exit('Thiếu mã bài học.'); }

$stmt = $pdo->prepare('SELECT l.id,l.title,l.status,c.id AS course_id,c.teacher_id,c.title AS course_title,c.status AS course_status
    FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id
    JOIN courses c ON c.id=ch.course_id WHERE l.id=?');
$stmt->execute([$lid]);
$lesson = $stmt->fetch();
if (!$lesson) { http_response_code(404); exit('Không tìm thấy bài học.'); }

$isOwner = $user['role'] === 'teacher' && (int) $lesson['teacher_id'] === $uid;
$isAdmin = $user['role'] === 'admin';
$isStudent = false;
if ($user['role'] === 'student' && $lesson['status'] === 'published' && $lesson['course_status'] === 'published') {
    $stmt = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND status IN ("active","completed")');
    $stmt->execute([$uid, (int) $lesson['course_id']]);
    $isStudent = (bool) $stmt->fetchColumn();
}
if (!$isOwner && !$isAdmin && !$isStudent) {
    http_response_code(403); exit('Bạn không có quyền thảo luận trong bài học này.');
}

if (is_post()) {
    require_csrf();
    $action = get_post('action', 'discussion');
    $body = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
    if ($body === '' || bf_social_len($body) > 3900) {
        $errors[] = 'Nội dung cần có từ 1 đến 3.900 ký tự.';
    } elseif ($action === 'reply') {
        // Only the course-owning teacher may reply under a STUDENT's question.
        if (!$isOwner) {
            http_response_code(403); exit('Chỉ giảng viên phụ trách khóa học được trả lời câu hỏi.');
        }
        $discussionId = positive_int($_POST['discussion_id'] ?? null);
        $stmt = $pdo->prepare('SELECT d.id,d.author_id FROM bf_lesson_discussions d
            JOIN users u ON u.id=d.author_id AND u.role=?
            WHERE d.id=? AND d.lesson_id=? LIMIT 1');
        $stmt->execute(['student', $discussionId, $lid]);
        $question = $stmt->fetch();
        if (!$question) {
            $errors[] = 'Không tìm thấy câu hỏi của học viên trong bài học này.';
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO bf_lesson_discussion_replies(discussion_id,teacher_id,body) VALUES (?,?,?)');
                $insert->execute([$discussionId, $uid, $body]);
                $replyId = (int) $pdo->lastInsertId();
                $notify = $pdo->prepare('INSERT INTO bf_student_notifications
                    (student_id,teacher_id,lesson_id,discussion_id,reply_id) VALUES (?,?,?,?,?)');
                $notify->execute([(int)$question['author_id'], $uid, $lid, $discussionId, $replyId]);
                $pdo->commit();
                redirect('discussions.php?lesson=' . $lid . '&message=' . $discussionId . '#reply-' . $replyId, 303);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[BrightFuture discussion reply] ' . $ex->getMessage());
                $errors[] = 'Chưa gửi được phản hồi. Hãy chạy SQL bổ sung trả lời và thông báo học viên trước.';
            }
        }
    } elseif ($action === 'discussion') {
        $kind = (string) ($_POST['kind'] ?? 'question');
        if (!in_array($kind, ['question', 'issue'], true)) $kind = 'question';
        $storedBody = $kind === 'issue' ? "[Báo lỗi bài học]\n" . $body : $body;
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO bf_lesson_discussions(lesson_id,author_id,body) VALUES (?,?,?)');
            $insert->execute([$lid, $uid, $storedBody]);
            $messageId = (int) $pdo->lastInsertId();
            if ($isStudent) {
                $notify = $pdo->prepare('INSERT INTO bf_teacher_notifications
                    (teacher_id,lesson_id,discussion_id,sender_id,kind) VALUES (?,?,?,?,?)');
                $notify->execute([(int)$lesson['teacher_id'], $lid, $messageId, $uid, $kind]);
            }
            $pdo->commit();
            redirect('discussions.php?lesson=' . $lid . '&message=' . $messageId . '#discussion-' . $messageId, 303);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[BrightFuture discussion submit] ' . $ex->getMessage());
            $errors[] = 'Không gửi được thảo luận. Hãy kiểm tra bảng thảo luận và bảng thông báo giảng viên.';
        }
    } else {
        $errors[] = 'Thao tác không hợp lệ.';
    }
}

$rows = [];
$replyMap = [];
try {
    $stmt = $pdo->prepare('SELECT d.id,d.body,d.created_at,d.author_id,u.full_name,u.role
        FROM bf_lesson_discussions d JOIN users u ON u.id=d.author_id
        WHERE d.lesson_id=? ORDER BY d.id DESC LIMIT 100');
    $stmt->execute([$lid]);
    $rows = $stmt->fetchAll();

    // Notification deep links must still display questions older than the latest 100.
    $targetId = positive_int($_GET['message'] ?? null);
    if ($targetId) {
        $found = false;
        foreach ($rows as $row) {
            if ((int)$row['id'] === $targetId) { $found = true; break; }
        }
        if (!$found) {
            $target = $pdo->prepare('SELECT d.id,d.body,d.created_at,d.author_id,u.full_name,u.role
                FROM bf_lesson_discussions d JOIN users u ON u.id=d.author_id
                WHERE d.lesson_id=? AND d.id=? LIMIT 1');
            $target->execute([$lid, $targetId]);
            $extra = $target->fetch();
            if ($extra) $rows[] = $extra;
        }
    }
    if ($rows) {
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT r.id,r.discussion_id,r.body,r.created_at,r.teacher_id,u.full_name AS teacher_name
            FROM bf_lesson_discussion_replies r
            JOIN bf_lesson_discussions d ON d.id=r.discussion_id
            JOIN users u ON u.id=r.teacher_id
            WHERE d.lesson_id=? AND r.discussion_id IN (' . $placeholders . ')
            ORDER BY r.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$lid], $ids));
        foreach ($stmt->fetchAll() as $reply) {
            $replyMap[(int)$reply['discussion_id']][] = $reply;
        }
    }
} catch (Throwable $ex) {
    error_log('[BrightFuture discussion list] ' . $ex->getMessage());
    $errors[] = 'Không tải được toàn bộ thảo luận hoặc phản hồi. Hãy kiểm tra các bảng SQL bổ sung.';
}

bf_portal_begin($user, 'courses', 'Thảo luận bài học');
?>
<style>
.bf-discussion{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin:14px 0;scroll-margin-top:95px}
.bf-discussion:target,.bf-reply:target{outline:2px solid #818cf8;outline-offset:2px;background:#f6f7ff}
.bf-discussion-head{display:flex;flex-wrap:wrap;align-items:center;gap:9px;color:#64748b;font-size:13px}
.bf-discussion-head strong{color:#18243c;font-size:15px}.bf-discussion-body{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65;margin:12px 0}
.bf-replies{margin:13px 0 0 26px;padding-left:16px;border-left:3px solid #dbe4ff}
.bf-reply{padding:13px 15px;background:#f6f8fe;border-radius:11px;margin:9px 0;scroll-margin-top:110px}
.bf-reply b{color:#3730a3}.bf-reply p{white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0 0}
.bf-reply-form{margin:14px 0 0 26px;padding:15px;background:#f8faff;border:1px dashed #c7d2fe;border-radius:11px}
.bf-reply-form label{font-weight:700;font-size:13px;display:block;margin-bottom:7px}
.bf-reply-form textarea{width:100%;min-height:88px;resize:vertical}
.bf-reply-form button{margin-top:10px}.bf-discussion-count{color:#64748b;font-size:13px;font-weight:400}
@media(max-width:600px){.bf-discussion{padding:14px}.bf-replies,.bf-reply-form{margin-left:5px}}
</style>
<header class="bf-header">
  <div><h1>Thảo luận: <?=e($lesson['title'])?></h1><p><?=e($lesson['course_title'])?> · Học viên đặt câu hỏi, giảng viên trả lời ngay bên dưới.</p></div>
  <a class="bf-muted" href="<?=e(app_url($user['role']==='student' ? 'student/learn.php?id='.$lid : ($user['role']==='teacher' ? 'teacher/lessons.php?course_id='.(int)$lesson['course_id'] : 'admin/courses.php')))?>">← Trở về bài học</a>
</header>
<?php foreach ($errors as $error): ?><div class="bf-note bf-error" role="alert"><?=e($error)?></div><?php endforeach; ?>
<section class="bf-panel" style="margin-bottom:20px">
  <h2>Đăng câu hỏi / trao đổi</h2>
  <form method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="discussion"><input type="hidden" name="lesson" value="<?=$lid?>">
    <label class="bf-field" for="discussionKind">Loại trao đổi</label>
    <select class="bf-input" id="discussionKind" name="kind" style="margin-bottom:12px"><option value="question">Câu hỏi / thảo luận</option><option value="issue">Báo lỗi hoặc vấn đề với bài học</option></select>
    <label class="bf-field" for="discussionBody">Nội dung</label>
    <textarea class="bf-input" id="discussionBody" name="body" rows="4" maxlength="3900" required placeholder="Bạn muốn trao đổi điều gì về bài học này?"></textarea>
    <div style="margin-top:13px"><button type="submit" class="bf-btn">Gửi thảo luận</button></div>
  </form>
</section>
<section class="bf-panel">
  <h2>Thảo luận gần nhất <span class="bf-discussion-count">(<?=count($rows)?> bài viết)</span></h2>
  <?php if (!$rows): ?><p class="bf-subtle">Chưa có trao đổi nào. Hãy đặt câu hỏi đầu tiên.</p><?php endif; ?>
  <?php foreach ($rows as $r): $id=(int)$r['id']; ?>
    <article id="discussion-<?=$id?>" class="bf-discussion">
      <div class="bf-discussion-head"><strong><?=e($r['full_name'])?></strong><span class="bf-chip"><?=e(bf_social_role($r['role']))?></span><time><?=e($r['created_at'])?></time></div>
      <p class="bf-discussion-body"><?=e($r['body'])?></p>
      <?php if (!empty($replyMap[$id])): ?>
        <div class="bf-replies" aria-label="Phản hồi của giảng viên">
          <?php foreach($replyMap[$id] as $reply): ?>
            <div class="bf-reply" id="reply-<?=(int)$reply['id']?>">
              <div class="bf-discussion-head"><b>✓ Giảng viên <?=e($reply['teacher_name'])?></b><time><?=e($reply['created_at'])?></time></div>
              <p><?=e($reply['body'])?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($isOwner && $r['role']==='student'): ?>
        <form class="bf-reply-form" method="post">
          <?=csrf_field()?><input type="hidden" name="action" value="reply"><input type="hidden" name="lesson" value="<?=$lid?>"><input type="hidden" name="discussion_id" value="<?=$id?>">
          <label for="reply-body-<?=$id?>">↳ Trả lời trực tiếp câu hỏi này</label>
          <textarea class="bf-input" id="reply-body-<?=$id?>" name="body" maxlength="3900" required placeholder="Nhập lời giải đáp để học viên nhận được thông báo..."></textarea>
          <button type="submit" class="bf-btn">Gửi phản hồi cho học viên</button>
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php bf_portal_end(); ?>
