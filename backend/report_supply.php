<?php
/* ============================================================
 *  backend/report_supply.php — รายละเอียดการสั่งซื้อ-เบิกใช้ รายเดือน
 *
 *  รายงานหน้าเดียวที่ตอบว่า "เดือนนี้ของแต่ละตัวเป็นยังไงบ้าง"
 *  เรียงเป็นแถวละสินค้า ไล่จากซ้ายไปขวาตามลำดับเวลาจริง
 *
 *      ยกมา  +  ซื้อเข้า  -  เบิกออก (แยกเป็นคอลัมน์ตามหน่วยงาน)  =  คงเหลือ
 *
 *  ต่างจาก report_cost.php ตรงมุมมอง: ตัวนั้นสรุป "เงิน" ให้บัญชีปิดงบ
 *  ตัวนี้กาง "ของ" ให้คลังกระทบยอดทีละรายการ จึงต้องมีทั้งยอดยกมาและ
 *  ยอดคงเหลือ ซึ่งรายงานค่าใช้จ่ายไม่มี
 *
 *  ---------------------------------------------------------------
 *  ตัวเลขมาจากไหน
 *  ---------------------------------------------------------------
 *  tb_stock_movement ที่เดียว ไม่แตะ tb_stock_balance เลย
 *
 *  ยอดคงเหลือใน tb_stock_balance บอกได้แค่ "ตอนนี้" ซึ่งใช้กับรายงาน
 *  ย้อนหลังไม่ได้ ส่วน movement เก็บทุกครั้งที่ยอดขยับพร้อมราคาที่ใช้จริง
 *  ณ วันนั้น จึงย้อนกลับไปประกอบยอดของเดือนไหนก็ได้ และเปิดรายงาน
 *  เดือนเก่ากี่ครั้งก็ได้ตัวเลขเดิมเสมอ
 *
 *  ยอดยกมา = ผลรวมทุกรายการ "ก่อน" วันแรกของเดือน ไม่ใช่การนับของจริง
 *  ระบบนี้ยังไม่มีขั้นตอนตรวจนับ ตัวเลขจึงเป็นยอดตามบัญชีล้วน ๆ
 *
 *  มูลค่า: total_cost ในตารางเก็บเป็น "ขนาด" เสมอ (ไม่ติดลบ) ทิศทางดูจาก
 *  move_type จึงต้องใส่เครื่องหมายเองทุกครั้งที่รวม รับเข้ากับยกเลิกรับเข้า
 *  และจ่ายออกกับยกเลิกจ่ายออก หักกันเป็นคู่ ๆ
 *
 *  ---------------------------------------------------------------
 *  ทำไมคอลัมน์หน่วยงานถึงไม่คงที่
 *  ---------------------------------------------------------------
 *  ระบบมีหน่วยงานกว่า 60 หน่วย แต่เดือนหนึ่งมีเบิกจริงไม่กี่หน่วย
 *  ถ้ากางครบทุกหน่วยจะได้ตารางกว้างร้อยกว่าคอลัมน์ที่ว่างเปล่าเกือบหมด
 *  จึงกางเฉพาะหน่วยงานที่มีการเบิกจริงในเดือนนั้น
 *
 *  แลกมาด้วยการที่คอลัมน์ของแต่ละเดือนไม่เท่ากัน ซึ่งยอมรับได้
 *  เพราะรายงานนี้อ่านทีละเดือน ไม่ได้เอาไปวางเทียบกันข้ามเดือน
 *
 *  กติกาของชั้น backend เหมือนเดิม: ไม่ echo ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/report_cost.php';   /* ใช้ cost_month_range() / cost_months() ร่วมกัน */


/* ---------- ตัวช่วยยิง query ที่คืนหลายแถว ----------
 * เหมือน cost_rows() แต่รับพารามิเตอร์ได้อิสระ เพราะรายงานนี้มีทั้ง query
 * ที่ใช้ช่วงวันที่สองค่า และ query ยอดยกมาที่ใช้ค่าเดียว
 */
function supply_rows($sql, $types, $values)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }
    mysqli_stmt_execute($stmt);

    $meta = mysqli_stmt_result_metadata($stmt);
    $cols = array();
    $row  = array();
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


/* ---------- ทะเบียนสินค้าที่จะขึ้นเป็นแถว ----------
 * เอาเฉพาะที่ยังใช้งานอยู่ เรียงตามหมวดหมู่หลักแล้วตามรหัส
 * ให้ตรงกับใบที่คลังใช้ไล่ตรวจของทีละชั้นวาง
 *
 * ไม่ใช้ stock_list() เพราะตัวนั้นตามไปดึงรูปสินค้าให้ด้วยทุกครั้ง
 * ซึ่งรายงานนี้ไม่ได้ใช้ และเป็น query เพิ่มอีกชุดกับสินค้าสามร้อยกว่าตัว
 */
function supply_products()
{
    $sql = "SELECT p.`product_id`, p.`product_code`, p.`product_name`,
                   COALESCE(u.`unit_name`, '')            AS `unit_name`,
                   COALESCE(c.`category_id`, 0)           AS `category_id`,
                   COALESCE(c.`category_name`, '(ไม่ระบุหมวดหมู่)') AS `category_name`,
                   COALESCE(s.`sub_category_name`, '')    AS `sub_category_name`
            FROM `tb_product` p
            LEFT JOIN `tb_unit`         u ON u.`unit_id`         = p.`unit_id`
            LEFT JOIN `tb_sub_category` s ON s.`sub_category_id` = p.`sub_category_id`
            LEFT JOIN `tb_category`     c ON c.`category_id`     = s.`category_id`
            WHERE p.`is_active` = 1
            ORDER BY (c.`category_id` IS NULL), c.`category_id`, p.`product_code`";

    return supply_rows($sql, '', array());
}


/* ---------- ยอดยกมา ณ ต้นเดือน ----------
 * ผลรวมของทุกอย่างที่เกิดก่อนวินาทีแรกของเดือน
 * ใช้ < ไม่ใช่ <= เพราะรายการที่เกิดเวลา 00:00:00 ของวันที่ 1 เป็นของเดือนนี้
 */
function supply_opening($from)
{
    $sql = "SELECT m.`product_id`,
                   SUM(m.`qty_in` - m.`qty_out`) AS `qty`,
                   SUM(CASE WHEN m.`qty_in` > 0 THEN m.`total_cost`
                            ELSE -m.`total_cost` END)  AS `val`
            FROM `tb_stock_movement` m
            WHERE m.`move_date` < ?
            GROUP BY m.`product_id`";

    return supply_rows($sql, 's', array($from));
}


/* ---------- ของที่ซื้อเข้ามาในเดือน ----------
 * รับเข้าหักด้วยยกเลิกรับเข้า ของที่รับผิดแล้วยกเลิกไม่ใช่ของที่ซื้อจริง
 */
function supply_purchases($from, $to)
{
    $sql = "SELECT m.`product_id`,
                   SUM(m.`qty_in` - m.`qty_out`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'receive' THEN m.`total_cost`
                            ELSE -m.`total_cost` END)  AS `val`
            FROM `tb_stock_movement` m
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('receive', 'cancel_receive')
            GROUP BY m.`product_id`";

    return supply_rows($sql, 'ss', array($from, $to));
}


/* ---------- ของที่เบิกออกในเดือน แยกตามหน่วยงาน ----------
 * หน่วยงานอยู่ที่หัวเอกสาร ไม่ได้เก็บซ้ำใน movement จึง join กลับไปเอา
 * ใบจ่ายทุกใบมี department_id เสมอ (คัดลอกมาจากใบเบิกตอนบันทึก)
 * ที่เผื่อ 0 ไว้คือใบเก่าที่หัวเอกสารหาย ซึ่งต้องยังนับรวมในยอดเบิก
 * ไม่งั้นแถวจะไม่ลงตัว แล้วคนอ่านจะไล่หาไม่เจอว่าของหายไปไหน
 */
function supply_issues($from, $to)
{
    $sql = "SELECT m.`product_id`,
                   COALESCE(h.`department_id`, 0) AS `department_id`,
                   SUM(m.`qty_out` - m.`qty_in`) AS `qty`,
                   SUM(CASE WHEN m.`move_type` = 'issue' THEN m.`total_cost`
                            ELSE -m.`total_cost` END)  AS `val`
            FROM `tb_stock_movement` m
            LEFT JOIN `tb_doc_header` h ON h.`doc_id` = m.`doc_id`
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` IN ('issue', 'cancel_issue')
            GROUP BY m.`product_id`, h.`department_id`";

    return supply_rows($sql, 'ss', array($from, $to));
}


/* ---------- การปรับปรุงยอดในเดือน ----------
 * ยังไม่มีหน้าจอไหนสร้างรายการชนิดนี้ แต่ ENUM ของตารางรองรับไว้แล้ว
 * ถ้าไม่นับรวม วันที่มีการปรับยอดจริงแถวจะไม่ลงตัว แล้วคนอ่านจะสรุปว่า
 * รายงานผิด ทั้งที่ของหายไปกับรายการที่รายงานเลือกจะไม่แสดง
 *
 * คอลัมน์คู่นี้จะโผล่บนหน้าจอเฉพาะเดือนที่มีการปรับยอดจริงเท่านั้น
 */
function supply_adjusts($from, $to)
{
    $sql = "SELECT m.`product_id`,
                   SUM(m.`qty_in` - m.`qty_out`) AS `qty`,
                   SUM(CASE WHEN m.`qty_in` > 0 THEN m.`total_cost`
                            ELSE -m.`total_cost` END)  AS `val`
            FROM `tb_stock_movement` m
            WHERE m.`move_date` >= ? AND m.`move_date` <= ?
              AND m.`move_type` = 'adjust'
            GROUP BY m.`product_id`";

    return supply_rows($sql, 'ss', array($from, $to));
}


/* ---------- ชื่อหน่วยงานทั้งหมด ----------
 * ดึงมาทั้งตาราง (หลักสิบแถว) แล้วค่อยหยิบเฉพาะที่ใช้จริง
 * ถูกกว่าการยิง query แยกรายหน่วยงาน และง่ายกว่าการต่อ IN (...) ยาว ๆ
 */
function supply_departments()
{
    $sql = "SELECT d.`department_id`, d.`department_name`,
                   COALESCE(c.`company_name`, '') AS `company_name`
            FROM `tb_department` d
            LEFT JOIN `tb_company` c ON c.`company_id` = d.`company_id`";

    $rows = supply_rows($sql, '', array());

    $out = array();
    for ($i = 0; $i < count($rows); $i++) {
        $out[(int)$rows[$i]['department_id']] = $rows[$i];
    }
    return $out;
}


/* ============================================================
 *  ประกอบรายงานทั้งใบ
 * ============================================================
 *  รวมในหน่วยความจำ ไม่รวมด้วย SQL ท่าเดียว เพราะตารางนี้เป็น
 *  "ตารางไขว้" ที่คอลัมน์ขึ้นกับข้อมูล ซึ่ง MySQL 5.0 ทำให้ไม่ได้
 *  (ไม่มี pivot และการต่อ CASE รายหน่วยงานเองต้องสร้าง SQL แบบไดนามิก)
 *
 *  สินค้าสามร้อยกว่าตัวกับรายการเดือนละหลักพัน อยู่ในวิสัยที่ PHP
 *  รวมเองได้สบาย และอ่านโค้ดออกง่ายกว่ามาก
 */
function supply_report($month)
{
    $range = cost_month_range($month);
    $from  = $range['from'] . ' 00:00:00';
    $to    = $range['to']   . ' 23:59:59';

    $products = supply_products();
    $dept_all = supply_departments();

    /* ---------- ทำดัชนีจากผลลัพธ์แต่ละชุด ---------- */
    $open = array();
    $rows = supply_opening($from);
    for ($i = 0; $i < count($rows); $i++) {
        $open[(int)$rows[$i]['product_id']] = $rows[$i];
    }

    $buy  = array();
    $rows = supply_purchases($from, $to);
    for ($i = 0; $i < count($rows); $i++) {
        $buy[(int)$rows[$i]['product_id']] = $rows[$i];
    }

    $adj  = array();
    $rows = supply_adjusts($from, $to);
    for ($i = 0; $i < count($rows); $i++) {
        $adj[(int)$rows[$i]['product_id']] = $rows[$i];
    }

    /* เบิกเป็นคู่ (สินค้า, หน่วยงาน) จึงเก็บซ้อนสองชั้น
       และจดไว้ด้วยว่าเดือนนี้มีหน่วยงานไหนเบิกบ้าง เพื่อกางเป็นคอลัมน์ */
    $out     = array();
    $out_sum = array();
    $used    = array();
    $rows    = supply_issues($from, $to);

    for ($i = 0; $i < count($rows); $i++) {
        $pid = (int)$rows[$i]['product_id'];
        $did = (int)$rows[$i]['department_id'];
        $qty = (float)$rows[$i]['qty'];
        $val = (float)$rows[$i]['val'];

        /* ยกเลิกจ่ายออกอาจหักจนเหลือศูนย์พอดี บรรทัดที่ผลรวมเป็นศูนย์ทั้ง
           จำนวนและเงิน ไม่ต้องกางคอลัมน์ให้ เพราะสุดท้ายไม่มีอะไรเกิดขึ้น */
        if ($qty == 0 && $val == 0) { continue; }

        if (!isset($out[$pid])) { $out[$pid] = array(); }
        $out[$pid][$did] = array('qty' => $qty, 'val' => $val);

        if (!isset($out_sum[$pid])) { $out_sum[$pid] = array('qty' => 0, 'val' => 0); }
        $out_sum[$pid]['qty'] += $qty;
        $out_sum[$pid]['val'] += $val;

        $used[$did] = true;
    }

    /* ---------- คอลัมน์หน่วยงาน เรียงตามบริษัทแล้วตามชื่อ ----------
       คนอ่านหาหน่วยงานของตัวเองจากชื่อบริษัทก่อนเสมอ เพราะชื่อหน่วยงาน
       ซ้ำกันข้ามบริษัทได้ (มี "บัญชี" กับ "จัดซื้อ" อยู่หลายบริษัท) */
    $departments = array();
    foreach ($used as $did => $yes) {
        if ($did > 0 && isset($dept_all[$did])) {
            $departments[] = array(
                'id'      => $did,
                'name'    => trim($dept_all[$did]['department_name']) !== ''
                             ? $dept_all[$did]['department_name'] : '(ไม่ระบุชื่อ)',
                'company' => $dept_all[$did]['company_name']
            );
        } else {
            $departments[] = array('id' => $did, 'name' => '(ไม่ระบุหน่วยงาน)', 'company' => '');
        }
    }
    usort($departments, 'supply_dept_cmp');

    /* ---------- ประกอบทีละแถว ---------- */
    $list    = array();
    $has_adj = false;
    $sum     = array(
        'open_qty'  => 0, 'open_val'  => 0, 'buy_qty' => 0, 'buy_val' => 0,
        'adj_qty'   => 0, 'adj_val'   => 0, 'out_qty' => 0, 'out_val' => 0,
        'close_qty' => 0, 'close_val' => 0, 'moved'   => 0
    );

    for ($i = 0; $i < count($products); $i++) {

        $p   = $products[$i];
        $pid = (int)$p['product_id'];

        $open_qty = isset($open[$pid]) ? (float)$open[$pid]['qty'] : 0;
        $open_val = isset($open[$pid]) ? (float)$open[$pid]['val'] : 0;
        $buy_qty  = isset($buy[$pid])  ? (float)$buy[$pid]['qty']  : 0;
        $buy_val  = isset($buy[$pid])  ? (float)$buy[$pid]['val']  : 0;
        $adj_qty  = isset($adj[$pid])  ? (float)$adj[$pid]['qty']  : 0;
        $adj_val  = isset($adj[$pid])  ? (float)$adj[$pid]['val']  : 0;
        $out_qty  = isset($out_sum[$pid]) ? $out_sum[$pid]['qty'] : 0;
        $out_val  = isset($out_sum[$pid]) ? $out_sum[$pid]['val'] : 0;

        if ($adj_qty != 0 || $adj_val != 0) { $has_adj = true; }

        /* คงเหลือปลายเดือนคำนวณจากบรรทัดเดียวกับที่แสดง ไม่ได้อ่านจาก
           tb_stock_balance ตัวเลขที่ตาเห็นจึงบวกลบกันลงตัวเสมอ
           ถ้าไปอ่านยอดปัจจุบันมาแปะ เดือนเก่าจะไม่มีทางลงตัว */
        $close_qty = $open_qty + $buy_qty + $adj_qty - $out_qty;
        $close_val = $open_val + $buy_val + $adj_val - $out_val;

        /* ต่อคอลัมน์หน่วยงานด้วยกุญแจแบบ d<id> ไม่ใช่ตัวเลขล้วน เพราะ
           json_encode จะพลิก array ที่กุญแจเป็น 0,1,2 เรียงกันให้กลายเป็น
           array แทน object แล้วฝั่งหน้าจอจะอ่านไม่ตรงช่อง */
        $cells = array();
        if (isset($out[$pid])) {
            foreach ($out[$pid] as $did => $cell) {
                $cells['d' . $did] = array('qty' => $cell['qty'], 'val' => $cell['val']);
            }
        }

        $moved = ($buy_qty != 0 || $out_qty != 0 || $adj_qty != 0);

        $list[] = array(
            'product_id'    => $pid,
            'product_code'  => $p['product_code'],
            'product_name'  => $p['product_name'],
            'unit_name'     => $p['unit_name'],
            'category_id'   => (int)$p['category_id'],
            'category_name' => $p['category_name'],
            'open_qty'  => $open_qty,  'open_val'  => $open_val,
            'buy_qty'   => $buy_qty,   'buy_val'   => $buy_val,
            'adj_qty'   => $adj_qty,   'adj_val'   => $adj_val,
            'dept'      => $cells,
            'out_qty'   => $out_qty,   'out_val'   => $out_val,
            'close_qty' => $close_qty, 'close_val' => $close_val,
            'moved'     => $moved
        );

        $sum['open_qty']  += $open_qty;  $sum['open_val']  += $open_val;
        $sum['buy_qty']   += $buy_qty;   $sum['buy_val']   += $buy_val;
        $sum['adj_qty']   += $adj_qty;   $sum['adj_val']   += $adj_val;
        $sum['out_qty']   += $out_qty;   $sum['out_val']   += $out_val;
        $sum['close_qty'] += $close_qty; $sum['close_val'] += $close_val;
        if ($moved) { $sum['moved']++; }
    }

    /* วันสุดท้ายของเดือนก่อน = วันที่ของยอดยกมา ใช้พาดหัวคอลัมน์แรก
       ให้คนอ่านรู้ว่ายอดนั้นเป็นยอด ณ วันไหน ไม่ต้องเดาเอง */
    $range['prev_end'] = date('Y-m-d', strtotime($range['from'] . ' -1 day'));

    return array(
        'range'       => $range,
        'departments' => $departments,
        'hasAdjust'   => $has_adj,
        'rows'        => $list,
        'summary'     => $sum
    );
}


/* เรียงคอลัมน์หน่วยงาน: บริษัทก่อน แล้วชื่อหน่วยงาน
   "ไม่ระบุหน่วยงาน" (บริษัทว่าง) ไปอยู่ท้ายสุด ไม่ใช่หัวแถว
   เพราะเป็นข้อมูลตกหล่น ไม่ควรมาบังคอลัมน์ที่คนตั้งใจอ่าน */
function supply_dept_cmp($a, $b)
{
    if ($a['company'] === '' && $b['company'] !== '') { return 1; }
    if ($b['company'] === '' && $a['company'] !== '') { return -1; }

    $c = strcmp($a['company'], $b['company']);
    if ($c !== 0) { return $c; }

    return strcmp($a['name'], $b['name']);
}
