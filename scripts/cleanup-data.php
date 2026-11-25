<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/cleanup-data.php --dry-run|--apply\n");
    exit(2);
}

$targets = [
    ['table' => 'bf_meeting_signals', 'column' => 'created_at', 'days' => 7],
    ['table' => 'bf_meeting_chat', 'column' => 'created_at', 'days' => 90],
    ['table' => 'bf_audit_logs', 'column' => 'created_at', 'days' => 180],
    ['table' => 'bf_login_attempts', 'column' => 'attempted_at', 'days' => 7],
    ['table' => 'bf_rate_limit_windows', 'column' => 'window_started_at', 'days' => 1],
];

try {
    $pdo = db();
    foreach ($targets as $target) {
        $table = $target['table'];
        $column = $target['column'];
        $days = $target['days'];
        $condition = sprintf(
            '`%s` < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $column,
            $days
        );

        if ($mode === '--dry-run') {
            $count = $pdo->query(
                "SELECT COUNT(*) FROM `{$table}` WHERE {$condition}"
            )->fetchColumn();
            echo "{$table}: {$count} rows eligible (older than {$days} days)." . PHP_EOL;
            continue;
        }

        $deleted = 0;
        do {
            $batch = $pdo->exec(
                "DELETE FROM `{$table}` WHERE {$condition} LIMIT 1000"
            );
            if (!is_int($batch)) {
                throw new RuntimeException('Cleanup delete did not return a row count.');
            }
            $deleted += $batch;
        } while ($batch === 1000);

        echo "{$table}: deleted {$deleted} rows (older than {$days} days)." . PHP_EOL;
    }

    echo "bf_direct_messages and bf_group_messages: retained indefinitely by policy." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Data cleanup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
