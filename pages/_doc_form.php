<?php
/* ============================================================
 *  pages/_doc_form.php — โครงหน้าทำเอกสาร ใช้ร่วมกัน 3 หน้า
 *      receive_new.php / request_new.php / issue_new.php
 *
 *  หน้าที่เรียกต้องตั้งตัวแปรเหล่านี้มาก่อน include:
 *      $doc_type      receive | request | issue
 *      $page_title    หัวข้อของหน้า
 *      $page_hint     คำอธิบายใต้หัวข้อ
 *      $confirm_title / $confirm_text   ข้อความในกล่องยืนยันตอนกดบันทึก
 *                                       ({n} จะถูกแทนด้วยจำนวนรายการ)
 *
 *  ช่องในส่วนหัวจะโผล่ตามประเภทเอกสารเอง:
 *      หน่วยงาน   เฉพาะใบเบิก
 *      เลือกใบเบิก + ชื่อผู้รับ   เฉพาะใบจ่าย
 *  JavaScript ดูจาก id ของช่องที่มีอยู่จริง (ดู has() ใน doc-form.js)
 *  จึงไม่ต้องส่งค่าบอกซ้ำอีกชั้น
 *
 *  ไฟล์นี้ไม่มี SQL — ข้อมูลทั้งหมดดึงผ่าน api/documents.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* โหมดแก้ไข: เปิดหน้านี้ด้วย ?doc_id= เพื่อแก้ใบเบิกที่ยัง "รออนุมัติ"
   มีเฉพาะใบเบิก — ใบรับเข้า/จ่ายออก แก้ย้อนหลังไม่ได้อยู่แล้ว
   สิทธิ์/สถานะที่แก้ได้จริง ตรวจซ้ำในชั้น API (action bootstrap + update) */
$edit_doc_id = ($doc_type === 'request' && isset($_GET['doc_id']))
               ? (int)$_GET['doc_id'] : 0;

$df_confirm_title = $confirm_title;
$df_confirm_text  = $confirm_text;
if ($edit_doc_id > 0) {
    $df_confirm_title = 'ยืนยันการแก้ไขใบเบิก';
    $df_confirm_text  = 'บันทึกการแก้ไข {n} รายการ แล้วส่งให้ผู้อนุมัติพิจารณาใหม่?';
}

function df_json($value)
{
    /* PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง กัน </script> ในข้อมูลปิด tag */
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$label    = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== กำลังโหลดข้อมูลตั้งต้น ===== -->
  <div id="formLoading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดข้อมูลสินค้าและยอดคงเหลือ...
  </div>

  <!-- is-transfer บอก CSS ว่าหน้านี้ใช้ตัวเลือกสองฝั่ง ซึ่งบนจอเล็กต้องเลื่อนทั้งก้อนได้
       ต่างจากหน้าที่ใช้ตาราง ที่ตารางคุมการเลื่อนของตัวเองอยู่แล้ว -->
  <div id="formBody" hidden
       class="flex min-h-0 flex-1 flex-col<?php echo ($doc_type === 'request') ? ' is-transfer' : ''; ?>">

    <!-- ===== ส่วนหัวเอกสาร ===== -->
    <form id="docForm" class="flex-none border-b border-slate-200 p-4 dark:border-slate-800">

      <!-- <div class="mb-4 flex items-start gap-3">
        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300">
          <i data-lucide="<?php echo ($doc_type === 'receive') ? 'package-plus' : (($doc_type === 'request') ? 'clipboard-list' : 'package-minus'); ?>"
             class="h-5 w-5" aria-hidden="true"></i>
        </span>
        <span class="min-w-0">
          <span class="block font-semibold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="block text-sm text-slate-500 dark:text-slate-400"><?php echo htmlspecialchars($page_hint, ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
      </div> -->

      <?php if ($doc_type === 'request') { ?>
      <!-- โผล่เฉพาะตอนเปิดจากปุ่ม "แก้ไขใบเบิก" ในหน้ารายการใบเบิก
           JS เติมเลขที่ใบ + เปิดกล่องนี้หลังโหลดข้อมูลใบเดิมสำเร็จ -->
      <div id="editBanner" hidden
           class="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
        <i data-lucide="pencil" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="editBannerText"></span>
      </div>
      <?php } ?>

      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

        <div>
          <label for="docDate" class="<?php echo $label; ?>">วันที่เอกสาร</label>
          <input type="date" id="docDate" class="<?php echo $input; ?>">
        </div>

        <?php if ($doc_type === 'request') { ?>
        <div class="sm:col-span-1" id="deptBox">
          <label for="departmentId" class="<?php echo $label; ?>">หน่วยงานที่ขอเบิก</label>
          <select id="departmentId" class="<?php echo $input; ?>"
                  data-search data-search-placeholder="พิมพ์ชื่อหน่วยงานหรือบริษัท">
            <option value="">— เลือกหน่วยงาน —</option>
          </select>
        </div>
        <?php } ?>


        <?php if ($doc_type === 'issue') { ?>
        <div class="sm:col-span-2" id="refBox">
          <label for="refDocId" class="<?php echo $label; ?>">ใบเบิกที่จะจ่ายของ</label>
          <select id="refDocId" class="<?php echo $input; ?>"
                  data-search data-search-placeholder="พิมพ์เลขที่ใบเบิก ชื่อบริษัท หรือหน่วยงาน">
            <option value="">— เลือกใบเบิก —</option>
          </select>
        </div>

        <div>
          <label for="receiverName" class="<?php echo $label; ?>">ชื่อผู้รับของ</label>
          <input type="text" id="receiverName" placeholder="ชื่อ-นามสกุลผู้มารับของ" class="<?php echo $input; ?>">
        </div>
        <?php } ?>

        <div class="<?php echo ($doc_type === 'receive') ? 'sm:col-span-2 lg:col-span-3' : 'sm:col-span-2'; ?>">
          <label for="docNote" class="<?php echo $label; ?>">หมายเหตุ</label>
          <input type="text" id="docNote" maxlength="500"
                 placeholder="<?php echo ($doc_type === 'receive') ? 'เช่น ที่มาของของ เลขที่ใบส่งของ' : 'ระบุเพิ่มเติมได้ ไม่บังคับ'; ?>"
                 class="<?php echo $input; ?>">
        </div>
      </div>

      <?php if ($doc_type === 'request') { ?>
      <!-- บอกว่าหน่วยงานมาจากไหน ไม่ให้ผู้ใช้งงว่าทำไมเปลี่ยนไม่ได้ -->
      <div id="deptLocked" hidden
           class="mt-3 flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
        <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="deptLockedText"></span>
      </div>
      <?php } ?>

      <?php if ($doc_type === 'issue') { ?>
      <!-- ข้อมูลของใบเบิกที่เลือก แสดงให้ตรวจก่อนจ่าย -->
      <div id="reqInfo" hidden
           class="mt-3 flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
        <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="reqInfoText"></span>
      </div>

      <div id="noRequest" hidden
           class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span>ยังไม่มีใบเบิกที่อนุมัติแล้วและรอจ่าย — ต้องมีใบเบิกที่ผ่านการอนุมัติก่อนจึงจะจ่ายของได้</span>
      </div>
      <?php } ?>

      <div id="formError" hidden
           class="mt-3 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
        <span id="formErrorText"></span>
      </div>
    </form>

    <!-- ===== แถบเครื่องมือของรายการสินค้า ===== -->
    <div class="grid-toolbar flex flex-none flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
      <span class="text-sm font-medium text-slate-700 dark:text-slate-300">รายการสินค้า</span>

      <!-- ===== ช่องยิงรหัส =====
           รับได้ทั้งบาร์โค้ดและ QR เพราะเครื่องอ่านถอดรหัสเองแล้วส่งมาเป็นการกดปุ่ม
           (ข้อแม้อยู่ที่ตัวเครื่อง: รุ่นเลเซอร์เส้นตรงอ่าน QR ไม่ได้)

           แยกเป็นช่องต่างหาก ไม่ดักการกดปุ่มทั้งหน้า เพราะหน้านี้มีช่องจำนวน
           กับหมายเหตุที่ต้องพิมพ์เอง ถ้าดักทั้งหน้าตัวเลขที่พิมพ์จะหาย

           อ่านจากตำแหน่งปุ่มบนแป้น ไม่ใช่ตัวอักษรที่ Windows แปลงมา
           จึงยิงได้แม้เปิดภาษาไทยค้างไว้ (ดู assets/js/barcode-scan.js) -->
      <div class="doc-scan">
        <span class="doc-scan-icon" aria-hidden="true">
          <i data-lucide="scan-barcode" class="h-4 w-4"></i>
        </span>
        <input type="text" id="scanInput" autocomplete="off" spellcheck="false"
               class="doc-scan-input"
               placeholder="<?php echo ($doc_type === 'issue') ? 'ยิงบาร์โค้ดหรือ QR เพื่อตรวจของที่หยิบ' : 'ยิงบาร์โค้ดหรือ QR เพื่อเพิ่มสินค้า'; ?>"
               aria-label="ช่องยิงบาร์โค้ดหรือ QR">
        <!-- ส่องด้วยกล้องของเครื่อง สำหรับคนที่ไม่มีเครื่องยิงอยู่ในมือ
             เช่นเปิดหน้านี้จากมือถือตอนยืนอยู่ที่ชั้นวาง

             อ่านบาร์โค้ดแท่งได้เฉพาะเครื่องที่มีตัวถอดรหัสในตัว (Android เป็นหลัก)
             เครื่องที่ต้องใช้ตัวสำรองอ่านได้เฉพาะ QR ข้อความในกล่องกล้อง
             จะบอกเองตอนเปิด (ดูเหตุผลใน assets/js/qr-cam.js) -->
        <button type="button" id="btnScanCam" class="doc-scan-cam"
                title="เปิดกล้องเพื่อส่อง QR หรือบาร์โค้ด">
          <i data-lucide="camera" class="h-3.5 w-3.5" aria-hidden="true"></i>
        </button>
      </div>

      <?php /* grid-toolbar-actions ทำให้แถบปุ่มของหน้านี้ใช้กฎเดียวกับหน้าอื่นบนมือถือ
               (ปุ่มรองเหลือแต่ไอคอนเกาะกลุ่มซ้าย ปุ่มบันทึกไปสุดขวา แถวเดียวจบ)
               ไม่ต้องมีกฎเฉพาะของหน้านี้แยกอีกชุด */ ?>
      <div class="grid-toolbar-actions ml-auto flex flex-wrap items-center gap-2">
        <?php if ($doc_type === 'receive') { ?>
        <?php /* ปุ่มเพิ่มรายการยังต้องมีอยู่ แม้จะยิงบาร์โค้ดได้แล้ว
                 เพราะของบางอย่างไม่มีฉลากแปะ และเครื่องอ่านก็เสียได้ */ ?>
        <button type="button" id="btnAddRow" class="<?php echo $btn_soft; ?> btn-compact">
          <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มรายการ</span>
        </button>
        <?php } ?>

        <button type="button" id="btnSave" class="<?php echo $btn_main; ?>" disabled>
          <i data-lucide="save" class="btn-icon h-4 w-4" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
          <span>บันทึกเอกสาร</span>
        </button>
      </div>
    </div>

    <!-- ผลของการยิงล่าสุด — ต้องเห็นชัดโดยไม่ต้องเพ่ง เพราะคนยิงมองของอยู่ ไม่ได้มองจอ
         มีเสียงตอบรับคู่กันด้วย เสียงสูงสั้น = เจอ  เสียงต่ำยาว = ไม่เจอ -->
    <div id="scanMsg" hidden class="doc-scan-msg"></div>

    <!-- ===== กล่องกล้อง =====
         ลอยทับทั้งหน้าต่าง เพราะตอนส่องต้องเห็นภาพให้ใหญ่ที่สุด
         ย้ายไปเป็นลูกของ body ตอนเริ่มทำงาน เพราะ #appMain ตั้ง overflow: clip ไว้ -->
    <div id="scanCam" hidden class="scan-cam">
      <div class="scan-cam-box" role="dialog" aria-modal="true" aria-label="ส่อง QR หรือบาร์โค้ด ด้วยกล้อง">

        <div class="scan-cam-head">
          <span class="scan-cam-title">
            <i data-lucide="camera" class="h-4 w-4" aria-hidden="true"></i>
            ส่องรหัสที่ฉลากสินค้า
          </span>
          <button type="button" id="scanCamFlip" class="scan-cam-icon" title="สลับกล้องหน้า/หลัง">
            <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
          </button>
          <button type="button" id="scanCamClose" class="scan-cam-icon" aria-label="ปิดกล้อง">
            <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
          </button>
        </div>

        <div class="scan-cam-view">
          <video id="scanCamVideo" class="scan-cam-video" playsinline muted></video>
          <!-- กรอบเล็งเป็นแค่ตัวช่วยสายตา ตัวถอดรหัสอ่านทั้งเฟรม
               เล็งไม่ตรงกรอบก็ยังอ่านได้ -->
          <div class="scan-cam-aim" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </div>

        <div id="scanCamMsg" class="scan-cam-msg">กำลังเปิดกล้อง…</div>
      </div>
    </div>

<?php if ($doc_type === 'request') { ?>

    <!-- ===== ตัวเลือกสินค้าแบบสองฝั่ง =====
         ซ้าย = ทะเบียนสินค้าทั้งหมด  ขวา = ที่เลือกจะเบิก
         คนเบิกไม่ได้จำรหัสสินค้า จึงต้องกวาดสายตาหาจากรายการได้
         บนจอเล็กสองฝั่งจะเรียงซ้อนกันตามลำดับนี้ (สินค้าก่อน แล้วที่เลือก)
         และเลื่อนได้ทั้งก้อน โดยตรึงปุ่มบันทึกไว้ด้านบน

         หน้านี้ไม่มีแถบตัวกรองด้านขวาเหมือนหน้ารายงาน/ประวัติ
         ตัวกรองสินค้าอยู่ในหัวฝั่งซ้ายซึ่งเป็นฝั่งที่มันกรองโดยตรง -->
    <div class="tf-wrap">

      <!-- ===== แท็บสลับสองฝั่ง — โผล่เฉพาะจอเล็ก (ดู .tf-tabs ใน app.css) =====
           บนมือถือสองฝั่งต้องเรียงบนล่าง "รายการที่จะเบิก" จึงไปอยู่ใต้รายการสินค้า
           ทั้งทะเบียน ซึ่งเลื่อนไม่ถึงกันจริง ๆ แท็บทำให้กดถึงได้ทันที
           และเหลือกล่องที่เลื่อนได้ชั้นเดียวแทนที่จะซ้อนกันสามชั้น

           ตัวเลขบนแท็บซ้ำกับป้ายในหัวของแต่ละฝั่งโดยตั้งใจ เพราะหัวฝั่งที่ไม่ได้เลือก
           ถูกซ่อนอยู่ ตัวเลข "เลือกไปกี่รายการแล้ว" จึงต้องอยู่บนแท็บด้วย
           ไม่งั้นแตะเลือกของแล้วมันหายเข้าไปในฝั่งที่มองไม่เห็น -->
      <div class="tf-tabs" role="tablist" aria-label="สลับฝั่งที่แสดง">
        <button type="button" class="tf-tab is-active" data-pane="src"
                role="tab" aria-selected="true" aria-controls="tfPaneSrc">
          <span class="tf-tab-text">สินค้าทั้งหมด</span>
          <span class="tf-tab-num" id="tfTabSrc">0</span>
        </button>
        <button type="button" class="tf-tab" data-pane="dst"
                role="tab" aria-selected="false" aria-controls="tfPaneDst" tabindex="-1">
          <span class="tf-tab-text">รายการที่จะเบิก</span>
          <span class="tf-tab-num is-on" id="tfTabDst">0</span>
        </button>
      </div>

      <!-- ---------- ฝั่งซ้าย ---------- -->
      <section id="tfPaneSrc" class="tf-pane tf-pane-src" aria-labelledby="tfLeftHead">
        <div class="tf-pane-head">
          <h3 id="tfLeftHead" class="tf-pane-title">
            สินค้าทั้งหมด
            <span class="tf-badge"><span id="tfLeftCount">0</span> รายการ</span>
          </h3>
        </div>

        <!-- ตัวกรองอยู่ติดกับรายการที่มันกรอง ไม่แยกไปเป็นแถบด้านขวา
             เพราะหน้านี้มีสองฝั่งอยู่แล้ว ถ้าเพิ่มแถบที่สามจะเหลือที่ให้ชื่อสินค้าน้อย
             และตัวกรองชุดนี้กรองเฉพาะฝั่งซ้าย ไม่ได้กรองทั้งหน้า -->
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

            <!-- หมวดหมู่หลักมีแค่สองอัน กรองแล้วยังเหลือเป็นร้อยรายการ
                 หมวดหมู่ย่อยคือชั้นที่ทำให้เหลือขนาดที่กวาดตาจนจบได้จริง
                 (เช่น "รายการยารักษาโรค" เหลือ 15 รายการ)

                 รายการในช่องนี้ตัดให้เหลือเฉพาะหมวดย่อยของหมวดหลักที่เลือกไว้
                 และสร้างจากสินค้าที่โหลดมาจริง ไม่ใช่จากทะเบียนหมวดหมู่ทั้งชุด
                 หมวดย่อยที่ไม่มีของให้เบิกจึงไม่โผล่มาเป็นทางตัน
                 (ดู fillSubCategories() ใน doc-transfer.js) -->
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

      <!-- ---------- ฝั่งขวา ---------- -->
      <section id="tfPaneDst" class="tf-pane tf-pane-dst" aria-labelledby="tfRightHead">
        <div class="tf-pane-head">
          <h3 id="tfRightHead" class="tf-pane-title">
            รายการที่จะเบิก
            <span class="tf-badge is-on"><span id="tfCount">0</span> รายการ</span>
          </h3>
          <button type="button" id="tfClear" class="tf-clear">ล้างทั้งหมด</button>
        </div>

        <div id="tfRight" class="tf-list tf-list-picked"></div>
      </section>
    </div>

<?php } else { ?>

    <!-- ===== ตารางรายการแบบ Excel (รับเข้า / จ่ายออก) ===== -->
    <div id="items" class="min-h-0 flex-1"></div>

<?php } ?>

    <!-- ===== สรุปท้ายตาราง ===== -->
    <div class="flex flex-none flex-wrap items-center gap-x-6 gap-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
      <span class="text-slate-500 dark:text-slate-400">
        รายการ <b id="sumLines" class="num text-slate-800 dark:text-slate-100">0</b>
      </span>
      <span class="text-slate-500 dark:text-slate-400">
        จำนวนรวม <b id="sumQty" class="num text-slate-800 dark:text-slate-100">0</b>
      </span>
      <?php if ($doc_type === 'receive') { ?>
      <!-- ใบรับเข้ามีเงิน ต้องเห็นยอดรวมก่อนกดบันทึก
           เป็นตัวเลขที่คนคลังเอาไปเทียบกับใบกำกับที่ถืออยู่ในมือ -->
      <span class="text-slate-500 dark:text-slate-400">
        เป็นเงิน <b id="sumMoney" class="num text-slate-800 dark:text-slate-100">0.00</b> บาท
      </span>
      <?php } ?>
      <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">
        <?php echo ($doc_type === 'request')
                   ? 'ใบเบิกยังไม่ตัดสต๊อก จะตัดตอนคลังทำใบจ่าย'
                   : 'บันทึกแล้วสต๊อกจะเปลี่ยนทันที และแก้ไขเอกสารย้อนหลังไม่ได้'; ?>
      </span>
      <!-- บอกวิธีเปิดช่องแก้ไขให้ตรงกับที่ตารางตั้งไว้จริง
           ใบรับเข้าเปิดด้วยคลิกเดียว ส่วนใบจ่ายออกยังเป็นดับเบิลคลิก
           (ดู editTriggerEvent ใน buildTable() ที่ assets/js/doc-form.js)
           ส่วนใบเบิกไม่ใช้ตาราง แต่เป็นตัวเลือกสองฝั่งที่มีช่องจำนวนอยู่ในตัวอยู่แล้ว
           บรรทัดนี้จึงไม่มีอะไรจะบอก -->
      <?php if ($doc_type !== 'request') { ?>
      <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">
        <?php echo ($doc_type === 'receive')
                   ? 'คลิกที่ช่องเพื่อแก้ไขได้ทันที'
                   : 'ดับเบิลคลิกที่รายการเพื่อแก้ไขจำนวนหรือหมายเหตุ'; ?>
      </span>
      <?php } ?>
    </div>
  </div>
</div>


<script>
window.DOCFORM = {
    docType:      <?php echo df_json($doc_type); ?>,
    csrfToken:    <?php echo df_json($csrf_token); ?>,
    endpoint:     'api/documents.php',
    /* 0 = โหมดสร้างใหม่ตามปกติ, >0 = กำลังแก้ใบเบิกใบนี้ (ส่ง action:"update") */
    editDocId:    <?php echo (int)$edit_doc_id; ?>,
    confirmTitle: <?php echo df_json($df_confirm_title); ?>,
    confirmText:  <?php echo df_json($df_confirm_text); ?>,
    /* ใช้กับใบเบิกสมมติของคู่มือ ให้วันที่ตรงกับเซิร์ฟเวอร์ ไม่ใช่นาฬิกาเครื่องผู้ใช้ */
    today:        <?php echo df_json(date('Y-m-d')); ?>
};

/* ที่อยู่ของตัวถอดรหัสสำรอง qr-cam.js จะโหลดเองเมื่อจำเป็น
   (เครื่องที่ไม่มี BarcodeDetector ในตัว เช่น Safari บน iPhone)
   ไม่ใส่เป็น <script> ตายตัว เพราะเครื่องที่มีตัวถอดในตัวไม่ต้องโหลด 337 KB นี้เลย */
window.SCAN_CAM_LIB = '<?php echo asset_url('assets/js/zxing.js', $ASSET_BASE); ?>';
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<?php if ($doc_type === 'request') { ?>
<!-- หน้าเบิกใช้ตัวเลือกสองฝั่ง ไม่ต้องโหลด Tabulator ที่หนัก 430 KB -->
<script src="<?php echo asset_url('assets/js/doc-transfer.js', $ASSET_BASE); ?>"></script>
<!-- แท็บสลับสองฝั่งบนจอเล็ก — ใช้ร่วมกับหน้าสินค้าของหน่วยงาน -->
<script src="<?php echo asset_url('assets/js/tf-tabs.js', $ASSET_BASE); ?>"></script>
<?php } else { ?>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<?php } ?>
<script src="<?php echo asset_url('assets/js/barcode-scan.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/qr-cam.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/doc-form.js', $ASSET_BASE); ?>"></script>
