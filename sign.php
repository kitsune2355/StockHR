<?php
/* ============================================================
 *  sign.php — หน้าเซ็นรับของสำหรับผู้เบิก เปิดจากลิงก์ในอีเมล
 *
 *      sign.php?t=<กุญแจ 40 ตัวอักษร>
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องมีหน้านี้
 *  ---------------------------------------------------------------
 *  เมล "ของพร้อมให้รับแล้ว" เคยชี้ไป main.php?page=issue_history ซึ่งอยู่หลัง
 *  หน้าล็อกอิน ผู้ขอเบิกส่วนใหญ่ไม่มีบัญชี หรือมีแต่ไม่มีสิทธิ์เข้าหน้านั้น
 *  กดปุ่มในเมลแล้วจึงไปจบที่หน้าล็อกอินหรือ "ไม่มีสิทธิ์เข้าถึง"
 *  ใบจ่ายเลยค้างสถานะ "จ่ายแล้ว" ไปเรื่อย ๆ และคลังต้องโทรตามเอง
 *
 *  หน้านี้ทำได้อย่างเดียวคือดูใบจ่ายใบนั้นแล้วเซ็นรับ
 *  ดูใบอื่นไม่ได้ แก้จำนวนไม่ได้ ยกเลิกใบไม่ได้ เพราะไม่มีทางให้ทำ
 *
 *  ---------------------------------------------------------------
 *  ทำไมไม่ใช้ session / CSRF token แบบหน้าอื่น
 *  ---------------------------------------------------------------
 *  คนที่เปิดหน้านี้ไม่มี session เพราะไม่ได้ล็อกอิน กุญแจใน URL คือสิทธิ์
 *  ทั้งหมดที่มี การโจมตีแบบ CSRF ต้องรู้กุญแจอยู่แล้วจึงจะยิงมาถูกใบ
 *  และถ้ารู้กุญแจก็เปิดหน้านี้เซ็นเองได้ตรง ๆ อยู่ดี โทเคนอีกชั้นจึงไม่เพิ่มอะไร
 *
 *  สิ่งที่ป้องกันจริงคือกุญแจที่เดาไม่ได้ + หมดอายุใน 7 วัน + ออกใหม่ทับได้
 *
 *  ---------------------------------------------------------------
 *  ส่งฟอร์มแบบ POST ธรรมดา ไม่ใช่ AJAX
 *  ---------------------------------------------------------------
 *  ปลายทางคือคนที่เปิดจากมือถือผ่านลิงก์ในเมล บนเครือข่ายที่อาจไม่ดี
 *  POST แล้ว redirect ทำงานได้แม้ JavaScript พัง ส่วนผืนเซ็นต้องใช้ JS
 *  อยู่แล้ว จึงเหลือทางสำรองไว้ให้ด้วย: ไม่เซ็นก็ยืนยันรับของด้วยชื่อได้
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

require_once dirname(__FILE__) . '/backend/thai_date.php';
require_once dirname(__FILE__) . '/backend/sign_link.php';
require_once dirname(__FILE__) . '/backend/document.php';
require_once dirname(__FILE__) . '/backend/issue.php';

function sg_esc($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/* แสดงจำนวนแบบไม่มีทศนิยมถ้าเป็นจำนวนเต็ม — "5 ม้วน" อ่านง่ายกว่า "5.00 ม้วน" */
function sg_qty($value)
{
    $n = (float)$value;
    return ($n == floor($n)) ? number_format($n) : number_format($n, 2);
}


/* ============================================================
 *  ตรวจกุญแจก่อนทำอะไรทั้งสิ้น
 * ============================================================ */
$token = isset($_GET['t']) ? $_GET['t'] : (isset($_POST['t']) ? $_POST['t'] : '');
$check = sign_link_find($token);

$fatal = '';
$doc   = null;

if (!$check['ok']) {
    /* แยกสองกรณีให้ชัด เพราะทางแก้คนละทาง
       หมดอายุ = ขอลิงก์ใหม่ได้ / ไม่พบ = ลิงก์ผิดหรือถูกยกเลิกไปแล้ว */
    $fatal = ($check['reason'] === 'expired')
        ? 'ลิงก์นี้หมดอายุแล้ว (ลิงก์เซ็นรับของมีอายุ ' . SIGN_LINK_DAYS . ' วัน) '
        . 'กรุณาติดต่อฝ่ายคลังเพื่อขอลิงก์ใหม่'
        : 'ลิงก์นี้ใช้ไม่ได้ อาจถูกพิมพ์มาไม่ครบ หรือมีการส่งลิงก์ใหม่ทับไปแล้ว '
        . 'กรุณาใช้ลิงก์จากอีเมลฉบับล่าสุด';
} else {
    $doc = doc_find($check['doc_id']);
    if ($doc === null || $doc['doc_type'] !== 'issue') {
        $fatal = 'ไม่พบใบจ่ายที่ลิงก์นี้อ้างถึง';
    }
}


/* ============================================================
 *  บันทึกการเซ็นรับ
 * ============================================================ */
$error = '';
$done  = false;

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = isset($_POST['receiver_name']) ? trim($_POST['receiver_name']) : '';
    $sign = isset($_POST['receiver_sign']) ? trim($_POST['receiver_sign']) : '';

    /* ผู้เซ็นคือผู้ขอเบิกของใบนี้ จึงบันทึกชื่อผู้ใช้นั้นเป็นผู้รับ
       ใบที่ไม่มีผู้ขอเบิกผูกไว้ (ข้อมูลเก่า) ใช้คำว่า sign_link แทน
       เพื่อให้เห็นจากประวัติได้ว่ารายการนี้ปิดผ่านลิงก์ ไม่ใช่ผ่านหน้าเว็บ */
    $as = ($doc['requester'] !== '') ? $doc['requester'] : 'sign_link';

    $res = issue_acknowledge($doc['doc_id'], $name, $as, 'sign_link', $sign);

    if ($res['ok']) {
        /* redirect หลังบันทึกสำเร็จ กันผู้ใช้กดรีเฟรชแล้วส่งซ้ำ
           (ครั้งที่สองจะเจอ "เซ็นไปแล้ว" ซึ่งดูเหมือนระบบพัง ทั้งที่บันทึกสำเร็จ) */
        header('Location: sign.php?t=' . urlencode($token) . '&ok=1');
        exit;
    }
    $error = $res['message'];
    $doc   = doc_find($check['doc_id']);   // อ่านสถานะล่าสุดมาแสดงใหม่
}

$done = (isset($_GET['ok']) && $_GET['ok'] === '1');

/* ใบที่เซ็นไปแล้วไม่ต้องแสดงผืนเซ็นอีก */
$signed    = ($fatal === '' && $doc['status'] === 'received');
$cancelled = ($fatal === '' && $doc['status'] === 'cancelled');
$can_sign  = ($fatal === '' && $doc['status'] === 'issued');

$page_title = ($fatal === '') ? ('เซ็นรับของ ' . $doc['doc_no']) : 'เซ็นรับของ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- ลิงก์นี้เป็นกุญแจ ห้ามให้เครื่องมือค้นหาเก็บไว้เด็ดขาด -->
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?php echo sg_esc($page_title); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">

<style>
/* ============================================================
   หน้านี้เขียน CSS เอง ไม่ดึง app.css มาใช้
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
    background: linear-gradient(135deg, #029488 0%, #02776f 100%);
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
.note-ok   { background: #effbf9; border: 1px solid #a9ebe4; color: #055f59; }
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
h2 {
    margin: 22px 0 10px;
    font-size: 15px;
    font-weight: 700;
}
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
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
    vertical-align: top;
}
.items .num { text-align: right; white-space: nowrap; }
.code { font-size: 12px; color: #94a3b8; }

/* ---------- ผืนเซ็น ---------- */
.field { margin-top: 18px; }
.label { display: block; margin-bottom: 6px; font-size: 14px; font-weight: 600; }
.hint  { margin: 4px 0 0; font-size: 12.5px; color: #64748b; }

input[type=text] {
    width: 100%;
    height: 48px;
    padding: 0 14px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    font-family: inherit;
    font-size: 15px;
    color: #0f172a;
    background: #fff;
}
input[type=text]:focus {
    outline: none;
    border-color: #029488;
    box-shadow: 0 0 0 4px rgba(2, 148, 136, .14);
}

.padbox {
    position: relative;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
}
.padbox canvas {
    display: block;
    width: 100%;
    height: 190px;
    touch-action: none;      /* ไม่งั้นการลากนิ้วบนมือถือจะเลื่อนหน้าแทนที่จะวาดเส้น */
    cursor: crosshair;
}
.padline {
    position: absolute;
    left: 22px; right: 22px; bottom: 42px;
    border-bottom: 1px solid #e2e8f0;
    pointer-events: none;
}
.padtip {
    position: absolute;
    left: 0; right: 0; bottom: 16px;
    text-align: center;
    font-size: 13px;
    color: #94a3b8;
    pointer-events: none;
}

.padbar {
    display: flex;
    justify-content: flex-end;
    margin-top: 8px;
}

/* ---------- ปุ่ม ---------- */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 46px;
    padding: 0 18px;
    border: 1px solid transparent;
    border-radius: 12px;
    font-family: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}
.btn-main {
    width: 100%;
    height: 54px;
    margin-top: 20px;
    background: #029488;
    color: #fff;
    font-size: 16px;
}
.btn-main:active { background: #02776f; }
.btn-soft {
    background: #fff;
    border-color: #cbd5e1;
    color: #475569;
}
.btn-link {
    display: inline-block;
    margin-top: 14px;
    color: #02776f;
    font-weight: 600;
    text-decoration: none;
}

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
      <p>ลิงก์เซ็นรับของ</p>
    </div>
    <div class="body">
      <div class="note note-bad"><span><?php echo sg_esc($fatal); ?></span></div>
      <p class="foot">ถ้าต้องการความช่วยเหลือ กรุณาติดต่อฝ่ายคลังสินค้าพร้อมแจ้งเลขที่ใบจ่ายที่อยู่ในอีเมล</p>
    </div>
  </div>

<?php } else { ?>

  <div class="card">
    <div class="head">
      <h1><?php echo $signed ? 'เซ็นรับของเรียบร้อยแล้ว' : 'ยืนยันการรับของ'; ?></h1>
      <p>ใบจ่าย <?php echo sg_esc($doc['doc_no']); ?><?php
         echo $doc['ref_doc_no'] !== '' ? ' · ตามใบเบิก ' . sg_esc($doc['ref_doc_no']) : ''; ?></p>
    </div>

    <div class="body">

<?php if ($done) { ?>
      <div class="note note-ok">
        <span><b>บันทึกเรียบร้อยแล้ว</b> ขอบคุณครับ รายการนี้ปิดแล้ว
              ระบบได้แจ้งฝ่ายคลังให้ทราบเรียบร้อย</span>
      </div>
<?php } elseif ($error !== '') { ?>
      <div class="note note-bad"><span><?php echo sg_esc($error); ?></span></div>
<?php } elseif ($signed) { ?>
      <div class="note note-ok">
        <span>ใบจ่ายนี้เซ็นรับไปแล้วเมื่อ <b><?php echo sg_esc(thai_date_time($doc['received_at'])); ?></b>
              โดย <b><?php echo sg_esc($doc['receiver_name']); ?></b> ไม่ต้องทำอะไรเพิ่ม</span>
      </div>
<?php } elseif ($cancelled) { ?>
      <div class="note note-warn">
        <span>ใบจ่ายนี้ถูกยกเลิกไปแล้ว จึงเซ็นรับไม่ได้
              หากยังไม่ได้รับของ กรุณาติดต่อฝ่ายคลัง</span>
      </div>
<?php } elseif (!$can_sign) { ?>
      <div class="note note-warn">
        <span>ใบจ่ายนี้อยู่ในสถานะ <b><?php echo sg_esc($doc['status_label']); ?></b>
              ซึ่งเซ็นรับไม่ได้</span>
      </div>
<?php } else { ?>
      <div class="note note-info">
        <span>กรุณาตรวจรายการด้านล่างให้ครบก่อน แล้วจึงเซ็นชื่อยืนยันว่าได้รับของแล้ว</span>
      </div>
<?php } ?>

      <!-- ---------- หัวเอกสาร ---------- -->
      <table class="pairs">
        <tr><th>เลขที่เอกสาร</th><td><?php echo sg_esc($doc['doc_no']); ?></td></tr>
        <tr><th>วันที่จ่าย</th><td><?php echo sg_esc(thai_date_long($doc['doc_date'])); ?></td></tr>
<?php   if ($doc['department_name'] !== '') { ?>
        <tr><th>หน่วยงาน</th><td><?php echo sg_esc($doc['department_name']); ?></td></tr>
<?php   } ?>
<?php   if ($doc['requester_name'] !== '' || $doc['requester'] !== '') { ?>
        <tr><th>ผู้ขอเบิก</th><td><?php
            echo sg_esc($doc['requester_name'] !== '' ? $doc['requester_name'] : $doc['requester']); ?></td></tr>
<?php   } ?>
<?php   if ($doc['ref_doc_no'] !== '') { ?>
        <tr><th>อ้างอิงใบเบิก</th><td><?php echo sg_esc($doc['ref_doc_no']); ?></td></tr>
<?php   } ?>
<?php   if ($doc['note'] !== '') { ?>
        <tr><th>หมายเหตุ</th><td><?php echo sg_esc($doc['note']); ?></td></tr>
<?php   } ?>
      </table>

      <!-- ---------- รายการของที่จ่าย ---------- -->
      <h2>รายการที่จ่าย</h2>
      <table class="items">
        <thead>
          <tr><th>สินค้า</th><th>รหัส</th><th class="num">จำนวน</th></tr>
        </thead>
        <tbody>
<?php   $total = 0;
        for ($i = 0; $i < count($doc['items']); $i++) {
            $it = $doc['items'][$i];
            $total += (float)$it['qty']; ?>
          <tr>
            <td><?php echo sg_esc($it['product_name']); ?>
                <div class="code"><?php echo sg_esc($it['product_code']); ?></div></td>
            <td class="code"><?php echo sg_esc($it['product_code']); ?></td>
            <td class="num"><b><?php echo sg_qty($it['qty']); ?></b>
                <?php echo sg_esc($it['unit_name']); ?></td>
          </tr>
<?php   } ?>
        </tbody>
      </table>

<?php if ($can_sign) { ?>
      <!-- ---------- ฟอร์มเซ็นรับ ---------- -->
      <form method="post" action="sign.php" id="signForm">
        <input type="hidden" name="t" value="<?php echo sg_esc($token); ?>">
        <input type="hidden" name="receiver_sign" id="signData" value="">

        <div class="field">
          <label class="label" for="receiverName">ชื่อผู้รับของ</label>
          <input type="text" id="receiverName" name="receiver_name" maxlength="150"
                 autocomplete="name" required
                 value="<?php echo sg_esc($doc['requester_name'] !== ''
                              ? $doc['requester_name'] : $doc['requester']); ?>">
          <p class="hint">ถ้ามารับแทน ให้แก้เป็นชื่อคนที่มารับจริง</p>
        </div>

        <div class="field">
          <span class="label">ลายเซ็น</span>
          <div class="padbox">
            <canvas id="signCanvas"></canvas>
            <div class="padline"></div>
            <div class="padtip" id="signTip">เซ็นด้วยนิ้วหรือเมาส์ในกรอบนี้</div>
          </div>
          <div class="padbar">
            <button type="button" class="btn btn-soft" id="signClear">ล้างลายเซ็น</button>
          </div>
          <p class="hint">ลายเซ็นจะถูกพิมพ์ลงบนใบจ่ายเพื่อใช้เป็นหลักฐานการส่งมอบ</p>
        </div>

        <button type="submit" class="btn btn-main" id="signSubmit">ยืนยันว่าได้รับของครบแล้ว</button>
      </form>
<?php } ?>

<?php if ($signed || $done) { ?>
      <a class="btn-link" href="print_doc.php?doc_id=<?php echo (int)$doc['doc_id']; ?>&amp;t=<?php
         echo sg_esc($token); ?>" target="_blank" rel="noopener">เปิดใบจ่ายเพื่อพิมพ์ &rsaquo;</a>
<?php } ?>

    </div>
  </div>

  <p class="foot">
    ลิงก์นี้ใช้ได้เฉพาะใบจ่ายใบนี้ และหมดอายุใน <?php echo (int)SIGN_LINK_DAYS; ?> วันนับจากวันที่ส่ง<br>
    กรุณาอย่าส่งต่อให้ผู้อื่น เพราะผู้ที่ได้รับลิงก์สามารถเซ็นรับแทนคุณได้
  </p>

<?php } ?>

</div>

<?php if ($fatal === '' && $can_sign) { ?>
<script src="assets/js/signpad.js"></script>
<script>
(function () {
    'use strict';

    var canvas = document.getElementById('signCanvas');
    var pad    = (window.SignPad && canvas) ? window.SignPad.create(canvas) : null;
    if (!pad) { return; }

    /* ข้อความบอกวิธีใช้หายไปทันทีที่เริ่มลากเส้นแรก
       ถ้าปล่อยไว้จะทับลายเซ็นจนดูเหมือนเซ็นไม่ติด */
    var tip = document.getElementById('signTip');
    pad.onStart(function () { if (tip) { tip.style.display = 'none'; } });

    document.getElementById('signClear').onclick = function () {
        pad.clear();
        if (tip) { tip.style.display = ''; }
    };

    /* ---------- ตอนส่งฟอร์ม ----------
     * ลายเซ็นอยู่บน canvas ซึ่งส่งไปกับฟอร์มเองไม่ได้
     * ต้องแปลงเป็น data URL ใส่ช่องซ่อนก่อนปล่อยให้ฟอร์มส่งออกไป
     *
     * ไม่บังคับว่าต้องเซ็น — คนที่เปิดบนเครื่องที่ลากเส้นไม่ได้จริง ๆ
     * ยังต้องปิดงานได้ แค่เตือนให้รู้ตัวว่ากำลังยืนยันโดยไม่มีลายเซ็น
     */
    var form = document.getElementById('signForm');
    var sent = false;

    form.onsubmit = function () {
        if (sent) { return false; }          // กันกดปุ่มรัว ๆ แล้วส่งซ้ำ

        if (!pad.isEmpty()) {
            document.getElementById('signData').value = pad.toDataURL();
        } else if (!window.confirm('ยังไม่ได้เซ็นลายเซ็น ต้องการยืนยันรับของโดยไม่มีลายเซ็นหรือไม่')) {
            return false;
        }

        sent = true;
        var btn = document.getElementById('signSubmit');
        btn.disabled    = true;
        btn.textContent = 'กำลังบันทึก…';
        return true;
    };
}());
</script>
<?php } ?>
</body>
</html>
