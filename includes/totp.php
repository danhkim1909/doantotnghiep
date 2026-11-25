<?php

if (!function_exists('bf_totp_base32_encode')) {
    function bf_totp_base32_encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = '';
        $bits = 0;
        $value = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $buffer .= $alphabet[($value >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $buffer .= $alphabet[($value << (5 - $bits)) & 31];
        }

        return $buffer;
    }
}

if (!function_exists('bf_totp_base32_decode')) {
    function bf_totp_base32_decode(string $encoded): ?string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
        if ($encoded === '') {
            return null;
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = '';
        $bits = 0;
        $value = 0;

        for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
            $index = strpos($alphabet, $encoded[$i]);
            if ($index === false) {
                return null;
            }

            $value = ($value << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $buffer .= chr(($value >> $bits) & 255);
            }
        }

        return $buffer;
    }
}

if (!function_exists('bf_totp_generate_secret')) {
    function bf_totp_generate_secret(): string
    {
        return substr(bf_totp_base32_encode(random_bytes(20)), 0, 32);
    }
}

if (!function_exists('bf_totp_code')) {
    function bf_totp_code(string $secret, ?int $timestamp = null): ?string
    {
        $secretBytes = bf_totp_base32_decode($secret);
        if ($secretBytes === null) {
            return null;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);
        $binary = pack('N2', 0, $counter);
        $digest = hash_hmac('sha1', $binary, $secretBytes, true);
        $offset = ord(substr($digest, -1)) & 15;
        $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('bf_totp_verify')) {
    function bf_totp_verify(string $secret, string $code): bool
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        for ($window = -1; $window <= 1; $window++) {
            $candidate = bf_totp_code($secret, time() + ($window * 30));
            if ($candidate !== null && hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('bf_totp_is_enabled')) {
    function bf_totp_is_enabled(int $userId): bool
    {
        $stmt = db()->prepare(
            'SELECT 1 FROM bf_totp_secrets WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }
}

if (!function_exists('bf_totp_get_secret')) {
    function bf_totp_get_secret(int $userId): ?string
    {
        $stmt = db()->prepare(
            'SELECT secret_base32 FROM bf_totp_secrets WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $secret = $stmt->fetchColumn();
        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}

if (!function_exists('bf_totp_create_backup_codes')) {
    function bf_totp_create_backup_codes(int $userId, int $count = 10): array
    {
        if ($userId <= 0 || $count < 1) {
            return [];
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare(
                'DELETE FROM bf_totp_backup_codes WHERE user_id = :user_id'
            );
            $delete->execute(['user_id' => $userId]);

            $insert = $pdo->prepare(
                'INSERT INTO bf_totp_backup_codes (user_id, code_hash)
                 VALUES (:user_id, :code_hash)'
            );
            $codes = [];

            for ($i = 0; $i < $count; $i++) {
                $code = strtoupper(bin2hex(random_bytes(4)));
                $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);
                $codes[] = $code;
                $insert->execute([
                    'user_id' => $userId,
                    'code_hash' => hash('sha256', str_replace('-', '', $code)),
                ]);
            }

            $pdo->commit();
            return $codes;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

if (!function_exists('bf_totp_verify_backup_code')) {
    function bf_totp_verify_backup_code(int $userId, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if (!preg_match('/^[A-F0-9]{8}$/D', $normalized)) {
            return false;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM bf_totp_backup_codes
                 WHERE user_id = :user_id
                   AND code_hash = :code_hash
                   AND used_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                'user_id' => $userId,
                'code_hash' => hash('sha256', $normalized),
            ]);
            $id = $stmt->fetchColumn();

            if ($id === false) {
                $pdo->rollBack();
                return false;
            }

            $consume = $pdo->prepare(
                'UPDATE bf_totp_backup_codes
                 SET used_at = NOW()
                 WHERE id = :id AND used_at IS NULL'
            );
            $consume->execute(['id' => (int) $id]);
            $pdo->commit();
            return $consume->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
