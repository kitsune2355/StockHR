<?php
/* ============================================================
 *  api/permissions.php — endpoint ของหน้าสิทธิ์การใช้งาน
 *
 *  ทุกคำสั่งส่งมาแบบ POST พร้อม body เป็น JSON
 *
 *  1) บันทึกสิทธิ์ทั้งชุด
 *     {
 *       "action": "save",
 *       "csrf_token": "...",
 *       "rows": [ {menu_key, role, can_access}, ... ]
 *     }
 *
 *  2) คืนค่าเป็นค่าตั้งต้นที่เขียนไว้ในโค้ด
 *     { "action": "reset", "csrf_token": "..." }
 *
 *  ใครเข้าหน้านี้ได้ ตัดสินจากสิทธิ์เมนูเหมือนหน้าอื่น (app_can_access)
 *  ไม่ได้ล็อกไว้ที่ super_admin ตายตัว เพราะ super_admin มอบหมายงาน
 *  จัดสิทธิ์ของผู้อนุมัติกับผู้ใช้ทั่วไปให้ admin ทำแทนได้
 *
 *  สิ่งที่ห้ามคือ "แก้สิทธิ์ของ role ตัวเอง" ไม่ใช่ "เข้าหน้านี้"
 *  admin จึงเปิดสิทธิ์เมนูให้ตัวเองไม่ได้ ต้องรอ super_admin เปิดให้
 *  บังคับที่ menu_permission_editable_roles() ซึ่งกันทั้งทางหน้าจอและทาง API
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/menu_permission.php';
require_once dirname(dirname(__FILE__)) . '/includes/menu.php';

api_require_post();
$actor = api_require_login();

/* สิทธิ์ของคนที่กดมา ใช้ตัดสินว่าแก้คอลัมน์ไหนได้บ้าง
   _bootstrap.php ไม่ได้อ่านให้ ต้องอ่านเองเหมือน api ตัวอื่นในโปรเจกต์ */
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

/* เข้าถึงหน้านี้ได้ไหม ใช้กติกาเดียวกับแถบเมนูและ router
   ปิดเมนูนี้ของ admin ไว้ แล้ว admin ยิง API ตรง ๆ ก็ต้องโดนปฏิเสธเหมือนกัน */
app_apply_permissions();
$routes = app_routes();
if (!isset($routes['permissions']) || !app_can_access($routes['permissions'], $actor_role)) {
    api_fail('ไม่มีสิทธิ์เข้าถึงหน้าสิทธิ์การใช้งาน');
}

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'save') {

    api_require_csrf($input);

    $rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : array();

    /* ยอมรับเฉพาะ key ที่มีอยู่จริงในเมนู ไม่งั้นตารางจะมีขยะค้าง
       จากเมนูที่ถูกลบไปแล้ว หรือจากคนที่ยิง API ตรง ๆ */
    $allowed = array_keys(app_routes());

    $res = menu_permission_save($rows, $actor, $allowed, $actor_role);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message'], 'saved' => $res['id']));
}


if ($action === 'reset') {

    api_require_csrf($input);

    /* คืนค่าตั้งต้นคือการลบทุกแถวทิ้ง แล้วกลับไปใช้ค่าที่เขียนใน includes/menu.php
       ซึ่งค่าตั้งต้นเหล่านั้นเปิดเมนูส่วนใหญ่ให้ admin อยู่แล้ว
       ถ้า admin กดได้ ก็เท่ากับเปิดสิทธิ์ที่ super_admin เพิ่งปิดไปคืนให้ตัวเองทั้งชุด
       เป็นทางลัดยกระดับตัวเองที่อ้อมกว่าการติ๊กทีละช่อง แต่ได้ผลแรงกว่า */
    if ($actor_role !== 'super_admin') {
        api_fail('คืนค่าตั้งต้นได้เฉพาะผู้ดูแลระบบสูงสุด เพราะมีผลกับสิทธิ์ของทุก role รวมถึงของคุณเอง');
    }

    $res = menu_permission_reset();
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
