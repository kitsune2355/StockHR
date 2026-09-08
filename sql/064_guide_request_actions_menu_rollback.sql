-- ============================================================
--  064_guide_request_actions_menu_rollback.sql
--  ถอนขั้น "คอลัมน์จัดการ + เมนู ⋮" ออกจากคู่มือหน้ารายการใบเบิก
--
--  ลบขั้นก่อนแล้วค่อยเลื่อนลำดับกลับ ถ้าเลื่อนก่อนลบ ขั้นที่จะลบจะไปทับ
--  เลขของขั้นอื่นแล้วได้ลำดับซ้ำ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/064_guide_request_actions_menu_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.cell-rowacts';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 3;
