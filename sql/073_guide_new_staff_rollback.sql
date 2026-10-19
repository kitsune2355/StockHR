-- ============================================================
--  073_guide_new_staff_rollback.sql
--  ย้อน 073_guide_new_staff.sql
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/073_guide_new_staff_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ขั้นตอนลบตามด้วย fk_step_guide (ON DELETE CASCADE) */
DELETE FROM `tb_guide` WHERE `page_key` IN ('new_staff_request', 'new_staff_list', 'new_staff_kit');

/* ไทล์พนักงานใหม่บนหน้าภาพรวม — ลบแล้วเลื่อนขั้นที่ตามมากลับขึ้นหนึ่งช่อง */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'dashboard' AND s.`target_selector` = '#bnNewStaff';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'dashboard' AND s.`step_order` >= 4;
