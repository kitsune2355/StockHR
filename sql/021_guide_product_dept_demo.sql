-- ============================================================
--  021_guide_product_dept_demo.sql
--  ให้คู่มือหน้า "สินค้าของหน่วยงาน" ชี้ไปที่ของที่มองเห็นได้จริง
--
--  ที่มา: ขั้นที่ 3 กับ 4 ไม่มีเป้าหมาย จึงเป็นกล่องลอยกลางจอที่พูดถึง
--  "ฝั่งซ้าย" กับ "ปุ่มเลือกที่เห็นทั้งหมด" โดยไม่ได้ชี้ไปที่ไหนเลย
--  ยิ่งกว่านั้นคนที่เปิดคู่มือส่วนใหญ่ยังไม่ได้เลือกหน่วยงาน หน้าจอจึงว่าง
--  คำอธิบายเลยอ้างถึงของที่ไม่มีอยู่บนจอตอนนั้น
--
--  ตอนนี้หน้าจะใส่ชุดข้อมูลตัวอย่างให้ระหว่างเปิดคู่มือ (assets/js/product-dept.js)
--  ทั้งสองฝั่งจึงมีของให้ดูจริง ขั้นตอนพวกนี้ก็ชี้ไปที่มันได้แล้ว
--
--    ขั้น 3  ->  #pdLeft   รายการสินค้าฝั่งซ้าย
--    ขั้น 4  ->  #pdAll    ปุ่ม "เลือกที่เห็นทั้งหมด"
--
--  เพิ่มขั้นที่ 6 ให้ชี้ฝั่งขวา เพราะเป็นครึ่งที่ตอบว่า "แล้วได้อะไร"
--  ซึ่งของเดิมพูดถึงแค่ในประโยคเดียวปนกับเรื่องอื่น
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/021_guide_product_dept_demo.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/021_guide_product_dept_demo_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '#pdLeft',
    s.`side`            = 'right',
    s.`align`           = 'start'
WHERE g.`page_key` = 'product_dept' AND s.`step_order` = 3;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '#pdAll',
    s.`side`            = 'bottom',
    s.`align`           = 'end'
WHERE g.`page_key` = 'product_dept' AND s.`step_order` = 4;

-- ขั้นใหม่: ฝั่งขวาคือผลลัพธ์ ต้องมีขั้นของตัวเองเพราะเป็นครึ่งหนึ่งของหน้านี้
INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#pdRight', 'ฝั่งขวาคือผลลัพธ์',
       'ทุกอย่างที่อยู่ฝั่งนี้คือสิ่งที่หน่วยงานที่เลือกไว้จะเบิกได้หลังกดบันทึก แตะที่รายการเพื่อเอาออก ตัวเลขบนหัวบอกจำนวนที่ผูกไว้ตอนนี้ ถ้าฝั่งนี้ว่างเปล่า แปลว่าหน่วยงานนั้นเบิกได้ทุกอย่างตามปกติ',
       'left', 'start', NOW()
FROM `tb_guide` WHERE `page_key` = 'product_dept'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` s
                  WHERE s.`guide_id` = `tb_guide`.`guide_id` AND s.`target_selector` = '#pdRight');

-- ---------------------------------------------------------------
--  จัดลำดับให้ "บันทึก" เป็นขั้นสุดท้ายเสมอ
-- ---------------------------------------------------------------
--  ขั้นใหม่ถูกใส่ไว้ที่ 6 ซึ่งไปอยู่ต่อจาก "บันทึก" พอดี
--  กลายเป็นสอนให้กดบันทึกก่อน แล้วค่อยอธิบายว่าฝั่งขวาคืออะไร
--  ซึ่งกลับหัวกลับหางกับลำดับที่ผู้ใช้ทำงานจริง
--
--  สั่งด้วยการอ้าง target_selector ไม่ใช่สลับเลขกันตรง ๆ
--  จึงรันซ้ำได้โดยไม่สลับกลับไปกลับมา
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 5
WHERE g.`page_key` = 'product_dept' AND s.`target_selector` = '#pdRight';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 6
WHERE g.`page_key` = 'product_dept' AND s.`target_selector` = '#pdSave';
