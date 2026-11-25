<?php

declare(strict_types=1);

namespace BrightFuture\Models;

use BrightFuture\Database;
use PDO;

require_once __DIR__ . '/../Database.php';

/**
 * Model quản lý Bài giảng và Tiến độ học tập (Lesson).
 * Áp dụng kiến trúc OOP theo chuẩn PHP 8.1+.
 */
class Lesson
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Tìm bài giảng theo ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, ch.course_id, ch.title AS chapter_title
             FROM lessons l
             INNER JOIN course_chapters ch ON ch.id = l.chapter_id
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Lấy danh sách các bài học gần đây học viên đã tham gia
     */
    public function getRecentStudentLessons(int $studentId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT lp.lesson_id, lp.status AS progress_status, lp.updated_at,
                    l.title AS lesson_title, l.lesson_type,
                    c.id AS course_id, c.title AS course_title
             FROM lesson_progress lp
             INNER JOIN lessons l ON l.id = lp.lesson_id
             INNER JOIN course_chapters ch ON ch.id = l.chapter_id
             INNER JOIN courses c ON c.id = ch.course_id
             WHERE lp.student_id = :student_id
             ORDER BY lp.updated_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Đánh dấu bài học đã hoàn thành
     */
    public function markCompleted(int $studentId, int $lessonId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lesson_progress (student_id, lesson_id, status, completed_at, updated_at)
             VALUES (:student_id, :lesson_id, "completed", NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = "completed", completed_at = NOW(), updated_at = NOW()'
        );

        return $stmt->execute([
            'student_id' => $studentId,
            'lesson_id' => $lessonId,
        ]);
    }
}
