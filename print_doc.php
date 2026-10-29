<?php
/* ============================================================
 *  print_doc.php — หน้าพิมพ์เอกสาร (ใบเบิก / ใบจ่าย / ใบรับเข้า)
 *
 *  เปิดในแท็บใหม่จากปุ่ม "พิมพ์" ในกล่องรายละเอียดเอกสาร
 *      print_doc.php?doc_id=45
 *
 *  ---------------------------------------------------------------
 *  ทำไมเป็นไฟล์แยก ไม่ใช่หน้าใน main.php
 *  ---------------------------------------------------------------
 *  เอกสารที่พิมพ์ต้องไม่มีเมนูข้าง แถบบน หรือกรอบที่เลื่อนได้
 *  ถ้าอยู่ใน main.php ต้องเขียน @media print ไปซ่อนทีละชิ้น ซึ่งจะพังทุกครั้ง
 *  ที่มีคนเพิ่มอะไรใหม่เข้าไปในกรอบหน้าโดยไม่รู้ว่ามีกติกานี้อยู่
 *
 *  แยกไฟล์แล้วได้กระดาษเปล่าจริง ๆ ตั้งแต่แรก และหน้านี้ควบคุมทุกอย่างเอง
 *
 *  ---------------------------------------------------------------
 *  สิทธิ์การเข้าถึง
 *  ---------------------------------------------------------------
 *  ใช้กติกาเดียวกับ api/documents.php action "get" เป๊ะ ๆ
 *  ไม่งั้นคนที่เปิดใบของหน่วยงานอื่นผ่าน API ไม่ได้ จะมาเปิดอ่านทางนี้แทน
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

require_once dirname(__FILE__) . '/backend/sign_link.php';

$doc_id = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;

/* ---------- ทางเข้าที่สอง: กุญแจลิงก์เซ็นรับของ ----------
 * ผู้เบิกที่เพิ่งเซ็นรับผ่านลิงก์ในอีเมลต้องพิมพ์ใบที่มีลายเซ็นตัวเองไว้เป็นหลักฐานได้
 * ถ้าไม่เปิดทางนี้ เขาจะเซ็นเสร็จแล้วไม่มีอะไรติดมือกลับไปเลย
 *
 * กุญแจต้องตรงกับใบที่ขอพิมพ์เท่านั้น ถือกุญแจของใบหนึ่งแล้วเปลี่ยนเลข doc_id
 * ไปพิมพ์ใบอื่นไม่ได้ — เทียบ doc_id ที่กุญแจชี้ไปกับที่ขอมาตรง ๆ
 *
 * ตรวจก่อน checksession เพราะยามตัวนั้นเด้งไปหน้าล็อกอินทันทีเมื่อไม่มี session
 * ซึ่งคือสิ่งที่คนถือลิงก์ไม่มีอยู่แล้ว
 */
$by_token = false;
$tk       = isset($_GET['t']) ? $_GET['t'] : '';

if ($tk !== '') {
    $tchk = sign_link_find($tk);
    if ($tchk['ok'] && $tchk['doc_id'] === $doc_id && $doc_id > 0) { $by_token = true; }
}

if (!$by_token) {
    include "config_ctrl/checksession.php";
}

require_once dirname(__FILE__) . '/backend/thai_date.php';
require_once dirname(__FILE__) . '/backend/document.php';
require_once dirname(__FILE__) . '/backend/user.php';
require_once dirname(__FILE__) . '/backend/company_logo.php';
require_once dirname(__FILE__) . '/backend/adjust.php';
/* ข้อความ/ส่วนที่พิมพ์ ตั้งได้ที่ Admin mode > เอกสารพิมพ์ (คีย์ print_<ประเภท>_<ช่อง> ใน tb_app_setting) */
require_once dirname(__FILE__) . '/backend/print_setting.php';

/* ตัวอย่างแบบทันทีจากหน้า Admin mode > เอกสารพิมพ์: ส่งค่าที่ยังไม่บันทึกมาทาง POST
   ใช้วาดหน้านี้ครั้งเดียว ไม่ลงฐานข้อมูล — เฉพาะ super_admin ที่ล็อกอินอยู่ (ไม่ใช่ผ่านลิงก์เซ็นรับ) */
if (!$by_token && isset($sess_role) && $sess_role === 'super_admin'
    && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_cfg'])) {
    $pv_raw = (string)$_POST['preview_cfg'];
    if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) { $pv_raw = stripslashes($pv_raw); }   // PHP 5.2 บางเครื่องเปิด magic quotes
    $pv_in = json_decode($pv_raw, true);
    if (is_array($pv_in) && isset($pv_in['type']) && isset($pv_in['values'])) {
        $pv = print_setting_clean($pv_in['values']);
        /* ค่าที่ไม่ผ่าน (เช่นตัวเลขนอกช่วง) ถูกตัดทิ้ง ใช้ค่าที่บันทึกไว้แทน */
        $GLOBALS['PRINT_CFG_PREVIEW'] = array('type' => (string)$pv_in['type'], 'values' => $pv['values']);
    }
}
/* ค่าตั้งแยกรายประเภทเอกสาร — ใช้ของใบเบิกไปก่อน แล้วเปลี่ยนเป็นของประเภทจริงหลังโหลดเอกสาร (ดู $print_type) */
$PC = print_cfg('request');

/* ตัวอย่างในหน้า Admin mode > เอกสารพิมพ์: ?mock=<ประเภท> = เอกสารสมมติ ไม่อ่านเอกสารจริง
   เฉพาะ super_admin ที่ล็อกอินอยู่ — คนอื่นส่ง mock มาก็ถูกเมิน กลับไปใช้ doc_id ตามปกติ */
$is_mock = (!$by_token && isset($sess_role) && $sess_role === 'super_admin'
            && isset($_GET['mock']) && in_array($_GET['mock'], print_types()));
$doc = $is_mock ? print_mock_doc($_GET['mock']) : doc_find($doc_id);

$deny = '';
if ($doc === null) {
    $deny = 'ไม่พบเอกสารที่ต้องการพิมพ์';

} elseif (!$is_mock && !$by_token && !doc_can_read($doc, $sess_username, $sess_role)) {
    /* กติกาเดียวกับ api/documents.php เป๊ะ ๆ เพราะเรียกฟังก์ชันตัวเดียวกัน
       ถ้าเขียนแยกกัน วันหนึ่งจะแก้ที่เดียวแล้วเหลือรูรั่วไว้อีกทาง */
    $deny = 'คุณไม่มีสิทธิ์พิมพ์เอกสารใบนี้';
}

/* ---------- ตัวช่วยจัดรูปแบบ ---------- */
function pd_esc($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function pd_money($value)
{
    return number_format((float)$value, 2);
}

/* แสดงจำนวนแบบไม่มีทศนิยมถ้าเป็นจำนวนเต็ม — "5 ด้าม" อ่านง่ายกว่า "5.00 ด้าม" */
function pd_qty($value)
{
    $value = (float)$value;
    return ($value == (int)$value) ? number_format($value) : number_format($value, 2);
}

/* วันที่แบบไทยย้ายไปอยู่ที่ backend/thai_date.php แล้ว
   ใบที่พิมพ์ใช้แบบปีเต็ม (13 สิงหาคม 2569) เพราะกระดาษใบนี้จะถูกอ่าน
   ตอนไหนก็ได้ โดยคนที่ไม่มีบริบทว่าเป็นปีไหน */


if ($deny === '') {

    $company = company_logo_for_department($doc['department_id']);
    $logo    = company_logo_url($company['logo_file']);

    /* ช่องลงนามต้องเป็นชื่อ-นามสกุลจริง ไม่ใช่ชื่อผู้ใช้ที่ใช้ล็อกอิน
       เอกสารที่พิมพ์ออกไปเป็นหลักฐานให้คนนอกระบบอ่าน คนที่เห็นคำว่า
       "wh_admin" บนช่องผู้อนุมัติจะไม่รู้ว่าใครเป็นคนเซ็น */
    /* ลายเซ็นดึงแยก ไม่ได้มากับ doc_find() — ดูเหตุผลที่ doc_receiver_sign() */
    $receiver_sign = doc_receiver_sign($doc['doc_id']);

    /* ชื่อเต็มจาก username — ถ้าหาไม่เจอก็คืน username ไปดีกว่าเว้นว่าง
       อย่างน้อยยังตามตัวคนได้ ใช้เป็น fallback สำรองเท่านั้น (ดูด้านล่าง) */
    function pd_full_name($username)
    {
        $username = trim((string)$username);
        if ($username === '') { return ''; }

        $u = user_find($username);
        return ($u !== null && trim($u['full_name']) !== '') ? $u['full_name'] : $username;
    }

    /* ชื่อบนเอกสารที่พิมพ์ต้องเป็นชื่อ ณ ตอนเซ็นจริง ไม่ใช่ชื่อปัจจุบันของ username นั้น
       doc_find() คืนชื่อที่แช่แข็งไว้แล้ว (approved_by_name/created_by_name — ดู sql/071)
       เผื่อ fallback ไป pd_full_name() เฉพาะเอกสารเก่าก่อน migration ที่ค่านี้ยังว่างอยู่ */
    /* ใบจ่ายไม่มีขั้นอนุมัติของตัวเอง (อนุมัติผ่านมาแล้วตอนใบเบิก — ดู backend/issue.php)
       approved_by ของใบจ่ายจึงว่างเสมอ ถ้าว่างและมีใบเบิกอ้างอิง ดึงผู้อนุมัติจากใบเบิกต้นทางมาแสดงแทน
       ไม่งั้นช่องผู้อนุมัติจะว่างทั้งที่จริงมีคนอนุมัติไปแล้วตอนใบเบิก */
    $approved_by      = $doc['approved_by'];
    $approved_by_name = $doc['approved_by_name'];
    $approved_at      = $doc['approved_at'];
    if ($doc['doc_type'] === 'issue' && $approved_by === '' && $doc['ref_doc_id'] > 0) {
        $ref_request = doc_find($doc['ref_doc_id']);
        if ($ref_request !== null) {
            $approved_by      = $ref_request['approved_by'];
            $approved_by_name = $ref_request['approved_by_name'];
            $approved_at      = $ref_request['approved_at'];
        }
    }

    $approver_name = ($approved_by_name !== '')
                    ? $approved_by_name : pd_full_name($approved_by);

    /* ผู้จ่ายของ = คนที่บันทึกใบจ่าย มีเฉพาะใบจ่ายออก
       ใบรับเข้ากับใบเบิกไม่มีการจ่ายของ ช่องนี้จึงเว้นไว้ให้เซ็นด้วยปากกา */
    $issuer_name = ($doc['doc_type'] === 'issue')
                 ? (($doc['created_by_name'] !== '') ? $doc['created_by_name'] : pd_full_name($doc['created_by']))
                 : '';

    $items = $doc['items'];
    $total_qty = 0;
    $total_amt = 0;
    for ($i = 0; $i < count($items); $i++) {
        $total_qty += $items[$i]['qty'];
        $total_amt += $items[$i]['amount'];
    }

    /* ใบเบิกยังไม่เคยแตะสต๊อก ราคาบนบรรทัดจึงเป็นค่าเฉลี่ยปัจจุบัน ไม่ใช่ยอดที่ลงบัญชี
       ต้องเขียนกำกับไว้ ไม่งั้นฝ่ายบัญชีจะเอาตัวเลขนี้ไปใช้เป็นยอดจริง */
    $is_estimate = ($doc['doc_type'] === 'request');

    /* ใบปรับยอด: คอลัมน์คนละชุด (ยอดเดิม/นับได้/ส่วนต่าง) ไม่มีราคา ไม่มีหมายเหตุแบบฟอร์มการเบิก */
    $is_adjust = ($doc['doc_type'] === 'adjust');
    $adj_lines = $is_adjust ? adjust_doc_lines($doc) : array();
    $adj_diff_total = 0;
    for ($i = 0; $i < count($adj_lines); $i++) { $adj_diff_total += $adj_lines[$i]['diff']; }
    $maker_name = $is_adjust
                ? (($doc['created_by_name'] !== '') ? $doc['created_by_name'] : pd_full_name($doc['created_by']))
                : '';

    $print_type = in_array($doc['doc_type'], print_types()) ? $doc['doc_type'] : 'request';

    /* ใบเบิกพนักงานใหม่ (sql/072) — พิมพ์ข้อมูลพนักงานและป้าย "ต้องคืน"
       ใบนี้เป็นหลักฐานตอนพนักงานลาออก ว่ารับอะไรไปและต้องคืนอะไร */
    $new_staff = !empty($doc['new_staff']) ? $doc['new_staff'] : null;

    /* ใบจ่ายของใบเบิกพนักงานใหม่ = "เอกสารรับชุดเครื่องแบบพนักงาน" (แบบฟอร์ม 019-HRM)
       พนักงานเซ็นรับทราบเงื่อนไขการหักค่าชุด/คืนชุดบนใบนี้ ไม่ใช่ใบจ่ายวัสดุทั่วไป
       ข้อมูลพนักงานอยู่ที่ใบเบิกต้นทาง (tb_doc_new_staff ผูกกับใบเบิก ไม่ใช่ใบจ่าย) */
    /* ใบเบิกพนักงานใหม่ (NRQ) เองก็พิมพ์เป็นเอกสารนี้ — HR พิมพ์ใบเบิกให้พนักงานเซ็นรับทราบ
       ตอนรับชุด ไม่ได้รอใบจ่าย */
    /* ข้อความเงื่อนไขและรหัสแบบฟอร์มอยู่ที่ backend/uniform_receipt.php
       ใช้ชุดเดียวกับหน้าเซ็นรับ (sign.php) */
    require_once dirname(__FILE__) . '/backend/uniform_receipt.php';
    $uniform_receipt = uniform_receipt_for_doc($doc, isset($ref_request) ? $ref_request : null);
    if ($uniform_receipt !== null) {
        $print_type   = 'uniform';
        $ur_form_code = uniform_receipt_form_code();
    }

    /* ค่าตั้งของเอกสารประเภทนี้ (Admin mode > เอกสารพิมพ์ แยกตั้งได้ทั้ง 5 แบบ) */
    $PC = print_cfg($print_type);
    $paper_title = $PC['title'];
    /* ใบพนักงานใหม่ที่ไม่ได้พิมพ์เป็นเอกสารรับชุดเครื่องแบบ (กรณีเฉพาะ) — ชื่อใบเบิก + (พนักงานใหม่) ตามเดิม */
    if ($new_staff !== null && $uniform_receipt === null) {
        $paper_title = $PC['title'] . ' (พนักงานใหม่)';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $deny === '' ? pd_esc($paper_title . ' ' . $doc['doc_no']) : 'พิมพ์เอกสาร'; ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">

<style>
/* ============================================================
   หน้ากระดาษ A4 แนวตั้ง
   ใช้หน่วย mm ทั้งหมด เพราะสิ่งที่ต้องตรงคือขนาดบนกระดาษจริง ไม่ใช่บนจอ
   ตั้งขอบ 12mm ให้พอดีกับเครื่องพิมพ์ทั่วไปที่พิมพ์ชิดขอบไม่ได้
   ============================================================ */
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 18px;
    background: #e2e8f0;
    font-family: 'Sarabun', Tahoma, sans-serif;
    font-size: 13px;
    line-height: 1.55;
    color: #111827;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.paper {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 12mm 12mm 10mm;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(15, 23, 42, .18);
}

/* ---------- หัวกระดาษ ---------- */
.ph { display: flex; align-items: flex-start; gap: 14px; border-bottom: 2px solid #111827; padding-bottom: 9px; }
.ph-logo { max-height: 50px; max-width: 100px; object-fit: contain; flex: none; }
.ph-text { flex: 1; min-width: 0; }
.ph-co   { font-size: 17px; font-weight: 700; line-height: 1.35; }
.ph-sub  { font-size: 12px; color: #4b5563; }
.ph-doc  { text-align: right; flex: none; }
.ph-title{ font-size: 20px; font-weight: 700; letter-spacing: .5px; }
.ph-no   { font-size: 13px; font-weight: 600; margin-top: 3px; }
.ph-date { font-size: 12px; color: #4b5563; }

/* ---------- ข้อมูลหัวเอกสาร ---------- */
.meta { display: flex; flex-wrap: wrap; gap: 4px 26px; margin: 10px 0 12px; font-size: 13px; }
.meta div { min-width: 210px; }
.meta b { font-weight: 600; }
.meta span { color: #374151; }

/* ---------- ตารางรายการ ---------- */
table.items { width: 100%; border-collapse: collapse; font-size: 12.5px; }
table.items th, table.items td { border: 1px solid #9ca3af; padding: 5px 7px; vertical-align: top; }
table.items thead th { background: #f3f4f6; font-weight: 600; text-align: center; }
table.items td.c { text-align: center; }
table.items td.r { text-align: right; white-space: nowrap; }
table.items tbody tr:nth-child(even) td { background: #fafafa; }
table.items tfoot td { font-weight: 700; background: #f3f4f6; }

.code { font-family: Consolas, monospace; font-size: 11.5px; color: #374151; }

/* ---------- หมายเหตุ ---------- */
.notes { margin-top: 12px; border: 1px solid #9ca3af; padding: 8px 11px; font-size: 12px; line-height: 1.75; }
.notes-h { font-weight: 700; margin-bottom: 2px; }
.notes ol { margin: 0; padding-left: 18px; }
.notes li { margin-bottom: 1px; }
/* เอกสารรับชุดเครื่องแบบ: ข้อความรับทราบเหนือช่องลงนาม (ตามแบบฟอร์ม 019-HRM) */
.ur-ack { margin: 12px 0 0 28px; font-size: 13px; font-weight: 600; }
.ur-sec { margin: 14px 0 6px; font-size: 13px; font-weight: 600; }

.estimate { margin-top: 7px; font-size: 11.5px; color: #6b7280; }

/* ---------- ช่องเซ็น ----------
   สี่ช่องเรียงแนวนอน ต้องอยู่หน้าเดียวกับตารางเสมอ
   page-break-inside: avoid กันไม่ให้ถูกหั่นครึ่งตอนขึ้นหน้าใหม่ */

.signs { display: flex; gap: 12px; margin-top: 26px; page-break-inside: avoid; }
.sign  { flex: 1; text-align: center; font-size: 12px; }
.sign-line { position: relative; border-bottom: 1px dotted #6b7280; height: 42px; margin-bottom: 5px; }

/* ลายเซ็นวางทับพื้นที่เหนือเส้น ให้ก้นลายเซ็นแตะเส้นพอดีเหมือนเซ็นด้วยปากกาจริง
   จำกัดความสูงไว้ที่ความสูงช่อง ลายเซ็นที่วาดใหญ่เกินจะถูกย่อลงมาให้พอดี */
.sign-img {
    position: absolute;
    left: 50%;
    bottom: 1px;
    transform: translateX(-50%);
    max-height: 40px;
    max-width: 100%;
    object-fit: contain;
}

/* ---------- ชื่อที่พิมพ์แทนลายเซ็น ----------
   ใช้เมื่อไม่มีลายเซ็นที่วาดไว้ วางตำแหน่งเดียวกับลายเซ็นคือเหนือเส้น
   สีน้ำเงินเพื่อให้เห็นชัดว่าเป็นชื่อที่ระบบพิมพ์ให้ ไม่ใช่คนเซ็นด้วยปากกา
   ตัวที่ยาวเกินช่องถูกย่อลง ไม่ปล่อยให้ล้นไปทับช่องข้าง ๆ */
.sign-typed {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 3px;
    font-size: 12.5px;
    font-weight: 700;
    color: #1d4ed8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 2px;
}

/* ---------- รหัสแบบฟอร์มมุมขวาล่าง ----------
   ตัวเล็กและจางกว่าเนื้อหา เพราะเป็นข้อมูลของระบบเอกสาร ไม่ใช่ข้อมูลของรายการที่เบิก
   คนอ่านใบเบิกไม่ต้องสนใจ แต่ฝ่ายเอกสารต้องหาเจอเมื่อต้องการ */
.form-code {
    margin-top: 18px;
    text-align: right;
    font-size: 10.5px;
    letter-spacing: .3px;
    color: #6b7280;
}
.sign-name { min-height: 17px; font-weight: 600; }
.sign-cap  { color: #4b5563; }
.sign-date { color: #9ca3af; font-size: 11px; margin-top: 2px; }

/* ---------- แถบเครื่องมือ (ไม่พิมพ์) ---------- */
.bar {
    width: 210mm; margin: 0 auto 12px; display: flex; gap: 8px; align-items: center;
}
.bar button, .bar a {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 17px; border: 0; border-radius: 9px;
    font-family: inherit; font-size: 14px; font-weight: 600;
    text-decoration: none; cursor: pointer;
}
.bar .primary { background: #0891b2; color: #fff; }
.bar .ghost   { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
.bar .hint    { margin-left: auto; font-size: 12px; color: #475569; }

.deny {
    width: 210mm; margin: 40px auto; padding: 40px; background: #fff;
    border-radius: 12px; text-align: center; font-size: 15px; color: #b91c1c;
}

@media print {
    body   { background: #fff; padding: 0; }
    .bar   { display: none; }
    .paper { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; }

    /* ให้เบราว์เซอร์รู้ว่าเป็น A4 และไม่ต้องเติมหัว/ท้ายกระดาษของตัวเองมาทับ */
    @page { size: A4 portrait; margin: 12mm; }

    table.items thead { display: table-header-group; }   /* ยาวข้ามหน้าแล้วหัวตารางตามไปด้วย */
    table.items tr    { page-break-inside: avoid; }
}

@media (max-width: 230mm) {
    .paper, .bar, .deny { width: 100%; }
}
</style>
<?php
/* ขนาดตัวอักษรตั้งได้ที่ Admin mode > เอกสารพิมพ์ — ทับค่าใน CSS ด้านบน
   ส่วนย่อยที่ผูกกับส่วนหลัก (รหัสสินค้า หมายเหตุใต้ตาราง) ย่อ/ขยายตามสัดส่วนเดิม */
$fs_tb = print_px($PC, 'fs_table');
$fs_nt = print_px($PC, 'fs_notes');
?>
<style>
.ph-co   { font-size: <?php echo print_px($PC, 'fs_company'); ?>px; }
.ph-title{ font-size: <?php echo print_px($PC, 'fs_title'); ?>px; }
.meta    { font-size: <?php echo print_px($PC, 'fs_meta'); ?>px; }
table.items { font-size: <?php echo $fs_tb; ?>px; }
table.items .code { font-size: <?php echo round($fs_tb * 0.92, 1); ?>px; }
.notes   { font-size: <?php echo $fs_nt; ?>px; }
.notes-rich p { margin: 0 0 2px; }
.notes-rich ul { margin: 0; padding-left: 18px; }
.estimate{ font-size: <?php echo round($fs_nt * 0.96, 1); ?>px; }
.sign    { font-size: <?php echo print_px($PC, 'fs_sign'); ?>px; }
/* ตัวอย่างในหน้า Admin mode > เอกสารพิมพ์ (iframe ?embed=1) ไม่ต้องมีปุ่มพิมพ์/ปิด */
body.is-embed .bar { display: none; }
</style>
</head>
<body<?php echo (isset($_GET['embed']) && $_GET['embed'] === '1') ? ' class="is-embed"' : ''; ?>>

<?php if ($deny !== ''): ?>

  <div class="deny"><?php echo pd_esc($deny); ?></div>

<?php else: ?>

<div class="bar">
  <button type="button" class="primary" onclick="window.print()">พิมพ์เอกสาร</button>
  <button type="button" class="ghost" onclick="window.close()">ปิดหน้าต่าง</button>
  <span class="hint">เลือกปลายทางเป็น &ldquo;บันทึกเป็น PDF&rdquo; ในหน้าต่างพิมพ์ เพื่อส่งทางอีเมล</span>
</div>

<div class="paper">

  <!-- ============ หัวกระดาษ ============ -->
  <div class="ph">
    <?php if ($logo !== ''): ?>
    <img src="<?php echo pd_esc($logo); ?>" alt="" class="ph-logo">
    <?php endif; ?>

    <div class="ph-text">
      <div class="ph-co"><?php echo pd_esc($company['company_name'] !== '' ? $company['company_name'] : 'บริษัท แฮปปี้แลนด์ กรุ๊ป จำกัด'); ?></div>
      <div class="ph-sub"><?php echo pd_esc($PC['label_dept']); ?> <?php echo pd_esc($doc['department_name'] !== '' ? $doc['department_name'] : '-'); ?></div>
    </div>

    <div class="ph-doc">
      <div class="ph-title"><?php echo pd_esc($paper_title); ?></div>
      <div class="ph-no"><?php echo pd_esc($PC['label_no']); ?> <?php echo pd_esc($doc['doc_no']); ?></div>
      <div class="ph-date"><?php echo pd_esc($PC['label_date']); ?> <?php echo pd_esc(thai_date_long($doc['doc_date'])); ?></div>
    </div>
  </div>

  <!-- ============ ข้อมูลหัวเอกสาร ============ -->
  <div class="meta">
    <?php if ($is_adjust): ?>

    <div><b>ผู้บันทึก:</b> <span><?php echo pd_esc($maker_name !== '' ? $maker_name : $doc['created_by']); ?></span></div>
    <div><b>สถานะ:</b> <span><?php echo pd_esc($doc['status_label']); ?></span></div>

    <?php if ($approved_by !== ''): ?>
    <div><b>ผู้อนุมัติ:</b> <span><?php echo pd_esc($approver_name); ?> <?php echo pd_esc(thai_date_time($approved_at, '')); ?></span></div>
    <?php endif; ?>

    <?php if (trim($doc['note']) !== ''): ?>
    <div style="min-width:100%"><b>เหตุผลการปรับยอด:</b> <span><?php echo pd_esc($doc['note']); ?></span></div>
    <?php endif; ?>

    <?php if (trim($doc['cancel_note']) !== ''): ?>
    <div style="min-width:100%"><b>เหตุผลที่ยกเลิก:</b> <span><?php echo pd_esc($doc['cancel_note']); ?></span></div>
    <?php endif; ?>

    <?php else: ?>

    <?php if ($new_staff !== null && $uniform_receipt === null): ?>
    <div><b>พนักงานใหม่:</b> <span><?php echo pd_esc($new_staff['employee_name']); ?><?php echo ($new_staff['employee_code'] !== '') ? ' (' . pd_esc($new_staff['employee_code']) . ')' : ''; ?></span></div>
    <div><b>ตำแหน่ง:</b> <span><?php echo pd_esc($new_staff['position_name'] !== '' ? $new_staff['position_name'] : '-'); ?></span></div>
    <div><b>วันที่เริ่มงาน:</b> <span><?php echo pd_esc(thai_date_long($new_staff['start_date'])); ?></span></div>
    <?php endif; ?>

    <?php if ($uniform_receipt !== null): ?>
    <div><b>ชื่อพนักงาน:</b> <span><?php echo pd_esc($uniform_receipt['employee_name']); ?><?php echo ($uniform_receipt['employee_code'] !== '') ? ' (' . pd_esc($uniform_receipt['employee_code']) . ')' : ''; ?></span></div>
    <div><b>ตำแหน่ง:</b> <span><?php echo pd_esc($uniform_receipt['position_name'] !== '' ? $uniform_receipt['position_name'] : '-'); ?></span></div>
    <div><b>วันเริ่มงาน:</b> <span><?php echo pd_esc(thai_date_long($uniform_receipt['start_date'])); ?></span></div>
    <?php endif; ?>

    <div><b>ผู้ขอเบิก:</b> <span><?php echo pd_esc($doc['requester_name'] !== '' ? $doc['requester_name'] : $doc['requester']); ?></span></div>
    <div><b>สถานะ:</b> <span><?php echo pd_esc($doc['status_label']); ?></span></div>

    <?php /* เปิด/ปิดได้ที่ Admin mode > เอกสารพิมพ์ */ ?>
    <?php if ($PC['show_ref'] === '1' && $doc['ref_doc_no'] !== ''): ?>
    <div><b>อ้างอิงใบเบิก:</b> <span><?php echo pd_esc($doc['ref_doc_no']); ?></span></div>
    <?php endif; ?>

    <?php if ($approved_by !== ''): ?>
    <div><b>ผู้อนุมัติ:</b> <span><?php echo pd_esc($approver_name); ?> <?php echo pd_esc(thai_date_time($approved_at, '')); ?></span></div>
    <?php endif; ?>

    <?php if ($PC['show_req_note'] === '1' && trim($doc['note']) !== ''): ?>
    <div style="min-width:100%"><b>หมายเหตุผู้ขอ:</b> <span><?php echo pd_esc($doc['note']); ?></span></div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <!-- ============ รายการวัสดุ ============ -->
  <?php if ($is_adjust): ?>

  <table class="items">
    <thead>
      <tr>
        <th style="width:34px"><?php echo pd_esc($PC['label_line']); ?></th>
        <th style="width:92px">รหัส</th>
        <th>รายการ</th>
        <th style="width:56px">หน่วย</th>
        <th style="width:72px">ยอดเดิม</th>
        <th style="width:78px">นับได้จริง</th>
        <th style="width:72px">ส่วนต่าง</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i = 0; $i < count($adj_lines); $i++): $ln = $adj_lines[$i]; ?>
      <tr>
        <td class="c"><?php echo ($i + 1); ?></td>
        <td class="code"><?php echo pd_esc($ln['product_code']); ?></td>
        <td>
          <?php echo pd_esc($ln['product_name']); ?>
          <?php if (trim($ln['note']) !== ''): ?>
          <div style="font-size:11px;color:#6b7280"><?php echo pd_esc($ln['note']); ?></div>
          <?php endif; ?>
        </td>
        <td class="c"><?php echo pd_esc($ln['unit_name']); ?></td>
        <td class="r"><?php echo pd_qty($ln['before']); ?></td>
        <td class="r"><?php echo pd_qty($ln['counted']); ?></td>
        <td class="r"><?php echo ($ln['diff'] > 0 ? '+' : '') . pd_qty($ln['diff']); ?></td>
      </tr>
      <?php endfor; ?>

      <?php $blank = (int)$PC['blank_rows'] - count($adj_lines); for ($i = 0; $i < $blank; $i++): ?>
      <tr><td class="c">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
      <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6" style="text-align:right">ส่วนต่างรวม</td>
        <td class="r"><?php echo ($adj_diff_total > 0 ? '+' : '') . pd_qty($adj_diff_total); ?></td>
      </tr>
    </tfoot>
  </table>

  <p class="estimate">
    * "ยอดเดิม" คือยอดคงเหลือในระบบ ณ เวลาที่<?php echo ($doc['status'] === 'approved') ? 'อนุมัติ' : 'พิมพ์'; ?>ใบนี้
    &nbsp; "นับได้จริง" คือจำนวนที่ตรวจนับได้ &nbsp; ยอดคงเหลือจะถูกปรับให้เท่ากับ "นับได้จริง" เมื่อใบนี้ได้รับอนุมัติ
  </p>

  <?php else: ?>

  <?php if ($uniform_receipt !== null): ?>
  <!-- ข้อ 1 ของแบบฟอร์ม 019-HRM — หมายเหตุด้านล่างจึงเริ่มที่ข้อ 2 -->
  <div class="ur-sec">1. รายละเอียดชุดเครื่องแบบพนักงาน</div>
  <?php endif; ?>

  <table class="items">
    <thead>
      <tr>
        <th style="width:34px"><?php echo pd_esc($PC['label_line']); ?></th>
        <th style="width:88px">รหัส</th>
        <th>รายการ</th>
        <th style="width:60px">หน่วย</th>
        <th style="width:62px">จำนวน</th>
        <th style="width:78px">ราคา/หน่วย</th>
        <th style="width:88px">จำนวนเงิน</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i = 0; $i < count($items); $i++): $it = $items[$i]; ?>
      <tr>
        <td class="c"><?php echo ($i + 1); ?></td>
        <td class="code"><?php echo pd_esc($it['product_code']); ?></td>
        <td>
          <?php echo pd_esc($it['product_name']); ?>
          <?php if (!empty($it['must_return'])): ?>
          <span style="font-size:10px;border:1px solid #b45309;color:#b45309;border-radius:3px;padding:0 3px;white-space:nowrap">ต้องคืน</span>
          <?php endif; ?>
          <?php if (trim($it['note']) !== ''): ?>
          <div style="font-size:11px;color:#6b7280"><?php echo pd_esc($it['note']); ?></div>
          <?php endif; ?>
        </td>
        <td class="c"><?php echo pd_esc($it['unit_name']); ?></td>
        <td class="r"><?php echo pd_qty($it['qty']); ?></td>
        <td class="r"><?php echo pd_money($it['price']); ?></td>
        <td class="r"><?php echo pd_money($it['amount']); ?></td>
      </tr>
      <?php endfor; ?>

      <?php
      /* เติมแถวว่างให้ตารางดูเต็มหน้าและมีที่ให้เขียนเพิ่มด้วยปากกา
         ใบเบิกจริงมักมีการเพิ่มรายการหน้างาน ถ้าตารางจบพอดีจะไม่มีที่เขียน */
      $blank = (int)$PC['blank_rows'] - count($items);
      for ($i = 0; $i < $blank; $i++): ?>
      <tr>
        <td class="c">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td>
      </tr>
      <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" style="text-align:right">รวม</td>
        <td class="r"><?php echo pd_qty($total_qty); ?></td>
        <td class="r">บาท</td>
        <td class="r"><?php echo pd_money($total_amt); ?></td>
        <!-- ช่อง "บาท" อยู่ใต้คอลัมน์ราคา/หน่วย เพื่อบอกหน่วยเงินของยอดที่อยู่ถัดไป
             โดยไม่ต้องเบียดคำว่า "บาท" เข้าไปในตัวเลขจนอ่านยาก -->
      </tr>
    </tfoot>
  </table>

  <?php endif; /* /!$is_adjust */ ?>

  <?php if ($is_estimate && $PC['show_estimate'] === '1'): ?>
  <p class="estimate">
    * ราคาต่อหน่วยเป็นราคาซื้อล่าสุดของสินค้า ณ วันที่พิมพ์ ใช้ประกอบการพิจารณาเท่านั้น
    ยอดที่ลงบัญชีจริงจะยืนยันอีกครั้งเมื่อคลังจ่ายของ
  </p>
  <?php endif; ?>

  <!-- ============ หมายเหตุประจำแบบฟอร์ม ============
       ข้อความชุดนี้เป็นเงื่อนไขการเบิกที่ฝ่ายทรัพยากรบุคคลกำหนด — ไม่เกี่ยวกับใบปรับยอด -->
  <?php if ($uniform_receipt !== null): ?>
  <!-- เอกสารรับชุดเครื่องแบบ: เงื่อนไขที่พนักงานรับทราบตอนเซ็นรับ (ข้อ 2-4 ของแบบฟอร์ม 019-HRM)
       แก้ได้ที่ Admin mode > เอกสารพิมพ์ แท็บเอกสารรับชุดเครื่องแบบ — ชุดเดียวกับหน้าเซ็นรับ (sign.php)
       เลขข้อเริ่มตาม notes_start (ค่าตั้งต้น 2 ให้ตรงกับแบบฟอร์มกระดาษ ข้อ 1 คือตารางรายการด้านบน) -->
  <?php if (print_notes_has_text($PC['notes'])): ?>
  <div class="notes notes-rich">
    <div class="notes-h">หมายเหตุ:</div>
    <?php echo print_notes_render($PC); ?>
  </div>
  <?php endif; ?>
  <?php if (trim($PC['ack']) !== ''): ?>
  <p class="ur-ack"><?php echo pd_esc($PC['ack']); ?></p>
  <?php endif; ?>
  <?php elseif ($new_staff !== null): ?>
  <?php
  /* ใบพนักงานใหม่ใช้หมายเหตุของตัวเอง (ตั้งได้ที่หน้า "อุปกรณ์พนักงานใหม่")
     เป็นเงื่อนไขการดูแล/คืนของที่พนักงานรับทราบตอนเซ็นรับ — ไม่ใช่รอบเวลาเบิกของหน่วยงาน */
  require_once dirname(__FILE__) . '/backend/setting.php';
  $ns_terms = array();
  foreach (explode("\n", setting_get('new_staff_terms', '')) as $ln) {
      if (trim($ln) !== '') { $ns_terms[] = trim($ln); }
  }
  ?>
  <?php if (count($ns_terms) > 0): ?>
  <div class="notes">
    <div class="notes-h">หมายเหตุ:</div>
    <ol>
      <?php foreach ($ns_terms as $ln): ?>
      <li><?php echo pd_esc($ln); ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <?php /* HTML จากตัวแก้ไข ผ่าน print_clean_html() แล้ว (เหลือแท็กจัดรูปแบบ ไม่มี attribute)
           ใบจ่ายอาจใช้หมายเหตุ + ข้อความรับทราบชุดเดียวกับเอกสารรับชุดเครื่องแบบ (ดู print_notes_for) */
        $NT = print_notes_for($PC, $print_type); ?>
  <?php if ($NT['has']): ?>
  <div class="notes notes-rich">
    <div class="notes-h">หมายเหตุ:</div>
    <?php echo $NT['notes']; ?>
  </div>
  <?php endif; ?>
  <?php if ($NT['ack'] !== ''): ?>
  <p class="ur-ack"><?php echo pd_esc($NT['ack']); ?></p>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ============ ช่องลงนาม ============
       สามช่องแรกไม่มีลายเซ็นในระบบ จึงพิมพ์ชื่อ-นามสกุลเป็นสีน้ำเงินตัวหนา
       ไว้เหนือเส้นแทน ให้รู้ว่าใครเป็นเจ้าของช่องโดยไม่ต้องรอเซ็นด้วยปากกา
       คนที่ยังไม่มีชื่อในระบบ (เช่นยังไม่อนุมัติ) เหลือเส้นประไว้เซ็นเอง

       ชื่อขึ้นที่เดียวคือเหนือเส้น ไม่ซ้ำใต้เส้นอีก ยกเว้นช่องผู้รับของที่มี
       ลายเซ็นจริง ซึ่งต้องมีชื่อกำกับใต้เส้นว่าลายมือนั้นเป็นของใคร -->
  <div class="signs">

    <?php
    /* ป้ายชื่อเหนือเส้น + ชื่อกำกับใต้เส้น เขียนครั้งเดียวใช้ทั้งสี่ช่อง */
    function pd_sign_box($caption, $name, $date_text, $sign_img)
    {
        $has_img = (trim((string)$sign_img) !== '');
        $name    = trim((string)$name);
        ?>
        <div class="sign">
          <div class="sign-line">
            <?php if ($has_img): ?>
              <img src="<?php echo pd_esc($sign_img); ?>" alt="ลายเซ็น<?php echo pd_esc($caption); ?>" class="sign-img">
            <?php elseif ($name !== ''): ?>
              <span class="sign-typed"><?php echo pd_esc($name); ?></span>
            <?php endif; ?>
          </div>
          <div class="sign-name"><?php echo $has_img ? pd_esc($name) : '&nbsp;'; ?></div>
          <div class="sign-cap"><?php echo pd_esc($caption); ?></div>
          <div class="sign-date">วันที่ <?php echo $date_text; ?></div>
        </div>
        <?php
    }

    if ($is_adjust) {

        /* ใบปรับยอดมีสองฝ่าย: คนตรวจนับ/บันทึก กับคนอนุมัติ */
        pd_sign_box($PC['sign1'], $maker_name,
                    pd_esc(thai_date_long($doc['doc_date'])), '');

        pd_sign_box($PC['sign2'], $approver_name,
                    $approved_at !== '' ? pd_esc(thai_date_long($approved_at)) : '............', '');

    } else {

        pd_sign_box($PC['sign1'], $doc['requester_name'],
                    pd_esc(thai_date_long($doc['doc_date'])), '');

        pd_sign_box($PC['sign2'], $approver_name,
                    $approved_at !== '' ? pd_esc(thai_date_long($approved_at)) : '............', '');

        pd_sign_box($PC['sign3'], $issuer_name,
                    ($issuer_name !== '' && $doc['doc_date'] !== '') ? pd_esc(thai_date_long($doc['doc_date'])) : '............', '');

        /* ผู้รับของเป็นช่องเดียวที่มีลายเซ็นจริง — วาดไว้ตอนกดเซ็นรับ
           ใบที่เซ็นก่อนระบบมีฟีเจอร์นี้ หรือยังไม่ได้เซ็น จะตกไปใช้ชื่อสีน้ำเงินเหมือนช่องอื่น */
        /* เอกสารรับชุดเครื่องแบบ: ผู้รับคือตัวพนักงานที่เซ็นรับทราบเงื่อนไข
           ยังไม่ได้เซ็นในระบบ = พิมพ์ชื่อพนักงานไว้ให้เซ็นด้วยปากกา */
        if ($uniform_receipt !== null) {
            pd_sign_box($PC['sign4'],
                        $doc['receiver_name'] !== '' ? $doc['receiver_name'] : $uniform_receipt['employee_name'],
                        $doc['received_at'] !== '' ? pd_esc(thai_date_long($doc['received_at'])) : '............',
                        $receiver_sign);
        } else {
            /* ใบจ่ายของใบเบิกสวัสดิการ ที่ยังไม่มีชื่อผู้รับ (ใบเก่า) = ชื่อพนักงานในใบเบิกต้นทาง */
            pd_sign_box($PC['sign4'], (string)$doc['receiver_name'] !== '' ? $doc['receiver_name'] : $doc['employee_receiver'],
                        $doc['received_at'] !== '' ? pd_esc(thai_date_long($doc['received_at'])) : '............',
                        $receiver_sign);
        }
    }
    ?>
  </div>


  <!-- ============ รหัสแบบฟอร์ม ============
       ระบบเอกสารควบคุมขององค์กร รหัสและรอบแก้ไขต่างกันในแต่ละบริษัท
       ตั้งได้ที่หน้า "หน่วยงาน" แท็บบริษัท บริษัทที่ยังไม่ได้ตั้งจะไม่มีบรรทัดนี้
       ใช้รหัสที่เก็บไว้กับใบตอนออกใบ (sql/085) — แก้รหัสภายหลัง ใบเก่ายังพิมพ์รหัสรุ่นเดิม
       ก่อนรัน sql/085 / เอกสารสมมติในหน้าตั้งค่า = รหัสปัจจุบัน -->
  <?php
  $form_code = $company['form_code'];
  $snap = $is_mock ? null : doc_form_codes($doc['doc_id']);
  if ($snap !== null) {
      $form_code = $snap['form_code'];
      if (trim($snap['uniform_form_code']) !== '') { $ur_form_code = $snap['uniform_form_code']; }
  }
  ?>
  <?php if ($uniform_receipt !== null): ?>
  <div class="form-code"><?php echo pd_esc($ur_form_code); ?></div>
  <?php elseif (trim($form_code) !== ''): ?>
  <div class="form-code"><?php echo pd_esc($form_code); ?></div>
  <?php endif; ?>

</div>


<script>

/* เปิดหน้าต่างพิมพ์ให้เลยถ้าถูกเรียกด้วย &auto=1
   ใช้ตอนกดปุ่มพิมพ์จากในระบบ ผู้ใช้จะได้ไม่ต้องกดสองครั้ง
   รอ load ก่อนเพราะถ้าสั่งพิมพ์ตอนโลโก้ยังโหลดไม่เสร็จ หัวกระดาษจะว่าง */
(function () {
    if (window.location.search.indexOf('auto=1') === -1) { return; }
    window.addEventListener('load', function () {
        window.setTimeout(function () { window.print(); }, 250);
    });
})();
</script>

<?php endif; ?>

</body>
</html>
