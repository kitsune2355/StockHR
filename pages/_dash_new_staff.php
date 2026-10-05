<?php
/* ============================================================
 *  pages/_dash_new_staff.php — ไทล์ "พนักงานใหม่" ของหน้าภาพรวม
 *
 *  include จาก pages/dashboard.php ใช้ $ns จาก dashboard_new_staff()
 *  ($ns = null -> ไม่วาดอะไร) และ db_esc / db_can / db_ns_when ของหน้านั้น
 *
 *  กว้างเต็มแถวเสมอ (.bn-ns) จึงไม่ไปรบกวนการนับช่องของไทล์อื่น
 *  ที่คำนวณไว้ใน dashboard.php (แถวเต็มพอดี 4 ช่อง)
 *
 *  ซ้าย  ตัวเลขสี่ช่อง ตอบว่า "มีกี่ใบค้างอยู่ขั้นไหน"
 *  ขวา   รายชื่อคนที่ใกล้เริ่มงาน ตอบว่า "ใครจะมาถึงแล้วของยังไม่พร้อม"
 * ============================================================ */

if (!isset($ns) || $ns === null) { return; }

$ns_list_ok = db_can('new_staff_list');
$ns_new_ok  = db_can('new_staff_request');

$ns_pill = array(
    'waiting'  => 'is-wait',
    'approved' => 'is-ok',
    'issued'   => 'is-info'
);

$ns_stats = array(
    array('num' => $ns['waiting'],    'cap' => 'รออนุมัติ',            'icon' => 'clock',          'tone' => ''),
    array('num' => $ns['ready'],      'cap' => 'อนุมัติแล้ว รอจ่ายของ', 'icon' => 'package-minus',  'tone' => ''),
    array('num' => $ns['at_risk'],    'cap' => 'เริ่มงานใน 7 วัน ของยังไม่พร้อม', 'icon' => 'alarm-clock', 'tone' => $ns['at_risk'] > 0 ? ' is-warn' : ''),
    array('num' => $ns['this_month'], 'cap' => 'ใบเบิกเดือนนี้',        'icon' => 'calendar-days',  'tone' => '')
);
?>
<!-- ---------- ไทล์กว้าง : พนักงานใหม่ ---------- -->
<section class="bn-tile bn-ns" id="bnNewStaff">
  <div class="bn-tile-head">
    <h3 class="bn-ns-title">
      <i data-lucide="user-plus" class="h-4 w-4" aria-hidden="true"></i>
      พนักงานใหม่
    </h3>
    <?php if ($ns_new_ok) { ?>
    <a href="main.php?page=new_staff_request" class="bn-more">+ ทำใบเบิก</a>
    <?php } ?>
    <?php if ($ns_list_ok) { ?>
    <a href="main.php?page=new_staff_list" class="bn-more<?php echo $ns_new_ok ? ' bn-more-next' : ''; ?>">ดูทั้งหมด</a>
    <?php } ?>
  </div>

  <div class="bn-ns-body">

    <ul class="bn-ns-stats">
      <?php foreach ($ns_stats as $s) { ?>
      <li class="bn-ns-stat<?php echo $s['tone']; ?>">
        <i data-lucide="<?php echo db_esc($s['icon']); ?>" class="bn-ns-stat-ico h-4 w-4" aria-hidden="true"></i>
        <span class="bn-ns-stat-num num"><?php echo number_format($s['num']); ?></span>
        <span class="bn-ns-stat-cap"><?php echo db_esc($s['cap']); ?></span>
      </li>
      <?php } ?>
    </ul>

    <div class="bn-ns-up">
      <p class="bn-ns-up-cap">
        เริ่มงานเร็ว ๆ นี้
        <?php if ($ns['upcoming_total'] > count($ns['upcoming'])) { ?>
        <span class="bn-ns-up-more">แสดง <?php echo count($ns['upcoming']); ?> จาก <?php echo number_format($ns['upcoming_total']); ?> คน</span>
        <?php } ?>
      </p>

      <?php if (count($ns['upcoming']) === 0) { ?>
      <p class="bn-blank">ไม่มีพนักงานใหม่ที่กำลังจะเริ่มงาน</p>
      <?php } else { ?>
      <ul class="bn-ns-list">
        <?php foreach ($ns['upcoming'] as $u) {
            $sub = dashboard_join($u['position'], $u['department']);
            $ts  = strtotime($u['start']);
        ?>
        <li>
          <<?php echo $ns_list_ok ? 'a href="main.php?page=new_staff_list"' : 'div'; ?>
             class="bn-ns-row<?php echo $u['urgent'] ? ' is-urgent' : ''; ?>"
             title="<?php echo db_esc($u['doc_no'] . ' · ' . $u['name'] . ($sub !== '' ? ' · ' . $sub : '')); ?>">
            <span class="bn-ns-date">
              <span class="bn-ns-day num"><?php echo date('j', $ts); ?></span>
              <span class="bn-ns-mon"><?php echo db_esc(thai_date_format($u['start'], 'M', '')); ?></span>
            </span>
            <span class="bn-ns-who">
              <span class="bn-ns-name"><?php echo db_esc($u['name']); ?></span>
              <span class="bn-ns-sub"><?php echo db_esc($sub !== '' ? $sub : $u['doc_no']); ?></span>
            </span>
            <span class="bn-ns-when<?php echo $u['urgent'] ? ' is-urgent' : ''; ?>"><?php echo db_esc(db_ns_when($u['days'])); ?></span>
            <span class="doc-pill <?php echo isset($ns_pill[$u['status']]) ? $ns_pill[$u['status']] : ''; ?>">
              <?php echo db_esc($u['status'] === 'issued' ? 'ได้ของแล้ว' : $u['status_label']); ?>
            </span>
          </<?php echo $ns_list_ok ? 'a' : 'div'; ?>>
        </li>
        <?php } ?>
      </ul>
      <?php } ?>
    </div>
  </div>
</section>
