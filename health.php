<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$healthy = checkDatabaseConnection();
$payload = [
    'status' => $healthy ? 'ok' : 'unhealthy',
];

http_response_code($healthy ? 200 : 503);
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
