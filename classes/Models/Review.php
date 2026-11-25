<?php

declare(strict_types=1);

namespace BrightFuture\Models;

use BrightFuture\Database;
use PDO;

require_once __DIR__ . '/../Database.php';

/**
 * Model quản lý Đánh giá & Nhận xét Khóa học (Course Reviews & Ratings).
 * Chuẩn OOP PHP 8.1+.
 */
class Review
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Lấy danh sách đánh giá công khai của một khóa học
     */
    public function getCourseReviews(int $courseId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.rating, r.comment, r.created_at,
                    u.full_name AS student_name
             FROM bf_course_reviews r
             INNER JOIN users u ON u.id = r.student_id
             WHERE r.course_id = :course_id AND r.status = "published"
             ORDER BY r.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':course_id', $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Tính điểm đánh giá trung bình và tổng số lượt đánh giá
     */
    public function getCourseRatingSummary(int $courseId): array
    {
        $stmt = $this->db->prepare(
            'SELECT AVG(rating) AS average_rating, COUNT(id) AS total_reviews
             FROM bf_course_reviews
             WHERE course_id = :course_id AND status = "published"'
        );
        $stmt->execute(['course_id' => $courseId]);
        $row = $stmt->fetch();

        return [
            'average' => $row && $row['average_rating'] !== null ? round((float) $row['average_rating'], 1) : 5.0,
            'total' => (int) ($row['total_reviews'] ?? 0),
        ];
    }

    /**
     * Thêm hoặc cập nhật đánh giá của học viên cho một khóa học
     */
    public function saveReview(int $courseId, int $studentId, int $rating, string $comment): bool
    {
        $rating = max(1, min(5, $rating));
        $comment = trim($comment);

        if ($comment === '') {
            return false;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO bf_course_reviews (course_id, student_id, rating, comment, status, created_at, updated_at)
             VALUES (:course_id, :student_id, :rating, :comment, "published", NOW(), NOW())
             ON DUPLICATE KEY UPDATE rating = :update_rating, comment = :update_comment, updated_at = NOW()'
        );

        return $stmt->execute([
            'course_id' => $courseId,
            'student_id' => $studentId,
            'rating' => $rating,
            'comment' => $comment,
            'update_rating' => $rating,
            'update_comment' => $comment,
        ]);
    }
}
