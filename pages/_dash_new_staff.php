<?php
/* ============================================================
 *  pages/_dash_new_staff.php — ไทล์ "พนักงานใหม่" ของหน้าภาพรวม
 *
 *  include จาก pages/dashboard.php ใช้ $ns จาก dashboard_new_staff()
 *  ($ns = null -> ไม่วาดอะไร) และ db_esc / db_can ของหน้านั้น
 *
 *  ตอบสองคำถาม ไม่มีเรื่องราคา:
 *    ซ้าย  "วันต่อ ๆ ไปต้องเตรียมของให้ใคร" — จัดกลุ่มตามระยะถึงวันเริ่มงาน
 *    ขวา   "จ่ายของทันก่อนวันเริ่มงานไหม" — คนที่เริ่มงานไปแล้วใน 30 วัน
 *
 *  หน้าตาใช้ชิ้นส่วนเดิมของหน้าภาพรวม จะได้เป็นชุดเดียวกับไทล์อื่น
 *      แถวตัวเลขสามช่อง  .bn-money-row     หัวข้อย่อย  .bn-money-top-cap
 *      รายการ             .bn-move*          ป้ายสถานะ   .doc-pill
 *
 *  กว้างเต็มแถวเสมอ (.bn-ns) จึงไม่ไปรบกวนการนับช่องของไทล์อื่น
 * ============================================================ */

if (!isset($ns) || $ns === null) { return; }

$ns_list_ok = db_can('new_staff_list');
$ns_tag     = $ns_list_ok ? 'a' : 'div';
$ns_href    = $ns_list_ok ? ' href="main.php?page=new_staff_list"' : '';

/* กลุ่มของรายการเตรียมของ เรียงตามความเร่งด่วน */
$ns_groups = array(
    'overdue'  => array('เลยวันเริ่มงานแล้ว ยังไม่ได้ของ', ' is-bad'),
    'today'    => array('เริ่มงานวันนี้',                  ' is-warn'),
    'tomorrow' => array('เริ่มงานพรุ่งนี้',                 ' is-warn'),
    'week'     => array('ภายใน 7 วัน',                      ''),
    'later'    => array('หลังจากนั้น',                       '')
);

/* ขั้นของใบ -> ไอคอน / สี / ป้าย */
$ns_stage = array(
    'waiting'  => array('clock',         'is-out', 'is-wait', 'รออนุมัติ'),
    'approved' => array('package-minus', 'is-sky', 'is-info', 'รอจ่ายของ'),
    'issued'   => array('check',         'is-in',  'is-ok',   'ของพร้อมแล้ว')
);

/* ผลการเทียบวันจ่ายกับวันเริ่มงาน */
$ns_result = array(
    'ontime'  => array('check',        'is-in',  'is-ok',   'ทัน'),
    'late'    => array('clock-alert',  'is-out', 'is-wait', 'ช้า'),
    'missing' => array('x',            'is-bad', 'is-bad',  'ยังไม่ได้ของ')
);

if (!function_exists('ns_result_text')) {
function ns_result_text($v)
{
    if ($v['result'] === 'ontime') {
        return ($v['gap'] === 0) ? 'จ่ายวันเดียวกับวันเริ่มงาน'
                                 : 'จ่ายก่อนวันเริ่มงาน ' . $v['gap'] . ' วัน';
    }
    if ($v['result'] === 'late') {
        return 'จ่ายช้ากว่าวันเริ่มงาน ' . $v['gap'] . ' วัน';
    }
    return 'เริ่มงานมาแล้ว ' . $v['gap'] . ' วัน ยังไม่ได้ของ';
}
}
?>
<!-- ---------- ไทล์กว้าง : พนักงานใหม่ ---------- -->
<section class="bn-tile bn-ns" id="bnNewStaff">
  <div class="bn-tile-head">
    <h3>พนักงานใหม่</h3>
    <?php if ($ns_list_ok) { ?>
    <a href="main.php?page=new_staff_list" class="bn-more">ดูใบเบิกทั้งหมด</a>
    <?php } ?>
  </div>

  <div class="bn-ns-grid">

    <!-- ============ ซ้าย : ต้องเตรียมของให้ใคร ============ -->
    <div class="bn-ns-side">
      <p class="bn-money-top-cap bn-ns-cap">
        <i data-lucide="package-open" class="h-3.5 w-3.5" aria-hidden="true"></i>
        ต้องเตรียมของให้ใคร
        <?php if ($ns['prepare_open'] > 0) { ?>
        <span class="bn-ns-count">ยังไม่พร้อม <?php echo number_format($ns['prepare_open']); ?> คน</span>
        <?php } ?>
      </p>

      <?php if (count($ns['prepare']) === 0) { ?>
      <p class="bn-blank">ยังไม่มีพนักงานใหม่ที่กำลังจะเริ่มงาน</p>
      <?php } else {
          foreach ($ns_groups as $gkey => $g) {
              $rows = array();
              foreach ($ns['prepare'] as $u) { if ($u['group'] === $gkey) { $rows[] = $u; } }
              if (count($rows) === 0) { continue; }
      ?>
      <p class="bn-ns-group<?php echo $g[1]; ?>"><?php echo db_esc($g[0]); ?></p>
      <ul class="bn-move-list">
        <?php foreach ($rows as $u) { $st = $ns_stage[$u['status']]; ?>
        <li>
          <<?php echo $ns_tag . $ns_href; ?> class="bn-move bn-ns-row"
             title="<?php echo db_esc($u['doc_no'] . ' · ' . $u['name'] . ' · ' . $st[3]); ?>">
            <span class="bn-move-time"><?php echo db_esc(thai_date_format($u['start'], 'j M', '')); ?></span>
            <span class="bn-move-mark <?php echo $st[1]; ?>">
              <i data-lucide="<?php echo $st[0]; ?>" class="h-3 w-3" aria-hidden="true"></i>
            </span>
            <span class="bn-move-body">
              <span class="bn-move-name"><?php echo db_esc($u['name']); ?></span>
              <span class="bn-move-sub"><?php echo db_esc(dashboard_join($u['position'] !== '-' ? $u['position'] : '', $u['department'])); ?></span>
            </span>
            <span class="doc-pill <?php echo $st[2]; ?>"><?php echo db_esc($st[3]); ?></span>
          </<?php echo $ns_tag; ?>>
        </li>
        <?php } ?>
      </ul>
      <?php }
          if ($ns['prepare_total'] > count($ns['prepare'])) { ?>
      <p class="bn-ns-more">และอีก <?php echo number_format($ns['prepare_total'] - count($ns['prepare'])); ?> คน</p>
      <?php }
      } ?>
    </div>

    <!-- ============ ขวา : จ่ายทันวันเริ่มงานไหม ============ -->
    <div class="bn-ns-side bn-ns-right">
      <p class="bn-money-top-cap bn-ns-cap">
        <i data-lucide="calendar-check" class="h-3.5 w-3.5" aria-hidden="true"></i>
        จ่ายของทันวันเริ่มงานไหม
        <span class="bn-ns-period"><?php echo (int)$ns['review_days']; ?> วันที่ผ่านมา</span>
      </p>

      <div class="bn-money-row">
        <div class="bn-money-cell is-in">
          <span class="bn-money-cap">ทันก่อนเริ่มงาน</span>
          <span class="bn-money-num num"><?php echo number_format($ns['ontime']); ?> คน</span>
        </div>
        <div class="bn-money-cell is-out">
          <span class="bn-money-cap">จ่ายช้า</span>
          <span class="bn-money-num num"><?php echo number_format($ns['late']); ?> คน</span>
        </div>
        <div class="bn-money-cell is-bad">
          <span class="bn-money-cap">ยังไม่ได้ของ</span>
          <span class="bn-money-num num"><?php echo number_format($ns['missing']); ?> คน</span>
        </div>
      </div>

      <?php if (count($ns['review']) === 0) { ?>
      <p class="bn-blank">ยังไม่มีพนักงานใหม่ที่เริ่มงานในช่วงนี้</p>
      <?php } else { ?>
      <ul class="bn-move-list bn-ns-review">
        <?php foreach ($ns['review'] as $v) { $rs = $ns_result[$v['result']]; ?>
        <li>
          <<?php echo $ns_tag . $ns_href; ?> class="bn-move bn-ns-row"
             title="<?php echo db_esc($v['doc_no'] . ' · ' . $v['name'] . ' · เริ่มงาน ' . thai_date($v['start'], '')
                     . ($v['issued'] !== '' ? ' · จ่ายครบ ' . thai_date($v['issued'], '') : '')); ?>">
            <span class="bn-move-time"><?php echo db_esc(thai_date_format($v['start'], 'j M', '')); ?></span>
            <span class="bn-move-mark <?php echo $rs[1]; ?>">
              <i data-lucide="<?php echo $rs[0]; ?>" class="h-3 w-3" aria-hidden="true"></i>
            </span>
            <span class="bn-move-body">
              <span class="bn-move-name"><?php echo db_esc($v['name']); ?></span>
              <span class="bn-move-sub"><?php echo db_esc(ns_result_text($v)); ?></span>
            </span>
            <span class="doc-pill <?php echo $rs[2]; ?>"><?php echo db_esc($rs[3]); ?></span>
          </<?php echo $ns_tag; ?>>
        </li>
        <?php } ?>
      </ul>
      <?php } ?>
    </div>
  </div>
</section>
