<?php

if (!function_exists('bf_mail_normalize_address')) {
    function bf_mail_normalize_address(string $address): ?string
    {
        $address = trim($address);
        if (
            strlen($address) > 190
            || preg_match('/[\r\n\x00]/', $address)
            || !filter_var($address, FILTER_VALIDATE_EMAIL)
        ) {
            return null;
        }
        return $address;
    }
}

if (!function_exists('bf_mail_validate_header_value')) {
    function bf_mail_validate_header_value(string $value, string $field): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new InvalidArgumentException('Invalid mail ' . $field . '.');
        }
        return $value;
    }
}

if (!function_exists('bf_mail_smtp_encode_body')) {
    function bf_mail_smtp_encode_body(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        $body = str_replace("\n", "\r\n", $body);

        if (!str_ends_with($body, "\r\n")) {
            $body .= "\r\n";
        }

        return $body;
    }
}

if (!function_exists('bf_mail_smtp_read')) {
    function bf_mail_smtp_read($socket): string
    {
        $response = '';
        $responseCode = null;
        $isComplete = false;
        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if (!is_string($line)) {
                break;
            }

            if (!preg_match('/^(\d{3})([- ])/', $line, $matches)) {
                throw new RuntimeException('SMTP server returned an invalid response.');
            }
            if ($responseCode !== null && $matches[1] !== $responseCode) {
                throw new RuntimeException('SMTP server returned an inconsistent response.');
            }
            $responseCode = $matches[1];
            $response .= $line;
            if ($matches[2] === ' ') {
                $isComplete = true;
                break;
            }
        }

        $metadata = stream_get_meta_data($socket);
        if (!empty($metadata['timed_out'])) {
            throw new RuntimeException('SMTP server response timed out.');
        }
        if ($response === '') {
            throw new RuntimeException('SMTP server closed the connection.');
        }
        if (!$isComplete) {
            throw new RuntimeException('SMTP server returned an incomplete response.');
        }

        return $response;
    }
}

if (!function_exists('bf_mail_smtp_write')) {
    function bf_mail_smtp_write($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write SMTP data.');
            }
            $offset += $written;
        }
    }
}

if (!function_exists('bf_mail_smtp_command')) {
    function bf_mail_smtp_command($socket, string $command, array $expectedCodes): string
    {
        if ($command !== '') {
            bf_mail_smtp_write($socket, $command . "\r\n");
        }
        $response = bf_mail_smtp_read($socket);
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP server rejected a command. Code: ' . $code);
        }
        return $response;
    }
}

if (!function_exists('bf_mail_send_smtp')) {
    function bf_mail_send_smtp(
        string $recipient,
        string $subject,
        string $body
    ): bool {
        $recipient = bf_mail_normalize_address($recipient);
        if ($recipient === null) {
            throw new InvalidArgumentException('Invalid mail recipient.');
        }
        $subject = bf_mail_validate_header_value($subject, 'subject');

        $host = trim((string) env_value('MAIL_HOST', ''));
        if ($host === '') {
            return false;
        }

        $port = (int) env_value('MAIL_PORT', '587');
        $security = strtolower((string) env_value('MAIL_SECURITY', 'starttls'));
        $timeout = max(3, (int) env_value('MAIL_TIMEOUT', '10'));
        $username = (string) env_value('MAIL_USERNAME', '');
        $password = (string) env_value('MAIL_PASSWORD', '');
        $fromAddress = bf_mail_normalize_address(
            (string) env_value('MAIL_FROM', 'no-reply@brightfuture.local')
        );
        $fromName = trim((string) env_value('MAIL_FROM_NAME', 'BrightFuture'));

        if ($fromAddress === null) {
            throw new InvalidArgumentException('Invalid sender email address.');
        }
        $fromName = bf_mail_validate_header_value($fromName, 'sender name');
        if (!in_array($port, [25, 465, 587], true)) {
            throw new RuntimeException('SMTP port is not supported.');
        }
        if (!in_array($security, ['none', 'starttls', 'ssl'], true)) {
            throw new RuntimeException('SMTP security mode is not supported.');
        }
        if ($username !== '' && $security === 'none') {
            throw new RuntimeException('SMTP authentication requires TLS.');
        }

        $transport = $security === 'ssl' ? 'ssl://' . $host : 'tcp://' . $host;
        $socket = @stream_socket_client(
            $transport . ':' . $port,
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Cannot connect to SMTP server.');
        }

        stream_set_timeout($socket, $timeout);
        try {
            bf_mail_smtp_command($socket, '', [220]);
            bf_mail_smtp_command($socket, 'EHLO localhost', [250]);

            if ($security === 'starttls') {
                bf_mail_smtp_command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Cannot enable SMTP TLS.');
                }
                bf_mail_smtp_command($socket, 'EHLO localhost', [250]);
            }

            if ($username !== '') {
                bf_mail_smtp_command($socket, 'AUTH LOGIN', [334]);
                bf_mail_smtp_command($socket, base64_encode($username), [334]);
                bf_mail_smtp_command($socket, base64_encode($password), [235]);
            }

            bf_mail_smtp_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
            bf_mail_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            bf_mail_smtp_command($socket, 'DATA', [354]);

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFromName = $fromName === ''
                ? ''
                : '=?UTF-8?B?' . base64_encode($fromName) . '?= ';
            $headers = [
                'From: ' . $encodedFromName . '<' . $fromAddress . '>',
                'To: ' . $recipient,
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $message = implode("\r\n", $headers)
                . "\r\n\r\n"
                . bf_mail_smtp_encode_body($body)
                . ".\r\n";
            bf_mail_smtp_write($socket, $message);
            bf_mail_smtp_command($socket, '', [250]);

            try {
                bf_mail_smtp_command($socket, 'QUIT', [221]);
            } catch (RuntimeException) {
                error_log('[BrightFuture Mail SMTP] Message accepted, but QUIT failed.');
            }

            return true;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

if (!function_exists('bf_send_mail')) {
    function bf_send_mail(
        string $recipient,
        string $subject,
        string $body
    ): bool {
        $recipient = bf_mail_normalize_address($recipient);
        if ($recipient === null) {
            error_log('[BrightFuture Mail] Recipient email address is invalid.');
            return false;
        }
        try {
            $subject = bf_mail_validate_header_value($subject, 'subject');
        } catch (InvalidArgumentException $exception) {
            error_log('[BrightFuture Mail] ' . $exception->getMessage());
            return false;
        }

        $smtpHost = trim((string) env_value('MAIL_HOST', ''));
        if ($smtpHost !== '') {
            try {
                return bf_mail_send_smtp($recipient, $subject, $body);
            } catch (Throwable $exception) {
                error_log('[BrightFuture Mail SMTP] ' . $exception->getMessage());
                return false;
            }
        }

        if (APP_ENV === 'production') {
            error_log('[BrightFuture Mail] SMTP host is not configured.');
            return false;
        }

        $from = bf_mail_normalize_address(
            (string) env_value('MAIL_FROM', 'no-reply@brightfuture.local')
        );
        if ($from === null) {
            error_log('[BrightFuture Mail] Sender email address is invalid.');
            return false;
        }
        $headers = 'From: ' . $from . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8';
        $sent = @mail($recipient, $subject, $body, $headers);
        if (!$sent) {
            error_log('[BrightFuture Mail] PHP mail transport failed.');
        }
        return $sent;
    }
}
