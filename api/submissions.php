<?php
/**
 * BrightFuture - Student submission REST-style JSON API (PHP 8+, MySQL PDO)
 * GET  ?exercise_id=ID[&page=1&per_page=10]  - own submission history
 * GET  ?id=ID                            - own submission detail
 * POST JSON/multipart action=submit, exercise_id=ID
 *   coding: submitted_code; text: submitted_text;
 *   quiz: answers: {question_id: option_id | [option_id,...]}
 *   file_upload: upload field 'file' (multipart only)
 * CSRF via X-CSRF-Token header, or csrf_token form field.
 * Requires includes/permissions.php and phase3 tables used by teacher/submissions.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/permissions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Vary: Cookie');

function subapi_send(int $status, string $message, mixed $data = null, array $meta = []): never {
    $response = ['success' => $status >= 200 && $status < 300, 'message' => $message, 'data' => $data];
    if ($meta) $response['meta'] = $meta;
    json_response($response, $status);
}
/** PHP 8.0 compatible substitute for array_is_list (introduced in PHP 8.1). */
function subapi_array_is_list(array $array): bool {
    $expected = 0;
    foreach ($array as $key => $_unused) {
        if ($key !== $expected++) return false;
    }
    return true;
}
function subapi_id(mixed $value): ?int {
    if (!is_int($value) && !is_string($value)) return null;
    $v = (string)$value;
    if (!preg_match('/^[1-9][0-9]*$/D', $v)) return null;
    $id = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}
function subapi_text(mixed $v, int $maxBytes, bool $allowEmpty = false): ?string {
    if (!is_string($v) || strlen($v) > $maxBytes) return null;
    $v = trim($v);
    if (!$allowEmpty && $v === '') return null;
    return $v;
}
function subapi_query_limit(mixed $v, int $default, int $max): ?int {
    if ($v === null) return $default;
    $n = subapi_id($v);
    return $n !== null && $n <= $max ? $n : null;
}
function subapi_exercise(PDO $pdo, int $exerciseId, int $studentId, bool $lock = false): ?array {
    $sql = "SELECT ex.id, ex.title, ex.exercise_type, ex.max_score, ex.max_attempts, ex.deadline,
                   ex.status, l.id AS lesson_id, c.id AS course_id, c.title AS course_title,
                   en.status AS enrollment_status
            FROM exercises ex
            JOIN lessons l ON l.id = ex.lesson_id
            JOIN course_chapters ch ON ch.id = l.chapter_id
            JOIN courses c ON c.id = ch.course_id
            JOIN enrollments en ON en.course_id = c.id
                AND en.student_id = :student_id AND en.status IN ('active','completed')
            WHERE ex.id = :exercise_id AND ex.status = 'published'
                AND l.status = 'published' AND c.status = 'published'
            LIMIT 1";
    if ($lock) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['student_id' => $studentId, 'exercise_id' => $exerciseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function subapi_submission(PDO $pdo, int $submissionId, int $studentId): ?array {
    $stmt = $pdo->prepare("SELECT s.id, s.exercise_id, s.student_id, s.attempt_number, s.status,
            s.score, s.feedback, s.submitted_text, s.submitted_code, s.file_path,
            s.submitted_at, s.graded_at, ex.title AS exercise_title,
            ex.exercise_type, ex.max_score, c.id AS course_id, c.title AS course_title
        FROM exercise_submissions s
        JOIN exercises ex ON ex.id = s.exercise_id
        JOIN lessons l ON l.id = ex.lesson_id
        JOIN course_chapters ch ON ch.id = l.chapter_id
        JOIN courses c ON c.id = ch.course_id
        WHERE s.id = :submission_id AND s.student_id = :student_id LIMIT 1");
    $stmt->execute(['submission_id' => $submissionId, 'student_id' => $studentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function subapi_format_submission(array $s, bool $detail = false): array {
    $result = [
        'id' => (int)$s['id'], 'exercise_id' => (int)$s['exercise_id'],
        'attempt_number' => (int)$s['attempt_number'], 'status' => $s['status'],
        'score' => $s['score'] === null ? null : (float)$s['score'],
        'feedback' => $s['feedback'], 'submitted_at' => $s['submitted_at'],
        'graded_at' => $s['graded_at'] ?? null,
    ];
    if ($detail) {
        $result['exercise_title'] = $s['exercise_title'];
        $result['exercise_type'] = $s['exercise_type'];
        $result['max_score'] = (float)$s['max_score'];
        $result['course_id'] = (int)$s['course_id'];
        $result['course_title'] = $s['course_title'];
        $result['submitted_text'] = $s['submitted_text'];
        $result['submitted_code'] = $s['submitted_code'];
        // Do NOT return a direct upload URL/path: uploaded files must be served behind auth.
        $result['has_file'] = $s['file_path'] !== null && $s['file_path'] !== '';
    }
    return $result;
}
/** Validate submitted answers against this exercise's question/options without exposing correctness. */
function subapi_quiz_answers(PDO $pdo, int $exerciseId, mixed $answers): array {
    if (!is_array($answers) || subapi_array_is_list($answers) || count($answers) > 100) {
        subapi_send(422, 'answers phải là object ánh xạ question_id tới option_id hoặc mảng option_id.');
    }
    $q = $pdo->prepare("SELECT q.id AS question_id, q.question_type, o.id AS option_id
        FROM exercise_questions q JOIN question_options o ON o.question_id = q.id
        WHERE q.exercise_id = :exercise_id ORDER BY q.position, q.id, o.position, o.id");
    $q->execute(['exercise_id' => $exerciseId]);
    $questions = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qid = (int)$row['question_id'];
        if (!isset($questions[$qid])) $questions[$qid] = ['type' => $row['question_type'], 'options' => []];
        $questions[$qid]['options'][(int)$row['option_id']] = true;
    }
    if (!$questions) subapi_send(409, 'Bài trắc nghiệm chưa có câu hỏi hợp lệ.');
    if (count($answers) !== count($questions)) subapi_send(422, 'Bạn cần trả lời đầy đủ các câu hỏi.');
    $rows = [];
    foreach ($answers as $rawQid => $rawSelected) {
        $qid = subapi_id($rawQid);
        if ($qid === null || !isset($questions[$qid])) subapi_send(422, 'Câu hỏi không thuộc bài tập.');
        $selected = is_array($rawSelected) && subapi_array_is_list($rawSelected) ? $rawSelected : [$rawSelected];
        if (count($selected) === 0 || count($selected) > 8) subapi_send(422, 'Số đáp án được chọn không hợp lệ.');
        if ($questions[$qid]['type'] === 'single_choice' && count($selected) !== 1) {
            subapi_send(422, 'Câu hỏi một lựa chọn chỉ nhận một đáp án.');
        }
        $seen = [];
        foreach ($selected as $rawOption) {
            $oid = subapi_id($rawOption);
            if ($oid === null || !isset($questions[$qid]['options'][$oid]) || isset($seen[$oid])) {
                subapi_send(422, 'Đáp án không hợp lệ hoặc bị trùng.');
            }
            $seen[$oid] = true;
            $rows[] = [$qid, $oid];
        }
    }
    return $rows;
}
/** Count exactly matched correct answer sets, with no partial credit for a question. */
function subapi_grade_quiz(PDO $pdo, int $exerciseId, array $answers): array {
    $stmt = $pdo->prepare('SELECT q.id AS qid, o.id AS oid, o.is_correct
        FROM exercise_questions q
        JOIN question_options o ON o.question_id = q.id
        WHERE q.exercise_id = :eid ORDER BY q.id, o.id');
    $stmt->execute(['eid' => $exerciseId]);
    $correct = [];
    $all = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qid = (int)$r['qid'];
        $all[$qid] = true;
        if ((int)$r['is_correct'] === 1) $correct[$qid][] = (int)$r['oid'];
    }
    if (count($all) !== 10) {
        subapi_send(409, 'Bài trắc nghiệm cần đúng 10 câu hỏi trước khi mở cho học viên.');
    }
    $selected = [];
    foreach ($answers as $pair) {
        $selected[(int)$pair[0]][] = (int)$pair[1];
    }
    $right = 0;
    foreach (array_keys($all) as $qid) {
        $key = $correct[$qid] ?? [];
        $answer = $selected[$qid] ?? [];
        sort($key, SORT_NUMERIC); sort($answer, SORT_NUMERIC);
        if ($key && $key === $answer) ++$right;
    }
    return ['correct' => $right, 'wrong' => 10 - $right, 'passed' => $right >= 5];
}

/** Check all published quizzes in the lesson have at least one passing graded submission. */
function subapi_all_lesson_quizzes_passed(PDO $pdo, int $lessonId, int $studentId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exercises ex
        WHERE ex.lesson_id = :lid AND ex.exercise_type = 'quiz' AND ex.status = 'published'
          AND NOT EXISTS (SELECT 1 FROM exercise_submissions s
               WHERE s.exercise_id = ex.id AND s.student_id = :sid
                 AND s.status = 'graded' AND s.score >= ex.max_score * 0.5)");
    $stmt->execute(['lid' => $lessonId, 'sid' => $studentId]);
    return (int)$stmt->fetchColumn() === 0;
}

/** MIME AND extension allow-list, no web-executable content, <= 10MiB. */
function subapi_validate_file(mixed $upload): array {
    if (!is_array($upload) || is_array($upload['error'] ?? null)) subapi_send(422, 'Vui lòng chọn một tệp hợp lệ.');
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) subapi_send(413, 'Tệp vượt giới hạn upload của máy chủ.');
    if ($error !== UPLOAD_ERR_OK) subapi_send(422, 'Tệp tải lên không hợp lệ hoặc tải lên thất bại.');
    $tmp = $upload['tmp_name'] ?? null;
    $size = $upload['size'] ?? null;
    $name = $upload['name'] ?? null;
    if (!is_string($tmp) || !is_uploaded_file($tmp) || !is_int($size) || $size < 1 || $size > 10 * 1024 * 1024 || !is_string($name)) {
        subapi_send(422, 'Tệp phải có dung lượng từ 1 byte đến 10 MB.');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
    ];
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
        subapi_send(422, 'Chỉ hỗ trợ PDF, TXT, PNG, JPG, DOCX hoặc ZIP hợp lệ.');
    }
    // Reject polyglot/script disguised as a document for common plain text and image types.
    return ['tmp' => $tmp, 'extension' => $ext, 'size' => $size];
}
/** Upload directory is denied for direct Apache requests; teacher serves through permission check. */
function subapi_prepare_upload_dir(): string {
    $path = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'submissions';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create submission storage.');
    }
    if (is_link($path)) throw new RuntimeException('Symlink upload dir rejected.');
    $deny = $path . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        $contents = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (file_put_contents($deny, $contents, LOCK_EX) === false) throw new RuntimeException('Unable to protect submission storage.');
    }
    return $path;
}
function subapi_check_csrf(array $body): void {
    // api_require_csrf() is the existing JSON-aware authorization helper.
    // For multipart/form data, require_csrf() validates the posted form token.
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        require_csrf();
        return;
    }
    api_require_csrf();
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    subapi_send(405, 'Phương thức không được hỗ trợ.');
}
$user = api_require_role(ROLE_STUDENT);
$studentId = (int)$user['id'];
try {
    $pdo = db();
    if ($method === 'GET') {
        $sid = isset($_GET['id']) ? subapi_id($_GET['id']) : null;
        $eid = isset($_GET['exercise_id']) ? subapi_id($_GET['exercise_id']) : null;
        if (isset($_GET['id']) && $sid === null || isset($_GET['exercise_id']) && $eid === null || ($sid !== null && $eid !== null)) {
            subapi_send(422, 'Chỉ cung cấp id hoặc exercise_id hợp lệ.');
        }
        if ($sid !== null) {
            $s = subapi_submission($pdo, $sid, $studentId);
            if (!$s) subapi_send(404, 'Không tìm thấy bài nộp.');
            $out = subapi_format_submission($s, true);
            if ($s['exercise_type'] === 'quiz') {
                $stmt = $pdo->prepare('SELECT a.question_id, a.option_id FROM submission_answers a WHERE a.submission_id = :sid ORDER BY a.question_id, a.option_id');
                $stmt->execute(['sid' => $sid]);
                $grouped = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $grouped[(string)$r['question_id']][] = (int)$r['option_id'];
                $out['answers'] = $grouped;
            }
            subapi_send(200, 'Đã lấy chi tiết bài nộp.', $out);
        }
        if ($eid === null) subapi_send(422, 'Cần cung cấp id hoặc exercise_id.');
        // No history unless student currently has access to this published exercise.
        if (!subapi_exercise($pdo, $eid, $studentId)) subapi_send(404, 'Không tìm thấy bài tập hoặc quyền truy cập.');
        $page = subapi_query_limit($_GET['page'] ?? null, 1, 1000000);
        $perPage = subapi_query_limit($_GET['per_page'] ?? null, 10, 50);
        if ($page === null || $perPage === null) subapi_send(422, 'Tham số phân trang không hợp lệ.');
        $count = $pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE student_id = :student_id AND exercise_id = :exercise_id');
        $count->execute(['student_id' => $studentId, 'exercise_id' => $eid]);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $stmt = $pdo->prepare('SELECT id,exercise_id,attempt_number,status,score,feedback,submitted_at,graded_at FROM exercise_submissions WHERE student_id = :student_id AND exercise_id = :exercise_id ORDER BY attempt_number DESC,id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':exercise_id', $eid, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map(fn(array $row) => subapi_format_submission($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
        subapi_send(200, 'Đã lấy lịch sử bài nộp.', $items, ['total_items' => $total, 'per_page' => $perPage, 'current_page' => $page, 'total_pages' => $pages]);
    }

    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $multipart = preg_match('~^multipart/form-data(?:\s*;|$)~i', $ct) === 1;
    $json = preg_match('~^application/json(?:\s*;|$)~i', $ct) === 1;
    if (!$multipart && !$json) subapi_send(415, 'POST yêu cầu application/json hoặc multipart/form-data.');
    $body = [];
    if ($multipart) {
        $body = $_POST;
    } else {
        $input = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
        if (!is_string($input) || strlen($input) > 1024 * 1024) subapi_send(413, 'Dữ liệu JSON vượt quá 1 MB.');
        try { $body = json_decode($input, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { subapi_send(400, 'JSON không hợp lệ.'); }
        if (!is_array($body) || subapi_array_is_list($body)) subapi_send(400, 'Yêu cầu phải là JSON object.');
    }
    subapi_check_csrf($body);
    if (($body['action'] ?? null) !== 'submit') subapi_send(422, 'action phải là submit.');
    $exerciseId = subapi_id($body['exercise_id'] ?? null);
    if ($exerciseId === null) subapi_send(422, 'exercise_id không hợp lệ.');

    $code = null; $text = null; $answers = []; $upload = null;
    // Quick authorization before parsing user content.
    $ex = subapi_exercise($pdo, $exerciseId, $studentId);
    if (!$ex) subapi_send(404, 'Bài tập chưa công khai hoặc bạn chưa đăng ký khóa học.');
    switch ($ex['exercise_type']) {
        case 'coding':
            $code = subapi_text($body['submitted_code'] ?? null, 200000);
            if ($code === null) subapi_send(422, 'Mã nguồn bắt buộc và không được vượt 200 KB.');
            break;
        case 'text':
            $text = subapi_text($body['submitted_text'] ?? null, 100000);
            if ($text === null) subapi_send(422, 'Nội dung trả lời bắt buộc và không được vượt 100 KB.');
            break;
        case 'quiz':
            $rawAnswers = $body['answers'] ?? null;
            if ($multipart && is_string($rawAnswers)) {
                if (strlen($rawAnswers) > 100000) subapi_send(413, 'Dữ liệu câu trả lời quá lớn.');
                try { $rawAnswers = json_decode($rawAnswers, true, 32, JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { subapi_send(400, 'Dữ liệu answers không phải JSON hợp lệ.'); }
            }
            $answers = subapi_quiz_answers($pdo, $exerciseId, $rawAnswers);
            break;
        case 'file_upload':
            if (!$multipart) subapi_send(415, 'Bài tập nộp file yêu cầu multipart/form-data.');
            $upload = subapi_validate_file($_FILES['file'] ?? null);
            break;
        default:
            subapi_send(422, 'Loại bài tập chưa được hỗ trợ.');
    }
    $saved = null;
    $pdo->beginTransaction();
    try {
        // Lock enrollment first: serialize concurrent submissions from the same student/course.
        $lock = $pdo->prepare("SELECT en.id FROM enrollments en
            JOIN courses c ON c.id = en.course_id
            WHERE en.student_id = :sid AND en.course_id = :cid
              AND en.status IN ('active','completed') AND c.status='published'
            LIMIT 1 FOR UPDATE");
        $lock->execute(['sid' => $studentId, 'cid' => (int)$ex['course_id']]);
        if (!$lock->fetchColumn()) { $pdo->rollBack(); subapi_send(403, 'Bạn không còn quyền nộp bài cho khóa học.'); }
        // Revalidate publication, deadline and type within the transaction.
        $ex = subapi_exercise($pdo, $exerciseId, $studentId, true);
        if (!$ex) { $pdo->rollBack(); subapi_send(404, 'Bài tập không còn khả dụng.'); }
        if ($ex['exercise_type'] === 'quiz') {
            // Must have watched >=90% of this lesson since the last failed quiz.
            $video = $pdo->prepare('SELECT l.video_duration_seconds,l.duration_minutes,l.lesson_type,l.video_url,l.video_file_path
                FROM lessons l WHERE l.id=? LIMIT 1');
            $video->execute([(int)$ex['lesson_id']]);
            $v = $video->fetch(PDO::FETCH_ASSOC);
            $duration = (int)($v['video_duration_seconds'] ?? 0);
            if ($duration <= 0) $duration = (int)($v['duration_minutes'] ?? 0) * 60;
            $hasVideo = $v && ($v['lesson_type'] === 'video' || $v['lesson_type'] === 'mixed')
                && ($v['video_url'] || $v['video_file_path']);
            if ($hasVideo) {
                $prog = $pdo->prepare('SELECT last_position_seconds FROM lesson_progress
                    WHERE student_id=? AND lesson_id=? LIMIT 1 FOR UPDATE');
                $prog->execute([$studentId,(int)$ex['lesson_id']]);
                $watched = (int)$prog->fetchColumn();
                if ($duration <= 0 || $watched < (int)ceil($duration * .90)) {
                    $pdo->rollBack(); subapi_send(409, 'Bạn cần xem ít nhất 90% video bài học trước khi làm trắc nghiệm. Nếu vừa làm sai, hãy học lại video.');
                }
            }
        }
        $last = $pdo->prepare('SELECT attempt_number,status FROM exercise_submissions WHERE exercise_id=:eid AND student_id=:sid ORDER BY attempt_number DESC,id DESC LIMIT 1 FOR UPDATE');
        $last->execute(['eid' => $exerciseId, 'sid' => $studentId]);
        $previous = $last->fetch(PDO::FETCH_ASSOC);
        $attempt = $previous ? (int)$previous['attempt_number'] + 1 : 1;
        if ($ex['max_attempts'] !== null && $attempt > (int)$ex['max_attempts']) {
            $pdo->rollBack(); subapi_send(409, 'Bạn đã dùng hết số lần nộp cho phép. Hãy liên hệ giáo viên nếu cần bổ sung.');
        }
        if ($ex['deadline'] !== null) {
            $deadlineCheck = $pdo->prepare('SELECT (:deadline >= NOW()) AS allowed');
            $deadlineCheck->execute(['deadline' => $ex['deadline']]);
            if (!(bool)$deadlineCheck->fetchColumn()) {
                $pdo->rollBack(); subapi_send(409, 'Bài tập đã quá hạn nộp.');
            }
        }
        $filePath = null;
        if ($upload !== null) {
            $dir = subapi_prepare_upload_dir();
            $fileName = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
            $target = $dir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($upload['tmp'], $target)) throw new RuntimeException('Unable to save uploaded file.');
            @chmod($target, 0600);
            $saved = $target;
            $filePath = 'uploads/submissions/' . $fileName;
        }
        $insert = $pdo->prepare("INSERT INTO exercise_submissions
            (exercise_id, student_id, attempt_number, submitted_code, submitted_text, file_path, status, submitted_at)
            VALUES (:exercise_id, :student_id, :attempt, :code, :text, :file, 'submitted', NOW())");
        $insert->execute(['exercise_id' => $exerciseId, 'student_id' => $studentId, 'attempt' => $attempt,
            'code' => $code, 'text' => $text, 'file' => $filePath]);
        $submissionId = (int)$pdo->lastInsertId();
        if ($answers !== []) {
            $stmt = $pdo->prepare('INSERT INTO submission_answers (submission_id,question_id,option_id) VALUES (:sid,:qid,:oid)');
            foreach ($answers as [$qid, $oid]) $stmt->execute(['sid' => $submissionId, 'qid' => $qid, 'oid' => $oid]);
        }
        $grading = null;
        if ($ex['exercise_type'] === 'quiz') {
            $grading = subapi_grade_quiz($pdo, $exerciseId, $answers);
            $maxScore = (float)$ex['max_score'];
            $quizScore = round($maxScore * $grading['correct'] / 10, 2);
            $feedback = $grading['passed']
                ? 'Đạt: đúng ' . $grading['correct'] . '/10 câu, sai ' . $grading['wrong'] . ' câu.'
                : 'Chưa đạt: sai ' . $grading['wrong'] . '/10 câu. Bạn cần học lại video và làm lại trắc nghiệm.';
            $graded = $pdo->prepare("UPDATE exercise_submissions SET status='graded',score=:score,feedback=:feedback,graded_at=NOW()
                WHERE id=:id AND student_id=:sid");
            $graded->execute(['score'=>$quizScore,'feedback'=>$feedback,'id'=>$submissionId,'sid'=>$studentId]);
            // A wrong answer must never leave the lesson marked completed.
            if (!$grading['passed']) {
                $reset = $pdo->prepare("UPDATE lesson_progress SET status='in_progress',last_position_seconds=0,
                    completed_at=NULL,started_at=NOW() WHERE student_id=:sid AND lesson_id=:lid");
                $reset->execute(['sid'=>$studentId,'lid'=>(int)$ex['lesson_id']]);
            } elseif (subapi_all_lesson_quizzes_passed($pdo, (int)$ex['lesson_id'], $studentId)) {
                $complete = $pdo->prepare("UPDATE lesson_progress SET status='completed',
                    completed_at=COALESCE(completed_at,NOW()) WHERE student_id=:sid AND lesson_id=:lid");
                $complete->execute(['sid'=>$studentId,'lid'=>(int)$ex['lesson_id']]);
            }
        }
        $pdo->commit();
        if ($grading !== null) {
            subapi_send(201, $feedback, ['id'=>$submissionId,'exercise_id'=>$exerciseId,
                'attempt_number'=>$attempt,'status'=>'graded','score'=>$quizScore,
                'max_score'=>$maxScore,'correct'=>$grading['correct'],'wrong'=>$grading['wrong'],
                'passed'=>$grading['passed'],'must_rewatch'=>!$grading['passed']]);
        }
        subapi_send(201, 'Nộp bài thành công. Giáo viên sẽ đánh giá và phản hồi.', [
            'id' => $submissionId, 'exercise_id' => $exerciseId,
            'attempt_number' => $attempt, 'status' => 'submitted', 'has_file' => $filePath !== null,
        ]);
    } catch (Throwable $err) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($saved !== null && is_file($saved)) @unlink($saved);
        throw $err;
    }
} catch (Throwable $err) {
    error_log('[BrightFuture Submissions API] ' . $err->getMessage());
    subapi_send(500, 'Có lỗi hệ thống khi xử lý bài nộp. Vui lòng thử lại sau.');
}
