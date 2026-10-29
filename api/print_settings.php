<?php
/* ============================================================
 *  api/print_settings.php — ค่าตั้งเอกสารพิมพ์ (Admin mode > เอกสารพิมพ์)
 *
 *  { "action": "get" }
 *      -> values (ค่าที่ใช้จริง), defaults (ค่าตั้งต้น), samples (เลขเอกสารล่าสุดของแต่ละประเภท ไว้ดูตัวอย่าง)
 *  { "action": "save", "values": { "request": { "title": "...", ... }, "issue": {...} }, "csrf_token": "..." }
 *
 *  เฉพาะผู้ดูแลระบบสูงสุด — ตรงกับเมนูใน includes/menu.php (super_only)
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/print_setting.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'get') {

    /* ค่าที่ใช้จริง / ค่าตั้งต้น ของทุกประเภท + ช่องไหนมีผลกับประเภทไหน (หน้าตั้งค่าซ่อนช่องที่ไม่เกี่ยว) */
    $values   = array();
    $defaults = array();
    $applies  = array();
    foreach (print_types() as $t) {
        $values[$t]   = print_cfg($t);
        $defaults[$t] = array();
        foreach (print_setting_defs() as $f => $def) {
            $defaults[$t][$f] = ($def['type'] === 'html') ? print_clean_html(print_default($def, $t)) : print_default($def, $t);
        }
    }
    foreach (print_setting_defs() as $f => $def) { $applies[$f] = $def['types']; }

    /* เอกสารล่าสุดของแต่ละแบบ ให้หน้าตั้งค่าเปิดตัวอย่างจากของจริงได้ทันที
       ใบเบิกแยกใบปกติกับใบพนักงานใหม่ — stock_hr พิมพ์ใบพนักงานใหม่เป็นเอกสารรับชุดเครื่องแบบ (uniform) */
    $connect = db_connect();
    $samples = array();
    $q = array(
        'request'   => "SELECT h.`doc_id`, h.`doc_no` FROM `tb_doc_header` h LEFT JOIN `tb_doc_new_staff` n ON n.`doc_id` = h.`doc_id`
                        WHERE h.`doc_type` = 'request' AND n.`doc_id` IS NULL ORDER BY h.`doc_id` DESC LIMIT 1",
        'uniform'   => "SELECT h.`doc_id`, h.`doc_no` FROM `tb_doc_header` h INNER JOIN `tb_doc_new_staff` n ON n.`doc_id` = h.`doc_id`
                        ORDER BY h.`doc_id` DESC LIMIT 1",
        'issue'     => "SELECT `doc_id`, `doc_no` FROM `tb_doc_header` WHERE `doc_type` = 'issue'   ORDER BY `doc_id` DESC LIMIT 1",
        'receive'   => "SELECT `doc_id`, `doc_no` FROM `tb_doc_header` WHERE `doc_type` = 'receive' ORDER BY `doc_id` DESC LIMIT 1",
        'adjust'    => "SELECT `doc_id`, `doc_no` FROM `tb_doc_header` WHERE `doc_type` = 'adjust'  ORDER BY `doc_id` DESC LIMIT 1",
    );
    foreach ($q as $k => $sql) {
        $r = @mysqli_query($connect, $sql);   // ก่อนรัน sql/072 ไม่มี tb_doc_new_staff — ข้ามไปเฉย ๆ
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($r) { mysqli_free_result($r); }
        if ($row) { $samples[$k] = array('doc_id' => (int)$row['doc_id'], 'doc_no' => $row['doc_no']); }
    }

    api_ok(array('values' => $values, 'defaults' => $defaults, 'applies' => $applies, 'samples' => $samples));
}


if ($action === 'save') {

    api_require_csrf($input);

    $values = (isset($input['values']) && is_array($input['values'])) ? $input['values'] : array();
    $res = print_setting_save($values, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => 'บันทึกการตั้งค่าเอกสารพิมพ์เรียบร้อย'));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
