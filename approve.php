<?php
/* ============================================================
 *  approve.php — หน้าอนุมัติใบเบิกสำหรับผู้อนุมัติ เปิดจากลิงก์ในอีเมล
 *
 *      approve.php?t=<กุญแจ 40 ตัวอักษร>
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องมีหน้านี้
 *  ---------------------------------------------------------------
 *  เมล "ใบเบิกรอคุณอนุมัติ" เคยชี้ไป main.php?page=request_list ซึ่งอยู่หลัง
 *  หน้าล็อกอิน ผู้อนุมัติหลายคนอ่านเมลจากมือถือระหว่างวัน กดปุ่มแล้วเจอ
 *  หน้าล็อกอิน ต้องพิมพ์รหัสผ่านบนจอเล็ก ๆ ก่อนถึงจะกดอนุมัติได้
 *  ส่วนใหญ่จึงเลื่อนไว้ทำตอนกลับถึงโต๊ะ แล้วลืม ใบเบิกค้างข้ามวัน
 *  ทั้งที่การตัดสินใจใช้เวลาสามวินาที
 *
 *  หน้านี้ทำได้สองอย่างคืออนุมัติหรือไม่อนุมัติใบนั้นใบเดียว
 *  ดูใบอื่นไม่ได้ แก้จำนวนไม่ได้ ยกเลิกใบไม่ได้ เพราะไม่มีทางให้ทำ
 *
 *  ---------------------------------------------------------------
 *  กุญแจผูกกับตัวคน ไม่ใช่ผูกกับเอกสารอย่างเดียว
 *  ---------------------------------------------------------------
 *  ต่างจาก sign.php ตรงนี้: ลิงก์เซ็นรับออกดอกเดียวต่อใบ แต่ลิงก์อนุมัติ
 *  ออกให้ผู้อนุมัติเป็นรายคน เพราะระบบต้องบันทึกให้ได้ว่า "ใครอนุมัติ"
 *  และต้องบังคับกฎห้ามอนุมัติใบของตัวเองกับกฎขอบเขตหน่วยงานได้
 *
 *  ผลคือหน้านี้ตัดสินใจ "ในนาม" ของผู้อนุมัติที่กุญแจออกให้ ไม่ใช่ในนาม
 *  ใครก็ได้ที่ถือลิงก์ ประวัติการอนุมัติจึงยังอ่านได้เหมือนเดิมทุกประการ
 *
 *  ---------------------------------------------------------------
 *  ทำไมไม่ใช้ session / CSRF token แบบหน้าอื่น
 *  ---------------------------------------------------------------
 *  เหตุผลเดียวกับ sign.php ทุกข้อ: คนที่เปิดหน้านี้ไม่มี session
 *  กุญแจใน URL คือสิทธิ์ทั้งหมดที่มี การโจมตีแบบ CSRF ต้องรู้กุญแจอยู่แล้ว
 *  จึงจะยิงมาถูกใบ และถ้ารู้กุญแจก็เปิดหน้านี้กดเองได้ตรง ๆ อยู่ดี
 *
 *  สิ่งที่ป้องกันจริงคือกุญแจที่เดาไม่ได้ + หมดอายุ 3 วัน + ใช้ได้ครั้งเดียว
 *  + ออกใหม่ทับได้ (ดู backend/approve_link.php)
 *
 *  ---------------------------------------------------------------
 *  ส่งฟอร์มแบบ POST ธรรมดา ไม่ใช่ AJAX
 *  ---------------------------------------------------------------
 *  ปลายทางคือคนที่เปิดจากมือถือผ่านลิงก์ในเมล บนเครือข่ายที่อาจไม่ดี
 *  POST แล้ว redirect ทำงานได้แม้ JavaScript พัง ทั้งหน้าไม่ใช้ JS เลย
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

/* ต้องมาก่อนไฟล์อื่น เพราะมันเปิดบัฟเฟอร์รับคำตอบไว้ตั้งแต่บรรทัดที่ include
   กดอนุมัติแล้วได้หน้าถัดไปทันที ส่วนเมลแจ้งผลตามไปทีหลังโดยไม่ให้ใครรอ */
require_once dirname(__FILE__) . '/backend/after_response.php';

require_once dirname(__FILE__) . '/backend/thai_date.php';
require_once dirname(__FILE__) . '/backend/approve_link.php';
require_once dirname(__FILE__) . '/backend/document.php';
require_once dirname(__FILE__) . '/backend/request.php';
require_once dirname(__FILE__) . '/backend/adjust.php';   // ลิงก์อนุมัติใช้กับใบปรับยอดด้วย

function ap_esc($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/* แสดงจำนวนแบบไม่มีทศนิยมถ้าเป็นจำนวนเต็ม — "5 ม้วน" อ่านง่ายกว่า "5.00 ม้วน" */
function ap_qty($value)
{
    $n = (float)$value;
    return ($n == floor($n)) ? number_format($n) : number_format($n, 2);
}


/* ============================================================
 *  ตรวจกุญแจก่อนทำอะไรทั้งสิ้น
 * ============================================================ */
$token = isset($_GET['t']) ? $_GET['t'] : (isset($_POST['t']) ? $_POST['t'] : '');
$check = approve_link_find($token);

$fatal    = '';
$doc      = null;
$approver = null;

if (!$check['ok']) {

    /* แยกสามกรณีให้ชัด เพราะทางแก้คนละทางกันหมด */
    if ($check['reason'] === 'expired') {
        $fatal = 'ลิงก์นี้หมดอายุแล้ว (ลิงก์อนุมัติมีอายุ ' . APPROVE_LINK_DAYS . ' วัน) '
               . 'กรุณาเข้าระบบเพื่ออนุมัติ หรือแจ้งผู้ดูแลระบบให้ส่งลิงก์ใหม่';
    } elseif ($check['reason'] === 'used') {
        $fatal = 'ลิงก์นี้ถูกใช้ไปแล้ว เอกสารใบนี้ได้รับการตัดสินเรียบร้อยแล้ว '
               . 'ไม่ต้องทำอะไรเพิ่ม';
    } else {
        $fatal = 'ลิงก์นี้ใช้ไม่ได้ อาจถูกพิมพ์มาไม่ครบ หรือมีการส่งลิงก์ใหม่ทับไปแล้ว '
               . 'กรุณาใช้ลิงก์จากอีเมลฉบับล่าสุด';
    }

} else {

    $doc = doc_find($check['doc_id']);
    if ($doc === null || !in_array($doc['doc_type'], array('request', 'adjust'))) {
        $fatal = 'ไม่พบเอกสารที่ลิงก์นี้อ้างถึง';
    } else {
        $approver = user_find($check['username']);

        /* บัญชีถูกปิดหรือถูกลบไปหลังจากส่งเมล ลิงก์ต้องตายตาม
           ไม่งั้นคนที่ลาออกไปแล้วยังอนุมัติใบเบิกได้จากเมลเก่าในกล่องจดหมาย */
        if ($approver === null) {
            $fatal = 'ไม่พบบัญชีผู้อนุมัติที่ลิงก์นี้ออกให้ กรุณาติดต่อผู้ดูแลระบบ';
        } elseif ((int)$approver['is_active'] !== 1) {
            $fatal = 'บัญชีผู้อนุมัติที่ลิงก์นี้ออกให้ถูกปิดใช้งานแล้ว จึงอนุมัติผ่านลิงก์ไม่ได้';
        }
    }
}


/* ============================================================
 *  บันทึกผลการตัดสิน
 * ============================================================ */
$error = '';

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $decision = isset($_POST['decision']) ? $_POST['decision'] : '';
    $reason   = isset($_POST['reason'])   ? trim($_POST['reason']) : '';

    if ($decision !== 'approved' && $decision !== 'rejected') {
        $error = 'กรุณาเลือกว่าจะอนุมัติหรือไม่อนุมัติ';
    } else {

        /* ตัดสินใน "นาม" ของผู้อนุมัติที่กุญแจออกให้
           ฟังก์ชัน *_decide จะบังคับกฎที่เหลือเองทั้งหมด ทั้งเรื่องสิทธิ์
           ห้ามอนุมัติใบตัวเอง และกันสองคนกดพร้อมกัน หน้านี้จึงไม่ต้องทำซ้ำ */
        $res = ($doc['doc_type'] === 'adjust')
             ? adjust_decide($doc['doc_id'], $decision, $reason, $approver['username'], $approver['role'])
             : request_decide($doc['doc_id'], $decision, $reason, $approver['username'], $approver['role']);

        if ($res['ok']) {
            /* เผากุญแจทุกดอกของใบนี้ รวมของผู้อนุมัติคนอื่นด้วย
               เรื่องจบแล้ว ลิงก์ที่เหลือไม่ควรเปิดเจอหน้าปุ่มเต็ม ๆ อีก */
            approve_link_spend($token, $approver['username']);
            approve_link_close_doc($doc['doc_id'], $approver['username']);

            /* redirect หลังบันทึกสำเร็จ กันผู้ใช้กดรีเฟรชแล้วส่งซ้ำ
               ครั้งที่สองจะเจอ "ลิงก์ถูกใช้ไปแล้ว" ซึ่งดูเหมือนระบบพัง
               ทั้งที่บันทึกสำเร็จไปแล้ว */
            header('Location: approve.php?t=' . urlencode($token) . '&ok=' . $decision);
            exit;
        }

        $error = $res['message'];
        $doc   = doc_find($check['doc_id']);   // อ่านสถานะล่าสุดมาแสดงใหม่
    }
}

/* ---------- หน้าหลัง redirect ----------
 * ตอนนั้นกุญแจถูกใช้ไปแล้ว จึงเข้ามาทาง $fatal ของ reason = 'used'
 * ต้องดักค่า ok แล้วแสดงหน้าขอบคุณแทนหน้าลิงก์ใช้ไม่ได้
 *
 * เงื่อนไขต้องแน่นกว่า "มี ?ok= ใน URL" เพราะใครก็เติมพารามิเตอร์นั้นเองได้
 * ถ้าเชื่อค่าใน URL ตรง ๆ คนที่เปิด approve.php?t=มั่ว&ok=approved
 * จะได้หน้า "อนุมัติเรียบร้อยแล้ว" ทั้งที่ไม่มีอะไรเกิดขึ้น ซึ่งหลอกคนอ่าน
 *
 * จึงยอมรับเฉพาะเมื่อกุญแจมีอยู่จริง เปิดใบได้จริง และใบนั้นถูกตัดสินไปแล้วจริง
 */
$done = '';
if (isset($_GET['ok']) && ($_GET['ok'] === 'approved' || $_GET['ok'] === 'rejected')
    && $check['doc_id'] > 0) {

    if ($doc === null) { $doc = doc_find($check['doc_id']); }

    if ($doc !== null && in_array($doc['doc_type'], array('request', 'adjust'))
        && $doc['status'] === $_GET['ok']) {
        $done  = $_GET['ok'];
        $fatal = '';
    }
}

/* ตาข่ายกันหน้าขาว: ส่วนล่างวาดจาก $doc ตรง ๆ ถ้าไม่มีใบให้วาดต้องบอกไปเลย
   เกิดได้ถ้าเอกสารถูกลบระหว่างที่ผู้ใช้ค้างหน้านี้ไว้แล้วเพิ่งกดส่ง */
if ($fatal === '' && $doc === null) {
    $fatal = 'ไม่พบเอกสารที่ลิงก์นี้อ้างถึง อาจถูกลบไปแล้ว';
}

$can_decide = ($fatal === '' && $done === '' && $doc['status'] === 'waiting');

/* ข้อความให้เปลี่ยนตามชนิดเอกสาร — ใบเบิก vs ใบปรับยอด */
$is_adjust  = ($doc !== null && $doc['doc_type'] === 'adjust');
$doc_noun   = $is_adjust ? 'ใบปรับยอด' : 'ใบเบิก';
$adj_lines  = $is_adjust ? adjust_doc_lines($doc) : array();

$page_title = ($doc !== null) ? ('อนุมัติ' . $doc_noun . ' ' . $doc['doc_no']) : ('อนุมัติ' . $doc_noun);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- ลิงก์นี้เป็นกุญแจ ห้ามให้เครื่องมือค้นหาเก็บไว้เด็ดขาด -->
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?php echo ap_esc($page_title); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">

<style>
/* ============================================================
   เขียน CSS เองเหมือน sign.php ไม่ดึง app.css มาใช้
   เพราะ app.css ผูกกับโครงหน้าที่มีเมนูข้างและแถบบน ซึ่งหน้านี้ไม่มี
   และผู้เปิดส่วนใหญ่มาจากมือถือ จึงออกแบบจากจอแคบขึ้นไป
   ============================================================ */
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 16px 12px 40px;
    background: #eef2f6;
    font-family: 'Sarabun', Tahoma, sans-serif;
    font-size: 15px;
    line-height: 1.6;
    color: #0f172a;
}

.wrap { max-width: 640px; margin: 0 auto; }

.card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 30px -12px rgba(15, 23, 42, .25);
    overflow: hidden;
}

.head {
    padding: 20px 20px 18px;
    background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
    color: #fff;
}
.head h1 { margin: 0; font-size: 19px; font-weight: 700; }
.head p  { margin: 4px 0 0; font-size: 13px; opacity: .9; }

.body { padding: 20px; }

/* ---------- แถบสถานะ ---------- */
.note {
    display: flex;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.55;
}
.note b { font-weight: 700; }
.note-ok   { background: #ecfeff; border: 1px solid #a5f3fc; color: #155e75; }
.note-bad  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.note-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.note-info { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; }

/* ---------- ตารางหัวเอกสาร ---------- */
.pairs { width: 100%; border-collapse: collapse; margin-top: 16px; }
.pairs th, .pairs td {
    padding: 7px 0;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
}
.pairs th { width: 38%; font-weight: 400; color: #64748b; }
.pairs td { font-weight: 600; }

/* ---------- รายการสินค้า ---------- */
h2 { margin: 22px 0 10px; font-size: 15px; font-weight: 700; }

.items { width: 100%; border-collapse: collapse; }
.items th {
    padding: 8px 10px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-align: left;
}
.items td {
    padding: 9px 10px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
    vertical-align: top;
}
.items .num { text-align: right; white-space: nowrap; }
.code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #0e7490; }

/* ---------- ส่วนตัดสินใจ ---------- */
.decide { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 20px; }

.who {
    margin: 0 0 14px;
    font-size: 13.5px;
    color: #475569;
}
.who b { color: #0f172a; }

label { display: block; margin-bottom: 6px; font-size: 14px; font-weight: 600; }

textarea {
    width: 100%;
    min-height: 84px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    font-family: inherit;
    font-size: 15px;
    line-height: 1.5;
    resize: vertical;
}
textarea:focus { outline: none; border-color: #0891b2; box-shadow: 0 0 0 4px #cffafe; }

.hint { margin: 6px 0 0; font-size: 12.5px; color: #94a3b8; }

.btn {
    display: block;
    width: 100%;
    margin-top: 12px;
    padding: 14px 16px;
    border: 1px solid transparent;
    border-radius: 12px;
    font-family: inherit;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
}
.btn-main { background: #0891b2; color: #fff; }
.btn-main:active { background: #0e7490; }
.btn-bad {
    background: #fff;
    border-color: #fca5a5;
    color: #b91c1c;
}
.btn-bad:active { background: #fef2f2; }

.foot {
    margin: 18px 4px 0;
    font-size: 12.5px;
    color: #94a3b8;
    text-align: center;
    line-height: 1.7;
}

@media (max-width: 420px) {
    .pairs th { width: 44%; }
    .items th:nth-child(2), .items td:nth-child(2) { display: none; }
}
</style>
</head>
<body>
<div class="wrap">

<?php if ($fatal !== '') { ?>

  <!-- ---------- กุญแจใช้ไม่ได้ ---------- -->
  <div class="card">
    <div class="head">
      <h1>เปิดลิงก์ไม่ได้</h1>
      <p>ลิงก์อนุมัติ<?php echo ap_esc($doc_noun); ?></p>
    </div>
    <div class="body">
      <div class="note note-bad"><span><?php echo ap_esc($fatal); ?></span></div>
      <p class="foot">ถ้าต้องการความช่วยเหลือ กรุณาติดต่อผู้ดูแลระบบพร้อมแจ้งเลขที่เอกสารที่อยู่ในอีเมล</p>
    </div>
  </div>

<?php } else { ?>

  <div class="card">
    <div class="head">
      <h1><?php
          if ($done === 'approved')      { echo 'อนุมัติเรียบร้อยแล้ว'; }
          elseif ($done === 'rejected')  { echo 'บันทึกการไม่อนุมัติแล้ว'; }
          else                           { echo ap_esc($doc_noun) . 'รอการอนุมัติ'; }
      ?></h1>
      <p><?php echo ap_esc($doc_noun . ' ' . $doc['doc_no']); ?></p>
    </div>

    <div class="body">

<?php if ($done === 'approved') { ?>
      <div class="note note-ok">
        <span><b>อนุมัติเรียบร้อยแล้ว</b>
        <?php echo $is_adjust
            ? 'ยอดคงเหลือถูกปรับให้ตรงกับที่นับไว้เรียบร้อย ระบบแจ้งผู้บันทึกให้ทราบแล้ว'
            : 'ระบบแจ้งผู้ขอเบิกให้ทราบแล้ว ขั้นต่อไปคลังจะจัดของและบันทึกจ่ายออก'; ?></span>
      </div>
<?php } elseif ($done === 'rejected') { ?>
      <div class="note note-warn">
        <span><b>บันทึกว่าไม่อนุมัติแล้ว</b>
        <?php echo $is_adjust
            ? 'ยอดคงเหลือไม่เปลี่ยน ระบบส่งเหตุผลไปให้ผู้บันทึกแล้ว'
            : 'ระบบส่งเหตุผลไปให้ผู้ขอเบิกแล้ว เขาจะได้รู้ว่าต้องแก้อะไรก่อนทำใบใหม่'; ?></span>
      </div>
<?php } elseif ($error !== '') { ?>
      <div class="note note-bad"><span><?php echo ap_esc($error); ?></span></div>
<?php } elseif (!$can_decide) { ?>
      <div class="note note-warn">
        <span><?php echo ap_esc($doc_noun); ?>นี้อยู่ในสถานะ <b><?php echo ap_esc($doc['status_label']); ?></b>
              ซึ่งตัดสินไม่ได้แล้ว อาจมีผู้อนุมัติคนอื่นตัดสินไปก่อน</span>
      </div>
<?php } else { ?>
      <div class="note note-info">
        <span>กรุณาตรวจรายการด้านล่างให้ครบก่อนตัดสินใจ
        <?php echo $is_adjust
            ? 'อนุมัติแล้วยอดคงเหลือจะถูกปรับให้ตรงกับ "นับได้จริง" ทันที'
            : 'การอนุมัติคือการยืนยันว่าของทั้งใบสมเหตุสมผล'; ?></span>
      </div>
<?php } ?>

      <!-- ---------- หัวเอกสาร ---------- -->
      <table class="pairs">
        <tr><th>เลขที่เอกสาร</th><td><?php echo ap_esc($doc['doc_no']); ?></td></tr>
        <tr><th><?php echo $is_adjust ? 'วันที่ตรวจนับ' : 'วันที่ขอเบิก'; ?></th><td><?php echo ap_esc(thai_date_long($doc['doc_date'])); ?></td></tr>
<?php   if (!$is_adjust && $doc['department_name'] !== '') { ?>
        <tr><th>หน่วยงาน</th><td><?php echo ap_esc($doc['department_name']); ?></td></tr>
<?php   } ?>
<?php   if ($is_adjust && $doc['created_by'] !== '') { ?>
        <tr><th>ผู้บันทึก</th><td><?php echo ap_esc($doc['created_by']); ?></td></tr>
<?php   } elseif (!$is_adjust && ($doc['requester_name'] !== '' || $doc['requester'] !== '')) { ?>
        <tr><th>ผู้ขอเบิก</th><td><?php
            echo ap_esc($doc['requester_name'] !== '' ? $doc['requester_name'] : $doc['requester']); ?></td></tr>
<?php   } ?>
<?php   if ($doc['note'] !== '') { ?>
        <tr><th><?php echo $is_adjust ? 'เหตุผลการปรับยอด' : 'หมายเหตุ'; ?></th><td><?php echo ap_esc($doc['note']); ?></td></tr>
<?php   } ?>
<?php   if ($doc['status'] !== 'waiting' && $doc['approve_note'] !== '') { ?>
        <tr><th>เหตุผล/หมายเหตุ</th><td><?php echo ap_esc($doc['approve_note']); ?></td></tr>
<?php   } ?>
      </table>

<?php if ($is_adjust) { ?>

      <!-- ---------- รายการปรับยอด ---------- -->
      <h2>รายการปรับยอด</h2>
      <table class="items">
        <thead>
          <tr><th>สินค้า</th><th class="num">ยอดเดิม</th><th class="num">นับได้จริง</th><th class="num">ส่วนต่าง</th></tr>
        </thead>
        <tbody>
<?php   for ($i = 0; $i < count($adj_lines); $i++) { $ln = $adj_lines[$i];
            $dv = (float)$ln['diff']; ?>
          <tr>
            <td><?php echo ap_esc($ln['product_name']); ?>
                <div class="code"><?php echo ap_esc($ln['product_code']); ?></div>
<?php           if (trim($ln['note']) !== '') { ?>
                <div class="hint"><?php echo ap_esc($ln['note']); ?></div>
<?php           } ?>
            </td>
            <td class="num"><?php echo ap_qty($ln['before']); ?></td>
            <td class="num"><b><?php echo ap_qty($ln['counted']); ?></b> <?php echo ap_esc($ln['unit_name']); ?></td>
            <td class="num" style="color:<?php echo $dv > 0 ? '#059669' : ($dv < 0 ? '#dc2626' : '#64748b'); ?>">
                <b><?php echo ($dv > 0 ? '+' : '') . ap_qty($dv); ?></b></td>
          </tr>
<?php   } ?>
        </tbody>
      </table>

<?php } else { ?>

      <!-- ---------- รายการที่ขอเบิก ---------- -->
      <h2>รายการที่ขอเบิก</h2>
      <table class="items">
        <thead>
          <tr><th>สินค้า</th><th>รหัส</th><th class="num">จำนวน</th></tr>
        </thead>
        <tbody>
<?php   for ($i = 0; $i < count($doc['items']); $i++) {
            $it = $doc['items'][$i]; ?>
          <tr>
            <td><?php echo ap_esc($it['product_name']); ?>
                <div class="code"><?php echo ap_esc($it['product_code']); ?></div>
<?php           if ($it['note'] !== '') { ?>
                <div class="hint"><?php echo ap_esc($it['note']); ?></div>
<?php           } ?>
            </td>
            <td class="code"><?php echo ap_esc($it['product_code']); ?></td>
            <td class="num"><b><?php echo ap_qty($it['qty']); ?></b>
                <?php echo ap_esc($it['unit_name']); ?></td>
          </tr>
<?php   } ?>
        </tbody>
      </table>

<?php } ?>

<?php if ($can_decide) { ?>

      <!-- ---------- ตัดสินใจ ----------
           สองปุ่มอยู่ในฟอร์มเดียวกัน แยกกันที่ค่าของ name="decision"
           ช่องเหตุผลใช้ร่วมกัน: อนุมัติจะกรอกหรือไม่กรอกก็ได้
           ไม่อนุมัติต้องกรอก (request_decide บังคับเอง ไม่ได้เช็คแค่ที่หน้าจอ) -->
      <form method="post" class="decide">
        <input type="hidden" name="t" value="<?php echo ap_esc($token); ?>">

        <p class="who">กำลังตัดสินในนาม
           <b><?php echo ap_esc($approver['full_name'] !== ''
                                ? $approver['full_name'] : $approver['username']); ?></b>
           ตามลิงก์ที่ส่งไปในอีเมล</p>

        <label for="reason">เหตุผลหรือหมายเหตุ</label>
        <textarea id="reason" name="reason" maxlength="500"
                  placeholder="<?php echo $is_adjust
                      ? 'ถ้าไม่อนุมัติ ต้องระบุเหตุผลเสมอ เพราะผู้บันทึกจะได้รู้ว่าต้องแก้อะไร'
                      : 'ถ้าไม่อนุมัติ ต้องระบุเหตุผลเสมอ เพราะผู้ขอเบิกจะได้รู้ว่าต้องแก้อะไร'; ?>"><?php
            echo ap_esc(isset($_POST['reason']) ? $_POST['reason'] : ''); ?></textarea>
        <p class="hint">อนุมัติจะใส่หมายเหตุหรือไม่ก็ได้ · ไม่อนุมัติต้องระบุเหตุผล</p>

        <button type="submit" name="decision" value="approved" class="btn btn-main">
          อนุมัติ<?php echo ap_esc($doc_noun); ?>นี้
        </button>
        <button type="submit" name="decision" value="rejected" class="btn btn-bad">
          ไม่อนุมัติ
        </button>
      </form>

      <p class="foot">ลิงก์นี้ใช้ได้ครั้งเดียว และหมดอายุใน <?php echo (int)APPROVE_LINK_DAYS; ?> วันนับจากวันที่ส่งเมล<br>
         กรุณาอย่าส่งต่อให้ผู้อื่น เพราะผู้ที่ถือลิงก์จะตัดสินใบนี้ในนามของคุณได้</p>

<?php } else { ?>

      <p class="foot">เปิดระบบเพื่อดู<?php echo ap_esc($doc_noun); ?>ทั้งหมดได้จากในระบบ</p>

<?php } ?>

    </div>
  </div>

<?php } ?>

</div>
</body>
</html>
