<?php
/* ============================================================
 *  backend/sign_link.php — กุญแจลิงก์เซ็นรับของแบบไม่ต้องล็อกอิน
 *
 *  ผู้ขอเบิกส่วนใหญ่ไม่มีบัญชีหรือไม่มีสิทธิ์เข้าหน้าประวัติการจ่ายออก
 *  ปุ่มในเมลจึงพาไปเจอหน้าล็อกอินแล้วงานค้าง ไฟล์นี้ออกกุญแจสุ่มผูกกับ
 *  ใบจ่ายหนึ่งใบ ให้ sign.php เปิดหน้าเซ็นได้โดยไม่ต้องล็อกอิน
 *
 *  กุญแจนี้คือสิทธิ์ ใครถือลิงก์ก็เซ็นแทนได้ จึงคุมสามอย่าง:
 *    1. เดาไม่ได้   sha1 ของ uniqid ที่มี entropy เพิ่ม ได้ 40 ตัวอักษร
 *    2. หมดอายุ     7 วัน — ไม่มีวันหมดอายุแปลว่าปลอมลายเซ็นได้ตลอดกาล
 *    3. เพิกถอนได้  ออกดอกใหม่ทับดอกเก่า กดส่งเมลซ้ำแล้วลิงก์เก่าใช้ไม่ได้ทันที
 *  ทำได้อย่างเดียวคือเซ็นรับใบนั้น อ่านใบอื่น/แก้จำนวน/ยกเลิกไม่ได้
 *
 *  ออกให้เฉพาะใบจ่าย — ใบเบิกกับใบรับเข้าไม่มีการเซ็นรับ
 *  ตรวจ doc_type ที่นี่ ไม่ปล่อยให้ผู้เรียกจำเอง
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/setting.php';

/* อายุของกุญแจนับเป็นวัน ตั้งไว้ที่เดียว ไม่กระจายเลข 7 ไปทั่วไฟล์ */
define('SIGN_LINK_DAYS', 7);


/* ---------- สุ่มกุญแจหนึ่งดอก ----------
 * PHP 5.2.6 ไม่มี random_bytes และไม่มี openssl_random_pseudo_bytes ที่เชื่อได้
 * uniqid(mt_rand(), true) ให้เวลาระดับไมโครวินาที + ตัวสุ่ม + entropy เพิ่ม
 * ผสม doc_id เข้าไปด้วย เพื่อให้สองใบที่ขอกุญแจในไมโครวินาทีเดียวกันไม่ชนกัน
 * แล้ว sha1 ให้ได้ความยาวคงที่ 40 ตัว พอดีกับคอลัมน์ CHAR(40)
 */
function sign_token_make($doc_id)
{
    return sha1(uniqid(mt_rand(), true) . '|' . (int)$doc_id . '|' . microtime());
}


/* ---------- ออกกุญแจดอกใหม่ให้ใบจ่าย ----------
 * เขียนทับดอกเดิมเสมอ = เพิกถอนของเก่าไปในตัว
 * คืนกุญแจที่ออกให้ หรือ '' ถ้าใบนั้นไม่ใช่ใบจ่าย / ไม่มีอยู่
 *
 * ไม่เช็คว่าใบนั้นเซ็นไปหรือยัง เพราะการออกลิงก์กับการเซ็นเป็นคนละเรื่อง
 * ใบที่เซ็นแล้วยังต้องเปิดลิงก์ดูได้ว่าเซ็นไปเมื่อไหร่ (sign.php จัดการต่อเอง)
 */
function sign_link_issue($doc_id, $actor = null)
{
    $doc_id  = (int)$doc_id;
    if ($doc_id <= 0) { return ''; }

    $connect = db_connect();
    $token   = sign_token_make($doc_id);

    /* ---------- ไม่แตะ updated_by ----------
     * คอลัมน์นั้นมี foreign key ไป tb_user.username การใส่ค่าที่ไม่ใช่ชื่อผู้ใช้จริง
     * เช่น 'system' หรือ 'cron' จะทำให้ UPDATE ทั้งคำสั่งล้มเหลวด้วย error 1452
     *
     * เจอตอนทดสอบ: ตัวออกกุญแจถูกเรียกจากตอนส่งเมล ซึ่งบางครั้งไม่มีผู้กระทำ
     * (ระบบส่งเอง) กุญแจจึงออกไม่สำเร็จเงียบ ๆ แล้วเมลตกกลับไปใช้ลิงก์เดิม
     * ที่ต้องล็อกอิน — คือกลับไปเป็นปัญหาเดิมทั้งดุ้นโดยไม่มีอะไรฟ้อง
     *
     * การออกลิงก์ไม่ใช่การแก้ข้อมูลของเอกสารอยู่แล้ว ใครกดขอลิงก์จึงไม่ใช่
     * สิ่งที่ควรไปทับ "คนที่แก้เอกสารล่าสุด"
     */
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
            SET `sign_token`     = ?,
                `sign_token_exp` = DATE_ADD(NOW(), INTERVAL " . (int)SIGN_LINK_DAYS . " DAY)
          WHERE `doc_id` = ? AND `doc_type` = 'issue'");
    if (!$stmt) { return ''; }

    mysqli_stmt_bind_param($stmt, 'si', $token, $doc_id);
    $ok = mysqli_stmt_execute($stmt);
    $n  = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    /* affected_rows เป็น 0 ได้สองกรณี: ไม่พบใบจ่ายใบนั้น หรือพบแต่ค่าเดิมเท่ากัน
       กรณีหลังเกิดไม่ได้เพราะ token สุ่มใหม่ทุกครั้ง 0 จึงแปลว่าไม่พบจริง ๆ */
    return ($ok && $n > 0) ? $token : '';
}


/* ---------- อ่านกุญแจที่ยังใช้ได้ของใบจ่าย ----------
 * คืน '' ถ้ายังไม่เคยออก หรือออกแล้วแต่หมดอายุ
 */
function sign_link_current($doc_id)
{
    $doc_id  = (int)$doc_id;
    if ($doc_id <= 0) { return ''; }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT `sign_token`
           FROM `tb_doc_header`
          WHERE `doc_id` = ? AND `doc_type` = 'issue'
            AND `sign_token` IS NOT NULL
            AND `sign_token_exp` IS NOT NULL
            AND `sign_token_exp` > NOW()
          LIMIT 1");
    if (!$stmt) { return ''; }

    mysqli_stmt_bind_param($stmt, 'i', $doc_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $token);

    $out = mysqli_stmt_fetch($stmt) ? $token : '';
    mysqli_stmt_close($stmt);
    return $out;
}


/* ---------- ขอกุญแจที่ใช้ได้ ออกใหม่ให้ถ้ายังไม่มีหรือหมดอายุ ----------
 * จุดเรียกส่วนใหญ่ต้องการแค่ "ขอลิงก์ที่ใช้งานได้มาหนึ่งอัน"
 * ไม่ได้อยากรู้ว่าต้องออกใหม่หรือใช้ของเดิม จึงรวมไว้ให้ตรงนี้
 *
 * ใช้ของเดิมถ้ายังไม่หมดอายุ เพื่อไม่ให้กดคัดลอกลิงก์สองครั้งแล้วได้คนละอัน
 * ซึ่งจะทำให้ลิงก์ที่เพิ่งส่งให้ผู้เบิกไปเมื่อกี้ใช้ไม่ได้
 */
function sign_link_ensure($doc_id, $actor = null)
{
    $token = sign_link_current($doc_id);
    if ($token !== '') { return $token; }

    return sign_link_issue($doc_id, $actor);
}


/* ---------- ประกอบเป็น URL เต็ม ----------
 * คืน '' ถ้ายังไม่ได้ตั้ง site_url เพราะลิงก์ที่ไม่มีชื่อโฮสต์
 * ส่งไปในเมลแล้วกดไม่ได้ ปล่อยว่างดีกว่าส่งของเสียออกไป
 */
function sign_link_url($token)
{
    if ($token === '') { return ''; }

    $base = trim(setting_get('site_url', ''));
    if ($base === '') { return ''; }
    if (substr($base, -1) !== '/') { $base .= '/'; }

    return $base . 'sign.php?t=' . urlencode($token);
}


/* ---------- ลิงก์พร้อมใช้ของใบจ่ายใบหนึ่ง ---------- */
function sign_link_for($doc_id, $actor = null)
{
    return sign_link_url(sign_link_ensure($doc_id, $actor));
}


/* ---------- หาใบจ่ายจากกุญแจ ----------
 * คืน array('ok'=>bool, 'reason'=>..., 'doc_id'=>int)
 *
 * reason แยกเป็น 'notfound' กับ 'expired' เพื่อให้หน้าจอบอกผู้ใช้ได้ตรงเรื่อง
 * "ลิงก์หมดอายุ ขอใหม่ได้" ต่างจาก "ลิงก์นี้ใช้ไม่ได้" คนละทางแก้กันคนละทาง
 *
 * ตรวจความยาวก่อนยิง query กันคนลองยิงค่ามั่ว ๆ เข้ามารัว ๆ
 */
function sign_link_find($token)
{
    $token = trim((string)$token);

    if ($token === '' || strlen($token) !== 40 || !preg_match('/^[0-9a-f]{40}$/', $token)) {
        return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0);
    }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT `doc_id`, (`sign_token_exp` IS NOT NULL AND `sign_token_exp` > NOW()) AS `alive`
           FROM `tb_doc_header`
          WHERE `sign_token` = ? AND `doc_type` = 'issue'
          LIMIT 1");
    if (!$stmt) { return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0); }

    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $doc_id, $alive);

    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return array('ok' => false, 'reason' => 'notfound', 'doc_id' => 0); }
    if (!$alive) { return array('ok' => false, 'reason' => 'expired',  'doc_id' => (int)$doc_id); }

    return array('ok' => true, 'reason' => 'ok', 'doc_id' => (int)$doc_id);
}
