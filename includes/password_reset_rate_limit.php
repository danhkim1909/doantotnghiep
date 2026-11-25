<?php

const BF_PASSWORD_RESET_ACCOUNT_LIMIT = 5;
const BF_PASSWORD_RESET_IP_LIMIT = 20;

function bf_password_reset_email_hash(string $email): string
{
    return hash('sha256', 'account:' . mb_strtolower(trim($email), 'UTF-8'));
}

function bf_password_reset_ip_hash(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        $remoteAddress = '0.0.0.0';
    }

    return hash('sha256', 'ip:' . $remoteAddress);
}

function bf_password_reset_request_allowed(string $email): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $pdo->exec(
            'DELETE FROM bf_password_reset_limits
             WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );

        $identifiers = [
            [
                'scope' => 'account',
                'hash' => bf_password_reset_email_hash($email),
                'limit' => BF_PASSWORD_RESET_ACCOUNT_LIMIT,
            ],
            [
                'scope' => 'ip',
                'hash' => bf_password_reset_ip_hash(),
                'limit' => BF_PASSWORD_RESET_IP_LIMIT,
            ],
        ];
        $increment = $pdo->prepare(
            'INSERT INTO bf_password_reset_limits
                (scope, subject_hash, window_started_at, request_count)
             VALUES
                (:scope, :subject_hash, NOW(), 1)
             ON DUPLICATE KEY UPDATE
                request_count = IF(
                    window_started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR),
                    1,
                    LEAST(request_count + 1, :count_cap)
                ),
                window_started_at = IF(
                    window_started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR),
                    NOW(),
                    window_started_at
                )'
        );
        $readCount = $pdo->prepare(
            'SELECT request_count
             FROM bf_password_reset_limits
             WHERE scope = :scope AND subject_hash = :subject_hash'
        );
        $allowed = true;

        foreach ($identifiers as $identifier) {
            $increment->execute([
                'scope' => $identifier['scope'],
                'subject_hash' => $identifier['hash'],
                'count_cap' => $identifier['limit'] + 1,
            ]);
            $readCount->execute([
                'scope' => $identifier['scope'],
                'subject_hash' => $identifier['hash'],
            ]);
            if ((int) $readCount->fetchColumn() > $identifier['limit']) {
                $allowed = false;
            }
        }

        $pdo->commit();
        return $allowed;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
