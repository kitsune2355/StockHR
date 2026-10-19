-- ============================================================
--  052_guide_report_supply_v2_rollback.sql
--  ย้อนคู่มือหน้า "สั่งซื้อ-เบิกใช้รายเดือน" กลับเป็นชุด 8 ขั้นของไฟล์ 049
--
--  ลบสามขั้นที่เพิ่มเข้ามา แล้วเลื่อนลำดับที่เหลือกลับที่เดิม
--  พร้อมคืนข้อความสองขั้นที่ถูกแก้
--
--  ⚠ ถอนแล้วคู่มือจะไม่พูดถึงของที่หน้ามีอยู่จริงสี่อย่าง
--    (แถวย่อยรายราคา · หมวดหมู่ย่อย · ปุ่มเลือกคอลัมน์ · ตัวเลือกตอนส่งออก)
--    และขั้นที่ 4 จะกลับไปบอกว่าตรึงสี่คอลัมน์ ทั้งที่ตรึงเก้าคอลัมน์
--    ใช้คู่กับการย้อนโค้ดฝั่งหน้าเว็บเท่านั้น
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/052_guide_report_supply_v2_rollback.sql
-- ============================================================

SET NAMES utf8;

-- ------------------------------------------------------------
--  1) ลบขั้นที่เพิ่มเข้ามา
-- ------------------------------------------------------------
DELETE s FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'report_supply' AND s.`step_order` IN (6, 7, 9);


-- ------------------------------------------------------------
--  2) เลื่อนลำดับกลับ ทำจากหน้าไปหลัง เพื่อไม่ให้ทับกัน
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 6  WHERE g.`page_key` = 'report_supply' AND s.`step_order` = 8;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 7  WHERE g.`page_key` = 'report_supply' AND s.`step_order` = 10;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = 8  WHERE g.`page_key` = 'report_supply' AND s.`step_order` = 11;


-- ------------------------------------------------------------
--  3) คืนข้อความเดิม
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ตรงกลางตารางจะกางคอลัมน์ให้เฉพาะหน่วยงานที่มีการเบิกจริงในเดือนนั้น หน่วยงานที่เดือนนี้ไม่ได้เบิกอะไรเลยจะไม่มีคอลัมน์ ไม่ได้หายไปจากระบบ ระบบมีหน่วยงานหกสิบกว่าหน่วย ถ้ากางครบทุกเดือนจะได้ตารางกว้างร้อยกว่าคอลัมน์ที่ว่างเปล่าเกือบหมด สี่คอลัมน์แรกถูกตรึงไว้ให้ เลื่อนไปทางขวาไกลแค่ไหนก็ยังเห็นว่ากำลังดูสินค้าตัวไหน'
WHERE g.`page_key` = 'report_supply' AND s.`step_order` = 4;

UPDATE `tb_guide_step` s JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ได้ไฟล์หน้าตาเหมือนที่เห็นบนจอทุกอย่าง ทั้งหัวตารางสองชั้น คอลัมน์หน่วยงาน และแถวคั่นหมวด ตัวเลขส่งออกเป็นตัวเลขจริง เอาไปบวกต่อใน Excel ได้เลย ถ้ากรองอะไรไว้อยู่ ไฟล์ที่ได้จะมีเฉพาะที่กรองไว้เท่านั้น'
WHERE g.`page_key` = 'report_supply' AND s.`step_order` = 7;


SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'report_supply'
ORDER BY s.`step_order`;
