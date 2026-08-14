<?php
/* ============================================================
 *  pages/notifications.php — การแจ้งเตือนทั้งหมดของตัวเอง
 *
 *  กล่องกระดิ่งบนแถบหัวตอบคำถาม "มีอะไรใหม่ไหม" ซึ่งเป็นเหตุผลที่คนกดปุ่มนั้น
 *  แต่ตอบไม่ได้ว่า "เรื่องเมื่อสองสัปดาห์ก่อนไปไหน" เพราะกล่องนั้นสูงครึ่งจอ
 *  ค้นไม่ได้ และกรองไม่ได้
 *
 *  หน้านี้คือที่ที่กว้างพอสำหรับคำถามแบบหลัง
 *
 *  ---------------------------------------------------------------
 *  เห็นเฉพาะของตัวเอง ไม่มีข้อยกเว้น
 *  ---------------------------------------------------------------
 *  ทุกคำสั่งส่งผ่าน api/notify.php ซึ่งบังคับ username ของคนที่ล็อกอินอยู่
 *  ในทุก query อยู่แล้ว แม้แต่ผู้ดูแลระบบสูงสุดก็ดูของคนอื่นทางนี้ไม่ได้
 *  จึงไม่ต้องมีตัวกรอง "ของใคร" ให้เลือก
 *
 *  ไฟล์นี้ไม่มี SQL — ข้อมูลมาจาก api/notify.php
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/notify.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}

$nt_total  = notify_total_count($sess_username);
$nt_unread = notify_unread_count($sess_username);

/* ใช้ app_json() ตัวกลางจาก includes/head_assets.php ซึ่งหนี < เป็น \u003C ให้ด้วย
   กันข้อมูลที่มี "</script>" ปนมาแล้วปิดบล็อกสคริปต์กลางคัน */

/* กรอบสูงตามเนื้อหา ไม่ใช่สูงเต็มจอเสมอ
   ใช้ max-h-full แทน flex-1 — มีแค่สองรายการก็ได้กรอบเตี้ย ๆ พอดีเนื้อหา
   ไม่ใช่กรอบสูงเท่าจอที่ว่างเปล่าอยู่ค่อนใบ ส่วนตอนรายการยาว
   กรอบจะขยายจนเต็มจอแล้วเลื่อนข้างในตามเดิม (ดู .nt-scroll ใน app.css) */
$panel = 'flex min-h-0 max-h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
?>

<!-- ===== โครงหน้า =====
     กรอบนอกคุมความสูงให้เลื่อนอยู่ในตัวเอง (หน้านี้ตั้ง full => true)
     ส่วนเนื้อหาข้างในถูกจำกัดความกว้างไว้ ไม่ปล่อยให้ยืดเต็มจอ
     เพราะการแจ้งเตือนคือข้อความที่ต้องอ่าน ไม่ใช่ตารางข้อมูล
     บรรทัดที่ยาว 1,500 พิกเซลทำให้สายตาหาจุดขึ้นบรรทัดใหม่ไม่เจอ
     และทำให้ข้อความสั้น ๆ ลอยอยู่กลางพื้นที่ว่างมหาศาลทางขวา -->
<div class="<?php echo $panel; ?>">

  <!-- ===== หัวหน้า ===== -->
  <div class="nt-head">
    <div class="nt-head-inner">

      <div class="nt-head-main">
        <span class="nt-head-icon" aria-hidden="true">
          <i data-lucide="bell" class="h-5 w-5"></i>
        </span>
        <div class="min-w-0">
          <h2 class="nt-head-title">การแจ้งเตือน</h2>
          <p class="nt-head-sub">
            ทั้งหมด <b id="ntTotal" class="num"><?php echo (int)$nt_total; ?></b> รายการ<span
              id="ntUnreadWrap" class="<?php echo $nt_unread > 0 ? '' : 'hidden'; ?>"
              > · ยังไม่ได้อ่าน <b id="ntUnread" class="num nt-head-unread"><?php echo (int)$nt_unread; ?></b></span>
          </p>
        </div>
      </div>

      <div class="nt-head-acts">
        <!-- กรองที่ฝั่งเซิร์ฟเวอร์ ไม่ใช่ซ่อนแถวที่โหลดมาแล้ว
             เพราะโหลดมาทีละ 10 การซ่อนจะได้ผลลัพธ์ที่ไม่ครบโดยที่ผู้ใช้ไม่รู้ตัว -->
        <button type="button" id="ntFilter" data-mode="all" class="nt-btn">
          <i data-lucide="filter" class="h-4 w-4" aria-hidden="true"></i>
          <span class="btn-label">แสดงทั้งหมด</span>
        </button>

        <button type="button" id="ntReadAll" class="nt-btn"
                <?php echo $nt_unread > 0 ? '' : 'disabled'; ?>>
          <i data-lucide="check-check" class="h-4 w-4" aria-hidden="true"></i>
          <span>อ่านทั้งหมด</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ===== รายการ =====
       เลื่อนในกรอบนี้กรอบเดียว หน้าเบื้องหลังไม่ขยับ
       โหลดทีละ 10 รายการ เลื่อนถึงท้ายแล้วโหลดต่อ

       ท้ายรายการ (#ntFoot) ย้ายเข้ามาอยู่ในกรอบที่เลื่อนได้ด้วย
       ของเดิมตรึงไว้ที่ก้นจอ พอมีแค่สองรายการ ข้อความ "แสดงครบทุกรายการแล้ว"
       จะไปอยู่ห่างจากรายการสุดท้ายเกือบทั้งจอ เหมือนไม่เกี่ยวข้องกัน -->
  <div id="ntScroll" class="nt-scroll nav-scroll">
    <div class="nt-wrap">

      <!-- สวิตช์แจ้งเตือนนอกหน้าเว็บ — มีทั้งที่นี่และในกล่องกระดิ่ง
           ตัวข้อความอธิบายกับสถานะเขียนโดย assets/js/notifications.js
           ที่นี่มีที่มากกว่า จึงอธิบายข้อจำกัดได้ครบกว่าในกล่องกระดิ่ง

           ย้ายลงมาจากแถบเครื่องมือ เพราะมันเป็น "การตั้งค่า" ไม่ใช่ "การกระทำ"
           อยู่ปนกับปุ่มกรองและปุ่มอ่านทั้งหมดแล้วอ่านออกมาเป็นชุดเดียวกัน
           ทั้งที่กดแล้วผลต่างกันคนละเรื่อง -->
      <div id="ntDesk" class="nt-desk"></div>

      <div id="ntList"></div>
      <div id="ntFoot"></div>
    </div>
  </div>
</div>

<script>
window.NOTIPAGE = {
    endpoint:  'api/notify.php',
    csrfToken: <?php echo app_json($_SESSION['csrf_token']); ?>,
    total:     <?php echo (int)$nt_total; ?>,
    unread:    <?php echo (int)$nt_unread; ?>
};
</script>
<script src="<?php echo asset_url('assets/js/notifications.js', $ASSET_BASE); ?>"></script>
