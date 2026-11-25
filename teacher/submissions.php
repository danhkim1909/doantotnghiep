<?php
/** BrightFuture - Teacher submission management (PHP 8+, PDO). */
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/submission_storage.php';
require_once __DIR__ . '/../classes/Models/Exercise.php';

use BrightFuture\Models\Exercise;

$user = require_role(ROLE_TEACHER);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$teacherId = (int)$user['id'];
$pdo = db();
$exerciseModel = new Exercise($pdo);

/** Ensure a submission belongs to a course taught by the current teacher. */
function submission_for_teacher(PDO $pdo, int $submissionId, int $teacherId): ?array {
    global $exerciseModel;
    if ($exerciseModel instanceof Exercise) {
        return $exerciseModel->getSubmissionForTeacher($submissionId, $teacherId);
    }
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
    $real = bf_submission_resolve_file($stored);
    if ($real === null) {
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
                $exerciseModel->saveTeacherGrade($submissionId, $teacherId, $score, $feedback);
                set_flash('success', 'Đã lưu điểm và nhận xét cho bài nộp.');
            } elseif ($action === 'return_revision') {
                if ($feedback === '') { throw new InvalidArgumentException('Vui lòng nhập lý do trả lại bổ sung.'); }
                $exerciseModel->returnTeacherRevision($submissionId, $teacherId, $feedback);
                set_flash('success', 'Đã trả bài nộp để học viên bổ sung.');
            } else {
                $exerciseModel->markTeacherGrading($submissionId, $teacherId, $feedback);
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
    $courses = $exerciseModel->getTeacherCourses($teacherId);
    $exercises = $exerciseModel->getTeacherExercises($teacherId);
    $counts = $exerciseModel->getTeacherSubmissionsCount($teacherId);
    $gradeAnalytics = $exerciseModel->getTeacherGradeDistribution($teacherId, $courseFilter, $exerciseFilter);

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

$pageTitle = 'Chấm bài học viên';
$pageDescription = 'Theo dõi bài nộp, đánh giá kết quả và gửi nhận xét cho học viên trên BrightFuture';
$activeMenu = 'submissions';
$extraCss = ['css/bf-teacher-submissions.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($user, 'submissions') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content ts-content"><div class="heading"><h1>Quản lý & chấm bài</h1><p>Theo dõi bài nộp, đánh giá kết quả và gửi nhận xét cho học viên trong các khóa học của bạn.</p></div>
<?php if ($flash && isset($flash['message'])): ?><div class="notice <?= ($flash['type'] ?? '') === 'error' ? 'error' : '' ?>" role="status"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($dataError): ?><div class="notice error">Không tải được dữ liệu bài nộp. Vui lòng kiểm tra cơ sở dữ liệu và nhật ký PHP.</div><?php endif; ?>
<section class="stats" aria-label="Thống kê bài nộp">
<?php foreach (['total'=>'Tổng lượt nộp','submitted'=>'Chờ chấm','grading'=>'Đang chấm','graded'=>'Đã chấm','returned'=>'Trả lại bổ sung'] as $key=>$label): ?>
<div class="stat"><p><?= e($label) ?></p><strong><?= format_number((int)($counts[$key] ?? 0)) ?></strong></div>
<?php endforeach; ?></section>

<?php if (!empty($gradeAnalytics) && $gradeAnalytics['total_graded'] > 0): ?>
<section class="panel bf-grade-analytics" aria-label="Phân tích phổ điểm" style="margin-bottom: 22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <div>
            <h2 style="font-size:18px;margin:0 0 4px">Phổ điểm bài tập (Grade Analytics)</h2>
            <p style="margin:0;font-size:13px;color:var(--muted)">Thống kê dựa trên <?= format_number($gradeAnalytics['total_graded']) ?> bài nộp đã được chấm điểm</p>
        </div>
        <div style="background:#eef2ff;padding:8px 16px;border-radius:12px;display:inline-flex;align-items:center;gap:8px">
            <span style="font-size:13px;font-weight:600;color:#4338ca">Điểm trung bình:</span>
            <strong style="font-size:18px;color:#4f46e5;font-variant-numeric:tabular-nums"><?= $gradeAnalytics['average_score'] ?> / 10</strong>
        </div>
    </div>
    <div class="grade-bars-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
        <?php foreach ($gradeAnalytics['buckets'] as $bucket): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="font-size:12px;font-weight:700;color:var(--text)"><?= e($bucket['label']) ?></span>
                    <strong style="font-size:14px;color:<?= $bucket['color'] ?>"><?= $bucket['count'] ?> <small style="font-size:11px;color:var(--muted)">(<?= $bucket['percent'] ?>%)</small></strong>
                </div>
                <div style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                    <div style="height:100%;width:<?= $bucket['percent'] ?>%;background:<?= $bucket['color'] ?>;border-radius:99px;transition:width 0.4s ease"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
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
<div class="actions">
    <button type="submit" class="btn" name="action" value="save_grade" style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('check') ?> Lưu điểm & chấm xong</button>
    <button type="submit" class="btn secondary" name="action" value="mark_grading" style="display:inline-flex;align-items:center;gap:6px"><?= bf_icon('clock') ?> Đang chấm</button>
    <button type="submit" class="btn danger" name="action" value="return_revision" style="display:inline-flex;align-items:center;gap:6px" onclick="return document.getElementById('feedback').value.trim()!=='' || (alert('Vui lòng nhập lý do trả lại bổ sung.'),false)"><?= bf_icon('undo') ?> Trả lại bổ sung</button>
</div>
<p class="hint">Trả lại bổ sung sẽ xóa điểm đã chấm của lần nộp này. Việc cho phép học viên nộp lại phụ thuộc số lần nộp và hạn nộp trong cấu hình bài tập.</p>
</form></div></section>
<?php elseif (positive_int($_GET['id'] ?? null)): ?><div class="notice error">Không tìm thấy bài nộp hoặc bạn không có quyền xem.</div><?php endif; ?>
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
