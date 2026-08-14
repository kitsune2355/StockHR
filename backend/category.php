<?php
/* ============================================================
 *  backend/category.php — ทุกอย่างที่แตะตาราง tb_category (หมวดหมู่หลัก)
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/category.php';
 *
 *      $rows = category_list();
 *      $res  = category_create(array('category_code' => 'EL', 'category_name' => 'อุปกรณ์ไฟฟ้า'), $username);
 *      if (!$res['ok']) { echo $res['message']; }
 *
 *  ตัวเลือกใน $options ของ category_list / category_count
 *      search     ค้นจากรหัสหรือชื่อหมวดหมู่ (ว่าง = ไม่กรอง)
 *      is_active  1 = เฉพาะที่เปิดใช้งาน, 0 = เฉพาะที่ปิด, null = ทั้งหมด
 *      limit      จำนวนแถวสูงสุด (0 = ไม่จำกัด)  — เฉพาะ category_list
 *      offset     ข้ามกี่แถว                      — เฉพาะ category_list
 *
 *  ไฟล์นี้ไม่ echo และไม่ redirect — คืนค่ากลับให้ผู้เรียกตัดสินใจ
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('CATEGORY_CODE_MAX', 20);
define('CATEGORY_NAME_MAX', 150);


/* ---------- เงื่อนไข WHERE ที่ list กับ count ใช้ร่วมกัน ---------- */
function category_build_filter($options)
{
    $search    = isset($options['search'])    ? trim($options['search']) : '';
    $is_active = isset($options['is_active']) ? $options['is_active']    : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "(c.`category_code` LIKE ? OR c.`category_name` LIKE ?)";
        $types   .= 'ss';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
    }

    if ($is_active !== null && $is_active !== '') {
        $where[]  = "c.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$is_active;
    }

    return array(
        'sql'    => (count($where) > 0) ? (' WHERE ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'values' => $values
    );
}


/* ---------- ผูกค่าเข้า statement แบบไดนามิก ----------
 * PHP 5.2 ไม่มี argument unpacking จึงต้องพึ่ง call_user_func_array
 * และ mysqli_stmt_bind_param ต้องการ reference ไม่ใช่ค่า
 */
function category_bind($stmt, $types, $values)
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


/* ============================================================
 *  อ่านข้อมูล
 * ============================================================ */

function category_count($options = array())
{
    $connect = db_connect();
    $filter  = category_build_filter($options);

    $stmt = mysqli_prepare($connect, "SELECT COUNT(*) FROM `tb_category` c" . $filter['sql']);
    if (!$stmt) {
        return 0;
    }

    category_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* รายการหมวดหมู่หลัก พร้อมจำนวนหมวดหมู่ย่อยและจำนวนสินค้าในสังกัด
 * นับให้เห็นตั้งแต่ในตาราง เพราะเป็นตัวเลขที่ตัดสินใจได้ทันทีว่าลบได้ไหม */
function category_list($options = array())
{
    $connect = db_connect();
    $filter  = category_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT c.`category_id`, c.`category_code`, c.`category_name`, c.`is_active`,
                   (SELECT COUNT(*) FROM `tb_sub_category` s
                     WHERE s.`category_id` = c.`category_id`) AS sub_count,
                   (SELECT COUNT(*) FROM `tb_product` p
                      JOIN `tb_sub_category` s2 ON s2.`sub_category_id` = p.`sub_category_id`
                     WHERE s2.`category_id` = c.`category_id`) AS product_count
            FROM `tb_category` c"
         . $filter['sql']
         . " ORDER BY c.`category_code` ASC";

    /* ต่อเป็นตัวเลขตรงได้ เพราะผ่าน (int) มาแล้ว ไม่ใช่ค่าดิบจากผู้ใช้ */
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return array();
    }

    category_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $code, $name, $active, $sub_count, $product_count);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'category_id'   => (int)$id,
            'category_code' => $code,
            'category_name' => $name,
            'is_active'     => (int)$active,
            'sub_count'     => (int)$sub_count,
            'product_count' => (int)$product_count
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


function category_find($category_id)
{
    $connect     = db_connect();
    $category_id = (int)$category_id;

    $stmt = mysqli_prepare($connect,
        "SELECT `category_id`, `category_code`, `category_name`, `is_active`
         FROM `tb_category` WHERE `category_id` = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $category_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $code, $name, $active);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'category_id'   => (int)$id,
        'category_code' => $code,
        'category_name' => $name,
        'is_active'     => (int)$active
    );
}


/* รายการย่อสำหรับ dropdown */
function category_options($only_active = false)
{
    $connect = db_connect();

    $sql = "SELECT `category_id`, `category_code`, `category_name` FROM `tb_category`";
    if ($only_active) {
        $sql .= " WHERE `is_active` = 1";
    }
    $sql .= " ORDER BY `category_code` ASC";

    $result = mysqli_query($connect, $sql);
    if (!$result) {
        return array();
    }

    $rows = array();
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'category_id'   => (int)$r['category_id'],
            'category_code' => $r['category_code'],
            'category_name' => $r['category_name']
        );
    }
    return $rows;
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 * ============================================================ */
function category_validate($data)
{
    $code = isset($data['category_code']) ? trim($data['category_code']) : '';
    $name = isset($data['category_name']) ? trim($data['category_name']) : '';

    if ($code === '') {
        return 'กรุณากรอกรหัสหมวดหมู่หลัก';
    }
    if (db_text_length($code) > CATEGORY_CODE_MAX) {
        return 'รหัสหมวดหมู่หลักยาวเกิน ' . CATEGORY_CODE_MAX . ' ตัวอักษร';
    }
    /* รหัสนี้ถูกเอาไปประกอบเป็นรหัสสินค้า จึงต้องเป็นอักษรอังกฤษ/ตัวเลขเท่านั้น
       ถ้าปล่อยให้มีช่องว่างหรือภาษาไทย รหัสสินค้าที่ได้จะเอาไปค้นและพิมพ์ยาก */
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $code)) {
        return 'รหัสหมวดหมู่หลักใช้ได้เฉพาะ A-Z, 0-9 และ - เท่านั้น';
    }
    if ($name === '') {
        return 'กรุณากรอกชื่อหมวดหมู่หลัก';
    }
    if (db_text_length($name) > CATEGORY_NAME_MAX) {
        return 'ชื่อหมวดหมู่หลักยาวเกิน ' . CATEGORY_NAME_MAX . ' ตัวอักษร';
    }
    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

function category_create($data, $actor)
{
    $error = category_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect   = db_connect();
    $code      = strtoupper(trim($data['category_code']));
    $name      = trim($data['category_name']);
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_category`
         SET `category_code` = ?, `category_name` = ?, `is_active` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssis', $code, $name, $is_active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(category_dup_message($errno, $code), $errno);
    }

    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มหมวดหมู่หลักเรียบร้อย', $new_id);
}


function category_update($category_id, $data, $actor)
{
    $category_id = (int)$category_id;
    if ($category_id <= 0) {
        return db_fail('ไม่พบหมวดหมู่หลักที่ต้องการแก้ไข');
    }

    $error = category_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect   = db_connect();
    $code      = strtoupper(trim($data['category_code']));
    $name      = trim($data['category_name']);
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_category`
         SET `category_code` = ?, `category_name` = ?, `is_active` = ?, `updated_by` = ?
         WHERE `category_id` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssisi', $code, $name, $is_active, $actor, $category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(category_dup_message($errno, $code), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขหมวดหมู่หลักเรียบร้อย', $category_id);
}


function category_save($category_id, $data, $actor)
{
    $category_id = (int)$category_id;
    if ($category_id > 0) {
        return category_update($category_id, $data, $actor);
    }
    return category_create($data, $actor);
}


function category_toggle($category_id, $actor)
{
    $connect     = db_connect();
    $category_id = (int)$category_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_category` SET `is_active` = 1 - `is_active`, `updated_by` = ?
         WHERE `category_id` = ?");
    if (!$stmt) {
        return db_fail('เปลี่ยนสถานะไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย', $category_id);
}


/* ลบ — ฐานข้อมูลจะปฏิเสธเองถ้ายังมีหมวดหมู่ย่อยอยู่ (FK RESTRICT) */
function category_delete($category_id)
{
    $connect     = db_connect();
    $category_id = (int)$category_id;

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_category` WHERE `category_id` = ?");
    if (!$stmt) {
        return db_fail('ลบไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'i', $category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno,
            'ลบไม่ได้ เพราะยังมีหมวดหมู่ย่อยอยู่ในหมวดนี้ กรุณาย้ายหรือลบหมวดหมู่ย่อยก่อน หรือปิดใช้งานแทน'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบหมวดหมู่หลักเรียบร้อย', $category_id);
}


/* ข้อความเฉพาะกรณีรหัสซ้ำ — บอกไปเลยว่ารหัสไหนซ้ำ จะได้ไม่ต้องเดา */
function category_dup_message($errno, $code)
{
    if ($errno === 1062) {
        return 'รหัส "' . $code . '" ถูกใช้ไปแล้ว กรุณาใช้รหัสอื่น';
    }
    return db_error_message($errno);
}
