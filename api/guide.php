<?php
/* ============================================================
 *  api/guide.php — endpoint ของคู่มือการใช้งาน
 *
 *    { "action": "get",  "page_key": "request_new" }
 *        คู่มือของหน้านั้นพร้อมขั้นตอนทั้งหมด + เคยดูหรือยัง
 *
 *    { "action": "seen", "page_key": "request_new", "csrf_token": "..." }
 *        บันทึกว่าดูจบแล้ว จะได้ไม่เด้งอีกครั้งหน้า
 *
 *    { "action": "reset", "csrf_token": "..." }
 *        ล้างประวัติของตัวเอง ให้คู่มือเด้งใหม่ทุกหน้า
 *
 *  ---- เฉพาะผู้ดูแลระบบ ----
 *    { "action": "admin_list" }                     คู่มือทุกหน้า + ขั้นตอนทั้งหมด
 *    { "action": "admin_save",   "rows": [...] }    บันทึกคำอธิบายที่แก้
 *    { "action": "admin_toggle", "guide_id": 3, "field": "is_active" }
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/guide.php ทั้งหมด
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/guide.php';

api_require_post();
$actor      = api_require_login();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* ============================================================
 *  คู่มือของหน้าหนึ่ง
 * ============================================================ */
if ($action === 'get') {

    $page_key = isset($input['page_key']) ? $input['page_key'] : '';
    $guide    = guide_for_page($page_key, $actor);

    /* หน้าที่ยังไม่มีคู่มือ ไม่ใช่ข้อผิดพลาด — ตอบ ok พร้อม guide = null
       ฝั่งหน้าเว็บจะได้ซ่อนปุ่มคู่มือไปเงียบ ๆ ไม่ต้องขึ้นกล่อง error ให้ผู้ใช้ตกใจ */
    api_ok(array('guide' => $guide));
}


/* ============================================================
 *  บันทึกว่าดูจบแล้ว
 * ============================================================ */
if ($action === 'seen') {

    api_require_csrf($input);

    $res = guide_mark_seen($actor, isset($input['page_key']) ? $input['page_key'] : '', $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  ล้างประวัติของตัวเอง
 * ============================================================ */
if ($action === 'reset') {

    api_require_csrf($input);

    $res = guide_reset_seen($actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  ส่วนของผู้ดูแลระบบ
 * ============================================================ */
if ($action === 'admin_list') {

    api_require_role(array('super_admin', 'admin'));

    api_ok(array(
        'guides' => guide_list(),
        'steps'  => guide_all_steps()
    ));
}


if ($action === 'admin_save') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin'));

    $rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : array();

    $res = guide_steps_save($rows, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'saved' => $res['id']));
}


if ($action === 'admin_toggle') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin'));

    $res = guide_toggle(
        isset($input['guide_id']) ? (int)$input['guide_id'] : 0,
        isset($input['field'])    ? $input['field']         : '',
        $actor
    );
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
