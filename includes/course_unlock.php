<?php
/** BrightFuture: sequential learning paths. All checks are server-side. PHP 8.0+. */
require_once __DIR__ . '/permissions.php';

function bf_course_unlocked(PDO $pdo, int $courseId, ?int $studentId, array &$visited = []): bool
{
    if ($courseId <= 0) return false;
    if (isset($visited[$courseId])) return false; // fail closed on cycles
    $visited[$courseId] = true;
    try {
        $st = $pdo->prepare('SELECT id, level, status FROM courses WHERE id = :id LIMIT 1');
        $st->execute(['id' => $courseId]);
        $course = $st->fetch(PDO::FETCH_ASSOC);
        if (!$course || $course['status'] !== 'published') return false;
        if ($course['level'] === 'beginner') return true;
        if ($studentId === null || $studentId < 1) return false;
        $st = $pdo->prepare('SELECT prerequisite_course_id, required_test_exercise_id, min_score_percent FROM course_unlock_rules WHERE course_id = :id');
        $st->execute(['id' => $courseId]);
        $rule = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rule) return false; // intentionally hidden unless teacher configures
        $previous = (int)$rule['prerequisite_course_id'];
        if (!bf_course_unlocked($pdo, $previous, $studentId, $visited)) return false;

        // Must be enrolled in previous course and finish every published lesson (at least one).
        $st = $pdo->prepare("SELECT 1 FROM enrollments WHERE student_id = :sid AND course_id = :cid AND status IN ('active','completed') LIMIT 1");
        $st->execute(['sid'=>$studentId,'cid'=>$previous]);
        if (!$st->fetchColumn()) return false;
        $st = $pdo->prepare("SELECT COUNT(*) AS total,
                SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) AS done
            FROM lessons l JOIN course_chapters ch ON ch.id = l.chapter_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = :sid
            WHERE ch.course_id = :cid AND l.status = 'published'");
        $st->execute(['sid'=>$studentId,'cid'=>$previous]);
        $progress = $st->fetch(PDO::FETCH_ASSOC);
        if (!$progress || (int)$progress['total'] < 1 || (int)$progress['done'] !== (int)$progress['total']) return false;

        // Test must belong to prerequisite course, be published, graded, and meet percentage.
        $st = $pdo->prepare("SELECT ex.id, ex.max_score FROM exercises ex
            JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters ch ON ch.id=l.chapter_id
            WHERE ex.id=:tid AND ch.course_id=:cid AND ex.exercise_type='quiz'
              AND ex.status='published' AND l.status='published' LIMIT 1");
        $st->execute(['tid'=>(int)$rule['required_test_exercise_id'],'cid'=>$previous]);
        $test = $st->fetch(PDO::FETCH_ASSOC);
        if (!$test || (float)$test['max_score'] <= 0) return false;
        $st = $pdo->prepare("SELECT MAX(score) FROM exercise_submissions
             WHERE exercise_id=:tid AND student_id=:sid AND status='graded' AND score IS NOT NULL");
        $st->execute(['tid'=>(int)$test['id'],'sid'=>$studentId]);
        $best = $st->fetchColumn();
        return $best !== false && $best !== null
          && (100.0 * (float)$best / (float)$test['max_score']) + 0.00001 >= (float)$rule['min_score_percent'];
    } finally {
        unset($visited[$courseId]);
    }
}

function bf_visible_course_ids(PDO $pdo, ?int $studentId): array
{
    $ids = $pdo->query("SELECT id FROM courses WHERE status='published' ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $visible = [];
    foreach ($ids as $id) {
        if (bf_course_unlocked($pdo, (int)$id, $studentId)) $visible[] = (int)$id;
    }
    return $visible;
}
