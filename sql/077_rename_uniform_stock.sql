-- ============================================================
--  077_rename_uniform_stock.sql
--  เปลี่ยนชื่อระบบจาก "ระบบสต็อก HR" เป็น "ระบบสต็อก Uniform"
--
--  ชื่อในโค้ด (หัวเมนู หน้าเข้าระบบ อีเมล) แก้ในไฟล์แล้ว
--  ไฟล์นี้แก้เฉพาะค่าที่เก็บในฐานข้อมูล คือชื่อผู้ส่งอีเมล
--  แก้เฉพาะตอนยังเป็นค่าตั้งต้นเดิม ถ้าผู้ดูแลตั้งชื่ออื่นไว้เองจะไม่ทับ
--
--  ชื่อทางเทคนิค (โฟลเดอร์/ฐานข้อมูล stock_hr) ไม่เปลี่ยน
--  เพราะลิงก์ในอีเมล ลิงก์เซ็นรับ และการตั้งค่าเซิร์ฟเวอร์อ้างชื่อนี้อยู่
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/077_rename_uniform_stock.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_app_setting`
SET `setting_value` = 'ระบบสต็อก Uniform Happyland'
WHERE `setting_key` = 'mail_from_name'
  AND `setting_value` IN ('ระบบสต็อก HR Happyland', 'ระบบสต็อก HR', 'ระบบสต็อกธุรการ Happyland', 'ระบบสต็อกธุรการ');

SELECT `setting_key`, `setting_value` FROM `tb_app_setting` WHERE `setting_key` = 'mail_from_name';
