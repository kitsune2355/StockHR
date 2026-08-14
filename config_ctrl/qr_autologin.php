<?php
/* ============================================================
 *  qr_autologin.php — เข้าสู่ระบบด้วย QR Code สำหรับผู้แจ้งซ่อม
 *
 *  รับ ?u=<username> แล้วสร้าง session ให้เลยโดยไม่ถามรหัสผ่าน
 *
 *  คำเตือนด้านความปลอดภัย:
 *    ไฟล์นี้ยืนยันตัวตนด้วย "ชื่อผู้ใช้" อย่างเดียว ใครก็ตามที่เดาหรือรู้
 *    ชื่อผู้ใช้ของคนอื่น สามารถเปิด URL นี้แล้วเข้าระบบเป็นคนนั้นได้ทันที
 *    ถ้ายังต้องใช้งานต่อ ควรเปลี่ยนไปใช้ token สุ่มที่มีวันหมดอายุแทน
 *    ผูกกับผู้ใช้แต่ละคน แทนการส่งชื่อผู้ใช้ตรง ๆ ทาง URL
 *
 *  หมายเหตุ: เขียนให้รองรับ PHP 5.2.6 และใช้ prepared statement ทุก query
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);
@session_start();

$username = isset($_GET['u']) ? trim($_GET['u']) : '';

if ($username === '') {
    $_SESSION['login_error'] = 'QR Code ไม่ถูกต้อง หรือไม่ได้ระบุผู้ใช้งาน';
    header('Location: ../login.php');
    exit();
}

/* ---------- ค่าประจำระบบ ---------- */
$db_short_name = '82';
$server_host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$server_id     = ($server_host === 'localhost') ? ('db_job_' . $db_short_name) : 'happylandc_wha';

include "connect.php";

/* ---------- ส่งกลับหน้า login พร้อมข้อความผิดพลาด ----------
 * ล้าง session ทิ้งก่อน กันสภาพ "ล็อกอินค้างครึ่งทาง" ที่ทำให้ login.php
 * เห็นว่าล็อกอินแล้วและเด้งต่อโดยไม่แสดงข้อความผิดพลาด
 */
function qr_login_failed($message)
{
    $_SESSION = array();
    $_SESSION['login_error'] = $message;
    header('Location: ../login.php');
    exit();
}

/* ---------- ค้นหาผู้ใช้ ---------- */
$sql = "SELECT `username`, `first_name`, `last_name`, `full_name`, `email`,
               `role`, `position`, `position_level`, `dept_id`, `agency_id`, `is_active`
        FROM `tb_user`
        WHERE `username` = ?
        LIMIT 1";

$stmt = mysqli_prepare($connect, $sql);
if (!$stmt) {
    qr_login_failed('เกิดข้อผิดพลาดในการตรวจสอบผู้ใช้งาน');
}

mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result(
    $stmt,
    $u_username, $u_first_name, $u_last_name, $u_full_name, $u_email,
    $u_role, $u_position, $u_position_level, $u_dept_id, $u_agency_id, $u_is_active
);
$found = mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

if (!$found) {
    qr_login_failed('ไม่พบสิทธิ์การใช้งานของบัญชีผู้แจ้งซ่อม');
}

if ((string)$u_is_active !== '1') {
    qr_login_failed('บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
}

/* ---------- ตั้ง session เหมือนล็อกอินปกติ ---------- */
session_regenerate_id(true);

$_SESSION['sess_id']             = session_id();
$_SESSION['sess_username']       = $u_username;
$_SESSION['sess_first_name']     = $u_first_name;
$_SESSION['sess_last_name']      = $u_last_name;
$_SESSION['sess_full_name']      = $u_full_name;
$_SESSION['sess_email']          = $u_email;
$_SESSION['sess_role']           = $u_role;
$_SESSION['sess_position']       = $u_position;
$_SESSION['sess_position_level'] = $u_position_level;
$_SESSION['sess_dept_id']        = $u_dept_id;
$_SESSION['sess_agency_id']      = (int)$u_agency_id;
$_SESSION['sess_is_active']      = $u_is_active;
$_SESSION['sess_system_ctrl']    = $db_short_name;
$_SESSION['sess_server_id']      = $server_id;
$_SESSION['sess_server_host']    = $server_host;
$_SESSION['sess_tmp_user_id']    = date('His');
$_SESSION['sess_last_activity']  = time();
$_SESSION['sess_need_agency_popup'] = 0;

/* ---------- บันทึกเวลาเข้าใช้งาน (ข้ามได้ถ้าตารางยังไม่มี) ---------- */
$stmt_log = mysqli_prepare($connect, "INSERT INTO `tb_time_login` SET `tl_name` = ?, `tl_time` = NOW()");
if ($stmt_log) {
    mysqli_stmt_bind_param($stmt_log, 's', $u_username);
    mysqli_stmt_execute($stmt_log);
    mysqli_stmt_close($stmt_log);
}

/* ---------- โหลดหน่วยงานของผู้ใช้ ---------- */
$ag_id_param = (int)$u_agency_id;
if ($ag_id_param > 0) {
    $sql_ag = "SELECT `ag_id`, `ag_job`, `ag_contract`, `ag_start_date`, `ag_end_date`
               FROM `tb_agency`
               WHERE `ag_id` = ? AND `ag_status` = 1
               LIMIT 1";
    $stmt_ag = mysqli_prepare($connect, $sql_ag);
    if ($stmt_ag) {
        mysqli_stmt_bind_param($stmt_ag, 'i', $ag_id_param);
        mysqli_stmt_execute($stmt_ag);
        mysqli_stmt_bind_result($stmt_ag, $ag_id, $ag_job, $ag_contract, $ag_start_date, $ag_end_date);
        if (mysqli_stmt_fetch($stmt_ag)) {
            $_SESSION['sess_agency_id']         = (int)$ag_id;
            $_SESSION['sess_agency_code']       = $ag_job;
            $_SESSION['sess_agency_name']       = $ag_contract;
            $_SESSION['sess_agency_start_date'] = $ag_start_date;
            $_SESSION['sess_agency_end_date']   = $ag_end_date;
            $_SESSION['sess_is_qr_user']        = true;
        }
        mysqli_stmt_close($stmt_ag);
    }
}

header('Location: ../main2.php#maintenance_request.php');
exit();
