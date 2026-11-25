<?php
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/login_rate_limit.php';
require_once __DIR__ . '/includes/icons.php';


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
            // Ghi nhan giai doan de khoanh vung loi tren hosting.
            $loginStage = 'kiem_tra_thong_tin_dang_nhap';
            $distributedAttempts = bf_login_attempt_count($email, LOGIN_LOCK_SECONDS);

            if (
                $distributedAttempts['ip'] >= LOGIN_MAX_ATTEMPTS
                || $distributedAttempts['email'] >= LOGIN_MAX_ATTEMPTS
            ) {
                $error =
                    'Bạn đã thử đăng nhập quá nhiều lần. ' .
                    'Vui lòng thử lại sau 15 phút.';
            } else {

                // attempt_login() kiem tra password_verify,
                // tao Session va regenerate Session ID.
                if (attempt_login($email, $password)) {
                $loginStage = 'kiem_tra_xac_thuc_hai_buoc';
                $loggedIn = current_user();
                bf_login_record_attempt($email, true);
                if ($loggedIn) {
                    bf_audit_log(
                        bf_totp_is_enabled((int) $loggedIn['id'])
                            ? 'login.success_2fa_pending'
                            : 'login.success',
                        'user',
                        (int) $loggedIn['id']
                    );
                }

                if ($loggedIn && bf_totp_is_enabled((int) $loggedIn['id'])) {
                    $_SESSION['2fa_pending_user_id'] = (int) $loggedIn['id'];
                    $_SESSION['2fa_pending_role'] = (string) $loggedIn['role'];
                    unset($_SESSION['auth_user_id']);
                    redirect('two-factor.php');
                }

                $loginStage = 'tao_session_hoac_chuyen_dashboard';
                redirect_to_dashboard();
                }

                bf_login_record_attempt($email, false);

                if (
                    isset($_SESSION['pending_verification_email'])
                    && hash_equals(
                        (string) $_SESSION['pending_verification_email'],
                        $email
                    )
                ) {
                    $error =
                        'Tài khoản chưa xác minh email. ' .
                        'Vui lòng mở liên kết xác minh trong email.';
                    bf_audit_log('login.unverified', 'user', null);
                } else {
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
                        bf_audit_log('login.failure', 'user', null);
                    }
                }
            }

        } catch (Throwable $exception) {

            // Ma tham chieu chi de tim log, khong chua email/mat khau.
            $loginErrorId = bin2hex(random_bytes(4));
            error_log(sprintf(
                '[BrightFuture Login][%s][stage=%s][%s] %s (%s:%d)',
                $loginErrorId,
                $loginStage ?? 'chua_xac_dinh',
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));

            $error =
                'Hệ thống đang gặp sự cố. ' .
                'Vui lòng thử lại sau. Mã lỗi: ' . $loginErrorId;
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
                            <?= bf_icon('book') ?>
                        </span>
                        <span>
                            Khóa học lập trình có lộ trình rõ ràng
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <?= bf_icon('laptop') ?>
                        </span>
                        <span>
                            Học lý thuyết kết hợp thực hành
                        </span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <?= bf_icon('target') ?>
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

                    <a href="<?= e(app_url('forgot-password.php')) ?>">Quên mật khẩu?</a>

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
                Chưa nhận được email xác minh?
                <a href="<?= e(app_url('resend-verification.php')) ?>">Gửi lại email</a>
            </p>

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
