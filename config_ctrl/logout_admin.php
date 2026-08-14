<?php
/* ============================================================
 *  logout_admin.php — ออกจากระบบฝั่งผู้ดูแล
 *  ทำงานเหมือน logout.php ต่างกันแค่ปลายทางที่ส่งกลับ
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

if (session_id() === '') {
    @session_start();
}

$_SESSION = array();

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_unset();
session_destroy();

header('Location: ../login.php');
exit();
