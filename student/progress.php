<?php
/**
 * BrightFuture - Student learning progress
 * File: student/progress.php
 * Requirements: PHP 8+, PDO MySQL, schema.sql + phase2.sql + phase3.sql
 */

require_once __DIR__ . '/../includes/permissions.php';

$user = require_role(ROLE_STUDENT);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
$studentId = (int)$user['id'];
$selectedCourseId = positive_int($_GET['course_id'] ?? null);
$courseRows = [];
$recentSubmissions = [];
$errorLoading = false;
$totals = ['courses' => 0, 'lessons' => 0, 'completed' => 0, 'exercises' => 0, 'attempted' => 0, 'graded' => 0];

function progress_percent(int $part, int $whole): float {
    return $whole > 0 ? round(min(100, max(0, $part * 100 / $whole)), 1) : 0.0;
}
function progress_level(?string $value): string {
    return match ($value) {
        'beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao',
        default => 'Chưa phân loại'
    };
}
function progress_submission_label(string $status): string {
    return match ($status) {
        'graded' => 'Đã chấm', 'returned' => 'Đã trả bài', 'grading' => 'Đang chấm',
        'submitted' => 'Chờ chấm', default => 'Đã nộp'
    };
}

try {
    $pdo = db();
    // Scoped to enrolled courses: students cannot request arbitrary course statistics.
    $courseStmt = $pdo->prepare(
        "SELECT c.id, c.title, c.level, c.category, c.status AS course_status,
                en.status AS enrollment_status, en.enrolled_at,
                u.full_name AS teacher_name,
                COALESCE(cp.total_lessons, 0) AS total_lessons,
                COALESCE(cp.completed_lessons, 0) AS completed_lessons,
                COALESCE(cp.progress_percent, 0) AS progress_percent
         FROM enrollments en
         INNER JOIN courses c ON c.id = en.course_id
         INNER JOIN users u ON u.id = c.teacher_id
         LEFT JOIN view_student_course_progress cp
             ON cp.student_id = en.student_id AND cp.course_id = en.course_id
         WHERE en.student_id = :student_id AND en.status IN ('active','completed')
         ORDER BY CASE WHEN en.status = 'active' THEN 0 ELSE 1 END,
                  en.enrolled_at DESC, c.id DESC"
    );
    $courseStmt->execute(['student_id' => $studentId]);
    $courseRows = $courseStmt->fetchAll();
    $validCourseIds = array_map(static fn(array $row): int => (int)$row['id'], $courseRows);
    if ($selectedCourseId > 0 && !in_array($selectedCourseId, $validCourseIds, true)) {
        // Ignore inaccessible course identifiers, without disclosing if they exist.
        $selectedCourseId = 0;
    }

    // Latest attempt per student/exercise, including ungraded attempts.
    // Exercise scores count only a latest attempt that has actually been graded.
    $exerciseStmt = $pdo->prepare(
        "SELECT ch.course_id,
                COUNT(*) AS exercise_count,
                SUM(CASE WHEN sub.id IS NOT NULL THEN 1 ELSE 0 END) AS attempted_count,
                SUM(CASE WHEN sub.status IN ('graded','returned') AND sub.score IS NOT NULL
                         THEN 1 ELSE 0 END) AS graded_count,
                AVG(CASE WHEN sub.status IN ('graded','returned') AND sub.score IS NOT NULL
                         AND ex.max_score > 0 THEN
                           LEAST(100, GREATEST(0, sub.score * 100 / ex.max_score))
                         ELSE NULL END) AS average_score_percent
         FROM exercises ex
         INNER JOIN lessons l ON l.id = ex.lesson_id
         INNER JOIN course_chapters ch ON ch.id = l.chapter_id
         INNER JOIN courses c ON c.id = ch.course_id
         INNER JOIN enrollments en ON en.course_id = c.id
             AND en.student_id = :enrolled_student AND en.status IN ('active','completed')
         LEFT JOIN exercise_submissions sub ON sub.id = (
             SELECT s.id FROM exercise_submissions s
             WHERE s.exercise_id = ex.id AND s.student_id = :submission_student
             ORDER BY s.attempt_number DESC, s.id DESC LIMIT 1
         )
         WHERE c.status = 'published' AND l.status = 'published'
           AND ex.status = 'published'
         GROUP BY ch.course_id"
    );
    $exerciseStmt->execute([
        'enrolled_student' => $studentId,
        'submission_student' => $studentId,
    ]);
    $exerciseMap = [];
    foreach ($exerciseStmt->fetchAll() as $row) {
        $exerciseMap[(int)$row['course_id']] = $row;
    }
    foreach ($courseRows as &$course) {
        $id = (int)$course['id'];
        $scores = $exerciseMap[$id] ?? [];
        $course['exercise_count'] = (int)($scores['exercise_count'] ?? 0);
        $course['attempted_count'] = (int)($scores['attempted_count'] ?? 0);
        $course['graded_count'] = (int)($scores['graded_count'] ?? 0);
        $course['average_score_percent'] = !isset($scores['average_score_percent'])
            ? null : round((float)$scores['average_score_percent'], 1);
        $totals['courses']++;
        $totals['lessons'] += (int)$course['total_lessons'];
        $totals['completed'] += (int)$course['completed_lessons'];
        $totals['exercises'] += $course['exercise_count'];
        $totals['attempted'] += $course['attempted_count'];
        $totals['graded'] += $course['graded_count'];
    }
    unset($course);

    // Show the latest submitted work, optionally filtered by an enrolled course.
    $recentSql = "SELECT sub.id, sub.submitted_at, sub.status, sub.score,
                         ex.id AS exercise_id, ex.title AS exercise_title,
                         ex.max_score, c.id AS course_id, c.title AS course_title
                  FROM exercise_submissions sub
                  INNER JOIN exercises ex ON ex.id = sub.exercise_id
                  INNER JOIN lessons l ON l.id = ex.lesson_id
                  INNER JOIN course_chapters ch ON ch.id = l.chapter_id
                  INNER JOIN courses c ON c.id = ch.course_id
                  INNER JOIN enrollments en ON en.course_id = c.id
                      AND en.student_id = sub.student_id
                      AND en.status IN ('active','completed')
                  WHERE sub.student_id = :student_id";
    $recentParams = ['student_id' => $studentId];
    if ($selectedCourseId > 0) {
        $recentSql .= ' AND c.id = :course_id';
        $recentParams['course_id'] = $selectedCourseId;
    }
    $recentSql .= ' ORDER BY sub.submitted_at DESC, sub.id DESC LIMIT 8';
    $recentStmt = $pdo->prepare($recentSql);
    foreach ($recentParams as $key => $value) {
        $recentStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $recentStmt->execute();
    $recentSubmissions = $recentStmt->fetchAll();
} catch (Throwable $exception) {
    error_log('[BrightFuture Progress] ' . $exception->getMessage());
    $errorLoading = true;
    $courseRows = [];
    $recentSubmissions = [];
    $totals = ['courses' => 0, 'lessons' => 0, 'completed' => 0, 'exercises' => 0, 'attempted' => 0, 'graded' => 0];
}

$visibleCourses = $selectedCourseId > 0
    ? array_values(array_filter($courseRows, static fn(array $c): bool => (int)$c['id'] === $selectedCourseId))
    : $courseRows;
$lessonPercent = progress_percent($totals['completed'], $totals['lessons']);
$exercisePercent = progress_percent($totals['attempted'], $totals['exercises']);
$avatarName = trim((string)$user['full_name']);
$avatarLetter = function_exists('mb_substr') ? mb_substr($avatarName, 0, 1, 'UTF-8') : substr($avatarName, 0, 1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Theo dõi tiến độ học tập BrightFuture">
<title>Tiến độ học tập | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--secondary:#7c3aed;--ink:#172033;--muted:#64748b;--line:#e7ebf2;--surface:#fff;--bg:#f6f8fc;--sidebar:255px}
*{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',Arial,sans-serif;color:var(--ink);background:var(--bg)}a{color:inherit;text-decoration:none}button,select{font:inherit}.layout{min-height:100vh}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:#fff;border-right:1px solid var(--line);z-index:40;display:flex;flex-direction:column;transition:transform .22s}.brand{height:89px;padding:0 23px;display:flex;align-items:center;gap:11px;font-weight:800;font-size:21px;color:var(--primary)}.brand-icon{display:grid;place-items:center;width:39px;height:39px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;font-size:15px}.nav{padding:12px 15px;overflow:auto;flex:1}.nav-label{text-transform:uppercase;font-size:11px;color:#94a3b8;font-weight:700;letter-spacing:1px;margin:14px 12px 10px}.nav a{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:11px;color:var(--muted);font-size:14px;margin-bottom:4px}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--primary)}.nav a.active{font-weight:700}.nav a span{font-size:18px;width:23px;text-align:center}.side-bottom{padding:16px;border-top:1px solid var(--line)}.user{display:flex;align-items:center;gap:10px;margin-bottom:14px}.avatar{display:grid;place-items:center;flex-shrink:0;width:42px;height:42px;border-radius:12px;background:#ede9fe;color:#6d28d9;font-weight:800}.user small{color:var(--muted);display:block;margin-top:4px}.user strong{display:block;max-width:158px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}.logout{width:100%;padding:12px;border:0;border-radius:10px;background:#fff1f2;color:#be123c;font-weight:700;cursor:pointer}
.main{margin-left:var(--sidebar)}.topbar{height:82px;padding:0 36px;background:white;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:14px;color:var(--muted);font-size:14px}.topbar-right{display:flex;align-items:center;gap:12px;color:var(--ink);font-weight:600}.topbar-avatar{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:#e0e7ff;color:var(--primary)}.menu-toggle{display:none;border:0;background:none;font-size:25px;cursor:pointer}.content{max-width:1500px;margin:auto;padding:32px 36px 60px}.breadcrumb{font-size:13px;color:var(--muted);margin-bottom:21px}.breadcrumb a:hover{color:var(--primary)}.heading{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:27px}.heading h1{font-size:29px;letter-spacing:-.7px;margin:0 0 9px}.heading p{font-size:14px;line-height:1.6;color:var(--muted);margin:0}.filter{display:flex;align-items:center;gap:10px}.filter label{font-size:13px;color:var(--muted)}.filter select{border:1px solid var(--line);border-radius:11px;padding:12px;max-width:260px;background:#fff;min-width:160px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:25px}.stat{padding:22px;background:#fff;border:1px solid var(--line);border-radius:17px;display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.stat p{font-size:13px;color:var(--muted);margin:0 0 13px}.stat strong{font-size:28px;letter-spacing:-.5px}.stat-icon{display:grid;place-items:center;width:44px;height:44px;background:#eef2ff;color:var(--primary);border-radius:12px;font-size:23px;flex-shrink:0}
.panel{background:#fff;border:1px solid var(--line);border-radius:17px;padding:23px;min-width:0}.panel h2{font-size:19px;margin:0 0 7px}.subtext{color:var(--muted);font-size:13px;line-height:1.6;margin:0 0 23px}.section{margin-bottom:25px}.chart-row{display:grid;grid-template-columns:minmax(120px,1fr) minmax(140px,2fr) 58px;gap:15px;align-items:center;padding:13px 0;border-bottom:1px solid var(--line)}.chart-row:last-child{border:0}.chart-name{font-size:13px;font-weight:600;line-height:1.5;overflow-wrap:anywhere}.chart-name small{display:block;color:var(--muted);font-weight:400}.bar-track{height:13px;border-radius:20px;background:#e9edf5;overflow:hidden}.bar-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#4f46e5,#8b5cf6)}.bar-label{font-weight:700;color:var(--primary);font-size:13px;text-align:right;font-variant-numeric:tabular-nums}.course-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.course{border:1px solid var(--line);border-radius:15px;padding:19px;min-width:0}.course-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:15px}.course h3{font-size:16px;line-height:1.45;margin:0 0 7px;overflow-wrap:anywhere}.course p{font-size:12px;color:var(--muted);line-height:1.6;margin:0}.tag{display:inline-block;border-radius:100px;padding:6px 10px;background:#eef2ff;color:var(--primary);font-size:11px;font-weight:700;white-space:nowrap}.tag.done{background:#dcfce7;color:#166534}.meter-head{display:flex;justify-content:space-between;gap:8px;font-size:12px;margin:12px 0 9px}.meter-head span{color:var(--muted)}.meter-head strong{color:var(--primary)}.bar-track.small{height:8px}.course-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;border-top:1px solid var(--line);margin-top:18px;padding-top:16px;font-size:12px;color:var(--muted)}.course-meta strong{display:block;color:var(--ink);font-size:15px;margin-top:5px}.course-link{display:inline-block;color:var(--primary);font-weight:700;font-size:13px;margin-top:17px}.table-scroll{overflow:auto}.data-table{width:100%;border-collapse:collapse;font-size:13px;min-width:650px}.data-table th{text-align:left;padding:13px 12px;color:var(--muted);font-weight:600;background:#f8faff}.data-table td{padding:15px 12px;border-top:1px solid var(--line);vertical-align:top}.data-table td a{color:var(--primary);font-weight:600}.data-table td small{color:var(--muted)}.empty{text-align:center;padding:35px 15px;color:var(--muted);font-size:14px;line-height:1.7}.empty a{color:var(--primary);font-weight:700}.alert{padding:15px 18px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;margin-bottom:22px;font-size:14px}.hint{margin-top:12px;color:var(--muted);font-size:12px;line-height:1.6}.overlay{display:none}
@media(max-width:1200px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.menu-toggle{display:block}.overlay.show{display:block;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:30}.topbar{height:70px;padding:0 22px}.content{padding:25px 22px 45px}}@media(max-width:650px){.stats,.course-grid{grid-template-columns:1fr}.content{padding:20px 15px 40px}.topbar{padding:0 15px}.topbar-right .full-name{display:none}.heading h1{font-size:25px}.filter{width:100%;align-items:stretch;flex-direction:column}.filter select{max-width:none;width:100%}.chart-row{grid-template-columns:minmax(90px,1fr) minmax(80px,1fr) 45px;gap:8px}.panel{padding:17px}.stat{padding:19px}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="layout">
<div class="overlay" id="overlay" aria-hidden="true"></div>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(app_url()) ?>"><span class="brand-icon">&lt;/&gt;</span>BrightFuture</a>
    <nav class="nav" aria-label="Menu học viên">
        <div class="nav-label">Học tập</div>
        <a href="<?= e(app_url('student/dashboard.php')) ?>"><span>▦</span>Tổng quan</a>
        <a href="<?= e(app_url('student/my-courses.php')) ?>"><span>📚</span>Khóa học của tôi</a>
        <a href="<?= e(app_url('courses.php')) ?>"><span>⌕</span>Khám phá khóa học</a>
        <a href="<?= e(app_url('student/exercises.php')) ?>"><span>⌨</span>Bài tập</a>
        <a href="<?= e(app_url('student/progress.php')) ?>" class="active" aria-current="page"><span>▥</span>Tiến độ học tập</a>
        <div class="nav-label">Cá nhân</div>
        <a href="<?= e(app_url('profile.php')) ?>"><span>♙</span>Hồ sơ của tôi</a>
        <a href="<?= e(app_url()) ?>"><span>⌂</span>Trang chủ</a>
    <a href="<?= e(app_url('certificate.php')) ?>">✧ Chứng chỉ của tôi</a>
</nav>
    <div class="side-bottom">
        <div class="user"><span class="avatar"><?= e($avatarLetter) ?></span><div><strong><?= e($avatarName) ?></strong><small>Học viên</small></div></div>
        <form action="<?= e(app_url('logout.php')) ?>" method="POST"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form>
    </div>
</aside>
<div class="main">
<header class="topbar">
    <div style="display:flex;align-items:center;gap:15px"><button type="button" class="menu-toggle" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu">☰</button><span>Không gian học tập</span></div>
    <div class="topbar-right"><span class="full-name"><?= e($avatarName) ?></span><span class="topbar-avatar"><?= e($avatarLetter) ?></span></div>
</header>
<main class="content">
    <div class="breadcrumb"><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a> / <strong>Tiến độ học tập</strong></div>
    <div class="heading"><div><h1>Tiến độ học tập</h1><p>Theo dõi bài học, bài tập và kết quả thực tế của bạn trên BrightFuture.</p></div>
    <form class="filter" method="GET" action="<?= e(app_url('student/progress.php')) ?>"><label for="course_id">Xem chi tiết:</label><select id="course_id" name="course_id" onchange="this.form.submit()"><option value="">Tất cả khóa học</option>
    <?php foreach ($courseRows as $course): ?><option value="<?= (int)$course['id'] ?>" <?= $selectedCourseId === (int)$course['id'] ? 'selected' : '' ?>><?= e((string)$course['title']) ?></option><?php endforeach; ?>
    </select><noscript><button type="submit">Lọc</button></noscript></form></div>
    <?php if ($errorLoading): ?><div class="alert" role="alert">Không thể tải dữ liệu tiến độ. Hãy kiểm tra kết nối MySQL và các bảng, View từ phase3.sql.</div><?php endif; ?>
    <section class="stats" aria-label="Thống kê học tập toàn bộ khóa học">
        <div class="stat"><div><p>Khóa học đã đăng ký</p><strong><?= format_number($totals['courses']) ?></strong></div><div class="stat-icon">📚</div></div>
        <div class="stat"><div><p>Bài học hoàn thành</p><strong><?= format_number($totals['completed']) ?> / <?= format_number($totals['lessons']) ?></strong></div><div class="stat-icon">✓</div></div>
        <div class="stat"><div><p>Tiến độ bài học</p><strong><?= e((string)$lessonPercent) ?>%</strong></div><div class="stat-icon">▥</div></div>
        <div class="stat"><div><p>Bài tập đã nộp ít nhất 1 lần</p><strong><?= format_number($totals['attempted']) ?> / <?= format_number($totals['exercises']) ?></strong></div><div class="stat-icon">⌨</div></div>
    </section>
    <section class="panel section" aria-labelledby="chart-title">
        <h2 id="chart-title">Biểu đồ tiến độ theo khóa học</h2>
        <p class="subtext">Tỷ lệ bài giảng công khai đã đánh dấu hoàn thành trong từng khóa học đã đăng ký.</p>
        <?php if (!$visibleCourses): ?><div class="empty">Chưa có dữ liệu khóa học để hiển thị. <a href="<?= e(app_url('courses.php')) ?>">Khám phá khóa học</a></div>
        <?php else: foreach ($visibleCourses as $course):
            $percent = max(0, min(100, (float)$course['progress_percent'])); ?>
            <div class="chart-row">
                <div class="chart-name"><?= e((string)$course['title']) ?><small><?= (int)$course['completed_lessons'] ?>/<?= (int)$course['total_lessons'] ?> bài học</small></div>
                <div class="bar-track" role="progressbar" aria-label="<?= e('Tiến độ ' . $course['title']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($percent) ?>"><div class="bar-fill" style="width:<?= e((string)$percent) ?>%"></div></div>
                <div class="bar-label"><?= e((string)$percent) ?>%</div>
            </div>
        <?php endforeach; endif; ?>
        <p class="hint">Biểu đồ phản ánh bài học đã hoàn thành, không phải thời gian xem video hay điểm bài tập.</p>
    </section>
    <section class="panel section" aria-labelledby="courses-title">
        <h2 id="courses-title">Chi tiết từng khóa học</h2><p class="subtext">Kết quả được tổng hợp từ các bài học, bài tập và lần nộp mới nhất.</p>
        <?php if (!$visibleCourses): ?><div class="empty">Bạn chưa có khóa học tương ứng với bộ lọc.</div>
        <?php else: ?><div class="course-grid">
        <?php foreach ($visibleCourses as $course):
            $percent = max(0, min(100, (float)$course['progress_percent']));
            $courseId = (int)$course['id']; ?>
            <article class="course">
                <div class="course-top"><div><h3><?= e((string)$course['title']) ?></h3><p><?= e((string)$course['teacher_name']) ?> · <?= e(progress_level($course['level'])) ?></p></div><span class="tag <?= $course['enrollment_status'] === 'completed' ? 'done' : '' ?>"><?= $course['enrollment_status'] === 'completed' ? 'Đã hoàn thành' : 'Đang học' ?></span></div>
                <div class="meter-head"><span>Bài học đã hoàn thành</span><strong><?= e((string)$percent) ?>%</strong></div>
                <div class="bar-track small" role="progressbar" aria-label="Tiến độ bài học" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($percent) ?>"><div class="bar-fill" style="width:<?= e((string)$percent) ?>%"></div></div>
                <div class="course-meta"><div>Bài học<strong><?= (int)$course['completed_lessons'] ?> / <?= (int)$course['total_lessons'] ?></strong></div><div>Bài tập đã nộp<strong><?= (int)$course['attempted_count'] ?> / <?= (int)$course['exercise_count'] ?></strong></div><div>Bài tập có điểm lần nộp mới nhất<strong><?= (int)$course['graded_count'] ?></strong></div><div>Điểm trung bình quy đổi<strong><?= $course['average_score_percent'] === null ? 'Chưa có' : e((string)$course['average_score_percent']) . '%' ?></strong></div></div>
                <a class="course-link" href="<?= e(course_url($courseId)) ?>">Xem khóa học →</a>
            </article>
        <?php endforeach; ?></div><?php endif; ?>
        <p class="hint">Điểm trung bình là tỷ lệ phần trăm của các lần nộp mới nhất đã được chấm, không phải điểm trung bình tổng kết khóa học. Trạng thái “Đã hoàn thành” dựa trên bảng enrollments.</p>
    </section>
    <section class="panel" aria-labelledby="submissions-title">
        <h2 id="submissions-title">Bài nộp gần đây</h2><p class="subtext">8 lần nộp gần nhất<?= $selectedCourseId > 0 ? ' trong khóa học đã chọn' : '' ?>.</p>
        <?php if (!$recentSubmissions): ?><div class="empty">Chưa có bài nộp nào. <a href="<?= e(app_url('student/exercises.php')) ?>">Xem bài tập</a></div>
        <?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>Bài tập</th><th>Khóa học</th><th>Thời điểm nộp</th><th>Trạng thái</th><th>Điểm</th></tr></thead><tbody>
        <?php foreach ($recentSubmissions as $submission): ?>
            <tr><td><a href="<?= e(exercise_url((int)$submission['exercise_id'])) ?>"><?= e((string)$submission['exercise_title']) ?></a></td><td><?= e((string)$submission['course_title']) ?></td><td><?= e(format_datetime($submission['submitted_at'])) ?></td><td><?= e(progress_submission_label((string)$submission['status'])) ?></td><td><?php if ($submission['score'] !== null && in_array($submission['status'], ['graded','returned'], true)): ?><?= e(format_number((float)$submission['score'], 2)) ?> / <?= e(format_number((float)$submission['max_score'], 2)) ?><?php else: ?>Chưa có điểm<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</main></div></div>
<script>
(function(){
    const sidebar=document.getElementById('sidebar');
    const overlay=document.getElementById('overlay');
    const button=document.getElementById('menuToggle');
    if(!sidebar||!overlay||!button)return;
    function show(open){sidebar.classList.toggle('open',open);overlay.classList.toggle('show',open);button.setAttribute('aria-expanded',String(open));button.setAttribute('aria-label',open?'Đóng menu':'Mở menu');}
    button.addEventListener('click',()=>show(!sidebar.classList.contains('open')));
    overlay.addEventListener('click',()=>show(false));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')show(false)});
    window.addEventListener('resize',()=>{if(window.innerWidth>900)show(false)});
})();
</script>
<script>window.BF_LEGACY={role:'student',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
