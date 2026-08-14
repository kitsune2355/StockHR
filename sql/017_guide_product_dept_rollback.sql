-- ============================================================
--  017_guide_product_dept_rollback.sql
--  เอาคู่มือของหน้า "สินค้าของหน่วยงาน" ออก
--
--  ขั้นตอนถูกลบตามไปเองด้วย foreign key fk_step_guide ที่ตั้ง ON DELETE CASCADE
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/017_guide_product_dept_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'product_dept';

SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;