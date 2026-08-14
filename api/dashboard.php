<?php
/* ============================================================
 *  api/dashboard.php — ข้อมูลกราฟบนหน้าภาพรวมระบบ
 *
 *    { "action": "series", "unit": "day|month|year",
 *      "date_from": "2026-08-01", "date_to": "2026-08-13" }
 *
 *  แยกเป็น endpoint เพื่อให้เปลี่ยนช่วงกราฟได้โดยไม่ต้องโหลดหน้าใหม่
 *  ซึ่งจะดึงตัวเลขสรุป งานค้าง และรายการเคลื่อนไหวมาใหม่ทั้งหมดโดยไม่จำเป็น
 *
 *  กราฟนี้เป็นยอดรวมของทั้งคลัง จึงเปิดให้เฉพาะสิทธิ์ที่เห็นภาพรวมคลังอยู่แล้ว
 *  (dashboard_is_staff) ยิงมาตรง ๆ ก็ถูกปฏิเสธเหมือนกัน
 *
 *  ไม่มี SQL — เรียกจาก backend/dashboard.php ทั้งหมด
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/dashboard.php';

api_require_post();
api_require_login();

/* _bootstrap.php ไม่ได้ประกาศ $actor_role ให้ แต่ละ endpoint อ่านเองจาก session
   (ตกตรงนี้ทีแรก ผลคือ dashboard_is_staff ได้ค่าว่างแล้วปฏิเสธผู้ดูแลระบบไปด้วย) */
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'series') {

    if (!dashboard_is_staff($actor_role)) {
        api_fail('สิทธิ์ของคุณไม่เห็นภาพรวมความเคลื่อนไหวของคลัง');
    }

    $unit = isset($input['unit']) ? $input['unit'] : 'day';
    if (!in_array($unit, array('day', 'month', 'year'))) { $unit = 'day'; }

    /* ไม่มีช่วงส่งมา = 7 วันล่าสุด ซึ่งเป็นค่าเดิมของหน้านี้
       คนที่เพิ่งเปิดหน้าจะได้เห็นสิ่งเดียวกับที่เคยเห็นเสมอ */
    $to   = isset($input['date_to'])   ? trim($input['date_to'])   : '';
    $from = isset($input['date_from']) ? trim($input['date_from']) : '';

    if ($to === '')   { $to   = date('Y-m-d'); }
    if ($from === '') { $from = date('Y-m-d', strtotime('-6 days', strtotime($to))); }

    $res = dashboard_series($from, $to, $unit);

    /* รวมยอดจากชุดเดียวกับที่ส่งไปวาด ตัวเลขใต้กราฟจึงตรงกับกราฟเสมอ
       ไม่ใช่ยิง query แยกอีกชุดที่อาจใช้เงื่อนไขต่างกันนิดหน่อย */
    $total_in = 0;
    $total_out = 0;
    for ($i = 0; $i < count($res['points']); $i++) {
        $total_in  += $res['points'][$i]['in'];
        $total_out += $res['points'][$i]['out'];
    }

    api_ok(array(
        'points'    => $res['points'],
        'unit'      => $res['unit'],
        'truncated' => $res['truncated'],
        'date_from' => $from,
        'date_to'   => $to,
        'total_in'  => $total_in,
        'total_out' => $total_out
    ));
}

api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
