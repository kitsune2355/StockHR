-- ============================================================
--  080_new_staff_kit_department_rollback.sql — ย้อน 080
--
--  ชุดของแผนก (department_id > 0) ถูกลบทิ้ง เหลือเฉพาะชุดกลาง
--  เพราะโครงเดิมเก็บได้ชุดเดียว สินค้าเดียวกันอยู่ได้แถวเดียว
--  ต้องย้อนโค้ดก่อนรันไฟล์นี้
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_new_staff_kit` WHERE `department_id` > 0;

ALTER TABLE `tb_new_staff_kit`
  DROP KEY `uq_kit_dept_product`,
  ADD UNIQUE KEY `uq_kit_product` (`product_id`),
  DROP KEY `idx_kit_dept_active_sort`,
  ADD KEY `idx_kit_active_sort` (`is_active`, `sort_order`);

ALTER TABLE `tb_new_staff_kit`
  DROP KEY `idx_kit_product`,
  DROP COLUMN `department_id`;
