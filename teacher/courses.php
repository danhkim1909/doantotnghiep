<?php
/** BrightFuture - Teacher course management. PHP 8.0+, MySQL 8.0+ */


require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';
$user = require_role(ROLE_TEACHER);
$teacherId = (int)$user['id'];
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

const TEACHER_THUMB_MAX_BYTES = 3 * 1024 * 1024;
const TEACHER_COURSES_PER_PAGE = 9;

function tc_flash(string $type, string $message): void { set_flash($type, $message); }
function tc_back(): never { redirect('teacher/courses.php'); }
function tc_owner_course(PDO $pdo, int $courseId, int $teacherId): ?array {
    $stmt = $pdo->prepare('SELECT id, title, description, category, thumbnail, level, status FROM courses WHERE id = :id AND teacher_id = :teacher_id AND deleted_at IS NULL LIMIT 1');
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
        // Never cascade-delete lessons, submissions or a learner's progress from course management.
        if ($existing['status'] !== 'draft') {
            tc_flash('error', 'Chỉ được xóa khóa học ở trạng thái Bản nháp.');
            tc_back();
        }
        try {
            // The course must be empty: otherwise cascading FK deletes could destroy graded answers.
            $checkChapters = $pdo->prepare('SELECT COUNT(*) FROM course_chapters WHERE course_id = :course_id');
            $checkChapters->execute(['course_id' => $id]);
            if ((int)$checkChapters->fetchColumn() > 0) {
                tc_flash('error', 'Khóa học còn chương hoặc bài giảng. Hãy xử lý các nội dung bản nháp trước; không thể xóa cả khóa học để tránh mất dữ liệu học tập.');
                tc_back();
            }
            $checkLearners = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = :course_id');
            $checkLearners->execute(['course_id' => $id]);
            if ((int)$checkLearners->fetchColumn() > 0) {
                tc_flash('error', 'Khóa học đã có học viên đăng ký. Không thể xóa vì cần bảo toàn dữ liệu học tập.');
                tc_back();
            }
            $stmt = $pdo->prepare("UPDATE courses SET deleted_at = NOW() WHERE id = :id AND teacher_id = :teacher_id AND status = 'draft' AND deleted_at IS NULL");
            $stmt->execute(['id' => $id, 'teacher_id' => $teacherId]);
            if ($stmt->rowCount() !== 1) {
                tc_flash('error', 'Khóa học đã thay đổi trạng thái hoặc không còn tồn tại.');
                tc_back();
            }
            tc_remove_own_thumbnail($existing['thumbnail']);
            tc_flash('success', 'Đã xóa khóa học bản nháp chưa có dữ liệu học tập.');
        } catch (PDOException $e) {
            error_log('[BrightFuture] Teacher course delete: ' . $e->getMessage());
            $reason = ($e->getCode() === '23000')
                ? 'Khóa học vẫn còn dữ liệu liên quan trong database. Hệ thống đã chặn xóa để bảo vệ bài nộp, câu hỏi và tiến độ học tập.'
                : 'Không thể xóa khóa học do lỗi truy vấn. Vui lòng kiểm tra nhật ký PHP.';
            tc_flash('error', $reason);
        }
        tc_back();
    }
    if ($action === 'toggle') {
        $next = $existing['status'] === 'published' ? 'draft' : 'published';
        if ($next === 'published') {
            $checkLessons = $pdo->prepare('SELECT COUNT(l.id) FROM lessons l INNER JOIN course_chapters ch ON ch.id = l.chapter_id WHERE ch.course_id = :course_id');
            $checkLessons->execute(['course_id' => $id]);
            if ((int)$checkLessons->fetchColumn() === 0) {
                tc_flash('error', 'Khóa học chưa có bài giảng nào. Vui lòng thêm ít nhất 1 bài học trước khi công khai.');
                tc_back();
            }
        }
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
        if ($form['status'] === 'published') {
            if (!$editing) {
                $errors[] = 'Khóa học mới tạo chưa có bài giảng nào. Vui lòng lưu dưới dạng Bản nháp và thêm bài học trước khi công khai.';
            } else {
                $checkLessons = $pdo->prepare('SELECT COUNT(l.id) FROM lessons l INNER JOIN course_chapters ch ON ch.id = l.chapter_id WHERE ch.course_id = :course_id');
                $checkLessons->execute(['course_id' => $id]);
                if ((int)$checkLessons->fetchColumn() === 0) {
                    $errors[] = 'Khóa học chưa có bài giảng nào. Vui lòng thêm ít nhất 1 bài học trước khi công khai.';
                }
            }
        }
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
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status='draft'),0) AS draft, COALESCE(SUM(status='published'),0) AS published FROM courses WHERE teacher_id=:teacher_id AND deleted_at IS NULL");
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
    $count = $pdo->prepare('SELECT COUNT(*) FROM courses c WHERE c.deleted_at IS NULL AND '.$where);
    foreach ($params as $k=>$v) $count->bindValue(':'.$k, $v, $k==='teacher_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    $count->execute();
    $pagination = paginate((int)$count->fetchColumn(), $page, TEACHER_COURSES_PER_PAGE);
    $sql = "SELECT c.id, c.title, c.description, c.category, c.thumbnail, c.level, c.status, c.created_at, c.updated_at,
        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status IN ('active','completed')) AS student_count,
        (SELECT COUNT(*) FROM course_chapters cc WHERE cc.course_id=c.id) AS chapter_count,
        (SELECT COUNT(*) FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id=c.id) AS lesson_count
        FROM courses c WHERE c.deleted_at IS NULL AND $where ORDER BY c.updated_at DESC,c.id DESC LIMIT :limit OFFSET :offset";
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
$pageTitle = 'Quản lý khóa học';
$pageDescription = 'Tạo, chỉnh sửa và công khai khóa học dành cho học viên trên BrightFuture';
$activeMenu = 'courses';
$extraCss = ['css/bf-teacher-courses.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($user, 'courses') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content tc-content">
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
<section class="stats">
    <div class="stat"><div><span>Tổng khóa học</span><strong><?= (int)$stats['total'] ?></strong></div><span class="stat-icon" aria-hidden="true"><?= bf_icon('courses') ?></span></div>
    <div class="stat"><div><span>Đã công khai</span><strong><?= (int)$stats['published'] ?></strong></div><span class="stat-icon" style="background:#dcfce7;color:#166534" aria-hidden="true"><?= bf_icon('check') ?></span></div>
    <div class="stat"><div><span>Bản nháp</span><strong><?= (int)$stats['draft'] ?></strong></div><span class="stat-icon" style="background:#fef3c7;color:#b45309" aria-hidden="true"><?= bf_icon('edit') ?></span></div>
</section>
<section class="panel"><form class="filter" method="GET" action="<?= e(tc_query_url()) ?>"><input name="q" type="search" maxlength="100" placeholder="Tìm tên khóa học hoặc danh mục..." value="<?= e($search) ?>" aria-label="Tìm kiếm"><select name="status" aria-label="Lọc trạng thái"><option value="all" <?= $status==='all'?'selected':'' ?>>Tất cả trạng thái</option><option value="published" <?= $status==='published'?'selected':'' ?>>Đã công khai</option><option value="draft" <?= $status==='draft'?'selected':'' ?>>Bản nháp</option></select><button type="submit" class="btn-primary">Tìm kiếm</button></form><div class="result">Tìm thấy <?= (int)$pagination['total_items'] ?> khóa học<?php if ($search!=='' || $status!=='all'): ?><a href="<?= e(tc_query_url()) ?>">Xóa bộ lọc</a><?php endif; ?></div></section>
<?php if ($listError): ?><div class="alert alert-error">Không thể tải danh sách khóa học. Hãy kiểm tra database và các bảng của giai đoạn 2, 3.</div><?php endif; ?>
<section class="cards" aria-label="Danh sách khóa học">
<?php if (!$rows): ?><div class="empty"><h3><?= $search !== '' || $status !== 'all' ? 'Không tìm thấy khóa học phù hợp' : 'Bạn chưa có khóa học nào' ?></h3><p><?= $search !== '' || $status !== 'all' ? 'Hãy thử bộ lọc hoặc từ khóa khác.' : 'Bấm “Tạo khóa học” để bắt đầu thiết kế nội dung học tập.' ?></p><a class="btn-primary" href="<?= e(tc_query_url(['mode'=>'create'])) ?>">+ Tạo khóa học</a></div><?php endif; ?>
<?php foreach ($rows as $course): ?>
<?php $courseId=(int)$course['id'];$image=tc_thumb_url($course['thumbnail']); ?>
<article class="course"><div class="thumb"><?php if ($image): ?><img src="<?= e($image) ?>" alt="Ảnh khóa học <?= e($course['title']) ?>" loading="lazy"><?php else: ?>&lt;/&gt;<?php endif; ?><span class="status <?= $course['status']==='published'?'published':'' ?>"><?= $course['status']==='published'?'Công khai':'Bản nháp' ?></span></div>
<div class="course-body"><span class="tag"><?= e($course['category'] ?: tc_label($course['level'])) ?></span><h3><?= e($course['title']) ?></h3><p class="desc"><?= e(truncate_text((string)($course['description'] ?? ''),105)) ?></p><div class="numbers">
    <span style="display:inline-flex;align-items:center;gap:4px"><?= bf_icon('users') ?> <?= (int)$course['student_count'] ?> học viên</span>
    <span style="display:inline-flex;align-items:center;gap:4px"><?= bf_icon('courses') ?> <?= (int)$course['chapter_count'] ?> chương</span>
    <span style="display:inline-flex;align-items:center;gap:4px"><?= bf_icon('lesson') ?> <?= (int)$course['lesson_count'] ?> bài học</span>
</div>
<div class="actions"><a class="button" href="<?= e(tc_query_url(['mode'=>'edit','id'=>$courseId])) ?>">Chỉnh sửa</a>
<a class="button" href="<?= e(app_url('teacher/lessons.php?course_id='.$courseId)) ?>">Bài giảng</a>
<?php if($course['level']!=='beginner'): ?><a class="button" href="<?= e(app_url('teacher/learning-paths.php?course_id='.$courseId)) ?>">Điều kiện mở khóa</a><?php endif; ?>
<form method="POST" action="<?= e(tc_query_url()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= $courseId ?>"><button class="button" type="submit"><?= $course['status']==='published'?'Ẩn khóa học':'Công khai' ?></button></form>
<?php if ($course['status'] === 'draft'): ?><form method="POST" action="<?= e(tc_query_url()) ?>" onsubmit="return confirm('Chỉ xóa khóa học bản nháp chưa có chương và học viên. Nếu còn dữ liệu liên quan, hệ thống sẽ từ chối xóa. Tiếp tục?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="course_id" value="<?= $courseId ?>"><button type="submit" class="button danger">Xóa bản nháp</button></form><?php endif; ?></div></div></article>
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
</main>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
