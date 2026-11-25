<?php

/**
 * BrightFuture - Course Completion Certificate
 * Hệ thống cấp và xác minh chứng chỉ hoàn thành khóa học.
 * Hỗ trợ in ấn / xuất PDF chuẩn khổ ngang A4 và tra cứu công khai.
 * PHP 8.0+ / PDO MySQL
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/portal_ui.php';
require_once __DIR__ . '/includes/icons.php';

$pdo = db();
$verification = strtoupper(trim(get_query('verify')));
$errors = [];
$publicCert = null;

// =========================================================================
// 1. CHẾ ĐỘ XÁC MINH CÔNG KHAI (Dành cho nhà tuyển dụng hoặc bên thứ 3)
// =========================================================================
if ($verification !== '') {
    if (preg_match('/^[A-F0-9]{32}$/', $verification)) {
        try {
            $stmt = $pdo->prepare('
                SELECT
                    cert.verification_code,
                    cert.issued_at,
                    u.full_name AS student_name,
                    c.id AS course_id,
                    c.title AS course_title,
                    t.full_name AS teacher_name
                FROM bf_certificates cert
                JOIN users u ON u.id = cert.student_id
                JOIN courses c ON c.id = cert.course_id
                LEFT JOIN users t ON t.id = c.teacher_id
                WHERE cert.verification_code = ?
                LIMIT 1
            ');
            $stmt->execute([$verification]);
            $publicCert = $stmt->fetch() ?: null;
        } catch (Throwable $ex) {
            error_log('[BrightFuture Certificate Verify] ' . $ex->getMessage());
            $errors[] = 'Hệ thống đang bảo trì cơ sở dữ liệu xác thực. Vui lòng thử lại sau.';
        }
    }
    ?>
    <!doctype html>
    <html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Xác minh chứng chỉ trực tuyến | BrightFuture Academy</title>
        <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
        <link rel="stylesheet" href="<?= e(app_url('css/bf-portal.css')) ?>">
        <link rel="stylesheet" href="<?= e(app_url('css/bf-public.css')) ?>">
        <link rel="stylesheet" href="<?= e(app_url('css/bf-toast.css')) ?>">
        <link rel="stylesheet" href="<?= e(app_url('css/bf-certificate.css')) ?>">
    </head>
    <body class="bf-public">
        <header class="bf-public-nav">
            <div class="bf-public-nav-container">
                <a href="<?= e(app_url('index.php')) ?>" class="bf-public-logo">
                    <span class="bf-logo-mark">BF</span>
                    <span class="bf-logo-text">BrightFuture</span>
                </a>
                <nav class="bf-public-nav-links">
                    <a href="<?= e(app_url('courses.php')) ?>">Khóa học</a>
                    <a href="<?= e(app_url('login.php')) ?>">Đăng nhập</a>
                </nav>
            </div>
        </header>

        <main class="bf-verify-wrap">
            <div class="bf-verify-card">
                <?php if ($publicCert): ?>
                    <div class="bf-verify-badge valid">✓</div>
                    <h1 style="font-size: 24px; font-weight: 800; margin: 0 0 8px; color: #065f46;">Chứng chỉ hợp lệ</h1>
                    <p style="color: var(--color-muted); margin: 0 0 20px;">
                        Chứng chỉ này được cấp chính thức bởi hệ thống đào tạo <strong>BrightFuture Academy</strong>.
                    </p>

                    <div class="bf-verify-details">
                        <div class="bf-verify-row">
                            <span class="bf-verify-label">Học viên tốt nghiệp:</span>
                            <span class="bf-verify-value"><?= e($publicCert['student_name']) ?></span>
                        </div>
                        <div class="bf-verify-row">
                            <span class="bf-verify-label">Khóa học hoàn thành:</span>
                            <span class="bf-verify-value"><?= e($publicCert['course_title']) ?></span>
                        </div>
                        <?php if (!empty($publicCert['teacher_name'])): ?>
                            <div class="bf-verify-row">
                                <span class="bf-verify-label">Giảng viên hướng dẫn:</span>
                                <span class="bf-verify-value"><?= e($publicCert['teacher_name']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="bf-verify-row">
                            <span class="bf-verify-label">Ngày cấp chứng chỉ:</span>
                            <span class="bf-verify-value"><?= e(date('d/m/Y H:i', strtotime($publicCert['issued_at']))) ?></span>
                        </div>
                        <div class="bf-verify-row">
                            <span class="bf-verify-label">Mã xác thực:</span>
                            <span class="bf-verify-value"><span class="bf-code-pill"><?= e($publicCert['verification_code']) ?></span></span>
                        </div>
                        <div class="bf-verify-row">
                            <span class="bf-verify-label">Trạng thái:</span>
                            <span class="bf-verify-value" style="color: #059669;">● Đã xác thực trên hệ thống</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                        <a href="<?= e(app_url('course.php?id=' . (int)$publicCert['course_id'])) ?>" class="bf-public-button">
                            Xem thông tin khóa học
                        </a>
                        <button type="button" class="bf-btn-secondary" onclick="copyVerifyUrl()">
                            Sao chép liên kết xác minh
                        </button>
                    </div>
                <?php else: ?>
                    <div class="bf-verify-badge invalid">✕</div>
                    <h1 style="font-size: 24px; font-weight: 800; margin: 0 0 8px; color: #991b1b;">Không tìm thấy chứng chỉ</h1>
                    <p style="color: var(--color-muted); margin: 0 0 24px;">
                        Mã xác minh <code class="bf-code-pill"><?= e($verification) ?></code> không tồn tại trên hệ thống hoặc đã bị thu hồi.
                    </p>
                    <div style="display: flex; gap: 12px; justify-content: center;">
                        <a href="<?= e(app_url('courses.php')) ?>" class="bf-public-button">
                            Khám phá khóa học khác
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <script src="<?= e(app_url('js/bf-toast.js')) ?>"></script>
        <script>
            function copyVerifyUrl() {
                navigator.clipboard.writeText(window.location.href).then(function() {
                    if (window.bfToast) {
                        window.bfToast.success('Đã sao chép liên kết xác minh chứng chỉ!');
                    } else {
                        alert('Đã sao chép liên kết xác minh chứng chỉ!');
                    }
                }).catch(function() {
                    alert('Không thể sao chép liên kết tự động. Hãy sao chép từ thanh địa chỉ trình duyệt.');
                });
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// =========================================================================
// 2. KHÔNG GIAN HỌC VIÊN ĐĂNG NHẬP
// =========================================================================
$user = require_role(ROLE_STUDENT);
$uid = (int) $user['id'];
$requestedCourseId = positive_int($_GET['course'] ?? $_POST['course'] ?? 0);

// Xử lý gửi yêu cầu nhận chứng chỉ
if (is_post()) {
    require_csrf();
    try {
        $cid = positive_int($_POST['course'] ?? 0);
        if (!$cid) {
            throw new DomainException('Khóa học không hợp lệ.');
        }

        // Kiểm tra xem học viên có đang theo học khóa học này không
        $stmtEnroll = $pdo->prepare('
            SELECT 1 FROM enrollments e
            JOIN courses c ON c.id = e.course_id
            WHERE e.student_id = ? AND e.course_id = ?
              AND e.status IN ("active", "completed")
              AND c.status = "published"
              AND c.deleted_at IS NULL
        ');
        $stmtEnroll->execute([$uid, $cid]);
        if (!$stmtEnroll->fetchColumn()) {
            throw new DomainException('Bạn chưa ghi danh vào khóa học này.');
        }

        // Kiểm tra điều kiện hoàn thành 100% bài học
        $stmtProgress = $pdo->prepare('
            SELECT
                COUNT(*) AS total_lessons,
                COALESCE(SUM(CASE WHEN lp.status = "completed" THEN 1 ELSE 0 END), 0) AS completed_lessons
            FROM lessons l
            JOIN course_chapters ch ON ch.id = l.chapter_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
            WHERE ch.course_id = ? AND l.status = "published"
        ');
        $stmtProgress->execute([$uid, $cid]);
        $prog = $stmtProgress->fetch();

        $totalLessons = (int) ($prog['total_lessons'] ?? 0);
        $completedLessons = (int) ($prog['completed_lessons'] ?? 0);

        if ($totalLessons === 0 || $completedLessons < $totalLessons) {
            throw new DomainException('Bạn cần hoàn thành tất cả bài giảng và bài tập để đủ điều kiện cấp chứng chỉ.');
        }

        // Tạo mã xác minh 32 ký tự ngẫu nhiên
        $newCode = strtoupper(bin2hex(random_bytes(16)));
        $stmtInsert = $pdo->prepare('
            INSERT IGNORE INTO bf_certificates (student_id, course_id, verification_code)
            VALUES (?, ?, ?)
        ');
        $stmtInsert->execute([$uid, $cid, $newCode]);

        redirect('certificate.php?course=' . $cid . '&claimed=1', 303);
    } catch (DomainException $ex) {
        $errors[] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('[BrightFuture Certificate Issue] ' . $ex->getMessage());
        $errors[] = 'Không thể cấp chứng chỉ tại thời điểm này. Vui lòng thử lại sau.';
    }
}

// Tải danh sách khóa học và tình trạng chứng chỉ của học viên
$courses = [];
try {
    $stmt = $pdo->prepare('
        SELECT
            c.id,
            c.title,
            t.full_name AS teacher_name,
            cert.verification_code,
            cert.issued_at,
            (
                SELECT COUNT(*)
                FROM lessons l
                JOIN course_chapters ch ON ch.id = l.chapter_id
                WHERE ch.course_id = c.id AND l.status = "published"
            ) AS total_lessons,
            (
                SELECT COUNT(*)
                FROM lessons l
                JOIN course_chapters ch ON ch.id = l.chapter_id
                JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ? AND lp.status = "completed"
                WHERE ch.course_id = c.id AND l.status = "published"
            ) AS completed_lessons
        FROM enrollments en
        JOIN courses c ON c.id = en.course_id
        LEFT JOIN users t ON t.id = c.teacher_id
        LEFT JOIN bf_certificates cert ON cert.student_id = en.student_id AND cert.course_id = c.id
        WHERE en.student_id = ?
          AND en.status IN ("active", "completed")
          AND c.status = "published"
          AND c.deleted_at IS NULL
        ORDER BY cert.issued_at DESC, en.enrolled_at DESC
    ');
    $stmt->execute([$uid, $uid]);
    $courses = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[BrightFuture Certificate List] ' . $ex->getMessage());
    $errors[] = 'Không thể tải danh sách chứng chỉ. Vui lòng thử lại sau.';
}

// Tìm khóa học đang được chọn để xem chứng chỉ
$selectedCourse = null;
if ($requestedCourseId > 0) {
    foreach ($courses as $c) {
        if ((int)$c['id'] === $requestedCourseId && !empty($c['verification_code'])) {
            $selectedCourse = $c;
            break;
        }
    }
}

$pageTitle = 'Chứng chỉ học tập';
$activeTab = 'certificates';
$extraCss = ['css/bf-certificate.css'];
$portalRoleTitle = 'Không gian học tập';
require_once __DIR__ . '/views/layouts/portal_head.php';
echo bf_unified_sidebar($user, $activeTab);
require_once __DIR__ . '/views/layouts/portal_topbar.php';
?>
<main class="content container cert-content">
<header class="bf-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 6px;">Chứng chỉ hoàn thành khóa học</h1>
        <p style="color: #64748b; font-size: 14px; margin: 0;">Học viên hoàn thành 100% bài giảng và bài tập theo yêu cầu sẽ được cấp chứng chỉ điện tử có mã định danh xác minh.</p>
    </div>
    <a class="bf-muted" href="<?= e(app_url('student/progress.php')) ?>" style="color: #4f46e5; text-decoration: none; font-size: 13px; font-weight: 600;">← Xem tiến độ học tập</a>
</header>

<?php foreach ($errors as $error): ?>
    <div class="bf-note bf-error" role="alert" style="margin-bottom: 20px;"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if (get_query('claimed') === '1'): ?>
    <div class="bf-note bf-success" role="status" style="margin-bottom: 20px;">
        Chúc mừng bạn đã hoàn thành khóa học và được cấp chứng chỉ thành công!
    </div>
<?php endif; ?>

<!-- =======================================================
     KHUNG HIỂN THỊ CHỨNG CHỈ (NẾU ĐANG CHỌN XEM)
     ======================================================= -->
<?php if ($selectedCourse): ?>
    <section class="bf-cert-wrapper">
        <div class="bf-cert-frame">
            <div class="bf-cert-inner-border">
                <div class="bf-cert-watermark" aria-hidden="true">BRIGHTFUTURE ACADEMY · OFFICIAL VERIFIED</div>
                <div class="bf-cert-emblem">★</div>
                <div class="bf-cert-academy">BrightFuture Academy · International Education</div>
                <h1 class="bf-cert-title">CHỨNG CHỈ TỐT NGHIỆP</h1>
                <p class="bf-cert-subtitle">Chứng nhận chính thức từ ban học thuật</p>

                <p class="bf-cert-desc">Chứng nhận học viên:</p>
                <div class="bf-cert-recipient"><?= e($user['full_name']) ?></div>

                <p class="bf-cert-desc">
                    Đã hoàn thành xuất sắc toàn bộ chương trình đào tạo chuyên môn và đạt tiêu chuẩn đánh giá của khóa học:
                </p>
                <div class="bf-cert-course-name">“<?= e($selectedCourse['title']) ?>”</div>

                <div class="bf-cert-footer">
                    <div class="bf-cert-meta">
                        <div><strong>Ngày cấp:</strong> <?= e(date('d/m/Y', strtotime($selectedCourse['issued_at']))) ?></div>
                        <div><strong>Mã chứng chỉ:</strong> <?= e($selectedCourse['verification_code']) ?></div>
                        <div><strong>Tra cứu trực tuyến:</strong> <?= e(app_url('certificate.php?verify=' . $selectedCourse['verification_code'])) ?></div>
                    </div>
                    <div class="bf-cert-seal">
                        <div class="bf-cert-sign-line"></div>
                        <div style="font-weight: 700; color: #1e3a8a;"><?= e($selectedCourse['teacher_name'] ?: 'Ban Giám Hiệu BrightFuture') ?></div>
                        <small style="color: var(--color-muted);">Giảng viên phụ trách</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="bf-cert-actions bf-actions-bar">
            <button type="button" class="bf-btn" onclick="window.print()">
                <?= bf_icon('check', 'bf-button-icon') ?> In / Tải PDF chứng chỉ
            </button>
            <button type="button" class="bf-btn-secondary" onclick="copyCertLink('<?= e(app_url('certificate.php?verify=' . $selectedCourse['verification_code'])) ?>')">
                Sao chép liên kết xác minh
            </button>
            <a class="bf-muted" href="<?= e(app_url('certificate.php')) ?>" style="align-self: center;">
                Đóng chứng chỉ
            </a>
        </div>
    </section>
<?php endif; ?>

<!-- =======================================================
     DANH SÁCH KHÓA HỌC & TRẠNG THÁI CHỨNG CHỈ
     ======================================================= -->
<section class="bf-panel bf-list-section">
    <h2>Khóa học của bạn & Tiến độ nhận chứng chỉ</h2>

    <?php if (!$courses): ?>
        <div class="bf-empty" style="text-align: center; padding: 40px 20px;">
            <p>Bạn chưa đăng ký khóa học nào. Hãy khám phá thư viện khóa học để bắt đầu lộ trình học tập!</p>
            <a href="<?= e(app_url('courses.php')) ?>" class="bf-btn" style="margin-top: 12px; display: inline-block;">
                Khám phá khóa học
            </a>
        </div>
    <?php else: ?>
        <div class="bf-cert-grid">
            <?php foreach ($courses as $c):
                $total = (int) $c['total_lessons'];
                $done = (int) $c['completed_lessons'];
                $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
                $hasCert = !empty($c['verification_code']);
                $canClaim = !$hasCert && $total > 0 && $done === $total;
            ?>
                <div class="bf-cert-card">
                    <div>
                        <div class="bf-cert-card-header">
                            <h3 class="bf-cert-card-title"><?= e($c['title']) ?></h3>
                            <?php if ($hasCert): ?>
                                <span class="bf-chip bf-chip-success">Đã cấp</span>
                            <?php elseif ($canClaim): ?>
                                <span class="bf-chip bf-chip-warning">Đủ điều kiện</span>
                            <?php else: ?>
                                <span class="bf-chip">Đang học</span>
                            <?php endif; ?>
                        </div>

                        <div style="font-size: 13px; color: var(--color-muted); margin-bottom: 6px;">
                            Giảng viên: <strong><?= e($c['teacher_name'] ?: 'BrightFuture Academy') ?></strong>
                        </div>

                        <div class="bf-progress-bar-bg">
                            <div class="bf-progress-bar-fill" style="width: <?= $percent ?>%; <?= $percent < 100 ? 'background: #3b82f6;' : '' ?>"></div>
                        </div>
                        <div style="font-size: 12px; color: var(--color-muted); display: flex; justify-content: space-between;">
                            <span>Hoàn thành: <?= $done ?> / <?= $total ?> bài</span>
                            <span><strong><?= $percent ?>%</strong></span>
                        </div>
                    </div>

                    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--color-border); display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
                        <?php if ($hasCert): ?>
                            <a class="bf-btn" href="?course=<?= (int)$c['id'] ?>">Xem chứng chỉ</a>
                            <a class="bf-muted" href="?verify=<?= e($c['verification_code']) ?>" title="Trang xác minh công khai">Tra cứu</a>
                        <?php elseif ($canClaim): ?>
                            <form method="post" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="course" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="bf-btn" style="background: #059669; border-color: #059669;">
                                    Nhận chứng chỉ
                                </button>
                            </form>
                        <?php else: ?>
                            <a class="bf-muted" href="<?= e(app_url('course.php?id=' . (int)$c['id'])) ?>">
                                Tiếp tục học →
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
function copyCertLink(url) {
    navigator.clipboard.writeText(url).then(function() {
        if (window.bfToast) {
            window.bfToast.success('Đã sao chép liên kết chứng chỉ!');
        } else {
            alert('Đã sao chép liên kết chứng chỉ!');
        }
    }).catch(function() {
        alert('Vui lòng sao chép liên kết: ' + url);
    });
}
</script>

</main>
<?php require_once __DIR__ . '/views/layouts/portal_foot.php'; ?>
