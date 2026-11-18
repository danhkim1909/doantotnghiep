<?php
/** Chatbot inside BrightFuture; only logged-in users can open. */
declare(strict_types=1);
require_once __DIR__ . '/includes/ai_chat.php';
$user = require_login();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
$lessonId = positive_int($_GET['lesson_id'] ?? 0);
$lesson = null;
if ($lessonId) {
    try { $lesson = bf_ai_lesson_context(db(), $lessonId, $user); }
    catch (Throwable $e) { error_log('[BrightFuture AI page] ' . $e->getMessage()); }
    if (!$lesson) $lessonId = 0;
}
$state =& bf_ai_state((int)$user['id'], $lessonId);
$messages = $state['messages'];
$available = trim((string)env_value('OPENAI_API_KEY', '')) !== '';
require_once __DIR__ . '/includes/portal_ui.php';
bf_portal_begin($user, 'ai', 'Trợ giảng AI');
?>
<section class="ai-shell" aria-label="Chatbot học tập BrightFuture">
    <div class="ai-heading">
        <div class="ai-icon">✦</div>
        <div class="ai-heading-text"><span class="ai-kicker">BRIGHTFUTURE AI</span><h1>Trợ giảng AI</h1><p>Hỏi đáp lập trình, gỡ lỗi và ôn tập kiến thức cùng AI.</p></div>
        <button class="ai-reset" id="aiReset" type="button">＋ Trò chuyện mới</button>
    </div>
    <?php if ($lesson): ?>
        <div class="ai-context" role="note"><span>📘 Đang hỏi theo bài học</span>
            <strong><?= e($lesson['lesson']) ?></strong><small><?= e($lesson['course']) ?></small>
            <a href="<?= e(app_url(($user['role'] === 'student') ? 'student/learn.php?id=' . $lessonId : (($user['role'] === 'teacher') ? 'teacher/lessons.php?course_id=' . (int)$lesson['course_id'] : 'admin/courses.php'))) ?>">Xem bài học</a>
        </div>
    <?php endif; ?>
    <?php if (!$available): ?>
        <div class="ai-warning" role="alert">Chưa thiết lập API key. Quản trị viên cần cấu hình <code>OPENAI_API_KEY</code> trong tệp <code>.env</code> của máy chủ.</div>
    <?php endif; ?>
    <div class="ai-messages" id="aiMessages" aria-live="polite" aria-label="Nội dung trò chuyện">
        <?php if (!$messages): ?>
            <div class="ai-empty" id="aiEmpty"><div class="ai-hero-symbol">✧</div><h2>Hôm nay bạn muốn học gì?</h2><p>Đặt câu hỏi về Python, HTML, CSS, JavaScript hoặc bài học đang xem.</p>
                <div class="ai-prompts"><button type="button" data-prompt="Giải thích cho tôi vòng lặp for trong Python bằng ví dụ dễ hiểu.">Giải thích vòng lặp Python</button><button type="button" data-prompt="Hướng dẫn tôi cách tìm nguyên nhân lỗi trong code PHP.">Gỡ lỗi code PHP</button><button type="button" data-prompt="Giúp tôi lập kế hoạch ôn tập kiến thức lập trình cơ bản.">Lập kế hoạch ôn tập</button></div>
            </div>
        <?php else: foreach ($messages as $entry): ?>
            <div class="ai-message <?= ($entry['role'] ?? '') === 'user' ? 'ai-user' : 'ai-bot' ?>"><div class="ai-bubble"><span class="ai-name"><?= ($entry['role'] ?? '') === 'user' ? 'Bạn' : 'Trợ giảng AI' ?></span><div class="ai-text"><?= e((string)($entry['content'] ?? '')) ?></div></div></div>
        <?php endforeach; endif; ?>
    </div>
    <div class="ai-bottom">
        <div class="ai-error" id="aiError" role="alert" hidden></div>
        <form id="aiForm" class="ai-form"><label for="aiInput" class="ai-visually-hidden">Nhập câu hỏi</label><textarea id="aiInput" rows="2" maxlength="7000" placeholder="Viết câu hỏi của bạn... (Enter để gửi, Shift + Enter xuống dòng)" <?= $available ? '' : 'disabled' ?> required></textarea><button type="submit" id="aiSend" <?= $available ? '' : 'disabled' ?> aria-label="Gửi câu hỏi">Gửi <span>➤</span></button></form>
        <p class="ai-footnote">AI có thể mắc lỗi. Hãy kiểm tra các đáp án quan trọng. Nội dung bạn gửi và ngữ cảnh bài học (nếu có) được chuyển tới OpenAI để trả lời. Lịch sử chỉ lưu trong phiên đăng nhập.</p>
    </div>
</section>
<script>
window.BF_AI_CONFIG = <?= json_encode([
    'api' => app_url('api/ai-chat.php'), 'csrf' => csrf_token(), 'lessonId' => $lessonId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(app_url('js/bf-ai-chat.js')) ?>" defer></script>
<?php bf_portal_end(); ?>
