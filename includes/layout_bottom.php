<?php
/* ============================================================
 *  includes/layout_bottom.php
 *  ปิดท้ายทุกหน้า — ปิดพื้นที่เนื้อหา + โหลดสคริปต์ร่วม
 *  ใช้คู่กับ includes/layout_top.php เสมอ
 *
 *  จำนวน div ที่ต้องปิดขึ้นกับโหมดของหน้า ($PAGE_FULL)
 *  จึงต้องเช็คตัวแปรเดียวกับที่ layout_top.php ใช้เปิด
 * ============================================================ */
?>

<?php if (!empty($PAGE_FULL)): ?>
  </div><!-- /กรอบเนื้อหาเต็มจอ -->
<?php else: ?>
    </div><!-- /container -->
  </div><!-- /pageScroll -->
<?php endif; ?>
</main>

<script src="<?php echo asset_url('assets/js/app.js', $ASSET_BASE); ?>"></script>

<!-- สคริปต์ส่วนกลางที่ทำงานทุกหน้า — ต้องมาหลัง app.js เพราะใช้ตัวช่วยจากที่นั่น
     ทั้งสองตัวอ่านค่าเริ่มต้นจาก window.APP_CTX ที่ layout_top.php ประกาศไว้ -->
<!-- ต้องมาก่อน app-notify.js เพราะตัวนั้นเรียก window.DesktopNoti ตั้งแต่ตอนวาดแถบล่างของกล่องกระดิ่ง -->
<script src="<?php echo asset_url('assets/js/app-desktop-noti.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/app-notify.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/app-guide.js', $ASSET_BASE); ?>"></script>
</body>
</html>
<?php
/* ส่งบัฟเฟอร์ที่ main.php เปิดไว้ออกไปให้เบราว์เซอร์ */
if (ob_get_level() > 0) {
    ob_end_flush();
}
