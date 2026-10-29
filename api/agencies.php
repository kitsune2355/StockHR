<?php
/* ============================================================
 *  api/agencies.php — endpoint ของหน้าตั้งค่าหน่วยงาน
 *
 *  ทุกคำสั่งส่งมาแบบ POST พร้อม body เป็น JSON
 *
 *  1) ขอข้อมูลทั้งหมดมาลงตาราง
 *     { "action": "list" }
 *     -> { ok, companies: [...], departments: [...] }
 *
 *  2) บันทึกการแก้ไขทั้งชุด (แก้หลายช่อง เพิ่มหลายแถว ลบหลายแถว ในครั้งเดียว)
 *     {
 *       "action": "save",
 *       "csrf_token": "...",
 *       "tab": "company" | "department",
 *       "creates": [ {...}, {...} ],          แถวใหม่
 *       "updates": [ {id, ...}, {...} ],      แถวที่แก้
 *       "deletes": [ id, id, ... ]            แถวที่สั่งลบ
 *     }
 *     -> { ok, saved, failed, errors: [ {row, message} ] }
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/ ทั้งหมด
 *  จึงได้กติกาตรวจข้อมูลชุดเดียวกับหน้าเว็บโดยอัตโนมัติ
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/company.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';

api_require_post();
$actor = api_require_login();

/* ใครใช้ได้ = ใครเข้าเมนู "หน่วยงาน" ได้ (ตั้งที่หน้า "สิทธิ์การใช้งาน") — กติกาเดียวกับแถบเมนูและ router
   เดิมล็อกไว้ที่ super_admin ตายตัว เปิดเมนูให้ admin แล้วเข้าหน้าได้ แต่กดบันทึกโดนปฏิเสธ */
require_once dirname(dirname(__FILE__)) . '/includes/menu.php';
app_apply_permissions();
$routes = app_routes();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';
if (!isset($routes['agencies']) || !app_can_access($routes['agencies'], $actor_role)) {
    api_fail('คุณไม่มีสิทธิ์ใช้งานส่วนนี้', 403);
}

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* ============================================================
 *  ขอข้อมูลทั้งหมด
 *
 *  ส่งทั้งก้อนเพราะตารางฝั่งหน้าเว็บทำการค้นหา เรียงลำดับ และแบ่งหน้าเอง
 *  จึงค้นได้ทันทีที่พิมพ์โดยไม่ต้องวิ่งกลับมาถามเซิร์ฟเวอร์
 *  ข้อมูลชุดนี้อยู่ระดับหลักสิบถึงหลักพันแถว ยังส่งทีเดียวได้สบาย
 *  ถ้าวันหนึ่งแตะหลักหมื่น ค่อยเปลี่ยนไปโหลดทีละหน้าจากเซิร์ฟเวอร์
 * ============================================================ */
if ($action === 'list') {

    $companies = company_list();
    $departments = department_list();

    api_ok(array(
        'companies'   => $companies,
        'departments' => $departments
    ));
}


/* ============================================================
 *  บันทึกทั้งชุด
 * ============================================================ */
if ($action === 'save') {

    api_require_csrf($input);

    $tab     = isset($input['tab']) ? $input['tab'] : '';
    $creates = isset($input['creates']) && is_array($input['creates']) ? $input['creates'] : array();
    $updates = isset($input['updates']) && is_array($input['updates']) ? $input['updates'] : array();
    $deletes = isset($input['deletes']) && is_array($input['deletes']) ? $input['deletes'] : array();

    if ($tab !== 'company' && $tab !== 'department') {
        api_fail('ไม่รู้จักตารางที่ส่งมา');
    }

    $saved  = 0;
    $errors = array();

    /* ---------- ลบก่อน ----------
     * ทำก่อนเพราะถ้าผู้ใช้ลบแผนกแล้วลบบริษัทในครั้งเดียวกัน
     * ต้องให้แผนกหายไปก่อน บริษัทถึงจะลบผ่าน FK ได้
     */
    foreach ($deletes as $id) {
        $id = (int)$id;
        if ($id <= 0) { continue; }

        $res = ($tab === 'company') ? company_delete($id) : department_delete($id);

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => $id, 'action' => 'delete', 'message' => $res['message']);
        }
    }

    /* ---------- แก้ของเดิม ---------- */
    foreach ($updates as $row) {

        if ($tab === 'company') {
            $id  = isset($row['company_id']) ? (int)$row['company_id'] : 0;
            $res = company_update($id, array(
                'company_name' => isset($row['company_name']) ? $row['company_name'] : '',
                'form_code'    => isset($row['form_code'])    ? $row['form_code']    : '',
                'is_active'    => isset($row['is_active'])    ? (int)$row['is_active'] : 0
            ), $actor);
            $label = isset($row['company_name']) ? $row['company_name'] : ('#' . $id);
        } else {
            $id  = isset($row['department_id']) ? (int)$row['department_id'] : 0;
            $res = department_update($id, array(
                'department_name' => isset($row['department_name']) ? $row['department_name'] : '',
                'company_id'      => isset($row['company_id']) ? (int)$row['company_id'] : 0,
                'is_active'       => isset($row['is_active']) ? (int)$row['is_active'] : 0
            ), $actor);
            $label = isset($row['department_name']) ? $row['department_name'] : ('#' . $id);
        }

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => $id, 'action' => 'update', 'label' => $label, 'message' => $res['message']);
        }
    }

    /* ---------- เพิ่มแถวใหม่ ---------- */
    foreach ($creates as $row) {

        if ($tab === 'company') {
            $res = company_create(array(
                'company_name' => isset($row['company_name']) ? $row['company_name'] : '',
                'is_active'    => isset($row['is_active']) ? (int)$row['is_active'] : 1
            ), $actor);
            $label = isset($row['company_name']) ? $row['company_name'] : '(แถวใหม่)';
        } else {
            $res = department_create(array(
                'department_name' => isset($row['department_name']) ? $row['department_name'] : '',
                'company_id'      => isset($row['company_id']) ? (int)$row['company_id'] : 0,
                'is_active'       => isset($row['is_active']) ? (int)$row['is_active'] : 1
            ), $actor);
            $label = isset($row['department_name']) ? $row['department_name'] : '(แถวใหม่)';
        }

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => 0, 'action' => 'create', 'label' => $label, 'message' => $res['message']);
        }
    }

    api_ok(array(
        'saved'  => $saved,
        'failed' => count($errors),
        'errors' => $errors
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
