-- ============================================================
--  027_guide_barcode_scan_rollback.sql
--  เอาขั้นคู่มือของช่องยิงบาร์โค้ดออกจากทั้งสามหน้า แล้วเลื่อนลำดับกลับ
--
--  ลบด้วย target_selector ไม่ใช่เลขลำดับ เผื่อมีใครแทรกขั้นอื่นเพิ่มระหว่างนี้
--  ถ้าลบตามเลขลำดับจะไปโดนขั้นที่ไม่เกี่ยวข้อง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/027_guide_barcode_scan_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('receive_new', 'issue_new', 'request_new')
  AND s.`target_selector` = '#scanInput';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` IN ('receive_new', 'issue_new', 'request_new')
  AND s.`step_order` >= 4;
