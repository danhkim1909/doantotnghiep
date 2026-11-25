<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/permissions.php';

$user = require_role(ROLE_STUDENT);
$exerciseId = positive_int($_GET['id'] ?? null);

if ($exerciseId > 0) {
    try {
        $stmt = db()->prepare('SELECT lesson_id FROM exercises WHERE id = ? AND status = "published" LIMIT 1');
        $stmt->execute([$exerciseId]);
        $lessonId = (int) $stmt->fetchColumn();
        if ($lessonId > 0) {
            redirect('student/learn.php?id=' . $lessonId . '#exercise-' . $exerciseId);
        }
    } catch (Throwable $e) {
        error_log('[exercise redirect] ' . $e->getMessage());
    }
}

redirect('student/exercises.php');
