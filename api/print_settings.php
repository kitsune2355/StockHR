<?php
/* ============================================================
 *  api/print_settings.php — ค่าตั้งเอกสารพิมพ์ (Admin mode > เอกสารพิมพ์)
 *
 *  { "action": "get" }
 *      -> values (ค่าที่ใช้จริง), defaults (ค่าตั้งต้น), applies (ช่องไหนมีผลกับประเภทไหน)
 *  ตัวอย่างในหน้าตั้งค่าเป็นเอกสารสมมติ (print_doc.php?mock=) ไม่อ่านเอกสารจริง
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

    api_ok(array('values' => $values, 'defaults' => $defaults, 'applies' => $applies));
}


if ($action === 'save') {

    api_require_csrf($input);

    $values = (isset($input['values']) && is_array($input['values'])) ? $input['values'] : array();
    $res = print_setting_save($values, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => 'บันทึกการตั้งค่าเอกสารพิมพ์เรียบร้อย'));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
