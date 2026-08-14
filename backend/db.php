<?php
/* ============================================================
 *  backend/db.php — จุดเชื่อมต่อฐานข้อมูลของชั้น backend
 *
 *  ไฟล์ในโฟลเดอร์ backend/ คือ "ชั้นเข้าถึงข้อมูล" ของระบบ
 *  กติกาของชั้นนี้:
 *    - ที่นี่เท่านั้นที่เขียน SQL ได้ หน้าเว็บ (pages/) ห้ามมี SQL เด็ดขาด
 *    - ห้าม echo, ห้าม HTML, ห้าม header(), ห้าม die()
 *      ผิดพลาดให้ "คืนค่ากลับ" ไปให้ผู้เรียกตัดสินใจเอง
 *    - ห้ามอ่าน $_POST / $_GET / $_SESSION โดยตรง
 *      ผู้เรียกต้องส่งค่าที่ต้องใช้เข้ามาเป็นพารามิเตอร์
 *    - ทุก query ที่มีค่าจากผู้ใช้ ใช้ prepared statement เท่านั้น
 *
 *  ผลที่ได้: ฟังก์ชันชุดเดียวกันเรียกได้จากหน้าเว็บ, จาก API,
 *  จาก cron หรือจากสคริปต์นำเข้าข้อมูล โดยไม่ต้องเขียน query ซ้ำ
 *
 *  เขียนให้รองรับ PHP 5.2.6 (array() ไม่ใช้ [], ไม่ใช้ ??)
 * ============================================================ */


/* ---------- ขอ connection ----------
 * เรียกกี่ครั้งก็ได้ ต่อจริงครั้งเดียว
 * ถ้าหน้าเว็บ include config_ctrl/connect.php ไว้แล้ว จะใช้ตัวเดิมต่อ
 */
function db_connect()
{
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    if (isset($GLOBALS['connect']) && $GLOBALS['connect']) {
        $conn = $GLOBALS['connect'];
        return $conn;
    }

    include dirname(dirname(__FILE__)) . '/config_ctrl/connect.php';

    $GLOBALS['connect'] = $connect;
    $conn = $connect;
    return $conn;
}


/* ---------- รูปแบบผลลัพธ์มาตรฐานของคำสั่งที่เขียนข้อมูล ----------
 * ทุกฟังก์ชันที่ create / update / delete คืนค่าหน้าตาเดียวกันเสมอ
 *   array(
 *     'ok'      => true|false,
 *     'message' => ข้อความภาษาไทยที่เอาไปแสดงได้เลย,
 *     'id'      => id ของแถวที่เกี่ยวข้อง (ถ้ามี),
 *     'errno'   => รหัส error ของ MySQL (ถ้ามี)
 *   )
 * ผู้เรียกจึงตรวจแค่ $result['ok'] แล้วเอา message ไปแสดง
 */
function db_ok($message, $id = 0)
{
    return array('ok' => true, 'message' => $message, 'id' => (int)$id, 'errno' => 0);
}

function db_fail($message, $errno = 0)
{
    return array('ok' => false, 'message' => $message, 'id' => 0, 'errno' => (int)$errno);
}


/* ---------- แปลรหัส error ของ MySQL เป็นภาษาคน ----------
 * $context = ข้อความที่จะใช้เมื่อลบไม่ได้เพราะมีข้อมูลอื่นอ้างอยู่ (error 1451)
 */
function db_error_message($errno, $context = '')
{
    if ($errno === 1062) {
        return 'มีข้อมูลนี้อยู่แล้ว กรุณาใช้ค่าอื่น';
    }
    if ($errno === 1452) {
        return 'ไม่พบข้อมูลอ้างอิงที่เลือก กรุณาเลือกใหม่';
    }
    if ($errno === 1451) {
        return ($context !== '') ? $context : 'ลบไม่ได้ เพราะยังมีข้อมูลอื่นอ้างถึงรายการนี้อยู่';
    }
    return 'ดำเนินการกับฐานข้อมูลไม่สำเร็จ';
}


/* ---------- นับความยาวข้อความแบบรู้จักภาษาไทย ----------
 * mb_strlen ไม่ได้เปิดใช้ทุกเครื่อง จึงมีทางถอยให้
 */
function db_text_length($text)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    // นับจำนวนอักขระ UTF-8 โดยไม่นับ byte ที่เป็นส่วนต่อของอักขระเดียวกัน
    return strlen(preg_replace('/[\x80-\xBF]/', '', $text));
}


/* ---------- ทำให้ค่าแบ่งหน้าอยู่ในช่วงที่ใช้ได้จริง ----------
 * คืน array('page', 'per_page', 'offset', 'last_page')
 * ใช้ร่วมกันได้ทุกหน้าที่มีตารางแบ่งหน้า
 */
function db_paginate($page, $per_page, $total_rows, $allowed_per_page = null)
{
    if ($allowed_per_page === null) {
        $allowed_per_page = array(10, 20, 50, 100);
    }

    $per_page = (int)$per_page;
    if (!in_array($per_page, $allowed_per_page)) {
        $per_page = 20;
    }

    $total_rows = (int)$total_rows;
    $last_page  = ($total_rows > 0) ? (int)ceil($total_rows / $per_page) : 1;

    $page = (int)$page;
    if ($page < 1)          { $page = 1; }
    if ($page > $last_page) { $page = $last_page; }   // ลบแถวสุดท้ายของหน้าแล้วต้องไม่ค้างหน้าว่าง

    return array(
        'page'      => $page,
        'per_page'  => $per_page,
        'offset'    => ($page - 1) * $per_page,
        'last_page' => $last_page,
        'total'     => $total_rows
    );
}
