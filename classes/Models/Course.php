<?php

declare(strict_types=1);

namespace BrightFuture\Models;

use BrightFuture\Database;
use PDO;

require_once __DIR__ . '/../Database.php';

/**
 * Model quản lý dữ liệu Khóa học (Course).
 * Áp dụng lập trình hướng đối tượng (OOP) theo chuẩn PHP 8.1+.
 */
class Course
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Tìm khóa học theo ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, u.full_name AS teacher_name
             FROM courses c
             LEFT JOIN users u ON u.id = c.teacher_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Lấy danh sách khóa học học viên đã đăng ký
     */
    public function getStudentCourses(int $studentId, int $limit = 6): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.title, c.category, c.level, c.thumbnail,
                    u.full_name AS teacher_name,
                    e.enrolled_at, e.completed_at
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN users u ON u.id = c.teacher_id
             WHERE e.student_id = :student_id
             ORDER BY e.enrolled_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Tính toán phần trăm tiến độ của học viên trong một khóa học
     */
    public function calculateProgress(int $studentId, int $courseId): float
    {
        $stmtTotal = $this->db->prepare(
            'SELECT COUNT(l.id) AS total_lessons
             FROM lessons l
             INNER JOIN course_chapters ch ON ch.id = l.chapter_id
             WHERE ch.course_id = :course_id'
        );
        $stmtTotal->execute(['course_id' => $courseId]);
        $total = (int) ($stmtTotal->fetchColumn() ?: 0);

        if ($total === 0) {
            return 0.0;
        }

        $stmtCompleted = $this->db->prepare(
            'SELECT COUNT(lp.id) AS completed_lessons
             FROM lesson_progress lp
             INNER JOIN lessons l ON l.id = lp.lesson_id
             INNER JOIN course_chapters ch ON ch.id = l.chapter_id
             WHERE ch.course_id = :course_id
               AND lp.student_id = :student_id
               AND lp.status = "completed"'
        );
        $stmtCompleted->execute([
            'course_id' => $courseId,
            'student_id' => $studentId,
        ]);
        $completed = (int) ($stmtCompleted->fetchColumn() ?: 0);

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Lấy danh sách khóa học gợi ý mới nhất
     */
    public function getRecommended(int $studentId, int $limit = 4): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.title, c.category, c.level, c.thumbnail,
                    u.full_name AS teacher_name
             FROM courses c
             LEFT JOIN users u ON u.id = c.teacher_id
             WHERE c.status = "published"
               AND c.id NOT IN (
                   SELECT course_id FROM enrollments WHERE student_id = :student_id
               )
             ORDER BY c.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
