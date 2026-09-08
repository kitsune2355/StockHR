-- ============================================================
--  057_guide_adjust_approval_rollback.sql
--  ลบคู่มือของหน้าปรับยอด (ชุดที่มีขั้นอนุมัติ) และหน้า "ตั้งค่าอนุมัติ"
--
--  ขั้นตอนถูกลบตามด้วย foreign key fk_step_guide (ON DELETE CASCADE)
--  ถ้าต้องการคู่มือชุดเดิม (แบบไม่มีขั้นอนุมัติ) ให้รัน sql/055_guide_stock_adjust.sql อีกครั้ง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/057_guide_adjust_approval_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` IN ('adjust_new', 'adjust_history', 'adjust_approval');

SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;
