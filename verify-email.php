<?php
require_once __DIR__ . '/includes/permissions.php';

require_guest();

$userId = positive_int($_GET['id'] ?? $_POST['id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$verified = false;
$error = '';

if ($userId > 0 && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $tokenHash = hash('sha256', strtolower($token));

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id
             FROM bf_email_verifications
             WHERE user_id = :user_id
               AND token_hash = :token_hash
               AND expires_at >= NOW()
               AND used_at IS NULL
             FOR UPDATE'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
        $verificationId = $stmt->fetchColumn();

        if ($verificationId !== false) {
            $pdo->prepare(
                'UPDATE users SET email_verified_at = NOW() WHERE id = :user_id'
            )->execute(['user_id' => $userId]);

            $pdo->prepare(
                'UPDATE bf_email_verifications
                 SET used_at = NOW()
                 WHERE id = :id'
            )->execute(['id' => (int) $verificationId]);

            $pdo->prepare(
                'DELETE FROM bf_email_verifications WHERE user_id = :user_id'
            )->execute(['user_id' => $userId]);

            $pdo->commit();
            unset(
                $_SESSION['pending_verification_email'],
                $_SESSION['development_verification_url']
            );
            $verified = true;
        } else {
            $pdo->rollBack();
            $error = 'Liên kết xác minh không hợp lệ, đã được sử dụng hoặc đã hết hạn.';
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[BrightFuture Email Verification] ' . $exception->getMessage());
        $error = 'Không thể xác minh email lúc này.';
    }
} elseif ($token !== '' || $userId > 0) {
    $error = 'Liên kết xác minh không hợp lệ.';
}

$pageTitle = 'Xác minh email | ' . APP_NAME;
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-error.css')) ?>">
</head>
<body>
<main class="bf-error-page">
    <section class="bf-error-card" role="alert">
        <div class="bf-error-code"><?= $verified ? 'THÀNH CÔNG' : 'XÁC MINH EMAIL' ?></div>
        <h1><?= $verified ? 'Email đã được xác minh' : 'Không thể xác minh email' ?></h1>
        <p><?= e($verified ? 'Tài khoản đã sẵn sàng để đăng nhập.' : ($error ?: 'Liên kết xác minh không tồn tại.')) ?></p>
        <a class="bf-error-action" href="<?= e(app_url('login.php')) ?>">Đến trang đăng nhập</a>
    </section>
</main>
</body>
</html>
