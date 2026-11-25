<?php
/** BrightFuture: sequential learning paths. All checks are server-side. PHP 8.0+. */
require_once __DIR__ . '/permissions.php';

function bf_course_unlocked(PDO $pdo, int $courseId, ?int $studentId, array &$visited = []): bool
{
    if ($courseId <= 0) return false;
    if (isset($visited[$courseId])) return false; // fail closed on cycles
    $visited[$courseId] = true;
    try {
        $st = $pdo->prepare('SELECT id, level, status FROM courses WHERE id = :id AND deleted_at IS NULL LIMIT 1');
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

function bf_course_unlock_load_context(PDO $pdo, array $courseIds, ?int $studentId): array
{
    $pending = [];
    foreach ($courseIds as $courseId) {
        $courseId = (int)$courseId;
        if ($courseId > 0) {
            $pending[$courseId] = $courseId;
        }
    }

    $courses = [];
    $rules = [];
    $loaded = [];
    while ($pending) {
        $next = [];
        $batches = array_chunk(array_values($pending), 500);
        foreach ($batches as $pendingBatch) {
            $batch = [];
            foreach ($pendingBatch as $id) {
                if (!isset($loaded[$id])) {
                    $batch[] = $id;
                }
            }
            if (!$batch) {
                continue;
            }
            foreach ($batch as $id) {
                $loaded[$id] = true;
            }

            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $pdo->prepare(
                "SELECT c.id, c.level, c.status, c.deleted_at,
                        r.prerequisite_course_id, r.required_test_exercise_id,
                        r.min_score_percent
                 FROM courses c
                 LEFT JOIN course_unlock_rules r ON r.course_id = c.id
                 WHERE c.id IN ({$placeholders})"
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['id'];
                $courses[$id] = [
                    'level' => (string)$row['level'],
                    'status' => (string)$row['status'],
                    'deleted_at' => $row['deleted_at'],
                ];
                if ($row['prerequisite_course_id'] === null) {
                    continue;
                }

                $rules[$id] = [
                    'prerequisite_course_id' => (int)$row['prerequisite_course_id'],
                    'required_test_exercise_id' => (int)$row['required_test_exercise_id'],
                    'min_score_percent' => (float)$row['min_score_percent'],
                ];
                $prerequisiteId = (int)$row['prerequisite_course_id'];
                if ($prerequisiteId > 0 && !isset($loaded[$prerequisiteId])) {
                    $next[$prerequisiteId] = $prerequisiteId;
                }
            }
        }
        $pending = $next;
    }

    $context = [
        'student_id' => $studentId,
        'courses' => $courses,
        'rules' => $rules,
        'enrolled' => [],
        'progress' => [],
        'tests' => [],
        'best_scores' => [],
    ];
    if (!$courses || $studentId === null || $studentId < 1) {
        return $context;
    }

    $loadedCourseIds = array_map('intval', array_keys($courses));
    $coursePlaceholders = implode(',', array_fill(0, count($loadedCourseIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT course_id FROM enrollments
         WHERE student_id = ? AND status IN ('active', 'completed')
           AND course_id IN ({$coursePlaceholders})"
    );
    $stmt->execute(array_merge([$studentId], $loadedCourseIds));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $courseId) {
        $context['enrolled'][(int)$courseId] = true;
    }

    $stmt = $pdo->prepare(
        "SELECT ch.course_id, COUNT(*) AS total,
                SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) AS done
         FROM lessons l
         JOIN course_chapters ch ON ch.id = l.chapter_id
         LEFT JOIN lesson_progress lp
           ON lp.lesson_id = l.id AND lp.student_id = ?
         WHERE l.status = 'published'
           AND ch.course_id IN ({$coursePlaceholders})
         GROUP BY ch.course_id"
    );
    $stmt->execute(array_merge([$studentId], $loadedCourseIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['progress'][(int)$row['course_id']] = [
            'total' => (int)$row['total'],
            'done' => (int)$row['done'],
        ];
    }

    $testIds = [];
    foreach ($rules as $rule) {
        if ($rule['required_test_exercise_id'] > 0) {
            $testIds[$rule['required_test_exercise_id']] = $rule['required_test_exercise_id'];
        }
    }
    if (!$testIds) {
        return $context;
    }

    $testIds = array_values($testIds);
    $testPlaceholders = implode(',', array_fill(0, count($testIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT ex.id, ex.max_score, ch.course_id
         FROM exercises ex
         JOIN lessons l ON l.id = ex.lesson_id
         JOIN course_chapters ch ON ch.id = l.chapter_id
         WHERE ex.id IN ({$testPlaceholders})
           AND ex.exercise_type = 'quiz'
           AND ex.status = 'published'
           AND l.status = 'published'"
    );
    $stmt->execute($testIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['tests'][(int)$row['id']] = [
            'course_id' => (int)$row['course_id'],
            'max_score' => (float)$row['max_score'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT exercise_id, MAX(score) AS best_score
         FROM exercise_submissions
         WHERE student_id = ?
           AND status = 'graded'
           AND score IS NOT NULL
           AND exercise_id IN ({$testPlaceholders})
         GROUP BY exercise_id"
    );
    $stmt->execute(array_merge([$studentId], $testIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['best_scores'][(int)$row['exercise_id']] = (float)$row['best_score'];
    }

    return $context;
}

function bf_course_unlock_evaluate(int $courseId, array $context, array &$path, array &$memo): bool
{
    if ($courseId <= 0 || isset($path[$courseId])) {
        return false;
    }
    if (array_key_exists($courseId, $memo)) {
        return $memo[$courseId];
    }

    $course = $context['courses'][$courseId] ?? null;
    if (
        !$course
        || $course['status'] !== 'published'
        || $course['deleted_at'] !== null
    ) {
        return $memo[$courseId] = false;
    }
    if ($course['level'] === 'beginner') {
        return $memo[$courseId] = true;
    }

    $studentId = $context['student_id'];
    $rule = $context['rules'][$courseId] ?? null;
    if ($studentId === null || $studentId < 1 || !$rule) {
        return $memo[$courseId] = false;
    }

    $path[$courseId] = true;
    try {
        $prerequisiteId = $rule['prerequisite_course_id'];
        if (
            !bf_course_unlock_evaluate($prerequisiteId, $context, $path, $memo)
            || !isset($context['enrolled'][$prerequisiteId])
        ) {
            return $memo[$courseId] = false;
        }

        $progress = $context['progress'][$prerequisiteId] ?? null;
        if (
            !$progress
            || $progress['total'] < 1
            || $progress['done'] !== $progress['total']
        ) {
            return $memo[$courseId] = false;
        }

        $testId = $rule['required_test_exercise_id'];
        $test = $context['tests'][$testId] ?? null;
        $bestScore = $context['best_scores'][$testId] ?? null;
        if (
            !$test
            || $test['course_id'] !== $prerequisiteId
            || $test['max_score'] <= 0
            || $bestScore === null
        ) {
            return $memo[$courseId] = false;
        }

        $scorePercent = 100.0 * $bestScore / $test['max_score'];
        return $memo[$courseId] = $scorePercent + 0.00001 >= $rule['min_score_percent'];
    } finally {
        unset($path[$courseId]);
    }
}

function bf_course_unlock_statuses(PDO $pdo, array $courseIds, ?int $studentId): array
{
    $normalizedIds = [];
    foreach ($courseIds as $courseId) {
        $courseId = (int)$courseId;
        if ($courseId > 0) {
            $normalizedIds[$courseId] = $courseId;
        }
    }
    if (!$normalizedIds) {
        return [];
    }

    $context = bf_course_unlock_load_context($pdo, array_values($normalizedIds), $studentId);
    $memo = [];
    $statuses = [];
    foreach ($normalizedIds as $courseId) {
        $path = [];
        $statuses[$courseId] = bf_course_unlock_evaluate($courseId, $context, $path, $memo);
    }
    return $statuses;
}

function bf_visible_course_ids(PDO $pdo, ?int $studentId): array
{
    $ids = $pdo->query("SELECT id FROM courses WHERE status='published' AND deleted_at IS NULL ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $statuses = bf_course_unlock_statuses($pdo, $ids, $studentId);
    $visible = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($statuses[$id] ?? false) $visible[] = $id;
    }
    return $visible;
}
