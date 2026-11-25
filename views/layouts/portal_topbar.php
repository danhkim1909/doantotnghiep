<?php
/**
 * BrightFuture - Portal Topbar Partial
 * Thanh điều hướng trên cùng, responsive drawer toggle, thông tin người dùng.
 * PHP 8+
 */

declare(strict_types=1);

$currentUser = $user ?? $admin ?? (function_exists('current_user') ? current_user() : null) ?? [];
$fullName = (string)($currentUser['full_name'] ?? 'Người dùng');
$avatarInitial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr(trim($fullName), 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr(trim($fullName), 0, 1));
$userRole = (string)($currentUser['role'] ?? '');
$portalLabel = $portalRoleTitle ?? match ($userRole) {
    'admin' => 'Quản trị hệ thống',
    'teacher' => 'Không gian giảng dạy',
    default => 'Không gian học tập',
};
$roleBadge = match ($userRole) {
    'admin' => 'Quản trị viên',
    'teacher' => 'Giảng viên',
    default => 'Học viên',
};

$topbarNotifUrl = null;
$topbarUnreadCount = 0;
if ($userRole === 'student' && !empty($currentUser['id'])) {
    require_once __DIR__ . '/../../includes/student_notifications.php';
    $topbarNotifUrl = 'student/notifications.php';
    $topbarUnreadCount = bf_student_unread_count(db(), (int)$currentUser['id']);
} elseif ($userRole === 'teacher' && !empty($currentUser['id'])) {
    require_once __DIR__ . '/../../includes/teacher_notifications.php';
    $topbarNotifUrl = 'teacher/notifications.php';
    $topbarUnreadCount = bf_teacher_unread_count(db(), (int)$currentUser['id']);
}
?>
<div class="main">
<header class="topbar bf-topbar" style="height: 72px; padding: 0 24px; background: #ffffff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 30; gap: 12px; min-width: 0;">
    <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
        <button type="button" class="menu-toggle bf-menu-toggle" id="portalMenuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu điều hướng" style="border: 0; background: #f1f5f9; color: #1e293b; width: 38px; height: 38px; border-radius: 10px; cursor: pointer; align-items: center; justify-content: center; font-size: 18px;">
            ☰
        </button>
        <span style="font-weight: 600; font-size: 15px; color: #334155; white-space: nowrap;">
            <?= e($portalLabel) ?>
        </span>
    </div>
    <div class="topbar-search" style="flex: 1; max-width: 380px; min-width: 0; margin: 0 10px;">
        <button type="button" class="bf-cmd-trigger" id="bfCmdTrigger" style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 7px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; color: #64748b; font-size: 13px; cursor: pointer; transition: all 0.2s ease;">
            <span style="display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="flex-shrink:0"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Tìm nhanh...</span>
            </span>
            <kbd style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px 5px; font-size: 11px; font-weight: 600; color: #475569; font-family: inherit; flex-shrink:0">Ctrl K</kbd>
        </button>
    </div>
    <div class="topbar-right" style="position: relative; display: flex; align-items: center; gap: 12px; flex-shrink: 0;">
        <?php if ($topbarNotifUrl): ?>
            <a href="<?= e(app_url($topbarNotifUrl)) ?>" 
               class="bf-topbar-bell" 
               aria-label="Thông báo<?= $topbarUnreadCount > 0 ? " ({$topbarUnreadCount} chưa đọc)" : '' ?>" 
               style="position: relative; width: 38px; height: 38px; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; justify-content: center; color: #475569; text-decoration: none; transition: all 0.15s ease;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                </svg>
                <?php if ($topbarUnreadCount > 0): ?>
                    <span style="position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 4px; background: #ef4444; color: #ffffff; border-radius: 99px; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff;">
                        <?= $topbarUnreadCount > 99 ? '99+' : $topbarUnreadCount ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <div id="bfUserDropdownToggle" style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 4px 8px; border-radius: 10px; transition: background 0.15s ease;" role="button" aria-expanded="false" aria-haspopup="true">
            <div style="text-align: right;" class="full-name">
                <span style="font-size: 14px; font-weight: 700; color: #1e293b; display: block; line-height: 1.2;">
                    <?= e($fullName) ?>
                </span>
                <span style="font-size: 11px; font-weight: 600; color: #6366f1; background: #eef2ff; padding: 1px 7px; border-radius: 6px; display: inline-block; margin-top: 2px;">
                    <?= e($roleBadge) ?>
                </span>
            </div>
            <span class="topbar-avatar" style="width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; display: grid; place-items: center; font-weight: 700; font-size: 15px; flex-shrink:0; box-shadow: 0 2px 6px rgba(99, 102, 241, 0.25);">
                <?= e($avatarInitial) ?>
            </span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.5" style="transition: transform 0.2s;" id="bfUserDropdownArrow"><path d="m6 9 6 6 6-6"/></svg>
        </div>

        <!-- Menu thả xuống góc phải -->
        <div id="bfUserDropdownMenu" style="display: none; position: absolute; right: 0; top: calc(100% + 8px); width: 230px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12); padding: 8px; z-index: 50; flex-direction: column; gap: 4px;">
            <div style="padding: 8px 12px; border-bottom: 1px solid #f1f5f9; margin-bottom: 4px;">
                <span style="font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Tài khoản đăng nhập</span>
                <div style="font-size: 13px; font-weight: 600; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px;">
                    <?= e($currentUser['email'] ?? '') ?>
                </div>
            </div>
            <a href="<?= e(app_url('profile.php')) ?>" style="display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Hồ sơ cá nhân</span>
            </a>
            <?php if ($topbarNotifUrl): ?>
            <a href="<?= e(app_url($topbarNotifUrl)) ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <span style="display: flex; align-items: center; gap: 10px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    <span>Thông báo</span>
                </span>
                <?php if ($topbarUnreadCount > 0): ?>
                    <span style="background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; border-radius: 99px; padding: 1px 6px;">
                        <?= $topbarUnreadCount > 99 ? '99+' : $topbarUnreadCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <a href="<?= e(app_url('social.php')) ?>" style="display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Tin nhắn & Thảo luận</span>
            </a>
            <a href="<?= e(app_url('index.php')) ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Trang chủ website ↗</span>
            </a>
            <div style="height: 1px; background: #f1f5f9; margin: 4px 0;"></div>
            <form method="post" action="<?= e(app_url('logout.php')) ?>" style="margin: 0;">
                <?= csrf_field() ?>
                <button type="submit" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 9px 12px; border: 0; background: transparent; border-radius: 8px; color: #e11d48; font-size: 13px; font-weight: 600; cursor: pointer; text-align: left; transition: background 0.15s ease;" onmouseover="this.style.background='#fff1f2'" onmouseout="this.style.background='transparent'">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Đăng xuất</span>
                </button>
            </form>
        </div>
    </div>
</header>
<script>
(function() {
    const toggle = document.getElementById('bfUserDropdownToggle');
    const menu = document.getElementById('bfUserDropdownMenu');
    const arrow = document.getElementById('bfUserDropdownArrow');
    if (!toggle || !menu) return;

    function closeMenu() {
        menu.style.display = 'none';
        toggle.setAttribute('aria-expanded', 'false');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    }

    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        const isOpen = menu.style.display === 'flex';
        if (isOpen) {
            closeMenu();
        } else {
            menu.style.display = 'flex';
            toggle.setAttribute('aria-expanded', 'true');
            if (arrow) arrow.style.transform = 'rotate(180deg)';
        }
    });

    document.addEventListener('click', function(e) {
        if (!menu.contains(e.target) && !toggle.contains(e.target)) {
            closeMenu();
        }
    });
})();
</script>
