
<?php
/**
 * ==================================================
 * BRIGHTFUTURE - REGISTER PAGE
 * File: register.php
 * PHP 8.0+ / MySQL PDO / PHP Session
 * ==================================================
 */



require_once __DIR__ . '/includes/permissions.php';

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
                        role
                    ) VALUES (
                        :full_name,
                        :email,
                        :password_hash,
                        :role
                    )"
                );

                $stmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => $role
                ]);

                // Chuyen sang dang nhap.
                // Khong tu dong dang nhap sau dang ky.
                set_flash(
                    'success',
                    'Đăng ký tài khoản thành công! ' .
                    'Vui lòng đăng nhập để tiếp tục.'
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

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #7c3aed;
            --text: #172033;
            --muted: #64748b;
            --border: #e2e8f0;
            --background: #f8faff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        .auth-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* ======================================
           BRAND PANEL
        ====================================== */

        .brand-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 48px 65px;
            overflow: hidden;
            color: white;
            background: linear-gradient(
                145deg,
                #312e81 0%,
                #4f46e5 48%,
                #7c3aed 100%
            );
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: rgba(255,255,255,0.07);
            right: -190px;
            top: -130px;
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            width: 340px;
            height: 340px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            bottom: -160px;
            left: -130px;
        }

        .brand-content,
        .brand-footer {
            position: relative;
            z-index: 1;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -0.7px;
        }

        .logo-icon {
            display: grid;
            place-items: center;
            width: 45px;
            height: 45px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 14px;
            font-size: 23px;
        }

        .brand-main {
            margin-top: 100px;
            max-width: 510px;
        }

        .brand-badge {
            display: inline-block;
            padding: 9px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: #e0e7ff;
            background: rgba(255,255,255,0.12);
            margin-bottom: 25px;
        }

        .brand-main h1 {
            font-size: clamp(34px, 3.4vw, 53px);
            line-height: 1.22;
            letter-spacing: -1.5px;
            margin-bottom: 23px;
        }

        .brand-main h1 span {
            color: #c4b5fd;
        }

        .brand-main p {
            font-size: 16px;
            line-height: 1.9;
            color: #e0e7ff;
        }

        .features {
            display: grid;
            gap: 18px;
            margin-top: 42px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 15px;
        }

        .feature-icon {
            display: grid;
            place-items: center;
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            border-radius: 13px;
            background: rgba(255,255,255,0.13);
            font-size: 20px;
        }

        .brand-footer {
            margin-top: 55px;
            font-size: 13px;
            color: #c7d2fe;
        }

        /* ======================================
           REGISTER PANEL
        ====================================== */

        .register-panel {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 45px 30px;
        }

        .register-container {
            width: 100%;
            max-width: 440px;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 32px;
            transition: color .2s;
        }

        .back-home:hover {
            color: var(--primary);
        }

        .register-heading {
            margin-bottom: 28px;
        }

        .register-heading h2 {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 10px;
        }

        .register-heading p {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 19px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 52px;
            padding: 0 16px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            outline: none;
            background: white;
            color: var(--text);
            transition: all .2s;
        }

        .form-input::placeholder {
            color: #94a3b8;
        }

        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79,70,229,.1);
        }

        .password-input {
            padding-right: 80px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--primary);
            font-size: 13px;
            font-weight: 600;
            padding: 10px 4px;
            cursor: pointer;
        }

        .form-hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            margin-top: 7px;
        }

        .submit-btn {
            width: 100%;
            height: 55px;
            margin-top: 7px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(
                100deg,
                var(--primary),
                var(--secondary)
            );
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(79,70,229,.25);
        }

        .submit-btn:disabled {
            opacity: .75;
            cursor: wait;
        }

        .login-text {
            margin-top: 27px;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
        }

        .login-text a {
            color: var(--primary);
            font-weight: 700;
        }

        .login-text a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 11px;
            margin-bottom: 23px;
            font-size: 14px;
            line-height: 1.65;
        }

        .alert-error {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .alert-error ul {
            padding-left: 19px;
        }

        .alert-error li + li {
            margin-top: 5px;
        }

        .register-footer {
            margin-top: 35px;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .auth-layout {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                display: none;
            }

            .register-panel {
                min-height: 100vh;
            }
        }

        @media (max-width: 480px) {
            .register-panel {
                padding: 30px 22px;
            }

            .register-heading h2 {
                font-size: 28px;
            }
        }
    </style>
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
                <span class="logo-icon">&lt;/&gt;</span>
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
                            &#128218;
                        </span>
                        <span>
                            Khám phá các khóa học lập trình
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            &#128187;
                        </span>
                        <span>
                            Học tập kết hợp thực hành
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            &#127919;
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
