<?php
/* ============================================================
 *  api/users.php — endpoint ของหน้าจัดการผู้ใช้งาน
 *
 *  ทุกคำสั่งส่งมาแบบ POST พร้อม body เป็น JSON
 *
 *  1) ขอข้อมูลทั้งหมด
 *     { "action": "list" }
 *
 *  2) บันทึกทั้งชุด (เพิ่มหลายคน แก้หลายคน ในครั้งเดียว)
 *     {
 *       "action": "save",
 *       "csrf_token": "...",
 *       "creates": [ {username, full_name, email, role, agency_id, dept_ids, is_active}, ... ],
 *       "updates": [ {username, full_name, email, role, agency_id, dept_ids, is_active}, ... ]
 *     }
 *     -> { ok, saved, failed, errors: [ {label, message} ] }
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/user.php ทั้งหมด
 *  กฎความปลอดภัย (ห้ามปิดบัญชีตัวเอง ห้ามยกระดับเป็น super_admin ฯลฯ)
 *  อยู่ในชั้น backend จึงบังคับใช้เหมือนกันไม่ว่าจะเรียกจากทางไหน
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/user.php';
require_once dirname(dirname(__FILE__)) . '/backend/company.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';

api_require_post();
$actor = api_require_login();
$actor_role = api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* ============================================================
 *  ขอข้อมูลทั้งหมด
 * ============================================================ */
if ($action === 'list') {

    /* ส่งรายชื่อหน่วยงานไปด้วย เพื่อให้ตารางมีช่องเลือกหน่วยงานต้นสังกัด
       ซึ่งเป็นตัวกำหนดว่าผู้อนุมัติคนนี้ดูแลใบเบิกของหน่วยงานไหน */
    $departments = department_list(array('is_active' => 1));
    $dept_options = array();
    for ($i = 0; $i < count($departments); $i++) {
        $d = $departments[$i];
        $dept_options[(int)$d['department_id']] = $d['department_name']
            . (isset($d['company_name']) && $d['company_name'] !== ''
               ? ' — ' . $d['company_name'] : '');
    }

    api_ok(array(
        'users'          => user_list(),
        'companyOptions' => company_options(),
        'deptOptions'    => $dept_options,
        'roles'          => user_roles()
    ));
}


/* ============================================================
 *  คืนรหัสผ่านของผู้ใช้คนหนึ่งกลับเป็นค่าตั้งต้น
 *
 *  แยกเป็นคำสั่งของตัวเอง ไม่รวมกับ save เพราะเป็นการกระทำที่ต้องตั้งใจกดทีละคน
 *  ตารางจึงไม่มีช่องรหัสผ่านให้พิมพ์ (ซึ่งเคยถูกลากคัดลอกข้ามบัญชีมาแล้ว)
 * ============================================================ */
if ($action === 'reset_password') {

    api_require_csrf($input);

    $username = isset($input['username']) ? trim($input['username']) : '';
    if ($username === '') {
        api_fail('ไม่ได้ระบุบัญชีที่ต้องการคืนรหัสผ่าน');
    }

    $res = user_reset_password($username, $actor, $actor_role);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  บันทึกทั้งชุด
 * ============================================================ */
if ($action === 'save') {

    api_require_csrf($input);

    $creates = isset($input['creates']) && is_array($input['creates']) ? $input['creates'] : array();
    $updates = isset($input['updates']) && is_array($input['updates']) ? $input['updates'] : array();

    $saved  = 0;
    $errors = array();

    /* ---------- แก้ของเดิมก่อน ---------- */
    foreach ($updates as $row) {

        $username = isset($row['username']) ? $row['username'] : '';

        $res = user_update($username, array(
            'full_name'    => isset($row['full_name'])    ? $row['full_name']    : '',
            'email'        => isset($row['email'])        ? $row['email']        : '',
            'role'         => isset($row['role'])         ? $row['role']         : '',
            'agency_id'    => isset($row['agency_id'])    ? (int)$row['agency_id'] : 0,
            'dept_id'      => isset($row['dept_id'])      ? (int)$row['dept_id']   : 0,
            'dept_ids'     => isset($row['dept_ids']) && is_array($row['dept_ids']) ? $row['dept_ids'] : array(),
            'is_active'    => isset($row['is_active'])    ? (int)$row['is_active'] : 0,
            'new_password' => isset($row['new_password']) ? $row['new_password'] : ''
        ), $actor, $actor_role);

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('label' => $username, 'message' => $res['message']);
        }
    }

    /* ---------- เพิ่มคนใหม่ ---------- */
    foreach ($creates as $row) {

        $username = isset($row['username']) ? $row['username'] : '';

        $res = user_create(array(
            'username'     => $username,
            'full_name'    => isset($row['full_name'])    ? $row['full_name']    : '',
            'email'        => isset($row['email'])        ? $row['email']        : '',
            'role'         => isset($row['role'])         ? $row['role']         : '',
            'agency_id'    => isset($row['agency_id'])    ? (int)$row['agency_id'] : 0,
            'dept_id'      => isset($row['dept_id'])      ? (int)$row['dept_id']   : 0,
            'dept_ids'     => isset($row['dept_ids']) && is_array($row['dept_ids']) ? $row['dept_ids'] : array(),
            'is_active'    => isset($row['is_active'])    ? (int)$row['is_active'] : 1,
            'new_password' => isset($row['new_password']) ? $row['new_password'] : ''
        ), $actor, $actor_role);

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('label' => ($username !== '' ? $username : '(แถวใหม่)'), 'message' => $res['message']);
        }
    }

    api_ok(array(
        'saved'  => $saved,
        'failed' => count($errors),
        'errors' => $errors
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
