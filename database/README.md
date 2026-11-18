# BrightFuture database

## Current state

`schema.sql` is currently empty. The application code expects the database to
already contain the tables and view listed below, so a fresh installation is
not reproducible yet.

This inventory is intentionally derived from the SQL used by the application.
It is not a replacement schema: column types, foreign keys, indexes, and
constraints must be verified against the live database before the first
destructive migration is written.

## Required tables

### Identity and learning

- `users`
- `courses`
- `course_chapters`
- `lessons`
- `enrollments`
- `lesson_progress`
- `course_unlock_rules`
- `lesson_resources`

### Exercises and submissions

- `exercises`
- `exercise_questions`
- `question_options`
- `exercise_submissions`
- `submission_answers`

### Certificates

- `bf_certificates`

### Community and collaboration

- `bf_lesson_discussions`
- `bf_user_reports`
- `bf_chat_groups`
- `bf_chat_members`
- `bf_group_messages`
- `bf_friendships`
- `bf_direct_messages`

### Rooms and meetings

- `bf_meeting_rooms`
- `bf_meeting_participants`
- `bf_meeting_signals`
- `bf_meeting_chat`

## Required view

- `view_student_course_progress`

## Migration rules

1. Export and back up the live database before changing any table.
2. Inspect the live schema with `SHOW CREATE TABLE` and `SHOW CREATE VIEW`.
3. Store one forward-only migration per change in `database/migrations/`.
4. Every foreign key must specify its delete behavior explicitly.
5. Add indexes for every foreign key and for list/filter columns used by the
   dashboards and APIs.
6. Never put production credentials in SQL files or repository files.
7. Run the application smoke tests after every migration.

## Next database implementation step

Create a read-only schema export from the configured database, compare it with
the inventory above, then generate `database/migrations/0001_baseline.sql`
from the verified structure. Do not generate that baseline from guesses: the
application contains destructive operations and a wrong foreign key can delete
learning progress or submissions.
