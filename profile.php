<?php
/**
 * BrightFuture - Ho so ca nhan Student / Teacher / Admin
 * PHP >= 8.0; PDO; CSRF; no dependency beyond existing includes.
 */
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/portal_ui.php';
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
        } elseif ($action === 'totp_setup') {
            $_SESSION['totp_pending_secret'] = bf_totp_generate_secret();
            set_flash('info','Đã tạo mã thiết lập xác thực hai bước.');
            redirect('profile.php');
        } elseif ($action === 'totp_confirm') {
            $secret = (string) ($_SESSION['totp_pending_secret'] ?? '');
            $code = clean_text((string) ($_POST['totp_code'] ?? ''));
            if ($secret === '' || bf_totp_base32_decode($secret) === null) {
                $errors[]='Phiên thiết lập xác thực đã hết hạn. Hãy tạo mã mới.';
            } elseif (!bf_totp_verify($secret, $code)) {
                $errors[]='Mã xác thực không đúng. Kiểm tra thời gian trên điện thoại.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO bf_totp_secrets (user_id, secret_base32)
                     VALUES (:user_id, :secret)
                     ON DUPLICATE KEY UPDATE secret_base32 = VALUES(secret_base32)'
                );
                $stmt->execute(['user_id'=>$uid,'secret'=>$secret]);
                unset($_SESSION['totp_pending_secret']);
                $_SESSION['totp_backup_codes'] = bf_totp_create_backup_codes($uid);
                bf_audit_log('totp.enable','user',$uid);
                set_flash('success','Đã bật xác thực hai bước.');
                redirect('profile.php');
            }
        } elseif ($action === 'totp_disable') {
            $password=(string)($_POST['old_password']??'');
            $q=db()->prepare('SELECT password_hash FROM users WHERE id=:id');
            $q->execute(['id'=>$uid]);
            $hash=$q->fetchColumn();
            if (!is_string($hash) || !password_verify($password,$hash)) {
                $errors[]='Mật khẩu hiện tại không đúng.';
            } else {
                $stmt=db()->prepare('DELETE FROM bf_totp_secrets WHERE user_id=:id');
                $stmt->execute(['id'=>$uid]);
                unset($_SESSION['totp_pending_secret']);
                unset($_SESSION['totp_backup_codes']);
                $pdo = db();
                $pdo->prepare('DELETE FROM bf_totp_backup_codes WHERE user_id = :id')
                    ->execute(['id' => $uid]);
                bf_audit_log('totp.disable','user',$uid);
                set_flash('success','Đã tắt xác thực hai bước.');
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
$totpEnabled = bf_totp_is_enabled($uid);
$totpPendingSecret = (string) ($_SESSION['totp_pending_secret'] ?? '');
$totpBackupCodes = is_array($_SESSION['totp_backup_codes'] ?? null)
    ? array_values($_SESSION['totp_backup_codes'])
    : [];
unset($_SESSION['totp_backup_codes']);

$pageTitle = 'Hồ sơ của tôi';
$pageDescription = 'Quản lý thông tin cá nhân và bảo mật tài khoản BrightFuture';
$activeMenu = 'profile';

require_once __DIR__ . '/views/layouts/portal_head.php';
?>
<link rel="stylesheet" href="<?= e(app_url('css/bf-profile.css')) ?>">
<?= bf_unified_sidebar($user, 'profile') ?>
<?php require_once __DIR__ . '/views/layouts/portal_topbar.php'; ?>
<main class="content profile-content">
<div class="crumb"><a href="<?= e(app_url($home)) ?>">Tổng quan</a> / Hồ sơ cá nhân</div>
<h1 class="profile-title">Hồ sơ của tôi</h1>
<div class="muted">Quản lý thông tin cá nhân và bảo mật tài khoản.</div>
<div class="hero"><div class="avatar"><?= e($initial ?: 'U') ?></div><div><h2><?= e((string)$user['full_name']) ?></h2><div class="muted"><?= e((string)$user['email']) ?></div><p style="margin:8px 0 0"><strong><?= e($roleName) ?></strong> · BrightFuture</p></div></div>
<?php if($flash): ?><div class="banner <?= ($flash['type']??'')==='success'?'ok':'' ?>"><?= e((string)($flash['message']??'')) ?></div><?php endif; ?><?php foreach($errors as $error): ?><div class="banner"><?= e($error) ?></div><?php endforeach; ?>
<?php if($stats): ?><div class="stats"><?php foreach($statLabels as $key=>$title): ?><div class="stat"><div class="n"><?= number_format((int)($stats[$key]??0)) ?></div><div class="label"><?= e($title) ?></div></div><?php endforeach; ?></div><?php endif; ?>
<div class="sections"><div><section class="panel"><h2>Thông tin cá nhân</h2><p class="subtitle">Chỉ chỉnh sửa thông tin của tài khoản đang đăng nhập.</p><form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><div class="grid2"><div class="field"><label for="name">Họ và tên *</label><input id="name" name="full_name" required maxlength="120" value="<?= e((string)$user['full_name']) ?>"></div><div class="field"><label for="email">Email *</label><input id="email" name="email" type="email" required maxlength="190" value="<?= e((string)$user['email']) ?>"></div></div><?php if($table!==null): ?><div class="field"><label for="phone">Số điện thoại</label><input id="phone" name="phone" type="tel" maxlength="20" value="<?= e((string)($profile['phone']??'')) ?>"></div><?php endif; ?><?php if($role===ROLE_STUDENT): ?><div class="grid2"><div class="field"><label for="dob">Ngày sinh</label><input type="date" name="date_of_birth" id="dob" value="<?= e((string)($profile['date_of_birth']??'')) ?>"></div><div class="field"><label for="school">Trường / cơ sở học tập</label><input id="school" name="school" maxlength="200" value="<?= e((string)($profile['school']??'')) ?>"></div></div><div class="field"><label for="goal">Mục tiêu học tập</label><textarea name="learning_goal" id="goal" maxlength="2000" placeholder="Bạn muốn đạt được gì sau các khóa học?"><?= e((string)($profile['learning_goal']??'')) ?></textarea></div><?php elseif($role===ROLE_TEACHER): ?><div class="field"><label for="special">Chuyên môn giảng dạy</label><input id="special" name="specialization" maxlength="200" placeholder="VD: Lập trình Python, Web, AI..." value="<?= e((string)($profile['specialization']??'')) ?>"></div><div class="grid2"><div class="field"><label for="org">Đơn vị công tác</label><input id="org" name="organization" maxlength="200" value="<?= e((string)($profile['organization']??'')) ?>"></div><div class="field"><label for="years">Số năm kinh nghiệm</label><input type="number" min="0" max="80" id="years" name="years_experience" value="<?= e((string)($profile['years_experience']??'')) ?>"></div></div><?php endif; ?><?php if($table!==null): ?><div class="field"><label for="bio">Giới thiệu bản thân</label><textarea name="bio" id="bio" maxlength="2000" placeholder="Giới thiệu ngắn về bản thân..."><?= e((string)($profile['bio']??'')) ?></textarea></div><?php endif; ?><button class="btn" type="submit">Lưu thay đổi</button></form></section></div><div><section class="panel"><h2>Bảo mật tài khoản</h2><p class="subtitle">Đổi mật khẩu định kỳ để bảo vệ tài khoản.</p><form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="change_password">
<div class="field"><label for="old">Mật khẩu hiện tại</label>
<div class="bf-pwd-wrap">
    <input type="password" name="old_password" id="old" autocomplete="current-password" required>
    <button type="button" class="bf-pwd-toggle" data-target="old" aria-label="Hiện mật khẩu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
</div>
</div>
<div class="field"><label for="new">Mật khẩu mới</label>
<div class="bf-pwd-wrap">
    <input type="password" name="new_password" id="new" autocomplete="new-password" minlength="8" maxlength="128" required>
    <button type="button" class="bf-pwd-toggle" data-target="new" aria-label="Hiện mật khẩu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
</div>
<div class="bf-pwd-meter">
    <div class="bf-pwd-meter-bar"><div class="bf-pwd-meter-fill" id="meterFill"></div></div>
    <div class="bf-pwd-meter-text"><span id="meterText">Độ mạnh: Chưa nhập</span><span>Chuẩn NIST</span></div>
    <div class="bf-pwd-criteria">
        <div class="bf-pwd-crit-item" id="critLen">● Tối thiểu 8 ký tự</div>
        <div class="bf-pwd-crit-item" id="critUpper">● Có chữ hoa (A-Z)</div>
        <div class="bf-pwd-crit-item" id="critNum">● Có chữ số (0-9)</div>
        <div class="bf-pwd-crit-item" id="critSpecial">● Ký tự đặc biệt (!@#$)</div>
    </div>
</div>
</div>
<div class="field"><label for="confirm">Xác nhận mật khẩu mới</label>
<div class="bf-pwd-wrap">
    <input type="password" name="confirm_password" id="confirm" autocomplete="new-password" minlength="8" maxlength="128" required>
    <button type="button" class="bf-pwd-toggle" data-target="confirm" aria-label="Hiện mật khẩu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
</div>
<div id="matchHint" style="font-size: 12px; margin-top: 5px; font-weight: 600;"></div>
</div>
<button class="btn" type="submit" style="margin-top: 6px;">Đổi mật khẩu</button></form></section>
<section class="panel"><h2>Xác thực hai bước (2FA)</h2>
<?php if($totpBackupCodes): ?>
<div class="bf-backup-box" role="status">
    <div style="display: flex; align-items: center; gap: 8px; color: #166534; font-weight: 700; margin-bottom: 8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>Lưu các mã dự phòng sau ở nơi an toàn:</span>
    </div>
    <p style="font-size: 12px; color: #64748b; margin: 0 0 10px;">Mỗi mã chỉ được sử dụng một lần nếu bạn mất điện thoại hoặc không thể mở ứng dụng Authenticator.</p>
    <div class="bf-backup-grid">
        <?php foreach($totpBackupCodes as $backupCode): ?>
            <div class="bf-backup-code"><?= e((string)$backupCode) ?></div>
        <?php endforeach; ?>
    </div>
    <div style="display: flex; gap: 8px; margin-top: 10px;">
        <button type="button" class="btn btn-secondary" id="copyBackupCodes" style="font-size: 12px; padding: 7px 14px;">📋 Sao chép mã</button>
        <button type="button" class="btn btn-secondary" id="downloadBackupCodes" style="font-size: 12px; padding: 7px 14px;">💾 Tải file .txt</button>
    </div>
</div>
<?php endif; ?>
<p class="subtitle">Bảo vệ tài khoản bằng mã xác thực 6 số từ Google Authenticator hoặc Authy.</p>
<?php if($totpEnabled): ?>
<p class="password-hint" style="color: #16a34a; font-weight: 600; margin-bottom: 12px;">✓ Xác thực hai bước đang được bật trên tài khoản của bạn.</p>
<form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="totp_disable"><div class="field"><label for="totp-password">Mật khẩu hiện tại</label><input type="password" name="old_password" id="totp-password" autocomplete="current-password" required></div><button class="btn" type="submit" style="background: #dc2626;">Tắt xác thực hai bước</button></form>
<?php elseif($totpPendingSecret!==''): ?>
<p class="password-hint">Thêm khóa sau vào ứng dụng Authenticator:<br><strong style="font-size: 15px; color: #4338ca; letter-spacing: 1px;"><?= e($totpPendingSecret) ?></strong></p>
<form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="totp_confirm"><div class="field"><label for="totp-code">Mã xác thực 6 số</label><input id="totp-code" name="totp_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required placeholder="000000"></div><button class="btn" type="submit">Xác nhận và bật 2FA</button></form>
<?php else: ?>
<form method="post" action="<?= e(app_url('profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="totp_setup"><button class="btn" type="submit">Thiết lập xác thực hai bước</button></form>
<?php endif; ?>
</section>
<section class="panel"><h2>Truy cập nhanh</h2>
<p><a href="<?= e(app_url('social.php')) ?>" style="color: #4f46e5; text-decoration: none; font-weight: 500;">♧ Cộng đồng & Tin nhắn →</a></p>
<p><a href="<?= e(app_url('rooms.php')) ?>" style="color: #4f46e5; text-decoration: none; font-weight: 500;">◉ Phòng gọi nhóm WebRTC →</a></p>
<p><a href="<?= e(app_url($home)) ?>" style="color: #64748b; text-decoration: none;">← Về trang tổng quan</a></p>
<?php if($role===ROLE_STUDENT): ?>
<p><a href="<?= e(app_url('student/my-courses.php')) ?>" style="color: #4f46e5; text-decoration: none; font-weight: 500;">Khóa học đã đăng ký →</a></p>
<?php elseif($role===ROLE_TEACHER): ?>
<p><a href="<?= e(app_url('teacher/courses.php')) ?>" style="color: #4f46e5; text-decoration: none; font-weight: 500;">Quản lý khóa học →</a></p>
<?php endif; ?>
</section></div></div></main>
<script src="<?= e(app_url('js/bf-profile.js')) ?>" defer></script>
<?php require_once __DIR__ . '/views/layouts/portal_foot.php'; ?>
