<?php
/* ============================================================
 *  api/account.php — endpoint ของบัญชีตัวเอง
 *
 *  {
 *    "action": "change_password",
 *    "csrf_token": "...",
 *    "current_password": "...",
 *    "new_password": "...",
 *    "confirm_password": "..."
 *  }
 *
 *  เปิดให้ทุกสิทธิ์ที่ล็อกอินอยู่ เพราะทุกคนต้องเปลี่ยนรหัสผ่านของตัวเองได้
 *  แต่เปลี่ยนได้เฉพาะบัญชีของคนที่กำลังล็อกอินเท่านั้น —
 *  ชื่อผู้ใช้เอามาจาก session ไม่ได้รับจากฟอร์ม ต่อให้ยิงมาก็ไม่ถูกใช้
 *  ไม่งั้นใครก็ยิงคำสั่งเปลี่ยนรหัสผ่านให้บัญชีคนอื่นได้
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/user.php';

api_require_post();
$actor = api_require_login();

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'change_password') {

    api_require_csrf($input);

    $current = isset($input['current_password']) ? $input['current_password'] : '';
    $new     = isset($input['new_password'])     ? $input['new_password']     : '';
    $confirm = isset($input['confirm_password']) ? $input['confirm_password'] : '';

    $res = user_change_own_password($actor, $current, $new, $confirm);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
