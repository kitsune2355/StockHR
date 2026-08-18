-- ============================================================
--  045_guide_request_print_rollback.sql
--  ถอนขั้นปุ่มพิมพ์ออกจากคู่มือหน้ารายการใบเบิก
--
--  ลบขั้นก่อนแล้วค่อยเลื่อนลำดับกลับ ถ้าเลื่อนก่อนลบ ขั้นที่จะลบ
--  จะไปทับเลขของขั้นอื่นแล้วได้ลำดับซ้ำ
--
--  ไม่แตะข้อความขั้นอื่น เพราะไฟล์ 045 ไม่ได้แก้ข้อความไหนเลย
--  แค่แทรกขั้นใหม่กับเลื่อนเลข
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/045_guide_request_print_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[href^="print_doc.php"]';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 4;
