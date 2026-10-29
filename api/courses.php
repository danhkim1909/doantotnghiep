<?php
function bf_array_is_list(array $a): bool { $i=0; foreach($a as $k=>$_){ if($k!==$i++) return false; } return true; }

/**
 * BrightFuture - Course API
 * GET  /api/courses.php?q=&category=&level=&page=&per_page=
 * GET  /api/courses.php?id=123
 * POST /api/courses.php  action=enroll, course_id=123, csrf_token=...
 * PHP 8.0+, MySQL 8, PDO. Requires config and includes previously created.
 */


require_once __DIR__ . '/../includes/course_unlock.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Return a consistent JSON envelope. */
function course_api_send(int $status, string $message, mixed $data = null, array $meta = []): never
{
    $result = [
        'success' => $status >= 200 && $status < 300,
        'message' => $message,
        'data' => $data,
    ];
    if ($meta !== []) {
        $result['meta'] = $meta;
    }
    json_response($result, $status);
}

/** Validate a positive integer without silently accepting partial numbers. */
function course_api_id(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $value = (string) $value;
    if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
        return null;
    }
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $validated === false ? null : $validated;
}

function course_api_text(mixed $value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $length <= $maxLength ? $value : null;
}

/** Do not return internal PDO errors, configuration, or stack traces to clients. */
function course_api_fail_unexpected(Throwable $error): never
{
    error_log('[BrightFuture Course API] ' . $error->getMessage());
    course_api_send(500, 'Có lỗi hệ thống. Vui lòng thử lại sau.');
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        // GET is public; only courses published by teachers are visible.
        $pdo = db();
        $courseIdRaw = $_GET['id'] ?? null;
        if ($courseIdRaw !== null) {
            $courseId = course_api_id($courseIdRaw);
            if ($courseId === null) {
                course_api_send(422, 'ID khóa học không hợp lệ.');
            }

            $stmt = $pdo->prepare(
                "SELECT c.id, c.title, c.description, c.category, c.thumbnail,
                        c.level, c.status, c.created_at, c.updated_at,
                        u.id AS teacher_id, u.full_name AS teacher_name,
                        (SELECT COUNT(*) FROM enrollments e
                         WHERE e.course_id = c.id AND e.status IN ('active','completed')) AS student_count,
                        (SELECT COUNT(*) FROM course_chapters cc
                         WHERE cc.course_id = c.id) AS chapter_count,
                        (SELECT COUNT(*) FROM lessons l
                         JOIN course_chapters cc ON cc.id = l.chapter_id
                         WHERE cc.course_id = c.id AND l.status = 'published') AS lesson_count
                 FROM courses c
                 JOIN users u ON u.id = c.teacher_id
                 WHERE c.id = :id AND c.status = 'published'
                 LIMIT 1"
            );
            $stmt->execute(['id' => $courseId]);
            $course = $stmt->fetch();
            if (!$course) {
                course_api_send(404, 'Không tìm thấy khóa học công khai.');
            }
            $viewer = current_user();
            $viewerId = $viewer && $viewer['role'] === ROLE_STUDENT ? (int)$viewer['id'] : null;
            if (!bf_course_unlocked($pdo, $courseId, $viewerId)) {
                course_api_send(404, 'Khóa học chưa được mở khóa.');
            }

            $course['id'] = (int) $course['id'];
            $course['teacher_id'] = (int) $course['teacher_id'];
            foreach (['student_count', 'chapter_count', 'lesson_count'] as $key) {
                $course[$key] = (int) $course[$key];
            }

            // Explicitly expose only published chapters' published lessons.
            $sections = $pdo->prepare(
                "SELECT cc.id, cc.title, cc.description, cc.position,
                        l.id AS lesson_id, l.title AS lesson_title,
                        l.lesson_type, l.duration_minutes, l.is_preview, l.position AS lesson_position
                 FROM course_chapters cc
                 LEFT JOIN lessons l ON l.chapter_id = cc.id AND l.status = 'published'
                 WHERE cc.course_id = :course_id
                 ORDER BY cc.position ASC, cc.id ASC, l.position ASC, l.id ASC"
            );
            $sections->execute(['course_id' => $courseId]);
            $chapters = [];
            foreach ($sections->fetchAll() as $record) {
                $chapterId = (int) $record['id'];
                if (!isset($chapters[$chapterId])) {
                    $chapters[$chapterId] = [
                        'id' => $chapterId,
                        'title' => $record['title'],
                        'description' => $record['description'],
                        'position' => (int) $record['position'],
                        'lessons' => [],
                    ];
                }
                if ($record['lesson_id'] !== null) {
                    $chapters[$chapterId]['lessons'][] = [
                        'id' => (int) $record['lesson_id'],
                        'title' => $record['lesson_title'],
                        'lesson_type' => $record['lesson_type'],
                        'duration_minutes' => (int) $record['duration_minutes'],
                        'is_preview' => (bool) $record['is_preview'],
                        'position' => (int) $record['lesson_position'],
                    ];
                }
            }
            $course['chapters'] = array_values($chapters);

            $user = current_user();
            $course['is_enrolled'] = $user !== null
                && $user['role'] === ROLE_STUDENT
                && is_enrolled($courseId, (int) $user['id']);
            course_api_send(200, 'Lấy chi tiết khóa học thành công.', $course);
        }

        $search = course_api_text($_GET['q'] ?? '', 100);
        $category = course_api_text($_GET['category'] ?? '', 100);
        $level = course_api_text($_GET['level'] ?? '', 30);
        if ($search === null || $category === null || $level === null) {
            course_api_send(422, 'Bộ lọc vượt giới hạn hoặc không hợp lệ.');
        }
        if ($level !== '' && !in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
            course_api_send(422, 'Trình độ không hợp lệ.');
        }

        $page = course_api_id($_GET['page'] ?? '1');
        $perPage = course_api_id($_GET['per_page'] ?? '12');
        if ($page === null || $perPage === null || $perPage > 50) {
            course_api_send(422, 'Thông số phân trang không hợp lệ (per_page tối đa 50).');
        }

        $viewer = current_user();
        $viewerId = $viewer && $viewer['role'] === ROLE_STUDENT ? (int)$viewer['id'] : null;
        $visibleIds = bf_visible_course_ids($pdo, $viewerId);
        if (!$visibleIds) course_api_send(200, 'Chưa có khóa học đã mở khóa.', [], paginate(0, $page, $perPage));
        $where = ["c.status = 'published'", 'c.id IN (' . implode(',', $visibleIds) . ')'];
        $params = [];
        if ($search !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where[] = "(c.title LIKE :title_q ESCAPE '!' OR c.description LIKE :desc_q ESCAPE '!')";
            $params['title_q'] = $like;
            $params['desc_q'] = $like;
        }
        if ($category !== '') {
            $where[] = 'c.category = :category';
            $params['category'] = $category;
        }
        if ($level !== '') {
            $where[] = 'c.level = :level';
            $params['level'] = $level;
        }
        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM courses c WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pagination = paginate($total, $page, $perPage);
        $stmt = $pdo->prepare(
            "SELECT c.id, c.title, c.description, c.category, c.thumbnail,
                    c.level, c.created_at, c.updated_at,
                    u.id AS teacher_id, u.full_name AS teacher_name,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id
                     AND e.status IN ('active','completed')) AS student_count
             FROM courses c
             INNER JOIN users u ON u.id = c.teacher_id
             WHERE {$whereSql}
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $courses = $stmt->fetchAll();
        foreach ($courses as &$course) {
            $course['id'] = (int) $course['id'];
            $course['teacher_id'] = (int) $course['teacher_id'];
            $course['student_count'] = (int) $course['student_count'];
        }
        unset($course);
        course_api_send(200, 'Lấy danh sách khóa học thành công.', $courses, $pagination);
    }

    if ($method === 'POST') {
        // Accept both regular form posts and JSON bodies.
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($contentType, 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if ($raw === false || strlen($raw) > 1048576) {
                course_api_send(413, 'Dữ liệu gửi lên quá lớn.');
            }
            try {
                $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                course_api_send(400, 'JSON không hợp lệ.');
            }
            if (!is_array($body) || bf_array_is_list($body)) {
                course_api_send(400, 'Nội dung JSON phải là một đối tượng.');
            }
        } elseif (str_starts_with($contentType, 'application/x-www-form-urlencoded')
            || str_starts_with($contentType, 'multipart/form-data')) {
            $body = $_POST;
        } else {
            course_api_send(415, 'Chỉ hỗ trợ JSON hoặc dữ liệu form.');
        }

        // Authentication before performing any modification.
        $user = current_user();
        if ($user === null) {
            course_api_send(401, 'Vui lòng đăng nhập để đăng ký khóa học.');
        }
        if ($user['role'] !== ROLE_STUDENT) {
            course_api_send(403, 'Chỉ học viên được đăng ký khóa học.');
        }
        refresh_auth_session();

        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? null);
        if (!is_string($csrf) || !verify_csrf($csrf)) {
            course_api_send(403, 'CSRF token không hợp lệ.');
        }

        if (($body['action'] ?? null) !== 'enroll') {
            course_api_send(422, 'Hành động không được hỗ trợ.');
        }
        $courseId = course_api_id($body['course_id'] ?? null);
        if ($courseId === null) {
            course_api_send(422, 'ID khóa học không hợp lệ.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the course row so enrolment and publication checks are consistent.
            $stmt = $pdo->prepare('SELECT id, status FROM courses WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $courseId]);
            $course = $stmt->fetch();
            if (!$course || $course['status'] !== 'published') {
                $pdo->rollBack();
                course_api_send(404, 'Khóa học không tồn tại hoặc chưa công khai.');
            }
            if (!bf_course_unlocked($pdo, $courseId, (int)$user['id'])) {
                $pdo->rollBack();
                course_api_send(403, 'Bạn cần hoàn thành khóa học và đạt bài kiểm tra tiên quyết để đăng ký.');
            }

            $stmt = $pdo->prepare(
                'SELECT id, status FROM enrollments
                 WHERE student_id = :student_id AND course_id = :course_id
                 FOR UPDATE'
            );
            $stmt->execute(['student_id' => $user['id'], 'course_id' => $courseId]);
            $existing = $stmt->fetch();
            if ($existing && $existing['status'] !== 'cancelled') {
                $pdo->rollBack();
                course_api_send(409, 'Bạn đã đăng ký khóa học này.', [
                    'course_id' => $courseId,
                    'enrollment_id' => (int) $existing['id'],
                    'status' => $existing['status'],
                ]);
            }

            if ($existing) {
                $stmt = $pdo->prepare(
                    "UPDATE enrollments SET status = 'active', completed_at = NULL,
                     enrolled_at = CURRENT_TIMESTAMP WHERE id = :id"
                );
                $stmt->execute(['id' => $existing['id']]);
                $enrollmentId = (int) $existing['id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO enrollments (student_id, course_id, status)
                     VALUES (:student_id, :course_id, 'active')"
                );
                $stmt->execute(['student_id' => $user['id'], 'course_id' => $courseId]);
                $enrollmentId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            course_api_send(201, 'Đăng ký khóa học thành công.', [
                'enrollment_id' => $enrollmentId,
                'course_id' => $courseId,
                'status' => 'active',
            ]);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Unique(student_id, course_id) prevents duplicated concurrent enrolments.
            if ($exception->getCode() === '23000') {
                course_api_send(409, 'Bạn đã đăng ký khóa học này.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    header('Allow: GET, POST');
    course_api_send(405, 'Phương thức HTTP không được hỗ trợ.');
} catch (Throwable $exception) {
    course_api_fail_unexpected($exception);
}
