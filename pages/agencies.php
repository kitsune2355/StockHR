<?php
/* ============================================================
 *  pages/agencies.php — ตั้งค่าหน่วยงาน (บริษัท / แผนก)
 *
 *  ตารางเป็นแบบแก้ในช่องได้ทันที (Tabulator)
 *    - พิมพ์ในช่องค้นหา -> กรองทันทีโดยไม่ต้องรอเซิร์ฟเวอร์
 *    - ดับเบิลคลิกที่ช่อง -> แก้ค่าได้เลย
 *    - เพิ่มได้ทีละหลายแถว หรือวางจาก Excel ทั้งก้อน
 *    - แก้สะสมไว้ก่อน แล้วกด "บันทึก" ส่งทั้งชุดครั้งเดียว
 *
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL
 *    ข้อมูลตั้งต้น  : ดึงผ่าน backend/ แล้วฝังเป็น JSON ให้ตารางใช้ทันที
 *                    (ไม่ต้องรอเรียก API อีกรอบตอนเปิดหน้า)
 *    ตอนบันทึก     : ยิงไปที่ api/agencies.php ซึ่งเรียก backend/ ตัวเดียวกัน
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/company.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$valid_tabs = array('company', 'department');
$tab = (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs)) ? $_GET['tab'] : 'company';

/* ---------- ข้อมูลตั้งต้น ---------- */
$companies       = company_list();
$departments     = department_list();
$company_options = company_options();

/* ---------- ฝัง JSON ลงหน้าอย่างปลอดภัย ----------
 * PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง
 * ไม่งั้นถ้าในข้อมูลมีคำว่า </script> เบราว์เซอร์จะปิดแท็กกลางคัน
 */
function ag_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';

$tabs = array(
    'company'    => array('title' => 'บริษัท', 'icon' => 'building-2', 'count' => count($companies)),
    'department' => array('title' => 'แผนก',   'icon' => 'network',    'count' => count($departments)),
);
?>

<!-- ===== แท็บ ===== -->
<div class="mb-4 flex flex-none flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800">
  <?php foreach ($tabs as $key => $t): ?>
  <a href="main.php?page=agencies&amp;tab=<?php echo $key; ?>" data-tab-link
     class="-mb-px flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition <?php
        echo ($tab === $key)
            ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300'
            : 'border-transparent text-slate-500 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300'; ?>">
    <i data-lucide="<?php echo $t['icon']; ?>" class="h-4 w-4" aria-hidden="true"></i>
    <?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?>
    <span class="num rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400"><?php echo $t['count']; ?></span>
  </a>
  <?php endforeach; ?>
</div>


<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ ===== -->
  <div class="grid-toolbar flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center">

    <!-- ค้นหาทันทีที่พิมพ์ (กรองในเครื่อง ไม่วิ่งกลับเซิร์ฟเวอร์) -->
    <div class="relative flex-1 lg:max-w-xs">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="gridSearch" autocomplete="off"
             placeholder="<?php echo ($tab === 'company') ? 'ค้นหาชื่อบริษัท' : 'ค้นหาชื่อแผนก หรือบริษัท'; ?>"
             class="<?php echo $input; ?> pl-10">
    </div>

    <div class="grid-toolbar-actions flex flex-wrap items-center gap-2 max-lg:justify-end lg:ml-auto">

      <!-- เพิ่มทีละหลายแถว -->
      <div class="flex items-center gap-2">
        <input type="number" id="addCount" value="1" min="1" max="50" aria-label="จำนวนแถวที่จะเพิ่ม"
               class="num h-11 w-16 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900">
        <button type="button" id="btnAddRows" class="<?php echo $btn_soft; ?> btn-compact" title="เพิ่มแถวตามจำนวนที่กรอก">
          <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มแถว</span>
        </button>
      </div>

      <!-- ส่งออกเฉพาะแถวที่ค้นหาอยู่ตอนนั้น ครบทุกหน้า (ดู grid-export.js) -->
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

  <!-- ผลการบันทึกแสดงเป็นกล่อง SweetAlert2 (ดู swal-theme.js) จึงไม่มีแถบผลลัพธ์ในหน้า -->

  <!-- ===== ตาราง ===== -->
  <div id="grid" class="min-h-0 flex-1"></div>

  <!-- ===== คำใบ้การใช้งาน ===== -->
  <div class="grid-hints">
    <span><i data-lucide="mouse-pointer-click" class="h-3.5 w-3.5" aria-hidden="true"></i> ดับเบิลคลิกที่ช่องเพื่อแก้ไข</span>
    <span><i data-lucide="table-properties" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากคลุมแล้ว Ctrl+C คัดลอกไปวางใน Excel ได้</span>
    <span><i data-lucide="move-vertical" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไป</span>
    <span><i data-lucide="clipboard-paste" class="h-3.5 w-3.5" aria-hidden="true"></i> วางจาก Excel ได้ทั้งก้อน</span>
  </div>
</div>


<script>
/* ข้อมูลและค่าตั้งต้นที่หน้าเว็บส่งให้ตาราง */
window.AGENCIES = {
    tab:        <?php echo ag_json($tab); ?>,
    csrfToken:  <?php echo ag_json($csrf_token); ?>,
    endpoint:   'api/agencies.php',
    companies:  <?php echo ag_json($companies); ?>,
    departments:<?php echo ag_json($departments); ?>,
    companyOptions: <?php echo ag_json($company_options); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/agencies.js', $ASSET_BASE); ?>"></script>
