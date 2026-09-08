-- ============================================================
--  062_guide_request_edit_sendback_rollback.sql
--  ถอนขั้นปุ่ม "แก้ไขใบเบิก" และ "ส่งกลับแก้ไข" ออกจากคู่มือหน้ารายการใบเบิก
--  และคืนข้อความขั้น "ดูรายละเอียด" กลับเป็นของเดิม
--
--  ลบขั้นก่อนแล้วค่อยเลื่อนลำดับกลับ ถ้าเลื่อนก่อนลบ ขั้นที่จะลบจะไปทับเลข
--  ของขั้นอื่นแล้วได้ลำดับซ้ำ — ทำจากขั้นท้าย (ส่งกลับ) ไปหาขั้นต้น (แก้ไข)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/062_guide_request_edit_sendback_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ---------- ถอนปุ่มส่งกลับแก้ไข (ขั้นที่ 8) ---------- */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="sendback"]';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 9;

/* ---------- ถอนปุ่มแก้ไขใบเบิก (ขั้นที่ 5) ---------- */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="edit"]';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 6;

/* ---------- คืนข้อความขั้น "ดูรายละเอียด" ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'เปิดดูรายการสินค้าทั้งใบก่อนตัดสินใจ ควรกดทุกครั้งก่อนอนุมัติ เพราะการอนุมัติคือการยืนยันว่าของทั้งใบสมเหตุสมผล ไม่ใช่แค่ยืนยันว่ามีใบเข้ามา จุดสีข้างไอคอนนี้แปลว่าใบนั้นมีงานรออยู่ที่คุณ'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="open"]';
