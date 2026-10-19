-- ============================================================
--  079_welfare_requested_by_user.sql
--  เก็บ "ใคร" ใน HR ที่กดส่งใบจากระบบสวัสดิการ ไม่ใช่แค่ชื่อ
--
--  ใบจากระบบสวัสดิการ (ดู 075) มีผู้ขอเป็นบัญชีระบบ hl-welfare ซึ่งไม่ใช่คนจริง
--  พอคลังจ่ายของ เมล "ของพร้อมให้รับแล้ว — เซ็นรับ" (ลิงก์ sign.php) จึงไม่ถึงใคร
--  ใบจ่ายค้างสถานะ "จ่ายแล้ว" จนคลังต้องตามเอง
--
--  ระบบสวัสดิการส่งผู้ใช้ที่ล็อกอินอยู่มาด้วย (requested_by_user / requested_by_emp_code)
--  backend/notify_events.php ใช้ requested_by_user หาบัญชีในระบบนี้ที่ชื่อผู้ใช้ตรงกัน
--  แล้วส่งลิงก์เซ็นรับให้ ถ้าไม่มีบัญชีตรงกัน (หรือใบเก่าที่ยังว่าง) ส่งให้ผู้ดูแลระบบแทน
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/079_welfare_requested_by_user.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/079_welfare_requested_by_user_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_doc_welfare`
  ADD COLUMN `requested_by_user`     VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'ชื่อผู้ใช้ HR ในระบบสวัสดิการที่กดส่ง'
      AFTER `requested_by_name`,
  ADD COLUMN `requested_by_emp_code` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'รหัสพนักงานของ HR ที่กดส่ง'
      AFTER `requested_by_user`;
