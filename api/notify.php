<?php
/* ============================================================
 *  api/notify.php — endpoint ของแจ้งเตือนในระบบ (กระดิ่งบนแถบบน)
 *
 *    { "action": "list",  "only_unread": false, "limit": 30 }
 *    { "action": "count" }                          เฉพาะตัวเลขบนกระดิ่ง
 *    { "action": "read",  "noti_id": 12, "csrf_token": "..." }
 *    { "action": "read_all",              "csrf_token": "..." }
 *
 *  ทุกคำสั่งทำงานกับแจ้งเตือน "ของคนที่ล็อกอินอยู่" เท่านั้น
 *  ชื่อผู้ใช้มาจาก session ไม่เคยรับจากฝั่งหน้าเว็บ
 *  จึงไม่มีทางส่ง username ของคนอื่นมาแล้วอ่านหรือปิดแจ้งเตือนของเขาได้
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/notify.php';

api_require_post();
$actor = api_require_login();

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* ============================================================
 *  รายการแจ้งเตือน
 * ============================================================ */
if ($action === 'list') {

    $limit = isset($input['limit']) ? (int)$input['limit'] : 30;
    if ($limit < 1)  { $limit = 1; }
    if ($limit > 50) { $limit = 50; }

    /* ขอเกินมาหนึ่งแถวเพื่อรู้ว่ายังมีของต่ออีกไหม แล้วตัดทิ้งก่อนส่ง
       ถูกกว่าการยิง COUNT(*) อีกครั้งซึ่งต้องไล่ทั้งตารางเพื่อตอบคำถามเดียว */
    $rows = notify_list($actor, array(
        'only_unread' => !empty($input['only_unread']),
        'before_id'   => isset($input['before_id']) ? (int)$input['before_id'] : 0,
        'limit'       => $limit + 1
    ));

    $has_more = (count($rows) > $limit);
    if ($has_more) { array_pop($rows); }

    /* ตัวชี้สำหรับขอหน้าถัดไป หน้าจอส่งค่านี้กลับมาโดยไม่ต้องรู้ว่ามันคืออะไร */
    $next = count($rows) > 0 ? (int)$rows[count($rows) - 1]['noti_id'] : 0;

    api_ok(array(
        'items'    => $rows,
        'has_more' => $has_more,
        'next_id'  => $next,
        'unread'   => notify_unread_count($actor),
        'total'    => notify_total_count($actor)
    ));
}


/* ============================================================
 *  ตัวเลขบนกระดิ่งอย่างเดียว
 *
 *  แยกจาก list เพราะหน้าเว็บถามตัวเลขนี้เป็นระยะ ๆ เพื่ออัปเดตกระดิ่ง
 *  ถ้าใช้ list จะลากข้อความทั้ง 30 รายการมาด้วยทุกครั้งโดยไม่ได้ใช้
 * ============================================================ */
if ($action === 'count') {

    api_ok(array('unread' => notify_unread_count($actor)));
}


/* ============================================================
 *  ทำเครื่องหมายว่าอ่านแล้ว
 * ============================================================ */
if ($action === 'read') {

    api_require_csrf($input);

    $res = notify_mark_read(isset($input['noti_id']) ? (int)$input['noti_id'] : 0, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'unread' => notify_unread_count($actor)));
}


if ($action === 'read_all') {

    api_require_csrf($input);

    $res = notify_mark_all_read($actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'unread' => 0));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
