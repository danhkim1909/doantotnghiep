
<?php
/**
 * =====================================================
 * BRIGHTFUTURE - STUDENT DASHBOARD
 * File: student/dashboard.php
 * PHP 8.0+ / MySQL PDO
 * =====================================================
 */

// =====================================================
// 1. LOAD AUTHENTICATION & PERMISSIONS
// =====================================================

require_once __DIR__ . '/../includes/permissions.php';

// Chi hoc vien duoc truy cap.
$user = require_role(ROLE_STUDENT);

// Khong cache trang ca nhan.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$studentId = (int) $user['id'];

// =====================================================
// 2. KHOI TAO DU LIEU
// =====================================================

$stats = [
    'total_courses' => 0,
    'active_courses' => 0,
    'completed_courses' => 0,
    'completed_lessons' => 0
];

$myCourses = [];
$recentLessons = [];
$recommendedCourses = [];

$dataError = false;

// =====================================================
// 3. LAY DU LIEU TU MYSQL
// =====================================================

try {

    $pdo = db();

    // -------------------------------------------------
    // 3.1. THONG KE DANG KY KHOA HOC
    // -------------------------------------------------

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_courses,

            COALESCE(
                SUM(status = 'active'), 0
            ) AS active_courses,

            COALESCE(
                SUM(status = 'completed'), 0
            ) AS completed_courses

         FROM enrollments
         WHERE student_id = :student_id
           AND status IN ('active', 'completed')"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $result = $stmt->fetch();

    if ($result) {
        $stats['total_courses'] =
            (int) $result['total_courses'];

        $stats['active_courses'] =
            (int) $result['active_courses'];

        $stats['completed_courses'] =
            (int) $result['completed_courses'];
    }

    // -------------------------------------------------
    // 3.2. SO BAI HOC DA HOAN THANH
    // -------------------------------------------------

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS completed_lessons
         FROM lesson_progress lp

         INNER JOIN lessons l
             ON l.id = lp.lesson_id

         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id

         INNER JOIN enrollments e
             ON e.course_id = cc.course_id
             AND e.student_id = lp.student_id

         WHERE lp.student_id = :student_id
           AND lp.status = 'completed'
           AND l.status = 'published'
           AND e.status IN ('active', 'completed')"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $stats['completed_lessons'] =
        (int) $stmt->fetchColumn();

    // -------------------------------------------------
    // 3.3. DANH SACH KHOA HOC CUA TOI
    // -------------------------------------------------

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

            COALESCE(
                p.total_lessons, 0
            ) AS total_lessons,

            COALESCE(
                p.completed_lessons, 0
            ) AS completed_lessons,

            COALESCE(
                p.progress_percent, 0
            ) AS progress_percent

         FROM enrollments e

         INNER JOIN courses c
             ON c.id = e.course_id

         INNER JOIN users u
             ON u.id = c.teacher_id

         LEFT JOIN view_student_course_progress p
             ON p.course_id = e.course_id
             AND p.student_id = e.student_id

         WHERE e.student_id = :student_id
           AND e.status IN ('active', 'completed')

         ORDER BY
             CASE
                 WHEN e.status = 'active' THEN 0
                 ELSE 1
             END,
             e.enrolled_at DESC,
             c.id DESC

         LIMIT 12"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $myCourses = $stmt->fetchAll();

    // -------------------------------------------------
    // 3.4. BAI HOC GAN DAY
    // -------------------------------------------------

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

         INNER JOIN lessons l
             ON l.id = lp.lesson_id

         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id

         INNER JOIN courses c
             ON c.id = cc.course_id

         INNER JOIN enrollments e
             ON e.course_id = c.id
             AND e.student_id = lp.student_id

         WHERE lp.student_id = :student_id
           AND lp.status IN ('in_progress', 'completed')
           AND e.status IN ('active', 'completed')
           AND c.status = 'published'
           AND l.status = 'published'

         ORDER BY lp.updated_at DESC

         LIMIT 5"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $recentLessons = $stmt->fetchAll();

    // -------------------------------------------------
    // 3.5. KHOA HOC GOI Y
    // CHI LAY KHOA HOC CHUA DANG KY
    // -------------------------------------------------

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

         INNER JOIN users u
             ON u.id = c.teacher_id

         WHERE c.status = 'published'

           AND NOT EXISTS (
               SELECT 1
               FROM enrollments e
               WHERE e.course_id = c.id
                 AND e.student_id = :student_id
           )

         ORDER BY c.created_at DESC, c.id DESC

         LIMIT 3"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $recommendedCourses = $stmt->fetchAll();

} catch (Throwable $exception) {

    error_log(
        '[BrightFuture Student Dashboard] ' .
        $exception->getMessage()
    );

    $dataError = true;

    // Khong hien thong tin ket noi database.
    $stats = [
        'total_courses' => 0,
        'active_courses' => 0,
        'completed_courses' => 0,
        'completed_lessons' => 0
    ];

    $myCourses = [];
    $recentLessons = [];
    $recommendedCourses = [];
}

// =====================================================
// 4. HAM HIEN THI RIENG CUA DASHBOARD
// =====================================================

function student_level_label(string $level): string
{
    return match ($level) {
        'beginner' => 'Cơ bản',
        'intermediate' => 'Trung cấp',
        'advanced' => 'Nâng cao',
        default => 'Chưa phân loại'
    };
}

function student_progress_label(string $status): string
{
    return match ($status) {
        'completed' => 'Đã hoàn thành',
        'in_progress' => 'Đang học',
        default => 'Chưa bắt đầu'
    };
}

function student_course_icon(?string $category): string
{
    $category = strtolower(trim($category ?? ''));

    if (str_contains($category, 'python')) {
        return 'Py';
    }

    if (
        str_contains($category, 'web')
        || str_contains($category, 'html')
        || str_contains($category, 'css')
    ) {
        return '</>';
    }

    if (
        str_contains($category, 'javascript')
        || str_contains($category, 'node')
    ) {
        return 'JS';
    }

    if (str_contains($category, 'java')) {
        return 'Jv';
    }

    if (
        str_contains($category, 'data')
        || str_contains($category, 'ai')
    ) {
        return 'AI';
    }

    return '{}';
}

// Chi cho phep thumbnail duong dan tuong doi
// trong thu muc uploads/courses.
// Khong nhan URL tuy y tu database.
function student_thumbnail_url(?string $thumbnail): ?string
{
    if ($thumbnail === null || $thumbnail === '') {
        return null;
    }

    $thumbnail = str_replace('\\', '/', $thumbnail);

    if (
        str_contains($thumbnail, '..')
        || str_starts_with($thumbnail, '/')
        || preg_match(
            '~^[a-z][a-z0-9+.-]*:~i',
            $thumbnail
        )
        || preg_match('~[\x00-\x1F\x7F]~', $thumbnail)
    ) {
        return null;
    }

    if (!preg_match(
        '~^(?:uploads/courses/)?[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)$~i',
        $thumbnail
    )) {
        return null;
    }

    if (!str_starts_with(
        $thumbnail,
        'uploads/courses/'
    )) {
        $thumbnail = 'uploads/courses/' . $thumbnail;
    }

    return app_url($thumbnail);
}

$pageTitle = 'Tổng quan học tập | ' . APP_NAME;

$firstName = trim(
    explode(' ', trim($user['full_name']))[
        count(
            explode(' ', trim($user['full_name']))
        ) - 1
    ] ?? $user['full_name']
);

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Trang quản lý học tập dành cho học viên BrightFuture."
    >

    <title><?= e($pageTitle) ?></title>

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --secondary: #7c3aed;
            --background: #f6f8fc;
            --surface: #ffffff;
            --text: #172033;
            --muted: #64748b;
            --border: #e7ebf2;
            --success: #16a34a;
            --radius: 16px;
            --sidebar-width: 255px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--background);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button {
            font: inherit;
            cursor: pointer;
        }

        .layout {
            min-height: 100vh;
        }

        /* ====================================
           SIDEBAR
        ==================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform .25s;
        }

        .sidebar-logo {
            height: 90px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 25px;
            font-weight: 800;
            font-size: 21px;
            color: var(--primary);
            letter-spacing: -.5px;
        }

        .logo-icon {
            width: 39px;
            height: 39px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: linear-gradient(
                135deg,
                var(--primary),
                var(--secondary)
            );
            color: white;
            font-size: 15px;
            font-weight: 800;
        }

        .sidebar-nav {
            padding: 12px 15px;
            flex: 1;
            overflow-y: auto;
        }

        .nav-label {
            padding: 14px 13px 9px;
            font-size: 11px;
            color: #94a3b8;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 15px;
            border-radius: 11px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
            transition: .2s;
        }

        .nav-link:hover {
            background: #f4f5ff;
            color: var(--primary);
        }

        .nav-link.active {
            color: var(--primary);
            background: var(--primary-light);
            font-weight: 700;
        }

        .nav-icon {
            width: 23px;
            text-align: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .sidebar-bottom {
            padding: 17px;
            border-top: 1px solid var(--border);
        }

        .user-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            background: #ede9fe;
            color: #6d28d9;
            font-weight: 800;
            font-size: 18px;
            flex-shrink: 0;
        }

        .user-mini-info {
            min-width: 0;
            flex: 1;
        }

        .user-mini-info strong {
            display: block;
            font-size: 13px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .user-mini-info span {
            font-size: 12px;
            color: var(--muted);
        }

        .logout-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            background: #fff1f2;
            color: #be123c;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
        }

        .logout-btn:hover {
            background: #ffe4e6;
        }

        /* ====================================
           MAIN CONTENT
        ==================================== */

        .main {
            margin-left: var(--sidebar-width);
            min-width: 0;
        }

        .topbar {
            height: 83px;
            padding: 0 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            border-bottom: 1px solid var(--border);
        }

        .topbar-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--muted);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .topbar-user {
            font-size: 13px;
            font-weight: 600;
        }

        .topbar-avatar {
            width: 37px;
            height: 37px;
            border-radius: 50%;
            background: #e0e7ff;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-weight: 800;
        }

        .mobile-menu {
            display: none;
            border: none;
            background: transparent;
            font-size: 24px;
            color: var(--text);
        }

        .content {
            width: 100%;
            max-width: 1500px;
            padding: 34px 36px 60px;
            margin: auto;
        }

        /* ====================================
           WELCOME BANNER
        ==================================== */

        .welcome-banner {
            border-radius: 21px;
            color: white;
            background: linear-gradient(
                115deg,
                #4338ca,
                #6366f1 60%,
                #8b5cf6
            );
            padding: 35px 38px;
            position: relative;
            overflow: hidden;
            margin-bottom: 29px;
        }

        .welcome-banner::before {
            content: "";
            position: absolute;
            right: -65px;
            top: -100px;
            width: 330px;
            height: 330px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }

        .welcome-content {
            position: relative;
            z-index: 1;
        }

        .welcome-badge {
            display: inline-block;
            background: rgba(255,255,255,.17);
            border-radius: 30px;
            padding: 7px 13px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 17px;
        }

        .welcome-banner h1 {
            font-size: clamp(23px, 2.5vw, 32px);
            letter-spacing: -.7px;
            margin-bottom: 12px;
        }

        .welcome-banner p {
            color: #e0e7ff;
            line-height: 1.7;
            max-width: 650px;
            font-size: 14px;
        }

        .welcome-action {
            display: inline-flex;
            margin-top: 20px;
            padding: 12px 18px;
            border-radius: 10px;
            background: white;
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }

        /* ====================================
           STATISTICS
        ==================================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 33px;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .stat-info p {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
            margin-bottom: 13px;
        }

        .stat-info strong {
            font-size: 29px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .icon-purple {
            background: #ede9fe;
            color: #7c3aed;
        }

        .icon-blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .icon-green {
            background: #dcfce7;
            color: #16a34a;
        }

        .icon-orange {
            background: #ffedd5;
            color: #ea580c;
        }

        /* ====================================
           SECTIONS
        ==================================== */

        .section {
            margin-bottom: 33px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 19px;
        }

        .section-header h2 {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .section-header p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 7px;
        }

        .view-all {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        /* ====================================
           COURSE CARDS
        ==================================== */

        .course-grid {
            display: grid;
            grid-template-columns: repeat(
                3,
                minmax(0, 1fr)
            );
            gap: 19px;
        }

        .course-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 17px;
            overflow: hidden;
            min-width: 0;
            transition: transform .2s, box-shadow .2s;
        }

        .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(15,23,42,.07);
        }

        .course-thumbnail {
            height: 145px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(
                135deg,
                #e0e7ff,
                #ddd6fe
            );
            color: var(--primary);
            font-size: 44px;
            font-weight: 800;
            overflow: hidden;
        }

        .course-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .course-body {
            padding: 19px;
        }

        .course-category {
            display: inline-block;
            background: #eef2ff;
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 7px;
            margin-bottom: 12px;
        }

        .course-title {
            font-size: 16px;
            font-weight: 750;
            line-height: 1.5;
            margin-bottom: 9px;
            min-height: 48px;
            overflow-wrap: anywhere;
        }

        .course-teacher {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 19px;
        }

        .progress-heading {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            margin-bottom: 9px;
        }

        .progress-heading span:first-child {
            color: var(--muted);
        }

        .progress-heading strong {
            color: var(--primary);
        }

        .progress-track {
            background: #e9edf5;
            width: 100%;
            height: 8px;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(
                90deg,
                var(--primary),
                #8b5cf6
            );
            height: 100%;
            border-radius: 10px;
        }

        .course-meta {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 7px;
            color: var(--muted);
            font-size: 12px;
            margin-top: 12px;
        }

        .course-action {
            display: block;
            text-align: center;
            padding: 12px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
            margin-top: 18px;
            transition: .2s;
        }

        .course-action:hover {
            background: var(--primary);
            color: white;
        }

        /* ====================================
           LOWER GRID
        ==================================== */

        .lower-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 20px;
        }

        .panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 22px;
            min-width: 0;
        }

        .panel h3 {
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .lesson-item {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border);
        }

        .lesson-item:last-child {
            border-bottom: none;
        }

        .lesson-icon {
            display: grid;
            place-items: center;
            width: 43px;
            height: 43px;
            border-radius: 11px;
            background: #eef2ff;
            color: var(--primary);
            font-weight: 800;
            font-size: 15px;
            flex-shrink: 0;
        }

        .lesson-info {
            min-width: 0;
            flex: 1;
        }

        .lesson-info strong {
            display: block;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 5px;
            overflow-wrap: anywhere;
        }

        .lesson-info p {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }

        .lesson-status {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        .lesson-status.completed {
            color: var(--success);
        }

        .recommend-item {
            display: flex;
            gap: 13px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .recommend-item:last-child {
            border: none;
        }

        .recommend-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: #e0e7ff;
            color: var(--primary);
            font-size: 15px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .recommend-info {
            min-width: 0;
            flex: 1;
        }

        .recommend-info strong {
            font-size: 13px;
            line-height: 1.5;
            display: block;
            margin-bottom: 5px;
        }

        .recommend-info span {
            font-size: 12px;
            color: var(--muted);
        }

        .recommend-link {
            color: var(--primary);
            font-size: 21px;
            font-weight: 800;
        }

        /* ====================================
           EMPTY STATE
        ==================================== */

        .empty-state {
            background: white;
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
            padding: 36px 22px;
            text-align: center;
            grid-column: 1 / -1;
        }

        .empty-icon {
            font-size: 40px;
            margin-bottom: 13px;
        }

        .empty-state h3 {
            font-size: 16px;
            margin-bottom: 9px;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .empty-action {
            display: inline-block;
            padding: 11px 17px;
            color: white;
            background: var(--primary);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .data-alert {
            padding: 15px 18px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 12px;
            color: #9a3412;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        /* ====================================
           MOBILE RESPONSIVE
        ==================================== */

        .sidebar-overlay {
            display: none;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .course-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .lower-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 90;
                background: rgba(15,23,42,.45);
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: block;
            }

            .topbar {
                height: 70px;
                padding: 0 22px;
            }

            .content {
                padding: 25px 22px;
            }
        }

        @media (max-width: 600px) {
            .stats-grid,
            .course-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 18px 15px 40px;
            }

            .topbar {
                padding: 0 15px;
            }

            .topbar-user {
                display: none;
            }

            .welcome-banner {
                padding: 28px 23px;
            }

            .welcome-banner h1 {
                font-size: 24px;
            }

            .section-header h2 {
                font-size: 19px;
            }

            .stat-card {
                padding: 20px;
            }
        }
    </style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>

<body>

<div class="layout">

    <!-- ==========================================
         MOBILE OVERLAY
    =========================================== -->

    <div
        id="sidebarOverlay"
        class="sidebar-overlay"
        aria-hidden="true"
    ></div>

    <!-- ==========================================
         SIDEBAR
    =========================================== -->

    <aside class="sidebar" id="sidebar">

        <a
            href="<?= e(app_url()) ?>"
            class="sidebar-logo"
        >
            <span class="logo-icon">&lt;/&gt;</span>
            <span>BrightFuture</span>
        </a>

        <nav class="sidebar-nav">
<a class="nav-link" href="<?= e(app_url('certificate.php')) ?>">✧ &nbsp; Chứng chỉ</a>

            <div class="nav-label">
                Học tập
            </div>

            <a
                href="<?= e(app_url('student/dashboard.php')) ?>"
                class="nav-link active"
                aria-current="page"
            >
                <span class="nav-icon">&#9638;</span>
                Tổng quan
            </a>

            <a
                href="<?= e(app_url('student/my-courses.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#128218;</span>
                Khóa học của tôi
            </a>

            <a
                href="<?= e(app_url('courses.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#128269;</span>
                Khám phá khóa học
            </a>

            <a
                href="<?= e(app_url('student/exercises.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#128187;</span>
                Bài tập
            </a>

            <a
                href="<?= e(app_url('student/progress.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#128200;</span>
                Tiến độ học tập
            </a>

            <div class="nav-label">
                Cá nhân
            </div>

            <a
                href="<?= e(app_url('profile.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#9786;</span>
                Hồ sơ của tôi
            </a>

            <a
                href="<?= e(app_url()) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#8962;</span>
                Trang chủ
            </a>

        </nav>

        <!-- USER & LOGOUT -->

        <div class="sidebar-bottom">

            <div class="user-mini">

                <div class="avatar">
                    <?= e(
                        function_exists('mb_substr')
                        ? mb_substr(
                            $user['full_name'],
                            0,
                            1,
                            'UTF-8'
                        )
                        : substr(
                            $user['full_name'],
                            0,
                            1
                        )
                    ) ?>
                </div>

                <div class="user-mini-info">

                    <strong>
                        <?= e($user['full_name']) ?>
                    </strong>

                    <span>Học viên</span>

                </div>

            </div>

            <form
                action="<?= e(app_url('logout.php')) ?>"
                method="POST"
            >
                <?= csrf_field() ?>

                <button
                    type="submit"
                    class="logout-btn"
                >
                    <span>&#10140;</span>
                    Đăng xuất
                </button>
            </form>

        </div>

    </aside>

    <!-- ==========================================
         MAIN
    =========================================== -->

    <div class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <div style="
                display:flex;
                align-items:center;
                gap:15px;
            ">

                <button
                    type="button"
                    id="mobileMenu"
                    class="mobile-menu"
                    aria-label="Mở menu"
                    aria-expanded="false"
                    aria-controls="sidebar"
                >
                    &#9776;
                </button>

                <span class="topbar-title">
                    Không gian học tập
                </span>

            </div>

            <div class="topbar-right">

                <span class="topbar-user">
                    <?= e($user['full_name']) ?>
                </span>

                <div class="topbar-avatar">
                    <?= e(
                        function_exists('mb_substr')
                        ? mb_substr(
                            $user['full_name'],
                            0,
                            1,
                            'UTF-8'
                        )
                        : substr(
                            $user['full_name'],
                            0,
                            1
                        )
                    ) ?>
                </div>

            </div>

        </header>

        <!-- CONTENT -->

        <main class="content">

            <!-- THONG BAO LOI DU LIEU -->

            <?php if ($dataError): ?>

                <div class="data-alert" role="alert">
                    Không thể tải đầy đủ dữ liệu học tập.
                    Vui lòng kiểm tra kết nối MySQL,
                    các bảng và View trong database.
                </div>

            <?php endif; ?>

            <!-- WELCOME -->

            <section class="welcome-banner">

                <div class="welcome-content">

                    <span class="welcome-badge">
                        &#10024; Không gian học tập của bạn
                    </span>

                    <h1>
                        Xin chào,
                        <?= e($firstName) ?>!
                    </h1>

                    <p>
                        Chào mừng bạn quay trở lại BrightFuture.
                        Tiếp tục khám phá kiến thức, thực hành
                        lập trình và phát triển kỹ năng mỗi ngày.
                    </p>

                    <a
                        href="<?= e(
                            app_url('student/my-courses.php')
                        ) ?>"
                        class="welcome-action"
                    >
                        Tiếp tục học &#8594;
                    </a>

                </div>

            </section>

            <!-- ==================================
                 THONG KE
            =================================== -->

            <section class="stats-grid">

                <div class="stat-card">

                    <div class="stat-info">
                        <p>Tổng khóa học</p>

                        <strong>
                            <?= format_number(
                                $stats['total_courses']
                            ) ?>
                        </strong>
                    </div>

                    <span class="stat-icon icon-purple">
                        &#128218;
                    </span>

                </div>

                <div class="stat-card">

                    <div class="stat-info">
                        <p>Khóa học đang học</p>

                        <strong>
                            <?= format_number(
                                $stats['active_courses']
                            ) ?>
                        </strong>
                    </div>

                    <span class="stat-icon icon-blue">
                        &#128187;
                    </span>

                </div>

                <div class="stat-card">

                    <div class="stat-info">
                        <p>Khóa học hoàn thành</p>

                        <strong>
                            <?= format_number(
                                $stats['completed_courses']
                            ) ?>
                        </strong>
                    </div>

                    <span class="stat-icon icon-green">
                        &#10003;
                    </span>

                </div>

                <div class="stat-card">

                    <div class="stat-info">
                        <p>Bài học đã hoàn thành</p>

                        <strong>
                            <?= format_number(
                                $stats['completed_lessons']
                            ) ?>
                        </strong>
                    </div>

                    <span class="stat-icon icon-orange">
                        &#9733;
                    </span>

                </div>

            </section>

            <!-- ==================================
                 KHOA HOC CUA TOI
            =================================== -->

            <section class="section">

                <div class="section-header">

                    <div>
                        <h2>Khóa học của tôi</h2>

                        <p>
                            Theo dõi những khóa học bạn
                            đã đăng ký.
                        </p>
                    </div>

                    <a
                        class="view-all"
                        href="<?= e(
                            app_url('student/my-courses.php')
                        ) ?>"
                    >
                        Xem tất cả &#8594;
                    </a>

                </div>

                <div class="course-grid">

                    <?php if (empty($myCourses)): ?>

                        <div class="empty-state">

                            <div class="empty-icon">
                                &#128218;
                            </div>

                            <h3>
                                Bạn chưa có khóa học nào
                            </h3>

                            <p>
                                Hãy khám phá các khóa học
                                và bắt đầu hành trình học
                                lập trình cùng BrightFuture.
                            </p>

                            <a
                                href="<?= e(
                                    app_url('courses.php')
                                ) ?>"
                                class="empty-action"
                            >
                                Khám phá khóa học
                            </a>

                        </div>

                    <?php else: ?>

                        <?php foreach ($myCourses as $course): ?>

                            <?php
                                $progress = max(
                                    0,
                                    min(
                                        100,
                                        (float) $course[
                                            'progress_percent'
                                        ]
                                    )
                                );

                                $thumbnail =
                                    student_thumbnail_url(
                                        $course['thumbnail']
                                    );

                                $courseId =
                                    (int) $course['id'];

                                $isCompleted =
                                    $course['enrollment_status']
                                    === 'completed';
                            ?>

                            <article class="course-card">

                                <div class="course-thumbnail">

                                    <?php if ($thumbnail !== null): ?>

                                        <img
                                            src="<?= e($thumbnail) ?>"
                                            alt="<?= e(
                                                $course['title']
                                            ) ?>"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <span>
                                            <?= e(
                                                student_course_icon(
                                                    $course['category']
                                                )
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div class="course-body">

                                    <span class="course-category">
                                        <?= e(
                                            $course['category']
                                            ?: student_level_label(
                                                $course['level']
                                            )
                                        ) ?>
                                    </span>

                                    <h3 class="course-title">
                                        <?= e($course['title']) ?>
                                    </h3>

                                    <p class="course-teacher">
                                        Giảng viên:
                                        <?= e(
                                            $course['teacher_name']
                                        ) ?>
                                    </p>

                                    <div class="progress-heading">

                                        <span>Tiến độ học tập</span>

                                        <strong>
                                            <?= e(
                                                number_format(
                                                    $progress,
                                                    0
                                                )
                                            ) ?>%
                                        </strong>

                                    </div>

                                    <div
                                        class="progress-track"
                                        role="progressbar"
                                        aria-label="Tiến độ khóa học"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="<?= e(
                                            (string) round($progress)
                                        ) ?>"
                                    >

                                        <div
                                            class="progress-fill"
                                            style="width: <?= e(
                                                (string) $progress
                                            ) ?>%;"
                                        ></div>

                                    </div>

                                    <div class="course-meta">

                                        <span>
                                            <?= (int) $course[
                                                'completed_lessons'
                                            ] ?>
                                            /
                                            <?= (int) $course[
                                                'total_lessons'
                                            ] ?>
                                            bài học
                                        </span>

                                        <span>
                                            <?= $isCompleted
                                                ? 'Đã hoàn thành'
                                                : 'Đang học' ?>
                                        </span>

                                    </div>

                                    <a
                                        href="<?= e(
                                            course_url($courseId)
                                        ) ?>"
                                        class="course-action"
                                    >
                                        <?= $isCompleted
                                            ? 'Xem lại khóa học'
                                            : 'Tiếp tục học' ?>
                                        &#8594;
                                    </a>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>

            <!-- ==================================
                 BAI HOC GAN DAY + KHOA HOC GOI Y
            =================================== -->

            <div class="lower-grid">

                <!-- BAI HOC GAN DAY -->

                <section class="panel">

                    <h3>Bài học gần đây</h3>

                    <?php if (empty($recentLessons)): ?>

                        <div class="empty-state">

                            <div class="empty-icon">
                                &#128214;
                            </div>

                            <h3>Chưa có lịch sử học tập</h3>

                            <p>
                                Khi bạn bắt đầu học,
                                các bài học gần đây sẽ
                                xuất hiện tại đây.
                            </p>

                        </div>

                    <?php else: ?>

                        <?php foreach ($recentLessons as $lesson): ?>

                            <div class="lesson-item">

                                <div class="lesson-icon">
                                    <?= $lesson['lesson_type'] === 'video'
                                        ? '&#9654;'
                                        : '&lt;/&gt;' ?>
                                </div>

                                <div class="lesson-info">

                                    <a
                                        href="<?= e(
                                            lesson_url(
                                                (int) $lesson[
                                                    'lesson_id'
                                                ]
                                            )
                                        ) ?>"
                                    >
                                        <strong>
                                            <?= e(
                                                $lesson['lesson_title']
                                            ) ?>
                                        </strong>
                                    </a>

                                    <p>
                                        <?= e(
                                            $lesson['course_title']
                                        ) ?>
                                        &middot;
                                        <?= e(
                                            format_datetime(
                                                $lesson['updated_at']
                                            )
                                        ) ?>
                                    </p>

                                </div>

                                <span class="lesson-status
                                    <?= $lesson['progress_status']
                                        === 'completed'
                                        ? 'completed'
                                        : '' ?>"
                                >
                                    <?= e(
                                        student_progress_label(
                                            $lesson['progress_status']
                                        )
                                    ) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </section>

                <!-- KHOA HOC GOI Y -->

                <section class="panel">

                    <h3>Khám phá khóa học</h3>

                    <?php if (empty($recommendedCourses)): ?>

                        <div class="empty-state">

                            <div class="empty-icon">
                                &#128161;
                            </div>

                            <h3>Chưa có khóa học gợi ý</h3>

                            <p>
                                Các khóa học công khai
                                chưa đăng ký sẽ được
                                hiển thị tại đây.
                            </p>

                        </div>

                    <?php else: ?>

                        <?php foreach (
                            $recommendedCourses as $course
                        ): ?>

                            <div class="recommend-item">

                                <div class="recommend-icon">
                                    <?= e(
                                        student_course_icon(
                                            $course['category']
                                        )
                                    ) ?>
                                </div>

                                <div class="recommend-info">

                                    <a
                                        href="<?= e(
                                            course_url(
                                                (int) $course['id']
                                            )
                                        ) ?>"
                                    >
                                        <strong>
                                            <?= e(
                                                $course['title']
                                            ) ?>
                                        </strong>
                                    </a>

                                    <span>
                                        <?= e(
                                            student_level_label(
                                                $course['level']
                                            )
                                        ) ?>
                                        &middot;
                                        <?= e(
                                            $course['teacher_name']
                                        ) ?>
                                    </span>

                                </div>

                                <a
                                    href="<?= e(
                                        course_url(
                                            (int) $course['id']
                                        )
                                    ) ?>"
                                    class="recommend-link"
                                    aria-label="<?= e(
                                        'Xem khóa học ' .
                                        $course['title']
                                    ) ?>"
                                >
                                    &#8594;
                                </a>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </section>

            </div>

        </main>

    </div>

</div>

<!-- ==========================================
     JAVASCRIPT - MOBILE SIDEBAR
=========================================== -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const sidebar =
        document.getElementById('sidebar');

    const menuButton =
        document.getElementById('mobileMenu');

    const overlay =
        document.getElementById('sidebarOverlay');

    function setMenuOpen(isOpen) {

        sidebar.classList.toggle('open', isOpen);

        overlay.classList.toggle('show', isOpen);

        menuButton.setAttribute(
            'aria-expanded',
            String(isOpen)
        );

        menuButton.setAttribute(
            'aria-label',
            isOpen ? 'Đóng menu' : 'Mở menu'
        );
    }

    menuButton.addEventListener('click', function () {

        const isOpen =
            sidebar.classList.contains('open');

        setMenuOpen(!isOpen);
    });

    overlay.addEventListener('click', function () {
        setMenuOpen(false);
    });

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            setMenuOpen(false);
        }
    });

    window.addEventListener('resize', function () {

        if (window.innerWidth > 900) {
            setMenuOpen(false);
        }
    });

});
</script>

<script>window.BF_LEGACY={role:'student',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
