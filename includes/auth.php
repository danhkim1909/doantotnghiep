
<?php


// ==================================================
// 1. LOAD CAC FILE CAN THIET
// ==================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';


// ==================================================
// 2. CAU HINH XAC THUC
// ==================================================

// Doi Session ID sau moi 15 phut
defined('AUTH_REGENERATE_INTERVAL') ||
    define('AUTH_REGENERATE_INTERVAL', 900);

// Gioi han do dai email
defined('AUTH_MAX_EMAIL_LENGTH') ||
    define('AUTH_MAX_EMAIL_LENGTH', 190);

// Gioi han kich thuoc mat khau dau vao
defined('AUTH_MAX_PASSWORD_BYTES') ||
    define('AUTH_MAX_PASSWORD_BYTES', 4096);


// ==================================================
// 3. LAY ID NGUOI DUNG TU SESSION
// ==================================================

if (!function_exists('current_user_id')) {

    function current_user_id(): ?int
    {
        $id = $_SESSION['auth_user_id'] ?? null;

        $id = positive_int($id);

        return $id > 0 ? $id : null;
    }
}


// ==================================================
// 4. KIEM TRA NGUOI DUNG DANG NHAP
// ==================================================

if (!function_exists('is_logged_in')) {

    function is_logged_in(): bool
    {
        return current_user() !== null;
    }
}


// ==================================================
// 5. KIEM TRA KHACH CHUA DANG NHAP
// ==================================================

if (!function_exists('is_guest')) {

    function is_guest(): bool
    {
        return !is_logged_in();
    }
}


// ==================================================
// 6. LAY THONG TIN USER HIEN TAI
// ==================================================

if (!function_exists('current_user')) {

    function current_user(): ?array
    {
        static $cachedUser = null;
        static $cachedId = null;
        static $loaded = false;

        $userId = current_user_id();

        if ($userId === null) {
            $cachedUser = null;
            $cachedId = null;
            $loaded = false;
            return null;
        }

        // Chi truy van 1 lan cho cung user
        // trong mot HTTP request.
        if ($loaded && $cachedId === $userId) {
            return $cachedUser;
        }

        $cachedId = $userId;
        $cachedUser = null;
        $loaded = true;

        $stmt = db()->prepare(
            "SELECT
                id,
                full_name,
                email,
                role,
                created_at,
                updated_at
             FROM users
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $userId
        ]);

        $user = $stmt->fetch();

        if (!$user) {
            unset(
                $_SESSION['auth_user_id'],
                $_SESSION['auth_logged_in_at'],
                $_SESSION['auth_last_regenerated']
            );

            return null;
        }

        // Tu choi vai tro khong hop le
        if (!in_array(
            $user['role'],
            USER_ROLES,
            true
        )) {
            unset(
                $_SESSION['auth_user_id'],
                $_SESSION['auth_logged_in_at'],
                $_SESSION['auth_last_regenerated']
            );

            return null;
        }

        $user['id'] = (int) $user['id'];

        $cachedUser = $user;

        return $cachedUser;
    }
}


// ==================================================
// 7. LAY VAI TRO NGUOI DUNG
// ==================================================

if (!function_exists('current_user_role')) {

    function current_user_role(): ?string
    {
        $user = current_user();

        return $user !== null
            ? $user['role']
            : null;
    }
}


// ==================================================
// 8. LAY TEN NGUOI DUNG
// ==================================================

if (!function_exists('current_user_name')) {

    function current_user_name(): ?string
    {
        $user = current_user();

        return $user !== null
            ? $user['full_name']
            : null;
    }
}


// ==================================================
// 9. LAY EMAIL NGUOI DUNG
// ==================================================

if (!function_exists('current_user_email')) {

    function current_user_email(): ?string
    {
        $user = current_user();

        return $user !== null
            ? $user['email']
            : null;
    }
}


// ==================================================
// 10. KIEM TRA EMAIL VA MAT KHAU
// ==================================================

if (!function_exists('attempt_login')) {

    function attempt_login(
        string $email,
        string $password
    ): bool {

        $email = trim($email);

        // Khong trim password:
        // khoang trang co the la mot phan mat khau.
        if (
            $email === ''
            || $password === ''
            || strlen($email) > AUTH_MAX_EMAIL_LENGTH
            || strlen($password) > AUTH_MAX_PASSWORD_BYTES
            || !is_valid_email($email)
        ) {
            return false;
        }

        $stmt = db()->prepare(
            "SELECT
                id,
                password_hash,
                role
             FROM users
             WHERE email = :email
             LIMIT 1"
        );

        $stmt->execute([
            'email' => $email
        ]);

        $user = $stmt->fetch();

        if (!$user) {
            // Hash gia de giam khac biet thoi gian
            // giua email ton tai va khong ton tai.
            password_verify(
                $password,
                '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi'
            );

            return false;
        }

        if (!in_array(
            $user['role'],
            USER_ROLES,
            true
        )) {
            return false;
        }

        if (!password_verify(
            $password,
            $user['password_hash']
        )) {
            return false;
        }

        // Cap nhat hash neu thuat toan da cu
        if (password_needs_rehash(
            $user['password_hash'],
            PASSWORD_DEFAULT
        )) {

            $newHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $update = db()->prepare(
                "UPDATE users
                 SET password_hash = :hash
                 WHERE id = :id"
            );

            $update->execute([
                'hash' => $newHash,
                'id' => $user['id']
            ]);
        }

        login_user((int) $user['id']);

        return true;
    }
}


// ==================================================
// 11. TAO PHIEN DANG NHAP
// ==================================================

if (!function_exists('login_user')) {

    function login_user(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'ID người dùng không hợp lệ.'
            );
        }

        // Bat buoc xac minh user ton tai
        $stmt = db()->prepare(
            "SELECT id, role
             FROM users
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $userId
        ]);

        $user = $stmt->fetch();

        if (
            !$user
            || !in_array(
                $user['role'],
                USER_ROLES,
                true
            )
        ) {
            throw new RuntimeException(
                'Tài khoản không tồn tại hoặc không hợp lệ.'
            );
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            startAppSession();
        }

        // Chong Session Fixation
        if (!session_regenerate_id(true)) {
            throw new RuntimeException(
                'Không thể tạo phiên đăng nhập an toàn.'
            );
        }

        // Xoa du lieu phien cu
        $_SESSION = [];

        // Luu thong tin xac thuc can thiet
        $_SESSION['auth_user_id'] = $userId;

        $_SESSION['auth_logged_in_at'] = time();

        $_SESSION['auth_last_regenerated'] = time();

        $_SESSION['_last_activity'] = time();

        // Tao CSRF token moi sau khi dang nhap
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }
}


// ==================================================
// 12. YEU CAU NGUOI DUNG DANG NHAP
// ==================================================

if (!function_exists('require_login')) {

    function require_login(): array
    {
        $user = current_user();

        if ($user === null) {

            if (
                isset($_SERVER['REQUEST_URI'])
                && str_contains(
                    parse_url(
                        $_SERVER['REQUEST_URI'],
                        PHP_URL_PATH
                    ) ?: '',
                    '/api/'
                )
            ) {
                json_response([
                    'success' => false,
                    'message' => 'Bạn cần đăng nhập.'
                ], 401);
            }

            set_flash(
                'warning',
                'Vui lòng đăng nhập để tiếp tục.'
            );

            redirect('login.php');
        }

        refresh_auth_session();

        return $user;
    }
}


// ==================================================
// 13. YEU CAU NGUOI DUNG CHUA DANG NHAP
// ==================================================

if (!function_exists('require_guest')) {

    function require_guest(): void
    {
        $user = current_user();

        if ($user !== null) {
            header(
                'Location: ' . dashboard_url(
                    $user['role']
                ),
                true,
                302
            );

            exit;
        }
    }
}


// ==================================================
// 14. LAM MOI SESSION ID DINH KY
// ==================================================

if (!function_exists('refresh_auth_session')) {

    function refresh_auth_session(): void
    {
        if (current_user_id() === null) {
            return;
        }

        $now = time();

        $lastRegenerated = (int) (
            $_SESSION['auth_last_regenerated']
            ?? 0
        );

        if (
            $now - $lastRegenerated
            >= AUTH_REGENERATE_INTERVAL
        ) {

            if (session_regenerate_id(true)) {

                $_SESSION['auth_last_regenerated'] =
                    $now;
            }
        }
    }
}


// ==================================================
// 15. DANG XUAT
// ==================================================

if (!function_exists('logout_user')) {

    function logout_user(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            startAppSession();
        }

        // Xoa du lieu Session
        $_SESSION = [];

        // Xoa Session Cookie
        if (ini_get('session.use_cookies')) {

            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' =>
                        $params['samesite'] ?? 'Lax'
                ]
            );
        }

        // Huy phien tren server
        session_destroy();
    }
}


// ==================================================
// 16. CHUYEN DEN DASHBOARD PHU HOP
// ==================================================

if (!function_exists('redirect_to_dashboard')) {

    function redirect_to_dashboard(): never
    {
        $user = require_login();

        header(
            'Location: ' . dashboard_url(
                $user['role']
            ),
            true,
            302
        );

        exit;
    }
}


// ==================================================
// 17. KIEM TRA USER CO VAI TRO CU THE
// ==================================================

if (!function_exists('has_role')) {

    function has_role(string|array $roles): bool
    {
        $role = current_user_role();

        if ($role === null) {
            return false;
        }

        $roles = (array) $roles;

        return in_array(
            $role,
            $roles,
            true
        );
    }
}


// ==================================================
// 18. KIEM TRA CHU SO HUU KHOA HOC
// ==================================================

if (!function_exists('is_course_owner')) {

    function is_course_owner(int $courseId): bool
    {
        $user = current_user();

        if ($user === null || $courseId <= 0) {
            return false;
        }

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
}


// ==================================================
// 19. KIEM TRA HOC VIEN DA DANG KY KHOA HOC
// ==================================================

if (!function_exists('is_enrolled')) {

    function is_enrolled(
        int $courseId,
        ?int $studentId = null
    ): bool {

        if ($courseId <= 0) {
            return false;
        }

        if ($studentId === null) {

            $user = current_user();

            if (
                $user === null
                || $user['role'] !== ROLE_STUDENT
            ) {
                return false;
            }

            $studentId = $user['id'];
        }

        if ($studentId <= 0) {
            return false;
        }

        $stmt = db()->prepare(
            "SELECT 1
             FROM enrollments
             WHERE course_id = :course_id
               AND student_id = :student_id
               AND status IN ('active', 'completed')
             LIMIT 1"
        );

        $stmt->execute([
            'course_id' => $courseId,
            'student_id' => $studentId
        ]);

        return $stmt->fetchColumn() !== false;
    }
}


// ==================================================
// END - BRIGHTFUTURE AUTHENTICATION
// ==================================================
