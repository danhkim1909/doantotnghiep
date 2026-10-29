
<?php
/**
 * ==================================================
 * BRIGHTFUTURE - DASHBOARD ROUTER
 * File: dashboard.php
 * PHP 8.0+ / MySQL PDO / PHP Session
 * ==================================================
 */

// ==================================================
// 1. LOAD HE THONG XAC THUC VA PHAN QUYEN
// ==================================================

require_once __DIR__ . '/includes/permissions.php';

// ==================================================
// 2. KHONG CACHE NOI DUNG CA NHAN
// ==================================================

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ==================================================
// 3. CHI CHAP NHAN PHUONG THUC GET
// ==================================================

$requestMethod = strtoupper(
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);

if ($requestMethod !== 'GET') {

    header('Allow: GET');
    http_response_code(405);

    header('Content-Type: text/plain; charset=utf-8');

    exit('405 - Phương thức không được hỗ trợ.');
}

// ==================================================
// 4. BAT BUOC DANG NHAP
// ==================================================

// require_login() da duoc dinh nghia
// trong includes/auth.php.
//
// Neu chua dang nhap:
// - Luu thong bao vao Session
// - Chuyen ve login.php
//
// Neu da dang nhap:
// - Lay thong tin user tu MySQL
// - Kiem tra vai tro
// - Lam moi Session ID dinh ky

$user = require_login();

// ==================================================
// 5. LAY VAI TRO NGUOI DUNG
// ==================================================

$role = $user['role'] ?? null;

// ==================================================
// 6. KIEM TRA VAI TRO HOP LE
// ==================================================

if (
    !is_string($role)
    || !in_array($role, USER_ROLES, true)
) {
    deny_access(
        false,
        'Tài khoản của bạn không có vai trò hợp lệ.'
    );
}

// ==================================================
// 7. DIEU HUONG THEO VAI TRO
// ==================================================

switch ($role) {

    // ----------------------------------------------
    // HOC VIEN
    // ----------------------------------------------

    case ROLE_STUDENT:

        redirect('student/dashboard.php');

    // ----------------------------------------------
    // GIAO VIEN
    // ----------------------------------------------

    case ROLE_TEACHER:

        redirect('teacher/dashboard.php');

    // ----------------------------------------------
    // QUAN TRI VIEN
    // ----------------------------------------------

    case ROLE_ADMIN:

        redirect('admin/dashboard.php');

    // ----------------------------------------------
    // VAI TRO KHONG XAC DINH
    // ----------------------------------------------

    default:

        deny_access(
            false,
            'Bạn không có quyền truy cập Dashboard.'
        );
}
