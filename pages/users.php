<?php
/* ============================================================
 *  pages/users.php — จัดการผู้ใช้งาน
 *
 *  ตารางแบบแก้ในช่องได้ทันที (Tabulator) รูปแบบเดียวกับหน้าหน่วยงาน
 *    - พิมพ์ในช่องค้นหา -> กรองทันที (ชื่อผู้ใช้ / ชื่อเต็ม / อีเมล)
 *    - คลิกที่ช่องแล้วแก้ได้เลย  สถานะกดสวิตช์
 *    - เพิ่มได้ทีละหลายแถว
 *    - แก้สะสมไว้ก่อน แล้วกดบันทึกส่งทั้งชุดครั้งเดียว
 *
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL
 *    ข้อมูลตั้งต้น : ดึงผ่าน backend/user.php แล้วฝังเป็น JSON
 *    ตอนบันทึก    : ยิงไปที่ api/users.php ซึ่งเรียก backend ตัวเดียวกัน
 *
 *  เรื่องรหัสผ่าน:
 *    - คนใหม่ ต้องกำหนดรหัสผ่านตั้งต้น
 *    - คนเดิม เว้นว่างไว้ = ใช้รหัสเดิมต่อ พิมพ์ลงไป = ตั้งรหัสใหม่ให้
 *    - รหัสผ่านของเดิมไม่เคยถูกส่งออกมาที่หน้าเว็บเลย แม้จะเข้ารหัสแล้วก็ตาม
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/user.php';
require_once dirname(dirname(__FILE__)) . '/backend/company.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- ข้อมูลตั้งต้น ---------- */
$users           = user_list();
$company_options = company_options();
$roles           = user_roles();

/* หน่วยงานต้นสังกัดของผู้ใช้ — เป็นตัวกำหนดขอบเขตการอนุมัติใบเบิก
   แสดงชื่อบริษัทต่อท้ายด้วย เพราะหลายบริษัทมีหน่วยงานชื่อซ้ำกัน (บัญชี จัดซื้อ ฯลฯ) */
$dept_options = array();
$departments  = department_list(array('is_active' => 1));
for ($i = 0; $i < count($departments); $i++) {
    /* ป้ายมาจาก department_add_labels() ที่เดียวกับทุกช่องเลือกในระบบ
       ซึ่งเติมเลขหน่วยงานให้ด้วยเฉพาะคู่ที่ชื่อกับบริษัทซ้ำกันทั้งคู่
       (กรรมการ/เลขา, ฝ่ายบริหารกิจกรรม, รักษาความปลอดภัย มีอย่างละสองอันในบริษัทเดียวกัน) */
    $dept_options[(int)$departments[$i]['department_id']] = $departments[$i]['label'];
}

/* ตัวเลือกที่กดได้จริงมี 3 อย่าง : ผู้ดูแลระบบ / ผู้อนุมัติ / ผู้ใช้งานทั่วไป
   ส่วน "ผู้ดูแลระบบสูงสุด" สงวนไว้ให้บัญชี admin บัญชีเดียว จึงไม่อยู่ใน dropdown
   แต่ยังต้องส่ง $roles ทั้งชุดไปด้วย เพื่อให้แถวของ admin แสดงชื่อสิทธิ์ได้ถูกต้อง
   (ถ้าส่งไปแค่ตัวที่เลือกได้ ช่องสิทธิ์ของ admin จะว่างเปล่า) */
$role_options = user_assignable_roles();

/* ---------- ฝัง JSON ลงหน้าอย่างปลอดภัย ----------
 * PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง
 * ไม่งั้นถ้าในข้อมูลมีคำว่า </script> เบราว์เซอร์จะปิดแท็กกลางคัน
 */
function us_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>

<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ ===== -->
  <div class="grid-toolbar flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center">

    <div class="relative flex-1 lg:max-w-xs">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="gridSearch" autocomplete="off"
             placeholder="ค้นหาชื่อผู้ใช้ ชื่อ หรืออีเมล"
             class="<?php echo $input; ?> pl-10">
    </div>

    <div class="grid-toolbar-actions flex flex-wrap items-center gap-2 max-lg:justify-end lg:ml-auto">
      <div class="flex items-center gap-2">
        <input type="number" id="addCount" value="1" min="1" max="50" aria-label="จำนวนแถวที่จะเพิ่ม"
               class="num h-11 w-16 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900">
        <button type="button" id="btnAddRows" class="<?php echo $btn_soft; ?> btn-compact" title="เพิ่มผู้ใช้ตามจำนวนที่กรอก">
          <i data-lucide="user-plus" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เพิ่มผู้ใช้</span>
        </button>
      </div>

      <!-- ส่งออกเฉพาะแถวที่ค้นหาอยู่ตอนนั้น ครบทุกหน้า และไม่มีคอลัมน์รหัสผ่านติดไปด้วย -->
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
    <span><i data-lucide="lock" class="h-3.5 w-3.5" aria-hidden="true"></i> ผู้ใช้ใหม่ได้รหัสผ่านตั้งต้น <?php echo htmlspecialchars(USER_DEFAULT_PASSWORD, ENT_QUOTES, 'UTF-8'); ?> แล้วไปเปลี่ยนเองได้</span>
    <span><i data-lucide="shield-check" class="h-3.5 w-3.5" aria-hidden="true"></i> ชื่อผู้ใช้แก้ได้เฉพาะตอนสร้างใหม่ (ดับเบิลคลิกเพื่อคัดลอกได้)</span>
  </div>
</div>


<script>
window.USERS = {
    csrfToken:      <?php echo us_json($csrf_token); ?>,
    endpoint:       'api/users.php',
    me:             <?php echo us_json($sess_username); ?>,
    myRole:         <?php echo us_json($sess_role); ?>,
    users:          <?php echo us_json($users); ?>,
    roleLabels:     <?php echo us_json($roles); ?>,
    roleOptions:    <?php echo us_json($role_options); ?>,
    defaultPassword: <?php echo us_json(USER_DEFAULT_PASSWORD); ?>,
    companyOptions: <?php echo us_json($company_options); ?>,
    deptOptions:    <?php echo us_json($dept_options); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/users.js', $ASSET_BASE); ?>"></script>
