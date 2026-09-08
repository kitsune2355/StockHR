<?php
/* ============================================================
 *  backend/notify.php — แจ้งเตือนในระบบ (กระดิ่งบนแถบบน)
 *
 *  1 แถวในตาราง = 1 คนได้รับ 1 เรื่อง
 *  ใบเบิกที่มีผู้อนุมัติ 3 คน จึงเกิด 3 แถว ไม่ใช่แถวเดียวแล้วแตกทีหลัง
 *  เพราะ "อ่านแล้ว" เป็นสถานะของแต่ละคน ไม่ใช่ของเอกสาร
 *
 *  หลักสำคัญ: การแจ้งเตือน "ห้ามทำให้งานหลักพัง"
 *  ถ้าเขียนแจ้งเตือนไม่สำเร็จ ใบเบิกที่บันทึกไปแล้วต้องยังอยู่ครบ
 *  ทุกฟังก์ชันในไฟล์นี้จึงคืน true/false เฉย ๆ ไม่โยน error ออกไป
 *  และผู้เรียกไม่ต้องตรวจผลก็ได้
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/thai_date.php';


/* ---------- ประเภทแจ้งเตือน + หน้าตาที่ใช้แสดง ----------
 * เก็บไอคอนกับโทนสีไว้ที่เดียว หน้าเว็บกับ API จึงแสดงเหมือนกันเสมอ
 * ชื่อไอคอนเป็นของ Lucide (ใช้ทั้งระบบอยู่แล้ว)
 */
function notify_types()
{
    return array(
        'request_waiting'    => array('label' => 'ใบเบิกรออนุมัติ',   'icon' => 'clipboard-list', 'tone' => 'amber'),
        'request_approved'   => array('label' => 'อนุมัติใบเบิกแล้ว',  'icon' => 'circle-check',   'tone' => 'green'),
        'request_rejected'   => array('label' => 'ไม่อนุมัติใบเบิก',   'icon' => 'circle-x',       'tone' => 'red'),
        'request_cancelled'  => array('label' => 'ยกเลิกใบเบิก',      'icon' => 'ban',            'tone' => 'slate'),
        'issue_created'      => array('label' => 'จ่ายของแล้ว',       'icon' => 'package-minus',  'tone' => 'brand'),
        'issue_acknowledged' => array('label' => 'เซ็นรับของแล้ว',    'icon' => 'signature',      'tone' => 'green'),
        'issue_cancelled'    => array('label' => 'ยกเลิกใบจ่าย',      'icon' => 'ban',            'tone' => 'slate'),
        'adjust_waiting'     => array('label' => 'ใบปรับยอดรออนุมัติ', 'icon' => 'scale',          'tone' => 'amber'),
        'adjust_approved'    => array('label' => 'อนุมัติใบปรับยอดแล้ว', 'icon' => 'circle-check',  'tone' => 'green'),
        'adjust_rejected'    => array('label' => 'ไม่อนุมัติใบปรับยอด', 'icon' => 'circle-x',       'tone' => 'red'),
        'stock_low'          => array('label' => 'ของใกล้หมด',        'icon' => 'triangle-alert', 'tone' => 'red'),
        'system'             => array('label' => 'ระบบ',              'icon' => 'bell',           'tone' => 'slate')
    );
}

function notify_type_meta($type)
{
    $all = notify_types();
    return isset($all[$type]) ? $all[$type] : $all['system'];
}


/* ---------- ชื่อผู้กระทำที่เขียนลงคอลัมน์ซึ่งมี foreign key ได้ ----------
 * created_by / updated_by ของ tb_notification ผูก FK ไว้กับ tb_user.username
 * ค่าที่ไม่ใช่ชื่อผู้ใช้จริง โดยเฉพาะสตริงว่าง จะทำให้ทั้งคำสั่งล้ม
 * ด้วย error 1452 ไม่ใช่แค่คอลัมน์นั้นเป็นค่าว่าง
 *
 * เส้นทางที่ไม่มีผู้กระทำจริงมีอยู่จริงและถูกต้อง — ปุ่ม "ส่งเมลซ้ำ" ส่ง
 * actor = '' มาโดยตั้งใจ เพราะระบบเป็นคนส่ง ไม่ใช่คนใดคนหนึ่ง
 * จึงต้องแปลงเป็น NULL ที่ชั้นนี้ ไม่ใช่ไปแก้ให้ผู้เรียกส่งชื่อปลอมมาแทน
 *
 * ทำไมเพิ่งโผล่: เดิม @mysqli_stmt_execute() กลบ warning ไว้ แถวไม่ถูกเขียน
 * แบบเงียบ ๆ แต่ mysqli บน PHP รุ่นใหม่โยน exception ซึ่ง @ กดไม่อยู่
 * บั๊กที่ซ่อนมานานจึงกลายเป็นหน้าจอ fatal error ทั้งที่งานหลักสำเร็จไปแล้ว
 */
function notify_actor($actor)
{
    $actor = trim((string)$actor);
    return ($actor === '') ? null : $actor;
}


/* ---------- สร้างแจ้งเตือนให้คนเดียว ----------
 * คืน true/false — ผู้เรียกจะตรวจหรือไม่ตรวจก็ได้
 *
 * ไม่แจ้งเตือนตัวเอง: คนที่เพิ่งกดปุ่มรู้อยู่แล้วว่าตัวเองทำอะไรลงไป
 * กระดิ่งที่เด้งบอกสิ่งที่ตัวเองเพิ่งทำคือเสียงรบกวนล้วน ๆ
 */
function notify_push($username, $type, $title, $message,
                     $link_page = '', $link_doc_id = null, $actor = null)
{
    $username = trim((string)$username);
    if ($username === '') { return false; }

    if ($actor !== null && $actor === $username) {
        return false;
    }

    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_notification`
            (`username`, `noti_type`, `title`, `message`,
             `link_page`, `link_doc_id`, `is_read`, `created_at`, `created_by`)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), ?)");
    if (!$stmt) { return false; }

    /* ตัดข้อความให้พอดีความยาวคอลัมน์ก่อนเขียน
       ปล่อยให้ MySQL ตัดเองจะได้ warning และข้อความท้ายหายแบบเงียบ ๆ */
    $title   = notify_clip($title, 150);
    $message = notify_clip($message, 500);

    $doc_id = ($link_doc_id === null || (int)$link_doc_id <= 0) ? null : (int)$link_doc_id;
    $by     = notify_actor($actor);

    mysqli_stmt_bind_param($stmt, 'sssssis',
        $username, $type, $title, $message, $link_page, $doc_id, $by);

    $ok = @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok ? true : false;
}


/* ---------- สร้างแจ้งเตือนให้หลายคนพร้อมกัน ----------
 * คืนจำนวนคนที่แจ้งได้จริง (ตัดคนซ้ำและตัวผู้ทำรายการออกแล้ว)
 */
function notify_push_many($usernames, $type, $title, $message,
                          $link_page = '', $link_doc_id = null, $actor = null)
{
    if (!is_array($usernames)) { return 0; }

    $done = array();
    $n    = 0;

    for ($i = 0; $i < count($usernames); $i++) {
        $u = trim((string)$usernames[$i]);
        if ($u === '' || isset($done[$u])) { continue; }
        $done[$u] = true;

        if (notify_push($u, $type, $title, $message, $link_page, $link_doc_id, $actor)) {
            $n++;
        }
    }
    return $n;
}


/* ---------- ตัดข้อความตามจำนวนอักขระ (รู้จักภาษาไทย) ---------- */
function notify_clip($text, $max)
{
    $text = trim((string)$text);
    if (db_text_length($text) <= $max) {
        return $text;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
    /* ไม่มี mbstring — ตัดแบบไม่ให้อักขระ UTF-8 ขาดกลางตัว */
    $cut = substr($text, 0, $max - 1);
    return preg_replace('/[\x80-\xBF]+$/', '', $cut) . '…';
}


/* ---------- จำนวนที่ยังไม่ได้อ่าน — ใช้ทำตัวเลขบนกระดิ่ง ----------
 * เรียกทุกครั้งที่โหลดหน้า จึงต้องเป็น COUNT ที่วิ่งบน index ล้วน
 * (idx_noti_inbox = username, is_read, noti_id)
 */
function notify_unread_count($username)
{
    if (trim((string)$username) === '') { return 0; }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_notification`
         WHERE `username` = ? AND `is_read` = 0");
    if (!$stmt) { return 0; }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ---------- จำนวนแจ้งเตือนทั้งหมดของคนหนึ่งคน ----------
 * ใช้บนหน้ารายการทั้งหมด เพื่อบอกว่ามีของให้เลื่อนดูอีกเท่าไร
 * แยกจาก notify_unread_count เพราะคนละคำถาม: "ค้างกี่เรื่อง" กับ "มีทั้งหมดกี่เรื่อง"
 */
function notify_total_count($username)
{
    if (trim((string)$username) === '') { return 0; }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_notification` WHERE `username` = ?");
    if (!$stmt) { return 0; }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ---------- รายการแจ้งเตือนของคนหนึ่งคน ----------
 * $options = array('only_unread' => bool, 'limit' => int, 'before_id' => int)
 *
 * เรียงด้วย noti_id ไม่ใช่ created_at เพราะสองแถวที่เกิดในวินาทีเดียวกัน
 * จะมี created_at เท่ากัน แล้วลำดับจะสลับไปมาทุกครั้งที่โหลด
 */
function notify_list($username, $options = array())
{
    if (trim((string)$username) === '') { return array(); }

    $only_unread = !empty($options['only_unread']);
    $limit       = isset($options['limit']) ? (int)$options['limit'] : 30;
    if ($limit < 1)   { $limit = 1; }
    if ($limit > 200) { $limit = 200; }

    /* ---------- ตัวชี้ตำแหน่งสำหรับโหลดหน้าถัดไป ----------
     * ใช้ "เอาที่เก่ากว่า id นี้" ไม่ใช่ OFFSET
     *
     * เหตุผล: แจ้งเตือนใหม่เข้ามาระหว่างที่ผู้ใช้กำลังเลื่อนดูได้ตลอด
     * OFFSET 10 จะเลื่อนตามไปด้วยทุกครั้งที่มีของใหม่แทรกด้านบน
     * ผลคือรายการที่ 10 ถูกดันลงไปเป็นที่ 11 แล้วโดนข้ามไปทั้งรายการ
     * ส่วน id นั้นคงที่ ของที่โหลดมาแล้วจึงไม่ซ้ำและไม่หายไปไหน
     */
    $before_id = isset($options['before_id']) ? (int)$options['before_id'] : 0;

    $connect = db_connect();

    $sql = "SELECT `noti_id`, `noti_type`, `title`, `message`,
                   `link_page`, `link_doc_id`, `is_read`, `created_at`, `created_by`
            FROM `tb_notification`
            WHERE `username` = ?";
    if ($only_unread) {
        $sql .= " AND `is_read` = 0";
    }
    if ($before_id > 0) {
        $sql .= " AND `noti_id` < " . $before_id;
    }
    $sql .= " ORDER BY `noti_id` DESC LIMIT " . $limit;

    $stmt = @mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $id, $type, $title, $message, $link_page, $link_doc_id,
        $is_read, $created_at, $created_by);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $meta = notify_type_meta($type);
        $rows[] = array(
            'noti_id'     => (int)$id,
            'noti_type'   => $type,
            'type_label'  => $meta['label'],
            'icon'        => $meta['icon'],
            'tone'        => $meta['tone'],
            'title'       => $title,
            'message'     => $message,
            'link_page'   => $link_page,
            'link_doc_id' => ($link_doc_id === null) ? 0 : (int)$link_doc_id,
            'is_read'     => (int)$is_read,
            'created_at'  => $created_at,
            'ago'         => notify_time_ago($created_at),
            'created_by'  => ($created_by === null) ? '' : $created_by
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ---------- "เมื่อสักครู่ / 3 ชั่วโมงที่แล้ว" ----------
 * คนอ่านแจ้งเตือนอยากรู้ว่า "นานแค่ไหนแล้ว" ไม่ใช่ "เวลาอะไร"
 * เกิน 7 วันค่อยกลับไปแสดงวันที่จริง เพราะ "43 วันที่แล้ว" ไม่ช่วยอะไร
 */
function notify_time_ago($datetime)
{
    $ts = @strtotime($datetime);
    if (!$ts) { return ''; }

    $diff = time() - $ts;
    if ($diff < 0)     { $diff = 0; }           // นาฬิกาเครื่องกับฐานข้อมูลเพี้ยนกันเล็กน้อย
    if ($diff < 60)    { return 'เมื่อสักครู่'; }
    if ($diff < 3600)  { return floor($diff / 60) . ' นาทีที่แล้ว'; }
    if ($diff < 86400) { return floor($diff / 3600) . ' ชั่วโมงที่แล้ว'; }
    if ($diff < 604800){ return floor($diff / 86400) . ' วันที่แล้ว'; }

    /* เกิน 7 วันแล้วก็กลับไปใช้รูปแบบวันที่กลางของระบบ ไม่ประกอบเอง */
    return thai_date(date('Y-m-d', $ts));
}


/* ---------- ทำเครื่องหมายว่าอ่านแล้ว ----------
 * บังคับ username ใน WHERE ด้วย ไม่ใช่เช็คแค่ noti_id
 * ไม่งั้นเปลี่ยนเลข id ใน request แล้วไปปิดแจ้งเตือนของคนอื่นได้
 */
function notify_mark_read($noti_id, $username)
{
    $noti_id = (int)$noti_id;
    if ($noti_id <= 0 || trim((string)$username) === '') {
        return db_fail('ไม่พบแจ้งเตือนที่ต้องการ');
    }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_notification`
         SET `is_read` = 1, `read_at` = NOW(), `updated_by` = ?
         WHERE `noti_id` = ? AND `username` = ? AND `is_read` = 0");
    if (!$stmt) { return db_fail('อัปเดตแจ้งเตือนไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sis', $username, $noti_id, $username);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) { return db_fail('อัปเดตแจ้งเตือนไม่สำเร็จ'); }

    /* ไม่เช็ค affected_rows เพราะกดซ้ำที่รายการที่อ่านไปแล้วไม่ใช่ความผิดพลาด
       แค่ไม่มีอะไรต้องเปลี่ยน */
    return db_ok('อ่านแล้ว', $noti_id);
}


function notify_mark_all_read($username)
{
    if (trim((string)$username) === '') {
        return db_fail('ไม่พบผู้ใช้');
    }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_notification`
         SET `is_read` = 1, `read_at` = NOW(), `updated_by` = ?
         WHERE `username` = ? AND `is_read` = 0");
    if (!$stmt) { return db_fail('อัปเดตแจ้งเตือนไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'ss', $username, $username);
    $ok = mysqli_stmt_execute($stmt);
    $n  = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) { return db_fail('อัปเดตแจ้งเตือนไม่สำเร็จ'); }
    return db_ok('อ่านทั้งหมดแล้ว (' . (int)$n . ' รายการ)', $n);
}


/* ---------- ลบแจ้งเตือนเก่าที่อ่านไปแล้ว ----------
 * ตัวส่งเมล (cron/mail_worker.php) เรียกให้ตอนทำงานรอบดึก
 * ตารางนี้โตเร็วกว่าตารางอื่นเพราะเอกสารหนึ่งใบสร้างหลายแถว
 * เก็บของที่อ่านแล้วไว้เกิน 90 วันไม่มีใครย้อนกลับไปดู
 */
function notify_purge_old($days = 90)
{
    $days = (int)$days;
    if ($days < 7) { $days = 7; }               // กันเผลอสั่งลบของเมื่อวาน

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "DELETE FROM `tb_notification`
         WHERE `is_read` = 1 AND `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)");
    if (!$stmt) { return 0; }

    mysqli_stmt_bind_param($stmt, 'i', $days);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return (int)$n;
}
