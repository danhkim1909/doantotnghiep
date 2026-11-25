<?php

declare(strict_types=1);

namespace BrightFuture\Models;

use BrightFuture\Database;
use PDO;

require_once __DIR__ . '/../Database.php';

/**
 * Model quản lý dữ liệu Người dùng (User).
 * Áp dụng kiến trúc OOP theo chuẩn PHP 8.1+.
 */
class User
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Tìm người dùng theo ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, full_name, role, status, avatar, email_verified_at, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Tìm người dùng theo email
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Đếm số lượng người dùng theo vai trò (student, teacher, admin)
     */
    public function countByRole(string $role): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(id) FROM users WHERE role = :role'
        );
        $stmt->execute(['role' => $role]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Cập nhật thông tin hồ sơ
     */
    public function updateProfile(int $id, string $fullName, ?string $avatar = null): bool
    {
        $params = [
            'id' => $id,
            'full_name' => trim($fullName),
        ];

        $avatarSql = '';
        if ($avatar !== null) {
            $avatarSql = ', avatar = :avatar';
            $params['avatar'] = $avatar;
        }

        $stmt = $this->db->prepare(
            "UPDATE users SET full_name = :full_name {$avatarSql}, updated_at = NOW() WHERE id = :id"
        );

        return $stmt->execute($params);
    }
}
