<?php
/**
 * BrightFuture - Admin Dashboard
 * File: admin/dashboard.php | PHP 8.0+ / MySQL PDO
 */


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

try {
    $pdo = db();
    $queries = [
        'users' => "SELECT COUNT(CASE WHEN role = 'student' THEN 1 END) AS students, COUNT(CASE WHEN role = 'teacher' THEN 1 END) AS teachers, COUNT(CASE WHEN role = 'admin' THEN 1 END) AS admins FROM users",
        'courses' => "SELECT COUNT(*) AS courses, COUNT(CASE WHEN status = 'published' THEN 1 END) AS published_courses, COUNT(CASE WHEN status = 'draft' THEN 1 END) AS draft_courses FROM courses",
        'lessons' => "SELECT COUNT(*) AS lessons, COUNT(CASE WHEN status = 'published' THEN 1 END) AS published_lessons FROM lessons",
        'chapters' => 'SELECT COUNT(*) AS chapters FROM course_chapters',
        'exercises' => 'SELECT COUNT(*) AS exercises FROM exercises',
        'submissions' => "SELECT COUNT(*) AS submissions, COUNT(CASE WHEN status IN ('submitted','grading') THEN 1 END) AS pending_submissions FROM exercise_submissions",
        'enrollments' => "SELECT COUNT(*) AS enrollments FROM enrollments WHERE status IN ('active','completed')",
    ];
    foreach ($queries as $sql) {
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        foreach (($row ?: []) as $key => $value) {
            if (array_key_exists($key, $stats)) {
                $stats[$key] = (int) $value;
            }
        }
    }

    $latestUsers = $pdo->query(
        "SELECT id, full_name, email, role, created_at
         FROM users ORDER BY created_at DESC, id DESC LIMIT 6"
    )->fetchAll();

    $latestCourses = $pdo->query(
        "SELECT c.id, c.title, c.status, c.level, c.created_at,
                u.full_name AS teacher_name,
                (SELECT COUNT(*) FROM enrollments en
                   WHERE en.course_id = c.id AND en.status IN ('active','completed')) AS student_count
         FROM courses c INNER JOIN users u ON u.id = c.teacher_id
         ORDER BY c.created_at DESC, c.id DESC LIMIT 6"
    )->fetchAll();

    $latestSubmissions = $pdo->query(
        "SELECT es.id, es.status, es.score, es.submitted_at,
                st.full_name AS student_name, ex.title AS exercise_title,
                c.title AS course_title
         FROM exercise_submissions es
         INNER JOIN users st ON st.id = es.student_id
         INNER JOIN exercises ex ON ex.id = es.exercise_id
         INNER JOIN lessons l ON l.id = ex.lesson_id
         INNER JOIN course_chapters cc ON cc.id = l.chapter_id
         INNER JOIN courses c ON c.id = cc.course_id
         ORDER BY es.submitted_at DESC, es.id DESC LIMIT 6"
    )->fetchAll();

    // Daily registrations over the last 7 calendar days (including today).
    $stmt = $pdo->query(
        "SELECT DATE(created_at) AS day, COUNT(*) AS total
         FROM users
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY DATE(created_at)"
    );
    $dailyMap = [];
    foreach ($stmt->fetchAll() as $item) {
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
    error_log('[BrightFuture Admin Dashboard] ' . $exception->getMessage());
    $dataError = true;
    // Show a clear failure, not misleading zero values.
    $latestUsers = $latestCourses = $latestSubmissions = $registrationTrend = [];
}

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
$maxTrend = max(1, ...array_map(static fn(array $d): int => $d['count'], $registrationTrend));
$cards = [
    ['label' => 'Học viên', 'key' => 'students', 'icon' => '🎓', 'tone' => 'purple'],
    ['label' => 'Giáo viên', 'key' => 'teachers', 'icon' => '👩‍🏫', 'tone' => 'blue'],
    ['label' => 'Khóa học', 'key' => 'courses', 'icon' => '📚', 'tone' => 'teal'],
    ['label' => 'Bài giảng', 'key' => 'lessons', 'icon' => '📖', 'tone' => 'amber'],
    ['label' => 'Bài tập', 'key' => 'exercises', 'icon' => '📝', 'tone' => 'purple'],
    ['label' => 'Bài nộp', 'key' => 'submissions', 'icon' => '📩', 'tone' => 'blue'],
    ['label' => 'Chờ chấm', 'key' => 'pending_submissions', 'icon' => '⏳', 'tone' => 'amber'],
    ['label' => 'Lượt đăng ký học', 'key' => 'enrollments', 'icon' => '✅', 'tone' => 'teal'],
];
$menu = [
    ['label' => 'Tổng quan', 'path' => 'admin/dashboard.php', 'icon' => '▦', 'active' => true],
    ['label' => 'Người dùng', 'path' => 'admin/users.php', 'icon' => '♙'],
    ['label' => 'Khóa học', 'path' => 'admin/courses.php', 'icon' => '▤'],
    ['label' => 'Báo cáo', 'path' => 'admin/reports.php', 'icon' => '▥'],
    ['label' => 'Trang chủ', 'path' => '', 'icon' => '⌂'],
];
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Bảng điều khiển quản trị BrightFuture">
<title><?= e('Quản trị hệ thống | ' . APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--dark:#172033;--muted:#64748b;--border:#e7ebf2;--bg:#f6f8fc;--sidebar:255px}
*{box-sizing:border-box}html,body{margin:0}body{font:14px/1.6 'Segoe UI',Arial,sans-serif;color:var(--dark);background:var(--bg)}a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:white;border-right:1px solid var(--border);z-index:100;display:flex;flex-direction:column;transition:transform .25s}
.logo{display:flex;align-items:center;gap:10px;height:90px;padding:0 22px;font-size:21px;font-weight:800;color:var(--primary)}.logo-mark{display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:white;font-size:15px}
.nav{flex:1;overflow-y:auto;padding:10px 15px}.nav-title{font-size:11px;letter-spacing:1px;text-transform:uppercase;font-weight:700;color:#94a3b8;padding:14px 13px 9px}.nav a{display:flex;gap:12px;align-items:center;padding:12px 14px;color:var(--muted);border-radius:11px;margin-bottom:4px;font-weight:500}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--primary)}.nav a.active{font-weight:700}.nav-symbol{width:23px;text-align:center;font-size:18px}
.sidebar-foot{border-top:1px solid var(--border);padding:16px}.account{display:flex;align-items:center;gap:10px;margin-bottom:14px}.avatar{height:40px;width:40px;border-radius:12px;display:grid;place-items:center;background:#ede9fe;color:#6d28d9;font-weight:800;flex-shrink:0}.account-info{min-width:0}.account-info strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:175px}.account-info small{color:var(--muted)}.logout{width:100%;border:0;border-radius:10px;padding:11px;background:#fff1f2;color:#be123c;font-weight:700}.logout:hover{background:#ffe4e6}
.main{margin-left:var(--sidebar);min-width:0}.topbar{height:83px;background:white;border-bottom:1px solid var(--border);padding:0 36px;display:flex;align-items:center;justify-content:space-between}.topbar-right{display:flex;align-items:center;gap:12px}.topbar-name{font-weight:600}.topbar .avatar{border-radius:50%;width:37px;height:37px}.hamburger{display:none;border:0;background:transparent;font-size:25px}.content{max-width:1500px;margin:auto;padding:32px 36px 60px}
.banner{background:linear-gradient(110deg,#3730a3,#4f46e5 60%,#7c3aed);border-radius:20px;color:white;padding:30px 34px;margin-bottom:24px;position:relative;overflow:hidden}.banner:after{content:"";width:280px;height:280px;right:-85px;top:-135px;border-radius:50%;position:absolute;background:#ffffff12}.banner h1{font-size:clamp(22px,2.3vw,30px);line-height:1.25;margin:5px 0 12px}.banner p{color:#e0e7ff;margin:0;max-width:660px}.banner small{font-weight:700;color:#c7d2fe;letter-spacing:.06em}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:27px}.stat{display:flex;justify-content:space-between;gap:10px;background:white;border:1px solid var(--border);border-radius:16px;padding:19px;min-width:0}.stat p{color:var(--muted);font-size:13px;margin:0 0 7px}.stat strong{font-size:29px;line-height:1.3}.stat-icon{width:43px;height:43px;display:grid;place-items:center;border-radius:12px;font-size:21px;flex-shrink:0}.purple{background:#ede9fe}.blue{background:#dbeafe}.teal{background:#ccfbf1}.amber{background:#fef3c7}
.row-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:20px;margin-bottom:22px}.panel{background:white;border:1px solid var(--border);border-radius:17px;padding:22px;min-width:0}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.panel-heading h2{font-size:17px;margin:0;letter-spacing:-.35px}.panel-heading a{font-size:13px;font-weight:700;color:var(--primary)}.subtle{font-size:12px;color:var(--muted)}
.bars{display:flex;align-items:end;justify-content:space-between;gap:9px;height:192px;padding-top:12px}.bar-col{height:100%;flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:end;gap:7px}.bar-track{width:100%;max-width:45px;height:140px;display:flex;align-items:end;background:#f4f5ff;border-radius:8px 8px 0 0;overflow:hidden}.bar-fill{width:100%;min-height:0;background:linear-gradient(180deg,#a78bfa,#4f46e5);border-radius:7px 7px 0 0}.bar-col span{font-size:11px;color:var(--muted)}.bar-col strong{font-size:11px}
.short-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.quick-link{border:1px solid var(--border);padding:14px;border-radius:12px;display:flex;align-items:center;gap:11px;font-weight:600}.quick-link:hover{border-color:#a5b4fc;background:#f8faff;color:var(--primary)}.quick-icon{font-size:23px}.ministats{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px;color:var(--muted);font-size:12px}.ministats strong{color:var(--primary)}
.table-wrap{overflow-x:auto}.table{border-collapse:collapse;width:100%;text-align:left}.table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;font-weight:700;background:#f8fafc}.table td,.table th{padding:12px;border-bottom:1px solid var(--border);vertical-align:middle}.table td{font-size:12px}.table tr:last-child td{border-bottom:0}.name{font-weight:700}.secondary{font-size:11px;color:var(--muted);margin-top:3px}.chip{display:inline-block;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:700;white-space:nowrap}.chip.success{background:#dcfce7;color:#166534}.chip.warning{background:#fef3c7;color:#92400e}.chip.danger{background:#ffe4e6;color:#be123c}.chip.neutral{background:#f1f5f9;color:#475569}.empty{padding:35px 15px;text-align:center;color:var(--muted);font-size:13px}.alert{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:16px 18px;border-radius:12px;margin-bottom:24px}.error-note{font-size:13px;color:var(--muted);margin:0}.muted-link{color:var(--primary);font-weight:700}.overlay{display:none}
@media(max-width:1250px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.row-grid{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.overlay.open{display:block;position:fixed;inset:0;z-index:90;background:#0f172a80}.main{margin-left:0}.hamburger{display:block}.topbar{height:70px;padding:0 22px}.content{padding:24px 22px}}
@media(max-width:590px){.stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.stat{padding:15px}.stat strong{font-size:23px}.stat-icon{width:34px;height:34px;font-size:18px}.content{padding:16px 14px 35px}.topbar{padding:0 14px}.topbar-name{display:none}.banner{padding:25px 20px}.panel{padding:17px}.short-grid{grid-template-columns:1fr}.table{min-width:540px}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div id="overlay" class="overlay" aria-hidden="true"></div>
<aside id="sidebar" class="sidebar" aria-label="Menu quản trị">
    <a class="logo" href="<?= e(app_url()) ?>"><span class="logo-mark">&lt;/&gt;</span> BrightFuture</a>
    <nav class="nav" aria-label="Điều hướng">
        <div class="nav-title">Quản trị hệ thống</div>
        <?php foreach ($menu as $item): ?>
        <a href="<?= e(app_url($item['path'])) ?>" class="<?= !empty($item['active']) ? 'active' : '' ?>" <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>><span class="nav-symbol" aria-hidden="true"><?= e($item['icon']) ?></span><?= e($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <div class="account"><span class="avatar"><?= e(admin_initial((string)$user['full_name'])) ?></span><span class="account-info"><strong><?= e((string)$user['full_name']) ?></strong><small>Quản trị viên</small></span></div>
        <form method="POST" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">↪ Đăng xuất</button></form>
    </div>
</aside>
<div class="main">
    <header class="topbar"><div style="display:flex;align-items:center;gap:12px"><button type="button" class="hamburger" id="menuToggle" aria-label="Mở menu" aria-controls="sidebar" aria-expanded="false">☰</button><strong style="color:var(--muted)">Không gian quản trị</strong></div><div class="topbar-right"><span class="topbar-name"><?= e((string)$user['full_name']) ?></span><span class="avatar"><?= e(admin_initial((string)$user['full_name'])) ?></span></div></header>
    <main class="content">
        <div class="banner"><small>BRIGHTFUTURE · ADMIN PANEL</small><h1>Xin chào, <?= e((string)$user['full_name']) ?>!</h1><p>Tổng quan người dùng, khóa học, hoạt động giảng dạy và tình hình học tập trên toàn hệ thống.</p></div>
        <?php if ($dataError): ?>
            <div class="alert" role="alert"><strong>Không thể tải dữ liệu quản trị.</strong> Vui lòng kiểm tra MySQL và các bảng trong <code>schema.sql</code>, <code>phase2.sql</code>, <code>phase3.sql</code>. Xem PHP error log để biết nguyên nhân.</div>
        <?php else: ?>
        <section class="stats" aria-label="Thống kê hệ thống">
            <?php foreach ($cards as $card): ?>
            <article class="stat"><div><p><?= e($card['label']) ?></p><strong><?= format_number($stats[$card['key']]) ?></strong></div><span class="stat-icon <?= e($card['tone']) ?>" aria-hidden="true"><?= e($card['icon']) ?></span></article>
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
        <?php endif; ?>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    function openMenu(open) {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Đóng menu' : 'Mở menu');
    }
    toggle.addEventListener('click', () => openMenu(!sidebar.classList.contains('open')));
    overlay.addEventListener('click', () => openMenu(false));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') openMenu(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) openMenu(false); });
});
</script>
<script>window.BF_LEGACY={role:'admin',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
