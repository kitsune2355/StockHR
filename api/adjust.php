<?php
/* ============================================================
 *  api/adjust.php — endpoint หน้า "บันทึกปรับยอด"
 *
 *    { "action": "products", "search": "", "category_id": 0, "sub_category_id": 0 }
 *        รายการสินค้าที่เปิดใช้งาน พร้อมยอดคงเหลือปัจจุบัน (ไว้ทำใบตรวจนับ)
 *
 *    { "action": "save", "csrf_token": "...", "doc_date": "YYYY-MM-DD",
 *      "note": "", "items": [ { "product_id": 1, "counted": 10, "note": "" } ] }
 *        ยื่นใบปรับยอด — สร้างเอกสาร doc_type='adjust' สถานะ waiting
 *        ยอดคงเหลือยังไม่เปลี่ยนจนกว่าจะมีผู้อนุมัติ
 *
 *  จำกัดเฉพาะ super_admin / admin (คนที่ยื่นใบปรับยอดได้)
 *  ส่วนการดูรายการ / อนุมัติ / ยกเลิก อยู่ที่ api/documents.php (doc_type=adjust)
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกผ่าน backend/adjust.php กับ backend/stock.php
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/adjust.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'products') {

    $rows = stock_list(array(
        'only_active'     => true,
        'search'          => isset($input['search'])          ? $input['search']               : '',
        'category_id'     => isset($input['category_id'])     ? (int)$input['category_id']     : 0,
        'sub_category_id' => isset($input['sub_category_id'])  ? (int)$input['sub_category_id'] : 0
    ));

    api_ok(array('rows' => $rows));
}


if ($action === 'save') {

    api_require_csrf($input);

    $items_in = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();
    $items    = array();
    for ($i = 0; $i < count($items_in); $i++) {
        $it = $items_in[$i];
        $items[] = array(
            'product_id' => isset($it['product_id']) ? (int)$it['product_id'] : 0,
            'counted'    => isset($it['counted'])    ? $it['counted']         : null,
            'note'       => isset($it['note'])       ? $it['note']            : ''
        );
    }

    $res = adjust_save(array(
        'doc_date' => isset($input['doc_date']) ? $input['doc_date'] : '',
        'note'     => isset($input['note'])     ? $input['note']     : '',
        'items'    => $items
    ), $actor);

    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array(
        'message' => $res['message'],
        'doc_id'  => $res['id'],
        'doc_no'  => isset($res['doc_no']) ? $res['doc_no'] : ''
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
