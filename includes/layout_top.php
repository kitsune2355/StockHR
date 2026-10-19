<?php
/* ============================================================
 *  includes/layout_top.php
 *  ส่วนบนของทุกหน้า — <head> + เมนูข้าง + แถบบน + เปิดพื้นที่เนื้อหา
 *
 *  ตัวแปรที่ต้องกำหนดไว้ก่อน include:
 *    $PAGE_TITLE   ชื่อหน้า (แสดงบนหัวข้อและ <title>)
 *    $ACTIVE_PAGE  key ของเมนูที่กำลังเปิดอยู่
 *    $ACTIVE_PARENT key ของหัวข้อแม่ (ถ้าหน้านี้เป็นเมนูย่อย) — main.php ตั้งให้
 *    $PAGE_FULL    true = หน้านี้ขอคุมพื้นที่เลื่อนเอง (ดูด้านล่าง)
 *
 *  ต้อง include config_ctrl/checksession.php และ includes/menu.php มาก่อน
 *  ปิดท้ายหน้าด้วย includes/layout_bottom.php เสมอ
 *
 *  ---------------------------------------------------------------
 *  โครงหน้าเป็นแบบเต็มจอ (app shell)
 *  ---------------------------------------------------------------
 *  หน้าต่างเบราว์เซอร์ไม่เลื่อนเลย แถบบนกับเมนูข้างอยู่กับที่ตลอด
 *  พื้นที่เนื้อหาสูงเท่าที่เหลือพอดี แล้วแบ่งเป็น 2 โหมด:
 *
 *    $PAGE_FULL = false (ค่าเริ่มต้น)
 *        เนื้อหาเลื่อนได้ทั้งก้อนในกรอบของตัวเอง เหมาะกับหน้าทั่วไป
 *
 *    $PAGE_FULL = true
 *        กรอบเนื้อหาสูงคงที่และไม่เลื่อน หน้านั้นต้องจัดการ scroll เอง
 *        เหมาะกับหน้าตารางที่อยากตรึงแถบเครื่องมือกับแถบแบ่งหน้าไว้
 *        แล้วให้เลื่อนเฉพาะตัวตาราง
 *        เปิดใช้โดยใส่ 'full' => true ในรายการเมนูที่ includes/menu.php
 *
 *  เมนูข้างมี 3 สถานะ:
 *    จอใหญ่ปกติ    — กว้าง 16rem แสดงชื่อครบ
 *    จอใหญ่ย่อ     — กว้าง 5rem เหลือแต่ไอคอน ชี้แล้วมี tooltip (กดปุ่มย่อที่หัวเมนู)
 *    จอเล็ก        — ซ่อนไว้นอกจอ เปิดด้วยปุ่มสามขีดบนแถบบน
 * ============================================================ */

$PAGE_TITLE    = isset($PAGE_TITLE)    ? $PAGE_TITLE    : 'ระบบสต็อก Uniform';
$ACTIVE_PAGE   = isset($ACTIVE_PAGE)   ? $ACTIVE_PAGE   : '';
$ACTIVE_PARENT = isset($ACTIVE_PARENT) ? $ACTIVE_PARENT : '';
$PAGE_FULL     = isset($PAGE_FULL)     ? (bool)$PAGE_FULL : false;

$display_name = ($sess_full_name !== '') ? $sess_full_name : $sess_username;

/* ---------- โทเคนกันการยิงคำสั่งข้ามเว็บ ----------
 * สร้างที่นี่เพราะกระดิ่งแจ้งเตือนกับคู่มือมีอยู่ "ทุกหน้า"
 * ถ้าปล่อยให้แต่ละหน้าสร้างเอง หน้าที่ยังไม่ได้แก้จะไม่มีโทเคนให้สองตัวนี้ใช้
 * หน้าที่สร้างเองอยู่แล้วก็ไม่กระทบ เพราะโค้ดชุดนั้นเช็ค empty ก่อนสร้างเหมือนกัน
 * จึงมาเจอค่าที่มีอยู่แล้วและใช้ตัวเดียวกันต่อ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$layout_csrf = $_SESSION['csrf_token'];

/* ---------- จำนวนแจ้งเตือนที่ยังไม่ได้อ่าน ----------
 * อ่านตั้งแต่ตอนวาดหน้า ไม่ใช่ให้ JavaScript ไปถามทีหลัง
 * ไม่งั้นกระดิ่งจะขึ้นมาเป็น 0 ก่อนแล้วค่อยกระพริบเป็นเลขจริง
 * เป็น COUNT(*) บน index (username, is_read) จึงไม่หน่วงการโหลดหน้า */
require_once dirname(dirname(__FILE__)) . '/backend/notify.php';
$layout_unread = notify_unread_count($sess_username);

/* ค่าที่สคริปต์ส่วนกลางทุกตัวต้องใช้ ประกาศที่เดียวจบ */
$layout_ctx = array(
    'page'     => $ACTIVE_PAGE,
    'csrf'     => $layout_csrf,
    'user'     => $sess_username,
    'role'     => $sess_role,
    'unread'   => $layout_unread
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8'); ?> | ระบบสต็อก Uniform Happyland</title>

<?php include 'includes/head_assets.php'; ?>

<!-- ค่าที่สคริปต์ส่วนกลาง (คู่มือ + กระดิ่งแจ้งเตือน) ต้องใช้
     แปลง < เป็น < ก่อนพ่นออกมา กันข้อความที่มี </script> แฝงอยู่
     ปิดแท็กก่อนเวลาแล้วเนื้อหาที่เหลือหลุดออกมาเป็น HTML
     (PHP 5.2 ยังไม่มี JSON_HEX_TAG ให้ใช้ จึงต้องแทนที่เอง) -->
<script>
window.APP_CTX = <?php echo app_json($layout_ctx); ?>;
</script>
</head>

<body class="app-shell bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">

<!-- ฉากหลังทึบตอนเปิดเมนูบนจอเล็ก -->
<div id="sidebarOverlay" hidden class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

<!-- ============================================================
     กล่องแจ้งเตือน
     วางไว้เป็นลูกของ <body> ตั้งแต่แรก ไม่ใช่ในแถบบน
     เพราะ #appHeader กับ #appMain มี overflow ที่จะตัดกล่องนี้หายไปครึ่งหนึ่ง
     ซึ่ง z-index แก้ไม่ได้ (ปัญหาเดียวกับที่เคยเจอกับกล่องรายละเอียดเอกสาร)
     ============================================================ -->
<div id="notiBackdrop" hidden class="fixed inset-0 z-[60] bg-slate-900/20"></div>

<aside id="notiPanel" hidden
       class="fixed z-[61] flex max-h-[min(32rem,calc(100vh-5rem))] w-[min(24rem,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
       role="dialog" aria-modal="false" aria-labelledby="notiTitle">

  <div class="flex flex-none items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
    <h2 id="notiTitle" class="text-sm font-bold text-slate-800 dark:text-slate-100">การแจ้งเตือน</h2>
    <span id="notiCount"
          class="rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-semibold leading-none text-white"
          hidden></span>

    <button type="button" id="notiReadAll"
            class="ml-auto rounded-lg px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:opacity-40 dark:text-brand-300 dark:hover:bg-brand-500/10">
      อ่านทั้งหมด
    </button>
    <button type="button" id="notiClose" aria-label="ปิด"
            class="flex h-7 w-7 flex-none items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand-200 dark:hover:bg-slate-800">
      <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
    </button>
  </div>

  <!-- รายการ — เลื่อนเฉพาะในกล่องนี้ หน้าข้างหลังไม่ขยับ
       โหลดมา 10 รายการก่อน แล้วเลื่อนถึงท้ายค่อยโหลดเพิ่ม (assets/js/app-notify.js) -->
  <div id="notiList" class="nav-scroll flex-1 overflow-y-auto overscroll-contain">
    <div class="px-4 py-10 text-center text-sm text-slate-400">กำลังโหลด…</div>
  </div>

  <!-- แถบล่าง — อยู่นอกกล่องที่เลื่อน จะได้ค้างให้เห็นตลอดไม่ต้องเลื่อนไปหา -->
  <div id="notiFoot" class="flex-none border-t border-slate-200 dark:border-slate-800"></div>
</aside>

<!-- ============================================================
     เมนูข้าง
     ============================================================ -->
<aside id="sidebar"
       class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-[transform,width] duration-200 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0">

  <!-- ---------- หัวเมนู : โลโก้ + ปุ่มย่อ ---------- -->
  <div class="relative flex h-16 flex-none items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
    <a href="main.php" class="nav-brand flex min-w-0 flex-1 items-center gap-3">
      <span class="flex h-10 w-10 flex-none items-center justify-center overflow-hidden rounded-xl bg-white ring-1 ring-brand-100 dark:bg-slate-800 dark:ring-slate-700">
        <img src="<?php echo asset_url('assets/img/hl_logo-removebg-preview.png', $ASSET_BASE); ?>" alt="" class="logo-img h-full w-full object-contain p-0.5">
        <i data-lucide="warehouse" class="logo-fallback h-5 w-5 text-brand-600" hidden aria-hidden="true"></i>
      </span>
      <span class="nav-text min-w-0">
        <span class="brand-title block truncate text-[15px] font-bold leading-tight text-slate-900 dark:text-white">ระบบสต็อก Uniform</span>
        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">ฝ่ายทรัพยากรบุคคล · Happyland</span>
      </span>
    </a>

    <!-- ย่อ/ขยายเมนู (เฉพาะจอใหญ่)
         วางลอยไว้ที่ขอบขวาของเมนู เพื่อให้กดได้ทั้งตอนกางและตอนย่อ
         ถ้าวางไว้ในแถวปกติ พอย่อเหลือ 5rem จะไม่มีที่ให้ปุ่มอยู่ -->
    <button type="button" id="sidebarCollapse" aria-label="ย่อเมนู" title="ย่อเมนู"
            class="absolute -right-3 top-1/2 z-10 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-400 shadow-sm transition hover:border-brand-300 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-brand-600 dark:focus:ring-brand-900 max-lg:hidden">
      <i data-lucide="chevron-left" class="h-3.5 w-3.5 transition-transform" aria-hidden="true"></i>
    </button>
  </div>

  <!-- ---------- รายการเมนู ---------- -->
  <nav class="nav-scroll flex-1 space-y-6 overflow-y-auto overflow-x-hidden px-3 py-5">
    <?php foreach ($APP_NAV as $section):
        $items = app_visible_items($section, $sess_role);
        if (count($items) === 0) { continue; }
    ?>
    <div>
      <p class="nav-section px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
        <span class="nav-text"><?php echo htmlspecialchars($section['section'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="nav-section-dash" aria-hidden="true"></span>
      </p>

      <ul class="space-y-1">
        <?php foreach ($items as $item):

            $has_children = isset($item['children']);
            $is_open      = ($has_children && $item['key'] === $ACTIVE_PARENT);
            $is_active    = (!$has_children && $item['key'] === $ACTIVE_PAGE);
            $is_danger    = (isset($item['tone']) && $item['tone'] === 'danger');

            /* สีของแถวเมนู */
            if ($is_active) {
                $row_class = 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300';
            } elseif ($is_danger) {
                $row_class = 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10';
            } else {
                $row_class = 'text-slate-600 hover:bg-slate-100 hover:text-brand-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-brand-300';
            }
        ?>
        <li class="nav-item relative">

          <?php if ($has_children): ?>
          <!-- ===== หัวข้อที่มีเมนูย่อย ===== -->
          <button type="button"
                  class="nav-link nav-toggle flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition <?php echo $row_class; ?>"
                  data-target="sub-<?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?>"
                  aria-controls="sub-<?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?>"
                  aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
            <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"
               class="nav-icon h-[18px] w-[18px] flex-none" aria-hidden="true"></i>
            <span class="nav-text flex-1 truncate text-left"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            <i data-lucide="chevron-right"
               class="nav-text nav-caret h-4 w-4 flex-none text-slate-400 transition-transform <?php echo $is_open ? 'rotate-90' : ''; ?>"
               aria-hidden="true"></i>
            <span class="nav-tooltip"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
          </button>

          <ul id="sub-<?php echo htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8'); ?>"
              class="nav-sub mt-1 space-y-1 border-l border-slate-200 pl-3 dark:border-slate-800 ml-[1.4rem]"
              <?php if (!$is_open): ?>hidden<?php endif; ?>>
            <?php foreach ($item['children'] as $child):
                $child_active = ($child['key'] === $ACTIVE_PAGE);
            ?>
            <li>
              <a href="main.php?page=<?php echo urlencode($child['key']); ?>"
                 <?php if ($child_active): ?>aria-current="page"<?php endif; ?>
                 class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition <?php
                    echo $child_active
                        ? 'bg-brand-600 font-medium text-white shadow-sm shadow-brand-600/25'
                        : 'text-slate-500 hover:bg-slate-100 hover:text-brand-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-brand-300'; ?>">
                <span class="truncate"><?php echo htmlspecialchars($child['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (!empty($child['badge'])): ?>
                <span class="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">
                  <?php echo (int)$child['badge']; ?>
                </span>
                <?php endif; ?>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>

          <?php else: ?>
          <!-- ===== รายการเดี่ยว ===== -->
          <a href="<?php echo isset($item['url'])
                        ? htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8')
                        : 'main.php?page=' . urlencode($item['key']); ?>"
             <?php if ($is_active): ?>aria-current="page"<?php endif; ?>
             class="nav-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition <?php echo $row_class; ?>">
            <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"
               class="nav-icon h-[18px] w-[18px] flex-none" aria-hidden="true"></i>
            <span class="nav-text flex-1 truncate"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if (!empty($item['badge'])): ?>
            <span class="nav-text rounded-full px-2 py-0.5 text-[10px] font-semibold leading-none text-white <?php
                echo (isset($item['tone']) && $item['tone'] === 'red') ? 'bg-red-500' : 'bg-brand-600'; ?>">
              <?php echo (int)$item['badge']; ?>
            </span>
            <?php endif; ?>
          </a>
          <?php endif; ?>

        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </nav>

  <!-- ---------- ท้ายเมนู : ออกจากระบบ ----------
       ตรึงไว้นอกพื้นที่ที่เลื่อนได้ จะได้อยู่ตำแหน่งเดิมเสมอ ไม่หายไปกับ scroll -->
  <div class="flex-none border-t border-slate-200 px-3 py-3 dark:border-slate-800">
    <div class="nav-item relative">
      <a href="config_ctrl/logout.php"
         class="nav-link flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
        <i data-lucide="log-out" class="nav-icon h-[18px] w-[18px] flex-none" aria-hidden="true"></i>
        <span class="nav-text flex-1 text-left">ออกจากระบบ</span>
        <span class="nav-tooltip">ออกจากระบบ</span>
      </a>
    </div>
  </div>
</aside>

<!-- ============================================================
     แถบบน
     ============================================================ -->
<header id="appHeader"
        class="fixed inset-x-0 top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 transition-[left] duration-200 dark:border-slate-800 dark:bg-slate-900 lg:left-64 lg:px-6">

  <!-- ปุ่มเปิดเมนูบนจอเล็ก -->
  <button type="button" id="sidebarToggle" aria-controls="sidebar" aria-expanded="false" aria-label="เปิดเมนู"
          class="flex h-10 w-10 flex-none items-center justify-center rounded-lg text-slate-500 transition hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:text-slate-400 dark:hover:bg-slate-800 dark:focus:ring-brand-900 lg:hidden">
    <i data-lucide="menu" class="h-5 w-5" aria-hidden="true"></i>
  </button>

  <!-- ชื่อหน้าปัจจุบัน แสดงทุกขนาดจอ — เดิมจอเล็กแสดงชื่อระบบแทน
       ทำให้บนมือถือไม่รู้เลยว่าอยู่หน้าไหน ทั้งที่ชื่อระบบเห็นได้จากเมนูข้างอยู่แล้ว -->
  <span class="truncate text-[15px] font-bold text-slate-900 dark:text-white lg:font-semibold lg:text-slate-700 lg:dark:text-slate-300">
    <?php echo htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8'); ?>
  </span>

  <div class="ml-auto flex items-center gap-2">

    <?php if (!empty($sess_agency_name)): ?>
    <span class="hidden items-center gap-2 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300 md:inline-flex">
      <i data-lucide="building-2" class="h-3.5 w-3.5" aria-hidden="true"></i>
      <?php echo htmlspecialchars($sess_agency_name, ENT_QUOTES, 'UTF-8'); ?>
    </span>
    <?php endif; ?>

    <!-- ---------- กระดิ่งแจ้งเตือน ----------
         ตัวเลขถูกวาดมาจากฝั่งเซิร์ฟเวอร์ตั้งแต่แรก ไม่ใช่ให้ JS ไปถามทีหลัง
         ไม่งั้นจะเห็นกระดิ่งเปล่าแวบหนึ่งแล้วเลขค่อยเด้งขึ้นมา
         ตัวรายการค่อยโหลดตอนกดเปิด เพราะคนส่วนใหญ่ไม่ได้กดทุกครั้งที่เข้าหน้า -->
    <div class="relative">
      <button type="button" id="notiBtn" aria-haspopup="true" aria-expanded="false"
              aria-label="แจ้งเตือน" title="แจ้งเตือน"
              class="relative flex h-10 w-10 flex-none items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-brand-300 dark:focus:ring-brand-900">
        <i data-lucide="bell" class="h-5 w-5" aria-hidden="true"></i>
        <span id="notiDot"
              class="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-red-500 px-1 text-[10px] font-bold leading-[18px] text-white"
              <?php if ($layout_unread <= 0): ?>hidden<?php endif; ?>>
          <?php echo ($layout_unread > 99) ? '99+' : (int)$layout_unread; ?>
        </span>
      </button>
    </div>

    <!-- ---------- ปุ่มเปิด/ปิดคู่มือการใช้งาน ----------
         ซ่อนไว้ก่อนเสมอ แล้ว app-guide.js จะเปิดให้เฉพาะหน้าที่มีคู่มือจริง
         ปุ่มที่กดแล้วไม่มีอะไรเกิดขึ้นแย่กว่าไม่มีปุ่ม -->
    <button type="button" id="appGuideBtn" aria-pressed="false" hidden
            aria-label="คู่มือการใช้งานหน้านี้" title="คู่มือการใช้งานหน้านี้"
            class="flex h-10 w-10 flex-none items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-brand-300 dark:focus:ring-brand-900">
      <i data-lucide="circle-help" class="h-5 w-5" aria-hidden="true"></i>
    </button>

    <!-- สลับโหมดสว่าง/มืด
         ไอคอนบอก "โหมดที่จะเปลี่ยนไป" ไม่ใช่โหมดปัจจุบัน
         อยู่โหมดสว่าง -> เห็นพระจันทร์ (กดเพื่อเข้าโหมดมืด) และกลับกัน -->
    <button type="button" id="themeToggle" aria-pressed="false"
            aria-label="สลับเป็นโหมดมืด" title="สลับเป็นโหมดมืด"
            class="flex h-10 w-10 flex-none items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-brand-300 dark:focus:ring-brand-900">
      <i data-lucide="moon" class="icon-to-dark h-5 w-5" aria-hidden="true"></i>
      <i data-lucide="sun" class="icon-to-light h-5 w-5" aria-hidden="true" hidden></i>
    </button>
    
    <div class="flex items-center gap-2 border-l border-slate-200 pl-2 dark:border-slate-800">
      <span class="flex h-9 w-9 flex-none items-center justify-center rounded-lg bg-brand-600 text-sm font-semibold text-white">
        <?php echo htmlspecialchars(app_initials($display_name), ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <span class="hidden text-left sm:block">
        <span class="block max-w-[10rem] truncate text-sm font-semibold leading-tight text-slate-800 dark:text-slate-200">
          <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <span class="block text-xs text-slate-500 dark:text-slate-400">
          <?php echo htmlspecialchars(app_role_label($sess_role), ENT_QUOTES, 'UTF-8'); ?>
        </span>
      </span>
    </div>
  </div>
</header>

<!-- ============================================================
     พื้นที่เนื้อหา
     ============================================================ -->
<main id="appMain"
      class="fixed inset-x-0 bottom-0 top-16 flex flex-col overflow-hidden transition-[padding] duration-200 lg:pl-64">

<?php if ($PAGE_FULL): ?>
  <!-- โหมดเต็มจอ: กรอบนี้สูงคงที่และไม่เลื่อน หน้าเป็นคนตัดสินใจว่าจะให้ส่วนไหนเลื่อน
       ลูกที่ต้องการเลื่อนต้องมี flex-1 min-h-0 overflow-auto เอง
       (min-h-0 จำเป็น ไม่งั้น flex item จะไม่ยอมหดต่ำกว่าความสูงเนื้อหา แล้วล้นกรอบ) -->
  <div class="page-enter mx-auto flex w-full max-w-8xl flex-1 flex-col overflow-hidden px-4 py-5 lg:px-8">
<?php else: ?>
  <!-- โหมดปกติ: เนื้อหาเลื่อนทั้งก้อนในกรอบนี้ หน้าต่างเบราว์เซอร์ยังไม่เลื่อน -->
  <div id="pageScroll" class="nav-scroll flex-1 overflow-y-auto">
    <div class="page-enter mx-auto w-full max-w-8xl px-4 py-6 lg:px-8">
<?php endif; ?>

    <?php if (!empty($sess_need_agency_popup)): ?>
    <div class="mb-6 flex flex-none items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
         role="alert">
      <i data-lucide="triangle-alert" class="mt-0.5 h-5 w-5 flex-none" aria-hidden="true"></i>
      <span>คุณดูแลหลายหน่วยงาน กรุณาเลือกหน่วยงานที่ต้องการทำงานก่อนบันทึกข้อมูล</span>
    </div>
    <?php endif; ?>
