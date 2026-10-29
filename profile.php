<?php
/**
 * BrightFuture - Ho so ca nhan Student / Teacher / Admin
 * PHP >= 8.0; PDO; CSRF; no dependency beyond existing includes.
 */
require_once __DIR__ . '/includes/permissions.php';
$user = require_login();
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
$uid = (int)$user['id'];
$role = (string)$user['role'];
$table = $role === ROLE_STUDENT ? 'student_profiles' : ($role === ROLE_TEACHER ? 'teacher_profiles' : null);
$home = $role === ROLE_STUDENT ? 'student/dashboard.php' : ($role === ROLE_TEACHER ? 'teacher/dashboard.php' : 'admin/dashboard.php');
$errors = [];
$flash = get_flash();
$profile = [];
$stats = [];
$roleName = $role === ROLE_STUDENT ? 'Học viên' : ($role === ROLE_TEACHER ? 'Giảng viên' : 'Quản trị viên');

try {
    if ($table !== null) {
        $stmt = db()->prepare("SELECT * FROM `$table` WHERE user_id = :id LIMIT 1");
        $stmt->execute(['id' => $uid]);
        $profile = $stmt->fetch() ?: [];
    }
    if ($role === ROLE_STUDENT) {
        $stmt = db()->prepare("SELECT (SELECT COUNT(*) FROM enrollments WHERE student_id = :id1 AND status IN ('active','completed')) AS course_count, (SELECT COUNT(*) FROM lesson_progress WHERE student_id = :id2 AND status = 'completed') AS completed_lessons, (SELECT COUNT(*) FROM exercise_submissions WHERE student_id = :id3) AS submission_count");
        $stmt->execute(['id1'=>$uid,'id2'=>$uid,'id3'=>$uid]);
        $stats = $stmt->fetch() ?: [];
    } elseif ($role === ROLE_TEACHER) {
        $stmt = db()->prepare("SELECT COUNT(DISTINCT c.id) AS course_count, (SELECT COUNT(*) FROM course_chapters ch JOIN courses c2 ON c2.id=ch.course_id WHERE c2.teacher_id=:id2) AS chapter_count, (SELECT COUNT(*) FROM lessons l JOIN course_chapters ch2 ON ch2.id=l.chapter_id JOIN courses c3 ON c3.id=ch2.course_id WHERE c3.teacher_id=:id3) AS lesson_count FROM courses c WHERE c.teacher_id=:id1");
        $stmt->execute(['id1'=>$uid,'id2'=>$uid,'id3'=>$uid]);
        $stats = $stmt->fetch() ?: [];
    }
} catch (Throwable $ex) {
    error_log('[BrightFuture profile read] '.$ex->getMessage());
    $errors[] = 'Không đọc được dữ liệu hồ sơ. Vui lòng chạy file database/04_ho_so_nguoi_dung.sql và kiểm tra kết nối MySQL.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_profile') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
            $phone = trim((string)($_POST['phone'] ?? ''));
            $bio = trim((string)($_POST['bio'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors[] = 'Họ tên phải từ 2 đến 120 ký tự.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Email không hợp lệ.';
            if ($phone !== '' && (!preg_match('/^[0-9+().\s-]{7,20}$/', $phone))) $errors[] = 'Số điện thoại không hợp lệ.';
            if (mb_strlen($bio) > 2000) $errors[] = 'Giới thiệu tối đa 2.000 ký tự.';
            $fields = ['phone'=>$phone ?: null, 'bio'=>$bio ?: null];
            if ($role === ROLE_STUDENT) {
                $dob = trim((string)($_POST['date_of_birth'] ?? ''));
                $school = trim((string)($_POST['school'] ?? ''));
                $goal = trim((string)($_POST['learning_goal'] ?? ''));
                if ($dob !== '') {
                    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
                    if (!$dt || $dt->format('Y-m-d') !== $dob || $dt > new DateTimeImmutable('today')) $errors[] = 'Ngày sinh không hợp lệ.';
                }
                if (mb_strlen($school)>200 || mb_strlen($goal)>2000) $errors[] = 'Thông tin trường học hoặc mục tiêu quá dài.';
                $fields += ['date_of_birth'=>$dob ?: null,'school'=>$school ?: null,'learning_goal'=>$goal ?: null];
            } elseif ($role === ROLE_TEACHER) {
                $special = trim((string)($_POST['specialization'] ?? ''));
                $org = trim((string)($_POST['organization'] ?? ''));
                $years = trim((string)($_POST['years_experience'] ?? ''));
                if (mb_strlen($special)>200 || mb_strlen($org)>200) $errors[]='Chuyên môn hoặc đơn vị công tác quá dài.';
                if ($years !== '' && (!ctype_digit($years) || (int)$years > 80)) $errors[]='Số năm kinh nghiệm không hợp lệ.';
                $fields += ['specialization'=>$special ?: null,'organization'=>$org ?: null,'years_experience'=>$years === '' ? null : (int)$years];
            }
            if (!$errors) {
                $pdo=db();
                $pdo->beginTransaction();
                try {
                    $check=$pdo->prepare('SELECT id FROM users WHERE email=:email AND id<>:id LIMIT 1');
                    $check->execute(['email'=>$email,'id'=>$uid]);
                    if ($check->fetchColumn()) throw new DomainException('Email này đã được sử dụng bởi tài khoản khác.');
                    $update=$pdo->prepare('UPDATE users SET full_name=:name,email=:email WHERE id=:id');
                    $update->execute(['name'=>$name,'email'=>$email,'id'=>$uid]);
                    if ($table !== null) {
                        $cols=array_keys($fields);
                        $columns='`user_id`, '.implode(', ',array_map(fn($c)=>'`'.$c.'`',$cols));
                        $slots=':user_id, '.implode(', ',array_map(fn($c)=>':'.$c,$cols));
                        $updates=implode(', ',array_map(fn($c)=>'`'.$c.'`=VALUES(`'.$c.'`)',$cols));
                        $sql="INSERT INTO `$table` ($columns) VALUES ($slots) ON DUPLICATE KEY UPDATE $updates";
                        $save=$pdo->prepare($sql);
                        $save->execute(['user_id'=>$uid]+$fields);
                    }
                    $pdo->commit();
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $ex;
                }
                set_flash('success','Đã cập nhật hồ sơ thành công.');
                redirect('profile.php');
            }
            $user['full_name']=$name;
            $user['email']=$email;
            $profile=array_merge($profile,$fields);
        } elseif ($action === 'change_password') {
            $old=(string)($_POST['old_password']??'');
            $new=(string)($_POST['new_password']??'');
            $confirm=(string)($_POST['confirm_password']??'');
            if (strlen($new)<8 || strlen($new)>128) $errors[]='Mật khẩu mới phải từ 8 đến 128 ký tự.';
            if ($new!==$confirm) $errors[]='Xác nhận mật khẩu mới không khớp.';
            $q=db()->prepare('SELECT password_hash FROM users WHERE id=:id');
            $q->execute(['id'=>$uid]);
            $hash=$q->fetchColumn();
            if (!is_string($hash) || !password_verify($old,$hash)) $errors[]='Mật khẩu hiện tại không đúng.';
            if (!$errors) {
                $stmt=db()->prepare('UPDATE users SET password_hash=:hash WHERE id=:id');
                $stmt->execute(['hash'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$uid]);
                session_regenerate_id(true);
                set_flash('success','Đã thay đổi mật khẩu.');
                redirect('profile.php');
            }
        } else {
            $errors[]='Thao tác không hợp lệ.';
        }
    } catch (DomainException $ex) {
        $errors[]=$ex->getMessage();
    } catch (Throwable $ex) {
        error_log('[BrightFuture profile save] '.$ex->getMessage());
        $errors[]='Không lưu được hồ sơ. Hãy kiểm tra SQL hồ sơ và cấu hình MySQL.';
    }
}
$initial = mb_strtoupper(mb_substr(trim((string)$user['full_name']), 0, 1, 'UTF-8'), 'UTF-8');
$statLabels=$role===ROLE_STUDENT?['course_count'=>'Khóa học của tôi','completed_lessons'=>'Bài đã hoàn thành','submission_count'=>'Bài đã nộp']:['course_count'=>'Khóa học phụ trách','chapter_count'=>'Chương đã tạo','lesson_count'=>'Bài giảng đã tạo'];
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hồ sơ của tôi | BrightFuture</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#17233c;background:#f4f7fc}*{box-sizing:border-box}body{margin:0}a{color:#4757d6;text-decoration:none}button,input,textarea{font:inherit}.layout{display:grid;grid-template-columns:248px 1fr;min-height:100vh}.side{background:#fff;border-right:1px solid #e5eaf2;padding:24px 16px;display:flex;flex-direction:column;gap:14px}.brand{font-size:21px;font-weight:800;color:#243ab4;padding:10px 12px}.side a.nav{display:block;padding:12px 14px;border-radius:11px;color:#4b5a70;font-weight:600}.side a.nav:hover,.side a.nav.active{background:#edf0ff;color:#394cc6}.foot{margin-top:auto;padding:12px;border-top:1px solid #e7ebf3}.foot small{display:block;color:#798397;margin-top:4px}.logout{background:#fff;border:1px solid #e1e5ef;color:#334155;padding:10px 14px;border-radius:9px;margin-top:12px;cursor:pointer;width:100%;text-align:left}.main{max-width:1200px;width:100%;margin:auto;padding:36px 35px 70px}.crumb{color:#718096;font-size:13px}h1{font-size:29px;margin:11px 0 6px}h2{font-size:18px;margin:0 0 20px}.muted{color:#748095}.hero{display:flex;align-items:center;gap:20px;padding:26px;border:1px solid #e2e7f0;border-radius:17px;background:#fff;margin:24px 0}.avatar{width:84px;height:84px;display:grid;place-items:center;border-radius:22px;font-size:40px;font-weight:800;background:#e9edff;color:#4654cc;flex-shrink:0}.hero h2{margin:0 0 5px;font-size:24px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 20px}.stat,.panel{border:1px solid #e2e7f0;border-radius:15px;background:#fff}.stat{padding:18px}.stat .n{font-size:27px;font-weight:800;margin-bottom:5px}.stat .label{color:#7b8494;font-size:13px}.sections{display:grid;grid-template-columns:1.4fr 1fr;gap:20px;align-items:start}.panel{padding:24px;margin-bottom:20px}label{display:block;font-size:13px;font-weight:700;margin:0 0 7px}.field{margin-bottom:16px}input,textarea{width:100%;border:1px solid #dce3ee;background:#fff;padding:12px 13px;border-radius:10px;outline:0;color:#17233c}textarea{min-height:100px;resize:vertical}input:focus,textarea:focus{border-color:#636de6;box-shadow:0 0 0 3px #636de61c}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:15px}.btn{background:#4f56cc;color:#fff;border:0;padding:12px 18px;border-radius:10px;font-weight:700;cursor:pointer}.btn:hover{background:#3e47b6}.banner{padding:13px 16px;border-radius:10px;margin:14px 0;border:1px solid #fecaca;background:#fff1f2;color:#9f1239}.banner.ok{background:#ecfdf5;color:#166534;border-color:#a7f3d0}.subtitle{font-size:13px;color:#64748b;margin-top:-12px;margin-bottom:18px}.password-hint{font-size:12px;color:#7e879a}.back{display:inline-block;margin-top:18px}.mobile-head{display:none}@media(max-width:930px){.layout{grid-template-columns:1fr}.side{display:none}.mobile-head{display:flex;padding:14px 20px;background:#fff;justify-content:space-between;align-items:center;border-bottom:1px solid #e5eaf2}.main{padding:22px 16px 50px}.sections{grid-template-columns:1fr}}@media(max-width:560px){.stats{grid-template-columns:1fr 1fr}.grid2{grid-template-columns:1fr}.hero{align-items:flex-start}.avatar{width:62px;height:62px;font-size:30px}.hero h2{font-size:19px}}
</style></head><body><div class="mobile-head"><strong>BrightFuture</strong><a href="<?= e(app_url($home)) ?>">← Tổng quan</a></div><div class="layout"><aside class="side"><a class="brand" href="<?= e(app_url()) ?>">&lt;/&gt; BrightFuture</a><a class="nav" href="<?= e(app_url($home)) ?>">▦ Tổng quan</a><?php if($role===ROLE_STUDENT): ?><a class="nav" href="<?= e(app_url('courses.php')) ?>">▤ Khám phá khóa học</a><a class="nav" href="<?= e(app_url('student/my-courses.php')) ?>">▥ Khóa học của tôi</a><a class="nav" href="<?= e(app_url('student/progress.php')) ?>">◴ Tiến độ học tập</a><?php elseif($role===ROLE_TEACHER): ?><a class="nav" href="<?= e(app_url('teacher/courses.php')) ?>">▤ Quản lý khóa học</a><a class="nav" href="<?= e(app_url('teacher/exercises.php')) ?>">▥ Quản lý bài tập</a><a class="nav" href="<?= e(app_url('teacher/submissions.php')) ?>">✓ Chấm bài</a><?php else: ?><a class="nav" href="<?= e(app_url('admin/users.php')) ?>">▤ Quản lý người dùng</a><?php endif; ?><a class="nav" href="<?= e(app_url('social.php')) ?>">♧ Bạn bè & tin nhắn</a><a class="nav" href="<?= e(app_url('rooms.php')) ?>">◉ Phòng gọi nhóm</a><?php if($role===ROLE_STUDENT): ?><a class="nav" href="<?= e(app_url('certificate.php')) ?>">✧ Chứng chỉ</a><?php endif; ?><a class="nav active" href="<?= e(app_url('profile.php')) ?>">☺ Hồ sơ của tôi</a><div class="foot"><strong><?= e((string)$user['full_name']) ?></strong><small><?= e($roleName) ?></small><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="logout">↪ Đăng xuất</button></form></div></aside><main class="main"><div class="crumb"><a href="<?= e(app_url($home)) ?>">Tổng quan</a> / Hồ sơ cá nhân</div><h1>Hồ sơ của tôi</h1><div class="muted">Quản lý thông tin cá nhân và bảo mật tài khoản.</div><div class="hero"><div class="avatar"><?= e($initial ?: 'U') ?></div><div><h2><?= e((string)$user['full_name']) ?></h2><div class="muted"><?= e((string)$user['email']) ?></div><p style="margin:8px 0 0"><strong><?= e($roleName) ?></strong> · BrightFuture</p></div></div>
<?php if($flash): ?><div class="banner <?= ($flash['type']??'')==='success'?'ok':'' ?>"><?= e((string)($flash['message']??'')) ?></div><?php endif; ?><?php foreach($errors as $error): ?><div class="banner"><?= e($error) ?></div><?php endforeach; ?>
<?php if($stats): ?><div class="stats"><?php foreach($statLabels as $key=>$title): ?><div class="stat"><div class="n"><?= number_format((int)($stats[$key]??0)) ?></div><div class="label"><?= e($title) ?></div></div><?php endforeach; ?></div><?php endif; ?>
<div class="sections"><div><section class="panel"><h2>Thông tin cá nhân</h2><p class="subtitle">Chỉ chỉnh sửa thông tin của tài khoản đang đăng nhập.</p><form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><div class="grid2"><div class="field"><label for="name">Họ và tên *</label><input id="name" name="full_name" required maxlength="120" value="<?= e((string)$user['full_name']) ?>"></div><div class="field"><label for="email">Email *</label><input id="email" name="email" type="email" required maxlength="190" value="<?= e((string)$user['email']) ?>"></div></div><?php if($table!==null): ?><div class="field"><label for="phone">Số điện thoại</label><input id="phone" name="phone" type="tel" maxlength="20" value="<?= e((string)($profile['phone']??'')) ?>"></div><?php endif; ?><?php if($role===ROLE_STUDENT): ?><div class="grid2"><div class="field"><label for="dob">Ngày sinh</label><input type="date" name="date_of_birth" id="dob" value="<?= e((string)($profile['date_of_birth']??'')) ?>"></div><div class="field"><label for="school">Trường / cơ sở học tập</label><input id="school" name="school" maxlength="200" value="<?= e((string)($profile['school']??'')) ?>"></div></div><div class="field"><label for="goal">Mục tiêu học tập</label><textarea name="learning_goal" id="goal" maxlength="2000" placeholder="Bạn muốn đạt được gì sau các khóa học?"><?= e((string)($profile['learning_goal']??'')) ?></textarea></div><?php elseif($role===ROLE_TEACHER): ?><div class="field"><label for="special">Chuyên môn giảng dạy</label><input id="special" name="specialization" maxlength="200" placeholder="VD: Lập trình Python, Web, AI..." value="<?= e((string)($profile['specialization']??'')) ?>"></div><div class="grid2"><div class="field"><label for="org">Đơn vị công tác</label><input id="org" name="organization" maxlength="200" value="<?= e((string)($profile['organization']??'')) ?>"></div><div class="field"><label for="years">Số năm kinh nghiệm</label><input type="number" min="0" max="80" id="years" name="years_experience" value="<?= e((string)($profile['years_experience']??'')) ?>"></div></div><?php endif; ?><?php if($table!==null): ?><div class="field"><label for="bio">Giới thiệu bản thân</label><textarea name="bio" id="bio" maxlength="2000" placeholder="Giới thiệu ngắn về bản thân..."><?= e((string)($profile['bio']??'')) ?></textarea></div><?php endif; ?><button class="btn" type="submit">Lưu thay đổi</button></form></section></div><div><section class="panel"><h2>Bảo mật tài khoản</h2><p class="subtitle">Đổi mật khẩu để bảo vệ tài khoản.</p><form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><div class="field"><label for="old">Mật khẩu hiện tại</label><input type="password" name="old_password" id="old" autocomplete="current-password" required></div><div class="field"><label for="new">Mật khẩu mới</label><input type="password" name="new_password" id="new" autocomplete="new-password" minlength="8" maxlength="128" required></div><div class="field"><label for="confirm">Xác nhận mật khẩu mới</label><input type="password" name="confirm_password" id="confirm" autocomplete="new-password" minlength="8" maxlength="128" required></div><p class="password-hint">Tối thiểu 8 ký tự; không chia sẻ mật khẩu với người khác.</p><button class="btn" type="submit">Đổi mật khẩu</button></form></section><section class="panel"><h2>Truy cập nhanh</h2><p><a href="<?= e(app_url('social.php')) ?>">♧ Cộng đồng / Tin nhắn →</a></p><p><a href="<?= e(app_url('rooms.php')) ?>">◉ Phòng gọi nhóm →</a></p><a href="<?= e(app_url($home)) ?>">← Về trang tổng quan</a><?php if($role===ROLE_STUDENT): ?><p><a href="<?= e(app_url('student/my-courses.php')) ?>">Khóa học đã đăng ký →</a></p><?php elseif($role===ROLE_TEACHER): ?><p><a href="<?= e(app_url('teacher/courses.php')) ?>">Quản lý khóa học →</a></p><?php endif; ?></section></div></div></main></div></body></html>
