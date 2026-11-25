<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const MIGRATION_DIRECTORY = __DIR__ . '/../database/migrations';

function migration_usage(): never
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/migrate.php status\n");
    fwrite(STDOUT, "  php scripts/migrate.php migrate\n");
    fwrite(STDOUT, "  php scripts/migrate.php dry-run\n");
    exit(0);
}

function migration_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $buffer .= $sql[++$i];
                }
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '' && !str_starts_with(ltrim($statement), '--')) {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '' && !str_starts_with(ltrim($statement), '--')) {
        $statements[] = $statement;
    }

    return $statements;
}

function migration_table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :name
         LIMIT 1'
    );
    $stmt->execute(['name' => $name]);
    return $stmt->fetchColumn() !== false;
}

function migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return $stmt->fetchColumn() !== false;
}

function migration_index_columns(PDO $pdo, string $table, string $index): array
{
    $stmt = $pdo->prepare(
        'SELECT column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name
         ORDER BY seq_in_index'
    );
    $stmt->execute(['table_name' => $table, 'index_name' => $index]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function migration_foreign_key_matches(
    PDO $pdo,
    string $table,
    string $name,
    string $column,
    string $referencedTable,
    string $referencedColumn,
    string $deleteRule
): bool {
    $stmt = $pdo->prepare(
        'SELECT k.column_name AS column_name,
                k.referenced_table_name AS referenced_table_name,
                k.referenced_column_name AS referenced_column_name,
                r.delete_rule AS delete_rule
         FROM information_schema.key_column_usage k
         JOIN information_schema.referential_constraints r
           ON r.constraint_schema = k.constraint_schema
          AND r.table_name = k.table_name
          AND r.constraint_name = k.constraint_name
         WHERE k.constraint_schema = DATABASE()
           AND k.table_name = :table_name
           AND k.constraint_name = :constraint_name
         ORDER BY k.ordinal_position'
    );
    $stmt->execute(['table_name' => $table, 'constraint_name' => $name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1
        && $rows[0]['column_name'] === $column
        && $rows[0]['referenced_table_name'] === $referencedTable
        && $rows[0]['referenced_column_name'] === $referencedColumn
        && strtoupper((string) $rows[0]['delete_rule']) === strtoupper($deleteRule);
}

function migration_feature_schema(PDO $pdo, string $filename): array
{
    $requirements = match ($filename) {
        '0001_password_resets.sql' => [
            'tables' => [
                'bf_password_resets' => ['id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'created_at'],
            ],
            'indexes' => [
                ['bf_password_resets', 'PRIMARY', ['id']],
                ['bf_password_resets', 'uq_password_reset_token', ['token_hash']],
                ['bf_password_resets', 'idx_password_reset_user', ['user_id', 'expires_at']],
            ],
            'foreign_keys' => [
                ['bf_password_resets', 'fk_password_reset_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        '0002_audit_log.sql' => [
            'tables' => [
                'bf_audit_logs' => ['id', 'actor_id', 'action', 'target_type', 'target_id', 'metadata', 'ip_address', 'user_agent', 'created_at'],
            ],
            'indexes' => [
                ['bf_audit_logs', 'PRIMARY', ['id']],
                ['bf_audit_logs', 'idx_audit_actor_created', ['actor_id', 'created_at']],
                ['bf_audit_logs', 'idx_audit_action_created', ['action', 'created_at']],
                ['bf_audit_logs', 'idx_audit_target', ['target_type', 'target_id']],
            ],
            'foreign_keys' => [
                ['bf_audit_logs', 'fk_audit_actor', 'actor_id', 'users', 'id', 'SET NULL'],
            ],
            'columns' => [],
        ],
        '0003_totp.sql' => [
            'tables' => [
                'bf_totp_secrets' => ['user_id', 'secret_base32', 'enabled_at', 'updated_at'],
            ],
            'indexes' => [
                ['bf_totp_secrets', 'PRIMARY', ['user_id']],
            ],
            'foreign_keys' => [
                ['bf_totp_secrets', 'fk_totp_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        '0004_email_verification.sql' => [
            'tables' => [
                'bf_email_verifications' => ['id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'created_at'],
            ],
            'indexes' => [
                ['bf_email_verifications', 'PRIMARY', ['id']],
                ['bf_email_verifications', 'uq_email_verification_token', ['token_hash']],
                ['bf_email_verifications', 'idx_email_verification_user', ['user_id', 'expires_at']],
            ],
            'foreign_keys' => [
                ['bf_email_verifications', 'fk_email_verification_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [['users', 'email_verified_at']],
        ],
        '0005_totp_backup_codes.sql' => [
            'tables' => [
                'bf_totp_backup_codes' => ['id', 'user_id', 'code_hash', 'used_at', 'created_at'],
            ],
            'indexes' => [
                ['bf_totp_backup_codes', 'PRIMARY', ['id']],
                ['bf_totp_backup_codes', 'uq_totp_backup_code', ['code_hash']],
                ['bf_totp_backup_codes', 'idx_totp_backup_user', ['user_id', 'used_at']],
            ],
            'foreign_keys' => [
                ['bf_totp_backup_codes', 'fk_totp_backup_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        '0006_soft_delete.sql' => [
            'tables' => [],
            'indexes' => [
                ['users', 'idx_users_deleted_created', ['deleted_at', 'created_at']],
                ['courses', 'idx_courses_deleted_updated', ['deleted_at', 'updated_at']],
            ],
            'foreign_keys' => [],
            'columns' => [['users', 'deleted_at'], ['courses', 'deleted_at']],
        ],
        '0007_login_attempts.sql' => [
            'tables' => [
                'bf_login_attempts' => ['id', 'email_hash', 'ip_address', 'successful', 'attempted_at'],
            ],
            'indexes' => [
                ['bf_login_attempts', 'PRIMARY', ['id']],
                ['bf_login_attempts', 'idx_login_attempt_ip_time', ['ip_address', 'attempted_at']],
                ['bf_login_attempts', 'idx_login_attempt_email_time', ['email_hash', 'attempted_at']],
            ],
            'foreign_keys' => [],
            'columns' => [],
        ],
        '0008_lesson_discussion_notifications.sql' => [
            'tables' => [
                'bf_lesson_discussion_replies' => [
                    'id', 'discussion_id', 'teacher_id', 'body', 'created_at',
                ],
                'bf_student_notifications' => [
                    'id', 'student_id', 'teacher_id', 'lesson_id', 'discussion_id',
                    'reply_id', 'is_read', 'created_at',
                ],
                'bf_teacher_notifications' => [
                    'id', 'teacher_id', 'lesson_id', 'discussion_id', 'sender_id',
                    'kind', 'is_read', 'created_at',
                ],
            ],
            'indexes' => [
                ['bf_lesson_discussion_replies', 'PRIMARY', ['id']],
                ['bf_lesson_discussion_replies', 'idx_discussion_replies', ['discussion_id', 'id']],
                ['bf_lesson_discussion_replies', 'idx_discussion_replies_teacher', ['teacher_id']],
                ['bf_student_notifications', 'PRIMARY', ['id']],
                ['bf_student_notifications', 'uq_student_notification_reply', ['reply_id']],
                ['bf_student_notifications', 'idx_student_notifications_inbox', ['student_id', 'is_read', 'id']],
                ['bf_student_notifications', 'idx_student_notifications_teacher', ['teacher_id']],
                ['bf_student_notifications', 'idx_student_notifications_lesson', ['lesson_id']],
                ['bf_student_notifications', 'idx_student_notifications_discussion', ['discussion_id']],
                ['bf_teacher_notifications', 'PRIMARY', ['id']],
                ['bf_teacher_notifications', 'uq_teacher_notification_discussion', ['discussion_id']],
                ['bf_teacher_notifications', 'idx_teacher_notifications_inbox', ['teacher_id', 'is_read', 'id']],
                ['bf_teacher_notifications', 'idx_teacher_notifications_sender', ['sender_id']],
                ['bf_teacher_notifications', 'idx_teacher_notifications_lesson', ['lesson_id']],
            ],
            'foreign_keys' => [
                ['bf_lesson_discussion_replies', 'fk_discussion_reply_discussion', 'discussion_id', 'bf_lesson_discussions', 'id', 'CASCADE'],
                ['bf_lesson_discussion_replies', 'fk_discussion_reply_teacher', 'teacher_id', 'users', 'id', 'CASCADE'],
                ['bf_student_notifications', 'fk_student_notification_student', 'student_id', 'users', 'id', 'CASCADE'],
                ['bf_student_notifications', 'fk_student_notification_teacher', 'teacher_id', 'users', 'id', 'CASCADE'],
                ['bf_student_notifications', 'fk_student_notification_lesson', 'lesson_id', 'lessons', 'id', 'CASCADE'],
                ['bf_student_notifications', 'fk_student_notification_discussion', 'discussion_id', 'bf_lesson_discussions', 'id', 'CASCADE'],
                ['bf_student_notifications', 'fk_student_notification_reply', 'reply_id', 'bf_lesson_discussion_replies', 'id', 'CASCADE'],
                ['bf_teacher_notifications', 'fk_teacher_notification_teacher', 'teacher_id', 'users', 'id', 'CASCADE'],
                ['bf_teacher_notifications', 'fk_teacher_notification_lesson', 'lesson_id', 'lessons', 'id', 'CASCADE'],
                ['bf_teacher_notifications', 'fk_teacher_notification_discussion', 'discussion_id', 'bf_lesson_discussions', 'id', 'CASCADE'],
                ['bf_teacher_notifications', 'fk_teacher_notification_sender', 'sender_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        '0009_password_reset_rate_limits.sql' => [
            'tables' => [
                'bf_password_reset_limits' => [
                    'scope', 'subject_hash', 'window_started_at', 'request_count',
                ],
            ],
            'indexes' => [
                ['bf_password_reset_limits', 'PRIMARY', ['scope', 'subject_hash']],
                ['bf_password_reset_limits', 'idx_password_reset_limit_window', ['window_started_at']],
            ],
            'foreign_keys' => [],
            'columns' => [],
        ],
        '0010_ai_account_quotas.sql' => [
            'tables' => [
                'bf_ai_usage_limits' => [
                    'user_id', 'window_started_at', 'request_count', 'last_request_at',
                ],
            ],
            'indexes' => [
                ['bf_ai_usage_limits', 'PRIMARY', ['user_id']],
                ['bf_ai_usage_limits', 'idx_ai_usage_window', ['window_started_at']],
            ],
            'foreign_keys' => [
                ['bf_ai_usage_limits', 'fk_ai_usage_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        '0011_rate_limits_and_retention_indexes.sql' => [
            'tables' => [
                'bf_rate_limit_windows' => [
                    'user_id', 'scope', 'window_started_at', 'request_count',
                ],
            ],
            'indexes' => [
                ['bf_rate_limit_windows', 'PRIMARY', ['user_id', 'scope']],
                ['bf_rate_limit_windows', 'idx_rate_limit_window', ['window_started_at']],
                ['bf_audit_logs', 'idx_audit_created', ['created_at']],
                ['bf_login_attempts', 'idx_login_attempt_created', ['attempted_at']],
                ['bf_meeting_chat', 'idx_meeting_chat_created', ['created_at']],
                ['bf_meeting_signals', 'idx_meeting_signals_created', ['created_at']],
            ],
            'foreign_keys' => [
                ['bf_rate_limit_windows', 'fk_rate_limit_window_user', 'user_id', 'users', 'id', 'CASCADE'],
            ],
            'columns' => [],
        ],
        default => null,
    };

    if ($requirements === null) {
        return ['state' => 'unmanaged', 'missing' => []];
    }

    $featurePresent = false;
    $missing = [];
    foreach ($requirements['tables'] as $table => $columns) {
        if (!migration_table_exists($pdo, $table)) {
            $missing[] = 'table ' . $table;
            continue;
        }
        $featurePresent = true;
        foreach ($columns as $column) {
            if (!migration_column_exists($pdo, $table, $column)) {
                $missing[] = $table . '.' . $column;
            }
        }
    }

    foreach ($requirements['columns'] as [$table, $column]) {
        if (migration_column_exists($pdo, $table, $column)) {
            $featurePresent = true;
        } else {
            $missing[] = $table . '.' . $column;
        }
    }

    foreach ($requirements['indexes'] as [$table, $index, $columns]) {
        $actualColumns = migration_index_columns($pdo, $table, $index);
        if ($actualColumns !== []) {
            $featurePresent = true;
        }
        if ($actualColumns !== $columns) {
            $missing[] = 'index ' . $table . '.' . $index;
        }
    }

    foreach ($requirements['foreign_keys'] as [
        $table, $name, $column, $referencedTable, $referencedColumn, $deleteRule,
    ]) {
        if (migration_foreign_key_matches(
            $pdo,
            $table,
            $name,
            $column,
            $referencedTable,
            $referencedColumn,
            $deleteRule
        )) {
            $featurePresent = true;
        } else {
            $missing[] = 'foreign key ' . $table . '.' . $name;
        }
    }

    return [
        'state' => $missing === [] ? 'complete' : ($featurePresent ? 'partial' : 'missing'),
        'missing' => $missing,
    ];
}

function migration_core_objects_missing(PDO $pdo): array
{
    $tables = [
        'bf_blocks', 'bf_certificates', 'bf_chat_groups', 'bf_chat_members',
        'bf_direct_messages', 'bf_friendships', 'bf_group_messages',
        'bf_lesson_discussions', 'bf_meeting_chat', 'bf_meeting_participants',
        'bf_meeting_rooms', 'bf_meeting_signals', 'bf_user_reports',
        'course_chapters', 'course_unlock_rules', 'courses', 'enrollments',
        'exercise_questions', 'exercise_submissions', 'exercises', 'lesson_progress',
        'lesson_resources', 'lessons', 'question_options', 'student_profiles',
        'submission_answers', 'teacher_profiles', 'users',
    ];
    $views = ['view_course_statistics', 'view_student_course_progress'];
    $missing = [];

    foreach ($tables as $table) {
        if (!migration_table_exists($pdo, $table)) {
            $missing[] = 'table ' . $table;
        }
    }
    foreach ($views as $view) {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.views
             WHERE table_schema = DATABASE() AND table_name = :name
             LIMIT 1'
        );
        $stmt->execute(['name' => $view]);
        if ($stmt->fetchColumn() === false) {
            $missing[] = 'view ' . $view;
        }
    }

    return $missing;
}

function migration_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bf_database_migrations (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            checksum char(64) COLLATE ascii_bin NOT NULL,
            applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_database_migration_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

$command = strtolower((string) ($argv[1] ?? ''));
if (!in_array($command, ['status', 'migrate', 'dry-run'], true)) {
    migration_usage();
}

try {
    $pdo = db();
    $coreMissing = migration_core_objects_missing($pdo);
    if ($coreMissing !== []) {
        throw new RuntimeException(
            'Core baseline is incomplete. Missing: ' . implode(', ', $coreMissing)
        );
    }
    if ($command === 'migrate') {
        migration_bootstrap($pdo);
    }

    $files = glob(MIGRATION_DIRECTORY . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);

    $applied = [];
    if (migration_table_exists($pdo, 'bf_database_migrations')) {
        foreach ($pdo->query(
            'SELECT name, checksum FROM bf_database_migrations'
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applied[(string) $row['name']] = (string) $row['checksum'];
        }
    }

    $migrations = [];
    foreach ($files as $file) {
        $name = basename($file);
        $sql = file_get_contents($file);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Migration file is empty: ' . $name);
        }

        $checksum = hash('sha256', $sql);
        $migrations[] = [
            'name' => $name,
            'checksum' => $checksum,
            'sql' => $sql,
            'feature' => migration_feature_schema($pdo, $name),
        ];
    }

    if ($command === 'status') {
        $inconsistent = false;
        foreach ($migrations as $migration) {
            $name = $migration['name'];
            $feature = $migration['feature'];
            if (isset($applied[$name]) && $applied[$name] !== $migration['checksum']) {
                echo "changed  " . $name . PHP_EOL;
                $inconsistent = true;
            } elseif ($feature['state'] === 'partial') {
                echo "inconsistent  " . $name . ' missing: '
                    . implode(', ', $feature['missing']) . PHP_EOL;
                $inconsistent = true;
            } elseif (isset($applied[$name]) && $feature['state'] !== 'complete'
                && $feature['state'] !== 'unmanaged') {
                echo "inconsistent  " . $name . ' schema is missing.' . PHP_EOL;
                $inconsistent = true;
            } elseif (isset($applied[$name])) {
                echo "applied  " . $name . PHP_EOL;
            } elseif ($feature['state'] === 'complete') {
                echo "adoptable  " . $name . PHP_EOL;
            } else {
                echo "pending  " . $name . PHP_EOL;
            }
        }
        exit($inconsistent ? 1 : 0);
    }

    if ($command === 'dry-run') {
        foreach ($migrations as $migration) {
            $name = $migration['name'];
            $feature = $migration['feature'];
            if (isset($applied[$name]) && $applied[$name] !== $migration['checksum']) {
                throw new RuntimeException(
                    'Checksum changed for applied migration: ' . $name
                );
            }
            if ($feature['state'] === 'partial') {
                throw new RuntimeException(
                    'Partial schema for ' . $name . '. Missing: '
                    . implode(', ', $feature['missing'])
                );
            }
            if (isset($applied[$name])) {
                if ($feature['state'] !== 'complete' && $feature['state'] !== 'unmanaged') {
                    throw new RuntimeException('Applied migration schema is missing: ' . $name);
                }
                echo "already applied " . $name . PHP_EOL;
            } elseif ($feature['state'] === 'complete') {
                echo "would adopt " . $name . PHP_EOL;
            } else {
                echo "would apply " . $name . PHP_EOL;
            }
        }
        exit(0);
    }

    foreach ($migrations as $migration) {
        $name = $migration['name'];
        $feature = $migration['feature'];
        if (isset($applied[$name])) {
            if ($applied[$name] !== $migration['checksum']) {
                throw new RuntimeException(
                    'Checksum changed for applied migration: ' . $name
                );
            }
            if ($feature['state'] !== 'complete' && $feature['state'] !== 'unmanaged') {
                throw new RuntimeException('Applied migration schema is missing: ' . $name);
            }
            continue;
        }
        if ($feature['state'] === 'partial') {
            throw new RuntimeException(
                'Partial schema for ' . $name . '. Missing: '
                . implode(', ', $feature['missing'])
            );
        }
        if ($feature['state'] === 'complete') {
            $adopt = $pdo->prepare(
                'INSERT INTO bf_database_migrations (name, checksum)
                 VALUES (:name, :checksum)'
            );
            $adopt->execute([
                'name' => $name,
                'checksum' => $migration['checksum'],
            ]);
            echo "Adopted " . $name . PHP_EOL;
            continue;
        }

        $statements = migration_split_sql($migration['sql']);

        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $feature = migration_feature_schema($pdo, $name);
            if ($feature['state'] !== 'complete' && $feature['state'] !== 'unmanaged') {
                throw new RuntimeException(
                    'Migration produced an incomplete schema for ' . $name . ': '
                    . implode(', ', $feature['missing'])
                );
            }
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO bf_database_migrations (name, checksum)
                 VALUES (:name, :checksum)'
            );
            $insert->execute([
                'name' => $migration['name'],
                'checksum' => $migration['checksum'],
            ]);
            $pdo->commit();
            echo "Applied " . $name . PHP_EOL;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(
                STDERR,
                'Migration failed: ' . $migration['name'] . ' - ' . $exception->getMessage() . PHP_EOL
            );
            exit(1);
        }
    }

    echo "Migration complete.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration runner error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
