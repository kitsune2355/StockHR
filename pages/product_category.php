<?php
/* ============================================================
 *  pages/product_category.php — ตั้งค่าหมวดหมู่สินค้า (หลัก / ย่อย) และหน่วยนับ
 *
 *  โครงเดียวกับหน้าตั้งค่าหน่วยงาน: ตารางแก้ในช่องได้ทันที (Tabulator)
 *    - พิมพ์ในช่องค้นหา -> กรองทันที
 *    - ดับเบิลคลิกที่ช่องแล้วแก้ได้เลย  สถานะกดสวิตช์
 *    - ลากจุดมุมขวาล่างคัดลอกค่าลงแถวถัดไป  Ctrl+C คัดลอกไปวาง Excel
 *    - แก้สะสมไว้ก่อน แล้วกดบันทึกส่งทั้งชุดครั้งเดียว
 *
 *  ไม่มี SQL — ข้อมูลตั้งต้นฝังเป็น JSON ตอนบันทึกยิงไป api/catalog.php
 *
 *  รหัส (category_code / sub_category_code) ถูกเอาไปประกอบเป็นรหัสสินค้า
 *  เช่น CS-SUP-001 จึงรับเฉพาะ A-Z, 0-9 และขีด แล้วแปลงเป็นตัวพิมพ์ใหญ่ให้เอง
 *
 *  แท็บหน่วยนับต่างจากอีกสองแท็บตรงที่ "ลบ" ได้ เฉพาะหน่วยที่ยังไม่มีสินค้าใช้
 *  (ดู backend/unit.php) มีไว้เก็บกวาดหน่วยที่สะกดเพี้ยนซึ่งหลุดเข้ามาจาก
 *  หน้าทะเบียนสินค้าที่สร้างหน่วยให้อัตโนมัติ
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/category.php';
require_once dirname(dirname(__FILE__)) . '/backend/sub_category.php';
require_once dirname(dirname(__FILE__)) . '/backend/unit.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$valid_tabs = array('category', 'sub_category', 'unit');
$tab = (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs)) ? $_GET['tab'] : 'category';

/* ---------- ข้อมูลตั้งต้น ---------- */
$categories     = category_list();
$sub_categories = sub_category_list();
$units          = unit_list();
$cat_options    = category_options();

/* ---------- ฝัง JSON ลงหน้าอย่างปลอดภัย ----------
 * PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง
 * ไม่งั้นถ้าในข้อมูลมีคำว่า </script> เบราว์เซอร์จะปิดแท็กกลางคัน
 */
function pc_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';

$tabs = array(
    'category'     => array('title' => 'หมวดหมู่หลัก', 'icon' => 'folder',      'count' => count($categories)),
    'sub_category' => array('title' => 'หมวดหมู่ย่อย', 'icon' => 'folder-tree', 'count' => count($sub_categories)),
    'unit'         => array('title' => 'หน่วยนับ',     'icon' => 'ruler',       'count' => count($units)),
);

/* ข้อความในช่องค้นหาและคำใบ้ท้ายตาราง เปลี่ยนตามแท็บ
   แยกมาไว้ตรงนี้เพราะสามแท็บใช้โครงหน้าเดียวกันทั้งหมด ต่างกันแค่คำ */
$search_hint = array(
    'category'     => 'ค้นหารหัส หรือชื่อหมวดหมู่',
    'sub_category' => 'ค้นหารหัส ชื่อหมวดย่อย หรือหมวดหลัก',
    'unit'         => 'ค้นหาชื่อหน่วยนับ'
);

$last_hint = ($tab === 'unit')
    ? array('icon' => 'trash-2', 'text' => 'ลบได้เฉพาะหน่วยที่ยังไม่มีสินค้าใช้ ถ้ามีสินค้าใช้อยู่ให้ปิดใช้งานแทน')
    : array('icon' => 'hash',    'text' => 'รหัสใช้ประกอบรหัสสินค้า ใส่ได้เฉพาะ A-Z 0-9 และ -');
?>

<!-- ===== แท็บ =====
     ทุกลิงก์มี id ของตัวเอง (pcTab-<คีย์>) ให้คู่มือชี้ได้ตรงตัว
     ไม่ต้องพึ่ง selector ที่อิงลำดับหรือคลาส ซึ่งพังทันทีที่เพิ่มแท็บใหม่ -->
<div class="mb-4 flex-none border-b border-slate-200 dark:border-slate-800">
<div id="pcTabs" class="tab-strip-scroll flex items-center gap-1">
  <?php foreach ($tabs as $key => $t): ?>
  <a id="pcTab-<?php echo $key; ?>"
     href="main.php?page=product_category&amp;tab=<?php echo $key; ?>"
     <?php echo ($tab === $key) ? 'aria-current="page"' : ''; ?>
     class="flex flex-none items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold transition <?php
        echo ($tab === $key)
            ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300'
            : 'border-transparent text-slate-500 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300'; ?>">
    <i data-lucide="<?php echo $t['icon']; ?>" class="h-4 w-4" aria-hidden="true"></i>
    <?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?>
    <span class="num rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400"><?php echo $t['count']; ?></span>
  </a>
  <?php endforeach; ?>
</div>
</div>


<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ ===== -->
  <div class="grid-toolbar flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center">

    <div class="relative flex-1 lg:max-w-xs">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="gridSearch" autocomplete="off"
             placeholder="<?php echo $search_hint[$tab]; ?>"
             class="<?php echo $input; ?> pl-10">
    </div>

    <div class="grid-toolbar-actions flex flex-wrap items-center gap-2 max-lg:justify-end lg:ml-auto">

      <div class="flex items-center gap-2">
        <input type="number" id="addCount" value="1" min="1" max="50" aria-label="จำนวนแถวที่จะเพิ่ม"
               class="num h-11 w-16 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900">
        <button type="button" id="btnAddRows" class="<?php echo $btn_soft; ?> btn-compact" title="เพิ่มแถวตามจำนวนที่กรอก">
          <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มแถว</span>
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
    <span><i data-lucide="<?php echo $last_hint['icon']; ?>" class="h-3.5 w-3.5" aria-hidden="true"></i> <?php echo $last_hint['text']; ?></span>
  </div>
</div>


<script>
window.CATALOG = {
    tab:        <?php echo pc_json($tab); ?>,
    csrfToken:  <?php echo pc_json($csrf_token); ?>,
    endpoint:   'api/catalog.php',
    categories:    <?php echo pc_json($categories); ?>,
    subCategories: <?php echo pc_json($sub_categories); ?>,
    units:         <?php echo pc_json($units); ?>,
    catOptions:    <?php echo pc_json($cat_options); ?>,
    subOptions:    [],
    unitOptions:   [],
    products:      []
};
</script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/catalog.js', $ASSET_BASE); ?>"></script>
