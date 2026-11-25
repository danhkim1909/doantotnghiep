/* Add missing community navigation to existing role-specific sidebars. */
(() => {
  'use strict';

  const config = window.BF_LEGACY;
  if (!config) return;

  const base = String(config.base || '').replace(/\/$/, '');
  const role = config.role;
  const sidebar = document.querySelector('aside.sidebar,aside.side');
  if (!sidebar) return;

  const nav = sidebar.querySelector('nav.sidebar-nav,nav.nav');
  if (!nav) return;

  // Nếu sidebar đã có link cộng đồng (như unified sidebar), không chèn thêm để tránh trùng lặp
  if (nav.querySelector('a[href*="social.php"]') || sidebar.classList.contains('bf-rail')) return;

  const iconMarkup = {
    social: '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/>',
    rooms: '<rect x="3" y="5" width="18" height="14" rx="3"/><circle cx="9" cy="12" r="2"/><path d="M15 10h3M15 14h3"/>',
    reports: '<path d="M4 20V10M10 20V4M16 20v-6M22 20H2"/>'
  };

  const svg = (name) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    node.setAttribute('viewBox', '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', '1.8');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.setAttribute('aria-hidden', 'true');
    node.innerHTML = iconMarkup[name];
    return node;
  };

  const common = [
    ['social.php', 'social', 'Cộng đồng'],
    ['rooms.php', 'rooms', 'Phòng gọi nhóm']
  ];

  if (role === 'admin') {
    common.push(['admin/moderation.php', 'reports', 'Kiểm duyệt cộng đồng']);
  }

  const heading = document.createElement('div');
  heading.className = 'bf-nav-section';
  heading.textContent = 'Cộng đồng';
  nav.appendChild(heading);

  for (const [path, iconName, label] of common) {
    const link = document.createElement('a');
    link.className = 'bf-extra-link';
    link.href = base + '/' + path;

    const icon = document.createElement('span');
    icon.className = 'bf-icon';
    icon.appendChild(svg(iconName));

    const text = document.createElement('span');
    text.textContent = label;
    link.append(icon, text);

    if (location.pathname.replace(/\/$/, '').toLowerCase() === link.pathname.replace(/\/$/, '').toLowerCase()) {
      link.classList.add('active');
      link.setAttribute('aria-current', 'page');
    }

    nav.appendChild(link);
  }

  const current = location.pathname.replace(/\/$/, '').toLowerCase();
  for (const link of nav.querySelectorAll('a[href]')) {
    try {
      if (new URL(link.href).pathname.toLowerCase() === current) {
        link.classList.add('active');
        link.setAttribute('aria-current', 'page');
      }
    } catch (error) {
      // Ignore malformed third-party links.
    }
  }
})();
