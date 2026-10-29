<?php
/**
 * BrightFuture - Student Learning Progress API
 * File: api/progress.php
 * PHP >= 8.0, MySQL 8+, PDO
 *
 * GET  ?lesson_id=ID  : student's progress for one lesson
 * GET  ?course_id=ID  : student's progress for a course and its lessons
 * POST JSON {"action":"start|position|complete", "lesson_id":ID,
 *            "position_seconds":N (required for action=position)}
 * CSRF: X-CSRF-Token header (or csrf_token in form POST).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
// Compatibility with older shared permission helpers. Keeps API errors in JSON.
if (!function_exists('api_require_role')) {
    function api_require_role(string $role): array {
        $u=current_user();
        if (!$u) progress_response(401,'Vui lòng đăng nhập.');
        if (($u['role']??'')!==$role) progress_response(403,'Tài khoản không có quyền truy cập.');
        return $u;
    }
}
if (!function_exists('api_require_csrf')) {
    function api_require_csrf(): void {
        $token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($token) || !function_exists('verify_csrf') || !verify_csrf($token)) {
            progress_response(403,'CSRF token không hợp lệ.');
        }
    }
}


/** Send a consistent JSON response using the shared application helper. */
function progress_response(int $status, string $message, mixed $data = null): never
{
    json_response([
        'success' => $status >= 200 && $status < 300,
        'message' => $message,
        'data' => $data,
    ], $status);
}

/** Reject invalid/missing positive integer IDs (no float/scientific notation). */
function progress_array_is_list(array $array): bool {
    $i = 0;
    foreach ($array as $key => $_value) if ($key !== $i++) return false;
    return true;
}

function progress_id(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    return $number === false ? null : $number;
}

/** Whole seconds from 0 to 24h; reject malformed and excessive input. */
function progress_seconds(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }
    $seconds = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 86400],
    ]);
    return $seconds === false ? null : $seconds;
}

/** The student's active/completed enrollment and a published lesson are required. */
function progress_authorized_lesson(PDO $pdo, int $lessonId, int $studentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.title, l.duration_minutes, cc.course_id, c.title AS course_title
         FROM lessons l
         JOIN course_chapters cc ON cc.id = l.chapter_id
         JOIN courses c ON c.id = cc.course_id
         JOIN enrollments e ON e.course_id = c.id
             AND e.student_id = :student_id
             AND e.status IN (\'active\', \'completed\')
         WHERE l.id = :lesson_id
             AND l.status = \'published\'
             AND c.status = \'published\'
         LIMIT 1'
    );
    $stmt->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Check active/completed enrollment and course publication before returning course data. */
function progress_authorized_course(PDO $pdo, int $courseId, int $studentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.title, e.status AS enrollment_status
         FROM courses c
         JOIN enrollments e ON e.course_id = c.id
             AND e.student_id = :student_id
             AND e.status IN (\'active\', \'completed\')
         WHERE c.id = :course_id AND c.status = \'published\'
         LIMIT 1'
    );
    $stmt->execute(['student_id' => $studentId, 'course_id' => $courseId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Normalize progress fields for predictable JSON types. */
function progress_data(?array $row): array
{
    return [
        'status' => $row['status'] ?? 'not_started',
        'last_position_seconds' => (int) ($row['last_position_seconds'] ?? 0),
        'started_at' => $row['started_at'] ?? null,
        'completed_at' => $row['completed_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    progress_response(405, 'Phương thức không được hỗ trợ.');
}

// Both read and write endpoints are restricted to the authenticated student.
$user = api_require_role(ROLE_STUDENT);
$studentId = (int) $user['id'];

try {
    $pdo = db();

    if ($method === 'GET') {
        $lessonId = isset($_GET['lesson_id']) ? progress_id($_GET['lesson_id']) : null;
        $courseId = isset($_GET['course_id']) ? progress_id($_GET['course_id']) : null;

        if ($lessonId !== null && $courseId !== null) {
            progress_response(400, 'Chỉ chọn lesson_id hoặc course_id.');
        }
        if (isset($_GET['lesson_id']) && $lessonId === null
            || isset($_GET['course_id']) && $courseId === null
            || $lessonId === null && $courseId === null) {
            progress_response(422, 'Cần lesson_id hoặc course_id hợp lệ.');
        }

        if ($lessonId !== null) {
            $lesson = progress_authorized_lesson($pdo, $lessonId, $studentId);
            if ($lesson === null) {
                progress_response(404, 'Không tìm thấy bài giảng hoặc bạn chưa có quyền truy cập.');
            }
            $stmt = $pdo->prepare(
                'SELECT status, last_position_seconds, started_at, completed_at, updated_at
                 FROM lesson_progress WHERE student_id = :student_id AND lesson_id = :lesson_id LIMIT 1'
            );
            $stmt->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
            $stored = $stmt->fetch() ?: null;
            progress_response(200, 'Lấy tiến độ bài giảng thành công.', [
                'lesson_id' => $lessonId,
                'course_id' => (int) $lesson['course_id'],
                'progress' => progress_data($stored),
            ]);
        }

        $course = progress_authorized_course($pdo, $courseId, $studentId);
        if ($course === null) {
            progress_response(404, 'Không tìm thấy khóa học hoặc bạn chưa có quyền truy cập.');
        }

        $stmt = $pdo->prepare(
            'SELECT l.id AS lesson_id, l.title AS lesson_title, cc.id AS chapter_id,
                    lp.status, lp.last_position_seconds, lp.started_at,
                    lp.completed_at, lp.updated_at
             FROM course_chapters cc
             JOIN lessons l ON l.chapter_id = cc.id AND l.status = \'published\'
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = :student_id
             WHERE cc.course_id = :course_id
             ORDER BY cc.position, cc.id, l.position, l.id'
        );
        $stmt->execute(['student_id' => $studentId, 'course_id' => $courseId]);
        $lessons = [];
        $completed = 0;
        foreach ($stmt->fetchAll() as $row) {
            $entry = [
                'lesson_id' => (int) $row['lesson_id'],
                'lesson_title' => $row['lesson_title'],
                'chapter_id' => (int) $row['chapter_id'],
                'progress' => progress_data($row),
            ];
            if ($entry['progress']['status'] === 'completed') {
                ++$completed;
            }
            $lessons[] = $entry;
        }
        $total = count($lessons);
        progress_response(200, 'Lấy tiến độ khóa học thành công.', [
            'course_id' => $courseId,
            'course_title' => $course['title'],
            'enrollment_status' => $course['enrollment_status'],
            'total_lessons' => $total,
            'completed_lessons' => $completed,
            'progress_percent' => $total > 0 ? round($completed * 100 / $total, 2) : 0,
            'lessons' => $lessons,
        ]);
    }

    // POST requires a CSRF token from the student's existing PHP session.
    api_require_csrf();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!is_string($contentType) || !preg_match('~^application/json(?:\s*;|$)~i', $contentType)) {
        progress_response(415, 'Yêu cầu POST phải có Content-Type: application/json.');
    }
    // Bounded read; unlike get_json_body(), malformed JSON can be distinguished.
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (!is_string($raw) || strlen($raw) > 1048576) {
        progress_response(413, 'Nội dung yêu cầu vượt quá 1 MB.');
    }
    try {
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        progress_response(400, 'JSON không hợp lệ.');
    }
    if (!is_array($body) || progress_array_is_list($body)) {
        progress_response(400, 'Dữ liệu phải là một JSON object.');
    }
    $action = $body['action'] ?? null;
    $lessonId = progress_id($body['lesson_id'] ?? null);
    if (!is_string($action) || !in_array($action, ['start', 'position', 'complete'], true)
        || $lessonId === null) {
        progress_response(422, 'action hoặc lesson_id không hợp lệ.');
    }
    $position = null;
    $runtimeDuration = null;
    if ($action === 'position') {
        $position = progress_seconds($body['position_seconds'] ?? null);
        if ($position === null) {
            progress_response(422, 'position_seconds phải là số nguyên từ 0 đến 86400.');
        }
        // The HTML5 / YouTube player reports its actual media duration.
        // This corrects manually entered lesson minutes that differ from the video length.
        if (isset($body['duration_seconds'])) {
            $runtimeDuration = progress_seconds($body['duration_seconds']);
            if ($runtimeDuration === null || $runtimeDuration < 5 || $runtimeDuration < $position) {
                progress_response(422, 'Thời lượng thực tế của video không hợp lệ.');
            }
        }
    }

    $pdo->beginTransaction();
    try {
        // Serialize writes for a given student/course with the enrollment row lock.
        // Lock before reading or writing progress; do not trust lesson_id from client.
        $lock = $pdo->prepare(
            'SELECT e.id, l.video_duration_seconds, l.duration_minutes
             FROM lessons l
             JOIN course_chapters cc ON cc.id = l.chapter_id
             JOIN courses c ON c.id = cc.course_id AND c.status = \'published\'
             JOIN enrollments e ON e.course_id = c.id
                  AND e.student_id = :student_id
                  AND e.status IN (\'active\', \'completed\')
             WHERE l.id = :lesson_id AND l.status = \'published\'
             LIMIT 1 FOR UPDATE'
        );
        $lock->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
        $lessonLock = $lock->fetch(PDO::FETCH_ASSOC);
        if ($lessonLock === false) {
            $pdo->rollBack();
            progress_response(404, 'Không tìm thấy bài giảng hoặc bạn chưa có quyền cập nhật.');
        }

        // Unique(student_id, lesson_id) allows a safe insert on first activity.
        $insert = $pdo->prepare(
            'INSERT INTO lesson_progress
                 (student_id, lesson_id, status, last_position_seconds, started_at)
             VALUES (:student_id, :lesson_id, \'in_progress\', 0, NOW())
             ON DUPLICATE KEY UPDATE id = id'
        );
        $insert->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);

        if ($action === 'complete') {
            // A client cannot mark a lesson complete before reaching the video threshold.
            $knownDuration=(int)($lessonLock['video_duration_seconds']??0);
            if($knownDuration<=0){$knownDuration=(int)($lessonLock['duration_minutes']??0)*60;}
            $check=$pdo->prepare('SELECT last_position_seconds FROM lesson_progress WHERE student_id=? AND lesson_id=? LIMIT 1');
            $check->execute([$studentId,$lessonId]);$saved=(int)$check->fetchColumn();
            if($saved<1 || ($knownDuration>0 && $saved < (int)ceil($knownDuration*0.90))){
                $pdo->rollBack();progress_response(422,'Bạn cần xem ít nhất 90% video trước khi hoàn thành bài học.');
            }

            // Video completion alone is insufficient when there is a published quiz.
            $quizGate=$pdo->prepare("SELECT COUNT(*) FROM exercises ex
                WHERE ex.lesson_id=:lid AND ex.exercise_type='quiz' AND ex.status='published'
                  AND NOT EXISTS (SELECT 1 FROM exercise_submissions sub
                     WHERE sub.exercise_id=ex.id AND sub.student_id=:sid
                       AND sub.status='graded' AND sub.score >= ex.max_score * 0.5)");
            $quizGate->execute(['lid'=>$lessonId,'sid'=>$studentId]);
            if ((int)$quizGate->fetchColumn()>0) {
                $pdo->commit();
                progress_response(200,'Đã lưu tiến độ video. Bài học chỉ hoàn thành khi đạt trắc nghiệm (đúng ít nhất 5/10 câu).', [
                    'lesson_id'=>$lessonId,'progress'=>[
                        'status'=>'in_progress','last_position_seconds'=>$saved],
                    'quiz_required'=>true]);
            }

            $update = $pdo->prepare(
                'UPDATE lesson_progress SET
                    status = \'completed\',
                    started_at = COALESCE(started_at, NOW()),
                    completed_at = COALESCE(completed_at, NOW())
                 WHERE student_id = :student_id AND lesson_id = :lesson_id'
            );
            $update->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
        } elseif ($action === 'position') {
            if ($runtimeDuration !== null) {
                // Synchronize player metadata; video_duration_seconds is distinct from
                // the teacher-entered duration_minutes (which can be approximate).
                $sync = $pdo->prepare('UPDATE lessons SET video_duration_seconds=:duration WHERE id=:id');
                $sync->execute(['duration' => $runtimeDuration, 'id' => $lessonId]);
            }
            // A late update must never change completed -> in_progress or rewind video.
            $update = $pdo->prepare(
                'UPDATE lesson_progress SET
                    status = CASE WHEN status = \'completed\'
                                  THEN \'completed\' ELSE \'in_progress\' END,
                    started_at = COALESCE(started_at, NOW()),
                    last_position_seconds = GREATEST(last_position_seconds, :seconds)
                 WHERE student_id = :student_id AND lesson_id = :lesson_id'
            );
            $update->bindValue(':seconds', $position, PDO::PARAM_INT);
            $update->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            $update->bindValue(':lesson_id', $lessonId, PDO::PARAM_INT);
            $update->execute();
        }

        $stmt = $pdo->prepare(
            'SELECT status, last_position_seconds, started_at, completed_at, updated_at
             FROM lesson_progress WHERE student_id = :student_id AND lesson_id = :lesson_id'
        );
        $stmt->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
        $result = progress_data($stmt->fetch() ?: null);
        $pdo->commit();
        progress_response(200, 'Đã lưu tiến độ học tập.', [
            'lesson_id' => $lessonId,
            'progress' => $result,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

} catch (Throwable $e) {
    error_log('[BrightFuture Progress API] ' . $e->getMessage());
    progress_response(500, 'Không thể xử lý tiến độ học tập. Vui lòng thử lại sau.');
}
