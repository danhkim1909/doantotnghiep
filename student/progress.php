<?php
/**
 * BrightFuture - Student learning progress
 * File: student/progress.php
 * Requirements: PHP 8+, PDO MySQL, canonical database baseline and migrations
 */

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';

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

$pageTitle = 'Tiến độ học tập';
$pageDescription = 'Theo dõi tiến độ học tập trên BrightFuture';
$activeMenu = 'progress';
$extraCss = ['css/bf-student-progress.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($user, 'progress') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content progress-content">
    <div class="breadcrumb"><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a> / <strong>Tiến độ học tập</strong></div>
    <div class="heading"><div><h1>Tiến độ học tập</h1><p>Theo dõi bài học, bài tập và kết quả thực tế của bạn trên BrightFuture.</p></div>
    <form class="filter" method="GET" action="<?= e(app_url('student/progress.php')) ?>"><label for="course_id">Xem chi tiết:</label><select id="course_id" name="course_id" onchange="this.form.submit()"><option value="">Tất cả khóa học</option>
    <?php foreach ($courseRows as $course): ?><option value="<?= (int)$course['id'] ?>" <?= $selectedCourseId === (int)$course['id'] ? 'selected' : '' ?>><?= e((string)$course['title']) ?></option><?php endforeach; ?>
    </select><noscript><button type="submit">Lọc</button></noscript></form></div>
    <?php if ($errorLoading): ?><div class="alert" role="alert">Không thể tải dữ liệu tiến độ. Hãy kiểm tra kết nối MySQL, schema baseline và trạng thái migration.</div><?php endif; ?>
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
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
