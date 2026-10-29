
<?php
if (!function_exists('bf_array_is_list')) { function bf_array_is_list(array $a): bool { $i=0; foreach ($a as $k=>$_) { if ($k !== $i++) return false; } return true; } }


// ====================================================
// 1. LOAD CAU HINH UNG DUNG
// ====================================================

require_once __DIR__ . '/../config/app.php';


// ====================================================
// 2. ESCAPE HTML - CHONG XSS
// ====================================================

if (!function_exists('e')) {

    function e(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}


// ====================================================
// 3. LAM SACH CHUOI VAN BAN
// ====================================================

if (!function_exists('clean_text')) {

    function clean_text(
        ?string $value,
        int $maxLength = 0
    ): string {

        $value = trim($value ?? '');

        // Xoa ky tu dieu khien, giu lai
        // xuong dong va tab.
        $value = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            '',
            $value
        ) ?? '';

        if ($maxLength > 0) {

            if (function_exists('mb_substr')) {
                $value = mb_substr(
                    $value,
                    0,
                    $maxLength,
                    'UTF-8'
                );
            } else {
                $value = substr(
                    $value,
                    0,
                    $maxLength
                );
            }
        }

        return $value;
    }
}


// ====================================================
// 4. LAY DU LIEU POST
// ====================================================

if (!function_exists('get_post')) {

    function get_post(
        string $key,
        string $default = ''
    ): string {

        $value = $_POST[$key] ?? $default;

        if (!is_string($value)) {
            return $default;
        }

        return clean_text($value);
    }
}


// ====================================================
// 5. LAY DU LIEU GET
// ====================================================

if (!function_exists('get_query')) {

    function get_query(
        string $key,
        string $default = ''
    ): string {

        $value = $_GET[$key] ?? $default;

        if (!is_string($value)) {
            return $default;
        }

        return clean_text($value);
    }
}


// ====================================================
// 6. KIEM TRA HTTP METHOD
// ====================================================

if (!function_exists('is_post')) {

    function is_post(): bool
    {
        return strtoupper(
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ) === 'POST';
    }
}

if (!function_exists('is_get')) {

    function is_get(): bool
    {
        return strtoupper(
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ) === 'GET';
    }
}


// ====================================================
// 7. VALIDATE EMAIL
// ====================================================

if (!function_exists('is_valid_email')) {

    function is_valid_email(string $email): bool
    {
        return filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) !== false;
    }
}


// ====================================================
// 8. VALIDATE SO NGUYEN DUONG
// ====================================================

if (!function_exists('positive_int')) {

    function positive_int(
        mixed $value,
        int $default = 0
    ): int {

        if (!is_scalar($value)) {
            return $default;
        }

        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        return $number === false
            ? $default
            : $number;
    }
}


// ====================================================
// 9. TAO CSRF TOKEN
// ====================================================

if (!function_exists('csrf_token')) {

    function csrf_token(): string
    {
        if (
            !isset($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
        ) {
            $_SESSION['csrf_token'] = bin2hex(
                random_bytes(32)
            );
        }

        return $_SESSION['csrf_token'];
    }
}


// ====================================================
// 10. HTML INPUT CHUA CSRF TOKEN
// ====================================================

if (!function_exists('csrf_field')) {

    function csrf_field(): string
    {
        return '<input type="hidden" ' .
            'name="csrf_token" value="' .
            e(csrf_token()) .
            '">';
    }
}


// ====================================================
// 11. XAC MINH CSRF TOKEN
// ====================================================

if (!function_exists('verify_csrf')) {

    function verify_csrf(?string $token = null): bool
    {
        if ($token === null) {

            $value = $_POST['csrf_token'] ?? null;

            $token = is_string($value)
                ? $value
                : '';
        }

        $sessionToken =
            $_SESSION['csrf_token'] ?? null;

        if (
            !is_string($sessionToken)
            || $sessionToken === ''
            || $token === ''
        ) {
            return false;
        }

        return hash_equals(
            $sessionToken,
            $token
        );
    }
}


// ====================================================
// 12. BAT BUOC KIEM TRA CSRF
// ====================================================

if (!function_exists('require_csrf')) {

    function require_csrf(): void
    {
        if (!is_post() || !verify_csrf()) {

            http_response_code(403);

            exit(
                'Yêu cầu không hợp lệ hoặc ' .
                'phiên làm việc đã hết hạn.'
            );
        }
    }
}


// ====================================================
// 13. FLASH MESSAGE
// LUU THONG BAO VAO SESSION
// ====================================================

if (!function_exists('set_flash')) {

    function set_flash(
        string $type,
        string $message
    ): void {

        $allowedTypes = [
            'success',
            'error',
            'warning',
            'info'
        ];

        if (!in_array(
            $type,
            $allowedTypes,
            true
        )) {
            $type = 'info';
        }

        $_SESSION['flash_message'] = [
            'type' => $type,
            'message' => $message
        ];
    }
}


// ====================================================
// 14. LAY FLASH MESSAGE
// TU DONG XOA SAU KHI DOC
// ====================================================

if (!function_exists('get_flash')) {

    function get_flash(): ?array
    {
        $flash =
            $_SESSION['flash_message'] ?? null;

        unset($_SESSION['flash_message']);

        return is_array($flash)
            ? $flash
            : null;
    }
}


// ====================================================
// 15. HIEN THI FLASH MESSAGE
// ====================================================

if (!function_exists('render_flash')) {

    function render_flash(): string
    {
        $flash = get_flash();

        if ($flash === null) {
            return '';
        }

        $type = $flash['type'] ?? 'info';

        $message = $flash['message'] ?? '';

        $allowedTypes = [
            'success',
            'error',
            'warning',
            'info'
        ];

        if (
            !is_string($type)
            || !in_array($type, $allowedTypes, true)
        ) {
            $type = 'info';
        }

        if (!is_string($message)) {
            $message = '';
        }

        return '<div class="alert alert-' .
            e($type) .
            '" role="alert">' .
            e($message) .
            '</div>';
    }
}


// ====================================================
// 16. TAO DUONG DAN TAI NGUYEN
// ====================================================

if (!function_exists('asset_url')) {

    function asset_url(string $path): string
    {
        return app_url($path);
    }
}


// ====================================================
// 17. DINH DANG NGAY
// ====================================================

if (!function_exists('format_date')) {

    function format_date(
        ?string $date,
        string $format = 'd/m/Y'
    ): string {

        if (
            $date === null
            || trim($date) === ''
        ) {
            return '';
        }

        try {

            $datetime = new DateTimeImmutable(
                $date
            );

            return $datetime->format($format);

        } catch (Exception $e) {

            return '';
        }
    }
}


// ====================================================
// 18. DINH DANG NGAY GIO
// ====================================================

if (!function_exists('format_datetime')) {

    function format_datetime(
        ?string $date
    ): string {

        return format_date(
            $date,
            'd/m/Y H:i'
        );
    }
}


// ====================================================
// 19. RUT GON VAN BAN
// ====================================================

if (!function_exists('truncate_text')) {

    function truncate_text(
        string $text,
        int $length = 120
    ): string {

        if ($length <= 0) {
            return '';
        }

        if (function_exists('mb_strlen')) {

            if (
                mb_strlen($text, 'UTF-8')
                <= $length
            ) {
                return $text;
            }

            return mb_substr(
                $text,
                0,
                $length,
                'UTF-8'
            ) . '...';
        }

        if (strlen($text) <= $length) {
            return $text;
        }

        return substr(
            $text,
            0,
            $length
        ) . '...';
    }
}


// ====================================================
// 20. DINH DANG SO
// ====================================================

if (!function_exists('format_number')) {

    function format_number(
        int|float $number,
        int $decimals = 0
    ): string {

        return number_format(
            $number,
            max(0, $decimals),
            ',',
            '.'
        );
    }
}


// ====================================================
// 21. PHAN TRANG
// ====================================================

if (!function_exists('paginate')) {

    function paginate(
        int $totalItems,
        int $currentPage = 1,
        int $perPage = 10
    ): array {

        $totalItems = max(0, $totalItems);

        $perPage = max(1, $perPage);

        $totalPages = max(
            1,
            (int) ceil(
                $totalItems / $perPage
            )
        );

        $currentPage = max(
            1,
            min(
                $currentPage,
                $totalPages
            )
        );

        $offset =
            ($currentPage - 1) * $perPage;

        return [
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'has_previous' => $currentPage > 1,
            'has_next' =>
                $currentPage < $totalPages,
            'previous_page' =>
                max(1, $currentPage - 1),
            'next_page' =>
                min($totalPages, $currentPage + 1)
        ];
    }
}


// ====================================================
// 22. KIEM TRA DUNG LUONG UPLOAD
// ====================================================

if (!function_exists('is_valid_upload_size')) {

    function is_valid_upload_size(
        int $fileSize
    ): bool {

        return $fileSize > 0
            && $fileSize <= MAX_UPLOAD_SIZE;
    }
}


// ====================================================
// 23. LAY PHAN MO RONG FILE
// ====================================================

if (!function_exists('file_extension')) {

    function file_extension(
        string $filename
    ): string {

        return strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );
    }
}


// ====================================================
// 24. TAO TEN FILE NGAU NHIEN
// ====================================================

if (!function_exists('generate_filename')) {

    function generate_filename(
        string $extension = ''
    ): string {

        $filename = bin2hex(
            random_bytes(16)
        );

        $extension = strtolower(
            ltrim($extension, '.')
        );

        if ($extension !== '') {

            if (!preg_match(
                '/^[a-z0-9]{1,12}$/',
                $extension
            )) {
                throw new InvalidArgumentException(
                    'Phần mở rộng file không hợp lệ.'
                );
            }

            $filename .= '.' . $extension;
        }

        return $filename;
    }
}


// ====================================================
// 25. TRA VE JSON CHO API
// ====================================================

if (!function_exists('json_response')) {

    function json_response(
        array $data,
        int $statusCode = 200
    ): never {

        http_response_code($statusCode);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header('Cache-Control: no-store');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        exit;
    }
}


// ====================================================
// 26. DOC JSON REQUEST BODY
// ====================================================

if (!function_exists('get_json_body')) {

    function get_json_body(): array
    {
        $contentType =
            $_SERVER['CONTENT_TYPE'] ?? '';

        if (
            !is_string($contentType)
            || !preg_match(
                '~^application/json(?:\s*;|$)~i',
                $contentType
            )
        ) {
            return [];
        }

        $contentLength =
            (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        // Gioi han JSON body: 1 MB
        if ($contentLength > 1048576) {
            return [];
        }

        $raw = file_get_contents(
            'php://input',
            false,
            null,
            0,
            1048577
        );

        if (
            $raw === false
            || strlen($raw) > 1048576
        ) {
            return [];
        }

        $data = json_decode(
            $raw,
            true
        );

        return is_array($data)
            && bf_array_is_list($data) === false
            ? $data
            : [];
    }
}


// ====================================================
// 27. KIEM TRA ID HOP LE
// ====================================================

if (!function_exists('valid_id')) {

    function valid_id(
        mixed $id
    ): bool {

        return positive_int($id) > 0;
    }
}


// ====================================================
// 28. URL KHOA HOC
// ====================================================

if (!function_exists('course_url')) {

    function course_url(int $courseId): string
    {
        return app_url(
            'course.php?id=' . $courseId
        );
    }
}


// ====================================================
// 29. URL BAI GIANG
// ====================================================

if (!function_exists('lesson_url')) {

    function lesson_url(int $lessonId): string
    {
        return app_url(
            'lesson.php?id=' . $lessonId
        );
    }
}


// ====================================================
// 30. URL BAI TAP
// ====================================================

if (!function_exists('exercise_url')) {

    function exercise_url(
        int $exerciseId
    ): string {

        return app_url(
            'exercise.php?id=' . $exerciseId
        );
    }
}

// ====================================================
// END - COMMON FUNCTIONS
// ====================================================
