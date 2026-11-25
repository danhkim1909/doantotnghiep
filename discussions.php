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
                redirect('discussions.php?lesson=' . $lid . '&message=' . $discussionId . '&replied=1#reply-' . $replyId, 303);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[BrightFuture discussion reply] ' . $ex->getMessage());
                $errors[] = 'Chưa gửi được phản hồi. Hãy kiểm tra trạng thái migration thông báo bài học.';
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
            redirect('discussions.php?lesson=' . $lid . '&message=' . $messageId . '&posted=1#discussion-' . $messageId, 303);
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
    $errors[] = 'Không tải được toàn bộ thảo luận hoặc phản hồi. Hãy kiểm tra schema baseline và trạng thái migration.';
}

if (!function_exists('bf_time_ago')) {
    function bf_time_ago(string $datetime): string
    {
        try {
            $time = new DateTimeImmutable($datetime);
            $now = new DateTimeImmutable();
            $diff = $now->getTimestamp() - $time->getTimestamp();
            if ($diff < 45) return 'Vừa xong';
            if ($diff < 3600) return floor($diff / 60) . ' phút trước';
            if ($diff < 86400) return floor($diff / 3600) . ' giờ trước';
            if ($diff < 259200) return floor($diff / 86400) . ' ngày trước';
            return $time->format('d/m/Y H:i');
        } catch (Throwable) {
            return $datetime;
        }
    }
}

bf_portal_begin($user, 'courses', 'Thảo luận bài học', ['css/bf-design-system.css', 'css/bf-discussions.css']);
$justPosted = get_query('posted') === '1';
$justReplied = get_query('replied') === '1';
?>

<header class="bf-header">
    <div>
        <h1>Thảo luận: <?= e($lesson['title']) ?></h1>
        <p><?= e($lesson['course_title']) ?> · Học viên đặt câu hỏi, giảng viên giải đáp chi tiết.</p>
    </div>
    <a class="bf-muted" href="<?= e(app_url($user['role'] === 'student' ? 'student/learn.php?id=' . $lid : ($user['role'] === 'teacher' ? 'teacher/lessons.php?course_id=' . (int)$lesson['course_id'] : 'admin/courses.php'))) ?>">
        ← Trở về bài học
    </a>
</header>

<?php foreach ($errors as $error): ?>
    <div class="bf-note bf-error" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>

<section class="bf-panel" style="margin-bottom: 24px;">
    <h2>Đặt câu hỏi hoặc chia sẻ thắc mắc</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discussion">
        <input type="hidden" name="lesson" value="<?= $lid ?>">

        <label class="bf-field" for="discussionKind">Chủ đề thảo luận</label>
        <select class="bf-input" id="discussionKind" name="kind" style="margin-bottom: 14px;">
            <option value="question">Câu hỏi / Trao đổi bài học</option>
            <option value="issue">Báo lỗi hoặc vấn đề kỹ thuật với bài học</option>
        </select>

        <label class="bf-field" for="discussionBody">Nội dung câu hỏi</label>
        <textarea class="bf-input" id="discussionBody" name="body" rows="4" maxlength="3900" required placeholder="Nhập chi tiết câu hỏi hoặc thắc mắc của bạn về bài học này..."></textarea>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
            <span style="font-size: 12px; color: var(--color-muted);">Mẹo: Đặt câu hỏi cụ thể, rõ ràng để nhận giải đáp tốt nhất từ giảng viên.</span>
            <span class="bf-char-counter" id="charCounter">0 / 3.900</span>
        </div>

        <div style="margin-top: 14px;">
            <button type="submit" class="bf-btn">
                <?= bf_icon('social', 'bf-button-icon') ?> Gửi thảo luận
            </button>
        </div>
    </form>
</section>

<section class="bf-panel">
    <h2>Các câu hỏi thảo luận <span class="bf-discussion-count">(<?= count($rows) ?> bài viết)</span></h2>

    <?php if (!$rows): ?>
        <div class="bf-empty" style="text-align: center; padding: 32px 16px;">
            <p>Chưa có thảo luận nào trong bài học này. Hãy là người đầu tiên đặt câu hỏi!</p>
        </div>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $id = (int) $r['id'];
        $rawBody = (string) $r['body'];
        $isIssue = str_starts_with($rawBody, "[Báo lỗi bài học]\n");
        $displayBody = $isIssue ? substr($rawBody, strlen("[Báo lỗi bài học]\n")) : $rawBody;
        $initial = mb_substr(trim($r['full_name']), 0, 1, 'UTF-8');
        $hasReplies = !empty($replyMap[$id]);
        $fullTime = date('d/m/Y H:i:s', strtotime($r['created_at']));
        $timeAgo = bf_time_ago($r['created_at']);
    ?>
        <article id="discussion-<?= $id ?>" class="bf-discussion">
            <div class="bf-discussion-head">
                <div class="bf-user-badge-wrap">
                    <span class="bf-avatar-circle"><?= e($initial) ?></span>
                    <strong class="bf-discussion-author"><?= e($r['full_name']) ?></strong>
                </div>
                <span class="bf-chip"><?= e(bf_social_role($r['role'])) ?></span>
                <?php if ($isIssue): ?>
                    <span class="bf-issue-badge">⚠️ Báo lỗi</span>
                <?php endif; ?>
                <?php if ($r['role'] === 'student'): ?>
                    <?php if ($hasReplies): ?>
                        <span class="bf-answered-badge">✓ Đã giải đáp</span>
                    <?php else: ?>
                        <span class="bf-pending-badge">⏳ Chờ phản hồi</span>
                    <?php endif; ?>
                <?php endif; ?>
                <time style="margin-left: auto;" title="<?= e($fullTime) ?>"><?= e($timeAgo) ?></time>
            </div>

            <p class="bf-discussion-body"><?= e($displayBody) ?></p>

            <?php if (!empty($replyMap[$id])): ?>
                <div class="bf-replies" aria-label="Phản hồi của giảng viên">
                    <?php foreach ($replyMap[$id] as $reply):
                        $tInitial = mb_substr(trim($reply['teacher_name']), 0, 1, 'UTF-8');
                        $rFullTime = date('d/m/Y H:i:s', strtotime($reply['created_at']));
                        $rTimeAgo = bf_time_ago($reply['created_at']);
                    ?>
                        <div class="bf-reply" id="reply-<?= (int)$reply['id'] ?>">
                            <div class="bf-discussion-head">
                                <div class="bf-user-badge-wrap">
                                    <span class="bf-avatar-circle bf-avatar-teacher"><?= e($tInitial) ?></span>
                                    <strong style="color: #1e40af;"><?= bf_icon('check') ?> Giảng viên <?= e($reply['teacher_name']) ?></strong>
                                </div>
                                <time style="margin-left: auto;" title="<?= e($rFullTime) ?>"><?= e($rTimeAgo) ?></time>
                            </div>
                            <p class="bf-reply-body"><?= e($reply['body']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($isOwner && $r['role'] === 'student'): ?>
                <form class="bf-reply-form" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="lesson" value="<?= $lid ?>">
                    <input type="hidden" name="discussion_id" value="<?= $id ?>">

                    <label for="reply-body-<?= $id ?>">↳ Trả lời trực tiếp câu hỏi của học viên:</label>
                    <textarea class="bf-input" id="reply-body-<?= $id ?>" name="body" maxlength="3900" required placeholder="Nhập nội dung giải đáp chuyên môn..."></textarea>
                    <button type="submit" class="bf-btn">
                        Gửi phản hồi cho học viên
                    </button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mainTextarea = document.getElementById('discussionBody');
    const mainCounter = document.getElementById('charCounter');
    if (mainTextarea && mainCounter) {
        function updateCount() {
            const len = mainTextarea.value.length;
            mainCounter.textContent = len.toLocaleString('vi-VN') + ' / 3.900';
            mainCounter.classList.toggle('warning', len > 3500 && len <= 3850);
            mainCounter.classList.toggle('danger', len > 3850);
        }
        mainTextarea.addEventListener('input', updateCount);
        updateCount();
    }
});
</script>

<?php if ($justPosted || $justReplied): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.bfToast) {
        <?php if ($justPosted): ?>
            window.bfToast.success('Đã gửi câu hỏi thảo luận thành công!');
        <?php elseif ($justReplied): ?>
            window.bfToast.success('Đã gửi phản hồi giải đáp cho học viên thành công!');
        <?php endif; ?>
    }
});
</script>
<?php endif; ?>

<?php bf_portal_end(); ?>
