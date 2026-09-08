-- ============================================================
--  055_guide_stock_adjust_rollback.sql
--  เอาคู่มือของหน้า "บันทึกปรับยอด" และ "ประวัติการปรับยอด" ออก
--
--  ขั้นตอนถูกลบตามไปเองด้วย foreign key fk_step_guide ที่ตั้ง ON DELETE CASCADE
--
--  ถอนแล้วปุ่มเครื่องหมายคำถามบนแถบบนจะหายไปเฉพาะสองหน้านี้
--  ตัวหน้าปรับยอดและประวัติยังทำงานเหมือนเดิมทุกอย่าง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/055_guide_stock_adjust_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` IN ('adjust_new', 'adjust_history');

SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;
