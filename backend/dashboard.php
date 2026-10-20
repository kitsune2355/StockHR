<?php
/* ============================================================
 *  backend/dashboard.php — ตัวเลขและรายการสำหรับหน้าภาพรวมระบบ
 *
 *  รวมคำถามที่หน้าภาพรวมต้องถามไว้ที่เดียว แทนที่จะให้หน้าเว็บยิงทีละคำถาม
 *  เพราะหน้านี้เปิดทุกครั้งที่เข้าระบบ ถ้ากระจายเป็น 10 query จากหน้าเว็บ
 *  จะช้าและควบคุมยากเวลาข้อมูลโตขึ้น
 *
 *  ทุกอย่างที่คืนออกไปขึ้นกับ "คนที่กำลังดู" เสมอ
 *  ผู้ใช้ทั่วไปไม่ต้องเห็นยอดรับเข้าของทั้งคลัง แต่ต้องเห็นว่าใบเบิกของตัวเอง
 *  ค้างอยู่ขั้นไหน ส่วนคนคลังต้องเห็นภาพรวมของทั้งคลัง
 *
 *  กติกาของชั้น backend เหมือนเดิม: ไม่ echo ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/stock.php';
require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/request.php';
require_once dirname(__FILE__) . '/issue.php';
require_once dirname(__FILE__) . '/adjust.php';
require_once dirname(__FILE__) . '/user.php';
require_once dirname(__FILE__) . '/thai_date.php';


/* คนกลุ่มนี้ดูภาพรวมของทั้งคลังได้ */
function dashboard_is_staff($role)
{
    return in_array($role, array('super_admin', 'admin', 'approver'));
}


/* ต่อข้อความด้วย " · " ข้ามตัวที่ว่าง
   ไม่ใช้ trim($s, ' ·') เพราะ trim ตัดทีละไบต์ จุดกลาง (2 ไบต์) จะไปกินไบต์ท้ายของอักษรไทย */
function dashboard_join($a, $b)
{
    $parts = array();
    if (trim($a) !== '') { $parts[] = trim($a); }
    if (trim($b) !== '') { $parts[] = trim($b); }
    return implode(' · ', $parts);
}


/* ---------- นับแถวแบบง่าย ๆ ที่ใช้ซ้ำหลายที่ ---------- */
function dashboard_count($sql, $types = '', $values = array())
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return 0; }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return ($n === null) ? 0 : $n;
}


/* ============================================================
 *  ตัวเลขสรุปทั้งหมดของหน้าภาพรวม
 * ============================================================ */
function dashboard_summary($username, $role)
{
    $today = date('Y-m-d');

    $out = array(
        /* ---------- ภาพรวมคลัง ---------- */
        'products'   => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_product` WHERE `is_active` = 1"),

        'low'        => stock_low_count(),

        'out_of_stock' => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_product` p
             LEFT JOIN `tb_stock_balance` b ON b.`product_id` = p.`product_id`
             WHERE p.`is_active` = 1 AND COALESCE(b.`qty`, 0) <= 0"),

        /* มีสินค้ากี่ตัวที่ตั้งจุดสั่งซื้อไว้แล้ว
           ใช้แยกกรณี "ไม่มีอะไรต้องสั่ง" ออกจาก "ยังไม่ได้ตั้งจุดสั่งซื้อเลย"
           สองอย่างนี้ได้เลข 0 เท่ากัน แต่ความหมายคนละเรื่อง */
        'with_min' => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_product` WHERE `is_active` = 1 AND `min_qty` > 0"),

        /* ---------- ความเคลื่อนไหววันนี้ ---------- */
        'today_in'   => (float)dashboard_count(
            "SELECT COALESCE(SUM(`qty_in`), 0) FROM `tb_stock_movement`
             WHERE `move_date` >= ? AND `move_date` <= ?",
            'ss', array($today . ' 00:00:00', $today . ' 23:59:59')),

        'today_out'  => (float)dashboard_count(
            "SELECT COALESCE(SUM(`qty_out`), 0) FROM `tb_stock_movement`
             WHERE `move_date` >= ? AND `move_date` <= ?",
            'ss', array($today . ' 00:00:00', $today . ' 23:59:59')),

        /* ---------- งานค้างของคลัง ---------- */
        'ready_to_issue' => count(request_ready_list()),

        /* ---------- งานที่รอคนคนนี้ ---------- */
        'waiting_approve' => request_waiting_count($username, $role),
        'waiting_ack'     => issue_pending_ack_count($username),

        /* ---------- ใบเบิกของฉัน ---------- */
        'my_waiting'  => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'request' AND `status` = 'waiting' AND `requester` = ?",
            's', array($username)),

        'my_approved' => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'request' AND `status` = 'approved' AND `requester` = ?",
            's', array($username)),

        'my_total'    => (int)dashboard_count(
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'request' AND `requester` = ?",
            's', array($username))
    );

    /* ยอดรวมทั้งคลัง — ตัวเลขเดียวที่บอกว่าของในมือเยอะแค่ไหน
       ไม่ได้แปลว่ามูลค่า เพราะระบบนี้นับเฉพาะจำนวนตามที่ตกลงไว้ */
    $out['stock_total'] = (float)dashboard_count(
        "SELECT COALESCE(SUM(`qty`), 0) FROM `tb_stock_balance`");

    return $out;
}


/* ============================================================
 *  งานที่รอคนคนนี้ทำ — รายการจริง ไม่ใช่แค่ตัวเลข
 *
 *  เรียงตามความเร่งด่วน: อนุมัติก่อน (คนอื่นรออยู่)
 *  แล้วค่อยเซ็นรับของ (ของถึงมือแล้ว แค่ยังไม่ได้ยืนยัน)
 * ============================================================ */
function dashboard_my_tasks($username, $role, $limit = 6)
{
    $tasks = array();

    /* ---------- ใบเบิกที่รอเราอนุมัติ ---------- */
    if (dashboard_is_staff($role)) {
        $rows = doc_list(array('doc_type' => 'request', 'status' => 'waiting', 'limit' => 50));

        $my_depts = array();
        if ($role === 'approver') {
            $me = user_find($username);
            $my_depts = ($me === null) ? array() : $me['dept_ids'];
        }

        for ($i = 0; $i < count($rows); $i++) {
            $r = $rows[$i];

            /* ใบของตัวเองอนุมัติไม่ได้ ไม่ต้องเอามาขึ้นรายการให้เก้อ */
            if ($r['requester'] === $username) { continue; }

            /* ผู้อนุมัติเห็นเฉพาะหน่วยงานตัวเอง — กฎเดียวกับ request_decide */
            if ($role === 'approver' && !in_array((int)$r['department_id'], $my_depts)) { continue; }

            $dept_name = $r['department_name'] !== '' ? $r['department_name'] : 'ไม่ระบุหน่วยงาน';
            $ns        = !empty($r['is_new_staff']);

            $tasks[] = array(
                'kind'    => 'approve',
                'label'   => 'รออนุมัติ',
                'icon'    => 'clipboard-check',
                'tone'    => 'amber',
                'doc_no'  => $r['doc_no'],
                /* ใบพนักงานใหม่ ชื่อพนักงานบอกได้มากกว่าชื่อหน่วยงาน
                   (หน่วยงานย้ายไปอยู่บรรทัดรองคู่กับบริษัท) */
                'title'   => $ns ? ('พนักงานใหม่: ' . $r['new_staff_name']) : $dept_name,
                /* ชื่อบริษัทกำกับไว้ เพราะชื่อหน่วยงานซ้ำกันข้ามบริษัทได้
                   เห็นแค่ชื่อหน่วยงานอย่างเดียวยังตอบไม่ได้ว่าเป็นใบของที่ไหน */
                'company' => $ns ? dashboard_join($dept_name, $r['company_name']) : $r['company_name'],
                'sub'     => 'ผู้ขอ ' . ($r['requester_name'] !== '' ? $r['requester_name'] : $r['requester'])
                           . ' · ' . (int)$r['line_count'] . ' รายการ',
                'date'    => $r['doc_date'],
                /* ทุกใบอยู่ใน "รายการใบเบิก" (เมนูใบเบิกพนักงานใหม่ถูกซ่อน) */
                'page'    => 'request_list'
            );
            if (count($tasks) >= $limit) { return $tasks; }
        }
    }

    /* ---------- ใบปรับยอดที่รอเราอนุมัติ ----------
       ผู้อนุมัติใบปรับยอดตั้งเป็นรายชื่อเฉพาะ ไม่ผูกกับ role (ดู adjust_approver_can)
       จึงเช็คสิทธิ์แยกจาก dashboard_is_staff() ข้างบน */
    if (adjust_approver_can($username, $role)) {
        $rows = doc_list(array('doc_type' => 'adjust', 'status' => 'waiting', 'limit' => 50));

        for ($i = 0; $i < count($rows); $i++) {
            $r = $rows[$i];

            /* อนุมัติใบที่ตัวเองสร้างไม่ได้ — กฎเดียวกับ adjust_decide() */
            if ($r['created_by'] === $username) { continue; }

            $tasks[] = array(
                'kind'   => 'adjust',
                'label'  => 'รออนุมัติปรับยอด',
                'icon'   => 'scale',
                'tone'   => 'violet',
                'doc_no' => $r['doc_no'],
                'title'  => 'ใบปรับยอดสินค้า',
                'sub'    => 'ผู้บันทึก ' . $r['created_by'] . ' · ' . (int)$r['line_count'] . ' รายการ',
                'date'   => $r['doc_date'],
                'page'   => 'adjust_history'
            );
            if (count($tasks) >= $limit) { return $tasks; }
        }
    }

    /* ---------- ใบจ่ายที่รอเราเซ็นรับของ ---------- */
    $rows = doc_list(array('doc_type' => 'issue', 'status' => 'issued',
                           'requester' => $username, 'limit' => 20));
    for ($i = 0; $i < count($rows); $i++) {
        $r = $rows[$i];
        $tasks[] = array(
            'kind'   => 'ack',
            'label'  => 'รอเซ็นรับของ',
            'icon'   => 'pen-line',
            'tone'   => 'brand',
            'doc_no' => $r['doc_no'],
            'title'  => 'ของพร้อมให้รับแล้ว',
            'company' => $r['company_name'],
            'sub'    => 'อ้างใบเบิก ' . ($r['ref_doc_no'] !== '' ? $r['ref_doc_no'] : '-')
                      . ' · ' . (int)$r['line_count'] . ' รายการ',
            'date'   => $r['doc_date'],
            'page'   => 'issue_history'
        );
        if (count($tasks) >= $limit) { return $tasks; }
    }

    /* ---------- ใบเบิกที่อนุมัติแล้วรอคลังจ่าย (เฉพาะคนคลัง) ---------- */
    if (dashboard_is_staff($role)) {
        $rows = request_ready_list();
        for ($i = 0; $i < count($rows); $i++) {
            $r = $rows[$i];
            $tasks[] = array(
                'kind'   => 'issue',
                'label'  => 'รอจ่ายของ',
                'icon'   => 'package-minus',
                'tone'   => 'sky',
                'doc_no' => $r['doc_no'],
                'title'  => !empty($r['is_new_staff'])
                            ? ('พนักงานใหม่: ' . $r['new_staff_name'])
                            : ($r['department_name'] !== '' ? $r['department_name'] : 'ไม่ระบุหน่วยงาน'),
                'company' => !empty($r['is_new_staff'])
                            ? dashboard_join($r['department_name'], $r['company_name'])
                            : $r['company_name'],
                'sub'    => 'ค้างจ่าย ' . stock_format($r['pending_qty']) . ' หน่วย',
                'date'   => $r['doc_date'],
                'page'   => 'issue_new'
            );
            if (count($tasks) >= $limit) { return $tasks; }
        }
    }

    return $tasks;
}


/* ============================================================
 *  ความพร้อมของข้อมูลตั้งต้น
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องมี
 *  ---------------------------------------------------------------
 *  ระบบนี้นำเข้าทะเบียนสินค้ามา 313 รายการ แต่ค่าที่ต้องกรอกเองไม่ได้มาด้วย
 *  ผลคือฟีเจอร์ที่พึ่งค่าพวกนั้นทำงานไม่ได้ โดยไม่มีอะไรบนหน้าจอบอกเลย:
 *
 *    ไม่มีราคา        -> "เงินของเดือน" ต่ำกว่าความจริง และหน้ารับเข้าไม่เติมราคาให้
 *    ไม่มีจุดสั่งซื้อ  -> "ต่ำกว่าจุดสั่งซื้อ" นับสินค้าตัวนั้นไม่ได้เลยแม้ของจะหมด
 *
 *  ที่อันตรายคือทั้งสองกรณีแสดงผลออกมาเป็นเลข 0 หรือเลขน้อย ๆ
 *  ซึ่งอ่านเหมือน "ทุกอย่างเรียบร้อย" ทั้งที่ความจริงคือ "ยังไม่ได้ตั้งค่า"
 *
 *  ฟังก์ชันนี้จึงนับสิ่งที่ยังขาด เพื่อให้หน้าภาพรวมพูดออกมาตรง ๆ
 *
 *  ---------------------------------------------------------------
 *  นับเฉพาะสินค้าที่เปิดใช้งาน
 *  ---------------------------------------------------------------
 *  สินค้าที่ปิดไปแล้วไม่ต้องมีราคาหรือจุดสั่งซื้อ การนับรวมเข้ามาจะทำให้
 *  ตัวเลขไม่มีวันลงถึงศูนย์ แล้วไทล์นี้จะค้างเตือนตลอดไปจนคนเลิกสนใจ
 * ============================================================ */
function dashboard_data_health()
{
    $total = (int)dashboard_count(
        "SELECT COUNT(*) FROM `tb_product` WHERE `is_active` = 1");

    $no_price = (int)dashboard_count(
        "SELECT COUNT(*) FROM `tb_product` WHERE `is_active` = 1 AND `unit_cost` <= 0");

    $no_min = (int)dashboard_count(
        "SELECT COUNT(*) FROM `tb_product` WHERE `is_active` = 1 AND `min_qty` <= 0");

    /* สินค้าที่ยังไม่เคยมีของเข้าเลย ต่างจาก "ของหมด" ตรงที่ของหมดคือเคยมีแล้วใช้จนหมด
       ส่วนตัวนี้คือยังไม่เคยรับเข้าเลยสักครั้ง ซึ่งแปลว่ายอดตั้งต้นยังไม่ได้ลง */
    $no_stock = (int)dashboard_count(
        "SELECT COUNT(*) FROM `tb_product` p
         WHERE p.`is_active` = 1
           AND NOT EXISTS (SELECT 1 FROM `tb_stock_movement` m
                           WHERE m.`product_id` = p.`product_id`)");

    /* ความพร้อมคิดจากช่องที่ต้องกรอกทั้งหมด ไม่ใช่จำนวนสินค้า
       สินค้าหนึ่งตัวมีสองช่องที่นับ (ราคา กับ จุดสั่งซื้อ)
       ยอดตั้งต้นไม่นับรวม เพราะเป็นงานรับของ ไม่ใช่งานตั้งค่าทะเบียน */
    $slots = $total * 2;
    $done  = $slots - $no_price - $no_min;
    $pct   = ($slots > 0) ? (int)floor(($done / $slots) * 100) : 100;

    return array(
        'products' => $total,
        'no_price' => $no_price,
        'no_min'   => $no_min,
        'no_stock' => $no_stock,
        'percent'  => $pct,
        /* มีอะไรให้ทำไหม — ใช้ตัดสินว่าจะแสดงไทล์นี้หรือซ่อนไปเลย
           ตั้งค่าครบแล้วไม่ต้องมีไทล์เตือนค้างอยู่บนหน้าจอให้เกะกะ */
        'has_gap'  => ($no_price > 0 || $no_min > 0)
    );
}


/* ---------- สินค้าที่ควรสั่งเพิ่ม ---------- */
function dashboard_low_items($limit = 6)
{
    $rows = stock_list(array('only_active' => true, 'low_only' => true));

    /* เรียงจากขาดหนักที่สุดก่อน — ตัวที่หมดแล้วต้องอยู่บนสุดเสมอ
       เทียบด้วยส่วนต่างจากจุดสั่งซื้อ ไม่ใช่ยอดดิบ เพราะของที่เหลือ 2 จาก
       จุดสั่งซื้อ 100 เร่งด่วนกว่าของที่เหลือ 1 จากจุดสั่งซื้อ 2 */
    $gap = array();
    for ($i = 0; $i < count($rows); $i++) {
        $min = ($rows[$i]['min_qty'] > 0) ? $rows[$i]['min_qty'] : 1;
        $gap[$i] = ($min - $rows[$i]['qty']) / $min;
    }
    arsort($gap);

    $out = array();
    foreach ($gap as $i => $score) {
        $out[] = $rows[$i];
        if (count($out) >= $limit) { break; }
    }
    return $out;
}


/* ---------- ความเคลื่อนไหวล่าสุด ---------- */
function dashboard_recent_moves($limit = 8)
{
    return stock_movements(array('limit' => $limit));
}


/* ============================================================
 *  มิติเงิน — เฉพาะคนที่เข้าถึงรายงานค่าใช้จ่ายได้
 *
 *  ตัวเลขเงินเป็นข้อมูลของทั้งองค์กร จึงจำกัดสิทธิ์เท่ากับหน้ารายงาน
 *  ค่าใช้จ่ายรายเดือน (super_admin / admin) ไม่งั้นผู้อนุมัติของหน่วยงานหนึ่ง
 *  จะเห็นยอดใช้จ่ายของทุกหน่วยงานผ่านหน้าภาพรวมโดยไม่ตั้งใจ
 *
 *  คืน null เมื่อไม่มีสิทธิ์ หน้าเว็บเช็คค่า null แล้วไม่วาดไทล์เงินเลย
 * ============================================================ */
function dashboard_money($role)
{
    if (!in_array($role, array('super_admin', 'admin'))) {
        return null;
    }

    require_once dirname(__FILE__) . '/report_cost.php';

    $range = cost_month_range(date('Y-m'));
    $dept  = cost_by_department($range['from'], $range['to']);

    $total = 0;
    for ($i = 0; $i < count($dept); $i++) {
        $total += (float)$dept[$i]['amount'];
    }

    /* เอาเฉพาะหน่วยงานที่ใช้เงินจริง เรียงมากไปน้อย
       (cost_by_department เรียงมาให้แล้ว แต่ยังมีแถวยอด 0 จากใบที่ถูกยกเลิกปนอยู่) */
    $top = array();
    for ($i = 0; $i < count($dept); $i++) {
        if ((float)$dept[$i]['amount'] <= 0) { continue; }
        $top[] = array(
            'name'   => $dept[$i]['name'],
            'amount' => (float)$dept[$i]['amount'],
            /* สัดส่วนเทียบกับหน่วยงานที่ใช้มากที่สุด ใช้วาดความยาวแถบ
               เทียบกับตัวมากสุด ไม่ใช่ยอดรวม เพราะถ้ามีหน่วยงานเดียวแถบจะสั้นจนดูแปลก */
            'pct'    => 0
        );
        if (count($top) >= 4) { break; }
    }
    if (count($top) > 0) {
        $max = $top[0]['amount'];
        for ($i = 0; $i < count($top); $i++) {
            $top[$i]['pct'] = ($max > 0) ? ($top[$i]['amount'] / $max) * 100 : 0;
        }
    }

    $connect = db_connect();
    $res = mysqli_query($connect,
        "SELECT COALESCE(SUM(b.`qty` * p.`unit_cost`), 0)
         FROM `tb_stock_balance` b
         INNER JOIN `tb_product` p ON p.`product_id` = b.`product_id`");
    $stock_value = 0;
    if ($res) {
        $r = mysqli_fetch_row($res);
        $stock_value = (float)$r[0];
        mysqli_free_result($res);
    }

    /* มูลค่าที่รับเข้าเดือนนี้ — ใช้เทียบว่าซื้อเข้ามากกว่าหรือน้อยกว่าที่เบิกใช้ */
    $in = (float)dashboard_count(
        "SELECT COALESCE(SUM(CASE WHEN `move_type` = 'receive'
                                  THEN `total_cost` ELSE -`total_cost` END), 0)
         FROM `tb_stock_movement`
         WHERE `move_type` IN ('receive', 'cancel_receive')
           AND `move_date` >= ? AND `move_date` <= ?",
        'ss', array($range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59'));

    return array(
        'month'       => $range['month'],
        'spent'       => $total,
        'received'    => $in,
        'stock_value' => $stock_value,
        'top'         => $top
    );
}


/* ============================================================
 *  ยอดเข้า/ออกรายวันย้อนหลัง — ใช้วาดกราฟแท่งเล็กบนไทล์
 *
 *  รวมทั้งช่วงด้วย query เดียวแล้วค่อยกระจายลงวัน ไม่ยิงวันละ query
 *  และเติมวันที่ไม่มีความเคลื่อนไหวให้เป็น 0 เอง เพราะกราฟที่ข้ามวันว่าง
 *  จะอ่านผิด — ดูเหมือนของเข้าออกทุกวันทั้งที่จริงหยุดไปสองวัน
 * ============================================================ */
/* ============================================================
 *  ความเคลื่อนไหวตามช่วงเวลาที่เลือก — ใช้กับกราฟบนหน้าภาพรวม
 * ============================================================
 *  $unit  day | month | year   ความละเอียดของแต่ละแท่ง
 *  $from / $to  วันที่แบบ Y-m-d (รวมวันปลายทั้งสองข้าง)
 *
 *  ---------------------------------------------------------------
 *  เติมช่องว่างให้ครบทุกช่วง ไม่ใช่ส่งเฉพาะช่วงที่มีข้อมูล
 *  ---------------------------------------------------------------
 *  ถ้าส่งเฉพาะวันที่มีความเคลื่อนไหว กราฟจะบีบวันที่เงียบหายไป
 *  แล้วเส้นจะดูเหมือนเคลื่อนไหวต่อเนื่องทั้งที่จริงมีวันว่างคั่นอยู่
 *  ซึ่งอ่านผิดได้ง่ายมากเวลาดูแนวโน้ม
 *
 *  ---------------------------------------------------------------
 *  จำกัดจำนวนแท่ง
 *  ---------------------------------------------------------------
 *  เลือกช่วงกว้างมากแล้วดูรายวันจะได้เป็นพันแท่ง ซึ่งวาดช้าและอ่านไม่ออก
 *  ตัดที่ 400 ช่วง แล้วบอกกลับไปว่าถูกตัด หน้าจอจะได้เตือนให้เปลี่ยนความละเอียด
 *  ไม่ใช่เงียบ ๆ แล้วผู้ใช้เข้าใจว่าข้อมูลมีเท่านี้
 *
 *  MySQL 5.0 ไม่มีฟังก์ชันสร้างชุดวันที่ จึงวนเติมเองในฝั่ง PHP
 * ============================================================ */
function dashboard_series($from, $to, $unit = 'day')
{
    $connect = db_connect();

    if (!in_array($unit, array('day', 'month', 'year'))) { $unit = 'day'; }

    $from_ts = strtotime($from);
    $to_ts   = strtotime($to);
    if (!$from_ts || !$to_ts) { return array('points' => array(), 'unit' => $unit, 'truncated' => false); }
    if ($from_ts > $to_ts)    { $tmp = $from_ts; $from_ts = $to_ts; $to_ts = $tmp; }

    $fmt = array('day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y');

    $stmt = mysqli_prepare($connect,
        "SELECT DATE_FORMAT(`move_date`, '" . $fmt[$unit] . "') AS `k`,
                COALESCE(SUM(`qty_in`), 0), COALESCE(SUM(`qty_out`), 0)
         FROM `tb_stock_movement`
         WHERE `move_date` >= ? AND `move_date` <= ?
         GROUP BY `k`");

    $found = array();
    if ($stmt) {
        $a = date('Y-m-d', $from_ts) . ' 00:00:00';
        $b = date('Y-m-d', $to_ts)   . ' 23:59:59';
        mysqli_stmt_bind_param($stmt, 'ss', $a, $b);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $k, $qin, $qout);
        while (mysqli_stmt_fetch($stmt)) {
            $found[$k] = array('in' => (float)$qin, 'out' => (float)$qout);
        }
        mysqli_stmt_close($stmt);
    }

    $points    = array();
    $today     = date('Y-m-d');
    $truncated = false;
    $cursor    = $from_ts;

    while ($cursor <= $to_ts) {

        if (count($points) >= 400) { $truncated = true; break; }

        /* ป้ายแกนนอนใช้รูปแบบจาก thai_date_config() เหมือนที่อื่นในระบบ
           ไม่ได้ประกอบชื่อเดือนกับปี พ.ศ. เองตรงนี้ */
        $stamp = date('Y-m-d', $cursor);

        if ($unit === 'day') {
            $key   = date('Y-m-d', $cursor);
            $label = thai_date_format($stamp, 'axis_day');
            $short = thai_date_format($stamp, 'axis_day_short');
            $next  = strtotime('+1 day', $cursor);

        } elseif ($unit === 'month') {
            $key   = date('Y-m', $cursor);
            $label = thai_date_format($stamp, 'axis_month');
            $short = thai_date_format($stamp, 'axis_month_short');
            /* เดินจากวันที่ 1 เสมอ ไม่งั้นวันที่ 31 บวกหนึ่งเดือนจะข้ามเดือนที่มี 30 วัน */
            $next  = strtotime('+1 month', strtotime(date('Y-m-01', $cursor)));

        } else {
            $key   = date('Y', $cursor);
            $label = thai_date_format($stamp, 'axis_year');
            $short = $label;
            $next  = strtotime('+1 year', strtotime(date('Y-01-01', $cursor)));
        }

        $row = isset($found[$key]) ? $found[$key] : array('in' => 0, 'out' => 0);

        $points[] = array(
            'key'   => $key,
            'label' => $label,
            'short' => $short,
            'in'    => $row['in'],
            'out'   => $row['out'],
            'today' => ($unit === 'day' && $key === $today)
        );

        $cursor = $next;
    }

    return array('points' => $points, 'unit' => $unit, 'truncated' => $truncated);
}


function dashboard_week_series($days = 7)
{
    $connect = db_connect();

    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $end   = date('Y-m-d');

    /* MySQL 5.0 ไม่มี generated column แต่ DATE() ใน GROUP BY ใช้ได้ปกติ */
    $stmt = mysqli_prepare($connect,
        "SELECT DATE(`move_date`) AS `d`,
                COALESCE(SUM(`qty_in`), 0), COALESCE(SUM(`qty_out`), 0)
         FROM `tb_stock_movement`
         WHERE `move_date` >= ? AND `move_date` <= ?
         GROUP BY DATE(`move_date`)");

    $found = array();
    if ($stmt) {
        $from = $start . ' 00:00:00';
        $to   = $end   . ' 23:59:59';
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $d, $qin, $qout);
        while (mysqli_stmt_fetch($stmt)) {
            $found[$d] = array('in' => (float)$qin, 'out' => (float)$qout);
        }
        mysqli_stmt_close($stmt);
    }

    $out = array();
    $max = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts  = strtotime('-' . $i . ' days');
        $key = date('Y-m-d', $ts);

        $row = isset($found[$key]) ? $found[$key] : array('in' => 0, 'out' => 0);
        if ($row['in']  > $max) { $max = $row['in']; }
        if ($row['out'] > $max) { $max = $row['out']; }

        $out[] = array(
            'date'  => $key,
            'label' => thai_date_format($key, 'axis_day_short'),
            'day'   => (int)date('j', $ts),
            'in'    => $row['in'],
            'out'   => $row['out'],
            'today' => ($key === $end)
        );
    }

    /* ส่งค่าสูงสุดกลับไปด้วย เพื่อให้ฝั่งหน้าจอคำนวณความสูงแท่งได้
       โดยไม่ต้องวนหาเองซ้ำ และทุกแท่งเทียบกับฐานเดียวกันเสมอ */
    return array('days' => $out, 'max' => $max);
}


/* ============================================================
 *  ไทล์ "พนักงานใหม่" — ใบเบิกวัสดุของพนักงานใหม่ (sql/072)
 *
 *  ตอบสองคำถามของฝ่ายทรัพยากรบุคคล (ไม่มีเรื่องราคา):
 *
 *    1. "วันต่อ ๆ ไปต้องเตรียมของให้ใคร"  -> 'prepare'
 *       คนที่ยังไม่ถึงวันเริ่มงาน + คนที่เลยวันเริ่มงานแล้วแต่ยังไม่ได้ของ
 *       จัดกลุ่มตามระยะ: เลยกำหนด / วันนี้ / พรุ่งนี้ / ภายใน 7 วัน / หลังจากนั้น
 *
 *    2. "จ่ายของทันก่อนวันเริ่มงานไหม"    -> 'review'
 *       คนที่เริ่มงานไปแล้วในช่วง REVIEW_DAYS วันล่าสุด เทียบวันที่คลังจ่ายครบ
 *       (ใบจ่ายใบสุดท้ายที่ไม่ถูกยกเลิก) กับวันเริ่มงาน
 *           ontime   จ่ายครบก่อนหรือในวันเริ่มงาน
 *           late     จ่ายครบหลังวันเริ่มงาน
 *           missing  เลยวันเริ่มงานแล้วยังไม่ได้ของครบ
 *
 *  ขอบเขตการมองเห็นใช้ doc_scope_for() ตัวเดียวกับหน้ารายการ
 *  ไม่นับใบที่ไม่อนุมัติ/ยกเลิก — คนนั้นไม่ได้รอของจากใบนั้นแล้ว
 *
 *  คืน null ถ้ายังไม่ได้รัน sql/072 (หน้าภาพรวมต้องไม่ล้มเพราะเมนูใหม่)
 * ============================================================ */
function dashboard_new_staff($username, $role, $limit = 6)
{
    require_once dirname(__FILE__) . '/new_staff.php';
    if (!new_staff_ready()) { return null; }

    $review_days = 30;

    $opts = array('doc_type' => 'request', 'new_staff_only' => true, 'limit' => 500);
    foreach (doc_scope_for($username, $role) as $k => $v) { $opts[$k] = $v; }
    $rows = doc_list($opts);

    $today       = date('Y-m-d');
    $review_from = date('Y-m-d', strtotime('-' . $review_days . ' days'));

    /* ---------- วันที่คลังจ่ายครบ ----------
     * ใบเบิกสถานะ issued = จ่ายครบแล้ว วันที่ครบคือวันที่ของใบจ่ายใบสุดท้ายที่อ้างถึงมัน
     * ยิงครั้งเดียวสำหรับทุกใบ ไม่ถามทีละใบ */
    $issued_on = array();
    $ids = array();
    for ($i = 0; $i < count($rows); $i++) {
        if ($rows[$i]['status'] === 'issued') { $ids[] = (int)$rows[$i]['doc_id']; }
    }
    if (count($ids) > 0) {
        $res = mysqli_query(db_connect(),
            "SELECT `ref_doc_id`, MAX(`doc_date`) FROM `tb_doc_header`
             WHERE `doc_type` = 'issue' AND `status` <> 'cancelled'
               AND `ref_doc_id` IN (" . implode(',', $ids) . ")
             GROUP BY `ref_doc_id`");
        if ($res) {
            while ($r = mysqli_fetch_row($res)) { $issued_on[(int)$r[0]] = $r[1]; }
            mysqli_free_result($res);
        }
    }

    $out = array(
        'waiting'        => 0,      // รออนุมัติ (ใช้ตัดสินว่าจะโชว์ไทล์ให้ผู้ใช้ทั่วไปไหม)
        'ready'          => 0,      // อนุมัติแล้ว รอคลังจ่าย
        'prepare'        => array(),
        'prepare_total'  => 0,
        'prepare_open'   => 0,      // ในรายการเตรียมของ คนที่ของยังไม่พร้อม
        'review'         => array(),
        'review_days'    => $review_days,
        'ontime'         => 0,
        'late'           => 0,
        'missing'        => 0
    );

    $prepare = array();
    $review  = array();

    for ($i = 0; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (in_array($r['status'], array('rejected', 'cancelled'))) { continue; }

        if ($r['status'] === 'waiting')  { $out['waiting']++; }
        if ($r['status'] === 'approved') { $out['ready']++; }

        $start = $r['new_staff_start'];
        if ($start === '') { continue; }

        $done   = ($r['status'] === 'issued');
        $issued = ($done && isset($issued_on[(int)$r['doc_id']])) ? $issued_on[(int)$r['doc_id']] : '';
        $days   = (int)round((strtotime($start) - strtotime($today)) / 86400);

        $base = array(
            'doc_no'       => $r['doc_no'],
            'name'         => $r['new_staff_name'],
            'position'     => $r['new_staff_position'],
            'department'   => $r['department_name'],
            'start'        => $start,
            'days'         => $days,
            'status'       => $r['status'],
            'issued'       => $issued
        );

        /* ---------- 1) ต้องเตรียมของให้ใคร ---------- */
        if ($days >= 0 || !$done) {
            if ($days < 0)       { $group = 'overdue'; }
            elseif ($days === 0) { $group = 'today'; }
            elseif ($days === 1) { $group = 'tomorrow'; }
            elseif ($days <= 7)  { $group = 'week'; }
            else                 { $group = 'later'; }

            $p = $base;
            $p['group'] = $group;
            $prepare[]  = $p;
            if (!$done) { $out['prepare_open']++; }
        }

        /* ---------- 2) จ่ายทันวันเริ่มงานไหม (เริ่มงานไปแล้วในช่วงที่ดู) ----------
         * คนที่เริ่มงานวันนี้แต่ยังไม่ได้ของ ยังไม่นับว่า "ไม่ทัน" — วันนี้ยังจ่ายทัน
         * (อยู่ในรายการเตรียมของกลุ่ม "วันนี้" อยู่แล้ว) จะเข้ามาที่นี่ตอนจ่ายแล้ว หรือพ้นวันไป */
        if ($start >= $review_from && ($start < $today || ($start === $today && $done))) {
            $v = $base;
            if (!$done) {
                $v['result'] = 'missing';
                $v['gap']    = abs($days);                       // เลยวันเริ่มงานมากี่วัน
            } elseif ($issued !== '' && $issued <= $start) {
                $v['result'] = 'ontime';
                $v['gap']    = (int)round((strtotime($start) - strtotime($issued)) / 86400);   // จ่ายก่อนกี่วัน
            } else {
                $v['result'] = 'late';
                $v['gap']    = ($issued !== '')
                               ? (int)round((strtotime($issued) - strtotime($start)) / 86400) : 0;
            }
            $out[$v['result']]++;
            $review[] = $v;
        }
    }

    /* เตรียมของ: ใกล้วันเริ่มงานที่สุดก่อน (คนที่เลยกำหนดจึงขึ้นบนสุดเอง) */
    usort($prepare, 'dashboard_new_staff_cmp');
    $out['prepare']       = array_slice($prepare, 0, $limit);
    $out['prepare_total'] = count($prepare);

    /* ทบทวน: คนที่ยังไม่ได้ของขึ้นก่อน แล้วไล่จากเริ่มงานล่าสุด */
    usort($review, 'dashboard_new_staff_review_cmp');
    $out['review'] = array_slice($review, 0, $limit);

    return $out;
}

function dashboard_new_staff_review_cmp($a, $b)
{
    $rank = array('missing' => 0, 'late' => 1, 'ontime' => 2);
    if ($rank[$a['result']] !== $rank[$b['result']]) {
        return $rank[$a['result']] - $rank[$b['result']];
    }
    if ($a['start'] === $b['start']) { return strcmp($a['doc_no'], $b['doc_no']); }
    return ($a['start'] > $b['start']) ? -1 : 1;
}

function dashboard_new_staff_cmp($a, $b)
{
    if ($a['start'] === $b['start']) { return strcmp($a['doc_no'], $b['doc_no']); }
    return ($a['start'] < $b['start']) ? -1 : 1;
}
