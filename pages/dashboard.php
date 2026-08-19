<?php
/* ============================================================
 *  pages/dashboard.php — หน้าภาพรวมระบบ (แบบไทล์ขนาดไม่เท่ากัน)
 *
 *  ไฟล์นี้ถูก include เข้ามาในกรอบของ main.php จึงมีแต่เนื้อหาตรงกลาง
 *  ไม่ต้องมี <html> / <head> / เมนู และห้ามเข้าถึงตรง ๆ
 *
 *  ไทล์ไม่เท่ากันโดยตั้งใจ — ขนาดคือการจัดลำดับความสำคัญ ใหญ่สุดคือ
 *  "งานที่รอคุณ" เพราะเป็นสิ่งเดียวที่ต้องลงมือทำ ที่เหลือเป็นข้อมูลประกอบ
 *  ถ้าการ์ดขนาดเท่ากันหมด ทุกอย่างจะดูสำคัญเท่ากัน
 *
 *  เนื้อหาเปลี่ยนตามสิทธิ์:
 *    คนคลัง/ผู้อนุมัติ  ภาพรวมทั้งคลัง + งานที่ต้องอนุมัติหรือจ่าย
 *    ผู้ใช้ทั่วไป       สถานะใบเบิกของตัวเอง ไม่เห็นตัวเลขของคลัง
 *
 *  ไฟล์นี้ไม่มี SQL — ตัวเลขทั้งหมดมาจาก backend/dashboard.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/dashboard.php';

/* หน้านี้เพิ่งมีคำสั่งที่ยิงกลับไปหาเซิร์ฟเวอร์เป็นครั้งแรก (ข้อมูลกราฟ)
   จึงต้องมีโทเคนกันการยิงข้ามเว็บเหมือนหน้าอื่น ๆ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$is_staff = dashboard_is_staff($sess_role);
$sum      = dashboard_summary($sess_username, $sess_role);
$tasks    = dashboard_my_tasks($sess_username, $sess_role, 5);
$lows     = $is_staff ? dashboard_low_items(4)    : array();
$moves    = $is_staff ? dashboard_recent_moves(6) : array();
/* กราฟย้ายไปโหลดข้อมูลเองผ่าน api/dashboard.php แล้ว เพราะเลือกช่วงเวลาได้
   จึงไม่ต้องดึงชุด 7 วันมาตั้งแต่ตอนวาดหน้าอีก (ดู assets/js/dashboard-chart.js) */

/* มิติเงินเปิดให้เฉพาะสิทธิ์ที่เข้ารายงานค่าใช้จ่ายได้ — คืน null เมื่อไม่มีสิทธิ์ */
$money    = dashboard_money($sess_role);

$routes_all = app_routes();

function db_esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }

/* เงินแสดงทศนิยม 2 ตำแหน่งเสมอ ให้ตรงกับรายงานที่ส่งบัญชี
   ยอดหลักหมื่นขึ้นไปย่อเป็น "12.3 พัน" ไม่ได้ เพราะบัญชีต้องอ่านตัวเต็ม */
function db_money($v)
{
    return number_format((float)$v, 2);
}

/* ชื่อเดือนไทยสำหรับหัวไทล์เงิน */
/* ชื่อเดือนแบบไทยย้ายไปอยู่ที่ backend/thai_date.php แล้ว (thai_month) */

/* เปิดหน้านั้นได้ไหม — ใช้ตัดสินว่าจะทำไทล์ให้กดได้หรือไม่
   ลิงก์ที่กดแล้วเจอ "ไม่มีสิทธิ์" แย่กว่าไม่มีลิงก์เลย */
function db_can($key)
{
    global $routes_all, $sess_role;
    return isset($routes_all[$key]) && app_can_access($routes_all[$key], $sess_role);
}

/* ความพร้อมของข้อมูลตั้งต้น — เฉพาะคนที่แก้ได้เท่านั้นที่ควรเห็น
   คนทั่วไปเห็นแล้วทำอะไรไม่ได้ กลายเป็นเสียงรบกวนบนหน้าแรกทุกวัน
   ใช้สิทธิ์เข้าหน้าทะเบียนสินค้าเป็นเกณฑ์ เพราะนั่นคือที่ที่ใช้แก้จริง

   ต้องอยู่ใต้ db_can() และใต้ $routes_all เท่านั้น
   db_can อ่าน $routes_all ผ่าน global ถ้าเรียกก่อนที่ตัวแปรนั้นจะถูกตั้งค่า
   จะได้ false เสมอโดยไม่มี error ให้เห็น แล้วไทล์จะหายไปเงียบ ๆ (เคยพลาดมาแล้ว) */
$health = db_can('product_list') ? dashboard_data_health() : null;

/* แยกจำนวนงานค้างตามชนิด ใช้แสดงเป็นบรรทัดย่อยในไทล์ใหญ่ */
$by_kind = array('approve' => 0, 'ack' => 0, 'issue' => 0);
for ($i = 0; $i < count($tasks); $i++) {
    $by_kind[$tasks[$i]['kind']]++;
}
$kind_label = array(
    'approve' => array('รออนุมัติ',    'amber'),
    'ack'     => array('รอเซ็นรับของ', 'brand'),
    'issue'   => array('รอจ่ายของ',    'sky')
);

$todo = count($tasks);

/* ---------- ความกว้างของไทล์ท้าย ----------
 * ตาราง 4 คอลัมน์ ผลรวมของ "ช่อง" (กว้างกี่คอลัมน์ x สูงกี่แถว) ต้องหาร 4 ลงตัว
 * ไม่งั้นเหลือช่องว่างค้างท้ายแถว  บล็อกบนสุด = 8 ช่อง
 *
 *   เงิน+ความพร้อม  8 + 4 + กราฟ 2 + เงิน 2 + ต้องสั่ง 2 + ทางลัด 2 + เคลื่อนไหว 4 = 24
 *   มีเงิน          8 +     กราฟ 2 + เงิน 2 + ต้องสั่ง 2 + ทางลัด 2 + เคลื่อนไหว 4 = 20
 *   ไม่มีเงิน        8 +     กราฟ 2 +          ต้องสั่ง 2 + ทางลัด 2 + เคลื่อนไหว 4 = 18  <-- ไม่ลงตัว
 *   ผู้ใช้ทั่วไป      8 +                                  ทางลัด 4               = 12
 *
 * กรณีไม่มีเงิน (ผู้อนุมัติ) ต้องบวกอีก 2 จึงให้ "ต้องสั่งเพิ่ม" กว้างเต็มแถว
 * เพิ่มไทล์ใหม่เมื่อไร ต้องกลับมาบวกเลขตรงนี้ใหม่ทุกครั้ง
 */
$lows_w = 2;

if (!$is_staff) {
    $links_w = 4;
    $moves_w = 0;
} elseif ($money !== null) {
    $links_w = 2;
    $moves_w = 4;
} else {
    /* ไม่มีไทล์เงิน (ผู้อนุมัติ) — ขาดไป 2 ช่อง ให้ "ต้องสั่งเพิ่ม" กินเต็มแถวแทน */
    $lows_w  = 4;
    $links_w = 2;
    $moves_w = 4;
}
?>

<div class="bn">

  <!-- ============================================================
       ไทล์ใหญ่ : งานที่รอคุณ
       ============================================================ -->
  <!-- id ทั้งสองตัวมีไว้ให้คู่มือการใช้งานชี้ถูกจุด (tb_guide_step.target_selector)
       ไม่ได้ใช้ในสไตล์หรือสคริปต์ใด ๆ ห้ามเปลี่ยนชื่อโดยไม่แก้ในตารางด้วย -->
  <section class="bn-tile bn-hero" id="bnHero">
    <p class="bn-eyebrow">
      <?php echo db_esc($sess_full_name !== '' ? $sess_full_name : $sess_username); ?>
      <span class="bn-dot">·</span>
      <?php echo db_esc(app_role_label($sess_role)); ?>
      <span class="bn-dot">·</span>
      <?php echo thai_date(date('Y-m-d')); ?>
    </p>

    <p class="bn-hero-num num"><?php echo number_format($todo); ?></p>
    <p class="bn-hero-cap"><?php echo ($todo > 0) ? 'งานที่รอคุณอยู่' : 'ไม่มีงานค้าง'; ?></p>

    <?php if ($todo === 0) { ?>
    <p class="bn-hero-note">เอกสารทุกใบที่เกี่ยวข้องกับคุณดำเนินการครบแล้ว</p>

    <?php } else { ?>
    <ul class="bn-breakdown">
      <?php foreach ($kind_label as $kind => $meta) {
          if ($by_kind[$kind] === 0) { continue; } ?>
      <li>
        <span class="bn-bullet is-<?php echo $meta[1]; ?>"></span>
        <span class="bn-bd-name"><?php echo db_esc($meta[0]); ?></span>
        <span class="bn-bd-num num"><?php echo number_format($by_kind[$kind]); ?></span>
      </li>
      <?php } ?>
    </ul>

    <?php } ?>

    <!-- ---------- แถบตัวเลขท้ายไทล์ ----------
         ดันไปชิดขอบล่างด้วย margin-top: auto เพื่อให้ไทล์มีของยึดทั้งหัวและท้าย

         ตัวเลขที่แสดงต่างกันตามสิทธิ์ คนคลังอยากรู้เรื่องของในคลัง
         ส่วนคนทั่วไปสนใจเฉพาะใบเบิกของตัวเอง ตัวเลขสองชุดนี้คำนวณมาแล้ว
         ตั้งแต่ dashboard_summary() แต่ชุดหลังไม่เคยถูกเอามาแสดงที่ไหนเลย -->
    <ul class="bn-hero-facts">
      <?php if ($is_staff) { ?>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['stock_total'], ($sum['stock_total'] == (int)$sum['stock_total']) ? 0 : 2); ?></span>
        <span class="bn-fact-cap">หน่วยในคลัง</span>
      </li>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['out_of_stock']); ?></span>
        <span class="bn-fact-cap">รายการที่ของหมด</span>
      </li>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['ready_to_issue']); ?></span>
        <span class="bn-fact-cap">ใบเบิกรอจ่าย</span>
      </li>
      <?php } else { ?>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['my_waiting']); ?></span>
        <span class="bn-fact-cap">ใบเบิกรออนุมัติ</span>
      </li>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['my_approved']); ?></span>
        <span class="bn-fact-cap">อนุมัติแล้ว รอรับของ</span>
      </li>
      <li>
        <span class="bn-fact-num num"><?php echo number_format($sum['my_total']); ?></span>
        <span class="bn-fact-cap">ใบเบิกของฉันทั้งหมด</span>
      </li>
      <?php } ?>
    </ul>
  </section>


  <!-- ============================================================
       ไทล์ : รายการงานที่รออยู่
       ============================================================
       แยกออกมาจากไทล์ใหญ่ เพราะสองอย่างนี้ตอบคนละคำถาม
         ไทล์ใหญ่  "มีงานค้างกี่ชิ้น" — ตัวเลขสรุปที่กวาดตาผ่านครั้งเดียวจบ
         ไทล์นี้    "งานชิ้นไหนบ้าง"  — รายการที่ต้องกดเข้าไปทำทีละใบ

       รวมอยู่ในไทล์เดียวกันทำให้ไทล์ใหญ่สูงเกินความจำเป็น
       และรายการงานถูกบีบอยู่ท้ายกล่องจนดูเหมือนของแถม ทั้งที่เป็นสิ่งที่ต้องกด

       สูงสองแถวของตาราง คู่กับไทล์ใหญ่ที่อยู่ซ้ายมือกับไทล์เล็กสองใบใต้มัน
       ความสูงจึงถูกกำหนดโดยกล่องอื่น ไม่ใช่โดยจำนวนงานที่ค้าง
       ซึ่งดีกว่า เพราะจำนวนงานเปลี่ยนทุกวันแต่ผังหน้าไม่ควรขยับตาม -->
  <section class="bn-tile bn-tasks" id="bnTasks">
    <div class="bn-tile-head">
      <h3>รายการงานที่รออยู่</h3>
      <?php if ($todo > 0 && db_can('request_list')) { ?>
      <a href="main.php?page=request_list" class="bn-more">ดูใบเบิกทั้งหมด</a>
      <?php } ?>
    </div>

    <?php if ($todo === 0) { ?>
    <p class="bn-blank">ไม่มีเอกสารที่รอคุณดำเนินการ</p>
    <?php } else { ?>
    <ul class="bn-queue" id="bnQueue">
      <?php foreach ($tasks as $t) {
          $t_company = isset($t['company']) ? $t['company'] : '';

          /* ข้อความเต็มไว้ให้ชี้เมาส์ดู เพราะบนแถวจริงถูกตัดด้วย …
             ในกล่องกว้างเท่านี้ ชื่อบริษัทยาว ๆ ตัดทิ้งแน่นอน
             แต่บางทีมันคือสิ่งเดียวที่แยกใบสองใบออกจากกัน จึงต้องมีทางดูให้ครบ */
          $t_full = $t['doc_no'] . ' · ' . trim($t['title'])
                  . ($t_company !== '' ? ' · ' . trim($t_company) : '')
                  . (isset($t['sub']) && $t['sub'] !== '' ? ' · ' . $t['sub'] : '');
      ?>
      <li>
        <a href="main.php?page=<?php echo urlencode($t['page']); ?>" class="bn-q"
           title="<?php echo db_esc($t_full); ?>">
          <span class="bn-bullet is-<?php echo db_esc($t['tone']); ?>"></span>
          <span class="bn-q-body">
            <span class="bn-q-top">
              <span class="bn-q-no"><?php echo db_esc($t['doc_no']); ?></span>
              <span class="bn-q-text"><?php echo db_esc($t['title']); ?></span>
            </span>
            <?php if ($t_company !== '') { ?>
            <span class="bn-q-company"><?php echo db_esc($t_company); ?></span>
            <?php } ?>
          </span>
          <i data-lucide="arrow-up-right" class="bn-q-go h-3.5 w-3.5" aria-hidden="true"></i>
        </a>
      </li>
      <?php } ?>
    </ul>
    <?php } ?>
  </section>


  <?php if ($is_staff) { ?>

  <!-- ---------- ไทล์เล็ก : จำนวนสินค้า ---------- -->
  <?php $c = db_can('product_list'); ?>
  <<?php echo $c ? 'a href="main.php?page=product_list"' : 'div'; ?> class="bn-tile bn-stat<?php echo $c ? ' is-link' : ''; ?>">
    <i data-lucide="package" class="bn-stat-icon h-4 w-4" aria-hidden="true"></i>
    <span class="bn-stat-num num"><?php echo number_format($sum['products']); ?></span>
    <span class="bn-stat-cap">รายการสินค้า</span>
  </<?php echo $c ? 'a' : 'div'; ?>>

  <!-- ---------- ไทล์เล็ก : ต่ำกว่าจุดสั่งซื้อ ---------- -->
  <?php
  $c = db_can('report_low');

  /* ---------- หมายเหตุใต้ตัวเลข ----------
   * เลขที่นี่นับได้เฉพาะสินค้าที่ตั้งจุดสั่งซื้อไว้แล้วเท่านั้น
   * ของเดิมเตือนเรื่องนี้ต่อเมื่อ "ไม่มีสินค้าตั้งจุดสั่งซื้อเลยสักตัว" (with_min === 0)
   * ซึ่งไม่พอ เพราะพอมีตั้งไว้ตัวเดียวจาก 313 ตัว เงื่อนไขก็เป็นเท็จทันที
   * แล้วกลับไปแสดงข้อความที่อ่านเหมือนทุกอย่างปกติ ทั้งที่ครอบคลุมแค่ 0.3%
   *
   * เปลี่ยนเป็นดูสัดส่วนความครอบคลุมแทน ถ้ายังไม่ถึงครึ่งให้บอกตรง ๆ ว่า
   * ตัวเลขนี้ดูได้แค่ไหน เพราะเลขน้อย ๆ ที่มาจากฐานแคบ อ่านผิดง่ายที่สุด
   */
  $with_min = (int)$sum['with_min'];
  $prod_n   = (int)$sum['products'];

  if ($with_min === 0) {
      $low_note = 'ยังไม่ได้ตั้งจุดสั่งซื้อ';
  } elseif ($prod_n > 0 && $with_min < $prod_n / 2) {
      $low_note = 'นับจากที่ตั้งจุดสั่งซื้อแล้ว ' . number_format($with_min)
                . '/' . number_format($prod_n) . ' รายการ';
  } elseif ($sum['out_of_stock'] > 0) {
      $low_note = 'ของหมด ' . number_format($sum['out_of_stock']) . ' รายการ';
  } else {
      $low_note = 'อยู่ในเกณฑ์ปกติ';
  }

  /* ระบายสีเตือนเมื่อมีของต่ำกว่าจุดสั่งซื้อจริง หรือเมื่อฐานที่ใช้นับแคบเกินไป
     กรณีหลังคือ "เลข 0 ที่เชื่อไม่ได้" ซึ่งควรสะดุดตาพอกับเลขที่มีปัญหาจริง */
  $low_tone = ($sum['low'] > 0 || ($prod_n > 0 && $with_min < $prod_n / 2)) ? ' is-warn' : '';
  ?>
  <<?php echo $c ? 'a href="main.php?page=report_low"' : 'div'; ?> class="bn-tile bn-stat<?php echo $low_tone; ?><?php echo $c ? ' is-link' : ''; ?>">
    <i data-lucide="triangle-alert" class="bn-stat-icon h-4 w-4" aria-hidden="true"></i>
    <span class="bn-stat-num num"><?php echo number_format($sum['low']); ?></span>
    <span class="bn-stat-cap">ต่ำกว่าจุดสั่งซื้อ</span>
    <span class="bn-stat-note"><?php echo db_esc($low_note); ?></span>
  </<?php echo $c ? 'a' : 'div'; ?>>

  <!-- ---------- ไทล์กว้าง : กราฟความเคลื่อนไหว ----------
       เดิมเป็นแท่ง CSS ที่ PHP วาดตายตัวไว้ 7 วัน เปลี่ยนช่วงไม่ได้เลย
       ตอนนี้วาดด้วย ECharts และเลือกช่วงกับความละเอียดเองได้

       ค่าเริ่มต้นยังเป็น 7 วันล่าสุดเหมือนเดิม คนที่เปิดหน้ามาจะได้เห็น
       สิ่งเดียวกับที่เคยเห็น ไม่ต้องมาตั้งค่าก่อนถึงจะได้ข้อมูล -->
  <section class="bn-tile bn-chart" id="bnChartTile">
    <div class="bn-tile-head">
      <h3>ความเคลื่อนไหวของคลัง</h3>
      <span class="bn-legend">
        <span><i class="bn-key is-in"></i>เข้า</span>
        <span><i class="bn-key is-out"></i>ออก</span>
      </span>
    </div>

    <!-- ---------- ตัวกรองช่วงเวลา ----------
         ปุ่มลัดสี่ปุ่มครอบคำถามที่ถามบ่อยที่สุดไว้แล้ว ส่วนช่องวันที่มีไว้
         สำหรับช่วงเฉพาะกิจ เช่นตรวจย้อนหลังตอนปิดบัญชี

         ความละเอียด (วัน/เดือน/ปี) แยกจากช่วง เพราะเป็นคนละคำถาม
         "ดูย้อนหลังแค่ไหน" กับ "อยากเห็นละเอียดแค่ไหน" ไม่ได้ผูกกันเสมอไป -->
    <div class="bn-cf">
      <div class="bn-cf-quick" id="bnCfQuick" role="group" aria-label="ช่วงเวลาที่ใช้บ่อย">
        <button type="button" class="bn-cf-btn is-on" data-range="7d">7 วัน</button>
        <button type="button" class="bn-cf-btn" data-range="30d">30 วัน</button>
        <button type="button" class="bn-cf-btn" data-range="ytd">ปีนี้</button>
        <button type="button" class="bn-cf-btn" data-range="3y">3 ปี</button>
      </div>

      <div class="bn-cf-unit" id="bnCfUnit" role="group" aria-label="ความละเอียดของกราฟ">
        <button type="button" class="bn-cf-btn is-on" data-unit="day">รายวัน</button>
        <button type="button" class="bn-cf-btn" data-unit="month">รายเดือน</button>
        <button type="button" class="bn-cf-btn" data-unit="year">รายปี</button>
      </div>

      <div class="bn-cf-range" id="bnCfRange">
        <input type="date" id="bnFrom" class="bn-cf-date" aria-label="ตั้งแต่วันที่">
        <span class="bn-cf-sep" aria-hidden="true">ถึง</span>
        <input type="date" id="bnTo" class="bn-cf-date" aria-label="ถึงวันที่">
      </div>
    </div>

    <!-- ECharts วาดลงกล่องนี้ ต้องมีความสูงจริงตั้งแต่แรก
         ไม่งั้นจะคำนวณขนาดตอนสร้างได้ 0 แล้วกราฟไม่ขึ้น -->
    <div id="bnChart" class="bn-chart-box"></div>

    <p class="bn-blank bn-chart-blank" id="bnChartBlank" hidden></p>

    <p class="bn-chart-foot">
      <span id="bnChartSum">
        รวม <b class="doc-in">+0</b> <span class="bn-dot">·</span> <b class="doc-out">−0</b>
      </span>
      <span class="bn-chart-today">วันนี้ เข้า <?php echo db_esc(stock_format($sum['today_in'])); ?>
        · ออก <?php echo db_esc(stock_format($sum['today_out'])); ?></span>
    </p>
  </section>


  <?php if ($money !== null) { ?>
  <!-- ---------- ไทล์กว้าง : เงินของเดือนนี้ ----------
       มิติเงินเพิ่งมีในระบบ แต่หน้าภาพรวมยังไม่เคยพูดถึงเลย
       ทั้งที่เป็นตัวเลขที่ผู้ดูแลต้องตอบให้ได้ทุกสิ้นเดือน
       จำกัดสิทธิ์เท่ากับหน้ารายงานค่าใช้จ่าย (ดู dashboard_money) -->
  <section class="bn-tile bn-money" id="bnMoney">
    <div class="bn-tile-head">
      <h3>เงินของเดือน<?php echo db_esc(thai_month($money['month'])); ?></h3>
      <?php if (db_can('report_cost')) { ?>
      <a href="main.php?page=report_cost" class="bn-more">ดูรายงานเต็ม</a>
      <?php } ?>
    </div>

    <div class="bn-money-row">
      <div class="bn-money-cell is-out">
        <span class="bn-money-cap">เบิกใช้ไป</span>
        <span class="bn-money-num num"><?php echo db_money($money['spent']); ?></span>
      </div>
      <div class="bn-money-cell is-in">
        <span class="bn-money-cap">ซื้อเข้ามา</span>
        <span class="bn-money-num num"><?php echo db_money($money['received']); ?></span>
      </div>
      <div class="bn-money-cell">
        <span class="bn-money-cap">มูลค่าคงคลัง</span>
        <span class="bn-money-num num"><?php echo db_money($money['stock_value']); ?></span>
      </div>
    </div>

    <?php if (count($money['top']) > 0) { ?>
    <div class="bn-money-top">
      <p class="bn-money-top-cap">หน่วยงานที่เบิกใช้มากที่สุด</p>
      <?php foreach ($money['top'] as $t) { ?>
      <div class="bn-dept">
        <span class="bn-dept-head">
          <span class="bn-dept-name"><?php echo db_esc($t['name']); ?></span>
          <span class="bn-dept-num num"><?php echo db_money($t['amount']); ?></span>
        </span>
        <span class="bn-dept-bar"><span class="bn-dept-fill" style="width: <?php echo (int)$t['pct']; ?>%"></span></span>
      </div>
      <?php } ?>
    </div>
    <?php } else { ?>
    <p class="bn-blank">ยังไม่มีการเบิกใช้ในเดือนนี้</p>
    <?php } ?>

    <!-- ทั้งสามตัวเลขข้างบนคิดจากราคาที่กรอกไว้ในทะเบียนสินค้า
         สินค้าที่ยังไม่ได้ตั้งราคาถูกคิดเป็นศูนย์ ยอดจึงต่ำกว่าความจริง
         ต้องบอกไว้ ไม่งั้นตัวเลขนี้ถูกเอาไปใช้ตัดสินใจโดยไม่รู้ว่ามันไม่ครบ -->
    <?php if ($health !== null && $health['no_price'] > 0) { ?>
    <p class="bn-money-caveat">
      <i data-lucide="info" class="h-3.5 w-3.5" aria-hidden="true"></i>
      คิดจากสินค้าที่ตั้งราคาแล้ว
      <b class="num"><?php echo number_format($health['products'] - $health['no_price']); ?></b>
      จาก <b class="num"><?php echo number_format($health['products']); ?></b> รายการ —
      ที่เหลือถูกนับเป็นศูนย์
    </p>
    <?php } ?>
  </section>
  <?php } ?>


  <!-- ---------- ไทล์กว้าง : ต้องสั่งเพิ่ม ---------- -->
  <section class="bn-tile bn-lows is-w<?php echo (int)$lows_w; ?>" id="bnLows">
    <div class="bn-tile-head">
      <h3>ต้องสั่งเพิ่ม</h3>
      <?php if (db_can('report_low')) { ?>
      <a href="main.php?page=report_low" class="bn-more">ดูทั้งหมด</a>
      <?php } ?>
    </div>

    <?php if (count($lows) === 0) { ?>
    <p class="bn-blank">
      <?php echo ($sum['with_min'] === 0)
                 ? 'ยังไม่ได้ตั้งจุดสั่งซื้อให้สินค้าใด — ตั้งได้ที่หน้า "รายการสินค้า"'
                 : 'ทุกรายการยังอยู่เหนือจุดสั่งซื้อ'; ?>
    </p>
    <?php } else { ?>
    <ul class="bn-low-list">
      <?php foreach ($lows as $p) {
          $out = ($p['qty'] <= 0);
          $pct = ($p['min_qty'] > 0) ? min(100, ($p['qty'] / $p['min_qty']) * 100) : 0;
      ?>
      <li class="bn-low">
        <span class="bn-low-top">
          <span class="bn-code"><?php echo db_esc($p['product_code']); ?></span>
          <span class="bn-low-name"><?php echo db_esc($p['product_name']); ?></span>
          <span class="bn-low-qty <?php echo $out ? 'is-out' : 'is-low'; ?>">
            <?php echo db_esc(stock_format($p['qty'])); ?><span class="bn-low-min">/<?php echo db_esc(stock_format($p['min_qty'])); ?></span>
          </span>
        </span>
        <span class="bn-low-bar">
          <span class="bn-low-fill<?php echo $out ? ' is-out' : ''; ?>" style="width: <?php echo (int)$pct; ?>%"></span>
        </span>
      </li>
      <?php } ?>
    </ul>
    <?php } ?>
  </section>

  <?php } else { ?>

  <!-- ---------- ผู้ใช้ทั่วไป : ไทล์เล็กของตัวเอง ---------- -->
  <?php
  $mine = array(
      array('num' => $sum['my_waiting'],  'cap' => 'ใบเบิกรออนุมัติ',  'icon' => 'clock',           'page' => 'request_list'),
      array('num' => $sum['my_approved'], 'cap' => 'อนุมัติแล้ว',       'icon' => 'clipboard-check', 'page' => 'request_list'),
      array('num' => $sum['waiting_ack'], 'cap' => 'รอเซ็นรับของ',      'icon' => 'pen-line',        'page' => 'issue_history'),
      array('num' => $sum['my_total'],    'cap' => 'ใบเบิกทั้งหมด',     'icon' => 'files',           'page' => 'request_list')
  );
  foreach ($mine as $m) {
      $c = db_can($m['page']);
  ?>
  <<?php echo $c ? 'a href="main.php?page=' . urlencode($m['page']) . '"' : 'div'; ?> class="bn-tile bn-stat<?php echo $c ? ' is-link' : ''; ?>">
    <i data-lucide="<?php echo db_esc($m['icon']); ?>" class="bn-stat-icon h-4 w-4" aria-hidden="true"></i>
    <span class="bn-stat-num num"><?php echo number_format($m['num']); ?></span>
    <span class="bn-stat-cap"><?php echo db_esc($m['cap']); ?></span>
  </<?php echo $c ? 'a' : 'div'; ?>>
  <?php } ?>

  <?php } ?>


  <!-- ---------- ไทล์ : ทางลัด ---------- -->
  <section id="bnLinks" class="bn-tile bn-links is-w<?php echo (int)$links_w; ?>">
    <div class="bn-tile-head"><h3>ทางลัด</h3></div>
    <div class="bn-link-grid">
      <?php
      /* เรียงตามลำดับที่คนใช้จริงในหนึ่งวัน: ขอเบิก -> อนุมัติ -> จ่าย -> รับเข้า -> ดูยอด */
      $shortcuts = array('request_new', 'request_list', 'issue_new', 'receive_new', 'report_stock', 'product_list');
      foreach ($shortcuts as $key) {
          if (!db_can($key)) { continue; }
          $item = $routes_all[$key];
      ?>
      <a href="main.php?page=<?php echo urlencode($item['key']); ?>" class="bn-link">
        <i data-lucide="<?php echo db_esc($item['icon']); ?>" class="h-4 w-4" aria-hidden="true"></i>
        <span><?php echo db_esc($item['title']); ?></span>
      </a>
      <?php } ?>
    </div>
  </section>


  <?php if ($is_staff) { ?>
  <!-- ---------- ไทล์กว้าง : ความเคลื่อนไหวล่าสุด ---------- -->
  <section id="bnMoves" class="bn-tile bn-moves is-w<?php echo (int)$moves_w; ?>">
    <div class="bn-tile-head">
      <h3>ความเคลื่อนไหวล่าสุด</h3>
      <?php if (db_can('report_movement')) { ?>
      <a href="main.php?page=report_movement" class="bn-more">ดูทั้งหมด</a>
      <?php } ?>
    </div>

    <?php if (count($moves) === 0) { ?>
    <p class="bn-blank">ยังไม่มีความเคลื่อนไหว — เริ่มจากบันทึกรับสินค้าเข้าคลัง</p>
    <?php } else { ?>
    <ul class="bn-move-list">
      <?php foreach ($moves as $m) { $in = ($m['qty_in'] > 0); ?>
      <li class="bn-move">
        <span class="bn-move-time"><?php echo db_esc(thai_date_format($m['move_date'], 'time', '')); ?></span>
        <span class="bn-move-mark<?php echo $in ? ' is-in' : ' is-out'; ?>">
          <i data-lucide="<?php echo $in ? 'arrow-down-left' : 'arrow-up-right'; ?>" class="h-3 w-3" aria-hidden="true"></i>
        </span>
        <span class="bn-move-body">
          <span class="bn-move-name">
            <span class="bn-code"><?php echo db_esc($m['product_code']); ?></span>
            <?php echo db_esc($m['product_name']); ?>
          </span>
          <span class="bn-move-sub"><?php echo db_esc($m['move_type_label']); ?> · <?php echo db_esc($m['doc_no']); ?></span>
        </span>
        <span class="bn-move-qty <?php echo $in ? 'doc-in' : 'doc-out'; ?>">
          <?php echo $in ? '+' . db_esc(stock_format($m['qty_in'])) : '−' . db_esc(stock_format($m['qty_out'])); ?>
        </span>
      </li>
      <?php } ?>
    </ul>
    <?php } ?>
  </section>
  <?php } ?>

  <!-- ---------- ไทล์กว้าง : ความพร้อมของข้อมูล ----------
       วางไว้ก่อนกราฟและก่อนไทล์เงิน เพราะตัวเลขในสองไทล์นั้นเชื่อถือได้แค่ไหน
       ขึ้นอยู่กับว่าข้อมูลตั้งต้นครบหรือยัง ต้องเห็นข้อจำกัดก่อนเห็นตัวเลข

       หายไปเองเมื่อกรอกครบ ไม่ใช่ไทล์ที่อยู่ถาวร
       ไทล์เตือนที่เตือนตลอดไปแม้ไม่มีอะไรให้ทำ คือไทล์ที่คนเลิกมอง -->
  <?php if ($health !== null && $health['has_gap']) { ?>
  <section class="bn-tile bn-health" id="bnHealth">
    <div class="bn-tile-head">
      <h3>ความพร้อมของข้อมูล</h3>
      <span class="bn-health-pct num"><?php echo (int)$health['percent']; ?>%</span>
    </div>

    <div class="bn-health-bar" role="img"
         aria-label="กรอกข้อมูลตั้งต้นแล้ว <?php echo (int)$health['percent']; ?> เปอร์เซ็นต์">
      <span style="width: <?php echo (int)$health['percent']; ?>%"></span>
    </div>

    <p class="bn-health-lead">
      ทะเบียนสินค้ามี <b class="num"><?php echo number_format($health['products']); ?></b> รายการ
      แต่ยังกรอกค่าที่ระบบต้องใช้ไม่ครบ ตัวเลขในไทล์อื่นจึงยังไม่สะท้อนของจริงทั้งหมด
    </p>

    <ul class="bn-health-list">
      <?php if ($health['no_price'] > 0) { ?>
      <li class="bn-health-row">
        <span class="bn-health-ico is-money"><i data-lucide="circle-dollar-sign" class="h-4 w-4" aria-hidden="true"></i></span>
        <span class="bn-health-txt">
          <b class="num"><?php echo number_format($health['no_price']); ?></b> รายการยังไม่ได้ตั้งราคา
          <span class="bn-health-why">ทำให้ยอดเงินต่ำกว่าความจริง และหน้ารับเข้าไม่เติมราคาให้</span>
        </span>
        <?php if (db_can('product_list')) { ?>
        <a href="main.php?page=product_list" class="bn-health-go">ไปตั้งค่า</a>
        <?php } ?>
      </li>
      <?php } ?>

      <?php if ($health['no_min'] > 0) { ?>
      <li class="bn-health-row">
        <span class="bn-health-ico is-warn"><i data-lucide="triangle-alert" class="h-4 w-4" aria-hidden="true"></i></span>
        <span class="bn-health-txt">
          <b class="num"><?php echo number_format($health['no_min']); ?></b> รายการยังไม่ได้ตั้งจุดสั่งซื้อ
          <span class="bn-health-why">สินค้าเหล่านี้จะไม่ขึ้นเตือนว่าของใกล้หมด แม้ของจะหมดจริง</span>
        </span>
        <?php if (db_can('product_list')) { ?>
        <a href="main.php?page=product_list" class="bn-health-go">ไปตั้งค่า</a>
        <?php } ?>
      </li>
      <?php } ?>

      <?php if ($health['no_stock'] > 0) { ?>
      <li class="bn-health-row">
        <span class="bn-health-ico is-info"><i data-lucide="package-plus" class="h-4 w-4" aria-hidden="true"></i></span>
        <span class="bn-health-txt">
          <b class="num"><?php echo number_format($health['no_stock']); ?></b> รายการยังไม่เคยรับเข้าเลย
          <span class="bn-health-why">ต่างจาก "ของหมด" ตรงที่ยังไม่เคยลงยอดตั้งต้น ไม่ใช่เบิกจนหมด</span>
        </span>
        <?php if (db_can('receive_new')) { ?>
        <a href="main.php?page=receive_new" class="bn-health-go">บันทึกรับเข้า</a>
        <?php } ?>
      </li>
      <?php } ?>
    </ul>
  </section>
  <?php } ?>

</div>


<?php if ($is_staff) { ?>
<script>
window.DASHCHART = {
    endpoint:  'api/dashboard.php',
    csrfToken: <?php echo app_json($csrf_token); ?>,
    today:     <?php echo app_json(date('Y-m-d')); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/dashboard-chart.js', $ASSET_BASE); ?>"></script>
<?php } ?>
