<?php
/* ============================================================
 *  backend/sub_category.php — ทุกอย่างที่แตะตาราง tb_sub_category (หมวดหมู่ย่อย)
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/sub_category.php';
 *
 *      $rows = sub_category_list(array('category_id' => 1));
 *      $res  = sub_category_create(array(
 *                  'category_id'       => 1,
 *                  'sub_category_code' => 'CS-CLN',
 *                  'sub_category_name' => 'รายการทำความสะอาด'
 *              ), $username);
 *
 *  ตัวเลือกใน $options ของ sub_category_list / sub_category_count
 *      search       ค้นจากรหัส/ชื่อหมวดย่อย หรือชื่อหมวดหมู่หลัก
 *      category_id  จำกัดเฉพาะหมวดหมู่หลักที่ระบุ (0 = ทั้งหมด)
 *      is_active    1 / 0 / null
 *      limit,offset แบ่งหน้า — เฉพาะ sub_category_list
 *
 *  ไฟล์นี้ไม่ echo และไม่ redirect — คืนค่ากลับให้ผู้เรียกตัดสินใจ
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('SUB_CATEGORY_CODE_MAX', 20);
define('SUB_CATEGORY_NAME_MAX', 150);


function sub_category_build_filter($options)
{
    $search      = isset($options['search'])      ? trim($options['search'])   : '';
    $category_id = isset($options['category_id']) ? (int)$options['category_id'] : 0;
    $is_active   = isset($options['is_active'])   ? $options['is_active']      : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "(s.`sub_category_code` LIKE ? OR s.`sub_category_name` LIKE ? OR c.`category_name` LIKE ?)";
        $types   .= 'sss';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
        $values[] = '%' . $search . '%';
    }

    if ($category_id > 0) {
        $where[]  = "s.`category_id` = ?";
        $types   .= 'i';
        $values[] = $category_id;
    }

    if ($is_active !== null && $is_active !== '') {
        $where[]  = "s.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$is_active;
    }

    return array(
        'sql'    => (count($where) > 0) ? (' WHERE ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'values' => $values
    );
}


function sub_category_bind($stmt, $types, $values)
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

function sub_category_count($options = array())
{
    $connect = db_connect();
    $filter  = sub_category_build_filter($options);

    /* ต้อง JOIN ด้วย เพราะคำค้นครอบไปถึงชื่อหมวดหมู่หลัก */
    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_sub_category` s
           JOIN `tb_category` c ON c.`category_id` = s.`category_id`" . $filter['sql']);
    if (!$stmt) {
        return 0;
    }

    sub_category_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


function sub_category_list($options = array())
{
    $connect = db_connect();
    $filter  = sub_category_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT s.`sub_category_id`, s.`category_id`, s.`sub_category_code`,
                   s.`sub_category_name`, s.`is_active`,
                   c.`category_code`, c.`category_name`,
                   (SELECT COUNT(*) FROM `tb_product` p
                     WHERE p.`sub_category_id` = s.`sub_category_id`) AS product_count
            FROM `tb_sub_category` s
            JOIN `tb_category` c ON c.`category_id` = s.`category_id`"
         . $filter['sql']
         . " ORDER BY c.`category_code` ASC, s.`sub_category_code` ASC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return array();
    }

    sub_category_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $cat_id, $code, $name, $active,
                                   $cat_code, $cat_name, $product_count);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'sub_category_id'   => (int)$id,
            'category_id'       => (int)$cat_id,
            'sub_category_code' => $code,
            'sub_category_name' => $name,
            'is_active'         => (int)$active,
            'category_code'     => $cat_code,
            'category_name'     => $cat_name,
            'product_count'     => (int)$product_count
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


function sub_category_find($sub_category_id)
{
    $connect         = db_connect();
    $sub_category_id = (int)$sub_category_id;

    $stmt = mysqli_prepare($connect,
        "SELECT `sub_category_id`, `category_id`, `sub_category_code`, `sub_category_name`, `is_active`
         FROM `tb_sub_category` WHERE `sub_category_id` = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $sub_category_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $cat_id, $code, $name, $active);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'sub_category_id'   => (int)$id,
        'category_id'       => (int)$cat_id,
        'sub_category_code' => $code,
        'sub_category_name' => $name,
        'is_active'         => (int)$active
    );
}


/* รายการย่อสำหรับ dropdown ของหน้าสินค้า
 * ใส่ชื่อหมวดหมู่หลักนำหน้าไว้ด้วย เพราะชื่อหมวดย่อยหลายอันซ้ำกัน
 * เช่น "รายการอื่นๆ เบิ้มเบิม" มีทั้งใน CS และ OF ถ้าไม่บอกหมวดแม่จะเลือกผิด */
function sub_category_options($only_active = false)
{
    $connect = db_connect();

    $sql = "SELECT s.`sub_category_id`, s.`category_id`, s.`sub_category_code`,
                   s.`sub_category_name`, c.`category_name`
            FROM `tb_sub_category` s
            JOIN `tb_category` c ON c.`category_id` = s.`category_id`";
    if ($only_active) {
        $sql .= " WHERE s.`is_active` = 1 AND c.`is_active` = 1";
    }
    $sql .= " ORDER BY c.`category_code` ASC, s.`sub_category_code` ASC";

    $result = mysqli_query($connect, $sql);
    if (!$result) {
        return array();
    }

    $rows = array();
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'sub_category_id'   => (int)$r['sub_category_id'],
            'category_id'       => (int)$r['category_id'],
            'sub_category_code' => $r['sub_category_code'],
            'sub_category_name' => $r['sub_category_name'],
            'category_name'     => $r['category_name'],
            'label'             => $r['category_name'] . ' › ' . $r['sub_category_name']
        );
    }
    return $rows;
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 * ============================================================ */
function sub_category_validate($data)
{
    $category_id = isset($data['category_id']) ? (int)$data['category_id'] : 0;
    $code        = isset($data['sub_category_code']) ? trim($data['sub_category_code']) : '';
    $name        = isset($data['sub_category_name']) ? trim($data['sub_category_name']) : '';

    if ($category_id <= 0) {
        return 'กรุณาเลือกหมวดหมู่หลัก';
    }
    if ($code === '') {
        return 'กรุณากรอกรหัสหมวดหมู่ย่อย';
    }
    if (db_text_length($code) > SUB_CATEGORY_CODE_MAX) {
        return 'รหัสหมวดหมู่ย่อยยาวเกิน ' . SUB_CATEGORY_CODE_MAX . ' ตัวอักษร';
    }
    /* รหัสนี้ถูกเอาไปประกอบเป็นรหัสสินค้า จึงต้องเป็นอักษรอังกฤษ/ตัวเลขเท่านั้น */
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $code)) {
        return 'รหัสหมวดหมู่ย่อยใช้ได้เฉพาะ A-Z, 0-9 และ - เท่านั้น';
    }
    if ($name === '') {
        return 'กรุณากรอกชื่อหมวดหมู่ย่อย';
    }
    if (db_text_length($name) > SUB_CATEGORY_NAME_MAX) {
        return 'ชื่อหมวดหมู่ย่อยยาวเกิน ' . SUB_CATEGORY_NAME_MAX . ' ตัวอักษร';
    }
    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

function sub_category_create($data, $actor)
{
    $error = sub_category_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect     = db_connect();
    $category_id = (int)$data['category_id'];
    $code        = strtoupper(trim($data['sub_category_code']));
    $name        = trim($data['sub_category_name']);
    $is_active   = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_sub_category`
         SET `category_id` = ?, `sub_category_code` = ?, `sub_category_name` = ?,
             `is_active` = ?, `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'issis', $category_id, $code, $name, $is_active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(sub_category_dup_message($errno, $code), $errno);
    }

    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มหมวดหมู่ย่อยเรียบร้อย', $new_id);
}


function sub_category_update($sub_category_id, $data, $actor)
{
    $sub_category_id = (int)$sub_category_id;
    if ($sub_category_id <= 0) {
        return db_fail('ไม่พบหมวดหมู่ย่อยที่ต้องการแก้ไข');
    }

    $error = sub_category_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect     = db_connect();
    $category_id = (int)$data['category_id'];
    $code        = strtoupper(trim($data['sub_category_code']));
    $name        = trim($data['sub_category_name']);
    $is_active   = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_sub_category`
         SET `category_id` = ?, `sub_category_code` = ?, `sub_category_name` = ?,
             `is_active` = ?, `updated_by` = ?
         WHERE `sub_category_id` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'issisi', $category_id, $code, $name, $is_active, $actor, $sub_category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(sub_category_dup_message($errno, $code), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขหมวดหมู่ย่อยเรียบร้อย', $sub_category_id);
}


function sub_category_save($sub_category_id, $data, $actor)
{
    $sub_category_id = (int)$sub_category_id;
    if ($sub_category_id > 0) {
        return sub_category_update($sub_category_id, $data, $actor);
    }
    return sub_category_create($data, $actor);
}


function sub_category_toggle($sub_category_id, $actor)
{
    $connect         = db_connect();
    $sub_category_id = (int)$sub_category_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_sub_category` SET `is_active` = 1 - `is_active`, `updated_by` = ?
         WHERE `sub_category_id` = ?");
    if (!$stmt) {
        return db_fail('เปลี่ยนสถานะไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $sub_category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย', $sub_category_id);
}


/* ลบ — ฐานข้อมูลจะปฏิเสธเองถ้ายังมีสินค้าอยู่ (FK RESTRICT) */
function sub_category_delete($sub_category_id)
{
    $connect         = db_connect();
    $sub_category_id = (int)$sub_category_id;

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_sub_category` WHERE `sub_category_id` = ?");
    if (!$stmt) {
        return db_fail('ลบไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'i', $sub_category_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno,
            'ลบไม่ได้ เพราะยังมีสินค้าอยู่ในหมวดหมู่ย่อยนี้ กรุณาย้ายสินค้าไปหมวดอื่นก่อน หรือปิดใช้งานแทน'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบหมวดหมู่ย่อยเรียบร้อย', $sub_category_id);
}


function sub_category_dup_message($errno, $code)
{
    if ($errno === 1062) {
        return 'รหัส "' . $code . '" ถูกใช้ไปแล้ว กรุณาใช้รหัสอื่น';
    }
    return db_error_message($errno);
}
