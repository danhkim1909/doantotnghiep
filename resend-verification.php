<?php
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/mail.php';

require_guest();

const EMAIL_VERIFICATION_RESEND_COOLDOWN = 300;
const EMAIL_VERIFICATION_SESSION_COOLDOWN = 30;

$errors = [];
$success = '';
$email = '';
$developmentVerificationUrl = null;

if (is_post()) {
    require_csrf();

    $email = mb_strtolower(trim(get_post('email')), 'UTF-8');

    if (!is_valid_email($email) || strlen($email) > AUTH_MAX_EMAIL_LENGTH) {
        $errors[] = 'Vui lòng nhập địa chỉ email hợp lệ.';
    } else {
        $lastRequestAt = (int) ($_SESSION['verification_resend_last_request_at'] ?? 0);
        $sessionIsCoolingDown = time() - $lastRequestAt < EMAIL_VERIFICATION_SESSION_COOLDOWN;

        if (!$sessionIsCoolingDown) {
            $_SESSION['verification_resend_last_request_at'] = time();

            try {
                $pdo = db();
                $pdo->beginTransaction();

                $userStmt = $pdo->prepare(
                    'SELECT id, email, email_verified_at
                     FROM users
                     WHERE email = :email
                       AND deleted_at IS NULL
                     LIMIT 1
                     FOR UPDATE'
                );
                $userStmt->execute(['email' => $email]);
                $user = $userStmt->fetch();

                if ($user && empty($user['email_verified_at'])) {
                    $recentRequestStmt = $pdo->prepare(
                        'SELECT 1
                         FROM bf_email_verifications
                         WHERE user_id = :user_id
                           AND created_at >= DATE_SUB(NOW(), INTERVAL '
                            . EMAIL_VERIFICATION_RESEND_COOLDOWN . ' SECOND)
                         LIMIT 1'
                    );
                    $recentRequestStmt->execute(['user_id' => (int) $user['id']]);
                    $accountIsCoolingDown = $recentRequestStmt->fetchColumn() !== false;

                    if (!$accountIsCoolingDown) {
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);

                        $deleteStmt = $pdo->prepare(
                            'DELETE FROM bf_email_verifications WHERE user_id = :user_id'
                        );
                        $deleteStmt->execute(['user_id' => (int) $user['id']]);

                        $insertStmt = $pdo->prepare(
                            'INSERT INTO bf_email_verifications
                                (user_id, token_hash, expires_at)
                             VALUES
                                (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                        );
                        $insertStmt->execute([
                            'user_id' => (int) $user['id'],
                            'token_hash' => $tokenHash,
                        ]);

                        $verificationUrl = app_url(
                            'verify-email.php?id=' . (int) $user['id']
                            . '&token=' . rawurlencode($token)
                        );
                    }
                }

                $pdo->commit();

                if (isset($verificationUrl)) {
                    bf_send_mail(
                        (string) $user['email'],
                        'Xác minh tài khoản BrightFuture',
                        "Chào bạn,\n\n"
                        . "Hãy xác minh email tài khoản trong vòng 24 giờ:\n"
                        . $verificationUrl . "\n\n"
                        . "Nếu bạn không yêu cầu, hãy bỏ qua email này."
                    );
                }

                $success = 'Nếu địa chỉ email thuộc tài khoản chưa xác minh, hướng dẫn sẽ được gửi. Nếu bạn vừa yêu cầu gần đây, vui lòng đợi vài phút rồi thử lại.';
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[BrightFuture Resend Verification] ' . $exception->getMessage());
                $errors[] = 'Không thể xử lý yêu cầu lúc này. Vui lòng thử lại sau.';
            }
        } else {
            $success = 'Nếu địa chỉ email thuộc tài khoản chưa xác minh, hướng dẫn sẽ được gửi. Nếu bạn vừa yêu cầu gần đây, vui lòng đợi vài phút rồi thử lại.';
        }
    }
}

$pageTitle = 'Gửi lại email xác minh | ' . APP_NAME;
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
<main class="resend-page">
    <section class="resend-card">
        <h1>Gửi lại email xác minh</h1>
        <p>Nhập email đã đăng ký. Vì lý do bảo mật, thông báo sẽ không xác nhận tài khoản có tồn tại hay không.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label for="email">Địa chỉ email</label>
            <input id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($email) ?>" required>
            <div class="resend-actions">
                <button class="resend-button" type="submit">Gửi email xác minh</button>
                <a class="resend-link" href="<?= e(app_url('login.php')) ?>">Quay lại đăng nhập</a>
            </div>
        </form>
        <?php foreach ($errors as $error): ?>
            <div class="resend-message error" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($success !== ''): ?>
            <div class="resend-message success" role="status"><?= e($success) ?></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
