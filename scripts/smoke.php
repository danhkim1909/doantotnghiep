<?php
require_once __DIR__ . '/../config/db.php';

$requiredTables = [
    'bf_blocks',
    'bf_audit_logs',
    'bf_ai_usage_limits',
    'bf_certificates',
    'bf_chat_groups',
    'bf_chat_members',
    'bf_direct_messages',
    'bf_email_verifications',
    'bf_friendships',
    'bf_group_messages',
    'bf_lesson_discussions',
    'bf_lesson_discussion_replies',
    'bf_login_attempts',
    'bf_meeting_chat',
    'bf_meeting_participants',
    'bf_meeting_rooms',
    'bf_meeting_signals',
    'bf_password_resets',
    'bf_password_reset_limits',
    'bf_rate_limit_windows',
    'bf_student_notifications',
    'bf_teacher_notifications',
    'bf_totp_backup_codes',
    'bf_totp_secrets',
    'bf_user_reports',
    'users',
    'courses',
    'course_chapters',
    'lessons',
    'enrollments',
    'lesson_progress',
    'course_unlock_rules',
    'lesson_resources',
    'exercise_questions',
    'exercise_submissions',
    'exercises',
    'question_options',
    'student_profiles',
    'submission_answers',
    'teacher_profiles',
    'bf_database_migrations',
];

$requiredViews = [
    'view_course_statistics',
    'view_student_course_progress',
];

try {
    $pdo = db();
    $existing = [];

    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) reset($row);
        $type = (string) next($row);
        $existing[$name] = $type;
    }

    $missing = [];
    foreach ($requiredTables as $name) {
        if (($existing[$name] ?? null) !== 'BASE TABLE') {
            $missing[] = 'table:' . $name;
        }
    }

    foreach ($requiredViews as $name) {
        if (($existing[$name] ?? null) !== 'VIEW') {
            $missing[] = 'view:' . $name;
        }
    }

    if ($missing !== []) {
        fwrite(STDERR, "Database smoke check failed. Missing:\n- " . implode("\n- ", $missing) . PHP_EOL);
        exit(1);
    }

    echo 'Database smoke check passed: ' . count($requiredTables) . ' tables and '
        . count($requiredViews) . ' view.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database smoke check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
