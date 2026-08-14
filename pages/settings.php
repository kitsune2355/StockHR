<?php
/* ============================================================
 *  pages/settings.php — สองหน้าตั้งค่าที่ใช้ไฟล์ร่วมกัน
 *
 *    settings        อีเมลแจ้งเตือน  ค่าเชื่อมต่อ Zoho Mail + ทดสอบส่ง + คิวเมลขาออก
 *    settings_guide  คู่มือการใช้งาน  แก้คำอธิบายแต่ละขั้นตอนที่ผู้ใช้เห็น
 *
 *  สองเมนูแต่ไฟล์เดียว เพราะโครงหน้าและ assets/js/settings.js ใช้ร่วมกัน
 *  แยกไฟล์แล้วต้องแยกสคริปต์ตาม ได้โค้ดซ้ำสองชุดโดยไม่ได้อะไรกลับมา
 *  ใช้ $ACTIVE_PAGE (จาก main.php) ตัดสินว่าจะแสดงส่วนไหน
 *
 *  ไม่มี SQL — ข้อมูลมาจาก api/settings.php และ api/guide.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

/* ส่วนที่จะแสดง — เผื่อกรณีที่ไฟล์ถูก include โดยไม่ผ่าน router
   ให้ตกกลับไปที่อีเมล ซึ่งเป็นพฤติกรรมเดิมของหน้านี้ */
$st_section = (isset($ACTIVE_PAGE) && $ACTIVE_PAGE === 'settings_guide') ? 'guide' : 'mail';

if (!function_exists('st_json')) {
    function st_json($value) { return str_replace('<', '\u003C', json_encode($value)); }
}
if (!function_exists('st_esc')) {
    function st_esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
?>

<div class="st-wrap flex h-full min-h-0 flex-col gap-5">

  <!-- แถบแท็บถูกถอดออกแล้ว — แถบเมนูซ้ายทำหน้าที่นี้แทน
       ถ้าเก็บทั้งสองอย่างไว้ จะมีตัวเลือกซ้ำกันสองที่บนหน้าจอเดียว
       และเกิดกรณีที่เมนูซ้ายชี้เรื่องหนึ่งแต่แท็บชี้อีกเรื่องหนึ่ง -->

  <!-- ============================================================
       หัวข้อที่ 1 — อีเมลแจ้งเตือน
       ============================================================ -->
  <div id="tabMail" class="st-pane<?php echo ($st_section === 'mail') ? ' is-on' : ''; ?> overflow-y-auto nav-scroll">

    <!-- แจ้งสถานะเครื่อง: ต่อแบบเข้ารหัสได้ไหม ค่าตั้งครบไหม
         ขึ้นไว้บนสุดเพราะถ้าสองอย่างนี้ไม่ผ่าน กรอกอะไรลงไปก็ส่งไม่ออก -->
    <div id="envBox" hidden class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm" role="alert">
      <i data-lucide="triangle-alert" class="mt-0.5 h-5 w-5 flex-none" aria-hidden="true"></i>
      <span id="envText"></span>
    </div>

    <!-- min-w-0 ที่ลูกทุกตัวจำเป็น ไม่งั้น grid item จะไม่ยอมหดต่ำกว่าความกว้าง
         ของเนื้อหาข้างใน แล้วล้นออกนอกจอแบบเลื่อนไปดูไม่ได้ (วัดได้ 439px ในจอ 390) -->
    <div class="grid gap-5 lg:grid-cols-5">

      <!-- ---------- ฟอร์มค่าเชื่อมต่อ ---------- -->
      <section class="min-w-0 lg:col-span-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-bold text-slate-800 dark:text-slate-100">การเชื่อมต่อ Zoho Mail</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
          ระบบใช้ค่าชุดนี้ส่งอีเมลแจ้งเตือนให้ผู้อนุมัติและผู้ขอเบิก
        </p>

        <div class="mt-5 space-y-4">

          <!-- สวิตช์เปิด/ปิด -->
          <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700">
            <input type="checkbox" id="mailEnabled" class="mt-0.5 h-4 w-4 flex-none rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">เปิดใช้งานการส่งอีเมล</span>
              <span class="mt-0.5 block text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                ปิดไว้ = ระบบยังสร้างแจ้งเตือนในกระดิ่งตามปกติ แต่อีเมลจะค้างอยู่ในคิวโดยไม่ถูกส่งออก
                เปิดเมื่อไรก็ส่งของที่ค้างไว้ได้ทั้งหมด ไม่มีอะไรตกหาย
              </span>
            </span>
          </label>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0">
              <label for="smtpHost" class="st-label">โฮสต์ SMTP</label>
              <input type="text" id="smtpHost" class="st-input" placeholder="smtp.zoho.com" autocomplete="off">
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="min-w-0">
                <label for="smtpPort" class="st-label">พอร์ต</label>
                <input type="number" id="smtpPort" class="st-input num" min="1" max="65535" placeholder="465">
              </div>
              <div class="min-w-0">
                <label for="smtpSecure" class="st-label">เข้ารหัส</label>
                <select id="smtpSecure" class="st-input">
                  <option value="ssl">SSL (465)</option>
                  <option value="tls">TLS (587)</option>
                </select>
              </div>
            </div>
          </div>

          <div>
            <label for="smtpUser" class="st-label">บัญชีอีเมลที่ใช้ส่ง</label>
            <input type="email" id="smtpUser" class="st-input" placeholder="warehouse@happyland.co.th" autocomplete="off">
          </div>

          <div>
            <label for="smtpPass" class="st-label">
              รหัสผ่านแอป (App Password)
              <span id="passState" class="ml-1 text-xs font-normal text-slate-400"></span>
            </label>
            <input type="password" id="smtpPass" class="st-input" placeholder="เว้นว่างไว้ = ใช้รหัสเดิม" autocomplete="new-password" disabled readonly>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
              ต้องเป็นรหัสผ่านแอป ไม่ใช่รหัสที่ใช้เข้า Zoho ตามปกติ
              สร้างที่ Zoho Mail &rarr; Settings &rarr; Security &rarr; App Passwords
            </p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0">
              <label for="mailFrom" class="st-label">อีเมลผู้ส่งที่แสดงในหัวเมล</label>
              <input type="email" id="mailFrom" class="st-input" placeholder="เว้นว่าง = ใช้บัญชีด้านบน" autocomplete="off">
            </div>
            <div class="min-w-0">
              <label for="mailFromName" class="st-label">ชื่อผู้ส่ง</label>
              <input type="text" id="mailFromName" class="st-input" placeholder="ระบบสต็อกธุรการ Happyland">
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0">
              <label for="siteUrl" class="st-label">ที่อยู่ระบบ (ใช้ทำปุ่มในอีเมล)</label>
              <input type="text" id="siteUrl" class="st-input" placeholder="http://localhost/stock_hl/">
            </div>
            <div class="min-w-0">
              <label for="mailMaxTry" class="st-label">ลองส่งซ้ำได้กี่ครั้ง</label>
              <input type="number" id="mailMaxTry" class="st-input num" min="1" max="10">
            </div>
          </div>

          <div id="stError" hidden class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
            <i data-lucide="circle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
            <span id="stErrorText"></span>
          </div>

          <div class="flex flex-wrap gap-2 pt-1">
            <button type="button" id="btnSaveMail" class="st-btn-primary">
              <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
              บันทึกค่าตั้ง
            </button>
            <button type="button" id="btnTestMail" class="st-btn">
              <i data-lucide="send" class="h-4 w-4" aria-hidden="true"></i>
              ส่งเมลทดสอบ
            </button>
          </div>
        </div>
      </section>


      <!-- ---------- คิวเมลขาออก ---------- -->
      <section id="mailQueue" class="min-w-0 lg:col-span-2 flex flex-col rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-none items-center gap-2">
          <h2 class="min-w-0 text-base font-bold text-slate-800 dark:text-slate-100">คิวเมลขาออก</h2>
          <button type="button" id="btnReloadQueue" class="ml-auto st-icon-btn" title="โหลดใหม่" aria-label="โหลดใหม่">
            <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
          </button>
        </div>

        <p class="mt-1 flex-none text-sm text-slate-500 dark:text-slate-400">
          เมลทุกฉบับเข้าคิวก่อนเสมอ แล้วตัวส่งค่อยหยิบไป การบันทึกเอกสารจึงไม่ต้องรอเมล
        </p>

        <!-- ตัวเลขสรุปแยกตามสถานะ — จอแคบมากให้เหลือสองคอลัมน์ ไม่งั้นตัวเลขบีบจนอ่านไม่ออก -->
        <ul id="qSummary" class="mt-4 grid flex-none grid-cols-2 gap-2 min-[420px]:grid-cols-3"></ul>

        <div class="mt-4 flex flex-none flex-wrap gap-2">
          <button type="button" id="btnFlush" class="st-btn-primary">
            <i data-lucide="send-horizontal" class="h-4 w-4" aria-hidden="true"></i>
            ส่งคิวเดี๋ยวนี้
          </button>
          <button type="button" id="btnRetry" class="st-btn">
            <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
            ลองส่งฉบับที่ล้มเหลวใหม่
          </button>
        </div>

        <div id="qList" class="nav-scroll mt-4 max-h-[26rem] min-w-0 flex-1 space-y-2 overflow-y-auto"></div>
      </section>
    </div>


    <!-- ---------- วิธีตั้งให้ส่งเองอัตโนมัติ ---------- -->
    <!-- <section class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-slate-800 dark:bg-slate-900/60">
      <h2 class="flex items-center gap-2 text-base font-bold text-slate-800 dark:text-slate-100">
        <i data-lucide="clock" class="h-4 w-4" aria-hidden="true"></i>
        ให้ระบบส่งเมลเองทุกนาที
      </h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
        ปุ่ม &ldquo;ส่งคิวเดี๋ยวนี้&rdquo; ด้านบนใช้ตอนอยากส่งทันที
        ส่วนการส่งอัตโนมัติต้องตั้ง Task Scheduler ของ Windows ให้เรียกไฟล์นี้ตามรอบ
        เปิด Command Prompt แบบผู้ดูแลระบบแล้ววางคำสั่งนี้หนึ่งครั้ง
        (ตั้งให้วิ่งทุก 5 นาที ตลอด 24 ชั่วโมง)
      </p>
      <pre class="mt-3 overflow-x-auto rounded-xl bg-slate-900 px-4 py-3 text-[13px] leading-relaxed text-slate-100"><code>schtasks /create /tn "StockHL Mail Worker" /sc minute /mo 5 ^
  /tr "C:\AppServ\php5\php.exe C:\AppServ\www\stock_hl\cron\mail_worker.php" /ru SYSTEM</code></pre>
      <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
        ถ้าติดตั้ง AppServ ไว้ที่อื่น ให้แก้เส้นทางทั้งสองจุดให้ตรงกับเครื่อง
      </p>
    </section> -->
  </div>


  <!-- ============================================================
       หัวข้อที่ 2 — คู่มือการใช้งาน
       ============================================================ -->
  <div id="tabGuide" class="st-pane<?php echo ($st_section === 'guide') ? ' is-on' : ''; ?>">

    <div class="flex flex-none flex-wrap items-center gap-2 pb-3">
      <div class="min-w-0">
        <h2 class="text-base font-bold text-slate-800 dark:text-slate-100">คำอธิบายในคู่มือ</h2>
        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
          แก้ข้อความในช่องได้เลย — ผู้ใช้จะเห็นข้อความใหม่ทันทีที่เปิดคู่มือครั้งถัดไป
        </p>
      </div>

      <!-- จอแคบให้ช่องค้นหากินเต็มบรรทัด แล้วปุ่มลงมาอยู่บรรทัดถัดไป
           ถ้าตรึงกว้าง 13rem ไว้ ทั้งแถวจะดันกว้างเกินจอ -->
      <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
        <input type="search" id="guideSearch" class="st-input w-full sm:w-52" placeholder="ค้นหาในคู่มือ…">
        <button type="button" id="btnGuideReset" class="st-btn">
          <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
          ให้คู่มือเด้งใหม่ทุกหน้า
        </button>
        <button type="button" id="btnGuideSave" class="st-btn-primary" disabled>
          <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
          บันทึก (<span id="guidePending">0</span>)
        </button>
      </div>
    </div>

    <!-- รายการขั้นตอนแบบการ์ด ไม่ใช่ตาราง
         เพราะคำอธิบายเป็นย่อหน้ายาว 1-3 บรรทัด ซึ่งพิมพ์ในช่องตารางแคบ ๆ ไม่ไหว
         ต้องเห็นข้อความทั้งก้อนพร้อมกันถึงจะรู้ว่าเขียนรู้เรื่องหรือยัง -->
    <div id="guideList" class="nav-scroll min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
      <div class="px-4 py-12 text-center text-sm text-slate-400">กำลังโหลด…</div>
    </div>
  </div>
</div>


<script>
window.SETTINGS = {
    csrfToken: <?php echo st_json($csrf_token); ?>,
    apiUrl:    'api/settings.php',
    guideUrl:  'api/guide.php',
    /* บอกสคริปต์ว่าหน้านี้เปิดเรื่องไหนอยู่ จะได้โหลดเฉพาะข้อมูลของเรื่องนั้น
       เดิมดูจากแท็บที่ผู้ใช้กด แต่ตอนนี้ไม่มีแท็บแล้ว */
    section:   <?php echo st_json($st_section); ?>
};
</script>
<script src="<?php echo asset_url('assets/js/settings.js', $ASSET_BASE); ?>"></script>
