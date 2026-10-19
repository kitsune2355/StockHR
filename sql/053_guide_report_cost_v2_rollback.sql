-- ============================================================
--  053_guide_report_cost_v2_rollback.sql
--  ย้อนคู่มือหน้า "ค่าใช้จ่ายรายเดือน" กลับเป็นชุด 9 ขั้นของเดิม
--
--  ลบสองขั้นที่เพิ่มเข้ามา แล้วเลื่อนลำดับที่เหลือกลับที่เดิม
--  พร้อมคืนข้อความขั้นส่งออกที่ถูกแก้
--
--  ⚠ ถอนแล้วคู่มือจะไม่พูดถึงตารางแรกของหน้าเลย
--    ทั้งที่เป็นตารางที่ผู้ใช้เห็นก่อนใคร และมีกับดักที่อ่านผิดได้ง่าย
--    (คอลัมน์ยอดซื้อกับคอลัมน์หน่วยงานอยู่คนละฝั่ง ไม่ต้องบวกกันลงตัว)
--    ใช้คู่กับการย้อนโค้ดฝั่งหน้าเว็บเท่านั้น
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/053_guide_report_cost_v2_rollback.sql
-- ============================================================

SET NAMES utf8;

-- ------------------------------------------------------------
--  1) ลบขั้นที่เพิ่มเข้ามา
-- ------------------------------------------------------------
DELETE s FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'report_cost' AND s.`step_order` IN (3, 4);


-- ------------------------------------------------------------
--  2) เลื่อนลำดับกลับ ทำจากหน้าไปหลัง เพื่อไม่ให้ทับกัน
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 3  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 5;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 4  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 6;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 5  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 7;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 6  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 8;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 7  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 9;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 8  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 10;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 9  WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 11;


-- ------------------------------------------------------------
--  3) คืนข้อความเดิมของขั้นส่งออก
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ได้ทุกตารางในหน้านี้เป็นไฟล์เดียว แยกเป็นชีตละตาราง เอาไปทำ pivot หรือรวมกับข้อมูลอื่นต่อได้ ตัวเลขในไฟล์เป็นตัวเลขจริง ไม่ใช่ข้อความ จึงคำนวณต่อได้ทันที'
WHERE g.`page_key` = 'report_cost' AND s.`step_order` = 8;


SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'report_cost'
ORDER BY s.`step_order`;
