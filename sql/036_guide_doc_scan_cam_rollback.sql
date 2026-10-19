-- ============================================================
--  036_guide_doc_scan_cam_rollback.sql
--  ลบขั้นคู่มือของปุ่มเปิดกล้อง แล้วเลื่อนเลขลำดับกลับ
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e):
--      mysql -u root -p1234 stock_hr < sql/036_guide_doc_scan_cam_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('receive_new', 'request_new', 'issue_new')
  AND s.`target_selector` = '#btnScanCam';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` IN ('receive_new', 'request_new', 'issue_new')
  AND s.`step_order` >= 5;
