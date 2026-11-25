<?php

/**
 * BrightFuture - Unified Portal UI Helper
 * Quản lý khung giao diện chung, sidebar điều hướng đồng bộ cho tất cả các vai trò.
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/social.php';
require_once __DIR__ . '/icons.php';

/**
 * Danh sách menu điều hướng phân nhóm theo vai trò người dùng.
 */
function bf_get_sidebar_sections(array $user): array
{
    $role = (string) ($user['role'] ?? 'student');
    $home = match ($role) {
        'admin' => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        default => 'student/dashboard.php',
    };

    if ($role === 'teacher') {
        return [
            [
                'title' => 'GIẢNG DẠY',
                'items' => [
                    ['dashboard', $home, 'dashboard', 'Tổng quan'],
                    ['courses', 'teacher/courses.php', 'courses', 'Quản lý khóa học'],
                    ['exercises', 'teacher/exercises.php', 'exercises', 'Quản lý bài tập'],
                    ['submissions', 'teacher/submissions.php', 'check', 'Chấm bài học viên'],
                    ['explore', 'courses.php', 'search', 'Thư viện khóa học'],
                ]
            ],
            [
                'title' => 'CỘNG ĐỒNG & CÁ NHÂN',
                'items' => [
                    ['notifications', 'teacher/notifications.php', 'bell', 'Thông báo bài học'],
                    ['social', 'social.php', 'social', 'Cộng đồng & Tin nhắn'],
                    ['rooms', 'rooms.php', 'rooms', 'Phòng họp / gọi'],
                    ['profile', 'profile.php', 'profile', 'Hồ sơ cá nhân'],
                ]
            ]
        ];
    }

    if ($role === 'admin') {
        return [
            [
                'title' => 'QUẢN TRỊ HỆ THỐNG',
                'items' => [
                    ['dashboard', $home, 'dashboard', 'Tổng quan'],
                    ['users', 'admin/users.php', 'users', 'Người dùng & Duyệt GV'],
                    ['courses', 'admin/courses.php', 'courses', 'Quản lý khóa học'],
                    ['reports', 'admin/reports.php', 'reports', 'Báo cáo hệ thống'],
                    ['moderation', 'admin/moderation.php', 'check', 'Kiểm duyệt nội dung'],
                    ['audit', 'admin/audit.php', 'progress', 'Nhật ký kiểm toán'],
                ]
            ],
            [
                'title' => 'CỘNG ĐỒNG & CÁ NHÂN',
                'items' => [
                    ['social', 'social.php', 'social', 'Cộng đồng & Tin nhắn'],
                    ['rooms', 'rooms.php', 'rooms', 'Phòng gọi nhóm'],
                    ['profile', 'profile.php', 'profile', 'Hồ sơ cá nhân'],
                ]
            ]
        ];
    }

    // Default: ROLE_STUDENT
    return [
        [
            'title' => 'HỌC TẬP',
            'items' => [
                ['dashboard', $home, 'dashboard', 'Tổng quan'],
                ['my_courses', 'student/my-courses.php', 'courses', 'Khóa học của tôi'],
                ['explore', 'courses.php', 'search', 'Khám phá khóa học'],
                ['progress', 'student/progress.php', 'progress', 'Tiến độ học tập'],
                ['exercises', 'student/exercises.php', 'exercises', 'Bài tập & Đánh giá'],
                ['certificates', 'certificate.php', 'certificate', 'Chứng chỉ học tập'],
            ]
        ],
        [
            'title' => 'CỘNG ĐỒNG & CÁ NHÂN',
            'items' => [
                ['notifications', 'student/notifications.php', 'bell', 'Thông báo phản hồi'],
                ['social', 'social.php', 'social', 'Cộng đồng & Tin nhắn'],
                ['rooms', 'rooms.php', 'rooms', 'Phòng học nhóm'],
                ['profile', 'profile.php', 'profile', 'Hồ sơ cá nhân'],
            ]
        ]
    ];
}

/**
 * Xuất chuỗi HTML thanh điều hướng sidebar đồng bộ, dùng chung cho mọi dashboard.
 */
function bf_unified_sidebar(array $user, string $active, string $sidebarId = 'sidebar'): string
{
    $role = (string) ($user['role'] ?? 'student');
    $home = match ($role) {
        'admin' => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        default => 'student/dashboard.php',
    };
    $sections = bf_get_sidebar_sections($user);
    $initial = function_exists('mb_substr')
        ? mb_substr((string)($user['full_name'] ?? 'U'), 0, 1, 'UTF-8')
        : substr((string)($user['full_name'] ?? 'U'), 0, 1);

    ob_start();
    ?>
    <aside class="bf-rail sidebar side" id="<?= e($sidebarId) ?>" aria-label="Thanh điều hướng">
        <a class="bf-logo brand logo sidebar-logo" href="<?= e(app_url($home)) ?>">
            <span class="bf-logo-mark logo-icon logo-mark"><?= bf_icon('code', 'bf-logo-svg') ?></span>
            <span class="bf-logo-text">BrightFuture</span>
        </a>
        <nav class="bf-nav nav sidebar-nav" aria-label="Điều hướng chính" style="display: flex; flex-direction: column;">
            <?php foreach ($sections as $sec): ?>
                <div class="bf-nav-title nav-label nav-heading nav-caption" style="color: #94a3b8; font-size: 11px; font-weight: 750; letter-spacing: 0.08em; padding: 14px 14px 6px; text-transform: uppercase;">
                    <?= e($sec['title']) ?>
                </div>
                <?php foreach ($sec['items'] as [$key, $path, $icon, $label]): ?>
                    <?php 
                    $isActive = ($active === $key); 
                    $badgeHtml = '';
                    if ($key === 'notifications' && !empty($user['id'])) {
                        if ($role === 'student') {
                            require_once __DIR__ . '/student_notifications.php';
                            $cnt = bf_student_unread_count(db(), (int)$user['id']);
                            if ($cnt > 0) {
                                $badgeHtml = '<span class="bf-sidebar-badge" style="margin-left: auto; background: #ef4444; color: #ffffff; border-radius: 99px; font-size: 11px; font-weight: 700; padding: 1px 7px; line-height: 1.4;">' . ($cnt > 99 ? '99+' : $cnt) . '</span>';
                            }
                        } elseif ($role === 'teacher') {
                            require_once __DIR__ . '/teacher_notifications.php';
                            $cnt = bf_teacher_unread_count(db(), (int)$user['id']);
                            if ($cnt > 0) {
                                $badgeHtml = '<span class="bf-sidebar-badge" style="margin-left: auto; background: #ef4444; color: #ffffff; border-radius: 99px; font-size: 11px; font-weight: 700; padding: 1px 7px; line-height: 1.4;">' . ($cnt > 99 ? '99+' : $cnt) . '</span>';
                            }
                        }
                    }
                    ?>
                    <a class="nav-link <?= $isActive ? 'active' : '' ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>
                       href="<?= e(app_url($path)) ?>"
                       style="display: flex; align-items: center; gap: 12px; width: 100%;">
                        <?= bf_icon($icon, 'bf-nav-icon nav-icon nav-symbol') ?>
                        <span><?= e($label) ?></span>
                        <?= $badgeHtml ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="bf-user side-foot sidefoot foot sidebar-foot sidebar-bottom side-bottom">
            <div class="user-block user user-mini user-small" style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px;">
                <span class="avatar bf-avatar-circle" style="width: 38px; height: 38px; border-radius: 12px; background: #e0e7ff; color: #4338ca; font-weight: 750; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px;">
                    <?= e($initial) ?>
                </span>
                <div class="user-mini-info user-info user-names name" style="min-width: 0; flex: 1;">
                    <strong style="display: block; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--color-text, #1e293b);">
                        <?= e($user['full_name'] ?? 'User') ?>
                    </strong>
                    <span style="color: var(--color-muted, #64748b); font-size: 11px; display: block;">
                        <?= e(bf_social_role($role)) ?>
                    </span>
                </div>
            </div>
            <form method="post" action="<?= e(app_url('logout.php')) ?>" style="margin: 0;">
                <?= csrf_field() ?>
                <button class="logout-btn logout bf-muted" type="submit" style="width: 100%; border: 0; border-radius: 9px; padding: 10px 12px; font-weight: 600; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 7px; background: #fff1f2; color: #be123c; cursor: pointer;">
                    <?= bf_icon('logout', 'bf-button-icon') ?>
                    <span>Đăng xuất</span>
                </button>
            </form>
        </div>
    </aside>
    <?php
    return (string) ob_get_clean();
}

/**
 * Khởi tạo bố cục trang Portal chuẩn hóa.
 * @param array $user Thông tin người dùng hiện tại
 * @param string $active Menu tab đang được kích hoạt
 * @param string $title Tiêu đề trang
 * @param array<string> $extraCss Danh sách các đường dẫn file CSS mở rộng (tùy chọn)
 */
function bf_portal_begin(array $user, string $active, string $title, array $extraCss = []): void
{
    $role = (string) ($user['role'] ?? 'student');
    $home = match ($role) {
        'admin' => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        default => 'student/dashboard.php',
    };
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | BrightFuture</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(app_url('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-portal.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-foundation.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-ux-refresh.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-public.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-toast.css')) ?>">
    <?php foreach ($extraCss as $cssPath): ?>
        <link rel="stylesheet" href="<?= e(app_url($cssPath)) ?>">
    <?php endforeach; ?>
</head>
<body>
<div class="bf-app">
    <?= bf_unified_sidebar($user, $active, 'bfRail') ?>
    <div class="bf-shade" id="bfShade"></div>
    <div class="bf-main">
        <header class="bf-top">
            <div>
                <button type="button" id="bfMenu" class="bf-menu" aria-label="Mở hoặc thu gọn menu">
                    <?= bf_icon('menu', 'bf-menu-icon') ?>
                </button>
                <strong><?= e($title) ?></strong>
            </div>
            <a href="<?= e(app_url($home)) ?>">Trang chủ của tôi</a>
        </header>
        <main class="bf-container">
<?php
}

function bf_portal_end(): void
{
?>
        </main>
    </div>
</div>
<script src="<?= e(app_url('js/bf-toast.js')) ?>"></script>
<script src="<?= e(app_url('js/bf-portal.js')) ?>" defer></script>
<script src="<?= e(app_url('js/bf-ux-refresh.js')) ?>" defer></script>
</body>
</html>
<?php
}

/**
 * Xuất HTML component Empty State thẩm mỹ, hiện đại, không vẻ AI.
 */
function bf_empty_state(
    string $title,
    string $description = '',
    ?string $actionUrl = null,
    ?string $actionLabel = null,
    string $icon = 'search'
): string {
    ob_start();
    ?>
    <div class="bf-empty-state" role="region" aria-label="<?= e($title) ?>">
        <div class="bf-empty-icon" aria-hidden="true">
            <?= bf_icon($icon, 'bf-empty-svg') ?>
        </div>
        <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px;">
            <?= e($title) ?>
        </h3>
        <?php if ($description !== ''): ?>
            <p style="font-size: 14px; color: #64748b; margin: 0 auto 20px; max-width: 440px; line-height: 1.6;">
                <?= e($description) ?>
            </p>
        <?php endif; ?>
        <?php if ($actionUrl !== null && $actionLabel !== null): ?>
            <a href="<?= e(app_url($actionUrl)) ?>" class="bf-empty-action" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #4f46e5; color: #ffffff; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none;">
                <span><?= e($actionLabel) ?></span>
                <?= bf_icon('arrow', 'bf-button-arrow') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Xuất thanh phân trang thống nhất chuẩn UX, có aria-label cho trợ năng.
 */
function bf_pagination(array $pagination, string $baseUrl, array $extraParams = []): string
{
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    $currentPage = (int) ($pagination['current_page'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }

    $buildUrl = function (int $page) use ($baseUrl, $extraParams): string {
        $params = array_merge($extraParams, ['page' => $page]);
        return app_url($baseUrl . '?' . http_build_query($params));
    };

    ob_start();
    ?>
    <nav class="bf-pagination" aria-label="Phân trang danh sách" style="display: flex; justify-content: center; align-items: center; gap: 6px; margin: 28px 0; flex-wrap: wrap;">
        <?php if ($currentPage > 1): ?>
            <a href="<?= e($buildUrl($currentPage - 1)) ?>" class="bf-page-btn" aria-label="Trang trước" style="display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none;">
                &larr; Trước
            </a>
        <?php endif; ?>

        <?php
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        if ($start > 1): ?>
            <a href="<?= e($buildUrl(1)) ?>" class="bf-page-num" style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none;">1</a>
            <?php if ($start > 2): ?>
                <span style="color: #94a3b8; padding: 0 4px;">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
            <?php $isCurrent = ($p === $currentPage); ?>
            <a href="<?= e($buildUrl($p)) ?>"
               class="bf-page-num <?= $isCurrent ? 'active' : '' ?>"
               <?= $isCurrent ? 'aria-current="page"' : '' ?>
               style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; <?= $isCurrent ? 'background: #4f46e5; color: #ffffff; border: 1px solid #4f46e5;' : 'background: #ffffff; color: #475569; border: 1px solid #e2e8f0;' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?>
                <span style="color: #94a3b8; padding: 0 4px;">...</span>
            <?php endif; ?>
            <a href="<?= e($buildUrl($totalPages)) ?>" class="bf-page-num" style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none;"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= e($buildUrl($currentPage + 1)) ?>" class="bf-page-btn" aria-label="Trang tiếp" style="display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none;">
                Sau &rarr;
            </a>
        <?php endif; ?>
    </nav>
    <?php
    return (string) ob_get_clean();
}

/**
 * Xuất thanh điều hướng Breadcrumb vụn bánh mì chuẩn SEO & UX.
 * @param array<array{0: string, 1: ?string}> $items Mảng các cặp [Tên hiển thị, Link (hoặc null nếu là trang hiện tại)]
 */
function bf_breadcrumb(array $items): string
{
    if (empty($items)) {
        return '';
    }
    ob_start();
    ?>
    <nav aria-label="Breadcrumb" class="bf-breadcrumb" style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; margin-bottom: 18px; flex-wrap: wrap;">
        <?php foreach ($items as $idx => [$label, $url]): ?>
            <?php if ($idx > 0): ?>
                <span style="color: #cbd5e1; user-select: none;">/</span>
            <?php endif; ?>
            <?php if ($url !== null && $idx < count($items) - 1): ?>
                <a href="<?= e(app_url($url)) ?>" style="color: #64748b; text-decoration: none; font-weight: 500; transition: color 0.15s;">
                    <?= e($label) ?>
                </a>
            <?php else: ?>
                <span style="color: #1e293b; font-weight: 650;" aria-current="page">
                    <?= e($label) ?>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
    return (string) ob_get_clean();
}
