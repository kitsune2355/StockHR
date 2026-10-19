-- ============================================================
--  001_create_tb_user_rollback.sql
--  ย้อนสิ่งที่ 001_create_tb_user.sql ทำไว้
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/001_create_tb_user_rollback.sql
--
--  คำเตือน: คำสั่งนี้ลบตารางและข้อมูลผู้ใช้ทั้งหมดทิ้ง
--  สำรองก่อนเสมอ:
--      mysqldump -u root -p1234 stock_hr tb_user > backup_tb_user_YYYYMMDD.sql
-- ============================================================

-- ตารางอื่นที่อ้าง tb_user ด้วย FK จะทำให้ DROP ไม่ผ่าน
-- ปิดการตรวจ FK ชั่วคราวเฉพาะ session นี้
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tb_user`;

SET FOREIGN_KEY_CHECKS = 1;
