<?php
/** BrightFuture - Quan tri toan bo khoa hoc. PHP 8+, MySQL 8+. */

require_once __DIR__ . '/../includes/permissions.php';
$admin = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, private, no-cache, must-revalidate');
header('Pragma: no-cache');
$pdo = db();

function ac_url(array $params = []): string {
    return app_url('admin/courses.php') . ($params ? '?' . http_build_query($params) : '');
}
function ac_label(string $status): string {
    return $status === 'published' ? 'Công khai' : 'Bản nháp';
}
function ac_level(string $level): string {
    return match ($level) {'beginner'=>'Cơ bản','intermediate'=>'Trung cấp','advanced'=>'Nâng cao', default=>'Không xác định'};
}
function ac_thumbnail(?string $path): ?string {
    if (!$path) return null;
    $path = str_replace('\\','/',$path);
    if (str_contains($path, '..') || str_starts_with($path,'/') || preg_match('/[\x00-\x1F\x7F]/', $path)) return null;
    if (!preg_match('~^(?:uploads/courses/)?[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)$~i', $path)) return null;
    if (!str_starts_with($path,'uploads/courses/')) $path = 'uploads/courses/' . $path;
    return app_url($path);
}
function ac_deps(PDO $pdo, int $courseId): array {
    $sql = [
        'enrollments' => 'SELECT COUNT(*) FROM enrollments WHERE course_id = ?',
        'chapters' => 'SELECT COUNT(*) FROM course_chapters WHERE course_id = ?',
        'lessons' => 'SELECT COUNT(*) FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id = ?',
        'exercises' => 'SELECT COUNT(*) FROM exercises ex JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id = ?',
        'submissions' => 'SELECT COUNT(*) FROM exercise_submissions es JOIN exercises ex ON ex.id=es.exercise_id JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id = ?',
        'progress' => 'SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id = ?',
    ];
    $counts=[];
    foreach ($sql as $key=>$query) {
        $stmt=$pdo->prepare($query);
        $stmt->execute([$courseId]);
        $counts[$key]=(int)$stmt->fetchColumn();
    }
    return $counts;
}
$errors=[];
$filters=[
    'q'=>trim(get_query('q')),
    'status'=>get_query('status','all'),
    'teacher'=>positive_int($_GET['teacher'] ?? null),
    'category'=>trim(get_query('category')),
];
if (!in_array($filters['status'],['all','draft','published'],true)) $filters['status']='all';
$filters['q']=function_exists('mb_substr') ? mb_substr($filters['q'],0,100,'UTF-8') : substr($filters['q'],0,100);
$filters['category']=function_exists('mb_substr') ? mb_substr($filters['category'],0,100,'UTF-8') : substr($filters['category'],0,100);
$page=positive_int($_GET['page'] ?? null,1);

// POST actions: all mutations require CSRF, ownership is unrestricted ONLY for Admin.
if (is_post()) {
    require_csrf();
    $action=get_post('action');
    $id=positive_int($_POST['course_id'] ?? null);
    if ($id < 1 || !in_array($action,['update','toggle','delete'],true)) {
        $errors[]='Thao tác hoặc mã khóa học không hợp lệ.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT * FROM courses WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $course=$stmt->fetch();
            if (!$course) throw new DomainException('Khóa học không tồn tại hoặc đã bị xóa.');

            if ($action==='toggle') {
                $next=$course['status']==='published' ? 'draft' : 'published';
                $stmt=$pdo->prepare('UPDATE courses SET status = ? WHERE id = ?');
                $stmt->execute([$next,$id]);
                $message=$next==='published' ? 'Đã công khai khóa học.' : 'Đã chuyển khóa học về bản nháp.';
            } elseif ($action==='update') {
                $titleInput=$_POST['title'] ?? null;
                $descInput=$_POST['description'] ?? null;
                $categoryInput=$_POST['category'] ?? null;
                $levelInput=$_POST['level'] ?? null;
                $teacherInput=positive_int($_POST['teacher_id'] ?? null);
                $statusInput=$_POST['status'] ?? null;
                if (!is_string($titleInput) || !is_string($descInput) || !is_string($categoryInput) || !is_string($levelInput) || !is_string($statusInput)) {
                    throw new DomainException('Dữ liệu cập nhật không hợp lệ.');
                }
                $title=trim($titleInput);
                $description=trim($descInput);
                $category=trim($categoryInput);
                $len=static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s,'UTF-8') : strlen($s);
                if ($title==='' || $len($title)>255) throw new DomainException('Tên khóa học phải có từ 1 đến 255 ký tự.');
                if ($len($category)>100) throw new DomainException('Danh mục tối đa 100 ký tự.');
                if (strlen($description)>100000) throw new DomainException('Mô tả quá dài.');
                if (!in_array($levelInput,['beginner','intermediate','advanced'],true)) throw new DomainException('Trình độ không hợp lệ.');
                if (!in_array($statusInput,['draft','published'],true)) throw new DomainException('Trạng thái không hợp lệ.');
                $stmt=$pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
                $stmt->execute([$teacherInput]);
                if (!$stmt->fetchColumn()) throw new DomainException('Giáo viên được chọn không tồn tại hoặc không có vai trò Teacher.');
                $stmt=$pdo->prepare('UPDATE courses SET teacher_id=?, title=?, description=?, category=?, level=?, status=? WHERE id=?');
                $stmt->execute([$teacherInput,$title,$description,$category,$levelInput,$statusInput,$id]);
                $message='Đã cập nhật khóa học thành công.';
            } else {
                // Intentionally do not cascade-delete classroom data.
                $deps=ac_deps($pdo,$id);
                if (array_sum($deps)>0) {
                    throw new DomainException('Không thể xóa khóa học đã có chương, bài giảng, đăng ký học, bài tập hoặc dữ liệu học tập. Hãy lưu trữ/ẩn khóa học thay vì xóa.');
                }
                $stmt=$pdo->prepare('DELETE FROM courses WHERE id = ?');
                $stmt->execute([$id]);
                $message='Đã xóa khóa học chưa có dữ liệu liên quan.';
            }
            $pdo->commit();
            set_flash('success',$message);
            redirect('admin/courses.php');
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[]=$ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[BrightFuture Admin Courses POST] '.$ex->getMessage());
            $errors[]='Không thể thực hiện thao tác. Hãy kiểm tra dữ liệu và thử lại.';
        }
    }
}

$teachers=[];$categories=[];$courses=[];$total=0;$summary=['total'=>0,'published'=>0,'draft'=>0,'students'=>0];$pagination=paginate(0,$page,10);
try {
    $teachers=$pdo->query("SELECT id,full_name FROM users WHERE role='teacher' ORDER BY full_name,id")->fetchAll();
    $categories=$pdo->query("SELECT DISTINCT category FROM courses WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $summaryRow=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(status='published'),0) published,COALESCE(SUM(status='draft'),0) draft FROM courses")->fetch();
    if ($summaryRow) $summary=array_merge($summary,array_map('intval',$summaryRow));
    $summary['students']=(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status IN ('active','completed')")->fetchColumn();
    $where=[];$params=[];
    if ($filters['status']!=='all') {$where[]='c.status = :status';$params['status']=$filters['status'];}
    if ($filters['teacher']>0) {$where[]='c.teacher_id = :teacher';$params['teacher']=$filters['teacher'];}
    if ($filters['category']!=='') {$where[]='c.category = :category';$params['category']=$filters['category'];}
    if ($filters['q']!=='') {
        $where[]="(c.title LIKE :q1 ESCAPE '!' OR c.description LIKE :q2 ESCAPE '!' OR u.full_name LIKE :q3 ESCAPE '!')";
        $escaped=str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']);
        $params['q1']=$params['q2']=$params['q3']='%'.$escaped.'%';
    }
    $whereSql=$where ? ' WHERE '.implode(' AND ',$where) : '';
    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM courses c JOIN users u ON u.id=c.teacher_id'.$whereSql);
    foreach ($params as $key=>$value) $countStmt->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $countStmt->execute();$total=(int)$countStmt->fetchColumn();
    $pagination=paginate($total,$page,10);
    $sql="SELECT c.id,c.teacher_id,c.title,c.description,c.category,c.thumbnail,c.level,c.status,c.created_at,c.updated_at,
       u.full_name AS teacher_name,
       (SELECT COUNT(*) FROM enrollments e WHERE e.course_id=c.id AND e.status IN ('active','completed')) AS student_count,
       (SELECT COUNT(*) FROM course_chapters cc WHERE cc.course_id=c.id) AS chapter_count,
       (SELECT COUNT(*) FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id=c.id) AS lesson_count,
       (SELECT COUNT(*) FROM exercises ex JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id WHERE cc.course_id=c.id) AS exercise_count
       FROM courses c JOIN users u ON u.id=c.teacher_id".$whereSql." ORDER BY c.updated_at DESC,c.id DESC LIMIT :lim OFFSET :off";
    $listStmt=$pdo->prepare($sql);
    foreach ($params as $key=>$value) $listStmt->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $listStmt->bindValue(':lim',$pagination['per_page'],PDO::PARAM_INT);
    $listStmt->bindValue(':off',$pagination['offset'],PDO::PARAM_INT);
    $listStmt->execute();$courses=$listStmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[BrightFuture Admin Courses GET] '.$ex->getMessage());
    $errors[]='Không thể tải danh sách hoặc số liệu từ MySQL. Kiểm tra database và các bảng đã import.';
}
$flash=get_flash();
$filterQuery=array_filter(['q'=>$filters['q'],'status'=>$filters['status'],'teacher'=>$filters['teacher'],'category'=>$filters['category']],static fn($v)=>$v!=='' && $v!==0);
?>
<!DOCTYPE html>
<html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quản lý khóa học | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--deep:#312e81;--muted:#64748b;--border:#e5eaf2;--bg:#f6f8fc;--text:#172033;--sidebar:250px}*{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text)}a{text-decoration:none;color:inherit}button,input,select,textarea{font:inherit}button{cursor:pointer}.shell{display:flex;min-height:100vh}.sidebar{width:var(--sidebar);position:fixed;inset:0 auto 0 0;background:white;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:30}.brand{padding:28px 22px;font-size:22px;font-weight:800;color:var(--primary)}.brand span{background:var(--primary);color:white;border-radius:10px;padding:8px;margin-right:9px;font-size:16px}.nav{padding:10px 14px;flex:1;overflow:auto}.nav small{display:block;margin:18px 12px 8px;text-transform:uppercase;color:#94a3b8;font-weight:700;letter-spacing:.1em}.nav a{display:block;padding:13px;border-radius:10px;margin:4px 0;font-size:14px;color:var(--muted)}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--primary);font-weight:700}.sidebar-foot{border-top:1px solid var(--border);padding:16px}.sidebar-foot strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.sidebar-foot p{font-size:12px;color:var(--muted)}.logout{width:100%;padding:11px;border:0;border-radius:9px;background:#fff1f2;color:#be123c;font-weight:700}.main{margin-left:var(--sidebar);width:calc(100% - var(--sidebar));min-width:0}.topbar{background:white;border-bottom:1px solid var(--border);height:75px;padding:0 34px;display:flex;align-items:center;justify-content:space-between}.menu{display:none;border:0;background:none;font-size:23px}.content{max-width:1500px;margin:0 auto;padding:34px}.heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.heading h1{margin:0 0 7px;font-size:28px}.muted{color:var(--muted)}.heading p{margin:0;font-size:14px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:22px}.stat,.panel,.card{background:#fff;border:1px solid var(--border);border-radius:16px}.stat{padding:20px}.stat span{font-size:13px;color:var(--muted)}.stat strong{display:block;font-size:28px;margin-top:9px}.panel{padding:20px;margin-bottom:22px}.filters{display:grid;grid-template-columns:minmax(150px,2fr) repeat(3,minmax(120px,1fr)) auto;gap:10px}.field{width:100%;padding:11px 12px;border-radius:10px;border:1px solid #dce2ec;background:white;min-width:0}.field:focus{outline:2px solid #c7d2fe;border-color:var(--primary)}.btn{border:0;border-radius:9px;padding:10px 14px;background:var(--primary);color:white;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:13px}.btn.secondary{background:#eef2ff;color:var(--primary)}.btn.danger{background:#fff1f2;color:#be123c}.btn.neutral{background:#edf0f6;color:#334155}.btn:disabled{opacity:.55;cursor:not-allowed}.filter-foot{margin-top:14px;font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px}.filter-foot a{color:var(--primary);font-weight:700}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}.card{padding:19px;min-width:0}.card-head{display:flex;gap:15px}.thumb{width:110px;height:88px;flex-shrink:0;background:linear-gradient(130deg,#ddd6fe,#e0e7ff);border-radius:12px;display:grid;place-items:center;color:var(--primary);font-weight:800;font-size:24px;overflow:hidden}.thumb img{width:100%;height:100%;object-fit:cover}.course-info{min-width:0;flex:1}.course-info h3{font-size:16px;margin:5px 0 8px;overflow-wrap:anywhere}.course-info p{font-size:12px;color:var(--muted);margin:4px 0}.pill{display:inline-block;border-radius:30px;font-size:11px;font-weight:700;padding:5px 9px;background:#ffedd5;color:#9a3412}.pill.published{background:#dcfce7;color:#166534}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:17px 0;padding:14px 0;border-block:1px solid var(--border)}.meta div{text-align:center;font-size:12px;color:var(--muted)}.meta strong{display:block;font-size:19px;color:var(--text);margin-bottom:4px}.card-actions{display:flex;flex-wrap:wrap;gap:8px}.card-actions form{display:inline}.empty{text-align:center;background:white;border:1px dashed #cbd5e1;border-radius:14px;padding:48px 20px;grid-column:1/-1;color:var(--muted)}.alert{padding:13px 16px;border-radius:11px;margin-bottom:18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.alert.success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.pagination{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin:26px 0}.pagination a,.pagination span{min-width:38px;padding:10px;border:1px solid var(--border);background:#fff;border-radius:9px;text-align:center;font-size:13px}.pagination .active{background:var(--primary);color:white}.backdrop{display:none;position:fixed;inset:0;background:#0f172a85;z-index:45}.modal{display:none;position:fixed;inset:0;z-index:50;overflow:auto;padding:30px 14px;align-items:flex-start;justify-content:center}.modal.open,.backdrop.open{display:flex}.modal-card{background:white;border-radius:17px;padding:25px;width:100%;max-width:680px;box-shadow:0 22px 70px #11182745}.modal-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.modal-top h2{margin:0;font-size:21px}.modal-top button{border:0;background:transparent;font-size:25px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.form-field{display:flex;flex-direction:column;gap:7px;font-size:13px;font-weight:600}.form-field.wide{grid-column:1/-1}textarea.field{min-height:125px;resize:vertical}.form-actions{margin-top:21px;display:flex;justify-content:flex-end;gap:9px}.hint{font-size:12px;color:var(--muted);line-height:1.6}.overlay{display:none}@media(max-width:1250px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}.filters .btn{min-height:43px}}@media(max-width:850px){.sidebar{transform:translateX(-100%);transition:transform .2s}.sidebar.open{transform:translateX(0)}.overlay.open{display:block;position:fixed;inset:0;background:#0f172a80;z-index:25}.main{width:100%;margin-left:0}.menu{display:block}.content{padding:22px}.topbar{padding:0 20px}}@media(max-width:570px){.heading{align-items:flex-start;flex-direction:column}.stats,.filters,.form-grid{grid-template-columns:1fr}.content{padding:15px}.card-head{flex-direction:column}.thumb{width:100%;height:155px}.meta{grid-template-columns:repeat(3,1fr)}.modal-card{padding:17px}}
</style><link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head><body>
<div class="shell">
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar">
<a class="brand" href="<?= e(app_url()) ?>"><span>&lt;/&gt;</span>BrightFuture</a>
<nav class="nav"><small>Quản trị hệ thống</small><a href="<?= e(app_url('admin/dashboard.php')) ?>">▦ Tổng quan</a><a href="<?= e(app_url('admin/users.php')) ?>">♙ Người dùng</a><a class="active" aria-current="page" href="<?= e(ac_url()) ?>">▤ Khóa học</a><a href="<?= e(app_url('admin/reports.php')) ?>">▥ Báo cáo</a><small>Truy cập</small><a href="<?= e(app_url('courses.php')) ?>">⌕ Khám phá khóa học</a><a href="<?= e(app_url()) ?>">⌂ Trang chủ</a></nav>
<div class="sidebar-foot"><strong><?= e($admin['full_name']) ?></strong><p>Quản trị viên</p><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="logout">Đăng xuất</button></form></div>
</aside>
<div class="main"><header class="topbar"><div style="display:flex;align-items:center;gap:14px"><button class="menu" id="menu" type="button" aria-label="Mở menu">☰</button><strong>Không gian quản trị</strong></div><span class="muted" style="font-size:13px"><?= e($admin['full_name']) ?></span></header>
<main class="content">
<div class="heading"><div><h1>Quản lý khóa học</h1><p class="muted">Theo dõi và quản lý khóa học của tất cả giáo viên trên BrightFuture.</p></div><a class="btn secondary" href="<?= e(app_url('admin/dashboard.php')) ?>">← Về tổng quan</a></div>
<?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '')==='success'?'success':'' ?>" role="status"><?= e((string)($flash['message'] ?? '')) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="stats"><div class="stat"><span>Tổng khóa học</span><strong><?= format_number($summary['total']) ?></strong></div><div class="stat"><span>Đã công khai</span><strong><?= format_number($summary['published']) ?></strong></div><div class="stat"><span>Bản nháp</span><strong><?= format_number($summary['draft']) ?></strong></div><div class="stat"><span>Lượt ghi danh hợp lệ</span><strong><?= format_number($summary['students']) ?></strong></div></section>
<section class="panel"><form class="filters" method="get" action="<?= e(ac_url()) ?>">
<input class="field" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Tên khóa học, mô tả, giáo viên..." aria-label="Tìm kiếm khóa học">
<select class="field" name="teacher" aria-label="Lọc giáo viên"><option value="0">Tất cả giáo viên</option><?php foreach($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>" <?= $filters['teacher']===(int)$teacher['id']?'selected':'' ?>><?= e($teacher['full_name']) ?></option><?php endforeach; ?></select>
<select class="field" name="category" aria-label="Lọc danh mục"><option value="">Tất cả danh mục</option><?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>" <?= $filters['category']===$cat?'selected':'' ?>><?= e($cat) ?></option><?php endforeach; ?></select>
<select class="field" name="status" aria-label="Lọc trạng thái"><option value="all">Mọi trạng thái</option><option value="published" <?= $filters['status']==='published'?'selected':'' ?>>Công khai</option><option value="draft" <?= $filters['status']==='draft'?'selected':'' ?>>Bản nháp</option></select>
<button class="btn" type="submit">Tìm kiếm</button></form><div class="filter-foot"><span>Hiển thị <?= count($courses) ?> / <?= format_number($pagination['total_items']) ?> khóa học phù hợp.</span><a href="<?= e(ac_url()) ?>">Xóa bộ lọc</a></div></section>
<section class="grid">
<?php if (!$courses): ?><div class="empty"><h3>Không tìm thấy khóa học</h3><p>Hãy đổi bộ lọc hoặc kiểm tra dữ liệu trong bảng courses.</p></div><?php endif; ?>
<?php foreach($courses as $course): $img=ac_thumbnail($course['thumbnail']); ?>
<article class="card"><div class="card-head"><div class="thumb"><?php if($img): ?><img src="<?= e($img) ?>" alt="Ảnh khóa học" loading="lazy"><?php else: ?> &lt;/&gt; <?php endif; ?></div><div class="course-info"><span class="pill <?= $course['status']==='published'?'published':'' ?>"><?= e(ac_label($course['status'])) ?></span><h3><?= e($course['title']) ?></h3><p>Giáo viên: <?= e($course['teacher_name']) ?></p><p><?= e($course['category'] ?: 'Chưa phân loại') ?> · <?= e(ac_level($course['level'])) ?></p><p>Cập nhật: <?= e(format_datetime($course['updated_at'])) ?></p></div></div>
<div class="meta"><div><strong><?= (int)$course['student_count'] ?></strong>Học viên</div><div><strong><?= (int)$course['chapter_count'] ?></strong>Chương</div><div><strong><?= (int)$course['lesson_count'] ?></strong>Bài giảng</div></div>
<div class="card-actions"><button class="btn secondary js-edit" type="button" data-course="<?= e(json_encode($course,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)) ?>">✎ Chỉnh sửa</button>
<form method="post" action="<?= e(ac_url()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="btn neutral" type="submit"><?= $course['status']==='published'?'Ẩn khóa học':'Công khai' ?></button></form>
<a class="btn secondary" href="<?= e(course_url((int)$course['id'])) ?>">Xem chi tiết</a>
<form method="post" action="<?= e(ac_url()) ?>" class="js-delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="btn danger" type="submit">Xóa</button></form></div>
</article>
<?php endforeach; ?>
</section>
<?php if ($pagination['total_pages']>1): ?><nav class="pagination" aria-label="Phân trang"><?php if($pagination['has_previous']): ?><a href="<?= e(ac_url(array_merge($filterQuery,['page'=>$pagination['previous_page']]))) ?>">←</a><?php endif; ?><?php $start=max(1,$pagination['current_page']-2);$end=min($pagination['total_pages'],$start+4);$start=max(1,$end-4);for($i=$start;$i<=$end;$i++): ?><a class="<?= $i===$pagination['current_page']?'active':'' ?>" <?= $i===$pagination['current_page']?'aria-current="page"':'' ?> href="<?= e(ac_url(array_merge($filterQuery,['page'=>$i]))) ?>"><?= $i ?></a><?php endfor; ?><?php if($pagination['has_next']): ?><a href="<?= e(ac_url(array_merge($filterQuery,['page'=>$pagination['next_page']]))) ?>">→</a><?php endif; ?></nav><?php endif; ?>
</main></div></div>
<div class="backdrop" id="modalBackdrop"></div><div class="modal" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editTitle"><div class="modal-card"><div class="modal-top"><h2 id="editTitle">Chỉnh sửa khóa học</h2><button type="button" id="closeModal" aria-label="Đóng">×</button></div><form method="post" action="<?= e(ac_url()) ?>" id="editForm"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="course_id" id="editId"><div class="form-grid"><label class="form-field wide">Tên khóa học<input class="field" type="text" name="title" id="editName" maxlength="255" required></label><label class="form-field">Giáo viên<select class="field" name="teacher_id" id="editTeacher" required><?php foreach($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name']) ?></option><?php endforeach; ?></select></label><label class="form-field">Danh mục<input class="field" type="text" name="category" id="editCategory" maxlength="100"></label><label class="form-field">Trình độ<select class="field" name="level" id="editLevel"><option value="beginner">Cơ bản</option><option value="intermediate">Trung cấp</option><option value="advanced">Nâng cao</option></select></label><label class="form-field">Trạng thái<select class="field" name="status" id="editStatus"><option value="draft">Bản nháp</option><option value="published">Công khai</option></select></label><label class="form-field wide">Mô tả<textarea class="field" name="description" id="editDescription" maxlength="100000"></textarea></label></div><p class="hint">Ảnh thumbnail được giữ nguyên. Giáo viên mới phải có vai trò Teacher. Thay đổi giáo viên sẽ chuyển quyền quản lý khóa học sang giáo viên được chọn.</p><div class="form-actions"><button class="btn neutral" id="cancelModal" type="button">Hủy</button><button class="btn" type="submit">Lưu thay đổi</button></div></form></div></div>
<script>
(()=>{const $=id=>document.getElementById(id);const sidebar=$('sidebar'),overlay=$('overlay');$('menu').addEventListener('click',()=>{sidebar.classList.toggle('open');overlay.classList.toggle('open')});overlay.addEventListener('click',()=>{sidebar.classList.remove('open');overlay.classList.remove('open')});const modal=$('editModal'),backdrop=$('modalBackdrop');function close(){modal.classList.remove('open');backdrop.classList.remove('open')};for(const id of ['closeModal','cancelModal'])$(id).addEventListener('click',close);backdrop.addEventListener('click',close);document.addEventListener('keydown',ev=>{if(ev.key==='Escape')close()});document.querySelectorAll('.js-edit').forEach(button=>button.addEventListener('click',()=>{let c;try{c=JSON.parse(button.dataset.course)}catch{return};$('editId').value=c.id;$('editName').value=c.title||'';$('editTeacher').value=c.teacher_id;$('editCategory').value=c.category||'';$('editLevel').value=c.level||'beginner';$('editStatus').value=c.status||'draft';$('editDescription').value=c.description||'';modal.classList.add('open');backdrop.classList.add('open');$('editName').focus()}));document.querySelectorAll('.js-delete').forEach(form=>form.addEventListener('submit',ev=>{if(!confirm('Chỉ xóa khóa học hoàn toàn trống. Bạn chắc chắn muốn xóa?'))ev.preventDefault()}))})();
</script><script>window.BF_LEGACY={role:'admin',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body></html>
