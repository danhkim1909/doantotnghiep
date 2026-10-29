<?php
/** Verifiable course-completion certificate (printable HTML/PDF via browser). */
require_once __DIR__.'/includes/portal_ui.php';
$pdo=db();$verification=strtoupper(trim(get_query('verify')));$errors=[];$public=null;
try{
 if(preg_match('/^[A-F0-9]{32}$/',$verification)){
  $s=$pdo->prepare('SELECT cert.verification_code,cert.issued_at,u.full_name,c.title AS course_title FROM bf_certificates cert JOIN users u ON u.id=cert.student_id JOIN courses c ON c.id=cert.course_id WHERE cert.verification_code=?');$s->execute([$verification]);$public=$s->fetch()?:null;
 }
}catch(Throwable $ex){$errors[]='Không thể tra cứu chứng chỉ. Hãy kiểm tra SQL cập nhật.';}
if($verification!==''){
 ?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Xác thực chứng chỉ | BrightFuture</title><link rel="stylesheet" href="<?=e(app_url('css/bf-portal.css'))?>"></head><body><main style="max-width:750px;margin:70px auto;padding:20px"><section class="bf-panel"><h1>Xác minh chứng chỉ BrightFuture</h1><?php if($public):?><div class="bf-note bf-success">Chứng chỉ hợp lệ, được phát hành bởi hệ thống.</div><p>Học viên: <strong><?=e($public['full_name'])?></strong></p><p>Khóa học: <strong><?=e($public['course_title'])?></strong></p><p>Ngày cấp: <?=e($public['issued_at'])?></p><p>Mã xác minh: <?=e($public['verification_code'])?></p><?php else:?><div class="bf-note bf-error">Không tìm thấy chứng chỉ với mã đã nhập.</div><?php endif?><a class="bf-btn" href="<?=e(app_url('courses.php'))?>">Đến trang khóa học</a></section></main></body></html><?php exit;
}
$user=require_role(ROLE_STUDENT);$uid=(int)$user['id'];$course=positive_int($_GET['course']??$_POST['course']??0);
if(is_post()){
 require_csrf();try{
  $cid=positive_int($_POST['course']??0);if(!$cid)throw new DomainException('Khóa học không hợp lệ.');
  $s=$pdo->prepare('SELECT 1 FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.student_id=? AND e.course_id=? AND e.status IN ("active","completed") AND c.status="published"');$s->execute([$uid,$cid]);if(!$s->fetchColumn())throw new DomainException('Bạn chưa đăng ký khóa học này.');
  $s=$pdo->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN lp.status="completed" THEN 1 ELSE 0 END),0) AS done FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=? WHERE ch.course_id=? AND l.status="published"');$s->execute([$uid,$cid]);$p=$s->fetch();
  if((int)$p['total']===0||(int)$p['done']!==(int)$p['total'])throw new DomainException('Bạn cần hoàn thành tất cả bài học công khai để nhận chứng chỉ.');
  $pdo->prepare('INSERT IGNORE INTO bf_certificates(student_id,course_id,verification_code) VALUES (?,?,?)')->execute([$uid,$cid,strtoupper(bin2hex(random_bytes(16)))]);
  redirect('certificate.php?course='.$cid,303);
 }catch(DomainException $ex){$errors[]=$ex->getMessage();}catch(Throwable $ex){$errors[]='Không thể cấp chứng chỉ. Kiểm tra SQL cập nhật.';}
}
$courses=[];
try{
 $s=$pdo->prepare('SELECT c.id,c.title,cert.verification_code,cert.issued_at,(SELECT COUNT(*) FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id WHERE ch.course_id=c.id AND l.status="published") AS total,(SELECT COUNT(*) FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=? AND lp.status="completed" WHERE ch.course_id=c.id AND l.status="published") AS done FROM enrollments en JOIN courses c ON c.id=en.course_id LEFT JOIN bf_certificates cert ON cert.student_id=en.student_id AND cert.course_id=c.id WHERE en.student_id=? AND en.status IN ("active","completed") ORDER BY en.enrolled_at DESC');$s->execute([$uid,$uid]);$courses=$s->fetchAll();
}catch(Throwable $ex){$errors[]='Không tải được chứng chỉ. Hãy nhập SQL cập nhật.';}
$printCourse=null;foreach($courses as $c){if((int)$c['id']===$course&&$c['verification_code'])$printCourse=$c;}
bf_portal_begin($user,'courses','Chứng chỉ học tập');
?>
<style>@media print{.bf-rail,.bf-top,.bf-header,.bf-list,.bf-actions,.bf-note{display:none!important}.bf-main{margin:0!important}.bf-container{padding:0!important}.bf-cert{border:8px double #4338ca!important;margin:3vh auto;padding:65px!important;box-shadow:none!important;min-height:90vh;max-width:none!important}.bf-cert h1{font-size:45px!important}.bf-cert h2{font-size:33px!important}}</style>
<header class="bf-header"><div><h1>Chứng chỉ hoàn thành khóa học</h1><p>Hoàn thành toàn bộ bài giảng công khai và đạt yêu cầu trắc nghiệm để được cấp chứng chỉ.</p></div><a class="bf-muted" href="<?=e(app_url('student/progress.php'))?>">← Tiến độ học tập</a></header>
<?php foreach($errors as $error):?><div class="bf-note bf-error"><?=e($error)?></div><?php endforeach?>
<?php if($printCourse):?><section class="bf-panel bf-cert" style="text-align:center;padding:50px;max-width:1000px;margin:20px auto;border:7px double #4338ca"><p style="font-size:25px;color:#4338ca;font-weight:850">BRIGHTFUTURE</p><h1 style="font-size:38px;letter-spacing:.05em">CHỨNG CHỈ HOÀN THÀNH</h1><p>Xác nhận học viên</p><h2 style="font-size:30px"><?=e($user['full_name'])?></h2><p>đã hoàn thành khóa học</p><h2><?=e($printCourse['title'])?></h2><p>Cấp ngày: <?=e($printCourse['issued_at'])?></p><p>Mã xác minh: <strong><?=e($printCourse['verification_code'])?></strong></p><p>Xem tại: <?=e(app_url('certificate.php?verify='.$printCourse['verification_code']))?></p><div class="bf-actions" style="justify-content:center"><button type="button" class="bf-btn" onclick="window.print()">In / Lưu PDF</button><a class="bf-muted" href="<?=e(app_url('certificate.php'))?>">Trở về danh sách</a></div></section><?php endif?>
<section class="bf-panel bf-list"><h2>Khóa học đã đăng ký</h2><?php foreach($courses as $c):?><div class="bf-row"><div><strong><?=e($c['title'])?></strong><small>Hoàn thành <?=(int)$c['done']?> / <?=(int)$c['total']?> bài học</small></div><div class="bf-actions"><?php if($c['verification_code']):?><a class="bf-btn" href="?course=<?=(int)$c['id']?>">Xem chứng chỉ</a><a class="bf-muted" href="?verify=<?=e($c['verification_code'])?>">Xác minh</a><?php elseif((int)$c['total']>0&&(int)$c['done']===(int)$c['total']):?><form method="post"><?=csrf_field()?><input type="hidden" name="course" value="<?=(int)$c['id']?>"><button class="bf-btn">Nhận chứng chỉ</button></form><?php else:?><span class="bf-chip">Chưa đủ điều kiện</span><?php endif?></div></div><?php endforeach?><?php if(!$courses):?><div class="bf-empty">Bạn chưa đăng ký khóa học nào.</div><?php endif?></section>
<?php bf_portal_end(); ?>
