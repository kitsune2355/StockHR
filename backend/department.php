<?php
/* ============================================================
 *  backend/department.php — ทุกอย่างที่แตะตาราง tb_department
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/department.php';
 *
 *      $total = department_count(array('company_id' => 3));
 *      $rows  = department_list(array('company_id' => 3, 'limit' => 20, 'offset' => 0));
 *      $res   = department_create(array('department_name' => 'คลังสินค้า', 'company_id' => 3), $username);
 *
 *  ตัวเลือกที่ใช้ได้ใน $options ของ department_list / department_count
 *      company_id  กรองเฉพาะบริษัทนี้ (0 = ทุกบริษัท)
 *      search      ค้นจากชื่อแผนก (ว่าง = ไม่กรอง)
 *      is_active   1 / 0 / null
 *      limit       จำนวนแถวสูงสุด (0 = ไม่จำกัด)  — เฉพาะ department_list
 *      offset      ข้ามกี่แถว                      — เฉพาะ department_list
 *
 *  หมายเหตุ: ตารางมีคอลัมน์ departmenttype_ID ติดมาจากระบบเดิม
 *  ระบบนี้ไม่ได้ใช้ จึงไม่แตะตอนเพิ่ม/แก้ แถวใหม่ได้ค่า 0 ตาม DEFAULT
 *  และแถวที่ย้ายมาจากระบบเดิมยังคงค่าเดิมไว้ครบ
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('DEPARTMENT_NAME_MAX', 200);


/* ---------- เงื่อนไข WHERE ที่ list กับ count ใช้ร่วมกัน ---------- */
function department_build_filter($options)
{
    $company_id = isset($options['company_id']) ? (int)$options['company_id'] : 0;
    $search     = isset($options['search'])     ? trim($options['search'])    : '';
    $is_active  = isset($options['is_active'])  ? $options['is_active']       : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($company_id > 0) {
        $where[]  = "d.`company_id` = ?";
        $types   .= 'i';
        $values[] = $company_id;
    }

    if ($search !== '') {
        $where[]  = "d.`department_name` LIKE ?";
        $types   .= 's';
        $values[] = '%' . $search . '%';
    }

    if ($is_active !== null && $is_active !== '') {
        $where[]  = "d.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$is_active;
    }

    return array(
        'sql'    => (count($where) > 0) ? (' WHERE ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'values' => $values
    );
}


/* PHP 5.2 ไม่มี argument unpacking และ bind_param ต้องการ reference */
function department_bind($stmt, $types, $values)
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

function department_count($options = array())
{
    $connect = db_connect();
    $filter  = department_build_filter($options);

    $sql = "SELECT COUNT(*) FROM `tb_department` d
            INNER JOIN `tb_company` c ON c.`company_id` = d.`company_id`" . $filter['sql'];

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return 0;
    }

    department_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ดึงรายการแผนก พร้อมชื่อบริษัทต้นสังกัด */
function department_list($options = array())
{
    $connect = db_connect();
    $filter  = department_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT d.`department_id`, d.`department_name`, d.`company_id`, d.`is_active`, c.`company_name`
            FROM `tb_department` d
            INNER JOIN `tb_company` c ON c.`company_id` = d.`company_id`"
         . $filter['sql']
         . " ORDER BY c.`company_name` ASC, d.`department_name` ASC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) {
        return array();
    }

    department_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $company_id, $active, $company_name);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'department_id'   => (int)$id,
            'department_name' => $name,
            'company_id'      => (int)$company_id,
            'is_active'       => (int)$active,
            'company_name'    => $company_name
        );
    }
    mysqli_stmt_close($stmt);

    return department_add_labels($rows);
}


/* ============================================================
 *  ป้ายชื่อหน่วยงานที่ใช้ในช่องเลือกทุกช่องของระบบ
 * ============================================================
 *  ชื่อหน่วยงานอย่างเดียวไม่พอ — จาก 62 หน่วยงาน มี 11 ชื่อที่ซ้ำกัน
 *  เช่น "จัดซื้อ" มีทั้งของแฮปปี้แลนด์ กรุ๊ป และของแฮปปี้แลนด์
 *  ช่องเลือกจึงมีสองบรรทัดที่เขียนเหมือนกันเป๊ะ เลือกไปแล้วไม่รู้ว่าได้อันไหน
 *
 *  ---------------------------------------------------------------
 *  ชื่อบริษัทยังแก้ไม่ครบทุกกรณี
 *  ---------------------------------------------------------------
 *  มีสามคู่ที่ชื่อซ้ำกัน "ในบริษัทเดียวกัน" ด้วย:
 *      กรรมการ/เลขา (#57, #58), ฝ่ายบริหารกิจกรรม (#75, #76),
 *      รักษาความปลอดภัย (#31, #79)   ทั้งหมดอยู่ในบริษัทเดียวกัน
 *
 *  คู่พวกนี้เติมชื่อบริษัทไปก็ยังเหมือนกันอยู่ดี จึงเติมเลขประจำหน่วยงาน
 *  ต่อท้ายให้ "เฉพาะ" กลุ่มที่ยังชนกันเท่านั้น ไม่ได้เติมให้ทุกแถว
 *  เพราะเลข id ไม่มีความหมายกับผู้ใช้ ใส่ทั่วไปจะเป็นขยะที่รบกวนสายตา
 *
 *  นี่คือการประคองให้เลือกถูก ไม่ใช่การแก้ต้นเหตุ — ต้นเหตุคือข้อมูลซ้ำ
 *  ซึ่งต้องไปรวมหรือเปลี่ยนชื่อที่หน้าจัดการหน่วยงาน
 * ============================================================ */
function department_label($row)
{
    $name    = isset($row['department_name']) ? trim($row['department_name']) : '';
    $company = isset($row['company_name'])    ? trim($row['company_name'])    : '';

    if ($company === '') { return $name; }
    return $name . ' — ' . $company;
}

function department_add_labels($rows)
{
    /* นับก่อนว่าป้ายไหนซ้ำ แล้วค่อยตัดสินว่าต้องเติมเลขให้แถวไหน
       ทำสองรอบเพราะรอบเดียวยังไม่รู้ว่าป้ายที่กำลังสร้างจะไปชนกับแถวข้างหลังไหม */
    $seen = array();
    for ($i = 0; $i < count($rows); $i++) {
        $label = department_label($rows[$i]);
        $seen[$label] = isset($seen[$label]) ? $seen[$label] + 1 : 1;
    }

    for ($i = 0; $i < count($rows); $i++) {
        $label = department_label($rows[$i]);

        $rows[$i]['company_name'] = isset($rows[$i]['company_name']) ? $rows[$i]['company_name'] : '';
        $rows[$i]['label']        = ($seen[$label] > 1)
            ? $label . ' (#' . (int)$rows[$i]['department_id'] . ')'
            : $label;

        /* ส่วนที่หน้าจอเอาไปแสดงเป็นบรรทัดรองใต้ชื่อ
           แยกไว้ให้ ไม่ต้องให้แต่ละหน้าไปตัดข้อความจาก label เอง */
        $rows[$i]['label_sub'] = $rows[$i]['company_name']
            . ($seen[$label] > 1 ? ' (#' . (int)$rows[$i]['department_id'] . ')' : '');
    }

    return $rows;
}


/* ดึงแผนกเดียวตาม id — คืน null ถ้าไม่พบ */
function department_find($department_id)
{
    $connect       = db_connect();
    $department_id = (int)$department_id;

    $stmt = mysqli_prepare($connect,
        "SELECT d.`department_id`, d.`department_name`, d.`company_id`, d.`is_active`, c.`company_name`
         FROM `tb_department` d
         INNER JOIN `tb_company` c ON c.`company_id` = d.`company_id`
         WHERE d.`department_id` = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $company_id, $active, $company_name);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return null;
    }

    return array(
        'department_id'   => (int)$id,
        'department_name' => $name,
        'company_id'      => (int)$company_id,
        'is_active'       => (int)$active,
        'company_name'    => $company_name
    );
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 * ============================================================ */
function department_validate($data)
{
    $name       = isset($data['department_name']) ? trim($data['department_name']) : '';
    $company_id = isset($data['company_id'])      ? (int)$data['company_id']       : 0;

    if ($company_id <= 0) {
        return 'กรุณาเลือกบริษัทต้นสังกัด';
    }
    if ($name === '') {
        return 'กรุณากรอกชื่อแผนก';
    }
    if (db_text_length($name) > DEPARTMENT_NAME_MAX) {
        return 'ชื่อแผนกยาวเกิน ' . DEPARTMENT_NAME_MAX . ' ตัวอักษร';
    }
    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

function department_create($data, $actor)
{
    $error = department_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect    = db_connect();
    $name       = trim($data['department_name']);
    $company_id = (int)$data['company_id'];
    $is_active  = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_department`
         SET `department_name` = ?, `company_id` = ?, `is_active` = ?,
             `create_date` = NOW(), `created_by` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'siis', $name, $company_id, $is_active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }

    $new_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มแผนกใหม่เรียบร้อย', $new_id);
}


function department_update($department_id, $data, $actor)
{
    $department_id = (int)$department_id;
    if ($department_id <= 0) {
        return db_fail('ไม่พบแผนกที่ต้องการแก้ไข');
    }

    $error = department_validate($data);
    if ($error !== '') {
        return db_fail($error);
    }

    $connect    = db_connect();
    $name       = trim($data['department_name']);
    $company_id = (int)$data['company_id'];
    $is_active  = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_department`
         SET `department_name` = ?, `company_id` = ?, `is_active` = ?, `updated_by` = ?
         WHERE `department_id` = ?");
    if (!$stmt) {
        return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'siisi', $name, $company_id, $is_active, $actor, $department_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขข้อมูลแผนกเรียบร้อย', $department_id);
}


function department_save($department_id, $data, $actor)
{
    $department_id = (int)$department_id;
    if ($department_id > 0) {
        return department_update($department_id, $data, $actor);
    }
    return department_create($data, $actor);
}


function department_toggle($department_id, $actor)
{
    $connect       = db_connect();
    $department_id = (int)$department_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_department` SET `is_active` = 1 - `is_active`, `updated_by` = ?
         WHERE `department_id` = ?");
    if (!$stmt) {
        return db_fail('เปลี่ยนสถานะไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $department_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย', $department_id);
}


function department_delete($department_id)
{
    $connect       = db_connect();
    $department_id = (int)$department_id;

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_department` WHERE `department_id` = ?");
    if (!$stmt) {
        return db_fail('ลบไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno,
            'ลบไม่ได้ เพราะยังมีข้อมูลอื่นอ้างถึงแผนกนี้อยู่'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบแผนกเรียบร้อย', $department_id);
}
