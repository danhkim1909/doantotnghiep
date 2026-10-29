
<?php
/**
 * ===================================================
 * BRIGHTFUTURE - MY COURSES
 * File: student/my-courses.php
 * PHP 8.0+ / PDO MySQL
 * ===================================================
 */



// ===================================================
// 1. AUTHENTICATION & PERMISSIONS
// ===================================================

require_once __DIR__ . '/../includes/permissions.php';

$user = require_role(ROLE_STUDENT);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$studentId = (int) $user['id'];

// ===================================================
// 2. FILTER & PAGINATION
// ===================================================

$search = get_query('q');

if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
} else {
    $search = substr($search, 0, 100);
}

$status = get_query('status', 'all');

$allowedStatuses = [
    'all',
    'active',
    'completed'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$page = positive_int($_GET['page'] ?? null, 1);

$perPage = 9;

$pagination = paginate(0, $page, $perPage);

// ===================================================
// 3. INITIAL DATA
// ===================================================

$stats = [
    'total' => 0,
    'active' => 0,
    'completed' => 0
];

$courses = [];

$dataError = false;

// ===================================================
// 4. DATABASE QUERIES
// ===================================================

try {

    $pdo = db();

    // -----------------------------------------------
    // 4.1. THONG KE
    // -----------------------------------------------

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,

            COALESCE(
                SUM(status = 'active'),
                0
            ) AS active,

            COALESCE(
                SUM(status = 'completed'),
                0
            ) AS completed

         FROM enrollments

         WHERE student_id = :student_id
           AND status IN ('active', 'completed')"
    );

    $stmt->execute([
        'student_id' => $studentId
    ]);

    $row = $stmt->fetch();

    if ($row) {
        $stats = [
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
            'completed' => (int) $row['completed']
        ];
    }

    // -----------------------------------------------
    // 4.2. DIEU KIEN LOC
    // -----------------------------------------------

    $conditions = [
        "e.student_id = :student_id",
        "e.status IN ('active', 'completed')"
    ];

    $params = [
        'student_id' => $studentId
    ];

    if ($status !== 'all') {

        $conditions[] = "e.status = :status";

        $params['status'] = $status;
    }

    if ($search !== '') {

        // Escape ky tu dac biet cua LIKE:
        // %, _, va !.
        $escapedSearch = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $search
        );

        $conditions[] =
            "(c.title LIKE :search_title ESCAPE '!'
              OR c.category LIKE :search_category ESCAPE '!'
              OR u.full_name LIKE :search_teacher ESCAPE '!')";

        $keyword = '%' . $escapedSearch . '%';

        $params['search_title'] = $keyword;
        $params['search_category'] = $keyword;
        $params['search_teacher'] = $keyword;
    }

    $whereSql = implode(' AND ', $conditions);

    // -----------------------------------------------
    // 4.3. DEM KHOA HOC SAU KHI LOC
    // -----------------------------------------------

    $countSql =
        "SELECT COUNT(*)

         FROM enrollments e

         INNER JOIN courses c
             ON c.id = e.course_id

         INNER JOIN users u
             ON u.id = c.teacher_id

         WHERE {$whereSql}";

    $countStmt = $pdo->prepare($countSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue(
            ':' . $key,
            $value,
            $key === 'student_id'
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
        );
    }

    $countStmt->execute();

    $totalItems = (int) $countStmt->fetchColumn();

    // -----------------------------------------------
    // 4.4. TINH PHAN TRANG
    // -----------------------------------------------

    $pagination = paginate(
        $totalItems,
        $page,
        $perPage
    );

    // -----------------------------------------------
    // 4.5. LAY DANH SACH KHOA HOC
    // -----------------------------------------------

    $sql =
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
            e.completed_at,

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

         WHERE {$whereSql}

         ORDER BY
            CASE
                WHEN e.status = 'active'
                THEN 0
                ELSE 1
            END,
            e.enrolled_at DESC,
            c.id DESC

         LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue(
            ':' . $key,
            $value,
            $key === 'student_id'
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
        );
    }

    $stmt->bindValue(
        ':limit',
        $pagination['per_page'],
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':offset',
        $pagination['offset'],
        PDO::PARAM_INT
    );

    $stmt->execute();

    $courses = $stmt->fetchAll();

} catch (Throwable $exception) {

    error_log(
        '[BrightFuture My Courses] ' .
        $exception->getMessage()
    );

    $dataError = true;

    $courses = [];

    $pagination = paginate(0, 1, $perPage);
}

// ===================================================
// 5. DISPLAY HELPERS
// ===================================================

function mycourses_level(string $level): string
{
    return match ($level) {
        'beginner' => 'Cơ bản',
        'intermediate' => 'Trung cấp',
        'advanced' => 'Nâng cao',
        default => 'Chưa phân loại'
    };
}

function mycourses_thumbnail(
    ?string $thumbnail
): ?string {

    if (!$thumbnail) {
        return null;
    }

    $thumbnail = str_replace(
        '\\',
        '/',
        $thumbnail
    );

    if (
        str_contains($thumbnail, '..')
        || str_starts_with($thumbnail, '/')
        || preg_match(
            '~^[a-z][a-z0-9+.-]*:~i',
            $thumbnail
        )
        || preg_match(
            '~[\x00-\x1F\x7F]~',
            $thumbnail
        )
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
        $thumbnail =
            'uploads/courses/' . $thumbnail;
    }

    return app_url($thumbnail);
}

function mycourses_icon(?string $category): string
{
    $category = strtolower(
        trim($category ?? '')
    );

    if (str_contains($category, 'python')) {
        return 'Py';
    }

    if (str_contains($category, 'javascript')) {
        return 'JS';
    }

    if (str_contains($category, 'java')) {
        return 'Jv';
    }

    if (
        str_contains($category, 'web')
        || str_contains($category, 'html')
    ) {
        return '</>';
    }

    if (
        str_contains($category, 'ai')
        || str_contains($category, 'data')
    ) {
        return 'AI';
    }

    return '{}';
}

function mycourses_url(
    int $page,
    string $search,
    string $status
): string {

    return app_url(
        'student/my-courses.php?' .
        http_build_query([
            'q' => $search,
            'status' => $status,
            'page' => $page
        ])
    );
}

$displayName = trim($user['full_name']);

$avatarLetter = function_exists('mb_substr')
    ? mb_substr($displayName, 0, 1, 'UTF-8')
    : substr($displayName, 0, 1);

$pageTitle = 'Khóa học của tôi | ' . APP_NAME;

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
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
            --sidebar-width: 255px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--background);
            color: var(--text);
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button, input, select {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        /* =====================================
           SIDEBAR
        ===================================== */

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
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
            min-height: 90px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 25px;
            font-size: 21px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -.5px;
        }

        .logo-icon {
            width: 39px;
            height: 39px;
            border-radius: 12px;
            background: linear-gradient(
                135deg,
                var(--primary),
                var(--secondary)
            );
            color: white;
            display: grid;
            place-items: center;
            font-size: 15px;
        }

        .sidebar-nav {
            padding: 12px 15px;
            flex: 1;
            overflow-y: auto;
        }

        .nav-label {
            padding: 14px 13px 9px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #94a3b8;
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
            background: var(--primary-light);
            color: var(--primary);
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
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-info strong {
            display: block;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info span {
            font-size: 12px;
            color: var(--muted);
        }

        .logout-btn {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: none;
            background: #fff1f2;
            color: #be123c;
            font-size: 13px;
            font-weight: 700;
        }

        .logout-btn:hover {
            background: #ffe4e6;
        }

        /* =====================================
           TOPBAR & MAIN
        ===================================== */

        .main {
            margin-left: var(--sidebar-width);
            min-width: 0;
        }

        .topbar {
            height: 83px;
            padding: 0 36px;
            background: white;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .topbar-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--muted);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .topbar-name {
            font-size: 13px;
            font-weight: 600;
        }

        .topbar-avatar {
            width: 37px;
            height: 37px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #e0e7ff;
            color: var(--primary);
            font-weight: 800;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: transparent;
            font-size: 24px;
        }

        .content {
            max-width: 1500px;
            margin: auto;
            padding: 35px 36px 60px;
        }

        /* =====================================
           PAGE HEADER
        ===================================== */

        .breadcrumb {
            display: flex;
            gap: 9px;
            align-items: center;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 22px;
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .breadcrumb-current {
            color: var(--primary);
            font-weight: 600;
        }

        .page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 29px;
        }

        .page-heading h1 {
            font-size: 29px;
            letter-spacing: -.8px;
            margin-bottom: 9px;
        }

        .page-heading p {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        .primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 19px;
            border-radius: 11px;
            background: var(--primary);
            color: white;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            transition: .2s;
        }

        .primary-btn:hover {
            background: var(--primary-dark);
        }

        /* =====================================
           STATS
        ===================================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 23px;
        }

        .stat-card p {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .stat-card strong {
            font-size: 28px;
            font-weight: 800;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            font-size: 22px;
        }

        .purple {
            background: #ede9fe;
            color: #7c3aed;
        }

        .blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .green {
            background: #dcfce7;
            color: #16a34a;
        }

        /* =====================================
           FILTER BAR
        ===================================== */

        .filter-panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 20px;
            margin-bottom: 26px;
        }

        .filter-form {
            display: flex;
            gap: 14px;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 0;
            position: relative;
        }

        .search-input {
            width: 100%;
            height: 48px;
            padding: 0 16px 0 43px;
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            outline: none;
            color: var(--text);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }

        .filter-select {
            width: 180px;
            height: 48px;
            padding: 0 13px;
            background: white;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            color: var(--text);
            outline: none;
        }

        .search-input:focus,
        .filter-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,.08);
        }

        .filter-submit {
            height: 48px;
            padding: 0 21px;
            border: 0;
            border-radius: 11px;
            color: white;
            background: var(--primary);
            font-weight: 700;
            font-size: 13px;
        }

        .filter-result {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 18px;
            color: var(--muted);
            font-size: 13px;
        }

        .clear-filter {
            color: var(--primary);
            font-weight: 600;
        }

        /* =====================================
           COURSE GRID
        ===================================== */

        .course-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
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

        .course-image {
            height: 155px;
            background: linear-gradient(
                135deg,
                #e0e7ff,
                #ddd6fe
            );
            display: grid;
            place-items: center;
            color: var(--primary);
            font-size: 43px;
            font-weight: 800;
            position: relative;
            overflow: hidden;
        }

        .course-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .status-badge {
            position: absolute;
            top: 13px;
            right: 13px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 30px;
            padding: 7px 11px;
            color: #1d4ed8;
            background: #eff6ff;
        }

        .status-badge.completed {
            color: #166534;
            background: #dcfce7;
        }

        .course-body {
            padding: 20px;
        }

        .course-category {
            display: inline-block;
            padding: 6px 10px;
            background: #eef2ff;
            color: var(--primary);
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .course-title {
            font-size: 16px;
            line-height: 1.5;
            font-weight: 800;
            min-height: 48px;
            margin-bottom: 9px;
            overflow-wrap: anywhere;
        }

        .course-title a:hover {
            color: var(--primary);
        }

        .course-teacher {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 15px;
        }

        .course-description {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.7;
            min-height: 42px;
            margin-bottom: 17px;
        }

        .progress-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            margin-bottom: 9px;
        }

        .progress-row span {
            color: var(--muted);
        }

        .progress-row strong {
            color: var(--primary);
        }

        .progress-track {
            height: 8px;
            background: #e9edf5;
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 20px;
            background: linear-gradient(
                90deg,
                var(--primary),
                #8b5cf6
            );
        }

        .course-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            margin-top: 13px;
        }

        .course-action {
            display: block;
            margin-top: 19px;
            padding: 12px;
            text-align: center;
            border-radius: 10px;
            color: var(--primary);
            background: var(--primary-light);
            font-size: 13px;
            font-weight: 700;
            transition: .2s;
        }

        .course-action:hover {
            color: white;
            background: var(--primary);
        }

        /* =====================================
           EMPTY STATE
        ===================================== */

        .empty-state {
            grid-column: 1 / -1;
            background: white;
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
            padding: 60px 25px;
            text-align: center;
        }

        .empty-icon {
            font-size: 44px;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 14px;
            line-height: 1.7;
            color: var(--muted);
            margin-bottom: 21px;
        }

        .error-alert {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 11px;
            padding: 15px 18px;
            margin-bottom: 22px;
            color: #9a3412;
            font-size: 13px;
        }

        /* =====================================
           PAGINATION
        ===================================== */

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 35px;
        }

        .page-link {
            min-width: 39px;
            height: 39px;
            padding: 0 12px;
            border-radius: 9px;
            background: white;
            border: 1px solid var(--border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .page-link:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .page-link.disabled {
            opacity: .45;
            pointer-events: none;
        }

        /* =====================================
           RESPONSIVE
        ===================================== */

        .sidebar-overlay {
            display: none;
        }

        @media (max-width: 1200px) {
            .course-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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

        @media (max-width: 650px) {
            .stats-grid,
            .course-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px 15px 40px;
            }

            .topbar {
                padding: 0 15px;
            }

            .topbar-name {
                display: none;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-select {
                width: 100%;
            }

            .filter-submit {
                width: 100%;
            }
        }
    </style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>

<body>

<div class="layout">

    <!-- =====================================
         MOBILE OVERLAY
    ====================================== -->

    <div
        id="sidebarOverlay"
        class="sidebar-overlay"
        aria-hidden="true"
    ></div>

    <!-- =====================================
         SIDEBAR
    ====================================== -->

    <aside class="sidebar" id="sidebar">

        <a
            class="sidebar-logo"
            href="<?= e(app_url()) ?>"
        >
            <span class="logo-icon">&lt;/&gt;</span>
            <span>BrightFuture</span>
        </a>

        <nav class="sidebar-nav">
<a class="nav-link" href="<?= e(app_url('certificate.php')) ?>">✧ &nbsp; Chứng chỉ</a>

            <div class="nav-label">Học tập</div>

            <a
                href="<?= e(app_url('student/dashboard.php')) ?>"
                class="nav-link"
            >
                <span class="nav-icon">&#9638;</span>
                Tổng quan
            </a>

            <a
                href="<?= e(app_url('student/my-courses.php')) ?>"
                class="nav-link active"
                aria-current="page"
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

            <div class="nav-label">Cá nhân</div>

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

        <div class="sidebar-bottom">

            <div class="user-mini">

                <div class="avatar">
                    <?= e($avatarLetter) ?>
                </div>

                <div class="user-info">
                    <strong>
                        <?= e($displayName) ?>
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
                    &#10140; Đăng xuất
                </button>
            </form>

        </div>

    </aside>

    <!-- =====================================
         MAIN CONTENT
    ====================================== -->

    <div class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <div style="
                display:flex;
                align-items:center;
                gap:15px;
            ">

                <button
                    id="mobileMenu"
                    type="button"
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

                <span class="topbar-name">
                    <?= e($displayName) ?>
                </span>

                <div class="topbar-avatar">
                    <?= e($avatarLetter) ?>
                </div>

            </div>

        </header>

        <main class="content">

            <!-- BREADCRUMB -->

            <nav class="breadcrumb">

                <a
                    href="<?= e(
                        app_url('student/dashboard.php')
                    ) ?>"
                >
                    Tổng quan
                </a>

                <span>/</span>

                <span class="breadcrumb-current">
                    Khóa học của tôi
                </span>

            </nav>

            <!-- PAGE HEADING -->

            <div class="page-heading">

                <div>

                    <h1>Khóa học của tôi</h1>

                    <p>
                        Quản lý khóa học đã đăng ký,
                        theo dõi tiến độ và tiếp tục
                        hành trình học tập của bạn.
                    </p>

                </div>

                <a
                    class="primary-btn"
                    href="<?= e(app_url('courses.php')) ?>"
                >
                    + Khám phá khóa học
                </a>

            </div>

            <!-- DATABASE ERROR -->

            <?php if ($dataError): ?>

                <div class="error-alert" role="alert">
                    Không thể tải dữ liệu khóa học.
                    Vui lòng kiểm tra MySQL và các
                    bảng, View của BrightFuture.
                </div>

            <?php endif; ?>

            <!-- =================================
                 STATISTICS
            ================================== -->

            <section class="stats-grid">

                <div class="stat-card">

                    <div>
                        <p>Tổng khóa học</p>
                        <strong>
                            <?= format_number(
                                $stats['total']
                            ) ?>
                        </strong>
                    </div>

                    <div class="stat-icon purple">
                        &#128218;
                    </div>

                </div>

                <div class="stat-card">

                    <div>
                        <p>Đang học</p>
                        <strong>
                            <?= format_number(
                                $stats['active']
                            ) ?>
                        </strong>
                    </div>

                    <div class="stat-icon blue">
                        &#128187;
                    </div>

                </div>

                <div class="stat-card">

                    <div>
                        <p>Đã hoàn thành</p>
                        <strong>
                            <?= format_number(
                                $stats['completed']
                            ) ?>
                        </strong>
                    </div>

                    <div class="stat-icon green">
                        &#10003;
                    </div>

                </div>

            </section>

            <!-- =================================
                 SEARCH & FILTER
            ================================== -->

            <section class="filter-panel">

                <form
                    method="GET"
                    action="<?= e(
                        app_url('student/my-courses.php')
                    ) ?>"
                    class="filter-form"
                >

                    <div class="search-box">

                        <span
                            class="search-icon"
                            aria-hidden="true"
                        >
                            &#9906;
                        </span>

                        <input
                            type="search"
                            name="q"
                            class="search-input"
                            placeholder="Tìm tên khóa học, danh mục, giáo viên..."
                            value="<?= e($search) ?>"
                            maxlength="100"
                            aria-label="Tìm kiếm khóa học"
                        >

                    </div>

                    <select
                        name="status"
                        class="filter-select"
                        aria-label="Lọc trạng thái"
                    >

                        <option
                            value="all"
                            <?= $status === 'all'
                                ? 'selected'
                                : '' ?>
                        >
                            Tất cả trạng thái
                        </option>

                        <option
                            value="active"
                            <?= $status === 'active'
                                ? 'selected'
                                : '' ?>
                        >
                            Đang học
                        </option>

                        <option
                            value="completed"
                            <?= $status === 'completed'
                                ? 'selected'
                                : '' ?>
                        >
                            Đã hoàn thành
                        </option>

                    </select>

                    <button
                        type="submit"
                        class="filter-submit"
                    >
                        Tìm kiếm
                    </button>

                </form>

                <div class="filter-result">

                    <span>
                        Tìm thấy
                        <strong>
                            <?= format_number(
                                $pagination['total_items']
                            ) ?>
                        </strong>
                        khóa học
                    </span>

                    <?php if (
                        $search !== ''
                        || $status !== 'all'
                    ): ?>

                        <a
                            href="<?= e(
                                app_url(
                                    'student/my-courses.php'
                                )
                            ) ?>"
                            class="clear-filter"
                        >
                            Xóa bộ lọc
                        </a>

                    <?php endif; ?>

                </div>

            </section>

            <!-- =================================
                 COURSE LIST
            ================================== -->

            <section class="course-grid">

                <?php if (empty($courses)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            &#128218;
                        </div>

                        <?php if (
                            $search !== ''
                            || $status !== 'all'
                        ): ?>

                            <h3>
                                Không tìm thấy khóa học phù hợp
                            </h3>

                            <p>
                                Hãy thử từ khóa khác hoặc
                                thay đổi bộ lọc trạng thái.
                            </p>

                            <a
                                class="primary-btn"
                                href="<?= e(
                                    app_url(
                                        'student/my-courses.php'
                                    )
                                ) ?>"
                            >
                                Xem tất cả khóa học
                            </a>

                        <?php else: ?>

                            <h3>
                                Bạn chưa đăng ký khóa học nào
                            </h3>

                            <p>
                                Hãy khám phá các khóa học
                                và bắt đầu học lập trình
                                cùng BrightFuture.
                            </p>

                            <a
                                class="primary-btn"
                                href="<?= e(
                                    app_url('courses.php')
                                ) ?>"
                            >
                                Khám phá khóa học
                            </a>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <?php foreach ($courses as $course): ?>

                        <?php
                            $courseId = (int) $course['id'];

                            $completed =
                                $course['enrollment_status']
                                === 'completed';

                            $progress = max(
                                0,
                                min(
                                    100,
                                    (float) $course[
                                        'progress_percent'
                                    ]
                                )
                            );

                            $thumbnail = mycourses_thumbnail(
                                $course['thumbnail']
                            );

                            $description = clean_text(
                                $course['description'] ?? ''
                            );

                            $description = truncate_text(
                                $description,
                                110
                            );
                        ?>

                        <article class="course-card">

                            <div class="course-image">

                                <?php if (
                                    $thumbnail !== null
                                ): ?>

                                    <img
                                        src="<?= e($thumbnail) ?>"
                                        alt="<?= e(
                                            $course['title']
                                        ) ?>"
                                        loading="lazy"
                                    >

                                <?php else: ?>

                                    <?= e(
                                        mycourses_icon(
                                            $course['category']
                                        )
                                    ) ?>

                                <?php endif; ?>

                                <span class="status-badge
                                    <?= $completed
                                        ? 'completed'
                                        : '' ?>"
                                >
                                    <?= $completed
                                        ? 'Đã hoàn thành'
                                        : 'Đang học' ?>
                                </span>

                            </div>

                            <div class="course-body">

                                <span class="course-category">
                                    <?= e(
                                        $course['category']
                                        ?: mycourses_level(
                                            $course['level']
                                        )
                                    ) ?>
                                </span>

                                <h3 class="course-title">

                                    <a
                                        href="<?= e(
                                            course_url($courseId)
                                        ) ?>"
                                    >
                                        <?= e(
                                            $course['title']
                                        ) ?>
                                    </a>

                                </h3>

                                <p class="course-teacher">
                                    Giảng viên:
                                    <?= e(
                                        $course['teacher_name']
                                    ) ?>
                                </p>

                                <p class="course-description">
                                    <?= e(
                                        $description !== ''
                                        ? $description
                                        : 'Khám phá nội dung và các bài học trong khóa học.'
                                    ) ?>
                                </p>

                                <div class="progress-row">

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
                                    aria-valuenow="<?= (int) round(
                                        $progress
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
                                        <?= e(
                                            mycourses_level(
                                                $course['level']
                                            )
                                        ) ?>
                                    </span>

                                </div>

                                <a
                                    class="course-action"
                                    href="<?= e(
                                        course_url($courseId)
                                    ) ?>"
                                >
                                    <?= $completed
                                        ? 'Xem lại khóa học'
                                        : 'Tiếp tục học' ?>
                                    &#8594;
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </section>

            <!-- =================================
                 PAGINATION
            ================================== -->

            <?php if (
                $pagination['total_pages'] > 1
            ): ?>

                <nav
                    class="pagination"
                    aria-label="Phân trang khóa học"
                >

                    <!-- PREVIOUS -->

                    <?php if (
                        $pagination['has_previous']
                    ): ?>

                        <a
                            class="page-link"
                            href="<?= e(
                                mycourses_url(
                                    $pagination['previous_page'],
                                    $search,
                                    $status
                                )
                            ) ?>"
                            aria-label="Trang trước"
                        >
                            &#8592;
                        </a>

                    <?php else: ?>

                        <span
                            class="page-link disabled"
                            aria-disabled="true"
                        >
                            &#8592;
                        </span>

                    <?php endif; ?>

                    <!-- NUMBERED PAGES -->

                    <?php
                        $currentPage =
                            $pagination['current_page'];

                        $totalPages =
                            $pagination['total_pages'];

                        $startPage = max(
                            1,
                            $currentPage - 2
                        );

                        $endPage = min(
                            $totalPages,
                            $startPage + 4
                        );

                        $startPage = max(
                            1,
                            $endPage - 4
                        );
                    ?>

                    <?php for (
                        $i = $startPage;
                        $i <= $endPage;
                        $i++
                    ): ?>

                        <a
                            href="<?= e(
                                mycourses_url(
                                    $i,
                                    $search,
                                    $status
                                )
                            ) ?>"
                            class="page-link
                                <?= $i === $currentPage
                                    ? 'active'
                                    : '' ?>"
                            <?= $i === $currentPage
                                ? 'aria-current="page"'
                                : '' ?>
                        >
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>

                    <!-- NEXT -->

                    <?php if (
                        $pagination['has_next']
                    ): ?>

                        <a
                            class="page-link"
                            href="<?= e(
                                mycourses_url(
                                    $pagination['next_page'],
                                    $search,
                                    $status
                                )
                            ) ?>"
                            aria-label="Trang tiếp theo"
                        >
                            &#8594;
                        </a>

                    <?php else: ?>

                        <span
                            class="page-link disabled"
                            aria-disabled="true"
                        >
                            &#8594;
                        </span>

                    <?php endif; ?>

                </nav>

            <?php endif; ?>

        </main>

    </div>

</div>

<!-- ==========================================
     JAVASCRIPT
=========================================== -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const sidebar =
        document.getElementById('sidebar');

    const menuButton =
        document.getElementById('mobileMenu');

    const overlay =
        document.getElementById('sidebarOverlay');

    function setMenuOpen(open) {

        sidebar.classList.toggle('open', open);

        overlay.classList.toggle('show', open);

        menuButton.setAttribute(
            'aria-expanded',
            String(open)
        );

        menuButton.setAttribute(
            'aria-label',
            open ? 'Đóng menu' : 'Mở menu'
        );
    }

    menuButton.addEventListener('click', function () {

        setMenuOpen(
            !sidebar.classList.contains('open')
        );
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
