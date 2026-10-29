<?php
/** BrightFuture - Teacher submission management (PHP 8+, PDO). */
require_once __DIR__ . '/../includes/permissions.php';
$user = require_role(ROLE_TEACHER);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$teacherId = (int)$user['id'];
$pdo = db();

/** Ensure a submission belongs to a course taught by the current teacher. */
function submission_for_teacher(PDO $pdo, int $submissionId, int $teacherId): ?array {
    $q = $pdo->prepare("SELECT s.*, ex.title AS exercise_title, ex.max_score, ex.exercise_type,
             ex.id AS exercise_id, ex.deadline, l.title AS lesson_title,
             c.id AS course_id, c.title AS course_title, u.full_name AS student_name, u.email AS student_email
        FROM exercise_submissions s
        JOIN exercises ex ON ex.id=s.exercise_id
        JOIN lessons l ON l.id=ex.lesson_id
        JOIN course_chapters ch ON ch.id=l.chapter_id
        JOIN courses c ON c.id=ch.course_id
        JOIN users u ON u.id=s.student_id
        WHERE s.id=:sid AND c.teacher_id=:tid LIMIT 1");
    $q->execute(['sid'=>$submissionId,'tid'=>$teacherId]);
    $row = $q->fetch();
    return $row ?: null;
}
function submission_state_label(string $state): string {
    return match($state) {
        'submitted'=>'Chờ chấm', 'grading'=>'Đang chấm',
        'graded'=>'Đã chấm', 'returned'=>'Trả lại bổ sung', default=>'Không xác định'
    };
}
function submission_link(array $changes = []): string {
    $data = [
        'q' => get_query('q'),
        'course_id' => positive_int($_GET['course_id'] ?? null),
        'exercise_id' => positive_int($_GET['exercise_id'] ?? null),
        'status' => get_query('status', 'all'),
        'page' => positive_int($_GET['page'] ?? null, 1),
    ];
    $data = array_merge($data, $changes);
    return app_url('teacher/submissions.php') . '?' . http_build_query($data);
}

// Secure file serving: never redirect directly to an uploaded file URL.
if (get_query('action') === 'download') {
    $id = positive_int($_GET['id'] ?? null);
    $record = $id ? submission_for_teacher($pdo, $id, $teacherId) : null;
    if (!$record) { http_response_code(404); exit('Không tìm thấy bài nộp.'); }
    $stored = $record['file_path'];
    if (!is_string($stored) || $stored === '') { http_response_code(404); exit('Không có tệp đính kèm.'); }
    $stored = str_replace('\\','/', $stored);
    // Only accept paths anchored within uploads/submissions; reject traversal and control characters.
    if (!preg_match('~^(?:uploads/submissions/)?[a-zA-Z0-9_./-]+$~D', $stored)
        || str_contains($stored, '..') || str_contains($stored, '//')) {
        http_response_code(404); exit('Đường dẫn tệp không hợp lệ.');
    }
    $relative = str_starts_with($stored, 'uploads/submissions/')
        ? substr($stored, strlen('uploads/submissions/')) : $stored;
    $root = realpath(UPLOAD_PATH . '/submissions');
    $real = $root && $relative !== '' ? realpath($root . '/' . $relative) : false;
    if (!$root || !$real || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) {
        http_response_code(404); exit('Không tìm thấy tệp trên máy chủ.');
    }
    $filename = basename($real);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="submission-' . $id . '-' . $filename . '"');
    header('Content-Length: ' . filesize($real));
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

$error = '';
if (is_post()) {
    require_csrf();
    $submissionId = positive_int($_POST['submission_id'] ?? null);
    $action = get_post('action');
    $record = $submissionId ? submission_for_teacher($pdo, $submissionId, $teacherId) : null;
    if (!$record) {
        $error = 'Không tìm thấy bài nộp hoặc bạn không có quyền thao tác.';
    } elseif (!in_array($action, ['save_grade', 'return_revision', 'mark_grading'], true)) {
        $error = 'Thao tác không hợp lệ.';
    } else {
        try {
            $feedback = $_POST['feedback'] ?? '';
            if (!is_string($feedback) || strlen($feedback) > 10000) {
                throw new InvalidArgumentException('Nhận xét không hợp lệ hoặc quá dài (tối đa 10.000 byte).');
            }
            $feedback = trim($feedback);
            if ($action === 'save_grade') {
                $raw = $_POST['score'] ?? null;
                if (!is_string($raw) || !preg_match('/^(?:\d{1,6})(?:\.\d{1,2})?$/D', $raw)) {
                    throw new InvalidArgumentException('Điểm phải là số không âm, tối đa 2 chữ số thập phân.');
                }
                $score = (float)$raw;
                if (!is_finite($score) || $score > (float)$record['max_score']) {
                    throw new InvalidArgumentException('Điểm phải nằm từ 0 đến điểm tối đa của bài tập.');
                }
                $update = $pdo->prepare("UPDATE exercise_submissions s
                    JOIN exercises ex ON ex.id=s.exercise_id
                    JOIN lessons l ON l.id=ex.lesson_id
                    JOIN course_chapters ch ON ch.id=l.chapter_id
                    JOIN courses c ON c.id=ch.course_id
                    SET s.score=:score, s.feedback=:feedback, s.status='graded',
                        s.graded_by=:grader, s.graded_at=NOW()
                    WHERE s.id=:sid AND c.teacher_id=:teacher");
                $update->execute(['score'=>$score,'feedback'=>$feedback,'grader'=>$teacherId,'sid'=>$submissionId,'teacher'=>$teacherId]);
                set_flash('success', 'Đã lưu điểm và nhận xét cho bài nộp.');
            } elseif ($action === 'return_revision') {
                if ($feedback === '') { throw new InvalidArgumentException('Vui lòng nhập lý do trả lại bổ sung.'); }
                $update = $pdo->prepare("UPDATE exercise_submissions s
                    JOIN exercises ex ON ex.id=s.exercise_id
                    JOIN lessons l ON l.id=ex.lesson_id
                    JOIN course_chapters ch ON ch.id=l.chapter_id
                    JOIN courses c ON c.id=ch.course_id
                    SET s.score=NULL, s.feedback=:feedback, s.status='returned',
                        s.graded_by=:grader, s.graded_at=NOW()
                    WHERE s.id=:sid AND c.teacher_id=:teacher");
                $update->execute(['feedback'=>$feedback,'grader'=>$teacherId,'sid'=>$submissionId,'teacher'=>$teacherId]);
                set_flash('success', 'Đã trả bài nộp để học viên bổ sung.');
            } else {
                $update = $pdo->prepare("UPDATE exercise_submissions s
                    JOIN exercises ex ON ex.id=s.exercise_id
                    JOIN lessons l ON l.id=ex.lesson_id
                    JOIN course_chapters ch ON ch.id=l.chapter_id
                    JOIN courses c ON c.id=ch.course_id
                    SET s.status='grading', s.feedback=:feedback,
                        s.score=NULL, s.graded_by=NULL, s.graded_at=NULL
                    WHERE s.id=:sid AND c.teacher_id=:teacher");
                $update->execute(['feedback'=>$feedback,'sid'=>$submissionId,'teacher'=>$teacherId]);
                set_flash('success', 'Đã chuyển bài nộp sang trạng thái đang chấm.');
            }
            redirect('teacher/submissions.php?' . http_build_query(['id'=>$submissionId]));
        } catch (InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('[Teacher submissions update] '.$ex->getMessage());
            $error = 'Không lưu được kết quả. Vui lòng thử lại.';
        }
    }
}

$search = get_query('q');
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
$courseFilter = positive_int($_GET['course_id'] ?? null);
$exerciseFilter = positive_int($_GET['exercise_id'] ?? null);
$statusFilter = get_query('status', 'all');
if (!in_array($statusFilter, ['all','submitted','grading','graded','returned'], true)) $statusFilter = 'all';
$page = positive_int($_GET['page'] ?? null, 1);
$perPage = 10;
$pagination = paginate(0, $page, $perPage);
$rows = $courses = $exercises = [];
$counts = ['total'=>0,'submitted'=>0,'grading'=>0,'graded'=>0,'returned'=>0];
$detail = null;
$dataError = false;
try {
    $q = $pdo->prepare('SELECT id,title FROM courses WHERE teacher_id=:tid ORDER BY title');
    $q->execute(['tid'=>$teacherId]);
    $courses = $q->fetchAll();
    $q = $pdo->prepare("SELECT ex.id,ex.title,c.id AS course_id FROM exercises ex
        JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters ch ON ch.id=l.chapter_id
        JOIN courses c ON c.id=ch.course_id WHERE c.teacher_id=:tid ORDER BY c.title,ex.title");
    $q->execute(['tid'=>$teacherId]);
    $exercises = $q->fetchAll();
    $q = $pdo->prepare("SELECT COUNT(*) total,
       COALESCE(SUM(s.status='submitted'),0) submitted,
       COALESCE(SUM(s.status='grading'),0) grading,
       COALESCE(SUM(s.status='graded'),0) graded,
       COALESCE(SUM(s.status='returned'),0) returned
       FROM exercise_submissions s JOIN exercises ex ON ex.id=s.exercise_id
       JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters ch ON ch.id=l.chapter_id
       JOIN courses c ON c.id=ch.course_id WHERE c.teacher_id=:tid");
    $q->execute(['tid'=>$teacherId]);
    $counts = $q->fetch() ?: $counts;

    $conditions = ['c.teacher_id=:tid'];
    $params = ['tid'=>$teacherId];
    if ($courseFilter) { $conditions[]='c.id=:cid'; $params['cid']=$courseFilter; }
    if ($exerciseFilter) { $conditions[]='ex.id=:eid'; $params['eid']=$exerciseFilter; }
    if ($statusFilter !== 'all') { $conditions[]='s.status=:status'; $params['status']=$statusFilter; }
    if ($search !== '') {
        $conditions[]="(u.full_name LIKE :name OR ex.title LIKE :exercise OR c.title LIKE :course)";
        $term = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        // Explicit ESCAPE is unnecessary for normal searches; escape syntax uses SQL ! here.
        $conditions[count($conditions)-1]="(u.full_name LIKE :name ESCAPE '!' OR ex.title LIKE :exercise ESCAPE '!' OR c.title LIKE :course ESCAPE '!')";
        $params += ['name'=>$term,'exercise'=>$term,'course'=>$term];
    }
    $from = " FROM exercise_submissions s
        JOIN exercises ex ON ex.id=s.exercise_id
        JOIN lessons l ON l.id=ex.lesson_id
        JOIN course_chapters ch ON ch.id=l.chapter_id
        JOIN courses c ON c.id=ch.course_id
        JOIN users u ON u.id=s.student_id";
    $where = ' WHERE '.implode(' AND ', $conditions);
    $q = $pdo->prepare('SELECT COUNT(*)'.$from.$where);
    foreach ($params as $key=>$value) $q->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $q->execute();
    $pagination = paginate((int)$q->fetchColumn(), $page, $perPage);
    $q = $pdo->prepare("SELECT s.id,s.student_id,s.status,s.attempt_number,s.score,s.submitted_at,
           ex.title AS exercise_title,ex.max_score,c.title AS course_title,
           c.id AS course_id,u.full_name AS student_name".$from.$where.
           ' ORDER BY s.submitted_at DESC,s.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key=>$value) $q->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $q->bindValue(':limit',$pagination['per_page'],PDO::PARAM_INT);
    $q->bindValue(':offset',$pagination['offset'],PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll();
    $detailId = positive_int($_GET['id'] ?? null);
    if ($detailId) {
        $detail = submission_for_teacher($pdo, $detailId, $teacherId);
        if ($detail && $detail['exercise_type'] === 'quiz') {
            $q = $pdo->prepare("SELECT question.question_text, opt.option_text
                FROM submission_answers a
                JOIN exercise_questions question ON question.id=a.question_id
                JOIN question_options opt ON opt.id=a.option_id AND opt.question_id=question.id
                WHERE a.submission_id=:id ORDER BY question.position,opt.position");
            $q->execute(['id'=>$detailId]);
            $detail['answers'] = $q->fetchAll();
        }
    }
} catch (Throwable $ex) {
    error_log('[Teacher submissions list] '.$ex->getMessage());
    $dataError = true;
}
$flash = get_flash();
$name = (string)$user['full_name'];
$initial = function_exists('mb_substr') ? mb_substr($name,0,1,'UTF-8') : substr($name,0,1);
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chấm bài học viên | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--pale:#eef2ff;--background:#f6f8fc;--border:#e6eaf2;--muted:#64748b;--text:#172033;--sidebar:255px}
*{box-sizing:border-box}body{margin:0;background:var(--background);color:var(--text);font-family:'Segoe UI',Arial,sans-serif;font-size:14px}a{text-decoration:none;color:inherit}button,input,select,textarea{font:inherit}button{cursor:pointer}.sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:white;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:10}.brand{padding:27px 23px;color:var(--primary);font-size:21px;font-weight:800}.brand span{display:inline-block;background:var(--primary);color:#fff;border-radius:10px;padding:9px;margin-right:7px;font-size:15px}.nav{flex:1;padding:10px 14px;overflow:auto}.nav-caption{font-size:11px;letter-spacing:1px;color:#94a3b8;text-transform:uppercase;font-weight:700;margin:15px 12px 8px}.nav a{display:block;border-radius:11px;padding:13px;color:var(--muted);margin-bottom:5px}.nav a:hover,.nav a.active{color:var(--primary);background:var(--pale)}.foot{border-top:1px solid var(--border);padding:18px}.foot-name{font-weight:700;margin-bottom:13px;overflow:hidden;text-overflow:ellipsis}.logout{width:100%;border:0;border-radius:9px;background:#fff1f2;color:#be123c;padding:12px;font-weight:700}.main{margin-left:var(--sidebar)}.topbar{background:white;border-bottom:1px solid var(--border);height:76px;padding:0 32px;display:flex;align-items:center;justify-content:space-between}.topbar strong{color:var(--muted)}.avatar{background:#ede9fe;color:var(--primary);border-radius:50%;width:38px;height:38px;display:grid;place-items:center;font-weight:700}.account{display:flex;align-items:center;gap:10px}.content{max-width:1500px;margin:auto;padding:32px}.heading{margin-bottom:25px}.heading h1{font-size:29px;margin:0 0 8px;letter-spacing:-.5px}.heading p{color:var(--muted);margin:0;line-height:1.6}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:23px}.stat{background:#fff;border:1px solid var(--border);border-radius:15px;padding:20px}.stat p{margin:0 0 12px;color:var(--muted);font-size:12px}.stat strong{font-size:27px}.panel{background:#fff;border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:22px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:11px}.control{width:100%;padding:12px;border:1px solid #dce2ed;border-radius:10px;background:#fff;min-width:0;color:var(--text)}.control:focus{outline:2px solid #c7d2fe;border-color:var(--primary)}.btn{border:0;border-radius:10px;padding:12px 17px;background:var(--primary);color:white;font-weight:700}.btn.secondary{background:var(--pale);color:var(--primary)}.btn.danger{background:#fff1f2;color:#be123c}.hint{color:var(--muted);font-size:12px;margin-top:13px}.table-wrap{overflow-x:auto}.table{width:100%;border-collapse:collapse;min-width:790px}.table th{color:var(--muted);font-size:12px;text-align:left;background:#f8fafc;padding:13px}.table td{padding:15px 13px;border-top:1px solid var(--border);vertical-align:top}.table td small{display:block;color:var(--muted);margin-top:5px}.table td a{color:var(--primary);font-weight:700}.pill{display:inline-block;border-radius:30px;background:#eff6ff;color:#1d4ed8;padding:6px 10px;font-size:11px;font-weight:700}.pill.graded{background:#dcfce7;color:#166534}.pill.returned{background:#fff1f2;color:#be123c}.pill.grading{background:#fef3c7;color:#92400e}.pagination{display:flex;justify-content:center;gap:7px;margin-top:20px;flex-wrap:wrap}.pagination a,.pagination span{border:1px solid var(--border);padding:9px 13px;border-radius:8px}.pagination .current{background:var(--primary);color:white}.detail-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:20px}.field{margin:0 0 13px}.field strong{display:block;font-size:12px;color:var(--muted);margin-bottom:5px}.field p{margin:0;overflow-wrap:anywhere}.codebox{background:#111827;color:#e5e7eb;white-space:pre-wrap;overflow-wrap:anywhere;max-height:450px;overflow:auto;padding:17px;border-radius:10px;font:13px/1.7 Consolas,monospace}.text-box{white-space:pre-wrap;overflow-wrap:anywhere;max-height:450px;overflow:auto;background:#f8fafc;border-radius:10px;padding:15px}.form-group{margin-bottom:15px}.form-group label{display:block;font-weight:700;margin-bottom:7px}.form-group textarea{min-height:135px;resize:vertical}.actions{display:flex;gap:9px;flex-wrap:wrap}.notice{border-radius:10px;padding:13px 16px;margin-bottom:18px;background:#f0fdf4;color:#166534}.notice.error{background:#fef2f2;color:#991b1b}.empty{text-align:center;color:var(--muted);padding:35px 10px}.mobile-toggle{display:none;border:0;background:none;font-size:25px}.overlay{display:none}
@media(max-width:1250px){.stats{grid-template-columns:repeat(3,1fr)}.filters{grid-template-columns:repeat(2,1fr)}.detail-grid{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%);transition:transform .2s}.sidebar.open{transform:none}.overlay.open{display:block;position:fixed;inset:0;background:#0f172a66;z-index:9}.mobile-toggle{display:block}.main{margin-left:0}.topbar{padding:0 18px}.content{padding:22px}}
@media(max-width:600px){.stats{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr}.panel{padding:15px}.heading h1{font-size:24px}.account .name{display:none}}
</style><link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar">
<a class="brand" href="<?= e(app_url()) ?>"><span>&lt;/&gt;</span>BrightFuture</a>
<nav class="nav"><div class="nav-caption">Giảng dạy</div>
<a href="<?= e(app_url('teacher/dashboard.php')) ?>">▦ Tổng quan</a>
<a href="<?= e(app_url('teacher/courses.php')) ?>">▤ Quản lý khóa học</a>
<a href="<?= e(app_url('teacher/lessons.php')) ?>">▣ Chương và bài giảng</a>
<a href="<?= e(app_url('teacher/exercises.php')) ?>">▧ Quản lý bài tập</a>
<a class="active" aria-current="page" href="<?= e(app_url('teacher/submissions.php')) ?>">✓ Chấm bài học viên</a>
<div class="nav-caption">Cá nhân</div><a href="<?= e(app_url('profile.php')) ?>">☺ Hồ sơ cá nhân</a><a href="<?= e(app_url()) ?>">⌂ Trang chủ</a></nav>
<div class="foot"><div class="foot-name"><?= e($name) ?> · Giáo viên</div><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout">Đăng xuất</button></form></div>
</aside>
<div class="main"><header class="topbar"><div style="display:flex;gap:13px;align-items:center"><button class="mobile-toggle" id="menuToggle" aria-label="Mở menu" aria-expanded="false">☰</button><strong>Không gian giảng dạy</strong></div><div class="account"><span class="name"><?= e($name) ?></span><span class="avatar"><?= e($initial) ?></span></div></header>
<main class="content"><div class="heading"><h1>Quản lý & chấm bài</h1><p>Theo dõi bài nộp, đánh giá kết quả và gửi nhận xét cho học viên trong các khóa học của bạn.</p></div>
<?php if ($flash && isset($flash['message'])): ?><div class="notice <?= ($flash['type'] ?? '') === 'error' ? 'error' : '' ?>" role="status"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($dataError): ?><div class="notice error">Không tải được dữ liệu bài nộp. Vui lòng kiểm tra cơ sở dữ liệu và nhật ký PHP.</div><?php endif; ?>
<section class="stats" aria-label="Thống kê bài nộp">
<?php foreach (['total'=>'Tổng lượt nộp','submitted'=>'Chờ chấm','grading'=>'Đang chấm','graded'=>'Đã chấm','returned'=>'Trả lại bổ sung'] as $key=>$label): ?>
<div class="stat"><p><?= e($label) ?></p><strong><?= format_number((int)($counts[$key] ?? 0)) ?></strong></div>
<?php endforeach; ?></section>
<section class="panel"><form class="filters" method="get" action="<?= e(app_url('teacher/submissions.php')) ?>">
<input class="control" type="search" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Tên học viên, bài tập, khóa học..." aria-label="Tìm kiếm">
<select class="control" name="course_id" aria-label="Khóa học"><option value="0">Tất cả khóa học</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $courseFilter===(int)$c['id']?'selected':'' ?>><?= e($c['title']) ?></option><?php endforeach; ?></select>
<select class="control" name="exercise_id" aria-label="Bài tập"><option value="0">Tất cả bài tập</option><?php foreach ($exercises as $ex): ?><option value="<?= (int)$ex['id'] ?>" <?= $exerciseFilter===(int)$ex['id']?'selected':'' ?>><?= e($ex['title']) ?></option><?php endforeach; ?></select>
<select class="control" name="status" aria-label="Trạng thái"><option value="all">Tất cả trạng thái</option><?php foreach (['submitted','grading','graded','returned'] as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= e(submission_state_label($s)) ?></option><?php endforeach; ?></select>
<button class="btn" type="submit">Lọc</button></form>
<div class="hint">Tìm thấy <?= format_number($pagination['total_items']) ?> bài nộp. <a style="color:var(--primary)" href="<?= e(app_url('teacher/submissions.php')) ?>">Xóa bộ lọc</a></div></section>
<section class="panel"><h2 style="font-size:18px;margin:0 0 17px">Danh sách bài nộp</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Học viên</th><th>Bài tập / Khóa học</th><th>Lần nộp</th><th>Ngày nộp</th><th>Trạng thái</th><th>Điểm</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['student_name']) ?></strong></td><td><strong><?= e($r['exercise_title']) ?></strong><small><?= e($r['course_title']) ?></small></td><td>#<?= (int)$r['attempt_number'] ?></td><td><?= e(format_datetime($r['submitted_at'])) ?></td><td><span class="pill <?= e($r['status']) ?>"><?= e(submission_state_label($r['status'])) ?></span></td><td><?= $r['score']===null?'—':e((string)$r['score']).' / '.e((string)$r['max_score']) ?></td><td><a href="<?= e(submission_link(['id'=>(int)$r['id']])) ?>#detail">Xem & chấm →</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">Chưa có bài nộp phù hợp với bộ lọc.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($pagination['total_pages']>1): ?><nav class="pagination" aria-label="Phân trang bài nộp">
<?php if ($pagination['has_previous']): ?><a href="<?= e(submission_link(['page'=>$pagination['previous_page']])) ?>">← Trước</a><?php endif; ?>
<?php for ($i=max(1,$pagination['current_page']-2);$i<=min($pagination['total_pages'],$pagination['current_page']+2);$i++): ?><a class="<?= $i===$pagination['current_page']?'current':'' ?>" <?= $i===$pagination['current_page']?'aria-current="page"':'' ?> href="<?= e(submission_link(['page'=>$i])) ?>"><?= $i ?></a><?php endfor; ?>
<?php if ($pagination['has_next']): ?><a href="<?= e(submission_link(['page'=>$pagination['next_page']])) ?>">Tiếp →</a><?php endif; ?></nav><?php endif; ?>
</section>
<?php if ($detail): ?><section class="detail-grid" id="detail"><div class="panel"><h2 style="font-size:19px;margin-top:0">Chi tiết bài nộp #<?= (int)$detail['id'] ?></h2>
<div class="field"><strong>Học viên</strong><p><?= e($detail['student_name']) ?> · <?= e($detail['student_email']) ?></p></div>
<div class="field"><strong>Khóa học / bài giảng / bài tập</strong><p><?= e($detail['course_title']) ?> / <?= e($detail['lesson_title']) ?> / <?= e($detail['exercise_title']) ?></p></div>
<div class="field"><strong>Lần nộp / thời điểm / trạng thái</strong><p>#<?= (int)$detail['attempt_number'] ?> · <?= e(format_datetime($detail['submitted_at'])) ?> · <?= e(submission_state_label($detail['status'])) ?></p></div>
<?php if ($detail['submitted_code'] !== null && $detail['submitted_code'] !== ''): ?><div class="field"><strong>Mã nguồn học viên</strong><pre class="codebox"><code><?= e($detail['submitted_code']) ?></code></pre></div><?php endif; ?>
<?php if ($detail['submitted_text'] !== null && $detail['submitted_text'] !== ''): ?><div class="field"><strong>Nội dung trả lời</strong><div class="text-box"><?= e($detail['submitted_text']) ?></div></div><?php endif; ?>
<?php if (!empty($detail['answers'])): ?><div class="field"><strong>Đáp án trắc nghiệm đã chọn</strong><?php foreach ($detail['answers'] as $a): ?><div class="text-box" style="margin-bottom:9px"><b><?= e($a['question_text']) ?></b><p><?= e($a['option_text']) ?></p></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($detail['file_path']): ?><div class="field"><strong>Tệp đính kèm</strong><a class="btn secondary" style="display:inline-block" href="<?= e(app_url('teacher/submissions.php?action=download&id='.(int)$detail['id'])) ?>">↓ Tải tệp bài nộp</a></div><?php endif; ?>
<?php if (!$detail['file_path'] && !$detail['submitted_text'] && !$detail['submitted_code'] && empty($detail['answers'])): ?><p class="hint">Bài nộp không có nội dung có thể hiển thị.</p><?php endif; ?>
</div><div class="panel"><h2 style="font-size:19px;margin-top:0">Đánh giá & phản hồi</h2>
<form method="post" action="<?= e(app_url('teacher/submissions.php')) ?>"><?= csrf_field() ?><input type="hidden" name="submission_id" value="<?= (int)$detail['id'] ?>">
<div class="form-group"><label for="score">Điểm (tối đa <?= e((string)$detail['max_score']) ?>)</label><input class="control" id="score" name="score" type="number" min="0" max="<?= e((string)$detail['max_score']) ?>" step="0.01" value="<?= $detail['score']===null?'':e((string)$detail['score']) ?>" placeholder="Nhập điểm"></div>
<div class="form-group"><label for="feedback">Nhận xét / yêu cầu bổ sung</label><textarea class="control" name="feedback" id="feedback" maxlength="10000" placeholder="Nhập nhận xét cụ thể cho học viên..."><?= e($detail['feedback'] ?? '') ?></textarea></div>
<div class="actions"><button type="submit" class="btn" name="action" value="save_grade">✓ Lưu điểm & chấm xong</button><button type="submit" class="btn secondary" name="action" value="mark_grading">Đang chấm</button><button type="submit" class="btn danger" name="action" value="return_revision" onclick="return document.getElementById('feedback').value.trim()!=='' || (alert('Vui lòng nhập lý do trả lại bổ sung.'),false)">↩ Trả lại bổ sung</button></div>
<p class="hint">Trả lại bổ sung sẽ xóa điểm đã chấm của lần nộp này. Việc cho phép học viên nộp lại phụ thuộc số lần nộp và hạn nộp trong cấu hình bài tập.</p>
</form></div></section>
<?php elseif (positive_int($_GET['id'] ?? null)): ?><div class="notice error">Không tìm thấy bài nộp hoặc bạn không có quyền xem.</div><?php endif; ?>
</main></div>
<script>
const sidebar=document.getElementById('sidebar'),toggle=document.getElementById('menuToggle'),overlay=document.getElementById('overlay');
function menu(open){sidebar.classList.toggle('open',open);overlay.classList.toggle('open',open);toggle.setAttribute('aria-expanded',String(open))}
toggle.addEventListener('click',()=>menu(!sidebar.classList.contains('open')));overlay.addEventListener('click',()=>menu(false));document.addEventListener('keydown',e=>{if(e.key==='Escape')menu(false)});
</script><script>window.BF_LEGACY={role:'teacher',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body></html>
