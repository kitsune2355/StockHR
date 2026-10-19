<?php
/* ============================================================
 *  backend/welfare_uniform.php — ชุดฟอร์มรอบเริ่มงานตามเงื่อนไขสวัสดิการ (อ่านอย่างเดียว)
 *
 *  ระบบสวัสดิการเป็นต้นทางของชุดฟอร์มพนักงานใหม่ (rules.html แท็บชุดฟอร์ม)
 *  ส่งมากับ sync_master ช่อง uniform_start แล้วเก็บใน tb_welfare_uniform_rule (ดู sql/082)
 *  ไฟล์นี้มีแค่ฝั่งอ่าน ฝั่งเขียนอยู่ใน backend/welfare_master.php
 *
 *  ใช้สองเรื่อง
 *    1) แผง "ชุดฟอร์มจากเงื่อนไขสวัสดิการ" ในหน้าอุปกรณ์พนักงานใหม่และฟอร์มเบิกพนักงานใหม่
 *    2) แยก "สินค้าชุดฟอร์ม" ออกจากชุดของของสต็อก (tb_new_staff_kit)
 *       สินค้าชุดฟอร์ม = สินค้าที่หมวดย่อยถูกจับคู่ใน tb_welfare_item_map
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/thai_date.php';


/* ---------- ตาราง/คอลัมน์ของ sql/082 มีแล้วหรือยัง ---------- */
function welfare_uniform_ready()
{
    static $ok = null;
    if ($ok !== null) { return $ok; }

    $connect = db_connect();
    $res = @mysqli_query($connect, "SHOW TABLES LIKE 'tb_welfare_uniform_rule'");
    $ok = ($res && mysqli_num_rows($res) > 0);
    if ($res) { mysqli_free_result($res); }
    return $ok;
}


/* ---------- หมวดย่อยที่เป็นชุดฟอร์ม: sub_category_id => true ----------
 * ตาราง tb_welfare_item_map มาจาก sql/075 ถ้ายังไม่มี = ไม่มีสินค้าชุดฟอร์ม */
function welfare_uniform_sub_categories()
{
    static $map = null;
    if ($map !== null) { return $map; }

    $map = array();
    $connect = db_connect();
    $res = @mysqli_query($connect, "SELECT DISTINCT `sub_category_id` FROM `tb_welfare_item_map`");
    if (!$res) { return $map; }
    while ($r = mysqli_fetch_row($res)) { $map[(int)$r[0]] = true; }
    mysqli_free_result($res);
    return $map;
}


/* ---------- สินค้านี้เป็นชุดฟอร์มไหม ---------- */
function welfare_uniform_is_product($product_id)
{
    $subs = welfare_uniform_sub_categories();
    if (count($subs) === 0) { return false; }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect, "SELECT `sub_category_id` FROM `tb_product` WHERE `product_id` = ? LIMIT 1");
    if (!$stmt) { return false; }
    $product_id = (int)$product_id;
    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $sub);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return ($found && isset($subs[(int)$sub]));
}


/* ---------- ตัดสินค้าชุดฟอร์มออกจากรายการสินค้า (แถวต้องมี sub_category_id) ---------- */
function welfare_uniform_strip_products($rows)
{
    $subs = welfare_uniform_sub_categories();
    if (count($subs) === 0) { return $rows; }

    $out = array();
    for ($i = 0; $i < count($rows); $i++) {
        $sub = isset($rows[$i]['sub_category_id']) ? (int)$rows[$i]['sub_category_id'] : 0;
        if (!isset($subs[$sub])) { $out[] = $rows[$i]; }
    }
    return $out;
}


/* ---------- ชื่อแบบสินค้าของรายการชุดฟอร์มหนึ่งรายการ ----------
 * item_code -> หมวดย่อยที่จับคู่ไว้ (เลือกโลโก้ของบริษัท หรือแบบที่ใช้ได้ทุกบริษัท)
 * คืน array ของชื่อหมวดย่อย ว่าง = ยังไม่ได้จับคู่ในสต็อก */
function welfare_uniform_item_names($item_code, $logo)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT s.`sub_category_name`
         FROM `tb_welfare_item_map` m
         JOIN `tb_sub_category` s ON s.`sub_category_id` = m.`sub_category_id`
         WHERE m.`item_code` = ? AND (m.`logo` = ? OR m.`logo` = '')
         ORDER BY m.`sort_order` ASC, m.`map_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'ss', $item_code, $logo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $name);
    $names = array();
    while (mysqli_stmt_fetch($stmt)) { $names[] = $name; }
    mysqli_stmt_close($stmt);
    return $names;
}


/* ---------- เงื่อนไขของหน่วยงานหนึ่งแห่ง ---------- */
function welfare_uniform_site_rules($site_id, $logo)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT `rule_id`, `rule_label`, `item_code`, `qty`
         FROM `tb_welfare_uniform_rule`
         WHERE `welfare_site_id` = ?
         ORDER BY `rule_sort` ASC, `item_sort` ASC, `row_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $site_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $rule_id, $label, $code, $qty);

    $raw = array();
    while (mysqli_stmt_fetch($stmt)) {
        $raw[] = array('rule_id' => (int)$rule_id, 'label' => $label, 'item_code' => $code, 'qty' => (float)$qty);
    }
    mysqli_stmt_close($stmt);

    /* รวมแถวเป็นเงื่อนไข -> รายการ (ต้องปิด statement ก่อนค่อยถามชื่อสินค้า) */
    $rules = array();
    $index = array();
    $names = array();
    for ($i = 0; $i < count($raw); $i++) {
        $r = $raw[$i];
        if (!isset($index[$r['rule_id']])) {
            $index[$r['rule_id']] = count($rules);
            $rules[] = array('rule_id' => $r['rule_id'], 'label' => $r['label'], 'items' => array());
        }
        if ($r['item_code'] === '') { continue; }

        if (!isset($names[$r['item_code']])) {
            $names[$r['item_code']] = welfare_uniform_item_names($r['item_code'], $logo);
        }
        $rules[$index[$r['rule_id']]]['items'][] = array(
            'item_code' => $r['item_code'],
            'qty'       => $r['qty'],
            'names'     => $names[$r['item_code']],
            'mapped'    => (count($names[$r['item_code']]) > 0)
        );
    }
    return $rules;
}


/* ============================================================
 *  แผงชุดฟอร์มของแผนกหนึ่งแผนก
 *
 *  คืน array(
 *    'ready'   => bool   ตารางของ 082 มีแล้ว
 *    'managed' => bool   แผนกนี้ผูกกับหน่วยงานของระบบสวัสดิการ
 *    'sites'   => array( array('site_id', 'site_name', 'synced', 'synced_at', 'synced_label',
 *                              'rules' => array( array('rule_id', 'label',
 *                                         'items' => array( array('item_code','qty','names','mapped') ) ) ) ) ) )
 *  แผนก 0 (ชุดกลาง) ไม่มีหน่วยงานในระบบสวัสดิการ — managed = false
 * ============================================================ */
function welfare_uniform_for_department($department_id)
{
    $out = array('ready' => welfare_uniform_ready(), 'managed' => false, 'sites' => array());
    $department_id = (int)$department_id;
    if (!$out['ready'] || $department_id <= 0) { return $out; }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT m.`welfare_site_id`, m.`welfare_site_name`, m.`uniform_synced_at`,
                COALESCE(c.`company_code`, '')
         FROM `tb_welfare_site_map` m
         JOIN `tb_department` d ON d.`department_id` = m.`department_id`
         LEFT JOIN `tb_company` c ON c.`company_id` = d.`company_id`
         WHERE m.`department_id` = ?
         ORDER BY m.`welfare_site_id` ASC");
    if (!$stmt) { return $out; }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $site_id, $site_name, $synced_at, $logo);
    $sites = array();
    while (mysqli_stmt_fetch($stmt)) {
        $sites[] = array('site_id' => (int)$site_id, 'site_name' => $site_name,
                         'synced_at' => $synced_at, 'logo' => $logo);
    }
    mysqli_stmt_close($stmt);

    for ($i = 0; $i < count($sites); $i++) {
        $s = $sites[$i];
        $out['sites'][] = array(
            'site_id'      => $s['site_id'],
            'site_name'    => $s['site_name'],
            'synced'       => ($s['synced_at'] !== null),
            'synced_at'    => ($s['synced_at'] === null) ? '' : $s['synced_at'],
            'synced_label' => ($s['synced_at'] === null) ? '' : thai_date_time($s['synced_at'], ''),
            'rules'        => ($s['synced_at'] === null) ? array() : welfare_uniform_site_rules($s['site_id'], $s['logo'])
        );
    }
    $out['managed'] = (count($out['sites']) > 0);
    return $out;
}
