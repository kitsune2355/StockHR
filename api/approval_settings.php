<?php
/* ============================================================
 *  api/approval_settings.php — endpoint หน้า "ตั้งค่าอนุมัติ"
 *
 *    { "action": "bootstrap" }
 *        รายชื่อผู้ใช้ที่เปิดใช้งานทั้งหมด + รายชื่อที่เป็นผู้อนุมัติใบปรับยอดอยู่ตอนนี้
 *
 *    { "action": "save", "csrf_token": "...", "usernames": ["a","b"] }
 *        แทนที่รายชื่อผู้อนุมัติทั้งชุด
 *
 *  เฉพาะ super_admin / admin
 *  ไม่มี SQL ในไฟล์นี้ — เรียกผ่าน backend/adjust_approver.php กับ backend/user.php
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/adjust_approver.php';
require_once dirname(dirname(__FILE__)) . '/backend/user.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'bootstrap') {

    $rows  = user_list(array('is_active' => 1));
    $users = array();
    for ($i = 0; $i < count($rows); $i++) {
        $r = $rows[$i];
        $users[] = array(
            'username'    => $r['username'],
            'full_name'   => $r['full_name'],
            'role'        => $r['role'],
            'role_label'  => user_role_label($r['role']),
            'position'    => isset($r['position']) ? $r['position'] : '',
            'department'  => isset($r['department_name']) ? $r['department_name'] : ''
        );
    }

    api_ok(array(
        'users'   => $users,
        'current' => adjust_approver_usernames()
    ));
}


if ($action === 'save') {

    api_require_csrf($input);

    $usernames = isset($input['usernames']) && is_array($input['usernames'])
                 ? $input['usernames'] : array();

    $res = adjust_approver_save($usernames, $actor);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
