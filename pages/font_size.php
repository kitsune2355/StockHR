<?php
/* ============================================================
 *  pages/font_size.php — ตั้งขนาดตัวอักษรของทั้งระบบ
 *
 *  เป็นค่าของ "เครื่องที่ใช้อยู่" ไม่ใช่ของบัญชีผู้ใช้
 *  เก็บไว้ที่ localStorage ของเบราว์เซอร์ (คีย์ hl-font เป็นเปอร์เซ็นต์ เช่น 110)
 *  จึงไม่มี SQL และไม่มี API — ทั้งหน้าทำงานอยู่ที่ assets/js/font-size.js
 *
 *  เหตุผลที่ไม่เก็บลงฐานข้อมูล: คนคนเดียวกันเปิดจากจอคอมกับจากมือถือ
 *  ต้องการขนาดคนละแบบอยู่แล้ว ถ้าผูกกับบัญชี การตั้งให้จอเล็กอ่านง่าย
 *  จะไปทำให้จอใหญ่ตัวใหญ่เกินจนเห็นข้อมูลได้น้อยลง
 *
 *  ตัวที่เอาค่าไปใช้จริงอยู่ที่ includes/head_assets.php ซึ่งอ่าน localStorage
 *  แล้วเขียน font-size ลง <html> ตั้งแต่ก่อนวาดหน้า ทุกหน้าจึงได้ขนาดเดียวกัน
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

$panel = 'rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';

$btn_main = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';

/* ---------- ระดับสำเร็จรูป ----------
 * ค่ากลาง ๆ ที่คนส่วนใหญ่ใช้จริง มีไว้ให้กดทีเดียวจบโดยไม่ต้องลากแถบเลื่อน
 * ตัวเลขไม่ได้เว้นเท่ากันโดยตั้งใจ — ช่วงใกล้ 100% ถี่กว่า เพราะคนที่ปรับ
 * ส่วนใหญ่แค่อยากขยับนิดเดียว ส่วนคนที่ต้องการต่างจากเดิมมากมีไม่กี่คน
 */
$levels = array(
    array('value' =>  85, 'title' => 'เล็กพิเศษ'),
    array('value' =>  92, 'title' => 'เล็ก'),
    array('value' => 100, 'title' => 'ปกติ'),
    array('value' => 110, 'title' => 'ใหญ่'),
    array('value' => 120, 'title' => 'ใหญ่พิเศษ')
);
?>

<div class="">

  <!-- ===== หัวเรื่อง ===== -->
  <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100">ขนาดตัวอักษร</h1>
  <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
    เลือกขนาดตัวอักษรที่อ่านสบายตาที่สุดสำหรับคุณ ระบบจะปรับขนาดข้อความ ปุ่ม และตารางของทุกหน้าตามค่านี้
  </p>
  <p class="mt-1 text-sm text-slate-400 dark:text-slate-500">
    ค่านี้เก็บไว้ที่เบราว์เซอร์ของคุณเอง ผู้ใช้อื่นไม่ได้รับผลกระทบ
    ส่วนเอกสารที่สั่งพิมพ์ใช้ขนาดมาตรฐานเสมอ เพื่อให้ตารางลงหน้ากระดาษพอดีเหมือนเดิม
  </p>

  <div class="<?php echo $panel; ?> mt-5">

    <!-- ===== ขนาดที่ใช้อยู่ ===== -->
    <div id="fsCurrent" class="flex items-center gap-4 border-b border-slate-200 px-6 py-5 dark:border-slate-800">
      <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="a-large-small" class="h-5 w-5" aria-hidden="true"></i>
      </span>
      <span class="min-w-0">
        <span class="block text-xs text-slate-500 dark:text-slate-400">ขนาดที่ใช้อยู่</span>
        <span class="block">
          <b id="fsNow" class="num text-lg font-bold text-slate-800 dark:text-slate-100">100%</b>
          <span id="fsNowName" class="text-sm text-slate-500 dark:text-slate-400">(ปกติ)</span>
        </span>
      </span>
    </div>

    <!-- ===== ระดับสำเร็จรูป ===== -->
    <div class="border-b border-slate-200 px-6 py-5 dark:border-slate-800">
      <h2 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">เลือกระดับสำเร็จรูป</h2>

      <!-- ตัวอย่างในปุ่มใช้ em ไม่ใช่ rem เพราะต้องโชว์ "ขนาดของระดับนั้น"
           ไม่ใช่ขนาดที่กำลังใช้อยู่ ถ้าใช้ rem ทุกปุ่มจะตัวเท่ากันหมด
           แล้วมองไม่ออกว่าต่างกันตรงไหนจนกว่าจะกดลองทีละอัน -->
      <div id="fsLevels" class="fs-levels">
        <?php foreach ($levels as $lv): ?>
        <button type="button" class="fs-level" data-value="<?php echo (int)$lv['value']; ?>"
                aria-pressed="false">
          <span class="fs-level-sample" style="font-size: <?php echo (int)$lv['value']; ?>%">กขค Abc 123</span>
          <span class="fs-level-title"><?php echo htmlspecialchars($lv['title'], ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="fs-level-value num"><?php echo (int)$lv['value']; ?>%</span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ===== ปรับละเอียด ===== -->
    <div class="border-b border-slate-200 px-6 py-5 dark:border-slate-800">
      <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-300">ปรับละเอียด</h2>
      <p class="mb-3 mt-1 text-xs text-slate-500 dark:text-slate-400">
        ถ้าระดับสำเร็จรูปยังไม่พอดี ใช้แถบเลื่อนหรือปุ่มบวก/ลบ ปรับได้ทีละ 1% (ตั้งแต่ 75% ถึง 150%)
      </p>

      <div id="fsFine" class="fs-fine">
        <button type="button" id="fsMinus" class="fs-step" aria-label="ลดขนาดลง 1%">
          <i data-lucide="minus" class="h-4 w-4" aria-hidden="true"></i>
        </button>

        <div class="fs-slider-wrap">
          <!-- ค่าจริงอยู่ที่ input ตัวนี้ตัวเดียว ปุ่มบวก/ลบกับระดับสำเร็จรูป
               ล้วนทำงานโดยการเขียนค่าลงที่นี่แล้วสั่ง input event
               จะได้มีทางเดินทางเดียวไม่ว่าผู้ใช้จะปรับด้วยวิธีไหน -->
          <input type="range" id="fsRange" class="fs-slider"
                 min="75" max="150" step="1" value="100"
                 aria-label="ขนาดตัวอักษร (เปอร์เซ็นต์)">
          <div class="fs-ticks num" aria-hidden="true">
            <span>75%</span><span>100%</span><span>150%</span>
          </div>
        </div>

        <button type="button" id="fsPlus" class="fs-step" aria-label="เพิ่มขนาดขึ้น 1%">
          <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <!-- ===== ตัวอย่าง =====
         จำลองหน้ารายงานยอดคงเหลือ ซึ่งเป็นหน้าที่คนเปิดค้างไว้นานที่สุดในระบบ
         สิ่งที่คนกังวลตอนขยายตัวอักษรคือ "ตารางจะยังอ่านออกไหม"
         ไม่ใช่ "ตัวหนังสือใหญ่พอหรือยัง" ตัวอย่างจึงต้องเป็นตารางของจริง

         ใช้คลาสชุดเดียวกับตารางในกล่องรายละเอียดเอกสารทุกตัว
         (doc-table / doc-pill / doc-stock / grid-user) ไม่ได้ลอกสีมาเขียนใหม่
         ตัวอย่างจึงเป็นของจริงเป๊ะ และถ้าวันหนึ่งมีคนแก้สีป้ายสถานะ
         ตัวอย่างในหน้านี้จะเปลี่ยนตามเองโดยไม่ต้องมาแก้ซ้ำ -->
    <div class="px-6 py-5">
      <h2 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">ตัวอย่าง</h2>

      <div id="fsPreview" class="fs-preview">
        <h3 class="fs-preview-title">ยอดคงเหลือในคลัง</h3>
        <p class="fs-preview-sub">ตัวอย่างจากหน้ารายงานยอดคงเหลือ · ข้อมูลสมมติ</p>

        <div class="doc-table-wrap mt-3">
          <table class="doc-table">
            <thead>
              <tr>
                <th>รหัสสินค้า</th>
                <th>ชื่อสินค้า</th>
                <th class="num">คงเหลือ</th>
                <th>หน่วย</th>
                <th>สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="grid-user doc-no">UF-PMB-002</span></td>
                <td>เสื้อฟอร์ม ผู้ชาย PRO (ดำ) ไซซ์ M</td>
                <td class="num"><span class="doc-stock">120</span></td>
                <td>ตัว</td>
                <td><span class="doc-pill is-ok">ปกติ</span></td>
              </tr>
              <tr>
                <td><span class="grid-user doc-no">UF-PWN-004</span></td>
                <td>เสื้อฟอร์ม ผู้หญิง PRO (น้ำเงิน) ไซซ์ L</td>
                <td class="num"><span class="doc-stock is-low">18</span></td>
                <td>ตัว</td>
                <td><span class="doc-pill is-wait">ต่ำกว่าจุดสั่งซื้อ</span></td>
              </tr>
              <tr>
                <td><span class="grid-user doc-no">SF-SHO-004</span></td>
                <td>รองเท้าเซฟตี้ เบอร์ 40</td>
                <td class="num"><span class="doc-stock is-out">0</span></td>
                <td>คู่</td>
                <td><span class="doc-pill is-bad">ของหมด</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- ปุ่มตัวอย่างใช้คลาสเดียวกับปุ่มจริงของระบบ แต่เป็น span ไม่ใช่ button
             เพราะเป็นของโชว์ กดไม่ได้ ถ้าทำเป็นปุ่มจริงจะกดได้แล้วไม่เกิดอะไรขึ้น
             และผู้ใช้แป้นพิมพ์จะต้อง Tab ผ่านปุ่มตายสองตัวก่อนถึงปุ่มบันทึกจริง -->
        <div class="fs-preview-btns" aria-hidden="true">
          <span class="<?php echo $btn_main; ?>">
            <i data-lucide="save" class="h-4 w-4"></i>
            <span>บันทึก</span>
          </span>
          <span class="<?php echo $btn_soft; ?>">
            <i data-lucide="x" class="h-4 w-4"></i>
            <span>ยกเลิก</span>
          </span>
        </div>
      </div>
    </div>

    <!-- ===== ปุ่มท้ายหน้า ===== -->
    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-6 py-4 dark:border-slate-800">
      <button type="button" id="fsReset" class="<?php echo $btn_soft; ?>">
        <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
        <span>คืนค่าเริ่มต้น (100%)</span>
      </button>
      <button type="button" id="fsSave" class="<?php echo $btn_main; ?>">
        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
        <span>บันทึกการตั้งค่า</span>
      </button>
    </div>
  </div>
</div>

<script src="<?php echo asset_url('assets/js/font-size.js', $ASSET_BASE); ?>"></script>
