<?php
/* ============================================================
 *  backend/print_setting.php — ค่าตั้งของเอกสารพิมพ์ (print_doc.php) แยกรายประเภทเอกสาร
 *
 *  แก้ได้จากหน้า Admin mode > เอกสารพิมพ์ (pages/print_settings.php)
 *  ประเภท: request (ใบเบิก) · uniform (เอกสารรับชุดเครื่องแบบ 019-HRM) · issue (ใบจ่าย) · receive (ใบรับเข้า) · adjust (ใบปรับยอด)
 *  (stock_hr: ใบเบิกพนักงานใหม่และใบจ่ายของใบนั้นพิมพ์เป็นเอกสารรับชุดเครื่องแบบ — ดู backend/uniform_receipt.php)
 *
 *  เก็บใน tb_app_setting คีย์ print_<ประเภท>_<ช่อง> เช่น print_issue_title — ไม่ต้องมี migration
 *  หมายเหตุ (HTML) ยาวเกินคอลัมน์ 500 ตัวอักษร จึงแบ่งเก็บ print_<ประเภท>_notes_0..3
 *  คีย์ที่ยังไม่เคยบันทึกใช้ค่าตั้งต้นในไฟล์นี้ ซึ่งตรงกับหน้าตาใบพิมพ์ก่อนมีหน้าตั้งค่า
 *
 *  ชนิดของค่า (type):  text · bool ('1'/'0') · num (ขนาดตัวอักษร ทีละ 0.5) · int · html (หมายเหตุ)
 *  types = ประเภทเอกสารที่ช่องนี้มีผลจริง (หน้าตั้งค่าซ่อนช่องที่ไม่เกี่ยว)
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/setting.php';

define('PRINT_NOTES_CHUNKS', 4);

function print_types()
{
    return array('request', 'uniform', 'issue', 'receive', 'adjust');
}

function print_setting_defs()
{
    $ALL  = array('request', 'uniform', 'issue', 'receive', 'adjust');
    $FORM = array('request', 'uniform', 'issue', 'receive');      // ใบที่ใช้ตาราง/ช่องลงนามแบบใบเบิก

    $notes_req = '<ol><li>กรณีขอเบิกวัสดุที่นอกเหนือในแบบฟอร์มนี้ให้ส่งรายละเอียดทางอีเมลล์หาเจ้าหน้าที่ฝ่ายทรัพยากรบุคคล</li>'
               . '<li>รอบการส่งใบเบิกและเวลารับของ ให้เป็นไปตามประกาศของฝ่ายทรัพยากรบุคคล</li></ol>';

    return array(
        /* ---------- ชื่อเอกสาร / ป้ายหัวกระดาษ ---------- */
        'title'      => array('type' => 'text', 'max' => 140, 'types' => $ALL, 'default' => array(
                            'request' => 'ใบเบิกวัสดุ', 'uniform' => 'เอกสารรับชุดเครื่องแบบพนักงาน', 'issue' => 'ใบจ่ายวัสดุ',
                            'receive' => 'ใบรับวัสดุเข้าคลัง', 'adjust' => 'ใบปรับยอดสินค้า')),
        'label_dept' => array('type' => 'text', 'max' => 40, 'types' => $ALL, 'default' => 'หน่วยงาน'),
        'label_no'   => array('type' => 'text', 'max' => 40, 'types' => $ALL, 'default' => 'เลขที่'),
        'label_date' => array('type' => 'text', 'max' => 40, 'types' => $ALL, 'default' => 'วันที่'),
        'label_line' => array('type' => 'text', 'max' => 20, 'types' => $ALL, 'default' => 'ที่'),

        /* ---------- ชื่อช่องลงนาม (ใบปรับยอดมี 2 ช่อง) ---------- */
        'sign1' => array('type' => 'text', 'max' => 40, 'types' => $ALL, 'default' => array(
                       'request' => 'ผู้ขอเบิก', 'uniform' => 'ผู้ขอเบิก', 'issue' => 'ผู้ขอเบิก', 'receive' => 'ผู้ขอเบิก',
                       'adjust' => 'ผู้บันทึก / ผู้ตรวจนับ')),
        'sign2' => array('type' => 'text', 'max' => 40, 'types' => $ALL,  'default' => 'ผู้อนุมัติ'),
        'sign3' => array('type' => 'text', 'max' => 40, 'types' => $FORM, 'default' => 'ผู้จ่ายของ'),
        'sign4' => array('type' => 'text', 'max' => 40, 'types' => $FORM, 'default' => array(
                       'request' => 'ผู้รับของ', 'uniform' => 'พนักงาน (ผู้รับชุดเครื่องแบบ)', 'issue' => 'ผู้รับของ',
                       'receive' => 'ผู้รับของ', 'adjust' => '')),

        /* ---------- ข้อมูลที่พิมพ์ ---------- */
        'show_ref'      => array('type' => 'bool', 'types' => array('issue', 'uniform'), 'default' => '1'),   // อ้างอิงใบเบิก
        'show_req_note' => array('type' => 'bool', 'types' => $FORM,                     'default' => '1'),   // หมายเหตุผู้ขอ
        'show_estimate' => array('type' => 'bool', 'types' => array('request', 'uniform'), 'default' => '1'), // * ราคาประมาณการ

        /* ---------- ขนาดตัวอักษร (px = ขนาดบนกระดาษ A4 เมื่อพิมพ์ 100%) ---------- */
        'fs_company' => array('type' => 'num', 'min' => 10, 'max' => 26, 'types' => $ALL, 'default' => '17'),
        'fs_title'   => array('type' => 'num', 'min' => 10, 'max' => 26, 'types' => $ALL, 'default' => '20'),
        'fs_meta'    => array('type' => 'num', 'min' => 9,  'max' => 20, 'types' => $ALL, 'default' => '13'),
        'fs_table'   => array('type' => 'num', 'min' => 9,  'max' => 20, 'types' => $ALL, 'default' => '12.5'),
        'fs_notes'   => array('type' => 'num', 'min' => 9,  'max' => 20, 'types' => $ALL, 'default' => '12'),
        'fs_sign'    => array('type' => 'num', 'min' => 9,  'max' => 20, 'types' => $ALL, 'default' => '12'),

        /* ---------- ตาราง / หมายเหตุท้ายฟอร์ม ---------- */
        'blank_rows' => array('type' => 'int', 'min' => 0, 'max' => 20, 'types' => $ALL, 'default' => '8'),
        /* เอกสารรับชุดเครื่องแบบ: หมายเหตุ = เงื่อนไขที่พนักงานรับทราบ ใช้ทั้งใบพิมพ์และหน้าเซ็นรับ (sign.php)
           ยังไม่เคยตั้งที่นี่ = ใช้ uniform_receipt_terms() (ค่าตั้ง uniform_receipt_terms / ข้อความตามแบบฟอร์มกระดาษ) */
        'notes'      => array('type' => 'html', 'max' => 1900, 'types' => $ALL,
                            'default' => array('request' => $notes_req, 'uniform' => '@uniform_terms', 'issue' => $notes_req,
                                               'receive' => $notes_req, 'adjust' => '')),
        /* เลขข้อแรกของรายการเลขข้อในหมายเหตุ — แบบฟอร์ม 019-HRM เริ่มที่ข้อ 2 (ข้อ 1 คือตารางรายการ) */
        'notes_start'=> array('type' => 'int', 'min' => 1, 'max' => 50, 'types' => $ALL,
                            'default' => array('request' => '1', 'uniform' => '2', 'issue' => '1', 'receive' => '1', 'adjust' => '1')),
        /* ใบจ่าย: ใช้หมายเหตุ (เงื่อนไข + เลขข้อเริ่ม + ข้อความรับทราบ) ชุดเดียวกับเอกสารรับชุดเครื่องแบบ
           ระบบนี้จ่ายชุดเครื่องแบบเป็นหลัก เงื่อนไขการรับชุดจึงต้องอยู่บนใบจ่ายด้วย — แก้ที่แท็บเครื่องแบบที่เดียว */
        'notes_same_uniform' => array('type' => 'bool', 'types' => array('issue'), 'default' => '1'),
        /* ข้อความรับทราบใต้หมายเหตุของเอกสารรับชุดเครื่องแบบ */
        'ack'        => array('type' => 'text', 'max' => 140, 'types' => array('uniform'), 'default' => 'ได้รับเครื่องแบบพนักงานครบถ้วนแล้ว'),
    );
}

/* ค่าตั้งต้นของช่องหนึ่งสำหรับประเภทหนึ่ง */
function print_default($def, $type)
{
    $d = $def['default'];
    if (is_array($d)) { $d = isset($d[$type]) ? $d[$type] : ''; }
    if ($d === '@uniform_terms') {
        require_once dirname(__FILE__) . '/uniform_receipt.php';
        $li = '';
        foreach (uniform_receipt_terms() as $ln) {
            $li .= '<li>' . htmlspecialchars($ln, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $d = ($li !== '') ? '<ol>' . $li . '</ol>' : '';
    }
    if ($d === '@new_staff_terms') {
        $li = '';
        foreach (explode("\n", str_replace("\r", '', setting_get('new_staff_terms', ''))) as $ln) {
            if (trim($ln) !== '') { $li .= '<li>' . htmlspecialchars(trim($ln), ENT_QUOTES, 'UTF-8') . '</li>'; }
        }
        $d = ($li !== '') ? '<ol>' . $li . '</ol>' : '';
    }
    return $d;
}


/* ---------- ทำความสะอาด HTML ของหมายเหตุ ----------
 * เหลือเฉพาะแท็กจัดรูปแบบพื้นฐาน และตัด attribute ทิ้งทั้งหมด (กัน onclick= style= javascript: ฯลฯ)
 * ข้อความนี้ถูกพิมพ์ลงใบที่ทุกคนเปิดได้ ต้องไม่มีทางฝังสคริปต์เข้าไปได้แม้คนแก้คือ super_admin
 */
function print_clean_html($html)
{
    $html = str_replace("\r", '', (string)$html);
    $html = strip_tags($html, '<p><br><div><ol><ul><li><b><strong><i><em><u>');
    $html = preg_replace('/<(p|br|div|ol|ul|li|b|strong|i|em|u)\b[^>]*>/i', '<$1>', $html);
    $html = preg_replace('/<div>/i', '<p>', $html);
    $html = preg_replace('/<\/div>/i', '</p>', $html);
    $html = preg_replace('/<p>(\s|&nbsp;|<br>)*<\/p>/i', '', $html);
    $html = preg_replace('/(<br>\s*)+$/i', '', trim($html));
    return trim($html);
}

/* HTML หมายเหตุพร้อมพิมพ์: ใส่เลขข้อเริ่มต้นให้รายการเลขข้อชุดแรก (notes_start)
   ตัวทำความสะอาดตัด attribute ทุกตัวทิ้ง จึงเก็บเลขเริ่มแยกไว้ แล้วเติมตอนแสดงผลที่นี่ที่เดียว */
function print_notes_render($cfg)
{
    $html  = $cfg['notes'];
    $start = isset($cfg['notes_start']) ? (int)$cfg['notes_start'] : 1;
    if ($start > 1) {
        $html = preg_replace('/<ol>/', '<ol start="' . $start . '">', $html, 1);
    }
    return $html;
}

/* หมายเหตุที่ใช้พิมพ์จริงของเอกสารประเภทหนึ่ง
   ใบจ่ายที่เปิด "ใช้หมายเหตุเดียวกับเอกสารรับชุดเครื่องแบบ" = ดึงหมายเหตุ เลขข้อเริ่ม และข้อความรับทราบของแท็บเครื่องแบบ
   คืน array('notes' => HTML พร้อมพิมพ์, 'ack' => ข้อความรับทราบ หรือ '') */
function print_notes_for($cfg, $type)
{
    if ($type === 'issue' && isset($cfg['notes_same_uniform']) && $cfg['notes_same_uniform'] === '1') {
        $u = print_cfg('uniform');
        return array('notes' => print_notes_render($u), 'has' => print_notes_has_text($u['notes']), 'ack' => trim($u['ack']));
    }
    return array('notes' => print_notes_render($cfg), 'has' => print_notes_has_text($cfg['notes']),
                 'ack' => ($type === 'uniform') ? trim($cfg['ack']) : '');
}

/* หมายเหตุมีเนื้อหาจริงไหม — ว่าง = ไม่พิมพ์กล่องหมายเหตุ */
function print_notes_has_text($html)
{
    return trim(str_replace('&nbsp;', ' ', strip_tags($html))) !== '';
}


/* ---------- ค่าที่ใช้จริงของประเภทหนึ่ง (ค่าที่บันทึกไว้ทับค่าตั้งต้น) ----------
 * ข้อความว่างถือว่ายังไม่ได้ตั้ง กลับไปใช้ค่าตั้งต้น — กันหัวกระดาษโล่งเพราะเผลอลบหมด
 * ยกเว้นหมายเหตุที่ตั้งใจให้ว่างได้ (= ไม่พิมพ์กล่องหมายเหตุ)
 *
 * $GLOBALS['PRINT_CFG_PREVIEW'] = array('type' => ..., 'values' => ...) ค่าที่ยังไม่บันทึกจากหน้าตั้งค่า
 * ใช้วาดตัวอย่างทันที (ดู print_doc.php) — ไม่ได้ลงฐานข้อมูล
 */
function print_cfg($type)
{
    static $cache = array();
    if (!in_array($type, print_types())) { $type = 'request'; }
    if (isset($cache[$type])) { return $cache[$type]; }

    $all = setting_all();
    $cfg = array();
    foreach (print_setting_defs() as $f => $def) {
        $k = 'print_' . $type . '_' . $f;

        if ($def['type'] === 'html') {
            $found = false;
            $val = '';
            for ($i = 0; $i < PRINT_NOTES_CHUNKS; $i++) {
                if (isset($all[$k . '_' . $i])) { $found = true; $val .= (string)$all[$k . '_' . $i]['value']; }
            }
            $cfg[$f] = print_clean_html($found ? $val : print_default($def, $type));
            continue;
        }

        $val = isset($all[$k]) ? (string)$all[$k]['value'] : print_default($def, $type);
        if ($def['type'] === 'text' && trim($val) === '') { $val = print_default($def, $type); }
        $cfg[$f] = $val;
    }

    if (isset($GLOBALS['PRINT_CFG_PREVIEW']['type']) && $GLOBALS['PRINT_CFG_PREVIEW']['type'] === $type) {
        $defs = print_setting_defs();
        foreach ($GLOBALS['PRINT_CFG_PREVIEW']['values'] as $f => $v) {
            if (!isset($defs[$f])) { continue; }
            if ($defs[$f]['type'] === 'text' && trim($v) === '') { continue; }
            $cfg[$f] = $v;
        }
    }

    $cache[$type] = $cfg;
    return $cfg;
}

/* ขนาดตัวอักษรที่ปลอดภัยสำหรับใส่ใน CSS (ค่าผิดรูป/นอกช่วง = ค่าตั้งต้น) */
function print_px($cfg, $f)
{
    $defs = print_setting_defs();
    $v = $cfg[$f];
    if (!is_numeric($v) || (float)$v < $defs[$f]['min'] || (float)$v > $defs[$f]['max']) {
        $v = $defs[$f]['default'];
    }
    return (float)$v;
}


/* ---------- ตรวจ/แปลงค่าของประเภทหนึ่ง ----------
 * ใช้ทั้งตอนบันทึกและตอนวาดตัวอย่าง — ช่องที่ผิดถูกตัดทิ้ง พร้อมข้อความของช่องแรกที่ผิด (error)
 */
function print_setting_clean($values)
{
    $defs  = print_setting_defs();
    $clean = array();
    $err   = '';
    if (!is_array($values)) { return array('values' => $clean, 'error' => 'ไม่มีค่าที่จะบันทึก'); }

    foreach ($defs as $f => $def) {
        if (!array_key_exists($f, $values)) { continue; }
        $v = $values[$f];

        if ($def['type'] === 'bool') {
            $clean[$f] = ($v === true || $v === 1 || $v === '1') ? '1' : '0';
        } elseif ($def['type'] === 'num') {
            $n = is_numeric($v) ? round((float)$v * 2) / 2 : -1;
            if ($n < $def['min'] || $n > $def['max']) {
                if ($err === '') { $err = 'ขนาดตัวอักษรต้องอยู่ระหว่าง ' . $def['min'] . ' ถึง ' . $def['max'] . ' px'; }
                continue;
            }
            $clean[$f] = ($n == floor($n)) ? (string)(int)$n : (string)$n;
        } elseif ($def['type'] === 'int') {
            $n = is_numeric($v) ? (int)$v : -1;
            if ($n < $def['min'] || $n > $def['max']) {
                if ($err === '') { $err = 'จำนวนแถวว่างต้องอยู่ระหว่าง ' . $def['min'] . ' ถึง ' . $def['max']; }
                continue;
            }
            $clean[$f] = (string)$n;
        } elseif ($def['type'] === 'html') {
            $v = print_clean_html($v);
            if (db_text_length($v) > $def['max']) {
                if ($err === '') { $err = 'หมายเหตุท้ายฟอร์มยาวเกินไป — กรุณาย่อให้สั้นลง'; }
                continue;
            }
            $clean[$f] = $v;
        } else {
            $v = str_replace(array("\r", "\n"), array('', ' '), trim((string)$v));
            if (db_text_length($v) > $def['max']) {
                if ($err === '') { $err = 'ข้อความยาวเกิน ' . $def['max'] . ' ตัวอักษร — กรุณาย่อให้สั้นลง'; }
                continue;
            }
            $clean[$f] = $v;
        }
    }
    return array('values' => $clean, 'error' => $err);
}


/* ---------- บันทึกทุกประเภทที่ส่งมา ----------
 * $all_values = array('request' => array(ช่อง => ค่า), 'issue' => ...)
 * ตรวจครบทุกประเภทก่อน ถ้ามีช่องผิดไม่บันทึกอะไรเลย
 */
function print_setting_save($all_values, $actor)
{
    if (!is_array($all_values) || count($all_values) === 0) { return db_fail('ไม่มีค่าที่จะบันทึก'); }

    $labels = array('request' => 'ใบเบิก', 'uniform' => 'เอกสารรับชุดเครื่องแบบ', 'issue' => 'ใบจ่าย',
                    'receive' => 'ใบรับเข้า', 'adjust' => 'ใบปรับยอด');
    $defs  = print_setting_defs();
    $store = array();
    $allow = array();

    foreach ($all_values as $type => $values) {
        if (!in_array($type, print_types())) { continue; }
        $c = print_setting_clean($values);
        if ($c['error'] !== '') { return db_fail($labels[$type] . ': ' . $c['error']); }

        foreach ($c['values'] as $f => $v) {
            $k = 'print_' . $type . '_' . $f;
            if ($defs[$f]['type'] !== 'html') { $store[$k] = $v; $allow[] = $k; continue; }

            /* แบ่งท่อนละ 480 ตัวอักษร (เผื่อจากเพดาน 500) ท่อนที่ไม่ใช้เขียนเป็นค่าว่าง */
            for ($i = 0; $i < PRINT_NOTES_CHUNKS; $i++) {
                $part = function_exists('mb_substr') ? mb_substr($v, $i * 480, 480, 'UTF-8') : substr($v, $i * 480, 480);
                $store[$k . '_' . $i] = ($part === false) ? '' : $part;
                $allow[] = $k . '_' . $i;
            }
        }
    }

    return setting_set_many($store, $actor, $allow);
}


/* ============================================================
 *  เอกสารสมมติสำหรับตัวอย่างในหน้าตั้งค่า (print_doc.php?mock=<ประเภท>)
 *  ไม่อ่านเอกสารจริง — ตัวอย่างเหมือนกันทุกครั้ง และมีครบทุกส่วนที่ตั้งค่าได้
 *  (เอกสารรับชุดเครื่องแบบมีข้อมูลพนักงาน — stock_hr ไม่มีการปรับจำนวนตอนอนุมัติ จึงไม่มีบรรทัดไม่อนุมัติ)
 *  อ่านฐานข้อมูลที่เดียว: หน่วยงานแรกที่บริษัทมีโลโก้ ให้หัวกระดาษมีโลโก้เหมือนของจริง
 * ============================================================ */
function print_mock_item($no, $code, $name, $unit, $qty, $price, $extra = array())
{
    $it = array(
        'item_id' => -$no, 'line_no' => $no, 'product_id' => -$no,
        'product_code' => $code, 'product_name' => $name, 'unit_name' => $unit,
        'qty' => $qty, 'qty_requested' => null, 'qty_issued' => 0, 'price' => $price,
        'amount' => $qty * $price, 'unit_cost' => 0, 'total_cost' => 0, 'note' => '',
        'must_return' => false, 'balance' => 0
    );
    foreach ($extra as $k => $v) { $it[$k] = $v; }
    $it['amount'] = $it['qty'] * $it['price'];
    return $it;
}

function print_mock_doc($type)
{
    if (!in_array($type, print_types())) { return null; }

    $dept_id = 0;
    $r = @mysqli_query(db_connect(),
        "SELECT d.`department_id` FROM `tb_department` d INNER JOIN `tb_company` c ON c.`company_id` = d.`company_id`
         WHERE c.`logo_file` <> '' AND c.`is_active` = 1 ORDER BY c.`company_id`, d.`department_id` LIMIT 1");
    if ($r) { $row = mysqli_fetch_row($r); if ($row) { $dept_id = (int)$row[0]; } mysqli_free_result($r); }

    $today = date('Y-m-d');
    $ymd   = sprintf('%02d%02d', ((int)date('Y') + 543) % 100, (int)date('n'));   // เลขที่เอกสารใช้ปี พ.ศ. 2 หลัก + เดือน
    $doc = array(
        'doc_id' => 0, 'doc_type' => ($type === 'uniform') ? 'request' : $type,
        'doc_type_label' => '', 'doc_no' => 'ตัวอย่าง-0001', 'doc_date' => $today,
        'department_id' => $dept_id, 'department_name' => 'หน่วยงานตัวอย่าง',
        'requester' => '__mock__', 'requester_name' => 'สมชาย ตัวอย่าง',
        'status' => 'approved', 'status_label' => 'อนุมัติแล้ว',
        'ref_doc_id' => 0, 'ref_doc_no' => '', 'note' => 'ใช้ในงานประจำเดือน (ตัวอย่างหมายเหตุผู้ขอ)',
        'approved_by' => '__mock_approver__', 'approved_by_name' => 'สมหญิง ผู้อนุมัติ',
        'approved_at' => $today . ' 10:30:00', 'approve_note' => '',
        'created_by' => '__mock__', 'created_by_name' => 'สมชาย ตัวอย่าง',
        'received_at' => '', 'receiver_name' => '', 'employee_receiver' => '', 'cancel_note' => '',
        'new_staff' => null, 'is_new_staff' => false,
        'items' => array(
            print_mock_item(1, 'EX-SHT-001', 'เสื้อยูนิฟอร์ม แขนสั้น ไซซ์ M', 'ตัว', 3, 250),
            print_mock_item(2, 'EX-PNT-002', 'กางเกงยูนิฟอร์ม เอว 32', 'ตัว', 2, 320),
            print_mock_item(3, 'EX-SHO-003', 'รองเท้าเซฟตี้ เบอร์ 41', 'คู่', 1, 650),
        ),
        '_mock_ref_off' => array(),
    );

    if ($type === 'request') {
        $doc['doc_no'] = 'RQ-' . $ymd . '-0001';
    } elseif ($type === 'uniform') {
        /* ใบเบิกพนักงานใหม่ = พิมพ์เป็นเอกสารรับชุดเครื่องแบบ (uniform_receipt_for_doc ดูจาก new_staff) */
        $doc['doc_no'] = 'NRQ-' . $ymd . '-0001';
        $doc['is_new_staff'] = true;
        $doc['new_staff'] = array('employee_name' => 'สมศักดิ์ พนักงานใหม่', 'employee_code' => 'EMP0001',
                                  'position_name' => 'พนักงานต้อนรับ', 'start_date' => date('Y-m-d', strtotime('+7 days')));
    } elseif ($type === 'issue') {
        $doc['doc_no'] = 'IS-' . $ymd . '-0001';
        $doc['status'] = 'issued'; $doc['status_label'] = 'จ่ายแล้ว';
        $doc['ref_doc_no'] = 'RQ-' . $ymd . '-0001';
        $doc['created_by_name'] = 'สมปอง เจ้าหน้าที่คลัง';
    } elseif ($type === 'receive') {
        $doc['doc_no'] = 'RC-' . $ymd . '-0001';
        $doc['status'] = 'received'; $doc['status_label'] = 'รับเข้าแล้ว';
        $doc['approved_by'] = ''; $doc['approved_by_name'] = ''; $doc['approved_at'] = '';
        $doc['note'] = 'ใบส่งของเลขที่ INV-0001 (ตัวอย่าง)';
    } elseif ($type === 'adjust') {
        $doc['doc_no'] = 'ADJ-' . $ymd . '-0001';
        $doc['status'] = 'waiting'; $doc['status_label'] = 'รออนุมัติ';
        $doc['approved_by'] = ''; $doc['approved_by_name'] = ''; $doc['approved_at'] = '';
        $doc['note'] = 'ตรวจนับประจำเดือน (ตัวอย่าง)';
        /* adjust_doc_lines(): ยอดเดิม = balance, นับได้ = qty */
        $doc['items'][0]['balance'] = 5;
        $doc['items'][1]['balance'] = 2;
        $doc['items'][2]['balance'] = 0;
    }
    return $doc;
}
