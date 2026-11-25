<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (APP_ENV !== 'development') {
    fwrite(STDERR, "Seed development bị chặn vì APP_ENV không phải development.\n");
    exit(1);
}

$accounts = [
    'admin@brightfuture.local' => [
        'full_name' => 'BrightFuture Development Admin',
        'role' => ROLE_ADMIN,
    ],
    'teacher@brightfuture.local' => [
        'full_name' => 'BrightFuture Development Teacher',
        'role' => ROLE_TEACHER,
    ],
    'student@brightfuture.local' => [
        'full_name' => 'BrightFuture Development Student',
        'role' => ROLE_STUDENT,
    ],
];

$password = 'Dev123456!';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo = db();
    $pdo->beginTransaction();

    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO users
            (full_name, email, password_hash, role, email_verified_at)
         VALUES
            (:full_name, :email, :password_hash, :role, NOW())'
    );

    $created = [];
    foreach ($accounts as $email => $account) {
        $check->execute(['email' => $email]);
        if ($check->fetchColumn() !== false) {
            continue;
        }

        $insert->execute([
            'full_name' => $account['full_name'],
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $account['role'],
        ]);
        $created[] = $email;
    }

    $accountIds = [];
    foreach (array_keys($accounts) as $email) {
        $check->execute(['email' => $email]);
        $accountId = $check->fetchColumn();
        if ($accountId === false) {
            throw new RuntimeException('Development account fixture is missing.');
        }
        $accountIds[$email] = (int) $accountId;
    }

    $courseTitle = 'BrightFuture Development Completion Test Course';
    $courseStmt = $pdo->prepare(
        'SELECT id FROM courses WHERE teacher_id = :teacher_id AND title = :title LIMIT 1'
    );
    $courseStmt->execute([
        'teacher_id' => $accountIds['teacher@brightfuture.local'],
        'title' => $courseTitle,
    ]);
    $courseId = $courseStmt->fetchColumn();
    if ($courseId === false) {
        $insertCourse = $pdo->prepare(
            "INSERT INTO courses (teacher_id, title, description, category, level, status)
             VALUES (:teacher_id, :title, :description, 'Development', 'beginner', 'published')"
        );
        $insertCourse->execute([
            'teacher_id' => $accountIds['teacher@brightfuture.local'],
            'title' => $courseTitle,
            'description' => 'Development-only fixture for lesson progress tests.',
        ]);
        $courseId = (int) $pdo->lastInsertId();
    } else {
        $courseId = (int) $courseId;
        $pdo->prepare(
            "UPDATE courses SET status = 'published', deleted_at = NULL
             WHERE id = :id"
        )->execute(['id' => $courseId]);
    }

    $chapterTitle = 'Development Text Lesson Chapter';
    $chapterStmt = $pdo->prepare(
        'SELECT id FROM course_chapters
         WHERE course_id = :course_id AND title = :title LIMIT 1'
    );
    $chapterStmt->execute(['course_id' => $courseId, 'title' => $chapterTitle]);
    $chapterId = $chapterStmt->fetchColumn();
    if ($chapterId === false) {
        $insertChapter = $pdo->prepare(
            'INSERT INTO course_chapters (course_id, title, description, position)
             VALUES (:course_id, :title, :description, 1)'
        );
        $insertChapter->execute([
            'course_id' => $courseId,
            'title' => $chapterTitle,
            'description' => 'Development-only progress test fixture.',
        ]);
        $chapterId = (int) $pdo->lastInsertId();
    } else {
        $chapterId = (int) $chapterId;
    }

    $lessonTitle = 'Development Text Lesson Completion Test';
    $lessonStmt = $pdo->prepare(
        'SELECT id FROM lessons WHERE chapter_id = :chapter_id AND title = :title LIMIT 1'
    );
    $lessonStmt->execute(['chapter_id' => $chapterId, 'title' => $lessonTitle]);
    $lessonId = $lessonStmt->fetchColumn();
    if ($lessonId === false) {
        $insertLesson = $pdo->prepare(
            "INSERT INTO lessons
                (chapter_id, title, content, lesson_type, video_source, position, status)
             VALUES
                (:chapter_id, :title, :content, 'text', 'none', 1, 'published')"
        );
        $insertLesson->execute([
            'chapter_id' => $chapterId,
            'title' => $lessonTitle,
            'content' => 'Read this short development fixture, then mark the lesson complete.',
        ]);
        $lessonId = (int) $pdo->lastInsertId();
    } else {
        $lessonId = (int) $lessonId;
        $pdo->prepare(
            "UPDATE lessons
             SET content = :content, lesson_type = 'text', video_url = NULL,
                 video_source = 'none', video_file_path = NULL, status = 'published'
             WHERE id = :id"
        )->execute([
            'content' => 'Read this short development fixture, then mark the lesson complete.',
            'id' => $lessonId,
        ]);
    }

    $pdo->prepare(
        "INSERT INTO enrollments (student_id, course_id, status)
         VALUES (:student_id, :course_id, 'active')
         ON DUPLICATE KEY UPDATE status = 'active', completed_at = NULL"
    )->execute([
        'student_id' => $accountIds['student@brightfuture.local'],
        'course_id' => $courseId,
    ]);
    $pdo->prepare(
        'DELETE FROM lesson_progress WHERE student_id = :student_id AND lesson_id = :lesson_id'
    )->execute([
        'student_id' => $accountIds['student@brightfuture.local'],
        'lesson_id' => $lessonId,
    ]);

    $pdo->commit();

    echo "Development lesson fixture ready: " . $lessonTitle . "\n";
    if ($created === []) {
        echo "Development seed: all accounts already exist.\n";
    } else {
        echo "Created development accounts:\n";
        foreach ($created as $email) {
            echo '- ' . $email . "\n";
        }
        echo "Shared development password: " . $password . "\n";
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Development seed failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
