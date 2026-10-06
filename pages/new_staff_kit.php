<?php
/* ============================================================
 *  pages/new_staff_kit.php — ตั้งค่าอุปกรณ์พนักงานใหม่
 *
 *  สองเรื่องที่ต้องแก้คู่กันเสมอ จึงอยู่หน้าเดียวและบันทึกพร้อมกัน:
 *    ซ้าย  รายการพื้นฐาน (tb_new_staff_kit) — ใส่ให้อัตโนมัติในฟอร์มเบิกพนักงานใหม่
 *    ขวา   หมายเหตุท้ายฟอร์ม (tb_app_setting.new_staff_terms)
 *          อ้างเลขรายการ ("รายการที่ 1-4", "ลำดับที่ 4") ถ้าแก้ชุดแล้วลืมแก้หมายเหตุ
 *          เงื่อนไขบนใบที่พนักงานเซ็นจะไม่ตรงกับของที่ได้จริง
 *
 *  เฉพาะผู้ดูแลระบบ (ตรงกับ api/new_staff.php action kit_get / kit_save)
 *  ใบเบิกที่ส่งไปแล้วไม่เปลี่ยนตาม — ธง "ต้องคืน" แช่แข็งไว้ในบรรทัดของใบ
 *
 *  ไฟล์นี้ไม่มี SQL  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$card     = 'rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
?>

<!-- โครงหน้าแบบ 'full' (ดู includes/menu.php): กรอบสูงเท่าจอเสมอ
     เนื้อหา (#kitBody) เลื่อนในตัวเอง ส่วนแถบบันทึก (#kitBar) เป็นลูกตัวสุดท้ายที่ไม่ยืด
     จึงอยู่ล่างสุดทุกขนาดจอ — เดิมใช้ sticky ซึ่งพอเนื้อหาสั้นกว่าจอ แถบจะลอยค้างกลางหน้า -->
<div class="flex min-h-0 w-full flex-1 flex-col">

  <div id="kitLoading" data-guide-wait class="<?php echo $card; ?> flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดอุปกรณ์พนักงานใหม่...
  </div>

  <div id="kitBody" hidden class="kit-scroll min-h-0 flex-1 space-y-4 overflow-y-auto">
    <div class="kit-grid grid gap-4">

      <!-- ============================================================
           ซ้าย: รายการพื้นฐาน
           ============================================================ -->
      <section id="kitSecList" class="<?php echo $card; ?> kit-left xl:col-span-3">
        <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
          <span class="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
            <i data-lucide="pencil-ruler" class="h-[18px] w-[18px]" aria-hidden="true"></i>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-800 dark:text-slate-100">
              รายการเครื่องเขียนพื้นฐาน
              <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span id="kitCount">0</span> รายการ</span>
            </span>
            <span class="block text-sm text-slate-500 dark:text-slate-400">ลำดับตรงนี้คือเลขรายการบนฟอร์มและใบพิมพ์ — ใช้ลูกศรเลื่อนขึ้นลง</span>
          </span>
        </div>

        <!-- เพิ่มสินค้า -->
        <div id="kitAddBox" class="flex flex-col gap-2 border-b border-slate-100 p-5 sm:flex-row dark:border-slate-800">
          <div class="min-w-0 flex-1">
            <label for="kitPick" class="sr-only">เลือกสินค้าที่จะเพิ่มในชุด</label>
            <select id="kitPick" class="<?php echo $input; ?>"
                    data-search data-search-placeholder="พิมพ์รหัสหรือชื่อสินค้า">
              <option value="">— ค้นหาสินค้าที่จะเพิ่มในชุด —</option>
            </select>
          </div>
          <button type="button" id="btnKitAdd" class="<?php echo $btn_soft; ?> justify-center" disabled>
            <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
            เพิ่มในชุด
          </button>
        </div>

        <!-- หัวคอลัมน์ (จอกว้าง) -->
        <div class="kit-colhead items-center gap-3 border-b border-slate-100 bg-slate-50/70 py-2 text-xs font-medium text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
          <span class="text-center">ลำดับ</span>
          <span>สินค้า</span>
          <span>จำนวนตั้งต้น</span>
          <span>ต้องคืน</span>
          <span class="text-right">จัดการ</span>
        </div>

        <div id="kitList" class="divide-y divide-slate-100 dark:divide-slate-800"></div>

        <div id="kitEmpty" hidden class="flex flex-col items-center gap-2 px-5 py-10 text-center text-sm text-slate-400 dark:text-slate-500">
          <i data-lucide="inbox" class="h-6 w-6" aria-hidden="true"></i>
          ยังไม่มีรายการในชุด — ค้นหาสินค้าด้านบนแล้วกด "เพิ่มในชุด"
        </div>
      </section>

      <!-- ============================================================
           ขวา: หมายเหตุท้ายฟอร์ม + ตัวอย่าง
           ============================================================ -->
      <section id="kitSecTerms" class="<?php echo $card; ?> kit-right flex flex-col xl:col-span-2">
        <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
          <span class="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300">
            <i data-lucide="scroll-text" class="h-[18px] w-[18px]" aria-hidden="true"></i>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-800 dark:text-slate-100">หมายเหตุท้ายฟอร์ม</span>
            <span class="block text-sm text-slate-500 dark:text-slate-400">พิมพ์บรรทัดละหนึ่งข้อ ระบบใส่เลขข้อให้เอง</span>
          </span>
        </div>

        <div class="space-y-3 p-5">
          <textarea id="termsInput" rows="7" maxlength="500"
                    class="input-control w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900"
                    placeholder="เช่น รายการที่ 1-4 เป็นรายการพื้นฐานสำหรับพนักงานเข้าใหม่"></textarea>
          <div class="flex items-center justify-between text-xs text-slate-400">
            <span>ตัวอย่างที่ผู้ทำใบจะเห็น</span>
            <span><span id="termsLen" class="num">0</span>/500</span>
          </div>

          <!-- ตัวอย่าง — กรอบเดียวกับหน้าฟอร์ม -->
          <div class="overflow-hidden rounded-2xl border border-rose-200 dark:border-rose-500/30">
            <div class="grid sm:grid-cols-[6.5rem_1fr]">
              <div class="bg-rose-50 px-4 py-2 text-left text-sm font-semibold sm:px-3 sm:py-3 sm:text-right text-rose-900 dark:bg-rose-500/10 dark:text-rose-200">หมายเหตุ :</div>
              <ol id="termsPreview" class="list-decimal space-y-1 bg-amber-50/70 py-3 pl-9 pr-4 sm:pl-8 text-sm leading-relaxed text-slate-800 dark:bg-amber-500/5 dark:text-slate-200"></ol>
            </div>
          </div>

          <!-- เตือนเมื่อจำนวนรายการไม่ตรงกับเลขที่อ้างในหมายเหตุ -->
          <div id="termsWarn" hidden
               class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
            <span id="termsWarnText"></span>
          </div>
        </div>
      </section>
    </div>

    <div id="formError" hidden
         class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
      <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
      <span id="formErrorText"></span>
    </div>
  </div>

  <!-- ===== แถบบันทึก — ล่างสุดของกรอบเสมอ (flex-none ไม่ยืดไม่หด) ===== -->
  <div id="kitBar" hidden
       class="mt-4 flex-none rounded-2xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
    <div class="kit-bar flex w-full flex-wrap items-center gap-x-5 gap-y-2 text-sm">
      <span id="dirtyNote" hidden class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-300">
        <i data-lucide="circle-dot" class="h-4 w-4" aria-hidden="true"></i>
        มีการแก้ไขที่ยังไม่ได้บันทึก
      </span>
      <span class="ml-auto flex items-center gap-2">
        <button type="button" id="btnUndo" class="<?php echo $btn_soft; ?>" disabled>
          <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
          <span class="hidden sm:inline">ยกเลิกการแก้ไข</span>
        </button>
        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="save" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span>บันทึก</span>
        </button>
      </span>
    </div>
  </div>
</div>

<script>
window.NEWSTAFFKIT = {
    csrfToken: <?php echo app_json($csrf_token); ?>,
    endpoint:  'api/new_staff.php'
};
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/new-staff-kit.js', $ASSET_BASE); ?>"></script>
