<?php
/* ============================================================
 *  pages/_doc_list.php — โครงหน้าประวัติเอกสาร ใช้ร่วมกัน 3 หน้า
 *      receive_history.php / request_list.php / issue_history.php
 *
 *  หน้าที่เรียกต้องตั้งตัวแปรเหล่านี้มาก่อน include:
 *      $doc_type    receive | request | issue
 *      $page_title  หัวข้อของหน้า
 *      $page_hint   คำอธิบายใต้หัวข้อ
 *      $export_name ชื่อไฟล์ Excel (ไม่ต้องใส่นามสกุลและวันที่)
 *
 *  ตัวกรองทั้งหมดอยู่ในแถบด้านขวา (ดู _filter_side.php)
 *  แถบเครื่องมือด้านบนเหลือเฉพาะ "สิ่งที่ทำกับข้อมูลที่เห็นอยู่" คือ
 *  รีเฟรชกับส่งออก Excel และปุ่มเปิดตัวกรองสำหรับจอเล็ก
 *  เหตุผล: ตัวกรองของหน้านี้มี 5 ช่อง ถ้าวางเรียงบนแถบบนจะดันตารางลงไป
 *  จนเหลือพื้นที่อ่านน้อย และบนจอแคบจะพับเป็นหลายบรรทัดจนหาปุ่มไม่เจอ
 *
 *  ทุกอย่างที่ทำกับเอกสารได้ (อนุมัติ เซ็นรับของ ยกเลิก) อยู่ในกล่อง
 *  รายละเอียดที่เปิดจากปุ่มในตาราง ไม่กระจายเป็นปุ่มในแถว
 *  เพราะทุกคำสั่งเป็นเรื่องที่ต้องอ่านรายการสินค้าก่อนตัดสินใจ
 *
 *  ไฟล์นี้ไม่มี SQL — ข้อมูลทั้งหมดดึงผ่าน api/documents.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/document.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

function dl_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';

/* ผู้ใช้ทั่วไปเห็นได้เฉพาะใบของตัวเองอยู่แล้ว จึงไม่ต้องมีช่อง "เฉพาะของฉัน"
   ให้สับสน (api/documents.php บังคับเงื่อนไขนี้ ไม่ได้เชื่อค่าจากหน้าจอ) */
$can_see_all = in_array($sess_role, array('super_admin', 'admin', 'approver'));

$all_status = doc_statuses();

/* แสดงเฉพาะสถานะที่เอกสารประเภทนี้เป็นไปได้จริง
   ไม่งั้นจะมีตัวเลือกที่เลือกแล้วได้ 0 แถวเสมอ */
$status_of = array(
    'receive' => array('received', 'cancelled'),
    'request' => array('waiting', 'approved', 'rejected', 'issued', 'cancelled'),
    'issue'   => array('issued', 'received', 'cancelled')
);

$filter_hint = 'กรองจากเอกสารทั้งหมดของหน้านี้';

include dirname(__FILE__) . '/_filter_head.php';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ (เหลือเฉพาะปุ่มทำงาน) ===== -->
  <div class="fp-bar">
    <span class="fp-bar-info">
      พบ <b id="rowCount" class="num">0</b> รายการ
    </span>

    <div class="fp-bar-actions">
      <button type="button" id="btnReload" class="<?php echo $btn_soft; ?> btn-compact" title="โหลดข้อมูลใหม่">
        <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">รีเฟรช</span>
      </button>

      <button type="button" id="btnExport" class="<?php echo $btn_soft; ?> btn-compact"
              title="ส่งออกรายการเอกสาร หนึ่งแถวต่อหนึ่งใบ">
        <i data-lucide="file-spreadsheet" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออก Excel</span>
      </button>

      <!-- ---------- ส่งออกแบบรายบรรทัด ----------
           ปุ่มบนส่งออก "หนึ่งแถวต่อหนึ่งใบ" ซึ่งตอบคำถามเรื่องราคาไม่ได้
           เพราะราคาต่อหน่วยอยู่ที่บรรทัด ใบเดียวมีหลายราคาได้

           ปุ่มนี้ให้ไฟล์ที่แตกเป็นรายบรรทัด จึงตอบได้ว่า
           "เดือนนี้รับผ้าพันแผลเข้ามา 2 ครั้ง ครั้งแรก 5 ม้วน ม้วนละ 15
            ครั้งที่สอง 3 ม้วน ม้วนละ 10" ซึ่งไฟล์แบบแรกกลืนรวมกันหมด -->
      <button type="button" id="btnExportLines" class="<?php echo $btn_soft; ?> btn-compact"
              title="ส่งออกแยกรายบรรทัด เห็นราคาต่อหน่วยของแต่ละครั้งที่รับเข้า">
        <i data-lucide="table-2" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออกรายบรรทัด</span>
      </button>

      <!-- ปุ่มนี้โผล่เฉพาะจอที่แถบตัวกรองถูกซ่อน -->
      <button type="button" id="fpToggle" class="<?php echo $btn_soft; ?> btn-compact fp-toggle">
        <i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">ตัวกรอง</span>
        <span id="fpCount" class="fp-count num" hidden>0</span>
      </button>
    </div>
  </div>

  <div id="listLoading" class="flex flex-1 items-center justify-center gap-3 p-10 text-sm text-slate-500 dark:text-slate-400">
    <i data-lucide="loader-circle" class="h-5 w-5 animate-spin" aria-hidden="true"></i>
    กำลังโหลดเอกสาร...
  </div>

  <div id="listBody" hidden class="flex min-h-0 flex-1 flex-col">
    <div id="grid" class="min-h-0 flex-1"></div>
  </div>

  <div class="grid-hints">
    <span><i data-lucide="eye" class="h-3.5 w-3.5" aria-hidden="true"></i> กด "ดูรายละเอียด" เพื่อดูรายการสินค้าและทำงานกับเอกสาร</span>
    <span><i data-lucide="circle-dot" class="h-3.5 w-3.5" aria-hidden="true"></i> จุดสีข้างปุ่ม = ใบนั้นมีงานรอคุณอยู่</span>
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
                 placeholder="เลขที่ หน่วยงาน ผู้ขอ หมายเหตุ"
                 class="<?php echo $fp_input; ?> pl-9">
        </div>
      </div>

      <div class="fp-field">
        <label for="filterStatus" class="<?php echo $fp_label; ?>">สถานะเอกสาร</label>
        <select id="filterStatus" class="<?php echo $fp_input; ?>"
                data-search data-search-placeholder="พิมพ์ชื่อสถานะ">
          <option value="">ทุกสถานะ</option>
          <?php foreach ($status_of[$doc_type] as $key) { ?>
          <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($all_status[$key], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php } ?>
        </select>
      </div>

      <div class="fp-field">
        <span class="<?php echo $fp_label; ?>">ช่วงวันที่ของเอกสาร</span>
        <div class="fp-range">
          <input type="date" id="dateFrom" class="<?php echo $fp_input; ?>" aria-label="ตั้งแต่วันที่">
          <span class="fp-range-sep" aria-hidden="true">ถึง</span>
          <input type="date" id="dateTo" class="<?php echo $fp_input; ?>" aria-label="ถึงวันที่">
        </div>
      </div>

      <?php if ($can_see_all && $doc_type !== 'receive') { ?>
      <div class="fp-field">
        <label class="fp-check">
          <input type="checkbox" id="onlyMine" class="grid-check-native">
          <span>เฉพาะเอกสารของฉัน</span>
        </label>
      </div>
      <?php } ?>

<?php include dirname(__FILE__) . '/_filter_foot.php'; ?>


<!-- ===== กล่องรายละเอียดเอกสาร ===== -->
<div id="docPanel" hidden class="doc-overlay">
  <div class="doc-sheet" role="dialog" aria-modal="true" aria-labelledby="docTitle">

    <div class="doc-sheet-head">
      <div class="min-w-0">
        <h2 id="docTitle" class="truncate text-base font-semibold text-slate-800 dark:text-slate-100"></h2>
        <span id="docStatus" class="mt-1 block"></span>
      </div>
      <button type="button" id="btnCloseDoc" class="doc-close" aria-label="ปิด">
        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
      </button>
    </div>

    <div class="doc-sheet-body">
      <dl id="docMeta" class="doc-meta"></dl>

      <div class="doc-table-wrap">
        <table id="docItems" class="doc-table"></table>
      </div>

      <div class="doc-sum">
        จำนวนรวม <b id="docTotal" class="num">0</b>
      </div>
    </div>

    <div id="docActions" hidden class="doc-sheet-foot"></div>
  </div>
</div>


<script>
window.DOCLIST = {
    docType:    <?php echo dl_json($doc_type); ?>,
    csrfToken:  <?php echo dl_json($csrf_token); ?>,
    endpoint:   'api/documents.php',
    me:         <?php echo dl_json($sess_username); ?>,
    role:       <?php echo dl_json($sess_role); ?>,
    exportName: <?php echo dl_json($export_name); ?>,
    /* ใช้กับแถวตัวอย่างของคู่มือ ให้วันที่ตรงกับเซิร์ฟเวอร์ ไม่ใช่นาฬิกาเครื่องผู้ใช้ */
    today:      <?php echo dl_json(date('Y-m-d')); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<!-- ผืนเซ็นชื่อ ใช้ตอนกด "เซ็นรับของ" ในกล่องรายละเอียดใบจ่าย
     ต้องมาก่อน doc-list.js เพราะที่นั่นเรียก window.SignPad -->
<script src="<?php echo asset_url('assets/js/signpad.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/doc-list.js', $ASSET_BASE); ?>"></script>
