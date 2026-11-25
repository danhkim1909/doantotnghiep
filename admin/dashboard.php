<?php

require_once __DIR__ . '/../includes/permissions.php';
$user = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$stats = [
    'students' => 0, 'teachers' => 0, 'admins' => 0,
    'courses' => 0, 'published_courses' => 0, 'draft_courses' => 0,
    'chapters' => 0, 'lessons' => 0, 'published_lessons' => 0,
    'exercises' => 0, 'submissions' => 0, 'pending_submissions' => 0,
    'enrollments' => 0,
];
$latestUsers = [];
$latestCourses = [];
$latestSubmissions = [];
$registrationTrend = [];
$dataError = false;
$failedSections = [];
$pdo = null;

// One failing query must never hide all the other dashboard sections.
try {
    $pdo = db();
} catch (Throwable $exception) {
    error_log('[BrightFuture Admin Dashboard] [connection] ' . $exception->getMessage());
    $failedSections[] = 'Kết nối cơ sở dữ liệu';
}

if ($pdo instanceof PDO) {
    $queries = [
        'users' => "SELECT COUNT(CASE WHEN role = 'student' THEN 1 END) AS students, COUNT(CASE WHEN role = 'teacher' THEN 1 END) AS teachers, COUNT(CASE WHEN role = 'admin' THEN 1 END) AS admins FROM users",
        'courses' => "SELECT COUNT(*) AS courses, COUNT(CASE WHEN status = 'published' THEN 1 END) AS published_courses, COUNT(CASE WHEN status = 'draft' THEN 1 END) AS draft_courses FROM courses",
        'lessons' => "SELECT COUNT(*) AS lessons, COUNT(CASE WHEN status = 'published' THEN 1 END) AS published_lessons FROM lessons",
        'chapters' => 'SELECT COUNT(*) AS chapters FROM course_chapters',
        'exercises' => 'SELECT COUNT(*) AS exercises FROM exercises',
        'submissions' => "SELECT COUNT(*) AS submissions, COUNT(CASE WHEN status IN ('submitted','grading') THEN 1 END) AS pending_submissions FROM exercise_submissions",
        'enrollments' => "SELECT COUNT(*) AS enrollments FROM enrollments WHERE status IN ('active','completed')",
    ];
    $labels = [
        'users' => 'Thống kê tài khoản',
        'courses' => 'Thống kê khóa học',
        'lessons' => 'Thống kê bài giảng',
        'chapters' => 'Thống kê chương học',
        'exercises' => 'Thống kê bài tập',
        'submissions' => 'Thống kê bài nộp',
        'enrollments' => 'Thống kê ghi danh',
    ];
    foreach ($queries as $section => $sql) {
        try {
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            foreach (($row ?: []) as $key => $value) {
                if (array_key_exists($key, $stats)) {
                    $stats[$key] = (int) $value;
                }
            }
        } catch (Throwable $exception) {
            error_log('[BrightFuture Admin Dashboard] [' . $section . '] ' . $exception->getMessage());
            $failedSections[] = $labels[$section];
        }
    }

    $sections = [
        'Người dùng mới' => [
            "SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 6",
            'latestUsers'
        ],
        'Khóa học mới' => [
            "SELECT c.id, c.title, c.status, c.level, c.created_at,
                u.full_name AS teacher_name,
                (SELECT COUNT(*) FROM enrollments en WHERE en.course_id = c.id AND en.status IN ('active','completed')) AS student_count
             FROM courses c INNER JOIN users u ON u.id = c.teacher_id
             ORDER BY c.created_at DESC, c.id DESC LIMIT 6",
            'latestCourses'
        ],
        'Bài nộp gần đây' => [
            "SELECT es.id, es.status, es.score, es.submitted_at,
                st.full_name AS student_name, ex.title AS exercise_title,
                c.title AS course_title
             FROM exercise_submissions es
             INNER JOIN users st ON st.id = es.student_id
             INNER JOIN exercises ex ON ex.id = es.exercise_id
             INNER JOIN lessons l ON l.id = ex.lesson_id
             INNER JOIN course_chapters cc ON cc.id = l.chapter_id
             INNER JOIN courses c ON c.id = cc.course_id
             ORDER BY es.submitted_at DESC, es.id DESC LIMIT 6",
            'latestSubmissions'
        ],
    ];
    foreach ($sections as $name => $definition) {
        try {
            ${$definition[1]} = $pdo->query($definition[0])->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('[BrightFuture Admin Dashboard] [' . $name . '] ' . $exception->getMessage());
            $failedSections[] = $name;
        }
    }

    try {
        $stmt = $pdo->query(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM users
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
               AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
             GROUP BY DATE(created_at)"
        );
        $dailyMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $dailyMap[(string) $item['day']] = (int) $item['total'];
        }
        $today = new DateTimeImmutable('today');
        for ($i = 6; $i >= 0; $i--) {
            $day = $today->modify('-' . $i . ' days');
            $key = $day->format('Y-m-d');
            $registrationTrend[] = [
                'day' => $day->format('d/m'),
                'count' => $dailyMap[$key] ?? 0,
            ];
        }
    } catch (Throwable $exception) {
        error_log('[BrightFuture Admin Dashboard] [registrationTrend] ' . $exception->getMessage());
        $failedSections[] = 'Biểu đồ tài khoản mới';
    }
}
$dataError = count($failedSections) > 0;

function admin_role_label(string $role): string
{
    return match ($role) {
        ROLE_STUDENT => 'Học viên',
        ROLE_TEACHER => 'Giáo viên',
        ROLE_ADMIN => 'Quản trị viên',
        default => 'Không xác định',
    };
}
function admin_status_label(string $status): string
{
    return match ($status) {
        'published' => 'Công khai', 'draft' => 'Bản nháp',
        'submitted' => 'Chờ chấm', 'grading' => 'Đang chấm',
        'graded' => 'Đã chấm', 'returned' => 'Trả bổ sung',
        default => 'Khác',
    };
}
function admin_status_class(string $status): string
{
    return match ($status) {
        'published', 'graded' => 'success',
        'submitted', 'grading' => 'warning',
        'returned' => 'danger',
        default => 'neutral',
    };
}
function admin_initial(string $fullName): string
{
    $fullName = trim($fullName);
    return $fullName === '' ? '?' : (function_exists('mb_substr')
        ? mb_substr($fullName, 0, 1, 'UTF-8') : substr($fullName, 0, 1));
}
$maxTrend = max(array_merge([1], array_map(static fn(array $d): int => (int) $d['count'], $registrationTrend)));
$cards = [
    ['label' => 'Học viên', 'key' => 'students', 'icon' => 'users', 'tone' => 'purple'],
    ['label' => 'Giáo viên', 'key' => 'teachers', 'icon' => 'teaching', 'tone' => 'blue'],
    ['label' => 'Khóa học', 'key' => 'courses', 'icon' => 'courses', 'tone' => 'teal'],
    ['label' => 'Bài giảng', 'key' => 'lessons', 'icon' => 'lesson', 'tone' => 'amber'],
    ['label' => 'Bài tập', 'key' => 'exercises', 'icon' => 'exercises', 'tone' => 'purple'],
    ['label' => 'Bài nộp', 'key' => 'submissions', 'icon' => 'code', 'tone' => 'blue'],
    ['label' => 'Chờ chấm', 'key' => 'pending_submissions', 'icon' => 'progress', 'tone' => 'amber'],
    ['label' => 'Lượt đăng ký học', 'key' => 'enrollments', 'icon' => 'check', 'tone' => 'teal'],
];
$menu = [
    ['label' => 'Tổng quan', 'path' => 'admin/dashboard.php', 'icon' => '▦', 'active' => true],
    ['label' => 'Người dùng', 'path' => 'admin/users.php', 'icon' => '♙'],
    ['label' => 'Khóa học', 'path' => 'admin/courses.php', 'icon' => '▤'],
    ['label' => 'Báo cáo', 'path' => 'admin/reports.php', 'icon' => '▥'],
    ['label' => 'Trang chủ', 'path' => '', 'icon' => '⌂'],
];
$pageTitle = 'Quản trị hệ thống';
$pageDescription = 'Bảng điều khiển quản trị BrightFuture';
$activeMenu = 'dashboard';
$extraCss = ['css/bf-admin-dashboard.css'];

require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, 'dashboard');
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
    <main class="content">
        <div class="banner"><small>BRIGHTFUTURE · ADMIN PANEL</small><h1>Xin chào, <?= e((string)$user['full_name']) ?>!</h1><p>Tổng quan người dùng, khóa học, hoạt động giảng dạy và tình hình học tập trên toàn hệ thống.</p></div>
        <?php if ($dataError): ?>
            <div class="alert" role="alert"><strong>Một số phần thống kê chưa tải được:</strong>
                <?= e(implode('; ', array_unique($failedSections))) ?>.
                Các phần còn lại vẫn được hiển thị; số 0 ở nhóm gặp lỗi chưa được xác minh.
                Hãy kiểm tra PHP error log để biết nguyên nhân chi tiết.
            </div>
        <?php endif; ?>
        <section class="stats" aria-label="Thống kê hệ thống">
            <?php foreach ($cards as $card): ?>
            <article class="stat"><div><p><?= e($card['label']) ?></p><strong><?= format_number($stats[$card['key']]) ?></strong></div><span class="stat-icon <?= e($card['tone']) ?>" aria-hidden="true"><?= bf_icon($card['icon']) ?></span></article>
            <?php endforeach; ?>
        </section>
        <div class="row-grid">
            <section class="panel">
                <div class="panel-heading"><div><h2>Đăng ký tài khoản 7 ngày gần đây</h2><div class="subtle">Số tài khoản mới theo ngày</div></div></div>
                <div class="bars" role="img" aria-label="Biểu đồ số tài khoản mới trong 7 ngày">
                    <?php foreach ($registrationTrend as $point): ?>
                    <?php $height = $point['count'] === 0 ? 0 : max(4, $point['count'] / $maxTrend * 100); ?>
                    <div class="bar-col" title="<?= e($point['day'] . ': ' . $point['count'] . ' tài khoản') ?>"><strong><?= format_number($point['count']) ?></strong><div class="bar-track"><div class="bar-fill" style="height:<?= e((string)number_format($height, 2, '.', '')) ?>%"></div></div><span><?= e($point['day']) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="panel">
                <div class="panel-heading"><h2>Quản lý nhanh</h2></div>
                <div class="short-grid">
                    <a class="quick-link" href="<?= e(app_url('admin/users.php')) ?>"><span class="quick-icon">👥</span> Người dùng →</a>
                    <a class="quick-link" href="<?= e(app_url('admin/courses.php')) ?>"><span class="quick-icon">📚</span> Khóa học →</a>
                    <a class="quick-link" href="<?= e(app_url('admin/reports.php')) ?>"><span class="quick-icon">📊</span> Báo cáo →</a>
                    <a class="quick-link" href="<?= e(app_url()) ?>"><span class="quick-icon">🏠</span> Trang chủ →</a>
                </div>
                <div class="ministats"><span>Admin: <strong><?= format_number($stats['admins']) ?></strong></span><span>Chương: <strong><?= format_number($stats['chapters']) ?></strong></span><span>Khóa công khai: <strong><?= format_number($stats['published_courses']) ?></strong></span><span>Bài giảng công khai: <strong><?= format_number($stats['published_lessons']) ?></strong></span></div>
            </section>
        </div>
        <div class="row-grid">
            <section class="panel">
                <div class="panel-heading"><h2>Tài khoản mới nhất</h2><a href="<?= e(app_url('admin/users.php')) ?>">Xem tất cả →</a></div>
                <?php if ($latestUsers === []): ?><div class="empty">Chưa có tài khoản nào.</div><?php else: ?>
                <div class="table-wrap"><table class="table"><thead><tr><th>Người dùng</th><th>Vai trò</th><th>Ngày tạo</th></tr></thead><tbody>
                <?php foreach ($latestUsers as $item): ?><tr><td><div class="name"><?= e((string)$item['full_name']) ?></div><div class="secondary"><?= e((string)$item['email']) ?></div></td><td><?= e(admin_role_label((string)$item['role'])) ?></td><td><?= e(format_datetime((string)$item['created_at'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-heading"><h2>Khóa học mới nhất</h2><a href="<?= e(app_url('admin/courses.php')) ?>">Xem tất cả →</a></div>
                <?php if ($latestCourses === []): ?><div class="empty">Chưa có khóa học nào.</div><?php else: ?>
                <div class="table-wrap"><table class="table"><thead><tr><th>Khóa học</th><th>Học viên</th><th>Trạng thái</th></tr></thead><tbody>
                <?php foreach ($latestCourses as $item): ?><tr><td><div class="name"><?= e((string)$item['title']) ?></div><div class="secondary">GV: <?= e((string)$item['teacher_name']) ?></div></td><td><?= format_number((int)$item['student_count']) ?></td><td><span class="chip <?= e(admin_status_class((string)$item['status'])) ?>"><?= e(admin_status_label((string)$item['status'])) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>
        </div>
        <section class="panel">
            <div class="panel-heading"><div><h2>Bài nộp gần đây</h2><div class="subtle">Theo thời điểm học viên nộp bài</div></div><a href="<?= e(app_url('admin/reports.php')) ?>">Báo cáo →</a></div>
            <?php if ($latestSubmissions === []): ?><div class="empty">Chưa có bài nộp nào.</div><?php else: ?>
            <div class="table-wrap"><table class="table"><thead><tr><th>Học viên</th><th>Bài tập / Khóa học</th><th>Trạng thái</th><th>Điểm</th><th>Thời gian</th></tr></thead><tbody>
                <?php foreach ($latestSubmissions as $item): ?><tr><td class="name"><?= e((string)$item['student_name']) ?></td><td><div class="name"><?= e((string)$item['exercise_title']) ?></div><div class="secondary"><?= e((string)$item['course_title']) ?></div></td><td><span class="chip <?= e(admin_status_class((string)$item['status'])) ?>"><?= e(admin_status_label((string)$item['status'])) ?></span></td><td><?= $item['score'] === null ? '—' : e(format_number((float)$item['score'], 2)) ?></td><td><?= e(format_datetime((string)$item['submitted_at'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    </main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
