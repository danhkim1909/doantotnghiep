<?php
/** BrightFuture - Student learning page (PHP 8+, MySQL PDO). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/course_unlock.php';
require_once __DIR__ . '/../includes/lesson_media.php';

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

// Progress updates and non-video completion are handled by api/progress.php.
if (is_post()) { http_response_code(405); exit('Tiến độ được ghi qua API học tập.'); }

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
$stmt = $pdo->prepare("SELECT id, title, exercise_type, max_score, deadline, description, starter_code, programming_language, max_attempts FROM exercises WHERE lesson_id=:lesson_id AND status='published' ORDER BY position, id");
$stmt->execute(['lesson_id' => $lessonId]);
$exercises = $stmt->fetchAll();

$submissionsByExercise = [];
if (!empty($exercises)) {
    $subStmt = $pdo->prepare(
        "SELECT s.* FROM exercise_submissions s
         INNER JOIN (
             SELECT exercise_id, MAX(id) AS max_id
             FROM exercise_submissions
             WHERE student_id = :student_id
             GROUP BY exercise_id
         ) m ON m.max_id = s.id"
    );
    $subStmt->execute(['student_id' => $studentId]);
    foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
        $submissionsByExercise[(int)$sub['exercise_id']] = $sub;
    }
}

$questionsByExercise = [];
$optionsByQuestion = [];
$quizExerciseIds = [];
foreach ($exercises as $exercise) {
    if ($exercise['exercise_type'] === 'quiz') {
        $quizExerciseIds[] = (int)$exercise['id'];
    }
}

if ($quizExerciseIds) {
    $exercisePlaceholders = implode(',', array_fill(0, count($quizExerciseIds), '?'));
    $questionStmt = $pdo->prepare(
        "SELECT id, exercise_id, question_text, question_type, points
         FROM exercise_questions
         WHERE exercise_id IN ({$exercisePlaceholders})
         ORDER BY exercise_id, position, id"
    );
    $questionStmt->execute($quizExerciseIds);
    $questionIds = [];
    foreach ($questionStmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
        $question['id'] = (int)$question['id'];
        $question['exercise_id'] = (int)$question['exercise_id'];
        $questionsByExercise[$question['exercise_id']][] = $question;
        $questionIds[] = $question['id'];
    }

    if ($questionIds) {
        $questionIds = array_values(array_unique($questionIds));
        $questionPlaceholders = implode(',', array_fill(0, count($questionIds), '?'));
        $optionStmt = $pdo->prepare(
            "SELECT id, question_id, option_text
             FROM question_options
             WHERE question_id IN ({$questionPlaceholders})
             ORDER BY question_id, position, id"
        );
        $optionStmt->execute($questionIds);
        foreach ($optionStmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $option['id'] = (int)$option['id'];
            $option['question_id'] = (int)$option['question_id'];
            $optionsByQuestion[$option['question_id']][] = $option;
        }
    }
}

function safe_resource_url(string $url): ?string {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return null;
    if (preg_match('~^https://~i', $url)) return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    // Relative paths must stay under uploads/lessons/.
    if (preg_match('~^uploads/lessons/[a-zA-Z0-9/_-]+\.(pdf|txt|docx|pptx|zip|py|js|php|html|css|sql)$~i', $url) && !str_contains($url, '..')) return app_url($url);
    return null;
}
$video = bf_lesson_media_source($lesson);
$hasVideo = $video['type'] !== 'none';
$hasPublishedQuiz = false;
foreach ($exercises as $exercise) {
    if ($exercise['exercise_type'] === 'quiz') {
        $hasPublishedQuiz = true;
        break;
    }
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-student-learn.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-toast.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-ux-refresh.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-design-system.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-toast.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-ux-refresh.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('css/bf-design-system.css')) ?>">
</head>
<body>
<header class="top">
  <div style="display:flex;align-items:center;gap:12px"><button class="mobile-toggle" id="menuBtn" type="button" aria-controls="lessonsNav" aria-expanded="false">☰</button><a class="logo" href="<?= e(app_url()) ?>">&lt;/&gt; BrightFuture</a></div>
  <div class="top-links"><button type="button" id="focusModeBtn" class="btn btn-light" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;border:1px solid #c7d2fe;cursor:pointer;"><span>🖥️</span> <span>Chế độ tập trung</span></button><a href="<?= e(app_url('social.php')) ?>">Tin nhắn</a><a href="<?= e(app_url('rooms.php')) ?>">Phòng gọi</a><a href="<?= e(app_url('student/dashboard.php')) ?>">Tổng quan</a><a href="<?= e(app_url('student/my-courses.php')) ?>">Khóa học của tôi</a><span><?= e($user['full_name']) ?></span><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout" type="submit">Đăng xuất</button></form></div>
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
  <section class="card" id="bf-notes-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap">
      <h2 style="margin:0">📝 Ghi chú cá nhân</h2>
      <span id="bf-note-status" class="subdued" style="font-size:12px;font-weight:600">Đang tải...</span>
    </div>
    <p class="subdued" style="margin-top:0;margin-bottom:12px">Ghi lại ý chính, công thức hoặc đoạn mã quan trọng. Tự động lưu hoặc nhấn <kbd style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;padding:2px 5px;font-size:11px;font-weight:600">Ctrl + S</kbd> để lưu ngay.</p>
    <textarea id="bf-note-input" rows="4" style="width:100%;border:1px solid #d9e0ec;border-radius:10px;padding:12px;font:inherit;resize:vertical;outline:0;box-sizing:border-box" placeholder="Nhập ghi chú của bạn cho bài giảng này..."></textarea>
  </section>
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
    <p class="subdued">Giảng viên tạo bài trắc nghiệm theo từng bài giảng. Bài trắc nghiệm được chấm tự động (đạt từ 50% số câu trở lên mới hoàn thành; nếu chưa đạt cần xem lại video rồi làm lại).</p>
    <?php foreach ($exercises as $ex): $sub = $submissionsByExercise[(int)$ex['id']] ?? null; ?>
      <?php if ($ex['exercise_type'] === 'quiz'): ?>
      <div class="bf-quiz" id="exercise-<?= (int)$ex['id'] ?>" style="border:1px solid #e1e7f2;border-radius:12px;padding:18px;margin:16px 0">
       <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
         <h3 style="margin:0"><?= e($ex['title']) ?> <small class="subdued">(Tối đa <?= e((string)$ex['max_score']) ?> điểm)</small></h3>
         <?php if ($sub): ?>
           <span style="font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;background:<?= $sub['status']==='graded'?'#dcfce7':'#fef3c7' ?>;color:<?= $sub['status']==='graded'?'#166534':'#92400e' ?>">
             <?= $sub['status']==='graded' ? '✓ Đã hoàn thành (Điểm: ' . e((string)$sub['score']) . ')' : 'Đã nộp bài' ?>
           </span>
         <?php endif; ?>
       </div>
       <?php if ((!$sub || $sub['status'] !== 'graded') && !empty($questionsByExercise[(int)$ex['id']])): ?>
       <div class="bf-quiz-timer" data-timer-for="form-quiz-<?= (int)$ex['id'] ?>" style="display:inline-flex;align-items:center;gap:8px;padding:6px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:20px;color:#92400e;font-size:13px;font-weight:600;margin-bottom:12px">
         <span>⏱️ Thời gian làm bài:</span>
         <strong class="bf-timer-clock" data-minutes="10" style="font-variant-numeric:tabular-nums;font-size:14px;color:#b45309">10:00</strong>
       </div>
       <?php endif; ?>
       <form class="bf-inline-quiz" id="form-quiz-<?= (int)$ex['id'] ?>" data-exercise-id="<?= (int)$ex['id'] ?>">
        <?php foreach (($questionsByExercise[(int)$ex['id']] ?? []) as $qi=>$question): $options = $optionsByQuestion[(int)$question['id']] ?? []; ?>
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
        <?php if(!empty($questionsByExercise[(int)$ex['id']])): ?><button class="btn" type="submit">Nộp trắc nghiệm</button><?php else: ?><p class="subdued">Giảng viên chưa thêm câu hỏi.</p><?php endif; ?>
        <div class="bf-quiz-status" role="status" aria-live="polite" style="margin-top:12px"></div>
       </form>
      </div>
      <?php elseif ($ex['exercise_type'] === 'coding'): ?>
      <div class="bf-exercise-item" id="exercise-<?= (int)$ex['id'] ?>" style="border:1px solid #e1e7f2;border-radius:12px;padding:18px;margin:16px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h3 style="margin:0"><?= e($ex['title']) ?> <small class="subdued">(Lập trình · Tối đa <?= e((string)$ex['max_score']) ?>đ)</small></h3>
          <?php if ($sub): ?>
            <span style="font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;background:<?= $sub['status']==='graded'?'#dcfce7':'#eff6ff' ?>;color:<?= $sub['status']==='graded'?'#166534':'#1d4ed8' ?>">
              <?= $sub['status']==='graded' ? 'Đã chấm: ' . e((string)$sub['score']) . 'đ' : 'Chờ chấm' ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($ex['description'])): ?><div class="subdued" style="margin-bottom:12px;line-height:1.6"><?= nl2br(e($ex['description'])) ?></div><?php endif; ?>
        <form class="bf-inline-code-form" data-exercise-id="<?= (int)$ex['id'] ?>">
          <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px">Mã nguồn của bạn<?= !empty($ex['programming_language']) ? ' (' . e($ex['programming_language']) . ')' : '' ?>:</label>
          <textarea style="width:100%;min-height:140px;padding:12px;background:#111827;color:#e5e7eb;border-radius:8px;font:13px/1.6 Consolas,monospace;box-sizing:border-box" name="submitted_code" placeholder="// Viết code tại đây..."><?= e($sub['submitted_code'] ?? $ex['starter_code'] ?? '') ?></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px">
            <button class="btn" type="submit">Nộp bài lập trình</button>
            <span class="bf-code-status subdued" role="status"></span>
          </div>
        </form>
        <?php if (!empty($sub['feedback'])): ?>
          <div style="background:#f8fafc;border-left:3px solid #4f46e5;padding:10px 14px;border-radius:4px;margin-top:12px;font-size:13px">
            <strong>Nhận xét từ giảng viên:</strong> <?= e($sub['feedback']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php elseif ($ex['exercise_type'] === 'text'): ?>
      <div class="bf-exercise-item" id="exercise-<?= (int)$ex['id'] ?>" style="border:1px solid #e1e7f2;border-radius:12px;padding:18px;margin:16px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h3 style="margin:0"><?= e($ex['title']) ?> <small class="subdued">(Tự luận · Tối đa <?= e((string)$ex['max_score']) ?>đ)</small></h3>
          <?php if ($sub): ?>
            <span style="font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;background:<?= $sub['status']==='graded'?'#dcfce7':'#eff6ff' ?>;color:<?= $sub['status']==='graded'?'#166534':'#1d4ed8' ?>">
              <?= $sub['status']==='graded' ? 'Đã chấm: ' . e((string)$sub['score']) . 'đ' : 'Chờ chấm' ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($ex['description'])): ?><div class="subdued" style="margin-bottom:12px;line-height:1.6"><?= nl2br(e($ex['description'])) ?></div><?php endif; ?>
        <form class="bf-inline-text-form" data-exercise-id="<?= (int)$ex['id'] ?>">
          <textarea style="width:100%;min-height:110px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;box-sizing:border-box" name="submitted_text" placeholder="Nhập câu trả lời của bạn..."><?= e($sub['submitted_text'] ?? '') ?></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px">
            <button class="btn" type="submit">Nộp câu trả lời</button>
            <span class="bf-text-status subdued" role="status"></span>
          </div>
        </form>
        <?php if (!empty($sub['feedback'])): ?>
          <div style="background:#f8fafc;border-left:3px solid #4f46e5;padding:10px 14px;border-radius:4px;margin-top:12px;font-size:13px">
            <strong>Nhận xét từ giảng viên:</strong> <?= e($sub['feedback']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php elseif ($ex['exercise_type'] === 'file_upload'): ?>
      <div class="bf-exercise-item" id="exercise-<?= (int)$ex['id'] ?>" style="border:1px solid #e1e7f2;border-radius:12px;padding:18px;margin:16px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h3 style="margin:0"><?= e($ex['title']) ?> <small class="subdued">(Nộp tệp · Tối đa <?= e((string)$ex['max_score']) ?>đ)</small></h3>
          <?php if ($sub): ?>
            <span style="font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;background:<?= $sub['status']==='graded'?'#dcfce7':'#eff6ff' ?>;color:<?= $sub['status']==='graded'?'#166534':'#1d4ed8' ?>">
              <?= $sub['status']==='graded' ? 'Đã chấm: ' . e((string)$sub['score']) . 'đ' : 'Chờ chấm' ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($ex['description'])): ?><div class="subdued" style="margin-bottom:12px;line-height:1.6"><?= nl2br(e($ex['description'])) ?></div><?php endif; ?>
        <form class="bf-inline-file-form" data-exercise-id="<?= (int)$ex['id'] ?>" enctype="multipart/form-data">
          <input type="file" name="file" accept=".pdf,.zip,.png,.jpg,.jpeg,.docx,.txt" required style="margin-bottom:10px;font-size:13px;display:block">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <button class="btn" type="submit">Nộp tệp bài làm</button>
            <span class="bf-file-status subdued" role="status"></span>
          </div>
        </form>
        <?php if (!empty($sub['file_path'])): ?>
          <p style="margin-top:10px;font-size:12px">
            📎 Tệp đã nộp gần nhất: <a href="<?= e(app_url('api/submissions.php?action=download&id=' . (int)$sub['id'])) ?>">Tải về xem lại</a>
          </p>
        <?php endif; ?>
        <?php if (!empty($sub['feedback'])): ?>
          <div style="background:#f8fafc;border-left:3px solid #4f46e5;padding:10px 14px;border-radius:4px;margin-top:12px;font-size:13px">
            <strong>Nhận xét từ giảng viên:</strong> <?= e($sub['feedback']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
    </section>
  <?php endif; ?>
  <section class="card"><h2>Tiến độ học tập</h2>
   <?php if($myProgress['status']==='completed'): ?><p class="completed-label">Đã hoàn thành bài học.</p>
   <?php elseif($hasVideo): ?><p class="subdued">Video được ghi nhận tự động. Nếu có bài trắc nghiệm, bạn phải đạt các bài trắc nghiệm để hoàn thành bài học.</p>
   <?php elseif($hasPublishedQuiz): ?><p class="subdued">Hãy học nội dung bài và đạt các bài trắc nghiệm để hoàn thành.</p>
   <?php else: ?><p class="subdued">Khi đã học xong nội dung, bạn có thể đánh dấu bài học là hoàn thành.</p>
    <button class="btn" type="button" id="markLessonComplete">Đánh dấu đã học xong</button>
   <?php endif; ?>
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
// Video progress is automatic; text-only lessons can be completed explicitly.
const bfLessonId=<?= (int)$lessonId ?>;
const bfProgressApi=<?= json_encode(app_url('api/progress.php'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const bfSubmissionApi=<?= json_encode(app_url('api/submissions.php'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const bfCsrf=document.querySelector('input[name="csrf_token"]')?.value || '';
const bfProgressStatus=document.getElementById('bf-progress-status');
const bfMarkLessonComplete=document.getElementById('markLessonComplete');
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
if(bfMarkLessonComplete){
 bfMarkLessonComplete.addEventListener('click',async()=>{
   bfMarkLessonComplete.disabled=true;
   bfMarkLessonComplete.textContent='Đang lưu...';
   const saved=await bfSaveProgress('complete');
   if(saved&&bfCompleted){
     bfProgressStatus.textContent='Đã hoàn thành bài học.';
     setTimeout(()=>window.location.reload(),500);
     return;
   }
   bfMarkLessonComplete.disabled=false;
   bfMarkLessonComplete.textContent='Đánh dấu đã học xong';
 });
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
// Quản lý đồng hồ đếm ngược Quiz Timer
const bfQuizTimers = new Map();
document.querySelectorAll('.bf-quiz-timer').forEach(timerBox => {
  const clockEl = timerBox.querySelector('.bf-timer-clock');
  const formId = timerBox.dataset.timerFor;
  const form = document.getElementById(formId);
  if (!clockEl || !form) return;

  const totalMinutes = parseInt(clockEl.dataset.minutes || '10', 10);
  let remainingSeconds = totalMinutes * 60;

  const timerInterval = setInterval(() => {
    remainingSeconds--;
    if (remainingSeconds <= 0) {
      clearInterval(timerInterval);
      clockEl.textContent = '00:00 (Hết giờ)';
      timerBox.style.background = '#fee2e2';
      timerBox.style.borderColor = '#fca5a5';
      clockEl.style.color = '#dc2626';

      const status = form.querySelector('.bf-quiz-status');
      if (status) {
        status.textContent = '⏳ Đã hết thời gian làm bài! Đang tự động gửi kết quả...';
      }
      form.requestSubmit();
      return;
    }

    const mins = Math.floor(remainingSeconds / 60);
    const secs = remainingSeconds % 60;
    clockEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

    if (remainingSeconds <= 60) {
      timerBox.style.background = '#fee2e2';
      timerBox.style.borderColor = '#fca5a5';
      clockEl.style.color = '#dc2626';
    }
  }, 1000);

  bfQuizTimers.set(form, timerInterval);
});

// Quiz is submitted in place; the API owns validation, attempt limits and grading state.
document.querySelectorAll('.bf-inline-quiz').forEach(form=>form.addEventListener('submit',async ev=>{
 ev.preventDefault();
 if(bfQuizTimers.has(form)) clearInterval(bfQuizTimers.get(form));
 const btn=form.querySelector('button[type=submit]');const status=form.querySelector('.bf-quiz-status');const answers={};
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
   const totalQ = data?.data?.total || ((data?.data?.correct || 0) + (data?.data?.wrong || 0)) || 10;
    status.textContent='✓ Đạt: đúng '+data.data.correct+'/'+totalQ+' câu, sai '+data.data.wrong+' câu. Bài học đã hoàn thành.';
   btn.disabled=true;
   if(bfProgressStatus)bfProgressStatus.textContent='✓ Đã hoàn thành bài học (video và trắc nghiệm).';
   setTimeout(()=>window.location.reload(),1200);
 }else if(r.ok && data?.data?.passed===false){
   const totalQ = data?.data?.total || ((data?.data?.correct || 0) + (data?.data?.wrong || 0)) || 10;
    status.textContent='Chưa đạt: đúng '+(data?.data?.correct || 0)+'/'+totalQ+' câu (cần đúng từ 50%). Bạn cần học lại video trước khi làm lại.';
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
// Xử lý nộp bài tập lập trình
document.querySelectorAll('.bf-inline-code-form').forEach(form=>form.addEventListener('submit',async ev=>{
  ev.preventDefault();
  const btn=form.querySelector('button[type=submit]');
  const status=form.querySelector('.bf-code-status');
  const code=form.querySelector('textarea[name=submitted_code]').value;
  if(!code.trim()){status.textContent='Vui lòng nhập mã nguồn trước khi nộp.';return;}
  btn.disabled=true;status.textContent='Đang nộp bài...';
  try{
    const r=await fetch(bfSubmissionApi,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':bfCsrf},
      body:JSON.stringify({action:'submit',exercise_id:Number(form.dataset.exerciseId),submitted_code:code})
    });
    const data=await r.json();
    if(r.ok){
      status.textContent='✓ '+(data.message||'Đã nộp bài lập trình thành công! Đang chờ giảng viên chấm.');
      setTimeout(()=>window.location.reload(),1200);
    }else{
      status.textContent=data.message||'Nộp bài không thành công.';
      btn.disabled=false;
    }
  }catch(_){status.textContent='Lỗi kết nối máy chủ.';btn.disabled=false;}
}));

// Xử lý nộp bài tập tự luận
document.querySelectorAll('.bf-inline-text-form').forEach(form=>form.addEventListener('submit',async ev=>{
  ev.preventDefault();
  const btn=form.querySelector('button[type=submit]');
  const status=form.querySelector('.bf-text-status');
  const text=form.querySelector('textarea[name=submitted_text]').value;
  if(!text.trim()){status.textContent='Vui lòng nhập nội dung câu trả lời.';return;}
  btn.disabled=true;status.textContent='Đang nộp bài...';
  try{
    const r=await fetch(bfSubmissionApi,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':bfCsrf},
      body:JSON.stringify({action:'submit',exercise_id:Number(form.dataset.exerciseId),submitted_text:text})
    });
    const data=await r.json();
    if(r.ok){
      status.textContent='✓ '+(data.message||'Đã nộp câu trả lời thành công! Đang chờ giảng viên chấm.');
      setTimeout(()=>window.location.reload(),1200);
    }else{
      status.textContent=data.message||'Nộp bài không thành công.';
      btn.disabled=false;
    }
  }catch(_){status.textContent='Lỗi kết nối máy chủ.';btn.disabled=false;}
}));

// Xử lý nộp bài tập đính kèm tệp
document.querySelectorAll('.bf-inline-file-form').forEach(form=>form.addEventListener('submit',async ev=>{
  ev.preventDefault();
  const btn=form.querySelector('button[type=submit]');
  const status=form.querySelector('.bf-file-status');
  const fileInput=form.querySelector('input[type=file]');
  if(!fileInput.files.length){status.textContent='Vui lòng chọn tệp để nộp.';return;}
  btn.disabled=true;status.textContent='Đang tải tệp lên...';
  try{
    const fd=new FormData();
    fd.append('action','submit');
    fd.append('exercise_id',form.dataset.exerciseId);
    fd.append('csrf_token',bfCsrf);
    fd.append('file',fileInput.files[0]);
    const r=await fetch(bfSubmissionApi,{
      method:'POST',
      credentials:'same-origin',
      body:fd
    });
    const data=await r.json();
    if(r.ok){
      status.textContent='✓ '+(data.message||'Đã nộp tệp bài làm thành công! Đang chờ giảng viên chấm.');
      setTimeout(()=>window.location.reload(),1200);
    }else{
      status.textContent=data.message||'Tải tệp không thành công.';
      btn.disabled=false;
    }
  }catch(_){status.textContent='Lỗi kết nối máy chủ.';btn.disabled=false;}
}));

const btn=document.getElementById('menuBtn');const aside=document.getElementById('lessonsNav');const shade=document.getElementById('shade');
function closeMenu(){aside.classList.remove('open');shade.classList.remove('open');btn.setAttribute('aria-expanded','false')}
btn.addEventListener('click',()=>{const open=!aside.classList.contains('open');aside.classList.toggle('open',open);shade.classList.toggle('open',open);btn.setAttribute('aria-expanded',String(open))});shade.addEventListener('click',closeMenu);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});

// Ghi chú cá nhân (Student Notes) với tự động lưu debounce
const bfNotesApi = <?= json_encode(app_url('api/notes.php'), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const bfNoteInput = document.getElementById('bf-note-input');
const bfNoteStatus = document.getElementById('bf-note-status');
let bfNoteDebounce = null;

async function bfLoadNote() {
  if (!bfNoteInput) return;
  try {
    const res = await fetch(`${bfNotesApi}?lesson_id=${bfLessonId}`, { credentials: 'same-origin' });
    const json = await res.json();
    if (json.success && json.note) {
      bfNoteInput.value = json.note.content || '';
      bfNoteStatus.textContent = json.note.updated_at ? `Đã lưu: ${json.note.updated_at}` : 'Chưa có ghi chú';
    }
  } catch (_) {
    bfNoteStatus.textContent = 'Không tải được ghi chú';
  }
}
bfLoadNote();

async function bfSaveNoteNow() {
  if (!bfNoteInput) return;
  bfNoteStatus.textContent = 'Đang lưu...';
  try {
    const res = await fetch(bfNotesApi, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': bfCsrf },
      body: JSON.stringify({ lesson_id: bfLessonId, content: bfNoteInput.value })
    });
    const json = await res.json();
    if (json.success) {
      bfNoteStatus.textContent = `✓ Đã lưu lúc ${json.updated_at}`;
    } else {
      bfNoteStatus.textContent = json.message || 'Lỗi khi lưu';
    }
  } catch (_) {
    bfNoteStatus.textContent = 'Lỗi kết nối máy chủ';
  }
}

if (bfNoteInput) {
  bfNoteInput.addEventListener('input', () => {
    bfNoteStatus.textContent = 'Đang nhập...';
    clearTimeout(bfNoteDebounce);
    bfNoteDebounce = setTimeout(bfSaveNoteNow, 1200);
  });

  // Phím tắt Ctrl + S hoặc Cmd + S lưu ghi chú tức thì
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      clearTimeout(bfNoteDebounce);
      bfSaveNoteNow();
    }
  });
}

// Phím tắt điều khiển: Space (Dừng/Phát video), Alt+Mũi tên (Chuyển bài)
document.addEventListener('keydown', (e) => {
  const isEditing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
  if (!isEditing && e.code === 'Space') {
    if (bfMp4) {
      e.preventDefault();
      bfMp4.paused ? bfMp4.play() : bfMp4.pause();
    } else if (typeof ytPlayer !== 'undefined' && ytPlayer && typeof ytPlayer.getPlayerState === 'function') {
      e.preventDefault();
      ytPlayer.getPlayerState() === YT.PlayerState.PLAYING ? ytPlayer.pauseVideo() : ytPlayer.playVideo();
    }
  }
  if (e.altKey && e.key === 'ArrowRight') {
    const nextLink = document.querySelector('.actions a.btn[href*="learn.php?id="]');
    if (nextLink) window.location.href = nextLink.href;
  }
  if (e.altKey && e.key === 'ArrowLeft') {
    const prevLink = document.querySelector('.actions a.btn-light[href*="learn.php?id="]');
    if (prevLink) window.location.href = prevLink.href;
  }
});

// Điều khiển danh sách bài học trên thiết bị di động
(() => {
  const menuBtn = document.getElementById('menuBtn');
  const lessonsNav = document.getElementById('lessonsNav');
  const shade = document.getElementById('shade');
  if (!menuBtn || !lessonsNav || !shade) return;

  function toggleNav(open) {
    lessonsNav.classList.toggle('open', open);
    shade.classList.toggle('open', open);
    menuBtn.setAttribute('aria-expanded', String(open));
    menuBtn.setAttribute('aria-label', open ? 'Đóng danh sách bài học' : 'Mở danh sách bài học');
  }

  menuBtn.addEventListener('click', () => {
    toggleNav(!lessonsNav.classList.contains('open'));
  });

  shade.addEventListener('click', () => {
    toggleNav(false);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lessonsNav.classList.contains('open')) {
      toggleNav(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 850 && lessonsNav.classList.contains('open')) {
      toggleNav(false);
    }
  });

  const focusBtn = document.getElementById('focusModeBtn');
  if (focusBtn) {
    focusBtn.addEventListener('click', () => {
      const isFocus = document.body.classList.toggle('focus-mode');
      const textSpan = focusBtn.querySelector('span:last-child');
      if (textSpan) textSpan.textContent = isFocus ? 'Thoát tập trung' : 'Chế độ tập trung';
      try { localStorage.setItem('bf_focus_mode', isFocus ? '1' : '0'); } catch (_) {}
    });
    try {
      if (localStorage.getItem('bf_focus_mode') === '1') {
        document.body.classList.add('focus-mode');
        const textSpan = focusBtn.querySelector('span:last-child');
        if (textSpan) textSpan.textContent = 'Thoát tập trung';
      }
    } catch (_) {}
  }
})();
</script>

<script src="<?= e(app_url('js/bf-toast.js')) ?>"></script>
<script src="<?= e(app_url('js/bf-ux-refresh.js')) ?>" defer></script>
</body></html>
