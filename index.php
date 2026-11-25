<?php
/**
 * ====================================================
 * BRIGHTFUTURE - TRANG CHỦ / LANDING PAGE
 * File: index.php
 * PHP 8.0+ / MySQL PDO
 * Dành cho khách vãng lai và giới thiệu nền tảng
 * ====================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';

$currentUser = is_logged_in() ? current_user() : null;
$flash = get_flash();

$pdo = db();

// Lấy 3 khóa học nổi bật đã công khai
$featuredCourses = [];
$totalStudents = 0;
$totalCourses = 0;
$totalLessons = 0;

try {
    $stmt = $pdo->query(
        "SELECT c.id, c.title, c.description, c.category, c.thumbnail, c.level, u.full_name AS teacher_name
         FROM courses c
         JOIN users u ON u.id = c.teacher_id
         WHERE c.status = 'published' AND c.deleted_at IS NULL
         ORDER BY c.created_at DESC
         LIMIT 3"
    );
    $featuredCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Thống kê nền tảng cho khách tham khảo
    $totalCourses = (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn();
    $totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL")->fetchColumn();
    $totalLessons = (int) $pdo->query("SELECT COUNT(*) FROM lessons WHERE status = 'published'")->fetchColumn();
} catch (Throwable $e) {
    error_log('[BrightFuture Landing] ' . $e->getMessage());
}

function bf_landing_thumb(?string $path): ?string
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
    <meta name="description" content="BrightFuture - Nền tảng học lập trình trực tuyến thực chiến, bài bản và hiệu quả.">
    <title><?= e(APP_NAME . ' — Học lập trình bài bản & thực chiến') ?></title>
    <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-public.css')) ?>">
</head>
<body class="bf-public">

<!-- HEADER CHÍNH -->
<header class="bf-public-header">
    <div class="bf-public-header-inner">
        <a class="bf-public-brand" href="<?= e(app_url()) ?>">
            <span class="bf-public-brand-mark">BF</span>
            <span>BrightFuture</span>
        </a>
        <nav class="bf-public-nav" aria-label="Điều hướng chính">
            <a href="<?= e(app_url('courses.php')) ?>">Khám phá khóa học</a>
            <a href="#tinh-nang">Tính năng nổi bật</a>
            <?php if ($currentUser): ?>
                <?php
                $dashboardUrl = match ($currentUser['role'] ?? 'student') {
                    'admin' => app_url('admin/dashboard.php'),
                    'teacher' => app_url('teacher/dashboard.php'),
                    default => app_url('student/dashboard.php')
                };
                ?>
                <a class="bf-btn-primary" style="padding: 8px 16px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;" href="<?= e($dashboardUrl) ?>">
                    <?= bf_icon('dashboard') ?> Bàn học của tôi
                </a>
            <?php else: ?>
                <a href="<?= e(app_url('login.php')) ?>">Đăng nhập</a>
                <a class="bf-btn-primary" style="padding: 8px 16px; font-size: 13px;" href="<?= e(app_url('register.php')) ?>">
                    Bắt đầu học ngay
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="bf-public-main">
    <?php if ($flash): ?>
    <div style="max-width: 1200px; margin: 16px auto 0; padding: 0 24px;">
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="display: flex; align-items: center; gap: 10px; border-radius: 10px; padding: 12px 18px; font-weight: 500; font-size: 14px; background: <?= $flash['type'] === 'success' ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $flash['type'] === 'success' ? '#065f46' : '#991b1b' ?>; border: 1px solid <?= $flash['type'] === 'success' ? '#a7f3d0' : '#fecaca' ?>;">
            <?= bf_icon($flash['type'] === 'success' ? 'check' : 'alert') ?>
            <span><?= e($flash['message']) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- HERO SECTION -->
    <section class="bf-hero">
        <div class="bf-hero-badge">
            <span class="dot"></span>
            Nền tảng học lập trình thế hệ mới
        </div>
        <h1>Học lập trình bài bản, thực chiến từng dòng code</h1>
        <p>
            Trải nghiệm lộ trình học tập tuần tự, bài tập thực tế có chấm điểm chi tiết, 
            và môi trường học tập tập trung giúp bạn nhanh chóng làm chủ công nghệ.
        </p>
        <div class="bf-hero-actions">
            <?php if ($currentUser): ?>
                <?php
                $dashboardUrl = match ($currentUser['role'] ?? 'student') {
                    'admin' => app_url('admin/dashboard.php'),
                    'teacher' => app_url('teacher/dashboard.php'),
                    default => app_url('student/dashboard.php')
                };
                ?>
                <a class="bf-btn-primary" href="<?= e($dashboardUrl) ?>">
                    <?= bf_icon('arrow') ?> Vào bàn học của bạn
                </a>
            <?php else: ?>
                <a class="bf-btn-primary" href="<?= e(app_url('register.php')) ?>">
                    <?= bf_icon('arrow') ?> Đăng ký học miễn phí
                </a>
            <?php endif; ?>
            <a class="bf-btn-outline" href="<?= e(app_url('courses.php')) ?>">
                <?= bf_icon('search') ?> Xem các khóa học
            </a>
        </div>
    </section>

    <!-- STATS STRIP -->
    <div class="bf-stats-strip">
        <div class="bf-stat-item">
            <strong><?= number_format(max(1, $totalCourses)) ?>+</strong>
            <span>Khóa học chuyên sâu</span>
        </div>
        <div class="bf-stat-item">
            <strong><?= number_format(max(1, $totalLessons)) ?>+</strong>
            <span>Bài học & bài tập</span>
        </div>
        <div class="bf-stat-item">
            <strong><?= number_format(max(1, $totalStudents)) ?>+</strong>
            <span>Học viên đồng hành</span>
        </div>
    </div>

    <!-- TÍNH NĂNG NỔI BẬT -->
    <section id="tinh-nang" class="bf-features-section">
        <div class="bf-section-title-wrap">
            <h2>Tại sao chọn BrightFuture?</h2>
            <p>Được thiết kế tối ưu cho trải nghiệm người học, loại bỏ hoàn toàn sự lộn xộn để bạn tập trung tiếp thu kiến thức.</p>
        </div>

        <div class="bf-features-grid">
            <div class="bf-feature-card">
                <div class="bf-feature-icon">
                    <?= bf_icon('courses') ?>
                </div>
                <h3>Lộ trình học mở khóa</h3>
                <p>Nội dung được cấu trúc theo chương và cấp độ từ cơ bản đến nâng cao. Hoàn thành bài trước để mở khóa bài tiếp theo.</p>
            </div>

            <div class="bf-feature-card">
                <div class="bf-feature-icon">
                    <?= bf_icon('check') ?>
                </div>
                <h3>Bài tập & Quiz tương tác</h3>
                <p>Kiểm tra kiến thức ngay sau mỗi bài học bằng câu hỏi trắc nghiệm và bài tập nộp file, có giảng viên chấm và nhận xét.</p>
            </div>

            <div class="bf-feature-card">
                <div class="bf-feature-icon">
                    <?= bf_icon('reports') ?>
                </div>
                <h3>Chứng chỉ xác thực</h3>
                <p>Nhận chứng chỉ điện tử có mã định danh duy nhất sau khi hoàn thành toàn bộ khóa học để bổ sung vào hồ sơ nghề nghiệp.</p>
            </div>
        </div>
    </section>

    <!-- KHÓA HỌC MỚI NHẤT -->
    <?php if (!empty($featuredCourses)): ?>
    <section style="margin-top: 50px;">
        <div class="bf-public-heading">
            <div>
                <h2>Khóa học mới nhất</h2>
                <p>Khám phá các khóa học lập trình được giảng viên biên soạn công phu.</p>
            </div>
            <a class="bf-btn-outline" href="<?= e(app_url('courses.php')) ?>" style="white-space: nowrap;">
                Xem tất cả khóa học <?= bf_icon('arrow') ?>
            </a>
        </div>

        <div class="bf-course-grid">
            <?php foreach ($featuredCourses as $course): ?>
                <article class="bf-course-card">
                    <div class="bf-course-cover">
                        <?php if ($img = bf_landing_thumb($course['thumbnail'])): ?>
                            <img src="<?= e($img) ?>" alt="<?= e($course['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <?= bf_icon('courses') ?>
                        <?php endif; ?>
                    </div>
                    <div class="bf-course-body">
                        <span class="bf-course-tag"><?= e($levelLabels[$course['level']] ?? $course['level']) ?></span>
                        <h2><?= e($course['title']) ?></h2>
                        <span class="bf-course-teacher">Giảng viên: <?= e($course['teacher_name']) ?></span>
                        <p><?= e(truncate_text((string) ($course['description'] ?? ''), 120)) ?></p>
                        <a class="bf-course-action" href="<?= e(app_url('course.php?id=' . (int) $course['id'])) ?>">
                            <?= bf_icon('arrow') ?> Xem chi tiết khóa học
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA BANNER -->
    <section class="bf-cta-banner">
        <h2>Sẵn sàng bắt đầu hành trình lập trình của bạn?</h2>
        <p>Đăng ký tài khoản hoàn toàn miễn phí chỉ trong 1 phút và bắt đầu học ngay hôm nay.</p>
        <a class="bf-btn-primary" style="background: #fff; color: var(--bf-blue); font-size: 15px; padding: 14px 30px;" href="<?= e(app_url('register.php')) ?>">
            Tạo tài khoản học viên ngay
        </a>
    </section>
</main>

<!-- FOOTER -->
<footer class="bf-public-footer">
    <div class="bf-public-footer-inner">
        <div class="bf-footer-copy">
            © <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong>. Nền tảng học lập trình trực tuyến.
        </div>
        <div class="bf-footer-links">
            <a href="<?= e(app_url('courses.php')) ?>">Khóa học</a>
            <a href="<?= e(app_url('login.php')) ?>">Đăng nhập</a>
            <a href="<?= e(app_url('register.php')) ?>">Đăng ký</a>
        </div>
    </div>
</footer>

</body>
</html>
