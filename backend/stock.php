<?php
/* ============================================================
 *  backend/stock.php — ยอดคงเหลือและประวัติความเคลื่อนไหว
 *
 *  ไฟล์นี้เป็นที่เดียวในระบบที่แก้ตัวเลขยอดคงเหลือได้
 *  ไฟล์อื่น (receive / issue / adjust) ต้องเรียกผ่าน stock_apply()
 *  จะได้มั่นใจว่าทุกครั้งที่ยอดเปลี่ยน มีแถวในประวัติคู่กันเสมอ
 *
 *  ---------------------------------------------------------------
 *  เรื่องที่ต้องระวังที่สุดของระบบสต๊อก: สองคนบันทึกพร้อมกัน
 *  ---------------------------------------------------------------
 *  ถ้าเขียนแบบ "อ่านยอด -> คำนวณ -> เขียนยอดใหม่" แล้วสองคนทำพร้อมกัน
 *  ทั้งคู่จะอ่านยอดเดิมได้ค่าเดียวกัน แล้วเขียนทับกัน ยอดจะหายไปหนึ่งรายการ
 *
 *  ที่นี่จึงบังคับสองอย่างเสมอ:
 *    1. ทุกการเปลี่ยนยอดอยู่ใน transaction เดียว (InnoDB)
 *    2. ล็อกแถวยอดคงเหลือด้วย SELECT ... FOR UPDATE ก่อนอ่านค่า
 *       คนที่สองจะถูกให้รอจนคนแรกทำเสร็จ แล้วค่อยอ่านยอดที่ถูกต้อง
 *
 *  ห้ามเรียก stock_apply() นอก transaction — ฟังก์ชันนี้ไม่เปิดเอง
 *  เพราะเอกสารหนึ่งใบมีหลายบรรทัด ต้องสำเร็จหรือล้มเหลวไปพร้อมกันทั้งใบ
 *  ผู้เรียกจึงเป็นคนคุมขอบเขต transaction เอง (ดู db_begin / db_commit)
 *
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

/* รูปสินค้า — stock_list() แนบไปให้ด้วย เพราะทั้งหน้าทะเบียนสินค้า
   และหน้าทำรายการเบิกต้องใช้ ถ้าไม่แนบมาตรงนี้ ทั้งสองหน้าจะต้องยิงถามเพิ่มอีกรอบ */
require_once dirname(__FILE__) . '/product_image.php';


/* ============================================================
 *  คุม transaction
 *  MySQL 5.0 ไม่มี mysqli_begin_transaction() (มาใน PHP 5.5)
 *  จึงสั่งผ่าน SQL ตรง ๆ ซึ่งได้ผลเหมือนกัน
 * ============================================================ */

function db_begin()
{
    $connect = db_connect();
    mysqli_query($connect, 'SET AUTOCOMMIT = 0');
    return mysqli_query($connect, 'START TRANSACTION');
}

function db_commit()
{
    $connect = db_connect();
    $ok = mysqli_query($connect, 'COMMIT');
    mysqli_query($connect, 'SET AUTOCOMMIT = 1');
    return $ok;
}

function db_rollback()
{
    $connect = db_connect();
    mysqli_query($connect, 'ROLLBACK');
    mysqli_query($connect, 'SET AUTOCOMMIT = 1');
    return true;
}


/* ============================================================
 *  อ่านยอดคงเหลือ
 * ============================================================ */

/* ยอดคงเหลือของสินค้าตัวเดียว — ไม่มีแถว = ยังไม่เคยเคลื่อนไหว = 0 */
function stock_qty($product_id)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT `qty` FROM `tb_stock_balance` WHERE `product_id` = ? LIMIT 1");
    if (!$stmt) { return 0; }

    $product_id = (int)$product_id;
    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $qty);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return $found ? (float)$qty : 0;
}


/* ---------- ยอดคงเหลือของสินค้าทุกตัว พร้อมข้อมูลทะเบียน ----------
 * ใช้ทั้งหน้าเลือกสินค้า (ต้องเห็นยอดก่อนเบิก) และรายงานยอดคงเหลือ
 *
 * $options
 *   search        ค้นจากรหัสหรือชื่อสินค้า
 *   category_id   กรองตามหมวดหมู่หลัก
 *   sub_category_id กรองตามหมวดหมู่ย่อย
 *   only_active   true = เอาเฉพาะสินค้าที่เปิดใช้งาน (ค่าเริ่มต้น)
 *   low_only      true = เอาเฉพาะที่ยอดต่ำกว่าหรือเท่าจุดสั่งซื้อ
 *   in_stock_only true = เอาเฉพาะที่ยังมีของ (ยอด > 0)
 */
function stock_list($options = array())
{
    $connect = db_connect();

    $where  = array();
    $types  = '';
    $values = array();

    $only_active = !isset($options['only_active']) || $options['only_active'];
    if ($only_active) {
        $where[] = "p.`is_active` = 1";
    }

    $search = isset($options['search']) ? trim($options['search']) : '';
    if ($search !== '') {
        $where[]  = "(p.`product_code` LIKE ? OR p.`product_name` LIKE ?)";
        $types   .= 'ss';
        $like     = '%' . $search . '%';
        $values[] = $like;
        $values[] = $like;
    }

    if (isset($options['sub_category_id']) && (int)$options['sub_category_id'] > 0) {
        $where[]  = "p.`sub_category_id` = ?";
        $types   .= 'i';
        $values[] = (int)$options['sub_category_id'];
    }

    if (isset($options['category_id']) && (int)$options['category_id'] > 0) {
        $where[]  = "s.`category_id` = ?";
        $types   .= 'i';
        $values[] = (int)$options['category_id'];
    }

    /* ยอดคงเหลือใช้ COALESCE เพราะสินค้าที่เพิ่งเพิ่มอาจยังไม่มีแถวใน balance */
    /* ต่ำกว่าจุดสั่งซื้อ ต้องมีจุดสั่งซื้อตั้งไว้ก่อน
       ถ้าไม่ตรวจ min_qty > 0 ด้วย สินค้าที่ยังไม่ได้ตั้งค่า (min_qty = 0 และยอด 0)
       จะเข้าเงื่อนไข 0 <= 0 แล้วโผล่มาทั้งทะเบียน จนรายงานไม่มีประโยชน์
       (ใช้เงื่อนไขเดียวกับ stock_low_count ที่ทำ badge บนเมนู ตัวเลขจะได้ตรงกัน) */
    if (!empty($options['low_only'])) {
        $where[] = "p.`min_qty` > 0";
        $where[] = "COALESCE(b.`qty`, 0) <= p.`min_qty`";
    }
    if (!empty($options['in_stock_only'])) {
        $where[] = "COALESCE(b.`qty`, 0) > 0";
    }

    /* ---------- จำกัดเฉพาะสินค้าที่หน่วยงานนี้เบิกได้ ----------
     * ใช้กับหน้าทำใบเบิกเท่านั้น รายงานกับหน้าทะเบียนสินค้าไม่ส่งค่านี้มา
     * เพราะคนดูรายงานต้องเห็นของทั้งคลัง ไม่ใช่เห็นเฉพาะของฝ่ายตัวเอง
     *
     * ท่อนเงื่อนไขอยู่ที่ product_dept_where() ที่เดียว จะได้ตีความกติกา
     * "ไม่ผูกกับใครเลย = ของกลาง" เหมือนกันทุกที่ที่กรอง */
    if (isset($options['department_id']) && (int)$options['department_id'] > 0) {
        require_once dirname(__FILE__) . '/product_department.php';

        $dept_where = product_dept_where($options['department_id']);
        if ($dept_where !== '') { $where[] = $dept_where; }
    }

    /* unit_cost = ราคาที่ตั้งไว้ในทะเบียนสินค้า (คอลัมน์ "ราคา" ในหน้ารายการสินค้า)
       หน้าบันทึกรับเข้าเอาไปเติมเป็นค่าตั้งต้นของช่องราคา/หน่วย แล้วผู้ใช้แก้ได้
       ก่อนหน้านี้ไม่ได้ดึงคอลัมน์นี้มา ช่องราคาจึงขึ้น "— ยังไม่ระบุ —" ทุกครั้ง
       ทั้งที่โค้ดฝั่งหน้าจอเตรียมเติมค่าไว้แล้ว */
    $sql = "SELECT p.`product_id`, p.`product_code`, p.`product_name`,
                   p.`min_qty`, p.`max_qty`, p.`is_active`, p.`unit_cost`,
                   u.`unit_name`,
                   s.`sub_category_id`, s.`sub_category_name`,
                   c.`category_id`, c.`category_name`,
                   COALESCE(b.`qty`, 0) AS `qty`
            FROM `tb_product` p
            LEFT JOIN `tb_unit`          u ON u.`unit_id`         = p.`unit_id`
            LEFT JOIN `tb_sub_category`  s ON s.`sub_category_id` = p.`sub_category_id`
            LEFT JOIN `tb_category`      c ON c.`category_id`     = s.`category_id`
            LEFT JOIN `tb_stock_balance` b ON b.`product_id`      = p.`product_id`";

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY p.`product_code` ASC";

    if (isset($options['limit']) && (int)$options['limit'] > 0) {
        $sql .= ' LIMIT ' . (int)$options['limit'];
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $product_id, $product_code, $product_name, $min_qty, $max_qty, $is_active, $unit_cost,
        $unit_name, $sub_id, $sub_name, $cat_id, $cat_name, $qty);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'product_id'        => (int)$product_id,
            'product_code'      => $product_code,
            'product_name'      => $product_name,
            'unit_name'         => ($unit_name === null) ? '' : $unit_name,
            'category_id'       => (int)$cat_id,
            'category_name'     => ($cat_name === null) ? '' : $cat_name,
            'sub_category_id'   => (int)$sub_id,
            'sub_category_name' => ($sub_name === null) ? '' : $sub_name,
            'min_qty'           => (float)$min_qty,
            'max_qty'           => (float)$max_qty,
            'unit_cost'         => (float)$unit_cost,
            'is_active'         => (int)$is_active,
            'qty'               => (float)$qty,
            'images'            => array()      /* เติมทีเดียวทั้งชุดข้างล่าง */
        );
    }
    mysqli_stmt_close($stmt);

    /* ---------- แนบรูปสินค้า ----------
     * ยิง query เพิ่มครั้งเดียวสำหรับสินค้าทั้งชุด ไม่ใช่ทีละแถว
     * หน้าเบิกโหลดสินค้าทั้งคลังมาให้เลือก ถ้าถามทีละตัวจะกลายเป็น
     * หลายร้อย query ต่อการเปิดหน้าหนึ่งครั้ง
     *
     * ไม่ทำเป็น JOIN ในคำสั่งข้างบน เพราะสินค้าหนึ่งตัวมีได้ 3 รูป
     * แถวสินค้าจะกลายเป็นสามแถว แล้วทุกที่ที่นับจำนวนสินค้าจะได้เลขผิดทันที
     */
    if (count($rows) > 0) {
        $ids = array();
        for ($i = 0; $i < count($rows); $i++) {
            $ids[] = $rows[$i]['product_id'];
        }

        $images = product_image_map($ids);
        for ($i = 0; $i < count($rows); $i++) {
            $pid = $rows[$i]['product_id'];
            if (isset($images[$pid])) { $rows[$i]['images'] = $images[$pid]; }
        }
    }

    return $rows;
}


/* จำนวนสินค้าที่ยอดต่ำกว่าหรือเท่าจุดสั่งซื้อ — ใช้ทำ badge บนเมนู */
function stock_low_count()
{
    $connect = db_connect();

    $sql = "SELECT COUNT(*)
            FROM `tb_product` p
            LEFT JOIN `tb_stock_balance` b ON b.`product_id` = p.`product_id`
            WHERE p.`is_active` = 1
              AND p.`min_qty` > 0
              AND COALESCE(b.`qty`, 0) <= p.`min_qty`";

    $res = mysqli_query($connect, $sql);
    if (!$res) { return 0; }

    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);

    return (int)$row[0];
}


/* ---------- ราคาซื้อล่าสุดของสินค้า ----------
 * ใช้ตอนตัดสต๊อกออกโดยที่ผู้เรียกไม่ได้ระบุราคามาเอง ซึ่งก็คือการเบิกใช้
 *
 * ค่านี้ถูกเขียนใหม่ทุกครั้งที่บันทึกหรือยกเลิกใบรับเข้า ให้เท่ากับใบที่ใหม่ที่สุด
 * (ดู receive_reprice ใน backend/receive.php) และแก้เองได้ที่หน้ารายการสินค้า
 * สำหรับของที่ยังไม่เคยซื้อผ่านระบบ
 *
 * ยังไม่ตั้งราคาไว้เลยจะได้ 0 ซึ่งแปลว่า "ยังไม่รู้ราคา" ไม่ใช่ "ของแจก"
 * ปล่อยเป็น 0 ตรง ๆ ดีกว่าเดาราคาให้ เพราะรายงานจะได้เห็นว่ามีของที่ยังไม่ได้
 * ตั้งราคาหลุดออกไป แล้วตามแก้ที่ต้นทางได้ (หน้าภาพรวมมีตัวนับไว้ให้แล้ว)
 */
function stock_last_price($product_id)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT `unit_cost` FROM `tb_product` WHERE `product_id` = ?");
    if (!$stmt) { return 0; }

    $pid = (int)$product_id;
    mysqli_stmt_bind_param($stmt, 'i', $pid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cost);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return $found ? (float)$cost : 0;
}


/* ============================================================
 *  เปลี่ยนยอดคงเหลือ — ทางเดียวที่ระบบยอมให้แก้ตัวเลขสต๊อก
 *
 *  $product_id  สินค้า
 *  $delta       บวก = รับเข้า, ลบ = จ่ายออก
 *  $move_type   receive | issue | cancel_receive | cancel_issue | adjust
 *  $doc         array('doc_id' => .., 'doc_no' => .., 'move_date' => 'Y-m-d H:i:s')
 *  $actor       username คนที่ทำ
 *  $note        หมายเหตุที่จะเก็บลงประวัติ
 *
 *  คืน array('ok', 'message', 'id' => move_id, 'qty' => ยอดใหม่)
 *
 *  ต้องเรียกอยู่ใน transaction ที่ผู้เรียกเปิดไว้แล้วเท่านั้น
 * ============================================================ */
function stock_apply($product_id, $delta, $move_type, $doc, $actor, $note = '', $unit_cost = null)
{
    $connect    = db_connect();
    $product_id = (int)$product_id;
    $delta      = (float)$delta;

    if ($delta == 0) {
        return db_fail('จำนวนต้องไม่เป็นศูนย์');
    }

    /* ---------- 1) ล็อกแถวยอดคงเหลือก่อนอ่าน ----------
       FOR UPDATE ทำให้คนที่สองที่ขอสินค้าตัวเดียวกันต้องรอ
       จนกว่า transaction นี้จะ COMMIT หรือ ROLLBACK
       ถ้าไม่ล็อก สองคนจะอ่านยอดเดิมค่าเดียวกันแล้วเขียนทับกัน */
    $stmt = mysqli_prepare($connect,
        "SELECT `qty` FROM `tb_stock_balance` WHERE `product_id` = ? FOR UPDATE");
    if (!$stmt) { return db_fail('อ่านยอดคงเหลือไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $current);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    /* สินค้าที่เพิ่มเข้าทะเบียนหลังจากรันไฟล์ 008 จะยังไม่มีแถว สร้างให้ตรงนี้
       INSERT จะล็อกแถวใหม่ให้เองอยู่แล้ว จึงปลอดภัยเท่ากับกรณีมีแถวอยู่ก่อน */
    if (!$found) {
        $current = 0;

        $stmt = mysqli_prepare($connect,
            "INSERT INTO `tb_stock_balance` (`product_id`, `qty`, `created_at`, `created_by`)
             VALUES (?, 0, NOW(), ?)");
        if (!$stmt) { return db_fail('สร้างยอดคงเหลือตั้งต้นไม่สำเร็จ'); }

        mysqli_stmt_bind_param($stmt, 'is', $product_id, $actor);
        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            /* 1062 = มีคนอื่นสร้างแถวนี้แทรกเข้ามาพอดี ถือว่าเริ่มจาก 0 เหมือนกัน */
            if ($errno !== 1062) {
                return db_fail(db_error_message($errno), $errno);
            }
        } else {
            mysqli_stmt_close($stmt);
        }
    }

    $current = (float)$current;
    $after   = $current + $delta;

    /* ---------- 2) ห้ามยอดติดลบ ----------
       ของจริงในคลังติดลบไม่ได้ ถ้ายอมให้ติดลบ รายงานจะเชื่อถือไม่ได้ทั้งระบบ
       ตรวจตรงนี้ ไม่ใช่ตรวจแค่ที่หน้าจอ เพราะระหว่างที่ผู้ใช้กรอกฟอร์มอยู่
       อาจมีคนอื่นเบิกของตัวเดียวกันไปก่อนแล้ว */
    if ($after < 0) {
        return db_fail('ยอดคงเหลือไม่พอ — คงเหลือ ' . stock_format($current)
                     . ' แต่ต้องการ ' . stock_format(abs($delta)));
    }

    /* ---------- 3) ราคาต่อหน่วยของรายการนี้ ----------
     * ระบบใช้ "ราคา ณ วันทำรายการ" ไม่ได้ถัวเฉลี่ยของที่กองอยู่ในคลัง
     *
     *   รับเข้า         ราคาที่ซื้อจริงของครั้งนั้น (ผู้เรียกส่งมา)
     *   ยกเลิกรับเข้า   ราคาของใบเดิม เพื่อถอนออกเท่าที่ใส่เข้าไป (ผู้เรียกส่งมา)
     *   ยกเลิกจ่ายออก   ราคาที่จ่ายออกไปตอนนั้น (ผู้เรียกส่งมา)
     *   จ่ายออก         ไม่มีใครส่งราคามา ใช้ราคาซื้อล่าสุดของสินค้าตัวนั้น ณ วินาทีนี้
     *
     * เคยคิดแบบถัวเฉลี่ยเคลื่อนที่มาก่อน แต่เลิกใช้แล้ว เพราะของตัวเดียวกัน
     * ที่ซื้อมาคนละราคาจะถูกเกลี่ยเป็นราคากลางที่ไม่ตรงกับใบเสร็จใบไหนเลย
     * (ซื้อ 7 ที่ 300 แล้วซื้อเพิ่ม 1 ที่ 100 จะได้ 275 ซึ่งอธิบายกับคนอ่านยาก)
     *
     * ราคาที่ใช้จะถูกแช่แข็งลงประวัติทันที เปิดรายงานเดือนเก่ากี่ครั้งก็ได้เท่าเดิม
     * ต่อให้ราคาซื้อล่าสุดของสินค้าขยับไปแล้วก็ตาม
     */
    $line_cost  = ($unit_cost !== null) ? (float)$unit_cost : stock_last_price($product_id);
    $line_total = abs($delta) * $line_cost;

    /* ---------- 4) เขียนยอดใหม่ ---------- */
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_stock_balance` SET `qty` = ?, `updated_by` = ?
         WHERE `product_id` = ?");
    if (!$stmt) { return db_fail('บันทึกยอดคงเหลือไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'dsi', $after, $actor, $product_id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* ---------- 4) บันทึกประวัติคู่กันเสมอ ----------
       อยู่ใน transaction เดียวกับการเขียนยอด ถ้าขั้นนี้ล้ม ยอดจะถูกย้อนไปด้วย
       จึงไม่มีทางเกิดกรณี "ยอดเปลี่ยนแต่ไม่มีประวัติ" */
    $qty_in    = ($delta > 0) ? $delta : 0;
    $qty_out   = ($delta < 0) ? -$delta : 0;
    $doc_id    = isset($doc['doc_id']) && (int)$doc['doc_id'] > 0 ? (int)$doc['doc_id'] : null;
    $doc_no    = isset($doc['doc_no']) ? $doc['doc_no'] : '';
    $move_date = isset($doc['move_date']) && $doc['move_date'] !== ''
                 ? $doc['move_date'] : date('Y-m-d H:i:s');

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_stock_movement`
         SET `product_id` = ?, `move_date` = ?, `move_type` = ?,
             `doc_id` = ?, `doc_no` = ?,
             `qty_in` = ?, `qty_out` = ?, `unit_cost` = ?, `total_cost` = ?,
             `balance_after` = ?, `note` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { return db_fail('บันทึกประวัติไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'issisdddddss',
        $product_id, $move_date, $move_type, $doc_id, $doc_no,
        $qty_in, $qty_out, $line_cost, $line_total,
        $after, $note, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    $move_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    /* คืนราคาที่ใช้จริงกลับไปด้วย ผู้เรียกเอาไปแช่แข็งไว้ในบรรทัดเอกสาร
       (ใบจ่ายต้องรู้ว่าตอนนั้นตัดที่ราคาเท่าไร ไม่ใช่ไปอ่านราคาวันที่เปิดรายงาน) */
    $res = db_ok('ปรับยอดเรียบร้อย', $move_id);
    $res['qty']        = $after;
    $res['unit_cost']  = $line_cost;
    $res['total_cost'] = $line_total;
    return $res;
}


/* ============================================================
 *  ประวัติความเคลื่อนไหว (รายงาน)
 *
 *  $options
 *    product_id  เฉพาะสินค้าตัวเดียว
 *    date_from / date_to   ช่วงวันที่ (รูปแบบ Y-m-d)
 *    move_type   receive | issue | ... (เว้นว่าง = ทุกประเภท)
 *    limit       จำกัดจำนวนแถว
 * ============================================================ */
function stock_movements($options = array())
{
    $connect = db_connect();

    $where  = array();
    $types  = '';
    $values = array();

    if (isset($options['product_id']) && (int)$options['product_id'] > 0) {
        $where[]  = "m.`product_id` = ?";
        $types   .= 'i';
        $values[] = (int)$options['product_id'];
    }

    $from = isset($options['date_from']) ? trim($options['date_from']) : '';
    if ($from !== '') {
        $where[]  = "m.`move_date` >= ?";
        $types   .= 's';
        $values[] = $from . ' 00:00:00';
    }

    $to = isset($options['date_to']) ? trim($options['date_to']) : '';
    if ($to !== '') {
        $where[]  = "m.`move_date` <= ?";
        $types   .= 's';
        $values[] = $to . ' 23:59:59';
    }

    $move_type = isset($options['move_type']) ? trim($options['move_type']) : '';
    if ($move_type !== '') {
        $where[]  = "m.`move_type` = ?";
        $types   .= 's';
        $values[] = $move_type;
    }

    $search = isset($options['search']) ? trim($options['search']) : '';
    if ($search !== '') {
        $where[]  = "(p.`product_code` LIKE ? OR p.`product_name` LIKE ? OR m.`doc_no` LIKE ?)";
        $types   .= 'sss';
        $like     = '%' . $search . '%';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
    }

    /* unit_cost / total_cost คือราคาที่ใช้จริง ณ วันทำรายการ แช่แข็งไว้แล้ว
       ไม่ได้คำนวณสดจากราคาสินค้าวันนี้ ตัวเลขของรายการเก่าจึงไม่มีวันขยับ */
    $sql = "SELECT m.`move_id`, m.`move_date`, m.`move_type`, m.`doc_no`,
                   m.`qty_in`, m.`qty_out`, m.`unit_cost`, m.`total_cost`,
                   m.`balance_after`, m.`note`, m.`created_by`,
                   p.`product_code`, p.`product_name`, u.`unit_name`,
                   d.`department_id`, dp.`department_name`
            FROM `tb_stock_movement` m
            INNER JOIN `tb_product`    p  ON p.`product_id`    = m.`product_id`
            LEFT  JOIN `tb_unit`       u  ON u.`unit_id`       = p.`unit_id`
            LEFT  JOIN `tb_doc_header` d  ON d.`doc_id`        = m.`doc_id`
            LEFT  JOIN `tb_department` dp ON dp.`department_id` = d.`department_id`";

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    /* เรียงใหม่สุดขึ้นก่อน แล้วต่อด้วย move_id เพื่อให้รายการที่เกิดในวินาทีเดียวกัน
       ยังเรียงตามลำดับที่เกิดจริงเสมอ ไม่สลับไปมาระหว่างการเปิดหน้าแต่ละครั้ง */
    $sql .= " ORDER BY m.`move_date` DESC, m.`move_id` DESC";

    $limit = isset($options['limit']) ? (int)$options['limit'] : 1000;
    if ($limit > 0) { $sql .= ' LIMIT ' . $limit; }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $move_id, $move_date, $mtype, $doc_no, $qty_in, $qty_out,
        $unit_cost, $total_cost, $balance_after,
        $note, $created_by, $product_code, $product_name, $unit_name,
        $department_id, $department_name);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'move_id'         => (int)$move_id,
            'move_date'       => $move_date,
            'move_type'       => $mtype,
            'move_type_label' => stock_move_label($mtype),
            'doc_no'          => $doc_no,
            'qty_in'          => (float)$qty_in,
            'qty_out'         => (float)$qty_out,
            'unit_cost'       => (float)$unit_cost,
            'total_cost'      => (float)$total_cost,
            'balance_after'   => (float)$balance_after,
            'note'            => $note,
            'created_by'      => ($created_by === null) ? '' : $created_by,
            'product_code'    => $product_code,
            'product_name'    => $product_name,
            'unit_name'       => ($unit_name === null) ? '' : $unit_name,
            'department_name' => ($department_name === null) ? '' : $department_name
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ---------- ชื่อประเภทความเคลื่อนไหวที่คนอ่านรู้เรื่อง ---------- */
function stock_move_types()
{
    return array(
        'receive'        => 'รับเข้า',
        'issue'          => 'จ่ายออก',
        'cancel_receive' => 'ยกเลิกรับเข้า',
        'cancel_issue'   => 'ยกเลิกจ่ายออก',
        'adjust'         => 'ปรับปรุงยอด'
    );
}

function stock_move_label($type)
{
    $labels = stock_move_types();
    return isset($labels[$type]) ? $labels[$type] : $type;
}


/* ---------- แสดงจำนวนแบบไม่มี .00 ห้อยท้ายเวลาที่เป็นจำนวนเต็ม ----------
 * ของส่วนใหญ่นับเป็นชิ้น การเห็น "5.00 กล่อง" ทุกที่อ่านยาก
 * แต่ยังต้องรองรับทศนิยมสำหรับของที่ตัดแบ่งได้ เช่น เมตร กิโลกรัม
 */
function stock_format($qty)
{
    $qty = (float)$qty;
    if ($qty == (int)$qty) {
        return number_format($qty, 0);
    }
    return rtrim(rtrim(number_format($qty, 2), '0'), '.');
}
