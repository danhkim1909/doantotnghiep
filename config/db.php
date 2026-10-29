
<?php



defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'brightfuture');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

defined('DB_CHARSET') ||
    define('DB_CHARSET', 'utf8mb4');


function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false,

                PDO::ATTR_PERSISTENT =>
                    false,

                PDO::ATTR_STRINGIFY_FETCHES =>
                    false,

                PDO::ATTR_TIMEOUT => 5,
            ]
        );

        return $pdo;

    } catch (PDOException $e) {

        error_log(
            '[BrightFuture Database] ' .
            $e->getMessage()
        );

        http_response_code(500);

        throw new RuntimeException(
            'Không thể kết nối cơ sở dữ liệu. ' .
            'Vui lòng kiểm tra cấu hình MySQL.',
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
        $pdo = getDBConnection();

        $statement = $pdo->query('SELECT 1');

        return $statement !== false
            && (int) $statement->fetchColumn() === 1;

    } catch (Throwable $e) {

        error_log(
            '[BrightFuture Database Check] ' .
            $e->getMessage()
        );

        return false;
    }
}
