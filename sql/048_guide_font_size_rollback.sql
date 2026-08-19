-- ============================================================
--  048_guide_font_size_rollback.sql
--  เอาคู่มือของหน้า "ขนาดตัวอักษร" ออก
--
--  ขั้นตอนถูกลบตามไปเองด้วย foreign key fk_step_guide ที่ตั้ง ON DELETE CASCADE
--
--  ถอนแล้วปุ่มเครื่องหมายคำถามบนแถบบนจะหายไปเฉพาะหน้านี้
--  ตัวหน้าและการตั้งค่าขนาดตัวอักษรยังทำงานเหมือนเดิมทุกอย่าง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/048_guide_font_size_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'font_size';

SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;
