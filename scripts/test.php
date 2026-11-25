<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/course_unlock.php';
require_once __DIR__ . '/../includes/lesson_media.php';
require_once __DIR__ . '/../includes/submission_storage.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/password_reset_rate_limit.php';
require_once __DIR__ . '/../includes/ai_quota.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/portal_ui.php';

$failures = [];
$passed = 0;

function test_assert(bool $condition, string $label): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "PASS  " . $label . PHP_EOL;
    } else {
        $failures[] = $label;
        fwrite(STDERR, "FAIL  " . $label . PHP_EOL);
    }
}

function test_php_lint(string $directory): array
{
    $failures = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $failures[] = $path . ': ' . implode("\n", $output);
        }
        $output = [];
    }

    return $failures;
}

try {
    $previousTrustedProxies = getenv('TRUSTED_PROXIES');
    $previousServer = $_SERVER;
    try {
        putenv('TRUSTED_PROXIES=203.0.113.12');
        $_SERVER['HTTPS'] = 'on';
        $directHttps = app_request_is_https();

        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $trustedProxyHttps = app_request_is_https();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $untrustedForwardedHeaderIgnored = !app_request_is_https();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
        $forwardedChainIgnored = !app_request_is_https();

        test_assert(
            $directHttps
                && $trustedProxyHttps
                && $untrustedForwardedHeaderIgnored
                && $forwardedChainIgnored,
            'HTTPS detection trusts only configured proxy addresses'
        );
    } finally {
        if ($previousTrustedProxies === false) {
            putenv('TRUSTED_PROXIES');
        } else {
            putenv('TRUSTED_PROXIES=' . $previousTrustedProxies);
        }
        $_SERVER = $previousServer;
    }

    test_assert(
        bf_mail_normalize_address(' learner@example.com ') === 'learner@example.com',
        'mail recipient normalization'
    );
    test_assert(
        bf_mail_normalize_address("learner@example.com\r\nBcc: attacker@example.com") === null,
        'mail recipient header injection rejection'
    );
    test_assert(
        bf_mail_smtp_encode_body("First\r\n.dot\nLast")
            === "First\r\n..dot\r\nLast\r\n",
        'SMTP body line-ending and dot-stuffing'
    );
    $headerInjectionRejected = false;
    try {
        bf_mail_validate_header_value("subject\r\nBcc: attacker@example.com", 'subject');
    } catch (InvalidArgumentException) {
        $headerInjectionRejected = true;
    }
    test_assert($headerInjectionRejected, 'mail header injection rejection');

    $smtpResponseStream = fopen('php://temp', 'r+');
    fwrite($smtpResponseStream, "250-example.test\r\n250 SIZE 1024\r\n");
    rewind($smtpResponseStream);
    $smtpResponse = bf_mail_smtp_read($smtpResponseStream);
    fclose($smtpResponseStream);
    test_assert(
        $smtpResponse === "250-example.test\r\n250 SIZE 1024\r\n",
        'SMTP multiline response parsing'
    );

    $smtpRejectStream = fopen('php://temp', 'r+');
    fwrite($smtpRejectStream, "550 rejected\r\n");
    rewind($smtpRejectStream);
    $smtpCodeRejected = false;
    try {
        bf_mail_smtp_command($smtpRejectStream, '', [250]);
    } catch (RuntimeException) {
        $smtpCodeRejected = true;
    }
    fclose($smtpRejectStream);
    test_assert($smtpCodeRejected, 'SMTP unexpected response rejection');

    $textLesson = [
        'id' => 1,
        'video_source' => 'none',
        'video_url' => null,
        'video_file_path' => null,
        'video_duration_seconds' => 0,
        'duration_minutes' => 12,
    ];
    $videoLesson = [
        'id' => 2,
        'video_source' => 'youtube',
        'video_url' => 'https://www.youtube.com/watch?v=abcdefghijk',
        'video_file_path' => null,
        'video_duration_seconds' => 100,
        'duration_minutes' => 2,
    ];
    $uploadedVideoLesson = [
        'id' => 3,
        'video_source' => 'upload',
        'video_url' => null,
        'video_file_path' => 'uploads/lessons/fixture.mp4',
    ];
    test_assert(!bf_lesson_has_video($textLesson), 'text lesson without video detection');
    test_assert(
        bf_lesson_media_source($uploadedVideoLesson)['type'] === 'mp4',
        'uploaded lesson video detection'
    );
    test_assert(
        bf_submission_path_is_within('/srv/private/submissions/file.pdf', '/srv/private')
            && !bf_submission_path_is_within('/srv/private-extra/file.pdf', '/srv/private'),
        'private storage path boundary'
    );
    $previousStoragePath = getenv('SUBMISSION_STORAGE_PATH');
    $previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $testStorageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'brightfuture-storage-test-' . bin2hex(random_bytes(8));
    try {
        putenv('SUBMISSION_STORAGE_PATH=' . $testStorageRoot);
        $_SERVER['DOCUMENT_ROOT'] = BASE_PATH;
        $testStorageDirectory = bf_submission_storage_dir();
        $testFileName = bin2hex(random_bytes(20)) . '.txt';
        $testFilePath = $testStorageDirectory . DIRECTORY_SEPARATOR . $testFileName;
        $writeResult = file_put_contents($testFilePath, 'private fixture');
        $resolvedTestFile = $writeResult === false
            ? null
            : bf_submission_resolve_file(bf_submission_file_key($testFileName));
        test_assert(
            $resolvedTestFile !== null
                && realpath($testFilePath) === $resolvedTestFile
                && !bf_submission_path_is_within($resolvedTestFile, BASE_PATH),
            'private submission storage resolution'
        );
    } finally {
        if (isset($testFilePath) && is_file($testFilePath)) {
            unlink($testFilePath);
        }
        if (isset($testStorageDirectory) && is_dir($testStorageDirectory)) {
            rmdir($testStorageDirectory);
        }
        if (is_dir($testStorageRoot)) {
            rmdir($testStorageRoot);
        }
        if ($previousStoragePath === false) {
            putenv('SUBMISSION_STORAGE_PATH');
        } else {
            putenv('SUBMISSION_STORAGE_PATH=' . $previousStoragePath);
        }
        if ($previousDocumentRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
        }
    }
    test_assert(
        bf_lesson_video_completion_allowed($textLesson, 0),
        'non-video lesson completion without playback'
    );
    test_assert(
        !bf_lesson_video_completion_allowed($videoLesson, 89)
            && bf_lesson_video_completion_allowed($videoLesson, 90),
        'video lesson watch threshold remains enforced'
    );

    $unlockContext = [
        'student_id' => 12,
        'courses' => [
            101 => ['level' => 'beginner', 'status' => 'published', 'deleted_at' => null],
            102 => ['level' => 'intermediate', 'status' => 'published', 'deleted_at' => null],
            103 => ['level' => 'intermediate', 'status' => 'published', 'deleted_at' => null],
            104 => ['level' => 'advanced', 'status' => 'published', 'deleted_at' => null],
            105 => ['level' => 'advanced', 'status' => 'published', 'deleted_at' => null],
        ],
        'rules' => [
            102 => [
                'prerequisite_course_id' => 101,
                'required_test_exercise_id' => 501,
                'min_score_percent' => 70.0,
            ],
            103 => [
                'prerequisite_course_id' => 101,
                'required_test_exercise_id' => 501,
                'min_score_percent' => 80.0,
            ],
            104 => [
                'prerequisite_course_id' => 105,
                'required_test_exercise_id' => 501,
                'min_score_percent' => 70.0,
            ],
            105 => [
                'prerequisite_course_id' => 104,
                'required_test_exercise_id' => 501,
                'min_score_percent' => 70.0,
            ],
        ],
        'enrolled' => [101 => true],
        'progress' => [101 => ['total' => 2, 'done' => 2]],
        'tests' => [501 => ['course_id' => 101, 'max_score' => 10.0]],
        'best_scores' => [501 => 7.0],
    ];
    $unlockMemo = [];
    $unlockPath = [];
    $meetsUnlockThreshold = bf_course_unlock_evaluate(102, $unlockContext, $unlockPath, $unlockMemo);
    $belowUnlockThreshold = bf_course_unlock_evaluate(103, $unlockContext, $unlockPath, $unlockMemo);
    $cyclicUnlockRejected = !bf_course_unlock_evaluate(104, $unlockContext, $unlockPath, $unlockMemo);
    test_assert(
        $meetsUnlockThreshold && !$belowUnlockThreshold && $cyclicUnlockRejected,
        'course unlock evaluation enforces score thresholds and rejects cycles'
    );

    $pdo = db();
    test_assert(checkDatabaseConnection(), 'database connection');

    $publishedCourseIds = $pdo->query(
        "SELECT id FROM courses
         WHERE status = 'published' AND deleted_at IS NULL
         ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $individualUnlockStatuses = [];
    foreach ($publishedCourseIds as $courseId) {
        $courseId = (int)$courseId;
        $individualUnlockStatuses[$courseId] = bf_course_unlocked($pdo, $courseId, 1);
    }
    test_assert(
        bf_course_unlock_statuses($pdo, $publishedCourseIds, 1) === $individualUnlockStatuses,
        'batched course-unlock checks match individual results'
    );

    $requiredTableNames = [
        'bf_password_resets',
        'bf_password_reset_limits',
        'bf_ai_usage_limits',
        'bf_rate_limit_windows',
        'bf_audit_logs',
        'bf_totp_secrets',
        'bf_email_verifications',
        'bf_totp_backup_codes',
        'bf_login_attempts',
        'bf_lesson_discussion_replies',
        'bf_student_notifications',
        'bf_teacher_notifications',
        'bf_student_notes',
        'bf_database_migrations',
    ];

    $existingTables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $existingTables[$tableName] = true;
    }
    foreach ($requiredTableNames as $tableName) {
        test_assert(isset($existingTables[$tableName]), 'table ' . $tableName);
    }

    $rateLimitEntries = [];
    $previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $accountEmail = 'reset-limit-' . bin2hex(random_bytes(8)) . '@example.com';
        $accountHash = bf_password_reset_email_hash($accountEmail);
        $accountIpHash = bf_password_reset_ip_hash();
        $rateLimitEntries[] = ['account', $accountHash];
        $rateLimitEntries[] = ['ip', $accountIpHash];

        $accountRequestsAllowed = true;
        for ($attempt = 0; $attempt < BF_PASSWORD_RESET_ACCOUNT_LIMIT; $attempt++) {
            $accountRequestsAllowed = bf_password_reset_request_allowed($accountEmail)
                && $accountRequestsAllowed;
        }
        $accountBlocked = !bf_password_reset_request_allowed($accountEmail);
        $expireAccountWindow = $pdo->prepare(
            "UPDATE bf_password_reset_limits
             SET window_started_at = DATE_SUB(NOW(), INTERVAL 2 HOUR)
             WHERE scope = 'account' AND subject_hash = :subject_hash"
        );
        $expireAccountWindow->execute(['subject_hash' => $accountHash]);
        $accountWindowReset = bf_password_reset_request_allowed($accountEmail);
        test_assert(
            $accountRequestsAllowed && $accountBlocked && $accountWindowReset,
            'password reset per-account limit and expiry'
        );

        $_SERVER['REMOTE_ADDR'] = '192.0.2.11';
        $ipHash = bf_password_reset_ip_hash();
        $rateLimitEntries[] = ['ip', $ipHash];
        $ipRequestsAllowed = true;
        for ($attempt = 0; $attempt < BF_PASSWORD_RESET_IP_LIMIT; $attempt++) {
            $email = 'reset-ip-limit-' . bin2hex(random_bytes(8)) . '@example.com';
            $rateLimitEntries[] = ['account', bf_password_reset_email_hash($email)];
            $ipRequestsAllowed = bf_password_reset_request_allowed($email)
                && $ipRequestsAllowed;
        }
        $blockedEmail = 'reset-ip-limit-' . bin2hex(random_bytes(8)) . '@example.com';
        $rateLimitEntries[] = ['account', bf_password_reset_email_hash($blockedEmail)];
        $ipBlocked = !bf_password_reset_request_allowed($blockedEmail);
        test_assert(
            $ipRequestsAllowed && $ipBlocked,
            'password reset per-IP limit'
        );
    } finally {
        $deleteRateLimit = $pdo->prepare(
            'DELETE FROM bf_password_reset_limits
             WHERE scope = :scope AND subject_hash = :subject_hash'
        );
        foreach ($rateLimitEntries as [$scope, $hash]) {
            $deleteRateLimit->execute(['scope' => $scope, 'subject_hash' => $hash]);
        }
        if ($previousRemoteAddress === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
        }
    }

    $testAiUserId = 0;
    try {
        $insertAiUser = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, role)
             VALUES (:full_name, :email, :password_hash, :role)'
        );
        $insertAiUser->execute([
            'full_name' => 'AI Quota Test',
            'email' => 'ai-quota-' . bin2hex(random_bytes(8)) . '@example.com',
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => 'student',
        ]);
        $testAiUserId = (int) $pdo->lastInsertId();

        $firstRequest = bf_ai_consume_account_quota($pdo, $testAiUserId);
        $cooldownRequest = bf_ai_consume_account_quota($pdo, $testAiUserId);
        $hourlyRequestsAllowed = true;
        $backdateAiRequest = $pdo->prepare(
            'UPDATE bf_ai_usage_limits
             SET last_request_at = DATE_SUB(NOW(), INTERVAL 6 SECOND)
             WHERE user_id = :user_id'
        );
        for ($attempt = 1; $attempt < BF_AI_ACCOUNT_HOURLY_LIMIT; $attempt++) {
            $backdateAiRequest->execute(['user_id' => $testAiUserId]);
            $quota = bf_ai_consume_account_quota($pdo, $testAiUserId);
            $hourlyRequestsAllowed = $quota['allowed'] && $hourlyRequestsAllowed;
        }
        $backdateAiRequest->execute(['user_id' => $testAiUserId]);
        $hourlyLimit = bf_ai_consume_account_quota($pdo, $testAiUserId);

        $expireAiWindow = $pdo->prepare(
            'UPDATE bf_ai_usage_limits
             SET window_started_at = DATE_SUB(NOW(), INTERVAL 2 HOUR),
                 last_request_at = DATE_SUB(NOW(), INTERVAL 2 HOUR)
             WHERE user_id = :user_id'
        );
        $expireAiWindow->execute(['user_id' => $testAiUserId]);
        $resetWindow = bf_ai_consume_account_quota($pdo, $testAiUserId);
        test_assert(
            $firstRequest['allowed']
                && !$cooldownRequest['allowed']
                && $cooldownRequest['reason'] === 'cooldown'
                && $hourlyRequestsAllowed
                && !$hourlyLimit['allowed']
                && $hourlyLimit['reason'] === 'hourly_limit'
                && $resetWindow['allowed']
                && $resetWindow['count'] === 1,
            'AI account quota, cooldown, and expiry'
        );

        $rateWindowFirst = bf_rate_limit_consume(
            $pdo,
            $testAiUserId,
            'social_chat',
            2,
            60
        );
        $rateWindowSecond = bf_rate_limit_consume(
            $pdo,
            $testAiUserId,
            'social_chat',
            2,
            60
        );
        $rateWindowDenied = !bf_rate_limit_consume(
            $pdo,
            $testAiUserId,
            'social_chat',
            2,
            60
        );
        $expireRateWindow = $pdo->prepare(
            'UPDATE bf_rate_limit_windows
             SET window_started_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE),
                 request_count = 2
             WHERE user_id = :user_id AND scope = :scope'
        );
        $expireRateWindow->execute([
            'user_id' => $testAiUserId,
            'scope' => 'social_chat',
        ]);
        $rateWindowReset = bf_rate_limit_consume(
            $pdo,
            $testAiUserId,
            'social_chat',
            2,
            60
        );
        test_assert(
            $rateWindowFirst
                && $rateWindowSecond
                && $rateWindowDenied
                && $rateWindowReset,
            'account rate-limit window and expiry'
        );
    } finally {
        if ($testAiUserId > 0) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')
                ->execute(['id' => $testAiUserId]);
        }
    }

    $softDeleteColumns = [
        ['users', 'deleted_at'],
        ['courses', 'deleted_at'],
        ['users', 'email_verified_at'],
    ];

    foreach ($softDeleteColumns as [$tableName, $columnName]) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        test_assert(
            $stmt->fetchColumn() !== false,
            'column ' . $tableName . '.' . $columnName
        );
    }

    $secret = bf_totp_generate_secret();
    $code = bf_totp_code($secret);
    test_assert(
        strlen($secret) === 32
            && is_string($code)
            && bf_totp_verify($secret, (string) $code),
        'TOTP generate and verify'
    );

    test_assert(
        function_exists('bf_totp_create_backup_codes'),
        'TOTP backup code helper'
    );
    test_assert(
        is_string(APP_NAME) && APP_NAME !== '',
        'application configuration'
    );

    $emptyHtml = bf_empty_state('Chưa có khóa học', 'Bạn chưa tham gia khóa học nào.', 'courses.php', 'Khám phá ngay', 'courses');
    test_assert(
        str_contains($emptyHtml, 'bf-empty-state')
            && str_contains($emptyHtml, 'Chưa có khóa học')
            && str_contains($emptyHtml, 'Khám phá ngay')
            && str_contains($emptyHtml, 'courses.php'),
        'empty state component rendering'
    );

    $pagSingle = bf_pagination(['total_pages' => 1, 'current_page' => 1], 'courses.php');
    $pagMulti = bf_pagination(['total_pages' => 5, 'current_page' => 2], 'courses.php', ['q' => 'python']);
    test_assert(
        $pagSingle === ''
            && str_contains($pagMulti, 'aria-current="page"')
            && str_contains($pagMulti, 'page=1')
            && str_contains($pagMulti, 'page=3')
            && str_contains($pagMulti, 'q=python'),
        'pagination component rendering'
    );

    $bcHtml = bf_breadcrumb([['Tổng quan', 'student/dashboard.php'], ['Khóa học', 'courses.php'], ['Python Cơ Bản', null]]);
    test_assert(
        str_contains($bcHtml, 'bf-breadcrumb')
            && str_contains($bcHtml, 'Tổng quan')
            && str_contains($bcHtml, 'Python Cơ Bản')
            && str_contains($bcHtml, 'aria-current="page"'),
        'breadcrumb component rendering'
    );

    $lintFailures = test_php_lint(dirname(__DIR__));
    test_assert(
        $lintFailures === [],
        'PHP lint'
    );
    foreach ($lintFailures as $lintFailure) {
        fwrite(STDERR, $lintFailure . PHP_EOL);
    }

    exec(
        'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/migrate.php') . ' status',
        $migrationStatusLines,
        $migrationExitCode
    );
    test_assert($migrationExitCode === 0, 'migration runner status');

    $migrationStatus = implode("\n", $migrationStatusLines);
    test_assert(
        is_string($migrationStatus)
            && !str_contains($migrationStatus, 'pending')
            && !str_contains($migrationStatus, 'changed')
            && !str_contains($migrationStatus, 'inconsistent')
            && !str_contains($migrationStatus, 'adoptable')
            && str_contains($migrationStatus, 'applied  0011_rate_limits_and_retention_indexes.sql'),
        'all migrations applied and unchanged'
    );
} catch (Throwable $exception) {
    $failures[] = 'test suite exception';
    fwrite(STDERR, 'FAIL  test suite exception: ' . $exception->getMessage() . PHP_EOL);
}

echo PHP_EOL;
echo 'Tests: ' . ($passed + count($failures)) . ', Passed: ' . $passed
    . ', Failed: ' . count($failures) . PHP_EOL;

exit($failures === [] ? 0 : 1);
