-- ============================================================
--  017_guide_product_dept.sql
--  คู่มือการใช้งานของหน้า "สินค้าของหน่วยงาน"
--
--  ทุกหน้าในระบบมีคู่มือของตัวเอง หน้าที่เพิ่มใหม่จึงต้องมีด้วย
--  ไม่งั้นปุ่มเครื่องหมายคำถามบนแถบบนจะหายไปเฉพาะหน้านี้
--  ซึ่งดูเหมือนระบบทำงานไม่สม่ำเสมอมากกว่าดูเหมือนตั้งใจ
--
--  เนื้อหาเน้นอธิบายกติกา "ไม่ติ๊ก = ของกลาง" เป็นหลัก
--  เพราะเป็นจุดเดียวที่คนเข้าใจผิดได้ง่ายที่สุดในหน้านี้:
--  เห็นหน่วยงานที่ไม่ได้ติ๊กอะไรเลย แล้วคิดว่าหน่วยงานนั้นเบิกอะไรไม่ได้
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/017_guide_product_dept.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/017_guide_product_dept_rollback.sql
-- ============================================================

SET NAMES utf8;

INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'product_dept', 'กำหนดสินค้าของแต่ละหน่วยงาน',
       'จำกัดว่าหน่วยงานไหนเบิกสินค้าตัวใดได้บ้าง', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'product_dept');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ไม่ติ๊ก = ของกลาง',
       'สินค้าที่ไม่ได้ติ๊กให้หน่วยงานใดเลย ทุกหน่วยงานเบิกได้ตามปกติ การติ๊กที่หน้านี้คือการ &quot;จำกัด&quot; ว่ารายการนั้นให้เฉพาะหน่วยงานที่เลือกไว้เบิกเท่านั้น จึงควรติ๊กเฉพาะของที่ต้องกันไว้จริง ๆ ไม่ใช่ไล่ติ๊กให้ครบทุกหน่วยงาน',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'product_dept';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#pdDept', 'เลือกหน่วยงานก่อน',
       'ตัวเลขในวงเล็บข้างชื่อคือจำนวนสินค้าที่ผูกไว้แล้ว หน่วยงานที่ไม่มีตัวเลขคือยังไม่เคยตั้งค่า ซึ่งแปลว่าเบิกได้ทุกอย่าง',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_dept';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#pdLeft', 'ติ๊กสินค้า',
       'แตะที่รายการเพื่อผูกเข้ากับหน่วยงานที่เลือกไว้ แตะซ้ำเพื่อเอาออก สินค้าตัวเดียวผูกได้หลายหน่วยงาน เช่นกระดาษที่หลายฝ่ายใช้ร่วมกันแต่ไม่อยากให้ทุกฝ่ายเบิก',
       'right', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_dept';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#pdAll', 'เลือกทีเดียวทั้งหมวด',
       'ใช้คู่กับตัวกรองหมวดหมู่หรือคำค้นด้านบน กดแล้วจะติ๊กเฉพาะรายการที่กำลังแสดงอยู่ ไม่ใช่ทั้ง 313 รายการ',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'product_dept';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#pdSave', 'บันทึก',
       'ต้องกดบันทึกทุกครั้ง เปลี่ยนหน่วยงานหรือปิดหน้าไปก่อนกด สิ่งที่ติ๊กไว้จะหายทั้งหมด หลังบันทึกแล้วหน้าทำใบเบิกจะเห็นผลทันที',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'product_dept';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT g.`page_key`, g.`title`, COUNT(s.`step_id`) AS `จำนวนขั้นตอน`
FROM `tb_guide` g
LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
WHERE g.`page_key` = 'product_dept'
GROUP BY g.`guide_id`, g.`page_key`, g.`title`;

SELECT COUNT(*) AS `คู่มือทั้งหมด` FROM `tb_guide`;
