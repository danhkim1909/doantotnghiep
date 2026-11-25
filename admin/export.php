<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/permissions.php';

$admin = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, private');

if (!is_get()) {
    header('Allow: GET');
    render_http_error(405, 'Phương thức không được hỗ trợ', 'Trang export chỉ hỗ trợ phương thức GET.');
}

$type = get_query('type');
$allowedTypes = ['users', 'courses', 'submissions', 'audit'];
if (!in_array($type, $allowedTypes, true)) {
    render_http_error(404, 'Dữ liệu export không tồn tại', 'Loại dữ liệu export không hợp lệ.');
}

const EXPORT_MAX_ROWS = 10000;
$exportQuery = [
    'q' => substr(clean_text(get_query('q')), 0, 100),
    'role' => get_query('role', 'all'),
    'archive' => get_query('archive', 'active'),
    'status' => get_query('status', 'all'),
    'teacher' => positive_int($_GET['teacher'] ?? null),
    'category' => substr(clean_text(get_query('category')), 0, 100),
    'action' => substr(clean_text(get_query('action')), 0, 80),
    'target' => substr(clean_text(get_query('target')), 0, 80),
    'actor' => positive_int($_GET['actor'] ?? null),
];

try {
    $pdo = db();
    $headers = [];
    $rows = [];

    if ($type === 'users') {
        $headers = ['ID', 'Họ và tên', 'Email', 'Vai trò', 'Ngày tạo'];
        $where = ['1=1'];
        $params = [];
        if ($exportQuery['role'] !== 'all' && in_array($exportQuery['role'], ['student','teacher','admin'], true)) {
            $where[] = 'role = :role';
            $params['role'] = $exportQuery['role'];
        }
        if ($exportQuery['archive'] === 'active') $where[] = 'deleted_at IS NULL';
        elseif ($exportQuery['archive'] === 'deleted') $where[] = 'deleted_at IS NOT NULL';
        if ($exportQuery['q'] !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $exportQuery['q']);
            $where[] = "(full_name LIKE :name ESCAPE '!' OR email LIKE :email ESCAPE '!')";
            $params['name'] = '%' . $escaped . '%';
            $params['email'] = '%' . $escaped . '%';
        }
        $sql = 'SELECT id, full_name, email, role, created_at FROM users WHERE '
            . implode(' AND ', $where)
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . EXPORT_MAX_ROWS;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                (int) $row['id'],
                $row['full_name'],
                $row['email'],
                $row['role'],
                $row['created_at'],
            ];
        }
    } elseif ($type === 'courses') {
        $headers = ['ID', 'Tên khóa học', 'Giảng viên', 'Trạng thái', 'Trình độ', 'Ngày tạo'];
        $where = ['1=1'];
        $params = [];
        if ($exportQuery['archive'] === 'active') $where[] = 'c.deleted_at IS NULL';
        elseif ($exportQuery['archive'] === 'deleted') $where[] = 'c.deleted_at IS NOT NULL';
        if ($exportQuery['status'] !== 'all' && in_array($exportQuery['status'], ['draft','published'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $exportQuery['status'];
        }
        if ($exportQuery['teacher'] > 0) {
            $where[] = 'c.teacher_id = :teacher';
            $params['teacher'] = $exportQuery['teacher'];
        }
        if ($exportQuery['q'] !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $exportQuery['q']);
            $where[] = "(c.title LIKE :title ESCAPE '!' OR u.full_name LIKE :teacher_name ESCAPE '!')";
            $params['title'] = '%' . $escaped . '%';
            $params['teacher_name'] = '%' . $escaped . '%';
        }
        if ($exportQuery['category'] !== '') {
            $where[] = 'c.category = :category';
            $params['category'] = $exportQuery['category'];
        }
        $sql = 'SELECT c.id, c.title, u.full_name AS teacher_name, c.status, c.level, c.created_at
                FROM courses c INNER JOIN users u ON u.id = c.teacher_id
                WHERE ' . implode(' AND ', $where)
                . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . EXPORT_MAX_ROWS;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                (int) $row['id'],
                $row['title'],
                $row['teacher_name'],
                $row['status'],
                $row['level'],
                $row['created_at'],
            ];
        }
    } elseif ($type === 'submissions') {
        $headers = ['ID', 'Học viên', 'Khóa học', 'Bài tập', 'Trạng thái', 'Điểm', 'Ngày nộp'];
        $stmt = $pdo->query(
            'SELECT es.id, st.full_name AS student_name, c.title AS course_title,
                    ex.title AS exercise_title, es.status, es.score, es.submitted_at
             FROM exercise_submissions es
             INNER JOIN users st ON st.id = es.student_id
             INNER JOIN exercises ex ON ex.id = es.exercise_id
             INNER JOIN lessons l ON l.id = ex.lesson_id
             INNER JOIN course_chapters cc ON cc.id = l.chapter_id
             INNER JOIN courses c ON c.id = cc.course_id
             ORDER BY es.submitted_at DESC, es.id DESC
             LIMIT ' . EXPORT_MAX_ROWS
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                (int) $row['id'],
                $row['student_name'],
                $row['course_title'],
                $row['exercise_title'],
                $row['status'],
                $row['score'],
                $row['submitted_at'],
            ];
        }
    } else {
        $headers = ['ID', 'Người thực hiện', 'Hành động', 'Đối tượng', 'ID đối tượng', 'Metadata', 'IP', 'Thời gian'];
        $where = ['1=1'];
        $params = [];
        if ($exportQuery['action'] !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $exportQuery['action']);
            $where[] = "l.action LIKE :action ESCAPE '!'";
            $params['action'] = '%' . $escaped . '%';
        }
        if ($exportQuery['target'] !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $exportQuery['target']);
            $where[] = "l.target_type LIKE :target ESCAPE '!'";
            $params['target'] = '%' . $escaped . '%';
        }
        if ($exportQuery['actor'] > 0) {
            $where[] = 'l.actor_id = :actor';
            $params['actor'] = $exportQuery['actor'];
        }
        $sql = 'SELECT l.id, u.full_name AS actor_name, l.action, l.target_type,
                       l.target_id, l.metadata, l.ip_address, l.created_at
                FROM bf_audit_logs l
                LEFT JOIN users u ON u.id = l.actor_id
                WHERE ' . implode(' AND ', $where)
                . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . EXPORT_MAX_ROWS;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                (int) $row['id'],
                $row['actor_name'] ?: '',
                $row['action'],
                $row['target_type'],
                $row['target_id'] !== null ? (int) $row['target_id'] : '',
                $row['metadata'] ?: '',
                $row['ip_address'] ?: '',
                $row['created_at'],
            ];
        }
    }

    bf_audit_log(
        'admin.export',
        $type,
        null,
        ['rows' => count($rows), 'limit' => EXPORT_MAX_ROWS]
    );

    $filename = 'brightfuture-' . $type . '-' . gmdate('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Cannot open CSV output stream.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($output, $row, ',', '"', '\\');
    }
    fclose($output);
    exit;
} catch (Throwable $exception) {
    error_log('[BrightFuture Admin Export] ' . $exception->getMessage());
    render_http_error(500, 'Không thể xuất dữ liệu', 'Đã xảy ra sự cố khi tạo file CSV.');
}
