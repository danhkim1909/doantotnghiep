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
$pageTitle = 'Tổng quan giảng dạy';
$pageDescription = 'Tổng quan giảng dạy BrightFuture';
$activeMenu = 'dashboard';
$extraCss = ['css/bf-teacher-dashboard.css'];

require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../views/layouts/portal_head.php';
echo bf_unified_sidebar($user, 'dashboard');
require_once __DIR__ . '/../views/layouts/portal_topbar.php';
?>
<main class="content">
<?php if ($errorLoading): ?><div class="alert" role="alert">Không thể tải đầy đủ dữ liệu giảng dạy. Hãy kiểm tra MySQL và các bảng trong database. Các số liệu dưới đây không phản ánh dữ liệu thực tế.</div><?php endif; ?>
<section class="welcome"><span class="pill">Không gian dành cho giáo viên</span><h1>Xin chào, <?= e($user['full_name']) ?>!</h1><p>Quản lý khóa học, theo dõi học viên và kiểm tra bài tập trên BrightFuture. Mọi số liệu bên dưới được tổng hợp từ các khóa học do bạn phụ trách.</p><a class="primary-light" href="<?= e(app_url('teacher/courses.php')) ?>">Quản lý khóa học →</a></section>
<section class="stats" aria-label="Thống kê giảng dạy">
<div class="stat"><div><p>Tổng khóa học</p><strong><?= format_number($stats['courses']) ?></strong></div><div class="stat-icon"><?= bf_icon('courses') ?></div></div>
<div class="stat"><div><p>Học viên đang/đã học</p><strong><?= format_number($stats['students']) ?></strong></div><div class="stat-icon blue"><?= bf_icon('users') ?></div></div>
<div class="stat"><div><p>Bài nộp chờ chấm</p><strong><?= format_number($stats['pending']) ?></strong></div><div class="stat-icon orange"><?= bf_icon('code') ?></div></div>
<div class="stat"><div><p>Khóa học công khai</p><strong><?= format_number($stats['published']) ?></strong></div><div class="stat-icon green"><?= bf_icon('check') ?></div></div>
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
<?php else: foreach ($pending as $item): ?><div class="submission"><div class="submission-icon" style="display:grid;place-items:center"><?= bf_icon('code') ?></div><div><strong><?= e($item['student_name']) ?> — <?= e($item['exercise_title']) ?></strong><p><?= e($item['course_title']) ?> · Lần nộp <?= (int)$item['attempt_number'] ?></p></div><div class="date"><?= e(teacher_date($item['submitted_at'])) ?></div></div><?php endforeach; endif; ?>
</section><section class="panel"><h3>Thao tác nhanh</h3><div class="quick">
    <a href="<?= e(app_url('teacher/courses.php')) ?>"><span style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('courses') ?> Quản lý và tạo khóa học</span><span>→</span></a>
    <a href="<?= e(app_url('teacher/lessons.php')) ?>"><span style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('lesson') ?> Quản lý chương và bài giảng</span><span>→</span></a>
    <a href="<?= e(app_url('teacher/exercises.php')) ?>"><span style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('exercises') ?> Quản lý bài tập</span><span>→</span></a>
    <a href="<?= e(app_url('teacher/submissions.php')) ?>"><span style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('check') ?> Xem bài nộp chờ chấm</span><span>→</span></a>
</div><p style="color:var(--muted);font-size:12px;line-height:1.7;margin-top:18px"><?= format_number($stats['drafts']) ?> khóa học nháp · <?= format_number($stats['grading']) ?> bài đang chấm · <?= format_number($stats['submissions']) ?> lượt nộp tổng cộng.</p></section></div>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
