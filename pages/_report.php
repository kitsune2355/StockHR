<?php
/* ============================================================
 *  pages/_report.php — โครงหน้ารายงาน ใช้ร่วมกัน 3 หน้า
 *      report_stock.php / report_low.php / report_movement.php
 *
 *  หน้าที่เรียกต้องตั้งตัวแปรเหล่านี้มาก่อน include:
 *      $mode        stock | low | movement
 *      $page_title / $page_hint
 *      $export_name ชื่อไฟล์ Excel
 *
 *  ตัวกรองอยู่ในแถบด้านขวาทั้งหมด (ดู _filter_side.php)
 *  ช่องที่มีต่างกันตามรายงาน:
 *      ยอดคงเหลือ / ต่ำกว่าจุดสั่งซื้อ  ค้นหา + หมวดหมู่หลัก + สถานะสต๊อก
 *      ความเคลื่อนไหว                  ค้นหา + ช่วงวันที่ + ประเภทรายการ
 *
 *  ช่วงวันที่กับประเภทกรองที่ฝั่งเซิร์ฟเวอร์ (ยิงใหม่ทุกครั้งที่เปลี่ยน)
 *  ส่วนค้นหา/หมวดหมู่/สถานะสต๊อก กรองในหน้าจากข้อมูลที่โหลดมาแล้ว
 *  จึงเห็นผลทันทีโดยไม่ต้องรอเซิร์ฟเวอร์
 *
 *  ไฟล์นี้ไม่มี SQL — ข้อมูลดึงผ่าน api/reports.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/stock.php';
require_once dirname(dirname(__FILE__)) . '/backend/category.php';
require_once dirname(dirname(__FILE__)) . '/backend/sub_category.php';

function rp_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';

$move_types    = stock_move_types();
$categories    = ($mode === 'movement') ? array() : category_options();
$sub_categories = ($mode === 'movement') ? array() : sub_category_options();
$filter_hint   = ($mode === 'movement')
               ? 'ช่วงวันที่และประเภทจะดึงข้อมูลใหม่'
               : 'กรองจากข้อมูลที่แสดงอยู่';

include dirname(__FILE__) . '/_filter_head.php';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ (เหลือเฉพาะปุ่มทำงาน) ===== -->
  <div class="fp-bar">
    <span class="fp-bar-info">
      <?php if ($mode === 'movement') { ?>
      <span id="rangeText"></span>
      <?php } else { ?>
      ข้อมูล ณ <?php echo thai_now(); ?>
      <?php } ?>
    </span>

    <div class="fp-bar-actions">
      <button type="button" id="btnReload" class="<?php echo $btn_soft; ?> btn-compact" title="โหลดข้อมูลใหม่">
        <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">รีเฟรช</span>
      </button>

      <button type="button" id="btnExport" class="<?php echo $btn_soft; ?> btn-compact" title="ส่งออกเป็นไฟล์ Excel">
        <i data-lucide="file-spreadsheet" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออก Excel</span>
      </button>

      <button type="button" id="fpToggle" class="<?php echo $btn_soft; ?> btn-compact fp-toggle">
        <i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">ตัวกรอง</span>
        <span id="fpCount" class="fp-count num" hidden>0</span>
      </button>
    </div>
  </div>

  <div id="repLoading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังรวบรวมข้อมูล...
  </div>

  <div id="repBody" hidden class="flex min-h-0 flex-1 flex-col">
    <div class="flex flex-none border-b border-slate-200 px-4 py-3 dark:border-slate-800">
      <div id="repSummary" class="rep-tiles"></div>
    </div>

    <div id="grid" class="min-h-0 flex-1"></div>
  </div>

  <div class="grid-hints">
    <?php if ($mode === 'movement') { ?>
    <span><i data-lucide="history" class="h-3.5 w-3.5" aria-hidden="true"></i> ทุกครั้งที่ยอดเปลี่ยนจะมีบรรทัดที่นี่เสมอ ลบไม่ได้ แก้ไม่ได้</span>
    <span><i data-lucide="calendar" class="h-3.5 w-3.5" aria-hidden="true"></i> ไม่เลือกช่วงวันที่ = ย้อนหลัง 30 วัน</span>
    <?php } else { ?>
    <span><i data-lucide="circle-alert" class="h-3.5 w-3.5" aria-hidden="true"></i> สีส้ม = ถึงจุดสั่งซื้อแล้ว · สีแดง = ของหมด</span>
    <span><i data-lucide="settings-2" class="h-3.5 w-3.5" aria-hidden="true"></i> จุดสั่งซื้อตั้งได้ที่หน้า "รายการสินค้า"</span>
    <?php } ?>
    <span><i data-lucide="table-properties" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากคลุมแล้ว Ctrl+C คัดลอกไปวางใน Excel ได้</span>
  </div>
</div>

<?php include dirname(__FILE__) . '/_filter_side.php'; ?>

      <div class="fp-field">
        <label for="gridSearch" class="<?php echo $fp_label; ?>">ค้นหา</label>
        <div class="relative">
          <span class="pointer-events-none absolute inset-y-0 left-0 flex w-9 items-center justify-center text-slate-400" aria-hidden="true">
            <i data-lucide="search" class="h-4 w-4"></i>
          </span>
          <input type="search" id="gridSearch" autocomplete="off"
                 placeholder="รหัสสินค้า ชื่อสินค้า<?php echo ($mode === 'movement') ? ' เลขที่เอกสาร' : ''; ?>"
                 class="<?php echo $fp_input; ?> pl-9">
        </div>
      </div>

      <?php if ($mode === 'movement') { ?>

      <div class="fp-field">
        <span class="<?php echo $fp_label; ?>">ช่วงวันที่</span>
        <div class="fp-range">
          <!-- ค่าตั้งต้นคือย้อนหลัง 30 วัน filter-panel.js จำค่านี้ไว้เป็นฐาน
               จึงไม่นับเป็น "กรองอยู่" จนกว่าผู้ใช้จะเปลี่ยนเอง -->
          <input type="date" id="dateFrom" class="<?php echo $fp_input; ?>" aria-label="ตั้งแต่วันที่"
                 value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
          <span class="fp-range-sep" aria-hidden="true">ถึง</span>
          <input type="date" id="dateTo" class="<?php echo $fp_input; ?>" aria-label="ถึงวันที่"
                 value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>

      <div class="fp-field">
        <label for="moveType" class="<?php echo $fp_label; ?>">ประเภทรายการ</label>
        <select id="moveType" class="<?php echo $fp_input; ?>"
                data-search data-search-placeholder="พิมพ์ชื่อประเภท">
          <option value="">ทุกประเภท</option>
          <?php foreach ($move_types as $key => $name) { ?>
          <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></option>
          <?php } ?>
        </select>
      </div>

      <?php } else { ?>

      <div class="fp-field">
        <label for="repCategory" class="<?php echo $fp_label; ?>">หมวดหมู่หลัก</label>
        <select id="repCategory" class="<?php echo $fp_input; ?>"
                data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่">
          <option value="">ทุกหมวดหมู่</option>
          <?php foreach ($categories as $cat) { ?>
          <option value="<?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php } ?>
        </select>
      </div>

      <div class="fp-field">
        <label for="repSubCategory" class="<?php echo $fp_label; ?>">หมวดหมู่ย่อย</label>
        <select id="repSubCategory" class="<?php echo $fp_input; ?>"
                data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่ย่อย">
          <option value="">ทุกหมวดหมู่ย่อย</option>
          <?php foreach ($sub_categories as $sub) { ?>
          <option value="<?php echo (int)$sub['sub_category_id']; ?>"><?php echo htmlspecialchars($sub['label'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php } ?>
        </select>
      </div>

      <div class="fp-field">
        <label for="repState" class="<?php echo $fp_label; ?>">สถานะสต๊อก</label>
        <select id="repState" class="<?php echo $fp_input; ?>"
                data-search data-search-placeholder="พิมพ์ชื่อสถานะ">
          <option value="">ทุกสถานะ</option>
          <option value="ok">ปกติ</option>
          <option value="low">ต้องสั่งเพิ่ม</option>
          <option value="out">ของหมด</option>
        </select>
      </div>

      <?php } ?>

<?php include dirname(__FILE__) . '/_filter_foot.php'; ?>


<script>
window.REPORT = {
    mode:        <?php echo rp_json($mode); ?>,
    endpoint:    'api/reports.php',
    exportName:  <?php echo rp_json($export_name); ?>,
    categories:  <?php echo rp_json($categories); ?>,
    subCategories: <?php echo rp_json($sub_categories); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/reports.js', $ASSET_BASE); ?>"></script>
