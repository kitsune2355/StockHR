<?php
/* ============================================================
 *  pages/approval_settings.php — ตั้งค่าอนุมัติ
 *
 *  กำหนดว่า "ใครกดอนุมัติใบปรับยอดสินค้าได้บ้าง" — เก็บใน tb_adjust_approver
 *  ใครอนุมัติได้ = เฉพาะคนในรายชื่อนี้เท่านั้น ไม่ผูกกับ role
 *  (super_admin / admin ที่ไม่ได้ถูกเลือกไว้ ก็อนุมัติไม่ได้)
 *
 *  จำกัดเฉพาะ super_admin / admin (ดู includes/menu.php และ api/approval_settings.php)
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL ข้อมูลผ่าน api/approval_settings.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$panel = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$input = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== หัวเรื่อง ===== -->
  <div class="flex flex-none items-start gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300">
      <i data-lucide="user-check" class="h-5 w-5" aria-hidden="true"></i>
    </span>
    <div class="min-w-0 flex-1">
      <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">ผู้อนุมัติใบปรับยอดสินค้า</h2>
      <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
        เฉพาะคนที่เลือกไว้ในหน้านี้เท่านั้นที่กดอนุมัติ/ไม่อนุมัติใบปรับยอดได้ — ไม่เกี่ยวกับระดับสิทธิ์
        ผู้ดูแลระบบที่ไม่ได้ถูกเลือกก็อนุมัติไม่ได้
      </p>
    </div>
  </div>

  <div id="loading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดรายชื่อผู้ใช้...
  </div>

  <div id="body" hidden class="flex min-h-0 flex-1 flex-col">

    <!-- ===== ผู้อนุมัติที่เลือกไว้ตอนนี้ ===== -->
    <div class="flex-none border-b border-slate-200 px-4 py-3 dark:border-slate-800">
      <div class="mb-2 flex items-center gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">ผู้อนุมัติที่เลือกไว้</span>
        <span id="pickCount" class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">0 คน</span>
      </div>
      <div id="chips" class="flex flex-wrap gap-2"></div>
      <div id="chipsEmpty" class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span>ยังไม่ได้เลือกผู้อนุมัติ — ใบปรับยอดที่ยื่นเข้ามาจะค้างรอจนกว่าจะเลือกอย่างน้อย 1 คน</span>
      </div>
    </div>

    <!-- ===== ค้นหา ===== -->
    <div class="flex-none border-b border-slate-200 px-4 py-3 dark:border-slate-800">
      <div class="relative max-w-md">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
          <i data-lucide="search" class="h-4 w-4"></i>
        </span>
        <input type="search" id="userSearch" autocomplete="off"
               placeholder="ค้นชื่อ ชื่อผู้ใช้ หน่วยงาน หรือตำแหน่ง" class="<?php echo $input; ?> pl-10">
      </div>
    </div>

    <!-- ===== รายชื่อผู้ใช้ทั้งหมด ===== -->
    <div class="min-h-0 flex-1 overflow-auto">
      <ul id="userList" class="divide-y divide-slate-100 dark:divide-slate-800"></ul>
      <div id="empty" hidden class="p-10 text-center text-sm text-slate-400 dark:text-slate-500">ไม่พบผู้ใช้ที่ค้นหา</div>
    </div>

    <!-- ===== แถบบันทึก (ตรึงล่าง) ===== -->
    <div class="flex flex-none items-center gap-3 border-t border-slate-200 bg-slate-50/60 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/40">
      <span id="dirtyHint" class="text-sm text-amber-600 dark:text-amber-400" hidden>มีการแก้ไขที่ยังไม่ได้บันทึก</span>
      <div class="ml-auto flex items-center gap-2">
        <button type="button" id="btnRevert" class="<?php echo $btn_soft; ?> btn-compact" disabled>
          <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">ยกเลิกการแก้ไข</span>
        </button>
        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="save" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span>บันทึกรายชื่อผู้อนุมัติ</span>
        </button>
      </div>
    </div>
  </div>

  <div class="grid-hints">
    <span><i data-lucide="users" class="h-3.5 w-3.5" aria-hidden="true"></i> ตั้งได้หลายคน — ใครก็ได้ในรายชื่อกดอนุมัติได้ ไม่ต้องครบทุกคน</span>
    <span><i data-lucide="bell" class="h-3.5 w-3.5" aria-hidden="true"></i> คนที่เลือกไว้จะได้อีเมลพร้อมลิงก์กดอนุมัติทุกครั้งที่มีใบปรับยอดใหม่</span>
  </div>
</div>

<script>
window.APPROVAL = {
    csrfToken: <?php echo app_json($csrf_token); ?>,
    endpoint:  'api/approval_settings.php'
};
</script>
<script src="<?php echo asset_url('assets/js/approval-settings.js', $ASSET_BASE); ?>"></script>
