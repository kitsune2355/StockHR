<?php
/* ============================================================
 *  backend/report_cost.php — รายงานค่าใช้จ่ายรายเดือน (ส่งฝ่ายบัญชี)
 *
 *  ตอบคำถามของบัญชี 3 ข้อในเดือนหนึ่ง ๆ
 *    1. แต่ละหน่วยงานใช้ของไปเป็นเงินเท่าไร   -> จะได้ลงค่าใช้จ่ายตามหน่วยงาน
 *    2. เงินก้อนนั้นหมดไปกับสินค้ากลุ่มไหน     -> จะได้ลงหมวดบัญชีให้ถูก
 *    3. เดือนนั้นซื้อของเข้ามาเท่าไร            -> ใช้กระทบยอดกับใบกำกับ/ใบสั่งซื้อ
 *
 *  ---------------------------------------------------------------
 *  ตัวเลขมาจากไหน
 *  ---------------------------------------------------------------
 *  อ่านจาก tb_stock_movement เท่านั้น ไม่คำนวณสดจากราคาสินค้าปัจจุบัน
 *  เพราะ movement เก็บ "ราคาที่ใช้จริง ณ วันนั้น" แช่แข็งไว้แล้ว
 *  ถ้าไปคูณกับ tb_product.unit_cost ตอนออกรายงาน ตัวเลขเดือนที่แล้ว
 *  จะขยับทุกครั้งที่ซื้อของราคาใหม่ ซึ่งบัญชีปิดงบไปแล้วรับไม่ได้
 *
 *  รายการที่นับเป็น "ค่าใช้จ่าย" คือ issue ลบด้วย cancel_issue
 *  (ของที่จ่ายออกแล้วถูกยกเลิกคืนคลัง ไม่ถือเป็นค่าใช้จ่ายของเดือนนั้น)
 *  ส่วน "รับเข้า" คือ receive ลบด้วย cancel_receive ด้วยเหตุผลเดียวกัน
 *
 *  กติกาของชั้น backend เหมือนเดิม: ไม่ echo ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/stock.php';


/* ---------- ช่วงเวลาของเดือนที่เลือก ----------
 * รับเป็น 'YYYY-MM' แล้วคืนวันแรกกับวันสุดท้ายของเดือนนั้น
 * คำนวณวันสุดท้ายด้วย 't' ของ date() ไม่ hardcode 30/31 เพราะกุมภาพันธ์
 */
function cost_month_range($month)
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $first = $month . '-01';
    $last  = date('Y-m-t', strtotime($first));

    return array('from' => $first, 'to' => $last, 'month' => $month);
}


/* ---------- ตัวช่วยยิง query ที่คืนหลายแถว ---------- */
function cost_rows($sql, $from, $to)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    $a = $from . ' 00:00:00';
    $b = $to   . ' 23:59:59';
    mysqli_stmt_bind_param($stmt, 'ss', $a, $b);
    mysqli_stmt_execute($stmt);

    $meta = mysqli_stmt_result_metadata($stmt);
    $cols = array();
    $bind = array($stmt);
    while ($f = mysqli_fetch_field($meta)) {
        $cols[] = $f->name;
        $bind[] = &$row[$f->name];
    }
    call_user_func_array('mysqli_stmt_bind_result', $bind);

    $out = array();
    while (mysqli_stmt_fetch($stmt)) {
        $one = array();
        foreach ($cols as $c) { $one[$c] = $row[$c]; }
        $out[] = $one;
    }
    mysqli_stmt_close($stmt);

    return $out;
}


/* ============================================================
 *  1) ค่าใช้จ่ายแยกตามหน่วยงานที่เบิก
 *
 *  join กลับไปที่หัวเอกสารเพื่อเอาหน่วยงาน เพราะ movement ไม่ได้เก็บซ้ำ
 *  ใบจ่ายทุกใบมี department_id เสมอ (คัดลอกมาจากใบเบิกตอนบันทึก)
 * ============================================================ */
function cost_by_department($from, $to)
{
    $sql = "SELECT COALESCE(dp.`department_name`, '(ไม่ระบุหน่วยงาน)') AS `name`,
                   COALESCE(c.`company_name`, '') AS `company`,
                   COUNT(DISTINCT m.`doc_id`) AS `docs`,
                   SUM(m.`qty_out` - m.`qty_in`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'issue'
                            THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`
            FROM `tb_stock_movement` m
            LEFT JOIN `tb_doc_header` h  ON h.`doc_id` = m.`doc_id`
            LEFT JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
            LEFT JOIN `tb_company`    c  ON c.`company_id` = dp.`company_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('issue', 'cancel_issue')
            GROUP BY dp.`department_id`, dp.`department_name`, c.`company_name`
            ORDER BY `amount` DESC";

    return cost_rows($sql, $from, $to);
}


/* ============================================================
 *  1.5) ค่าใช้จ่ายแยกตามบริษัท
 *
 *  ระบบมีหลายบริษัทในฐานข้อมูลเดียว และแต่ละบริษัทปิดงบแยกกัน
 *  ตารางรายหน่วยงานมีชื่อบริษัทอยู่ในคอลัมน์ก็จริง แต่บัญชีต้องนั่งบวกเอง
 *  ถ้ามีหน่วยงานสิบกว่าหน่วยกระจายอยู่หลายบริษัท ซึ่งเป็นงานที่ระบบทำให้ได้
 *
 *  ต้องคืน company_id มาด้วย ไม่ใช่แค่ชื่อ เพราะหน้าจอใช้เป็นกุญแจ
 *  ตอนกดดูรายละเอียดว่าเงินก้อนนี้มาจากเอกสารใบไหนบ้าง
 * ============================================================ */
function cost_by_company($from, $to)
{
    $sql = "SELECT COALESCE(c.`company_id`, 0) AS `company_id`,
                   COALESCE(c.`company_name`, '(ไม่ระบุบริษัท)') AS `name`,
                   COUNT(DISTINCT dp.`department_id`) AS `depts`,
                   COUNT(DISTINCT m.`doc_id`) AS `docs`,
                   SUM(m.`qty_out` - m.`qty_in`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'issue'
                            THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`
            FROM `tb_stock_movement` m
            LEFT JOIN `tb_doc_header` h  ON h.`doc_id` = m.`doc_id`
            LEFT JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
            LEFT JOIN `tb_company`    c  ON c.`company_id` = dp.`company_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('issue', 'cancel_issue')
            GROUP BY c.`company_id`, c.`company_name`
            ORDER BY `amount` DESC";

    return cost_rows($sql, $from, $to);
}


/* ============================================================
 *  2) ค่าใช้จ่ายแยกตามหมวดหมู่สินค้า
 * ============================================================ */
function cost_by_category($from, $to)
{
    $sql = "SELECT COALESCE(cat.`category_name`, '(ไม่ระบุหมวดหมู่)') AS `name`,
                   COALESCE(sub.`sub_category_name`, '') AS `sub_name`,
                   SUM(m.`qty_out` - m.`qty_in`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'issue'
                            THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`
            FROM `tb_stock_movement` m
            INNER JOIN `tb_product`      p   ON p.`product_id` = m.`product_id`
            LEFT  JOIN `tb_sub_category` sub ON sub.`sub_category_id` = p.`sub_category_id`
            LEFT  JOIN `tb_category`     cat ON cat.`category_id` = sub.`category_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('issue', 'cancel_issue')
            GROUP BY cat.`category_id`, cat.`category_name`,
                     sub.`sub_category_id`, sub.`sub_category_name`
            ORDER BY cat.`category_name` ASC, `amount` DESC";

    return cost_rows($sql, $from, $to);
}


/* ============================================================
 *  3) สรุปการรับเข้าในเดือน
 *
 *  แยกบรรทัดตามราคาที่ซื้อ ไม่ยุบรวมเป็นสินค้าเดียว
 *  เพราะโจทย์คือของตัวเดียวกันอาจซื้อหลายราคาในเดือนเดียว
 *  บัญชีต้องเห็นว่าล็อตไหนราคาเท่าไร ไม่ใช่เห็นแค่ค่าเฉลี่ย
 * ============================================================ */
function cost_receipts($from, $to)
{
    $sql = "SELECT p.`product_code` AS `code`, p.`product_name` AS `name`,
                   u.`unit_name` AS `unit`,
                   m.`unit_cost` AS `price`,
                   SUM(m.`qty_in` - m.`qty_out`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'receive'
                            THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`,
                   COUNT(DISTINCT m.`doc_id`) AS `docs`
            FROM `tb_stock_movement` m
            INNER JOIN `tb_product` p ON p.`product_id` = m.`product_id`
            LEFT  JOIN `tb_unit`    u ON u.`unit_id`    = p.`unit_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('receive', 'cancel_receive')
            GROUP BY m.`product_id`, m.`unit_cost`
            HAVING `qty` <> 0
            ORDER BY p.`product_code` ASC, m.`unit_cost` ASC";

    return cost_rows($sql, $from, $to);
}


/* ============================================================
 *  4) รายละเอียดจ่ายสินค้าออกรายตัวสินค้า แยกตามหน่วยงาน 
 *     ใช้เป็นเอกสารแนบเวลาบัญชีอยากเห็นว่าเงินไปกับอะไรบ้าง
 * ============================================================ */
function cost_detail($from, $to)
{
    $sql = "SELECT COALESCE(dp.`department_name`, '(ไม่ระบุหน่วยงาน)') AS `dept`,
                   p.`product_code` AS `code`, p.`product_name` AS `name`,
                   u.`unit_name` AS `unit`,
                   SUM(m.`qty_out` - m.`qty_in`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'issue'
                            THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`
            FROM `tb_stock_movement` m
            INNER JOIN `tb_product`    p  ON p.`product_id` = m.`product_id`
            LEFT  JOIN `tb_unit`       u  ON u.`unit_id`    = p.`unit_id`
            LEFT  JOIN `tb_doc_header` h  ON h.`doc_id`     = m.`doc_id`
            LEFT  JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('issue', 'cancel_issue')
            GROUP BY dp.`department_id`, m.`product_id`
            HAVING `qty` <> 0
            ORDER BY `dept` ASC, `amount` DESC";

    return cost_rows($sql, $from, $to);
}


/* ============================================================
 *  5) เจาะดูที่มาของเงินก้อนหนึ่ง
 *
 *  ตอบคำถามว่า "ยอดในบรรทัดนี้มาจากไหนบ้าง" ด้วยการไล่กลับไปที่
 *  การเคลื่อนไหวรายครั้ง ซึ่งเป็นข้อมูลดิบที่สุดที่ระบบมี
 *  หนึ่งแถว = ของออกจากคลังหนึ่งครั้ง พร้อมเลขที่เอกสารและคนที่ทำ
 *
 *  $filter รับได้:
 *      company_id   เจาะจากตารางรายบริษัท
 *      department   ชื่อหน่วยงาน (ตารางรายหน่วยงานไม่ได้ส่ง id ออกมา)
 *      product_code เจาะจากตารางรายตัวสินค้า
 *
 *  ---------------------------------------------------------------
 *  ทำไมสร้าง WHERE ต่อสตริงตรงนี้ได้
 *  ---------------------------------------------------------------
 *  ค่าที่ต่อเข้าไปมีแค่ (int) ที่แปลงแล้ว ส่วนค่าที่เป็นข้อความทั้งหมด
 *  ผูกผ่าน prepared statement เหมือนเดิม จึงไม่มีค่าดิบจากผู้ใช้
 *  หลุดเข้าไปในตัว SQL เลยแม้แต่ตัวเดียว
 * ============================================================ */
function cost_drill($from, $to, $filter = array())
{
    $connect = db_connect();

    $where  = array("m.`move_date` >= ?", "m.`move_date` <= ?",
                    "m.`move_type` IN ('issue', 'cancel_issue')");
    $types  = 'ss';
    $values = array($from . ' 00:00:00', $to . ' 23:59:59');

    if (isset($filter['company_id']) && $filter['company_id'] !== '') {
        $cid = (int)$filter['company_id'];
        /* บริษัท 0 = เอกสารที่หน่วยงานไม่ได้ผูกกับบริษัทใด ต้องหาด้วย IS NULL
           ถ้าใช้ = 0 จะไม่เจอสักแถว เพราะค่าจริงในฐานข้อมูลเป็น NULL */
        $where[] = ($cid > 0) ? ("dp.`company_id` = " . $cid) : "dp.`company_id` IS NULL";
    }

    if (isset($filter['department']) && trim($filter['department']) !== '') {
        $where[]  = "dp.`department_name` = ?";
        $types   .= 's';
        $values[] = trim($filter['department']);
    }

    if (isset($filter['product_code']) && trim($filter['product_code']) !== '') {
        $where[]  = "p.`product_code` = ?";
        $types   .= 's';
        $values[] = trim($filter['product_code']);
    }

    $sql = "SELECT m.`move_date` AS `move_date`,
                   COALESCE(h.`doc_no`, '') AS `doc_no`,
                   COALESCE(h.`doc_id`, 0)  AS `doc_id`,
                   COALESCE(dp.`department_name`, '(ไม่ระบุหน่วยงาน)') AS `dept`,
                   COALESCE(c.`company_name`, '(ไม่ระบุบริษัท)') AS `company`,
                   p.`product_code` AS `code`, p.`product_name` AS `name`,
                   COALESCE(u.`unit_name`, '') AS `unit`,
                   (m.`qty_out` - m.`qty_in`) AS `qty`,
                   m.`unit_cost` AS `price`,
                   (CASE WHEN m.`move_type` = 'issue'
                         THEN m.`total_cost` ELSE -m.`total_cost` END) AS `amount`,
                   m.`move_type` AS `move_type`,
                   COALESCE(ru.`full_name`, COALESCE(h.`requester`, '')) AS `requester`,
                   COALESCE(m.`created_by`, '') AS `by`
            FROM `tb_stock_movement` m
            INNER JOIN `tb_product`    p  ON p.`product_id`  = m.`product_id`
            LEFT  JOIN `tb_unit`       u  ON u.`unit_id`     = p.`unit_id`
            LEFT  JOIN `tb_doc_header` h  ON h.`doc_id`      = m.`doc_id`
            LEFT  JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
            LEFT  JOIN `tb_company`    c  ON c.`company_id`  = dp.`company_id`
            LEFT  JOIN `tb_user`       ru ON ru.`username`   = h.`requester`
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.`move_date` ASC, m.`move_id` ASC
            LIMIT 500";

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    /* bind_param รับพารามิเตอร์แบบอ้างอิงเท่านั้น PHP 5.2 จึงต้องประกอบ
       อาร์เรย์ของ reference แล้วเรียกผ่าน call_user_func_array */
    $bind = array($stmt, $types);
    for ($i = 0; $i < count($values); $i++) {
        $bind[] = &$values[$i];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $move_date, $doc_no, $doc_id, $dept, $company, $code, $name, $unit,
        $qty, $price, $amount, $move_type, $requester, $by);

    $out = array();
    while (mysqli_stmt_fetch($stmt)) {
        $out[] = array(
            'move_date' => $move_date,
            'doc_no'    => $doc_no,
            'doc_id'    => (int)$doc_id,
            'dept'      => $dept,
            'company'   => $company,
            'code'      => $code,
            'name'      => $name,
            'unit'      => $unit,
            'qty'       => (float)$qty,
            'price'     => (float)$price,
            'amount'    => (float)$amount,
            /* รายการที่เกิดจากการยกเลิกใบจ่ายเป็นยอดติดลบ ต้องบอกให้ชัด
               ไม่งั้นคนอ่านจะงงว่าทำไมมีบรรทัดจำนวนติดลบโผล่มา */
            'is_cancel' => ($move_type === 'cancel_issue'),
            'requester' => $requester,
            'by'        => $by
        );
    }
    mysqli_stmt_close($stmt);

    return $out;
}


/* ============================================================
 *  สรุปตามหมวดหมู่หลัก × หน่วยงาน
 * ============================================================
 *  หน้าตาแบบใบที่บัญชีทำมือกันอยู่: แถวละหมวดหมู่หลัก คอลัมน์ละหน่วยงาน
 *  มีทั้งยอดสั่งซื้อรวมของหมวดนั้น และยอดเบิกของแต่ละหน่วยงาน
 *
 *      ลำดับ | รายการ(หมวด) | ยอดรวมสั่งซื้อ | หน่วยงาน... | รวมเบิกทั้งหมด
 *
 *  ต่างจากตาราง "แยกตามหมวดหมู่" ที่มีอยู่แล้ว ตรงที่ตัวนั้นดูฝั่งเบิกอย่างเดียว
 *  และไม่ได้กางว่าใครเบิก ตัวนี้เอาสองมุมมาไว้ในตารางเดียวให้เห็นพร้อมกัน
 *
 *  กางเฉพาะหน่วยงานที่มีการเบิกจริงในเดือนนั้น เหมือนรายงานสั่งซื้อ-เบิกใช้
 *  เดือนที่ไม่มีใครเบิกเลยจะเหลือแค่คอลัมน์ยอดสั่งซื้อ
 */
function cost_cat_dept($from, $to)
{
    /* ---------- ฝั่งซื้อ : รวมทั้งหมวด ---------- */
    $buy_sql = "SELECT COALESCE(c.`category_id`, 0) AS `cid`,
                       COALESCE(c.`category_name`, '(ไม่ระบุหมวดหมู่)') AS `cname`,
                       SUM(CASE WHEN m.`move_type` = 'receive' THEN m.`total_cost`
                                ELSE -m.`total_cost` END) AS `val`
                FROM `tb_stock_movement` m
                INNER JOIN `tb_product`      p ON p.`product_id`       = m.`product_id`
                LEFT  JOIN `tb_sub_category` s ON s.`sub_category_id`  = p.`sub_category_id`
                LEFT  JOIN `tb_category`     c ON c.`category_id`      = s.`category_id`
                WHERE m.`move_date` >= ? AND m.`move_date` <= ?
                  AND m.`move_type` IN ('receive', 'cancel_receive')
                GROUP BY c.`category_id`, c.`category_name`";

    /* ---------- ฝั่งเบิก : แยกหมวด x หน่วยงาน ---------- */
    $out_sql = "SELECT COALESCE(c.`category_id`, 0) AS `cid`,
                       COALESCE(c.`category_name`, '(ไม่ระบุหมวดหมู่)') AS `cname`,
                       COALESCE(h.`department_id`, 0) AS `did`,
                       COALESCE(dp.`department_name`, '(ไม่ระบุหน่วยงาน)') AS `dname`,
                       COALESCE(co.`company_name`, '') AS `company`,
                       SUM(CASE WHEN m.`move_type` = 'issue' THEN m.`total_cost`
                                ELSE -m.`total_cost` END) AS `val`
                FROM `tb_stock_movement` m
                INNER JOIN `tb_product`      p  ON p.`product_id`      = m.`product_id`
                LEFT  JOIN `tb_sub_category` s  ON s.`sub_category_id` = p.`sub_category_id`
                LEFT  JOIN `tb_category`     c  ON c.`category_id`     = s.`category_id`
                LEFT  JOIN `tb_doc_header`   h  ON h.`doc_id`          = m.`doc_id`
                LEFT  JOIN `tb_department`   dp ON dp.`department_id`  = h.`department_id`
                LEFT  JOIN `tb_company`      co ON co.`company_id`     = dp.`company_id`
                WHERE m.`move_date` >= ? AND m.`move_date` <= ?
                  AND m.`move_type` IN ('issue', 'cancel_issue')
                GROUP BY c.`category_id`, h.`department_id`";

    $buy  = cost_rows($buy_sql, $from, $to);
    $outs = cost_rows($out_sql, $from, $to);

    /* ---------- ประกอบเป็นตาราง ---------- */
    $cat  = array();      /* cid -> แถว */
    $dept = array();      /* did -> ข้อมูลหน่วยงาน */

    function cost_cat_slot(&$cat, $cid, $cname) {
        if (!isset($cat[$cid])) {
            $cat[$cid] = array('category_id' => (int)$cid, 'category_name' => $cname,
                               'buy' => 0, 'out' => 0, 'dept' => array());
        }
    }

    for ($i = 0; $i < count($buy); $i++) {
        $cid = (int)$buy[$i]['cid'];
        cost_cat_slot($cat, $cid, $buy[$i]['cname']);
        $cat[$cid]['buy'] += (float)$buy[$i]['val'];
    }

    for ($i = 0; $i < count($outs); $i++) {
        $cid = (int)$outs[$i]['cid'];
        $did = (int)$outs[$i]['did'];
        $val = (float)$outs[$i]['val'];

        /* ยกเลิกจ่ายออกอาจหักจนเหลือศูนย์พอดี ไม่ต้องกางคอลัมน์ให้ */
        if ($val == 0) { continue; }

        cost_cat_slot($cat, $cid, $outs[$i]['cname']);
        $cat[$cid]['out'] += $val;

        $dk = 'd' . $did;
        if (!isset($cat[$cid]['dept'][$dk])) { $cat[$cid]['dept'][$dk] = 0; }
        $cat[$cid]['dept'][$dk] += $val;

        if (!isset($dept[$did])) {
            $dept[$did] = array('id' => $did,
                                'name' => trim($outs[$i]['dname']) !== '' ? $outs[$i]['dname'] : '(ไม่ระบุชื่อ)',
                                'company' => $outs[$i]['company']);
        }
    }

    /* หน่วยงานเรียงตามบริษัทแล้วตามชื่อ — ชื่อหน่วยงานซ้ำข้ามบริษัทได้
       คนอ่านจึงต้องเห็นชื่อบริษัทกำกับถึงจะแยกออก */
    $departments = array_values($dept);
    usort($departments, 'cost_dept_cmp');

    /* หมวดหมู่เรียงตามรหัส ให้ลำดับคงที่ทุกเดือน */
    ksort($cat);

    return array('departments' => $departments, 'rows' => array_values($cat));
}


function cost_dept_cmp($a, $b)
{
    if ($a['company'] === '' && $b['company'] !== '') { return 1; }
    if ($b['company'] === '' && $a['company'] !== '') { return -1; }

    $c = strcmp($a['company'], $b['company']);
    if ($c !== 0) { return $c; }

    return strcmp($a['name'], $b['name']);
}


/* ============================================================
 *  รวมทุกส่วนของรายงาน + ยอดสรุปหัวกระดาษ
 * ============================================================ */
function cost_report($month)
{
    $range = cost_month_range($month);
    $from  = $range['from'];
    $to    = $range['to'];

    $by_comp = cost_by_company($from, $to);
    $by_dept = cost_by_department($from, $to);
    $by_cat  = cost_by_category($from, $to);
    $recv    = cost_receipts($from, $to);
    $detail  = cost_detail($from, $to);

    /* รวมยอดจากชุดข้อมูลเดียวกับที่ส่งไปแสดง ตัวเลขหัวกระดาษจึงตรงกับตารางเสมอ
       ไม่ยิง query นับรวมแยกอีกชุด ซึ่งเป็นทางที่ทำให้สองตัวเลขไม่ตรงกัน */
    $total_out = 0; $total_qty_out = 0;
    for ($i = 0; $i < count($by_dept); $i++) {
        $total_out     += (float)$by_dept[$i]['amount'];
        $total_qty_out += (float)$by_dept[$i]['qty'];
    }

    $total_in = 0; $total_qty_in = 0;
    for ($i = 0; $i < count($recv); $i++) {
        $total_in     += (float)$recv[$i]['amount'];
        $total_qty_in += (float)$recv[$i]['qty'];
    }

    /* มูลค่าคงเหลือ ณ ตอนนี้ — ไม่ใช่ ณ สิ้นเดือนที่เลือก
       บอกไว้ให้ชัดในหน้าจอ เพราะระบบไม่ได้เก็บภาพย้อนหลังของมูลค่าคงคลัง */
    $connect = db_connect();
    $res = mysqli_query($connect,
        "SELECT COALESCE(SUM(b.`qty` * p.`unit_cost`), 0), COALESCE(SUM(b.`qty`), 0)
         FROM `tb_stock_balance` b
         INNER JOIN `tb_product` p ON p.`product_id` = b.`product_id`");
    $stock_value = 0; $stock_qty = 0;
    if ($res) {
        $r = mysqli_fetch_row($res);
        $stock_value = (float)$r[0];
        $stock_qty   = (float)$r[1];
        mysqli_free_result($res);
    }

    return array(
        'range'   => $range,
        'byComp'  => $by_comp,
        'byCatDept' => cost_cat_dept($from, $to),
        'byDept'  => $by_dept,
        'byCat'   => $by_cat,
        'receipts'=> $recv,
        'detail'  => $detail,
        'summary' => array(
            'total_out'   => $total_out,
            'qty_out'     => $total_qty_out,
            'total_in'    => $total_in,
            'qty_in'      => $total_qty_in,
            'dept_count'  => count($by_dept),
            'comp_count'  => count($by_comp),
            'stock_value' => $stock_value,
            'stock_qty'   => $stock_qty
        )
    );
}


/* ---------- เดือนที่มีข้อมูลจริง ใช้ทำตัวเลือกในหน้าจอ ---------- */
function cost_months()
{
    $connect = db_connect();

    $res = mysqli_query($connect,
        "SELECT DISTINCT DATE_FORMAT(`move_date`, '%Y-%m') AS m
         FROM `tb_stock_movement` ORDER BY m DESC LIMIT 36");
    if (!$res) { return array(); }

    $out = array();
    while ($r = mysqli_fetch_row($res)) { $out[] = $r[0]; }
    mysqli_free_result($res);

    return $out;
}
