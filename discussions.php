<?php
/** Lesson discussion: only enrolled students, course owners, and administrators. */
require_once __DIR__.'/includes/portal_ui.php';
$user=require_login();$uid=(int)$user['id'];$lid=positive_int($_GET['lesson']??$_POST['lesson']??0);$pdo=db();$errors=[];
if(!$lid){http_response_code(400);exit('Thiếu mã bài học.');}
$s=$pdo->prepare('SELECT l.id,l.title,l.status,c.id AS course_id,c.teacher_id,c.title AS course_title,c.status AS course_status FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id JOIN courses c ON c.id=ch.course_id WHERE l.id=?');$s->execute([$lid]);$lesson=$s->fetch();
if(!$lesson){http_response_code(404);exit('Không tìm thấy bài học.');}
$isOwner=$user['role']==='teacher'&&(int)$lesson['teacher_id']===$uid;$isAdmin=$user['role']==='admin';
$isStudent=false;
if($user['role']==='student'&&$lesson['status']==='published'&&$lesson['course_status']==='published'){
 $s=$pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND status IN ("active","completed")');$s->execute([$uid,(int)$lesson['course_id']]);$isStudent=(bool)$s->fetchColumn();
}
if(!$isOwner&&!$isAdmin&&!$isStudent){http_response_code(403);exit('Bạn không có quyền thảo luận trong bài học này.');}
if(is_post()){
 require_csrf();$body=trim((string)($_POST['body']??''));
 if($body===''||bf_social_len($body)>4000)$errors[]='Nội dung cần có từ 1 đến 4.000 ký tự.';
 else{try{$s=$pdo->prepare('INSERT INTO bf_lesson_discussions(lesson_id,author_id,body) VALUES (?,?,?)');$s->execute([$lid,$uid,$body]);redirect('discussions.php?lesson='.$lid,303);}catch(Throwable $ex){$errors[]='Không lưu được thảo luận. Kiểm tra bảng bf_lesson_discussions.';}}
}
$rows=[];
try{$s=$pdo->prepare('SELECT d.id,d.body,d.created_at,u.full_name,u.role FROM bf_lesson_discussions d JOIN users u ON u.id=d.author_id WHERE d.lesson_id=? ORDER BY d.id DESC LIMIT 100');$s->execute([$lid]);$rows=$s->fetchAll();}catch(Throwable $ex){$errors[]='Chưa có bảng thảo luận bài học. Hãy nhập SQL bổ sung.';}
bf_portal_begin($user,'courses','Thảo luận bài học');
?>
<header class="bf-header"><div><h1>Thảo luận: <?=e($lesson['title'])?></h1><p><?=e($lesson['course_title'])?> · Hỏi đáp và chia sẻ trong lớp học.</p></div><a class="bf-muted" href="<?=e(app_url($user['role']==='student'?'student/learn.php?id='.$lid:'teacher/lessons.php?course_id='.(int)$lesson['course_id']))?>">← Trở về bài học</a></header>
<?php foreach($errors as $error):?><div class="bf-note bf-error"><?=e($error)?></div><?php endforeach?>
<section class="bf-panel" style="margin-bottom:20px"><h2>Đăng câu hỏi / trao đổi</h2><form method="post"><?=csrf_field()?><input type="hidden" name="lesson" value="<?=$lid?>"><label class="bf-field" for="discussionBody">Nội dung thảo luận</label><textarea class="bf-input" id="discussionBody" name="body" rows="4" maxlength="4000" required placeholder="Bạn muốn trao đổi điều gì về bài học này?"></textarea><div style="margin-top:13px"><button type="submit" class="bf-btn">Gửi thảo luận</button></div></form></section>
<section class="bf-panel"><h2>Bài viết gần nhất (<?=count($rows)?>)</h2><?php if(!$rows):?><p class="bf-subtle">Chưa có trao đổi nào. Hãy đặt câu hỏi đầu tiên.</p><?php endif?><?php foreach($rows as $r):?><article class="bf-row" style="display:block"><div class="bf-actions"><strong><?=e($r['full_name'])?></strong><span class="bf-chip"><?=e(bf_social_role($r['role']))?></span><small><?=e($r['created_at'])?></small></div><p style="white-space:pre-wrap;overflow-wrap:anywhere"><?=e($r['body'])?></p></article><?php endforeach?></section>
<?php bf_portal_end(); ?>
