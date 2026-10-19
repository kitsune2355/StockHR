<?php
/* ============================================================
 *  api/_bootstrap.php — ตัวตั้งต้นของทุก endpoint ในโฟลเดอร์ api/
 *
 *  โฟลเดอร์ api/ คือ "ทางเข้าแบบ JSON" ของระบบ
 *  ใช้ชั้น backend/ ตัวเดียวกับหน้าเว็บ จึงได้กติกาตรวจข้อมูลชุดเดียวกัน
 *
 *  กติกาของชั้นนี้:
 *    - ตอบเป็น JSON เท่านั้น ห้ามพ่น HTML ออกไป
 *    - ต้องล็อกอินก่อนเสมอ และต้องมี CSRF token ถ้าเป็นคำสั่งที่เขียนข้อมูล
 *    - ไม่มี SQL อยู่ในโฟลเดอร์นี้ ให้เรียกฟังก์ชันจาก backend/ แทน
 *
 *  เขียนให้รองรับ PHP 5.2.6 (json_encode มีตั้งแต่ 5.2.0)
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

if (session_id() === '') {
    @session_name('STOCKHR_SID'); @session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');


/* ---------- งานที่ทำหลังตอบผู้ใช้ไปแล้ว ----------
 * ฝากงานไว้ด้วย api_after_response('ชื่อฟังก์ชัน') แล้ว api_send จะเรียกให้
 * หลังปิดการเชื่อมต่อ ผู้ใช้จึงไม่ต้องรอ
 *
 * ใช้กับงานที่ช้าและผลลัพธ์ไม่จำเป็นต้องอยู่ในคำตอบ เช่นการส่งอีเมล
 * ซึ่งใช้เวลาหลายวินาทีต่อฉบับเพราะต้องต่อ SMTP ออกไปข้างนอก
 */
$GLOBALS['api_after_response'] = array();

function api_after_response($fn)
{
    $GLOBALS['api_after_response'][] = $fn;
}


/* ---------- ตอบกลับแล้วจบการทำงาน ----------
 * ปิดการเชื่อมต่อก่อนแล้วค่อยทำงานที่ฝากไว้
 *
 * บอกความยาวไปด้วยแล้วสั่งปิด เบราว์เซอร์จึงรู้ว่าได้ครบแล้วและเลิกรอ
 * ส่วน PHP ยังทำงานต่อจนจบสคริปต์ (ignore_user_abort กันไม่ให้ถูกฆ่าทิ้ง)
 *
 * ใช้ได้เพราะเซิร์ฟเวอร์ทั้งสองที่ไม่ได้บีบอัดคำตอบ — ถ้าบีบอัด
 * ความยาวที่บอกไปจะไม่ตรงกับที่ส่งจริง แล้วคำตอบจะขาดกลางคัน (ตรวจแล้วไม่บีบ)
 * โฮสต์ที่เป็น PHP-FPM จะได้ fastcgi_finish_request ซึ่งตรงไปตรงมากว่า
 *
 * ถ้าวิธีนี้ไม่ได้ผลบนเซิร์ฟเวอร์ไหน ผลคือผู้ใช้รอเท่าเดิม ไม่มีอะไรพัง
 */
function api_send($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }

    $body  = json_encode($payload);
    $tasks = $GLOBALS['api_after_response'];

    if (count($tasks) > 0) {
        ignore_user_abort(true);
        @set_time_limit(120);

        if (!headers_sent()) {
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
    }

    echo $body;

    if (count($tasks) === 0) { exit(); }

    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

    /* ห้ามให้งานเบื้องหลังพังแล้วไปกระทบอะไร คำตอบถูกส่งไปแล้ว */
    for ($i = 0; $i < count($tasks); $i++) {
        if (is_callable($tasks[$i])) { @call_user_func($tasks[$i]); }
    }

    exit();
}

function api_ok($data = array())
{
    $out = array('ok' => true);
    foreach ($data as $k => $v) { $out[$k] = $v; }
    api_send($out);
}

function api_fail($message, $http_status = 400)
{
    if ($http_status === 401) {
        header('HTTP/1.1 401 Unauthorized');
    } elseif ($http_status === 403) {
        header('HTTP/1.1 403 Forbidden');
    } elseif ($http_status === 405) {
        header('HTTP/1.1 405 Method Not Allowed');
    } else {
        header('HTTP/1.1 400 Bad Request');
    }
    api_send(array('ok' => false, 'message' => $message));
}


/* ---------- ต้องล็อกอินก่อน ----------
 * ไม่ใช้ checksession.php เพราะตัวนั้น redirect เป็น HTML
 * ซึ่ง fetch() ฝั่งหน้าเว็บจะอ่านไม่รู้เรื่อง ต้องตอบ 401 เป็น JSON แทน
 */
function api_require_login()
{
    if (empty($_SESSION['sess_username']) || empty($_SESSION['sess_role'])) {
        api_fail('เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่', 401);
    }
    return $_SESSION['sess_username'];
}


/* ---------- จำกัดสิทธิ์ตาม role ---------- */
function api_require_role($allowed_roles)
{
    $role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';
    if (!in_array($role, $allowed_roles)) {
        api_fail('คุณไม่มีสิทธิ์ใช้งานส่วนนี้', 403);
    }
    return $role;
}


/* ---------- อ่าน body ที่ส่งมาเป็น JSON ---------- */
function api_input()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return array();
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}


/* ---------- ตรวจ CSRF token (ใช้กับคำสั่งที่เขียนข้อมูลเท่านั้น) ---------- */
function api_require_csrf($input)
{
    $sent    = isset($input['csrf_token']) ? $input['csrf_token'] : '';
    $expect  = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

    if ($expect === '' || $sent !== $expect) {
        api_fail('เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่', 403);
    }
}


/* ---------- รับเฉพาะ POST ---------- */
function api_require_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_fail('รองรับเฉพาะการส่งแบบ POST', 405);
    }
}
