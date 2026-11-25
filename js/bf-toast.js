/**
 * ====================================================
 * BRIGHTFUTURE - TOAST NOTIFICATION UTILITY
 * File: js/bf-toast.js
 * JavaScript thuần, tự động quản lý vòng đời Toast
 * ====================================================
 */

(function () {
  'use strict';

  let container = null;

  function ensureContainer() {
    if (!container || !document.body.contains(container)) {
      container = document.createElement('div');
      container.className = 'bf-toast-container';
      container.setAttribute('aria-live', 'polite');
      document.body.appendChild(container);
    }
    return container;
  }

  function show(message, type = 'info', duration = 3500) {
    if (!message) return null;
    const parent = ensureContainer();

    const toast = document.createElement('div');
    toast.className = `bf-toast bf-toast-${type}`;
    toast.setAttribute('role', 'status');

    const icons = {
      success: '✓',
      error: '✕',
      warning: '!',
      info: 'i'
    };

    const iconHtml = `<div class="bf-toast-icon">${icons[type] || 'i'}</div>`;
    const contentHtml = `<div class="bf-toast-content">${escapeHtml(message)}</div>`;
    const closeBtn = document.createElement('button');
    closeBtn.className = 'bf-toast-close';
    closeBtn.setAttribute('aria-label', 'Đóng thông báo');
    closeBtn.innerHTML = '×';

    toast.innerHTML = iconHtml + contentHtml;
    toast.appendChild(closeBtn);
    parent.appendChild(toast);

    // Kích hoạt transition mượt mà
    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    let dismissTimer = null;
    const dismiss = () => {
      if (dismissTimer) clearTimeout(dismissTimer);
      toast.classList.remove('show');
      toast.classList.add('hide');
      setTimeout(() => {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 240);
    };

    closeBtn.addEventListener('click', dismiss);

    if (duration > 0) {
      dismissTimer = setTimeout(dismiss, duration);
    }

    return { dismiss };
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  window.bfToast = {
    show: show,
    success: (msg, duration) => show(msg, 'success', duration),
    error: (msg, duration) => show(msg, 'error', duration),
    warning: (msg, duration) => show(msg, 'warning', duration),
    info: (msg, duration) => show(msg, 'info', duration)
  };
})();
