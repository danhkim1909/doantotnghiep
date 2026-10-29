
<?php
/**
 * ==================================================
 * BRIGHTFUTURE - LOGIN PAGE
 * File: login.php
 * PHP 8.0+ / MySQL PDO / PHP Session
 * ==================================================
 */


require_once __DIR__ . '/includes/permissions.php';

// ================================================
// 1. CHAN NGUOI DA DANG NHAP
// ================================================

require_guest();

// ================================================
// 2. KHOI TAO BIEN
// ================================================

$error = '';
$email = '';

$successMessage = '';

if (isset($_GET['registered'])) {
    $successMessage =
        'Đăng ký thành công! Vui lòng đăng nhập.';
}

// Lay thong bao tu Session
$flash = get_flash();

if ($flash !== null) {
    if ($flash['type'] === 'success') {
        $successMessage = (string) $flash['message'];
    } elseif (
        in_array(
            $flash['type'],
            ['error', 'warning'],
            true
        )
    ) {
        $error = (string) $flash['message'];
    }
}

// ================================================
// 3. GIOI HAN THU DANG NHAP CO BAN
// ================================================

// Gioi han 5 lan that bai trong 15 phut
// theo Session trinh duyet.
// Production can them rate limit theo IP/tai khoan.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 900;

if (!isset($_SESSION['login_security'])) {
    $_SESSION['login_security'] = [
        'attempts' => 0,
        'locked_until' => 0
    ];
}

$security = &$_SESSION['login_security'];

if (
    (int) $security['locked_until'] > 0
    && time() >= (int) $security['locked_until']
) {
    $security = [
        'attempts' => 0,
        'locked_until' => 0
    ];
}

// ================================================
// 4. XU LY DANG NHAP
// ================================================

if (is_post()) {

    // Kiem tra CSRF
    require_csrf();

    $rawEmail = $_POST['email'] ?? '';
    $rawPassword = $_POST['password'] ?? '';

    $email = is_string($rawEmail)
        ? strtolower(trim($rawEmail))
        : '';

    // Khong trim mat khau
    $password = is_string($rawPassword)
        ? $rawPassword
        : '';

    if (time() < (int) $security['locked_until']) {

        $error =
            'Bạn đã thử đăng nhập quá nhiều lần. ' .
            'Vui lòng thử lại sau 15 phút.';

    } elseif (
        $email === ''
        || $password === ''
    ) {

        $error =
            'Vui lòng nhập đầy đủ email và mật khẩu.';

    } elseif (
        strlen($email) > AUTH_MAX_EMAIL_LENGTH
        || !is_valid_email($email)
    ) {

        $error = 'Địa chỉ email không hợp lệ.';

    } elseif (
        strlen($password) > AUTH_MAX_PASSWORD_BYTES
    ) {

        $error = 'Mật khẩu vượt quá giới hạn cho phép.';

    } else {

        try {

            // attempt_login() kiem tra password_verify,
            // tao Session va regenerate Session ID.
            if (attempt_login($email, $password)) {

                // Chuyen den dashboard dung vai tro
                redirect_to_dashboard();
            }

            $security['attempts']++;

            if (
                $security['attempts']
                >= LOGIN_MAX_ATTEMPTS
            ) {
                $security['locked_until'] =
                    time() + LOGIN_LOCK_SECONDS;

                $error =
                    'Bạn đã thử đăng nhập quá nhiều lần. ' .
                    'Vui lòng thử lại sau 15 phút.';
            } else {
                $error =
                    'Email hoặc mật khẩu không chính xác.';
            }

        } catch (Throwable $exception) {

            error_log(
                '[BrightFuture Login] ' .
                $exception->getMessage()
            );

            $error =
                'Hệ thống đang gặp sự cố. ' .
                'Vui lòng thử lại sau.';
        }
    }
}

$pageTitle = 'Đăng nhập | ' . APP_NAME;

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
        content="Đăng nhập BrightFuture để bắt đầu hành trình học lập trình trực tuyến."
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
            text-decoration: none;
            color: inherit;
        }

        button,
        input {
            font: inherit;
        }

        .auth-layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        /* LEFT - BRAND SECTION */

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
            margin-top: 115px;
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

        /* RIGHT - LOGIN SECTION */

        .login-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 45px 30px;
        }

        .login-container {
            width: 100%;
            max-width: 430px;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 46px;
            transition: color .2s;
        }

        .back-home:hover {
            color: var(--primary);
        }

        .login-heading {
            margin-bottom: 35px;
        }

        .login-heading h2 {
            font-size: 33px;
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 11px;
        }

        .login-heading p {
            font-size: 15px;
            line-height: 1.6;
            color: var(--muted);
        }

        .form-group {
            margin-bottom: 23px;
        }

        .form-label {
            display: block;
            margin-bottom: 9px;
            font-weight: 600;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 54px;
            padding: 0 16px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            background: white;
            outline: none;
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
            padding-right: 78px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--primary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 10px 4px;
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 5px 0 26px;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 13px;
            cursor: pointer;
        }

        .remember-label input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .submit-btn {
            width: 100%;
            height: 55px;
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

        .register-text {
            text-align: center;
            color: var(--muted);
            font-size: 14px;
            margin-top: 30px;
        }

        .register-text a {
            font-weight: 700;
            color: var(--primary);
        }

        .register-text a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 11px;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 23px;
        }

        .alert-error {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .alert-success {
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .login-footer {
            margin-top: 45px;
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

            .login-panel {
                min-height: 100vh;
            }
        }

        @media (max-width: 480px) {
            .login-panel {
                padding: 35px 22px;
            }

            .login-heading h2 {
                font-size: 29px;
            }

            .back-home {
                margin-bottom: 38px;
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
                    Nền tảng học lập trình trực tuyến
                </span>

                <h1>
                    Học lập trình hôm nay,
                    <span>
                        kiến tạo tương lai.
                    </span>
                </h1>

                <p>
                    Bắt đầu hành trình khám phá công nghệ,
                    phát triển tư duy lập trình và nâng cao
                    kỹ năng cùng BrightFuture.
                </p>

                <div class="features">

                    <div class="feature">
                        <span class="feature-icon">
                            &#128218;
                        </span>
                        <span>
                            Khóa học lập trình có lộ trình rõ ràng
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            &#128187;
                        </span>
                        <span>
                            Học lý thuyết kết hợp thực hành
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            &#127919;
                        </span>
                        <span>
                            Theo dõi tiến độ và kết quả học tập
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
         RIGHT: DANG NHAP
    ======================================= -->

    <main class="login-panel">

        <div class="login-container">

            <a
                href="<?= e(app_url()) ?>"
                class="back-home"
            >
                &larr; Quay lại trang chủ
            </a>

            <div class="login-heading">
                <h2>Chào mừng trở lại!</h2>

                <p>
                    Đăng nhập để tiếp tục hành trình
                    học tập của bạn.
                </p>
            </div>

            <!-- THONG BAO THANH CONG -->

            <?php if ($successMessage !== ''): ?>
                <div
                    class="alert alert-success"
                    role="status"
                >
                    <?= e($successMessage) ?>
                </div>
            <?php endif; ?>

            <!-- THONG BAO LOI -->

            <?php if ($error !== ''): ?>
                <div
                    class="alert alert-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <!-- FORM DANG NHAP -->

            <form
                id="loginForm"
                action="<?= e(app_url('login.php')) ?>"
                method="POST"
            >

                <?= csrf_field() ?>

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
                            autofocus
                        >

                    </div>

                </div>

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
                            placeholder="Nhập mật khẩu của bạn"
                            maxlength="4096"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            id="togglePassword"
                            aria-label="Hiển thị mật khẩu"
                            aria-pressed="false"
                        >
                            Hiện
                        </button>

                    </div>

                </div>

                <div class="form-options">

                    <label class="remember-label">

                        <input
                            type="checkbox"
                            id="rememberEmail"
                        >

                        Ghi nhớ email
                    </label>

                </div>

                <button
                    type="submit"
                    class="submit-btn"
                    id="submitBtn"
                >
                    Đăng nhập
                </button>

            </form>

            <p class="register-text">
                Chưa có tài khoản?

                <a
                    href="<?= e(app_url('register.php')) ?>"
                >
                    Đăng ký ngay
                </a>
            </p>

            <div class="login-footer">
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

    const form = document.getElementById('loginForm');

    const passwordInput =
        document.getElementById('password');

    const togglePassword =
        document.getElementById('togglePassword');

    const emailInput =
        document.getElementById('email');

    const rememberEmail =
        document.getElementById('rememberEmail');

    const submitBtn =
        document.getElementById('submitBtn');

    // 1. HIEN / AN MAT KHAU

    togglePassword.addEventListener('click', function () {

        const isHidden =
            passwordInput.type === 'password';

        passwordInput.type =
            isHidden ? 'text' : 'password';

        togglePassword.textContent =
            isHidden ? 'Ẩn' : 'Hiện';

        togglePassword.setAttribute(
            'aria-label',
            isHidden ? 'Ẩn mật khẩu' : 'Hiển thị mật khẩu'
        );

        togglePassword.setAttribute(
            'aria-pressed',
            String(isHidden)
        );
    });

    // 2. GHI NHO EMAIL (KHONG LUU MAT KHAU)

    try {
        const savedEmail =
            localStorage.getItem('bf_remember_email');

        if (savedEmail) {
            rememberEmail.checked = true;

            // Uu tien email vua nhap neu dang co loi
            if (!emailInput.value) {
                emailInput.value = savedEmail;
            }
        }

    } catch (error) {
        // Trinh duyet co the chan localStorage
    }

    // 3. XU LY KHI GUI FORM

    form.addEventListener('submit', function (event) {

        if (!form.checkValidity()) {
            return;
        }

        try {
            if (rememberEmail.checked) {

                localStorage.setItem(
                    'bf_remember_email',
                    emailInput.value.trim()
                );

            } else {

                localStorage.removeItem(
                    'bf_remember_email'
                );
            }

        } catch (error) {
            // Khong can luu neu storage bi chan
        }

        // Chong bam nut lien tuc
        submitBtn.disabled = true;
        submitBtn.textContent = 'Đang đăng nhập...';
    });

});
</script>

</body>
</html>
