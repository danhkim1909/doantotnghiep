<?php
/**
 * Hiển thị nội dung bài học có HTML định dạng cơ bản, không cho phép chèn mã.
 * Chỉ phục hồi những thẻ cho phép KHÔNG có thuộc tính sau khi escape toàn bộ.
 * Các thẻ khác và mọi thuộc tính (onclick, style, href, ...) vẫn được escape.
 * Không cần ext-dom hoặc thư viện ngoài; tương thích XAMPP/PHP 8+.
 */
if (!function_exists('bf_render_lesson_content')) {
    function bf_render_lesson_content(?string $content): string
    {
        $content = trim($content ?? '');
        if ($content === '') {
            return '';
        }

        $allowed = 'h[1-6]|p|strong|b|em|i|u|s|ul|ol|li|blockquote|pre|code|br|hr|div|table|thead|tbody|tr|th|td';

        // Hỗ trợ dữ liệu HTML cũ đã lưu dạng &lt;p&gt;...&lt;/p&gt;.
        // Mọi nội dung đều được escape lại bên dưới trước khi phục hồi thẻ an toàn.
        if (preg_match('~&lt;\s*/?\s*(?:' . $allowed . ')\s*/?\s*&gt;~i', $content)) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Văn bản thuần giữ xuống dòng. HTML có thẻ khối dùng cấu trúc HTML riêng.
        $containsBlockHtml = (bool)preg_match('~<\s*/?\s*(?:h[1-6]|p|ul|ol|li|blockquote|pre|div|table|thead|tbody|tr|th|td)\s*/?\s*>~i', $content);
        $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace_callback(
            '~&lt;\s*(/?)\s*(' . $allowed . ')\s*(/?)\s*&gt;~i',
            static function (array $match): string {
                $tag = strtolower($match[2]);
                // br, hr là các thẻ tự đóng.
                if ($tag === 'br' || $tag === 'hr') {
                    return '<' . $tag . '>';
                }
                return '<' . ($match[1] === '/' ? '/' : '') . $tag . '>';
            },
            $escaped
        );
        return $containsBlockHtml ? $escaped : nl2br($escaped);
    }
}
