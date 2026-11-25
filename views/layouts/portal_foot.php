<?php
/**
 * BrightFuture - Portal Foot Partial
 * Khối đóng layout, điều khiển drawer menu di động, nạp script tối ưu.
 * PHP 8+
 */

declare(strict_types=1);
?>
</div><!-- /.main -->
</div><!-- /.bf-portal-layout -->

<script>
(function() {
    'use strict';
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('portalOverlay') || document.getElementById('overlay');
    const toggleBtn = document.getElementById('portalMenuToggle') || document.getElementById('menuToggle');

    if (!sidebar || !overlay || !toggleBtn) return;

    function toggleMenu(open) {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('show', open);
        toggleBtn.setAttribute('aria-expanded', String(open));
        toggleBtn.setAttribute('aria-label', open ? 'Đóng menu' : 'Mở menu');
    }

    toggleBtn.addEventListener('click', function() {
        toggleMenu(!sidebar.classList.contains('open'));
    });

    overlay.addEventListener('click', function() {
        toggleMenu(false);
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) {
            toggleMenu(false);
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 900 && sidebar.classList.contains('open')) {
            toggleMenu(false);
        }
    });
})();
</script>
<script src="<?= e(app_url('js/bf-ux-refresh.js')) ?>" defer></script>
</body>
</html>
