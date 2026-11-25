<?php

declare(strict_types=1);

namespace BrightFuture\Models;

use BrightFuture\Database;
use PDO;
use Throwable;

require_once __DIR__ . '/../Database.php';

/**
 * Model quản lý Bài tập (Exercises) và Bài nộp (Submissions).
 * Áp dụng kiến trúc OOP chuẩn mực cho PHP 8.1+.
 * Cung cấp logic chấm điểm trắc nghiệm tự động và quản lý bài nộp học viên.
 */
class Exercise
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Tìm bài tập theo ID kèm thông tin bài giảng và khóa học liên quan
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ex.*,
                    l.title AS lesson_title, l.chapter_id,
                    ch.title AS chapter_title, ch.course_id,
                    c.title AS course_title, c.teacher_id
             FROM exercises ex
             INNER JOIN lessons l ON l.id = ex.lesson_id
             INNER JOIN course_chapters ch ON ch.id = l.chapter_id
             INNER JOIN courses c ON c.id = ch.course_id
             WHERE ex.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Lấy danh sách bài tập thuộc một bài học cụ thể
     */
    public function getByLesson(int $lessonId, bool $onlyPublished = true): array
    {
        $sql = 'SELECT * FROM exercises WHERE lesson_id = :lesson_id';
        if ($onlyPublished) {
            $sql .= ' AND status = "published"';
        }
        $sql .= ' ORDER BY position ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['lesson_id' => $lessonId]);

        return $stmt->fetchAll();
    }

    /**
     * Lấy danh sách câu hỏi trắc nghiệm kèm các phương án lựa chọn (options)
     */
    public function getQuestions(int $exerciseId): array
    {
        $stmtQ = $this->db->prepare(
            'SELECT * FROM exercise_questions
             WHERE exercise_id = :exercise_id
             ORDER BY position ASC, id ASC'
        );
        $stmtQ->execute(['exercise_id' => $exerciseId]);
        $questions = $stmtQ->fetchAll();

        if (empty($questions)) {
            return [];
        }

        $questionIds = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

        $stmtOpt = $this->db->prepare(
            "SELECT * FROM question_options
             WHERE question_id IN ($placeholders)
             ORDER BY position ASC, id ASC"
        );
        $stmtOpt->execute($questionIds);
        $allOptions = $stmtOpt->fetchAll();

        $groupedOptions = [];
        foreach ($allOptions as $opt) {
            $groupedOptions[(int)$opt['question_id']][] = $opt;
        }

        foreach ($questions as &$q) {
            $q['options'] = $groupedOptions[(int)$q['id']] ?? [];
        }
        unset($q);

        return $questions;
    }

    /**
     * Lấy bài nộp mới nhất của học viên đối với một bài tập
     */
    public function getLatestSubmission(int $exerciseId, int $studentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, u.full_name AS grader_name
             FROM exercise_submissions s
             LEFT JOIN users u ON u.id = s.graded_by
             WHERE s.exercise_id = :exercise_id AND s.student_id = :student_id
             ORDER BY s.attempt_number DESC, s.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'exercise_id' => $exerciseId,
            'student_id' => $studentId,
        ]);
        $sub = $stmt->fetch();

        return $sub ?: null;
    }

    /**
     * Lấy danh sách các khóa học mà học viên đang đăng ký học
     */
    public function getStudentEnrolledCourses(int $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.title
             FROM enrollments en
             INNER JOIN courses c ON c.id = en.course_id
             WHERE en.student_id = :student_id
               AND en.status IN ('active', 'completed')
               AND c.status = 'published'
             ORDER BY c.title ASC, c.id ASC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Lấy số lượng thống kê theo các trạng thái (Tất cả, Chưa nộp, Chờ chấm, Đã chấm, Quá hạn)
     */
    public function getStudentCounts(int $studentId, int $courseId = 0, string $keyword = ''): array
    {
        $baseSql = " FROM exercises ex
            INNER JOIN lessons l ON l.id = ex.lesson_id
            INNER JOIN course_chapters ch ON ch.id = l.chapter_id
            INNER JOIN courses c ON c.id = ch.course_id
            INNER JOIN enrollments en
                ON en.course_id = c.id
               AND en.student_id = :enrolled_student_id
               AND en.status IN ('active', 'completed')
            LEFT JOIN exercise_submissions sub ON sub.id = (
                SELECT s.id FROM exercise_submissions s
                WHERE s.exercise_id = ex.id AND s.student_id = :latest_student_id
                ORDER BY s.attempt_number DESC, s.id DESC LIMIT 1
            )
            WHERE c.status = 'published'
              AND l.status = 'published'
              AND ex.status = 'published'";

        $statusSql = "CASE
            WHEN sub.id IS NOT NULL AND sub.status IN ('graded','returned') THEN 'graded'
            WHEN sub.id IS NOT NULL THEN 'submitted'
            WHEN ex.deadline IS NOT NULL AND ex.deadline < NOW() THEN 'overdue'
            ELSE 'pending' END";

        $params = [
            'enrolled_student_id' => $studentId,
            'latest_student_id' => $studentId,
        ];

        if ($courseId > 0) {
            $baseSql .= ' AND c.id = :course_id';
            $params['course_id'] = $courseId;
        }

        if ($keyword !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
            $like = '%' . $escaped . '%';
            $baseSql .= " AND (ex.title LIKE :title_search ESCAPE '!'
                                OR l.title LIKE :lesson_search ESCAPE '!'
                                OR c.title LIKE :course_search ESCAPE '!')";
            $params['title_search'] = $like;
            $params['lesson_search'] = $like;
            $params['course_search'] = $like;
        }

        $counts = ['all' => 0, 'pending' => 0, 'submitted' => 0, 'graded' => 0, 'overdue' => 0];

        $stmt = $this->db->prepare("SELECT {$statusSql} AS display_status, COUNT(*) AS total " . $baseSql . " GROUP BY display_status");
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        foreach ($stmt->fetchAll() as $r) {
            $s = (string)$r['display_status'];
            if (array_key_exists($s, $counts)) {
                $counts[$s] = (int)$r['total'];
                $counts['all'] += (int)$r['total'];
            }
        }

        return $counts;
    }

    /**
     * Lấy danh sách bài tập cho học viên kèm trạng thái nộp bài và lọc theo từ khóa, khóa học
     */
    public function getStudentExercises(
        int $studentId,
        array $filters = [],
        int $limit = 10,
        int $offset = 0
    ): array {
        $courseId = (int)($filters['course_id'] ?? 0);
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = (string)($filters['status'] ?? 'all');

        $baseSql = " FROM exercises ex
            INNER JOIN lessons l ON l.id = ex.lesson_id
            INNER JOIN course_chapters ch ON ch.id = l.chapter_id
            INNER JOIN courses c ON c.id = ch.course_id
            INNER JOIN enrollments en
                ON en.course_id = c.id
               AND en.student_id = :enrolled_student_id
               AND en.status IN ('active', 'completed')
            LEFT JOIN exercise_submissions sub ON sub.id = (
                SELECT s.id FROM exercise_submissions s
                WHERE s.exercise_id = ex.id AND s.student_id = :latest_student_id
                ORDER BY s.attempt_number DESC, s.id DESC LIMIT 1
            )
            WHERE c.status = 'published'
              AND l.status = 'published'
              AND ex.status = 'published'";

        $params = [
            'enrolled_student_id' => $studentId,
            'latest_student_id' => $studentId,
            'count_student_id' => $studentId,
        ];

        if ($courseId > 0) {
            $baseSql .= ' AND c.id = :course_id';
            $params['course_id'] = $courseId;
        }

        if ($keyword !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
            $like = '%' . $escaped . '%';
            $baseSql .= " AND (ex.title LIKE :title_search ESCAPE '!'
                                OR l.title LIKE :lesson_search ESCAPE '!'
                                OR c.title LIKE :course_search ESCAPE '!')";
            $params['title_search'] = $like;
            $params['lesson_search'] = $like;
            $params['course_search'] = $like;
        }

        $statusSql = "CASE
            WHEN sub.id IS NOT NULL AND sub.status IN ('graded','returned') THEN 'graded'
            WHEN sub.id IS NOT NULL THEN 'submitted'
            WHEN ex.deadline IS NOT NULL AND ex.deadline < NOW() THEN 'overdue'
            ELSE 'pending' END";

        if ($status !== 'all') {
            $baseSql .= " AND ($statusSql) = :status_filter";
            $params['status_filter'] = $status;
        }

        $selectSql = "SELECT ex.id, ex.lesson_id, ex.title, ex.description, ex.exercise_type, ex.max_score, ex.deadline,
                             ex.max_attempts, ex.position,
                             l.title AS lesson_title,
                             c.id AS course_id, c.title AS course_title,
                             sub.id AS submission_id, sub.status AS submission_status,
                             sub.score AS latest_score, sub.submitted_at, sub.file_path AS latest_file_path,
                             sub.graded_at, sub.attempt_number,
                             ($statusSql) AS display_status,
                             (SELECT COUNT(*) FROM exercise_submissions att
                              WHERE att.exercise_id = ex.id AND att.student_id = :count_student_id)
                              AS attempts_used"
            . $baseSql
            . " ORDER BY
                CASE WHEN ex.deadline IS NULL THEN 1 ELSE 0 END,
                ex.deadline ASC,
                ex.id DESC
              LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($selectSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Đếm tổng số bài tập của học viên theo bộ lọc
     */
    public function countStudentExercises(int $studentId, array $filters = []): int
    {
        $courseId = (int)($filters['course_id'] ?? 0);
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = (string)($filters['status'] ?? 'all');

        $baseSql = " FROM exercises ex
            INNER JOIN lessons l ON l.id = ex.lesson_id
            INNER JOIN course_chapters ch ON ch.id = l.chapter_id
            INNER JOIN courses c ON c.id = ch.course_id
            INNER JOIN enrollments en
                ON en.course_id = c.id
               AND en.student_id = :enrolled_student_id
               AND en.status IN ('active', 'completed')
            LEFT JOIN exercise_submissions sub ON sub.id = (
                SELECT s.id FROM exercise_submissions s
                WHERE s.exercise_id = ex.id AND s.student_id = :latest_student_id
                ORDER BY s.attempt_number DESC, s.id DESC LIMIT 1
            )
            WHERE c.status = 'published'
              AND l.status = 'published'
              AND ex.status = 'published'";

        $params = [
            'enrolled_student_id' => $studentId,
            'latest_student_id' => $studentId,
        ];

        if ($courseId > 0) {
            $baseSql .= ' AND c.id = :course_id';
            $params['course_id'] = $courseId;
        }

        if ($keyword !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
            $like = '%' . $escaped . '%';
            $baseSql .= " AND (ex.title LIKE :title_search ESCAPE '!'
                                OR l.title LIKE :lesson_search ESCAPE '!'
                                OR c.title LIKE :course_search ESCAPE '!')";
            $params['title_search'] = $like;
            $params['lesson_search'] = $like;
            $params['course_search'] = $like;
        }

        $statusSql = "CASE
            WHEN sub.id IS NOT NULL AND sub.status IN ('graded','returned') THEN 'graded'
            WHEN sub.id IS NOT NULL THEN 'submitted'
            WHEN ex.deadline IS NOT NULL AND ex.deadline < NOW() THEN 'overdue'
            ELSE 'pending' END";

        if ($status !== 'all') {
            $baseSql .= " AND ($statusSql) = :status_filter";
            $params['status_filter'] = $status;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*)' . $baseSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Tự động chấm điểm bài tập trắc nghiệm và ghi nhận bài nộp
     * 
     * @param int $exerciseId
     * @param int $studentId
     * @param array<int, int|array<int>> $answers Dạng [question_id => option_id hoặc mảng các option_id]
     * @return array{success: bool, score: float, max_score: float, correct_count: int, total_questions: int, message: string}
     */
    public function submitQuiz(int $exerciseId, int $studentId, array $answers): array
    {
        $exercise = $this->find($exerciseId);
        if (!$exercise) {
            return ['success' => false, 'score' => 0, 'max_score' => 0, 'correct_count' => 0, 'total_questions' => 0, 'message' => 'Bài tập không tồn tại.'];
        }

        // Lấy câu hỏi trắc nghiệm
        $questions = $this->getQuestions($exerciseId);
        $totalQuestions = count($questions);
        if ($totalQuestions === 0) {
            return ['success' => false, 'score' => 0, 'max_score' => (float)$exercise['max_score'], 'correct_count' => 0, 'total_questions' => 0, 'message' => 'Bài tập chưa có câu hỏi.'];
        }

        // Xác định số lần nộp (attempt_number)
        $latest = $this->getLatestSubmission($exerciseId, $studentId);
        $attemptNumber = $latest ? ((int)$latest['attempt_number'] + 1) : 1;

        if ($exercise['max_attempts'] !== null && $attemptNumber > (int)$exercise['max_attempts']) {
            return ['success' => false, 'score' => 0, 'max_score' => (float)$exercise['max_score'], 'correct_count' => 0, 'total_questions' => $totalQuestions, 'message' => 'Bạn đã vượt quá số lần làm bài cho phép.'];
        }

        // Chấm điểm từng câu hỏi
        $correctCount = 0;
        $savedAnswers = []; // Danh sách [question_id, option_id] cần lưu vào submission_answers

        foreach ($questions as $q) {
            $qId = (int)$q['id'];
            $correctOptionIds = [];
            foreach ($q['options'] as $opt) {
                if (!empty($opt['is_correct'])) {
                    $correctOptionIds[] = (int)$opt['id'];
                }
            }

            $userSelected = $answers[$qId] ?? null;
            if ($userSelected === null) {
                continue;
            }

            $selectedList = is_array($userSelected) ? array_map('intval', $userSelected) : [(int)$userSelected];
            foreach ($selectedList as $selectedOptId) {
                $savedAnswers[] = ['question_id' => $qId, 'option_id' => $selectedOptId];
            }

            // So sánh đáp án
            sort($selectedList);
            sort($correctOptionIds);
            if (!empty($correctOptionIds) && $selectedList === $correctOptionIds) {
                $correctCount++;
            }
        }

        $maxScore = (float)$exercise['max_score'];
        $calculatedScore = round(($correctCount / $totalQuestions) * $maxScore, 2);

        // Lưu bài nộp vào CSDL bằng Transaction
        try {
            $this->db->beginTransaction();

            $stmtSub = $this->db->prepare(
                'INSERT INTO exercise_submissions
                 (exercise_id, student_id, attempt_number, status, score, feedback, submitted_at, updated_at)
                 VALUES
                 (:exercise_id, :student_id, :attempt, "graded", :score, :feedback, NOW(), NOW())'
            );
            $feedbackText = "Đạt $correctCount/$totalQuestions câu đúng (" . round(($correctCount / $totalQuestions) * 100) . "%).";
            $stmtSub->execute([
                'exercise_id' => $exerciseId,
                'student_id' => $studentId,
                'attempt' => $attemptNumber,
                'score' => $calculatedScore,
                'feedback' => $feedbackText,
            ]);
            $submissionId = (int)$this->db->lastInsertId();

            if (!empty($savedAnswers)) {
                $stmtAns = $this->db->prepare(
                    'INSERT IGNORE INTO submission_answers (submission_id, question_id, option_id)
                     VALUES (:submission_id, :question_id, :option_id)'
                );
                foreach ($savedAnswers as $sa) {
                    $stmtAns->execute([
                        'submission_id' => $submissionId,
                        'question_id' => $sa['question_id'],
                        'option_id' => $sa['option_id'],
                    ]);
                }
            }

            $this->db->commit();

            return [
                'success' => true,
                'score' => $calculatedScore,
                'max_score' => $maxScore,
                'correct_count' => $correctCount,
                'total_questions' => $totalQuestions,
                'message' => 'Nộp bài và chấm điểm thành công!',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[Exercise::submitQuiz Error] ' . $e->getMessage());
            return [
                'success' => false,
                'score' => 0,
                'max_score' => $maxScore,
                'correct_count' => 0,
                'total_questions' => $totalQuestions,
                'message' => 'Lỗi khi lưu kết quả bài thi: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Nộp bài tập code hoặc bài luận/tự luận
     */
    public function submitTextOrCode(
        int $exerciseId,
        int $studentId,
        string $content,
        string $type = 'coding',
        ?string $filePath = null
    ): array {
        $exercise = $this->find($exerciseId);
        if (!$exercise) {
            return ['success' => false, 'message' => 'Bài tập không tồn tại.'];
        }

        $latest = $this->getLatestSubmission($exerciseId, $studentId);
        $attemptNumber = $latest ? ((int)$latest['attempt_number'] + 1) : 1;

        if ($exercise['max_attempts'] !== null && $attemptNumber > (int)$exercise['max_attempts']) {
            return ['success' => false, 'message' => 'Bạn đã nộp tối đa số lần cho phép.'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO exercise_submissions
             (exercise_id, student_id, attempt_number, submitted_code, submitted_text, file_path, status, submitted_at, updated_at)
             VALUES
             (:exercise_id, :student_id, :attempt, :code, :text, :file, "submitted", NOW(), NOW())'
        );

        $code = ($type === 'coding') ? $content : null;
        $text = ($type === 'text') ? $content : null;

        $ok = $stmt->execute([
            'exercise_id' => $exerciseId,
            'student_id' => $studentId,
            'attempt' => $attemptNumber,
            'code' => $code,
            'text' => $text,
            'file' => $filePath,
        ]);

        return [
            'success' => $ok,
            'attempt' => $attemptNumber,
            'message' => $ok ? 'Nộp bài tập thành công!' : 'Có lỗi khi lưu bài nộp.',
        ];
    }

    /**
     * Giảng viên chấm điểm và để lại nhận xét cho bài nộp
     */
    public function gradeSubmission(
        int $submissionId,
        int $teacherId,
        float $score,
        string $feedback = ''
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE exercise_submissions
             SET score = :score,
                 feedback = :feedback,
                 graded_by = :graded_by,
                 graded_at = NOW(),
                 status = "graded",
                 updated_at = NOW()
             WHERE id = :id'
        );

        return $stmt->execute([
            'score' => $score,
            'feedback' => $feedback,
            'graded_by' => $teacherId,
            'id' => $submissionId,
        ]);
    }

    /**
     * Lấy chi tiết bài nộp và xác thực quyền sở hữu của giảng viên phụ trách khóa học
     */
    public function getSubmissionForTeacher(int $submissionId, int $teacherId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, ex.title AS exercise_title, ex.max_score, ex.exercise_type,
                    ex.id AS exercise_id, ex.deadline, l.title AS lesson_title,
                    c.id AS course_id, c.title AS course_title, u.full_name AS student_name, u.email AS student_email
             FROM exercise_submissions s
             JOIN exercises ex ON ex.id = s.exercise_id
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             JOIN users u ON u.id = s.student_id
             WHERE s.id = :sid AND c.teacher_id = :tid
             LIMIT 1"
        );
        $stmt->execute(['sid' => $submissionId, 'tid' => $teacherId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Thống kê tổng số bài nộp theo các trạng thái của giảng viên
     */
    public function getTeacherSubmissionsCount(int $teacherId): array
    {
        $counts = ['total' => 0, 'submitted' => 0, 'grading' => 0, 'graded' => 0, 'returned' => 0];
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(s.status = 'submitted'), 0) AS submitted,
                    COALESCE(SUM(s.status = 'grading'), 0) AS grading,
                    COALESCE(SUM(s.status = 'graded'), 0) AS graded,
                    COALESCE(SUM(s.status = 'returned'), 0) AS returned
             FROM exercise_submissions s
             JOIN exercises ex ON ex.id = s.exercise_id
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             WHERE c.teacher_id = :tid"
        );
        $stmt->execute(['tid' => $teacherId]);
        $res = $stmt->fetch();

        return $res ? array_merge($counts, $res) : $counts;
    }

    /**
     * Lấy danh sách khóa học của giảng viên phục vụ bộ lọc bài nộp
     */
    public function getTeacherCourses(int $teacherId): array
    {
        $stmt = $this->db->prepare('SELECT id, title FROM courses WHERE teacher_id = :tid AND deleted_at IS NULL ORDER BY title');
        $stmt->execute(['tid' => $teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Lấy danh sách bài tập thuộc các khóa học của giảng viên phục vụ bộ lọc
     */
    public function getTeacherExercises(int $teacherId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ex.id, ex.title, c.id AS course_id
             FROM exercises ex
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             WHERE c.teacher_id = :tid AND c.deleted_at IS NULL
             ORDER BY c.title, ex.title"
        );
        $stmt->execute(['tid' => $teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Giảng viên lưu điểm và nhận xét (chuyển trạng thái sang graded)
     */
    public function saveTeacherGrade(int $submissionId, int $teacherId, float $score, string $feedback): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE exercise_submissions s
             JOIN exercises ex ON ex.id = s.exercise_id
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             SET s.score = :score, s.feedback = :feedback, s.status = 'graded',
                 s.graded_by = :grader, s.graded_at = NOW(), s.updated_at = NOW()
             WHERE s.id = :sid AND c.teacher_id = :teacher"
        );
        return $stmt->execute([
            'score' => $score,
            'feedback' => $feedback,
            'grader' => $teacherId,
            'sid' => $submissionId,
            'teacher' => $teacherId,
        ]);
    }

    /**
     * Giảng viên trả bài để học viên bổ sung
     */
    public function returnTeacherRevision(int $submissionId, int $teacherId, string $feedback): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE exercise_submissions s
             JOIN exercises ex ON ex.id = s.exercise_id
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             SET s.score = NULL, s.feedback = :feedback, s.status = 'returned',
                 s.graded_by = :grader, s.graded_at = NOW(), s.updated_at = NOW()
             WHERE s.id = :sid AND c.teacher_id = :teacher"
        );
        return $stmt->execute([
            'feedback' => $feedback,
            'grader' => $teacherId,
            'sid' => $submissionId,
            'teacher' => $teacherId,
        ]);
    }

    /**
     * Đánh dấu bài nộp đang trong quá trình chấm điểm
     */
    public function markTeacherGrading(int $submissionId, int $teacherId, string $feedback = ''): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE exercise_submissions s
             JOIN exercises ex ON ex.id = s.exercise_id
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters ch ON ch.id = l.chapter_id
             JOIN courses c ON c.id = ch.course_id
             SET s.status = 'grading', s.feedback = :feedback,
                 s.score = NULL, s.graded_by = NULL, s.graded_at = NULL, s.updated_at = NOW()
             WHERE s.id = :sid AND c.teacher_id = :teacher"
        );
        return $stmt->execute([
            'feedback' => $feedback,
            'sid' => $submissionId,
            'teacher' => $teacherId,
        ]);
    }

    /**
     * Lấy chi tiết bài tập thuộc quyền sở hữu của giảng viên
     */
    public function getTeacherExercise(int $exerciseId, int $teacherId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ex.*, l.title AS lesson_title, l.chapter_id, cc.course_id
             FROM exercises ex
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters cc ON cc.id = l.chapter_id
             JOIN courses c ON c.id = cc.course_id
             WHERE ex.id = :id AND c.teacher_id = :teacher_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $exerciseId, 'teacher_id' => $teacherId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Kiểm tra và lấy khóa học thuộc quyền sở hữu của giảng viên
     */
    public function getTeacherCourse(int $courseId, int $teacherId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, title, status FROM courses WHERE id = :id AND teacher_id = :teacher_id LIMIT 1');
        $stmt->execute(['id' => $courseId, 'teacher_id' => $teacherId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Kiểm tra và lấy bài học thuộc khóa học của giảng viên
     */
    public function getTeacherLesson(int $lessonId, int $teacherId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.title, cc.course_id
             FROM lessons l
             JOIN course_chapters cc ON cc.id = l.chapter_id
             JOIN courses c ON c.id = cc.course_id
             WHERE l.id = :id AND c.teacher_id = :teacher_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $lessonId, 'teacher_id' => $teacherId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lấy toàn bộ bài giảng của giảng viên phân cấp theo khóa học và chương
     */
    public function getTeacherLessons(int $teacherId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.title, cc.title AS chapter_title, cc.course_id, c.title AS course_title
             FROM lessons l
             JOIN course_chapters cc ON cc.id = l.chapter_id
             JOIN courses c ON c.id = cc.course_id
             WHERE c.teacher_id = :teacher_id
             ORDER BY c.title, cc.position, l.position, l.id'
        );
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Đếm số lượt bài nộp của học viên trong một bài tập
     */
    public function countExerciseSubmissions(int $exerciseId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE exercise_id = :id');
        $stmt->execute(['id' => $exerciseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Giảng viên xóa bài tập (chỉ cho phép khi chưa có bài nộp nào)
     */
    public function deleteTeacherExercise(int $exerciseId, int $teacherId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE ex FROM exercises ex
             JOIN lessons l ON l.id = ex.lesson_id
             JOIN course_chapters cc ON cc.id = l.chapter_id
             JOIN courses c ON c.id = cc.course_id
             WHERE ex.id = :id AND c.teacher_id = :teacher_id'
        );
        return $stmt->execute(['id' => $exerciseId, 'teacher_id' => $teacherId]);
    }

    /**
     * Đổi trạng thái bài tập (published <-> draft)
     */
    public function toggleTeacherExerciseStatus(int $exerciseId, int $teacherId): ?string
    {
        $exercise = $this->getTeacherExercise($exerciseId, $teacherId);
        if (!$exercise) {
            return null;
        }
        $newStatus = $exercise['status'] === 'published' ? 'draft' : 'published';
        $stmt = $this->db->prepare('UPDATE exercises SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $exerciseId]);
        return $newStatus;
    }

    /**
     * Đếm số lượng bài tập theo bộ lọc quản lý của giảng viên
     */
    public function countTeacherExercisesFiltered(int $teacherId, array $filters = []): int
    {
        $where = ['c.teacher_id = :teacher'];
        $params = ['teacher' => $teacherId];

        if (!empty($filters['course_id'])) {
            $where[] = 'c.id = :course';
            $params['course'] = (int) $filters['course_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 'ex.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $where[] = 'ex.exercise_type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(ex.title LIKE :search1 OR l.title LIKE :search2)';
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT COUNT(*) FROM exercises ex
                JOIN lessons l ON l.id = ex.lesson_id
                JOIN course_chapters cc ON cc.id = l.chapter_id
                JOIN courses c ON c.id = cc.course_id
                WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lấy danh sách bài tập phân trang theo bộ lọc quản lý của giảng viên
     */
    public function getTeacherExercisesFiltered(int $teacherId, array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $where = ['c.teacher_id = :teacher'];
        $params = ['teacher' => $teacherId];

        if (!empty($filters['course_id'])) {
            $where[] = 'c.id = :course';
            $params['course'] = (int) $filters['course_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 'ex.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $where[] = 'ex.exercise_type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(ex.title LIKE :search1 OR l.title LIKE :search2)';
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT ex.id, ex.title, ex.exercise_type, ex.max_score, ex.max_attempts, ex.deadline, ex.status, ex.position,
                       l.title AS lesson_title, c.id AS course_id, c.title AS course_title,
                       (SELECT COUNT(*) FROM exercise_submissions es WHERE es.exercise_id = ex.id) AS submissions_count
                FROM exercises ex
                JOIN lessons l ON l.id = ex.lesson_id
                JOIN course_chapters cc ON cc.id = l.chapter_id
                JOIN courses c ON c.id = cc.course_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY ex.updated_at DESC, ex.id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Thống kê phân phối điểm số học viên (Grade Analytics) phục vụ giảng viên
     */
    public function getTeacherGradeDistribution(int $teacherId, int $courseId = 0, int $exerciseId = 0): array
    {
        $where = [
            'c.teacher_id = :tid',
            "s.status = 'graded'",
            's.score IS NOT NULL',
            'ex.max_score > 0'
        ];
        $params = ['tid' => $teacherId];

        if ($courseId > 0) {
            $where[] = 'c.id = :cid';
            $params['cid'] = $courseId;
        }
        if ($exerciseId > 0) {
            $where[] = 'ex.id = :eid';
            $params['eid'] = $exerciseId;
        }

        $sql = "SELECT s.score, ex.max_score
                FROM exercise_submissions s
                JOIN exercises ex ON ex.id = s.exercise_id
                JOIN lessons l ON l.id = ex.lesson_id
                JOIN course_chapters ch ON ch.id = l.chapter_id
                JOIN courses c ON c.id = ch.course_id
                WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $distribution = [
            'total_graded' => count($rows),
            'average_score' => 0.0,
            'buckets' => [
                'excellent' => ['label' => 'Giỏi / Xuất sắc (≥85%)', 'count' => 0, 'percent' => 0, 'color' => '#10b981'],
                'good' => ['label' => 'Khá (70% - 84%)', 'count' => 0, 'percent' => 0, 'color' => '#3b82f6'],
                'average' => ['label' => 'Trung bình (50% - 69%)', 'count' => 0, 'percent' => 0, 'color' => '#f59e0b'],
                'weak' => ['label' => 'Chưa đạt (<50%)', 'count' => 0, 'percent' => 0, 'color' => '#ef4444'],
            ]
        ];

        if (empty($rows)) {
            return $distribution;
        }

        $totalScoreNormalized = 0.0;
        foreach ($rows as $r) {
            $score = (float)$r['score'];
            $maxScore = (float)$r['max_score'];
            $ratio = $maxScore > 0 ? ($score / $maxScore) : 0;
            $totalScoreNormalized += $ratio * 10; // Đưa về thang điểm 10

            if ($ratio >= 0.85) {
                $distribution['buckets']['excellent']['count']++;
            } elseif ($ratio >= 0.70) {
                $distribution['buckets']['good']['count']++;
            } elseif ($ratio >= 0.50) {
                $distribution['buckets']['average']['count']++;
            } else {
                $distribution['buckets']['weak']['count']++;
            }
        }

        $totalGraded = count($rows);
        $distribution['average_score'] = round($totalScoreNormalized / $totalGraded, 1);

        foreach ($distribution['buckets'] as $key => &$b) {
            $b['percent'] = (int)round(($b['count'] / $totalGraded) * 100);
        }
        unset($b);

        return $distribution;
    }

    /**
     * Trả về tên hiển thị tiếng Việt của loại bài tập
     */
    public static function getTypeLabel(string $type): string
    {
        return match ($type) {
            'quiz' => 'Trắc nghiệm',
            'coding' => 'Lập trình',
            'text' => 'Văn bản',
            'file_upload' => 'Nộp file',
            default => 'Bài tập'
        };
    }

    /**
     * Trả về tên hiển thị tiếng Việt của trạng thái bài tập / bài nộp
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'graded' => 'Đã chấm điểm',
            'submitted' => 'Đã nộp bài',
            'overdue' => 'Quá hạn nộp',
            'pending' => 'Chưa hoàn thành',
            default => 'Chưa làm'
        };
    }
}
