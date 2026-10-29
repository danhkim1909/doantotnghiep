<?php
/**
 * BrightFuture — Student exercises
 * File: student/exercises.php
 * PHP >= 8.0; tables from schema.sql, phase2.sql, phase3.sql
 */
require_once __DIR__ . '/../includes/permissions.php';

$user = require_role(ROLE_STUDENT);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
$studentId = (int) $user['id'];

$keyword = get_query('q');
$keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 100, 'UTF-8') : substr($keyword, 0, 100);
$status = get_query('status', 'all');
if (!in_array($status, ['all', 'pending', 'submitted', 'graded', 'overdue'], true)) {
    $status = 'all';
}
$courseId = positive_int($_GET['course_id'] ?? null);
$page = positive_int($_GET['page'] ?? null, 1);
$perPage = 9;
$pagination = paginate(0, 1, $perPage);
$coursesForFilter = [];
$exercises = [];
$counts = ['all' => 0, 'pending' => 0, 'submitted' => 0, 'graded' => 0, 'overdue' => 0];
$dataError = false;

/** Preserve active filters across pagination. */
function exercises_page_url(int $page, string $keyword, string $status, int $courseId): string
{
    return app_url('student/exercises.php?' . http_build_query([
        'q' => $keyword, 'status' => $status,
        'course_id' => $courseId > 0 ? $courseId : '', 'page' => $page
    ]));
}

function exercises_type_label(string $type): string
{
    return match ($type) {
        'quiz' => 'Trắc nghiệm',
        'coding' => 'Lập trình',
        'file_upload' => 'Nộp tệp',
        'text' => 'Tự luận',
        default => 'Bài tập'
    };
}

function exercises_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Chưa nộp',
        'submitted' => 'Đã nộp / chờ chấm',
        'graded' => 'Đã chấm',
        'overdue' => 'Quá hạn',
        default => 'Không xác định'
    };
}

try {
    $pdo = db();
    // Only courses the currently authenticated student is enrolled in.
    $courseStmt = $pdo->prepare(
        "SELECT c.id, c.title
         FROM enrollments en
         INNER JOIN courses c ON c.id = en.course_id
         WHERE en.student_id = :student_id
           AND en.status IN ('active','completed')
           AND c.status = 'published'
         ORDER BY c.title, c.id"
    );
    $courseStmt->execute(['student_id' => $studentId]);
    $coursesForFilter = $courseStmt->fetchAll();

    // Latest submission is identified by attempt_number, then id.
    // An exercise is displayed only when its lesson and course are published
    // and the logged-in student has an active/completed enrollment.
    $baseSql = " FROM exercises ex
        INNER JOIN lessons l ON l.id = ex.lesson_id
        INNER JOIN course_chapters ch ON ch.id = l.chapter_id
        INNER JOIN courses c ON c.id = ch.course_id
        INNER JOIN enrollments en
            ON en.course_id = c.id
           AND en.student_id = :enrolled_student_id
           AND en.status IN ('active', 'completed')
        LEFT JOIN exercise_submissions sub ON sub.id = (
            SELECT s.id FROM exercise_submissions s
            WHERE s.exercise_id = ex.id AND s.student_id = :latest_student_id
            ORDER BY s.attempt_number DESC, s.id DESC LIMIT 1
        )
        WHERE c.status = 'published'
          AND l.status = 'published'
          AND ex.status = 'published'";

    $statusSql = "CASE
        WHEN sub.id IS NOT NULL AND sub.status IN ('graded','returned') THEN 'graded'
        WHEN sub.id IS NOT NULL THEN 'submitted'
        WHEN ex.deadline IS NOT NULL AND ex.deadline < NOW() THEN 'overdue'
        ELSE 'pending' END";

    $params = [
        'enrolled_student_id' => $studentId,
        'latest_student_id' => $studentId,
    ];
    if ($courseId > 0) {
        $baseSql .= ' AND c.id = :course_id';
        $params['course_id'] = $courseId;
    }
    if ($keyword !== '') {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
        $like = '%' . $escaped . '%';
        $baseSql .= " AND (ex.title LIKE :title_search ESCAPE '!'
                            OR l.title LIKE :lesson_search ESCAPE '!'
                            OR c.title LIKE :course_search ESCAPE '!')";
        $params['title_search'] = $like;
        $params['lesson_search'] = $like;
        $params['course_search'] = $like;
    }

    // Count badges respect course and search, but not status filter.
    $groupStmt = $pdo->prepare("SELECT {$statusSql} AS display_status, COUNT(*) AS total " .
        $baseSql . ' GROUP BY display_status');
    foreach ($params as $key => $value) {
        $groupStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $groupStmt->execute();
    foreach ($groupStmt->fetchAll() as $r) {
        $s = (string) $r['display_status'];
        if (array_key_exists($s, $counts)) {
            $counts[$s] = (int) $r['total'];
            $counts['all'] += (int) $r['total'];
        }
    }

    $filteredSql = $baseSql;
    if ($status !== 'all') {
        $filteredSql .= " AND ({$statusSql}) = :status_filter";
        $params['status_filter'] = $status;
    }
    $totalItems = $status === 'all' ? $counts['all'] : $counts[$status];
    $pagination = paginate($totalItems, $page, $perPage);

    $sql = "SELECT ex.id, ex.title, ex.exercise_type, ex.description,
                   ex.max_score, ex.deadline, ex.max_attempts,
                   l.title AS lesson_title,
                   c.id AS course_id, c.title AS course_title,
                   sub.id AS submission_id, sub.status AS submission_status,
                   sub.score AS latest_score, sub.submitted_at,
                   sub.attempt_number,
                   {$statusSql} AS display_status,
                   (SELECT COUNT(*) FROM exercise_submissions att
                      WHERE att.exercise_id = ex.id AND att.student_id = :count_student_id)
                      AS attempts_used
            " . $filteredSql .
            ' ORDER BY CASE WHEN ex.deadline IS NULL THEN 1 ELSE 0 END, '
            . 'ex.deadline ASC, ex.id DESC LIMIT :page_limit OFFSET :page_offset';
    $stmt = $pdo->prepare($sql);
    $params['count_student_id'] = $studentId;
    $params['page_limit'] = $pagination['per_page'];
    $params['page_offset'] = $pagination['offset'];
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $exercises = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('[BrightFuture Exercises] ' . $exception->getMessage());
    $dataError = true;
    $exercises = [];
    $counts = ['all' => 0, 'pending' => 0, 'submitted' => 0, 'graded' => 0, 'overdue' => 0];
    $pagination = paginate(0, 1, $perPage);
}

$fullName = (string) $user['full_name'];
$avatar = function_exists('mb_substr') ? mb_substr($fullName, 0, 1, 'UTF-8') : substr($fullName, 0, 1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e('Bài tập của tôi | ' . APP_NAME) ?></title>
    <style>
        :root{--primary:#4f46e5;--purple:#7c3aed;--bg:#f6f8fc;--text:#172033;--muted:#64748b;--border:#e7ebf2;--sidebar:255px}
        *{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text)}a{text-decoration:none;color:inherit}button,input,select{font:inherit}button{cursor:pointer}
        .sidebar{width:var(--sidebar);position:fixed;inset:0 auto 0 0;background:white;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:20;transition:transform .25s}
        .brand{height:90px;padding:0 25px;display:flex;align-items:center;gap:11px;font-size:21px;font-weight:800;color:var(--primary)}.brand-icon{display:grid;place-items:center;width:39px;height:39px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--purple));color:#fff;font-size:15px}
        .nav{flex:1;overflow:auto;padding:12px 15px}.nav-label{padding:14px 13px 9px;font-size:11px;letter-spacing:1px;text-transform:uppercase;font-weight:700;color:#94a3b8}.nav a{display:flex;gap:13px;align-items:center;padding:13px 15px;margin-bottom:5px;border-radius:11px;color:var(--muted);font-size:14px}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--primary)}.nav a.active{font-weight:700}.nav .symbol{width:23px;text-align:center;font-size:18px}
        .sidebar-foot{border-top:1px solid var(--border);padding:17px}.user-block{display:flex;align-items:center;gap:10px;margin-bottom:15px}.avatar{display:grid;place-items:center;width:42px;height:42px;flex-shrink:0;background:#ede9fe;color:#6d28d9;border-radius:13px;font-weight:800}.user-names{min-width:0}.user-names strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.user-names small{color:var(--muted)}.logout{width:100%;border:0;background:#fff1f2;color:#be123c;border-radius:10px;padding:12px;font-weight:700}
        .main{margin-left:var(--sidebar);min-width:0}.topbar{height:83px;background:white;border-bottom:1px solid var(--border);padding:0 36px;display:flex;align-items:center;justify-content:space-between}.topbar-left{display:flex;align-items:center;gap:14px}.topbar-title{font-size:15px;color:var(--muted);font-weight:600}.topbar-user{display:flex;align-items:center;gap:12px;font-size:13px;font-weight:600}.topbar-user .avatar{width:37px;height:37px;border-radius:50%}.menu-btn{display:none;border:0;background:transparent;font-size:24px}.content{max-width:1500px;margin:auto;padding:35px 36px 60px}.breadcrumb{font-size:13px;color:var(--muted);margin-bottom:22px}.breadcrumb a:hover{color:var(--primary)}.breadcrumb span{color:var(--primary);font-weight:600}
        .heading{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:27px}.heading h1{font-size:29px;letter-spacing:-.6px;margin:0 0 9px}.heading p{margin:0;color:var(--muted);font-size:14px;line-height:1.6}.explore{display:inline-block;padding:13px 18px;border-radius:11px;background:var(--primary);color:white;font-size:13px;font-weight:700;white-space:nowrap}
        .summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:27px}.summary-item{border:1px solid var(--border);border-radius:16px;background:#fff;padding:19px}.summary-item span{display:block;color:var(--muted);font-size:12px;margin-bottom:12px}.summary-item strong{font-size:29px}.filter-box{background:white;border:1px solid var(--border);border-radius:17px;padding:20px;margin-bottom:26px}.filter-form{display:grid;grid-template-columns:minmax(180px,1fr) 210px 180px auto;gap:12px}.field{width:100%;height:48px;padding:0 14px;border:1.5px solid var(--border);border-radius:11px;background:white;outline:none;color:var(--text)}.field:focus{border-color:var(--primary);box-shadow:0 0 0 3px #4f46e51a}.search-btn{height:48px;padding:0 20px;border:0;border-radius:11px;background:var(--primary);color:white;font-weight:700}.filter-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px;color:var(--muted);font-size:13px}.filter-meta a{color:var(--primary);font-weight:700}
        .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:19px}.exercise{background:#fff;border:1px solid var(--border);border-radius:17px;padding:21px;min-width:0;display:flex;flex-direction:column}.card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:15px}.type-tag{padding:7px 10px;border-radius:7px;background:#eef2ff;color:var(--primary);font-size:11px;font-weight:700}.status{border-radius:30px;padding:7px 10px;font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8;text-align:center}.status.graded{background:#dcfce7;color:#166534}.status.overdue{background:#fee2e2;color:#991b1b}.status.submitted{background:#fef3c7;color:#92400e}.exercise h2{font-size:17px;line-height:1.45;margin:0 0 9px;overflow-wrap:anywhere}.exercise .location{font-size:12px;color:var(--muted);line-height:1.7;min-height:42px;margin:0 0 13px}.exercise .desc{font-size:13px;color:var(--muted);line-height:1.7;margin:0 0 15px;flex:1}.metadata{border-top:1px solid var(--border);padding-top:13px;display:grid;gap:9px;font-size:12px}.metadata div{display:flex;justify-content:space-between;align-items:baseline;gap:10px}.metadata span{color:var(--muted)}.metadata strong{text-align:right}.action{display:block;margin-top:18px;background:#eef2ff;color:var(--primary);padding:12px;text-align:center;border-radius:10px;font-size:13px;font-weight:700}.action:hover{background:var(--primary);color:#fff}.empty{grid-column:1/-1;text-align:center;padding:55px 25px;background:#fff;border:1px dashed #cbd5e1;border-radius:17px}.empty h2{font-size:18px}.empty p{font-size:14px;line-height:1.7;color:var(--muted)}.alert{border:1px solid #fed7aa;color:#9a3412;background:#fff7ed;padding:16px;border-radius:12px;margin-bottom:22px}.pagination{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;margin-top:32px}.page{border:1px solid var(--border);border-radius:9px;background:#fff;color:var(--muted);min-width:39px;height:39px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}.page.active{background:var(--primary);color:white;border-color:var(--primary)}.overlay{display:none}
        @media(max-width:1250px){.summary{grid-template-columns:repeat(3,minmax(0,1fr))}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-form{grid-template-columns:1fr 1fr}.filter-form input{grid-column:1/-1}}
        @media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.overlay.open{display:block;position:fixed;inset:0;background:#0f172a73;z-index:15}.main{margin-left:0}.menu-btn{display:block}.topbar{height:70px;padding:0 22px}.content{padding:25px 22px}}
        @media(max-width:640px){.content{padding:20px 15px 40px}.topbar{padding:0 15px}.topbar-user .user-text{display:none}.heading{align-items:flex-start;flex-direction:column}.heading h1{font-size:25px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.grid{grid-template-columns:1fr}.filter-form{display:flex;flex-direction:column}.filter-form input{grid-column:auto}.filter-meta{flex-wrap:wrap}}
        @media(max-width:380px){.summary{grid-template-columns:1fr}}
    </style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div id="overlay" class="overlay" aria-hidden="true"></div>
<aside id="sidebar" class="sidebar">
    <a class="brand" href="<?= e(app_url()) ?>"><span class="brand-icon">&lt;/&gt;</span>BrightFuture</a>
    <nav class="nav" aria-label="Điều hướng học viên">
<a href="<?= e(app_url('certificate.php')) ?>">✧ Chứng chỉ</a>
        <div class="nav-label">Học tập</div>
        <a href="<?= e(app_url('student/dashboard.php')) ?>"><span class="symbol">▦</span>Tổng quan</a>
        <a href="<?= e(app_url('student/my-courses.php')) ?>"><span class="symbol">📚</span>Khóa học của tôi</a>
        <a href="<?= e(app_url('courses.php')) ?>"><span class="symbol">⌕</span>Khám phá khóa học</a>
        <a class="active" aria-current="page" href="<?= e(app_url('student/exercises.php')) ?>"><span class="symbol">⌨</span>Bài tập</a>
        <a href="<?= e(app_url('student/progress.php')) ?>"><span class="symbol">▥</span>Tiến độ học tập</a>
        <div class="nav-label">Cá nhân</div>
        <a href="<?= e(app_url('profile.php')) ?>"><span class="symbol">☺</span>Hồ sơ của tôi</a>
        <a href="<?= e(app_url()) ?>"><span class="symbol">⌂</span>Trang chủ</a>
    </nav>
    <div class="sidebar-foot">
        <div class="user-block"><span class="avatar"><?= e($avatar) ?></span><div class="user-names"><strong><?= e($fullName) ?></strong><small>Học viên</small></div></div>
        <form method="POST" action="<?= e(app_url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="logout" type="submit">➜ Đăng xuất</button>
        </form>
    </div>
</aside>
<div class="main">
    <header class="topbar">
        <div class="topbar-left"><button type="button" id="menuBtn" class="menu-btn" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu">☰</button><span class="topbar-title">Không gian học tập</span></div>
        <div class="topbar-user"><span class="user-text"><?= e($fullName) ?></span><span class="avatar"><?= e($avatar) ?></span></div>
    </header>
    <main class="content">
        <div class="breadcrumb"><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a> / <span>Bài tập</span></div>
        <div class="heading"><div><h1>Bài tập của tôi</h1><p>Theo dõi bài tập, hạn nộp, số lần thực hiện và kết quả chấm điểm.</p></div><a class="explore" href="<?= e(app_url('courses.php')) ?>">Khám phá khóa học →</a></div>
        <?php if ($dataError): ?>
            <div class="alert" role="alert">Không tải được danh sách bài tập. Hãy kiểm tra kết nối MySQL và việc import đầy đủ các bảng trong <strong>phase3.sql</strong>.</div>
        <?php endif; ?>
        <section class="summary" aria-label="Thống kê bài tập">
            <?php foreach (['all'=>'Tất cả bài tập','pending'=>'Chưa nộp','submitted'=>'Chờ chấm','graded'=>'Đã chấm','overdue'=>'Quá hạn'] as $key => $label): ?>
                <div class="summary-item"><span><?= e($label) ?></span><strong><?= format_number($counts[$key]) ?></strong></div>
            <?php endforeach; ?>
        </section>
        <section class="filter-box" aria-label="Tìm kiếm và lọc bài tập">
            <form class="filter-form" method="GET" action="<?= e(app_url('student/exercises.php')) ?>">
                <input class="field" type="search" name="q" value="<?= e($keyword) ?>" maxlength="100" placeholder="Tìm bài tập, bài học hoặc khóa học..." aria-label="Từ khóa">
                <select class="field" name="course_id" aria-label="Khóa học">
                    <option value="">Tất cả khóa học</option>
                    <?php foreach ($coursesForFilter as $course): ?>
                        <option value="<?= (int)$course['id'] ?>" <?= $courseId === (int)$course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="field" name="status" aria-label="Trạng thái">
                    <?php foreach (['all'=>'Tất cả trạng thái','pending'=>'Chưa nộp','submitted'=>'Chờ chấm','graded'=>'Đã chấm','overdue'=>'Quá hạn'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="search-btn" type="submit">Tìm kiếm</button>
            </form>
            <div class="filter-meta"><span>Tìm thấy <strong><?= format_number($pagination['total_items']) ?></strong> bài tập</span>
                <?php if ($keyword !== '' || $courseId > 0 || $status !== 'all'): ?><a href="<?= e(app_url('student/exercises.php')) ?>">Xóa bộ lọc</a><?php endif; ?>
            </div>
        </section>
        <section class="grid" aria-label="Danh sách bài tập">
            <?php if (empty($exercises)): ?>
                <div class="empty"><div style="font-size:40px">📘</div><h2><?= $dataError ? 'Chưa thể hiển thị bài tập' : 'Không tìm thấy bài tập phù hợp' ?></h2>
                    <p><?= $dataError ? 'Vui lòng thử lại sau khi kiểm tra cơ sở dữ liệu.' : 'Nếu bạn chưa thấy bài tập, hãy kiểm tra khóa học đã đăng ký hoặc thay đổi bộ lọc.' ?></p>
                    <a class="explore" href="<?= e(app_url('student/my-courses.php')) ?>">Xem khóa học của tôi</a>
                </div>
            <?php else: ?>
                <?php foreach ($exercises as $ex): ?>
                    <?php
                    $displayStatus = (string)$ex['display_status'];
                    $score = $ex['latest_score'];
                    $deadline = $ex['deadline'];
                    $used = (int)$ex['attempts_used'];
                    $maxAttempts = $ex['max_attempts'] === null ? null : (int)$ex['max_attempts'];
                    $outOfAttempts = $maxAttempts !== null && $used >= $maxAttempts;
                    $deadlinePassed = $deadline !== null && strtotime((string)$deadline) < time();
                    $canSubmitAgain = !$deadlinePassed && !$outOfAttempts;
                    ?>
                    <article class="exercise">
                        <div class="card-top"><span class="type-tag"><?= e(exercises_type_label((string)$ex['exercise_type'])) ?></span><span class="status <?= e($displayStatus) ?>"><?= e(exercises_status_label($displayStatus)) ?></span></div>
                        <h2><?= e($ex['title']) ?></h2>
                        <p class="location"><?= e($ex['course_title']) ?> · <?= e($ex['lesson_title']) ?></p>
                        <p class="desc"><?= e(truncate_text(clean_text((string)($ex['description'] ?? '')), 130) ?: 'Xem yêu cầu và hướng dẫn để thực hiện bài tập.') ?></p>
                        <div class="metadata">
                            <div><span>Hạn nộp</span><strong><?= $deadline !== null ? e(format_datetime((string)$deadline)) : 'Không giới hạn' ?></strong></div>
                            <div><span>Số lần đã nộp</span><strong><?= $used ?><?= $maxAttempts !== null ? ' / ' . $maxAttempts : ' / Không giới hạn' ?></strong></div>
                            <div><span>Điểm gần nhất</span><strong><?= $score !== null ? e(format_number((float)$score, 2)) . ' / ' . e(format_number((float)$ex['max_score'], 2)) : 'Chưa có điểm' ?></strong></div>
                            <?php if ($ex['submitted_at'] !== null): ?><div><span>Lần nộp gần nhất</span><strong><?= e(format_datetime((string)$ex['submitted_at'])) ?></strong></div><?php endif; ?>
                        </div>
                        <a class="action" href="<?= e(exercise_url((int)$ex['id'])) ?>">
                            <?= $displayStatus === 'graded' ? 'Xem kết quả bài tập' : ($canSubmitAgain ? 'Xem / làm bài tập' : 'Xem chi tiết bài tập') ?> →
                        </a>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php if ($pagination['total_pages'] > 1): ?>
            <?php $start = max(1, min($pagination['current_page'] - 2, $pagination['total_pages'] - 4)); $end = min($pagination['total_pages'], $start + 4); ?>
            <nav class="pagination" aria-label="Phân trang">
                <?php if ($pagination['has_previous']): ?><a class="page" href="<?= e(exercises_page_url($pagination['previous_page'], $keyword, $status, $courseId)) ?>" aria-label="Trang trước">←</a><?php endif; ?>
                <?php for ($n = $start; $n <= $end; $n++): ?><a class="page <?= $n === $pagination['current_page'] ? 'active' : '' ?>" <?= $n === $pagination['current_page'] ? 'aria-current="page"' : '' ?> href="<?= e(exercises_page_url($n, $keyword, $status, $courseId)) ?>"><?= $n ?></a><?php endfor; ?>
                <?php if ($pagination['has_next']): ?><a class="page" href="<?= e(exercises_page_url($pagination['next_page'], $keyword, $status, $courseId)) ?>" aria-label="Trang sau">→</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</div>
<script>
(function(){
    const sidebar=document.getElementById('sidebar');
    const menu=document.getElementById('menuBtn');
    const overlay=document.getElementById('overlay');
    function toggle(open){sidebar.classList.toggle('open',open);overlay.classList.toggle('open',open);menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',open?'Đóng menu':'Mở menu');}
    menu.addEventListener('click',()=>toggle(!sidebar.classList.contains('open')));
    overlay.addEventListener('click',()=>toggle(false));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')toggle(false)});
    window.addEventListener('resize',()=>{if(window.innerWidth>900)toggle(false)});
})();
</script>
<script>window.BF_LEGACY={role:'student',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
