<?php

require_once __DIR__ . '/env.php';

defined('DB_HOST') || define('DB_HOST', env_value('DB_HOST', '127.0.0.1'));
defined('DB_PORT') || define('DB_PORT', env_value('DB_PORT', '3306'));
defined('DB_NAME') || define('DB_NAME', env_value('DB_NAME', 'brightfuture'));
defined('DB_USER') || define('DB_USER', env_value('DB_USER', 'root'));
defined('DB_PASS') || define('DB_PASS', env_value('DB_PASS', ''));
defined('DB_CHARSET') || define('DB_CHARSET', env_value('DB_CHARSET', 'utf8mb4'));

function getDBConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('[BrightFuture Database] ' . $e->getMessage());
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            if (!function_exists('render_http_error')) {
                @require_once __DIR__ . '/../includes/functions.php';
            }
            if (function_exists('render_http_error')) {
                render_http_error(
                    500,
                    'Không thể kết nối cơ sở dữ liệu',
                    'Hệ thống đang gặp sự cố kết nối tới máy chủ cơ sở dữ liệu MySQL. Vui lòng đảm bảo MySQL đã được khởi động và thử lại sau ít phút.',
                    function_exists('app_url') ? app_url() : '/'
                );
            }
        }
        http_response_code(500);
        throw new RuntimeException(
            'Không thể kết nối cơ sở dữ liệu. Vui lòng kiểm tra cấu hình MySQL.',
            0,
            $e
        );
    }
}

function db(): PDO
{
    return getDBConnection();
}

function checkDatabaseConnection(): bool
{
    try {
        return (int) getDBConnection()->query('SELECT 1')->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[BrightFuture Database Check] ' . $e->getMessage());
        return false;
    }
}
