-- ============================================================
--  010_cost_tracking_rollback.sql — ย้อนไฟล์ 010 ถอดคอลัมน์ต้นทุนทั้งหมด
--  ข้อมูลราคาที่กรอกไว้จะหายไปด้วย กู้ไม่ได้
-- ============================================================
ALTER TABLE `tb_doc_item`       DROP COLUMN `unit_cost`, DROP COLUMN `total_cost`;
ALTER TABLE `tb_stock_balance`  DROP COLUMN `avg_cost`;
ALTER TABLE `tb_stock_movement` DROP KEY `idx_move_type_date`;
ALTER TABLE `tb_stock_movement` DROP COLUMN `unit_cost`, DROP COLUMN `total_cost`, DROP COLUMN `avg_cost_after`;