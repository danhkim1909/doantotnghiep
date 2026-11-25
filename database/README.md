# BrightFuture database

## Compatibility contract

- MySQL 8.0+ is the supported database engine; CI tests against MySQL 8.0.
- `schema.sql` is the canonical schema for a **new, empty database**. Do not
  import it over a live database; its `DROP VIEW` statements replace views and
  `CREATE TABLE IF NOT EXISTS` does not repair existing tables.
- Views use invoker permissions and contain no deployment-specific database
  account or definer.
- The only supported legacy upgrade is the **legacy core schema**: the tables
  and views listed below match `schema.sql`, except the migration-managed
  objects listed under “Forward migrations.” Older or otherwise divergent
  core schemas are not safely auto-upgradable because their historical SQL
  baselines are not maintained here. Stop and compare a fresh schema export
  before running migrations on such a database.

## Canonical objects

### Core tables

`bf_blocks`, `bf_certificates`, `bf_chat_groups`, `bf_chat_members`,
`bf_direct_messages`, `bf_friendships`, `bf_group_messages`,
`bf_lesson_discussions`, `bf_meeting_chat`, `bf_meeting_participants`,
`bf_meeting_rooms`, `bf_meeting_signals`, `bf_user_reports`,
`course_chapters`, `course_unlock_rules`, `courses`, `enrollments`,
`exercise_questions`, `exercise_submissions`, `exercises`, `lesson_progress`,
`lesson_resources`, `lessons`, `question_options`, `student_profiles`,
`submission_answers`, `teacher_profiles`, `users`.

### Views

- `view_course_statistics`
- `view_student_course_progress`

### Forward migrations

The supported legacy core schema may omit these objects; the migration runner
adds or adopts them:

| Migration | Schema change |
| --- | --- |
| `0001_password_resets.sql` | `bf_password_resets` |
| `0002_audit_log.sql` | `bf_audit_logs` |
| `0003_totp.sql` | `bf_totp_secrets` |
| `0004_email_verification.sql` | `users.email_verified_at`, `bf_email_verifications` |
| `0005_totp_backup_codes.sql` | `bf_totp_backup_codes` |
| `0006_soft_delete.sql` | `users.deleted_at`, `courses.deleted_at`, and their indexes |
| `0007_login_attempts.sql` | `bf_login_attempts` |
| `0008_lesson_discussion_notifications.sql` | Lesson replies and student/teacher notification tables |
| `0009_password_reset_rate_limits.sql` | Hashed per-account and per-IP password-reset request windows |
| `0010_ai_account_quotas.sql` | Per-account AI request windows and cooldown state |
| `0011_rate_limits_and_retention_indexes.sql` | Account rate-limit windows and indexed retention cleanup |

Password-reset requests are limited to five per account and twenty per source
IP per one-hour window. The limiter stores hashed identifiers, returns the
same confirmation for blocked and accepted requests, and removes expired
window records as valid requests arrive.

AI requests are limited to forty per account per hour, with a five-second
minimum interval between requests. The database stores only usage counters and
timestamps; chat content remains in the login session.

## Retention and rate limits

Authenticated user-generated traffic is limited per account: up to 120 meeting
signaling events per minute, 30 meeting-room chat messages per minute, and 30
direct/group chat messages per minute. Login is already limited to five failed
attempts per account or IP per 15 minutes.

The scheduled cleanup command applies these retention periods:

| Data | Retention |
| --- | --- |
| Meeting signaling payloads | 7 days |
| Meeting-room chat | 90 days |
| Audit logs | 180 days |
| Login attempts | 7 days |
| Expired rate-limit windows | 1 day |
| Direct and group messages | Indefinite, per product decision |

Review deletion counts with `php scripts/cleanup-data.php --dry-run`.
Schedule `php scripts/cleanup-data.php --apply` daily to delete eligible rows
in batches. Direct and group messages are intentionally excluded from
automatic deletion.

## Upgrade procedure

1. Put the application in maintenance mode. Back up the database and uploaded
   files; verify that the backup can be restored.
2. Compare the live database’s tables, columns, indexes, foreign keys and views
   with the legacy core contract above. Do not run the migration runner if the
   core schema differs.
3. Configure the application’s database connection to the target database.
4. Inspect the migration plan:

   ```powershell
   php scripts/migrate.php status
   php scripts/migrate.php dry-run
   ```

   Both commands are read-only. `adoptable` means the complete migration
   schema already exists and will be recorded in the ledger. A partial
   migration or changed checksum is an error; inspect the schema and restore
   from backup or repair it deliberately before retrying.
5. Apply migrations and verify the application:

   ```powershell
   php scripts/migrate.php migrate
   php scripts/migrate.php status
   php scripts/smoke.php
   php scripts/test.php
   ```

6. Rehearse the same procedure against a restored copy before production.

MySQL DDL may implicitly commit. The runner validates each feature schema
before recording its migration, but it cannot roll back partially executed DDL.
If a migration fails, keep the application in maintenance mode and restore the
verified backup or complete a reviewed repair; do not re-import `schema.sql`.

## Fresh installation

Create a new empty MySQL 8 database, import `schema.sql`, configure `.env`, then
run:

```powershell
php scripts/migrate.php migrate
php scripts/migrate.php status
php scripts/seed-dev.php
```

The migration runner validates pre-existing migration objects before adopting
them into `bf_database_migrations`. It refuses to modify a database missing
the canonical core tables or views.

## Migration authoring

- Add one forward-only SQL file with the next sequence number.
- Never edit a migration after it has been recorded; the runner stores and
  checks each file’s SHA-256 checksum.
- Specify foreign-key delete behavior explicitly and add appropriate indexes.
- Back up and inspect the target schema before every production migration.
- Never put production credentials or application data in SQL files.

CI imports the current baseline and separately tests an upgrade from the
supported legacy core schema, including partial-schema detection.
