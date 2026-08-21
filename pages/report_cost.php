<?php
/* ============================================================
 *  pages/report_cost.php — รายงานค่าใช้จ่ายรายเดือน (ส่งฝ่ายบัญชี)
 *
 *  ต่างจากรายงานอื่นในระบบตรงที่หน้านี้เป็น "เอกสารที่จะส่งต่อ"
 *  ไม่ใช่หน้าจอไว้เล่นกับข้อมูล จึงไม่ใช้ตาราง Tabulator
 *    - เนื้อหาเป็นตารางสรุปที่ยาวไม่มาก อ่านรวดเดียวจบ ไม่ต้องแบ่งหน้า
 *    - พิมพ์ออกกระดาษได้ตรง ๆ (มี @media print ใน app.css)
 *    - ส่งออก Excel เป็นไฟล์เดียว 4 ชีต ให้บัญชีเปิดต่อได้เลย
 *
 *  ตัวเลขทั้งหมดมาจาก tb_stock_movement ที่แช่แข็งราคา ณ วันนั้นไว้แล้ว
 *  เปิดรายงานเดือนเก่ากี่ครั้งก็ได้ตัวเลขเดิมเสมอ
 *
 *  ไฟล์นี้ไม่มี SQL — ดึงผ่าน api/reports.php action = cost
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/report_cost.php';

function rc_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$months  = cost_months();
$current = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
           ? $_GET['month'] : date('Y-m');

$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>

<div>

  <!-- ===== แถบเครื่องมือ (ไม่พิมพ์ออกกระดาษ) ===== -->
  <div class="rc-bar no-print">
    <label for="rcMonth" class="rc-bar-label">เดือน</label>
    <input type="month" id="rcMonth" class="<?php echo $input; ?>"
           value="<?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?>"
           max="<?php echo date('Y-m'); ?>">

    <?php if (count($months) > 0) { ?>
    <span class="rc-bar-hint">มีข้อมูลตั้งแต่ <?php echo htmlspecialchars(end($months), ENT_QUOTES, 'UTF-8'); ?></span>
    <?php } ?>

    <div class="rc-bar-actions">
      <button type="button" id="btnPrint" class="<?php echo $btn_soft; ?> btn-compact">
        <i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">พิมพ์</span>
      </button>
      <button type="button" id="btnExport" class="<?php echo $btn_soft; ?> btn-compact">
        <i data-lucide="file-spreadsheet" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออก Excel</span>
      </button>
    </div>
  </div>

  <div id="rcLoading" class="rc-loading">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังรวบรวมตัวเลข...
  </div>

  <div id="rcBody" hidden>

    <!-- ===== หัวรายงาน ===== -->
    <header class="rc-head">
      <h1 class="rc-title">รายงานค่าใช้จ่ายวัสดุสิ้นเปลือง</h1>
      <p class="rc-sub">
        ประจำเดือน <b id="rcMonthText"></b>
        <span class="rc-dot">·</span> ออกรายงานเมื่อ <?php echo thai_now(); ?>
        <span class="rc-dot">·</span> โดย <?php echo htmlspecialchars($sess_full_name !== '' ? $sess_full_name : $sess_username, ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <p class="rc-method">
        ราคาที่ใช้คือราคา ณ วันที่ทำรายการจริง — รับเข้าใช้ราคาที่ซื้อ เบิกออกใช้ราคาซื้อล่าสุดของวันนั้น
      </p>
    </header>

    <!-- ===== ยอดสรุป ===== -->
    <div class="rc-totals" id="rcTotals">
      <div class="rc-total is-main">
        <span class="rc-total-cap">ค่าใช้จ่ายรวมทั้งเดือน</span>
        <span class="rc-total-num num" id="sumOut">0.00</span>
        <span class="rc-total-sub"><span id="sumQtyOut">0</span> หน่วย · <span id="sumDept">0</span> หน่วยงาน</span>
      </div>
      <div class="rc-total">
        <span class="rc-total-cap">มูลค่าที่รับเข้าในเดือน</span>
        <span class="rc-total-num num" id="sumIn">0.00</span>
        <span class="rc-total-sub"><span id="sumQtyIn">0</span> หน่วย</span>
      </div>
      <div class="rc-total">
        <span class="rc-total-cap">มูลค่าคงคลัง ณ ปัจจุบัน</span>
        <span class="rc-total-num num" id="sumStock">0.00</span>
        <span class="rc-total-sub">ไม่ใช่ยอด ณ สิ้นเดือนที่เลือก</span>
      </div>
    </div>

    <!-- ===== ตารางที่ 1 : แยกตามบริษัท =====
         แต่ละบริษัทปิดงบแยกกัน จึงต้องมียอดรายบริษัทให้เลย
         ไม่ใช่ให้บัญชีนั่งบวกเองจากตารางรายหน่วยงาน -->
    <section class="rc-sec">
      <h2 class="rc-sec-title"><span class="rc-no">1</span> ค่าใช้จ่ายแยกตามบริษัท</h2>
      <p class="rc-note no-print">กดที่บรรทัดใดก็ได้ เพื่อดูว่ายอดนั้นมาจากเอกสารใบไหนบ้าง</p>
      <div class="rc-table-wrap">
        <table class="rc-table" id="tblComp"></table>
      </div>
    </section>

    <!-- ===== ตารางที่ 2 : แยกตามหน่วยงาน ===== -->
    <section class="rc-sec">
      <h2 class="rc-sec-title"><span class="rc-no">2</span> ค่าใช้จ่ายแยกตามหน่วยงานที่เบิก</h2>
      <div class="rc-table-wrap">
        <table class="rc-table" id="tblDept"></table>
      </div>
    </section>

    <!-- ===== ตารางที่ 3 : แยกตามหมวดหมู่ ===== -->
    <section class="rc-sec">
      <h2 class="rc-sec-title"><span class="rc-no">3</span> ค่าใช้จ่ายแยกตามหมวดหมู่สินค้า</h2>
      <div class="rc-table-wrap">
        <table class="rc-table" id="tblCat"></table>
      </div>
    </section>

    <!-- ===== ตารางที่ 4 : การรับเข้า ===== -->
    <section class="rc-sec">
      <h2 class="rc-sec-title"><span class="rc-no">4</span> สรุปการรับเข้าในเดือน</h2>
      <p class="rc-note">
        แยกบรรทัดตามราคาที่ซื้อ — สินค้าตัวเดียวกันที่ซื้อคนละราคาในเดือนเดียว จะแยกเป็นคนละบรรทัด
      </p>
      <div class="rc-table-wrap">
        <table class="rc-table" id="tblRecv"></table>
      </div>
    </section>

    <!-- ===== ตารางที่ 5 : รายละเอียด ===== -->
    <section class="rc-sec">
      <h2 class="rc-sec-title"><span class="rc-no">5</span> รายละเอียดจ่ายสินค้าออกรายตัวสินค้า แยกตามหน่วยงาน </h2>
      <p class="rc-note no-print">กดที่บรรทัด เพื่อดูว่าสินค้าตัวนี้ถูกเบิกไปวันไหน ด้วยเอกสารใบใด</p>
      <div class="rc-table-wrap">
        <table class="rc-table" id="tblDetail"></table>
      </div>
    </section>
  </div>
</div>


<!-- ============================================================
     กล่องเจาะดูที่มาของยอด
     วางไว้นอก #rcBody เพราะกล่องนี้ไม่ใช่ส่วนหนึ่งของรายงานที่จะพิมพ์
     และต้องแสดงได้แม้ตอนที่รายงานยังโหลดไม่เสร็จ

     ตำแหน่ง fixed คลุมทั้งจอ ไม่ใช่วางในกรอบเนื้อหา เพราะ #appMain
     ตั้ง overflow ไว้ซึ่งจะตัดกล่องนี้หายไปครึ่งหนึ่ง (ปัญหาเดียวกับ
     กล่องรายละเอียดเอกสารและกระดิ่งแจ้งเตือน)
     ============================================================ -->
<div id="rcDrillBackdrop" hidden class="no-print fixed inset-0 z-[70] bg-slate-900/40"></div>

<aside id="rcDrill" hidden
       class="no-print fixed inset-x-3 bottom-3 top-16 z-[71] mx-auto flex max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900 sm:inset-x-6 sm:bottom-6"
       role="dialog" aria-modal="true" aria-labelledby="rcDrillTitle">

  <div class="flex flex-none items-start gap-3 border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
    <div class="min-w-0 flex-1">
      <h2 id="rcDrillTitle" class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">รายละเอียด</h2>
      <p id="rcDrillSum" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"></p>
    </div>
    <button type="button" id="rcDrillClose" aria-label="ปิด"
            class="flex h-8 w-8 flex-none items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-200 dark:hover:bg-slate-800">
      <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
    </button>
  </div>

  <div id="rcDrillBody" class="nav-scroll flex-1 overflow-auto px-5 py-4"></div>
</aside>


<script>
window.REPORTCOST = {
    endpoint: 'api/reports.php',
    month:    <?php echo rc_json($current); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/report-cost.js', $ASSET_BASE); ?>"></script>
