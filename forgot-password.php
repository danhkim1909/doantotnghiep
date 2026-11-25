<?php
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/password_reset_rate_limit.php';

require_guest();

$errors = [];
$success = '';
$developmentResetUrl = null;

if (is_post()) {
    require_csrf();

    $email = mb_strtolower(trim(get_post('email')), 'UTF-8');

    if (!is_valid_email($email) || strlen($email) > AUTH_MAX_EMAIL_LENGTH) {
        $errors[] = 'Vui lòng nhập địa chỉ email hợp lệ.';
    } else {
        try {
            if (bf_password_reset_request_allowed($email)) {
                $pdo = db();
                $stmt = $pdo->prepare(
                    'SELECT id, email FROM users WHERE email = :email LIMIT 1'
                );
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                if ($user) {
                    $userId = (int) $user['id'];
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $pdo->beginTransaction();
                    $delete = $pdo->prepare(
                        'DELETE FROM bf_password_resets WHERE user_id = :user_id'
                    );
                    $delete->execute(['user_id' => $userId]);

                    $insert = $pdo->prepare(
                        'INSERT INTO bf_password_resets
                            (user_id, token_hash, expires_at)
                         VALUES
                            (:user_id, :token_hash, :expires_at)'
                    );
                    $insert->execute([
                        'user_id' => $userId,
                        'token_hash' => $tokenHash,
                        'expires_at' => date('Y-m-d H:i:s', time() + 1800),
                    ]);
                    $pdo->commit();

                    $resetUrl = app_url(
                        'reset-password.php?id=' . $userId
                        . '&token=' . rawurlencode($token)
                    );

                    $subject = 'Đặt lại mật khẩu BrightFuture';
                    $body = "Bạn vừa yêu cầu đặt lại mật khẩu.\n\n"
                        . "Mở liên kết sau trong vòng 30 phút:\n"
                        . $resetUrl . "\n\n"
                        . "Nếu bạn không yêu cầu, hãy bỏ qua email này.";
                    if (!bf_send_mail((string) $user['email'], $subject, $body)) {
                        error_log('[BrightFuture Forgot Password] Reset email delivery failed.');
                    }
                }
            }

            $success = 'Nếu email tồn tại trong hệ thống, liên kết đặt lại mật khẩu đã được gửi.';
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[BrightFuture Forgot Password] ' . $exception->getMessage());
            $errors[] = 'Không thể tạo yêu cầu đặt lại mật khẩu lúc này.';
        }
    }
}

$pageTitle = 'Quên mật khẩu | ' . APP_NAME;
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
<main class="reset-page">
    <section class="reset-card">
        <h1>Quên mật khẩu</h1>
        <p>Nhập email tài khoản. Nếu tài khoản tồn tại, chúng tôi sẽ gửi liên kết đặt lại mật khẩu.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label for="email">Địa chỉ email</label>
            <input id="email" name="email" type="email" maxlength="190" autocomplete="email" required>
            <div class="reset-actions">
                <button class="reset-button" type="submit">Gửi liên kết đặt lại</button>
                <a class="reset-link" href="<?= e(app_url('login.php')) ?>">Quay lại đăng nhập</a>
            </div>
        </form>
        <?php foreach ($errors as $error): ?>
            <div class="reset-message error" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($success !== ''): ?>
            <div class="reset-message success" role="status"><?= e($success) ?></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
