<?php
/* ============================================================
 *  pages/product_dept.php — สินค้าที่แต่ละหน่วยงานเบิกได้
 *
 *  เลือกหน่วยงานหนึ่ง แล้วติ๊กว่าหน่วยงานนั้นเบิกสินค้าตัวใดได้บ้าง
 *  สินค้าหนึ่งตัวอยู่ได้หลายหน่วยงาน (ตารางเชื่อม tb_product_department)
 *
 *  ---------------------------------------------------------------
 *  ทำไมเป็นหน้าแยก ไม่ใช่คอลัมน์ในทะเบียนสินค้า
 *  ---------------------------------------------------------------
 *  ถ้าทำเป็นช่องเลือกหลายค่าในตารางสินค้า จะต้องเปิดรายการหน่วยงาน 62 รายการ
 *  ในช่องแคบ ๆ ของตาราง แล้วทำซ้ำทีละแถวจนครบ 313 รายการ
 *
 *  งานจริงมาจากฝั่งตรงข้าม: "ฝ่ายจัดซื้อเบิกอะไรได้บ้าง" ซึ่งตอบทีเดียว
 *  ได้ทั้งฝ่ายในหน้าจอเดียว จึงจัดหน้าให้ตรงกับวิธีที่คนคิดเรื่องนี้จริง ๆ
 *
 *  ไฟล์นี้ไม่มี SQL — ข้อมูลมาจาก api/product_dept.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$input = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
?>

<div class="pd-wrap flex h-full min-h-0 flex-col gap-4">

  <!-- ===== แถบหัวเรื่อง : เลือกหน่วยงาน + ตัวเลขสรุป + ปุ่ม =====
       ยุบสามแถบเดิม (เลือกหน่วยงาน / ตัวเลข / คำอธิบาย) มาไว้แถวเดียว

       เหตุผล: วัดของเดิมแล้วส่วนหัวกินพื้นที่ 434px จาก 872px
       เหลือให้รายการสินค้าแค่ 417px = เห็นพร้อมกัน 5 รายการจากทั้งหมด 313
       ซึ่งทำให้งานหลักของหน้านี้ (ไล่ดูแล้วติ๊ก) ทำได้ช้ามาก
       และช่องเลือกหน่วยงานถูกยืดเป็น 1,280px สำหรับข้อความบรรทัดเดียว -->
  <div class="pd-bar flex-none">

    <!-- id ที่กล่องครอบ ไม่ใช่ที่ <select> โดยตรง
         เพราะ select-search.js ซ่อน select ตัวจริงแล้ววาดช่องค้นหาของตัวเองแทน
         คู่มือการใช้งานที่ชี้ไปที่ select จึงมองไม่เห็นและถูกข้ามไป -->
    <div class="pd-bar-dept" id="pdDeptBox">
      <select id="pdDept" class="<?php echo $input; ?>">
        <option value="">— เลือกหน่วยงาน —</option>
      </select>
    </div>

    <!-- ตัวเลขสรุปแบบชิป — ให้ข้อมูลเท่าไทล์ใหญ่เดิมแต่สูง 24px แทน 78px
         ซ่อนไว้จนกว่าจะเลือกหน่วยงาน เพราะก่อนหน้านั้นยังไม่มีตัวเลขให้พูดถึง -->
    <ul id="pdStats" hidden class="pd-chips">
      <li class="pd-chip is-total">
        <span class="pd-chip-num num" id="pdStatTotal">0</span> เบิกได้
      </li>
      <li class="pd-chip">
        <span class="pd-chip-num num" id="pdStatCommon">0</span> ของกลาง
      </li>
      <li class="pd-chip is-own">
        <span class="pd-chip-num num" id="pdStatOwn">0</span> ติ๊กไว้ให้หน่วยงานนี้
      </li>
    </ul>

    <div class="pd-bar-actions">
      <button type="button" id="pdReset" class="st-btn" disabled>
        <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
        <span class="max-xl:hidden">ยกเลิกการแก้ไข</span>
      </button>
      <button type="button" id="pdSave" class="st-btn-primary" disabled>
        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
        บันทึก
      </button>
    </div>

    <!-- กติกาที่เปลี่ยนความหมายของทุกอย่างบนจอ ต้องอยู่ในสายตาเสมอ
         แต่ยาวแค่บรรทัดเดียวก็พอ ของเดิมเป็นกล่องพับที่กินอีก 43px -->
    <p class="pd-bar-hint">
      <i data-lucide="info" class="h-3.5 w-3.5" aria-hidden="true"></i>
      <span><b>ไม่ติ๊ก = ของกลาง</b> ทุกหน่วยงานเบิกได้ · <b>ติ๊ก = จำกัด</b> ให้เฉพาะหน่วยงานที่ติ๊กไว้ · สินค้าตัวเดียวติ๊กให้<b>หลายหน่วยงาน</b>ได้ ทำได้โดยเลือกอีกหน่วยงานแล้วติ๊กตัวเดิมซ้ำ</span>
    </p>
  </div>

  <!-- ===== ยังไม่ได้เลือกหน่วยงาน ===== -->
  <div id="pdEmpty" class="flex flex-1 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 px-6 py-16 text-center dark:border-slate-700">
    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
      <i data-lucide="building-2" class="h-7 w-7" aria-hidden="true"></i>
    </span>
    <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-300">เลือกหน่วยงานด้านบนก่อน</p>
    <p class="mt-1 text-xs text-slate-400">แล้วจึงติ๊กสินค้าที่ต้องการให้หน่วยงานนั้นเบิกได้</p>
  </div>

  <!-- ===== สองฝั่ง : ทั้งหมด | ที่เลือก =====
       ใช้คลาสชุดเดียวกับหน้าทำรายการเบิก (.tf-wrap / .tf-pane / .tf-pane-head /
       .tf-filters / .tf-badge / .tf-clear) ซึ่งมีสไตล์อยู่แล้วใน app.css

       รอบแรกผมตั้งชื่อคลาสเองเป็น .tf-head / .tf-tools / .tf-search / .tf-title
       ซึ่งไม่มีอยู่จริงสักตัว ส่วนหัวและแถบกรองของหน้านี้จึงไม่มีสไตล์เลย
       กลายเป็นข้อความกับช่องกรอกเปล่า ๆ วางซ้อนกันคนละแบบกับหน้าอื่นในระบบ -->
  <div id="pdBody" hidden class="tf-wrap min-h-0 flex-1">

    <!-- ===== แท็บสลับสองฝั่ง — โผล่เฉพาะจอเล็ก (ดู .tf-tabs ใน app.css) =====
         บนมือถือสองฝั่งต้องเรียงบนล่าง ฝั่งที่ติ๊กไว้จึงไปอยู่ใต้รายการ 335 ตัว
         ซึ่งเลื่อนไม่ถึงกันจริง ๆ แท็บทำให้กดถึงได้ทันทีและเหลือกล่องที่เลื่อนได้ชั้นเดียว

         ตัวเลขบนแท็บซ้ำกับป้ายในหัวของแต่ละฝั่งโดยตั้งใจ เพราะหัวฝั่งที่ไม่ได้เลือก
         ถูกซ่อนอยู่ ตัวเลข "ติ๊กไว้กี่รายการ" จึงต้องอยู่บนแท็บด้วย ไม่งั้นต้องสลับไปดู -->
    <div class="tf-tabs" role="tablist" aria-label="สลับฝั่งที่แสดง">
      <button type="button" class="tf-tab is-active" data-pane="src"
              role="tab" aria-selected="true" aria-controls="pdPaneSrc">
        <span class="tf-tab-text">สินค้าทั้งหมด</span>
        <span class="tf-tab-num" id="pdTabSrc">0</span>
      </button>
      <button type="button" class="tf-tab" data-pane="dst"
              role="tab" aria-selected="false" aria-controls="pdPaneDst" tabindex="-1">
        <span class="tf-tab-text">ที่ติ๊กไว้</span>
        <span class="tf-tab-num is-on" id="pdTabDst">0</span>
      </button>
    </div>

    <!-- ---------- ฝั่งซ้าย : สินค้าทั้งหมด ---------- -->
    <section id="pdPaneSrc" class="tf-pane tf-pane-src" aria-labelledby="pdLeftHead">
      <div class="tf-pane-head">
        <h3 id="pdLeftHead" class="tf-pane-title">
          สินค้าทั้งหมด
          <span class="tf-badge"><span id="pdLeftCount">0</span> รายการ</span>
        </h3>
        <button type="button" id="pdAll" class="tf-clear">เลือกที่เห็นทั้งหมด</button>
      </div>

      <div class="tf-filters">
        <div class="relative">
          <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
            <i data-lucide="search" class="h-4 w-4"></i>
          </span>
          <input type="search" id="pdSearch" autocomplete="off"
                 placeholder="ค้นรหัส ชื่อสินค้า หรือหมวดหมู่"
                 class="<?php echo $input; ?> pl-10">
        </div>

        <div class="tf-filters-row tf-filters-row3">
          <select id="pdCategory" class="<?php echo $input; ?>"
                  data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่"
                  aria-label="กรองตามหมวดหมู่หลัก">
            <option value="">ทุกหมวดหมู่</option>
          </select>

          <!-- หมวดหมู่ย่อยเป็นชั้นที่คนใช้จริงคิดถึงตอนไล่ติ๊ก เพราะรหัสสินค้า
               ถูกสร้างจากรหัสหมวดย่อย ของที่อยู่ด้วยกันจึงมักถูกตั้งค่าพร้อมกัน

               ตอนนี้มีสองหมวดหลักกับห้าหมวดย่อย เลือก "วัสดุสิ้นเปลือง" อย่างเดียว
               ยังเหลือ 113 รายการ แต่เจาะถึง "รายการยารักษาโรค" เหลือ 15
               ซึ่งเป็นขนาดที่ไล่ติ๊กจนจบได้ในรอบเดียว

               รายการในช่องนี้ตัดให้เหลือเฉพาะหมวดย่อยของหมวดหลักที่เลือกไว้
               (ดู fillSub() ใน product-dept.js) ถ้ายังไม่เลือกหมวดหลักจะเห็นครบ
               ทุกอัน โดยมีชื่อหมวดหลักเป็นบรรทัดรอง เพราะชื่อหมวดย่อยซ้ำกันข้ามหมวดได้
               อย่าง "รายการหมึก" ที่มีอยู่ทั้งสองหมวดหลัก -->
          <select id="pdSubCategory" class="<?php echo $input; ?>"
                  data-search data-search-placeholder="พิมพ์ชื่อหมวดหมู่ย่อย"
                  aria-label="กรองตามหมวดหมู่ย่อย">
            <option value="">ทุกหมวดหมู่ย่อย</option>
          </select>

          <!-- กรองตามสถานะการจอง — คำถามที่ผู้ดูแลระบบถามจริงตอนตั้งค่า
               เช่น "ของกลางเหลืออะไรบ้าง" หรือ "ฝ่ายอื่นจองอะไรไปแล้ว"
               ซึ่งตอบไม่ได้เลยถ้ามีแค่ช่องค้นหากับหมวดหมู่ -->
          <select id="pdState" class="<?php echo $input; ?>" aria-label="กรองตามสถานะ">
            <option value="">ทุกสถานะ</option>
            <option value="common">เฉพาะของกลาง</option>
            <option value="mine">เฉพาะที่ติ๊กไว้</option>
            <option value="other">ที่หน่วยงานอื่นใช้อยู่</option>
          </select>
        </div>
      </div>

      <div id="pdLeft" class="tf-list" role="list"></div>
    </section>

    <!-- ---------- ฝั่งขวา : ที่หน่วยงานนี้เบิกได้ ---------- -->
    <section id="pdPaneDst" class="tf-pane tf-pane-dst" aria-labelledby="pdRightHead">
      <div class="tf-pane-head">
        <h3 id="pdRightHead" class="tf-pane-title">
          สินค้าที่หน่วยงานนี้เบิกได้
          <span class="tf-badge is-on"><span id="pdCount">0</span> รายการ</span>
        </h3>
        <button type="button" id="pdClear" class="tf-clear">ล้างทั้งหมด</button>
      </div>

      <!-- ช่องค้นหาฝั่งขวาแก้สองเรื่องพร้อมกัน:
           1. หน่วยงานที่ผูกไว้เป็นร้อยรายการ หาตัวที่จะเอาออกไม่เจอถ้าไม่มีช่องค้น
           2. ฝั่งขวาไม่มีแถบเครื่องมือ รายการสองฝั่งจึงเริ่มไม่ตรงกัน
              (วัดได้ ซ้าย y=335 ขวา y=285) ซึ่งดูเหมือนหน้าเพี้ยน ไม่ใช่ตั้งใจ -->
      <div class="tf-filters">
        <div class="relative">
          <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-slate-400" aria-hidden="true">
            <i data-lucide="search" class="h-4 w-4"></i>
          </span>
          <input type="search" id="pdSearchRight" autocomplete="off"
                 placeholder="ค้นในรายการที่เลือกไว้"
                 class="<?php echo $input; ?> pl-10">
        </div>

        <!-- แถวว่างความสูงเท่ากับ .tf-filters-row ฝั่งซ้าย เพื่อให้รายการทั้งสองฝั่ง
             เริ่มที่ระดับเดียวกัน ฝั่งขวามีตัวกรองน้อยกว่าโดยธรรมชาติ

             ใส่ตัวคั่นให้ครบเท่าจำนวนช่องกรองฝั่งซ้าย (3 ช่อง) และใช้คลาสกริดตัวเดียวกัน
             ความสูงสองฝั่งจึงเท่ากันเองทุกขนาดจอ รวมถึงตอนที่ช่องกรองตกลงไปเป็นสองแถว
             ถ้ากำหนดความสูงตายตัวไว้ที่เดียว พอฝั่งซ้ายตกแถวจะเหลื่อมกันทันที -->
        <div class="tf-filters-row tf-filters-row3 pd-mirror" aria-hidden="true">
          <span class="pd-spacer"></span>
          <span class="pd-spacer"></span>
          <span class="pd-spacer"></span>
        </div>
      </div>

      <div id="pdRight" class="tf-list tf-list-picked" role="list"></div>
    </section>
  </div>
</div>

<!-- ช่องเลือกหน่วยงานมี 62 รายการ ต้องพิมพ์ค้นได้ ไม่งั้นต้องเลื่อนหาทีละอัน -->
<script src="<?php echo asset_url('assets/js/select-search.js', $ASSET_BASE); ?>"></script>

<!-- แท็บสลับสองฝั่งบนจอเล็ก — ใช้ร่วมกับหน้าทำรายการเบิก -->
<script src="<?php echo asset_url('assets/js/tf-tabs.js', $ASSET_BASE); ?>"></script>
<script>
window.PRODUCTDEPT = {
    endpoint:  'api/product_dept.php',
    csrfToken: <?php echo app_json($csrf_token); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/product-dept.js', $ASSET_BASE); ?>"></script>
