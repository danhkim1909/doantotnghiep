
<?php
/**
 * ====================================================
 * BRIGHTFUTURE - APPLICATION CONFIGURATION
 * File: config/app.php
 * PHP thuần + MySQL
 * ====================================================
 */


// ====================================================
// 1. THONG TIN UNG DUNG
// ====================================================

defined('APP_NAME') ||
    define('APP_NAME', 'BrightFuture');

defined('APP_FULL_NAME') ||
    define(
        'APP_FULL_NAME',
        'BrightFuture - Website học lập trình trực tuyến'
    );

defined('APP_DESCRIPTION') ||
    define(
        'APP_DESCRIPTION',
        'Nền tảng học lập trình trực tuyến dành cho học viên và giáo viên.'
    );

defined('APP_VERSION') ||
    define('APP_VERSION', '1.0.0');

// development | production
defined('APP_ENV') ||
    define('APP_ENV', 'development');

defined('APP_DEBUG') ||
    define(
        'APP_DEBUG',
        APP_ENV === 'development'
    );


// ====================================================
// 2. MUI GIO
// ====================================================

date_default_timezone_set('Asia/Ho_Chi_Minh');


// ====================================================
// 3. DUONG DAN THU MUC
// ====================================================

// Thu muc goc cua du an tren may chu
defined('BASE_PATH') ||
    define(
        'BASE_PATH',
        dirname(__DIR__)
    );

// Thu muc cau hinh
defined('CONFIG_PATH') ||
    define(
        'CONFIG_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'config'
    );

// Thu muc includes
defined('INCLUDES_PATH') ||
    define(
        'INCLUDES_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'includes'
    );

// Thu muc upload
defined('UPLOAD_PATH') ||
    define(
        'UPLOAD_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'uploads'
    );


// ====================================================
// 4. CAU HINH URL
// ====================================================

// Sua gia tri nay neu doi ten thu muc du an.
//
// Khong them dau / o cuoi.
//
// Vi du:
// /BrightFuture-Educational-Website-main
//
// Neu website chay tai ten mien goc:
// dat BASE_URL = ''

defined('BASE_URL') ||
    define(
        'BASE_URL',
        '/BrightFuture-Educational-Website-main'
    );


// ====================================================
// 5. HAM TAO URL NOI BO
// ====================================================

if (!function_exists('app_url')) {

    function app_url(string $path = ''): string
    {
        $base = rtrim(BASE_URL, '/');
        $path = ltrim($path, '/');

        if ($path === '') {
            return $base === '' ? '/' : $base . '/';
        }

        return $base . '/' . $path;
    }
}


// ====================================================
// 6. HAM CHUYEN HUONG
// ====================================================

if (!function_exists('redirect')) {

    function redirect(
        string $path = '',
        int $statusCode = 302
    ): void {

        if (!in_array(
            $statusCode,
            [301, 302, 303, 307, 308],
            true
        )) {
            $statusCode = 302;
        }

        // Chi cho phep duong dan noi bo.
        // Chan URL tuyet doi va protocol-relative URL.
        $path = str_replace('\\', '/', $path);

        if (
            str_starts_with($path, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
            || preg_match('/[\x00-\x1F\x7F]/', $path)
        ) {
            throw new InvalidArgumentException(
                'Đường dẫn chuyển hướng không hợp lệ.'
            );
        }

        header(
            'Location: ' . app_url($path),
            true,
            $statusCode
        );

        exit;
    }
}


// ====================================================
// 7. CAU HINH SESSION
// ====================================================

defined('SESSION_NAME') ||
    define(
        'SESSION_NAME',
        'BRIGHTFUTURE_SESSION'
    );

// 2 gio khong hoat dong
defined('SESSION_LIFETIME') ||
    define(
        'SESSION_LIFETIME',
        7200
    );


// ====================================================
// 8. KHOI TAO SESSION AN TOAN
// ====================================================

if (!function_exists('startAppSession')) {

    function startAppSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Không thể khởi tạo Session vì HTTP headers đã được gửi.'
            );
        }

        $isHttps = (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        );

        session_name(SESSION_NAME);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => BASE_URL === ''
                ? '/'
                : rtrim(BASE_URL, '/') . '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'Không thể khởi tạo Session.'
            );
        }

        // Het han sau 2 gio khong hoat dong
        $now = time();

        if (
            isset($_SESSION['_last_activity'])
            && $now - (int) $_SESSION['_last_activity']
                > SESSION_LIFETIME
        ) {
            $_SESSION = [];

            session_regenerate_id(true);
        }

        $_SESSION['_last_activity'] = $now;
    }
}


// ====================================================
// 9. PHAN QUYEN
// ====================================================

defined('ROLE_STUDENT') ||
    define('ROLE_STUDENT', 'student');

defined('ROLE_TEACHER') ||
    define('ROLE_TEACHER', 'teacher');

defined('ROLE_ADMIN') ||
    define('ROLE_ADMIN', 'admin');

defined('USER_ROLES') ||
    define(
        'USER_ROLES',
        [
            ROLE_STUDENT,
            ROLE_TEACHER,
            ROLE_ADMIN
        ]
    );


// ====================================================
// 10. DUONG DAN DASHBOARD THEO VAI TRO
// ====================================================

if (!function_exists('dashboard_url')) {

    function dashboard_url(string $role): string
    {
        switch ($role) {

            case ROLE_ADMIN:
                return app_url('admin/dashboard.php');

            case ROLE_TEACHER:
                return app_url('teacher/dashboard.php');

            case ROLE_STUDENT:
                return app_url('student/dashboard.php');

            default:
                return app_url('login.php');
        }
    }
}


// ====================================================
// 11. CAU HINH UPLOAD
// ====================================================

// Gioi han file 10MB
defined('MAX_UPLOAD_SIZE') ||
    define(
        'MAX_UPLOAD_SIZE',
        10 * 1024 * 1024
    );

defined('ALLOWED_IMAGE_EXTENSIONS') ||
    define(
        'ALLOWED_IMAGE_EXTENSIONS',
        ['jpg', 'jpeg', 'png', 'webp']
    );

defined('ALLOWED_DOCUMENT_EXTENSIONS') ||
    define(
        'ALLOWED_DOCUMENT_EXTENSIONS',
        ['pdf', 'docx', 'pptx', 'txt']
    );

defined('ALLOWED_CODE_EXTENSIONS') ||
    define(
        'ALLOWED_CODE_EXTENSIONS',
        [
            'py',
            'java',
            'cpp',
            'c',
            'js',
            'php',
            'html',
            'css',
            'sql',
            'txt'
        ]
    );


// ====================================================
// 12. CAU HINH HOC TAP
// ====================================================

defined('COURSES_PER_PAGE') ||
    define('COURSES_PER_PAGE', 12);

defined('LESSONS_PER_PAGE') ||
    define('LESSONS_PER_PAGE', 20);

defined('USERS_PER_PAGE') ||
    define('USERS_PER_PAGE', 20);

defined('DEFAULT_COURSE_LEVEL') ||
    define(
        'DEFAULT_COURSE_LEVEL',
        'beginner'
    );


// ====================================================
// 13. XU LY LOI
// ====================================================

// Luon ghi log loi
ini_set('log_errors', '1');

// Khong hien loi nhay cam tren production
ini_set(
    'display_errors',
    APP_DEBUG ? '1' : '0'
);

// Khong hien loi khoi dong tren production
ini_set(
    'display_startup_errors',
    APP_DEBUG ? '1' : '0'
);

error_reporting(
    APP_DEBUG ? E_ALL : 0
);


// ====================================================
// 14. SECURITY HEADERS
// ====================================================

if (!function_exists('setSecurityHeaders')) {

    function setSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');

        header('X-Frame-Options: SAMEORIGIN');

        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}


// ====================================================
// 15. KHOI TAO UNG DUNG
// ====================================================

// Cau hinh session truoc khi xuat HTML
startAppSession();

// Thiet lap security headers
setSecurityHeaders();
