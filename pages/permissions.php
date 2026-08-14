<?php
/* ============================================================
 *  pages/permissions.php — ตั้งค่าสิทธิ์การใช้งานเมนู
 *
 *  ตารางกาเปิด/ปิด : แถวคือเมนู คอลัมน์คือสิทธิ์ผู้ใช้งาน
 *  แก้สะสมไว้ก่อน แล้วกดบันทึกส่งทั้งชุดครั้งเดียวไปที่ api/permissions.php
 *
 *  ผลของการตั้งค่าไม่ได้แค่ซ่อนเมนู แต่กันการเข้าถึงจริง
 *  เพราะ main.php เรียก app_can_access ก่อน include ไฟล์หน้านั้นเสมอ
 *  ปิดเมนูแล้วพิมพ์ URL ตรง ๆ ก็จะเจอหน้า "ไม่มีสิทธิ์เข้าถึง"
 *
 *  ผู้ดูแลระบบสูงสุดไม่มีให้ปรับ — เข้าได้ทุกเมนูเสมอ
 *  ถ้าปรับได้แล้วเผลอปิดสิทธิ์หน้านี้ของตัวเอง จะไม่มีใครเข้ามาแก้คืนได้อีกเลย
 *
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/menu_permission.php';
require_once dirname(dirname(__FILE__)) . '/backend/user.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- ประกอบรายการเมนูจากแหล่งความจริงเดียว ----------
 * อ่านจาก $APP_NAV ที่ includes/menu.php เตรียมไว้ (main.php include มาก่อนแล้ว)
 * เพิ่มเมนูใหม่ในไฟล์นั้น หน้านี้จะมีแถวใหม่ให้ตั้งค่าเองอัตโนมัติ
 */
$perm_roles = menu_permission_roles();          // admin / approver / general
$role_names = user_roles();

$perm_rows  = array();
$saved_map  = menu_permission_map();

foreach ($APP_NAV as $section) {
    foreach ($section['items'] as $item) {

        /* หัวข้อที่มีเมนูย่อย ไม่มีหน้าของตัวเอง สิทธิ์คิดจากลูกทั้งหมด
           จึงให้ตั้งค่าที่ลูกอย่างเดียว แล้วแสดงชื่อหัวข้อแม่กำกับไว้ */
        if (isset($item['children'])) {
            /* กลุ่มคือหัวข้อแม่ ไอคอนกลุ่มจึงเป็นไอคอนของหัวข้อแม่นั้น
               เมนูย่อยไม่มีไอคอนของตัวเองใน menu.php อยู่แล้ว */
            $parent_icon = isset($item['icon']) ? $item['icon'] : 'folder';

            /* หัวข้อแม่บางกลุ่มไม่ต้องมีหัวกลุ่มให้พับในตารางนี้ (ดู perm_flat ใน menu.php) */
            $flat = (isset($item['perm_flat']) && $item['perm_flat']) ? 1 : 0;

            foreach ($item['children'] as $child) {
                $perm_rows[] = perm_row($child, $section['section'], $item['title'], $parent_icon, $perm_roles, $saved_map, $flat);
            }
            continue;
        }

        if (!isset($item['file'])) { continue; }

        /* เมนูเดี่ยวถูกจัดกลุ่มด้วยชื่อหมวด ซึ่งไม่มีไอคอนประจำหมวด
           จึงยืมไอคอนของเมนูแรกในหมวดนั้นมาใช้เป็นหน้าตาของกลุ่ม */
        $own_icon = isset($item['icon']) ? $item['icon'] : 'folder';
        $perm_rows[] = perm_row($item, $section['section'], '', $own_icon, $perm_roles, $saved_map, 0);
    }
}

function perm_row($item, $section, $parent, $group_icon, $roles, $saved_map, $flat = 0)
{
    $row = array(
        'menu_key'   => $item['key'],
        'title'      => $item['title'],
        'section'    => $section,
        'parent'     => $parent,
        'group_icon' => $group_icon,
        /* เมนูย่อยไม่ได้ตั้งไอคอนของตัวเองไว้ใน menu.php จึงยืมของหัวข้อแม่มาใช้
           เป็นวิธีเดียวกับที่แถบเมนูซ้ายทำอยู่ ไอคอนในตารางกับในแถบเมนูจะได้ตรงกัน */
        'icon'       => isset($item['icon']) ? $item['icon'] : $group_icon,
        /* ตั้งค่าไว้แล้วหรือยังใช้ค่าตั้งต้นในโค้ด — บอกให้ผู้ใช้รู้ว่าแถวไหนเคยแตะ */
        'is_saved' => isset($saved_map[$item['key']]) ? 1 : 0,
        /* 1 = แสดงเป็นแถวธรรมดา ไม่มีหัวกลุ่มให้พับ */
        'flat'     => $flat
    );

    foreach ($roles as $r) {
        $row[$r] = in_array($r, $item['roles']) ? 1 : 0;
    }
    return $row;
}

function pm_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>

<!-- ===== คำอธิบายหน้า ===== -->
<div class="mb-4 flex flex-none items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200">
  <i data-lucide="info" class="mt-0.5 h-5 w-5 flex-none" aria-hidden="true"></i>
  <span>
    กำหนดว่าสิทธิ์ไหนเปิดเมนูใดได้บ้าง — ปิดแล้วเมนูจะหายจากแถบซ้าย
    และถึงพิมพ์ที่อยู่หน้านั้นตรง ๆ ก็เข้าไม่ได้
    <b>ผู้ดูแลระบบสูงสุด</b> เข้าได้ทุกเมนูเสมอ ปรับไม่ได้
    <?php if ($sess_role !== 'super_admin') { ?>
    <br>คอลัมน์สิทธิ์ของคุณเองปรับไม่ได้ ต้องให้ผู้ดูแลระบบสูงสุดเป็นคนเปิดให้
    <?php } ?>
  </span>
</div>

<div class="<?php echo $panel; ?>">

  <!-- ===== แถบเครื่องมือ ===== -->
  <div class="grid-toolbar flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center">

    <div class="relative flex-1 lg:max-w-xs">
      <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
        <i data-lucide="search" class="h-4 w-4"></i>
      </span>
      <input type="search" id="gridSearch" autocomplete="off"
             placeholder="ค้นหาชื่อเมนู" class="<?php echo $input; ?> pl-10">
    </div>

    <div class="grid-toolbar-actions flex flex-wrap items-center gap-2 max-lg:justify-end lg:ml-auto">

      <!-- กาง/พับทุกกลุ่มพร้อมกัน — ตารางจัดกลุ่มตามเมนูหลักและเริ่มด้วยการพับไว้
           ปุ่มนี้ไว้ใช้ตอนอยากดูภาพรวมทั้งระบบทีเดียว แล้วพับกลับให้เรียบร้อย -->
      <button type="button" id="btnExpand" data-open="0" class="<?php echo $btn_soft; ?> btn-compact"
              title="กางหรือพับทุกกลุ่มพร้อมกัน">
        <i data-lucide="unfold-vertical" class="h-4 w-4" aria-hidden="true"></i>
        <span class="btn-label">กางทั้งหมด</span>
      </button>

      <button type="button" id="btnExport" class="<?php echo $btn_soft; ?> btn-compact"
              title="ส่งออกตารางสิทธิ์เป็นไฟล์ Excel">
        <i data-lucide="file-spreadsheet" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        <span class="btn-label">ส่งออก Excel</span>
      </button>

      <button type="button" id="btnRevert" class="<?php echo $btn_soft; ?> btn-compact"
              title="ยกเลิกการแก้ไขที่ยังไม่ได้บันทึก" disabled>
        <i data-lucide="undo-2" class="h-4 w-4" aria-hidden="true"></i>
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
    <span><i data-lucide="mouse-pointer-click" class="h-3.5 w-3.5" aria-hidden="true"></i> ติ๊กในช่องเพื่อเปิดสิทธิ์ ติ๊กออกเพื่อปิด</span>
    <span><i data-lucide="table-properties" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากคลุมแล้ว Ctrl+C คัดลอกไปวางใน Excel ได้</span>
    <span><i data-lucide="move-vertical" class="h-3.5 w-3.5" aria-hidden="true"></i> ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไป</span>
    <span><i data-lucide="git-branch" class="h-3.5 w-3.5" aria-hidden="true"></i> หัวข้อที่มีเมนูย่อยจะแสดงเมื่อมีลูกอย่างน้อยหนึ่งเมนูที่เปิดไว้</span>
  </div>
</div>


<script>
window.PERMISSIONS = {
    csrfToken: <?php echo pm_json($csrf_token); ?>,
    endpoint:  'api/permissions.php',
    me:        <?php echo pm_json($sess_username); ?>,
    rows:      <?php echo pm_json($perm_rows); ?>,
    roles:     <?php echo pm_json($perm_roles); ?>,
    roleNames: <?php echo pm_json($role_names); ?>,
    /* role ที่คนเปิดหน้านี้แก้ได้จริง — ไม่รวม role ของตัวเอง
       ฝั่งหน้าเว็บใช้ปิดคอลัมน์นั้นไม่ให้กด ส่วนการบังคับจริงอยู่ที่
       menu_permission_save() ซึ่งกันแม้จะยิง API ตรง ๆ */
    editable:  <?php echo pm_json(menu_permission_editable_roles($sess_role)); ?>,
    myRole:    <?php echo pm_json($sess_role); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/tabulator.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-common.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/grid-export.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/permissions.js', $ASSET_BASE); ?>"></script>
