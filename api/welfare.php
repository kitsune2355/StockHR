<?php
/* ============================================================
 *  api/welfare.php — ทางเข้าของระบบสวัสดิการ (hl-welfare) แบบเครื่องคุยกับเครื่อง
 *
 *  ต่างจาก endpoint อื่นในโฟลเดอร์นี้: ไม่ใช้ session / CSRF
 *  ผู้เรียกคือเซิร์ฟเวอร์ของระบบสวัสดิการ ยืนยันตัวตนด้วยลายเซ็นในหัวคำขอ
 *      X-Welfare-Timestamp: <unix time>
 *      X-Welfare-Signature: hex( HMAC-SHA256( กุญแจ, "<timestamp>.<body ดิบ>" ) )
 *  กุญแจอยู่ใน config_ctrl/welfare_key.php (ดู backend/welfare_bridge.php)
 *
 *  body เป็น JSON เสมอ:
 *
 *    { "action": "catalog", "items": ["POLO_BLACK","SAFETY_SHOES"], "logo": "PRO", "site_id": 4, "event": "START" }
 *        แบบ/ไซซ์ที่เลือกได้ของแต่ละรายการ พร้อมยอดคงเหลือ และหน่วยงานที่จะลงใบ
 *        event = START ได้ doc_kind = new_staff และชุดของพนักงานใหม่ (kit) ที่จะใส่ให้ในใบ
 *
 *    { "action": "submit", "employee_id": 12, "employee_code": "PRO-0012",
 *      "employee_name": "...", "position_name": "...", "company_code": "PRO", "logo": "PRO",
 *      "site_id": 4, "site_name": "...", "event": "ANNUAL", "entitlement_key": "ANNUAL-2026",
 *      "requested_by_name": "...", "note": "",
 *      "lines": [ {"item_code":"POLO_BLACK", "product_id":31, "qty":2} ] }
 *        ออกใบเบิก WRQ ที่รออนุมัติ (ผู้อนุมัติของหน่วยงานกดอนุมัติเอง) — ส่งซ้ำสิทธิ์เดิมได้ใบเดิมคืน (duplicate = true)
 *        event = START ต้องมี "start_date" และได้ใบเบิกพนักงานใหม่ NRQ (ใส่ชุดของพนักงานใหม่ให้)
 *
 *    { "action": "status", "refs": ["12:START", "12:ANNUAL-2026"] }
 *        สถานะล่าสุดของแต่ละสิทธิ์ (ref = "<employee_id>:<entitlement_key>")
 *
 *    { "action": "cancel", "ref": "12:ANNUAL-2026", "reason": "...", "requested_by_name": "..." }
 *        ยกเลิกใบที่คลังยังไม่ได้จ่าย
 *
 *    { "action": "sync_master", "requested_by_name": "...",
 *      "companies": [ {"code":"HL", "name":"บริษัท แฮปปี้แลนด์ จำกัด", "active":true} ],
 *      "sites":     [ {"id":1, "company_code":"HL", "name":"สำนักงานใหญ่ HL", "active":true} ],
 *      "uniform_start": [ {"site_id":4, "rules":[ {"rule_id":16, "label":"Pro Site · ช่างทีมโมบาย",
 *                          "items":[ {"item_code":"POLO_NAVY", "qty":1} ]} ]} ] }
 *        รับบริษัท/หน่วยงาน/ชุดฟอร์มรอบเริ่มงานจากระบบสวัสดิการ (ต้นทาง) — ทั้งชุดใน transaction เดียว
 *        ทุกส่วนไม่บังคับ / active=false = ปิดใช้งาน ไม่ลบ
 *        uniform_start: แต่ละหน่วยงานที่ส่งมา = แทนที่เงื่อนไขชุดฟอร์มทั้งชุดของหน่วยงานนั้น
 *        ตอบ companies [{code, company_id, created}], sites [{id, department_id, created}],
 *            uniform_start [{site_id, rules, items, changed}]
 *        (ดู backend/welfare_master.php, backend/welfare_uniform.php)
 *
 *  ตอบ { "ok": true, ... } หรือ { "ok": false, "message": "..." }
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/welfare_bridge.php และ backend/welfare_master.php
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/welfare_bridge.php';
require_once dirname(dirname(__FILE__)) . '/backend/welfare_master.php';

api_require_post();

/* อ่าน body ดิบครั้งเดียว (PHP 5.2 อ่าน php://input ซ้ำไม่ได้) ใช้ทั้งตรวจลายเซ็นและแปลง JSON */
$raw = file_get_contents('php://input');
if ($raw === false) { $raw = ''; }

$ts   = isset($_SERVER['HTTP_X_WELFARE_TIMESTAMP']) ? $_SERVER['HTTP_X_WELFARE_TIMESTAMP'] : '';
$sign = isset($_SERVER['HTTP_X_WELFARE_SIGNATURE']) ? $_SERVER['HTTP_X_WELFARE_SIGNATURE'] : '';

$auth_error = welfare_verify($ts, $sign, $raw);
if ($auth_error !== '') {
    api_fail($auth_error, 401);
}

if (!welfare_bridge_ready()) {
    api_fail('ฐานข้อมูลสต็อกยังไม่พร้อม — ต้องรัน sql/075_welfare_bridge.sql ก่อน');
}

$input = json_decode($raw, true);
if (!is_array($input)) { $input = array(); }
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'catalog') {

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();
    $logo  = isset($input['logo']) ? trim($input['logo']) : '';
    $site  = isset($input['site_id']) ? (int)$input['site_id'] : 0;
    $event = isset($input['event']) ? trim($input['event']) : '';

    api_ok(welfare_catalog($items, $logo, $site, $event));
}


if ($action === 'submit') {

    $res = welfare_submit($input);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array(
        'message'   => $res['message'],
        'doc_id'    => $res['id'],
        'doc_no'    => $res['doc_no'],
        'duplicate' => !empty($res['duplicate']),
        'status'    => welfare_status(array(
                           (int)$input['employee_id'] . ':' . trim($input['entitlement_key'])))
    ));
}


if ($action === 'status') {

    $refs = isset($input['refs']) && is_array($input['refs']) ? $input['refs'] : array();
    api_ok(array('status' => welfare_status($refs)));
}


if ($action === 'cancel') {

    $res = welfare_cancel(
        isset($input['ref'])               ? (string)$input['ref']               : '',
        isset($input['reason'])            ? (string)$input['reason']            : '',
        isset($input['requested_by_name']) ? (string)$input['requested_by_name'] : '');
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array('message' => $res['message'], 'doc_id' => $res['id']));
}


if ($action === 'sync_master') {

    $res = welfare_master_sync($input);
    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array(
        'message'   => $res['message'],
        'companies' => $res['companies'],
        'sites'     => $res['sites'],
        'uniform_start' => $res['uniform_start'],
        'created'   => $res['created'],
        'changed'   => $res['changed']
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
