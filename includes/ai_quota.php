<?php

const BF_AI_ACCOUNT_HOURLY_LIMIT = 40;

function bf_ai_consume_account_quota(PDO $pdo, int $userId): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid account is required for AI quota checks.');
    }

    $pdo->beginTransaction();
    try {
        $create = $pdo->prepare(
            'INSERT IGNORE INTO bf_ai_usage_limits
                (user_id, window_started_at, request_count, last_request_at)
             VALUES
                (:user_id, NOW(), 0, NULL)'
        );
        $create->execute(['user_id' => $userId]);

        $read = $pdo->prepare(
            'SELECT request_count,
                    (window_started_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS window_expired,
                    (last_request_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)) AS cooldown_active
             FROM bf_ai_usage_limits
             WHERE user_id = :user_id
             FOR UPDATE'
        );
        $read->execute(['user_id' => $userId]);
        $usage = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($usage)) {
            throw new RuntimeException('AI quota row could not be created.');
        }

        if ((int) $usage['window_expired'] === 1) {
            $reset = $pdo->prepare(
                'UPDATE bf_ai_usage_limits
                 SET window_started_at = NOW(), request_count = 1, last_request_at = NOW()
                 WHERE user_id = :user_id'
            );
            $reset->execute(['user_id' => $userId]);
            $pdo->commit();
            return ['allowed' => true, 'reason' => null, 'count' => 1];
        }

        $requestCount = (int) $usage['request_count'];
        if ((int) $usage['cooldown_active'] === 1) {
            $pdo->commit();
            return ['allowed' => false, 'reason' => 'cooldown', 'count' => $requestCount];
        }
        if ($requestCount >= BF_AI_ACCOUNT_HOURLY_LIMIT) {
            $pdo->commit();
            return ['allowed' => false, 'reason' => 'hourly_limit', 'count' => $requestCount];
        }

        $requestCount++;
        $update = $pdo->prepare(
            'UPDATE bf_ai_usage_limits
             SET request_count = :request_count, last_request_at = NOW()
             WHERE user_id = :user_id'
        );
        $update->execute([
            'request_count' => $requestCount,
            'user_id' => $userId,
        ]);
        $pdo->commit();

        return ['allowed' => true, 'reason' => null, 'count' => $requestCount];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
