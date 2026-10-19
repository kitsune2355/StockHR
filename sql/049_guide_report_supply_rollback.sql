-- ============================================================
--  049_guide_report_supply_rollback.sql
--  เอาคู่มือของหน้า "สั่งซื้อ-เบิกใช้รายเดือน" ออก
--
--  ขั้นตอนถูกลบตามไปเองด้วย foreign key fk_step_guide ที่ตั้ง ON DELETE CASCADE
--
--  ถอนแล้วปุ่มเครื่องหมายคำถามบนแถบบนจะหายไปเฉพาะหน้านี้
--  ตัวรายงานยังทำงานเหมือนเดิมทุกอย่าง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/049_guide_report_supply_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'report_supply';

SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;
