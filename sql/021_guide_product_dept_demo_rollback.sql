-- ============================================================
--  021_guide_product_dept_demo_rollback.sql
--  ย้อนกลับ 021_guide_product_dept_demo.sql
--
--  ขั้นที่ 3 กับ 4 กลับไปเป็นกล่องลอยกลางจอที่ไม่ชี้ไปที่ไหน
--  และลบขั้นที่ 6 (ฝั่งขวาคือผลลัพธ์) ทิ้ง
--
--  ชุดข้อมูลตัวอย่างระหว่างเปิดคู่มืออยู่ในไฟล์ assets/js/product-dept.js
--  ไม่ได้อยู่ในฐานข้อมูล การรันไฟล์นี้จึงไม่ได้ปิดการสาธิต
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/021_guide_product_dept_demo_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '', s.`side` = 'bottom', s.`align` = 'center'
WHERE g.`page_key` = 'product_dept' AND s.`step_order` IN (3, 4);

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'product_dept' AND s.`step_order` = 6;
