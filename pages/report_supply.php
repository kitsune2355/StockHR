<?php
/* ============================================================
 *  pages/report_supply.php — รายละเอียดการสั่งซื้อ-เบิกใช้ ประจำเดือน
 *
 *  ใบกระทบยอดของคลัง อ่านเป็นแถวละสินค้า ไล่จากซ้ายไปขวาตามเวลาจริง
 *      ยกมา  +  ซื้อ  -  เบิก (กางเป็นคอลัมน์ตามหน่วยงาน)  =  คงเหลือ
 *
 *  เป็นเอกสารที่จะส่งต่อ ไม่ใช่หน้าจอไว้เล่นกับข้อมูล จึงวาดเป็นตาราง HTML
 *  ธรรมดาเหมือน report_cost.php ไม่ใช้ Tabulator
 *    - หัวตารางเป็นสองชั้นและคอลัมน์ไม่คงที่ (ขึ้นกับหน่วยงานที่เบิกเดือนนั้น)
 *      ซึ่งเขียนเองตรง ๆ ง่ายกว่าไปดัดตารางสำเร็จรูป
 *    - พิมพ์ลงกระดาษได้ตรง ๆ และส่งออก Excel ให้หน้าตาเหมือนที่เห็นบนจอ
 *
 *  ไฟล์นี้ไม่มี SQL — ดึงผ่าน api/reports.php action = supply
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/report_supply.php';

function sp_json($value)
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
    <label for="spMonth" class="rc-bar-label">เดือน</label>
    <input type="month" id="spMonth" class="<?php echo $input; ?>"
           value="<?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?>"
           max="<?php echo date('Y-m'); ?>">

    <div class="relative">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-9 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="spSearch" autocomplete="off" placeholder="รหัสสินค้า หรือชื่อสินค้า"
             class="<?php echo $input; ?> pl-9" aria-label="ค้นหาในรายงาน">
    </div>

    <?php if (count($months) > 0) { ?>
    <span class="rc-bar-hint">มีข้อมูลตั้งแต่ <?php echo htmlspecialchars(end($months), ENT_QUOTES, 'UTF-8'); ?></span>
    <?php } ?>

    <?php /* ต้นแบบที่ใช้กันอยู่กางสินค้าทั้งทะเบียน เพราะใช้เป็นใบไล่ตรวจของด้วย
             แต่วันที่อยากดูแค่ "เดือนนี้ขยับอะไรบ้าง" 335 บรรทัดคือการหาเข็มในกองฟาง
             จึงมีสวิตช์ให้ตัดบรรทัดที่ไม่มีอะไรเกิดขึ้นทิ้ง */ ?>
    <label class="sp-check">
      <input type="checkbox" id="spMovedOnly">
      <span>เฉพาะรายการที่มีความเคลื่อนไหว</span>
    </label>

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

  <div id="spLoading" class="rc-loading">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังรวบรวมตัวเลข...
  </div>

  <div id="spBody" hidden>
    <p class="rc-note" id="spCount"></p>

    <!-- ตารางกว้างกว่าจอเสมอ ต้องเลื่อนแนวนอนในกรอบของตัวเอง
         ไม่ใช่ดันให้ทั้งหน้าเลื่อน ไม่งั้นแถบเครื่องมือจะหลุดออกนอกจอไปด้วย -->
    <div id="spWrap" class="sp-wrap">
      <table class="sp-table" id="spTable"></table>
    </div>

    <div class="grid-hints no-print">
      <span><i data-lucide="move-horizontal" class="h-3.5 w-3.5" aria-hidden="true"></i> เลื่อนตารางไปทางขวาเพื่อดูหน่วยงานที่เหลือ · สี่คอลัมน์แรกตรึงไว้ให้</span>
      <span><i data-lucide="columns-3" class="h-3.5 w-3.5" aria-hidden="true"></i> คอลัมน์หน่วยงานกางเฉพาะหน่วยที่มีการเบิกจริงในเดือนนั้น</span>
      <span><i data-lucide="table-properties" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากคลุมแล้ว Ctrl+C คัดลอกไปวางใน Excel ได้</span>
    </div>
  </div>
</div>

<style>
/* ตารางนี้กว้าง 20-40 คอลัมน์ พิมพ์แนวตั้งแล้วอ่านไม่ออก บังคับเป็นแนวนอน

   อยู่ในหน้านี้ ไม่ได้อยู่ใน app.css เพราะ @page มีผลกับทั้งเอกสาร
   ถ้าใส่ในไฟล์ที่ทุกหน้าใช้ร่วมกัน รายงานหน้าอื่นจะพลอยพิมพ์เป็นแนวนอนไปด้วย
   ทั้งที่ของเขาพอดีหน้ากระดาษแนวตั้งอยู่แล้ว

   เบราว์เซอร์ที่ไม่รับ size ของ @page (Safari รุ่นเก่า) จะได้แนวตั้งตามเดิม
   คนพิมพ์เลือกแนวนอนเองในกล่องพิมพ์ได้ ผลลัพธ์เหมือนกัน */
@media print {
    @page { size: A4 landscape; margin: 1cm; }
}
</style>

<script>
window.REPORTSUPPLY = {
    endpoint: 'api/reports.php',
    month:    <?php echo sp_json($current); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/report-supply.js', $ASSET_BASE); ?>"></script>
