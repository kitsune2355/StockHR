<?php
/* ============================================================
 *  backend/approve_link.php — กุญแจลิงก์อนุมัติใบเบิกแบบไม่ต้องล็อกอิน
 *
 *  พี่น้องกับ backend/sign_link.php ตั้งใจให้อ่านคู่กันได้ ต่างกันสามข้อ:
 *
 *    1. เก็บในตารางแยก (tb_approve_link) ไม่ใช่คอลัมน์ในเอกสาร
 *       เพราะใบเบิกหนึ่งใบมีผู้อนุมัติได้หลายคน และแต่ละคนต้องได้กุญแจ
 *       คนละดอก ไม่งั้นบอกไม่ได้ว่าใครเป็นคนกดอนุมัติ
 *
 *    2. อายุ 3 วัน ไม่ใช่ 7 การอนุมัติคือการอนุญาตให้ของออกจากคลัง
 *       ซึ่งแรงกว่าการเซ็นรับของ
 *
 *    3. ใช้ได้ครั้งเดียว ตัดสินสำเร็จแล้วประทับ used_at ทันที
 *
 *  ---------------------------------------------------------------
 *  กุญแจนี้คือสิทธิ์ และเป็นสิทธิ์ "ในนามของคนคนหนึ่ง"
 *  ---------------------------------------------------------------
 *  ใครถือลิงก์ก็ตัดสินใบนั้นในนามผู้อนุมัติที่ลิงก์ออกให้ได้ จึงคุมสี่ชั้น:
 *    1. เดาไม่ได้   sha1 ของ uniqid ที่มี entropy เพิ่ม ได้ 40 ตัวอักษร
 *    2. หมดอายุ     3 วัน
 *    3. ใช้ครั้งเดียว ตัดสินแล้วกุญแจตาย
 *    4. เพิกถอนได้  ออกดอกใหม่ทับดอกเก่าของคนเดิม
 *
 *  ทำได้อย่างเดียวคืออ่านใบนั้นแล้วอนุมัติหรือไม่อนุมัติ
 *  ดูใบอื่น แก้จำนวน หรือยกเลิกใบไม่ได้ เพราะไม่มีทางให้ทำ
 *
 *  ออกให้เฉพาะใบเบิกที่ยังรออนุมัติ — ใบที่ตัดสินไปแล้วออกลิงก์ไม่ได้
 *  ตรวจที่นี่ ไม่ปล่อยให้ผู้เรียกจำเอง
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/setting.php';

/* อายุของกุญแจนับเป็นวัน ตั้งไว้ที่เดียว ไม่กระจายเลข 3 ไปทั่วไฟล์ */
define('APPROVE_LINK_DAYS', 3);


/* ============================================================
 *  ทุกฟังก์ชันในไฟล์นี้ห้ามโยน exception ออกไปเด็ดขาด
 * ============================================================
 *  ตัวเรียกหลักคือ notify_events.php ซึ่งทำงาน "หลัง" ใบเบิกถูกบันทึกแล้ว
 *  และมีกติกาเหล็กเขียนไว้ในหัวไฟล์ว่าแจ้งเตือนต้องไม่ทำให้งานหลักพัง
 *
 *  บทเรียนจากของจริง: เขียนครั้งแรกใช้ @mysqli_prepare() แล้วเข้าใจว่า
 *  ถ้าตารางยังไม่ถูกสร้างก็แค่คืนค่าว่างไป แต่ @ กดได้แค่ warning
 *  ไม่ได้กด exception — และ mysqli บน PHP รุ่นใหม่โยน mysqli_sql_exception
 *  เป็นค่าปริยาย ผลคือคนกดส่งใบเบิกเจอหน้าจอ fatal error ทั้งที่ใบถูกบันทึก
 *  ไปเรียบร้อยแล้ว คือเสียหายหนักกว่าไม่มีลิงก์เสียอีก
 *
 *  (ทดสอบด้วย PHP CLI คนละรุ่นกับที่เว็บเซิร์ฟเวอร์ใช้ จึงไม่เจอตั้งแต่แรก)
 *
 *  ตัวห่อสองตัวข้างล่างจึงเป็นทางเดียวที่แตะฐานข้อมูลในไฟล์นี้
 *  ล้มด้วยเหตุใดก็ตาม — ตารางหาย สิทธิ์ไม่พอ ฐานข้อมูลล่ม — คืน null
 *  แล้วผู้เรียกตกกลับไปใช้ลิงก์เข้าระบบแบบเดิม
 * ============================================================ */

function approve_link_prepare($connect, $sql)
{
    try {
        $stmt = mysqli_prepare($connect, $sql);
        return $stmt ? $stmt : null;
    } catch (Exception $e) {
        return null;
    }
}

function approve_link_exec($stmt)
{
    try {
        return mysqli_stmt_execute($stmt) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}


/* ---------- สุ่มกุญแจหนึ่งดอก ----------
 * เหตุผลเดียวกับ sign_token_make() ทุกประการ — PHP 5.2.6 ไม่มี random_bytes
 * ผสม doc_id กับชื่อผู้อนุมัติเข้าไปด้วย เพื่อให้ผู้อนุมัติสองคนของใบเดียวกัน
 * ที่ขอกุญแจในไมโครวินาทีเดียวกันไม่ได้ค่าชนกัน
 */
function approve_token_make($doc_id, $username)
{
    return sha1(uniqid(mt_rand(), true) . '|' . (int)$doc_id . '|' . $username . '|' . microtime());
}


/* ---------- ใบนี้ยังออกลิงก์อนุมัติได้ไหม ----------
 * คืน true เฉพาะใบเบิก/ใบปรับยอดที่สถานะยังเป็น waiting
 * ใบที่ตัดสินไปแล้วออกลิงก์ไม่ได้ เพราะลิงก์ที่กดแล้วไม่เกิดอะไรขึ้น
 * แย่กว่าไม่มีลิงก์ให้ — คนกดจะไม่รู้ว่าตกลงอนุมัติไปหรือยัง
 */
function approve_link_doc_ok($doc_id)
{
    $connect = db_connect();
    $stmt = approve_link_prepare($connect,
        "SELECT 1 FROM `tb_doc_header`
          WHERE `doc_id` = ? AND `doc_type` IN ('request','adjust') AND `status` = 'waiting'
          LIMIT 1");
    if (!$stmt) { return false; }

    $id = (int)$doc_id;
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!approve_link_exec($stmt)) { mysqli_stmt_close($stmt); return false; }
    mysqli_stmt_store_result($stmt);
    $found = (mysqli_stmt_num_rows($stmt) > 0);
    mysqli_stmt_close($stmt);

    return $found;
}


/* ---------- ออกกุญแจดอกใหม่ให้ผู้อนุมัติหนึ่งคน ----------
 * เขียนทับดอกเดิมของคนเดิมเสมอ = เพิกถอนของเก่าไปในตัว
 * คืนกุญแจที่ออกให้ หรือ '' ถ้าออกไม่ได้
 *
 * ตรวจว่า username เป็นผู้ใช้จริงก่อน เพราะคอลัมน์นี้มี foreign key
 * ใส่ชื่อที่ไม่มีอยู่จะล้มทั้งคำสั่งด้วย error 1452 แบบเงียบ ๆ
 */
function approve_link_issue($doc_id, $username, $actor = null)
{
    $doc_id   = (int)$doc_id;
    $username = trim((string)$username);

    if ($doc_id <= 0 || $username === '')   { return ''; }
    if (!approve_link_doc_ok($doc_id))      { return ''; }

    $connect = db_connect();
    $token   = approve_token_make($doc_id, $username);

    /* REPLACE ไม่ใช้ เพราะจะไปชนกับ foreign key แบบ CASCADE ของตารางอื่นได้
       และ MySQL 5.0 ยังไม่มี ON DUPLICATE KEY ที่อ่านง่ายในกรณีสองคีย์
       ลบของเดิมก่อนแล้วค่อยเพิ่มใหม่ ตรงไปตรงมาและอ่านออกว่าเกิดอะไรขึ้น */
    $del = approve_link_prepare($connect,
        "DELETE FROM `tb_approve_link` WHERE `doc_id` = ? AND `username` = ?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'is', $doc_id, $username);
        approve_link_exec($del);
        mysqli_stmt_close($del);
    }

    $by = ($actor === null || $actor === '') ? null : (string)$actor;

    $stmt = approve_link_prepare($connect,
        "INSERT INTO `tb_approve_link`
             (`doc_id`, `username`, `token`, `expires_at`, `created_at`, `created_by`)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . (int)APPROVE_LINK_DAYS . " DAY), NOW(), ?)");
    if (!$stmt) { return ''; }

    mysqli_stmt_bind_param($stmt, 'isss', $doc_id, $username, $token, $by);
    $ok = approve_link_exec($stmt);
    mysqli_stmt_close($stmt);

    return $ok ? $token : '';
}


/* ---------- อ่านกุญแจที่ยังใช้ได้ของผู้อนุมัติคนหนึ่ง ----------
 * คืน '' ถ้ายังไม่เคยออก หมดอายุ หรือถูกใช้ไปแล้ว
 */
function approve_link_current($doc_id, $username)
{
    $doc_id   = (int)$doc_id;
    $username = trim((string)$username);
    if ($doc_id <= 0 || $username === '') { return ''; }

    $connect = db_connect();
    $stmt = approve_link_prepare($connect,
        "SELECT `token`
           FROM `tb_approve_link`
          WHERE `doc_id` = ? AND `username` = ?
            AND `used_at` IS NULL
            AND `expires_at` > NOW()
          LIMIT 1");
    if (!$stmt) { return ''; }

    mysqli_stmt_bind_param($stmt, 'is', $doc_id, $username);
    if (!approve_link_exec($stmt)) { mysqli_stmt_close($stmt); return ''; }
    mysqli_stmt_bind_result($stmt, $token);

    $out = mysqli_stmt_fetch($stmt) ? $token : '';
    mysqli_stmt_close($stmt);
    return $out;
}


/* ---------- ขอกุญแจที่ใช้ได้ ออกใหม่ให้ถ้ายังไม่มีหรือใช้ไม่ได้แล้ว ----------
 * ใช้ของเดิมถ้ายังไม่หมดอายุ ด้วยเหตุผลเดียวกับลิงก์เซ็นรับ:
 * กดคัดลอกลิงก์สองครั้งแล้วได้คนละอัน จะทำให้ลิงก์ที่เพิ่งส่งไปใช้ไม่ได้
 */
function approve_link_ensure($doc_id, $username, $actor = null)
{
    $token = approve_link_current($doc_id, $username);
    if ($token !== '') { return $token; }

    return approve_link_issue($doc_id, $username, $actor);
}


/* ---------- ประกอบเป็น URL เต็ม ----------
 * คืน '' ถ้าหาที่อยู่ระบบไม่ได้ เพราะลิงก์ที่ไม่มีชื่อโฮสต์ส่งไปในเมลแล้วกดไม่ได้
 * ปล่อยว่างดีกว่าส่งของเสียออกไป
 */
function approve_link_url($token)
{
    if ($token === '') { return ''; }

    $base = app_base_url();
    if ($base === '') { return ''; }

    return $base . 'approve.php?t=' . urlencode($token);
}


/* ---------- ลิงก์พร้อมใช้ของผู้อนุมัติคนหนึ่ง ---------- */
function approve_link_for($doc_id, $username, $actor = null)
{
    return approve_link_url(approve_link_ensure($doc_id, $username, $actor));
}


/* ---------- หาใบเบิกจากกุญแจ ----------
 * คืน array('ok'=>bool, 'reason'=>..., 'doc_id'=>int, 'username'=>string)
 *
 * reason แยกสามแบบเพราะทางแก้คนละทาง:
 *    notfound  ลิงก์ผิด พิมพ์มาไม่ครบ หรือถูกออกใหม่ทับไปแล้ว
 *    expired   หมดอายุ ขอลิงก์ใหม่ได้
 *    used      ตัดสินไปแล้วด้วยลิงก์นี้ ไม่ต้องทำอะไรต่อ
 *
 * ตรวจรูปแบบก่อนยิง query กันคนลองยิงค่ามั่ว ๆ เข้ามารัว ๆ
 */
function approve_link_find($token)
{
    $token = trim((string)$token);

    if ($token === '' || strlen($token) !== 40 || !preg_match('/^[0-9a-f]{40}$/', $token)) {
        return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0, 'username' => '');
    }

    $connect = db_connect();
    $stmt = approve_link_prepare($connect,
        "SELECT `doc_id`, `username`,
                (`used_at` IS NOT NULL)   AS `spent`,
                (`expires_at` > NOW())    AS `alive`
           FROM `tb_approve_link`
          WHERE `token` = ?
          LIMIT 1");
    if (!$stmt) {
        return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0, 'username' => '');
    }

    mysqli_stmt_bind_param($stmt, 's', $token);
    if (!approve_link_exec($stmt)) { mysqli_stmt_close($stmt); return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0, 'username' => ''); }
    mysqli_stmt_bind_result($stmt, $doc_id, $username, $spent, $alive);

    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0, 'username' => '');
    }

    $out = array('doc_id' => (int)$doc_id, 'username' => $username);

    /* เรียงลำดับเหตุผลตามความสำคัญที่ผู้ใช้ควรเห็น
       "ใช้ไปแล้ว" ต้องมาก่อน "หมดอายุ" เพราะลิงก์ที่ใช้แล้วและเลยกำหนดด้วย
       สิ่งที่ผู้ใช้ต้องรู้คือเรื่องนี้ตัดสินไปแล้ว ไม่ใช่ให้ไปขอลิงก์ใหม่ */
    if ($spent) { $out['ok'] = false; $out['reason'] = 'used';    return $out; }
    if (!$alive){ $out['ok'] = false; $out['reason'] = 'expired'; return $out; }

    $out['ok'] = true; $out['reason'] = 'ok';
    return $out;
}


/* ---------- ประทับว่ากุญแจถูกใช้ไปแล้ว ----------
 * เรียกหลังตัดสินสำเร็จเท่านั้น ตัดสินไม่ผ่าน (เช่นโดนกฎห้ามอนุมัติใบตัวเอง)
 * ต้องไม่เผากุญแจทิ้ง ไม่งั้นผู้อนุมัติเปิดลิงก์อ่านเหตุผลซ้ำไม่ได้
 */
function approve_link_spend($token, $actor = null)
{
    $token = trim((string)$token);
    if ($token === '') { return false; }

    $connect = db_connect();
    $by = ($actor === null || $actor === '') ? null : (string)$actor;

    $stmt = approve_link_prepare($connect,
        "UPDATE `tb_approve_link`
            SET `used_at` = NOW(), `updated_by` = ?
          WHERE `token` = ? AND `used_at` IS NULL");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'ss', $by, $token);
    $ok = approve_link_exec($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


/* ---------- เผากุญแจทุกดอกของใบหนึ่ง ----------
 * ใบที่ถูกตัดสินไปแล้ว (ไม่ว่าทางไหน) ลิงก์ของผู้อนุมัติคนอื่นต้องตายตามด้วย
 *
 * ไม่งั้นผู้อนุมัติคนที่สองเปิดลิงก์แล้วเห็นหน้าปุ่มอนุมัติเต็ม ๆ กดไป
 * แล้วเจอข้อความว่าใบถูกตัดสินไปแล้ว ซึ่งเสียเที่ยวและอ่านเหมือนระบบพัง
 * ทำเป็น "ใช้แล้ว" ไม่ใช่ลบทิ้ง เพื่อให้หน้าจอบอกได้ว่าเรื่องนี้จบไปแล้ว
 */
function approve_link_close_doc($doc_id, $actor = null)
{
    $doc_id = (int)$doc_id;
    if ($doc_id <= 0) { return false; }

    $connect = db_connect();
    $by = ($actor === null || $actor === '') ? null : (string)$actor;

    $stmt = approve_link_prepare($connect,
        "UPDATE `tb_approve_link`
            SET `used_at` = NOW(), `updated_by` = ?
          WHERE `doc_id` = ? AND `used_at` IS NULL");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'si', $by, $doc_id);
    $ok = approve_link_exec($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


/* ---------- ลิงก์ของผู้อนุมัติทุกคนของใบหนึ่ง ----------
 * ใช้ตอนผู้ดูแลระบบกดคัดลอกลิงก์ ซึ่งต้องเลือกได้ว่าจะส่งให้ใคร
 * คืน array of array('username','full_name','url')
 */
function approve_link_all($doc_id, $actor = null)
{
    require_once dirname(__FILE__) . '/document.php';
    require_once dirname(__FILE__) . '/user.php';

    $doc = doc_find($doc_id);
    if ($doc === null) { return array(); }

    /* ผู้รับลิงก์ต้องเป็นชุดเดียวกับผู้รับเมล ไม่งั้นจะมีคนได้ลิงก์
       ทั้งที่ไม่เคยได้เมล หรือกลับกัน */
    if ($doc['doc_type'] === 'adjust') {
        require_once dirname(__FILE__) . '/adjust_approver.php';
        $names  = adjust_approver_usernames();
        $people = array();
        for ($i = 0; $i < count($names); $i++) {
            if ($names[$i] === $doc['created_by']) { continue; }   // ห้ามอนุมัติใบตัวเอง
            $u = user_find($names[$i]);
            if ($u !== null && (int)$u['is_active'] === 1) {
                $people[] = array('username' => $u['username'], 'full_name' => $u['full_name']);
            }
        }
    } elseif ($doc['doc_type'] === 'request') {
        $people = user_approvers_for_dept($doc['department_id'], $doc['requester']);
    } else {
        return array();
    }

    $out = array();
    for ($i = 0; $i < count($people); $i++) {
        $url = approve_link_for($doc['doc_id'], $people[$i]['username'], $actor);
        if ($url === '') { continue; }

        $out[] = array(
            'username'  => $people[$i]['username'],
            'full_name' => ($people[$i]['full_name'] !== '')
                           ? $people[$i]['full_name'] : $people[$i]['username'],
            'url'       => $url
        );
    }
    return $out;
}
