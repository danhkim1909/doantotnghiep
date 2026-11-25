<?php
/** BrightFuture | Teacher exercise & quiz management | PHP 8+ */

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/portal_ui.php';
require_once __DIR__ . '/../classes/Models/Exercise.php';

use BrightFuture\Models\Exercise;

$user = require_role(ROLE_TEACHER);
header('Cache-Control: no-store, private');
$pdo = db();
$exerciseModel = new Exercise($pdo);
$teacherId = (int)$user['id'];
$errors = [];
$editing = null;
$editingQuestions = [];
$form = [];

function te_h(?string $s): string { return e($s); }
function te_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function te_cut(string $s, int $n): string { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }
function te_redirect(string $message, int $courseId = 0): void {
    set_flash('success', $message);
    redirect('teacher/exercises.php' . ($courseId > 0 ? '?course_id=' . $courseId : ''));
}
/** PHP 8.0 compatible list check (array_is_list was added in PHP 8.1). */
function te_array_is_list(array $values): bool {
    $expected = 0;
    foreach ($values as $key => $_value) {
        if ($key !== $expected) return false;
        ++$expected;
    }
    return true;
}
function te_validate_questions(string $raw,float $maxScore,array &$errors): array {
    if (strlen($raw)>150000) {$errors[]='Dữ liệu trắc nghiệm vượt giới hạn.';return [];}
    try {$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException $ex){$errors[]='Dữ liệu câu hỏi không hợp lệ.';return [];}
    if (!is_array($data)||!te_array_is_list($data)||count($data)<1||count($data)>80){$errors[]='Bài trắc nghiệm cần từ 1 đến 80 câu hỏi.';return [];}
    $clean=[];$total=0.0;
    foreach($data as $idx=>$row){
        $n=$idx+1;
        if(!is_array($row)){$errors[]="Câu $n không hợp lệ.";continue;}
        $text=trim((string)($row['text']??''));$type=$row['type']??'single_choice';
        $pts=filter_var($row['points']??null,FILTER_VALIDATE_FLOAT);
        if($text===''||te_len($text)>4000){$errors[]="Câu $n: nội dung phải từ 1 đến 4000 ký tự.";continue;}
        if(!in_array($type,['single_choice','multiple_choice'],true)){$errors[]="Câu $n: loại câu hỏi không hợp lệ.";continue;}
        if($pts===false||!is_finite((float)$pts)||$pts<=0||$pts>100000||round((float)$pts,2)!==(float)$pts){$errors[]="Câu $n: điểm không hợp lệ (tối đa hai chữ số thập phân).";continue;}
        $options=$row['options']??null;
        if(!is_array($options)||!te_array_is_list($options)||count($options)<2||count($options)>8){$errors[]="Câu $n: cần 2–8 đáp án.";continue;}
        $validOptions=[];$correct=0;
        foreach($options as $oi=>$option){
            if(!is_array($option)){$errors[]="Câu $n: đáp án không hợp lệ.";continue;}
            $ot=trim((string)($option['text']??''));
            if($ot===''||te_len($ot)>2000){$errors[]="Câu $n: đáp án ".($oi+1).' phải từ 1 đến 2000 ký tự.';continue;}
            $isCorrect=($option['correct']??false)===true;
            if($isCorrect)$correct++;
            $validOptions[]=['text'=>$ot,'correct'=>$isCorrect];
        }
        if($correct<1||($type==='single_choice'&&$correct!==1)){$errors[]="Câu $n: chọn ".($type==='single_choice'?'đúng một':'ít nhất một').' đáp án đúng.';}
        $total+=(float)$pts;
        $clean[]=['text'=>$text,'type'=>$type,'points'=>(float)$pts,'options'=>$validOptions];
    }
    if($total>$maxScore+0.00001)$errors[]='Tổng điểm câu hỏi không được vượt điểm tối đa của bài tập.';
    return $clean;
}
$courseId = positive_int($_GET['course_id'] ?? null);
$presetLessonId = positive_int($_GET['lesson_id'] ?? null);
$editId = positive_int($_GET['edit'] ?? null);
if ($presetLessonId && !$editId) {
    $preset = $exerciseModel->getTeacherLesson($presetLessonId, $teacherId);
    if ($preset && (!$courseId || $courseId === (int)$preset['course_id'])) {
        $courseId=(int)$preset['course_id'];
        $form['lesson_id']=$presetLessonId;
        $form['exercise_type']='quiz';
        $form['status']='draft';
    }
}
if ($editId) {
    $editing = $exerciseModel->getTeacherExercise($editId, $teacherId);
    if(!$editing){http_response_code(404);exit('Không tìm thấy bài tập hoặc bạn không có quyền.');}
    $courseId=(int)$editing['course_id'];
    if($editing['exercise_type']==='quiz')$editingQuestions = $exerciseModel->getQuestions($editId);
}
if (is_post()) {
    require_csrf();
    $action=get_post('action');
    $id=positive_int($_POST['id']??null);
    if(in_array($action,['delete','toggle'],true)) {
        $existing = $exerciseModel->getTeacherExercise($id, $teacherId);
        if(!$existing){http_response_code(403);exit('Bạn không có quyền thao tác bài tập này.');}
        $courseId=(int)$existing['course_id'];
        if($action==='delete') {
            // Delete is blocked when student submissions already exist.
            if($exerciseModel->countExerciseSubmissions($id) > 0){
                $errors[]='Không thể xóa bài tập đã có bài nộp. Hãy chuyển về bản nháp.';
            } else {
                $exerciseModel->deleteTeacherExercise($id, $teacherId);
                te_redirect('Đã xóa bài tập.',$courseId);
            }
        } else {
            if ($existing['status'] === 'draft' && $existing['exercise_type'] === 'quiz') {
                $qCount = count($exerciseModel->getQuestions($id));
                if ($qCount < 1) {
                    set_flash('error', "Không thể công khai: Bài trắc nghiệm cần có ít nhất 1 câu hỏi trước khi mở cho học viên.");
                    redirect('teacher/exercises.php' . ($courseId > 0 ? '?course_id=' . $courseId : ''));
                }
            }
            $exerciseModel->toggleTeacherExerciseStatus($id, $teacherId);
            te_redirect('Đã cập nhật trạng thái bài tập.',$courseId);
        }
    } elseif ($action==='save') {
        $old=$id ? $exerciseModel->getTeacherExercise($id, $teacherId) : null;
        if($id&&!$old){http_response_code(403);exit('Bạn không có quyền sửa bài tập này.');}
        $lessonId=positive_int($_POST['lesson_id']??null);
        $lesson = $exerciseModel->getTeacherLesson($lessonId, $teacherId);
        $title=get_post('title');$description=get_post('description');
        $type=get_post('exercise_type');$lang=get_post('programming_language');
        $starter=isset($_POST['starter_code'])&&is_string($_POST['starter_code'])?$_POST['starter_code']:'';
        $maxRaw=$_POST['max_score']??'';
        $maxScore=filter_var($maxRaw,FILTER_VALIDATE_FLOAT);
        $attemptRaw=trim((string)($_POST['max_attempts']??''));
        $maxAttempts=$attemptRaw===''?null:filter_var($attemptRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $position=positive_int($_POST['position']??null,1);
        $status=get_post('status');$deadlineRaw=get_post('deadline');$deadline=null;
        if(!$lesson)$errors[]='Vui lòng chọn bài giảng thuộc khóa học của bạn.';
        if($title===''||te_len($title)>255)$errors[]='Tiêu đề phải từ 1 đến 255 ký tự.';
        if(strlen($description)>200000)$errors[]='Mô tả quá dài.';
        if(!in_array($type,['quiz','coding','file_upload','text'],true))$errors[]='Loại bài tập không hợp lệ.';
        if(!in_array($status,['draft','published'],true))$errors[]='Trạng thái không hợp lệ.';
        if($maxScore===false||!is_finite((float)$maxScore)||$maxScore<=0||$maxScore>999999.99||round((float)$maxScore,2)!==(float)$maxScore)$errors[]='Điểm tối đa không hợp lệ.';
        if($maxAttempts===false)$errors[]='Số lần nộp phải là số nguyên dương hoặc để trống.';
        if(strlen($lang)>50)$errors[]='Tên ngôn ngữ quá dài.';
        if(strlen($starter)>200000)$errors[]='Code khởi tạo quá dài.';
        if($position>100000)$errors[]='Thứ tự phải nhỏ hơn hoặc bằng 100000.';
        if($deadlineRaw!=='') {
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$deadlineRaw);
            if(!$dt||$dt->format('Y-m-d\TH:i')!==$deadlineRaw)$errors[]='Hạn nộp không hợp lệ.';
            else $deadline=$dt->format('Y-m-d H:i:s');
        }
        $questions=[];$rawQuestions=isset($_POST['questions_json'])&&is_string($_POST['questions_json'])?$_POST['questions_json']:'[]';
        if($type==='quiz'&&$maxScore!==false&&$maxScore>0){
            $questions=te_validate_questions($rawQuestions,(float)$maxScore,$errors);
            if($status==='published'&&count($questions)<1){
                $errors[]='Bài trắc nghiệm phải có ít nhất 1 câu hỏi mới được phép Công khai. Nếu chưa soạn câu hỏi, vui lòng lưu ở trạng thái Bản nháp.';
            }
        }
        if($old&&$old['exercise_type']==='quiz'){
            $s=$pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE exercise_id=?');$s->execute([$id]);
            if((int)$s->fetchColumn()>0 && ($type!=='quiz'||$rawQuestions!==json_encode(array_map(static fn($q)=>['text'=>$q['question_text'],'type'=>$q['question_type'],'points'=>(float)$q['points'],'options'=>array_map(static fn($o)=>['text'=>$o['option_text'],'correct'=>(bool)$o['is_correct']],$q['options'])],te_questions($pdo,$id)),JSON_UNESCAPED_UNICODE))){
                $errors[]='Không được thay đổi bộ câu hỏi khi đã có bài nộp. Hãy tạo bài tập mới.';
            }
        }
        // Editing exercise type with submissions can invalidate existing answers.
        if($old&&$old['exercise_type']!==$type){$s=$pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE exercise_id=?');$s->execute([$id]);if((int)$s->fetchColumn()>0)$errors[]='Không thể đổi loại bài tập sau khi đã có bài nộp.';}
        $courseId=$lesson?(int)$lesson['course_id']:($old?(int)$old['course_id']:0);
        $form=$_POST;
        if(!$errors){
            try {
                $pdo->beginTransaction();
                if($id){
                    $s=$pdo->prepare('UPDATE exercises SET lesson_id=?,title=?,description=?,exercise_type=?,programming_language=?,starter_code=?,max_score=?,deadline=?,max_attempts=?,position=?,status=? WHERE id=?');
                    $s->execute([$lessonId,$title,$description,$type,$lang?:null,$starter?:null,$maxScore,$deadline,$maxAttempts,$position,$status,$id]);
                }else{
                    $s=$pdo->prepare('INSERT INTO exercises(lesson_id,title,description,exercise_type,programming_language,starter_code,max_score,deadline,max_attempts,position,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $s->execute([$lessonId,$title,$description,$type,$lang?:null,$starter?:null,$maxScore,$deadline,$maxAttempts,$position,$status]);
                    $id=(int)$pdo->lastInsertId();
                }
                if($type==='quiz'){
                    // Preserve existing submitted quiz answers: no replacement when submissions exist.
                    $s=$pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE exercise_id=?');$s->execute([$id]);
                    if((int)$s->fetchColumn()===0){
                        $pdo->prepare('DELETE FROM exercise_questions WHERE exercise_id=?')->execute([$id]);
                        $q=$pdo->prepare('INSERT INTO exercise_questions(exercise_id,question_text,question_type,points,position) VALUES (?,?,?,?,?)');
                        $o=$pdo->prepare('INSERT INTO question_options(question_id,option_text,is_correct,position) VALUES (?,?,?,?)');
                        foreach($questions as $qi=>$question){
                            $q->execute([$id,$question['text'],$question['type'],$question['points'],$qi+1]);$qid=(int)$pdo->lastInsertId();
                            foreach($question['options'] as $oi=>$option)$o->execute([$qid,$option['text'],$option['correct']?1:0,$oi+1]);
                        }
                    }
                } elseif($old&&$old['exercise_type']==='quiz'){
                    $pdo->prepare('DELETE FROM exercise_questions WHERE exercise_id=?')->execute([$id]);
                }
                $pdo->commit();te_redirect('Đã lưu bài tập thành công.',$courseId);
            }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();error_log('[BrightFuture exercise save] '.$ex->getMessage());$errors[]='Không thể lưu dữ liệu. Vui lòng thử lại.';}
        }
    }else{$errors[]='Thao tác không hợp lệ.';}
}
// Teacher-owned courses & lessons only.
$ownedCourses = $exerciseModel->getTeacherCourses($teacherId);
if($courseId && !$exerciseModel->getTeacherCourse($courseId, $teacherId)){http_response_code(403);exit('Khóa học không thuộc quyền quản lý của bạn.');}
$lessons = $exerciseModel->getTeacherLessons($teacherId);
if($editing && !$form){
    $form=$editing;
    $form['deadline']=$editing['deadline']?date('Y-m-d\TH:i',strtotime($editing['deadline'])):'';
    $form['questions_json']=json_encode(array_map(static fn($q)=>['text'=>$q['question_text'],'type'=>$q['question_type'],'points'=>(float)$q['points'],'options'=>array_map(static fn($o)=>['text'=>$o['option_text'],'correct'=>(bool)$o['is_correct']],$q['options'])],$editingQuestions),JSON_UNESCAPED_UNICODE);
}
$search=trim(get_query('q'));$search=te_cut($search,100);
$status=get_query('status','all');if(!in_array($status,['all','draft','published'],true))$status='all';
$typeFilter=get_query('type','all');if(!in_array($typeFilter,['all','quiz','coding','text','file_upload'],true))$typeFilter='all';
$page=positive_int($_GET['page']??null,1);$perPage=10;

$filters = [
    'course_id' => $courseId,
    'status' => $status,
    'type' => $typeFilter,
    'search' => $search
];

$total = $exerciseModel->countTeacherExercisesFiltered($teacherId, $filters);
$pagination = paginate($total, $page, $perPage);
$items = $exerciseModel->getTeacherExercisesFiltered($teacherId, $filters, $perPage, $pagination['offset']);
function te_list_url(int $page,int $courseId,string $search,string $status,string $type):string{return app_url('teacher/exercises.php?'.http_build_query(['course_id'=>$courseId?:null,'q'=>$search,'status'=>$status,'type'=>$type,'page'=>$page]));}
$flash=get_flash();

$pageTitle = 'Quản lý bài tập';
$pageDescription = 'Tạo bài thực hành, trắc nghiệm và quản lý hạn nộp trên BrightFuture';
$activeMenu = 'exercises';
$extraCss = ['css/bf-teacher-exercises.css'];

require_once __DIR__ . '/../views/layouts/portal_head.php';
?>
<?= bf_unified_sidebar($user, 'exercises') ?>
<?php require_once __DIR__ . '/../views/layouts/portal_topbar.php'; ?>
<main class="content container te-content">
<div class="crumb"><a href="<?=te_h(app_url('teacher/dashboard.php'))?>">Tổng quan</a> / Bài tập</div>
<div class="heading"><div><h1>Quản lý bài tập</h1><p>Tạo bài thực hành, trắc nghiệm và quản lý hạn nộp.</p></div><a class="btn" href="<?=te_h(app_url('teacher/exercises.php?create=1'.($courseId?'&course_id='.$courseId:'')))?>">+ Tạo bài tập</a></div>
<?php if($flash):?><div class="alert <?=($flash['type']??'')==='success'?'success':''?>" role="status"><?=te_h((string)($flash['message']??''))?></div><?php endif;?>
<?php if($errors):?><div class="alert" role="alert"><strong>Vui lòng kiểm tra:</strong><ul><?php foreach($errors as $err):?><li><?=te_h($err)?></li><?php endforeach;?></ul></div><?php endif;?>
<?php if($editing||isset($_GET['create'])||($form&&!empty($errors))):?>
<section class="panel" id="editor"><h2><?=($editing||positive_int($form['id']??null))?'Chỉnh sửa bài tập':'Tạo bài tập mới'?></h2>
<form method="post" id="exerciseForm" action="<?=te_h(app_url('teacher/exercises.php'.($editing?'?edit='.$editing['id']:'')))?>">
<?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($form['id']??($editing['id']??0)) ?>"><input type="hidden" name="questions_json" id="questionsJson">
<div class="grid2"><div class="field"><label for="lesson">Bài giảng *</label><select required class="input" name="lesson_id" id="lesson"><option value="">-- Chọn bài giảng --</option><?php foreach($lessons as $ls):?><option value="<?= (int)$ls['id'] ?>" <?= (int)($form['lesson_id']??0)===(int)$ls['id']?'selected':'' ?> <?= $courseId && (int)$ls['course_id']!==$courseId?'disabled':'' ?>><?=te_h($ls['course_title'].' / '.$ls['chapter_title'].' / '.$ls['title'])?></option><?php endforeach;?></select></div><div class="field"><label for="title">Tên bài tập *</label><input class="input" required maxlength="255" id="title" name="title" value="<?=te_h((string)($form['title']??''))?>"></div></div>
<div class="field" style="margin-top:13px"><label for="description">Đề bài / hướng dẫn</label><textarea class="input" id="description" name="description" rows="5"><?=te_h((string)($form['description']??''))?></textarea></div>
<div class="grid3" style="margin-top:13px"><div class="field"><label for="exerciseType">Loại bài tập</label><select class="input" name="exercise_type" id="exerciseType"><?php foreach(['coding'=>'Lập trình (Coding)','quiz'=>'Trắc nghiệm','text'=>'Trả lời văn bản','file_upload'=>'Nộp file'] as $v=>$label):?><option value="<?=$v?>" <?=($form['exercise_type']??'coding')===$v?'selected':''?>><?=te_h($label)?></option><?php endforeach;?></select></div><div class="field"><label for="maxScore">Điểm tối đa *</label><input class="input" type="number" step="0.01" min="0.01" max="999999.99" name="max_score" id="maxScore" required value="<?=te_h((string)($form['max_score']??'10'))?>"></div><div class="field"><label for="attempts">Số lần nộp (trống = không giới hạn)</label><input class="input" type="number" min="1" name="max_attempts" id="attempts" value="<?=te_h((string)($form['max_attempts']??''))?>"></div></div>
<div class="grid3" style="margin-top:13px"><div class="field"><label for="deadline">Hạn nộp (giờ Việt Nam)</label><input type="datetime-local" class="input" id="deadline" name="deadline" value="<?=te_h((string)($form['deadline']??''))?>"></div><div class="field"><label for="position">Thứ tự</label><input type="number" class="input" id="position" name="position" min="1" max="100000" value="<?=te_h((string)($form['position']??'1'))?>"></div><div class="field"><label for="status">Trạng thái</label><select class="input" id="status" name="status"><option value="draft" <?=($form['status']??'draft')==='draft'?'selected':''?>>Bản nháp</option><option value="published" <?=($form['status']??'draft')==='published'?'selected':''?>>Công khai</option></select></div></div>
<div id="codingFields" style="margin-top:13px"><div class="grid2"><div class="field"><label for="language">Ngôn ngữ lập trình</label><input class="input" maxlength="50" name="programming_language" id="language" placeholder="Python, JavaScript, C++..." value="<?=te_h((string)($form['programming_language']??''))?>"></div><div class="field"><label for="starter">Code khởi tạo (nếu có)</label><textarea class="input" rows="5" name="starter_code" id="starter"><?=te_h((string)($form['starter_code']??''))?></textarea></div></div><p class="note">Hệ thống lưu code của học viên để giáo viên chấm; không thực thi code trực tiếp trên máy chủ.</p></div>
<div id="quizFields" style="margin-top:15px"><h2 style="margin-bottom:5px">Soạn đề trắc nghiệm <span id="quizCount" class="quiz-count">0 câu hỏi</span></h2><p class="note">Mỗi câu có sẵn 4 phương án A–D. Nhập câu hỏi, điền đáp án rồi chọn đáp án đúng. Có thể thêm câu hỏi hoặc phương án tùy ý.</p><div id="quizContainer"></div><button type="button" id="addQuestion" class="btn light">+ Thêm câu hỏi</button></div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px"><button class="btn" type="submit">Lưu bài tập</button><a class="btn light" href="<?=te_h(app_url('teacher/exercises.php'.($courseId?'?course_id='.$courseId:'')))?>">Hủy</a></div></form></section>
<?php endif;?>
<section class="panel"><h2>Tìm kiếm & bộ lọc</h2><form method="get" class="filters" action="<?=te_h(app_url('teacher/exercises.php'))?>"><div class="field"><label for="q">Từ khóa</label><input class="input" type="search" name="q" id="q" maxlength="100" placeholder="Tên bài tập hoặc bài giảng..." value="<?=te_h($search)?>"></div><div class="field"><label for="courseFilter">Khóa học</label><select class="input" name="course_id" id="courseFilter"><option value="0">Tất cả khóa học</option><?php foreach($ownedCourses as $c):?><option value="<?=(int)$c['id']?>" <?=$courseId===(int)$c['id']?'selected':''?>><?=te_h($c['title'])?></option><?php endforeach;?></select></div><div class="field"><label for="statusFilter">Trạng thái</label><select class="input" id="statusFilter" name="status"><option value="all">Tất cả</option><option value="draft" <?=$status==='draft'?'selected':''?>>Bản nháp</option><option value="published" <?=$status==='published'?'selected':''?>>Công khai</option></select></div><div class="field"><label for="typeFilter">Loại bài tập</label><select class="input" id="typeFilter" name="type"><option value="all">Tất cả</option><?php foreach(['quiz'=>'Trắc nghiệm','coding'=>'Lập trình','text'=>'Văn bản','file_upload'=>'Nộp file'] as $v=>$label):?><option value="<?=$v?>" <?=$typeFilter===$v?'selected':''?>><?=te_h($label)?></option><?php endforeach;?></select></div><button type="submit" class="btn">Lọc</button></form></section>
<section class="panel"><h2>Danh sách bài tập <span class="muted">(<?=format_number($total)?>)</span></h2><div class="tablewrap"><table class="table"><thead><tr><th>Bài tập</th><th>Khóa học / bài giảng</th><th>Loại / điểm</th><th>Hạn nộp</th><th>Trạng thái</th><th>Bài nộp</th><th>Thao tác</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=te_h($item['title'])?></strong><small>Thứ tự #<?=(int)$item['position']?></small></td><td><?=te_h($item['course_title'])?><br><small><?=te_h($item['lesson_title'])?></small></td><td><?=te_h(['quiz'=>'Trắc nghiệm','coding'=>'Coding','text'=>'Văn bản','file_upload'=>'Nộp file'][$item['exercise_type']]??$item['exercise_type'])?><br><small><?=te_h((string)$item['max_score'])?> điểm</small></td><td><?=te_h($item['deadline']?format_datetime($item['deadline']):'Không giới hạn')?></td><td><span class="badge <?=$item['status']==='draft'?'draft':''?>"><?=$item['status']==='published'?'Công khai':'Bản nháp'?></span></td><td><?=(int)$item['submissions_count']?></td><td><div class="actions"><a class="btn light sm" href="<?=te_h(app_url('teacher/exercises.php?edit='.(int)$item['id']))?>">Sửa</a><form class="inline" method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="btn light sm" type="submit"><?=$item['status']==='published'?'Ẩn':'Công khai'?></button></form><form class="inline" method="post" onsubmit="return confirm('Xóa bài tập này? Chỉ có thể xóa bài chưa có bài nộp.');"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button type="submit" class="btn danger sm">Xóa</button></form></div></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="7" class="empty">Chưa có bài tập phù hợp. Hãy chọn “Tạo bài tập”.</td></tr><?php endif;?></tbody></table></div>
<?php if($pagination['total_pages']>1):?><nav class="pagination" aria-label="Phân trang"><?php for($i=max(1,$pagination['current_page']-2);$i<=min($pagination['total_pages'],$pagination['current_page']+2);$i++):?><a class="<?=$i===$pagination['current_page']?'active':''?>" href="<?=te_h(te_list_url($i,$courseId,$search,$status,$typeFilter))?>"><?=$i?></a><?php endfor;?></nav><?php endif;?></section>
</main>
<script>
const quizType=document.getElementById('exerciseType');
if(quizType){
 const quizArea=document.getElementById('quizFields'),codingArea=document.getElementById('codingFields'),list=document.getElementById('quizContainer'),form=document.getElementById('exerciseForm');
 const original=<?=json_encode((string)($form['questions_json']??'[]'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 let questions=[];try{questions=JSON.parse(original);if(!Array.isArray(questions))questions=[]}catch(e){questions=[]}
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const defaultQuestion=()=>({text:'',type:'single_choice',points:1,options:Array.from({length:4},(_,i)=>({text:'',correct:i===0}))});
 const letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
 // The server expects a JSON array of questions with {text,type,points,options}.
 function collect(){
   list.querySelectorAll('.quiz-question').forEach((el,i)=>{
     const q=questions[i];if(!q)return;
     q.text=el.querySelector('.q-text').value;
     q.type=el.querySelector('.q-type').value;
     q.points=Number(el.querySelector('.q-points').value);
     q.options=Array.from(el.querySelectorAll('.option-row'),row=>({text:row.querySelector('.o-text').value,correct:row.querySelector('.o-correct').checked}));
   });
 }
 function render(){
   list.replaceChildren();
   questions.forEach((q,qi)=>{
     if(!Array.isArray(q.options))q.options=defaultQuestion().options;
     const el=document.createElement('section');el.className='quiz-question';
     el.innerHTML=`<div class="quiz-head"><div><span class="q-number">Câu ${qi+1}</span><span class="q-hint">Nhập nội dung và đánh dấu đáp án chính xác</span></div><button type="button" class="btn danger sm q-remove">Xóa câu</button></div>
       <div class="field"><label for="qtext-${qi}">Nội dung câu hỏi <span class="req">*</span></label><textarea id="qtext-${qi}" class="input q-text" rows="2" maxlength="4000" required placeholder="Ví dụ: Trong Python, hàm nào dùng để hiển thị dữ liệu?">${esc(q.text)}</textarea></div>
       <div class="q-settings"><div class="field"><label>Kiểu câu hỏi</label><select class="input q-type"><option value="single_choice" ${q.type==='single_choice'?'selected':''}>Chọn 1 đáp án đúng</option><option value="multiple_choice" ${q.type==='multiple_choice'?'selected':''}>Chọn nhiều đáp án đúng</option></select></div><div class="field"><label>Điểm câu hỏi</label><input type="number" class="input q-points" step="0.01" min="0.01" max="100000" required value="${Number(q.points)||1}"></div></div>
       <div class="q-answer-heading">Các phương án trả lời <span class="muted">(chọn ô tròn / ô vuông để đánh dấu đáp án đúng)</span></div>
       <div class="quiz-options"></div><div class="quiz-actions"><button type="button" class="btn light sm o-add">+ Thêm phương án</button><span class="note">Từ 2 đến 8 phương án/câu</span></div>`;
     const opts=el.querySelector('.quiz-options');
     q.options.forEach((o,oi)=>{
       const row=document.createElement('div');row.className='option-row';
       const inp=document.createElement('input'); inp.type=q.type==='multiple_choice'?'checkbox':'radio';inp.className='o-correct';inp.name=`correct-${qi}`;inp.checked=!!o.correct;inp.setAttribute('aria-label',`Đáp án ${letters[oi]} đúng`);
       const label=document.createElement('span');label.className='o-letter';label.textContent=letters[oi];
       const value=document.createElement('input');value.className='input o-text';value.type='text';value.maxLength=2000;value.required=true;value.placeholder=`Nhập đáp án ${letters[oi]}`;value.value=o.text??'';
       const remove=document.createElement('button');remove.type='button';remove.className='btn danger sm o-remove';remove.textContent='Xóa';remove.setAttribute('aria-label',`Xóa đáp án ${letters[oi]}`);
       row.append(inp,label,value,remove);opts.appendChild(row);
       inp.addEventListener('change',()=>{if(q.type==='single_choice')opts.querySelectorAll('.o-correct').forEach(other=>{if(other!==inp)other.checked=false});});
       remove.addEventListener('click',()=>{collect();if(questions[qi].options.length<=2){alert('Một câu hỏi cần tối thiểu 2 phương án');return};questions[qi].options.splice(oi,1);if(questions[qi].type==='single_choice'&&!questions[qi].options.some(x=>x.correct))questions[qi].options[0].correct=true;render()});
     });
     el.querySelector('.q-remove').addEventListener('click',()=>{collect();questions.splice(qi,1);render()});
     el.querySelector('.o-add').addEventListener('click',()=>{collect();if(questions[qi].options.length>=8){alert('Tối đa 8 phương án');return}questions[qi].options.push({text:'',correct:false});render()});
     el.querySelector('.q-type').addEventListener('change',()=>{collect();const qq=questions[qi];if(qq.type==='single_choice'){let found=false;qq.options.forEach(o=>{if(o.correct){if(found)o.correct=false;else found=true}});if(!found)qq.options[0].correct=true}render()});
     list.appendChild(el);
   });
   const count=document.getElementById('quizCount');if(count)count.textContent=`${questions.length} câu hỏi`;
 }
 function toggle(){quizArea.hidden=quizType.value!=='quiz';codingArea.hidden=quizType.value!=='coding';if(quizType.value==='quiz'&&!questions.length){questions.push(defaultQuestion());render();}}
 quizType.addEventListener('change',toggle);toggle();render();
 document.getElementById('addQuestion').addEventListener('click',()=>{collect();if(questions.length>=80){alert('Tối đa 80 câu');return}questions.push(defaultQuestion());render();const last=list.lastElementChild;if(last)last.querySelector('.q-text').focus()});
 form.addEventListener('submit',event=>{
   if(quizType.value==='quiz'){
     collect();if(!questions.length){alert('Vui lòng thêm ít nhất một câu hỏi');event.preventDefault();return;}
     const maxPoints=Number(document.getElementById('maxScore').value);let total=0;
     for(let i=0;i<questions.length;i++){
       const q=questions[i];total+=q.points;
       if(!q.text.trim()||q.options.length<2||q.options.some(o=>!o.text.trim())||q.options.filter(o=>o.correct).length!==(q.type==='single_choice'?1:q.options.filter(o=>o.correct).length)||!q.options.some(o=>o.correct)){
         alert(`Câu ${i+1}: hãy điền câu hỏi, tất cả phương án và chọn đáp án đúng.`);event.preventDefault();list.children[i]?.querySelector('.q-text')?.focus();return;
       }
     }
     if(total>maxPoints+0.00001){alert(`Tổng điểm câu hỏi (${total}) vượt điểm tối đa (${maxPoints}).`);event.preventDefault();return;}
     document.getElementById('questionsJson').value=JSON.stringify(questions);
   }else document.getElementById('questionsJson').value='[]';
 });
}
</script>
<?php require_once __DIR__ . '/../views/layouts/portal_foot.php'; ?>
