<?php
/* ============================================================
 *  backend/company.php — ทุกอย่างที่แตะตาราง tb_company
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/company.php';
 *
 *      $total = company_count(array('search' => 'แฮปปี้แลนด์'));
 *      $rows  = company_list(array('search' => 'แฮปปี้แลนด์', 'limit' => 20, 'offset' => 0));
 *      $res   = company_create(array('company_name' => 'บริษัท ก จำกัด'), $username);
 *      if (!$res['ok']) { echo $res['message']; }
 *
 *  ตัวเลือกที่ใช้ได้ใน $options ของ company_list / company_count
 *      search     ค้นจากชื่อบริษัท (ว่าง = ไม่กรอง)
 *      is_active  1 = เฉพาะที่เปิดใช้งาน, 0 = เฉพาะที่ปิด, null = ทั้งหมด
 *      limit      จำนวนแถวสูงสุด (0 = ไม่จำกัด)  — เฉพาะ company_list
 *      offset     ข้ามกี่แถว                      — เฉพาะ company_list
 *
 *  ไฟล์นี้ไม่ echo และไม่ redirect — คืนค่ากลับให้ผู้เรียกตัดสินใจ
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('COMPANY_NAME_MAX', 200);


/* ---------- ประกอบเงื่อนไข WHERE ที่ list กับ count ใช้ร่วมกัน ----------
 * คืน array('sql' => '...', 'types' => 'si', 'values' => array(...))
 * แยกออกมาเพื่อไม่ให้เงื่อนไขของสองฟังก์ชันหลุดจากกัน
 */
function company_build_filter($options)
{
    $search    = isset($options['search'])    ? trim($options['search']) : '';
    $is_active = isset($options['is_active']) ? $options['is_active']    : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "c.`company_name` LIKE ?";
        $types   .= 's';
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
function company_bind($stmt, $types, $values)
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

/* นับจำนวนบริษัททั้งหมดตามเงื่อนไข */
function company_count($options = array())
{
    $connect = db_connect();
    $filter  = company_build_filter($options);

    $stmt = mysqli_prepare($connect, "SELECT COUNT(*) FROM `tb_company` c" . $filter['sql']);
    if (!$stmt) {
        return 0;
    }

    company_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ดึงรายการบริษัท พร้อมจำนวนแผนกในแต่ละบริษัท */
function company_list($options = array())
{
    $connect = db_connect();
    $filter  = company_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT c.`company_id`, c.`company_name`, c.`logo_file`, c.`form_code`, c.`is_active`, c.`create_date`,
                   (SELECT COUNT(*) FROM `tb_department` d WHERE d.`company_id` = c.`company_id`) AS dept_count
            FROM `tb_company` c"
         . $filter['sql']
         . " ORDER BY c.`company_name` ASC";

    /* ต่อเป็นตัวเลขตรงได้ เพราะผ่าน (int) มาแล้ว ไม่ใช่ค่าดิบจากผู้ใช้ */
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return array();
    }

    company_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $logo, $form_code, $active, $created, $dept_count);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'company_id'   => (int)$id,
            'company_name' => $name,
            'logo_file'    => ($logo === null) ? '' : $logo,
            'form_code'    => ($form_code === null) ? '' : $form_code,
            'is_active'    => (int)$active,
            'create_date'  => $created,
            'dept_count'   => (int)$dept_count
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ดึงบริษัทเดียวตาม id — คืน null ถ้าไม่พบ */
function company_find($company_id)
{
    $connect    = db_connect();
    $company_id = (int)$company_id;

    $stmt = mysqli_prepare($connect,
        "SELECT `company_id`, `company_name`, `logo_file`, `form_code`, `is_active`, `create_date`
         FROM `tb_company` WHERE `company_id` = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $company_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $logo, $form_code, $active, $created);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'company_id'   => (int)$id,
        'company_name' => $name,
        'logo_file'    => ($logo === null) ? '' : $logo,
        'form_code'    => ($form_code === null) ? '' : $form_code,
        'is_active'    => (int)$active,
        'create_date'  => $created
    );
}


/* รายชื่อบริษัทแบบย่อ สำหรับ dropdown — ไม่แบ่งหน้า ไม่ติดคำค้น */
function company_options($only_active = false)
{
    $connect = db_connect();

    $sql = "SELECT `company_id`, `company_name` FROM `tb_company`";
    if ($only_active) {
        $sql .= " WHERE `is_active` = 1";
    }
    $sql .= " ORDER BY `company_name` ASC";

    $result = mysqli_query($connect, $sql);
    if (!$result) {
        return array();
    }

    $rows = array();
    while ($r = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'company_id'   => (int)$r['company_id'],
            'company_name' => $r['company_name']
        );
    }
    return $rows;
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 *  แยกออกมาเพื่อให้ทุกทางเข้า (หน้าเว็บ / API / สคริปต์นำเข้า)
 *  ใช้กติกาชุดเดียวกัน คืนข้อความผิดพลาดตัวแรกที่เจอ หรือ '' ถ้าผ่าน
 * ============================================================ */
function company_validate($data)
{
    $name = isset($data['company_name']) ? trim($data['company_name']) : '';

    if ($name === '') {
        return 'กรุณากรอกชื่อบริษัท';
    }
    if (db_text_length($name) > COMPANY_NAME_MAX) {
        return 'ชื่อบริษัทยาวเกิน ' . COMPANY_NAME_MAX . ' ตัวอักษร';
    }

    /* รหัสแบบฟอร์มไม่บังคับกรอก บริษัทที่ยังไม่มีเอกสารควบคุมก็เว้นว่างไว้ได้
       แต่ถ้ากรอก ต้องไม่ยาวเกินช่องมุมขวาล่างของกระดาษจะรับไหว */
    $form_code = isset($data['form_code']) ? trim($data['form_code']) : '';
    if (db_text_length($form_code) > 120) {
        return 'รหัสแบบฟอร์มยาวเกิน 120 ตัวอักษร';
    }

    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

/* เพิ่มบริษัทใหม่ */
function company_create($data, $actor)
{
    $error = company_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect   = db_connect();
    $name      = trim($data['company_name']);
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;
    $form_code = isset($data['form_code']) ? trim($data['form_code']) : '';

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_company`
         SET `company_name` = ?, `form_code` = ?, `is_active` = ?, `create_date` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssis', $name, $form_code, $is_active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }

    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มบริษัทใหม่เรียบร้อย', $new_id);
}


/* แก้ไขบริษัทเดิม */
function company_update($company_id, $data, $actor)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0) {
        return db_fail('ไม่พบบริษัทที่ต้องการแก้ไข');
    }

    $error = company_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect   = db_connect();
    $name      = trim($data['company_name']);
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;
    $form_code = isset($data['form_code']) ? trim($data['form_code']) : '';

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_company`
         SET `company_name` = ?, `form_code` = ?, `is_active` = ?, `updated_by` = ?
         WHERE `company_id` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssisi', $name, $form_code, $is_active, $actor, $company_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขข้อมูลบริษัทเรียบร้อย', $company_id);
}


/* บันทึก — เลือกให้เองว่าจะเพิ่มใหม่หรือแก้ของเดิม
 * ใช้กับฟอร์มที่ใช้กล่องเดียวทั้งเพิ่มและแก้ */
function company_save($company_id, $data, $actor)
{
    $company_id = (int)$company_id;
    if ($company_id > 0) {
        return company_update($company_id, $data, $actor);
    }
    return company_create($data, $actor);
}


/* สลับสถานะเปิด/ปิดใช้งาน */
function company_toggle($company_id, $actor)
{
    $connect    = db_connect();
    $company_id = (int)$company_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_company` SET `is_active` = 1 - `is_active`, `updated_by` = ?
         WHERE `company_id` = ?");
    if (!$stmt) {
        return db_fail('เปลี่ยนสถานะไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $company_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย', $company_id);
}


/* ลบบริษัท — ฐานข้อมูลจะปฏิเสธเองถ้ายังมีแผนกอยู่ (FK RESTRICT) */
function company_delete($company_id)
{
    $connect    = db_connect();
    $company_id = (int)$company_id;

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_company` WHERE `company_id` = ?");
    if (!$stmt) {
        return db_fail('ลบไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'i', $company_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno,
            'ลบไม่ได้ เพราะยังมีแผนกอยู่ในบริษัทนี้ กรุณาลบแผนกให้หมดก่อน หรือปิดใช้งานแทน'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบบริษัทเรียบร้อย', $company_id);
}
