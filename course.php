<?php
/**
 * ====================================================
 * BRIGHTFUTURE - CHI TIẾT KHÓA HỌC
 * File: course.php
 * PHP 8.0+ / MySQL PDO
 * ====================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/course_unlock.php';
require_once __DIR__ . '/includes/icons.php';

header('Cache-Control: private, no-store');

$pdo = db();
$who = current_user();
$studentId = ($who !== null && $who['role'] === ROLE_STUDENT) ? (int) $who['id'] : null;

$courseId = positive_int($_GET['id'] ?? ($_POST['course_id'] ?? null));

if ($courseId <= 0 || !bf_course_unlocked($pdo, $courseId, $studentId)) {
    http_response_code(404);
    render_http_error(
        404,
        'Khóa học chưa mở khóa',
        'Khóa học này chưa được mở khóa theo lộ trình hoặc chưa công khai.',
        app_url('courses.php')
    );
}

// Lấy thông tin chi tiết khóa học kèm thống kê
$stmt = $pdo->prepare(
    "SELECT c.*, u.full_name AS teacher_name,
            COALESCE(s.student_count, 0) AS student_count,
            COALESCE(s.chapter_count, 0) AS chapter_count,
            COALESCE(s.lesson_count, 0) AS lesson_count
     FROM courses c
     JOIN users u ON u.id = c.teacher_id
     LEFT JOIN view_course_statistics s ON s.course_id = c.id
     WHERE c.id = :id AND c.status = 'published' AND c.deleted_at IS NULL
     LIMIT 1"
);
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(404);
    render_http_error(404, 'Không tìm thấy', 'Khóa học không tồn tại hoặc đã bị xóa.', app_url('courses.php'));
}

// Kiểm tra trạng thái ghi danh của học viên
$isEnrolled = false;
if ($studentId) {
    $stmt = $pdo->prepare(
        "SELECT id, status 
         FROM enrollments 
         WHERE student_id = :sid AND course_id = :cid 
         LIMIT 1"
    );
    $stmt->execute(['sid' => $studentId, 'cid' => $courseId]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    $isEnrolled = ($enrollment && $enrollment['status'] !== 'cancelled');
}

$errorMessage = '';

// Xử lý đăng ký khóa học (Enroll)
if (is_post()) {
    require_csrf();

    if (!$studentId) {
        redirect('login.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'submit_review' && $isEnrolled) {
        $rating = (int) ($_POST['rating'] ?? 5);
        $comment = (string) ($_POST['comment'] ?? '');
        require_once __DIR__ . '/classes/Models/Review.php';
        $reviewModel = new \BrightFuture\Models\Review($pdo);
        if ($reviewModel->saveReview($courseId, $studentId, $rating, $comment)) {
            set_flash('success', 'Đánh giá của bạn đã được ghi nhận thành công!');
            redirect('course.php?id=' . $courseId . '#reviews');
        } else {
            $errorMessage = 'Vui lòng nhập nội dung đánh giá hợp lệ.';
        }
    } elseif ($action !== 'enroll') {
        $errorMessage = 'Yêu cầu không hợp lệ.';
    } elseif (!$isEnrolled) {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT status FROM courses WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $courseId]);

            if ($lock->fetchColumn() !== 'published' || !bf_course_unlocked($pdo, $courseId, $studentId)) {
                throw new RuntimeException('Khóa học chưa được mở khóa.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO enrollments (student_id, course_id, status)
                 VALUES (:sid, :cid, 'active')
                 ON DUPLICATE KEY UPDATE 
                    completed_at = IF(status = 'cancelled', NULL, completed_at),
                    status = IF(status = 'cancelled', 'active', status)"
            );
            $stmt->execute(['sid' => $studentId, 'cid' => $courseId]);

            $pdo->commit();
            redirect('student/learn.php?course_id=' . $courseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[BrightFuture enrollment] ' . $e->getMessage());
            $errorMessage = 'Không thể đăng ký lúc này. Vui lòng thử lại sau.';
        }
    }
}

// Lấy danh sách chương và bài giảng
$stmt = $pdo->prepare(
    "SELECT ch.id AS chapter_id, ch.title AS chapter_title,
            l.id AS lesson_id, l.title AS lesson_title, l.lesson_type, l.duration_minutes, l.is_preview
     FROM course_chapters ch
     LEFT JOIN lessons l ON l.chapter_id = ch.id AND l.status = 'published'
     WHERE ch.course_id = :id
     ORDER BY ch.position, ch.id, l.position, l.id"
);
$stmt->execute(['id' => $courseId]);
$lessonRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chapters = [];
$totalMinutes = 0;
foreach ($lessonRows as $row) {
    $cid = (int) $row['chapter_id'];
    if (!isset($chapters[$cid])) {
        $chapters[$cid] = [
            'title' => $row['chapter_title'],
            'lessons' => []
        ];
    }
    if (!empty($row['lesson_id'])) {
        $chapters[$cid]['lessons'][] = $row;
        $totalMinutes += (int) $row['duration_minutes'];
    }
}

require_once __DIR__ . '/classes/Models/Review.php';
$reviewModel = new \BrightFuture\Models\Review($pdo);
$reviews = $reviewModel->getCourseReviews($courseId);
$ratingSummary = $reviewModel->getCourseRatingSummary($courseId);

function bf_course_thumb(?string $path): ?string
{
    if (
        $path !== null &&
        preg_match('~^uploads/courses/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$~', $path)
    ) {
        return app_url($path);
    }
    return null;
}

$levelLabels = [
    'beginner' => 'Cơ bản',
    'intermediate' => 'Trung bình',
    'advanced' => 'Nâng cao'
];
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($course['title']) ?> | BrightFuture</title>
    <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-public.css')) ?>">
</head>
<body class="bf-public">

<header class="bf-public-header">
    <div class="bf-public-header-inner">
        <a class="bf-public-brand" href="<?= e(app_url()) ?>">
            <span class="bf-public-brand-mark">BF</span>
            <span>BrightFuture</span>
        </a>
        <nav class="bf-public-nav" aria-label="Điều hướng">
            <a href="<?= e(app_url('courses.php')) ?>">Khóa học</a>
            <?php if ($who): ?>
                <a href="<?= e(app_url('dashboard.php')) ?>">Tổng quan</a>
                <a href="<?= e(app_url('profile.php')) ?>">Hồ sơ</a>
            <?php else: ?>
                <a href="<?= e(app_url('login.php')) ?>">Đăng nhập</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="bf-public-main">
    <!-- Breadcrumb -->
    <div style="margin-bottom: 20px; font-size: 13px; color: var(--bf-muted);">
        <a href="<?= e(app_url('courses.php')) ?>" style="color: var(--bf-blue); font-weight: 600;">← Khám phá khóa học</a>
        <span style="margin: 0 8px;">/</span>
        <span><?= e($course['title']) ?></span>
    </div>

    <?= render_flash() ?>

    <?php if ($errorMessage): ?>
        <div class="bf-public-empty" style="border-color: var(--bf-danger); color: var(--bf-danger); margin-bottom: 24px;">
            <?= e($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div class="bf-detail-layout">
        <!-- CỘT NỘI DUNG CHÍNH -->
        <section class="bf-detail-main">
            <span class="bf-course-tag"><?= e($levelLabels[$course['level']] ?? $course['level']) ?></span>
            <h1 style="margin: 12px 0 16px;"><?= e($course['title']) ?></h1>

            <div style="display: flex; gap: 20px; font-size: 13px; color: var(--bf-muted); margin-bottom: 24px; flex-wrap: wrap;">
                <span>Giảng viên: <strong style="color: var(--bf-ink);"><?= e($course['teacher_name']) ?></strong></span>
                <span>•</span>
                <span>Học viên: <strong style="color: var(--bf-ink);"><?= number_format((int) $course['student_count']) ?></strong></span>
                <span>•</span>
                <span>Thời lượng: <strong style="color: var(--bf-ink);"><?= $totalMinutes ?> phút</strong></span>
            </div>

            <div style="line-height: 1.7; color: #334155; margin-bottom: 36px;">
                <h3 style="font-size: 16px; margin: 0 0 10px; color: var(--bf-ink);">Giới thiệu khóa học</h3>
                <p><?= nl2br(e((string) $course['description'])) ?></p>
            </div>

            <!-- MỤC LỤC BÀI GIẢNG -->
            <h2>Nội dung khóa học (<?= count($lessonRows) ?> bài giảng)</h2>

            <?php if (!empty($chapters)): ?>
                <?php foreach ($chapters as $chapter): ?>
                    <section class="bf-detail-chapter">
                        <h3><?= e($chapter['title']) ?> (<?= count($chapter['lessons']) ?> bài)</h3>
                        <?php foreach ($chapter['lessons'] as $lesson): ?>
                            <div class="bf-detail-lesson">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span><?= bf_icon('courses') ?></span>
                                    <span><?= e($lesson['lesson_title']) ?></span>
                                    <small style="color: var(--bf-muted);">(<?= (int) $lesson['duration_minutes'] ?> phút)</small>
                                </div>

                                <?php if ($isEnrolled): ?>
                                    <a class="bf-course-action" style="padding: 6px 14px; font-size: 12px; margin: 0;" href="<?= e(app_url('student/learn.php?id=' . (int) $lesson['lesson_id'])) ?>">
                                        <?= bf_icon('arrow') ?> Học bài
                                    </a>
                                <?php elseif (!empty($lesson['is_preview'])): ?>
                                    <span style="font-size: 11px; background: #eef2ff; color: var(--bf-blue); padding: 4px 8px; border-radius: 6px; font-weight: 700;">
                                        Xem trước
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bf-public-empty">
                    Khóa học hiện đang trong quá trình cập nhật nội dung bài giảng.
                </div>
            <?php endif; ?>

            <!-- ĐÁNH GIÁ & NHẬN XÉT KHÓA HỌC -->
            <section id="reviews" style="margin-top: 40px; border-top: 1px solid var(--bf-line); padding-top: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                    <h2 style="margin: 0; font-size: 20px;">Đánh giá từ học viên</h2>
                    <div style="display: flex; align-items: center; gap: 8px; background: #fef3c7; color: #92400e; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 14px;">
                        <span>⭐ <?= e((string)$ratingSummary['average']) ?> / 5</span>
                        <span style="color: #b45309; font-size: 12px; font-weight: 500;">(<?= (int)$ratingSummary['total'] ?> đánh giá)</span>
                    </div>
                </div>

                <!-- FORM VIẾT ĐÁNH GIÁ (DÀNH CHO HỌC VIÊN ĐÃ ĐĂNG KÝ) -->
                <?php if ($isEnrolled): ?>
                    <div style="background: #ffffff; border: 1px solid var(--bf-line); border-radius: 12px; padding: 20px; margin-bottom: 28px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <h3 style="font-size: 15px; margin: 0 0 12px;">Chia sẻ cảm nhận của bạn về khóa học</h3>
                        <form method="POST" action="<?= e(app_url('course.php?id=' . $courseId)) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <input type="hidden" name="action" value="submit_review">
                            
                            <div style="margin-bottom: 14px;">
                                <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 6px;">Đánh giá số sao:</label>
                                <select name="rating" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff;">
                                    <option value="5">⭐⭐⭐⭐⭐ (5 sao - Xuất sắc)</option>
                                    <option value="4">⭐⭐⭐⭐ (4 sao - Rất tốt)</option>
                                    <option value="3">⭐⭐⭐ (3 sao - Tạm được)</option>
                                    <option value="2">⭐⭐ (2 sao - Cần cải thiện)</option>
                                    <option value="1">⭐ (1 sao - Kém)</option>
                                </select>
                            </div>
                            
                            <div style="margin-bottom: 14px;">
                                <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 6px;">Nhận xét chi tiết:</label>
                                <textarea name="comment" rows="3" required placeholder="Khóa học mang lại giá trị gì cho bạn? Giảng viên dạy thế nào?..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 13px; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>

                            <button type="submit" class="bf-btn-primary" style="padding: 9px 20px; font-size: 13px; cursor: pointer;">
                                Gửi đánh giá
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- DANH SÁCH CÁC ĐÁNH GIÁ -->
                <?php if (!empty($reviews)): ?>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <?php foreach ($reviews as $rev): ?>
                            <div style="background: #ffffff; border: 1px solid var(--bf-line); border-radius: 12px; padding: 18px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="width: 34px; height: 34px; border-radius: 50%; background: #e0e7ff; color: #4338ca; display: grid; place-items: center; font-weight: 700; font-size: 13px;">
                                            <?= e(mb_substr((string)$rev['student_name'], 0, 1)) ?>
                                        </span>
                                        <div>
                                            <strong style="font-size: 13px; color: var(--bf-ink); display: block;"><?= e((string)$rev['student_name']) ?></strong>
                                            <span style="font-size: 11px; color: var(--bf-muted);"><?= e(date('d/m/Y', strtotime((string)$rev['created_at']))) ?></span>
                                        </div>
                                    </div>
                                    <div style="color: #f59e0b; font-size: 14px;">
                                        <?= str_repeat('⭐', (int)$rev['rating']) ?>
                                    </div>
                                </div>
                                <p style="margin: 0; font-size: 13px; color: #334155; line-height: 1.6;">
                                    <?= nl2br(e((string)$rev['comment'])) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; color: var(--bf-muted); font-size: 13px;">
                        Chưa có đánh giá nào cho khóa học này. Hãy là người đầu tiên trải nghiệm và chia sẻ nhận xét!
                    </div>
                <?php endif; ?>
            </section>
        </section>

        <!-- CỘT THÔNG TIN BÊN PHẢI -->
        <aside class="bf-detail-side">
            <?php if ($thumbUrl = bf_course_thumb($course['thumbnail'])): ?>
                <div style="border-radius: 12px; overflow: hidden; margin-bottom: 20px; aspect-ratio: 16/9; background: #eee;">
                    <img src="<?= e($thumbUrl) ?>" alt="<?= e($course['title']) ?>" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
            <?php endif; ?>

            <h2 style="font-size: 18px; margin-bottom: 16px;">Thông tin khóa học</h2>

            <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--bf-muted);">Trình độ:</span>
                    <strong><?= e($levelLabels[$course['level']] ?? $course['level']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--bf-muted);">Số chương:</span>
                    <strong><?= count($chapters) ?> chương</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--bf-muted);">Tổng số bài:</span>
                    <strong><?= count($lessonRows) ?> bài</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--bf-muted);">Chứng chỉ:</span>
                    <strong>Có cấp sau hoàn thành</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--bf-muted);">Học phí:</span>
                    <strong style="color: #16a34a;">Miễn phí</strong>
                </div>
            </div>

            <?php if ($isEnrolled): ?>
                <a class="bf-btn-primary" style="width: 100%; text-align: center;" href="<?= e(app_url('student/learn.php?course_id=' . $courseId)) ?>">
                    <?= bf_icon('arrow') ?> Vào học ngay
                </a>
            <?php elseif (!$studentId): ?>
                <a class="bf-btn-primary" style="width: 100%; text-align: center;" href="<?= e(app_url('login.php')) ?>">
                    Đăng nhập để đăng ký
                </a>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="action" value="enroll">
                    <button class="bf-btn-primary" type="submit" style="width: 100%; cursor: pointer;">
                        <?= bf_icon('check') ?> Đăng ký học ngay
                    </button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
</main>

</body>
</html>
