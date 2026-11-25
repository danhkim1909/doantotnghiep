<?php
/**
 * ====================================================
 * BRIGHTFUTURE - KHÁM PHÁ KHÓA HỌC
 * File: courses.php
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

// Lấy danh sách ID các khóa học học viên đủ điều kiện nhìn thấy (mở khóa)
$visibleCourseIds = bf_visible_course_ids($pdo, $studentId);

// Nhận tham số tìm kiếm & lọc
$keyword = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($keyword, 'UTF-8') > 100) {
    $keyword = mb_substr($keyword, 0, 100, 'UTF-8');
}

$category = trim((string) ($_GET['category'] ?? ''));
if (mb_strlen($category, 'UTF-8') > 50) {
    $category = mb_substr($category, 0, 50, 'UTF-8');
}

$level = (string) ($_GET['level'] ?? '');
if (!in_array($level, ['', 'beginner', 'intermediate', 'advanced'], true)) {
    $level = '';
}

$categories = [];
$courses = [];

if (!empty($visibleCourseIds)) {
    // Chuẩn bị danh sách placeholder an toàn
    $idPlaceholders = implode(',', array_fill(0, count($visibleCourseIds), '?'));

    // Lấy danh mục hiện có của các khóa học đã mở
    $catStmt = $pdo->prepare(
        "SELECT DISTINCT category FROM courses 
         WHERE deleted_at IS NULL AND id IN ($idPlaceholders) AND category IS NOT NULL AND category != '' 
         ORDER BY category ASC"
    );
    $catStmt->execute(array_map('intval', $visibleCourseIds));
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT 
                c.id,
                c.title,
                c.description,
                c.category,
                c.thumbnail,
                c.level,
                u.full_name AS teacher_name,
                EXISTS(
                    SELECT 1 
                    FROM enrollments en 
                    WHERE en.course_id = c.id 
                      AND en.student_id = ? 
                      AND en.status IN ('active', 'completed')
                ) AS enrolled,
                (
                    SELECT ROUND(AVG(r.rating), 1)
                    FROM bf_course_reviews r
                    WHERE r.course_id = c.id AND r.status = 'published'
                ) AS avg_rating,
                (
                    SELECT COUNT(r.id)
                    FROM bf_course_reviews r
                    WHERE r.course_id = c.id AND r.status = 'published'
                ) AS total_reviews
            FROM courses c
            JOIN users u ON u.id = c.teacher_id
            WHERE c.deleted_at IS NULL
              AND c.id IN ($idPlaceholders)";

    $params = [$studentId ?? 0];
    foreach ($visibleCourseIds as $vid) {
        $params[] = (int) $vid;
    }

    if ($keyword !== '') {
        $sql .= " AND (c.title LIKE ? OR c.category LIKE ?)";
        $searchPattern = '%' . $keyword . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }

    if ($category !== '') {
        $sql .= " AND c.category = ?";
        $params[] = $category;
    }

    if ($level !== '') {
        $sql .= " AND c.level = ?";
        $params[] = $level;
    }

    $sql .= " ORDER BY FIELD(c.level, 'beginner', 'intermediate', 'advanced'), c.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bf_thumb(?string $path): ?string
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
    <title>Khám phá khóa học | BrightFuture</title>
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
        <nav class="bf-public-nav" aria-label="Điều hướng chính">
            <a class="active" aria-current="page" href="<?= e(app_url('courses.php')) ?>">Khóa học</a>
            <?php if ($who): ?>
                <a href="<?= e(app_url('dashboard.php')) ?>">Tổng quan</a>
                <a href="<?= e(app_url('social.php')) ?>">Cộng đồng</a>
                <a href="<?= e(app_url('profile.php')) ?>">Hồ sơ</a>
            <?php else: ?>
                <a href="<?= e(app_url('login.php')) ?>">Đăng nhập</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="bf-public-main">
    <div class="bf-public-heading">
        <div>
            <h1>Khám phá khóa học</h1>
            <p>Chọn khóa học phù hợp với trình độ và theo lộ trình học lập trình rõ ràng.</p>
        </div>
    </div>

    <form class="bf-public-filters" method="get">
        <input 
            class="bf-public-control" 
            type="search" 
            name="q" 
            placeholder="Tìm khóa học hoặc chủ đề..." 
            value="<?= e($keyword) ?>" 
            aria-label="Tìm khóa học"
        >
        <select class="bf-public-control" name="category" aria-label="Lọc theo danh mục">
            <option value="">Tất cả danh mục</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e((string) $cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                    <?= e((string) $cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="bf-public-control" name="level" aria-label="Lọc trình độ">
            <option value="">Mọi trình độ đã mở</option>
            <?php foreach ($levelLabels as $key => $text): ?>
                <option value="<?= e($key) ?>" <?= $level === $key ? 'selected' : '' ?>>
                    <?= e($text) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="bf-public-button" type="submit">
            <?= bf_icon('search') ?> Tìm kiếm
        </button>
    </form>

    <div class="bf-course-grid">
        <?php foreach ($courses as $row): ?>
            <article class="bf-course-card">
                <div class="bf-course-cover">
                    <?php if ($img = bf_thumb($row['thumbnail'])): ?>
                        <img src="<?= e($img) ?>" alt="<?= e($row['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <?= bf_icon('courses') ?>
                    <?php endif; ?>
                </div>
                <div class="bf-course-body">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px;">
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span class="bf-course-tag"><?= e($levelLabels[$row['level']] ?? $row['level']) ?></span>
                            <?php if (!empty($row['category'])): ?>
                                <span class="bf-course-tag" style="background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe;"><?= e((string) $row['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($row['avg_rating']) && (float) $row['avg_rating'] > 0): ?>
                            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: #b45309;" title="Điểm đánh giá trung bình">
                                ⭐ <?= number_format((float) $row['avg_rating'], 1) ?>
                                <span style="font-weight: 400; font-size: 12px; color: var(--bf-public-muted);">(<?= (int) $row['total_reviews'] ?>)</span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h2><?= e($row['title']) ?></h2>
                    <span class="bf-course-teacher">Giảng viên: <?= e($row['teacher_name']) ?></span>
                    <p><?= e(truncate_text((string) ($row['description'] ?? ''), 125)) ?></p>
                    <a class="bf-course-action" href="<?= e(app_url('course.php?id=' . (int) $row['id'])) ?>">
                        <?= bf_icon('arrow') ?>
                        <?= !empty($row['enrolled']) ? 'Tiếp tục học' : 'Xem và đăng ký' ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (empty($courses)): ?>
            <div class="bf-public-empty">
                Chưa có khóa học phù hợp hoặc bạn chưa mở khóa trình độ này.
            </div>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
