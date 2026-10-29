<?php
/** BrightFuture - Admin / Quan ly nguoi dung. PHP 8+, MySQL 8+. */

require_once __DIR__ . '/../includes/permissions.php';
$admin = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, private, no-cache, must-revalidate');
header('Pragma: no-cache');

/** Guard for preserving role-dependent foreign keys and classroom ownership. */
function bf_user_dependencies(PDO $pdo, int $id): array {
    $sql = [
        'courses' => 'SELECT COUNT(*) FROM courses WHERE teacher_id = ?',
        'enrollments' => 'SELECT COUNT(*) FROM enrollments WHERE student_id = ?',
        'submissions' => 'SELECT COUNT(*) FROM exercise_submissions WHERE student_id = ?',
        'progress' => 'SELECT COUNT(*) FROM lesson_progress WHERE student_id = ?',
        'graded' => 'SELECT COUNT(*) FROM exercise_submissions WHERE graded_by = ?',
    ];
    $result = [];
    foreach ($sql as $key => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $result[$key] = (int)$stmt->fetchColumn();
    }
    return $result;
}
function bf_role_name(string $role): string {
    return match($role) {
        ROLE_ADMIN => 'Quản trị viên',
        ROLE_TEACHER => 'Giáo viên',
        ROLE_STUDENT => 'Học viên',
        default => 'Không xác định'
    };
}
function bf_user_url(array $params = []): string {
    return app_url('admin/users.php') . ($params ? '?' . http_build_query($params) : '');
}
$errors = [];
$pdo = db();

if (is_post()) {
    require_csrf();
    $action = get_post('action');
    $targetId = positive_int($_POST['user_id'] ?? null);
    if ($targetId <= 0 || !in_array($action, ['update', 'delete'], true)) {
        $errors[] = 'Yêu cầu không hợp lệ.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, full_name, email, role FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new DomainException('Không tìm thấy tài khoản cần xử lý.');
            }
            if ($targetId === (int)$admin['id']) {
                throw new DomainException('Không thể sửa vai trò, thông tin hoặc xóa chính tài khoản Admin đang đăng nhập tại trang này.');
            }
            $deps = bf_user_dependencies($pdo, $targetId);
            if ($action === 'update') {
                $nameRaw = $_POST['full_name'] ?? null;
                $emailRaw = $_POST['email'] ?? null;
                $roleRaw = $_POST['role'] ?? null;
                if (!is_string($nameRaw) || !is_string($emailRaw) || !is_string($roleRaw)) {
                    throw new DomainException('Thông tin gửi lên không hợp lệ.');
                }
                $name = clean_text($nameRaw);
                $email = strtolower(trim($emailRaw));
                $role = $roleRaw;
                $nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
                if ($name === '' || $nameLen > 120) {
                    throw new DomainException('Họ tên phải từ 1 đến 120 ký tự.');
                }
                if (strlen($email) > 190 || !is_valid_email($email)) {
                    throw new DomainException('Email không hợp lệ hoặc quá dài.');
                }
                if (!in_array($role, USER_ROLES, true)) {
                    throw new DomainException('Vai trò không hợp lệ.');
                }
                // Existing teaching/learning records must not be orphaned at business level.
                if ($role !== ROLE_TEACHER && $deps['courses'] > 0) {
                    throw new DomainException('Tài khoản đang sở hữu khóa học; hãy chuyển quyền sở hữu khóa học trước khi đổi vai trò.');
                }
                if ($role !== ROLE_STUDENT && ($deps['enrollments'] > 0 || $deps['submissions'] > 0 || $deps['progress'] > 0)) {
                    throw new DomainException('Tài khoản có dữ liệu học tập; không thể đổi sang vai trò khác khi chưa xử lý dữ liệu.');
                }
                if (!in_array($role, [ROLE_TEACHER, ROLE_ADMIN], true) && $deps['graded'] > 0) {
                    throw new DomainException('Tài khoản có lịch sử chấm bài; không thể chuyển sang học viên.');
                }
                if ($target['role'] === ROLE_ADMIN && $role !== ROLE_ADMIN) {
                    $countAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    if ($countAdmin <= 1) {
                        throw new DomainException('Hệ thống phải còn ít nhất một quản trị viên.');
                    }
                }
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role, $targetId]);
                $message = 'Đã cập nhật tài khoản thành công.';
            } else {
                if ($target['role'] === ROLE_ADMIN) {
                    throw new DomainException('Không xóa Admin qua trang này. Hãy quản lý quyền trước khi xóa.');
                }
                if (array_sum($deps) > 0) {
                    throw new DomainException('Không thể xóa tài khoản có khóa học, đăng ký học, tiến độ, bài nộp hoặc lịch sử chấm bài. Hãy xử lý dữ liệu liên quan trước.');
                }
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                $message = 'Đã xóa tài khoản không có dữ liệu liên quan.';
            }
            $pdo->commit();
            set_flash('success', $message);
            redirect('admin/users.php');
        } catch (Throwable $exc) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exc instanceof DomainException) {
                $errors[] = $exc->getMessage();
            } elseif ($exc instanceof PDOException && $exc->getCode() === '23000') {
                $errors[] = 'Email đã tồn tại hoặc dữ liệu tài khoản đang được tham chiếu.';
            } else {
                error_log('[BrightFuture admin/users] ' . $exc->getMessage());
                $errors[] = 'Không thể hoàn tất thao tác. Vui lòng thử lại.';
            }
        }
    }
}

$q = get_query('q');
$q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
$roleFilter = get_query('role', 'all');
if (!in_array($roleFilter, ['all', ROLE_STUDENT, ROLE_TEACHER, ROLE_ADMIN], true)) $roleFilter = 'all';
$page = positive_int($_GET['page'] ?? null, 1);
$stats = ['student' => 0, 'teacher' => 0, 'admin' => 0];
$records = [];
$pagination = paginate(0, $page, 10);
$listError = false;
try {
    foreach ($pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role') as $r) {
        if (array_key_exists($r['role'], $stats)) $stats[$r['role']] = (int)$r['total'];
    }
    $where = ['1=1'];
    $params = [];
    if ($roleFilter !== 'all') { $where[] = 'role = :role'; $params['role'] = $roleFilter; }
    if ($q !== '') {
        $safeLike = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
        $where[] = "(full_name LIKE :name ESCAPE '!' OR email LIKE :email ESCAPE '!')";
        $params['name'] = '%' . $safeLike . '%';
        $params['email'] = '%' . $safeLike . '%';
    }
    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
    foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    $stmt->execute();
    $pagination = paginate((int)$stmt->fetchColumn(), $page, 10);
    $stmt = $pdo->prepare("SELECT id, full_name, email, role, created_at FROM users WHERE $whereSql ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue(':off', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();
} catch (Throwable $exc) {
    error_log('[BrightFuture admin/users list] ' . $exc->getMessage());
    $listError = true;
}
$flash = get_flash();
$name = $admin['full_name'];
$initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quản lý người dùng | <?= e(APP_NAME) ?></title>
<style>
:root{--p:#4f46e5;--purple:#7c3aed;--txt:#172033;--sub:#64748b;--line:#e6eaf2;--bg:#f6f8fc}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font:14px 'Segoe UI',Arial,sans-serif;color:var(--txt)}a{text-decoration:none;color:inherit}button,input,select{font:inherit}button{cursor:pointer}.sidebar{width:255px;position:fixed;inset:0 auto 0 0;background:white;border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:20}.logo{padding:26px 21px;color:var(--p);font-size:22px;font-weight:800}.logo span{display:inline-grid;place-items:center;background:linear-gradient(135deg,var(--p),var(--purple));border-radius:10px;color:white;width:39px;height:39px;margin-right:9px;font-size:15px}.nav{padding:12px;flex:1;overflow-y:auto}.nav-title{padding:15px 13px 8px;color:#94a3b8;font-size:11px;letter-spacing:1px;font-weight:700}.nav a{display:block;padding:14px;border-radius:10px;color:var(--sub);margin-bottom:4px}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--p);font-weight:700}.side-foot{border-top:1px solid var(--line);padding:17px}.user{display:flex;gap:10px;align-items:center;margin-bottom:12px}.avatar{width:39px;height:39px;border-radius:12px;background:#ede9fe;color:#6d28d9;display:grid;place-items:center;font-weight:800}.user strong{display:block;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.user small{color:var(--sub)}.logout{background:#fff1f2;color:#be123c;border:0;padding:12px;border-radius:10px;width:100%;font-weight:700}.main{margin-left:255px;min-width:0}.topbar{height:76px;display:flex;align-items:center;justify-content:space-between;background:white;padding:0 34px;border-bottom:1px solid var(--line)}.topbar small{color:var(--sub)}.hamburger{display:none;background:none;border:0;font-size:23px}.container{max-width:1450px;margin:auto;padding:33px}.crumb{color:var(--sub);margin-bottom:17px}.crumb a{color:var(--p)}h1{font-size:29px;margin:0 0 8px}h2{font-size:17px;margin:0}.subtitle{color:var(--sub);margin:0 0 28px;line-height:1.6}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px;margin-bottom:25px}.stat{border:1px solid var(--line);background:white;border-radius:16px;padding:22px}.stat label{color:var(--sub);font-size:13px}.stat strong{display:block;font-size:30px;margin-top:9px}.panel{border:1px solid var(--line);background:white;border-radius:17px;overflow:hidden;margin-bottom:20px}.filter{display:flex;gap:12px;padding:20px;flex-wrap:wrap}.filter input,.filter select,.field input,.field select{height:44px;border:1px solid #dce2ed;border-radius:10px;padding:0 13px;background:white;outline:none;min-width:0}.filter input:focus,.filter select:focus,.field input:focus,.field select:focus{border-color:var(--p);box-shadow:0 0 0 3px #eef2ff}.filter input{flex:1;min-width:220px}.btn{border:0;border-radius:10px;padding:11px 17px;background:var(--p);color:white;font-weight:700}.btn.subtle{background:#eef2ff;color:var(--p)}.btn.danger{background:#be123c}.btn.sm{padding:8px 11px;font-size:12px}.result{padding:0 20px 18px;color:var(--sub)}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left;min-width:780px}th{font-size:12px;background:#f8faff;color:var(--sub);font-weight:700}td,th{padding:15px 17px;border-top:1px solid var(--line)}td{vertical-align:middle}td strong{display:block}td small{color:var(--sub)}.pill{display:inline-block;border-radius:30px;padding:6px 11px;font-size:12px;font-weight:700;background:#eef2ff;color:var(--p)}.pill.teacher{background:#f3e8ff;color:#7e22ce}.pill.admin{background:#fef3c7;color:#92400e}.actions{display:flex;gap:7px}.muted{color:var(--sub)}.pagination{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin:28px 0}.page{min-width:40px;height:40px;display:inline-grid;place-items:center;background:white;border:1px solid var(--line);border-radius:9px;padding:0 10px}.page.active{background:var(--p);color:white}.notice{padding:14px 17px;border-radius:10px;margin-bottom:18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.notice.success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.empty{padding:40px;text-align:center;color:var(--sub)}.backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:50;align-items:center;justify-content:center;padding:20px}.backdrop.open{display:flex}.modal{width:min(100%,510px);background:white;border-radius:18px;padding:25px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 70px #0002}.modal h2{margin-bottom:10px}.modal p{color:var(--sub);line-height:1.6}.field{margin:16px 0}.field label{display:block;margin-bottom:7px;font-weight:600}.field input,.field select{width:100%}.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}.info{font-size:12px;color:var(--sub);line-height:1.6}.overlay{display:none}
@media(max-width:1000px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.overlay.show{display:block;position:fixed;inset:0;background:#0006;z-index:15}.main{margin-left:0}.hamburger{display:block}.container{padding:23px}.topbar{padding:0 23px}}@media(max-width:600px){.stats{grid-template-columns:1fr}.container{padding:17px}.filter{flex-direction:column}.filter input{min-width:0;width:100%}.filter select,.filter .btn{width:100%}h1{font-size:25px}.topbar{padding:0 17px}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar">
 <a class="logo" href="<?= e(app_url()) ?>"><span>&lt;/&gt;</span>BrightFuture</a>
 <nav class="nav" aria-label="Quản trị"><div class="nav-title">HỆ THỐNG</div>
 <a href="<?= e(app_url('admin/dashboard.php')) ?>">▦ &nbsp; Tổng quan</a>
 <a class="active" aria-current="page" href="<?= e(app_url('admin/users.php')) ?>">♙ &nbsp; Người dùng</a>
 <a href="<?= e(app_url('admin/courses.php')) ?>">▤ &nbsp; Khóa học</a>
 <a href="<?= e(app_url('admin/reports.php')) ?>">▥ &nbsp; Báo cáo</a>
 <div class="nav-title">LIÊN KẾT</div><a href="<?= e(app_url()) ?>">⌂ &nbsp; Trang chủ</a></nav>
 <div class="side-foot"><div class="user"><div class="avatar"><?= e($initial) ?></div><div><strong><?= e($name) ?></strong><small>Quản trị viên</small></div></div>
 <form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form></div>
</aside>
<div class="main"><header class="topbar"><div style="display:flex;align-items:center;gap:15px"><button class="hamburger" id="hamburger" aria-label="Mở menu" aria-expanded="false" aria-controls="sidebar">☰</button><b>Không gian quản trị</b></div><small><?= e($name) ?></small></header>
<main class="container">
 <div class="crumb"><a href="<?= e(app_url('admin/dashboard.php')) ?>">Tổng quan</a> / Người dùng</div>
 <h1>Quản lý người dùng</h1><p class="subtitle">Tra cứu, cập nhật thông tin, thay đổi vai trò và quản lý tài khoản hệ thống.</p>
 <?php foreach ($errors as $error): ?><div class="notice" role="alert"><?= e($error) ?></div><?php endforeach; ?>
 <?php if ($flash): ?><div class="notice <?= ($flash['type'] ?? '') === 'success' ? 'success' : '' ?>" role="status"><?= e((string)($flash['message'] ?? '')) ?></div><?php endif; ?>
 <?php if ($listError): ?><div class="notice" role="alert">Không tải được danh sách người dùng. Vui lòng kiểm tra kết nối MySQL.</div><?php endif; ?>
 <section class="stats" aria-label="Thống kê tài khoản">
 <div class="stat"><label>Học viên</label><strong><?= format_number($stats['student']) ?></strong></div>
 <div class="stat"><label>Giáo viên</label><strong><?= format_number($stats['teacher']) ?></strong></div>
 <div class="stat"><label>Quản trị viên</label><strong><?= format_number($stats['admin']) ?></strong></div>
 </section>
 <section class="panel">
  <form class="filter" method="get" action="<?= e(app_url('admin/users.php')) ?>">
   <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Tìm theo họ tên hoặc email..." aria-label="Tìm kiếm người dùng">
   <select name="role" aria-label="Lọc vai trò"><option value="all">Tất cả vai trò</option><?php foreach ([ROLE_STUDENT, ROLE_TEACHER, ROLE_ADMIN] as $r): ?><option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e(bf_role_name($r)) ?></option><?php endforeach; ?></select>
   <button class="btn" type="submit">Tìm kiếm</button><a class="btn subtle" href="<?= e(app_url('admin/users.php')) ?>">Xóa lọc</a>
  </form>
  <div class="result">Tìm thấy <strong><?= format_number($pagination['total_items']) ?></strong> tài khoản</div>
  <div class="scroll"><table><thead><tr><th>Người dùng</th><th>Vai trò</th><th>Ngày đăng ký</th><th>ID</th><th>Thao tác</th></tr></thead><tbody>
  <?php if (!$records): ?><tr><td colspan="5" class="empty"><?= $listError ? 'Không thể lấy dữ liệu.' : 'Không có tài khoản phù hợp.' ?></td></tr><?php endif; ?>
  <?php foreach ($records as $record): ?>
  <tr><td><strong><?= e($record['full_name']) ?></strong><small><?= e($record['email']) ?></small></td>
  <td><span class="pill <?= e($record['role']) ?>"><?= e(bf_role_name($record['role'])) ?></span></td>
  <td><?= e(format_datetime($record['created_at'])) ?></td><td class="muted">#<?= (int)$record['id'] ?></td>
  <td><?php if ((int)$record['id'] === (int)$admin['id']): ?><small>Tài khoản hiện tại</small><?php else: ?>
   <div class="actions">
    <button type="button" class="btn subtle sm edit-btn" data-user='<?= e(json_encode(['id'=>(int)$record['id'],'full_name'=>$record['full_name'],'email'=>$record['email'],'role'=>$record['role']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'>Sửa</button>
    <button type="button" class="btn danger sm delete-btn" data-id="<?= (int)$record['id'] ?>" data-name="<?= e($record['full_name']) ?>">Xóa</button>
   </div><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
 </section>
 <?php if ($pagination['total_pages'] > 1): ?>
 <nav class="pagination" aria-label="Phân trang">
 <?php if ($pagination['has_previous']): ?><a class="page" href="<?= e(bf_user_url(['q'=>$q,'role'=>$roleFilter,'page'=>$pagination['previous_page']])) ?>">←</a><?php endif; ?>
 <?php $from = max(1, $pagination['current_page'] - 2); $to = min($pagination['total_pages'], $from + 4); $from = max(1, $to - 4); ?>
 <?php for ($i=$from; $i<=$to; $i++): ?><a class="page <?= $i === $pagination['current_page'] ? 'active' : '' ?>" <?= $i === $pagination['current_page'] ? 'aria-current="page"' : '' ?> href="<?= e(bf_user_url(['q'=>$q,'role'=>$roleFilter,'page'=>$i])) ?>"><?= $i ?></a><?php endfor; ?>
 <?php if ($pagination['has_next']): ?><a class="page" href="<?= e(bf_user_url(['q'=>$q,'role'=>$roleFilter,'page'=>$pagination['next_page']])) ?>">→</a><?php endif; ?>
 </nav><?php endif; ?>
</main></div>
<div class="backdrop" id="editBackdrop" role="presentation"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="editTitle"><h2 id="editTitle">Chỉnh sửa tài khoản</h2><p>Thay đổi họ tên, email hoặc vai trò. Các tài khoản có dữ liệu học tập/giảng dạy có thể bị giới hạn đổi vai trò.</p>
<form method="post" action="<?= e(app_url('admin/users.php')) ?>" id="editForm">
<?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" id="editId">
<div class="field"><label for="editName">Họ và tên</label><input id="editName" name="full_name" maxlength="120" required></div>
<div class="field"><label for="editEmail">Email</label><input id="editEmail" name="email" type="email" maxlength="190" required></div>
<div class="field"><label for="editRole">Vai trò</label><select id="editRole" name="role" required><option value="student">Học viên</option><option value="teacher">Giáo viên</option><option value="admin">Quản trị viên</option></select></div>
<p class="info">Việc cấp quyền Admin là thao tác nhạy cảm. Chỉ cấp cho người được ủy quyền.</p><div class="modal-actions"><button class="btn subtle close-modal" type="button">Hủy</button><button class="btn" type="submit">Lưu thay đổi</button></div></form></section></div>
<div class="backdrop" id="deleteBackdrop" role="presentation"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteTitle"><h2 id="deleteTitle">Xác nhận xóa tài khoản</h2><p>Bạn muốn xóa <strong id="deleteName"></strong>? Chỉ có thể xóa tài khoản không có dữ liệu liên quan. Thao tác không thể hoàn tác.</p>
<form method="post" action="<?= e(app_url('admin/users.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" id="deleteId"><div class="modal-actions"><button type="button" class="btn subtle close-modal">Hủy</button><button type="submit" class="btn danger">Xác nhận xóa</button></div></form></section></div>
<script>
(()=>{
 const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay'),hamburger=document.getElementById('hamburger');
 const menu=(open)=>{sidebar.classList.toggle('open',open);overlay.classList.toggle('show',open);hamburger.setAttribute('aria-expanded',String(open));};
 hamburger.addEventListener('click',()=>menu(!sidebar.classList.contains('open')));overlay.addEventListener('click',()=>menu(false));
 const edit=document.getElementById('editBackdrop'),del=document.getElementById('deleteBackdrop');
 const close=()=>{edit.classList.remove('open');del.classList.remove('open');};
 document.querySelectorAll('.close-modal').forEach(x=>x.addEventListener('click',close));
 [edit,del].forEach(x=>x.addEventListener('click',e=>{if(e.target===x)close();}));
 document.querySelectorAll('.edit-btn').forEach(btn=>btn.addEventListener('click',()=>{try{const u=JSON.parse(btn.dataset.user);document.getElementById('editId').value=u.id;document.getElementById('editName').value=u.full_name;document.getElementById('editEmail').value=u.email;document.getElementById('editRole').value=u.role;edit.classList.add('open');document.getElementById('editName').focus();}catch(e){alert('Không thể mở dữ liệu tài khoản.');}}));
 document.querySelectorAll('.delete-btn').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById('deleteId').value=btn.dataset.id;document.getElementById('deleteName').textContent=btn.dataset.name;del.classList.add('open');}));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'){close();menu(false);}});
})();
</script>
<script>window.BF_LEGACY={role:'admin',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body></html>
