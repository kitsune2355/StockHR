<?php
/* ============================================================
 *  checksession.php — ยามเฝ้าประตูของทุกหน้าที่ต้องล็อกอินก่อน
 *
 *  วิธีใช้ (วางบรรทัดแรกสุดของหน้า ก่อนส่ง output ใด ๆ):
 *      include "config_ctrl/checksession.php";
 *
 *  หน้าที่:
 *   1. ไม่มี session -> เด้งกลับหน้า login
 *   2. ไม่ได้ใช้งานเกิน 1 ชั่วโมง -> หมดอายุ เด้งกลับหน้า login
 *   3. ผ่านแล้ว -> เตรียมตัวแปร $sess_* ให้หน้านั้นเรียกใช้ได้เลย
 *
 *  หมายเหตุ:
 *   - เขียนให้รองรับ PHP 5.2.6 (เวอร์ชันที่ Apache ตัวนี้ใช้จริง)
 *   - ไม่มีระบบ access token / refresh token ตามที่ตกลงไว้
 *     หมดเวลาแล้วต้องล็อกอินใหม่เท่านั้น
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

if (session_id() === '') {
    @session_start();
}

/* path กลับไปหน้า login — คิดจากตำแหน่งของไฟล์นี้ ใช้ได้ทั้งหน้า root และหน้าในโฟลเดอร์ย่อย */
$login_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/login.php';
if ($login_url === '/login.php' || $login_url === 'login.php') {
    $login_url = 'login.php';
}

/* ---------- ออกจากระบบแล้วกลับหน้า login ---------- */
function session_expired($login_url, $message)
{
    $_SESSION = array();
    session_unset();
    session_destroy();

    @session_start();
    $_SESSION['login_error'] = $message;

    header('Location: ' . $login_url);
    exit();
}

/* ---------- 1. ต้องมี session ที่ล็อกอินแล้วเท่านั้น ---------- */
if (empty($_SESSION['sess_username']) || empty($_SESSION['sess_role'])) {
    session_expired($login_url, 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
}

/* ---------- 2. หมดอายุเมื่อไม่ได้ใช้งานเกิน 1 ชั่วโมง ---------- */
$idle_limit = 3600;

if (empty($_SESSION['sess_last_activity'])) {
    $_SESSION['sess_last_activity'] = time();
}

if ((time() - (int)$_SESSION['sess_last_activity']) > $idle_limit) {
    session_expired($login_url, 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่');
}

/* ต่ออายุทุกครั้งที่มีการเรียกหน้า */
$_SESSION['sess_last_activity'] = time();

/* ============================================================
 *  3. ตัวแปรที่หน้าอื่นเรียกใช้ได้เลย
 * ============================================================ */

/* ---------- ข้อมูลผู้ใช้ ---------- */
$sess_id             = $_SESSION['sess_id'];
$sess_username       = $_SESSION['sess_username'];
$sess_first_name     = $_SESSION['sess_first_name'];
$sess_last_name      = $_SESSION['sess_last_name'];
$sess_full_name      = $_SESSION['sess_full_name'];
$sess_email          = $_SESSION['sess_email'];
$sess_role           = $_SESSION['sess_role'];
$sess_position       = $_SESSION['sess_position'];
$sess_position_level = $_SESSION['sess_position_level'];
$sess_dept_id        = $_SESSION['sess_dept_id'];
$sess_is_active      = $_SESSION['sess_is_active'];

/* ---------- หน่วยงานที่กำลังใช้งานอยู่ ---------- */
$sess_agency_id         = $_SESSION['sess_agency_id'];
$sess_agency_code       = $_SESSION['sess_agency_code'];
$sess_agency_name       = $_SESSION['sess_agency_name'];
$sess_agency_start_date = $_SESSION['sess_agency_start_date'];
$sess_agency_end_date   = $_SESSION['sess_agency_end_date'];
$sess_agency_list       = $_SESSION['sess_agency_list'];
$sess_need_agency_popup = $_SESSION['sess_need_agency_popup'];
$sess_agency_filter_own = $_SESSION['sess_agency_filter_own'];

/* ---------- ค่าประจำระบบ ---------- */
$sess_system_ctrl = $_SESSION['sess_system_ctrl'];
$sess_server_id   = $_SESSION['sess_server_id'];
$sess_server_host = $_SESSION['sess_server_host'];
$sess_tmp_user_id = $_SESSION['sess_tmp_user_id'];
$sess_is_qr_user  = $_SESSION['sess_is_qr_user'];

$actual_link = 'http://' . $_SERVER['HTTP_HOST'];
