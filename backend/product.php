<?php
/* ============================================================
 *  backend/product.php — ทุกอย่างที่แตะตาราง tb_product (ทะเบียนสินค้า)
 *                        รวมหน่วยนับ tb_unit ที่สินค้าต้องใช้
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/product.php';
 *
 *      $rows = product_list(array('search' => 'กระดาษ', 'limit' => 50));
 *      $res  = product_create(array(
 *                  'product_name'    => 'ปากกาลูกลื่น',
 *                  'sub_category_id' => 4,
 *                  'unit_id'         => 9,
 *                  'unit_cost'       => 5.50
 *              ), $username);
 *
 *  ตัวเลือกใน $options ของ product_list / product_count
 *      search           ค้นจากรหัสสินค้า / รหัสเดิม / ชื่อสินค้า
 *      sub_category_id  จำกัดเฉพาะหมวดหมู่ย่อย (0 = ทั้งหมด)
 *      category_id      จำกัดเฉพาะหมวดหมู่หลัก (0 = ทั้งหมด)
 *      is_active        1 / 0 / null
 *      limit,offset     แบ่งหน้า — เฉพาะ product_list
 *
 *  เรื่องรหัสสินค้า:
 *      ไม่ให้ผู้ใช้กรอกเอง ระบบสร้างให้ในรูปแบบ <รหัสหมวดย่อย>-<เลขรัน 3 หลัก>
 *      เช่น CS-SUP-063 เพื่อให้รหัสเรียงตามหมวดและไม่ซ้ำกันเอง
 *      ส่วน old_code เก็บรหัสเดิมจาก Excel ไว้ค้นย้อน แก้ได้อิสระ
 *
 *  ไฟล์นี้ไม่ echo และไม่ redirect — คืนค่ากลับให้ผู้เรียกตัดสินใจ
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('PRODUCT_NAME_MAX',     300);
define('PRODUCT_OLD_CODE_MAX', 30);
define('UNIT_NAME_MAX',        50);


/* ============================================================
 *  หน่วยนับ (tb_unit)
 *  เล็กเกินกว่าจะแยกไฟล์ และมีที่ใช้ที่เดียวคือหน้าสินค้า
 * ============================================================ */

function unit_options($only_active = false)
{
    $connect = db_connect();

    $sql = "SELECT `unit_id`, `unit_name` FROM `tb_unit`";
    if ($only_active) {
        $sql .= " WHERE `is_active` = 1";
    }
    $sql .= " ORDER BY `unit_name` ASC";

    $result = mysqli_query($connect, $sql);
    if (!$result) {
        return array();
    }

    $rows = array();
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'unit_id'   => (int)$r['unit_id'],
            'unit_name' => $r['unit_name']
        );
    }
    return $rows;
}


/* เพิ่มหน่วยนับใหม่ถ้ายังไม่มี แล้วคืน unit_id เสมอ
 * ใช้ตอนผู้ใช้พิมพ์หน่วยที่ยังไม่มีในระบบ จะได้ไม่ต้องออกไปเพิ่มอีกหน้า */
function unit_ensure($unit_name, $actor)
{
    $connect   = db_connect();
    $unit_name = trim($unit_name);

    if ($unit_name === '') {
        return 0;
    }
    if (db_text_length($unit_name) > UNIT_NAME_MAX) {
        return 0;
    }

    $stmt = mysqli_prepare($connect, "SELECT `unit_id` FROM `tb_unit` WHERE `unit_name` = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $unit_name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $found_id);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        if ($found) {
            return (int)$found_id;
        }
    }

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_unit` SET `unit_name` = ?, `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $unit_name, $actor);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return (int)$new_id;
}


/* ============================================================
 *  สินค้า — อ่านข้อมูล
 * ============================================================ */

function product_build_filter($options)
{
    $search   = isset($options['search'])          ? trim($options['search'])           : '';
    $sub_id   = isset($options['sub_category_id']) ? (int)$options['sub_category_id']   : 0;
    $cat_id   = isset($options['category_id'])     ? (int)$options['category_id']       : 0;
    $is_active= isset($options['is_active'])       ? $options['is_active']              : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "(p.`product_code` LIKE ? OR p.`old_code` LIKE ? OR p.`product_name` LIKE ?)";
        $types   .= 'sss';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
    }

    if ($sub_id > 0) {
        $where[]  = "p.`sub_category_id` = ?";
        $types   .= 'i';
        $values[] = $sub_id;
    }

    if ($cat_id > 0) {
        $where[]  = "s.`category_id` = ?";
        $types   .= 'i';
        $values[] = $cat_id;
    }

    if ($is_active !== null && $is_active !== '') {
        $where[]  = "p.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$is_active;
    }

    return array(
        'sql'    => (count($where) > 0) ? (' WHERE ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'values' => $values
    );
}


function product_bind($stmt, $types, $values)
{
    if ($types === '') {
        return;
    }

    $args = array($stmt, $types);
    for ($i = 0; $i < count($values); $i++) {
        $args[] = &$values[$i];
    }
    call_user_func_array('mysqli_stmt_bind_param', $args);
}


function product_count($options = array())
{
    $connect = db_connect();
    $filter  = product_build_filter($options);

    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_product` p
           JOIN `tb_sub_category` s ON s.`sub_category_id` = p.`sub_category_id`" . $filter['sql']);
    if (!$stmt) {
        return 0;
    }

    product_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


function product_list($options = array())
{
    $connect = db_connect();
    $filter  = product_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT p.`product_id`, p.`product_code`, p.`old_code`, p.`product_name`,
                   p.`sub_category_id`, p.`unit_id`, p.`unit_cost`, p.`min_qty`, p.`max_qty`,
                   p.`is_active`,
                   s.`category_id`, s.`sub_category_name`, c.`category_name`, u.`unit_name`
            FROM `tb_product` p
            JOIN `tb_sub_category` s ON s.`sub_category_id` = p.`sub_category_id`
            JOIN `tb_category`     c ON c.`category_id`     = s.`category_id`
            JOIN `tb_unit`         u ON u.`unit_id`         = p.`unit_id`"
         . $filter['sql']
         . " ORDER BY p.`product_code` ASC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return array();
    }

    product_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $code, $old_code, $name, $sub_id, $unit_id,
                                   $cost, $min_qty, $max_qty, $active,
                                   $cat_id, $sub_name, $cat_name, $unit_name);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'product_id'        => (int)$id,
            'product_code'      => $code,
            'old_code'          => ($old_code === null) ? '' : $old_code,
            'product_name'      => $name,
            'sub_category_id'   => (int)$sub_id,
            'unit_id'           => (int)$unit_id,
            'unit_cost'         => (float)$cost,
            'min_qty'           => (float)$min_qty,
            'max_qty'           => (float)$max_qty,
            'is_active'         => (int)$active,
            'category_id'       => (int)$cat_id,
            'sub_category_name' => $sub_name,
            'category_name'     => $cat_name,
            'unit_name'         => $unit_name
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


function product_find($product_id)
{
    $connect    = db_connect();
    $product_id = (int)$product_id;

    $stmt = mysqli_prepare($connect,
        "SELECT `product_id`, `product_code`, `old_code`, `product_name`,
                `sub_category_id`, `unit_id`, `unit_cost`, `min_qty`, `max_qty`, `is_active`
         FROM `tb_product` WHERE `product_id` = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $code, $old_code, $name, $sub_id, $unit_id,
                                   $cost, $min_qty, $max_qty, $active);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'product_id'      => (int)$id,
        'product_code'    => $code,
        'old_code'        => ($old_code === null) ? '' : $old_code,
        'product_name'    => $name,
        'sub_category_id' => (int)$sub_id,
        'unit_id'         => (int)$unit_id,
        'unit_cost'       => (float)$cost,
        'min_qty'         => (float)$min_qty,
        'max_qty'         => (float)$max_qty,
        'is_active'       => (int)$active
    );
}


/* ---------- สร้างรหัสสินค้าถัดไปของหมวดหมู่ย่อยที่เลือก ----------
 * รูปแบบ <รหัสหมวดย่อย>-<เลขรัน 3 หลัก> เช่น CS-SUP-063
 *
 * หาเลขรันจากรหัสที่มีอยู่จริง ไม่ใช่จากจำนวนแถว
 * เพราะถ้ามีการลบสินค้าออกไป จำนวนแถวจะน้อยกว่าเลขรันสูงสุด
 * แล้วจะสร้างรหัสซ้ำกับของเดิมทันที
 */
function product_next_code($sub_category_id)
{
    $connect         = db_connect();
    $sub_category_id = (int)$sub_category_id;

    $stmt = mysqli_prepare($connect,
        "SELECT `sub_category_code` FROM `tb_sub_category` WHERE `sub_category_id` = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    mysqli_stmt_bind_param($stmt, 'i', $sub_category_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $prefix);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found || $prefix === '') {
        return '';
    }

    $like = $prefix . '-%';
    $stmt = mysqli_prepare($connect,
        "SELECT MAX(CAST(SUBSTRING(`product_code`, ?) AS UNSIGNED))
         FROM `tb_product` WHERE `product_code` LIKE ?");
    if (!$stmt) {
        return '';
    }

    $start = strlen($prefix) + 2;   // SUBSTRING ของ MySQL เริ่มนับที่ 1 และต้องข้ามขีดคั่น
    mysqli_stmt_bind_param($stmt, 'is', $start, $like);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $max_seq);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    $next = ((int)$max_seq) + 1;
    return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 * ============================================================ */
function product_validate($data)
{
    $name   = isset($data['product_name'])    ? trim($data['product_name'])  : '';
    $sub_id = isset($data['sub_category_id']) ? (int)$data['sub_category_id']: 0;
    $unit   = isset($data['unit_id'])         ? (int)$data['unit_id']        : 0;
    $cost   = isset($data['unit_cost'])       ? $data['unit_cost']           : 0;
    $min    = isset($data['min_qty'])         ? $data['min_qty']             : 0;
    $max    = isset($data['max_qty'])         ? $data['max_qty']             : 0;
    $old    = isset($data['old_code'])        ? trim($data['old_code'])      : '';

    if ($name === '') {
        return 'กรุณากรอกชื่อสินค้า';
    }
    if (db_text_length($name) > PRODUCT_NAME_MAX) {
        return 'ชื่อสินค้ายาวเกิน ' . PRODUCT_NAME_MAX . ' ตัวอักษร';
    }
    if ($sub_id <= 0) {
        return 'กรุณาเลือกหมวดหมู่ย่อย';
    }
    if ($unit <= 0) {
        return 'กรุณาเลือกหน่วยนับ';
    }
    if ($old !== '' && db_text_length($old) > PRODUCT_OLD_CODE_MAX) {
        return 'รหัสเดิมยาวเกิน ' . PRODUCT_OLD_CODE_MAX . ' ตัวอักษร';
    }
    if (!is_numeric($cost) || (float)$cost < 0) {
        return 'ต้นทุนต่อหน่วยต้องเป็นตัวเลขและไม่ติดลบ';
    }
    if (!is_numeric($min) || (float)$min < 0) {
        return 'จุดสั่งซื้อต้องเป็นตัวเลขและไม่ติดลบ';
    }
    if (!is_numeric($max) || (float)$max < 0) {
        return 'จำนวนสูงสุดต้องเป็นตัวเลขและไม่ติดลบ';
    }
    /* max = 0 แปลว่ายังไม่ได้กำหนดเพดาน จึงไม่ต้องเทียบกับ min */
    if ((float)$max > 0 && (float)$max < (float)$min) {
        return 'จำนวนสูงสุดต้องไม่น้อยกว่าจุดสั่งซื้อ';
    }
    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

function product_create($data, $actor)
{
    $error = product_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect = db_connect();
    $sub_id  = (int)$data['sub_category_id'];

    $code = product_next_code($sub_id);
    if ($code === '') {
        return db_fail('สร้างรหัสสินค้าไม่สำเร็จ กรุณาตรวจว่าเลือกหมวดหมู่ย่อยถูกต้อง');
    }

    $old       = isset($data['old_code']) ? trim($data['old_code']) : '';
    $name      = trim($data['product_name']);
    $unit_id   = (int)$data['unit_id'];
    $cost      = isset($data['unit_cost']) ? (float)$data['unit_cost'] : 0;
    $min_qty   = isset($data['min_qty'])   ? (float)$data['min_qty']   : 0;
    $max_qty   = isset($data['max_qty'])   ? (float)$data['max_qty']   : 0;
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_product`
         SET `product_code` = ?, `old_code` = ?, `product_name` = ?,
             `sub_category_id` = ?, `unit_id` = ?, `unit_cost` = ?,
             `min_qty` = ?, `max_qty` = ?, `is_active` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    /* old_code ว่าง = ไม่มีรหัสเดิม เก็บเป็น NULL ไม่ใช่สตริงว่าง
       จะได้แยกออกว่า "ไม่มี" กับ "มีแต่เป็นค่าว่าง" */
    $old_value = ($old === '') ? null : $old;

    mysqli_stmt_bind_param($stmt, 'sssiidddis',
        $code, $old_value, $name, $sub_id, $unit_id, $cost, $min_qty, $max_qty, $is_active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }

    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มสินค้า ' . $code . ' เรียบร้อย', $new_id);
}


/* แก้ไขสินค้าเดิม
 * product_code ไม่ให้แก้ เพราะเอกสารรับเข้า/เบิกออกอ้างถึงรหัสนี้
 * ถ้าย้ายหมวดหมู่ย่อย รหัสเดิมจะไม่ตรงกับหมวดใหม่ ซึ่งยอมรับได้
 * ดีกว่าเปลี่ยนรหัสแล้วเอกสารเก่าอ้างไม่ตรง */
function product_update($product_id, $data, $actor)
{
    $product_id = (int)$product_id;
    if ($product_id <= 0) {
        return db_fail('ไม่พบสินค้าที่ต้องการแก้ไข');
    }

    $error = product_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect   = db_connect();
    $old       = isset($data['old_code']) ? trim($data['old_code']) : '';
    $name      = trim($data['product_name']);
    $sub_id    = (int)$data['sub_category_id'];
    $unit_id   = (int)$data['unit_id'];
    $cost      = isset($data['unit_cost']) ? (float)$data['unit_cost'] : 0;
    $min_qty   = isset($data['min_qty'])   ? (float)$data['min_qty']   : 0;
    $max_qty   = isset($data['max_qty'])   ? (float)$data['max_qty']   : 0;
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;
    $old_value = ($old === '') ? null : $old;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_product`
         SET `old_code` = ?, `product_name` = ?, `sub_category_id` = ?, `unit_id` = ?,
             `unit_cost` = ?, `min_qty` = ?, `max_qty` = ?, `is_active` = ?, `updated_by` = ?
         WHERE `product_id` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssiidddisi',
        $old_value, $name, $sub_id, $unit_id, $cost, $min_qty, $max_qty, $is_active, $actor, $product_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขข้อมูลสินค้าเรียบร้อย', $product_id);
}


function product_save($product_id, $data, $actor)
{
    $product_id = (int)$product_id;
    if ($product_id > 0) {
        return product_update($product_id, $data, $actor);
    }
    return product_create($data, $actor);
}


function product_toggle($product_id, $actor)
{
    $connect    = db_connect();
    $product_id = (int)$product_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_product` SET `is_active` = 1 - `is_active`, `updated_by` = ?
         WHERE `product_id` = ?");
    if (!$stmt) {
        return db_fail('เปลี่ยนสถานะไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $product_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย', $product_id);
}


function product_delete($product_id)
{
    $connect    = db_connect();
    $product_id = (int)$product_id;

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_product` WHERE `product_id` = ?");
    if (!$stmt) {
        return db_fail('ลบไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'i', $product_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno,
            'ลบไม่ได้ เพราะมีเอกสารรับเข้า/เบิกออกอ้างถึงสินค้านี้อยู่ กรุณาปิดใช้งานแทน'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบสินค้าเรียบร้อย', $product_id);
}
