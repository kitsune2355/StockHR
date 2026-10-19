<?php
/* ============================================================
 *  backend/new_staff.php — ใบเบิกวัสดุสำนักงาน "พนักงานใหม่"
 *
 *  ใบพนักงานใหม่ = ใบเบิกธรรมดา (doc_type='request') + แถวใน tb_doc_new_staff
 *  เดินเส้นทางเดียวกับใบเบิกทุกขั้น จึงใช้ doc_insert / การอนุมัติ / ใบจ่าย
 *  / การเซ็นรับของเดิมทั้งหมด ไฟล์นี้ดูแลแค่ส่วนที่เพิ่มมา (ดู sql/072)
 *
 *    - ชุดของพื้นฐาน (tb_new_staff_kit) ใส่ให้ในฟอร์มอัตโนมัติ
 *      เบิกได้ทุกหน่วยงาน ไม่ติด tb_product_department เพราะเป็นนโยบายบริษัท
 *      แยกตามแผนกได้ (ดู sql/080): แผนกที่ตั้งชุดของตัวเองใช้ชุดนั้น "แทน" ชุดกลาง
 *      แผนกที่ไม่ได้ตั้งใช้ชุดกลาง (department_id = 0)
 *      ไม่รวมชุดฟอร์ม — ชุดฟอร์มมาจากเงื่อนไขสวัสดิการ (ดู backend/welfare_uniform.php, sql/082)
 *    - รายการเพิ่มเติม (วัสดุถาวร/มีมูลค่า) เลือกจากทะเบียนสินค้า
 *      ติดสิทธิ์หน่วยงานเหมือนใบเบิกธรรมดา และ "ต้องคืน" ทุกบรรทัด
 *    - ธง "ต้องคืน" มาจากฝั่งเซิร์ฟเวอร์เสมอ ไม่เชื่อค่าจากฟอร์ม
 *    - หน้าตั้งค่าชุดของ (pages/new_staff_kit.php) ใช้ new_staff_kit_admin_list / _save
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/user.php';
require_once dirname(__FILE__) . '/stock.php';
require_once dirname(__FILE__) . '/product_department.php';
require_once dirname(__FILE__) . '/product.php';
require_once dirname(__FILE__) . '/notify_events.php';
require_once dirname(__FILE__) . '/welfare_uniform.php';


/* ---------- ตารางของ sql/072 มีแล้วหรือยัง ----------
 * ถ้ายังไม่ได้รัน migration ฟังก์ชันอ่านจะคืนรายการว่าง หน้าจอจึงดูเหมือน
 * "ยังไม่มีชุดของ" แล้วไปพังตอนกดบันทึกด้วยข้อความที่ไม่บอกอะไร
 * ให้ชั้น api เช็คก่อน แล้วบอกตรง ๆ ว่าต้องรันไฟล์ไหน
 */
function new_staff_ready()
{
    $connect = db_connect();
    $res = @mysqli_query($connect, "SHOW TABLES LIKE 'tb_new_staff_kit'");
    if (!$res) { return false; }
    $ok = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $ok;
}


/* ---------- ชุดของแผนกไหนที่ใช้กับแผนกนี้ ----------
 * คืน department_id ของชุดที่ใช้จริง: แผนกนั้นเอง ถ้าตั้งชุดไว้ (มีแถวที่เปิดใช้งาน)
 * ไม่งั้น 0 = ชุดกลาง
 */
function new_staff_kit_owner($department_id)
{
    $department_id = (int)$department_id;
    if ($department_id <= 0) { return 0; }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_new_staff_kit`
         WHERE `department_id` = ? AND `is_active` = 1");
    if (!$stmt) { return 0; }
    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return ((int)$n > 0) ? $department_id : 0;
}

/* ---------- ชุดของพื้นฐานที่เปิดใช้งานของแผนกนี้ เรียงตามลำดับในฟอร์ม ----------
 * ไม่ส่งแผนก (0) = ชุดกลาง */
function new_staff_kit_list($department_id = 0)
{
    $owner   = new_staff_kit_owner($department_id);
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT k.`product_id`, k.`default_qty`, k.`must_return`, p.`sub_category_id`
         FROM `tb_new_staff_kit` k
         INNER JOIN `tb_product` p ON p.`product_id` = k.`product_id`
         WHERE k.`department_id` = ? AND k.`is_active` = 1 AND p.`is_active` = 1
         ORDER BY k.`sort_order` ASC, k.`kit_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $owner);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pid, $qty, $ret, $sub);

    /* ชุดฟอร์มมาจากเงื่อนไขสวัสดิการ (ดู backend/welfare_uniform.php) ไม่ใช่จากชุดของนี้
       แถวเก่าที่ยังเป็นสินค้าชุดฟอร์มอยู่ถูกข้ามตรงนี้ — ทั้งฟอร์มเบิกในสต็อก
       แคตตาล็อก และใบ NRQ ที่ระบบสวัสดิการส่งมา (welfare_submit) ใช้ฟังก์ชันนี้ทั้งหมด */
    $uniform = welfare_uniform_sub_categories();

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        if (isset($uniform[(int)$sub])) { continue; }
        $rows[] = array(
            'product_id'  => (int)$pid,
            'default_qty' => (float)$qty,
            'must_return' => ((int)$ret === 1)
        );
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/* product_id => must_return (bool) ใช้ตรวจตอนบันทึก */
function new_staff_kit_map($department_id = 0)
{
    $map  = array();
    $kit  = new_staff_kit_list($department_id);
    for ($i = 0; $i < count($kit); $i++) {
        $map[$kit[$i]['product_id']] = $kit[$i]['must_return'];
    }
    return $map;
}


/* ---------- สินค้าที่ให้เลือกในฟอร์ม ----------
 * = ของที่หน่วยงานนี้เบิกได้ + ของในชุดพื้นฐาน (แม้หน่วยงานจะไม่มีสิทธิ์ตามปกติ)
 * ไม่ส่งหน่วยงาน (0) = ทุกรายการที่เปิดใช้งาน
 */
function new_staff_products($department_id)
{
    $all = stock_list(array('only_active' => true));
    if ((int)$department_id <= 0) { return $all; }

    $allowed = array();
    $dept_rows = stock_list(array('only_active' => true, 'department_id' => (int)$department_id));
    for ($i = 0; $i < count($dept_rows); $i++) {
        $allowed[$dept_rows[$i]['product_id']] = true;
    }
    $kit = new_staff_kit_map($department_id);

    $out = array();
    for ($i = 0; $i < count($all); $i++) {
        $pid = $all[$i]['product_id'];
        if (isset($allowed[$pid]) || isset($kit[$pid])) { $out[] = $all[$i]; }
    }
    return $out;
}


/* ---------- หน่วยงานที่ใบนี้ใช้ได้จริง ----------
 * กติกาเดียวกับ request_save: คนทั่วไปทำได้เฉพาะในนามหน่วยงานต้นสังกัด
 * ผู้ดูแลระบบเลือกได้อิสระ (ทำแทนหน่วยงานอื่น เช่น HR ที่ได้สิทธิ์ admin)
 */
function new_staff_resolve_dept($requested, $actor, $actor_role)
{
    $me = user_find($actor);
    if (!in_array($actor_role, array('super_admin', 'admin'))
        && $me !== null && (int)$me['dept_id'] > 0) {
        return (int)$me['dept_id'];
    }
    return (int)$requested;
}


/* ---------- บันทึกใบเบิกพนักงานใหม่ ----------
 * $data = array(
 *   'doc_date', 'department_id', 'note',
 *   'employee_name', 'employee_code', 'position_name', 'start_date',
 *   'items' => array( array('product_id', 'qty', 'note', 'must_return') ) )
 * $actor = username ผู้ทำใบ (จาก session เท่านั้น)
 */
function new_staff_save($data, $actor, $actor_role = '')
{
    /* ---------- ข้อมูลพนักงานใหม่ ---------- */
    $name     = isset($data['employee_name']) ? trim($data['employee_name']) : '';
    $code     = isset($data['employee_code']) ? trim($data['employee_code']) : '';
    $position = isset($data['position_name']) ? trim($data['position_name']) : '';
    $start    = isset($data['start_date'])    ? trim($data['start_date'])    : '';

    if ($name === '') {
        return db_fail('กรุณากรอกชื่อ-นามสกุลพนักงานใหม่');
    }
    if (db_text_length($name) > 150)     { return db_fail('ชื่อพนักงานยาวเกิน 150 ตัวอักษร'); }
    if (db_text_length($position) > 150) { return db_fail('ตำแหน่งยาวเกิน 150 ตัวอักษร'); }
    if (db_text_length($code) > 30)      { return db_fail('รหัสพนักงานยาวเกิน 30 ตัวอักษร'); }

    /* วันเริ่มงานลงล่วงหน้าได้ — ต่างจากวันที่เอกสาร เพราะปกติเตรียมของก่อนคนมาถึง */
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $start, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return db_fail('กรุณาระบุวันที่เริ่มงาน');
    }

    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    /* ---------- หน่วยงาน ---------- */
    $dept = new_staff_resolve_dept(isset($data['department_id']) ? $data['department_id'] : 0,
                                   $actor, $actor_role);
    if ($dept <= 0) {
        return db_fail('กรุณาเลือกหน่วยงานของพนักงานใหม่ '
                     . '(ถ้าเลือกไม่ได้ แปลว่าบัญชีของคุณยังไม่ได้ระบุหน่วยงาน '
                     . 'กรุณาแจ้งผู้ดูแลระบบ)');
    }
    $data['department_id'] = $dept;

    /* ---------- ธง "ต้องคืน" + สิทธิ์หน่วยงานรายบรรทัด ----------
     * ของในชุด: ธงมาจาก tb_new_staff_kit และไม่ตรวจสิทธิ์หน่วยงาน
     * ของเพิ่มเติม: ผู้ทำใบเลือกธงได้ (ฟอร์มติ๊กไว้ให้ก่อน) และต้องเป็นของที่หน่วยงานเบิกได้
     */
    $kit = new_staff_kit_map($dept);

    for ($i = 0; $i < count($data['items']); $i++) {
        $pid = (int)$data['items'][$i]['product_id'];

        if (isset($kit[$pid])) {
            $data['items'][$i]['must_return'] = $kit[$pid] ? 1 : 0;
            continue;
        }

        /* ของเพิ่มเติม = "วัสดุสำนักงานถาวรและมีมูลค่า" ตามฟอร์มเดิม ต้องคืนทุกชิ้น */
        $data['items'][$i]['must_return'] = 1;

        if (!product_dept_allows($pid, $dept)) {
            $p = product_find($pid);
            $label = ($p === null) ? ('รหัส ' . $pid)
                                   : ($p['product_code'] . ' ' . $p['product_name']);
            return db_fail($label . ': หน่วยงานที่เลือกไม่มีสิทธิ์เบิกสินค้ารายการนี้ '
                         . '— เอาออกจากรายการ หรือแจ้งผู้ดูแลระบบให้เพิ่มสิทธิ์ให้');
        }
    }

    $data['requester'] = $actor;

    /* ชุดเลขของตัวเอง NRQ-ปปดด-#### แยกจากใบเบิกธรรมดา (RQ) ให้แยกออกได้แค่ดูเลขที่
       ยังเป็น doc_type='request' เส้นทางอนุมัติ/จ่ายจึงเหมือนเดิมทุกอย่าง */
    $data['doc_prefix'] = 'NRQ';

    db_begin();

    $res = doc_insert('request', $data, 'waiting', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_doc_new_staff`
         SET `doc_id` = ?, `employee_name` = ?, `employee_code` = ?,
             `position_name` = ?, `start_date` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        db_rollback();
        return db_fail('เตรียมคำสั่งบันทึกข้อมูลพนักงานใหม่ไม่สำเร็จ');
    }

    $doc_id = (int)$res['id'];
    mysqli_stmt_bind_param($stmt, 'isssss', $doc_id, $name, $code, $position, $start, $actor);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* หมายเหตุใน timeline บอกชื่อพนักงานด้วย ผู้อนุมัติจะได้เห็นทันทีว่าเบิกให้ใคร */
    $history_note = 'พนักงานใหม่: ' . $name;
    if (trim($data['note']) !== '') { $history_note .= ' — ' . trim($data['note']); }
    doc_history_add($doc_id, 'submitted', $actor, $history_note);

    db_commit();

    /* หลัง commit เสมอ เหตุผลเดียวกับ request_save */
    notify_event_request_submitted($doc_id, $actor);

    $out = db_ok('ส่งใบเบิกพนักงานใหม่ ' . $res['doc_no'] . ' (' . $name . ') เรียบร้อย — รอผู้อนุมัติตรวจสอบ',
                 $doc_id);
    $out['doc_no'] = $res['doc_no'];
    return $out;
}


/* ============================================================
 *  หน้าตั้งค่าอุปกรณ์พนักงานใหม่ (ผู้ดูแลระบบ)
 * ============================================================ */

/* แถวในชุดของแผนกนี้เอง (0 = ชุดกลาง) เรียงตามลำดับ พร้อมชื่อสินค้าไว้แสดง
   ไม่ถอยไปชุดกลาง — หน้าตั้งค่าต้องเห็นว่าแผนกนี้ตั้งอะไรไว้จริง (ว่าง = ใช้ชุดกลาง)
   รวมสินค้าที่ถูกปิดใช้งานในทะเบียนด้วย จะได้เห็นและเอาออกได้ ไม่หายไปเงียบ ๆ */
function new_staff_kit_admin_list($department_id = 0)
{
    $connect       = db_connect();
    $department_id = max(0, (int)$department_id);

    $stmt = mysqli_prepare($connect,
        "SELECT k.`product_id`, k.`default_qty`, k.`must_return`,
                p.`product_code`, p.`product_name`, p.`is_active`, u.`unit_name`,
                COALESCE(b.`qty`, 0), p.`sub_category_id`
         FROM `tb_new_staff_kit` k
         INNER JOIN `tb_product` p ON p.`product_id` = k.`product_id`
         LEFT  JOIN `tb_unit`    u ON u.`unit_id`    = p.`unit_id`
         LEFT  JOIN `tb_stock_balance` b ON b.`product_id` = k.`product_id`
         WHERE k.`department_id` = ? AND k.`is_active` = 1
         ORDER BY k.`sort_order` ASC, k.`kit_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pid, $qty, $ret, $code, $name, $active, $unit, $balance, $sub);

    /* แถวที่เป็นสินค้าชุดฟอร์มยังแสดง (ติดป้าย) ให้ผู้ดูแลเห็นแล้วเอาออก — บันทึกจะไม่ยอมจนกว่าจะเอาออก */
    $uniform = welfare_uniform_sub_categories();

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'product_id'     => (int)$pid,
            'product_code'   => $code,
            'product_name'   => $name,
            'unit_name'      => ($unit === null) ? '' : $unit,
            'balance'        => (float)$balance,
            'product_active' => ((int)$active === 1),
            'is_uniform'     => isset($uniform[(int)$sub]),
            'default_qty'    => (float)$qty,
            'must_return'    => ((int)$ret === 1)
        );
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/* แผนกที่ตั้งชุดของตัวเองไว้ — array(department_id => จำนวนรายการ) ใช้ทำป้ายในช่องเลือกแผนก */
function new_staff_kit_department_counts()
{
    $connect = db_connect();
    $res = mysqli_query($connect,
        "SELECT `department_id`, COUNT(*) FROM `tb_new_staff_kit`
         WHERE `department_id` > 0 AND `is_active` = 1
         GROUP BY `department_id`");
    $out = array();
    if (!$res) { return $out; }
    while ($r = mysqli_fetch_row($res)) { $out[(int)$r[0]] = (int)$r[1]; }
    mysqli_free_result($res);
    return $out;
}


/* ---------- บันทึกชุดของทั้งชุด + หมายเหตุท้ายฟอร์ม ----------
 * $rows  = array( array('product_id', 'default_qty', 'must_return') ) เรียงตามลำดับที่ต้องการ
 * $terms = ข้อความหมายเหตุ บรรทัดละข้อ (ใช้ร่วมกันทุกแผนก)
 * $department_id = ชุดของแผนกไหน (0 = ชุดกลาง) — ส่งชุดว่างของแผนก = กลับไปใช้ชุดกลาง
 *
 * เขียนทับทั้งชุดของแผนกนั้น (ลบแล้วใส่ใหม่) แบบเดียวกับ adjust_approver_save
 * ไม่มีตารางอื่นอ้างถึงแถวในชุด ใบเบิกเก่าแช่แข็งธง "ต้องคืน" ไว้ในบรรทัดของตัวเองแล้ว
 */
function new_staff_kit_save($rows, $terms, $actor, $department_id = 0)
{
    if (!is_array($rows)) { $rows = array(); }

    $department_id = (int)$department_id;
    if ($department_id < 0) { return db_fail('แผนกไม่ถูกต้อง'); }
    if ($department_id > 0) {
        require_once dirname(__FILE__) . '/department.php';
        if (department_find($department_id) === null) { return db_fail('ไม่พบแผนกที่เลือก'); }
    }

    $seen = array();
    for ($i = 0; $i < count($rows); $i++) {
        $pid = isset($rows[$i]['product_id']) ? (int)$rows[$i]['product_id'] : 0;
        $qty = isset($rows[$i]['default_qty']) ? (float)$rows[$i]['default_qty'] : 0;

        if ($pid <= 0 || product_find($pid) === null) {
            return db_fail('ลำดับที่ ' . ($i + 1) . ': ไม่พบสินค้า');
        }
        if (welfare_uniform_is_product($pid)) {
            $p = product_find($pid);
            return db_fail('ลำดับที่ ' . ($i + 1) . ' (' . $p['product_code'] . ' ' . $p['product_name'] . '): '
                         . 'ชุดฟอร์มตั้งที่ระบบสวัสดิการ (หน้าเงื่อนไขสวัสดิการ แท็บชุดฟอร์ม) '
                         . '— เอารายการนี้ออกจากชุด');
        }
        if ($qty <= 0) {
            return db_fail('ลำดับที่ ' . ($i + 1) . ': จำนวนตั้งต้นต้องมากกว่า 0');
        }
        if (isset($seen[$pid])) {
            return db_fail('ลำดับที่ ' . ($i + 1) . ': สินค้าซ้ำกับลำดับที่ ' . $seen[$pid]);
        }
        $seen[$pid] = $i + 1;
    }

    /* เก็บบรรทัดที่มีเนื้อหาเท่านั้น บรรทัดว่างทำให้ใบพิมพ์มีเลขข้อโดดไปเปล่า ๆ */
    $lines = array();
    foreach (explode("\n", str_replace("\r", '', (string)$terms)) as $ln) {
        if (trim($ln) !== '') { $lines[] = trim($ln); }
    }
    $terms = implode("\n", $lines);
    if (db_text_length($terms) > 500) {
        return db_fail('หมายเหตุยาวเกิน 500 ตัวอักษร — ย่อข้อความให้สั้นลง');
    }

    $connect = db_connect();
    db_begin();

    if (!mysqli_query($connect,
            "DELETE FROM `tb_new_staff_kit` WHERE `department_id` = " . $department_id)) {
        db_rollback();
        return db_fail('บันทึกชุดของไม่สำเร็จ');
    }

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_new_staff_kit`
         SET `department_id` = ?, `product_id` = ?, `default_qty` = ?, `must_return` = ?,
             `sort_order` = ?, `is_active` = 1, `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { db_rollback(); return db_fail('เตรียมคำสั่งบันทึกชุดของไม่สำเร็จ'); }

    for ($i = 0; $i < count($rows); $i++) {
        $pid  = (int)$rows[$i]['product_id'];
        $qty  = (float)$rows[$i]['default_qty'];
        $ret  = !empty($rows[$i]['must_return']) ? 1 : 0;
        $sort = $i + 1;
        mysqli_stmt_bind_param($stmt, 'iidiis', $department_id, $pid, $qty, $ret, $sort, $actor);
        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            db_rollback();
            return db_fail('ลำดับที่ ' . $sort . ': ' . db_error_message($errno), $errno);
        }
    }
    mysqli_stmt_close($stmt);

    /* อยู่ใน transaction เดียวกับชุดของ ชุดกับหมายเหตุจึงเปลี่ยนพร้อมกันเสมอ
       (หมายเหตุอ้าง "รายการที่ 1-4" ซึ่งต้องตรงกับชุดที่บันทึก) */
    require_once dirname(__FILE__) . '/setting.php';
    $res = setting_set_many(array('new_staff_terms' => $terms), $actor, array('new_staff_terms'));
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    db_commit();
    if ($department_id > 0 && count($rows) === 0) {
        return db_ok('แผนกนี้กลับไปใช้ชุดกลางแล้ว', 0);
    }
    return db_ok('บันทึกอุปกรณ์พนักงานใหม่' . ($department_id > 0 ? 'ของแผนก ' : ' ')
                 . count($rows) . ' รายการเรียบร้อย', count($rows));
}
