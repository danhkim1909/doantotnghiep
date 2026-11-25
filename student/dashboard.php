
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
require_once __DIR__ . '/../includes/portal_ui.php';

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
$currentStreak = 0;
$weekTotalActivities = 0;
$weekActivityData = [];
$maxDayCount = 1;

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

    // -------------------------------------------------
    // 3.6. CHUỖI NGÀY HỌC TẬP (STREAK) & HOẠT ĐỘNG TUẦN
    // -------------------------------------------------

    $stmtActivity = $pdo->prepare(
        "SELECT DISTINCT act_date FROM (
            SELECT DATE(updated_at) AS act_date
            FROM lesson_progress
            WHERE student_id = :student_id AND status IN ('in_progress', 'completed')
            UNION
            SELECT DATE(submitted_at) AS act_date
            FROM exercise_submissions
            WHERE student_id = :student_id
        ) AS acts
        ORDER BY act_date DESC"
    );

    $stmtActivity->execute([
        'student_id' => $studentId
    ]);

    $activityDates = $stmtActivity->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $activitySet = array_flip($activityDates);

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $currentStreak = 0;
    $checkDate = isset($activitySet[$today]) ? $today : (isset($activitySet[$yesterday]) ? $yesterday : null);

    if ($checkDate !== null) {
        while (isset($activitySet[$checkDate])) {
            $currentStreak++;
            $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
        }
    }

    $monday = date('Y-m-d', strtotime('monday this week'));
    $stmtWeek = $pdo->prepare(
        "SELECT act_date, COUNT(*) AS total FROM (
            SELECT DATE(updated_at) AS act_date
            FROM lesson_progress
            WHERE student_id = :student_id AND updated_at >= :start_date AND status IN ('in_progress', 'completed')
            UNION ALL
            SELECT DATE(submitted_at) AS act_date
            FROM exercise_submissions
            WHERE student_id = :student_id AND submitted_at >= :start_date
        ) AS week_acts
        GROUP BY act_date"
    );

    $stmtWeek->execute([
        'student_id' => $studentId,
        'start_date' => $monday . ' 00:00:00'
    ]);

    $weeklyCounts = $stmtWeek->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $daysOfWeek = [
        ['label' => 'T2', 'full' => 'Thứ Hai'],
        ['label' => 'T3', 'full' => 'Thứ Ba'],
        ['label' => 'T4', 'full' => 'Thứ Tư'],
        ['label' => 'T5', 'full' => 'Thứ Năm'],
        ['label' => 'T6', 'full' => 'Thứ Sáu'],
        ['label' => 'T7', 'full' => 'Thứ Bảy'],
        ['label' => 'CN', 'full' => 'Chủ Nhật'],
    ];

    $weekActivityData = [];
    $maxDayCount = 1;
    $weekTotalActivities = 0;

    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($monday . " +$i days"));
        $cnt = (int) ($weeklyCounts[$d] ?? 0);
        $weekTotalActivities += $cnt;
        if ($cnt > $maxDayCount) {
            $maxDayCount = $cnt;
        }
        $weekActivityData[] = [
            'date' => $d,
            'label' => $daysOfWeek[$i]['label'],
            'full' => $daysOfWeek[$i]['full'],
            'count' => $cnt,
            'is_today' => ($d === $today),
            'is_future' => ($d > $today),
        ];
    }

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
    $currentStreak = 0;
    $weekTotalActivities = 0;
    $weekActivityData = [];
    $maxDayCount = 1;
}

if (empty($weekActivityData)) {
    $daysOfWeek = [
        ['label' => 'T2', 'full' => 'Thứ Hai'],
        ['label' => 'T3', 'full' => 'Thứ Ba'],
        ['label' => 'T4', 'full' => 'Thứ Tư'],
        ['label' => 'T5', 'full' => 'Thứ Năm'],
        ['label' => 'T6', 'full' => 'Thứ Sáu'],
        ['label' => 'T7', 'full' => 'Thứ Bảy'],
        ['label' => 'CN', 'full' => 'Chủ Nhật'],
    ];
    $today = date('Y-m-d');
    $monday = date('Y-m-d', strtotime('monday this week'));
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($monday . " +$i days"));
        $weekActivityData[] = [
            'date' => $d,
            'label' => $daysOfWeek[$i]['label'],
            'full' => $daysOfWeek[$i]['full'],
            'count' => 0,
            'is_today' => ($d === $today),
            'is_future' => ($d > $today),
        ];
    }
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
$pageTitle = 'Tổng quan học tập';
$pageDescription = 'Trang quản lý học tập dành cho học viên BrightFuture.';
$activeMenu = 'dashboard';
$extraCss = ['css/bf-student-dashboard.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, 'dashboard');
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
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
                        <?= bf_icon('courses') ?>
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
                        <?= bf_icon('lesson') ?>
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
                        <?= bf_icon('check') ?>
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
                        <?= bf_icon('certificate') ?>
                    </span>

                </div>

            </section>

            <!-- ==================================
                 CHUỖI HỌC TẬP & HOẠT ĐỘNG TRONG TUẦN
            =================================== -->

            <section class="streak-activity-section" aria-label="Chuỗi ngày học tập và hoạt động trong tuần">

                <div class="streak-card">

                    <div class="streak-badge-icon">
                        <span class="streak-flame">
                            <?= bf_icon('fire', 'bf-flame-icon') ?>
                        </span>
                    </div>

                    <div class="streak-info">

                        <div class="streak-header">
                            <span class="streak-tag">Kỷ luật học tập</span>
                            <?php if ($currentStreak > 0): ?>
                                <span class="streak-status-pill active">
                                    Đang duy trì 🔥
                                </span>
                            <?php else: ?>
                                <span class="streak-status-pill">
                                    Sẵn sàng bắt đầu
                                </span>
                            <?php endif; ?>
                        </div>

                        <h2 class="streak-title">
                            <?= $currentStreak > 0
                                ? e($currentStreak . ' Ngày liên tục')
                                : 'Bắt đầu chuỗi học tập hôm nay' ?>
                        </h2>

                        <p class="streak-desc">
                            <?= $currentStreak > 0
                                ? 'Xuất sắc! Tiếp tục hoàn thành ít nhất 1 bài học mỗi ngày để duy trì chuỗi thành tích.'
                                : 'Học tập đều đặn giúp bạn ghi nhớ sâu hơn 80%. Hoàn thành một bài học để kích hoạt ngọn lửa!' ?>
                        </p>

                    </div>

                </div>

                <div class="activity-chart-card">

                    <div class="activity-chart-header">
                        <div>
                            <h3 class="activity-chart-title">Hoạt động trong tuần</h3>
                            <span class="activity-chart-sub">Thứ 2 — Chủ nhật</span>
                        </div>

                        <div class="activity-total-badge">
                            <strong><?= format_number($weekTotalActivities) ?></strong>
                            <span>hoạt động</span>
                        </div>
                    </div>

                    <div class="activity-bars-grid" role="group" aria-label="Biểu đồ hoạt động từng ngày trong tuần">
                        <?php foreach ($weekActivityData as $day): ?>
                            <?php
                                $heightPercent = $day['count'] > 0
                                    ? max(18, min(100, round(($day['count'] / $maxDayCount) * 100)))
                                    : 8;
                            ?>
                            <div class="activity-day-col <?= $day['is_today'] ? 'is-today' : '' ?> <?= $day['is_future'] ? 'is-future' : '' ?>"
                                 title="<?= e($day['full']) ?> (<?= e($day['date']) ?>): <?= $day['count'] ?> hoạt động">
                                <div class="activity-bar-track">
                                    <div class="activity-bar-fill <?= $day['count'] > 0 ? 'has-activity' : '' ?>"
                                         style="height: <?= $heightPercent ?>%;">
                                        <?php if ($day['count'] > 0): ?>
                                            <span class="activity-bar-count"><?= $day['count'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="activity-day-label"><?= e($day['label']) ?></span>
                                <?php if ($day['is_today']): ?>
                                    <span class="activity-today-indicator" title="Hôm nay">•</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

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
                                <?= bf_icon('courses') ?>
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
                                <?= bf_icon('courses') ?>
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
                                        ? bf_icon('video')
                                        : bf_icon('code') ?>
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
                                <?= bf_icon('search') ?>
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
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
