<?php
/* ============================================================
 *  backend/welfare_master.php — รับบริษัท/หน่วยงานจากระบบสวัสดิการ (hl-welfare)
 *
 *  ระบบสวัสดิการเป็นต้นทางของข้อมูลหลักสองชุดนี้ สต็อกรับตามเท่านั้น
 *    บริษัท    จับคู่ด้วย tb_company.company_code (ดู sql/081)
 *    หน่วยงาน  จับคู่ด้วย tb_welfare_site_map.welfare_site_id -> tb_department (ดู sql/075, 076)
 *
 *  กติกา
 *    - ส่งซ้ำกี่รอบก็ได้ผลเดิม (upsert)
 *    - ทั้งชุดสำเร็จหรือล้มพร้อมกัน: ตรวจข้อมูลทั้งหมดก่อนเขียน แล้วเขียนใน transaction เดียว
 *    - ไม่ลบอะไรเลย หน่วยงานที่ active = false แค่ปิดใช้งาน (is_active = 0)
 *      เพราะใบเบิก/รับ/จ่ายเก่าอ้างหน่วยงานอยู่
 *    - ส่งมาแค่บริษัท หรือแค่หน่วยงานก็ได้
 *      หน่วยงานอ้างบริษัทที่มีรหัสอยู่แล้วในสต็อกได้ ไม่ต้องส่งบริษัทมาซ้ำ
 *    - ทุกครั้งที่สำเร็จ บันทึกลง tb_welfare_sync_log ว่าใครส่ง เปลี่ยนอะไร
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/stock.php';           // db_begin / db_commit / db_rollback
require_once dirname(__FILE__) . '/welfare_bridge.php';  // WELFARE_ACTOR
require_once dirname(__FILE__) . '/welfare_uniform.php'; // welfare_uniform_ready

define('WELFARE_MASTER_NAME_MAX', 200);


/* ---------- ตาราง/คอลัมน์ของ sql/081 มีแล้วหรือยัง ---------- */
function welfare_master_ready()
{
    $connect = db_connect();
    $res = @mysqli_query($connect, "SHOW COLUMNS FROM `tb_company` LIKE 'company_code'");
    $ok = ($res && mysqli_num_rows($res) > 0);
    if ($res) { mysqli_free_result($res); }
    if (!$ok) { return false; }

    $res = @mysqli_query($connect, "SHOW TABLES LIKE 'tb_welfare_sync_log'");
    $ok = ($res && mysqli_num_rows($res) > 0);
    if ($res) { mysqli_free_result($res); }
    return $ok;
}


/* ---------- ค่า active ที่ส่งมา (ไม่ส่ง = เปิดใช้งาน) ---------- */
function welfare_master_flag($row)
{
    if (!array_key_exists('active', $row)) { return 1; }
    $v = $row['active'];
    if ($v === false || $v === 0 || $v === '0' || $v === 'false') { return 0; }
    return 1;
}


/* ---------- ตรวจและจัดรูปข้อมูลที่ส่งมา ----------
 * คืน array('error' => '', 'companies' => [...], 'sites' => [...])
 * ตรวจครบทุกแถวก่อนเริ่มเขียน จะได้ไม่มีกรณีเขียนไปครึ่งชุดแล้วค่อยเจอแถวเสีย
 */
function welfare_master_parse($p)
{
    $out = array('error' => '', 'companies' => array(), 'sites' => array(), 'uniform' => array());

    $companies = (isset($p['companies'])     && is_array($p['companies']))     ? $p['companies']     : array();
    $sites     = (isset($p['sites'])         && is_array($p['sites']))         ? $p['sites']         : array();
    $uniform   = (isset($p['uniform_start']) && is_array($p['uniform_start'])) ? $p['uniform_start'] : array();

    if (count($companies) === 0 && count($sites) === 0 && count($uniform) === 0) {
        $out['error'] = 'ไม่มีข้อมูลบริษัท หน่วยงาน หรือชุดฟอร์มให้บันทึก';
        return $out;
    }

    $seen = array();
    for ($i = 0; $i < count($companies); $i++) {
        $c    = is_array($companies[$i]) ? $companies[$i] : array();
        $code = isset($c['code']) ? strtoupper(trim((string)$c['code'])) : '';
        $name = isset($c['name']) ? trim((string)$c['name']) : '';
        $no   = 'บริษัทแถวที่ ' . ($i + 1);

        if (!preg_match('/^[A-Z0-9_-]{1,10}$/', $code)) {
            $out['error'] = $no . ': รหัสบริษัทไม่ถูกต้อง (ตัวอักษรอังกฤษ/ตัวเลข ไม่เกิน 10 ตัว)';
            return $out;
        }
        if ($name === '') {
            $out['error'] = $no . ' (' . $code . '): ไม่มีชื่อบริษัท';
            return $out;
        }
        if (db_text_length($name) > WELFARE_MASTER_NAME_MAX) {
            $out['error'] = $no . ' (' . $code . '): ชื่อบริษัทยาวเกิน ' . WELFARE_MASTER_NAME_MAX . ' ตัวอักษร';
            return $out;
        }
        if (isset($seen[$code])) {
            $out['error'] = 'รหัสบริษัท ' . $code . ' ส่งมาซ้ำในชุดเดียวกัน';
            return $out;
        }
        $seen[$code] = true;

        $out['companies'][] = array('code' => $code, 'name' => $name, 'active' => welfare_master_flag($c));
    }

    $seen = array();
    for ($i = 0; $i < count($sites); $i++) {
        $s    = is_array($sites[$i]) ? $sites[$i] : array();
        $id   = isset($s['id']) ? (int)$s['id'] : 0;
        $code = isset($s['company_code']) ? strtoupper(trim((string)$s['company_code'])) : '';
        $name = isset($s['name']) ? trim((string)$s['name']) : '';
        $no   = 'หน่วยงานแถวที่ ' . ($i + 1);

        if ($id <= 0) {
            $out['error'] = $no . ': ไม่มี id หน่วยงาน';
            return $out;
        }
        if ($code === '') {
            $out['error'] = $no . ' (id ' . $id . '): ไม่มีรหัสบริษัท';
            return $out;
        }
        if ($name === '') {
            $out['error'] = $no . ' (id ' . $id . '): ไม่มีชื่อหน่วยงาน';
            return $out;
        }
        if (db_text_length($name) > WELFARE_MASTER_NAME_MAX) {
            $out['error'] = $no . ' (id ' . $id . '): ชื่อหน่วยงานยาวเกิน ' . WELFARE_MASTER_NAME_MAX . ' ตัวอักษร';
            return $out;
        }
        if (isset($seen[$id])) {
            $out['error'] = 'หน่วยงาน id ' . $id . ' ส่งมาซ้ำในชุดเดียวกัน';
            return $out;
        }
        $seen[$id] = true;

        $out['sites'][] = array('id' => $id, 'company_code' => $code, 'name' => $name,
                                'active' => welfare_master_flag($s));
    }

    /* ---------- ชุดฟอร์มรอบเริ่มงาน (ดู sql/082) ----------
       แต่ละหน่วยงานที่ส่งมา = แทนที่เงื่อนไขทั้งชุดของหน่วยงานนั้น (rules ว่าง = ล้าง)
       หน่วยงานที่ไม่ได้ส่งมา ไม่แตะ */
    $seen = array();
    for ($i = 0; $i < count($uniform); $i++) {
        $u     = is_array($uniform[$i]) ? $uniform[$i] : array();
        $sid   = isset($u['site_id']) ? (int)$u['site_id'] : 0;
        $rules = (isset($u['rules']) && is_array($u['rules'])) ? $u['rules'] : array();
        $no    = 'ชุดฟอร์มแถวที่ ' . ($i + 1);

        if ($sid <= 0) {
            $out['error'] = $no . ': ไม่มี site_id';
            return $out;
        }
        if (isset($seen[$sid])) {
            $out['error'] = 'ชุดฟอร์มของหน่วยงาน id ' . $sid . ' ส่งมาซ้ำในชุดเดียวกัน';
            return $out;
        }
        $seen[$sid] = true;

        $clean = array();
        $rule_seen = array();
        for ($r = 0; $r < count($rules); $r++) {
            $rule  = is_array($rules[$r]) ? $rules[$r] : array();
            $rid   = isset($rule['rule_id']) ? (int)$rule['rule_id'] : 0;
            $label = isset($rule['label']) ? trim((string)$rule['label']) : '';
            $items = (isset($rule['items']) && is_array($rule['items'])) ? $rule['items'] : array();
            $rno   = $no . ' (หน่วยงาน id ' . $sid . ') เงื่อนไขที่ ' . ($r + 1);

            if ($rid <= 0) {
                $out['error'] = $rno . ': ไม่มี rule_id';
                return $out;
            }
            if (isset($rule_seen[$rid])) {
                $out['error'] = $rno . ': rule_id ' . $rid . ' ซ้ำในหน่วยงานเดียวกัน';
                return $out;
            }
            $rule_seen[$rid] = true;
            if (db_text_length($label) > WELFARE_MASTER_NAME_MAX) {
                $out['error'] = $rno . ': ชื่อเงื่อนไขยาวเกิน ' . WELFARE_MASTER_NAME_MAX . ' ตัวอักษร';
                return $out;
            }

            $clean_items = array();
            for ($k = 0; $k < count($items); $k++) {
                $it   = is_array($items[$k]) ? $items[$k] : array();
                $code = isset($it['item_code']) ? strtoupper(trim((string)$it['item_code'])) : '';
                $qty  = (isset($it['qty']) && is_numeric($it['qty'])) ? (float)$it['qty'] : 0;

                if (!preg_match('/^[A-Z0-9_-]{1,30}$/', $code)) {
                    $out['error'] = $rno . ' รายการที่ ' . ($k + 1) . ': รหัสรายการชุดฟอร์มไม่ถูกต้อง';
                    return $out;
                }
                if ($qty <= 0 || $qty > 999) {
                    $out['error'] = $rno . ' (' . $code . '): จำนวนต้องมากกว่า 0 และไม่เกิน 999';
                    return $out;
                }
                $clean_items[] = array('item_code' => $code, 'qty' => $qty);
            }
            $clean[] = array('rule_id' => $rid, 'label' => $label, 'items' => $clean_items);
        }
        $out['uniform'][] = array('site_id' => $sid, 'rules' => $clean);
    }

    return $out;
}


/* ---------- บริษัทที่มีรหัสอยู่แล้วในสต็อก: code => row ---------- */
function welfare_master_companies_by_code($lock)
{
    $connect = db_connect();
    $res = mysqli_query($connect,
        "SELECT `company_id`, `company_code`, `company_name`, `is_active`
         FROM `tb_company` WHERE `company_code` IS NOT NULL" . ($lock ? ' FOR UPDATE' : ''));

    $rows = array();
    if (!$res) { return $rows; }
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[$r['company_code']] = $r;
    }
    mysqli_free_result($res);
    return $rows;
}


/* ---------- หน่วยงานที่ผูกกับ site ของระบบสวัสดิการแล้ว (ล็อกไว้จนจบ transaction) ---------- */
function welfare_master_site_find($site_id)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT d.`department_id`, d.`department_name`, d.`company_id`, d.`is_active`
         FROM `tb_welfare_site_map` m
         JOIN `tb_department` d ON d.`department_id` = m.`department_id`
         WHERE m.`welfare_site_id` = ? LIMIT 1 FOR UPDATE");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 'i', $site_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $dept_id, $dept_name, $company_id, $is_active);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }
    return array('department_id' => (int)$dept_id, 'department_name' => $dept_name,
                 'company_id' => (int)$company_id, 'is_active' => (int)$is_active);
}


/* ---------- รันคำสั่งเขียนหนึ่งคำสั่ง คืน insert id (หรือ 0) / -1 เมื่อพัง ---------- */
function welfare_master_exec($sql, $types, $values)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return -1; }

    $args = array($stmt, $types);
    for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
    call_user_func_array('mysqli_stmt_bind_param', $args);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return -1;
    }
    $id = (int)mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
    return $id;
}


/* ---------- เนื้อหาชุดฟอร์มเดิมของหน่วยงาน ไว้เทียบว่าเปลี่ยนไหม ----------
 * คืน array ของ "rule_id|label|item_code|qty" ตามลำดับ, null = ยังไม่เคยรับ (uniform_synced_at ว่าง) */
function welfare_master_uniform_signature($site_id)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT `uniform_synced_at` FROM `tb_welfare_site_map` WHERE `welfare_site_id` = ? LIMIT 1");
    if (!$stmt) { return null; }
    mysqli_stmt_bind_param($stmt, 'i', $site_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $synced_at);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if (!$found || $synced_at === null) { return null; }

    $stmt = mysqli_prepare($connect,
        "SELECT `rule_id`, `rule_label`, `item_code`, `qty` FROM `tb_welfare_uniform_rule`
         WHERE `welfare_site_id` = ? ORDER BY `rule_sort` ASC, `item_sort` ASC, `row_id` ASC FOR UPDATE");
    if (!$stmt) { return null; }
    mysqli_stmt_bind_param($stmt, 'i', $site_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $rid, $label, $code, $qty);
    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = $rid . '|' . $label . '|' . $code . '|' . sprintf('%.2f', (float)$qty);
    }
    mysqli_stmt_close($stmt);
    return $rows;
}


/* ============================================================
 *  sync — รับบริษัท/หน่วยงาน/ชุดฟอร์มรอบเริ่มงาน
 *
 *  $p = array(
 *    'companies' => array( array('code' => 'HL', 'name' => 'บริษัท แฮปปี้แลนด์ จำกัด', 'active' => true) ),
 *    'sites'     => array( array('id' => 1, 'company_code' => 'HL', 'name' => '...', 'active' => true) ),
 *    'uniform_start' => array( array('site_id' => 4, 'rules' => array(
 *          array('rule_id' => 16, 'label' => '...', 'items' => array( array('item_code' => 'POLO_NAVY', 'qty' => 1) )) )) ),
 *    'requested_by_name' => '...' )      ทุกส่วนไม่บังคับ แต่ต้องมีอย่างน้อยหนึ่งแถว
 *
 *  คืน db_ok + 'companies' => [{code, company_id, created}], 'sites' => [{id, department_id, created}],
 *              'uniform_start' => [{site_id, rules, items, changed}]
 * ============================================================ */
function welfare_master_sync($p)
{
    if (!welfare_master_ready()) {
        return db_fail('ฐานข้อมูลสต็อกยังไม่พร้อม — ต้องรัน sql/081_welfare_master_sync.sql ก่อน');
    }

    $in = welfare_master_parse($p);
    if ($in['error'] !== '') { return db_fail($in['error']); }

    if (count($in['uniform']) > 0 && !welfare_uniform_ready()) {
        return db_fail('ฐานข้อมูลสต็อกยังไม่พร้อมรับชุดฟอร์ม — ต้องรัน sql/082_welfare_uniform_start.sql ก่อน');
    }

    $actor   = WELFARE_ACTOR;
    $by_name = isset($p['requested_by_name']) ? trim((string)$p['requested_by_name']) : '';
    if (db_text_length($by_name) > 150) { $by_name = ''; }

    db_begin();

    /* ---------- รหัสบริษัทของทุกหน่วยงานต้องรู้จัก ก่อนเขียนอะไรทั้งนั้น ---------- */
    $known = welfare_master_companies_by_code(true);
    $sent  = array();
    for ($i = 0; $i < count($in['companies']); $i++) { $sent[$in['companies'][$i]['code']] = true; }

    for ($i = 0; $i < count($in['sites']); $i++) {
        $code = $in['sites'][$i]['company_code'];
        if (!isset($known[$code]) && !isset($sent[$code])) {
            db_rollback();
            return db_fail('หน่วยงาน "' . $in['sites'][$i]['name'] . '" (id ' . $in['sites'][$i]['id']
                         . ') อ้างรหัสบริษัท ' . $code . ' ซึ่งไม่มีในระบบสต็อก '
                         . '— ส่งบริษัทนี้มาในชุดเดียวกันก่อน ไม่ได้บันทึกอะไรเลย');
        }
    }

    /* ---------- ชุดฟอร์มต้องอ้างหน่วยงานที่สต็อกรู้จัก (หรือส่งมาในชุดเดียวกัน) ---------- */
    $sent_sites = array();
    for ($i = 0; $i < count($in['sites']); $i++) { $sent_sites[$in['sites'][$i]['id']] = true; }

    for ($i = 0; $i < count($in['uniform']); $i++) {
        $sid = $in['uniform'][$i]['site_id'];
        if (!isset($sent_sites[$sid]) && welfare_master_site_find($sid) === null) {
            db_rollback();
            return db_fail('ชุดฟอร์มอ้างหน่วยงาน id ' . $sid . ' ซึ่งไม่มีในระบบสต็อก '
                         . '— ส่งหน่วยงานนี้มาในชุดเดียวกันก่อน ไม่ได้บันทึกอะไรเลย');
        }
    }

    $log     = array();
    $created = 0;
    $changed = 0;
    $res_companies = array();
    $res_sites     = array();
    $company_ids   = array();
    foreach ($known as $code => $row) { $company_ids[$code] = (int)$row['company_id']; }

    /* ---------- บริษัท ---------- */
    for ($i = 0; $i < count($in['companies']); $i++) {
        $c = $in['companies'][$i];

        if (isset($known[$c['code']])) {
            $old = $known[$c['code']];
            $id  = (int)$old['company_id'];
            if ($old['company_name'] !== $c['name'] || (int)$old['is_active'] !== $c['active']) {
                $ok = welfare_master_exec(
                    "UPDATE `tb_company` SET `company_name` = ?, `is_active` = ?, `updated_by` = ?
                     WHERE `company_id` = ?",
                    'sisi', array($c['name'], $c['active'], $actor, $id));
                if ($ok < 0) { db_rollback(); return db_fail('บันทึกบริษัท ' . $c['code'] . ' ไม่สำเร็จ'); }

                $what = array();
                if ($old['company_name'] !== $c['name']) {
                    $what[] = '"' . $old['company_name'] . '" -> "' . $c['name'] . '"';
                }
                if ((int)$old['is_active'] !== $c['active']) {
                    $what[] = $c['active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
                }
                $changed++;
                $log[] = 'บริษัท ' . $c['code'] . ' (#' . $id . '): ' . implode(', ', $what);
            }
            $res_companies[] = array('code' => $c['code'], 'company_id' => $id, 'created' => false);

        } else {
            $id = welfare_master_exec(
                "INSERT INTO `tb_company`
                 SET `company_code` = ?, `company_name` = ?, `is_active` = ?,
                     `create_date` = NOW(), `created_by` = ?",
                'ssis', array($c['code'], $c['name'], $c['active'], $actor));
            if ($id <= 0) { db_rollback(); return db_fail('เพิ่มบริษัท ' . $c['code'] . ' ไม่สำเร็จ'); }

            $created++;
            $log[] = 'บริษัท ' . $c['code'] . ' (#' . $id . '): สร้างใหม่ "' . $c['name'] . '"';
            $res_companies[] = array('code' => $c['code'], 'company_id' => $id, 'created' => true);
        }
        $company_ids[$c['code']] = $id;
    }

    /* ---------- หน่วยงาน ---------- */
    for ($i = 0; $i < count($in['sites']); $i++) {
        $s          = $in['sites'][$i];
        $company_id = $company_ids[$s['company_code']];
        $old        = welfare_master_site_find($s['id']);

        if ($old !== null) {
            $dept_id = $old['department_id'];
            if ($old['department_name'] !== $s['name'] || $old['company_id'] !== $company_id
                || $old['is_active'] !== $s['active']) {

                $ok = welfare_master_exec(
                    "UPDATE `tb_department`
                     SET `department_name` = ?, `company_id` = ?, `is_active` = ?, `updated_by` = ?
                     WHERE `department_id` = ?",
                    'siisi', array($s['name'], $company_id, $s['active'], $actor, $dept_id));
                if ($ok < 0) { db_rollback(); return db_fail('บันทึกหน่วยงาน id ' . $s['id'] . ' ไม่สำเร็จ'); }

                $what = array();
                if ($old['department_name'] !== $s['name']) {
                    $what[] = '"' . $old['department_name'] . '" -> "' . $s['name'] . '"';
                }
                if ($old['company_id'] !== $company_id) {
                    $what[] = 'ย้ายไปบริษัท ' . $s['company_code'];
                }
                if ($old['is_active'] !== $s['active']) {
                    $what[] = $s['active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
                }
                $changed++;
                $log[] = 'หน่วยงาน site ' . $s['id'] . ' (แผนก #' . $dept_id . '): ' . implode(', ', $what);
            }

            /* ชื่อในตารางจับคู่มีไว้อ่านเท่านั้น ให้ตรงกับต้นทางเสมอ */
            $ok = welfare_master_exec(
                "UPDATE `tb_welfare_site_map` SET `welfare_site_name` = ?, `updated_by` = ?
                 WHERE `welfare_site_id` = ? AND `welfare_site_name` <> ?",
                'ssis', array($s['name'], $actor, $s['id'], $s['name']));
            if ($ok < 0) { db_rollback(); return db_fail('บันทึกการจับคู่หน่วยงาน id ' . $s['id'] . ' ไม่สำเร็จ'); }

            $res_sites[] = array('id' => $s['id'], 'department_id' => $dept_id, 'created' => false);

        } else {
            $dept_id = welfare_master_exec(
                "INSERT INTO `tb_department`
                 SET `department_name` = ?, `company_id` = ?, `is_active` = ?,
                     `create_date` = NOW(), `created_by` = ?",
                'siis', array($s['name'], $company_id, $s['active'], $actor));
            if ($dept_id <= 0) { db_rollback(); return db_fail('เพิ่มหน่วยงาน id ' . $s['id'] . ' ไม่สำเร็จ'); }

            $ok = welfare_master_exec(
                "INSERT INTO `tb_welfare_site_map`
                 SET `welfare_site_id` = ?, `welfare_site_name` = ?, `department_id` = ?,
                     `created_at` = NOW(), `created_by` = ?",
                'isis', array($s['id'], $s['name'], $dept_id, $actor));
            if ($ok < 0) { db_rollback(); return db_fail('บันทึกการจับคู่หน่วยงาน id ' . $s['id'] . ' ไม่สำเร็จ'); }

            $created++;
            $log[] = 'หน่วยงาน site ' . $s['id'] . ' (แผนก #' . $dept_id . '): สร้างใหม่ "' . $s['name']
                   . '" บริษัท ' . $s['company_code'] . ($s['active'] ? '' : ' (ปิดใช้งาน)');
            $res_sites[] = array('id' => $s['id'], 'department_id' => $dept_id, 'created' => true);
        }
    }

    /* ---------- ชุดฟอร์มรอบเริ่มงาน: เขียนทับทั้งชุดของแต่ละหน่วยงานที่ส่งมา ----------
       เวลา sync ล่าสุดขยับทุกครั้ง แม้ข้อมูลเท่าเดิม (บอกได้ว่าต้นทางเพิ่งยืนยันมา)
       นับ "แก้ไข" และลงประวัติเฉพาะเมื่อเนื้อหาเปลี่ยนจริง */
    $res_uniform = array();
    for ($i = 0; $i < count($in['uniform']); $i++) {
        $u   = $in['uniform'][$i];
        $sid = $u['site_id'];

        $before = welfare_master_uniform_signature($sid);
        $after  = array();
        $n_items = 0;
        for ($r = 0; $r < count($u['rules']); $r++) {
            $rule = $u['rules'][$r];
            if (count($rule['items']) === 0) {
                $after[] = $rule['rule_id'] . '|' . $rule['label'] . '||0.00';
            }
            for ($k = 0; $k < count($rule['items']); $k++) {
                $after[] = $rule['rule_id'] . '|' . $rule['label'] . '|' . $rule['items'][$k]['item_code']
                         . '|' . sprintf('%.2f', $rule['items'][$k]['qty']);
                $n_items++;
            }
        }
        $is_changed = ($before === null || implode("\n", $before) !== implode("\n", $after));

        if ($is_changed) {
            $ok = welfare_master_exec("DELETE FROM `tb_welfare_uniform_rule` WHERE `welfare_site_id` = ?",
                                      'i', array($sid));
            if ($ok < 0) { db_rollback(); return db_fail('ล้างชุดฟอร์มของหน่วยงาน id ' . $sid . ' ไม่สำเร็จ'); }

            for ($r = 0; $r < count($u['rules']); $r++) {
                $rule  = $u['rules'][$r];
                $items = $rule['items'];
                if (count($items) === 0) { $items = array(array('item_code' => '', 'qty' => 0)); }

                for ($k = 0; $k < count($items); $k++) {
                    $ok = welfare_master_exec(
                        "INSERT INTO `tb_welfare_uniform_rule`
                         SET `welfare_site_id` = ?, `rule_id` = ?, `rule_label` = ?, `rule_sort` = ?,
                             `item_code` = ?, `qty` = ?, `item_sort` = ?,
                             `created_at` = NOW(), `created_by` = ?",
                        'iisisdis', array($sid, $rule['rule_id'], $rule['label'], $r + 1,
                                          $items[$k]['item_code'], $items[$k]['qty'], $k + 1, $actor));
                    if ($ok <= 0) {
                        db_rollback();
                        return db_fail('บันทึกชุดฟอร์มของหน่วยงาน id ' . $sid . ' ไม่สำเร็จ');
                    }
                }
            }

            $changed++;
            $log[] = 'ชุดฟอร์มรอบเริ่มงาน site ' . $sid . ': ' . count($u['rules']) . ' เงื่อนไข '
                   . $n_items . ' รายการ' . ($before === null || count($before) === 0 ? ' (ครั้งแรก)' : ' (แทนชุดเดิม)');
        }

        $ok = welfare_master_exec(
            "UPDATE `tb_welfare_site_map` SET `uniform_synced_at` = NOW(), `updated_by` = ?
             WHERE `welfare_site_id` = ?",
            'si', array($actor, $sid));
        if ($ok < 0) { db_rollback(); return db_fail('บันทึกเวลารับชุดฟอร์มของหน่วยงาน id ' . $sid . ' ไม่สำเร็จ'); }

        $res_uniform[] = array('site_id' => $sid, 'rules' => count($u['rules']), 'items' => $n_items,
                               'changed' => $is_changed);
    }

    /* ---------- ประวัติ ---------- */
    $ok = welfare_master_exec(
        "INSERT INTO `tb_welfare_sync_log`
         SET `requested_by_name` = ?, `company_count` = ?, `site_count` = ?,
             `created_count` = ?, `changed_count` = ?, `detail` = ?,
             `created_at` = NOW(), `created_by` = ?",
        'siiiiss', array($by_name, count($in['companies']), count($in['sites']),
                         $created, $changed, implode("\n", $log), $actor));
    if ($ok <= 0) { db_rollback(); return db_fail('บันทึกประวัติการรับข้อมูลไม่สำเร็จ'); }

    db_commit();

    $msg = 'รับบริษัท ' . count($in['companies']) . ' แห่ง หน่วยงาน ' . count($in['sites']) . ' แห่ง'
         . (count($in['uniform']) > 0 ? ' ชุดฟอร์ม ' . count($in['uniform']) . ' หน่วยงาน' : '')
         . ' — สร้างใหม่ ' . $created . ', แก้ไข ' . $changed;
    $out = db_ok($msg, $ok);
    $out['companies']     = $res_companies;
    $out['sites']         = $res_sites;
    $out['uniform_start'] = $res_uniform;
    $out['created']   = $created;
    $out['changed']   = $changed;
    return $out;
}
