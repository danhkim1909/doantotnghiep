<?php
/**
 * ====================================================
 * BRIGHTFUTURE - COURSE & LEARNING QUERIES
 * File: includes/course_queries.php
 * PHP 8.0+ / MySQL PDO
 * Tách biệt tầng dữ liệu (Data Access) khỏi giao diện
 * ====================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Lấy số liệu thống kê tổng quan của một học viên
 */
function bf_get_student_dashboard_stats(PDO $pdo, int $studentId): array
{
    $stats = [
        'total_courses' => 0,
        'active_courses' => 0,
        'completed_courses' => 0,
        'completed_lessons' => 0
    ];

    if ($studentId <= 0) {
        return $stats;
    }

    // 1. Thống kê số khóa học đã tham gia
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_courses,
            COALESCE(SUM(status = 'active'), 0) AS active_courses,
            COALESCE(SUM(status = 'completed'), 0) AS completed_courses
         FROM enrollments
         WHERE student_id = :student_id
           AND status IN ('active', 'completed')"
    );
    $stmt->execute(['student_id' => $studentId]);
    $enrollmentResult = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($enrollmentResult) {
        $stats['total_courses'] = (int) $enrollmentResult['total_courses'];
        $stats['active_courses'] = (int) $enrollmentResult['active_courses'];
        $stats['completed_courses'] = (int) $enrollmentResult['completed_courses'];
    }

    // 2. Thống kê số bài học đã hoàn thành
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS completed_lessons
         FROM lesson_progress lp
         INNER JOIN lessons l ON l.id = lp.lesson_id
         INNER JOIN course_chapters cc ON cc.id = l.chapter_id
         INNER JOIN enrollments e ON e.course_id = cc.course_id AND e.student_id = lp.student_id
         WHERE lp.student_id = :student_id
           AND lp.status = 'completed'
           AND l.status = 'published'
           AND e.status IN ('active', 'completed')"
    );
    $stmt->execute(['student_id' => $studentId]);
    $stats['completed_lessons'] = (int) $stmt->fetchColumn();

    return $stats;
}

/**
 * Lấy danh sách khóa học học viên đã đăng ký
 */
function bf_get_student_enrolled_courses(PDO $pdo, int $studentId, int $limit = 12): array
{
    if ($studentId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 50));

    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.title,
            c.description,
            c.category,
            c.thumbnail,
            c.level,
            c.status AS course_status,
            u.full_name AS teacher_name,
            e.status AS enrollment_status,
            e.enrolled_at,
            COALESCE(p.total_lessons, 0) AS total_lessons,
            COALESCE(p.completed_lessons, 0) AS completed_lessons,
            COALESCE(p.progress_percent, 0) AS progress_percent
         FROM enrollments e
         INNER JOIN courses c ON c.id = e.course_id
         INNER JOIN users u ON u.id = c.teacher_id
         LEFT JOIN view_student_course_progress p
             ON p.course_id = e.course_id
             AND p.student_id = e.student_id
         WHERE e.student_id = :student_id
           AND e.status IN ('active', 'completed')
         ORDER BY
             CASE WHEN e.status = 'active' THEN 0 ELSE 1 END,
             e.enrolled_at DESC,
             c.id DESC
         LIMIT $limit"
    );

    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Lấy danh sách bài học học viên vừa tương tác gần đây
 */
function bf_get_student_recent_lessons(PDO $pdo, int $studentId, int $limit = 5): array
{
    if ($studentId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 20));

    $stmt = $pdo->prepare(
        "SELECT
            l.id AS lesson_id,
            l.title AS lesson_title,
            l.lesson_type,
            c.id AS course_id,
            c.title AS course_title,
            lp.status AS progress_status,
            lp.updated_at
         FROM lesson_progress lp
         INNER JOIN lessons l ON l.id = lp.lesson_id
         INNER JOIN course_chapters cc ON cc.id = l.chapter_id
         INNER JOIN courses c ON c.id = cc.course_id
         INNER JOIN enrollments e ON e.course_id = c.id AND e.student_id = lp.student_id
         WHERE lp.student_id = :student_id
           AND lp.status IN ('in_progress', 'completed')
           AND e.status IN ('active', 'completed')
           AND c.status = 'published'
           AND l.status = 'published'
         ORDER BY lp.updated_at DESC
         LIMIT $limit"
    );

    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Lấy các khóa học đề xuất mà học viên chưa đăng ký
 */
function bf_get_recommended_courses_for_student(PDO $pdo, int $studentId, int $limit = 3): array
{
    $limit = max(1, min($limit, 10));

    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.title,
            c.description,
            c.category,
            c.thumbnail,
            c.level,
            u.full_name AS teacher_name
         FROM courses c
         INNER JOIN users u ON u.id = c.teacher_id
         WHERE c.status = 'published'
           AND c.deleted_at IS NULL
           AND NOT EXISTS (
               SELECT 1
               FROM enrollments e
               WHERE e.course_id = c.id
                 AND e.student_id = :student_id
           )
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT $limit"
    );

    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
