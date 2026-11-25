(() => {
  'use strict';

  const prefixes = [
    '▦ ',
    '▤ ',
    '▥ ',
    '◴ ',
    '✓ ',
    '♧ ',
    '◉ ',
    '✧ ',
    '☺ ',
    '↪ ',
    '← '
  ];

  function cleanText(text) {
    let result = text.replace(/\s+/g, ' ').trim();
    for (const prefix of prefixes) {
      if (result.startsWith(prefix.trim())) {
        result = result.slice(prefix.trim().length).trim();
        break;
      }
    }
    return result;
  }

  function init() {
    document.querySelectorAll('.side a.nav, .side a.nav.active').forEach((link) => {
      const text = cleanText(link.textContent || '');
      if (text) link.textContent = text;
    });

    document.querySelectorAll('.side .logout').forEach((button) => {
      button.textContent = cleanText(button.textContent || '');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
