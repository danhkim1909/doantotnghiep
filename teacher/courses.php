<?php
/** BrightFuture - Teacher course management. PHP 8.0+, MySQL 8.0+ */


require_once __DIR__ . '/../includes/permissions.php';
$user = require_role(ROLE_TEACHER);
$teacherId = (int)$user['id'];
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

const TEACHER_THUMB_MAX_BYTES = 3 * 1024 * 1024;
const TEACHER_COURSES_PER_PAGE = 9;

function tc_flash(string $type, string $message): void { set_flash($type, $message); }
function tc_back(): never { redirect('teacher/courses.php'); }
function tc_owner_course(PDO $pdo, int $courseId, int $teacherId): ?array {
    $stmt = $pdo->prepare('SELECT id, title, description, category, thumbnail, level, status FROM courses WHERE id = :id AND teacher_id = :teacher_id LIMIT 1');
    $stmt->execute(['id' => $courseId, 'teacher_id' => $teacherId]);
    $data = $stmt->fetch();
    return $data ?: null;
}
function tc_upload_thumbnail(array $file): string {
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Không thể tải ảnh lên (mã lỗi: ' . (int)$uploadError . ').');
    }
    $tmp = $file['tmp_name'] ?? null;
    $size = $file['size'] ?? null;
    if (!is_string($tmp) || !is_uploaded_file($tmp) || !is_numeric($size) || (int)$size <= 0 || (int)$size > TEACHER_THUMB_MAX_BYTES) {
        throw new RuntimeException('Ảnh phải có dung lượng từ 1 byte đến 3 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.');
    }
    $details = @getimagesize($tmp);
    if (!$details || !isset($details[0], $details[1]) || $details[0] < 1 || $details[1] < 1 || $details[0] > 6000 || $details[1] > 6000 || ($details['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Tệp ảnh không hợp lệ hoặc kích thước vượt giới hạn.');
    }
    $folder = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'courses';
    if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException('Không thể tạo thư mục lưu ảnh.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = $folder . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Không thể lưu ảnh khóa học.');
    }
    @chmod($destination, 0644);
    return 'uploads/courses/' . $name;
}
function tc_remove_own_thumbnail(?string $path): void {
    // Only generated flat filenames from our own upload directory are deletable.
    if (!is_string($path) || !preg_match('~^uploads/courses/[a-f0-9]{32}\.(?:jpg|png|webp)$~', $path)) return;
    $full = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'courses' . DIRECTORY_SEPARATOR . basename($path);
    if (is_file($full)) @unlink($full);
}
function tc_thumb_url(?string $path): ?string {
    if (!is_string($path) || !preg_match('~^uploads/courses/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~', $path)) return null;
    return app_url($path);
}
function tc_query_url(array $fields = []): string {
    return app_url('teacher/courses.php') . ($fields ? '?' . http_build_query($fields) : '');
}
function tc_label(string $level): string {
    return ['beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao'][$level] ?? 'Cơ bản';
}

$errors = [];
$pdo = db();
$form = ['id'=>0, 'title'=>'', 'description'=>'', 'category'=>'', 'level'=>'beginner', 'status'=>'draft', 'thumbnail'=>null];
$editing = false;

// POST requests change state. A teacher can only mutate courses owned by that teacher.
if (is_post()) {
    require_csrf();
    $action = get_post('action');
    $id = positive_int($_POST['course_id'] ?? null);
    $existing = $id > 0 ? tc_owner_course($pdo, $id, $teacherId) : null;
    if (in_array($action, ['update', 'delete', 'toggle'], true) && !$existing) {
        http_response_code(403);
        exit('Bạn không có quyền thao tác với khóa học này.');
    }
    if ($action === 'delete') {
        // Deleting a course cascades to chapters, lessons, exercises, enrollments, progress and submissions.
        $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id AND teacher_id = :teacher_id');
        $stmt->execute(['id'=>$id, 'teacher_id'=>$teacherId]);
        if ($stmt->rowCount() !== 1) { http_response_code(409); exit('Khóa học đã thay đổi.'); }
        tc_remove_own_thumbnail($existing['thumbnail']);
        tc_flash('success', 'Đã xóa khóa học và các dữ liệu liên quan.');
        tc_back();
    }
    if ($action === 'toggle') {
        $next = $existing['status'] === 'published' ? 'draft' : 'published';
        $stmt = $pdo->prepare('UPDATE courses SET status=:status WHERE id=:id AND teacher_id=:teacher_id');
        $stmt->execute(['status'=>$next, 'id'=>$id, 'teacher_id'=>$teacherId]);
        tc_flash('success', $next === 'published' ? 'Đã công khai khóa học.' : 'Đã chuyển khóa học về bản nháp.');
        tc_back();
    }
    if ($action === 'create' || $action === 'update') {
        $editing = $action === 'update';
        $form = [
            'id' => $editing ? $id : 0,
            'title' => get_post('title'),
            'description' => get_post('description'),
            'category' => get_post('category'),
            'level' => get_post('level', 'beginner'),
            'status' => get_post('status', 'draft'),
            'thumbnail' => $existing['thumbnail'] ?? null
        ];
        $length = fn(string $v): int => function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
        if ($form['title'] === '' || $length($form['title']) > 255) $errors[] = 'Tên khóa học phải từ 1 đến 255 ký tự.';
        if ($length($form['category']) > 100) $errors[] = 'Danh mục tối đa 100 ký tự.';
        if ($length($form['description']) > 20000) $errors[] = 'Mô tả không được vượt quá 20.000 ký tự.';
        if (!in_array($form['level'], ['beginner','intermediate','advanced'], true)) $errors[] = 'Trình độ không hợp lệ.';
        if (!in_array($form['status'], ['draft','published'], true)) $errors[] = 'Trạng thái không hợp lệ.';
        $photo = $_FILES['thumbnail'] ?? null;
        $hasPhoto = is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($photo !== null && !is_array($photo)) $errors[] = 'Dữ liệu ảnh không hợp lệ.';
        if (is_array($photo) && is_array($photo['error'] ?? null)) $errors[] = 'Chỉ tải lên một ảnh.';
        if (!$errors) {
            $newThumbnail = null;
            try {
                if ($hasPhoto) $newThumbnail = tc_upload_thumbnail($photo);
                $thumbnail = $newThumbnail ?? ($existing['thumbnail'] ?? null);
                if ($editing) {
                    $stmt = $pdo->prepare('UPDATE courses SET title=:title, description=:description, category=:category, thumbnail=:thumbnail, level=:level, status=:status WHERE id=:id AND teacher_id=:teacher_id');
                    $stmt->execute([
                        'title'=>$form['title'], 'description'=>$form['description'], 'category'=>$form['category'],
                        'thumbnail'=>$thumbnail, 'level'=>$form['level'], 'status'=>$form['status'],
                        'id'=>$id, 'teacher_id'=>$teacherId
                    ]);
                    if ($newThumbnail) tc_remove_own_thumbnail($existing['thumbnail']);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO courses (teacher_id, title, description, category, thumbnail, level, status) VALUES (:teacher_id, :title, :description, :category, :thumbnail, :level, :status)');
                    $stmt->execute([
                        'teacher_id'=>$teacherId, 'title'=>$form['title'], 'description'=>$form['description'],
                        'category'=>$form['category'], 'thumbnail'=>$thumbnail,
                        'level'=>$form['level'], 'status'=>$form['status']
                    ]);
                }
                tc_flash('success', $editing ? 'Đã cập nhật khóa học.' : 'Đã tạo khóa học mới.');
                tc_back();
            } catch (Throwable $ex) {
                if ($newThumbnail) tc_remove_own_thumbnail($newThumbnail);
                error_log('[BrightFuture teacher/courses] '.$ex->getMessage());
                $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Không thể lưu khóa học. Vui lòng thử lại.';
            }
        }
    } else {
        http_response_code(400);
        exit('Yêu cầu không hợp lệ.');
    }
} elseif (get_query('mode') === 'edit') {
    $id = positive_int($_GET['id'] ?? null);
    $course = tc_owner_course($pdo, $id, $teacherId);
    if (!$course) { http_response_code(404); exit('Không tìm thấy khóa học của bạn.'); }
    $form = $course;
    $editing = true;
}

$search = get_query('q');
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
$status = get_query('status', 'all');
if (!in_array($status, ['all','draft','published'], true)) $status = 'all';
$page = positive_int($_GET['page'] ?? null, 1);
$rows = [];
$stats = ['total'=>0,'draft'=>0,'published'=>0];
$pagination = paginate(0, $page, TEACHER_COURSES_PER_PAGE);
$listError = false;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status='draft'),0) AS draft, COALESCE(SUM(status='published'),0) AS published FROM courses WHERE teacher_id=:teacher_id");
    $stmt->execute(['teacher_id'=>$teacherId]);
    $stats = $stmt->fetch() ?: $stats;

    $conditions = ['c.teacher_id = :teacher_id'];
    $params = ['teacher_id'=>$teacherId];
    if ($status !== 'all') { $conditions[] = 'c.status = :status'; $params['status'] = $status; }
    if ($search !== '') {
        $escaped = str_replace(['!','%','_'], ['!!','!%','!_'], $search);
        $conditions[] = "(c.title LIKE :title ESCAPE '!' OR c.category LIKE :category ESCAPE '!')";
        $params['title'] = '%'.$escaped.'%';
        $params['category'] = '%'.$escaped.'%';
    }
    $where = implode(' AND ', $conditions);
    $count = $pdo->prepare('SELECT COUNT(*) FROM courses c WHERE '.$where);
    foreach ($params as $k=>$v) $count->bindValue(':'.$k, $v, $k==='teacher_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    $count->execute();
    $pagination = paginate((int)$count->fetchColumn(), $page, TEACHER_COURSES_PER_PAGE);
    $sql = "SELECT c.id, c.title, c.description, c.category, c.thumbnail, c.level, c.status, c.created_at, c.updated_at,
        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status IN ('active','completed')) AS student_count,
        (SELECT COUNT(*) FROM course_chapters cc WHERE cc.course_id=c.id) AS chapter_count,
        (SELECT COUNT(*) FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id=c.id) AS lesson_count
        FROM courses c WHERE $where ORDER BY c.updated_at DESC,c.id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue(':'.$k, $v, $k==='teacher_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[BrightFuture teacher courses list] '.$ex->getMessage());
    $listError = true;
}
$flash = get_flash();
$showEditor = $editing || get_query('mode') === 'create' || ($errors !== []);
$avatar = function_exists('mb_substr') ? mb_substr($user['full_name'], 0, 1, 'UTF-8') : substr($user['full_name'], 0, 1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý khóa học | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--secondary:#7c3aed;--bg:#f6f8fc;--text:#172033;--muted:#64748b;--line:#e7ebf2;--side:255px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Segoe UI',Arial,sans-serif}a{text-decoration:none;color:inherit}button,input,textarea,select{font:inherit}button{cursor:pointer}.layout{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:var(--side);background:white;border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:20;transition:transform .25s}.logo{height:90px;display:flex;align-items:center;padding:0 24px;gap:10px;color:var(--primary);font-size:21px;font-weight:800}.mark{display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:white;font-size:14px}.nav{padding:12px 15px;flex:1;overflow:auto}.nav-label{padding:15px 13px 9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:1px;font-size:11px}.nav a{display:flex;align-items:center;gap:12px;padding:13px 15px;border-radius:11px;color:var(--muted);font-size:14px;margin-bottom:4px}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--primary)}.nav a.active{font-weight:700}.nav a span{font-size:18px}.sidebar-bottom{border-top:1px solid var(--line);padding:17px}.user-mini{display:flex;gap:10px;align-items:center;margin-bottom:15px}.avatar{display:grid;place-items:center;flex:none;width:42px;height:42px;border-radius:13px;background:#ede9fe;color:#6d28d9;font-weight:800}.user-mini strong{font-size:13px;display:block;max-width:157px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}.user-mini small{color:var(--muted)}.logout{width:100%;border:none;border-radius:10px;padding:12px;background:#fff1f2;color:#be123c;font-weight:700}.main{margin-left:var(--side);min-width:0}.topbar{height:83px;padding:0 36px;border-bottom:1px solid var(--line);background:white;display:flex;align-items:center;justify-content:space-between}.topbar strong{font-size:15px}.topbar .right{display:flex;align-items:center;gap:12px;font-size:13px}.content{max-width:1480px;margin:auto;padding:35px 36px 70px}.breadcrumb{font-size:13px;color:var(--muted);margin-bottom:24px}.breadcrumb a:hover{color:var(--primary)}.heading{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:25px}.heading h1{font-size:29px;margin:0 0 8px;letter-spacing:-.7px}.heading p{color:var(--muted);margin:0;font-size:14px}.btn-primary{border:none;background:var(--primary);color:white;border-radius:11px;padding:12px 17px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}.btn-primary:hover{background:#4338ca}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:26px}.stat,.panel,.course{border:1px solid var(--line);background:white;border-radius:17px}.stat{padding:23px;display:flex;justify-content:space-between;align-items:center}.stat span{color:var(--muted);font-size:13px}.stat strong{display:block;font-size:28px;margin-top:10px}.stat b{font-size:22px;color:var(--primary);background:#eef2ff;padding:12px;border-radius:12px}.panel{padding:21px;margin-bottom:25px}.filter{display:flex;gap:12px;flex-wrap:wrap}.filter input{flex:1;min-width:180px}.filter input,.filter select,.field input,.field textarea,.field select{border:1.5px solid #dde3ef;background:#fff;border-radius:10px;padding:12px;outline:none;max-width:100%}.filter select{min-width:155px}.filter input:focus,.filter select:focus,.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px #eef2ff}.result{margin-top:14px;font-size:13px;color:var(--muted)}.result a{color:var(--primary);font-weight:700;margin-left:12px}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:19px}.course{overflow:hidden;display:flex;flex-direction:column}.thumb{height:148px;background:linear-gradient(130deg,#e0e7ff,#ddd6fe);display:grid;place-items:center;color:var(--primary);font-size:36px;font-weight:800;position:relative}.thumb img{width:100%;height:100%;object-fit:cover}.status{position:absolute;top:12px;right:12px;background:#fff7ed;color:#9a3412;border-radius:30px;padding:7px 11px;font-size:11px;font-weight:700}.status.published{background:#dcfce7;color:#166534}.course-body{padding:19px;display:flex;flex-direction:column;flex:1}.tag{display:inline-block;background:#eef2ff;color:var(--primary);font-weight:700;font-size:11px;padding:6px 9px;border-radius:7px;align-self:flex-start}.course h3{font-size:17px;line-height:1.5;margin:13px 0 8px}.desc{font-size:13px;line-height:1.65;color:var(--muted);min-height:42px;margin:0 0 16px;overflow-wrap:anywhere}.numbers{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-top:auto;border-top:1px solid var(--line);padding-top:14px}.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:17px}.actions .button{border:1px solid var(--line);background:white;color:var(--primary);border-radius:9px;padding:10px 11px;font-size:12px;font-weight:700;cursor:pointer}.actions .button:hover{background:#eef2ff}.actions .danger{color:#be123c}.actions .danger:hover{background:#fff1f2}.actions form{display:inline-flex}.alert{padding:14px 17px;border-radius:11px;margin-bottom:20px;font-size:14px}.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.empty{grid-column:1/-1;text-align:center;padding:48px 18px;border:1px dashed #cbd5e1;border-radius:17px;background:white}.empty p{color:var(--muted)}.pagination{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin-top:30px}.page-link{display:grid;place-items:center;min-width:40px;height:40px;border-radius:9px;border:1px solid var(--line);background:white;font-size:13px}.page-link.active{background:var(--primary);color:#fff;border-color:var(--primary)}.editor{margin-bottom:25px}.editor h2{font-size:21px;margin:0 0 16px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:17px}.field{display:flex;flex-direction:column;gap:8px;font-size:13px;font-weight:600}.field.full{grid-column:1/-1}.field input,.field textarea,.field select{width:100%;font-weight:400}.field textarea{min-height:120px;resize:vertical}.hint{font-size:12px;color:var(--muted);font-weight:400}.form-actions{margin-top:20px;display:flex;gap:12px;align-items:center}.cancel{font-size:13px;color:var(--muted);font-weight:700}.mobile-menu{display:none;border:0;background:none;font-size:23px}.overlay{display:none}.preview-thumb{max-width:190px;max-height:110px;object-fit:cover;border-radius:9px;margin-top:6px}
@media(max-width:1200px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.overlay.show{display:block;position:fixed;inset:0;background:#0f172a70;z-index:19}.main{margin-left:0}.mobile-menu{display:inline-block}.topbar{height:70px;padding:0 22px}.content{padding:26px 22px}}@media(max-width:650px){.stats,.cards,.fields{grid-template-columns:1fr}.field.full{grid-column:auto}.content{padding:20px 15px 40px}.heading{align-items:flex-start;flex-direction:column}.heading h1{font-size:25px}.topbar .right{display:none}.filter{flex-direction:column}.filter input,.filter select{width:100%}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="layout">
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar">
<a class="logo" href="<?= e(app_url()) ?>"><span class="mark">&lt;/&gt;</span>BrightFuture</a>
<nav class="nav" aria-label="Điều hướng giáo viên">
<div class="nav-label">Giảng dạy</div>
<a href="<?= e(app_url('teacher/dashboard.php')) ?>"><span>▦</span>Tổng quan</a>
<a class="active" aria-current="page" href="<?= e(app_url('teacher/courses.php')) ?>"><span>📚</span>Khóa học của tôi</a>
<a href="<?= e(app_url('teacher/lessons.php')) ?>"><span>📖</span>Bài giảng</a>
<a href="<?= e(app_url('teacher/exercises.php')) ?>"><span>💻</span>Bài tập</a>
<a href="<?= e(app_url('teacher/submissions.php')) ?>"><span>✓</span>Chấm bài</a>
<div class="nav-label">Cá nhân</div>
<a href="<?= e(app_url('profile.php')) ?>"><span>☺</span>Hồ sơ</a>
<a href="<?= e(app_url()) ?>"><span>⌂</span>Trang chủ</a>
</nav>
<div class="sidebar-bottom"><div class="user-mini"><div class="avatar"><?= e($avatar) ?></div><div><strong><?= e($user['full_name']) ?></strong><small>Giáo viên</small></div></div><form method="POST" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form></div>
</aside>
<div class="main">
<header class="topbar"><div style="display:flex;align-items:center;gap:15px"><button class="mobile-menu" type="button" id="menuBtn" aria-label="Mở menu" aria-controls="sidebar" aria-expanded="false">☰</button><strong>Không gian giảng dạy</strong></div><div class="right"><?= e($user['full_name']) ?><span class="avatar" style="width:37px;height:37px;border-radius:50%"><?= e($avatar) ?></span></div></header>
<main class="content">
<div class="breadcrumb"><a href="<?= e(app_url('teacher/dashboard.php')) ?>">Tổng quan</a> / Quản lý khóa học</div>
<div class="heading"><div><h1>Khóa học của tôi</h1><p>Tạo, chỉnh sửa và công khai khóa học dành cho học viên.</p></div><a class="btn-primary" href="<?= e(tc_query_url(['mode'=>'create'])) ?>">+ Tạo khóa học</a></div>
<?php if ($flash && isset($flash['message'])): ?><div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><strong>Vui lòng kiểm tra lại:</strong><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($showEditor): ?>
<section class="panel editor" id="editor"><h2><?= $editing ? 'Chỉnh sửa khóa học' : 'Tạo khóa học mới' ?></h2>
<form method="POST" enctype="multipart/form-data" action="<?= e(app_url('teacher/courses.php')) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"><input type="hidden" name="course_id" value="<?= (int)$form['id'] ?>">
<div class="fields">
<label class="field full">Tên khóa học *<input name="title" maxlength="255" required value="<?= e($form['title']) ?>" placeholder="Ví dụ: Lập trình Python cơ bản"></label>
<label class="field">Danh mục<input name="category" maxlength="100" value="<?= e($form['category']) ?>" placeholder="Python, Web, AI..."></label>
<label class="field">Trình độ<select name="level"><?php foreach (['beginner'=>'Cơ bản','intermediate'=>'Trung cấp','advanced'=>'Nâng cao'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $form['level'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label class="field full">Mô tả khóa học<textarea name="description" maxlength="20000" placeholder="Giới thiệu nội dung, mục tiêu và đối tượng học viên..."><?= e($form['description']) ?></textarea></label>
<label class="field">Trạng thái<select name="status"><option value="draft" <?= $form['status']==='draft'?'selected':'' ?>>Bản nháp</option><option value="published" <?= $form['status']==='published'?'selected':'' ?>>Công khai</option></select><span class="hint">Chỉ công khai khi nội dung đã sẵn sàng.</span></label>
<label class="field">Ảnh đại diện<input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp"><span class="hint">JPG/PNG/WebP, tối đa 3 MB; kích thước không quá 6000 × 6000 px.</span><?php if (tc_thumb_url($form['thumbnail'])): ?><img class="preview-thumb" alt="Ảnh hiện tại" src="<?= e(tc_thumb_url($form['thumbnail'])) ?>"><?php endif; ?></label>
</div>
<div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Lưu thay đổi' : 'Tạo khóa học' ?></button><a class="cancel" href="<?= e(tc_query_url()) ?>">Hủy</a></div>
</form></section>
<?php endif; ?>
<section class="stats"><div class="stat"><div><span>Tổng khóa học</span><strong><?= (int)$stats['total'] ?></strong></div><b>▤</b></div><div class="stat"><div><span>Đã công khai</span><strong><?= (int)$stats['published'] ?></strong></div><b>✓</b></div><div class="stat"><div><span>Bản nháp</span><strong><?= (int)$stats['draft'] ?></strong></div><b>✎</b></div></section>
<section class="panel"><form class="filter" method="GET" action="<?= e(tc_query_url()) ?>"><input name="q" type="search" maxlength="100" placeholder="Tìm tên khóa học hoặc danh mục..." value="<?= e($search) ?>" aria-label="Tìm kiếm"><select name="status" aria-label="Lọc trạng thái"><option value="all" <?= $status==='all'?'selected':'' ?>>Tất cả trạng thái</option><option value="published" <?= $status==='published'?'selected':'' ?>>Đã công khai</option><option value="draft" <?= $status==='draft'?'selected':'' ?>>Bản nháp</option></select><button type="submit" class="btn-primary">Tìm kiếm</button></form><div class="result">Tìm thấy <?= (int)$pagination['total_items'] ?> khóa học<?php if ($search!=='' || $status!=='all'): ?><a href="<?= e(tc_query_url()) ?>">Xóa bộ lọc</a><?php endif; ?></div></section>
<?php if ($listError): ?><div class="alert alert-error">Không thể tải danh sách khóa học. Hãy kiểm tra database và các bảng của giai đoạn 2, 3.</div><?php endif; ?>
<section class="cards" aria-label="Danh sách khóa học">
<?php if (!$rows): ?><div class="empty"><h3><?= $search !== '' || $status !== 'all' ? 'Không tìm thấy khóa học phù hợp' : 'Bạn chưa có khóa học nào' ?></h3><p><?= $search !== '' || $status !== 'all' ? 'Hãy thử bộ lọc hoặc từ khóa khác.' : 'Bấm “Tạo khóa học” để bắt đầu thiết kế nội dung học tập.' ?></p><a class="btn-primary" href="<?= e(tc_query_url(['mode'=>'create'])) ?>">+ Tạo khóa học</a></div><?php endif; ?>
<?php foreach ($rows as $course): ?>
<?php $courseId=(int)$course['id'];$image=tc_thumb_url($course['thumbnail']); ?>
<article class="course"><div class="thumb"><?php if ($image): ?><img src="<?= e($image) ?>" alt="Ảnh khóa học <?= e($course['title']) ?>" loading="lazy"><?php else: ?>&lt;/&gt;<?php endif; ?><span class="status <?= $course['status']==='published'?'published':'' ?>"><?= $course['status']==='published'?'Công khai':'Bản nháp' ?></span></div>
<div class="course-body"><span class="tag"><?= e($course['category'] ?: tc_label($course['level'])) ?></span><h3><?= e($course['title']) ?></h3><p class="desc"><?= e(truncate_text((string)($course['description'] ?? ''),105)) ?></p><div class="numbers"><span>👥 <?= (int)$course['student_count'] ?> học viên</span><span>📚 <?= (int)$course['chapter_count'] ?> chương</span><span>📖 <?= (int)$course['lesson_count'] ?> bài học</span></div>
<div class="actions"><a class="button" href="<?= e(tc_query_url(['mode'=>'edit','id'=>$courseId])) ?>">Chỉnh sửa</a>
<a class="button" href="<?= e(app_url('teacher/lessons.php?course_id='.$courseId)) ?>">Bài giảng</a>
<?php if($course['level']!=='beginner'): ?><a class="button" href="<?= e(app_url('teacher/learning-paths.php?course_id='.$courseId)) ?>">Điều kiện mở khóa</a><?php endif; ?>
<form method="POST" action="<?= e(tc_query_url()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= $courseId ?>"><button class="button" type="submit"><?= $course['status']==='published'?'Ẩn khóa học':'Công khai' ?></button></form>
<form method="POST" action="<?= e(tc_query_url()) ?>" onsubmit="return confirm('Xóa vĩnh viễn khóa học này cùng chương, bài giảng, bài tập, bài nộp và dữ liệu đăng ký? Thao tác không thể hoàn tác.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="course_id" value="<?= $courseId ?>"><button type="submit" class="button danger">Xóa</button></form></div></div></article>
<?php endforeach; ?>
</section>
<?php if ($pagination['total_pages'] > 1): ?>
<nav class="pagination" aria-label="Phân trang">
<?php $current=(int)$pagination['current_page'];$total=(int)$pagination['total_pages'];$start=max(1,min($current-2,$total-4));$end=min($total,$start+4); ?>
<?php if ($current>1): ?><a class="page-link" href="<?= e(tc_query_url(['q'=>$search,'status'=>$status,'page'=>$current-1])) ?>">←</a><?php endif; ?>
<?php for ($i=$start;$i<=$end;$i++): ?><a class="page-link <?= $i===$current?'active':'' ?>" <?= $i===$current?'aria-current="page"':'' ?> href="<?= e(tc_query_url(['q'=>$search,'status'=>$status,'page'=>$i])) ?>"><?= $i ?></a><?php endfor; ?>
<?php if ($current<$total): ?><a class="page-link" href="<?= e(tc_query_url(['q'=>$search,'status'=>$status,'page'=>$current+1])) ?>">→</a><?php endif; ?>
</nav>
<?php endif; ?>
</main></div></div>
<script>
const menu=document.getElementById('menuBtn'),sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay');
function toggleMenu(open){sidebar.classList.toggle('open',open);overlay.classList.toggle('show',open);menu.setAttribute('aria-expanded',String(open))}
menu.addEventListener('click',()=>toggleMenu(!sidebar.classList.contains('open')));
overlay.addEventListener('click',()=>toggleMenu(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')toggleMenu(false)});
window.addEventListener('resize',()=>{if(window.innerWidth>900)toggleMenu(false)});
</script>
<script>window.BF_LEGACY={role:'teacher',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body></html>
