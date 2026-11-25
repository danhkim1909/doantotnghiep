<?php
/**
 * ==================================================
 * BRIGHTFUTURE - REGISTER PAGE
 * File: register.php
 * PHP 8.0+ / MySQL PDO / PHP Session
 * ==================================================
 */



require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/icons.php';

// ==================================================
// 1. CHAN TAI KHOAN DA DANG NHAP
// ==================================================

require_guest();

// ==================================================
// 2. KHOI TAO BIEN
// ==================================================

$errors = [];

$fullName = '';
$email = '';
$isTeacher = false;
$expertise = '';
$bio = '';

const REGISTER_MIN_PASSWORD = 8;
const REGISTER_MAX_PASSWORD = 72;
const REGISTER_MAX_NAME_LENGTH = 120;
const REGISTER_MAX_EMAIL_LENGTH = 190;

// ==================================================
// 3. GIOI HAN DANG KY CO BAN THEO SESSION
// ==================================================

const REGISTER_MAX_ATTEMPTS = 10;
const REGISTER_ATTEMPT_WINDOW = 900;

if (
    !isset($_SESSION['register_security'])
    || !is_array($_SESSION['register_security'])
) {
    $_SESSION['register_security'] = [
        'count' => 0,
        'window_start' => time()
    ];
}

if (
    time() - (int) (
        $_SESSION['register_security']['window_start'] ?? 0
    ) >= REGISTER_ATTEMPT_WINDOW
) {
    $_SESSION['register_security'] = [
        'count' => 0,
        'window_start' => time()
    ];
}

// ==================================================
// 4. XU LY DANG KY
// ==================================================

if (is_post()) {

    require_csrf();

    $rawName = $_POST['full_name'] ?? '';
    $rawEmail = $_POST['email'] ?? '';
    $rawPassword = $_POST['password'] ?? '';
    $rawConfirm = $_POST['confirm_password'] ?? '';

    $fullName = is_string($rawName)
        ? clean_text($rawName)
        : '';

    $email = is_string($rawEmail)
        ? strtolower(trim($rawEmail))
        : '';

    // Khong trim mat khau.
    $password = is_string($rawPassword)
        ? $rawPassword
        : '';

    $confirmPassword = is_string($rawConfirm)
        ? $rawConfirm
        : '';

    $isTeacher = !empty($_POST['register_as_teacher']);
    $rawExpertise = $_POST['expertise'] ?? '';
    $rawBio = $_POST['bio'] ?? '';
    $expertise = is_string($rawExpertise) ? clean_text($rawExpertise) : '';
    $bio = is_string($rawBio) ? trim($rawBio) : '';

    // ==========================================
    // 4.1. GIOI HAN SO LAN THU
    // ==========================================

    $registerSecurity = &$_SESSION['register_security'];

    if (
        (int) $registerSecurity['count']
        >= REGISTER_MAX_ATTEMPTS
    ) {
        $errors[] =
            'Bạn đã gửi quá nhiều yêu cầu đăng ký. ' .
            'Vui lòng thử lại sau 15 phút.';
    } else {

        $registerSecurity['count']++;

        // ======================================
        // 4.2. KIEM TRA HO TEN
        // ======================================

        if ($fullName === '') {

            $errors[] = 'Vui lòng nhập họ và tên.';

        } elseif (
            function_exists('mb_strlen')
            && mb_strlen($fullName, 'UTF-8')
                > REGISTER_MAX_NAME_LENGTH
        ) {

            $errors[] =
                'Họ và tên không được vượt quá 120 ký tự.';

        } elseif (
            !function_exists('mb_strlen')
            && strlen($fullName)
                > REGISTER_MAX_NAME_LENGTH
        ) {

            $errors[] =
                'Họ và tên quá dài.';
        }

        // ======================================
        // 4.3. KIEM TRA EMAIL
        // ======================================

        if ($email === '') {

            $errors[] =
                'Vui lòng nhập địa chỉ email.';

        } elseif (
            strlen($email) > REGISTER_MAX_EMAIL_LENGTH
            || !is_valid_email($email)
        ) {

            $errors[] =
                'Địa chỉ email không hợp lệ.';
        }

        // ======================================
        // 4.4. KIEM TRA MAT KHAU
        // ======================================

        $passwordLength = strlen($password);

        if ($password === '') {

            $errors[] = 'Vui lòng nhập mật khẩu.';

        } elseif (
            $passwordLength < REGISTER_MIN_PASSWORD
        ) {

            $errors[] =
                'Mật khẩu phải có ít nhất 8 ký tự.';

        } elseif (
            $passwordLength > REGISTER_MAX_PASSWORD
        ) {

            $errors[] =
                'Mật khẩu không được vượt quá 72 byte.';

        } elseif (
            !preg_match('/[A-Za-z]/', $password)
            || !preg_match('/[0-9]/', $password)
        ) {

            $errors[] =
                'Mật khẩu cần có ít nhất một chữ cái ' .
                'và một chữ số.';
        }

        // ======================================
        // 4.5. XAC NHAN MAT KHAU
        // ======================================

        if ($confirmPassword === '') {

            $errors[] =
                'Vui lòng xác nhận mật khẩu.';

        } elseif (
            $password !== $confirmPassword
        ) {

            $errors[] =
                'Mật khẩu xác nhận không khớp.';
        }

        // ======================================
        // 4.5.1. KIEM TRA THONG TIN DANG KY GIANG VIEN
        // ======================================

        if ($isTeacher) {
            if ($expertise === '') {
                $errors[] = 'Vui lòng nhập chuyên môn giảng dạy của bạn.';
            } elseif (function_exists('mb_strlen') && mb_strlen($expertise, 'UTF-8') > 255) {
                $errors[] = 'Chuyên môn giảng dạy không được vượt quá 255 ký tự.';
            }
            if ($bio !== '' && function_exists('mb_strlen') && mb_strlen($bio, 'UTF-8') > 2000) {
                $errors[] = 'Thông tin giới thiệu kinh nghiệm không được vượt quá 2.000 ký tự.';
            }
        }

        // ======================================
        // 4.6. KIEM TRA EMAIL TON TAI
        // ======================================

        if (empty($errors)) {

            try {

                $stmt = db()->prepare(
                    "SELECT id
                     FROM users
                     WHERE email = :email
                     LIMIT 1"
                );

                $stmt->execute([
                    'email' => $email
                ]);

                if ($stmt->fetch()) {
                    $errors[] =
                        'Email này đã được đăng ký. ' .
                        'Vui lòng sử dụng email khác.';
                }

            } catch (Throwable $exception) {

                error_log(
                    '[BrightFuture Register Check] ' .
                    $exception->getMessage()
                );

                $errors[] =
                    'Không thể kiểm tra tài khoản. ' .
                    'Vui lòng thử lại sau.';
            }
        }

        // ======================================
        // 4.7. TAO TAI KHOAN
        // ======================================

        if (empty($errors)) {

            try {

                // Khong bao gio lay role tu POST.
                // Tai khoan cong khai luon la student.
                $role = ROLE_STUDENT;

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = db()->prepare(
                    "INSERT INTO users (
                        full_name,
                        email,
                        password_hash,
                        role,
                        email_verified_at
                    ) VALUES (
                        :full_name,
                        :email,
                        :password_hash,
                        :role,
                        NULL
                    )"
                );

                $stmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => $role
                ]);

                $userId = (int) db()->lastInsertId();
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $pdo = db();
                $pdo->prepare(
                    'DELETE FROM bf_email_verifications WHERE user_id = :user_id'
                )->execute(['user_id' => $userId]);
                $pdo->prepare(
                    'INSERT INTO bf_email_verifications
                        (user_id, token_hash, expires_at)
                     VALUES
                        (:user_id, :token_hash, :expires_at)'
                )->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'expires_at' => date('Y-m-d H:i:s', time() + 86400),
                ]);

                if ($isTeacher) {
                    $stmtApp = $pdo->prepare(
                        'INSERT INTO bf_teacher_applications (user_id, expertise, bio, status)
                         VALUES (:user_id, :expertise, :bio, "pending")'
                    );
                    $stmtApp->execute([
                        'user_id' => $userId,
                        'expertise' => $expertise,
                        'bio' => $bio !== '' ? $bio : null,
                    ]);
                }

                $_SESSION['pending_verification_email'] = $email;

                $verifyUrl = app_url(
                    'verify-email.php?id=' . $userId . '&token=' . rawurlencode($token)
                );
                bf_send_mail(
                    $email,
                    'Xác minh tài khoản BrightFuture',
                    "Chào bạn,\n\n"
                    . "Hãy xác minh email tài khoản trong vòng 24 giờ:\n"
                    . $verifyUrl . "\n\n"
                    . "Nếu bạn không đăng ký, hãy bỏ qua email này."
                );

                // Chuyen sang dang nhap.
                // Khong tu dong dang nhap sau dang ky.
                $successNotice = $isTeacher
                    ? 'Đăng ký tài khoản thành công! Đơn đăng ký giảng viên của bạn đã được gửi tới Ban Quản Trị để phê duyệt. Vui lòng xác minh email trước khi đăng nhập.'
                    : 'Đăng ký tài khoản thành công! Vui lòng xác minh email trước khi đăng nhập.';

                set_flash(
                    'success',
                    $successNotice
                );

                redirect('login.php');

            } catch (PDOException $exception) {

                // Ma SQLSTATE 23000:
                // vi pham rang buoc UNIQUE/FK.
                // Trong truong hop nay kha nang cao la
                // email bi trung do request dong thoi.
                if ($exception->getCode() === '23000') {

                    $errors[] =
                        'Email này đã được đăng ký. ' .
                        'Vui lòng sử dụng email khác.';

                } else {

                    error_log(
                        '[BrightFuture Register Insert] ' .
                        $exception->getMessage()
                    );

                    $errors[] =
                        'Không thể tạo tài khoản. ' .
                        'Vui lòng thử lại sau.';
                }

            } catch (Throwable $exception) {

                error_log(
                    '[BrightFuture Register] ' .
                    $exception->getMessage()
                );

                $errors[] =
                    'Đã xảy ra lỗi hệ thống. ' .
                    'Vui lòng thử lại sau.';
            }
        }
    }
}

$pageTitle = 'Đăng ký tài khoản | ' . APP_NAME;

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Tạo tài khoản BrightFuture và bắt đầu hành trình học lập trình trực tuyến."
    >

    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/bf-auth.css">
</head>

<body>

<div class="auth-layout">

    <!-- ======================================
         LEFT: GIOI THIEU BRIGHTFUTURE
    ======================================= -->

    <section class="brand-panel">

        <div class="brand-content">

            <a
                class="logo"
                href="<?= e(app_url()) ?>"
            >
                <span class="logo-icon"><?= bf_icon('code', 'bf-logo-svg') ?></span>
                <span>BrightFuture</span>
            </a>

            <div class="brand-main">

                <span class="brand-badge">
                    Bắt đầu hành trình học lập trình
                </span>

                <h1>
                    Khởi đầu hôm nay,
                    <span>
                        làm chủ công nghệ ngày mai.
                    </span>
                </h1>

                <p>
                    Tạo tài khoản BrightFuture để khám phá
                    những khóa học lập trình, tiếp cận
                    kiến thức công nghệ và từng bước
                    phát triển kỹ năng của bạn.
                </p>

                <div class="features">

                    <div class="feature">
                        <span class="feature-icon">
                            <?= bf_icon('book') ?>
                        </span>
                        <span>
                            Khám phá các khóa học lập trình
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <?= bf_icon('laptop') ?>
                        </span>
                        <span>
                            Học tập kết hợp thực hành
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <?= bf_icon('target') ?>
                        </span>
                        <span>
                            Theo dõi hành trình học tập
                        </span>
                    </div>

                </div>

            </div>

        </div>

        <div class="brand-footer">
            &copy; <?= date('Y') ?> BrightFuture.
            Learn today. Build tomorrow.
        </div>

    </section>

    <!-- ======================================
         RIGHT: FORM DANG KY
    ======================================= -->

    <main class="register-panel">

        <div class="register-container">

            <a
                href="<?= e(app_url()) ?>"
                class="back-home"
            >
                &larr; Quay lại trang chủ
            </a>

            <div class="register-heading">

                <h2>Tạo tài khoản mới</h2>

                <p>
                    Tham gia BrightFuture và bắt đầu
                    hành trình học lập trình của bạn.
                </p>

            </div>

            <!-- HIEN THI LOI DANG KY -->

            <?php if (!empty($errors)): ?>

                <div
                    class="alert alert-error"
                    role="alert"
                >
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            <?php endif; ?>

            <!-- FORM DANG KY -->

            <form
                id="registerForm"
                action="<?= e(app_url('register.php')) ?>"
                method="POST"
            >

                <?= csrf_field() ?>

                <!-- HO TEN -->

                <div class="form-group">

                    <label
                        for="full_name"
                        class="form-label"
                    >
                        Họ và tên
                    </label>

                    <div class="input-wrapper">

                        <input
                            id="full_name"
                            class="form-input"
                            type="text"
                            name="full_name"
                            placeholder="Nhập họ và tên của bạn"
                            value="<?= e($fullName) ?>"
                            maxlength="120"
                            autocomplete="name"
                            required
                            autofocus
                        >

                    </div>

                </div>

                <!-- EMAIL -->

                <div class="form-group">

                    <label
                        for="email"
                        class="form-label"
                    >
                        Địa chỉ email
                    </label>

                    <div class="input-wrapper">

                        <input
                            id="email"
                            class="form-input"
                            type="email"
                            name="email"
                            placeholder="example@gmail.com"
                            value="<?= e($email) ?>"
                            maxlength="190"
                            autocomplete="email"
                            required
                        >

                    </div>

                </div>

                <!-- MAT KHAU -->

                <div class="form-group">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Mật khẩu
                    </label>

                    <div class="input-wrapper">

                        <input
                            id="password"
                            class="form-input password-input"
                            type="password"
                            name="password"
                            placeholder="Tạo mật khẩu"
                            minlength="8"
                            maxlength="72"
                            autocomplete="new-password"
                            aria-describedby="passwordHint"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="password"
                            aria-label="Hiển thị mật khẩu"
                            aria-pressed="false"
                        >
                            Hiện
                        </button>

                    </div>

                    <p
                        class="form-hint"
                        id="passwordHint"
                    >
                        Ít nhất 8 ký tự, có chữ cái và chữ số.
                    </p>

                </div>

                <!-- XAC NHAN MAT KHAU -->

                <div class="form-group">

                    <label
                        for="confirm_password"
                        class="form-label"
                    >
                        Xác nhận mật khẩu
                    </label>

                    <div class="input-wrapper">

                        <input
                            id="confirm_password"
                            class="form-input password-input"
                            type="password"
                            name="confirm_password"
                            placeholder="Nhập lại mật khẩu"
                            minlength="8"
                            maxlength="72"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="confirm_password"
                            aria-label="Hiển thị mật khẩu xác nhận"
                            aria-pressed="false"
                        >
                            Hiện
                        </button>

                    </div>

                </div>

                <!-- DANG KY GIANG VIEN -->
                <div class="form-group" style="margin-top: 18px; padding: 14px 16px; background: #f8faff; border: 1px solid #e0e7ff; border-radius: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; color: #1e293b; margin: 0;">
                        <input
                            type="checkbox"
                            id="registerAsTeacher"
                            name="register_as_teacher"
                            value="1"
                            <?= $isTeacher ? 'checked' : '' ?>
                            style="width: 18px; height: 18px; accent-color: #4f46e5; cursor: pointer;"
                            onchange="document.getElementById('teacherFields').style.display = this.checked ? 'block' : 'none';"
                        >
                        <span>Tôi muốn đăng ký tài khoản <strong>Giảng viên</strong> (Cần xét duyệt)</span>
                    </label>

                    <div id="teacherFields" style="display: <?= $isTeacher ? 'block' : 'none' ?>; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #c7d2fe;">
                        <div style="margin-bottom: 12px;">
                            <label for="expertise" class="form-label" style="font-size: 13px;">Lĩnh vực chuyên môn giảng dạy *</label>
                            <input
                                id="expertise"
                                class="form-input"
                                type="text"
                                name="expertise"
                                placeholder="Ví dụ: Lập trình Web Fullstack, AI & Machine Learning, Tiếng Anh..."
                                value="<?= e($expertise) ?>"
                                maxlength="255"
                            >
                        </div>
                        <div>
                            <label for="bio" class="form-label" style="font-size: 13px;">Giới thiệu kinh nghiệm / chứng chỉ chuyên môn</label>
                            <textarea
                                id="bio"
                                class="form-input"
                                name="bio"
                                rows="3"
                                placeholder="Tóm tắt quá trình công tác, giảng dạy hoặc các chứng chỉ liên quan..."
                                maxlength="2000"
                                style="resize: vertical; font-family: inherit;"
                            ><?= e($bio) ?></textarea>
                        </div>
                        <p class="form-hint" style="margin-top: 6px; font-size: 12px; color: #6366f1;">
                            ℹ️ Hồ sơ của bạn sẽ được gửi tới Ban Quản Trị BrightFuture phê duyệt trước khi kích hoạt quyền tạo khóa học.
                        </p>
                    </div>
                </div>

                <!-- SUBMIT -->

                <button
                    type="submit"
                    class="submit-btn"
                    id="submitBtn"
                >
                    Tạo tài khoản
                </button>

            </form>

            <p class="login-text">

                Đã có tài khoản?

                <a
                    href="<?= e(app_url('login.php')) ?>"
                >
                    Đăng nhập ngay
                </a>

            </p>

            <div class="register-footer">
                &copy; <?= date('Y') ?> BrightFuture.
                All rights reserved.
            </div>

        </div>

    </main>

</div>

<!-- ==========================================
     JAVASCRIPT
=========================================== -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById('registerForm');

    const passwordInput =
        document.getElementById('password');

    const confirmInput =
        document.getElementById('confirm_password');

    const submitBtn =
        document.getElementById('submitBtn');

    const toggleButtons =
        document.querySelectorAll('.toggle-password');

    // ======================================
    // 1. HIEN / AN MAT KHAU
    // ======================================

    toggleButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const targetId =
                button.getAttribute('data-target');

            const input =
                document.getElementById(targetId);

            if (!input) {
                return;
            }

            const isHidden = input.type === 'password';

            input.type = isHidden ? 'text' : 'password';

            button.textContent =
                isHidden ? 'Ẩn' : 'Hiện';

            button.setAttribute(
                'aria-pressed',
                String(isHidden)
            );

            button.setAttribute(
                'aria-label',
                isHidden ? 'Ẩn mật khẩu' : 'Hiển thị mật khẩu'
            );
        });
    });

    // ======================================
    // 2. KIEM TRA MAT KHAU KHOP NHAU
    // ======================================

    function validatePasswords() {

        if (
            confirmInput.value !== ''
            && passwordInput.value !== confirmInput.value
        ) {

            confirmInput.setCustomValidity(
                'Mật khẩu xác nhận không khớp.'
            );

        } else {

            confirmInput.setCustomValidity('');
        }
    }

    passwordInput.addEventListener(
        'input',
        validatePasswords
    );

    confirmInput.addEventListener(
        'input',
        validatePasswords
    );

    // ======================================
    // 3. XU LY SUBMIT
    // ======================================

    form.addEventListener('submit', function (event) {

        validatePasswords();

        if (!form.checkValidity()) {

            event.preventDefault();

            form.reportValidity();

            return;
        }

        submitBtn.disabled = true;

        submitBtn.textContent =
            'Đang tạo tài khoản...';
    });

});
</script>

</body>
</html>
