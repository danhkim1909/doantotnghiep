<?php

if (!function_exists('bf_login_ip')) {
    function bf_login_ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}

if (!function_exists('bf_login_email_hash')) {
    function bf_login_email_hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
    }
}

if (!function_exists('bf_login_record_attempt')) {
    function bf_login_record_attempt(string $email, bool $successful): void
    {
        try {
            $stmt = db()->prepare(
                'INSERT INTO bf_login_attempts
                    (email_hash, ip_address, successful)
                 VALUES
                    (:email_hash, :ip_address, :successful)'
            );
            $stmt->execute([
                'email_hash' => bf_login_email_hash($email),
                'ip_address' => bf_login_ip(),
                'successful' => $successful ? 1 : 0,
            ]);
        } catch (Throwable $exception) {
            error_log('[BrightFuture Login Rate Limit] ' . $exception->getMessage());
        }
    }
}

if (!function_exists('bf_login_attempt_count')) {
    function bf_login_attempt_count(string $email, int $seconds): array
    {
        $defaults = ['ip' => 0, 'email' => 0];
        try {
            $since = date('Y-m-d H:i:s', time() - $seconds);
            $ipStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM bf_login_attempts
                 WHERE ip_address = :ip
                   AND successful = 0
                   AND attempted_at >= :since'
            );
            $ipStmt->execute([
                'ip' => bf_login_ip(),
                'since' => $since,
            ]);
            $defaults['ip'] = (int) $ipStmt->fetchColumn();

            $emailStmt = db()->prepare(
                'SELECT COUNT(*)
                 FROM bf_login_attempts
                 WHERE email_hash = :email_hash
                   AND successful = 0
                   AND attempted_at >= :since'
            );
            $emailStmt->execute([
                'email_hash' => bf_login_email_hash($email),
                'since' => $since,
            ]);
            $defaults['email'] = (int) $emailStmt->fetchColumn();
        } catch (Throwable $exception) {
            error_log('[BrightFuture Login Rate Limit] ' . $exception->getMessage());
        }
        return $defaults;
    }
}
