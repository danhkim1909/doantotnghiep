<?php
/**
 * ====================================================
 * BRIGHTFUTURE - STUDENT LESSON NOTES API
 * File: api/notes.php
 * PHP 8.0+ / MySQL PDO
 *
 * GET  ?lesson_id=ID : Lấy nội dung ghi chú của học viên cho bài giảng
 * POST JSON {"lesson_id": ID, "content": "..."} : Lưu ghi chú
 * Header yêu cầu: X-CSRF-Token cho POST
 * ====================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$user = current_user();
if (!$user || $user['role'] !== ROLE_STUDENT) {
    json_response(['success' => false, 'message' => 'Bạn cần đăng nhập với vai trò học viên.'], 401);
}

$studentId = (int) $user['id'];
$pdo = db();

// Xử lý GET: Lấy ghi chú bài giảng
if (is_get()) {
    $lessonId = positive_int($_GET['lesson_id'] ?? null);
    if ($lessonId <= 0) {
        json_response(['success' => false, 'message' => 'Mã bài giảng không hợp lệ.'], 400);
    }

    if (!can_view_lesson($lessonId)) {
        json_response(['success' => false, 'message' => 'Bạn không có quyền truy cập bài học này.'], 403);
    }

    $stmt = $pdo->prepare(
        "SELECT content, updated_at 
         FROM bf_student_notes 
         WHERE student_id = :student_id AND lesson_id = :lesson_id 
         LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'lesson_id' => $lessonId
    ]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);

    json_response([
        'success' => true,
        'note' => $note ?: ['content' => '', 'updated_at' => null]
    ]);
}

// Xử lý POST: Lưu ghi chú
if (is_post()) {
    // Kiểm tra CSRF token
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($token) || !verify_csrf($token)) {
        json_response(['success' => false, 'message' => 'CSRF token không hợp lệ hoặc đã hết hạn.'], 403);
    }

    $body = get_json_body();
    $lessonId = positive_int($body['lesson_id'] ?? ($_POST['lesson_id'] ?? null));
    $content = (string) ($body['content'] ?? ($_POST['content'] ?? ''));

    if ($lessonId <= 0) {
        json_response(['success' => false, 'message' => 'Mã bài giảng không hợp lệ.'], 400);
    }

    if (!can_view_lesson($lessonId)) {
        json_response(['success' => false, 'message' => 'Bạn không có quyền truy cập bài học này.'], 403);
    }

    // Giới hạn ghi chú tối đa 50.000 ký tự
    if (mb_strlen($content, 'UTF-8') > 50000) {
        json_response(['success' => false, 'message' => 'Ghi chú vượt quá độ dài tối đa cho phép (50.000 ký tự).'], 422);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO bf_student_notes (student_id, lesson_id, content)
         VALUES (:student_id, :lesson_id, :content)
         ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'lesson_id' => $lessonId,
        'content' => $content
    ]);

    json_response([
        'success' => true,
        'message' => 'Đã lưu ghi chú thành công.',
        'updated_at' => date('Y-m-d H:i:s')
    ]);
}

json_response(['success' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405);
