<?php
/* ============================================================
 *  pages/new_staff_request.php — เบิกชุดฟอร์ม "พนักงานใหม่"
 *
 *  แทนฟอร์มกระดาษเดิมที่มีรายการ 1-4 ตายตัว + ตารางวัสดุถาวรเพิ่มเติม
 *  เรียงเป็น 3 ขั้นจากบนลงล่าง ตามลำดับที่คนกรอกคิดจริง:
 *      1) พนักงานใหม่คือใคร
 *      2) ชุดพื้นฐาน (ใส่ให้แล้ว แค่ตรวจ) — ตั้งค่าได้ที่หน้า "อุปกรณ์พนักงานใหม่"
 *      3) วัสดุถาวรและมีมูลค่า — ตัวเลือกสองฝั่งแบบเดียวกับหน้าทำรายการเบิก
 *  ท้ายฟอร์มเป็นกรอบหมายเหตุแบบเดียวกับฟอร์มกระดาษ แถบสรุป+ปุ่มส่งตรึงล่างจอ
 *
 *  บันทึกแล้วเป็นใบเบิกธรรมดา (doc_type='request') ไปต่อที่หน้ารายการใบเบิก
 *  ข้อมูลทั้งหมดผ่าน api/new_staff.php — ไฟล์นี้ไม่มี SQL
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$is_manager = in_array($sess_role, array('super_admin', 'admin'));

$card     = 'rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$label    = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$req      = '<span class="text-red-500" aria-hidden="true">*</span>';

/* หัวการ์ดแต่ละขั้น: เลขขั้น + ชื่อ + คำอธิบาย (+ ของเสริมด้านขวา) */
function ns_step_head($no, $title, $hint, $icon, $right = '')
{
    $e = 'htmlspecialchars';
    return '<div class="flex flex-wrap items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">'
         . '<span class="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">'
         . '<i data-lucide="' . $e($icon, ENT_QUOTES, 'UTF-8') . '" class="h-[18px] w-[18px]" aria-hidden="true"></i></span>'
         . '<span class="min-w-0 flex-1">'
         . '<span class="block font-semibold text-slate-800 dark:text-slate-100">'
         . '<span class="mr-1 text-brand-600 dark:text-brand-300">' . (int)$no . '.</span>'
         . $e($title, ENT_QUOTES, 'UTF-8') . '</span>'
         . '<span class="block text-sm text-slate-500 dark:text-slate-400">' . $e($hint, ENT_QUOTES, 'UTF-8') . '</span>'
         . '</span>' . $right . '</div>';
}
?>

<div class="w-full">

  <!-- ===== กำลังโหลด ===== -->
  <div id="nsLoading" data-guide-wait class="<?php echo $card; ?> flex items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดอุปกรณ์พนักงานใหม่และยอดคงเหลือ...
  </div>

  <div id="nsBody" hidden class="space-y-4">

    <form id="nsForm" novalidate class="space-y-4">

      <!-- ============================================================
           ขั้นที่ 1 ข้อมูลพนักงานใหม่
           ============================================================ -->
      <section id="nsSecEmp" class="<?php echo $card; ?>">
        <?php echo ns_step_head(1, 'ข้อมูลพนักงานใหม่', 'พนักงานยังไม่ต้องมีบัญชีในระบบ — คุณเป็นผู้ขอเบิกแทน', 'id-card'); ?>

        <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-12">
          <div class="xl:col-span-3">
            <label for="empName" class="<?php echo $label; ?>">ชื่อ-นามสกุล <?php echo $req; ?></label>
            <input type="text" id="empName" maxlength="150" autocomplete="off"
                   placeholder="เช่น นางสาวสมใจ ใจดี" class="<?php echo $input; ?>">
          </div>
          <div class="xl:col-span-2">
            <label for="empPosition" class="<?php echo $label; ?>">ตำแหน่ง <?php echo $req; ?></label>
            <input type="text" id="empPosition" maxlength="150" autocomplete="off"
                   placeholder="เช่น เจ้าหน้าที่บัญชี" class="<?php echo $input; ?>">
          </div>
          <div class="sm:col-span-2 xl:col-span-3">
            <label for="departmentId" class="<?php echo $label; ?>">หน่วยงาน/ฝ่าย <?php echo $req; ?></label>
            <select id="departmentId" class="<?php echo $input; ?>"
                    data-search data-search-placeholder="พิมพ์ชื่อหน่วยงานหรือบริษัท">
              <option value="">— เลือกหน่วยงาน —</option>
            </select>
          </div>
          <div class="xl:col-span-2">
            <label for="startDate" class="<?php echo $label; ?>">วันที่เริ่มงาน <?php echo $req; ?></label>
            <input type="date" id="startDate" class="<?php echo $input; ?>">
          </div>
          <div class="xl:col-span-2">
            <label for="empCode" class="<?php echo $label; ?>">รหัสพนักงาน <span class="font-normal text-slate-400">(ถ้ามี)</span></label>
            <input type="text" id="empCode" maxlength="30" autocomplete="off"
                   placeholder="ยังไม่มีเว้นไว้ได้" class="<?php echo $input; ?>">
          </div>
        </div>

        <div id="deptLocked" hidden
             class="mx-5 mb-5 -mt-1 flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
          <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
          <span id="deptLockedText"></span>
        </div>
      </section>

      <!-- ชุดฟอร์มตามเงื่อนไขสวัสดิการของหน่วยงานที่เลือก — แสดงอย่างเดียว ไม่ใส่ในใบนี้
           พนักงานได้ชุดฟอร์มผ่านใบ NRQ ที่ระบบสวัสดิการส่งมา (assets/js/welfare-uniform-panel.js) -->
      <section id="nsUniform" class="<?php echo $card; ?>" aria-live="polite"></section>

      <!-- ============================================================
           ขั้นที่ 2 อุปกรณ์พื้นฐาน (ไม่รวมชุดฟอร์ม)
           ============================================================ -->
      <section id="nsSecKit" class="<?php echo $card; ?>">
        <?php
        $kit_link = $is_manager
            ? '<a href="main.php?page=new_staff_kit" id="nsKitLink" class="inline-flex flex-none items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">'
              . '<i data-lucide="settings-2" class="h-4 w-4" aria-hidden="true"></i>ตั้งค่าชุดของ</a>'
            : '';
        echo ns_step_head(2, 'อุปกรณ์พื้นฐาน', 'ใส่ให้ครบแล้วตามมาตรฐาน (ไม่รวมชุดฟอร์ม) ปรับจำนวนได้ หรือเอาเครื่องหมายถูกออกถ้าไม่ต้องการ', 'package', $kit_link);
        ?>

        <div id="kitList" class="divide-y divide-slate-100 dark:divide-slate-800"></div>

        <div id="kitEmpty" hidden class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
          ยังไม่ได้ตั้งชุดของพื้นฐาน<?php echo $is_manager ? ' — ตั้งได้ที่ปุ่ม "ตั้งค่าชุดของ" ด้านบน' : ' — แจ้งผู้ดูแลระบบให้ตั้งค่า'; ?>
          ระหว่างนี้เลือกของในขั้นที่ 3 ได้ตามปกติ
        </div>
      </section>

      <!-- ============================================================
           ขั้นที่ 3 วัสดุถาวรและมีมูลค่า — ตัวเลือกสองฝั่งแบบหน้าทำรายการเบิก
           ใช้ doc-transfer.js ตัวเดียวกัน (id ของแต่ละช่องต้องตรงกับที่มันหา)
           ของในชุดพื้นฐานถูกตัดออกจากฝั่งซ้าย จะได้ไม่เลือกซ้ำกับขั้นที่ 2
           ============================================================ -->
      <section id="nsSecExtra" class="<?php echo $card; ?> ns-transfer">
        <?php echo ns_step_head(3, 'รายการเพิ่มเติม', 'ของที่ตำแหน่งนี้ต้องใช้เพิ่ม เช่น เสื้อช่าง รองเท้า Safety — ทุกรายการในขั้นนี้ต้องคืนเมื่อพ้นสภาพ ไม่มีก็ข้ามได้', 'package-plus'); ?>

        <div class="tf-wrap">

          <div class="tf-tabs" role="tablist" aria-label="สลับฝั่งที่แสดง">
            <button type="button" class="tf-tab is-active" data-pane="src"
                    role="tab" aria-selected="true" aria-controls="tfPaneSrc">
              <span class="tf-tab-text">สินค้าทั้งหมด</span>
              <span class="tf-tab-num" id="tfTabSrc">0</span>
            </button>
            <button type="button" class="tf-tab" data-pane="dst"
                    role="tab" aria-selected="false" aria-controls="tfPaneDst" tabindex="-1">
              <span class="tf-tab-text">รายการที่จะเบิกเพิ่ม</span>
              <span class="tf-tab-num is-on" id="tfTabDst">0</span>
            </button>
          </div>

          <section id="tfPaneSrc" class="tf-pane tf-pane-src" aria-labelledby="tfLeftHead">
            <div class="tf-pane-head">
              <h3 id="tfLeftHead" class="tf-pane-title">
                สินค้าทั้งหมด
                <span class="tf-badge"><span id="tfLeftCount">0</span> รายการ</span>
              </h3>
            </div>

            <div class="tf-filters">
              <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
                  <i data-lucide="search" class="h-4 w-4"></i>
                </span>
                <input type="search" id="tfSearch" autocomplete="off"
                       placeholder="ค้นรหัส ชื่อสินค้า หรือหมวดหมู่"
                       class="<?php echo $input; ?> pl-10">
              </div>

              <div class="tf-filters-row tf-filters-row3">
                <select id="tfCategory" class="<?php echo $input; ?>"
                        data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่"
                        aria-label="กรองตามหมวดหมู่หลัก">
                  <option value="">ทุกหมวดหมู่</option>
                </select>
                <select id="tfSubCategory" class="<?php echo $input; ?>"
                        data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่ย่อย"
                        aria-label="กรองตามหมวดหมู่ย่อย">
                  <option value="">ทุกหมวดหมู่ย่อย</option>
                </select>
                <label class="tf-check">
                  <input type="checkbox" id="tfInStock" class="grid-check-native">
                  <span>เฉพาะที่มีของ</span>
                </label>
              </div>
            </div>

            <div id="tfLeft" class="tf-list" role="list"></div>
          </section>

          <section id="tfPaneDst" class="tf-pane tf-pane-dst" aria-labelledby="tfRightHead">
            <div class="tf-pane-head">
              <h3 id="tfRightHead" class="tf-pane-title">
                รายการที่จะเบิกเพิ่ม
                <span class="tf-badge is-on"><span id="tfCount">0</span> รายการ</span>
              </h3>
              <button type="button" id="tfClear" class="tf-clear">ล้างทั้งหมด</button>
            </div>

            <div id="tfRight" class="tf-list tf-list-picked"></div>
          </section>
        </div>
      </section>

      <!-- ============================================================
           หมายเหตุถึงผู้อนุมัติ
           ============================================================ -->
      <section id="nsSecNote" class="<?php echo $card; ?> p-5">
        <label for="docNote" class="<?php echo $label; ?>">หมายเหตุถึงผู้อนุมัติ <span class="font-normal text-slate-400">(ไม่บังคับ)</span></label>
        <input type="text" id="docNote" maxlength="500" placeholder="ระบุเพิ่มเติมได้ เช่น เหตุผลที่ต้องใช้ของในขั้นที่ 3"
               class="<?php echo $input; ?>">
        <input type="hidden" id="docDate">
      </section>

      <!-- ============================================================
           กรอบหมายเหตุ — หน้าตาเดียวกับท้ายฟอร์มกระดาษเดิม
           ซ้าย = ป้าย "หมายเหตุ :"  ขวา = ข้อความทีละข้อ (แก้ได้ที่หน้าอุปกรณ์พนักงานใหม่)
           ใบพิมพ์มีกรอบเดียวกันนี้ (print_doc.php) พนักงานจึงเห็นเงื่อนไขเดียวกันทั้งบนจอและบนกระดาษ
           ============================================================ -->
      <section id="termsBox" hidden
               class="ns-terms overflow-hidden rounded-2xl border border-rose-200 dark:border-rose-500/30">
        <div class="grid sm:grid-cols-[9rem_1fr]">
          <div class="flex items-start gap-2 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-900 sm:justify-end dark:bg-rose-500/10 dark:text-rose-200">
            <i data-lucide="scroll-text" class="mt-0.5 h-4 w-4 sm:hidden" aria-hidden="true"></i>
            หมายเหตุ :
          </div>
          <ol id="termsList"
              class="list-decimal space-y-1 bg-amber-50/70 py-4 pl-10 pr-5 text-sm leading-relaxed text-slate-800 dark:bg-amber-500/5 dark:text-slate-200"></ol>
        </div>
      </section>

      <div id="formError" hidden
           class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="formErrorText"></span>
      </div>
    </form>
  </div>

  <!-- ===== แถบสรุป + ปุ่มส่ง ตรึงล่างจอ =====
       -mx/-mb หักล้าง padding ของกรอบเนื้อหา (#pageScroll) ให้แถบชิดขอบจอ -->
  <div id="nsBar" hidden
       class="sticky bottom-0 z-10 -mx-4 -mb-6 mt-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:-mx-8 lg:px-8 dark:border-slate-800 dark:bg-slate-900/95">
    <div class="flex w-full flex-wrap items-center gap-x-5 gap-y-2 text-sm">
      <span class="text-slate-500 dark:text-slate-400">รายการ <b id="sumLines" class="num text-slate-800 dark:text-slate-100">0</b></span>
      <span class="text-slate-500 dark:text-slate-400">จำนวนรวม <b id="sumQty" class="num text-slate-800 dark:text-slate-100">0</b></span>
      <span class="text-slate-500 dark:text-slate-400">ต้องคืน <b id="sumReturn" class="num text-amber-600 dark:text-amber-300">0</b> รายการ</span>
      <span class="ml-auto flex items-center gap-2">
        <button type="button" id="btnReset" class="<?php echo $btn_soft; ?>">
          <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
          <span class="hidden sm:inline">ล้างฟอร์ม</span>
        </button>
        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="send" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span>ส่งใบเบิก</span>
        </button>
      </span>
    </div>
  </div>
</div>

<script>
window.NEWSTAFF = {
    csrfToken: <?php echo app_json($csrf_token); ?>,
    endpoint:  'api/new_staff.php'
};
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/doc-transfer.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/tf-tabs.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/welfare-uniform-panel.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/new-staff.js', $ASSET_BASE); ?>"></script>
