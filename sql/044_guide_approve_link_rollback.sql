-- ============================================================
--  044_guide_approve_link_rollback.sql
--  ถอนขั้นลิงก์อนุมัติออกจากคู่มือหน้ารายการใบเบิก
--
--  คืนข้อความสองขั้นที่แก้ไปกลับเป็นฉบับของ 042 ด้วย
--  เพราะข้อความชุดนี้พูดถึงลิงก์อนุมัติ ซึ่งจะไม่จริงถ้าถอนฟีเจอร์ออก
--
--  ลำดับขั้นถูกเลื่อนกลับให้เหมือนเดิม โดยลบขั้นใหม่ก่อนแล้วค่อยเลื่อน
--  ถ้าเลื่อนก่อนลบ ขั้นใหม่จะไปทับเลขของขั้นอื่น
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/044_guide_approve_link_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ---------- ลบขั้นใหม่ ---------- */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="approvelink"]';

/* ---------- เลื่อนลำดับกลับ ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 4;

/* ---------- คืนข้อความขั้นอนุมัติ (ฉบับ 042) ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'อนุมัติแล้วใบจะไปรอที่คลังเพื่อทำใบจ่าย ยังไม่ตัดสต๊อกตอนนี้ ระบบแจ้งผู้ขอเบิกให้เองทั้งกระดิ่งและอีเมล ผู้อนุมัติอนุมัติใบของตัวเองไม่ได้'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="approve"]';

/* ---------- คืนข้อความขั้นส่งเมลซ้ำ (ฉบับเดิม) ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ส่งอีเมลแจ้งเรื่องตามสถานะปัจจุบันของใบนั้นอีกครั้ง ใช้ตอนที่ปลายทางหาเมลฉบับเดิมไม่เจอหรือลบไปแล้ว เห็นเฉพาะผู้ดูแลระบบ'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="mail"]';
