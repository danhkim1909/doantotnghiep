
<?php
/**
 * ==================================================
 * BRIGHTFUTURE - LOGOUT
 * File: logout.php
 * PHP 8.0+ / PHP Session / CSRF Protection
 * ==================================================
 */


// ==================================================
// 1. LOAD HE THONG XAC THUC
// ==================================================

require_once __DIR__ . '/includes/auth.php';

// ==================================================
// 2. CHI CHO PHEP HTTP POST
// ==================================================

if (!is_post()) {

    header('Allow: POST');

    http_response_code(405);

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html>';
    echo '<html lang="vi">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>405 - BrightFuture</title>';
    echo '</head>';
    echo '<body>';
    echo '<h2>405 - Phương thức không được hỗ trợ</h2>';
    echo '<p>Vui lòng sử dụng nút đăng xuất trên website.</p>';
    echo '<a href="' . e(app_url()) . '">';
    echo 'Quay lại trang chủ';
    echo '</a>';
    echo '</body>';
    echo '</html>';

    exit;
}

// ==================================================
// 3. KIEM TRA CSRF TOKEN
// ==================================================

require_csrf();

// ==================================================
// 4. KIEM TRA TRANG THAI DANG NHAP
// ==================================================

if (!is_logged_in()) {

    // Khong can huy Session neu chua dang nhap.
    // Chuyen ve trang dang nhap.
    redirect('login.php');
}

// ==================================================
// 5. HUY PHIEN DANG NHAP
// ==================================================

// logout_user() da duoc dinh nghia
// trong includes/auth.php.
//
// Ham nay thuc hien:
// - Xoa du lieu Session
// - Xoa Session Cookie
// - session_destroy()

logout_user();

// ==================================================
// 6. TAO SESSION KHACH MOI
// ==================================================

// Khong tai su dung Session ID da dang xuat.
// Session moi dung de luu flash message.
startAppSession();

// Dam bao CSRF token moi cho phien khach.
$_SESSION['csrf_token'] = bin2hex(
    random_bytes(32)
);

// ==================================================
// 7. THONG BAO THANH CONG
// ==================================================

set_flash(
    'success',
    'Bạn đã đăng xuất thành công.'
);

// ==================================================
// 8. CHUYEN VE TRANG DANG NHAP
// ==================================================

redirect('login.php');
