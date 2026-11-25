<?php
/** BrightFuture - Quan tri toan bo khoa hoc. PHP 8+, MySQL 8+. */

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../includes/icons.php';
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
    'archive'=>get_query('archive','active'),
];
if (!in_array($filters['status'],['all','draft','published'],true)) $filters['status']='all';
if (!in_array($filters['archive'],['active','deleted','all'],true)) $filters['archive']='active';
$filters['q']=function_exists('mb_substr') ? mb_substr($filters['q'],0,100,'UTF-8') : substr($filters['q'],0,100);
$filters['category']=function_exists('mb_substr') ? mb_substr($filters['category'],0,100,'UTF-8') : substr($filters['category'],0,100);
$page=positive_int($_GET['page'] ?? null,1);

// POST actions: all mutations require CSRF, ownership is unrestricted ONLY for Admin.
if (is_post()) {
    require_csrf();
    $action=get_post('action');
    $id=positive_int($_POST['course_id'] ?? null);
    if ($id < 1 || !in_array($action,['update','toggle','delete','restore'],true)) {
        $errors[]='Thao tác hoặc mã khóa học không hợp lệ.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT * FROM courses WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $course=$stmt->fetch();
            if (!$course) throw new DomainException('Khóa học không tồn tại hoặc đã bị xóa.');

            if ($action==='restore') {
                $stmt=$pdo->prepare('UPDATE courses SET deleted_at = NULL WHERE id = ?');
                $stmt->execute([$id]);
                $message='Đã khôi phục khóa học.';
            } elseif ($action==='toggle') {
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
                $stmt=$pdo->prepare('UPDATE courses SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([$id]);
                $message='Đã lưu trữ khóa học.';
            }
            $pdo->commit();
            $auditAction = match ($action) {
                'delete' => 'course.archive',
                'restore' => 'course.restore',
                'toggle' => 'course.toggle_status',
                default => 'course.update',
            };
            bf_audit_log(
                $auditAction,
                'course',
                $id,
                $action === 'update'
                    ? [
                        'status' => $statusInput,
                        'teacher_id' => $teacherInput,
                        'title' => $title,
                    ]
                    : [
                        'status' => $action === 'toggle' ? $next : 'deleted',
                        'title' => (string) $course['title'],
                    ]
            );
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
    $summaryRow=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(status='published'),0) published,COALESCE(SUM(status='draft'),0) draft FROM courses WHERE deleted_at IS NULL")->fetch();
    if ($summaryRow) $summary=array_merge($summary,array_map('intval',$summaryRow));
    $summary['students']=(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status IN ('active','completed')")->fetchColumn();
    $where=[];
    $params=[];
    if ($filters['archive'] === 'active') $where[]='c.deleted_at IS NULL';
    elseif ($filters['archive'] === 'deleted') $where[]='c.deleted_at IS NOT NULL';
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
    $sql="SELECT     c.id,c.teacher_id,c.title,c.description,c.category,c.thumbnail,c.level,c.status,c.created_at,c.updated_at,c.deleted_at,
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
$filterQuery = array_filter(['q'=>$filters['q'],'status'=>$filters['status'],'teacher'=>$filters['teacher'],'category'=>$filters['category'],'archive'=>$filters['archive']], static fn($v) => $v !== '' && $v !== 0);

$pageTitle = 'Quản lý khóa học - ' . APP_NAME;
$portalRole = ROLE_ADMIN;
$topbarTitle = 'Không gian quản trị';
$extraCss = ['css/bf-admin-courses.css'];
require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($admin, 'courses') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content ac-content">
<div class="heading"><div><h1>Quản lý khóa học</h1><p class="muted">Theo dõi và quản lý khóa học của tất cả giáo viên trên BrightFuture.</p></div><a class="btn secondary" href="<?= e(app_url('admin/dashboard.php')) ?>">← Về tổng quan</a></div>
<?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '')==='success'?'success':'' ?>" role="status"><?= e((string)($flash['message'] ?? '')) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="stats"><div class="stat"><span>Tổng khóa học</span><strong><?= format_number($summary['total']) ?></strong></div><div class="stat"><span>Đã công khai</span><strong><?= format_number($summary['published']) ?></strong></div><div class="stat"><span>Bản nháp</span><strong><?= format_number($summary['draft']) ?></strong></div><div class="stat"><span>Lượt ghi danh hợp lệ</span><strong><?= format_number($summary['students']) ?></strong></div></section>
<section class="panel"><form class="filters" method="get" action="<?= e(ac_url()) ?>">
<input class="field" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Tên khóa học, mô tả, giáo viên..." aria-label="Tìm kiếm khóa học">
<select class="field" name="teacher" aria-label="Lọc giáo viên"><option value="0">Tất cả giáo viên</option><?php foreach($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>" <?= $filters['teacher']===(int)$teacher['id']?'selected':'' ?>><?= e($teacher['full_name']) ?></option><?php endforeach; ?></select>
<select class="field" name="category" aria-label="Lọc danh mục"><option value="">Tất cả danh mục</option><?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>" <?= $filters['category']===$cat?'selected':'' ?>><?= e($cat) ?></option><?php endforeach; ?></select>
<select class="field" name="status" aria-label="Lọc trạng thái"><option value="all">Mọi trạng thái</option><option value="published" <?= $filters['status']==='published'?'selected':'' ?>>Công khai</option><option value="draft" <?= $filters['status']==='draft'?'selected':'' ?>>Bản nháp</option></select>
<select class="field" name="archive" aria-label="Lọc lưu trữ"><option value="active" <?= $filters['archive']==='active'?'selected':'' ?>>Đang hoạt động</option><option value="deleted" <?= $filters['archive']==='deleted'?'selected':'' ?>>Đã lưu trữ</option><option value="all" <?= $filters['archive']==='all'?'selected':'' ?>>Tất cả</option></select>
<button class="btn" type="submit">Tìm kiếm</button></form><div class="filter-foot"><span>Hiển thị <?= count($courses) ?> / <?= format_number($pagination['total_items']) ?> khóa học phù hợp.</span><span><a href="<?= e(ac_url()) ?>">Xóa bộ lọc</a> · <a href="<?= e(app_url('admin/export.php?type=courses&' . http_build_query(['q'=>$filters['q'],'status'=>$filters['status'],'teacher'=>$filters['teacher'],'category'=>$filters['category'],'archive'=>$filters['archive']]))) ?>">Tải CSV</a></span></div></section>
<section class="grid">
<?php if (!$courses): ?><div class="empty"><h3>Không tìm thấy khóa học</h3><p>Hãy đổi bộ lọc hoặc kiểm tra dữ liệu trong bảng courses.</p></div><?php endif; ?>
<?php foreach($courses as $course): $img=ac_thumbnail($course['thumbnail']); ?>
<article class="card"><div class="card-head"><div class="thumb"><?php if($img): ?><img src="<?= e($img) ?>" alt="Ảnh khóa học" loading="lazy"><?php else: ?> &lt;/&gt; <?php endif; ?></div><div class="course-info"><span class="pill <?= $course['status']==='published'?'published':'' ?>"><?= e(ac_label($course['status'])) ?></span><h3><?= e($course['title']) ?></h3><p>Giáo viên: <?= e($course['teacher_name']) ?></p><p><?= e($course['category'] ?: 'Chưa phân loại') ?> · <?= e(ac_level($course['level'])) ?></p><p>Cập nhật: <?= e(format_datetime($course['updated_at'])) ?></p></div></div>
<div class="meta"><div><strong><?= (int)$course['student_count'] ?></strong>Học viên</div><div><strong><?= (int)$course['chapter_count'] ?></strong>Chương</div><div><strong><?= (int)$course['lesson_count'] ?></strong>Bài giảng</div></div>
<div class="card-actions"><button class="btn secondary js-edit" type="button" data-course="<?= e(json_encode($course,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)) ?>">✎ Chỉnh sửa</button>
<form method="post" action="<?= e(ac_url()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="btn neutral" type="submit"><?= $course['status']==='published'?'Ẩn khóa học':'Công khai' ?></button></form>
<a class="btn secondary" href="<?= e(course_url((int)$course['id'])) ?>">Xem chi tiết</a>
<?php if (!empty($course['deleted_at'])): ?>
<form method="post" action="<?= e(ac_url()) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="btn secondary" type="submit">Khôi phục</button></form>
<?php else: ?>
<form method="post" action="<?= e(ac_url()) ?>" class="js-delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><button class="btn danger" type="submit">Lưu trữ</button></form>
<?php endif; ?></div>
</article>
<?php endforeach; ?>
</section>
<?php if ($pagination['total_pages']>1): ?><nav class="pagination" aria-label="Phân trang"><?php if($pagination['has_previous']): ?><a href="<?= e(ac_url(array_merge($filterQuery,['page'=>$pagination['previous_page']]))) ?>">←</a><?php endif; ?><?php $start=max(1,$pagination['current_page']-2);$end=min($pagination['total_pages'],$start+4);$start=max(1,$end-4);for($i=$start;$i<=$end;$i++): ?><a class="<?= $i===$pagination['current_page']?'active':'' ?>" <?= $i===$pagination['current_page']?'aria-current="page"':'' ?> href="<?= e(ac_url(array_merge($filterQuery,['page'=>$i]))) ?>"><?= $i ?></a><?php endfor; ?><?php if($pagination['has_next']): ?><a href="<?= e(ac_url(array_merge($filterQuery,['page'=>$pagination['next_page']]))) ?>">→</a><?php endif; ?></nav><?php endif; ?>
</main>

<div class="backdrop" id="modalBackdrop"></div>
<div class="modal" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
  <div class="modal-card">
    <div class="modal-top">
      <h2 id="editTitle">Chỉnh sửa khóa học</h2>
      <button type="button" id="closeModal" aria-label="Đóng">×</button>
    </div>
    <form method="post" action="<?= e(ac_url()) ?>" id="editForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="course_id" id="editId">
      <div class="form-grid">
        <label class="form-field wide">Tên khóa học
          <input class="field" type="text" name="title" id="editName" maxlength="255" required>
        </label>
        <label class="form-field">Giáo viên
          <select class="field" name="teacher_id" id="editTeacher" required>
            <?php foreach($teachers as $teacher): ?>
              <option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">Danh mục
          <input class="field" type="text" name="category" id="editCategory" maxlength="100">
        </label>
        <label class="form-field">Trình độ
          <select class="field" name="level" id="editLevel">
            <option value="beginner">Cơ bản</option>
            <option value="intermediate">Trung cấp</option>
            <option value="advanced">Nâng cao</option>
          </select>
        </label>
        <label class="form-field">Trạng thái
          <select class="field" name="status" id="editStatus">
            <option value="draft">Bản nháp</option>
            <option value="published">Công khai</option>
          </select>
        </label>
        <label class="form-field wide">Mô tả
          <textarea class="field" name="description" id="editDescription" maxlength="100000"></textarea>
        </label>
      </div>
      <p class="hint">Ảnh thumbnail được giữ nguyên. Giáo viên mới phải có vai trò Teacher. Thay đổi giáo viên sẽ chuyển quyền quản lý khóa học sang giáo viên được chọn.</p>
      <div class="form-actions">
        <button class="btn neutral" id="cancelModal" type="button">Hủy</button>
        <button class="btn" type="submit">Lưu thay đổi</button>
      </div>
    </form>
  </div>
</div>

<script>
(()=>{
  const $=id=>document.getElementById(id);
  const modal=$('editModal'),backdrop=$('modalBackdrop');
  function close(){
    if(!modal||!backdrop) return;
    modal.classList.remove('open');
    backdrop.classList.remove('open');
  }
  for(const id of ['closeModal','cancelModal']){
    const el=$(id);
    if(el) el.addEventListener('click',close);
  }
  if(backdrop) backdrop.addEventListener('click',close);
  document.addEventListener('keydown',ev=>{if(ev.key==='Escape')close()});
  document.querySelectorAll('.js-edit').forEach(button=>button.addEventListener('click',()=>{
    let c;
    try{c=JSON.parse(button.dataset.course)}catch{return};
    $('editId').value=c.id;
    $('editName').value=c.title||'';
    $('editTeacher').value=c.teacher_id;
    $('editCategory').value=c.category||'';
    $('editLevel').value=c.level||'beginner';
    $('editStatus').value=c.status||'draft';
    $('editDescription').value=c.description||'';
    modal.classList.add('open');
    backdrop.classList.add('open');
    $('editName').focus();
  }));
  document.querySelectorAll('.js-delete').forEach(form=>form.addEventListener('submit',ev=>{
    if(!confirm('Lưu trữ khóa học này? Dữ liệu học tập sẽ được giữ lại.'))ev.preventDefault();
  }));
})();
</script>

<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
</body>
</html>
