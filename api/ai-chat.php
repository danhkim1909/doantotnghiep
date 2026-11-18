<?php
/** BrightFuture chatbot API: login + CSRF + per-session quotas + OpenAI server-side only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/ai_chat.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function bf_ai_json(int $http, array $data): never {
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bf_ai_json(405, ['ok' => false, 'message' => 'Chỉ hỗ trợ POST.']);
}
$user = current_user();
if (!$user) bf_ai_json(401, ['ok' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Hãy đăng nhập lại.']);
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || !verify_csrf($csrf)) {
    bf_ai_json(403, ['ok' => false, 'message' => 'Phiên bảo mật không hợp lệ, hãy tải lại trang.']);
}
if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    bf_ai_json(415, ['ok' => false, 'message' => 'Yêu cầu phải ở dạng JSON.']);
}
$raw = file_get_contents('php://input', false, null, 0, 12001);
if (!is_string($raw) || strlen($raw) > 12000) {
    bf_ai_json(413, ['ok' => false, 'message' => 'Nội dung gửi quá dài.']);
}
$data = json_decode($raw, true);
if (!is_array($data)) bf_ai_json(400, ['ok' => false, 'message' => 'Dữ liệu JSON không hợp lệ.']);
$action = $data['action'] ?? 'send';
$lessonId = $data['lesson_id'] ?? 0;
if (!is_int($lessonId) || $lessonId < 0) {
    bf_ai_json(400, ['ok' => false, 'message' => 'Mã bài học không hợp lệ.']);
}
$context = null;
if ($lessonId > 0) {
    try {
        $context = bf_ai_lesson_context(db(), $lessonId, $user);
    } catch (Throwable $e) {
        error_log('[BrightFuture AI] Lesson context: ' . $e->getMessage());
        bf_ai_json(503, ['ok' => false, 'message' => 'Không thể kiểm tra quyền truy cập bài học.']);
    }
    if ($context === null) bf_ai_json(403, ['ok' => false, 'message' => 'Không có quyền truy cập nội dung bài học này.']);
}
$state =& bf_ai_state((int)$user['id'], $lessonId);
if ($action === 'reset') {
    $state['messages'] = [];
    bf_ai_json(200, ['ok' => true, 'messages' => []]);
}
if ($action !== 'send') bf_ai_json(400, ['ok' => false, 'message' => 'Thao tác không hợp lệ.']);
$message = $data['message'] ?? null;
if (!is_string($message) || trim($message) === '' || strlen($message) > 7000) {
    bf_ai_json(422, ['ok' => false, 'message' => 'Câu hỏi phải có nội dung và không quá 7000 byte.']);
}
$message = trim($message);
$now = time();
if ($now - (int)$state['hour_start'] > 3600) {
    $state['hour_start'] = $now;
    $state['hour_count'] = 0;
}
if ($now - (int)$state['last_sent'] < 5) {
    bf_ai_json(429, ['ok' => false, 'message' => 'Hãy chờ ít nhất 5 giây giữa các câu hỏi.']);
}
if ((int)$state['hour_count'] >= 40) {
    bf_ai_json(429, ['ok' => false, 'message' => 'Đã đạt giới hạn 40 câu hỏi/giờ của phiên này.']);
}
// Count API attempts too, including failed upstream calls, to protect the owner's API balance.
$state['hour_count']++;
$state['last_sent'] = $now;
$previous = array_slice($state['messages'], -12);
$input = $previous;
$input[] = ['role' => 'user', 'content' => $message];
$result = bf_ai_call_openai($input, $context);
if (!$result['ok']) bf_ai_json((int)$result['status'], ['ok' => false, 'message' => $result['message']]);
$state['messages'][] = ['role' => 'user', 'content' => $message];
$state['messages'][] = ['role' => 'assistant', 'content' => $result['answer']];
$state['messages'] = array_slice($state['messages'], -20);
bf_ai_json(200, ['ok' => true, 'answer' => $result['answer']]);
