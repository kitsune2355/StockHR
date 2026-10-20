<?php
/* ============================================================
 *  main.php — ประตูเดียวของระบบหลังบ้าน (front controller)
 *
 *  ทุกหน้าเข้าผ่านที่นี่:  main.php?page=<key>
 *  โดย <key> ต้องมีอยู่ในตาราง route ที่นิยามไว้ใน includes/menu.php เท่านั้น
 *
 *  ความปลอดภัย:
 *    ชื่อไฟล์ที่จะ include มาจาก "ตารางที่เรากำหนดเองในโค้ด" ไม่ได้เอามาจาก
 *    ค่าที่ผู้ใช้ส่งมา ค่า ?page= ถูกใช้แค่เป็น key ไปค้นในตารางเท่านั้น
 *    จึงไม่มีทางสั่ง include ไฟล์นอกรายการ (กัน path traversal / LFI)
 *
 *  ลำดับการทำงาน:
 *    1. checksession.php  -> ยังไม่ล็อกอินก็เด้งกลับหน้า login ทันที
 *    2. หา route          -> ไม่มีในรายการ = 404
 *    3. ตรวจสิทธิ์ role    -> ไม่ผ่าน = 403
 *    4. ตรวจว่ามีไฟล์จริง  -> ยังไม่ได้สร้าง = แสดงหน้าบอกว่าต้องสร้างไฟล์ไหน
 *    5. วาดกรอบหน้า แล้ว include เนื้อหาเข้ามาตรงกลาง
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);

/* เก็บ output ไว้ในบัฟเฟอร์ก่อน เพื่อให้หน้าที่มีฟอร์มบันทึกข้อมูลสั่ง
   header('Location: ...') ได้ แม้กรอบหน้าจะถูกวาดไปแล้ว
   (รูปแบบ POST -> Redirect -> GET กันผู้ใช้กด refresh แล้วบันทึกซ้ำ) */
ob_start();

include "config_ctrl/checksession.php";
include "includes/menu.php";

$routes    = app_routes();

/* หน้าแรกไม่เท่ากันทุกสิทธิ์ — ผู้ใช้งานทั่วไปลงที่ "ทำรายการเบิก"
   ส่วนคนอื่นลงที่ภาพรวมระบบ (ดู app_home_page ใน includes/menu.php)
   ตัวนี้ครอบทั้งตอนเข้าระบบ กดโลโก้ และลิงก์ main.php เปล่า ๆ ทุกที่ */
$HOME_PAGE = app_home_page($sess_role);

$requested = isset($_GET['page']) ? trim($_GET['page']) : '';
if ($requested === '') {
    $requested = $HOME_PAGE;
}

/* ชื่อหน้าแรกไว้ใช้บนปุ่มย้อนกลับของหน้าที่ไม่พบ/ไม่มีสิทธิ์
   จะได้ไม่เขียนว่า "กลับหน้าภาพรวมระบบ" ทั้งที่คนนั้นเข้าหน้านั้นไม่ได้ */
$HOME_TITLE = isset($routes[$HOME_PAGE]) ? $routes[$HOME_PAGE]['title'] : 'หน้าแรก';

$state         = 'ok';
$route         = null;
$page_file     = '';
$PAGE_TITLE    = '';
$ACTIVE_PAGE   = '';
$ACTIVE_PARENT = '';
$PAGE_FULL     = false;   // หน้าคุมพื้นที่เลื่อนเองหรือไม่ (ดู 'full' ใน includes/menu.php)

if (!isset($routes[$requested]) && isset($APP_MOVED_PAGES[$requested])) {
    /* หน้าที่ย้ายไปตั้งที่ระบบอื่นแล้ว (ดู includes/menu.php) */
    $state      = 'moved';
    $moved      = $APP_MOVED_PAGES[$requested];
    $PAGE_TITLE = $moved['title'];

} elseif (!isset($routes[$requested])) {
    $state      = 'not_found';
    $PAGE_TITLE = 'ไม่พบหน้าที่ต้องการ';

} else {
    $route = $routes[$requested];

    if (!app_can_access($route, $sess_role)) {
        $state      = 'forbidden';
        $PAGE_TITLE = 'ไม่มีสิทธิ์เข้าถึง';

    } else {
        $PAGE_TITLE  = $route['title'];
        $ACTIVE_PAGE = $route['key'];
        /* หน้าที่เป็นเมนูย่อย ต้องกางหัวข้อแม่ค้างไว้ให้เห็นว่าอยู่ตรงไหน */
        $ACTIVE_PARENT = isset($route['parent']) ? $route['parent'] : '';
        $PAGE_FULL     = isset($route['full']) ? (bool)$route['full'] : false;
        $page_file     = dirname(__FILE__) . '/' . $route['file'];

        if (!file_exists($page_file)) {
            $state = 'not_built';
        }
    }
}

/* ---------- ตัวเลขแจ้งเตือนบนเมนู ----------
 * ตั้งที่นี่ที่เดียว จะได้เห็นตัวเลขเดียวกันไม่ว่าเปิดหน้าไหนอยู่
 * ทั้งสามตัวเป็น COUNT(*) ที่มี index รองรับ จึงไม่หน่วงการโหลดหน้า
 *
 * สิ่งที่นับ ต่างกันตามสิทธิ์ เพราะ "งานที่ค้างอยู่" ของแต่ละคนคนละเรื่อง
 *   ผู้อนุมัติ  -> ใบเบิกที่รอตัดสิน
 *   ทุกคน      -> ใบจ่ายของตัวเองที่ยังไม่ได้เซ็นรับ
 *   คนดูแลคลัง -> สินค้าที่ถึงจุดต้องสั่งซื้อ
 */
require_once dirname(__FILE__) . '/backend/request.php';
require_once dirname(__FILE__) . '/backend/issue.php';
require_once dirname(__FILE__) . '/backend/adjust.php';

if (in_array($sess_role, array('super_admin', 'admin', 'approver'))) {
    /* "รายการใบเบิก" แสดงทุกใบ (รวมใบพนักงานใหม่) ตัวเลขจึงนับทุกใบด้วย */
    app_set_badge('request_list', request_waiting_count($sess_username, $sess_role, ''), 'amber');
    app_set_badge('report_low',   stock_low_count(),       'red');
}

/* ใบปรับยอดที่รออนุมัติ — เห็นเฉพาะคนที่อนุมัติได้ (ผู้ดูแลระบบ หรือคนในรายชื่อผู้อนุมัติ) */
if (adjust_approver_can($sess_username, $sess_role)) {
    app_set_badge('adjust_history', adjust_waiting_count(), 'amber');
}

/* ใบจ่ายที่รอเซ็นรับ นับเฉพาะของตัวเอง — เป็นงานที่คนอื่นทำแทนไม่ได้ */
app_set_badge('issue_history', issue_pending_ack_count($sess_username), 'brand');

include "includes/layout_top.php";
?>

<?php if ($state === 'ok'): ?>

    <?php include $page_file; ?>

<?php elseif ($state === 'not_built'): ?>

    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center dark:border-slate-700 dark:bg-slate-900">
      <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
        <i data-lucide="hammer" class="h-7 w-7" aria-hidden="true"></i>
      </span>
      <h2 class="mt-4 text-lg font-semibold text-slate-800 dark:text-slate-100">หน้านี้ยังไม่ได้สร้าง</h2>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">เมนูพร้อมใช้งานแล้ว เหลือแค่สร้างไฟล์เนื้อหาของหน้านี้</p>
      <p class="mx-auto mt-4 inline-block rounded-lg bg-slate-100 px-3 py-1.5 font-mono text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-300">
        <?php echo htmlspecialchars($route['file'], ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <p class="mt-6">
        <a href="main.php?page=<?php echo urlencode($HOME_PAGE); ?>"
           class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200">
          <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
          กลับหน้า<?php echo htmlspecialchars($HOME_TITLE, ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </p>
    </div>

<?php elseif ($state === 'moved'): ?>

    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-6 py-14 text-center dark:border-sky-500/30 dark:bg-sky-500/10">
      <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">
        <i data-lucide="arrow-right-left" class="h-7 w-7" aria-hidden="true"></i>
      </span>
      <h2 class="mt-4 text-lg font-semibold text-sky-900 dark:text-sky-200">
        <?php echo htmlspecialchars($moved['title'], ENT_QUOTES, 'UTF-8'); ?> ย้ายไปตั้งค่าที่ระบบสวัสดิการแล้ว
      </h2>
      <p class="mx-auto mt-1 max-w-xl text-sm text-sky-800 dark:text-sky-300/90">
        <?php echo htmlspecialchars($moved['message'], ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <?php
      $moved_to = (isset($moved['link']) && isset($routes[$moved['link']])
                   && app_can_access($routes[$moved['link']], $sess_role)) ? $moved['link'] : $HOME_PAGE;
      $moved_label = ($moved_to === $HOME_PAGE) ? ('กลับหน้า' . $HOME_TITLE) : $moved['link_title'];
      ?>
      <p class="mt-6">
        <a href="main.php?page=<?php echo urlencode($moved_to); ?>"
           class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200">
          <i data-lucide="<?php echo ($moved_to === $HOME_PAGE) ? 'arrow-left' : 'arrow-right'; ?>" class="h-4 w-4" aria-hidden="true"></i>
          <?php echo htmlspecialchars($moved_label, ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </p>
    </div>

<?php elseif ($state === 'forbidden'): ?>

    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-6 py-14 text-center dark:border-amber-500/30 dark:bg-amber-500/10">
      <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
        <i data-lucide="shield-alert" class="h-7 w-7" aria-hidden="true"></i>
      </span>
      <h2 class="mt-4 text-lg font-semibold text-amber-900 dark:text-amber-200">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h2>
      <p class="mt-1 text-sm text-amber-800 dark:text-amber-300/90">
        สิทธิ์ปัจจุบันของคุณคือ &ldquo;<?php echo htmlspecialchars(app_role_label($sess_role), ENT_QUOTES, 'UTF-8'); ?>&rdquo;
        หากต้องใช้งานหน้านี้ กรุณาติดต่อผู้ดูแลระบบ
      </p>
      <p class="mt-6">
        <a href="main.php?page=<?php echo urlencode($HOME_PAGE); ?>"
           class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200">
          <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
          กลับหน้า<?php echo htmlspecialchars($HOME_TITLE, ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </p>
    </div>

<?php else: ?>

    <div class="rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center dark:border-slate-800 dark:bg-slate-900">
      <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
        <i data-lucide="file-question" class="h-7 w-7" aria-hidden="true"></i>
      </span>
      <h2 class="mt-4 text-lg font-semibold text-slate-800 dark:text-slate-100">ไม่พบหน้าที่ต้องการ</h2>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">ลิงก์อาจพิมพ์ผิด หรือหน้านี้ถูกย้ายไปแล้ว</p>
      <p class="mt-6">
        <a href="main.php?page=<?php echo urlencode($HOME_PAGE); ?>"
           class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200">
          <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
          กลับหน้า<?php echo htmlspecialchars($HOME_TITLE, ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </p>
    </div>

<?php endif; ?>

<?php include "includes/layout_bottom.php"; ?>
