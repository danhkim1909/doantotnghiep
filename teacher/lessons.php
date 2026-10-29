<?php
/** BrightFuture - chapter and lesson management (PHP 8.0+, MySQL PDO). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/permissions.php';
$user = require_role(ROLE_TEACHER);
header('Cache-Control: no-store, private');
$teacherId = (int)$user['id'];
$pdo = db();

function lm_url(int $courseId, array $extra = []): string {
    return app_url('teacher/lessons.php?' . http_build_query(array_merge(['course_id' => $courseId], $extra)));
}
function lm_fail(string $message, int $status = 400): never {
    http_response_code($status);
    exit('<!doctype html><html lang="vi"><meta charset="utf-8"><title>BrightFuture</title><body style="font-family:Arial;padding:36px"><h2>Không thể thực hiện thao tác</h2><p>' . e($message) . '</p><a href="' . e(app_url('teacher/courses.php')) . '">Về danh sách khóa học</a></body></html>');
}
function lm_position(mixed $value): int {
    $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    return $n === false ? 1 : (int)$n;
}
function lm_text(mixed $value, int $max = 255): string {
    if (!is_string($value)) return '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}
function lm_owned_chapter(PDO $pdo, int $id, int $teacher): ?array {
    $q = $pdo->prepare('SELECT ch.id, ch.course_id, ch.title FROM course_chapters ch INNER JOIN courses c ON c.id=ch.course_id WHERE ch.id=:id AND c.teacher_id=:teacher LIMIT 1');
    $q->execute(['id'=>$id,'teacher'=>$teacher]);
    return $q->fetch() ?: null;
}
function lm_owned_lesson(PDO $pdo, int $id, int $teacher): ?array {
    $q=$pdo->prepare('SELECT l.*, ch.course_id FROM lessons l INNER JOIN course_chapters ch ON ch.id=l.chapter_id INNER JOIN courses c ON c.id=ch.course_id WHERE l.id=:id AND c.teacher_id=:teacher LIMIT 1');
    $q->execute(['id'=>$id,'teacher'=>$teacher]);
    return $q->fetch() ?: null;
}


// MP4 videos are stored outside Apache DocumentRoot; never expose the disk path.
function bf_video_dir(): string {
    $configured = getenv('BRIGHTFUTURE_VIDEO_DIR');
    return is_string($configured) && trim($configured) !== ''
        ? rtrim($configured, '/\\')
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'brightfuture-storage' . DIRECTORY_SEPARATOR . 'videos';
}
function bf_video_file(string $name): ?string {
    if (!preg_match('/\A[a-zA-Z0-9_-]+\.mp4\z/D', $name)) return null;
    return bf_video_dir() . DIRECTORY_SEPARATOR . $name;
}
function bf_video_remove(?string $name): void {
    $path = bf_video_file((string)$name);
    if ($path && is_file($path)) @unlink($path);
}
function bf_php_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $num = (float)$value;
    $unit = strtolower(substr($value, -1));
    $factor = match ($unit) { 'g' => 1024 * 1024 * 1024, 'm' => 1024 * 1024, 'k' => 1024, default => 1 };
    return (int)round($num * $factor);
}
function bf_upload_error(int $error): string {
    return match ($error) {
        UPLOAD_ERR_INI_SIZE => 'Video vượt upload_max_filesize trong php.ini đang chạy trên Apache.',
        UPLOAD_ERR_FORM_SIZE => 'Video vượt giới hạn dung lượng của biểu mẫu.',
        UPLOAD_ERR_PARTIAL => 'Video mới được tải lên một phần. Vui lòng thử lại.',
        UPLOAD_ERR_NO_FILE => 'Bạn chưa chọn tệp MP4.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP không tìm thấy thư mục lưu tạm (upload_tmp_dir).',
        UPLOAD_ERR_CANT_WRITE => 'PHP không ghi được tệp tạm lên ổ đĩa.',
        UPLOAD_ERR_EXTENSION => 'Một PHP extension đã chặn việc tải video.',
        default => 'PHP báo lỗi tải video, mã: ' . $error,
    };
}
function bf_video_upload(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException(bf_upload_error((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new DomainException('Tệp tải lên không hợp lệ.');
    $size = (int)($file['size'] ?? 0);
    if ($size < 128 || $size > 200 * 1024 * 1024) throw new DomainException('Video MP4 phải từ 128 byte đến 200 MB.');
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'mp4') throw new DomainException('Chỉ chấp nhận tệp .mp4.');
    $handle = @fopen((string)$file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 12) : false;
    if ($handle) fclose($handle);
    if (!is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') throw new DomainException('Tệp không có cấu trúc MP4 hợp lệ (thiếu ftyp).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    if (!in_array($mime, ['video/mp4', 'application/mp4', 'application/octet-stream'], true)) throw new DomainException('Tệp không được nhận diện là MP4 (MIME: ' . (string)$mime . ').');
    $dir = bf_video_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new DomainException('Không tạo được thư mục lưu video. Kiểm tra quyền ghi máy chủ.');
    if (!is_writable($dir)) throw new DomainException('Máy chủ chưa có quyền ghi thư mục video.');
    $name = bin2hex(random_bytes(20)) . '.mp4';
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) throw new DomainException('Không lưu được tệp MP4.');
    @chmod($dir . DIRECTORY_SEPARATOR . $name, 0640);
    return $name;
}
function bf_youtube_link(string $url): bool {
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $p = parse_url($url);
    if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https') return false;
    return in_array(strtolower($p['host'] ?? ''), ['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be','youtube-nocookie.com','www.youtube-nocookie.com'], true);
}

$requestTooLarge = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    && ($_SERVER['CONTENT_LENGTH'] ?? '') !== ''
    && (int)$_SERVER['CONTENT_LENGTH'] > bf_php_bytes((string)ini_get('post_max_size'))
    && bf_php_bytes((string)ini_get('post_max_size')) > 0;
$courseId = positive_int($_GET['course_id'] ?? $_POST['course_id'] ?? null);
if (!$courseId) lm_fail('Vui lòng chọn khóa học hợp lệ.', 400);
$check = $pdo->prepare('SELECT id,title,status FROM courses WHERE id=:id AND teacher_id=:teacher LIMIT 1');
$check->execute(['id'=>$courseId,'teacher'=>$teacherId]);
$course = $check->fetch();
if (!$course) lm_fail('Khóa học không tồn tại hoặc bạn không sở hữu khóa học này.', 403);

// When post_max_size is exceeded PHP empties $_POST / $_FILES, including csrf_token.
// Detect it BEFORE checking CSRF so teacher sees a useful error instead of a generic 403.
if ($requestTooLarge) {
    set_flash('error', 'Tệp tải lên vượt post_max_size đang áp dụng (' . ini_get('post_max_size') . '). Hãy sửa php.ini của Apache và khởi động lại Apache; không phải chỉ sửa php.ini của CLI.');
    redirect('teacher/lessons.php?course_id=' . $courseId);
}
// Only authenticated teacher may mutate; every operation is POST + CSRF.
if (is_post()) {
    require_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $chapterId = positive_int($_POST['chapter_id'] ?? null);
    $lessonId = positive_int($_POST['lesson_id'] ?? null);
    try {
        switch ($action) {
            case 'create_chapter':
            case 'update_chapter':
                $title = lm_text($_POST['title'] ?? null);
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                $position = lm_position($_POST['position'] ?? 1);
                if ($title === '') throw new DomainException('Vui lòng nhập tên chương học.');
                if ($action === 'update_chapter') {
                    $chapter = lm_owned_chapter($pdo,$chapterId,$teacherId);
                    if (!$chapter || (int)$chapter['course_id'] !== $courseId) throw new DomainException('Bạn không có quyền sửa chương này.');
                    $q=$pdo->prepare('UPDATE course_chapters SET title=:title,description=:description,position=:position WHERE id=:id AND course_id=:course');
                    $q->execute(['title'=>$title,'description'=>$description,'position'=>$position,'id'=>$chapterId,'course'=>$courseId]);
                } else {
                    $q=$pdo->prepare('INSERT INTO course_chapters (course_id,title,description,position) VALUES (:course,:title,:description,:position)');
                    $q->execute(['course'=>$courseId,'title'=>$title,'description'=>$description,'position'=>$position]);
                }
                set_flash('success',$action === 'create_chapter' ? 'Đã tạo chương học.' : 'Đã cập nhật chương học.');
                break;
            case 'delete_chapter':
                $chapter=lm_owned_chapter($pdo,$chapterId,$teacherId);
                if (!$chapter || (int)$chapter['course_id'] !== $courseId) throw new DomainException('Bạn không có quyền xóa chương này.');
                $vf=$pdo->prepare('SELECT l.video_file_path FROM lessons l WHERE l.chapter_id=:chapter AND l.video_source=\'upload\'');
                $vf->execute(['chapter'=>$chapterId]);
                $oldVideoFiles=$vf->fetchAll(PDO::FETCH_COLUMN);
                $q=$pdo->prepare('DELETE FROM course_chapters WHERE id=:id AND course_id=:course');
                $q->execute(['id'=>$chapterId,'course'=>$courseId]);
                if ($q->rowCount()) foreach ($oldVideoFiles as $oldVideoName) bf_video_remove((string)$oldVideoName);
                set_flash('success','Đã xóa chương và các bài học liên quan.');
                break;
            case 'create_lesson':
            case 'update_lesson':
                $chapter=lm_owned_chapter($pdo,$chapterId,$teacherId);
                if (!$chapter || (int)$chapter['course_id'] !== $courseId) throw new DomainException('Chương học không hợp lệ hoặc không thuộc khóa học này.');
                $title=lm_text($_POST['title'] ?? null);
                if ($title === '') throw new DomainException('Vui lòng nhập tên bài giảng.');
                $type=is_string($_POST['lesson_type'] ?? null) ? $_POST['lesson_type'] : 'text';
                $status=is_string($_POST['status'] ?? null) ? $_POST['status'] : 'draft';
                if (!in_array($type,['video','text','code','mixed'],true)) throw new DomainException('Loại bài giảng không hợp lệ.');
                if (!in_array($status,['draft','published'],true)) throw new DomainException('Trạng thái không hợp lệ.');
                $content=is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
                $code=is_string($_POST['code_example'] ?? null) ? $_POST['code_example'] : '';
                $language=lm_text($_POST['code_language'] ?? null,50);
                $video=lm_text($_POST['video_url'] ?? null,1000);
                $source = (string)($_POST['video_source'] ?? 'none');
                if (!in_array($source,['none','youtube','upload'],true)) throw new DomainException('Nguồn video không hợp lệ.');
                $old = null;
                if ($action === 'update_lesson') {
                    $old=lm_owned_lesson($pdo,$lessonId,$teacherId);
                    if (!$old || (int)$old['course_id'] !== $courseId) throw new DomainException('Bạn không có quyền sửa bài giảng này.');
                }
                $seconds = filter_var($_POST['video_duration_seconds'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>86400]]);
                if ($seconds === false) throw new DomainException('Thời lượng giây phải trong khoảng 0–86400.');
                $duration=filter_var($_POST['duration_minutes'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);
                if ($duration === false) throw new DomainException('Thời lượng không hợp lệ.');
                $previousFile = (string)($old['video_file_path'] ?? '');
                $filePath = null;
                $newUploadedFile = null;
                $removePreviousFile = false;
                if ($source === 'youtube') {
                    if (!bf_youtube_link($video)) throw new DomainException('Cần nhập liên kết YouTube HTTPS hợp lệ.');
                    $removePreviousFile = $previousFile !== '';
                } elseif ($source === 'upload') {
                    $video = '';
                    if (isset($_FILES['video_mp4']) && (int)($_FILES['video_mp4']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $newUploadedFile = bf_video_upload($_FILES['video_mp4']);
                        $filePath = $newUploadedFile;
                        $removePreviousFile = $previousFile !== '';
                    } elseif ($previousFile !== '' && ($old['video_source'] ?? '') === 'upload') {
                        $filePath = $previousFile;
                    } else {
                        throw new DomainException('Vui lòng chọn tệp MP4 để tải lên.');
                    }
                } else {
                    $video = '';
                    $removePreviousFile = $previousFile !== '';
                }
                $position=lm_position($_POST['position'] ?? 1);
                $preview=isset($_POST['is_preview']) && $_POST['is_preview']==='1' ? 1 : 0;
                $values=['chapter'=>$chapterId,'title'=>$title,'content'=>$content,'type'=>$type,
                    'video'=>$video ?: null,'source'=>$source,'file'=>$filePath,'seconds'=>$seconds,
                    'language'=>$language ?: null,'code'=>$code,'duration'=>$duration,'position'=>$position,'preview'=>$preview,'status'=>$status];
                try {
                    $pdo->beginTransaction();
                    if ($action === 'update_lesson') {
                        $q=$pdo->prepare('UPDATE lessons SET chapter_id=:chapter,title=:title,content=:content,lesson_type=:type,video_url=:video,video_source=:source,video_file_path=:file,video_duration_seconds=:seconds,code_language=:language,code_example=:code,duration_minutes=:duration,position=:position,is_preview=:preview,status=:status WHERE id=:id');
                        $values['id']=$lessonId;
                    } else {
                        $q=$pdo->prepare('INSERT INTO lessons (chapter_id,title,content,lesson_type,video_url,video_source,video_file_path,video_duration_seconds,code_language,code_example,duration_minutes,position,is_preview,status) VALUES (:chapter,:title,:content,:type,:video,:source,:file,:seconds,:language,:code,:duration,:position,:preview,:status)');
                    }
                    $q->execute($values);
                    $pdo->commit();
                    if ($removePreviousFile) bf_video_remove($previousFile);
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($newUploadedFile !== null) bf_video_remove($newUploadedFile);
                    throw $ex;
                }
                set_flash('success',$action === 'create_lesson' ? 'Đã tạo bài giảng.' : 'Đã cập nhật bài giảng.');
                break;
            case 'delete_lesson':
                $old=lm_owned_lesson($pdo,$lessonId,$teacherId);
                if (!$old || (int)$old['course_id'] !== $courseId) throw new DomainException('Bạn không có quyền xóa bài giảng này.');
                $deleteVideoName=($old['video_source']??'')==='upload' ? (string)($old['video_file_path']??'') : '';
                $q=$pdo->prepare('DELETE FROM lessons WHERE id=:id');
                $q->execute(['id'=>$lessonId]);
                if ($q->rowCount() && $deleteVideoName !== '') bf_video_remove($deleteVideoName);
                set_flash('success','Đã xóa bài giảng.');
                break;
            default:
                throw new DomainException('Thao tác không hợp lệ.');
        }
    } catch (DomainException $ex) {
        set_flash('error',$ex->getMessage());
    } catch (Throwable $ex) {
        error_log('[BrightFuture teacher lessons] '.$ex->getMessage());
        set_flash('error','Không thể lưu dữ liệu. Vui lòng kiểm tra và thử lại.');
    }
    redirect('teacher/lessons.php?course_id='.$courseId);
}

$chaptersQ=$pdo->prepare('SELECT ch.*, (SELECT COUNT(*) FROM lessons l WHERE l.chapter_id=ch.id) AS lesson_count FROM course_chapters ch WHERE ch.course_id=:course ORDER BY ch.position ASC,ch.id ASC');
$chaptersQ->execute(['course'=>$courseId]);
$chapters=$chaptersQ->fetchAll();
$lessonsQ=$pdo->prepare('SELECT l.* FROM lessons l INNER JOIN course_chapters ch ON ch.id=l.chapter_id WHERE ch.course_id=:course ORDER BY ch.position ASC,ch.id ASC,l.position ASC,l.id ASC');
$lessonsQ->execute(['course'=>$courseId]);
$lessons=$lessonsQ->fetchAll();
$grouped=[];
foreach ($lessons as $lesson) $grouped[(int)$lesson['chapter_id']][]=$lesson;
$editChapter=null;$editLesson=null;
$editChapterId=positive_int($_GET['edit_chapter'] ?? null);
$editLessonId=positive_int($_GET['edit_lesson'] ?? null);
if ($editChapterId) {
    $editChapter=lm_owned_chapter($pdo,$editChapterId,$teacherId);
    if (!$editChapter || (int)$editChapter['course_id'] !== $courseId) lm_fail('Không tìm thấy chương cần sửa.',404);
    foreach ($chapters as $ch) if ((int)$ch['id']===$editChapterId) $editChapter=$ch;
}
if ($editLessonId) {
    $editLesson=lm_owned_lesson($pdo,$editLessonId,$teacherId);
    if (!$editLesson || (int)$editLesson['course_id'] !== $courseId) lm_fail('Không tìm thấy bài giảng cần sửa.',404);
}
$flash=get_flash();
$publishedCount=count(array_filter($lessons,static fn($l)=>$l['status']==='published'));
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quản lý bài giảng | <?= e(APP_NAME) ?></title>
<style>
:root{--primary:#4f46e5;--violet:#7c3aed;--bg:#f6f8fc;--fg:#172033;--muted:#64748b;--line:#e7ebf2}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font-family:Segoe UI,Arial,sans-serif}a{text-decoration:none;color:inherit}button,input,textarea,select{font:inherit}button{cursor:pointer}.layout{min-height:100vh;display:flex}.side{width:255px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;display:flex;flex-direction:column;flex-shrink:0}.brand{font-weight:800;color:var(--primary);font-size:22px;display:flex;gap:9px;align-items:center;margin:10px 7px 35px}.logo{background:linear-gradient(135deg,var(--primary),var(--violet));color:white;border-radius:12px;padding:10px;font-size:14px}.nav{display:grid;gap:7px}.nav a{border-radius:11px;padding:13px 14px;font-size:14px;color:var(--muted)}.nav a.current,.nav a:hover{background:#eef2ff;color:var(--primary);font-weight:700}.side-footer{margin-top:auto;padding-top:20px}.side-footer p{font-size:13px;overflow-wrap:anywhere}.logout{background:#fff1f2;color:#be123c;border:0;padding:12px;border-radius:9px;width:100%;font-weight:600}.main{flex:1;min-width:0}.top{background:white;border-bottom:1px solid var(--line);padding:24px 32px;display:flex;justify-content:space-between;gap:14px;align-items:center}.top strong{font-size:15px}.content{max-width:1360px;margin:auto;padding:32px}.crumb{color:var(--muted);font-size:13px;margin-bottom:16px}.crumb a{color:var(--primary)}h1{font-size:27px;margin:0 0 9px}.subtitle{margin:0;color:var(--muted);font-size:14px;line-height:1.7}.headline{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:25px}.btn{border:0;border-radius:10px;padding:11px 15px;font-weight:700;font-size:13px;display:inline-flex;align-items:center;justify-content:center;gap:7px}.btn-primary{background:var(--primary);color:white}.btn-soft{background:#eef2ff;color:var(--primary)}.btn-danger{background:#fff1f2;color:#be123c}.btn-neutral{background:#f1f5f9;color:#475569}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:22px}.stat{background:#fff;border:1px solid var(--line);padding:22px;border-radius:15px}.stat small{display:block;color:var(--muted);margin-bottom:9px}.stat strong{font-size:27px}.columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,420px);gap:22px;align-items:start}.panel{background:white;border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:20px}.panel h2{font-size:18px;margin:0 0 18px}.chapter{border:1px solid var(--line);border-radius:13px;margin-bottom:15px;overflow:hidden}.chapter-head{background:#f8faff;padding:17px;display:flex;justify-content:space-between;align-items:start;gap:12px}.chapter-head strong{font-size:15px}.chapter-head p{font-size:12px;color:var(--muted);margin:8px 0 0}.actions{display:flex;gap:7px;flex-wrap:wrap}.small{border:0;border-radius:8px;padding:8px 10px;font-size:12px;font-weight:650;background:#eef2ff;color:var(--primary)}.small.danger{background:#fff1f2;color:#be123c}.lesson-row{display:flex;gap:12px;align-items:center;padding:13px 17px;border-top:1px solid var(--line)}.lesson-icon{display:grid;place-items:center;width:37px;height:37px;border-radius:10px;background:#eef2ff;color:var(--primary);font-weight:700;flex-shrink:0}.lesson-info{min-width:0;flex:1}.lesson-info strong{font-size:13px;display:block;overflow-wrap:anywhere}.lesson-info small{font-size:11px;color:var(--muted);display:block;margin-top:5px}.pill{font-size:11px;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569}.pill.pub{background:#dcfce7;color:#166534}.field{margin-bottom:14px}.field label{display:block;font-weight:650;font-size:13px;margin-bottom:7px}.field input,.field select,.field textarea{width:100%;border:1px solid #dbe1eb;border-radius:9px;padding:11px 12px;background:#fff;outline:none;font-size:13px}.field textarea{resize:vertical;min-height:94px}.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px #4f46e51a}.twocol{display:grid;grid-template-columns:1fr 1fr;gap:12px}.helper{font-size:12px;color:var(--muted);line-height:1.6}.check{display:flex;align-items:center;gap:9px;font-size:13px;margin:8px 0 16px}.check input{width:17px;height:17px}.flash{border-radius:10px;padding:13px 15px;margin-bottom:18px;font-size:13px}.flash.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.flash.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.empty{text-align:center;color:var(--muted);padding:30px 12px;font-size:13px}.footer-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center}.panel-caption{font-size:12px;color:var(--muted);line-height:1.6}.form-anchor{scroll-margin-top:20px}.mobilebar{display:none}@media(max-width:1120px){.columns{grid-template-columns:1fr}.side{width:220px}.content{padding:23px}}@media(max-width:760px){.side{display:none}.mobilebar{display:flex;gap:10px;flex-wrap:wrap;padding:12px 17px;background:#fff;border-bottom:1px solid var(--line)}.mobilebar a{font-size:12px;color:var(--primary);font-weight:700}.top{padding:17px}.content{padding:19px 14px}.headline{align-items:start;flex-direction:column}.stats{grid-template-columns:1fr 1fr}.panel{padding:16px}.chapter-head{flex-direction:column}.lesson-row{align-items:start;flex-wrap:wrap}.lesson-row .actions{width:100%}}@media(max-width:470px){.stats{grid-template-columns:1fr}.twocol{grid-template-columns:1fr}}
[hidden]{display:none!important}</style></head><body><div class="layout">
<aside class="side"><a href="<?= e(app_url()) ?>" class="brand"><span class="logo">&lt;/&gt;</span>BrightFuture</a><nav class="nav">
<a href="<?= e(app_url('social.php')) ?>">♧ Bạn bè & tin nhắn</a>
<a href="<?= e(app_url('rooms.php')) ?>">◉ Phòng gọi nhóm</a><a href="<?= e(app_url('teacher/dashboard.php')) ?>">▦ Tổng quan</a><a class="current" href="<?= e(app_url('teacher/courses.php')) ?>">▤ Khóa học của tôi</a><a href="<?= e(app_url('teacher/exercises.php')) ?>">⌘ Quản lý bài tập</a><a href="<?= e(app_url('teacher/submissions.php')) ?>">✓ Chấm bài</a><a href="<?= e(app_url('profile.php')) ?>">☺ Hồ sơ</a><a href="<?= e(app_url()) ?>">⌂ Trang chủ</a></nav><div class="side-footer"><p><strong><?= e($user['full_name']) ?></strong><br>Giáo viên</p><form method="post" action="<?= e(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="logout">Đăng xuất</button></form></div></aside>
<div class="main"><header class="top"><strong>Không gian giảng dạy / Quản lý bài giảng</strong><span class="helper"><?= e($user['full_name']) ?></span></header><nav class="mobilebar"><a href="<?= e(app_url('teacher/dashboard.php')) ?>">Tổng quan</a><a href="<?= e(app_url('teacher/courses.php')) ?>">Khóa học</a><a href="<?= e(app_url('teacher/submissions.php')) ?>">Chấm bài</a></nav><main class="content"><div class="crumb"><a href="<?= e(app_url('teacher/courses.php')) ?>">Khóa học của tôi</a> / <?= e($course['title']) ?></div><div class="headline"><div><h1>Quản lý chương & bài giảng</h1><p class="subtitle"><?= e($course['title']) ?> · <?= $course['status']==='published'?'Đã công khai':'Bản nháp' ?></p></div><a class="btn btn-soft" href="<?= e(app_url('teacher/courses.php')) ?>">← Danh sách khóa học</a></div>
<?php if($flash): ?><div class="flash <?= ($flash['type']??'')==='success'?'success':'error' ?>" role="status"><?= e((string)($flash['message']??'')) ?></div><?php endif; ?>
<div class="panel" style="margin-bottom:16px"><strong>Kiểm tra tải video MP4 trên XAMPP</strong><p class="panel-caption">Apache đang dùng cấu hình PHP: <code><?= e((string)(php_ini_loaded_file() ?: 'Không xác định')) ?></code></p>
<p class="panel-caption">file_uploads: <b><?= filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)?'On':'Off' ?></b> · upload_max_filesize: <b><?= e((string)ini_get('upload_max_filesize')) ?></b> · post_max_size: <b><?= e((string)ini_get('post_max_size')) ?></b> · Thư mục lưu: <code><?= e(bf_video_dir()) ?></code></p>
<p class="panel-caption">Thư mục video: <b><?= is_dir(bf_video_dir()) ? (is_writable(bf_video_dir())?'Đã có, ghi được':'Đã có nhưng không ghi được') : 'Chưa tạo (tự tạo khi upload)' ?></b> · Hãy kiểm tra nút tải MP4 ở biểu mẫu bên phải và thử một video dưới 10 MB trước.</p></div>
<div class="stats"><div class="stat"><small>Tổng chương học</small><strong><?= count($chapters) ?></strong></div><div class="stat"><small>Tổng bài giảng</small><strong><?= count($lessons) ?></strong></div><div class="stat"><small>Bài giảng công khai</small><strong><?= $publishedCount ?></strong></div></div>
<div class="columns"><section><div class="panel"><h2>Danh sách chương và bài học</h2><?php if(!$chapters): ?><div class="empty">Chưa có chương học nào. Tạo chương ở biểu mẫu bên phải để bắt đầu.</div><?php endif; ?><?php foreach($chapters as $ch): ?><div class="chapter"><div class="chapter-head"><div><strong>Chương <?= (int)$ch['position'] ?>: <?= e($ch['title']) ?></strong><p><?= (int)$ch['lesson_count'] ?> bài giảng<?php if($ch['description']): ?> · <?= e($ch['description']) ?><?php endif; ?></p></div><div class="actions"><a class="small" href="<?= e(lm_url($courseId,['edit_chapter'=>(int)$ch['id']])) ?>#chapter-form">Sửa</a><form method="post" onsubmit="return confirm('Xóa chương sẽ xóa tất cả bài giảng, tài liệu, bài tập và tiến độ liên quan. Bạn chắc chắn?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_chapter"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="chapter_id" value="<?= (int)$ch['id'] ?>"><button class="small danger">Xóa</button></form></div></div><?php foreach(($grouped[(int)$ch['id']]??[]) as $lesson): ?><div class="lesson-row"><div class="lesson-icon"><?= $lesson['lesson_type']==='video'?'▶':($lesson['lesson_type']==='code'?'{}':'≡') ?></div><div class="lesson-info"><strong><?= (int)$lesson['position'] ?>. <?= e($lesson['title']) ?></strong><small><?= e($lesson['lesson_type']) ?> · <?= (int)$lesson['duration_minutes'] ?> phút <?= (int)$lesson['is_preview']===1?'· Xem trước':'' ?></small></div><span class="pill <?= $lesson['status']==='published'?'pub':'' ?>"><?= $lesson['status']==='published'?'Công khai':'Bản nháp' ?></span><div class="actions"><a class="small" href="<?= e(app_url('discussions.php?lesson='.(int)$lesson['id'])) ?>">Thảo luận</a><a class="small" style="background:#e0f2fe;color:#075985" href="<?= e(app_url('teacher/exercises.php?'.http_build_query(['course_id'=>$courseId,'lesson_id'=>(int)$lesson['id'],'create'=>1]))) ?>">+ Trắc nghiệm bài này</a><a class="small" href="<?= e(lm_url($courseId,['edit_lesson'=>(int)$lesson['id']])) ?>#lesson-form">Sửa</a><form method="post" onsubmit="return confirm('Xóa bài giảng cùng bài tập, tài liệu và tiến độ liên quan?')"><?= csrf_field() ?><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="lesson_id" value="<?= (int)$lesson['id'] ?>"><input type="hidden" name="action" value="delete_lesson"><button class="small danger">Xóa</button></form></div></div><?php endforeach; ?></div><?php endforeach; ?></div></section>
<section><div id="chapter-form" class="panel form-anchor"><h2><?= $editChapter?'Chỉnh sửa chương học':'Tạo chương mới' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="<?= $editChapter?'update_chapter':'create_chapter' ?>"><?php if($editChapter): ?><input type="hidden" name="chapter_id" value="<?= (int)$editChapter['id'] ?>"><?php endif; ?><div class="field"><label for="chapter-title">Tên chương *</label><input id="chapter-title" name="title" required maxlength="255" value="<?= e($editChapter['title']??'') ?>" placeholder="Ví dụ: Làm quen với Python"></div><div class="field"><label for="chapter-description">Mô tả chương</label><textarea id="chapter-description" name="description" rows="3"><?= e($editChapter['description']??'') ?></textarea></div><div class="field"><label for="chapter-position">Thứ tự hiển thị</label><input id="chapter-position" type="number" min="1" max="100000" name="position" value="<?= (int)($editChapter['position']??count($chapters)+1) ?>"></div><div class="footer-actions"><button class="btn btn-primary"><?= $editChapter?'Lưu thay đổi':'Tạo chương' ?></button><?php if($editChapter): ?><a class="btn btn-neutral" href="<?= e(lm_url($courseId)) ?>#chapter-form">Hủy sửa</a><?php endif; ?></div></form></div>
<div id="lesson-form" class="panel form-anchor"><h2><?= $editLesson?'Chỉnh sửa bài giảng':'Thêm bài giảng mới' ?></h2><?php if(!$chapters): ?><p class="panel-caption">Bạn cần tạo ít nhất một chương học trước khi thêm bài giảng.</p><?php else: ?><form method="post" enctype="multipart/form-data" action="<?= e(lm_url($courseId)) ?>#lesson-form"><?= csrf_field() ?><input type="hidden" name="MAX_FILE_SIZE" value="209715200"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="action" value="<?= $editLesson?'update_lesson':'create_lesson' ?>"><?php if($editLesson): ?><input type="hidden" name="lesson_id" value="<?= (int)$editLesson['id'] ?>"><?php endif; ?><div class="field"><label for="lesson-chapter">Chương *</label><select id="lesson-chapter" name="chapter_id" required><?php foreach($chapters as $ch): ?><option value="<?= (int)$ch['id'] ?>" <?= (int)($editLesson['chapter_id']??$chapters[0]['id'])===(int)$ch['id']?'selected':'' ?>><?= e($ch['title']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="lesson-title">Tên bài giảng *</label><input id="lesson-title" name="title" maxlength="255" required value="<?= e($editLesson['title']??'') ?>" placeholder="Ví dụ: Biến và kiểu dữ liệu"></div><div class="twocol"><div class="field"><label for="lesson-type">Loại bài học</label><select id="lesson-type" name="lesson_type"><?php foreach(['text'=>'Lý thuyết','video'=>'Video','code'=>'Code','mixed'=>'Kết hợp'] as $v=>$name): ?><option value="<?= e($v) ?>" <?= ($editLesson['lesson_type']??'text')===$v?'selected':'' ?>><?= e($name) ?></option><?php endforeach; ?></select></div><div class="field"><label for="lesson-position">Thứ tự</label><input id="lesson-position" type="number" min="1" max="100000" name="position" value="<?= (int)($editLesson['position']??1) ?>"></div></div><div class="field"><label for="lesson-content">Nội dung lý thuyết</label><textarea id="lesson-content" name="content" rows="7" placeholder="Nhập nội dung bài học (văn bản thuần)..."><?= e($editLesson['content']??'') ?></textarea><p class="helper">Nội dung được lưu dạng văn bản thuần; giao diện học viên phải escape HTML khi hiển thị.</p></div><div class="field"><label for="lesson-source">Nguồn video</label><select id="lesson-source" name="video_source">
<?php $sourceNow=$editLesson['video_source']??'none'; if($sourceNow==='none' && !empty($editLesson['video_url'])) $sourceNow='youtube'; ?>
<option value="none" <?= $sourceNow==='none'?'selected':'' ?>>Không có video</option>
<option value="youtube" <?= $sourceNow==='youtube'?'selected':'' ?>>YouTube</option>
<option value="upload" <?= $sourceNow==='upload'?'selected':'' ?>>Tải MP4 từ máy tính</option></select></div>
<div id="youtube-input" class="field"><label for="lesson-video">Link YouTube (HTTPS)</label><input id="lesson-video" type="url" name="video_url" maxlength="1000" value="<?= e($editLesson['video_url']??'') ?>" placeholder="https://www.youtube.com/watch?v=..."></div>
<div id="mp4-input" class="field"><label for="lesson-mp4">Tải video .mp4 (tối đa 200 MB)</label><input id="lesson-mp4" type="file" name="video_mp4" accept="video/mp4,.mp4"><p id="mp4-selected" class="helper"></p><p class="helper">Giới hạn PHP đang dùng: upload_max_filesize = <strong><?= e((string)ini_get('upload_max_filesize')) ?></strong>, post_max_size = <strong><?= e((string)ini_get('post_max_size')) ?></strong>. <?php if($editLesson && !empty($editLesson['video_file_path'])): ?>Đã có MP4; không chọn tệp mới nếu muốn giữ video cũ.<?php endif; ?></p></div>
<div class="field"><label for="lesson-seconds">Thời lượng video (giây, tùy chọn)</label><input id="lesson-seconds" type="number" name="video_duration_seconds" min="0" max="86400" value="<?= (int)($editLesson['video_duration_seconds']??0) ?>"></div><div class="twocol"><div class="field"><label for="lesson-language">Ngôn ngữ code</label><input id="lesson-language" name="code_language" maxlength="50" value="<?= e($editLesson['code_language']??'') ?>" placeholder="python"></div><div class="field"><label for="lesson-duration">Thời lượng (phút)</label><input id="lesson-duration" type="number" name="duration_minutes" min="0" max="100000" value="<?= (int)($editLesson['duration_minutes']??0) ?>"></div></div><div class="field"><label for="lesson-code">Code mẫu</label><textarea id="lesson-code" name="code_example" rows="7" spellcheck="false" style="font-family:Consolas,monospace" placeholder="print('Hello BrightFuture')"><?= e($editLesson['code_example']??'') ?></textarea></div><div class="field"><label for="lesson-status">Trạng thái bài giảng</label><select id="lesson-status" name="status"><option value="draft" <?= ($editLesson['status']??'draft')==='draft'?'selected':'' ?>>Bản nháp</option><option value="published" <?= ($editLesson['status']??'draft')==='published'?'selected':'' ?>>Công khai</option></select></div><label class="check"><input type="checkbox" name="is_preview" value="1" <?= (int)($editLesson['is_preview']??0)===1?'checked':'' ?>>Cho phép xem trước bài học</label><div class="footer-actions"><button class="btn btn-primary"><?= $editLesson?'Lưu bài giảng':'Thêm bài giảng' ?></button><?php if($editLesson): ?><a class="btn btn-neutral" href="<?= e(lm_url($courseId)) ?>#lesson-form">Hủy sửa</a><?php endif; ?></div></form><?php endif; ?></div></section></div><p class="panel-caption">Lưu ý: Xóa chương hoặc bài học sẽ xóa dữ liệu phụ thuộc theo khóa ngoại ON DELETE CASCADE. Thứ tự trùng nhau được sắp xếp phụ theo ID.</p></main></div></div><script>
(function(){const sel=document.getElementById('lesson-source');if(!sel)return;
const yt=document.getElementById('youtube-input'), mp=document.getElementById('mp4-input');
function refresh(){yt.hidden=sel.value!=='youtube';mp.hidden=sel.value!=='upload';}
sel.addEventListener('change',refresh);refresh();
const mp4=document.getElementById('lesson-mp4'), info=document.getElementById('mp4-selected');
if(mp4&&info)mp4.addEventListener('change',()=>{const f=mp4.files&&mp4.files[0];info.textContent=f?'Đã chọn: '+f.name+' ('+(f.size/1024/1024).toFixed(1)+' MB)'+(f.size>209715200?' — vượt giới hạn 200 MB!':''):'';});})();
</script></body></html>
