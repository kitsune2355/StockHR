-- ============================================================
--  026_guide_unit_and_barcode_rollback.sql
--  ย้อนคู่มือกลับไปเป็นก่อนเพิ่มแท็บหน่วยนับและหน้าบาร์โค้ด
--
--  ทำสามอย่าง:
--    1) ลบคู่มือหน้าบาร์โค้ดทั้งชุด
--    2) ลบสามขั้นที่แทรกเข้าไปในคู่มือหมวดหมู่ แล้วเลื่อนลำดับที่เหลือกลับ
--    3) คืนชื่อคู่มือหมวดหมู่เป็นชื่อเดิม
--
--  ลบด้วย target_selector ไม่ใช่ด้วยเลขลำดับ เพราะถ้ามีใครไปแทรกขั้นอื่น
--  เพิ่มระหว่างนี้ การลบตามเลขลำดับจะไปโดนขั้นที่ไม่เกี่ยวข้อง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/026_guide_unit_and_barcode_rollback.sql
-- ============================================================

SET NAMES utf8;

-- 1) คู่มือหน้าบาร์โค้ด
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'product_barcode';

DELETE FROM `tb_guide` WHERE `page_key` = 'product_barcode';

-- 2) สามขั้นที่แทรกในคู่มือหมวดหมู่
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'product_category'
  AND s.`target_selector` IN ('#pcTab-unit', '.tabulator-col[tabulator-field="used"]', '#gridSearch');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 3
WHERE g.`page_key` = 'product_category' AND s.`step_order` >= 5;

-- 3) ชื่อคู่มือเดิม
UPDATE `tb_guide`
SET `title` = 'หมวดหมู่สินค้า',
    `description` = 'ตั้งค่าหมวดหมู่หลักและหมวดหมู่ย่อยของสินค้า'
WHERE `page_key` = 'product_category';
