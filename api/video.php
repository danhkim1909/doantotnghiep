<?php
/** BrightFuture — protected MP4 streaming with byte ranges. PHP 8+. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/permissions.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Referrer-Policy: same-origin');
function bf_video_error(int $code): never { http_response_code($code); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) { header('Allow: GET, HEAD'); bf_video_error(405); }
$lessonId=filter_var($_GET['lesson_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$lessonId) bf_video_error(400);
try {
    $pdo=db();
    $stmt=$pdo->prepare('SELECT l.video_file_path,l.video_source,l.status AS lesson_status,l.is_preview, c.id AS course_id,c.status AS course_status,c.teacher_id FROM lessons l JOIN course_chapters ch ON ch.id=l.chapter_id JOIN courses c ON c.id=ch.course_id WHERE l.id=:id LIMIT 1');
    $stmt->execute(['id'=>$lessonId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['video_source']!=='upload') bf_video_error(404);
    $user=current_user();
    $manager=$user && ($user['role']===ROLE_ADMIN || ($user['role']===ROLE_TEACHER && (int)$user['id']===(int)$row['teacher_id']));
    $published=$row['course_status']==='published' && $row['lesson_status']==='published';
    if (!$manager && !$published) bf_video_error(404);
    $enrolled=false;
    if ($user && $user['role']===ROLE_STUDENT) {
        $q=$pdo->prepare("SELECT 1 FROM enrollments WHERE student_id=:student AND course_id=:course AND status IN ('active','completed') LIMIT 1");
        $q->execute(['student'=>$user['id'],'course'=>$row['course_id']]);
        $enrolled=(bool)$q->fetchColumn();
    }
    if (!$manager && !$enrolled && !((int)$row['is_preview']===1 && $published)) bf_video_error($user ? 403 : 401);
    $name=(string)$row['video_file_path'];
    if (!preg_match('/\A[a-zA-Z0-9_-]+\.mp4\z/D',$name)) bf_video_error(404);
    $env=getenv('BRIGHTFUTURE_VIDEO_DIR');
    $dir=is_string($env)&&trim($env)!=='' ? rtrim($env,'/\\') : dirname(__DIR__,3).DIRECTORY_SEPARATOR.'brightfuture-storage'.DIRECTORY_SEPARATOR.'videos';
    $file=$dir.DIRECTORY_SEPARATOR.$name;
    if (!is_file($file)||!is_readable($file)) bf_video_error(404);
    $size=filesize($file);
    if ($size===false || $size<1) bf_video_error(404);
    $start=0;$end=$size-1;$partial=false;
    $range=$_SERVER['HTTP_RANGE']??'';
    if ($range!=='') {
        if (!preg_match('/\Abytes=(\d*)-(\d*)\z/D',$range,$m) || ($m[1]===''&&$m[2]==='')) {
            header('Content-Range: bytes */'.$size);bf_video_error(416);
        }
        if ($m[1]==='') {
            $tail=(int)$m[2]; if ($tail<=0) {header('Content-Range: bytes */'.$size);bf_video_error(416);}
            $start=max(0,$size-$tail);
        } else {
            $start=(int)$m[1];
            if ($m[2]!=='') $end=min($size-1,(int)$m[2]);
        }
        if ($start>=$size || $end<$start) {header('Content-Range: bytes */'.$size);bf_video_error(416);}
        $partial=true;
    }
    $length=$end-$start+1;
    http_response_code($partial?206:200);
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.$length);
    header('Content-Disposition: inline; filename="lesson.mp4"');
    if ($partial) header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
    // Prevent buffering whole videos into PHP memory.
    while (ob_get_level()>0) ob_end_clean();
    $handle=fopen($file,'rb'); if (!$handle) exit;
    fseek($handle,$start);$remaining=$length;
    while ($remaining>0&&!feof($handle)&&!connection_aborted()) {
        $chunk=fread($handle,min(1024*1024,$remaining));
        if ($chunk===false || $chunk==='') break;
        echo $chunk;$remaining-=strlen($chunk);flush();
    }
    fclose($handle);
} catch (Throwable $ex) {
    error_log('[BrightFuture api/video] '.$ex->getMessage());
    if (!headers_sent()) bf_video_error(500);
}
