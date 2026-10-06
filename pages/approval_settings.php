<?php
/* ============================================================
 *  pages/approval_settings.php — ตั้งค่าอนุมัติ
 *
 *  กำหนดว่า "ใครกดอนุมัติใบปรับยอดสินค้าได้บ้าง" — เก็บใน tb_adjust_approver
 *  ใครอนุมัติได้ = เฉพาะคนในรายชื่อนี้เท่านั้น ไม่ผูกกับ role
 *  (super_admin / admin ที่ไม่ได้ถูกเลือกไว้ ก็อนุมัติไม่ได้)
 *
 *  ---------------------------------------------------------------
 *  ทำไมเป็นสองฝั่ง (เดิมเป็นรายการเดียว + สวิตช์ชิดขวา)
 *  ---------------------------------------------------------------
 *  ของเดิมชื่ออยู่ซ้ายสุด สวิตช์อยู่ขวาสุดของแถวที่กว้างเต็มจอ ตาต้องลากข้ามจอ
 *  ทุกครั้ง และกดสวิตช์ผิดแถวได้ง่าย แก้สองชั้น:
 *    1. ช่องติ๊กอยู่ติดชื่อ และกดได้ทั้งแถว — ไม่ต้องเล็งปุ่มเล็ก ๆ ที่อยู่ไกล
 *    2. ฝั่งขวาแสดง "ผลลัพธ์หลังบันทึก" ตลอด พร้อมป้ายเพิ่มใหม่/จะถูกเอาออก
 *       กดผิดเห็นทันที และกล่องยืนยันก่อนบันทึกสรุปสิ่งที่จะเปลี่ยนอีกรอบ
 *  โครงเดียวกับตัวเลือกสองฝั่งของหน้าทำรายการเบิก/สินค้าของหน่วยงาน
 *
 *  จำกัดเฉพาะ super_admin / admin (ดู includes/menu.php และ api/approval_settings.php)
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL ข้อมูลผ่าน api/approval_settings.php
 *  id ที่คู่มือชี้ (#userSearch, #btnSave) ห้ามเปลี่ยนโดยไม่แก้ tb_guide_step ด้วย
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$pane     = 'flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800';
$input    = 'input-control h-10 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
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
      <!-- จอเล็กซ่อนคำอธิบาย เหลือที่ให้รายชื่อ (ใจความเดียวกันอยู่ในแถบคำแนะนำท้ายหน้าแล้ว) -->
      <p class="mt-0.5 hidden text-sm text-slate-500 sm:block dark:text-slate-400">
        ติ๊กชื่อทางซ้ายเพื่อเพิ่ม ตรวจรายชื่อทางขวา แล้วกดบันทึก — เฉพาะคนในรายชื่อเท่านั้นที่อนุมัติใบปรับยอดได้
        ผู้ดูแลระบบที่ไม่ได้ถูกเลือกก็อนุมัติไม่ได้
      </p>
    </div>
  </div>

  <div id="loading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดรายชื่อผู้ใช้...
  </div>

  <div id="body" hidden class="flex min-h-0 flex-1 flex-col">

    <!-- จอกว้าง (lg+): สองฝั่งคู่กัน
         จอเล็ก/แท็บเล็ต: เห็นทีละฝั่ง สลับด้วยแท็บ — ฝั่งที่แสดงกินความสูงเต็มกรอบ
         ไม่ต้องเลื่อนซ้อนกันสองชั้น (data-tab คุมด้วย CSS .ap-wrap[data-tab]) -->
    <div id="apWrap" data-tab="all"
         class="ap-wrap min-h-0 flex-1 gap-4 p-3 sm:p-4 lg:grid lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">

      <!-- แท็บสลับฝั่ง ใช้หน้าตาเดียวกับหน้าทำรายการเบิก (.tf-tabs) — โผล่เฉพาะจอ < lg -->
      <div class="tf-tabs ap-tabs" role="tablist" aria-label="สลับฝั่งที่แสดง">
        <button type="button" class="tf-tab is-active" data-tab="all"
                role="tab" aria-selected="true" aria-controls="apPaneAll">
          <span class="tf-tab-text">ผู้ใช้ทั้งหมด</span>
          <span class="tf-tab-num" id="tabAllNum">0</span>
        </button>
        <button type="button" class="tf-tab" data-tab="picked"
                role="tab" aria-selected="false" aria-controls="apPanePicked" tabindex="-1">
          <span class="tf-tab-text">ผู้อนุมัติ</span>
          <span class="tf-tab-num is-on" id="tabPickNum">0</span>
        </button>
      </div>

      <!-- ============================================================
           ซ้าย: ผู้ใช้ทั้งหมด — กดทั้งแถวเพื่อเลือก/เอาออก
           ============================================================ -->
      <section id="apPaneAll" class="<?php echo $pane; ?> ap-pane ap-pane-all" aria-labelledby="apAllHead">
        <div class="flex flex-none flex-wrap items-center gap-2 border-b border-slate-200 px-3 py-2.5 dark:border-slate-800">
          <h3 id="apAllHead" class="text-sm font-semibold text-slate-700 dark:text-slate-200">ผู้ใช้ทั้งหมด</h3>
          <span id="allCount" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">0 คน</span>
          <label class="ml-auto flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <input type="checkbox" id="onlyPicked" class="grid-check-native">
            เฉพาะที่เลือกไว้
          </label>
        </div>

        <div class="flex-none border-b border-slate-100 p-3 dark:border-slate-800">
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
              <i data-lucide="search" class="h-4 w-4"></i>
            </span>
            <input type="search" id="userSearch" autocomplete="off"
                   placeholder="ค้นชื่อ ชื่อผู้ใช้ หน่วยงาน หรือตำแหน่ง" class="<?php echo $input; ?> pl-10">
          </div>
        </div>

        <div class="ap-list min-h-0 flex-1 overflow-auto p-1.5">
          <ul id="userList" class="space-y-0.5" role="list"></ul>
          <div id="empty" hidden class="p-10 text-center text-sm text-slate-400 dark:text-slate-500">ไม่พบผู้ใช้ที่ค้นหา</div>
        </div>
      </section>

      <!-- ============================================================
           ขวา: รายชื่อหลังบันทึก — เห็นผลของสิ่งที่กดทันที
           ============================================================ -->
      <section id="apPanePicked" class="<?php echo $pane; ?> ap-pane ap-pane-picked" aria-labelledby="apPickHead">
        <div class="flex flex-none items-center gap-2 border-b border-slate-200 bg-brand-50/50 px-3 py-2.5 dark:border-slate-800 dark:bg-brand-500/5">
          <i data-lucide="shield-check" class="h-4 w-4 text-brand-600 dark:text-brand-300" aria-hidden="true"></i>
          <h3 id="apPickHead" class="text-sm font-semibold text-slate-700 dark:text-slate-200">ผู้อนุมัติ</h3>
          <span id="pickCount" class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">0 คน</span>
        </div>

        <div class="ap-list min-h-0 flex-1 overflow-auto p-3">
          <div id="chipsEmpty" class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
            <span>ยังไม่มีผู้อนุมัติ — ใบปรับยอดที่ยื่นเข้ามาจะค้างรอจนกว่าจะเลือกอย่างน้อย 1 คน</span>
          </div>
          <ul id="pickList" class="space-y-1.5" role="list"></ul>

          <!-- คนที่มีอยู่เดิมแต่ถูกติ๊กออกในรอบนี้ — ยังไม่หายจนกว่าจะบันทึก จึงต้องเห็นพร้อมปุ่มย้อน -->
          <div id="removedBox" hidden class="mt-4">
            <p class="mb-1.5 text-xs font-semibold text-red-600 dark:text-red-400">จะถูกเอาออกเมื่อบันทึก</p>
            <ul id="removedList" class="space-y-1.5" role="list"></ul>
          </div>
        </div>
      </section>
    </div>

    <!-- ===== แถบบันทึก (ตรึงล่าง) ===== -->
    <!-- จอเล็ก: ข้อความแก้ไขค้างขึ้นเต็มบรรทัดบนสุด ปุ่มอยู่บรรทัดล่าง ปุ่มบันทึกยืดเต็มที่เหลือ -->
    <div class="ap-bar flex flex-none flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200 bg-slate-50/60 px-3 py-3 sm:px-4 dark:border-slate-800 dark:bg-slate-800/40">
      <span id="dirtyHint" hidden class="inline-flex w-full items-center gap-1.5 text-sm text-amber-700 sm:w-auto dark:text-amber-400">
        <i data-lucide="circle-dot" class="h-4 w-4" aria-hidden="true"></i>
        <span id="dirtyText">มีการแก้ไขที่ยังไม่ได้บันทึก</span>
      </span>
      <div class="ml-auto flex items-center gap-2">
        <button type="button" id="btnRevert" class="<?php echo $btn_soft; ?> btn-compact" disabled>
          <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">ยกเลิกการแก้ไข</span>
        </button>
        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="save" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span><span class="hidden sm:inline">บันทึกรายชื่อผู้อนุมัติ</span><span class="sm:hidden">บันทึก</span></span>
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
