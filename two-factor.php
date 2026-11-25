<?php
require_once __DIR__ . '/includes/permissions.php';

$userId = positive_int($_SESSION['2fa_pending_user_id'] ?? null);
$role = (string) ($_SESSION['2fa_pending_role'] ?? '');
$error = '';

if ($userId <= 0 || !in_array($role, USER_ROLES, true)) {
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_role']);
    redirect('login.php');
}

if (is_post()) {
    require_csrf();
    $code = clean_text(get_post('code'));

    try {
        $secret = bf_totp_get_secret($userId);
        if ($secret === null) {
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_role']);
            set_flash('warning', 'Xác thực hai bước đã được thay đổi. Vui lòng đăng nhập lại.');
            redirect('login.php');
        }

        $valid = bf_totp_verify($secret, $code);
        if (!$valid) {
            $valid = bf_totp_verify_backup_code($userId, $code);
        }

        if (!$valid) {
            $error = 'Mã xác thực hoặc mã dự phòng không đúng hoặc đã hết hạn.';
            bf_audit_log('login.2fa_failure', 'user', $userId);
        } else {
            login_user($userId);
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_role']);
            bf_audit_log('login.2fa_success', 'user', $userId);
            redirect_to_dashboard();
        }
    } catch (Throwable $exception) {
        error_log('[BrightFuture 2FA] ' . $exception->getMessage());
        $error = 'Không thể xác thực mã lúc này.';
    }
}

$pageTitle = 'Xác thực hai bước | ' . APP_NAME;
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-error.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-auth.css')) ?>">
</head>
<body>
<main class="two-page">
    <section class="two-card">
        <h1>Xác thực hai bước</h1>
        <p>Nhập mã 6 số đang hiển thị trong ứng dụng Authenticator.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label for="code">Mã xác thực</label>
            <input id="code" name="code" autocomplete="one-time-code" maxlength="20" placeholder="123456 hoặc AB12-CD34" autofocus required>
            <button type="submit">Xác nhận</button>
        </form>
        <?php if ($error !== ''): ?>
            <div class="two-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
