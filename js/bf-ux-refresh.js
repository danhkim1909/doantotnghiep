/**
 * BrightFuture - Universal Command Palette & Navigation Enhancer
 * Cho phép điều hướng nhanh toàn hệ thống qua phím tắt Ctrl + K hoặc thanh tìm kiếm Topbar.
 * 
 * Tính năng chính:
 * 1. Mở hộp thoại Command Palette nhanh bằng phím tắt Ctrl + K hoặc Cmd + K.
 * 2. Tìm kiếm tiếng Việt thông minh (tự động loại bỏ dấu thanh).
 * 3. Hỗ trợ phím mũi tên Lên/Xuống để duyệt danh sách và Enter để truy cập.
 * 4. Đạt chuẩn trợ năng A11y (Focus trap, phím Esc để đóng modal, nhãn ARIA).
 */
(function() {
    'use strict';

    function initCommandPalette() {
        // Tránh khởi tạo nhiều lần
        if (window.__bfCommandPaletteInitialized) return;
        window.__bfCommandPaletteInitialized = true;

        // 1. Tạo giao diện Modal Command Palette
        const overlay = document.createElement('div');
        overlay.className = 'bfux-search-layer';
        overlay.hidden = true;
        overlay.setAttribute('role', 'presentation');

        const dialog = document.createElement('section');
        dialog.className = 'bfux-search-box';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', 'Tìm nhanh chức năng hệ thống');

        const heading = document.createElement('div');
        heading.className = 'bfux-search-heading';
        heading.innerHTML = `
            <span style="font-size: 15px; font-weight: 700; color: #1e293b;">Điều hướng nhanh BrightFuture</span>
            <button type="button" class="bfux-search-close" aria-label="Đóng tìm kiếm">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12M18 6 6 18"/>
                </svg>
            </button>
        `;

        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'bfux-search-input';
        input.placeholder = 'Gõ tên trang hoặc chức năng (vd: Khóa học, Bài tập, Tiến độ...)...';
        input.setAttribute('aria-label', 'Tìm kiếm chức năng');

        const results = document.createElement('div');
        results.className = 'bfux-search-results';

        const help = document.createElement('div');
        help.className = 'bfux-search-help';
        help.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span><kbd>↑</kbd> <kbd>↓</kbd> để di chuyển · <kbd>Enter</kbd> để mở · <kbd>Esc</kbd> để thoát</span>
                <span style="color: #94a3b8; font-size: 11px;">BrightFuture SaaS Nav</span>
            </div>
        `;

        dialog.append(heading, input, results, help);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        const closeBtn = heading.querySelector('.bfux-search-close');

        // 2. Thu thập danh sách các liên kết từ Sidebar Navigation
        function getAvailableLinks() {
            const nav = document.querySelector('aside.sidebar nav, aside.bf-rail nav, nav.sidebar-nav, nav.bf-nav');
            const items = [];

            if (nav) {
                const links = nav.querySelectorAll('a[href]');
                links.forEach(a => {
                    const href = a.getAttribute('href');
                    const text = (a.textContent || '').replace(/\s+/g, ' ').trim();
                    if (href && href !== '#' && !href.startsWith('javascript:') && text.length > 0) {
                        items.push({
                            label: text,
                            href: a.href,
                            isCurrent: a.classList.contains('active') || a.getAttribute('aria-current') === 'page'
                        });
                    }
                });
            }

            // Loại bỏ các mục trùng URL
            const unique = [];
            const seen = new Set();
            for (const it of items) {
                if (!seen.has(it.href)) {
                    seen.add(it.href);
                    unique.push(it);
                }
            }
            return unique;
        }

        // 3. Chuẩn hóa chuỗi tìm kiếm (xóa dấu tiếng Việt, chuyển chữ thường)
        function normalizeText(str) {
            return (str || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[đĐ]/g, 'd')
                .toLowerCase()
                .trim();
        }

        let allLinks = [];
        let visibleLinks = [];
        let selectedIndex = 0;
        let lastFocusedElement = null;
        let searchFetchController = null;
        let liveSearchTimer = null;
        let currentSearchQuery = '';

        // 4. Tìm kiếm khóa học từ API backend
        async function fetchCourseResults(rawQuery) {
            if (rawQuery.length < 2) return [];
            if (searchFetchController) {
                searchFetchController.abort();
            }
            searchFetchController = new AbortController();

            const baseUrl = (window.BF_APP && window.BF_APP.baseUrl) ? window.BF_APP.baseUrl.replace(/\/+$/, '') + '/' : '/';
            try {
                const res = await fetch(`${baseUrl}api/courses.php?q=${encodeURIComponent(rawQuery)}&per_page=5`, {
                    credentials: 'same-origin',
                    signal: searchFetchController.signal
                });
                if (!res.ok) return [];
                const json = await res.json();
                if (json.data && Array.isArray(json.data)) {
                    return json.data.map(c => ({
                        label: c.title,
                        href: `${baseUrl}course.php?id=${c.id}`,
                        isCurrent: false,
                        type: 'course',
                        badge: c.category || (c.level ? (c.level === 'beginner' ? 'Cơ bản' : 'Nâng cao') : 'Khóa học')
                    }));
                }
            } catch (err) {
                if (err.name !== 'AbortError') {
                    // bỏ qua lỗi mạng tạm thời
                }
            }
            return [];
        }

        // 5. Render danh sách kết quả tìm kiếm
        function renderResults(extraItems = []) {
            const rawQuery = input.value.trim();
            const query = normalizeText(rawQuery);
            currentSearchQuery = query;
            results.innerHTML = '';

            let matchingNav = [];
            if (query === '') {
                matchingNav = allLinks.slice(0, 8);
            } else {
                matchingNav = allLinks.filter(item => normalizeText(item.label).includes(query)).slice(0, 6);
            }

            visibleLinks = [...matchingNav, ...extraItems];
            selectedIndex = 0;

            if (visibleLinks.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'bfux-no-results';
                empty.textContent = 'Không tìm thấy chức năng hoặc khóa học phù hợp.';
                results.appendChild(empty);
                return;
            }

            visibleLinks.forEach((item, index) => {
                const a = document.createElement('a');
                a.href = item.href;
                a.className = 'bfux-search-item';
                if (index === selectedIndex) {
                    a.classList.add('is-active');
                }

                const isCourse = item.type === 'course';
                const iconSvg = isCourse 
                    ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10M6 10h10"/></svg>`
                    : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>`;

                let badgeHtml = '';
                if (item.isCurrent) {
                    badgeHtml = '<span style="font-size: 11px; color: #4f46e5; background: #eef2ff; padding: 2px 8px; border-radius: 6px;">Đang xem</span>';
                } else if (item.badge) {
                    badgeHtml = `<span style="font-size: 11px; color: #047857; background: #ecfdf5; padding: 2px 8px; border-radius: 6px; font-weight: 600;">${item.badge}</span>`;
                }

                a.innerHTML = `
                    <span class="bfux-search-icon" aria-hidden="true" style="${isCourse ? 'background: #f0fdf4; color: #16a34a;' : ''}">
                        ${iconSvg}
                    </span>
                    <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${item.label}</span>
                    ${badgeHtml}
                `;

                a.addEventListener('mouseenter', () => {
                    updateSelection(index);
                });

                results.appendChild(a);
            });
        }

        function updateSelection(newIndex) {
            selectedIndex = newIndex;
            const renderedItems = results.querySelectorAll('.bfux-search-item');
            renderedItems.forEach((el, idx) => {
                el.classList.toggle('is-active', idx === selectedIndex);
                if (idx === selectedIndex) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        // 5. Mở và Đóng Modal
        function openPalette() {
            lastFocusedElement = document.activeElement;
            allLinks = getAvailableLinks();
            overlay.hidden = false;
            document.body.classList.add('bfux-modal-active');
            input.value = '';
            renderResults();
            setTimeout(() => input.focus(), 30);
        }

        function closePalette() {
            overlay.hidden = true;
            document.body.classList.remove('bfux-modal-active');
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        // 6. Gán các Event Listeners
        // Lắng nghe click nút trigger trên Topbar hoặc bất kỳ nút nào có class .bf-cmd-trigger / .bfux-tool
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('#bfCmdTrigger, .bf-cmd-trigger, .bfux-open-palette');
            if (trigger) {
                e.preventDefault();
                openPalette();
            }
        });

        closeBtn.addEventListener('click', closePalette);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closePalette();
            }
        });

        input.addEventListener('input', () => {
            renderResults(); // Tìm nhanh các mục menu ngay lập tức
            const rawQuery = input.value.trim();
            clearTimeout(liveSearchTimer);
            if (rawQuery.length >= 2) {
                liveSearchTimer = setTimeout(async () => {
                    const courses = await fetchCourseResults(rawQuery);
                    if (normalizeText(input.value.trim()) === currentSearchQuery && courses.length > 0) {
                        renderResults(courses);
                    }
                }, 250);
            }
        });

        // Lắng nghe phím tắt toàn cục
        document.addEventListener('keydown', (e) => {
            // Phím tắt Ctrl + K hoặc Cmd + K
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (overlay.hidden) {
                    openPalette();
                } else {
                    closePalette();
                }
                return;
            }

            if (overlay.hidden) return;

            // Xử lý khi modal đang mở
            if (e.key === 'Escape') {
                e.preventDefault();
                closePalette();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (visibleLinks.length > 0) {
                    updateSelection((selectedIndex + 1) % visibleLinks.length);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (visibleLinks.length > 0) {
                    updateSelection((selectedIndex - 1 + visibleLinks.length) % visibleLinks.length);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (visibleLinks[selectedIndex]) {
                    window.location.href = visibleLinks[selectedIndex].href;
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommandPalette);
    } else {
        initCommandPalette();
    }
})();
