/**
 * BrightFuture - Profile Interactive Logic
 * Xử lý kiểm tra độ mạnh mật khẩu, ẩn/hiện mật khẩu, và sao chép mã 2FA.
 */
(function() {
    'use strict';

    function initProfileFeatures() {
        // 1. Chức năng Ẩn/Hiện Mật khẩu (Show/Hide Password)
        const toggleButtons = document.querySelectorAll('.bf-pwd-toggle');
        toggleButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                // Thay đổi icon SVG
                btn.innerHTML = isPassword
                    ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`
                    : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
                btn.setAttribute('aria-label', isPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
            });
        });

        // 2. Chức năng Đo Độ Mạnh Mật Khẩu (Password Strength Meter)
        const newPwdInput = document.getElementById('new');
        const confirmPwdInput = document.getElementById('confirm');
        const meterFill = document.getElementById('meterFill');
        const meterText = document.getElementById('meterText');
        const matchHint = document.getElementById('matchHint');

        // Các phần tử tiêu chí
        const critLen = document.getElementById('critLen');
        const critUpper = document.getElementById('critUpper');
        const critNum = document.getElementById('critNum');
        const critSpecial = document.getElementById('critSpecial');

        if (newPwdInput && meterFill && meterText) {
            newPwdInput.addEventListener('input', () => {
                const val = newPwdInput.value;
                let score = 0;

                const hasLen = val.length >= 8;
                const hasUpper = /[A-Z]/.test(val);
                const hasNum = /[0-9]/.test(val);
                const hasSpecial = /[^A-Za-z0-9]/.test(val);

                if (critLen) critLen.classList.toggle('valid', hasLen);
                if (critUpper) critUpper.classList.toggle('valid', hasUpper);
                if (critNum) critNum.classList.toggle('valid', hasNum);
                if (critSpecial) critSpecial.classList.toggle('valid', hasSpecial);

                if (hasLen) score++;
                if (hasUpper) score++;
                if (hasNum) score++;
                if (hasSpecial) score++;

                if (val.length === 0) {
                    meterFill.style.width = '0%';
                    meterFill.style.backgroundColor = '#e2e8f0';
                    meterText.textContent = 'Độ mạnh: Chưa nhập';
                    meterText.style.color = '#64748b';
                } else if (score <= 1) {
                    meterFill.style.width = '25%';
                    meterFill.style.backgroundColor = '#ef4444';
                    meterText.textContent = 'Độ mạnh: Yếu';
                    meterText.style.color = '#ef4444';
                } else if (score === 2) {
                    meterFill.style.width = '50%';
                    meterFill.style.backgroundColor = '#f59e0b';
                    meterText.textContent = 'Độ mạnh: Trung bình';
                    meterText.style.color = '#f59e0b';
                } else if (score === 3) {
                    meterFill.style.width = '75%';
                    meterFill.style.backgroundColor = '#3b82f6';
                    meterText.textContent = 'Độ mạnh: Khá';
                    meterText.style.color = '#3b82f6';
                } else {
                    meterFill.style.width = '100%';
                    meterFill.style.backgroundColor = '#10b981';
                    meterText.textContent = 'Độ mạnh: Rất mạnh';
                    meterText.style.color = '#10b981';
                }

                checkMatch();
            });
        }

        function checkMatch() {
            if (!confirmPwdInput || !matchHint) return;
            const p1 = newPwdInput ? newPwdInput.value : '';
            const p2 = confirmPwdInput.value;

            if (p2.length === 0) {
                matchHint.textContent = '';
                return;
            }

            if (p1 === p2) {
                matchHint.textContent = '✓ Mật khẩu xác nhận trùng khớp';
                matchHint.style.color = '#16a34a';
            } else {
                matchHint.textContent = '✕ Mật khẩu xác nhận chưa khớp';
                matchHint.style.color = '#dc2626';
            }
        }

        if (confirmPwdInput) {
            confirmPwdInput.addEventListener('input', checkMatch);
        }

        // 3. Sao chép và Tải mã khôi phục 2FA Backup Codes
        const copyBackupBtn = document.getElementById('copyBackupCodes');
        const downloadBackupBtn = document.getElementById('downloadBackupCodes');

        if (copyBackupBtn) {
            copyBackupBtn.addEventListener('click', () => {
                const codes = Array.from(document.querySelectorAll('.bf-backup-code'))
                    .map(el => el.textContent.trim())
                    .join('\n');

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(codes).then(() => {
                        const originalText = copyBackupBtn.textContent;
                        copyBackupBtn.textContent = '✓ Đã sao chép';
                        setTimeout(() => {
                            copyBackupBtn.textContent = originalText;
                        }, 2000);
                    });
                }
            });
        }

        if (downloadBackupBtn) {
            downloadBackupBtn.addEventListener('click', () => {
                const codes = Array.from(document.querySelectorAll('.bf-backup-code'))
                    .map(el => el.textContent.trim())
                    .join('\n');
                
                const content = "BRIGHTFUTURE - MA SAO LUU XAC THUC HAI BUOC (2FA)\n"
                    + "Luu y: Moi ma chi dung duoc 1 lan khi mat thiet bi.\n\n"
                    + codes + "\n\nNgay tao: " + new Date().toLocaleString();

                const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'brightfuture-2fa-backup-codes.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfileFeatures);
    } else {
        initProfileFeatures();
    }
})();
