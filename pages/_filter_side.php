<?php
/* ============================================================
 *  pages/_filter_side.php — ปิดพื้นที่เนื้อหา แล้วเปิดแถบตัวกรองด้านขวา
 *  หน้าที่เรียกต้องตั้ง $filter_hint (ข้อความอธิบายใต้หัวข้อ) ไว้ก่อน ถ้าต้องการ
 * ============================================================ */
$fp_label = 'mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400';
$fp_input = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>
  </div><!-- /.fp-main -->

  <!-- ===== แถบตัวกรอง =====
       จอกว้าง (>= 1280px) กางค้างไว้ทางขวาของเนื้อหา
       จอเล็ก   ซ่อนไว้นอกจอ เปิดด้วยปุ่ม "ตัวกรอง" บนแถบเครื่องมือ
       อยู่ใน <main> เดียวกับเนื้อหา ไม่ใช่ overlay ของทั้งหน้าต่าง
       จึงไม่ทับแถบเมนูข้างและแถบบน -->
  <aside id="filterPanel" class="fp-side" aria-label="ตัวกรองข้อมูล">

    <div class="fp-head">
      <span class="min-w-0">
        <span class="fp-title">
          <i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i>
          ตัวกรอง
        </span>
        <?php if (isset($filter_hint) && $filter_hint !== '') { ?>
        <span class="fp-hint"><?php echo htmlspecialchars($filter_hint, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php } ?>
      </span>
      <button type="button" id="fpClose" class="fp-close" aria-label="ปิดตัวกรอง">
        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
      </button>
    </div>

    <div class="fp-body">