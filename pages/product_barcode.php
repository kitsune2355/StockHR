<?php
/* ============================================================
 *  pages/product_barcode.php — สร้างฉลากบาร์โค้ดของรหัสสินค้า
 *
 *  ใช้ตอนรับของใหม่เข้าคลังแล้วต้องแปะรหัสไว้ที่ตัวสินค้า
 *  หรือตอนจัดชั้นใหม่แล้วป้ายเดิมหลุด/ซีดจนอ่านไม่ออก
 *
 *  ขั้นตอน: เลือกสินค้า (ซ้ายบน) -> ตั้งค่าในแผงขวา -> ดูแผ่นตัวอย่าง -> พิมพ์
 *  ผังเป็นแบบเต็มจอ ('full' => true ใน includes/menu.php) แต่ละกล่องเลื่อนในตัวเอง
 *
 *  ไม่บันทึกอะไรลงฐานข้อมูลเลย — รหัสถูกคำนวณสดจาก product_code ทุกครั้งที่เปิดหน้า
 *  จึงไม่มีทางที่ฉลากกับรหัสสินค้าจะไม่ตรงกัน และแก้รหัสแล้วฉลากเปลี่ยนตามเอง
 *
 *  เลือกได้สองชนิด เก็บ product_code ตัวเดียวกัน ต่างที่วิธีวาดและใครอ่านได้
 *    บาร์โค้ด  Code 128 ชุด B — ใช้กับเครื่องยิงที่เสียบคอมพิวเตอร์
 *    QR Code   ส่องด้วยกล้องมือถือได้ และเลือกจัดวางแนวนอน/แนวตั้งได้อีก
 *
 *  ตัวสร้าง: assets/js/barcode.js (เขียนเอง), assets/js/qr.js (ห่อ qrcode-generator MIT)
 *  การจัดหน้าฉลาก: assets/js/product-barcode.js
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/product.php';

/* เอาเฉพาะสินค้าที่ยังเปิดใช้งาน — สินค้าที่ปิดไปแล้วไม่ควรมีฉลากใหม่ไปแปะเพิ่ม */
$all_products = product_list(array('is_active' => 1));

$products = array();
for ($i = 0; $i < count($all_products); $i++) {
    $p = $all_products[$i];
    $products[] = array(
        'product_id'   => $p['product_id'],
        'product_code' => $p['product_code'],
        'product_name' => $p['product_name'],
        'old_code'     => $p['old_code'],
        'unit_name'    => $p['unit_name']
    );
}

/* PHP 5.2 ยังไม่มี JSON_HEX_TAG จึงต้องแทน < เอง
   ไม่งั้นถ้าชื่อสินค้ามีคำว่า </script> เบราว์เซอร์จะปิดแท็กกลางคัน */
function pb_json($value)
{
    return str_replace('<', '\u003C', json_encode($value));
}

$panel    = 'rounded-tl-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
$btn_main = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
$btn_soft = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
$input    = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$field    = 'block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
?>

<!-- ผังหน้า: ซ้าย = เลือกสินค้า (บน) + ตัวอย่างก่อนพิมพ์ (ล่าง)
     ขวา = แผงตั้งค่า ตรึงไว้ตลอด เพราะปรับค่าแล้วต้องเห็นผลที่แผ่นตัวอย่างทันที
     เต็มความสูงจอ หน้าไม่เลื่อนเอง แต่ละกล่องเลื่อนในตัวเอง -->
<div class="bc-shell no-print">

  <!-- ===== ซ้าย ===== -->
  <div class="bc-main">

  <!-- ----- บน: เลือกสินค้า ----- -->
  <section id="bcPickBox" class="<?php echo $panel; ?> flex min-h-0 flex-col overflow-hidden">

    <div class="flex flex-none flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
      <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">เลือกสินค้าที่จะทำฉลาก</h2>

        <div class="flex items-center gap-2">
          <span class="text-xs text-slate-500 dark:text-slate-400">
            เลือกแล้ว <span id="bcPicked" class="num font-semibold text-brand-700 dark:text-brand-300">0</span> รายการ
          </span>
          <!-- ปุ่มเปิดแผงตั้งค่า มีเฉพาะจอแคบ อยู่ในหัวกล่องเลือกสินค้าเพราะกล่องนี้แสดงตลอด -->
          <button type="button" id="bcSideToggle" class="<?php echo $btn_soft; ?> btn-compact bc-side-toggle">
            <i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i>
            <span class="btn-label">รูปแบบฉลาก</span>
          </button>
        </div>
      </div>

      <!-- ค้นหากับปุ่มจัดการอยู่บรรทัดเดียวกัน เพราะทำงานกับ "รายการที่เห็นอยู่ตอนนี้"
           เหมือนกัน — ปุ่มเลือกทั้งหมด = เลือกเฉพาะผลของคำค้นในช่องที่อยู่ติดกัน -->
      <div class="bc-search-row">
        <div class="bc-search">
          <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
            <i data-lucide="search" class="h-4 w-4"></i>
          </span>
          <input type="search" id="bcSearch" autocomplete="off"
                 placeholder="ค้นหารหัสสินค้า ชื่อสินค้า หรือรหัสเดิม"
                 class="<?php echo $input; ?> pl-10">
        </div>

        <button type="button" id="btnPickAll" class="<?php echo $btn_soft; ?> btn-compact flex-none"
                title="เลือกสินค้าทุกรายการที่แสดงอยู่ตอนนี้">
          <i data-lucide="list-checks" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">เลือกทั้งหมด</span>
        </button>
        <button type="button" id="btnClear" class="<?php echo $btn_soft; ?> btn-compact flex-none" disabled
                title="ล้างรายการที่เลือกไว้ทั้งหมด">
          <i data-lucide="eraser" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">ล้างที่เลือก</span>
        </button>
      </div>
    </div>

    <div id="bcList" class="bc-list"></div>
  </section>


  <!-- ----- ล่าง: แผ่นตัวอย่าง (ส่วนเดียวที่ถูกพิมพ์) ----- -->
  <div id="bcEmpty" class="<?php echo $panel; ?> bc-preview flex flex-col items-center justify-center p-10 text-center">
    <i data-lucide="scan-barcode" class="h-10 w-10 text-slate-300 dark:text-slate-600" aria-hidden="true"></i>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">เลือกสินค้าด้านบนเพื่อดูตัวอย่างฉลาก</p>
  </div>

  <div id="bcSheetWrap" hidden class="<?php echo $panel; ?> bc-preview flex min-h-0 flex-col overflow-hidden">
    <div class="no-print flex flex-none flex-wrap items-center gap-3 border-b border-slate-200 p-4 dark:border-slate-800">
      <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">ตัวอย่างก่อนพิมพ์</h2>
      <span id="bcCapped" hidden class="text-xs text-slate-500 dark:text-slate-400"></span>
      <!-- บนจอแคบ ฉลากถูกเรียงใหม่ให้พอดีจอ (ดู .bc-sheet ใน @media ของ app.css)
           ต้องบอกด้วย ไม่งั้นคนที่ดูจากมือถือจะนึกว่าเวลาพิมพ์ก็จะได้เรียงแบบนี้
           แล้วไปตัดสติกเกอร์ผิดแบบ ส่วนขนาดของแต่ละดวงยังเท่าของจริงทั้งสองกรณี -->
      <span class="ml-auto text-xs text-slate-400">
        <span class="hidden sm:inline">ขนาดบนจอเท่ากับขนาดจริงเมื่อพิมพ์ลงกระดาษ</span>
        <span class="sm:hidden">ขนาดดวงเท่าของจริง — การเรียงบนจอแคบไม่ตรงกับตอนพิมพ์</span>
      </span>
    </div>
    <!-- แยกกรอบที่เลื่อนได้ออกจากตัวตารางฉลาก
         กรอบนอกกินความกว้างเต็มและเป็นตัวเลื่อน ส่วนตารางข้างในกว้างได้ไม่เกิน
         ความกว้างของแผ่นสติกเกอร์จริง แล้วลดจำนวนคอลัมน์เองเมื่อที่ไม่พอ
         (ดู .bc-sheet ใน app.css) -->
    <div class="bc-sheet-scroll">
      <div id="bcSheet" class="bc-sheet"></div>
    </div>
  </div>
  </div>


  <!-- ===== ขวา: ตั้งค่าฉลาก =====
       จอกว้าง  ตรึงไว้ทางขวาตลอด ไม่ต้องกดเปิด
       จอแคบ    พับเก็บนอกจอ เปิดด้วยปุ่ม "รูปแบบฉลาก" เหมือนแถบตัวกรอง
                ของหน้าประวัติเอกสาร (ดู .fp-side ใน app.css เป็นต้นแบบ) -->
  <section id="bcSide" class="<?php echo $panel; ?> bc-side flex flex-col gap-4 p-4"
           aria-label="รูปแบบฉลาก">

    <div class="flex items-start gap-2">
      <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">รูปแบบฉลาก</h2>
      <button type="button" id="bcSideClose" class="bc-side-close" aria-label="ปิดแผงรูปแบบฉลาก">
        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
      </button>
    </div>

    <!-- ---------- ชนิดรหัส ----------
         บาร์โค้ดกับ QR เก็บ "รหัสสินค้า" ตัวเดียวกันเป๊ะ ต่างกันแค่วิธีวาด
         จึงเปลี่ยนไปมาได้โดยไม่กระทบอะไรอีกเลย ทั้งขนาดฉลาก จำนวนดวง
         การพิมพ์ และการดาวน์โหลด ใช้เส้นทางเดียวกันทั้งหมด

         ที่ต้องมีให้เลือกเพราะเครื่องอ่านคนละแบบกัน
         เครื่องยิงเลเซอร์ 1D ที่ใช้อยู่อ่านได้เฉพาะบาร์โค้ด
         ส่วน QR ไว้ให้ส่องด้วยกล้องมือถือ ซึ่งใครก็มีติดตัว -->
    <div>
      <span class="<?php echo $field; ?>">ชนิดรหัส</span>
      <div id="bcType" class="bc-type mt-1.5" role="group" aria-label="ชนิดรหัสบนฉลาก">
        <button type="button" class="bc-type-btn is-on" data-type="barcode">
          <i data-lucide="barcode" class="h-4 w-4" aria-hidden="true"></i>
          <span>บาร์โค้ด</span>
        </button>
        <button type="button" class="bc-type-btn" data-type="qr">
          <i data-lucide="qr-code" class="h-4 w-4" aria-hidden="true"></i>
          <span>QR Code</span>
        </button>
      </div>
    </div>

    <!-- การจัดวาง มีเฉพาะ QR เพราะบาร์โค้ดเป็นแถบเตี้ยกว้าง วางได้ท่าเดียว
         แนวนอน = QR ใหญ่สุด (กินความสูงเต็มดวง)  แนวตั้ง = ชื่อยาวอ่านง่ายกว่าแต่ QR เล็กลง
         ขึ้นกับว่าฉลากดวงนั้นจะให้กล้องอ่านหรือให้คนอ่านเป็นหลัก -->
    <div id="bcLayoutBox" hidden>
      <span class="<?php echo $field; ?>">การจัดวาง</span>
      <div id="bcLayout" class="bc-type mt-1.5" role="group" aria-label="การจัดวางบนฉลาก">
        <button type="button" class="bc-type-btn is-on" data-layout="landscape">
          <i data-lucide="panel-left" class="h-4 w-4" aria-hidden="true"></i>
          <span>แนวนอน</span>
        </button>
        <button type="button" class="bc-type-btn" data-layout="portrait">
          <i data-lucide="panel-top" class="h-4 w-4" aria-hidden="true"></i>
          <span>แนวตั้ง</span>
        </button>
      </div>
    </div>

    <div>
      <label for="bcSize" class="<?php echo $field; ?>">ขนาดดวงสติกเกอร์</label>
      <select id="bcSize" class="<?php echo $input; ?> mt-1.5">
        <option value="48x25" selected>A4 40 ดวง (48.5 × 25.4 มม.)</option>
        <option value="70x37">A4 24 ดวง (70 × 37 มม.)</option>
        <option value="38x21">A4 65 ดวง (38.1 × 21.2 มม.)</option>
        <option value="50x25">ม้วนสติกเกอร์ (50 × 25 มม.)</option>
      </select>
    </div>

    <div>
      <label for="bcCopies" class="<?php echo $field; ?>">จำนวนดวงต่อสินค้าหนึ่งรายการ</label>
      <input type="number" id="bcCopies" value="1" min="1" max="200"
             class="<?php echo $input; ?> num mt-1.5">
    </div>

    <div class="flex flex-col gap-2.5 border-t border-slate-200 pt-4 dark:border-slate-800">
      <label class="bc-toggle">
        <input type="checkbox" id="bcShowCode" checked>
        <span>แสดงรหัสสินค้าใต้บาร์โค้ด</span>
      </label>
      <label class="bc-toggle">
        <input type="checkbox" id="bcShowName" checked>
        <span>แสดงชื่อสินค้า</span>
      </label>
      <label class="bc-toggle">
        <input type="checkbox" id="bcShowUnit">
        <span>แสดงหน่วยนับ</span>
      </label>
      <!-- พิมพ์ลงกระดาษเปล่าแล้วตัดเอง ต้องมีเส้นนำ ไม่งั้นตัดไม่ตรง
           แต่ถ้าพิมพ์ลงแผ่นสติกเกอร์ที่ปรุรอยตัดมาแล้ว เส้นจะไปโผล่บนดวงจริง
           จึงปิดได้ ค่าเริ่มต้นเปิดไว้เพราะกระดาษเปล่าเป็นกรณีที่พบบ่อยกว่า -->
      <label class="bc-toggle">
        <input type="checkbox" id="bcCutLine" checked>
        <span>พิมพ์เส้นตัดประ</span>
      </label>
    </div>

    <!-- ขนาดของส่วนที่เล็กที่สุดในรหัส เป็นตัวชี้ขาดว่าเครื่องอ่านจะอ่านติดไหม
         บาร์โค้ดเรียกว่าแท่งที่แคบที่สุด QR เรียกว่าโมดูล เป็นเรื่องเดียวกัน
         แต่เกณฑ์ต่างกัน (0.25 กับ 0.40 มม.) ข้อความจึงเปลี่ยนตามชนิดรหัส -->
    <div id="bcQuality" class="rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/60">
      <div class="flex items-center justify-between">
        <span id="bcXlabel" class="text-slate-500 dark:text-slate-400">ความกว้างแท่งที่แคบที่สุด</span>
        <span id="bcXdim" class="num font-semibold text-slate-700 dark:text-slate-200">—</span>
      </div>
      <p class="mt-1 text-slate-400" id="bcXnote">เครื่องอ่านทั่วไปต้องการอย่างน้อย 0.25 มม.</p>
    </div>

    <div id="bcWarn" hidden
         class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
      <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
      <span id="bcWarnText">ฉลากขนาดนี้ทำให้แท่งบาร์โค้ดแคบกว่าเกณฑ์ เครื่องอ่านอาจยิงไม่ติด
            แนะนำให้เลือกดวงที่ใหญ่ขึ้น หรือปิดการแสดงชื่อสินค้าเพื่อคืนพื้นที่ให้บาร์โค้ด</span>
    </div>

    <div class="mt-auto flex flex-col gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
      <button type="button" id="btnPrint" class="<?php echo $btn_main; ?>" disabled>
        <i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>
        <span>พิมพ์ฉลาก</span>
      </button>
      <button type="button" id="btnSheetPng" class="<?php echo $btn_soft; ?>" disabled
              title="รวมฉลากทุกดวงเป็นไฟล์รูปเดียว">
        <i data-lucide="image-down" class="h-4 w-4" aria-hidden="true"></i>
        <span>ดาวน์โหลดทั้งแผ่นเป็น PNG</span>
      </button>
      <p class="text-center text-xs text-slate-400">ดาวน์โหลดทีละดวงได้จากปุ่มมุมขวาบนของแต่ละฉลาก</p>
    </div>
  </section>
</div>

<!-- ฉากหลังทึบตอนแผงกาง ต้องอยู่ในกรอบเดียวกับแผง ห้ามย้ายไป body
     (ดูเหตุผลใน assets/js/product-barcode.js ที่ initSide) -->
<div id="bcBackdrop" hidden class="bc-backdrop"></div>


<script>
window.BARCODE_PAGE = {
    products: <?php echo pb_json($products); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/barcode.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/qrcode.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/qr.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/product-barcode.js', $ASSET_BASE); ?>"></script>
