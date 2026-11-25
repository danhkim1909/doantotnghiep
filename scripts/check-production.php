<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/submission_storage.php';

$failures = [];

if (APP_ENV !== 'production') {
    $failures[] = 'APP_ENV must be production';
}
if (APP_DEBUG) {
    $failures[] = 'APP_DEBUG must be false';
}
if (!checkDatabaseConnection()) {
    $failures[] = 'database connection failed';
}
if (trim((string) env_value('MAIL_HOST', '')) === '') {
    $failures[] = 'MAIL_HOST is not configured';
}
if (trim((string) env_value('MAIL_FROM', '')) === '') {
    $failures[] = 'MAIL_FROM is not configured';
}
try {
    if (!is_writable(bf_submission_storage_dir())) {
        $failures[] = 'private submission storage is not writable';
    }
} catch (RuntimeException $exception) {
    $failures[] = 'private submission storage is not configured or accessible';
}

if ($failures !== []) {
    fwrite(STDERR, "Production preflight failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Production preflight passed.\n";
