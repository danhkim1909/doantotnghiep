<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function smtp_fixture_write($socket, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('SMTP fixture could not write a response.');
        }
        $offset += $written;
    }
}

function smtp_fixture_run(string $mode): void
{
    if (!in_array($mode, ['accept', 'reject-recipient'], true)) {
        throw new InvalidArgumentException('Invalid SMTP fixture mode.');
    }

    $server = stream_socket_server(
        'tcp://127.0.0.1:587',
        $errorNumber,
        $errorMessage,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start local SMTP fixture: ' . $errorMessage);
    }

    $address = stream_socket_get_name($server, false);
    $port = is_string($address) ? (int) substr(strrchr($address, ':'), 1) : 0;
    if ($port < 1) {
        fclose($server);
        throw new RuntimeException('Cannot determine local SMTP fixture port.');
    }

    echo 'READY ' . $port . PHP_EOL;
    flush();

    $client = null;
    try {
        $client = stream_socket_accept($server, 10);
        if (!is_resource($client)) {
            throw new RuntimeException('SMTP fixture did not receive a connection.');
        }
        stream_set_timeout($client, 5);
        smtp_fixture_write($client, "220 fixture.local ESMTP\r\n");

        $readingData = false;
        $sawDotStuffedLine = false;
        while (($line = fgets($client, 2048)) !== false) {
            if ($readingData) {
                if ($line === "..fixture line\r\n") {
                    $sawDotStuffedLine = true;
                }
                if ($line === ".\r\n") {
                    $readingData = false;
                    smtp_fixture_write($client, "250 queued\r\n");
                }
                continue;
            }

            $command = strtoupper(rtrim($line, "\r\n"));
            if ($command === 'EHLO LOCALHOST') {
                smtp_fixture_write($client, "250-fixture.local\r\n250 SIZE 100000\r\n");
            } elseif (str_starts_with($command, 'MAIL FROM:')) {
                smtp_fixture_write($client, "250 sender accepted\r\n");
            } elseif (str_starts_with($command, 'RCPT TO:')) {
                if ($mode === 'reject-recipient') {
                    smtp_fixture_write($client, "550 recipient rejected\r\n");
                    echo "RESULT rejected\r\n";
                    return;
                }
                smtp_fixture_write($client, "250 recipient accepted\r\n");
            } elseif ($command === 'DATA') {
                $readingData = true;
                smtp_fixture_write($client, "354 send message\r\n");
            } elseif ($command === 'QUIT') {
                smtp_fixture_write($client, "221 bye\r\n");
                echo $sawDotStuffedLine ? "RESULT accepted dotstuffed\r\n" : "RESULT accepted\r\n";
                return;
            } else {
                smtp_fixture_write($client, "500 unexpected command\r\n");
            }
        }

        echo "RESULT connection-closed\r\n";
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }
        fclose($server);
    }
}

if (($argv[1] ?? '') === '--fixture') {
    try {
        smtp_fixture_run((string) ($argv[2] ?? ''));
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/mail.php';

function smtp_test_start_fixture(string $mode): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--fixture', $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch local SMTP fixture.');
    }

    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    if (!is_string($ready) || !preg_match('/^READY ([0-9]+)\R$/', $ready, $matches)) {
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new RuntimeException('Local SMTP fixture failed to start: ' . trim((string) $stderr));
    }

    return [$process, $pipes, (int) $matches[1]];
}

function smtp_test_finish_fixture($process, array $pipes): array
{
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

$mailEnvironmentKeys = [
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_SECURITY',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM',
    'MAIL_FROM_NAME',
    'MAIL_TIMEOUT',
];
$previousMailEnvironment = [];
foreach ($mailEnvironmentKeys as $key) {
    $previousMailEnvironment[$key] = getenv($key);
}
$previousErrorLog = (string) ini_get('error_log');
$errorLogPath = tempnam(sys_get_temp_dir(), 'brightfuture-smtp-test-');
if ($errorLogPath === false) {
    fwrite(STDERR, "Cannot create temporary SMTP test log.\n");
    exit(1);
}
$activeFixtures = [];
$exitCode = 0;

try {
    ini_set('error_log', $errorLogPath);
    putenv('MAIL_HOST=127.0.0.1');
    putenv('MAIL_SECURITY=none');
    putenv('MAIL_USERNAME=');
    putenv('MAIL_PASSWORD=');
    putenv('MAIL_FROM=no-reply@example.test');
    putenv('MAIL_FROM_NAME=BrightFuture Test');
    putenv('MAIL_TIMEOUT=5');

    [$process, $pipes, $port] = smtp_test_start_fixture('accept');
    $activeFixtures[] = [$process, $pipes];
    putenv('MAIL_PORT=' . $port);
    $sent = bf_send_mail(
        'learner@example.test',
        'Local SMTP integration test',
        "First line\n.fixture line\nLast line"
    );
    [$exitCode, $stdout, $stderr] = smtp_test_finish_fixture($process, $pipes);
    array_pop($activeFixtures);
    if (!$sent || $exitCode !== 0 || !str_contains($stdout, 'RESULT accepted dotstuffed')) {
        $errorLog = file_get_contents($errorLogPath);
        throw new RuntimeException(
            'SMTP acceptance or dot-stuffing test failed: '
            . $stdout . $stderr . (is_string($errorLog) ? $errorLog : '')
        );
    }

    [$process, $pipes, $port] = smtp_test_start_fixture('reject-recipient');
    $activeFixtures[] = [$process, $pipes];
    putenv('MAIL_PORT=' . $port);
    $sent = bf_send_mail(
        'learner@example.test',
        'Local SMTP rejection test',
        'This message must be rejected.'
    );
    [$exitCode, $stdout, $stderr] = smtp_test_finish_fixture($process, $pipes);
    array_pop($activeFixtures);
    $errorLog = file_get_contents($errorLogPath);
    if (
        $sent
        || $exitCode !== 0
        || !str_contains($stdout, 'RESULT rejected')
        || !is_string($errorLog)
        || !str_contains($errorLog, 'Code: 550')
    ) {
        throw new RuntimeException('SMTP rejection handling test failed: ' . $stdout . $stderr);
    }

    echo "SMTP local integration passed: accepted message, dot-stuffing, and logged rejection.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'SMTP local integration failed: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ($activeFixtures as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    foreach ($previousMailEnvironment as $key => $value) {
        if ($value === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
    ini_set('error_log', $previousErrorLog);
    if (is_file($errorLogPath)) {
        unlink($errorLogPath);
    }
}

exit($exitCode);
