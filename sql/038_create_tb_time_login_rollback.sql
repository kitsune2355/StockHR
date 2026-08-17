-- ============================================================
--  038_create_tb_time_login_rollback.sql
--  ลบตารางบันทึกเวลาเข้าใช้งาน
--
--  ลบแล้วการเข้าระบบยังทำงานปกติ เพราะ checkmember.php ห่อ try/catch ไว้
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e):
--      mysql -u root -p1234 stock_hl < sql/038_create_tb_time_login_rollback.sql
-- ============================================================

DROP TABLE IF EXISTS `tb_time_login`;
