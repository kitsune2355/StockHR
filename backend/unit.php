<?php
/* ============================================================
 *  backend/unit.php — ทะเบียนหน่วยนับ (tb_unit)
 *
 *  ---------------------------------------------------------------
 *  ทำไมเพิ่งมามีไฟล์นี้
 *  ---------------------------------------------------------------
 *  ระบบใช้หน่วยนับมาตั้งแต่แรก (28 หน่วย มีสินค้าผูกอยู่ครบ 313 รายการ)
 *  แต่ไม่เคยมีหน้าจอหรือ API ไหนแก้ได้เลย ต้องเข้าไปแก้ที่ฐานข้อมูลตรง ๆ
 *  ต่างจากหมวดหมู่ที่มีหน้าให้จัดการตั้งแต่ต้น
 *
 *  ผลคือพิมพ์ชื่อหน่วยผิดแล้วแก้ไม่ได้ และเพิ่มหน่วยใหม่ได้ทางเดียวคือ
 *  พิมพ์ชื่อที่ยังไม่มีลงในช่องหน่วยของหน้าทะเบียนสินค้า (ดู unit_ensure)
 *  ซึ่งสร้างหน่วยซ้ำที่สะกดต่างกันนิดเดียวได้ง่ายมาก เช่น "ชิ้น" กับ "ชิ้น "
 *
 *  ---------------------------------------------------------------
 *  ลบไม่ได้ถ้ายังมีสินค้าใช้อยู่
 *  ---------------------------------------------------------------
 *  ตรวจก่อนลบเองที่นี่ ไม่รอให้ foreign key ปฏิเสธ
 *  เพราะข้อความจากฐานข้อมูลบอกแค่ว่าติด constraint ไหน
 *  ส่วนคนที่กดลบอยากรู้ว่า "มีสินค้ากี่ตัวใช้หน่วยนี้อยู่"
 *
 *  กติกาของชั้น backend เหมือนไฟล์อื่น: ไม่ echo ไม่อ่าน session
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('UNIT_NAME_MAX', 50);


/* ---------- รายการหน่วยนับพร้อมจำนวนสินค้าที่ใช้อยู่ ----------
 * ส่งจำนวนสินค้ามาด้วยเสมอ เพราะเป็นตัวเลขที่ตัดสินใจได้ทันทีว่า
 * หน่วยไหนลบได้ หน่วยไหนแตะไม่ได้ และหน่วยไหนสร้างมาแล้วไม่มีใครใช้
 */
function unit_list($options = array())
{
    $connect = db_connect();

    $where = array();
    $search = isset($options['search']) ? trim($options['search']) : '';
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "u.`unit_name` LIKE ?";
        $types   .= 's';
        $values[] = '%' . $search . '%';
    }

    if (isset($options['is_active']) && $options['is_active'] !== null && $options['is_active'] !== '') {
        $where[]  = "u.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$options['is_active'];
    }

    $sql = "SELECT u.`unit_id`, u.`unit_name`, u.`is_active`,
                   (SELECT COUNT(*) FROM `tb_product` p WHERE p.`unit_id` = u.`unit_id`) AS `used`
            FROM `tb_unit` u";

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY u.`unit_name` ASC";

    $stmt = @mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $active, $used);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'unit_id'   => (int)$id,
            'unit_name' => $name,
            'is_active' => (int)$active,
            'used'      => (int)$used
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


function unit_find($unit_id)
{
    $connect = db_connect();
    $unit_id = (int)$unit_id;

    $stmt = @mysqli_prepare($connect,
        "SELECT `unit_id`, `unit_name`, `is_active` FROM `tb_unit` WHERE `unit_id` = ? LIMIT 1");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 'i', $unit_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $active);

    $out = null;
    if (mysqli_stmt_fetch($stmt)) {
        $out = array('unit_id' => (int)$id, 'unit_name' => $name, 'is_active' => (int)$active);
    }
    mysqli_stmt_close($stmt);

    return $out;
}


/* ---------- ตรวจก่อนบันทึก ----------
 * ตัดช่องว่างหัวท้ายและยุบช่องว่างซ้อนให้เหลือช่องเดียว
 * เพราะ "ชิ้น" กับ "ชิ้น " เป็นคนละแถวในฐานข้อมูล แต่คนอ่านเห็นเหมือนกัน
 * แล้วจะได้หน่วยซ้ำที่ไม่มีใครสังเกตจนกว่าจะไปเจอในรายงาน
 */
function unit_validate($data)
{
    $name = isset($data['unit_name']) ? trim($data['unit_name']) : '';
    $name = preg_replace('/\s+/u', ' ', $name);

    if ($name === '') {
        return array('ok' => false, 'message' => 'กรุณากรอกชื่อหน่วยนับ');
    }
    if (db_text_length($name) > UNIT_NAME_MAX) {
        return array('ok' => false, 'message' => 'ชื่อหน่วยนับยาวเกิน ' . UNIT_NAME_MAX . ' ตัวอักษร');
    }

    return array('ok' => true, 'unit_name' => $name);
}


function unit_create($data, $actor)
{
    $check = unit_validate($data);
    if (!$check['ok']) { return db_fail($check['message']); }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_unit` (`unit_name`, `is_active`, `created_at`, `created_by`)
         VALUES (?, ?, NOW(), ?)");
    if (!$stmt) { return db_fail('เพิ่มหน่วยนับไม่สำเร็จ'); }

    $name   = $check['unit_name'];
    $active = (isset($data['is_active']) && (int)$data['is_active'] === 0) ? 0 : 1;

    mysqli_stmt_bind_param($stmt, 'sis', $name, $active, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        if ($errno === 1062) {
            return db_fail('มีหน่วยนับชื่อ "' . $name . '" อยู่แล้ว', $errno);
        }
        return db_fail(db_error_message($errno), $errno);
    }

    $id = mysqli_insert_id($connect);
    mysqli_stmt_close($stmt);

    return db_ok('เพิ่มหน่วยนับ "' . $name . '" เรียบร้อย', $id);
}


function unit_update($unit_id, $data, $actor)
{
    $unit_id = (int)$unit_id;
    if ($unit_id <= 0) { return db_fail('ไม่พบหน่วยนับที่ต้องการแก้ไข'); }

    $check = unit_validate($data);
    if (!$check['ok']) { return db_fail($check['message']); }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_unit` SET `unit_name` = ?, `is_active` = ?, `updated_by` = ?
         WHERE `unit_id` = ?");
    if (!$stmt) { return db_fail('แก้ไขหน่วยนับไม่สำเร็จ'); }

    $name   = $check['unit_name'];
    $active = (isset($data['is_active']) && (int)$data['is_active'] === 0) ? 0 : 1;

    mysqli_stmt_bind_param($stmt, 'sisi', $name, $active, $actor, $unit_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        if ($errno === 1062) {
            return db_fail('มีหน่วยนับชื่อ "' . $name . '" อยู่แล้ว', $errno);
        }
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('แก้ไขหน่วยนับ "' . $name . '" เรียบร้อย', $unit_id);
}


function unit_save($unit_id, $data, $actor)
{
    return ((int)$unit_id > 0)
        ? unit_update($unit_id, $data, $actor)
        : unit_create($data, $actor);
}


/* ---------- ลบ ----------
 * นับสินค้าที่ผูกอยู่ก่อนเสมอ แล้วบอกจำนวนกลับไปให้คนที่กดลบ
 * เขาจะได้ตัดสินใจได้ว่าจะไปแก้หน่วยของสินค้าพวกนั้นก่อน หรือแค่ปิดใช้งานพอ
 */
function unit_delete($unit_id)
{
    $unit_id = (int)$unit_id;
    if ($unit_id <= 0) { return db_fail('ไม่พบหน่วยนับที่ต้องการลบ'); }

    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_product` WHERE `unit_id` = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $unit_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $used);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ((int)$used > 0) {
            return db_fail('ลบไม่ได้ เพราะมีสินค้า ' . (int)$used . ' รายการใช้หน่วยนี้อยู่ '
                         . 'ถ้าเลิกใช้แล้วให้ปิดใช้งานแทน สินค้าเดิมจะยังอ่านได้เหมือนเดิม');
        }
    }

    $stmt = @mysqli_prepare($connect, "DELETE FROM `tb_unit` WHERE `unit_id` = ?");
    if (!$stmt) { return db_fail('ลบหน่วยนับไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $unit_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno, 'ลบไม่ได้ เพราะยังมีข้อมูลอื่นอ้างถึงหน่วยนี้อยู่'), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ลบหน่วยนับเรียบร้อย', $unit_id);
}
