<?php
/* ============================================================
 *  backend/welfare_bridge.php — รับคำขอเบิกชุดฟอร์มจากระบบสวัสดิการ (hl-welfare)
 *
 *  ใบจากระบบสวัสดิการ = ใบเบิกธรรมดา (doc_type='request', ชุดเลข WRQ)
 *  + แถวใน tb_doc_welfare (ดู sql/075)
 *
 *    - ออกใบเป็น "อนุมัติแล้ว" ทันที เพราะระบบสวัสดิการตรวจสิทธิ์มาแล้ว
 *      ผู้ขอและผู้อนุมัติคือบัญชีระบบ hl-welfare ชื่อ HR ที่กดส่งเก็บใน tb_doc_welfare
 *      (ไม่ผ่าน request_decide เพราะตัวนั้นห้ามอนุมัติใบของตัวเอง)
 *    - หลังออกใบ คลังจ่าย/เซ็นรับตามเส้นทางเดิมทุกอย่าง
 *    - หนึ่งสิทธิ์ (พนักงาน + รอบ) มีใบที่มีผลได้ใบเดียว — active_ref UNIQUE
 *    - ไม่ติดสิทธิ์หน่วยงานใน tb_product_department เพราะเป็นสวัสดิการของบริษัท
 *      (เหตุผลเดียวกับชุดของพนักงานใหม่)
 *    - ไม่ต้องคืนเมื่อพ้นสภาพ (must_return = 0)
 *    - รอบ "เริ่มงาน" (START) ออกเป็น "ใบเบิกพนักงานใหม่" (ชุดเลข NRQ + แถวใน tb_doc_new_staff
 *      ดู sql/072) ใส่ชุดของพนักงานใหม่ (tb_new_staff_kit) ให้ด้วย ธง "ต้องคืน" ของของในชุด
 *      มาจากชุดของ — ใบจึงไปอยู่หน้า "ใบเบิกพนักงานใหม่" และพิมพ์พร้อมเงื่อนไขท้ายใบเหมือนทำจากหน้านั้น
 *
 *  ชั้น api/welfare.php ยืนยันตัวตนด้วยลายเซ็น HMAC ก่อนเรียกไฟล์นี้
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/stock.php';
require_once dirname(__FILE__) . '/product.php';
require_once dirname(__FILE__) . '/new_staff.php';

define('WELFARE_ACTOR', 'hl-welfare');


/* ---------- ตารางของ sql/075 มีแล้วหรือยัง ---------- */
function welfare_bridge_ready()
{
    $connect = db_connect();
    $res = @mysqli_query($connect, "SHOW TABLES LIKE 'tb_doc_welfare'");
    if (!$res) { return false; }
    $ok = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $ok;
}


/* ---------- คอลัมน์ผู้ใช้ HR ของ sql/079 มีแล้วหรือยัง ---------- */
function welfare_has_sender_columns()
{
    static $ok = null;
    if ($ok !== null) { return $ok; }

    $connect = db_connect();
    $res = @mysqli_query($connect, "SHOW COLUMNS FROM `tb_doc_welfare` LIKE 'requested_by_user'");
    $ok = ($res && mysqli_num_rows($res) > 0);
    if ($res) { mysqli_free_result($res); }
    return $ok;
}


/* ============================================================
 *  ยืนยันตัวตนของผู้เรียก
 *
 *  กุญแจลับอยู่ใน config_ctrl/welfare_key.php (ไม่ขึ้น repo — ดู .example)
 *  ลายเซ็น = hex( HMAC-SHA256( กุญแจ, "<timestamp>.<body ดิบ>" ) )
 *  timestamp ต้องห่างจากเวลาเครื่องไม่เกิน 5 นาที กันการเอาคำขอเก่ามายิงซ้ำ
 * ============================================================ */
function welfare_secret()
{
    $file = dirname(dirname(__FILE__)) . '/config_ctrl/welfare_key.php';
    if (!is_file($file)) { return ''; }

    $welfare_api_secret = '';
    include $file;
    return trim($welfare_api_secret);
}

/* PHP 5.2 ไม่มี hash_equals — เทียบทุกตัวอักษรเสมอ เวลาที่ใช้จึงไม่บอกว่าผิดตรงไหน */
function welfare_safe_equals($a, $b)
{
    if (strlen($a) !== strlen($b)) { return false; }
    $diff = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $diff |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $diff === 0;
}

/* คืน '' ถ้าผ่าน หรือข้อความบอกสาเหตุ */
function welfare_verify($timestamp, $signature, $raw_body)
{
    $secret = welfare_secret();
    if ($secret === '') {
        return 'ระบบสต็อกยังไม่ได้ตั้งกุญแจเชื่อมต่อ (config_ctrl/welfare_key.php)';
    }
    if (!preg_match('/^\d{9,11}$/', $timestamp)) {
        return 'คำขอไม่มีเวลากำกับ';
    }
    if (abs(time() - (int)$timestamp) > 300) {
        return 'คำขอหมดอายุ — นาฬิกาของสองเครื่องห่างกันเกิน 5 นาที หรือเป็นคำขอเก่า';
    }

    $expect = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);
    if (!welfare_safe_equals($expect, strtolower(trim($signature)))) {
        return 'ลายเซ็นของคำขอไม่ถูกต้อง';
    }
    return '';
}


/* ============================================================
 *  การจับคู่
 * ============================================================ */

/* หน่วยงานในสต็อกของหน่วยงานในระบบสวัสดิการ — null = ยังไม่จับคู่ */
function welfare_site_department($site_id)
{
    $connect = db_connect();
    $site_id = (int)$site_id;

    $stmt = mysqli_prepare($connect,
        "SELECT d.`department_id`, d.`department_name`, cp.`company_name`
         FROM `tb_welfare_site_map` m
         JOIN `tb_department` d ON d.`department_id` = m.`department_id` AND d.`is_active` = 1
         LEFT JOIN `tb_company` cp ON cp.`company_id` = d.`company_id`
         WHERE m.`welfare_site_id` = ? LIMIT 1");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 'i', $site_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $name, $company);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }
    return array(
        'department_id'   => (int)$id,
        'department_name' => trim($name),
        'company_name'    => ($company === null) ? '' : trim($company)
    );
}

/* หมวดย่อยที่จับคู่ไว้กับรายการ + โลโก้ (รวมแถวที่ใช้ได้ทุกบริษัท)
   คืน array( array('sub_category_id', 'sub_category_name', 'variant_label') ) */
function welfare_item_variants($item_code, $logo)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT m.`sub_category_id`, s.`sub_category_name`, m.`variant_label`
         FROM `tb_welfare_item_map` m
         JOIN `tb_sub_category` s ON s.`sub_category_id` = m.`sub_category_id`
         WHERE m.`item_code` = ? AND (m.`logo` = ? OR m.`logo` = '')
         ORDER BY m.`sort_order`, m.`map_id`");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'ss', $item_code, $logo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sub_id, $sub_name, $label);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'sub_category_id'   => (int)$sub_id,
            'sub_category_name' => $sub_name,
            'variant_label'     => ($label !== '') ? $label : $sub_name
        );
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/* หมวดย่อยทุกตัวที่จับคู่กับรายการชุดฟอร์ม — array(sub_category_id => true) */
function welfare_mapped_sub_categories()
{
    $connect = db_connect();
    $res = mysqli_query($connect, "SELECT DISTINCT `sub_category_id` FROM `tb_welfare_item_map`");
    $out = array();
    if (!$res) { return $out; }
    while ($r = mysqli_fetch_row($res)) { $out[(int)$r[0]] = true; }
    mysqli_free_result($res);
    return $out;
}

/* ไซซ์ของสินค้า อ่านจากท้ายชื่อ ("... ไซซ์ XL" / "... เอว 32" / "... เบอร์ 41")
   ทะเบียนตั้งชื่อแบบนี้ทุกตัว (ดู 074) ถ้าไม่ตรงรูปแบบ ใช้ชื่อเต็มแทน */
function welfare_size_of($product_name)
{
    if (preg_match('/(ไซซ์|เอว|เบอร์)\s*(\S+)\s*$/u', $product_name, $m)) {
        return $m[2];
    }
    return $product_name;
}

/* สินค้าที่เปิดใช้งานในหมวดย่อย พร้อมยอดคงเหลือ เรียงตามรหัส (= ลำดับไซซ์ใน 074) */
function welfare_sub_products($sub_category_id)
{
    $connect = db_connect();
    $sub_category_id = (int)$sub_category_id;

    $stmt = mysqli_prepare($connect,
        "SELECT p.`product_id`, p.`product_code`, p.`product_name`, COALESCE(b.`qty`, 0)
         FROM `tb_product` p
         LEFT JOIN `tb_stock_balance` b ON b.`product_id` = p.`product_id`
         WHERE p.`sub_category_id` = ? AND p.`is_active` = 1
         ORDER BY p.`product_code`");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $sub_category_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $code, $name, $qty);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'product_id'   => (int)$id,
            'product_code' => $code,
            'product_name' => $name,
            'size'         => welfare_size_of($name),
            'on_hand'      => (float)$qty
        );
    }
    mysqli_stmt_close($stmt);
    return $rows;
}


/* ============================================================
 *  catalog — ตัวเลือกแบบ/ไซซ์ให้หน้าส่งเบิกของระบบสวัสดิการ
 *
 *  $item_codes  รายการในสิทธิ์ เช่น array('POLO_BLACK', 'SAFETY_SHOES')
 *  $logo        โลโก้บริษัทบนเสื้อ
 *  $site_id     หน่วยงานของพนักงาน (บอกล่วงหน้าว่าจับคู่หน่วยงานแล้วหรือยัง)
 *  $event       รอบสิทธิ์ — START ได้ชุดของพนักงานใหม่ที่จะใส่ให้ในใบด้วย (kit)
 * ============================================================ */
function welfare_catalog($item_codes, $logo, $site_id, $event = '')
{
    $items = array();
    for ($i = 0; $i < count($item_codes); $i++) {
        $code = trim((string)$item_codes[$i]);
        if ($code === '') { continue; }

        $variants = welfare_item_variants($code, $logo);
        for ($v = 0; $v < count($variants); $v++) {
            $variants[$v]['products'] = welfare_sub_products($variants[$v]['sub_category_id']);
        }
        $items[] = array('item_code' => $code, 'variants' => $variants);
    }

    $new_staff = ($event === 'START');
    return array(
        'department' => welfare_site_department($site_id),
        'items'      => $items,
        'doc_kind'   => $new_staff ? 'new_staff' : 'request',
        'kit'        => $new_staff ? welfare_new_staff_kit() : array()
    );
}

/* ชุดของพนักงานใหม่ที่เปิดใช้งาน พร้อมชื่อและยอดคงเหลือ (ให้ระบบสวัสดิการแสดงก่อนส่ง) */
function welfare_new_staff_kit()
{
    if (!new_staff_ready()) { return array(); }

    $kit = new_staff_kit_list();
    $out = array();
    for ($i = 0; $i < count($kit); $i++) {
        $p = product_find($kit[$i]['product_id']);
        if ($p === null) { continue; }
        $out[] = array(
            'product_id'   => $kit[$i]['product_id'],
            'product_name' => $p['product_name'],
            'qty'          => $kit[$i]['default_qty'],
            'must_return'  => $kit[$i]['must_return'],
            'on_hand'      => stock_qty($kit[$i]['product_id'])
        );
    }
    return $out;
}


/* ============================================================
 *  submit — ออกใบเบิกที่อนุมัติแล้ว
 *
 *  $p = array(
 *    'employee_id', 'employee_code', 'employee_name', 'position_name',
 *    'company_code', 'logo', 'site_id', 'site_name',
 *    'event', 'entitlement_key', 'requested_by_name', 'note',
 *    'requested_by_user', 'requested_by_emp_code',   (ไม่บังคับ — ดู sql/079)
 *    'start_date',   (จำเป็นเมื่อ event = START — ใบเบิกพนักงานใหม่)
 *    'lines' => array( array('item_code', 'product_id', 'qty') ) )
 *
 *  คืน db_ok + doc_id, doc_no, duplicate (true = สิทธิ์นี้มีใบอยู่แล้ว คืนใบเดิม)
 * ============================================================ */
function welfare_submit($p)
{
    $employee_id = isset($p['employee_id']) ? (int)$p['employee_id'] : 0;
    $name        = isset($p['employee_name'])     ? trim($p['employee_name'])     : '';
    $code        = isset($p['employee_code'])     ? trim($p['employee_code'])     : '';
    $position    = isset($p['position_name'])     ? trim($p['position_name'])     : '';
    $company     = isset($p['company_code'])      ? trim($p['company_code'])      : '';
    $logo        = isset($p['logo'])              ? trim($p['logo'])              : '';
    $site_id     = isset($p['site_id'])           ? (int)$p['site_id']            : 0;
    $site_name   = isset($p['site_name'])         ? trim($p['site_name'])         : '';
    $event       = isset($p['event'])             ? trim($p['event'])             : '';
    $ent_key     = isset($p['entitlement_key'])   ? trim($p['entitlement_key'])   : '';
    $by_name     = isset($p['requested_by_name']) ? trim($p['requested_by_name']) : '';
    $by_user     = isset($p['requested_by_user']) ? substr(trim($p['requested_by_user']), 0, 50) : '';
    $by_emp      = isset($p['requested_by_emp_code']) ? substr(trim($p['requested_by_emp_code']), 0, 20) : '';
    $note       = isset($p['note'])              ? trim($p['note'])              : '';
    $start       = isset($p['start_date'])        ? trim($p['start_date'])        : '';
    $lines       = isset($p['lines']) && is_array($p['lines']) ? $p['lines'] : array();
    $new_staff   = ($event === 'START');

    if ($employee_id <= 0 || $name === '') { return db_fail('ไม่มีข้อมูลพนักงาน'); }
    if (!in_array($event, array('START', 'PROBATION', 'ANNUAL'))) {
        return db_fail('รอบสิทธิ์ไม่ถูกต้อง');
    }
    if (!preg_match('/^(START|PROBATION|ANNUAL-\d{4})$/', $ent_key)) {
        return db_fail('รหัสสิทธิ์ไม่ถูกต้อง');
    }
    if (db_text_length($name) > 150 || db_text_length($position) > 150
        || db_text_length($site_name) > 150 || db_text_length($by_name) > 150) {
        return db_fail('ข้อความยาวเกิน 150 ตัวอักษร');
    }
    if (db_text_length($code) > 30)  { return db_fail('รหัสพนักงานยาวเกิน 30 ตัวอักษร'); }
    if (db_text_length($note) > 300) { return db_fail('หมายเหตุยาวเกิน 300 ตัวอักษร'); }
    if ($new_staff) {
        if (!new_staff_ready()) {
            return db_fail('ระบบสต็อกยังไม่พร้อมออกใบเบิกพนักงานใหม่ — ต้องรัน sql/072_new_staff_request.sql ก่อน');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $start, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return db_fail('ใบเบิกพนักงานใหม่ต้องมีวันที่เริ่มงาน');
        }
    }

    $dept = welfare_site_department($site_id);
    if ($dept === null) {
        return db_fail('หน่วยงาน "' . $site_name . '" ยังไม่ได้จับคู่กับหน่วยงานในระบบสต็อก '
                     . '— แจ้งผู้ดูแลระบบสต็อกให้เพิ่มใน tb_welfare_site_map');
    }

    /* ---------- รายการ: สินค้าต้องอยู่ในหมวดย่อยที่จับคู่กับรายการนั้น ---------- */
    $items = array();
    for ($i = 0; $i < count($lines); $i++) {
        $item_code = isset($lines[$i]['item_code']) ? trim($lines[$i]['item_code']) : '';
        $pid       = isset($lines[$i]['product_id']) ? (int)$lines[$i]['product_id'] : 0;
        $qty       = isset($lines[$i]['qty']) ? (float)$lines[$i]['qty'] : 0;

        $product = ($pid > 0) ? product_find($pid) : null;
        if ($product === null || (int)$product['is_active'] !== 1) {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ไม่พบสินค้าที่เลือก หรือสินค้าถูกปิดใช้งาน');
        }

        $allowed  = false;
        $variants = welfare_item_variants($item_code, $logo);
        for ($v = 0; $v < count($variants); $v++) {
            if ($variants[$v]['sub_category_id'] === (int)$product['sub_category_id']) { $allowed = true; }
        }
        if (!$allowed) {
            return db_fail($product['product_name'] . ': ไม่ใช่สินค้าของรายการ ' . $item_code
                         . ($logo !== '' ? ' (โลโก้ ' . $logo . ')' : ''));
        }

        $items[] = array('product_id' => $pid, 'qty' => $qty, 'note' => '', 'must_return' => 0);
    }

    /* ---------- พนักงานใหม่: ใส่ชุดของพนักงานใหม่ให้ เหมือนฟอร์มหน้า "เบิกพนักงานใหม่" ----------
     * ของที่ซ้ำกับรายการชุดฟอร์ม ใช้จำนวนจากระบบสวัสดิการ แต่ธง "ต้องคืน" ตามชุดของ */
    if ($new_staff) {
        $kit = new_staff_kit_list();
        for ($k = 0; $k < count($kit); $k++) {
            $found = false;
            for ($i = 0; $i < count($items); $i++) {
                if ($items[$i]['product_id'] === $kit[$k]['product_id']) {
                    $items[$i]['must_return'] = $kit[$k]['must_return'] ? 1 : 0;
                    $found = true;
                }
            }
            if (!$found) {
                $items[] = array('product_id' => $kit[$k]['product_id'], 'qty' => $kit[$k]['default_qty'],
                                 'note' => '', 'must_return' => $kit[$k]['must_return'] ? 1 : 0);
            }
        }
    }

    $data = array(
        'doc_date'      => date('Y-m-d'),
        'department_id' => $dept['department_id'],
        'requester'     => WELFARE_ACTOR,
        /* ชุดเลขของ backend เท่านั้น (ดู doc_insert) — NRQ ตรงกับใบที่ทำจากหน้าพนักงานใหม่ */
        'doc_prefix'    => $new_staff ? 'NRQ' : 'WRQ',
        'note'          => welfare_doc_note($event, $ent_key, $name, $note),
        'items'         => $items
    );
    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    $ref = $employee_id . ':' . $ent_key;

    db_begin();

    /* ---------- กันส่งซ้ำ ----------
     * ล็อกแถวของสิทธิ์นี้ไว้ก่อน ถ้ามีใบที่ยังมีผล = คืนใบเดิม (ระบบสวัสดิการกดซ้ำ/เน็ตหลุดแล้วส่งใหม่)
     * ถ้าใบนั้นถูกยกเลิกจากฝั่งสต็อกไปแล้ว ปลดออกจากสิทธิ์นี้ก่อนแล้วออกใบใหม่ได้
     */
    $existing = welfare_active_doc($ref, true);
    if ($existing !== null) {
        if ($existing['status'] !== 'cancelled' && $existing['status'] !== 'rejected') {
            db_rollback();
            $out = db_ok('สิทธิ์นี้ส่งเบิกไปแล้ว ใบเลขที่ ' . $existing['doc_no'], $existing['doc_id']);
            $out['doc_no']    = $existing['doc_no'];
            $out['duplicate'] = true;
            return $out;
        }
        welfare_release_ref($existing['doc_id']);
    }

    $res = doc_insert('request', $data, 'approved', WELFARE_ACTOR);
    if (!$res['ok']) { db_rollback(); return $res; }
    $doc_id = (int)$res['id'];

    /* อนุมัติอัตโนมัติ — doc_insert ไม่ตั้งผู้อนุมัติให้ */
    $connect = db_connect();
    $actor   = WELFARE_ACTOR;
    $actor_name = doc_user_name($actor);
    $approve_note = 'อนุมัติอัตโนมัติ — ผ่านการตรวจสิทธิ์จากระบบสวัสดิการ';
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(), `approve_note` = ?
         WHERE `doc_id` = ?");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกการอนุมัติไม่สำเร็จ'); }
    mysqli_stmt_bind_param($stmt, 'sssi', $actor, $actor_name, $approve_note, $doc_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) { db_rollback(); return db_fail('บันทึกการอนุมัติไม่สำเร็จ'); }

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_doc_welfare`
         SET `doc_id` = ?, `source_ref` = ?, `active_ref` = ?, `employee_id` = ?,
             `employee_code` = ?, `employee_name` = ?, `position_name` = ?,
             `company_code` = ?, `site_id` = ?, `site_name` = ?,
             `event` = ?, `entitlement_key` = ?, `requested_by_name` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { db_rollback(); return db_fail('เตรียมคำสั่งบันทึกข้อมูลสวัสดิการไม่สำเร็จ'); }
    mysqli_stmt_bind_param($stmt, 'ississssisssss',
        $doc_id, $ref, $ref, $employee_id, $code, $name, $position,
        $company, $site_id, $site_name, $event, $ent_key, $by_name, $actor);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        /* 1062 = อีกคำขอของสิทธิ์เดียวกันเพิ่งออกใบไปก่อนหน้าเสี้ยววินาที */
        if ($errno === 1062) { return welfare_submit_existing($ref); }
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* พนักงานใหม่: แถวนี้ทำให้ใบเป็น "ใบเบิกพนักงานใหม่" ทั้งหน้ารายการ แดชบอร์ด และใบพิมพ์ (ดู sql/072) */
    if ($new_staff) {
        $stmt = mysqli_prepare($connect,
            "INSERT INTO `tb_doc_new_staff`
             SET `doc_id` = ?, `employee_name` = ?, `employee_code` = ?,
                 `position_name` = ?, `start_date` = ?,
                 `created_at` = NOW(), `created_by` = ?");
        if (!$stmt) { db_rollback(); return db_fail('เตรียมคำสั่งบันทึกข้อมูลพนักงานใหม่ไม่สำเร็จ'); }
        mysqli_stmt_bind_param($stmt, 'isssss', $doc_id, $name, $code, $position, $start, $actor);
        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            db_rollback();
            return db_fail(db_error_message($errno), $errno);
        }
        mysqli_stmt_close($stmt);
    }

    /* ผู้ใช้ HR ที่กดส่ง (ดู sql/079) — ใช้ส่งลิงก์เซ็นรับตอนคลังจ่ายของ
       แยกเป็น UPDATE ทีหลังเพราะเครื่องที่ยังไม่ได้รัน 079 ต้องส่งเบิกได้เหมือนเดิม
       และบันทึกไม่ติดก็ไม่ล้มใบ — แค่ลิงก์เซ็นรับจะถอยไปหาผู้ดูแลระบบแทน */
    if (($by_user !== '' || $by_emp !== '') && welfare_has_sender_columns()) {
        $stmt = @mysqli_prepare($connect,
            "UPDATE `tb_doc_welfare`
             SET `requested_by_user` = ?, `requested_by_emp_code` = ?
             WHERE `doc_id` = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssi', $by_user, $by_emp, $doc_id);
            @mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    $by = ($by_name !== '') ? $by_name : 'ไม่ระบุชื่อ';
    doc_history_add($doc_id, 'submitted', $actor, 'ส่งจากระบบสวัสดิการโดย ' . $by . ' — '
                    . ($new_staff ? 'พนักงานใหม่: ' : '') . $name);
    doc_history_add($doc_id, 'approved', $actor, $approve_note);

    db_commit();

    /* หลัง commit เสมอ — แจ้งเตือนพังต้องไม่ทำให้ใบหาย (ดูหัวไฟล์ notify_events.php) */
    notify_event_welfare_request($doc_id, $actor, $by_name);

    $out = db_ok('ส่งเบิก' . ($new_staff ? 'พนักงานใหม่' : 'ชุดฟอร์ม') . 'แล้ว ใบเลขที่ ' . $res['doc_no']
                 . ' (' . $name . ') — รอคลังจ่าย', $doc_id);
    $out['doc_no']    = $res['doc_no'];
    $out['duplicate'] = false;
    return $out;
}

function welfare_submit_existing($ref)
{
    $doc = welfare_active_doc($ref, false);
    if ($doc === null) { return db_fail('สิทธิ์นี้กำลังถูกส่งเบิกจากอีกคำขอ กรุณาลองใหม่'); }
    $out = db_ok('สิทธิ์นี้ส่งเบิกไปแล้ว ใบเลขที่ ' . $doc['doc_no'], $doc['doc_id']);
    $out['doc_no']    = $doc['doc_no'];
    $out['duplicate'] = true;
    return $out;
}

function welfare_event_label($event)
{
    $labels = array('START' => 'เริ่มงาน', 'PROBATION' => 'ผ่านทดลองงาน', 'ANNUAL' => 'ประจำปี');
    return isset($labels[$event]) ? $labels[$event] : $event;
}

/* หมายเหตุของใบ — คลังเห็นในรายการทันทีว่าเบิกให้ใคร รอบไหน */
function welfare_doc_note($event, $ent_key, $name, $extra)
{
    $label = welfare_event_label($event);
    if ($event === 'ANNUAL') { $label .= ' ' . (substr($ent_key, 7) + 543); }
    $note = 'ชุดฟอร์มสวัสดิการ (' . $label . ') — ' . $name;
    if ($extra !== '') { $note .= ' — ' . $extra; }
    return $note;
}

/* ใบที่ผูกกับสิทธิ์นี้อยู่ (active_ref) — null = ไม่มี
   $lock = true ใช้ใน transaction เพื่อกันสองคำขอผ่านการตรวจพร้อมกัน */
function welfare_active_doc($ref, $lock)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT h.`doc_id`, h.`doc_no`, h.`status`
         FROM `tb_doc_welfare` w
         JOIN `tb_doc_header` h ON h.`doc_id` = w.`doc_id`
         WHERE w.`active_ref` = ? LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 's', $ref);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $no, $status);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }
    return array('doc_id' => (int)$id, 'doc_no' => $no, 'status' => $status);
}

/* ปลดใบออกจากสิทธิ์ (ใบถูกยกเลิก) สิทธิ์นั้นจึงส่งเบิกใหม่ได้ */
function welfare_release_ref($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;
    $actor   = WELFARE_ACTOR;
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_welfare` SET `active_ref` = NULL, `updated_by` = ? WHERE `doc_id` = ?");
    if (!$stmt) { return false; }
    mysqli_stmt_bind_param($stmt, 'si', $actor, $doc_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}


/* ============================================================
 *  status — สถานะล่าสุดของแต่ละสิทธิ์
 *
 *  $refs = array('12:START', '12:ANNUAL-2026', ...)  (ไม่เกิน 200)
 *  คืน array( ref => array(
 *      'doc_id', 'doc_no', 'stage', 'status', 'status_label',
 *      'issued_at', 'received_at', 'cancel_note',
 *      'items' => array( array('product_id', 'product_name', 'size', 'qty') ) ) )
 *
 *  stage ที่ระบบสวัสดิการใช้ตัดสิน:
 *    REQUESTED  ออกใบแล้ว รอคลังจ่าย (รวมจ่ายไปบางส่วน)
 *    ISSUED     คลังจ่ายครบแล้ว รอพนักงานเซ็นรับ
 *    RECEIVED   ทุกใบจ่ายเซ็นรับแล้ว
 *    CANCELLED  ใบถูกยกเลิก — สิทธิ์กลับเป็นค้างแจก
 *  ref ที่ไม่เคยส่งเบิก ไม่อยู่ในผลลัพธ์
 * ============================================================ */
function welfare_status($refs)
{
    $out = array();
    if (!is_array($refs) || count($refs) === 0) { return $out; }
    $refs = array_slice(array_values(array_unique($refs)), 0, 200);

    $connect = db_connect();
    $marks = implode(',', array_fill(0, count($refs), '?'));

    /* ใบล่าสุดของแต่ละสิทธิ์ (ส่งซ้ำได้หลังยกเลิก ใบใหม่มี doc_id มากกว่า) */
    $sql = "SELECT w.`source_ref`, h.`doc_id`, h.`doc_no`, h.`status`, h.`cancel_note`,
                   (SELECT MAX(i.`created_at`) FROM `tb_doc_header` i
                     WHERE i.`doc_type` = 'issue' AND i.`ref_doc_id` = h.`doc_id`
                       AND i.`status` <> 'cancelled') AS `issued_at`,
                   (SELECT COUNT(*) FROM `tb_doc_header` i
                     WHERE i.`doc_type` = 'issue' AND i.`ref_doc_id` = h.`doc_id`
                       AND i.`status` = 'issued') AS `unsigned_count`,
                   (SELECT MAX(i.`received_at`) FROM `tb_doc_header` i
                     WHERE i.`doc_type` = 'issue' AND i.`ref_doc_id` = h.`doc_id`
                       AND i.`status` = 'received') AS `received_at`
            FROM `tb_doc_welfare` w
            JOIN `tb_doc_header` h ON h.`doc_id` = w.`doc_id`
            WHERE w.`source_ref` IN (" . $marks . ")
              AND w.`doc_id` = (SELECT MAX(w2.`doc_id`) FROM `tb_doc_welfare` w2
                                 WHERE w2.`source_ref` = w.`source_ref`)";

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return $out; }

    $types = str_repeat('s', count($refs));
    $args  = array($stmt, $types);
    for ($i = 0; $i < count($refs); $i++) { $refs[$i] = (string)$refs[$i]; $args[] = &$refs[$i]; }
    call_user_func_array('mysqli_stmt_bind_param', $args);

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $ref, $doc_id, $doc_no, $status, $cancel_note,
                            $issued_at, $unsigned, $received_at);

    while (mysqli_stmt_fetch($stmt)) {
        if ($status === 'cancelled' || $status === 'rejected') {
            $stage = 'CANCELLED';
        } elseif ($status === 'issued' && (int)$unsigned === 0 && $received_at !== null) {
            $stage = 'RECEIVED';
        } elseif ($status === 'issued') {
            $stage = 'ISSUED';
        } else {
            $stage = 'REQUESTED';
        }

        $out[$ref] = array(
            'doc_id'       => (int)$doc_id,
            'doc_no'       => $doc_no,
            'stage'        => $stage,
            'status'       => $status,
            'status_label' => doc_status_label($status),
            'issued_at'    => ($stage === 'ISSUED' || $stage === 'RECEIVED') ? $issued_at : null,
            'received_at'  => ($stage === 'RECEIVED') ? $received_at : null,
            'cancel_note'  => $cancel_note
        );
    }
    mysqli_stmt_close($stmt);

    /* รายการสินค้าแยกถามทีหลัง — mysqli เปิดสอง statement ซ้อนกันไม่ได้
       uniform = เป็นสินค้าของหมวดที่จับคู่กับชุดฟอร์ม / false = ของจากชุดพนักงานใหม่ */
    $uniform_subs = welfare_mapped_sub_categories();
    foreach ($out as $ref => $row) {
        $lines = doc_items($row['doc_id']);
        $items = array();
        for ($i = 0; $i < count($lines); $i++) {
            $p = product_find($lines[$i]['product_id']);
            $items[] = array(
                'product_id'   => (int)$lines[$i]['product_id'],
                'product_name' => $lines[$i]['product_name'],
                'size'         => welfare_size_of($lines[$i]['product_name']),
                'qty'          => (float)$lines[$i]['qty'],
                'uniform'      => ($p !== null && isset($uniform_subs[$p['sub_category_id']]))
            );
        }
        $out[$ref]['items'] = $items;
    }

    return $out;
}


/* ============================================================
 *  cancel — ระบบสวัสดิการยกเลิกการส่งเบิก
 *  ยกเลิกได้เฉพาะใบที่คลังยังไม่ได้จ่ายเลย ถ้าจ่ายไปแล้ว (แม้บางส่วน)
 *  ต้องให้คลังยกเลิกใบจ่ายก่อน ไม่งั้นสต๊อกกับใบเบิกจะไม่ตรงกัน
 * ============================================================ */
function welfare_cancel($ref, $reason, $by_name)
{
    $reason = trim($reason);
    if ($reason === '') { $reason = 'ยกเลิกจากระบบสวัสดิการ'; }
    $by_name = trim($by_name);
    if ($by_name !== '') { $reason .= ' (โดย ' . $by_name . ')'; }
    if (db_text_length($reason) > 300) { return db_fail('เหตุผลยาวเกิน 300 ตัวอักษร'); }

    db_begin();

    $doc = welfare_active_doc($ref, true);
    if ($doc === null) {
        db_rollback();
        return db_fail('ไม่พบใบเบิกที่ยังมีผลของสิทธิ์นี้');
    }
    if ($doc['status'] === 'cancelled' || $doc['status'] === 'rejected') {
        welfare_release_ref($doc['doc_id']);
        db_commit();
        return db_ok('ใบเบิก ' . $doc['doc_no'] . ' ถูกยกเลิกไปแล้ว', $doc['doc_id']);
    }
    if ($doc['status'] !== 'approved' || welfare_issue_count($doc['doc_id']) > 0) {
        db_rollback();
        return db_fail('คลังจ่ายของตามใบ ' . $doc['doc_no'] . ' ไปแล้ว ยกเลิกจากระบบสวัสดิการไม่ได้ '
                     . '— ติดต่อคลังให้ยกเลิกใบจ่ายก่อน');
    }

    $connect = db_connect();
    $actor   = WELFARE_ACTOR;
    $id      = $doc['doc_id'];
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'approved'");
    if (!$stmt) { db_rollback(); return db_fail('ยกเลิกใบเบิกไม่สำเร็จ'); }
    mysqli_stmt_bind_param($stmt, 'sssi', $actor, $reason, $actor, $id);
    $ok = mysqli_stmt_execute($stmt);
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok || $changed < 1) { db_rollback(); return db_fail('ยกเลิกใบเบิกไม่สำเร็จ กรุณาลองใหม่'); }

    welfare_release_ref($id);
    doc_history_add($id, 'cancelled', $actor, $reason);

    db_commit();
    return db_ok('ยกเลิกใบเบิก ' . $doc['doc_no'] . ' แล้ว', $id);
}

/* ใบจ่ายที่ยังมีผลของใบเบิกนี้ */
function welfare_issue_count($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;
    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_doc_header`
         WHERE `doc_type` = 'issue' AND `ref_doc_id` = ? AND `status` <> 'cancelled'");
    if (!$stmt) { return 0; }
    mysqli_stmt_bind_param($stmt, 'i', $doc_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int)$n;
}
