
<?php
/**
 * =====================================================
 * BRIGHTFUTURE - PERMISSION MANAGEMENT
 * File: includes/permissions.php
 * PHP 8.0+ / PDO MySQL
 * =====================================================
 */


require_once __DIR__ . '/auth.php';

// =====================================================
// 1. CAC QUYEN HE THONG
// =====================================================

defined('PERMISSION_CREATE_COURSE') ||
    define('PERMISSION_CREATE_COURSE', 'course.create');

defined('PERMISSION_MANAGE_USERS') ||
    define('PERMISSION_MANAGE_USERS', 'users.manage');

defined('PERMISSION_VIEW_REPORTS') ||
    define('PERMISSION_VIEW_REPORTS', 'reports.view');

defined('PERMISSION_ENROLL_COURSE') ||
    define('PERMISSION_ENROLL_COURSE', 'course.enroll');

defined('PERMISSION_MANAGE_ALL_COURSES') ||
    define(
        'PERMISSION_MANAGE_ALL_COURSES',
        'courses.manage_all'
    );

// =====================================================
// 2. KIEM TRA VAI TRO
// =====================================================

function is_student(): bool
{
    return has_role(ROLE_STUDENT);
}

function is_teacher(): bool
{
    return has_role(ROLE_TEACHER);
}

function is_admin(): bool
{
    return has_role(ROLE_ADMIN);
}

// =====================================================
// 3. DANH SACH QUYEN THEO VAI TRO
// =====================================================

function role_permissions(string $role): array
{
    switch ($role) {
        case ROLE_STUDENT:
            return [
                PERMISSION_ENROLL_COURSE
            ];

        case ROLE_TEACHER:
            return [
                PERMISSION_CREATE_COURSE
            ];

        case ROLE_ADMIN:
            return [
                PERMISSION_CREATE_COURSE,
                PERMISSION_MANAGE_USERS,
                PERMISSION_VIEW_REPORTS,
                PERMISSION_MANAGE_ALL_COURSES
            ];

        default:
            return [];
    }
}

// =====================================================
// 4. KIEM TRA MOT QUYEN HE THONG
// =====================================================

function can(string $permission): bool
{
    $role = current_user_role();

    if ($role === null) {
        return false;
    }

    return in_array(
        $permission,
        role_permissions($role),
        true
    );
}

// =====================================================
// 5. TRA LOI TU CHOI TRUY CAP
// =====================================================

function deny_access(
    bool $api = false,
    string $message = 'Bạn không có quyền truy cập.'
): never {
    http_response_code(403);

    if ($api) {
        json_response([
            'success' => false,
            'message' => $message
        ], 403);
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html>';
    echo '<html lang="vi">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>403 - Không có quyền</title>';
    echo '</head>';
    echo '<body>';
    echo '<h2>403 - Truy cập bị từ chối</h2>';
    echo '<p>' . e($message) . '</p>';
    echo '<a href="' . e(app_url()) . '">';
    echo 'Về trang chủ';
    echo '</a>';
    echo '</body></html>';

    exit;
}

// =====================================================
// 6. KIEM TRA REQUEST API
// =====================================================

function is_api_request(): bool
{
    $uri = parse_url(
        $_SERVER['REQUEST_URI'] ?? '/',
        PHP_URL_PATH
    );

    if (!is_string($uri)) {
        return false;
    }

    $apiPath = rtrim(
        app_url('api'),
        '/'
    );

    return $uri === $apiPath
        || str_starts_with($uri, $apiPath . '/');
}

// =====================================================
// 7. YEU CAU MOT HOAC NHIEU VAI TRO
// =====================================================

function require_role(
    string|array $roles
): array {
    $user = require_login();

    $roles = (array) $roles;

    if (!in_array($user['role'], $roles, true)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 8. YEU CAU QUYEN HE THONG
// =====================================================

function require_permission(
    string $permission
): array {
    $user = require_login();

    if (!can($permission)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 9. KIEM TRA CHU SO HUU KHOA HOC
// =====================================================

function can_manage_course(int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }

    $user = current_user();

    if ($user === null) {
        return false;
    }

    // Admin co quyen quan ly tat ca khoa hoc.
    if ($user['role'] === ROLE_ADMIN) {
        return true;
    }

    if ($user['role'] !== ROLE_TEACHER) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT 1
         FROM courses
         WHERE id = :course_id
           AND teacher_id = :teacher_id
         LIMIT 1"
    );

    $stmt->execute([
        'course_id' => $courseId,
        'teacher_id' => $user['id']
    ]);

    return $stmt->fetchColumn() !== false;
}

// =====================================================
// 10. BAT BUOC QUYEN QUAN LY KHOA HOC
// =====================================================

function require_course_manager(
    int $courseId
): array {
    $user = require_login();

    if (!can_manage_course($courseId)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 11. LAY KHOA HOC CUA CHUONG
// =====================================================

function chapter_course_id(
    int $chapterId
): ?int {
    if ($chapterId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT course_id
         FROM course_chapters
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute(['id' => $chapterId]);

    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

// =====================================================
// 12. LAY KHOA HOC CUA BAI GIANG
// =====================================================

function lesson_course_id(
    int $lessonId
): ?int {
    if ($lessonId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT cc.course_id
         FROM lessons l
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         WHERE l.id = :lesson_id
         LIMIT 1"
    );

    $stmt->execute([
        'lesson_id' => $lessonId
    ]);

    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

// =====================================================
// 13. LAY KHOA HOC CUA BAI TAP
// =====================================================

function exercise_course_id(
    int $exerciseId
): ?int {
    if ($exerciseId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT cc.course_id
         FROM exercises ex
         INNER JOIN lessons l
             ON l.id = ex.lesson_id
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         WHERE ex.id = :exercise_id
         LIMIT 1"
    );

    $stmt->execute([
        'exercise_id' => $exerciseId
    ]);

    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

// =====================================================
// 14. QUYEN QUAN LY CHUONG
// =====================================================

function can_manage_chapter(int $chapterId): bool
{
    $courseId = chapter_course_id($chapterId);

    return $courseId !== null
        && can_manage_course($courseId);
}

// =====================================================
// 15. QUYEN QUAN LY BAI GIANG
// =====================================================

function can_manage_lesson(int $lessonId): bool
{
    $courseId = lesson_course_id($lessonId);

    return $courseId !== null
        && can_manage_course($courseId);
}

// =====================================================
// 16. QUYEN QUAN LY BAI TAP
// =====================================================

function can_manage_exercise(int $exerciseId): bool
{
    $courseId = exercise_course_id($exerciseId);

    return $courseId !== null
        && can_manage_course($courseId);
}

// =====================================================
// 17. BAT BUOC QUYEN QUAN LY CHUONG
// =====================================================

function require_chapter_manager(
    int $chapterId
): array {
    $user = require_login();

    if (!can_manage_chapter($chapterId)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 18. BAT BUOC QUYEN QUAN LY BAI GIANG
// =====================================================

function require_lesson_manager(
    int $lessonId
): array {
    $user = require_login();

    if (!can_manage_lesson($lessonId)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 19. BAT BUOC QUYEN QUAN LY BAI TAP
// =====================================================

function require_exercise_manager(
    int $exerciseId
): array {
    $user = require_login();

    if (!can_manage_exercise($exerciseId)) {
        deny_access(is_api_request());
    }

    return $user;
}

// =====================================================
// 20. QUYEN XEM KHOA HOC
// =====================================================

function can_view_course(int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }

    if (can_manage_course($courseId)) {
        return true;
    }

    $stmt = db()->prepare(
        "SELECT status
         FROM courses
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([
        'id' => $courseId
    ]);

    return $stmt->fetchColumn() === 'published';
}

// =====================================================
// 21. QUYEN TRUY CAP BAI GIANG
// =====================================================

function can_view_lesson(int $lessonId): bool
{
    if ($lessonId <= 0) {
        return false;
    }

    // Giao vien so huu / Admin.
    if (can_manage_lesson($lessonId)) {
        return true;
    }

    $stmt = db()->prepare(
        "SELECT
            c.id AS course_id,
            c.status AS course_status,
            l.status AS lesson_status,
            l.is_preview
         FROM lessons l
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         INNER JOIN courses c
             ON c.id = cc.course_id
         WHERE l.id = :lesson_id
         LIMIT 1"
    );

    $stmt->execute([
        'lesson_id' => $lessonId
    ]);

    $lesson = $stmt->fetch();

    if (!$lesson) {
        return false;
    }

    // Khong cong khai bai thuoc khoa hoc ban nhap.
    if (
        $lesson['course_status'] !== 'published'
        || $lesson['lesson_status'] !== 'published'
    ) {
        return false;
    }

    // Cho phep khach xem truoc.
    if ((int) $lesson['is_preview'] === 1) {
        return true;
    }

    // Chi hoc vien da dang ky moi duoc hoc.
    if (!is_student()) {
        return false;
    }

    return is_enrolled(
        (int) $lesson['course_id']
    );
}

// =====================================================
// 22. BAT BUOC QUYEN XEM BAI GIANG
// =====================================================

function require_lesson_access(
    int $lessonId
): void {
    if (!can_view_lesson($lessonId)) {
        deny_access(is_api_request());
    }
}

// =====================================================
// 23. QUYEN NOP BAI TAP
// =====================================================

function can_submit_exercise(
    int $exerciseId
): bool {
    $user = current_user();

    if (
        $user === null
        || $user['role'] !== ROLE_STUDENT
        || $exerciseId <= 0
    ) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT
            ex.id,
            ex.status AS exercise_status,
            ex.deadline,
            ex.max_attempts,
            l.status AS lesson_status,
            c.id AS course_id,
            c.status AS course_status
         FROM exercises ex
         INNER JOIN lessons l
             ON l.id = ex.lesson_id
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         INNER JOIN courses c
             ON c.id = cc.course_id
         WHERE ex.id = :exercise_id
         LIMIT 1"
    );

    $stmt->execute([
        'exercise_id' => $exerciseId
    ]);

    $exercise = $stmt->fetch();

    if (!$exercise) {
        return false;
    }

    if (
        $exercise['course_status'] !== 'published'
        || $exercise['lesson_status'] !== 'published'
        || $exercise['exercise_status'] !== 'published'
    ) {
        return false;
    }

    if (!is_enrolled((int) $exercise['course_id'])) {
        return false;
    }

    // Kiem tra han nop bang thoi gian cua MySQL.
    // Co so du lieu can dung cung timezone.
    if ($exercise['deadline'] !== null) {
        $deadline = strtotime($exercise['deadline']);

        if ($deadline === false || time() > $deadline) {
            return false;
        }
    }

    // Kiem tra so lan nop toi da.
    if ($exercise['max_attempts'] !== null) {
        $stmt = db()->prepare(
            "SELECT COUNT(*)
             FROM exercise_submissions
             WHERE exercise_id = :exercise_id
               AND student_id = :student_id"
        );

        $stmt->execute([
            'exercise_id' => $exerciseId,
            'student_id' => $user['id']
        ]);

        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= (int) $exercise['max_attempts']) {
            return false;
        }
    }

    return true;
}

// =====================================================
// 24. BAT BUOC QUYEN NOP BAI
// =====================================================

function require_exercise_submission(
    int $exerciseId
): array {
    $user = require_role(ROLE_STUDENT);

    if (!can_submit_exercise($exerciseId)) {
        deny_access(
            is_api_request(),
            'Bạn không thể nộp bài tập này.'
        );
    }

    return $user;
}

// =====================================================
// 25. QUYEN XEM BAI NOP
// =====================================================

function can_view_submission(
    int $submissionId
): bool {
    $user = current_user();

    if ($user === null || $submissionId <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT
            es.student_id,
            cc.course_id
         FROM exercise_submissions es
         INNER JOIN exercises ex
             ON ex.id = es.exercise_id
         INNER JOIN lessons l
             ON l.id = ex.lesson_id
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         WHERE es.id = :id
         LIMIT 1"
    );

    $stmt->execute([
        'id' => $submissionId
    ]);

    $submission = $stmt->fetch();

    if (!$submission) {
        return false;
    }

    if ($user['role'] === ROLE_ADMIN) {
        return true;
    }

    // Hoc vien chi xem bai nop cua minh.
    if ($user['role'] === ROLE_STUDENT) {
        return (int) $submission['student_id']
            === $user['id'];
    }

    // Giao vien chi xem bai thuoc khoa hoc minh.
    return $user['role'] === ROLE_TEACHER
        && can_manage_course(
            (int) $submission['course_id']
        );
}

// =====================================================
// 26. QUYEN CHAM BAI NOP
// =====================================================

function can_grade_submission(
    int $submissionId
): bool {
    $user = current_user();

    if (
        $user === null
        || !in_array(
            $user['role'],
            [ROLE_TEACHER, ROLE_ADMIN],
            true
        )
        || $submissionId <= 0
    ) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT cc.course_id
         FROM exercise_submissions es
         INNER JOIN exercises ex
             ON ex.id = es.exercise_id
         INNER JOIN lessons l
             ON l.id = ex.lesson_id
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         WHERE es.id = :id
         LIMIT 1"
    );

    $stmt->execute([
        'id' => $submissionId
    ]);

    $courseId = $stmt->fetchColumn();

    return $courseId !== false
        && can_manage_course((int) $courseId);
}

// =====================================================
// 27. QUYEN CAP NHAT TIEN DO HOC TAP
// =====================================================

function can_update_lesson_progress(
    int $lessonId
): bool {
    $user = current_user();

    if (
        $user === null
        || $user['role'] !== ROLE_STUDENT
        || $lessonId <= 0
    ) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT
            c.id AS course_id,
            c.status AS course_status,
            l.status AS lesson_status
         FROM lessons l
         INNER JOIN course_chapters cc
             ON cc.id = l.chapter_id
         INNER JOIN courses c
             ON c.id = cc.course_id
         WHERE l.id = :lesson_id
         LIMIT 1"
    );

    $stmt->execute([
        'lesson_id' => $lessonId
    ]);

    $lesson = $stmt->fetch();

    if (!$lesson) {
        return false;
    }

    if (
        $lesson['course_status'] !== 'published'
        || $lesson['lesson_status'] !== 'published'
    ) {
        return false;
    }

    return is_enrolled(
        (int) $lesson['course_id']
    );
}

// =====================================================
// 28. QUYEN QUAN LY NGUOI DUNG
// =====================================================

function can_manage_users(): bool
{
    return can(PERMISSION_MANAGE_USERS);
}

// =====================================================
// 29. QUYEN XEM BAO CAO TOAN HE THONG
// =====================================================

function can_view_reports(): bool
{
    return can(PERMISSION_VIEW_REPORTS);
}

// =====================================================
// 30. BAO VE API THEO VAI TRO
// =====================================================

function api_require_role(
    string|array $roles
): array {
    $user = current_user();

    if ($user === null) {
        json_response([
            'success' => false,
            'message' => 'Vui lòng đăng nhập.'
        ], 401);
    }

    if (!in_array(
        $user['role'],
        (array) $roles,
        true
    )) {
        json_response([
            'success' => false,
            'message' => 'Không có quyền truy cập.'
        ], 403);
    }

    refresh_auth_session();

    return $user;
}

// =====================================================
// 31. BAO VE API THEO QUYEN
// =====================================================

function api_require_permission(
    string $permission
): array {
    $user = current_user();

    if ($user === null) {
        json_response([
            'success' => false,
            'message' => 'Vui lòng đăng nhập.'
        ], 401);
    }

    if (!can($permission)) {
        json_response([
            'success' => false,
            'message' => 'Không có quyền thực hiện.'
        ], 403);
    }

    refresh_auth_session();

    return $user;
}

// =====================================================
// 32. YEU CAU PHUONG THUC HTTP
// =====================================================

function api_require_method(
    string $method
): void {
    $method = strtoupper($method);

    $requestMethod = strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );

    if ($requestMethod !== $method) {
        header('Allow: ' . $method);

        json_response([
            'success' => false,
            'message' => 'Phương thức HTTP không hợp lệ.'
        ], 405);
    }
}

// =====================================================
// 33. BAO VE CSRF CHO API DUNG SESSION
// =====================================================

function api_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === '') {
        $token = $_POST['csrf_token'] ?? '';
    }

    if (!is_string($token) || !verify_csrf($token)) {
        json_response([
            'success' => false,
            'message' => 'CSRF token không hợp lệ.'
        ], 403);
    }
}

// =====================================================
// 34. KIEM TRA QUYEN QUAN LY API COURSE
// =====================================================

function api_require_course_manager(
    int $courseId
): array {
    $user = api_require_role([
        ROLE_TEACHER,
        ROLE_ADMIN
    ]);

    if (!can_manage_course($courseId)) {
        json_response([
            'success' => false,
            'message' => 'Không có quyền quản lý khóa học.'
        ], 403);
    }

    return $user;
}

// =====================================================
// END - BRIGHTFUTURE PERMISSIONS
// =====================================================
