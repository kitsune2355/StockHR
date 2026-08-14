<?php
/* ============================================================
 *  logout.php — ออกจากระบบ
 *  ล้าง session ทั้งหมดแล้วส่งกลับหน้า login
 *  (ไม่มี refresh token ให้ต้องเพิกถอน ตามที่ตกลงไว้)
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

if (session_id() === '') {
    @session_start();
}

$_SESSION = array();

/* ลบ cookie ของ session ทิ้งด้วย ไม่ใช่แค่ล้างค่าฝั่งเซิร์ฟเวอร์ */
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_unset();
session_destroy();

header('Location: ../login.php');
exit();
