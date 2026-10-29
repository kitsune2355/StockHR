<?php
/* ============================================================
 *  pages/print_settings.php — Admin mode > เอกสารพิมพ์
 *
 *  ตั้งค่าหน้าตาเอกสารที่พิมพ์ (print_doc.php) โดยไม่ต้องแก้โค้ด:
 *    ชื่อเอกสาร · ป้ายหัวกระดาษ · ชื่อช่องลงนาม · ข้อมูลที่จะพิมพ์ · หมายเหตุท้ายฟอร์ม · แถวว่าง
 *  ซ้าย = ฟอร์ม  ขวา = ใบพิมพ์จริงของเอกสารล่าสุด (iframe print_doc.php?embed=1)
 *  บันทึกแล้วตัวอย่างโหลดใหม่ให้เห็นผลทันที
 *
 *  ค่าเก็บใน tb_app_setting คีย์ print_* (ดู backend/print_setting.php)
 *  เฉพาะ super_admin — ตรวจซ้ำใน api/print_settings.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$label    = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';

function ps_text($key, $title, $hint, $input)
{
    ?>
        <div class="ps-fld">
          <label for="<?php echo $key; ?>" id="lbl_<?php echo $key; ?>" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"><?php echo $title; ?></label>
          <input type="text" id="<?php echo $key; ?>" data-key="<?php echo $key; ?>" class="<?php echo $input; ?>" autocomplete="off">
          <?php if ($hint !== '') { ?><p class="ps-hint"><?php echo $hint; ?></p><?php } ?>
        </div>
    <?php
}

function ps_toggle($key, $title, $hint)
{
    ?>
        <label class="ps-toggle">
          <input type="checkbox" data-key="<?php echo $key; ?>" class="grid-check-native">
          <span><b><?php echo $title; ?></b><small><?php echo $hint; ?></small></span>
        </label>
    <?php
}
?>

<div id="psWrap" class="ps-wrap">

  <!-- ===================== ฟอร์ม ===================== -->
  <div class="ps-form rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div id="psLoading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500">
      <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i> กำลังโหลดค่าตั้ง...
    </div>

    <div id="psBody" hidden class="ps-body">

      <!-- ค่าทุกช่องด้านล่างเป็นของเอกสารที่เลือกอยู่ด้านขวา (ตั้งแยกได้ทั้ง 5 แบบ) -->
      <div class="ps-for">
        <i data-lucide="file-pen-line" class="h-4 w-4" aria-hidden="true"></i>
        <span>กำลังตั้งค่า: <b id="psForName">ใบเบิก</b></span>
        <span class="ps-for-hint">เลือกเอกสารอื่นที่แท็บด้านขวา · กดบันทึกครั้งเดียวได้ทุกเอกสารที่แก้</span>
      </div>

      <section class="ps-sec" id="psSecTitle">
        <h3 class="ps-h"><i data-lucide="heading" class="h-4 w-4" aria-hidden="true"></i> ชื่อเอกสาร</h3>
        <div class="ps-grid ps-grid-1">
          <?php ps_text('title', 'ชื่อเอกสาร', 'ข้อความตัวหนามุมขวาบนของหัวกระดาษ', $input); ?>
        </div>
      </section>

      <section class="ps-sec" id="psSecLabel">
        <h3 class="ps-h"><i data-lucide="tag" class="h-4 w-4" aria-hidden="true"></i> ป้ายหัวกระดาษและหัวตาราง</h3>
        <div class="ps-grid">
          <?php ps_text('label_dept', 'ป้ายหน่วยงาน', 'ใต้ชื่อบริษัท', $input); ?>
          <?php ps_text('label_no',   'ป้ายเลขที่',   'ใต้ชื่อเอกสาร', $input); ?>
          <?php ps_text('label_date', 'ป้ายวันที่',   'ใต้เลขที่', $input); ?>
          <?php ps_text('label_line', 'หัวคอลัมน์ลำดับ', 'คอลัมน์แรกของตาราง', $input); ?>
        </div>
      </section>

      <section class="ps-sec" id="psSecSign">
        <h3 class="ps-h"><i data-lucide="pen-line" class="h-4 w-4" aria-hidden="true"></i> ชื่อช่องลงนาม</h3>
        <p class="ps-sub">เรียงจากซ้ายไปขวาตามบนใบพิมพ์</p>
        <div class="ps-grid">
          <?php ps_text('sign1', 'ช่องที่ 1', '', $input); ?>
          <?php ps_text('sign2', 'ช่องที่ 2', '', $input); ?>
          <?php ps_text('sign3', 'ช่องที่ 3', '', $input); ?>
          <?php ps_text('sign4', 'ช่องที่ 4 (มีลายเซ็นจริง)', '', $input); ?>
        </div>
      </section>

      <section class="ps-sec" id="psSecShow">
        <h3 class="ps-h"><i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i> ข้อมูลที่พิมพ์</h3>
        <div class="ps-toggles">
          <?php ps_toggle('show_ref',          'อ้างอิงใบเบิก', 'แสดงเลขที่ใบเบิกต้นทางในส่วนหัว'); ?>
          <?php ps_toggle('show_req_note',     'หมายเหตุผู้ขอ', 'แสดงหมายเหตุที่ผู้ขอเบิกเขียนไว้'); ?>
          <?php ps_toggle('show_estimate',     'หมายเหตุราคาประมาณการ', '* ราคาต่อหน่วยเป็นราคาซื้อล่าสุด ใช้ประกอบการพิจารณาเท่านั้น'); ?>
        </div>
      </section>

      <section class="ps-sec" id="psSecFont">
        <h3 class="ps-h"><i data-lucide="a-large-small" class="h-4 w-4" aria-hidden="true"></i> ขนาดตัวอักษร</h3>
        <p class="ps-sub">หน่วย px (ขนาดบนกระดาษ A4 เมื่อพิมพ์ที่ 100%) ปรับทีละ 0.5 — ตัวเลขใหญ่ขึ้นตารางจะยาวขึ้นและอาจขึ้นหน้าใหม่</p>
        <div class="ps-fonts">
          <?php
          $ps_fonts = array(
              'fs_company' => array('ชื่อบริษัท', 10, 26),
              'fs_title'   => array('ชื่อเอกสาร', 10, 26),
              'fs_meta'    => array('ข้อมูลหัวเอกสาร (ผู้ขอ สถานะ ผู้อนุมัติ)', 9, 20),
              'fs_table'   => array('ตารางรายการ', 9, 20),
              'fs_notes'   => array('หมายเหตุ (ท้ายฟอร์มและใต้ตาราง)', 9, 20),
              'fs_sign'    => array('ช่องลงนาม', 9, 20),
          );
          foreach ($ps_fonts as $k => $f) { ?>
          <div class="ps-font">
            <label for="<?php echo $k; ?>"><?php echo $f[0]; ?></label>
            <div class="ps-step">
              <button type="button" class="ps-step-btn" data-step="-0.5" data-for="<?php echo $k; ?>" aria-label="ลดขนาด">−</button>
              <input type="number" id="<?php echo $k; ?>" data-key="<?php echo $k; ?>" min="<?php echo $f[1]; ?>" max="<?php echo $f[2]; ?>" step="0.5" inputmode="decimal">
              <button type="button" class="ps-step-btn" data-step="0.5" data-for="<?php echo $k; ?>" aria-label="เพิ่มขนาด">+</button>
              <span class="ps-unit">px</span>
            </div>
          </div>
          <?php } ?>
        </div>
      </section>

      <section class="ps-sec" id="psSecNotes">
        <h3 class="ps-h"><i data-lucide="list-ordered" class="h-4 w-4" aria-hidden="true"></i> หมายเหตุท้ายฟอร์ม</h3>
        <!-- ใบจ่าย: ใช้หมายเหตุชุดเดียวกับเอกสารรับชุดเครื่องแบบ (เปิด = ซ่อนตัวแก้ไขของใบจ่าย) -->
        <div class="ps-toggles" style="margin-bottom:.75rem">
          <?php ps_toggle('notes_same_uniform', 'ใช้หมายเหตุเดียวกับเอกสารรับชุดเครื่องแบบ',
                          'พิมพ์เงื่อนไข เลขข้อ และข้อความรับทราบชุดเดียวกับแท็บ "เอกสารรับชุดเครื่องแบบ" — แก้ที่แท็บนั้นที่เดียว'); ?>
        </div>
        <p id="psNsNote" class="ps-hint" hidden>เอกสารรับชุดเครื่องแบบ (019-HRM): หมายเหตุนี้คือเงื่อนไขที่พนักงานรับทราบ
          ใช้ทั้งบนใบพิมพ์ หน้าเซ็นรับของพนักงาน และใบจ่าย (ถ้าแท็บใบจ่ายเปิด "ใช้หมายเหตุเดียวกับเอกสารรับชุดเครื่องแบบ") — แก้ที่นี่ที่เดียว</p>
        <div class="ps-fld" id="psNotesOwn">
        <p class="ps-sub">จะเป็นข้อ ๆ มีเลข มีจุด หรือข้อความธรรมดาก็ได้ ลบหมด = ไม่พิมพ์กล่องหมายเหตุ</p>
        

        <!-- ตัวแก้ไขข้อความ (contenteditable) — ค่าจริงคือ HTML ในช่องซ่อน notes
             เซิร์ฟเวอร์ทำความสะอาดอีกชั้นก่อนบันทึกและก่อนพิมพ์ (print_clean_html) -->
        <div class="ps-editor">
          <div class="ps-tools" role="toolbar" aria-label="จัดรูปแบบหมายเหตุ">
            <button type="button" class="ps-tool" data-cmd="insertOrderedList" title="ข้อ ๆ มีเลข 1. 2. 3."><i data-lucide="list-ordered" class="h-4 w-4"></i></button>
            <button type="button" class="ps-tool" data-cmd="insertUnorderedList" title="ข้อ ๆ มีจุด"><i data-lucide="list" class="h-4 w-4"></i></button>
            <button type="button" class="ps-tool" data-cmd="plain" title="ข้อความธรรมดา (เอาเลข/จุดออก)"><i data-lucide="pilcrow" class="h-4 w-4"></i></button>
            <span class="ps-tool-sep"></span>
            <button type="button" class="ps-tool" data-cmd="bold" title="ตัวหนา (Ctrl+B)"><i data-lucide="bold" class="h-4 w-4"></i></button>
            <button type="button" class="ps-tool" data-cmd="italic" title="ตัวเอียง (Ctrl+I)"><i data-lucide="italic" class="h-4 w-4"></i></button>
            <button type="button" class="ps-tool" data-cmd="underline" title="ขีดเส้นใต้ (Ctrl+U)"><i data-lucide="underline" class="h-4 w-4"></i></button>
            <span class="ps-tool-sep"></span>
            <button type="button" class="ps-tool" data-cmd="removeFormat" title="ล้างตัวหนา/เอียง/ขีดเส้นใต้"><i data-lucide="remove-formatting" class="h-4 w-4"></i></button>
          </div>
          <div id="psNotesEditor" class="ps-edit" contenteditable="true" role="textbox" aria-multiline="true" aria-label="หมายเหตุท้ายฟอร์ม"></div>
        </div>
        <textarea id="notes" data-key="notes" hidden></textarea>
        <p class="ps-hint">กด Enter = ข้อใหม่ / บรรทัดใหม่ · วางข้อความจากที่อื่นจะเหลือแต่ตัวอักษร (ไม่ติดสี/ฟอนต์มา)</p>
        </div>

        <div class="ps-row ps-fld" id="psNotesStartRow">
          <label for="notes_start" class="<?php echo $label; ?> mb-0">เริ่มเลขข้อที่ (รายการเลขข้อชุดแรก)</label>
          <input type="number" id="notes_start" data-key="notes_start" min="1" max="50" class="<?php echo $input; ?> ps-num">
        </div>
        <div class="ps-fld" style="margin-top:1rem">
          <?php ps_text('ack', 'ข้อความรับทราบใต้หมายเหตุ', 'พิมพ์ตัวหนาใต้กล่องหมายเหตุ เหนือช่องลงนาม', $input); ?>
        </div>

        <div class="ps-row ps-fld">
          <label for="blank_rows" class="<?php echo $label; ?> mb-0">แถวว่างในตาราง (เผื่อเขียนเพิ่มด้วยปากกา)</label>
          <input type="number" id="blank_rows" data-key="blank_rows" min="0" max="20" class="<?php echo $input; ?> ps-num">
        </div>
      </section>
    </div>

    <div id="psBar" class="ps-bar">
      <span id="psDirty" class="ps-dirty" hidden><i data-lucide="circle-dot" class="h-3.5 w-3.5" aria-hidden="true"></i> <span id="psDirtyText">มีการแก้ไขที่ยังไม่บันทึก</span></span>
      <button type="button" id="psReset" class="<?php echo $btn_soft; ?>" title="ใส่ค่าตั้งต้นของเอกสารที่เลือกอยู่ลงในฟอร์ม (ยังไม่บันทึกจนกว่าจะกดบันทึก)">
        <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i> ค่าตั้งต้น
      </button>
      <button type="button" id="psSave" class="<?php echo $btn_main; ?>" disabled>
        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i> บันทึก
      </button>
    </div>
  </div>

  <!-- ===================== ตัวอย่าง ===================== -->
  <div class="ps-preview rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="ps-pv-head">
      <div id="psTypes" class="ps-types" role="tablist" aria-label="ประเภทเอกสารตัวอย่าง"></div>
      <a id="psOpen" href="#" target="_blank" rel="noopener" class="ps-open" title="เปิดใบพิมพ์ในแท็บใหม่">
        <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>
      </a>
    </div>
    <p class="ps-pv-note">ตัวอย่างจากเอกสารล่าสุดของจริง — เปลี่ยนตามที่กำลังแก้ทันที <span id="psPvState"></span> (ใบจริงจะเปลี่ยนเมื่อกดบันทึก)</p>
    <div id="psFrameWrap" class="ps-frame-wrap">
      <!-- สองเฟรมสลับกัน: วาดใบใหม่ในเฟรมที่ซ่อนอยู่ เสร็จแล้วค่อยสลับ — ไม่กระพริบขาวทุกครั้งที่พิมพ์ -->
      <iframe id="psFrame" name="psFrameA" title="ตัวอย่างเอกสารพิมพ์" class="ps-frame"></iframe>
      <iframe id="psFrameB" name="psFrameB" title="ตัวอย่างเอกสารพิมพ์" class="ps-frame" style="visibility:hidden"></iframe>
      <div id="psNoDoc" hidden class="ps-nodoc">ยังไม่มีเอกสารประเภทนี้ในระบบ</div>
    </div>
  </div>
</div>

<script>
window.PRINTSET = {
    endpoint:  'api/print_settings.php',
    csrfToken: <?php echo json_encode($csrf_token); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/print-settings.js', $ASSET_BASE); ?>"></script>
