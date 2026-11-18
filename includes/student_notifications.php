<?php
/** Student inbox for direct answers from their lesson's course teacher. */
require_once __DIR__ . '/permissions.php';

function bf_student_unread_count(PDO $pdo, int $studentId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bf_student_notifications n
            JOIN bf_lesson_discussions d ON d.id=n.discussion_id AND d.author_id=n.student_id AND d.lesson_id=n.lesson_id
            JOIN bf_lesson_discussion_replies r ON r.id=n.reply_id AND r.discussion_id=n.discussion_id AND r.teacher_id=n.teacher_id
            WHERE n.student_id=? AND n.is_read=0');
        $stmt->execute([$studentId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $ex) {
        // Allow the rest of the website to work before the migration is installed.
        error_log('[BrightFuture student notification count] ' . $ex->getMessage());
        return 0;
    }
}

/** Navigation link for legacy student pages that have their own sidebar. */
function bf_student_notification_link(int $studentId, string $className='nav-link'): string
{
    $count = bf_student_unread_count(db(), $studentId);
    $badge = $count > 0 ? ' <strong style="display:inline-block;margin-left:6px;padding:1px 8px;background:#e11d48;color:#fff;border-radius:999px;font-size:11px">' . ($count > 99 ? '99+' : $count) . '</strong>' : '';
    return '<a class="' . e($className) . '" href="' . e(app_url('student/notifications.php')) . '">🔔 &nbsp; Thông báo phản hồi' . $badge . '</a>';
}
