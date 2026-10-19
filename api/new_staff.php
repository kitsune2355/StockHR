<?php
/* ============================================================
 *  api/new_staff.php — endpoint ของหน้า "เบิกพนักงานใหม่"
 *
 *    { "action": "bootstrap", "department_id": 3 }
 *        สินค้าที่เลือกได้ + ชุดของพื้นฐาน + หน่วยงาน + เงื่อนไขท้ายฟอร์ม
 *        (ผู้ดูแลระบบเรียกซ้ำพร้อม department_id ตอนเปลี่ยนหน่วยงาน)
 *
 *    { "action": "save", "csrf_token": "...",
 *      "doc_date": "2026-10-05", "department_id": 3, "note": "",
 *      "employee_name": "...", "employee_code": "", "position_name": "...",
 *      "start_date": "2026-10-15",
 *      "items": [ {"product_id":1, "qty":1, "note":"", "must_return":1} ] }
 *
 *  หลังบันทึกแล้วใบนี้คือใบเบิกธรรมดา การอนุมัติ/ยกเลิก/พิมพ์
 *  ไปทำที่ api/documents.php เหมือนใบเบิกอื่น
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/ ทั้งหมด
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/new_staff.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';
require_once dirname(dirname(__FILE__)) . '/backend/setting.php';
require_once dirname(dirname(__FILE__)) . '/backend/welfare_uniform.php';

api_require_post();
$actor      = api_require_login();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';

if (!new_staff_ready()) {
    api_fail('ฐานข้อมูลยังไม่พร้อม — ต้องรัน sql/072_new_staff_request.sql ก่อนใช้งานเมนูนี้');
}


if ($action === 'bootstrap') {

    /* สิทธิ์เดียวกับการทำใบเบิก — ใครก็ตามที่เข้าเมนูได้ (ปิดเพิ่มได้จากหน้าสิทธิ์การใช้งาน) */
    api_require_role(array('super_admin', 'admin', 'approver', 'general'));

    $me       = user_find($actor);
    $my_dept  = ($me === null) ? 0 : (int)$me['dept_id'];
    $lock     = (!in_array($actor_role, array('super_admin', 'admin')) && $my_dept > 0);

    $dept = new_staff_resolve_dept(
        isset($input['department_id']) && (int)$input['department_id'] > 0
            ? (int)$input['department_id'] : $my_dept,
        $actor, $actor_role);

    $terms = trim(setting_get('new_staff_terms', ''));

    api_ok(array(
        'products'    => new_staff_products($dept),
        /* ชุดของตามแผนกที่เลือก — แผนกไม่ได้ตั้งชุดเอง = ชุดกลาง (ดู sql/080) */
        'kit'         => new_staff_kit_list($dept),
        'kitOwnDept'  => (new_staff_kit_owner($dept) > 0),
        /* �ش�����������͹����ʴԡ�âͧἹ���� � �ʴ����ҧ���� ����������� */
        'uniform'     => welfare_uniform_for_department($dept),
        'departments' => department_list(array('is_active' => 1)),
        'myDept'      => $my_dept,
        'myDeptName'  => ($me === null) ? '' : $me['department_name'],
        'lockDept'    => $lock,
        'deptId'      => $dept,
        'terms'       => ($terms === '') ? array() : explode("\n", $terms),
        'today'       => date('Y-m-d')
    ));
}


if ($action === 'save') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin', 'approver', 'general'));

    $data = array(
        'doc_date'      => isset($input['doc_date'])      ? $input['doc_date']           : '',
        'department_id' => isset($input['department_id']) ? (int)$input['department_id'] : 0,
        'note'          => isset($input['note'])          ? $input['note']               : '',
        'employee_name' => isset($input['employee_name']) ? $input['employee_name']      : '',
        'employee_code' => isset($input['employee_code']) ? $input['employee_code']      : '',
        'position_name' => isset($input['position_name']) ? $input['position_name']      : '',
        'start_date'    => isset($input['start_date'])    ? $input['start_date']         : '',
        'items'         => isset($input['items']) && is_array($input['items']) ? $input['items'] : array()
    );

    $res = new_staff_save($data, $actor, $actor_role);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array(
        'message' => $res['message'],
        'doc_id'  => $res['id'],
        'doc_no'  => isset($res['doc_no']) ? $res['doc_no'] : ''
    ));
}


/* ============================================================
 *  ตั้งค่าอุปกรณ์พนักงานใหม่ — เฉพาะผู้ดูแลระบบ (ตรงกับเมนูใน includes/menu.php)
 *
 *    { "action": "kit_get", "department_id": 0 }        0 = ชุดกลาง, >0 = ชุดของแผนกนั้น
 *    { "action": "kit_save", "csrf_token": "...", "department_id": 0, "terms": "บรรทัดละข้อ",
 *      "rows": [ {"product_id":81, "default_qty":1, "must_return":0} ] }
 *        แผนกส่ง rows ว่าง = ลบชุดของแผนก กลับไปใช้ชุดกลาง
 * ============================================================ */
if ($action === 'kit_get') {

    api_require_role(array('super_admin', 'admin'));

    $kit_dept = isset($input['department_id']) ? max(0, (int)$input['department_id']) : 0;

    api_ok(array(
        'departmentId' => $kit_dept,
        'kit'          => new_staff_kit_admin_list($kit_dept),
        /* ชุดกลางไว้ให้ปุ่ม "คัดลอกจากชุดกลาง" ของแผนกที่ยังไม่ได้ตั้งชุดเอง */
        'centralKit'   => ($kit_dept > 0) ? new_staff_kit_admin_list(0) : array(),
        'deptKits'     => new_staff_kit_department_counts(),
        'departments'  => department_list(array('is_active' => 1)),
        /* �Թ��Ҫش��������������͡ � �ش�������駷���к����ʴԡ�� */
        'products'     => welfare_uniform_strip_products(stock_list(array('only_active' => true))),
        'uniform'      => welfare_uniform_for_department($kit_dept),
        'terms'        => setting_get('new_staff_terms', '')
    ));
}

if ($action === 'kit_save') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin'));

    $res = new_staff_kit_save(
        isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : array(),
        isset($input['terms']) ? $input['terms'] : '',
        $actor,
        isset($input['department_id']) ? (int)$input['department_id'] : 0);

    if (!$res['ok']) {
        api_fail($res['message']);
    }
    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
