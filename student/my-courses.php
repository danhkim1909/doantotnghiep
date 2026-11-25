
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
require_once __DIR__ . '/../includes/portal_ui.php';

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

$pageTitle = 'Khóa học của tôi';
$pageDescription = 'Danh sách các khóa học đã đăng ký của bạn trên BrightFuture';
$activeMenu = 'my_courses';
$extraCss = ['css/bf-student-my-courses.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, 'my_courses');
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
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
                        <?= bf_icon('book') ?>
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
                        <?= bf_icon('laptop') ?>
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
                        <?= bf_icon('check') ?>
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
                            <?= bf_icon('search') ?>
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

                    <div style="grid-column: 1 / -1; width: 100%;">
                        <?php if ($search !== '' || $status !== 'all'): ?>
                            <?= bf_empty_state(
                                'Không tìm thấy khóa học phù hợp',
                                'Hãy thử từ khóa khác hoặc thay đổi bộ lọc trạng thái để xem khóa học.',
                                'student/my-courses.php',
                                'Xem tất cả khóa học của tôi',
                                'search'
                            ) ?>
                        <?php else: ?>
                            <?= bf_empty_state(
                                'Bạn chưa đăng ký khóa học nào',
                                'Hãy khám phá các khóa học lập trình chất lượng và bắt đầu học tập cùng BrightFuture ngay hôm nay.',
                                'courses.php',
                                'Khám phá khóa học ngay',
                                'courses'
                            ) ?>
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
                                        decoding="async"
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
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>