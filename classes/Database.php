<?php

declare(strict_types=1);

namespace BrightFuture;

use PDO;

require_once __DIR__ . '/../config/db.php';

/**
 * Lớp quản trị kết nối CSDL theo mô hình Singleton (OOP).
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = getDBConnection();
        }
        return self::$instance;
    }
}
