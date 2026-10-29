<?php
/** BrightFuture - Bao cao quan tri. PHP 8+, MySQL 8+. */
require_once __DIR__ . '/../includes/permissions.php';
$admin = require_role(ROLE_ADMIN);
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

// Khoang ngay loc theo thoi diem phat sinh su kien. Mac dinh 30 ngay.
$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('-29 days')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');
$fromInput = get_query('from', $defaultFrom);
$toInput = get_query('to', $defaultTo);
$errors = [];
function report_date(string $value): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}
$fromDate = report_date($fromInput);
$toDate = report_date($toInput);
if (!$fromDate || !$toDate || $fromDate > $toDate || $toDate > $today || $fromDate->diff($toDate)->days > 365) {
    $errors[] = 'Khoảng ngày không hợp lệ. Chọn từ ngày đến ngày, không vượt hôm nay và tối đa 366 ngày.';
    $fromDate = report_date($defaultFrom);
    $toDate = report_date($defaultTo);
}
$from = $fromDate->format('Y-m-d');
$to = $toDate->format('Y-m-d');
$fromTime = $from . ' 00:00:00';
$toExclusive = $toDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
$days = $fromDate->diff($toDate)->days + 1;
$monthly = $days > 45;

$overview = ['students'=>0,'teachers'=>0,'courses'=>0,'lessons'=>0,'exercises'=>0,'enrollments'=>0,'submissions'=>0,'graded'=>0,'completed_enrollments'=>0,'average_progress'=>null,'average_score'=>null];
$daily = [];
$topCourses = [];
$exerciseTypes = [];
$submissionStatuses = [];
$dataError = false;

try {
    $pdo = db();
    // Thong ke phat sinh TRONG khoang duoc chon, khong phai tong tich luy toan he thong.
    $queries = [
        'students' => ["SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at >= ? AND created_at < ?", 'created_at'],
        'teachers' => ["SELECT COUNT(*) FROM users WHERE role = 'teacher' AND created_at >= ? AND created_at < ?", 'created_at'],
        'courses' => ["SELECT COUNT(*) FROM courses WHERE created_at >= ? AND created_at < ?", 'created_at'],
        'lessons' => ["SELECT COUNT(*) FROM lessons WHERE created_at >= ? AND created_at < ?", 'created_at'],
        'exercises' => ["SELECT COUNT(*) FROM exercises WHERE created_at >= ? AND created_at < ?", 'created_at'],
        'enrollments' => ["SELECT COUNT(*) FROM enrollments WHERE enrolled_at >= ? AND enrolled_at < ?", 'enrolled_at'],
        'submissions' => ["SELECT COUNT(*) FROM exercise_submissions WHERE submitted_at >= ? AND submitted_at < ?", 'submitted_at'],
        'graded' => ["SELECT COUNT(*) FROM exercise_submissions WHERE status = 'graded' AND graded_at >= ? AND graded_at < ?", 'graded_at'],
        'completed_enrollments' => ["SELECT COUNT(*) FROM enrollments WHERE status = 'completed' AND completed_at >= ? AND completed_at < ?", 'completed_at'],
    ];
    foreach ($queries as $key => [$sql]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromTime,$toExclusive]);
        $overview[$key] = (int)$stmt->fetchColumn();
    }

    // Trung binh % bai hoc hoan thanh cua cac luot dang ky trong khoang.
    // VIEW tinh tien do hien tai, khong phai anh chup lich su tai thoi diem dang ky.
    $stmt = $pdo->prepare(
        "SELECT ROUND(AVG(COALESCE(p.progress_percent, 0)), 2)
           FROM enrollments e
           LEFT JOIN view_student_course_progress p ON p.student_id=e.student_id AND p.course_id=e.course_id
          WHERE e.enrolled_at >= ? AND e.enrolled_at < ? AND e.status IN ('active','completed')"
    );
    $stmt->execute([$fromTime,$toExclusive]);
    $value = $stmt->fetchColumn();
    $overview['average_progress'] = $value === null || $value === false ? null : (float)$value;

    // Diem trung binh cac bai duoc cham TRONG khoang, chi bao gom status graded.
    $stmt = $pdo->prepare(
        "SELECT ROUND(AVG(es.score),2) FROM exercise_submissions es
          WHERE es.status='graded' AND es.score IS NOT NULL
            AND es.graded_at >= ? AND es.graded_at < ?"
    );
    $stmt->execute([$fromTime,$toExclusive]);
    $value = $stmt->fetchColumn();
    $overview['average_score'] = $value === null || $value === false ? null : (float)$value;

    // Bieu do dang ky tai khoan va ghi danh theo ngay/thang.
    $exprUser = $monthly ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
    $exprEnroll = $monthly ? "DATE_FORMAT(enrolled_at, '%Y-%m')" : 'DATE(enrolled_at)';
    $stmt = $pdo->prepare("SELECT $exprUser AS period, COUNT(*) AS total FROM users WHERE created_at >= ? AND created_at < ? GROUP BY period ORDER BY period");
    $stmt->execute([$fromTime,$toExclusive]);
    $userSeries = [];
    foreach ($stmt->fetchAll() as $row) $userSeries[(string)$row['period']] = (int)$row['total'];
    $stmt = $pdo->prepare("SELECT $exprEnroll AS period, COUNT(*) AS total FROM enrollments WHERE enrolled_at >= ? AND enrolled_at < ? GROUP BY period ORDER BY period");
    $stmt->execute([$fromTime,$toExclusive]);
    $enrollSeries = [];
    foreach ($stmt->fetchAll() as $row) $enrollSeries[(string)$row['period']] = (int)$row['total'];
    $cursor = $monthly ? $fromDate->modify('first day of this month') : $fromDate;
    $last = $monthly ? $toDate->modify('first day of this month') : $toDate;
    while ($cursor <= $last) {
        $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
        $daily[] = ['period'=>$key, 'label'=>$cursor->format($monthly ? 'm/Y' : 'd/m'), 'users'=>$userSeries[$key] ?? 0,'enrollments'=>$enrollSeries[$key] ?? 0];
        $cursor = $cursor->modify($monthly ? '+1 month' : '+1 day');
    }

    // Top 10 khoa hoc co nhieu luot ghi danh trong khoang, tinh ca cancelled.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, u.full_name AS teacher_name,
                COUNT(e.id) AS enrollments,
                COUNT(DISTINCT CASE WHEN e.status IN ('active','completed') THEN e.student_id END) AS current_students
           FROM enrollments e
           JOIN courses c ON c.id=e.course_id
           JOIN users u ON u.id=c.teacher_id
          WHERE e.enrolled_at >= ? AND e.enrolled_at < ?
          GROUP BY c.id, c.title, u.full_name
          ORDER BY enrollments DESC, c.id DESC LIMIT 10"
    );
    $stmt->execute([$fromTime,$toExclusive]);
    $topCourses = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT exercise_type, COUNT(*) AS total FROM exercises
          WHERE created_at >= ? AND created_at < ? GROUP BY exercise_type ORDER BY total DESC"
    );
    $stmt->execute([$fromTime,$toExclusive]);
    $exerciseTypes = $stmt->fetchAll();

    // Trang thai HIEN TAI cua bai nop phat sinh trong khoang.
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total FROM exercise_submissions
          WHERE submitted_at >= ? AND submitted_at < ? GROUP BY status ORDER BY total DESC"
    );
    $stmt->execute([$fromTime,$toExclusive]);
    $submissionStatuses = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[BrightFuture Admin Reports] ' . $e->getMessage());
    $dataError = true;
    $overview = array_map(static fn($v) => is_null($v) ? null : 0, $overview);
    $daily = $topCourses = $exerciseTypes = $submissionStatuses = [];
}

function report_number(int|float|null $value, int $decimals = 0): string {
    return $value === null ? '—' : number_format($value, $decimals, ',', '.');
}
function report_status(string $status): string {
    return match ($status) {
        'submitted'=>'Chờ chấm', 'grading'=>'Đang chấm', 'graded'=>'Đã chấm', 'returned'=>'Trả bổ sung', default=>$status
    };
}
function report_exercise_type(string $type): string {
    return match ($type) {
        'quiz'=>'Trắc nghiệm', 'coding'=>'Lập trình', 'file_upload'=>'Nộp tệp', 'text'=>'Văn bản', default=>$type
    };
}
$chartJson = json_encode($daily, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Báo cáo & thống kê | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--purple:#7c3aed;--bg:#f6f8fc;--fg:#172033;--muted:#64748b;--line:#e7ebf2;--side:255px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font-family:'Segoe UI',Arial,sans-serif;font-size:14px}a{text-decoration:none;color:inherit}button,input{font:inherit}button{cursor:pointer}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--side);display:flex;flex-direction:column;background:#fff;border-right:1px solid var(--line);z-index:40;transition:transform .25s}
.brand{height:85px;display:flex;align-items:center;gap:12px;padding:0 23px;color:var(--primary);font-weight:800;font-size:21px}.brand-mark{background:linear-gradient(130deg,#4f46e5,#7c3aed);border-radius:12px;padding:12px;color:white;font-size:14px}
.nav{padding:10px 14px;flex:1;overflow:auto}.nav-label{font-size:11px;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;font-weight:700;padding:16px 13px 10px}.nav-link{display:flex;gap:12px;align-items:center;padding:13px 14px;border-radius:11px;margin-bottom:5px;color:var(--muted)}.nav-link:hover,.nav-link.active{background:#eef2ff;color:var(--primary)}.nav-link.active{font-weight:700}
.user-foot{padding:16px;border-top:1px solid var(--line)}.user-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-role{color:var(--muted);font-size:12px;margin:5px 0 14px}.logout{border:0;border-radius:10px;background:#fff1f2;color:#be123c;width:100%;padding:12px;font-weight:700}
.main{margin-left:var(--side)}.topbar{background:#fff;border-bottom:1px solid var(--line);height:78px;padding:0 34px;display:flex;align-items:center;justify-content:space-between}.top-title{font-weight:600;color:var(--muted)}.identity{display:flex;align-items:center;gap:12px;font-weight:600}.bubble{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:#e0e7ff;color:var(--primary)}.toggle{display:none;border:0;background:transparent;font-size:23px}.overlay{display:none}
.content{max-width:1520px;margin:auto;padding:30px 34px 70px}.crumb{color:var(--muted);font-size:13px;margin-bottom:17px}.crumb a:hover{color:var(--primary)}h1{font-size:29px;margin:0 0 8px;letter-spacing:-.6px}.intro{color:var(--muted);margin:0 0 25px;line-height:1.6}.panel{background:#fff;border:1px solid var(--line);border-radius:17px;padding:23px;min-width:0}.panel h2{margin:0 0 8px;font-size:18px}.note{font-size:12px;color:var(--muted);line-height:1.6}.filter{display:flex;align-items:flex-end;gap:13px;flex-wrap:wrap;margin-bottom:23px}.filter label{display:flex;flex-direction:column;gap:7px;font-weight:600;font-size:12px}.filter input{border:1px solid #dbe1eb;background:#fff;border-radius:10px;height:43px;padding:0 12px;min-width:160px}.btn{border:0;border-radius:10px;background:var(--primary);color:#fff;font-weight:700;padding:12px 19px;min-height:43px}.btn:hover{background:#4338ca}.reset{font-size:13px;color:var(--primary);font-weight:600;padding:11px}.alert{padding:15px 18px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:11px;margin-bottom:20px;line-height:1.6}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.metric{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;min-width:0}.metric .icon{display:inline-grid;place-items:center;background:#eef2ff;border-radius:11px;width:37px;height:37px;color:var(--primary);font-size:19px;margin-bottom:15px}.metric .label{color:var(--muted);font-size:12px;line-height:1.5}.metric strong{display:block;font-size:27px;margin-top:7px;letter-spacing:-.7px}.metric small{display:block;color:var(--muted);margin-top:8px;font-size:11px}
.twocol{display:grid;grid-template-columns:1.7fr 1fr;gap:19px;margin:0 0 22px}.chart-key{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin:12px 0}.dot{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;background:#4f46e5}.dot.other{background:#10b981}.chart-wrap{overflow-x:auto}.chart{width:100%;min-width:480px;height:290px;display:block}.empty{padding:27px 10px;text-align:center;color:var(--muted);line-height:1.6}.breakdown{margin:17px 0}.breakdown-title{display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-bottom:9px}.track{width:100%;height:8px;background:#edf0f6;border-radius:20px;overflow:hidden}.fill{height:100%;background:linear-gradient(90deg,#4f46e5,#8b5cf6);border-radius:20px}
.table-scroll{overflow-x:auto;margin-top:16px}table{width:100%;border-collapse:collapse;text-align:left;min-width:620px}th{background:#f8faff;color:var(--muted);font-size:12px;font-weight:700}th,td{padding:14px 13px;border-bottom:1px solid var(--line)}td{font-size:13px}td strong{display:block;margin-bottom:5px}.muted{color:var(--muted)}.number{text-align:right;white-space:nowrap}.small-cta{font-size:12px;color:var(--primary);font-weight:700}.stack{display:grid;gap:19px}.footnote{color:var(--muted);line-height:1.7;font-size:12px;margin-top:16px}
@media(max-width:1200px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.twocol{grid-template-columns:1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.toggle{display:block}.overlay.open{display:block;position:fixed;inset:0;z-index:30;background:#0f172a66}.topbar{height:70px;padding:0 22px}.content{padding:25px 22px}}
@media(max-width:580px){.content{padding:20px 15px 45px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric{padding:14px}.metric strong{font-size:23px}.topbar{padding:0 15px}.identity>span:first-child{display:none}h1{font-size:24px}.filter input{width:100%}.filter label{flex:1;min-width:130px}.panel{padding:16px}}
</style>
<link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head>
<body>
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar" aria-label="Điều hướng quản trị">
 <a class="brand" href="<?= e(app_url()) ?>"><span class="brand-mark">&lt;/&gt;</span>BrightFuture</a>
 <nav class="nav">
  <div class="nav-label">Quản trị</div>
  <a class="nav-link" href="<?= e(app_url('admin/dashboard.php')) ?>">▦ Tổng quan</a>
  <a class="nav-link" href="<?= e(app_url('admin/users.php')) ?>">♙ Người dùng</a>
  <a class="nav-link" href="<?= e(app_url('admin/courses.php')) ?>">▤ Khóa học</a>
  <a class="nav-link active" aria-current="page" href="<?= e(app_url('admin/reports.php')) ?>">▥ Báo cáo & thống kê</a>
  <div class="nav-label">Hệ thống</div>
  <a class="nav-link" href="<?= e(app_url()) ?>">⌂ Trang chủ</a>
 </nav>
 <div class="user-foot"><div class="user-name"><?= e($admin['full_name']) ?></div><div class="user-role">Quản trị viên</div>
  <form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">⇥ Đăng xuất</button></form>
 </div>
</aside>
<div class="main">
 <header class="topbar"><div style="display:flex;align-items:center;gap:14px"><button id="menu" class="toggle" type="button" aria-label="Mở menu" aria-expanded="false" aria-controls="sidebar">☰</button><span class="top-title">Không gian quản trị</span></div><div class="identity"><span><?= e($admin['full_name']) ?></span><div class="bubble">A</div></div></header>
 <main class="content">
  <div class="crumb"><a href="<?= e(app_url('admin/dashboard.php')) ?>">Tổng quan</a> / <strong>Báo cáo & thống kê</strong></div>
  <h1>Báo cáo & thống kê</h1>
  <p class="intro">Theo dõi tăng trưởng người dùng, hoạt động khóa học, đăng ký học và đánh giá bài tập từ dữ liệu MySQL.</p>
  <form class="panel filter" method="get" action="<?= e(app_url('admin/reports.php')) ?>">
   <label>Từ ngày<input type="date" name="from" required value="<?= e($from) ?>" max="<?= e($today->format('Y-m-d')) ?>"></label>
   <label>Đến ngày<input type="date" name="to" required value="<?= e($to) ?>" max="<?= e($today->format('Y-m-d')) ?>"></label>
   <button class="btn" type="submit">Áp dụng bộ lọc</button><a class="reset" href="<?= e(app_url('admin/reports.php')) ?>">Đặt lại</a>
   <div class="note" style="width:100%">Dữ liệu từ <?= e(format_date($from)) ?> đến <?= e(format_date($to)) ?> (<?= $days ?> ngày). Các chỉ số hoạt động được tính theo thời điểm phát sinh trong khoảng đã chọn.</div>
  </form>
  <?php foreach ($errors as $error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
  <?php if ($dataError): ?><div class="alert" role="alert">Không thể tải báo cáo. Kiểm tra MySQL và các bảng/View trong <code>schema.sql</code>, <code>phase2.sql</code>, <code>phase3.sql</code>. Không hiển thị số liệu chưa xác minh.</div><?php else: ?>
  <section class="metrics" aria-label="Chỉ số trong khoảng thời gian">
   <?php foreach ([
      ['♙','Học viên mới','students','Tài khoản student được tạo'],
      ['♧','Giáo viên mới','teachers','Tài khoản teacher được tạo'],
      ['▤','Khóa học mới','courses','Khóa học được tạo'],
      ['▦','Bài giảng mới','lessons','Bài học được tạo'],
      ['▣','Bài tập mới','exercises','Bài tập được tạo'],
      ['↗','Lượt đăng ký học','enrollments','Bao gồm cả lượt đã hủy'],
      ['✎','Bài nộp mới','submissions','Tính theo submitted_at'],
      ['✓','Bài được chấm','graded','Đã chấm, tính theo graded_at'],
   ] as [$icon,$label,$key,$hint]): ?>
    <article class="metric"><span class="icon"><?= e($icon) ?></span><div class="label"><?= e($label) ?></div><strong><?= report_number($overview[$key]) ?></strong><small><?= e($hint) ?></small></article>
   <?php endforeach; ?>
  </section>
  <div class="twocol">
   <section class="panel">
    <h2>Biến động tài khoản và đăng ký học</h2>
    <p class="note">Thống kê theo <?= $monthly ? 'tháng' : 'ngày' ?>; biểu đồ tự chuyển sang nhóm theo tháng khi khoảng lọc dài hơn 45 ngày.</p>
    <div class="chart-key"><span><i class="dot"></i> Tài khoản mới</span><span><i class="dot other"></i> Lượt đăng ký khóa học</span></div>
    <div class="chart-wrap"><svg id="trendChart" class="chart" viewBox="0 0 780 290" role="img" aria-label="Biểu đồ số tài khoản mới và lượt đăng ký học"></svg></div>
   </section>
   <section class="panel">
    <h2>Kết quả học tập</h2><p class="note">Các chỉ số sau dùng quy tắc thời gian được ghi rõ bên dưới.</p>
    <div class="stack" style="margin-top:20px">
     <div class="metric"><div class="label">Lượt hoàn thành khóa học trong kỳ</div><strong><?= report_number($overview['completed_enrollments']) ?></strong><small>Theo enrollments.completed_at</small></div>
     <div class="metric"><div class="label">Tiến độ trung bình hiện tại</div><strong><?= report_number($overview['average_progress'], 2) ?><?= $overview['average_progress'] !== null ? '%' : '' ?></strong><small>Của các ghi danh trong kỳ, trạng thái active/completed</small></div>
     <div class="metric"><div class="label">Điểm trung bình đã chấm</div><strong><?= report_number($overview['average_score'], 2) ?></strong><small>Điểm gốc, không quy đổi thang 10; chỉ bài chấm trong kỳ</small></div>
    </div>
   </section>
  </div>
  <div class="twocol">
   <section class="panel"><h2>Top 10 khóa học theo lượt ghi danh</h2><p class="note">Đếm những lượt đăng ký phát sinh trong khoảng lọc, kể cả đăng ký sau đó bị hủy.</p>
    <?php if (!$topCourses): ?><div class="empty">Chưa có lượt đăng ký khóa học trong khoảng thời gian này.</div><?php else: ?>
     <div class="table-scroll"><table><thead><tr><th>Khóa học</th><th>Giáo viên</th><th class="number">Ghi danh trong kỳ</th><th class="number">Còn hiệu lực</th></tr></thead><tbody>
     <?php foreach ($topCourses as $course): ?><tr><td><strong><?= e($course['title']) ?></strong><a class="small-cta" href="<?= e(app_url('admin/courses.php?q=' . rawurlencode((string)$course['title']))) ?>">Quản lý →</a></td><td><?= e($course['teacher_name']) ?></td><td class="number"><?= report_number((int)$course['enrollments']) ?></td><td class="number"><?= report_number((int)$course['current_students']) ?></td></tr><?php endforeach; ?>
     </tbody></table></div>
    <?php endif; ?>
   </section>
   <div class="stack">
    <section class="panel"><h2>Cơ cấu loại bài tập mới</h2><p class="note">Theo exercises.created_at trong kỳ</p>
     <?php $exerciseTotal = array_sum(array_map(static fn($r)=>(int)$r['total'],$exerciseTypes)); ?>
     <?php if (!$exerciseTypes): ?><div class="empty">Chưa có bài tập mới.</div><?php else: ?>
      <?php foreach ($exerciseTypes as $row): $pct=$exerciseTotal ? (int)round((int)$row['total']*100/$exerciseTotal) : 0; ?><div class="breakdown"><div class="breakdown-title"><span><?= e(report_exercise_type((string)$row['exercise_type'])) ?></span><strong><?= (int)$row['total'] ?> (<?= $pct ?>%)</strong></div><div class="track"><div class="fill" style="width:<?= $pct ?>%"></div></div></div><?php endforeach; ?>
     <?php endif; ?>
    </section>
    <section class="panel"><h2>Trạng thái bài nộp</h2><p class="note">Trạng thái hiện tại của các bài nộp trong kỳ</p>
     <?php $submissionTotal = array_sum(array_map(static fn($r)=>(int)$r['total'],$submissionStatuses)); ?>
     <?php if (!$submissionStatuses): ?><div class="empty">Chưa có bài nộp mới.</div><?php else: ?>
      <?php foreach ($submissionStatuses as $row): $pct=$submissionTotal ? (int)round((int)$row['total']*100/$submissionTotal) : 0; ?><div class="breakdown"><div class="breakdown-title"><span><?= e(report_status((string)$row['status'])) ?></span><strong><?= (int)$row['total'] ?> (<?= $pct ?>%)</strong></div><div class="track"><div class="fill" style="width:<?= $pct ?>%"></div></div></div><?php endforeach; ?>
     <?php endif; ?>
    </section>
   </div>
  </div>
  <p class="footnote">Lưu ý: Bảng <code>users</code> hiện không có lịch sử thay đổi vai trò; “học viên mới/giáo viên mới” phân loại theo vai trò hiện tại. Tiến độ được tính ở thời điểm mở báo cáo, không phải số liệu lịch sử. Điểm trung bình là điểm thô giữa các bài tập có thể khác thang điểm. Báo cáo không bao gồm các bản ghi đã bị xóa khỏi cơ sở dữ liệu.</p>
  <?php endif; ?>
 </main>
</div>
<script>
(() => {
 const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay'),button=document.getElementById('menu');
 function setOpen(open){sidebar.classList.toggle('open',open);overlay.classList.toggle('open',open);button.setAttribute('aria-expanded',String(open));button.setAttribute('aria-label',open?'Đóng menu':'Mở menu');}
 button.addEventListener('click',()=>setOpen(!sidebar.classList.contains('open')));overlay.addEventListener('click',()=>setOpen(false));document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
 window.addEventListener('resize',()=>{if(window.innerWidth>900)setOpen(false)});
 const series=<?= $chartJson ?>,svg=document.getElementById('trendChart');
 if(!svg)return;
 const ns='http://www.w3.org/2000/svg';
 function elem(tag,attrs,text){let el=document.createElementNS(ns,tag);for(const [key,value] of Object.entries(attrs))el.setAttribute(key,String(value));if(text!==undefined)el.textContent=String(text);svg.appendChild(el);return el;}
 const w=780,h=290,margin={l:46,r:15,t:15,b:45},plotW=w-margin.l-margin.r,plotH=h-margin.t-margin.b;
 const max=Math.max(1,...series.map(d=>Math.max(d.users,d.enrollments))),n=series.length;
 const step=Math.ceil(max/4),yMax=step*4;
 for(let i=0;i<=4;i++){const val=i*step,y=margin.t+plotH-(val/yMax)*plotH;elem('line',{x1:margin.l,x2:w-margin.r,y1:y,y2:y,stroke:'#e5eaf3'});elem('text',{x:margin.l-9,y:y+4,'text-anchor':'end',fill:'#64748b','font-size':11},val);}
 if(!n){elem('text',{x:w/2,y:h/2,'text-anchor':'middle',fill:'#64748b','font-size':14},'Chưa có dữ liệu');return;}
 const slot=plotW/n,barW=Math.max(1,Math.min(17,slot*.33));
 series.forEach((row,i)=>{const x=margin.l+i*slot+slot/2;[['users','#4f46e5',-barW],['enrollments','#10b981',0]].forEach(([key,color,dx])=>{const barH=Number(row[key])/yMax*plotH;const r=elem('rect',{x:x+dx,y:margin.t+plotH-barH,width:barW,height:Math.max(0,barH),fill:color,rx:2});const title=document.createElementNS(ns,'title');title.textContent=row.label+' — '+(key==='users'?'Tài khoản: ':'Ghi danh: ')+row[key];r.appendChild(title)});
 const interval=Math.max(1,Math.ceil(n/8));if(i%interval===0||i===n-1){elem('text',{x,y:h-18,'text-anchor':'middle',fill:'#64748b','font-size':10},row.label);}});
})();
</script>
<script>window.BF_LEGACY={role:'admin',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body>
</html>
