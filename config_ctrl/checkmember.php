<?php
/* ============================================================
 *  checkmember.php — ตรวจสอบสิทธิ์เข้าสู่ระบบ
 *
 *  รับจากฟอร์ม login.php:  LogInName, LogInPassWord
 *  สำเร็จ  -> ตั้ง session แล้วส่งไป ../main.php
 *  ไม่สำเร็จ -> ตั้ง $_SESSION['login_error'] แล้วส่งกลับ ../login.php
 *
 *  หมายเหตุ:
 *   - เขียนให้รองรับ PHP 5.2.6 (เวอร์ชันที่ Apache ตัวนี้ใช้จริง)
 *     ห้ามใช้ [] แทน array(), ห้ามใช้ ??, ห้ามใช้ mysqli_stmt_get_result()
 *   - ทุก query ใช้ prepared statement
 *   - ไม่มีระบบ access token / refresh token ตามที่ตกลงไว้
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);
@session_start();

$username     = isset($_POST['LogInName'])     ? trim($_POST['LogInName'])     : '';
$password_raw = isset($_POST['LogInPassWord']) ? trim($_POST['LogInPassWord']) : '';

/* ---------- ส่งกลับหน้า login พร้อมข้อความผิดพลาด ----------
 * ต้องล้าง session ทิ้งก่อนเสมอ เพราะบางกรณีเราตั้ง session ไปแล้ว
 * ค่อยมาล้มทีหลัง (เช่น หาหน่วยงานของผู้ใช้ไม่เจอ) ถ้าปล่อยค้างไว้
 * login.php จะเห็นว่า "ล็อกอินแล้ว" แล้วเด้งต่อไป main.php
 * ผู้ใช้จะไม่มีวันเห็นข้อความผิดพลาด และเข้าระบบแบบไม่มีหน่วยงานได้
 */
function login_failed($message)
{
    $_SESSION = array();
    $_SESSION['login_error'] = $message;
    header('Location: ../login.php');
    exit();
}

if ($username === '' || $password_raw === '') {
    login_failed('กรุณากรอกชื่อผู้ใช้งาน และรหัสผ่าน');
}

/* ---------- ค่าประจำระบบ ---------- */
$db_short_name = '82';
$server_host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$server_id     = ($server_host === 'localhost') ? ('db_job_' . $db_short_name) : 'happylandc_wha';

include "connect.php";

/* ---------- ตรวจ username + password ---------- */
$password_hash = md5(md5(md5($password_raw)));

$sql = "SELECT `username`, `first_name`, `last_name`, `full_name`, `email`,
               `role`, `position`, `position_level`, `dept_id`, `agency_id`, `is_active`
        FROM `tb_user`
        WHERE `username` = ? AND `password` = ?
        LIMIT 1";

$stmt = mysqli_prepare($connect, $sql);
if (!$stmt) {
    login_failed('เกิดข้อผิดพลาดในการตรวจสอบผู้ใช้งาน');
}

mysqli_stmt_bind_param($stmt, 'ss', $username, $password_hash);
mysqli_stmt_execute($stmt);

/* PHP 5.2 ไม่มี mysqli_stmt_get_result() จึงต้อง bind ผลลัพธ์ทีละคอลัมน์ */
mysqli_stmt_bind_result(
    $stmt,
    $u_username, $u_first_name, $u_last_name, $u_full_name, $u_email,
    $u_role, $u_position, $u_position_level, $u_dept_id, $u_agency_id, $u_is_active
);

$found = mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

if (!$found) {
    login_failed('ชื่อผู้ใช้งาน หรือรหัสผ่านไม่ถูกต้อง');
}

/* ---------- บัญชีถูกระงับ ---------- */
if ((string)$u_is_active !== '1') {
    login_failed('บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
}

/* ---------- ตั้ง session ---------- */
session_regenerate_id(true);   // กัน session fixation หลังยืนยันตัวตนผ่าน

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

/* ---------- บันทึกเวลาเข้าใช้งาน ----------
   ต้องกันไว้สองชั้น เพราะตารางที่ไม่มีทำให้ mysqli บนเครื่องนี้ทั้ง "โยน
   exception" และ "พิมพ์ warning" ออกมาพร้อมกัน

     try/catch   กัน mysqli_sql_exception ที่โยนตอน prepare
                 ของเดิมใช้ if ($stmt_log) ซึ่งไม่มีโอกาสได้ทำงานเลย
     @           กัน warning ที่พิมพ์ออกหน้าจอ
                 ถ้าไม่กด ข้อความจะถูกส่งออกไปก่อน แล้ว header('Location')
                 ด้านล่างใช้ไม่ได้ ("headers already sent") คนล็อกอินจะค้าง
                 อยู่หน้าเปล่า ทั้งที่เซสชันถูกตั้งเรียบร้อยแล้วข้างบน

   บันทึกเวลาเป็นของแถม ไม่ใช่เงื่อนไขของการเข้าระบบ ล้มก็ต้องเงียบ
   ถ้าอยากให้บันทึกจริง รัน sql/038_create_tb_time_login.sql */
try {
    $stmt_log = @mysqli_prepare($connect, "INSERT INTO `tb_time_login` SET `tl_name` = ?, `tl_time` = NOW()");
    if ($stmt_log) {
        @mysqli_stmt_bind_param($stmt_log, 's', $u_username);
        @mysqli_stmt_execute($stmt_log);
        @mysqli_stmt_close($stmt_log);
    }
} catch (Exception $e) {
    /* ไม่มีตารางก็ข้ามไป */
}

/* ============================================================
 *  แยกทางตามสิทธิ์
 * ============================================================ */

/* ---------- super_admin : เข้าได้เลย เห็นทุกหน่วยงาน ---------- */
if ($u_role === 'super_admin') {
    $_SESSION['sess_need_agency_popup'] = 0;
    header('Location: ../main.php');
    exit();
}

/* ---------- admin / approver / general : ผูกกับบริษัทเดียว ----------
 *
 *  ของเดิมอ่านจากตาราง tb_agency ของระบบเก่า ซึ่งไม่มีอยู่ในฐานข้อมูลนี้
 *  ผลคือ mysqli_prepare ล้มทุกครั้ง แล้วเด้งกลับหน้า login พร้อมข้อความ
 *  "เกิดข้อผิดพลาดในการโหลดข้อมูลหน่วยงาน" — ทุกบัญชีที่ไม่ใช่ super_admin
 *  จึงเข้าระบบไม่ได้เลย ไม่ว่ารหัสผ่านจะถูกหรือไม่
 *
 *  ระบบใหม่เก็บบริษัทไว้ที่ tb_company (จัดการผ่านหน้า "หน่วยงาน")
 *  คอลัมน์ agency_id ของผู้ใช้จึงชี้ไปที่ tb_company.company_id
 */
if ($u_role === 'admin' || $u_role === 'approver' || $u_role === 'general') {

    $_SESSION['sess_agency_code']       = '';
    $_SESSION['sess_agency_start_date'] = '';
    $_SESSION['sess_agency_end_date']   = '';
    $_SESSION['sess_need_agency_popup'] = 0;

    $ag_id_param = (int)$u_agency_id;

    /* ยังไม่ได้ผูกบริษัท — เข้าระบบได้ แต่แถบบนจะไม่แสดงชื่อบริษัท
       ไม่ปิดกั้นการเข้าระบบ เพราะหน้าจัดการผู้ใช้ยอมให้เลือก "ไม่ระบุ" ได้อยู่แล้ว
       ถ้าบล็อกตรงนี้ คนที่สร้างบัญชีจะงงว่าสร้างเสร็จแล้วทำไมล็อกอินไม่ได้ */
    if ($ag_id_param > 0) {

        $sql_ag = "SELECT `company_id`, `company_name`
                   FROM `tb_company`
                   WHERE `company_id` = ? AND `is_active` = 1
                   LIMIT 1";
        $stmt_ag = mysqli_prepare($connect, $sql_ag);
        if (!$stmt_ag) {
            login_failed('เกิดข้อผิดพลาดในการโหลดข้อมูลบริษัท');
        }

        mysqli_stmt_bind_param($stmt_ag, 'i', $ag_id_param);
        mysqli_stmt_execute($stmt_ag);
        mysqli_stmt_bind_result($stmt_ag, $ag_id, $ag_name);
        $has_agency = mysqli_stmt_fetch($stmt_ag);
        mysqli_stmt_close($stmt_ag);

        if (!$has_agency) {
            login_failed('บริษัทที่ผูกกับบัญชีนี้ถูกปิดใช้งานหรือถูกลบไปแล้ว กรุณาติดต่อผู้ดูแลระบบ');
        }

        $_SESSION['sess_agency_id']   = (int)$ag_id;
        $_SESSION['sess_agency_name'] = $ag_name;
    }

    header('Location: ../main.php');
    exit();
}

/* ---------- สิทธิ์ที่ไม่รู้จัก ---------- */
login_failed('สิทธิ์การใช้งานของบัญชีนี้ไม่ถูกต้อง กรุณาติดต่อผู้ดูแลระบบ');
