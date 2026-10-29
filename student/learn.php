<?php
/** BrightFuture - Student learning page (PHP 8+, MySQL PDO). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/course_unlock.php';

$user = require_role(ROLE_STUDENT);
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
$studentId = (int)$user['id'];
$lessonId = positive_int($_GET['id'] ?? null);
$courseId = positive_int($_GET['course_id'] ?? null);

// A lesson id takes priority. Otherwise select the first published lesson of the course.
if ($lessonId === 0 && $courseId > 0) {
    $stmt = db()->prepare("SELECT l.id FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE c.id=:course_id AND c.status='published' AND l.status='published' ORDER BY cc.position, cc.id, l.position, l.id LIMIT 1");
    $stmt->execute(['course_id' => $courseId]);
    $lessonId = (int)($stmt->fetchColumn() ?: 0);
    if ($lessonId > 0) {
        redirect('student/learn.php?id=' . $lessonId);
    }
}
if ($lessonId <= 0) {
    http_response_code(404);
    exit('Không tìm thấy bài học. Hãy chọn một khóa học có bài giảng đã công khai.');
}

$pdo = db();
$stmt = $pdo->prepare("SELECT l.*, cc.course_id, cc.title AS chapter_title, c.title AS course_title, c.status AS course_status FROM lessons l JOIN course_chapters cc ON cc.id=l.chapter_id JOIN courses c ON c.id=cc.course_id WHERE l.id=:id LIMIT 1");
$stmt->execute(['id' => $lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) {
    http_response_code(404);
    exit('Bài học không tồn tại.');
}
$courseId = (int)$lesson['course_id'];
if (!bf_course_unlocked($pdo, $courseId, $studentId)) {
    deny_access(false, 'Bạn cần hoàn thành trình độ và bài kiểm tra tiên quyết trước khi học khóa này.');
}

// A public preview does not grant progress-tracking rights or enrollment.
if (!can_update_lesson_progress($lessonId)) {
    deny_access(false, 'Bạn cần đăng ký khóa học và bài giảng phải được công khai để học.');
}

// Progress is written by the video player through api/progress.php.
// No manual completion action is accepted on this page.
if (is_post()) { http_response_code(405); exit('Tiến độ được ghi tự động khi học video.'); }

// Only published lessons are visible. A draft must not leak via the sidebar.
$stmt = $pdo->prepare("SELECT cc.id AS chapter_id, cc.title AS chapter_title, cc.position AS chapter_position, l.id AS lesson_id, l.title AS lesson_title, l.lesson_type, l.duration_minutes, lp.status AS progress_status FROM course_chapters cc JOIN lessons l ON l.chapter_id=cc.id AND l.status='published' LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=:student_id WHERE cc.course_id=:course_id ORDER BY cc.position, cc.id, l.position, l.id");
$stmt->execute(['student_id' => $studentId, 'course_id' => $courseId]);
$rows = $stmt->fetchAll();
$chapters = [];
$orderedIds = [];
foreach ($rows as $row) {
    $cid = (int)$row['chapter_id'];
    if (!isset($chapters[$cid])) {
        $chapters[$cid] = ['title' => $row['chapter_title'], 'lessons' => []];
    }
    $chapters[$cid]['lessons'][] = $row;
    $orderedIds[] = (int)$row['lesson_id'];
}
$currentIndex = array_search($lessonId, $orderedIds, true);
if ($currentIndex === false) {
    http_response_code(404);
    exit('Bài học không được công khai.');
}
$prevId = $orderedIds[$currentIndex - 1] ?? null;
$nextId = $orderedIds[$currentIndex + 1] ?? null;
$completedCount = 0;
foreach ($rows as $row) {
    if ($row['progress_status'] === 'completed') {
        ++$completedCount;
    }
}
$totalCount = count($rows);
$percent = $totalCount > 0 ? round($completedCount * 100 / $totalCount) : 0;

$stmt = $pdo->prepare('SELECT status, last_position_seconds FROM lesson_progress WHERE student_id=:student_id AND lesson_id=:lesson_id LIMIT 1');
$stmt->execute(['student_id' => $studentId, 'lesson_id' => $lessonId]);
$myProgress = $stmt->fetch() ?: ['status' => 'not_started', 'last_position_seconds' => 0];

$stmt = $pdo->prepare('SELECT title, resource_type, resource_url FROM lesson_resources WHERE lesson_id=:lesson_id ORDER BY id');
$stmt->execute(['lesson_id' => $lessonId]);
$resources = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT id, title, exercise_type, max_score, deadline FROM exercises WHERE lesson_id=:lesson_id AND status='published' ORDER BY position, id");
$stmt->execute(['lesson_id' => $lessonId]);
$exercises = $stmt->fetchAll();

function safe_resource_url(string $url): ?string {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return null;
    if (preg_match('~^https://~i', $url)) return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    // Relative paths must stay under uploads/lessons/.
    if (preg_match('~^uploads/lessons/[a-zA-Z0-9/_-]+\.(pdf|txt|docx|pptx|zip|py|js|php|html|css|sql)$~i', $url) && !str_contains($url, '..')) return app_url($url);
    return null;
}
function video_source(?string $url): array {
    $url = trim($url ?? '');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return ['type' => 'none', 'url' => ''];
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') return ['type' => 'none', 'url' => ''];
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    $id = null;
    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        if ($path === '/watch') { parse_str($parts['query'] ?? '', $q); $id = $q['v'] ?? null; }
        elseif (preg_match('~^/(embed|shorts)/([A-Za-z0-9_-]{11})$~', $path, $m)) $id = $m[2];
    } elseif ($host === 'youtu.be') {
        $id = ltrim($path, '/');
    }
    if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        return ['type' => 'youtube', 'url' => 'https://www.youtube-nocookie.com/embed/' . $id];
    }
    if (preg_match('~\.mp4$~i', $path)) return ['type' => 'mp4', 'url' => $url];
    return ['type' => 'none', 'url' => ''];
}
$video = video_source($lesson['video_url']);
if (($lesson['video_source'] ?? '') === 'upload' && !empty($lesson['video_file_path'])) {
    $video = ['type'=>'mp4','url'=>app_url('api/video.php?lesson_id='.$lessonId)];
}

$flash = get_flash();
$pageTitle = $lesson['title'] . ' | ' . APP_NAME;
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<style>
:root{--accent:#4f46e5;--dark:#172033;--muted:#64748b;--line:#e5e9f1;--bg:#f6f8fc}
*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--dark)}a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer}.top{height:70px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 25px;position:sticky;top:0;z-index:15}.logo{font-size:20px;font-weight:800;color:var(--accent)}.top-links{display:flex;gap:15px;align-items:center;font-size:13px}.top-links a:hover{color:var(--accent)}.logout{border:0;background:#fff1f2;color:#be123c;padding:9px 12px;border-radius:8px;font-weight:600}.layout{display:grid;grid-template-columns:295px minmax(0,1fr);min-height:calc(100vh - 70px)}.aside{background:#fff;border-right:1px solid var(--line);padding:20px;overflow:auto}.aside h2{font-size:17px;margin:0 0 12px}.subdued{font-size:13px;color:var(--muted);line-height:1.6}.progress-track{height:8px;background:#e8eaf4;border-radius:9px;overflow:hidden;margin:12px 0 20px}.progress-fill{height:100%;background:linear-gradient(90deg,#4f46e5,#8b5cf6)}.chapter{border-top:1px solid var(--line);padding-top:15px;margin-top:15px}.chapter h3{font-size:13px;margin:0 0 10px}.lesson-link{display:flex;gap:9px;padding:11px 10px;border-radius:9px;font-size:13px;line-height:1.45}.lesson-link:hover{background:#f2f3ff}.lesson-link.active{background:#eef2ff;color:#4338ca;font-weight:700}.lesson-mark{min-width:16px;color:#16a34a}.main{padding:27px;min-width:0;max-width:1160px;width:100%;margin:0 auto}.breadcrumb{font-size:13px;color:var(--muted);margin-bottom:16px}.breadcrumb a{color:var(--accent)}h1{font-size:clamp(23px,2.6vw,32px);margin:0 0 12px;line-height:1.3}.meta{font-size:13px;color:var(--muted);margin-bottom:22px}.card{background:#fff;border:1px solid var(--line);border-radius:15px;padding:24px;margin:19px 0;min-width:0}.card h2{font-size:19px;margin:0 0 17px}.video-frame{width:100%;aspect-ratio:16/9;border:0;border-radius:12px;background:#101629}.lesson-text{line-height:1.85;overflow-wrap:anywhere}.code{background:#111827;color:#e5e7eb;border-radius:11px;padding:18px;white-space:pre;overflow:auto;font:13px/1.7 Consolas,monospace}.pill{display:inline-block;background:#eef2ff;color:#4338ca;padding:6px 10px;border-radius:30px;font-size:12px;font-weight:700}.resource,.exercise{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--line);font-size:14px}.resource:last-child,.exercise:last-child{border-bottom:0}.resource a,.exercise a{color:var(--accent);font-weight:700}.actions{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.btn{background:#4f46e5;color:#fff;border:0;border-radius:10px;padding:12px 16px;font-weight:700;font-size:13px;display:inline-block}.btn-light{background:#eef2ff;color:#4338ca}.btn:disabled{opacity:.55;cursor:default}.alert{padding:13px 16px;border-radius:10px;background:#f0fdf4;color:#166534;margin-bottom:18px}.mobile-toggle{display:none}.sidebar-shade{display:none}.completed-label{color:#15803d;font-weight:700;font-size:13px}@media(max-width:850px){.layout{display:block}.aside{position:fixed;top:70px;bottom:0;left:0;width:min(320px,90vw);z-index:12;transform:translateX(-100%);transition:transform .2s}.aside.open{transform:translateX(0)}.sidebar-shade.open{display:block;position:fixed;inset:70px 0 0;background:#0f172a77;z-index:11}.mobile-toggle{display:inline-block;border:0;background:#eef2ff;border-radius:8px;padding:9px;color:#4338ca}.main{padding:20px}.top{padding:0 15px}.top-links span{display:none}}@media(max-width:500px){.card{padding:16px}.top-links{gap:9px}.top-links>a{display:none}.resource,.exercise{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<header class="top">
  <div style="display:flex;align-items:center;gap:12px"><button class="mobile-toggle" id="menuBtn" type="button" aria-controls="lessonsNav" aria-expanded="false">☰</button><a class="logo" href="<?= e(app_url()) ?>">&lt;/&gt; BrightFuture</a></div>
  <div class="top-links"><a href="<?= e(app_url('social.php')) ?>">Tin nhắn</a><a href="<?= e(app_url('rooms.php')) ?>">Phòng gọi</a><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a><a href="<?= e(app_url('student/my-courses.php')) ?>">Khóa học của tôi</a><span><?= e($user['full_name']) ?></span><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form></div>
</header>
<div class="sidebar-shade" id="shade"></div>
<div class="layout">
<aside class="aside" id="lessonsNav">
  <h2><?= e($lesson['course_title']) ?></h2>
  <p class="subdued">Tiến độ: <?= $completedCount ?>/<?= $totalCount ?> bài (<?= $percent ?>%)</p>
  <div class="progress-track" role="progressbar" aria-label="Tiến độ khóa học" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>"><div class="progress-fill" style="width:<?= $percent ?>%"></div></div>
  <?php foreach ($chapters as $chapter): ?>
  <section class="chapter"><h3><?= e($chapter['title']) ?></h3>
    <?php foreach ($chapter['lessons'] as $item): ?>
    <a class="lesson-link <?= (int)$item['lesson_id'] === $lessonId ? 'active' : '' ?>" href="<?= e(app_url('student/learn.php?id=' . (int)$item['lesson_id'])) ?>" <?= (int)$item['lesson_id'] === $lessonId ? 'aria-current="page"' : '' ?>><span class="lesson-mark"><?= $item['progress_status'] === 'completed' ? '✓' : '○' ?></span><span><?= e($item['lesson_title']) ?><br><small class="subdued"><?= (int)$item['duration_minutes'] ?> phút</small></span></a>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
</aside>
<main class="main">
  <div class="breadcrumb"><a href="<?= e(course_url($courseId)) ?>"><?= e($lesson['course_title']) ?></a> / <?= e($lesson['chapter_title']) ?></div>
  <?php if (is_array($flash) && ($flash['type'] ?? '') === 'success'): ?><div class="alert" role="status"><?= e((string)$flash['message']) ?></div><?php endif; ?>
  <h1><?= e($lesson['title']) ?></h1>
  <div class="meta"><span class="pill"><?= e($lesson['lesson_type']) ?></span> &nbsp; <?= (int)$lesson['duration_minutes'] ?> phút &nbsp; • &nbsp; <?= e($lesson['chapter_title']) ?></div>
  <?php if ($video['type'] !== 'none'): ?>
  <section class="card"><h2>Video bài giảng</h2>
    <?php if ($video['type'] === 'youtube'): ?>
      <iframe class="video-frame" id="youtube-player" src="<?= e($video['url']) ?>?enablejsapi=1" title="Video: <?= e($lesson['title']) ?>" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
    <?php else: ?>
      <video id="lesson-video" class="video-frame" controls preload="metadata" controlsList="nodownload"><source src="<?= e($video['url']) ?>" type="video/mp4">Trình duyệt không hỗ trợ phát video.</video>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="card"><h2>Trao đổi bài học</h2><p class="subdued">Có câu hỏi về bài giảng? Thảo luận cùng học viên và giáo viên.</p><a class="btn btn-light" href="<?= e(app_url('discussions.php?lesson=' . $lessonId)) ?>">Mở thảo luận bài học →</a></section>
  <?php if (trim((string)($lesson['content'] ?? '')) !== ''): ?>
  <section class="card"><h2>Nội dung bài học</h2><div class="lesson-text"><?= nl2br(e($lesson['content'])) ?></div></section>
  <?php endif; ?>
  <?php if (trim((string)($lesson['code_example'] ?? '')) !== ''): ?>
  <section class="card"><h2>Code mẫu <?php if (!empty($lesson['code_language'])): ?><span class="pill"><?= e($lesson['code_language']) ?></span><?php endif; ?></h2><pre class="code"><code><?= e($lesson['code_example']) ?></code></pre></section>
  <?php endif; ?>
  <?php if ($resources): ?>
  <section class="card"><h2>Tài liệu đính kèm</h2>
    <?php foreach ($resources as $resource): $url = safe_resource_url((string)$resource['resource_url']); if ($url === null) continue; ?>
      <div class="resource"><span>📎 <?= e($resource['title']) ?> <small class="subdued">(<?= e($resource['resource_type']) ?>)</small></span><a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">Mở tài liệu ↗</a></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php if ($exercises): ?>
    <section class="card" id="bai-tap-bai-hoc"><h2>📝 Trắc nghiệm và bài tập ngay sau bài học</h2>
    <p class="subdued">Giảng viên tạo bài trắc nghiệm theo từng bài giảng. Bài trắc nghiệm 10 câu được chấm tự động. Sai tối đa 5 câu mới hoàn thành; sai từ 6 câu phải xem lại video rồi làm lại.</p>
    <?php foreach ($exercises as $ex): ?>
      <?php if ($ex['exercise_type'] === 'quiz'): ?>
      <?php
        $q = $pdo->prepare('SELECT id, question_text, question_type, points FROM exercise_questions WHERE exercise_id = ? ORDER BY position,id');
        $q->execute([(int)$ex['id']]);$qs=$q->fetchAll();
        $opt=$pdo->prepare('SELECT id, option_text FROM question_options WHERE question_id = ? ORDER BY position,id');
      ?>
      <div class="bf-quiz" style="border:1px solid #e1e7f2;border-radius:12px;padding:18px;margin:16px 0">
       <h3><?= e($ex['title']) ?> <small class="subdued">(Tối đa <?= e((string)$ex['max_score']) ?> điểm)</small></h3>
       <form class="bf-inline-quiz" data-exercise-id="<?= (int)$ex['id'] ?>">
        <?php foreach ($qs as $qi=>$question): $opt->execute([(int)$question['id']]);$options=$opt->fetchAll(); ?>
         <fieldset style="border:0;border-top:1px solid #e5e7eb;margin-top:12px;padding:12px 0">
           <legend style="font-weight:700">Câu <?= $qi+1 ?>: <?= e($question['question_text']) ?></legend>
           <?php foreach ($options as $option): ?>
           <label style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;cursor:pointer">
             <input type="<?= $question['question_type'] === 'multiple_choice'?'checkbox':'radio' ?>"
                    name="q_<?= (int)$question['id'] ?><?= $question['question_type']==='multiple_choice'?'[]':'' ?>"
                    data-qid="<?= (int)$question['id'] ?>" value="<?= (int)$option['id'] ?>">
             <span><?= e($option['option_text']) ?></span>
           </label>
           <?php endforeach; ?>
         </fieldset>
        <?php endforeach; ?>
        <?php if($qs): ?><button class="btn" type="submit">Nộp trắc nghiệm</button><?php else: ?><p class="subdued">Giảng viên chưa thêm câu hỏi.</p><?php endif; ?>
        <div class="bf-quiz-status" role="status" aria-live="polite" style="margin-top:12px"></div>
       </form>
      </div>
      <?php else: ?>
      <div class="exercise"><span><strong><?= e($ex['title']) ?></strong> · <?= e($ex['exercise_type']) ?></span><a href="<?= e(exercise_url((int)$ex['id'])) ?>">Làm bài →</a></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </section>
  <?php endif; ?>
  <section class="card"><h2>Tiến độ tự động</h2>
   <?php if($myProgress['status']==='completed'): ?><p class="completed-label">✓ Đã hoàn thành bài học (video và bài kiểm tra đạt yêu cầu).</p>
   <?php else: ?><p class="subdued">Video được ghi nhận tự động. Nếu có bài trắc nghiệm, bạn phải trả lời đúng ít nhất 5/10 câu để hoàn thành.</p><?php endif; ?>
   <?= csrf_field() ?>
   <p id="bf-progress-status" class="subdued" aria-live="polite"></p>
  </section>
  <div class="actions">
    <div><?php if ($prevId !== null): ?><a class="btn btn-light" href="<?= e(app_url('student/learn.php?id=' . $prevId)) ?>">← Bài trước</a><?php endif; ?></div>
    <div><?php if ($nextId !== null): ?><a class="btn" href="<?= e(app_url('student/learn.php?id=' . $nextId)) ?>">Bài tiếp theo →</a><?php else: ?><a class="btn btn-light" href="<?= e(app_url('student/my-courses.php')) ?>">Về khóa học của tôi</a><?php endif; ?></div>
  </div>
</main>
</div>
<script>
// Automatic progress for MP4 and YouTube. A manual completed checkbox is intentionally absent.
const bfLessonId=<?= (int)$lessonId ?>;
const bfProgressApi=<?= json_encode(app_url('api/progress.php'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const bfSubmissionApi=<?= json_encode(app_url('api/submissions.php'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const bfCsrf=document.querySelector('input[name="csrf_token"]')?.value || '';
const bfProgressStatus=document.getElementById('bf-progress-status');
let bfLastSaved=-1,bfCompleted=false;
let bfProgressQueue=Promise.resolve();
// Serialize requests: near the 90% mark, the final position must be persisted
// before the quiz or completion request checks the server-side progress.
function bfSaveProgress(action,seconds=0,duration=0){
 if(!bfCsrf) return Promise.resolve(false);
 const body={action,lesson_id:bfLessonId};
 if(action==='position'){
   const n=Math.floor(seconds);
   if(!Number.isFinite(n)||n<0||n>86400) return Promise.resolve(false);
   body.position_seconds=n;
   const d=Math.floor(duration);
   if(Number.isFinite(d)&&d>=5&&d<=86400&&d>=n)body.duration_seconds=d;
 }
 const task=async()=>{
   if(action==='position'&&body.position_seconds<=bfLastSaved)return true;
   try{
     const r=await fetch(bfProgressApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':bfCsrf},body:JSON.stringify(body)});
     const j=await r.json();
     if(!r.ok){if(bfProgressStatus)bfProgressStatus.textContent=j.message||'Không lưu được tiến độ.';return false;}
     if(action==='position')bfLastSaved=Math.max(bfLastSaved,body.position_seconds);
     if(j?.data?.progress?.status==='completed'){bfCompleted=true;if(bfProgressStatus)bfProgressStatus.textContent='✓ Đã hoàn thành bài học.';}
     return true;
   }catch(_){if(bfProgressStatus)bfProgressStatus.textContent='Mất kết nối, tiến độ chưa lưu được. Vui lòng kiểm tra mạng.';return false;}
 };
 bfProgressQueue=bfProgressQueue.then(task,task);
 return bfProgressQueue;
}
async function bfRestorePosition(seek){try{const r=await fetch(bfProgressApi+'?lesson_id='+bfLessonId,{credentials:'same-origin'});if(!r.ok)return;const j=await r.json();const p=j?.data?.progress ?? j?.data;
 const seconds=Number(p?.last_position_seconds??0);bfCompleted=p?.status==='completed';if(seconds>0&&seconds<86400){bfLastSaved=Math.max(0,seconds-1);seek(seconds);}}
 catch(_){}}
async function bfUpdatePlayback(seconds,duration,ended=false){
 if(!Number.isFinite(seconds)||seconds<0)return;
 const ok=await bfSaveProgress('position',seconds,duration);
 if(!ok)return;
 if(!bfCompleted && (ended || Number.isFinite(duration)&&duration>0&&seconds/duration>=0.90)){
   await bfSaveProgress('complete');
   if(bfProgressStatus&&!bfCompleted)bfProgressStatus.textContent='Đã xem đủ video. Bạn có thể nộp trắc nghiệm bên dưới để hoàn thành bài học.';
 }
}
const bfMp4=document.getElementById('lesson-video');
if(bfMp4){
 bfMp4.addEventListener('loadedmetadata',()=>bfRestorePosition(n=>{if(Number.isFinite(bfMp4.duration)&&n<bfMp4.duration-3)bfMp4.currentTime=n}),{once:true});
 bfMp4.addEventListener('play',()=>bfSaveProgress('start'));
 bfMp4.addEventListener('pause',()=>bfUpdatePlayback(bfMp4.currentTime,bfMp4.duration));
 bfMp4.addEventListener('ended',()=>bfUpdatePlayback(bfMp4.duration,bfMp4.duration,true));
 setInterval(()=>{if(!bfMp4.paused&&!bfMp4.ended)bfUpdatePlayback(bfMp4.currentTime,bfMp4.duration)},15000);
}
if(document.getElementById('youtube-player')){
 let ytPlayer=null;const tag=document.createElement('script');tag.src='https://www.youtube.com/iframe_api';document.head.appendChild(tag);
 window.onYouTubeIframeAPIReady=function(){ytPlayer=new YT.Player('youtube-player',{events:{onReady:()=>bfRestorePosition(n=>{try{ytPlayer.seekTo(n,true)}catch(_){}}),onStateChange:ev=>{
 try{
  if(ev.data===YT.PlayerState.PLAYING)bfSaveProgress('start');
  if(ev.data===YT.PlayerState.PAUSED)bfUpdatePlayback(ytPlayer.getCurrentTime(),ytPlayer.getDuration());
  if(ev.data===YT.PlayerState.ENDED)bfUpdatePlayback(ytPlayer.getDuration(),ytPlayer.getDuration(),true);
 }catch(_){}
 }}})};
 setInterval(()=>{try{if(ytPlayer&&ytPlayer.getPlayerState()===YT.PlayerState.PLAYING)bfUpdatePlayback(ytPlayer.getCurrentTime(),ytPlayer.getDuration())}catch(_){}},15000);
}
// Quiz is submitted in place; the API owns validation, attempt limits and grading state.
document.querySelectorAll('.bf-inline-quiz').forEach(form=>form.addEventListener('submit',async ev=>{
 ev.preventDefault();const btn=form.querySelector('button[type=submit]');const status=form.querySelector('.bf-quiz-status');const answers={};
 form.querySelectorAll('input[data-qid]').forEach(el=>{const id=el.dataset.qid;if(!(id in answers))answers[id]=[];if(el.checked)answers[id].push(Number(el.value));});
 if(Object.values(answers).some(a=>!a.length)){status.textContent='Vui lòng trả lời đầy đủ câu hỏi.';return;}
 const payload={action:'submit',exercise_id:Number(form.dataset.exerciseId),answers};btn.disabled=true;status.textContent='Đang kiểm tra tiến độ video...';
 try{
   if(bfMp4&&Number.isFinite(bfMp4.duration))await bfSaveProgress('position',bfMp4.currentTime,bfMp4.duration);
   else if(typeof ytPlayer!=='undefined'&&ytPlayer){try{await bfSaveProgress('position',ytPlayer.getCurrentTime(),ytPlayer.getDuration());}catch(_){}}
   await bfProgressQueue;
   status.textContent='Đang nộp bài...';
   const r=await fetch(bfSubmissionApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':bfCsrf},body:JSON.stringify(payload)});const data=await r.json();
 if(r.ok && data?.data?.passed===true){
   status.textContent='✓ Đạt: đúng '+data.data.correct+'/10 câu, sai '+data.data.wrong+' câu. Bài học đã hoàn thành.';
   btn.disabled=true;
   if(bfProgressStatus)bfProgressStatus.textContent='✓ Đã hoàn thành bài học (video và trắc nghiệm).';
   setTimeout(()=>window.location.reload(),1200);
 }else if(r.ok && data?.data?.passed===false){
   status.textContent='Chưa đạt: sai '+data.data.wrong+'/10 câu (được sai tối đa 5). Bạn cần học lại video trước khi làm lại.';
   bfCompleted=false;bfLastSaved=-1;btn.disabled=false;
   if(bfMp4){bfMp4.pause();bfMp4.currentTime=0;}
   if(typeof ytPlayer!=='undefined' && ytPlayer){try{ytPlayer.pauseVideo();ytPlayer.seekTo(0,true);}catch(_){}}
   if(bfProgressStatus)bfProgressStatus.textContent='Chưa đạt bài kiểm tra — tiến độ video đã đặt lại để học lại.';
 }else{
   status.textContent=data.message||'Nộp bài không thành công.';
   btn.disabled=false;
 }
 }catch(_){status.textContent='Không kết nối được máy chủ. Vui lòng thử lại.';btn.disabled=false;}
}));
const btn=document.getElementById('menuBtn');const aside=document.getElementById('lessonsNav');const shade=document.getElementById('shade');
function closeMenu(){aside.classList.remove('open');shade.classList.remove('open');btn.setAttribute('aria-expanded','false')}
btn.addEventListener('click',()=>{const open=!aside.classList.contains('open');aside.classList.toggle('open',open);shade.classList.toggle('open',open);btn.setAttribute('aria-expanded',String(open))});shade.addEventListener('click',closeMenu);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});
</script>
</body></html>
