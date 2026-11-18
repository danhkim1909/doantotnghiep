<?php
/** Notifications sent to the teacher who owns the lesson, stored in MySQL. */
require_once __DIR__ . '/permissions.php';

function bf_teacher_unread_count(PDO $pdo, int $teacherId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*)
            FROM bf_teacher_notifications n
            INNER JOIN lessons l ON l.id = n.lesson_id
            INNER JOIN course_chapters ch ON ch.id = l.chapter_id
            INNER JOIN courses c ON c.id = ch.course_id AND c.teacher_id = n.teacher_id
            WHERE n.teacher_id = ? AND n.is_read = 0');
        $stmt->execute([$teacherId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        // Legacy installations without the new migration must still open pages.
        error_log('[BrightFuture teacher notifications] ' . $exception->getMessage());
        return 0;
    }
}

/** Sidebar link with accessible unread count; safe to call from all teacher views. */
function bf_teacher_notification_link(int $teacherId): string
{
    $count = bf_teacher_unread_count(db(), $teacherId);
    $suffix = $count > 0
        ? ' <strong style="display:inline-flex;align-items:center;justify-content:center;margin-left:7px;min-width:23px;padding:2px 7px;border-radius:99px;background:#e11d48;color:white;font-size:11px">'
            . ($count > 99 ? '99+' : $count) . '</strong>'
        : '';
    return '<a href="' . e(app_url('teacher/notifications.php')) . '">🔔 Thông báo' . $suffix . '</a>';
}
