-- ============================================================
--  018_guide_product_dept_fix.sql
--  แก้เป้าหมายของขั้นตอนคู่มือหน้า "สินค้าของหน่วยงาน"
--
--  ที่มา: ทดสอบแล้วคู่มือเดินได้แค่ 2 จาก 5 ขั้น เพราะ app-guide.js
--  ข้ามขั้นที่หา element ไม่เจอหรือมองไม่เห็นบนจอ (ตามที่ออกแบบไว้)
--
--  ขั้นที่ถูกข้ามและสาเหตุ:
--    #pdDept  select-search.js ซ่อน <select> ตัวจริงแล้ววาดช่องของตัวเองแทน
--             -> ย้ายไปชี้ที่กล่องครอบ #pdDeftBox ซึ่งเห็นเสมอ
--    #pdLeft  สองฝั่งยังไม่แสดงจนกว่าจะเลือกหน่วยงาน
--    #pdAll   เหตุผลเดียวกัน
--             -> เปลี่ยนเป็นกล่องกลางจอ เพราะคู่มือเด้งตอนเปิดหน้า
--                ซึ่งเป็นจังหวะที่ยังไม่มีใครเลือกหน่วยงาน
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/018_guide_product_dept_fix.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '#pdDeptBox'
WHERE g.`page_key` = 'product_dept' AND s.`target_selector` = '#pdDept';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '', s.`side` = 'over', s.`align` = 'center',
    s.`description` = 'ฝั่งซ้ายคือสินค้าทั้งหมด แตะที่รายการเพื่อผูกเข้ากับหน่วยงานที่เลือกไว้ แตะซ้ำเพื่อเอาออก ฝั่งขวาคือผลลัพธ์ที่จะบันทึก สินค้าตัวเดียวผูกได้หลายหน่วยงาน เช่นกระดาษที่หลายฝ่ายใช้ร่วมกันแต่ไม่อยากให้ทุกฝ่ายเบิก'
WHERE g.`page_key` = 'product_dept' AND s.`target_selector` = '#pdLeft';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '', s.`side` = 'over', s.`align` = 'center',
    s.`description` = 'ปุ่ม "เลือกที่เห็นทั้งหมด" ใช้คู่กับตัวกรองหมวดหมู่หรือคำค้น กดแล้วจะติ๊กเฉพาะรายการที่กำลังแสดงอยู่ ไม่ใช่ทั้ง 313 รายการ เหมาะกับตอนที่อยากยกทั้งหมวดให้ฝ่ายเดียว'
WHERE g.`page_key` = 'product_dept' AND s.`target_selector` = '#pdAll';


SELECT s.`step_order`,
       IF(s.`target_selector` = '', '(กล่องกลางจอ)', s.`target_selector`) AS `ชี้ที่`,
       s.`title`
FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'product_dept'
ORDER BY s.`step_order`;