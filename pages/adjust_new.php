<?php
/* ============================================================
 *  pages/adjust_new.php — ยื่นใบปรับยอดสินค้า (ใบตรวจนับ)
 *
 *  เลือกสินค้าเข้ามาทีละตัว กรอก "จำนวนที่นับได้จริง" ระบบคิดผลต่าง
 *  กับยอดในระบบให้เอง กดยื่น = สร้างเอกสาร doc_type='adjust' สถานะ waiting
 *  ยอดคงเหลือ "ยังไม่เปลี่ยน" จนกว่าจะมีผู้อนุมัติ (ดู backend/adjust.php)
 *
 *  จำกัดเฉพาะ super_admin / admin (ดู includes/menu.php และ api/adjust.php)
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL ข้อมูลผ่าน api/adjust.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* app_json() นิยามไว้ที่ includes/head_assets.php แล้ว (แทน < เป็น < กัน </script>) */
function adj_json($value)
{
    return app_json($value);
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$label    = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
?>

<div class="<?php echo $panel; ?>">

  <div id="formLoading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดข้อมูลสินค้าและยอดคงเหลือ...
  </div>

  <div id="formBody" hidden class="flex min-h-0 flex-1 flex-col">

    <!-- ===== ส่วนหัวใบ ===== -->
    <form id="adjForm" class="flex-none border-b border-slate-200 p-4 dark:border-slate-800">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label for="docDate" class="<?php echo $label; ?>">วันที่ตรวจนับ</label>
          <input type="date" id="docDate" class="<?php echo $input; ?>">
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
          <label for="docNote" class="<?php echo $label; ?>">เหตุผลการปรับยอด</label>
          <input type="text" id="docNote" maxlength="300"
                 placeholder="เช่น ตรวจนับประจำปี 2568 / ของชำรุด / ยอดยกมาไม่ตรง"
                 class="<?php echo $input; ?>">
        </div>
      </div>

      <div id="formError" hidden
           class="mt-3 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="formErrorText"></span>
      </div>
    </form>

    <!-- ===== แถบเครื่องมือ ===== -->
    <div class="flex flex-none flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <button type="button" id="btnAddRow" class="<?php echo $btn_soft; ?> btn-compact">
          <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มรายการ</span>
        </button>
        <button type="button" id="btnClear" class="<?php echo $btn_soft; ?> btn-compact">
          <i data-lucide="eraser" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">ล้างรายการ</span>
        </button>
        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="send" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span>ยื่นขออนุมัติ</span>
        </button>
      </div>
    </div>

    <!-- ===== ตารางใบตรวจนับ ===== -->
    <div class="min-h-0 flex-1 overflow-auto">
      <table class="w-full border-collapse text-sm">
        <thead>
          <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            <th class="sticky top-0 z-10 bg-slate-50 px-4 py-2.5 dark:bg-slate-800">สินค้า</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 dark:bg-slate-800">หน่วย</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 text-right dark:bg-slate-800">ยอดในระบบ</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 text-right dark:bg-slate-800">นับได้จริง</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 text-right dark:bg-slate-800">ส่วนต่าง</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 dark:bg-slate-800">หมายเหตุ</th>
            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-2.5 dark:bg-slate-800"></th>
          </tr>
        </thead>
        <tbody id="rows" class="divide-y divide-slate-100 dark:divide-slate-800"></tbody>
      </table>

      <div id="emptyRows" class="flex flex-col items-center gap-2 p-12 text-center text-sm text-slate-400 dark:text-slate-500">
        <i data-lucide="clipboard-list" class="h-8 w-8" aria-hidden="true"></i>
        ยังไม่มีรายการ — กดปุ่ม "เพิ่มรายการ" แล้วเลือกสินค้าในบรรทัด
      </div>
    </div>

    <!-- ===== สรุปท้ายใบ ===== -->
    <div class="flex flex-none flex-wrap items-center gap-x-6 gap-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
      <span class="text-slate-500 dark:text-slate-400">
        รายการ <b id="sumLines" class="num text-slate-800 dark:text-slate-100">0</b>
      </span>
      <span class="text-slate-500 dark:text-slate-400">
        ต้องปรับ <b id="sumChanged" class="num text-slate-800 dark:text-slate-100">0</b>
      </span>
      <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">
        กดยื่น = ส่งขออนุมัติ ยอดคงเหลือจะยังไม่เปลี่ยนจนกว่าจะได้รับอนุมัติ
      </span>
    </div>
  </div>
</div>

<script>
window.ADJUST = {
    csrfToken: <?php echo adj_json($csrf_token); ?>,
    endpoint:  'api/adjust.php',
    today:     <?php echo adj_json(date('Y-m-d')); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/adjust-new.js', $ASSET_BASE); ?>"></script>
