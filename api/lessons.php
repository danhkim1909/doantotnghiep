<?php
/**
 * BrightFuture - Lessons API (PHP 8+, MySQL 8)
 * GET /api/lessons.php?course_id=1&page=1&per_page=20[&chapter_id=2]
 * GET /api/lessons.php?id=10
 * GET /api/lessons.php?lesson_id=10   (alias)
 *
 * Lists chapter/lesson metadata; details return protected learning content.
 * No answer keys, draft content or private URLs are published to unauthorized users.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lessons_api_reply(int $status, string $message, mixed $data = null, array $meta = []): never
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

/** Require canonical positive decimal integer. */
function lessons_api_positive_id(mixed $raw): ?int
{
    if (!is_int($raw) && !is_string($raw)) {
        return null;
    }
    if (!preg_match('/^[1-9][0-9]*$/D', (string) $raw)) {
        return null;
    }
    $number = filter_var((string) $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $number === false ? null : (int) $number;
}

/** Determine course management rights without exposing drafts to other accounts. */
function lessons_api_manager(array $user, int $teacherId): bool
{
    return $user['role'] === ROLE_ADMIN
        || ($user['role'] === ROLE_TEACHER && (int) $user['id'] === $teacherId);
}

/** Course owner/admin can see drafts; students only with active/completed enrollment. */
function lessons_api_access(int $courseId, int $teacherId): array
{
    $user = current_user();
    $manager = $user !== null && lessons_api_manager($user, $teacherId);
    $enrolled = $user !== null
        && $user['role'] === ROLE_STUDENT
        && is_enrolled($courseId, (int) $user['id']);
    return [
        'manager' => $manager,
        'enrolled' => $enrolled,
        'user' => $user,
    ];
}

/** Safe pagination for the number of lessons (chapters are grouping metadata). */
function lessons_api_pages(mixed $pageRaw, mixed $sizeRaw): array
{
    $page = lessons_api_positive_id($pageRaw);
    $size = lessons_api_positive_id($sizeRaw);
    if ($page === null || $size === null || $size > 50) {
        lessons_api_reply(422, 'Phân trang không hợp lệ. per_page tối đa 50.');
    }
    if ($page > intdiv(PHP_INT_MAX, $size)) {
        lessons_api_reply(422, 'Số trang quá lớn.');
    }
    return [$page, $size, ($page - 1) * $size];
}

/** Build public lesson metadata, never include content or video URL. */
function lessons_api_lesson_summary(array $row, bool $manager, bool $enrolled): array
{
    $preview = (bool) $row['is_preview'];
    return [
        'id' => (int) $row['id'],
        'chapter_id' => (int) $row['chapter_id'],
        'title' => $row['title'],
        'lesson_type' => $row['lesson_type'],
        'position' => (int) $row['position'],
        'duration_minutes' => (int) $row['duration_minutes'],
            'video_source' => $row['video_source'] ?? 'none',
            'video_duration_seconds' => (int) ($row['video_duration_seconds'] ?? 0),
            'video_stream_url' => ($row['video_source'] ?? '') === 'upload' ? app_url('api/video.php?lesson_id=' . (int)$row['id']) : null,
        'is_preview' => $preview,
        'status' => $row['status'],
        'can_view_content' => $manager || $enrolled || $preview,
    ];
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET') {
        header('Allow: GET');
        lessons_api_reply(405, 'Chỉ hỗ trợ phương thức GET.');
    }

    $singleRaw = $_GET['id'] ?? $_GET['lesson_id'] ?? null;
    $courseRaw = $_GET['course_id'] ?? null;
    if ($singleRaw !== null && $courseRaw !== null) {
        lessons_api_reply(422, 'Chỉ truyền id hoặc course_id, không truyền đồng thời.');
    }
    if ($singleRaw === null && $courseRaw === null) {
        lessons_api_reply(400, 'Thiếu id bài giảng hoặc course_id.');
    }

    $pdo = db();

    // =============================================================
    // GET DETAIL: verify course/lesson statuses and access FIRST.
    // =============================================================
    if ($singleRaw !== null) {
        $lessonId = lessons_api_positive_id($singleRaw);
        if ($lessonId === null) {
            lessons_api_reply(422, 'ID bài giảng không hợp lệ.');
        }

        $stmt = $pdo->prepare(
            "SELECT l.id, l.chapter_id, l.title, l.content, l.lesson_type,
                    l.video_url, l.video_source, l.video_duration_seconds, l.code_language, l.code_example,
                    l.duration_minutes, l.position, l.is_preview, l.status,
                    l.created_at, l.updated_at,
                    cc.title AS chapter_title, cc.position AS chapter_position,
                    c.id AS course_id, c.title AS course_title,
                    c.status AS course_status, c.teacher_id
             FROM lessons l
             INNER JOIN course_chapters cc ON cc.id = l.chapter_id
             INNER JOIN courses c ON c.id = cc.course_id
             WHERE l.id = :lesson_id LIMIT 1"
        );
        $stmt->execute(['lesson_id' => $lessonId]);
        $row = $stmt->fetch();
        if (!$row) {
            lessons_api_reply(404, 'Không tìm thấy bài giảng.');
        }

        $courseId = (int) $row['course_id'];
        $access = lessons_api_access($courseId, (int) $row['teacher_id']);
        $manager = $access['manager'];
        $published = $row['course_status'] === 'published' && $row['status'] === 'published';
        // Do not leak whether unpublished material exists to non-owners.
        if (!$manager && !$published) {
            lessons_api_reply(404, 'Không tìm thấy bài giảng công khai.');
        }
        if (!$manager && !$access['enrolled'] && !(bool) $row['is_preview']) {
            lessons_api_reply(
                $access['user'] === null ? 401 : 403,
                $access['user'] === null
                    ? 'Vui lòng đăng nhập và đăng ký khóa học để xem bài này.'
                    : 'Bạn cần đăng ký khóa học để xem bài giảng.'
            );
        }

        $detail = [
            'id' => (int) $row['id'],
            'course_id' => $courseId,
            'course_title' => $row['course_title'],
            'chapter_id' => (int) $row['chapter_id'],
            'chapter_title' => $row['chapter_title'],
            'title' => $row['title'],
            'lesson_type' => $row['lesson_type'],
            'content' => $row['content'],
            'video_url' => ($row['video_source'] ?? '') === 'upload' ? null : $row['video_url'],
            'video_source' => $row['video_source'] ?? 'none',
            'video_duration_seconds' => (int) ($row['video_duration_seconds'] ?? 0),
            'video_stream_url' => ($row['video_source'] ?? '') === 'upload' ? app_url('api/video.php?lesson_id=' . (int)$row['id']) : null,
            'code_language' => $row['code_language'],
            'code_example' => $row['code_example'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'position' => (int) $row['position'],
            'is_preview' => (bool) $row['is_preview'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];

        $resourceStmt = $pdo->prepare(
            "SELECT id, title, resource_type, resource_url, created_at
             FROM lesson_resources WHERE lesson_id = :lesson_id
             ORDER BY id ASC"
        );
        $resourceStmt->execute(['lesson_id' => $lessonId]);
        $detail['resources'] = [];
        foreach ($resourceStmt->fetchAll() as $resource) {
            $resource['id'] = (int) $resource['id'];
            $detail['resources'][] = $resource;
        }

        // Only exercise summaries. Never transmit question_options.is_correct.
        // Preview guests can see exercise titles, not the submission/answer data.
        $exerciseStmt = $pdo->prepare(
            'SELECT id, title, description, exercise_type, max_score,
                    deadline, max_attempts, position, status
             FROM exercises
             WHERE lesson_id = :lesson_id'
            . ($manager ? '' : " AND status = 'published'")
            . ' ORDER BY position ASC, id ASC'
        );
        $exerciseStmt->execute(['lesson_id' => $lessonId]);
        $detail['exercises'] = [];
        foreach ($exerciseStmt->fetchAll() as $exercise) {
            $exercise['id'] = (int) $exercise['id'];
            $exercise['position'] = (int) $exercise['position'];
            $exercise['max_score'] = (float) $exercise['max_score'];
            $exercise['max_attempts'] = $exercise['max_attempts'] === null
                ? null : (int) $exercise['max_attempts'];
            $detail['exercises'][] = $exercise;
        }

        lessons_api_reply(200, 'Lấy chi tiết bài giảng thành công.', $detail);
    }

    // =============================================================
    // GET COURSE LESSONS: public metadata grouped by chapter.
    // Draft course stays invisible to non-owner. Other draft lessons
    // are not listed for students/guests.
    // =============================================================
    $courseId = lessons_api_positive_id($courseRaw);
    if ($courseId === null) {
        lessons_api_reply(422, 'ID khóa học không hợp lệ.');
    }
    [$page, $perPage, $offset] = lessons_api_pages(
        $_GET['page'] ?? '1',
        $_GET['per_page'] ?? '20'
    );

    $chapterId = null;
    if (array_key_exists('chapter_id', $_GET)) {
        $chapterId = lessons_api_positive_id($_GET['chapter_id']);
        if ($chapterId === null) {
            lessons_api_reply(422, 'ID chương học không hợp lệ.');
        }
    }

    $courseStmt = $pdo->prepare(
        'SELECT id, title, status, teacher_id FROM courses WHERE id = :id LIMIT 1'
    );
    $courseStmt->execute(['id' => $courseId]);
    $course = $courseStmt->fetch();
    if (!$course) {
        lessons_api_reply(404, 'Không tìm thấy khóa học.');
    }
    $access = lessons_api_access($courseId, (int) $course['teacher_id']);
    $manager = $access['manager'];
    if (!$manager && $course['status'] !== 'published') {
        lessons_api_reply(404, 'Không tìm thấy khóa học công khai.');
    }

    $chaptersStmt = $pdo->prepare(
        'SELECT id, title, description, position
         FROM course_chapters WHERE course_id = :course_id
         ORDER BY position ASC, id ASC'
    );
    $chaptersStmt->execute(['course_id' => $courseId]);
    $chapterRows = $chaptersStmt->fetchAll();
    if ($chapterId !== null) {
        $matches = array_filter($chapterRows, static fn(array $c): bool => (int) $c['id'] === $chapterId);
        if ($matches === []) {
            lessons_api_reply(404, 'Không tìm thấy chương thuộc khóa học.');
        }
    }

    $where = 'cc.course_id = :course_id';
    $params = ['course_id' => $courseId];
    if (!$manager) {
        $where .= " AND l.status = 'published'";
    }
    if ($chapterId !== null) {
        $where .= ' AND cc.id = :chapter_id';
        $params['chapter_id'] = $chapterId;
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lessons l
         INNER JOIN course_chapters cc ON cc.id = l.chapter_id
         WHERE $where"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));

    $listStmt = $pdo->prepare(
        "SELECT l.id, l.chapter_id, l.title, l.lesson_type,
                l.duration_minutes, l.video_source, l.video_duration_seconds, l.position, l.is_preview, l.status
         FROM lessons l
         INNER JOIN course_chapters cc ON cc.id = l.chapter_id
         WHERE $where
         ORDER BY cc.position ASC, cc.id ASC, l.position ASC, l.id ASC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $listStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $grouped = [];
    foreach ($chapterRows as $chapter) {
        $id = (int) $chapter['id'];
        if ($chapterId !== null && $id !== $chapterId) {
            continue;
        }
        $grouped[$id] = [
            'id' => $id,
            'title' => $chapter['title'],
            'description' => $chapter['description'],
            'position' => (int) $chapter['position'],
            'lessons' => [],
        ];
    }
    foreach ($listStmt->fetchAll() as $lesson) {
        $chapter = (int) $lesson['chapter_id'];
        $grouped[$chapter]['lessons'][] = lessons_api_lesson_summary(
            $lesson, $manager, $access['enrolled']
        );
    }

    lessons_api_reply(200, 'Lấy danh sách bài giảng thành công.', [
        'course' => [
            'id' => $courseId,
            'title' => $course['title'],
            'status' => $course['status'],
        ],
        'is_enrolled' => $access['enrolled'],
        'can_manage' => $manager,
        'chapters' => array_values($grouped),
    ], [
        'total_items' => $total,
        'per_page' => $perPage,
        'current_page' => $page,
        'total_pages' => $pages,
        'has_previous' => $page > 1,
        'has_next' => $page < $pages,
    ]);

} catch (Throwable $exception) {
    error_log('[BrightFuture Lessons API] ' . $exception->getMessage());
    lessons_api_reply(500, 'Lỗi hệ thống khi tải bài giảng. Vui lòng thử lại sau.');
}
