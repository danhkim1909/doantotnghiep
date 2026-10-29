<?php
/** BrightFuture | teacher/dashboard.php | PHP 8+, PDO MySQL */

require_once __DIR__ . '/../includes/permissions.php';

$user = require_role(ROLE_TEACHER);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
$teacherId = (int) $user['id'];
$stats = ['courses'=>0,'published'=>0,'drafts'=>0,'students'=>0,'pending'=>0,'grading'=>0,'submissions'=>0];
$courses = [];
$pending = [];
$errorLoading = false;

function teacher_level(?string $level): string {
    return match ($level) {
        'beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp',
        'advanced' => 'Nâng cao', default => 'Chưa phân loại'
    };
}
function teacher_date(?string $value): string {
    return $value ? format_datetime($value) : '—';
}
function teacher_initial(string $name): string {
    return function_exists('mb_substr') ? mb_substr(trim($name), 0, 1, 'UTF-8') : substr(trim($name), 0, 1);
}
function teacher_thumbnail(?string $path): ?string {
    if (!$path) return null;
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '..') || str_starts_with($path, '/')) return null;
    if (!preg_match('~^(?:uploads/courses/)?[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)$~i', $path)) return null;
    if (!str_starts_with($path, 'uploads/courses/')) $path = 'uploads/courses/' . $path;
    return app_url($path);
}

try {
    $pdo = db();
    // Each aggregate is constrained to courses owned by the logged-in teacher.
    $s = $pdo->prepare("SELECT COUNT(*) AS courses,
            COALESCE(SUM(status = 'published'),0) AS published,
            COALESCE(SUM(status = 'draft'),0) AS drafts
        FROM courses WHERE teacher_id = :teacher_id");
    $s->execute(['teacher_id' => $teacherId]);
    $counts = $s->fetch() ?: [];
    foreach (['courses','published','drafts'] as $key) $stats[$key] = (int)($counts[$key] ?? 0);

    $s = $pdo->prepare("SELECT COUNT(DISTINCT en.student_id)
        FROM enrollments en
        INNER JOIN courses c ON c.id = en.course_id
        WHERE c.teacher_id = :teacher_id AND en.status IN ('active','completed')");
    $s->execute(['teacher_id' => $teacherId]);
    $stats['students'] = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT
            COUNT(*) AS submissions,
            COALESCE(SUM(s.status = 'submitted'),0) AS pending,
            COALESCE(SUM(s.status = 'grading'),0) AS grading
        FROM exercise_submissions s
        INNER JOIN exercises ex ON ex.id = s.exercise_id
        INNER JOIN lessons l ON l.id = ex.lesson_id
        INNER JOIN course_chapters ch ON ch.id = l.chapter_id
        INNER JOIN courses c ON c.id = ch.course_id
        WHERE c.teacher_id = :teacher_id");
    $s->execute(['teacher_id' => $teacherId]);
    $result = $s->fetch() ?: [];
    foreach (['submissions','pending','grading'] as $key) $stats[$key] = (int)($result[$key] ?? 0);

    // Separate per-course aggregates so enrollment and lesson joins do not multiply counts.
    $s = $pdo->prepare("SELECT c.id, c.title, c.description, c.category, c.level,
            c.thumbnail, c.status, c.created_at, c.updated_at,
            COALESCE(en.students, 0) AS students,
            COALESCE(ls.lessons, 0) AS lessons,
            COALESCE(ex.exercises, 0) AS exercises
        FROM courses c
        LEFT JOIN (
            SELECT course_id, COUNT(*) AS students FROM enrollments
            WHERE status IN ('active','completed') GROUP BY course_id
        ) en ON en.course_id = c.id
        LEFT JOIN (
            SELECT ch.course_id, COUNT(l.id) AS lessons
            FROM course_chapters ch LEFT JOIN lessons l ON l.chapter_id = ch.id
            GROUP BY ch.course_id
        ) ls ON ls.course_id = c.id
        LEFT JOIN (
            SELECT ch.course_id, COUNT(ex.id) AS exercises
            FROM course_chapters ch
            INNER JOIN lessons l ON l.chapter_id = ch.id
            INNER JOIN exercises ex ON ex.lesson_id = l.id
            GROUP BY ch.course_id
        ) ex ON ex.course_id = c.id
        WHERE c.teacher_id = :teacher_id
        ORDER BY c.updated_at DESC, c.id DESC LIMIT 6");
    $s->execute(['teacher_id' => $teacherId]);
    $courses = $s->fetchAll();

    $s = $pdo->prepare("SELECT s.id, s.submitted_at, s.attempt_number,
            u.full_name AS student_name, ex.title AS exercise_title,
            c.title AS course_title, c.id AS course_id
        FROM exercise_submissions s
        INNER JOIN users u ON u.id = s.student_id
        INNER JOIN exercises ex ON ex.id = s.exercise_id
        INNER JOIN lessons l ON l.id = ex.lesson_id
        INNER JOIN course_chapters ch ON ch.id = l.chapter_id
        INNER JOIN courses c ON c.id = ch.course_id
        WHERE c.teacher_id = :teacher_id AND s.status = 'submitted'
        ORDER BY s.submitted_at ASC, s.id ASC LIMIT 6");
    $s->execute(['teacher_id' => $teacherId]);
    $pending = $s->fetchAll();
} catch (Throwable $ex) {
    error_log('[BrightFuture Teacher Dashboard] ' . $ex->getMessage());
    $errorLoading = true;
    $stats = array_fill_keys(array_keys($stats), 0);
    $courses = $pending = [];
}
$pageTitle = 'Tổng quan giảng dạy | ' . APP_NAME;
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Tổng quan giảng dạy BrightFuture">
<title><?= e($pageTitle) ?></title>
<style>
:root{--primary:#4f46e5;--purple:#7c3aed;--bg:#f6f8fc;--text:#172033;--muted:#64748b;--border:#e7ebf2;--side:255px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Segoe UI',Arial,sans-serif}a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--side);background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:20;transition:transform .25s}.logo{height:90px;display:flex;align-items:center;padding:0 23px;gap:11px;color:var(--primary);font-size:21px;font-weight:800}.logo-mark{background:linear-gradient(135deg,var(--primary),var(--purple));color:white;border-radius:12px;padding:11px;font-size:15px}.nav{padding:12px 15px;flex:1;overflow:auto}.nav-heading{padding:14px 13px 9px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;font-weight:750}.nav a{display:flex;align-items:center;gap:12px;border-radius:11px;padding:13px 15px;margin-bottom:5px;color:var(--muted);font-size:14px}.nav a.active,.nav a:hover{background:#eef2ff;color:var(--primary)}.nav-symbol{width:21px;text-align:center}.side-bottom{padding:17px;border-top:1px solid var(--border)}.user-small{display:flex;align-items:center;gap:10px;margin-bottom:14px}.avatar{width:42px;height:42px;display:grid;place-items:center;flex-shrink:0;border-radius:13px;background:#ede9fe;color:#6d28d9;font-weight:800}.user-small .name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:155px}.user-small small{font-size:12px;color:var(--muted)}.logout{border:0;border-radius:10px;background:#fff1f2;color:#be123c;width:100%;padding:12px;font-weight:700}.logout:hover{background:#ffe4e6}
.main{margin-left:var(--side);min-width:0}.topbar{height:83px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 36px;gap:15px}.topbar-name{font-weight:700;font-size:13px}.topbar-label{font-size:15px;color:var(--muted);font-weight:650}.top-right{display:flex;align-items:center;gap:13px}.avatar.top{width:37px;height:37px;border-radius:50%}.mobile-menu{display:none;border:0;background:none;font-size:26px}.content{max-width:1500px;margin:auto;padding:34px 36px 60px}.welcome{background:linear-gradient(115deg,#4338ca,#6366f1 60%,#8b5cf6);color:white;border-radius:21px;padding:34px 38px;position:relative;overflow:hidden;margin-bottom:27px}.welcome:after{content:"";position:absolute;right:-65px;top:-100px;width:330px;height:330px;border-radius:50%;background:#ffffff14}.welcome>*{position:relative;z-index:1}.welcome .pill{display:inline-block;background:#ffffff23;padding:8px 12px;border-radius:20px;font-size:12px;font-weight:650}.welcome h1{font-size:clamp(24px,2.5vw,32px);margin:17px 0 12px}.welcome p{font-size:14px;line-height:1.7;color:#e0e7ff;max-width:700px}.welcome .primary-light{display:inline-block;background:white;color:var(--primary);border-radius:10px;font-size:13px;font-weight:750;padding:12px 18px;margin-top:9px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:30px}.stat{border:1px solid var(--border);background:#fff;border-radius:17px;padding:22px;display:flex;justify-content:space-between;gap:10px}.stat p{font-size:13px;color:var(--muted);margin:0 0 13px}.stat strong{font-size:29px}.stat-icon{width:44px;height:44px;display:grid;place-items:center;background:#ede9fe;border-radius:12px;color:#6d28d9;font-size:22px}.stat-icon.blue{background:#dbeafe;color:#2563eb}.stat-icon.orange{background:#ffedd5;color:#ea580c}.stat-icon.green{background:#dcfce7;color:#15803d}
.section{margin-bottom:28px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.section-head h2{margin:0;font-size:21px;letter-spacing:-.4px}.section-head p{margin:7px 0 0;font-size:13px;color:var(--muted)}.section-head a{font-size:13px;color:var(--primary);font-weight:750;white-space:nowrap}.course-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:19px}.course{background:#fff;border:1px solid var(--border);border-radius:17px;overflow:hidden;min-width:0}.cover{height:132px;background:linear-gradient(135deg,#e0e7ff,#ddd6fe);color:var(--primary);display:grid;place-items:center;font-size:37px;font-weight:850;position:relative}.cover img{width:100%;height:100%;object-fit:cover}.status{position:absolute;right:12px;top:12px;font-size:11px;font-weight:750;background:#fff7ed;color:#9a3412;border-radius:18px;padding:6px 10px}.status.published{background:#dcfce7;color:#166534}.course-body{padding:18px}.course-body .category{font-size:11px;color:var(--primary);background:#eef2ff;padding:6px 9px;border-radius:7px;display:inline-block;font-weight:750}.course-body h3{font-size:16px;line-height:1.45;margin:13px 0 9px;min-height:46px;overflow-wrap:anywhere}.course-body p{font-size:12px;color:var(--muted);line-height:1.6;margin:0 0 16px}.course-meta{display:flex;flex-wrap:wrap;gap:12px;color:var(--muted);font-size:12px}.course-action{display:block;text-align:center;background:#eef2ff;border-radius:10px;padding:12px;margin-top:18px;color:var(--primary);font-size:13px;font-weight:750}.course-action:hover{background:var(--primary);color:#fff}.lower{display:grid;grid-template-columns:1.35fr 1fr;gap:20px}.panel{background:#fff;border:1px solid var(--border);border-radius:17px;padding:22px;min-width:0}.panel h3{font-size:17px;margin:0 0 16px}.submission{display:grid;grid-template-columns:43px minmax(0,1fr) auto;gap:12px;align-items:center;border-bottom:1px solid var(--border);padding:14px 0}.submission:last-child{border-bottom:0}.submission-icon{width:43px;height:43px;border-radius:11px;display:grid;place-items:center;background:#fff7ed;color:#c2410c}.submission strong{font-size:13px;display:block;line-height:1.5}.submission p{font-size:12px;color:var(--muted);line-height:1.5;margin:5px 0 0}.submission .date{font-size:11px;color:var(--muted);white-space:nowrap}.quick{display:grid;gap:10px}.quick a{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px;border:1px solid var(--border);border-radius:11px;font-size:13px;font-weight:700}.quick a:hover{background:#eef2ff;color:var(--primary)}.empty{grid-column:1/-1;border:1px dashed #cbd5e1;background:white;border-radius:17px;text-align:center;padding:34px 20px;color:var(--muted);font-size:13px;line-height:1.7}.empty strong{display:block;color:var(--text);font-size:16px;margin-bottom:8px}.empty a{color:var(--primary);font-weight:750}.alert{border-radius:12px;padding:15px 18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;margin-bottom:20px}.overlay{display:none}
@media(max-width:1200px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.lower{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.overlay.show{display:block;position:fixed;inset:0;background:#0f172a73;z-index:15}.main{margin-left:0}.mobile-menu{display:block}.topbar{height:70px;padding:0 22px}.content{padding:25px 22px}}
@media(max-width:600px){.stats,.course-grid{grid-template-columns:1fr}.content{padding:18px 15px 40px}.topbar{padding:0 15px}.topbar-name{display:none}.welcome{padding:26px 22px}.section-head h2{font-size:19px}.submission{grid-template-columns:40px minmax(0,1fr)}.submission .date{grid-column:2}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="overlay" id="overlay" aria-hidden="true"></div>
<aside class="sidebar" id="sidebar">
<a class="logo" href="<?= e(app_url()) ?>"><span class="logo-mark">&lt;/&gt;</span>BrightFuture</a>
<nav class="nav" aria-label="Điều hướng giáo viên">
<div class="nav-heading">Giảng dạy</div>
<a class="active" aria-current="page" href="<?= e(app_url('teacher/dashboard.php')) ?>"><span class="nav-symbol">▦</span> Tổng quan</a>
<a href="<?= e(app_url('teacher/courses.php')) ?>"><span class="nav-symbol">📚</span> Khóa học của tôi</a>
<a href="<?= e(app_url('teacher/lessons.php')) ?>"><span class="nav-symbol">▤</span> Quản lý bài giảng</a>
<a href="<?= e(app_url('teacher/exercises.php')) ?>"><span class="nav-symbol">✎</span> Quản lý bài tập</a>
<a href="<?= e(app_url('teacher/submissions.php')) ?>"><span class="nav-symbol">✓</span> Chấm bài</a>
<div class="nav-heading">Cá nhân</div>
<a href="<?= e(app_url('profile.php')) ?>"><span class="nav-symbol">♙</span> Hồ sơ của tôi</a>
<a href="<?= e(app_url()) ?>"><span class="nav-symbol">⌂</span> Trang chủ</a>
</nav>
<div class="side-bottom"><div class="user-small"><div class="avatar"><?= e(teacher_initial($user['full_name'])) ?></div><div><div class="name"><?= e($user['full_name']) ?></div><small>Giáo viên</small></div></div>
<form action="<?= e(app_url('logout.php')) ?>" method="post"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form></div>
</aside>
<div class="main"><header class="topbar"><div style="display:flex;align-items:center;gap:15px"><button type="button" class="mobile-menu" id="mobileMenu" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu">☰</button><span class="topbar-label">Không gian giảng dạy</span></div><div class="top-right"><span class="topbar-name"><?= e($user['full_name']) ?></span><div class="avatar top"><?= e(teacher_initial($user['full_name'])) ?></div></div></header>
<main class="content">
<?php if ($errorLoading): ?><div class="alert" role="alert">Không thể tải đầy đủ dữ liệu giảng dạy. Hãy kiểm tra MySQL và các bảng trong database. Các số liệu dưới đây không phản ánh dữ liệu thực tế.</div><?php endif; ?>
<section class="welcome"><span class="pill">Không gian dành cho giáo viên</span><h1>Xin chào, <?= e($user['full_name']) ?>!</h1><p>Quản lý khóa học, theo dõi học viên và kiểm tra bài tập trên BrightFuture. Mọi số liệu bên dưới được tổng hợp từ các khóa học do bạn phụ trách.</p><a class="primary-light" href="<?= e(app_url('teacher/courses.php')) ?>">Quản lý khóa học →</a></section>
<section class="stats" aria-label="Thống kê giảng dạy">
<div class="stat"><div><p>Tổng khóa học</p><strong><?= format_number($stats['courses']) ?></strong></div><div class="stat-icon">📚</div></div>
<div class="stat"><div><p>Học viên đang/đã học</p><strong><?= format_number($stats['students']) ?></strong></div><div class="stat-icon blue">♙</div></div>
<div class="stat"><div><p>Bài nộp chờ chấm</p><strong><?= format_number($stats['pending']) ?></strong></div><div class="stat-icon orange">✎</div></div>
<div class="stat"><div><p>Khóa học công khai</p><strong><?= format_number($stats['published']) ?></strong></div><div class="stat-icon green">✓</div></div>
</section>
<section class="section"><div class="section-head"><div><h2>Khóa học của tôi</h2><p>Các khóa học được chỉnh sửa gần đây</p></div><a href="<?= e(app_url('teacher/courses.php')) ?>">Xem tất cả →</a></div>
<div class="course-grid">
<?php if (!$courses): ?><div class="empty"><strong>Chưa có khóa học</strong>Bạn chưa tạo khóa học nào. <a href="<?= e(app_url('teacher/courses.php')) ?>">Đi đến quản lý khóa học</a>.</div>
<?php else: foreach ($courses as $course): $thumbnail = teacher_thumbnail($course['thumbnail']); ?>
<article class="course"><div class="cover"><?php if ($thumbnail): ?><img src="<?= e($thumbnail) ?>" alt="<?= e($course['title']) ?>" loading="lazy"><?php else: ?>&lt;/&gt;<?php endif; ?><span class="status <?= $course['status'] === 'published' ? 'published' : '' ?>"><?= $course['status'] === 'published' ? 'Công khai' : 'Bản nháp' ?></span></div>
<div class="course-body"><span class="category"><?= e($course['category'] ?: teacher_level($course['level'])) ?></span><h3><?= e($course['title']) ?></h3><p><?= e(truncate_text(clean_text($course['description'] ?? ''), 100) ?: 'Chưa có mô tả khóa học.') ?></p>
<div class="course-meta"><span><?= (int)$course['students'] ?> học viên</span><span><?= (int)$course['lessons'] ?> bài giảng</span><span><?= (int)$course['exercises'] ?> bài tập</span></div>
<a class="course-action" href="<?= e(app_url('teacher/courses.php?edit=' . (int)$course['id'])) ?>">Quản lý khóa học →</a></div></article>
<?php endforeach; endif; ?>
</div></section>
<div class="lower"><section class="panel"><div class="section-head"><h2>Bài nộp chờ chấm</h2><a href="<?= e(app_url('teacher/submissions.php')) ?>">Xem tất cả →</a></div>
<?php if (!$pending): ?><div class="empty"><strong>Không có bài nộp chờ chấm</strong>Các bài mới ở trạng thái “submitted” sẽ xuất hiện tại đây.</div>
<?php else: foreach ($pending as $item): ?><div class="submission"><div class="submission-icon">✎</div><div><strong><?= e($item['student_name']) ?> — <?= e($item['exercise_title']) ?></strong><p><?= e($item['course_title']) ?> · Lần nộp <?= (int)$item['attempt_number'] ?></p></div><div class="date"><?= e(teacher_date($item['submitted_at'])) ?></div></div><?php endforeach; endif; ?>
</section><section class="panel"><h3>Thao tác nhanh</h3><div class="quick"><a href="<?= e(app_url('teacher/courses.php')) ?>"><span>📚 Quản lý và tạo khóa học</span><span>→</span></a><a href="<?= e(app_url('teacher/lessons.php')) ?>"><span>▤ Quản lý chương và bài giảng</span><span>→</span></a><a href="<?= e(app_url('teacher/exercises.php')) ?>"><span>✎ Quản lý bài tập</span><span>→</span></a><a href="<?= e(app_url('teacher/submissions.php')) ?>"><span>✓ Xem bài nộp chờ chấm</span><span>→</span></a></div><p style="color:var(--muted);font-size:12px;line-height:1.7;margin-top:18px"><?= format_number($stats['drafts']) ?> khóa học nháp · <?= format_number($stats['grading']) ?> bài đang chấm · <?= format_number($stats['submissions']) ?> lượt nộp tổng cộng.</p></section></div>
</main></div>
<script>
document.addEventListener('DOMContentLoaded',()=>{const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay'),menu=document.getElementById('mobileMenu');const setOpen=open=>{sidebar.classList.toggle('open',open);overlay.classList.toggle('show',open);menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',open?'Đóng menu':'Mở menu')};menu.addEventListener('click',()=>setOpen(!sidebar.classList.contains('open')));overlay.addEventListener('click',()=>setOpen(false));document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});window.addEventListener('resize',()=>{if(window.innerWidth>900)setOpen(false)})});
</script>
<script>window.BF_LEGACY={role:'teacher',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
