<?php

const BF_MEETING_SIGNAL_RATE_LIMIT = 120;
const BF_MEETING_CHAT_RATE_LIMIT = 30;
const BF_SOCIAL_CHAT_RATE_LIMIT = 30;
const BF_USER_RATE_LIMIT_WINDOW_SECONDS = 60;

function bf_rate_limit_consume(
    PDO $pdo,
    int $userId,
    string $scope,
    int $limit,
    int $windowSeconds
): bool {
    if (
        $userId < 1
        || !preg_match('/^[a-z_]{1,32}$/D', $scope)
        || $limit < 1
        || $limit > 65535
        || $windowSeconds < 1
        || $windowSeconds > 86400
    ) {
        throw new InvalidArgumentException('Invalid rate limit configuration.');
    }

    $pdo->beginTransaction();
    try {
        $create = $pdo->prepare(
            'INSERT IGNORE INTO bf_rate_limit_windows
                (user_id, scope, window_started_at, request_count)
             VALUES
                (:user_id, :scope, NOW(), 0)'
        );
        $create->execute(['user_id' => $userId, 'scope' => $scope]);

        $read = $pdo->prepare(
            'SELECT request_count,
                    (window_started_at <= DATE_SUB(NOW(), INTERVAL '
            . $windowSeconds . ' SECOND)) AS window_expired
             FROM bf_rate_limit_windows
             WHERE user_id = :user_id AND scope = :scope
             FOR UPDATE'
        );
        $read->execute(['user_id' => $userId, 'scope' => $scope]);
        $window = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($window)) {
            throw new RuntimeException('Rate limit window could not be created.');
        }

        if ((int) $window['window_expired'] === 1) {
            $reset = $pdo->prepare(
                'UPDATE bf_rate_limit_windows
                 SET window_started_at = NOW(), request_count = 1
                 WHERE user_id = :user_id AND scope = :scope'
            );
            $reset->execute(['user_id' => $userId, 'scope' => $scope]);
            $pdo->commit();
            return true;
        }

        $requestCount = (int) $window['request_count'];
        if ($requestCount >= $limit) {
            $pdo->commit();
            return false;
        }

        $increment = $pdo->prepare(
            'UPDATE bf_rate_limit_windows
             SET request_count = :request_count
             WHERE user_id = :user_id AND scope = :scope'
        );
        $increment->execute([
            'request_count' => $requestCount + 1,
            'user_id' => $userId,
            'scope' => $scope,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
