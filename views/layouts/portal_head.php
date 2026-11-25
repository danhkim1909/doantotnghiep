<?php
/**
 * BrightFuture - Portal Layout Head Partial
 * Tệp mở đầu chuẩn cho giao diện Portal (Student / Teacher / Admin).
 * PHP 8+
 */

declare(strict_types=1);

if (!isset($pageTitle)) {
    $pageTitle = 'BrightFuture - Nền tảng học lập trình';
}
$portalRole = (string)($user['role'] ?? 'student');
$portalHome = match ($portalRole) {
    'admin' => 'admin/dashboard.php',
    'teacher' => 'teacher/dashboard.php',
    default => 'student/dashboard.php',
};
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e((string)($pageDescription ?? 'Nền tảng học trực tuyến BrightFuture')) ?>">
    <title><?= e((string)$pageTitle) ?> | <?= e(defined('APP_NAME') ? APP_NAME : 'BrightFuture') ?></title>
    
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Design System & Tokens -->
    <link rel="stylesheet" href="<?= e(app_url('css/bf-design-system.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-portal.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('css/bf-ux-refresh.css')) ?>">
    
    <?php 
    $mergedCss = array_unique(array_merge(
        !empty($extraCss) && is_array($extraCss) ? $extraCss : [],
        !empty($customCss) && is_array($customCss) ? $customCss : []
    ));
    ?>
    <?php foreach ($mergedCss as $cssPath): ?>
        <link rel="stylesheet" href="<?= e(app_url($cssPath)) ?>">
    <?php endforeach; ?>

    <script>window.BF_APP = Object.assign(window.BF_APP || {}, { baseUrl: <?= json_encode(app_url(''), JSON_UNESCAPED_SLASHES) ?> });</script>

    <style>
        :root {
            --bf-sidebar-w: 255px;
            --bf-topbar-h: 72px;
            --bf-primary: #4f46e5;
            --bf-text: #1e293b;
            --bf-border: #e2e8f0;
            --bf-bg: #f8fafc;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: Inter, 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--bf-text);
            background: var(--bf-bg);
            -webkit-font-smoothing: antialiased;
        }
        a {
            color: inherit;
            text-decoration: none;
        }
        button, input, select, textarea {
            font: inherit;
        }
        .bf-portal-layout {
            min-height: 100vh;
            position: relative;
        }
        /* Cố định Sidebar bên trái */
        .bf-portal-layout .sidebar,
        .bf-portal-layout .bf-rail,
        .bf-portal-layout aside.side {
            position: fixed !important;
            inset: 0 auto 0 0 !important;
            width: var(--bf-sidebar-w) !important;
            height: 100vh !important;
            background: #ffffff !important;
            border-right: 1px solid var(--bf-border) !important;
            z-index: 50 !important;
            display: flex !important;
            flex-direction: column !important;
            transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
            box-shadow: 2px 0 12px rgba(15, 23, 42, 0.03) !important;
        }
        /* Nội dung chính lùi sang phải bằng kích thước sidebar */
        .bf-portal-layout .main,
        .bf-portal-layout main.main {
            margin-left: var(--bf-sidebar-w) !important;
            min-height: 100vh;
            transition: margin-left 0.22s ease-in-out;
        }
        /* Khung bọc nội dung chuẩn bên dưới topbar */
        .bf-portal-layout .content {
            max-width: 1500px;
            margin: 0 auto;
            padding: 32px 36px 60px;
            box-sizing: border-box;
        }
        @media (max-width: 768px) {
            .bf-portal-layout .content {
                padding: 20px 16px 40px;
            }
        }
        .bf-portal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 45;
            backdrop-filter: blur(2px);
        }
        .bf-portal-overlay.show {
            display: block;
        }
        /* Đáp ứng di động */
        @media (max-width: 900px) {
            .bf-portal-layout .sidebar,
            .bf-portal-layout .bf-rail,
            .bf-portal-layout aside.side {
                transform: translateX(-100%) !important;
            }
            .bf-portal-layout .sidebar.open,
            .bf-portal-layout .bf-rail.open,
            .bf-portal-layout aside.side.open {
                transform: translateX(0) !important;
            }
            .bf-portal-layout .main,
            .bf-portal-layout main.main {
                margin-left: 0 !important;
            }
            .menu-toggle, .bf-menu-toggle {
                display: inline-flex !important;
            }
        }
        @media (max-width: 640px) {
            .bf-topbar .full-name,
            .bf-topbar .topbar-search {
                display: none !important;
            }
        }
        @media (min-width: 901px) {
            .menu-toggle, .bf-menu-toggle {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<div class="bf-portal-layout layout">
<div class="bf-portal-overlay overlay" id="portalOverlay" aria-hidden="true"></div>
