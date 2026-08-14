<?php
/* ============================================================
 *  api/reports.php — endpoint ของหน้ารายงาน
 *
 *    { "action": "stock" }      ยอดคงเหลือทุกสินค้า
 *    { "action": "low" }        เฉพาะที่ต่ำกว่าหรือเท่าจุดสั่งซื้อ
 *    { "action": "movement", "date_from": "...", "date_to": "...",
 *      "product_id": 0, "move_type": "", "search": "" }
 *
 *  เปิดให้ทุกสิทธิ์ที่ล็อกอิน เพราะเป็นการอ่านอย่างเดียว
 *  และทุกแผนกต้องดูได้ว่าของที่จะเบิกยังมีอยู่ไหมก่อนทำใบเบิก
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกจาก backend/stock.php ทั้งหมด
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/stock.php';

api_require_post();
api_require_login();

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'stock' || $action === 'low') {

    $rows = stock_list(array(
        'only_active' => true,
        'low_only'    => ($action === 'low'),
        'search'      => isset($input['search'])      ? $input['search']           : '',
        'category_id' => isset($input['category_id']) ? (int)$input['category_id'] : 0
    ));

    /* สรุปหัวรายงาน คำนวณจากชุดข้อมูลเดียวกับที่ส่งไป ตัวเลขจึงตรงกับตารางเสมอ */
    $total_qty = 0;
    $out_count = 0;
    $low_count = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $total_qty += $rows[$i]['qty'];
        if ($rows[$i]['qty'] <= 0) { $out_count++; }
        if ($rows[$i]['min_qty'] > 0 && $rows[$i]['qty'] <= $rows[$i]['min_qty']) { $low_count++; }
    }

    api_ok(array(
        'rows'    => $rows,
        'summary' => array(
            'products'  => count($rows),
            'total_qty' => $total_qty,
            'out_count' => $out_count,
            'low_count' => $low_count
        )
    ));
}


if ($action === 'movement') {

    /* ไม่ระบุช่วงวันที่ = ย้อนหลัง 30 วัน
       ถ้าปล่อยให้ดึงทั้งหมดตั้งแต่แรก หน้าจะช้าขึ้นเรื่อย ๆ ตามอายุการใช้งาน */
    $from = isset($input['date_from']) && $input['date_from'] !== ''
            ? $input['date_from'] : date('Y-m-d', strtotime('-30 days'));
    $to   = isset($input['date_to']) && $input['date_to'] !== ''
            ? $input['date_to'] : date('Y-m-d');

    $rows = stock_movements(array(
        'date_from'  => $from,
        'date_to'    => $to,
        'product_id' => isset($input['product_id']) ? (int)$input['product_id'] : 0,
        'move_type'  => isset($input['move_type'])  ? $input['move_type']       : '',
        'search'     => isset($input['search'])     ? $input['search']          : '',
        'limit'      => 2000
    ));

    $sum_in  = 0;
    $sum_out = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $sum_in  += $rows[$i]['qty_in'];
        $sum_out += $rows[$i]['qty_out'];
    }

    api_ok(array(
        'rows'      => $rows,
        'moveTypes' => stock_move_types(),
        'range'     => array('from' => $from, 'to' => $to),
        'summary'   => array(
            'lines'   => count($rows),
            'qty_in'  => $sum_in,
            'qty_out' => $sum_out
        )
    ));
}


/* ============================================================
 *  รายงานค่าใช้จ่ายรายเดือน (ส่งฝ่ายบัญชี)
 *
 *  จำกัดเฉพาะคนที่ดูแลระบบ/คลัง เพราะเป็นตัวเลขเงินของทั้งองค์กร
 *  ต่างจากรายงานยอดคงเหลือที่ทุกคนดูได้เพราะมีแค่จำนวน
 * ============================================================ */
if ($action === 'cost') {

    api_require_role(array('super_admin', 'admin'));
    require_once dirname(dirname(__FILE__)) . '/backend/report_cost.php';

    $month  = isset($input['month']) ? trim($input['month']) : date('Y-m');
    $report = cost_report($month);

    $report['months'] = cost_months();
    api_ok($report);
}


/* ============================================================
 *  เจาะดูที่มาของค่าใช้จ่าย
 *
 *  กดที่บรรทัดในรายงานแล้วถามต่อว่า "ยอดนี้มาจากไหน"
 *  คืนการเคลื่อนไหวรายครั้ง พร้อมเลขที่เอกสารและผู้ขอเบิก
 * ============================================================ */
if ($action === 'cost_drill') {

    api_require_role(array('super_admin', 'admin'));
    require_once dirname(dirname(__FILE__)) . '/backend/report_cost.php';

    $range = cost_month_range(isset($input['month']) ? trim($input['month']) : date('Y-m'));

    $rows = cost_drill($range['from'], $range['to'], array(
        'company_id'   => isset($input['company_id'])   ? $input['company_id']   : '',
        'department'   => isset($input['department'])   ? $input['department']   : '',
        'product_code' => isset($input['product_code']) ? $input['product_code'] : ''
    ));

    /* รวมยอดจากแถวที่ส่งไปจริง ไม่ยิง query นับแยกอีกชุด
       ตัวเลขสรุปในกล่องจึงตรงกับบรรทัดที่ผู้ใช้เห็นเสมอ */
    $sum = 0; $qty = 0;
    for ($i = 0; $i < count($rows); $i++) {
        $sum += $rows[$i]['amount'];
        $qty += $rows[$i]['qty'];
    }

    api_ok(array(
        'rows'    => $rows,
        'total'   => $sum,
        'qty'     => $qty,
        'count'   => count($rows),
        /* บอกให้ชัดว่าโดนตัดที่ 500 บรรทัด ไม่ใช่ปล่อยให้เข้าใจว่านี่คือทั้งหมด */
        'capped'  => (count($rows) >= 500)
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
