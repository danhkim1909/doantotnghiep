<?php
/** BrightFuture | Teacher exercise & quiz management | PHP 8+ */

require_once __DIR__ . '/../includes/permissions.php';
$user = require_role(ROLE_TEACHER);
header('Cache-Control: no-store, private');
$pdo = db();
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
function te_course(PDO $pdo, int $courseId, int $teacherId): ?array {
    $s=$pdo->prepare('SELECT id,title,status FROM courses WHERE id=? AND teacher_id=? LIMIT 1');
    $s->execute([$courseId,$teacherId]); return $s->fetch() ?: null;
}
function te_exercise(PDO $pdo,int $id,int $teacherId): ?array {
    $s=$pdo->prepare('SELECT ex.*,l.title AS lesson_title,l.chapter_id,cc.course_id FROM exercises ex JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE ex.id=? AND c.teacher_id=? LIMIT 1');
    $s->execute([$id,$teacherId]); return $s->fetch() ?: null;
}
function te_lesson(PDO $pdo,int $lessonId,int $teacherId): ?array {
    $s=$pdo->prepare('SELECT l.id, l.title,cc.course_id FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE l.id=? AND c.teacher_id=? LIMIT 1');
    $s->execute([$lessonId,$teacherId]); return $s->fetch() ?: null;
}
function te_questions(PDO $pdo,int $exerciseId): array {
    $q=$pdo->prepare('SELECT id,question_text,question_type,points,position FROM exercise_questions WHERE exercise_id=? ORDER BY position,id');
    $q->execute([$exerciseId]); $rows=$q->fetchAll();
    $opt=$pdo->prepare('SELECT option_text,is_correct FROM question_options WHERE question_id=? ORDER BY position,id');
    foreach($rows as &$r){$opt->execute([(int)$r['id']]);$r['options']=$opt->fetchAll();}unset($r);
    return $rows;
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
    $preset = te_lesson($pdo, $presetLessonId, $teacherId);
    if ($preset && (!$courseId || $courseId === (int)$preset['course_id'])) {
        $courseId=(int)$preset['course_id'];
        $form['lesson_id']=$presetLessonId;
        $form['exercise_type']='quiz';
        $form['status']='published';
    }
}
if ($editId) {
    $editing=te_exercise($pdo,$editId,$teacherId);
    if(!$editing){http_response_code(404);exit('Không tìm thấy bài tập hoặc bạn không có quyền.');}
    $courseId=(int)$editing['course_id'];
    if($editing['exercise_type']==='quiz')$editingQuestions=te_questions($pdo,$editId);
}
if (is_post()) {
    require_csrf();
    $action=get_post('action');
    $id=positive_int($_POST['id']??null);
    if(in_array($action,['delete','toggle'],true)) {
        $existing=te_exercise($pdo,$id,$teacherId);
        if(!$existing){http_response_code(403);exit('Bạn không có quyền thao tác bài tập này.');}
        $courseId=(int)$existing['course_id'];
        if($action==='delete') {
            // Delete is blocked when student submissions already exist.
            $s=$pdo->prepare('SELECT COUNT(*) FROM exercise_submissions WHERE exercise_id=?');$s->execute([$id]);
            if((int)$s->fetchColumn()>0){$errors[]='Không thể xóa bài tập đã có bài nộp. Hãy chuyển về bản nháp.';}
            else { $s=$pdo->prepare('DELETE ex FROM exercises ex JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE ex.id=? AND c.teacher_id=?');$s->execute([$id,$teacherId]);te_redirect('Đã xóa bài tập.',$courseId); }
        } else {
            $newStatus=$existing['status']==='published'?'draft':'published';
            $s=$pdo->prepare('UPDATE exercises SET status=? WHERE id=?');$s->execute([$newStatus,$id]);
            te_redirect('Đã cập nhật trạng thái bài tập.',$courseId);
        }
    } elseif ($action==='save') {
        $old=$id?te_exercise($pdo,$id,$teacherId):null;
        if($id&&!$old){http_response_code(403);exit('Bạn không có quyền sửa bài tập này.');}
        $lessonId=positive_int($_POST['lesson_id']??null);
        $lesson=te_lesson($pdo,$lessonId,$teacherId);
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
        if($type==='quiz'&&$maxScore!==false&&$maxScore>0)$questions=te_validate_questions($rawQuestions,(float)$maxScore,$errors);
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
$s=$pdo->prepare('SELECT id,title FROM courses WHERE teacher_id=? ORDER BY updated_at DESC,id DESC');$s->execute([$teacherId]);$ownedCourses=$s->fetchAll();
if($courseId && !te_course($pdo,$courseId,$teacherId)){http_response_code(403);exit('Khóa học không thuộc quyền quản lý của bạn.');}
$s=$pdo->prepare('SELECT l.id,l.title,cc.title AS chapter_title,cc.course_id,c.title AS course_title FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE c.teacher_id=? ORDER BY c.title,cc.position,l.position,l.id');$s->execute([$teacherId]);$lessons=$s->fetchAll();
if($editing && !$form){
    $form=$editing;
    $form['deadline']=$editing['deadline']?date('Y-m-d\TH:i',strtotime($editing['deadline'])):'';
    $form['questions_json']=json_encode(array_map(static fn($q)=>['text'=>$q['question_text'],'type'=>$q['question_type'],'points'=>(float)$q['points'],'options'=>array_map(static fn($o)=>['text'=>$o['option_text'],'correct'=>(bool)$o['is_correct']],$q['options'])],$editingQuestions),JSON_UNESCAPED_UNICODE);
}
$search=trim(get_query('q'));$search=te_cut($search,100);
$status=get_query('status','all');if(!in_array($status,['all','draft','published'],true))$status='all';
$typeFilter=get_query('type','all');if(!in_array($typeFilter,['all','quiz','coding','text','file_upload'],true))$typeFilter='all';
$page=positive_int($_GET['page']??null,1);$perPage=10;
$where=['c.teacher_id=:teacher'];$params=['teacher'=>$teacherId];
if($courseId){$where[]='c.id=:course';$params['course']=$courseId;}
if($status!=='all'){$where[]='ex.status=:status';$params['status']=$status;}
if($typeFilter!=='all'){$where[]='ex.exercise_type=:type';$params['type']=$typeFilter;}
if($search!==''){$where[]='(ex.title LIKE :search1 OR l.title LIKE :search2)';$params['search1']='%'.$search.'%';$params['search2']='%'.$search.'%';}
$whereSql=implode(' AND ',$where);
$from=' FROM exercises ex JOIN lessons l ON l.id=ex.lesson_id JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE '.$whereSql;
$countStmt=$pdo->prepare('SELECT COUNT(*) '.$from);foreach($params as $key=>$val)$countStmt->bindValue(':'.$key,$val,is_int($val)?PDO::PARAM_INT:PDO::PARAM_STR);$countStmt->execute();$total=(int)$countStmt->fetchColumn();
$pagination=paginate($total,$page,$perPage);
$listingSql='SELECT ex.id,ex.title,ex.exercise_type,ex.max_score,ex.max_attempts,ex.deadline,ex.status,ex.position,l.title AS lesson_title,c.id AS course_id,c.title AS course_title,(SELECT COUNT(*) FROM exercise_submissions es WHERE es.exercise_id=ex.id) AS submissions_count '.$from.' ORDER BY ex.updated_at DESC,ex.id DESC LIMIT :lim OFFSET :off';
$s=$pdo->prepare($listingSql);foreach($params as $key=>$val)$s->bindValue(':'.$key,$val,is_int($val)?PDO::PARAM_INT:PDO::PARAM_STR);$s->bindValue(':lim',$perPage,PDO::PARAM_INT);$s->bindValue(':off',$pagination['offset'],PDO::PARAM_INT);$s->execute();$items=$s->fetchAll();
function te_list_url(int $page,int $courseId,string $search,string $status,string $type):string{return app_url('teacher/exercises.php?'.http_build_query(['course_id'=>$courseId?:null,'q'=>$search,'status'=>$status,'type'=>$type,'page'=>$page]));}
$flash=get_flash();
?>
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quản lý bài tập | BrightFuture</title>
<style>
:root{--pri:#4f46e5;--violet:#7c3aed;--bg:#f6f8fc;--text:#172033;--muted:#64748b;--line:#e5eaf3}*{box-sizing:border-box}body{margin:0;font:14px/1.55 'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text)}a{text-decoration:none;color:inherit}button,input,select,textarea{font:inherit}button{cursor:pointer}.sidebar{width:250px;background:#fff;border-right:1px solid var(--line);position:fixed;inset:0 auto 0 0;display:flex;flex-direction:column;z-index:10}.logo{display:flex;align-items:center;gap:12px;padding:25px;font-size:21px;font-weight:800;color:var(--pri)}.logo-mark{padding:8px;border-radius:10px;color:white;background:linear-gradient(120deg,var(--pri),var(--violet))}.nav{padding:12px;flex:1;overflow:auto}.nav a{display:block;padding:12px 15px;border-radius:10px;color:var(--muted);margin:3px 0}.nav a:hover,.nav a.active{background:#eef2ff;color:var(--pri);font-weight:700}.sidefoot{padding:15px;border-top:1px solid var(--line)}.sidefoot strong{display:block}.sidefoot small{color:var(--muted)}.logout{width:100%;margin-top:12px;border:0;border-radius:9px;padding:11px;background:#fff1f2;color:#be123c}.main{margin-left:250px}.top{height:73px;background:white;border-bottom:1px solid var(--line);padding:0 30px;display:flex;align-items:center;justify-content:space-between}.container{max-width:1480px;margin:auto;padding:28px 30px 55px}.crumb{font-size:12px;color:var(--muted);margin-bottom:17px}.heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:24px}.heading h1{font-size:29px;margin:0}.heading p{color:var(--muted);margin:4px 0}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;background:var(--pri);color:white;border-radius:9px;padding:11px 16px;font-weight:700}.btn:hover{background:#4338ca}.btn.light{color:var(--pri);background:#eef2ff}.btn.danger{color:#be123c;background:#fff1f2}.btn.sm{padding:7px 11px;font-size:12px}.panel{border:1px solid var(--line);border-radius:16px;background:white;padding:22px;margin-bottom:22px;box-shadow:0 3px 15px #17203304}.panel h2{font-size:19px;margin:0 0 17px}.filters{display:grid;grid-template-columns:1.7fr 1fr 1fr 1fr auto;gap:10px;align-items:end}.field{display:flex;flex-direction:column;gap:6px;min-width:0}.field label{font-size:12px;font-weight:700}.input{width:100%;border:1px solid #d8dfeb;background:white;border-radius:9px;padding:10px 12px;color:var(--text);outline:none;min-height:43px}.input:focus{border-color:var(--pri);box-shadow:0 0 0 3px #4f46e519}textarea.input{min-height:100px;resize:vertical}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.tablewrap{overflow:auto}.table{width:100%;border-collapse:collapse;text-align:left;min-width:820px}.table th{padding:13px;border-bottom:1px solid var(--line);color:var(--muted);font-size:12px;font-weight:700}.table td{padding:14px 13px;border-bottom:1px solid var(--line);vertical-align:middle}.table td strong{display:block}.table small{color:var(--muted)}.badge{display:inline-block;padding:5px 9px;border-radius:30px;font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8}.badge.draft{background:#f1f5f9;color:#475569}.actions{display:flex;flex-wrap:wrap;gap:6px}.inline{display:inline}.alert{padding:14px 16px;border-radius:10px;margin-bottom:18px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.alert.success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}.muted{color:var(--muted)}.quiz-question{border:1px solid var(--line);border-radius:12px;padding:15px;margin:14px 0;background:#fbfcff}.quiz-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px}.quiz-options{display:grid;gap:8px}.option-row{display:flex;align-items:center;gap:9px}.option-row input[type=text]{flex:1;min-width:0}.option-row input[type=checkbox]{width:18px;height:18px;accent-color:var(--pri)}.quiz-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.note{font-size:12px;color:var(--muted);margin:7px 0}.pagination{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:18px}.pagination a,.pagination span{padding:8px 12px;background:white;border:1px solid var(--line);border-radius:8px}.pagination .active{background:var(--pri);color:white}.empty{text-align:center;padding:40px 15px;color:var(--muted)}.mobile-toggle{display:none}.overlay{display:none}@media(max-width:1150px){.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:850px){.sidebar{transform:translateX(-100%);transition:transform .2s}.sidebar.open{transform:translateX(0)}.overlay.open{display:block;position:fixed;inset:0;background:#0f172a66;z-index:9}.main{margin-left:0}.mobile-toggle{display:inline-block;border:0;background:transparent;font-size:24px}.container{padding:20px 16px}.top{padding:0 16px}.heading{align-items:start;flex-direction:column}}@media(max-width:570px){.filters,.grid2,.grid3{grid-template-columns:1fr}.panel{padding:16px}.heading h1{font-size:23px}.option-row{flex-wrap:wrap}}

/* Form soạn đề rõ ràng: một câu hỏi / một thẻ */
.quiz-question{background:#fff;border:1px solid #dce3f0;border-left:4px solid #6558ed;border-radius:14px;padding:20px;margin:15px 0;box-shadow:0 3px 12px #171f3309}
.quiz-head{padding-bottom:12px;border-bottom:1px solid #eef1f7;margin-bottom:16px}
.quiz-head>div{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.q-number{color:#4f46e5;font-weight:800;font-size:15px}.q-hint{font-size:12px;color:#64748b}
.quiz-count{font-size:12px;color:#4f46e5;font-weight:700;background:#eef2ff;padding:5px 10px;border-radius:20px;vertical-align:middle;margin-left:8px}
.req{color:#dc2626}.q-settings{display:grid;grid-template-columns:2fr 1fr;gap:12px;margin:12px 0 17px}
.q-answer-heading{font-size:13px;font-weight:800;margin-bottom:9px}.q-answer-heading .muted{font-weight:400;font-size:12px}
.quiz-options{display:flex;flex-direction:column;gap:9px}
.option-row{background:#fafbff;border:1px solid #e3e8f3;border-radius:10px;padding:8px 9px;display:flex;align-items:center;gap:10px}
.option-row:has(.o-correct:checked){border-color:#818cf8;background:#f3f3ff}
.option-row .o-correct{width:19px;height:19px;flex-shrink:0;accent-color:#4f46e5;cursor:pointer}
.o-letter{display:grid;place-items:center;width:26px;height:26px;flex-shrink:0;background:#e9edf7;color:#35415b;border-radius:7px;font-weight:800}
.option-row .o-text{flex:1;min-width:100px}.option-row .o-remove{flex-shrink:0}
.quiz-actions{align-items:center}.quiz-actions .note{margin:0}
@media(max-width:650px){.q-settings{grid-template-columns:1fr}.option-row{gap:6px;padding:7px}.option-row .o-remove{padding:6px}}
</style><link rel="stylesheet" href="<?= e(app_url('css/bf-legacy-fixes.css')) ?>">
</head><body>
<div id="overlay" class="overlay"></div><aside class="sidebar" id="sidebar"><a class="logo" href="<?=te_h(app_url())?>"><span class="logo-mark">&lt;/&gt;</span>BrightFuture</a><nav class="nav"><a href="<?=te_h(app_url('teacher/dashboard.php'))?>">▣ Tổng quan</a><a href="<?=te_h(app_url('teacher/courses.php'))?>">▤ Khóa học</a><a href="<?=te_h(app_url('teacher/courses.php'))?>">▥ Chương & bài giảng</a><a class="active" href="<?=te_h(app_url('teacher/exercises.php'))?>">☑ Quản lý bài tập</a><a href="<?=te_h(app_url('teacher/submissions.php'))?>">✎ Chấm bài</a><a href="<?=te_h(app_url('profile.php'))?>">◯ Hồ sơ</a></nav><div class="sidefoot"><strong><?=te_h($user['full_name'])?></strong><small>Giáo viên</small><form action="<?=te_h(app_url('logout.php'))?>" method="post"><?=csrf_field()?><button class="logout">Đăng xuất</button></form></div></aside>
<div class="main"><header class="top"><div><button class="mobile-toggle" id="mobileMenu" type="button" aria-label="Mở menu">☰</button> Không gian giảng dạy</div><strong><?=te_h($user['full_name'])?></strong></header><main class="container"><div class="crumb"><a href="<?=te_h(app_url('teacher/dashboard.php'))?>">Tổng quan</a> / Bài tập</div><div class="heading"><div><h1>Quản lý bài tập</h1><p>Tạo bài thực hành, trắc nghiệm và quản lý hạn nộp.</p></div><a class="btn" href="<?=te_h(app_url('teacher/exercises.php?create=1'.($courseId?'&course_id='.$courseId:'')))?>">+ Tạo bài tập</a></div>
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
</main></div>
<script>
const mobileMenu=document.getElementById('mobileMenu'),sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay');
if(mobileMenu){mobileMenu.addEventListener('click',()=>{sidebar.classList.toggle('open');overlay.classList.toggle('open')});overlay.addEventListener('click',()=>{sidebar.classList.remove('open');overlay.classList.remove('open')})}
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
</script><script>window.BF_LEGACY={role:'teacher',base:<?= json_encode(rtrim(BASE_URL, '/')) ?>};</script>
<script src="<?= e(app_url('js/bf-legacy-fixes.js')) ?>" defer></script>
</body></html>
