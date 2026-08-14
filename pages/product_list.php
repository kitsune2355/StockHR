<?php
/* ============================================================
 *  pages/product_list.php — ทะเบียนสินค้า
 *
 *  ตารางแก้ในช่องได้ทันที (Tabulator) โครงเดียวกับหน้าอื่นในระบบ
 *
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL
 *    ข้อมูลตั้งต้น : ดึงผ่าน backend/product.php แล้วฝังเป็น JSON
 *    ตอนบันทึก    : ยิงไปที่ api/catalog.php ซึ่งเรียก backend ตัวเดียวกัน
 *
 *  เรื่องรหัสสินค้า:
 *    ไม่ให้กรอกเอง ระบบสร้างให้ตอนบันทึกในรูปแบบ <รหัสหมวดย่อย>-<เลขรัน 3 หลัก>
 *    เช่น เลือกหมวดย่อย CS-SUP แล้วได้ CS-SUP-063
 *    เหตุผลที่ล็อกไว้: เอกสารรับเข้า/เบิกออกจะอ้างถึงรหัสนี้ ถ้าแก้ทีหลัง
 *    เอกสารเก่าจะอ้างไม่ตรง ส่วนรหัสเดิมจาก Excel เก็บไว้ในช่อง "รหัสเดิม" แก้ได้อิสระ
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/product.php';
require_once dirname(dirname(__FILE__)) . '/backend/sub_category.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- ข้อมูลตั้งต้น ---------- */
$products     = product_list();
$sub_options  = sub_category_options();
$unit_options = unit_options();

/* ---------- ฝัง JSON ลงหน้าอย่างปลอดภัย ----------
 * PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง
 */
function pl_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ ===== -->
  <div class="grid-toolbar flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center">

    <div class="relative flex-1 lg:max-w-sm">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="gridSearch" autocomplete="off"
             placeholder="ค้นหารหัสสินค้า ชื่อสินค้า หมวดหมู่ หรือหน่วยนับ"
             class="<?php echo $input; ?> pl-10">
    </div>

    <div class="grid-toolbar-actions flex flex-wrap items-center gap-2 max-lg:justify-end lg:ml-auto">

      <div class="flex items-center gap-2">
        <input type="number" id="addCount" value="1" min="1" max="50" aria-label="จำนวนแถวที่จะเพิ่ม"
               class="num h-11 w-16 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900">
        <button type="button" id="btnAddRows" class="<?php echo $btn_soft; ?> btn-compact" title="เพิ่มสินค้าตามจำนวนที่กรอก">
          <i data-lucide="package-plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มสินค้า</span>
        </button>
      </div>

      <button type="button" id="btnExport" class="<?php echo $btn_soft; ?> btn-compact"
              title="ส่งออกข้อมูลที่แสดงอยู่เป็นไฟล์ Excel">
        <i data-lucide="file-spreadsheet" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออก Excel</span>
      </button>

      <button type="button" id="btnRevert" class="<?php echo $btn_soft; ?> btn-compact"
              title="ยกเลิกการแก้ไขที่ยังไม่ได้บันทึกทั้งหมด" disabled>
        <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">ยกเลิกการแก้ไข</span>
      </button>

      <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
        <i data-lucide="save" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span>บันทึก</span>
        <span id="pendingCount" hidden class="num rounded-full bg-white/25 px-2 py-0.5 text-xs">0</span>
      </button>
    </div>
  </div>

  <!-- ===== แถบสรุปการแก้ไขที่ยังไม่บันทึก ===== -->
  <div id="pendingBar" hidden
       class="flex flex-none items-start gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
    <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
    <span id="pendingText"></span>
  </div>

  <!-- ===== ตาราง ===== -->
  <div id="grid" class="min-h-0 flex-1"></div>

  <!-- ===== คำใบ้การใช้งาน ===== -->
  <div class="grid-hints">
    <span><i data-lucide="mouse-pointer-click" class="h-3.5 w-3.5" aria-hidden="true"></i> ดับเบิลคลิกที่ช่องเพื่อแก้ไข</span>
    <span><i data-lucide="table-properties" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากคลุมแล้ว Ctrl+C คัดลอกไปวางใน Excel ได้</span>
    <span><i data-lucide="move-vertical" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไป</span>
    <span><i data-lucide="hash" class="h-3.5 w-3.5" aria-hidden="true"></i> รหัสสินค้าระบบสร้างให้ตอนบันทึก ตามหมวดหมู่ย่อยที่เลือก</span>
  </div>
</div>


<script>
window.CATALOG = {
    tab:        'product',
    csrfToken:  <?php echo pl_json($csrf_token); ?>,
    endpoint:   'api/catalog.php',
    products:      <?php echo pl_json($products); ?>,
    subOptions:    <?php echo pl_json($sub_options); ?>,
    unitOptions:   <?php echo pl_json($unit_options); ?>,
    categories:    [],
    subCategories: [],
    catOptions:    []
};
</script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/catalog.js', $ASSET_BASE); ?>"></script>
