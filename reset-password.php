<?php
require_once __DIR__ . '/includes/permissions.php';

require_guest();

$userId = positive_int($_GET['id'] ?? $_POST['id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$errors = [];
$success = '';
$valid = false;

$cleanupExpired = db()->prepare(
    'DELETE FROM bf_password_resets
     WHERE expires_at < NOW() OR used_at IS NOT NULL'
);
$cleanupExpired->execute();

if ($userId > 0 && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $tokenHash = hash('sha256', strtolower($token));
    $stmt = db()->prepare(
        'SELECT id
         FROM bf_password_resets
         WHERE user_id = :user_id
           AND token_hash = :token_hash
           AND expires_at >= NOW()
           AND used_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
    ]);
    $valid = (bool) $stmt->fetchColumn();
}

if (is_post()) {
    require_csrf();

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!$valid) {
        $errors[] = 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';
    } elseif (strlen($password) < 8 || strlen($password) > 128) {
        $errors[] = 'Mật khẩu phải từ 8 đến 128 ký tự.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Mật khẩu cần có ít nhất một chữ cái và một chữ số.';
    } elseif (!hash_equals($password, $confirm)) {
        $errors[] = 'Xác nhận mật khẩu không khớp.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                'SELECT id
                 FROM bf_password_resets
                 WHERE user_id = :user_id
                   AND token_hash = :token_hash
                   AND expires_at >= NOW()
                   AND used_at IS NULL
                 FOR UPDATE'
            );
            $lock->execute([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
            ]);

            $resetId = $lock->fetchColumn();
            if ($resetId === false) {
                $pdo->rollBack();
                $errors[] = 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';
            } else {
                $update = $pdo->prepare(
                    'UPDATE users SET password_hash = :password_hash WHERE id = :user_id'
                );
                $update->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'user_id' => $userId,
                ]);

                $consume = $pdo->prepare(
                    'UPDATE bf_password_resets
                     SET used_at = NOW()
                     WHERE id = :id'
                );
                $consume->execute(['id' => (int) $resetId]);

                $delete = $pdo->prepare(
                    'DELETE FROM bf_password_resets WHERE user_id = :user_id'
                );
                $delete->execute(['user_id' => $userId]);

                $pdo->commit();
                session_regenerate_id(true);
                set_flash('success', 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập.');
                redirect('login.php');
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[BrightFuture Reset Password] ' . $exception->getMessage());
            $errors[] = 'Không thể đặt lại mật khẩu lúc này.';
        }
    }
}

$pageTitle = 'Đặt lại mật khẩu | ' . APP_NAME;
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
        <h1>Đặt lại mật khẩu</h1>
        <?php if (!$valid): ?>
            <p>Liên kết không hợp lệ, đã được sử dụng hoặc đã hết hạn.</p>
            <a class="reset-link" href="<?= e(app_url('forgot-password.php')) ?>">Yêu cầu liên kết mới</a>
        <?php else: ?>
            <p>Tạo mật khẩu mới có ít nhất 8 ký tự, gồm chữ và số.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $userId ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label for="password">Mật khẩu mới</label>
                <input id="password" name="password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required>
                <label for="confirm_password">Xác nhận mật khẩu</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required>
                <button type="submit">Cập nhật mật khẩu</button>
            </form>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="reset-message" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
