-- ============================================================
--  059_guide_adjust_approve_link_rollback.sql
--  คืนคู่มือหน้า "ประวัติการปรับยอด" ขั้นที่ 4 กลับเป็นแบบไฟล์ 057
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/059_guide_adjust_approve_link_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step`
SET `title`       = 'กด "ดูรายละเอียด" เพื่อทำงานกับใบ',
    `description` = 'ในกล่องรายละเอียดของแต่ละใบจะเห็นยอดเดิม/นับได้จริง/ส่วนต่างทุกบรรทัด และปุ่ม อนุมัติ / ไม่อนุมัติ (เฉพาะผู้อนุมัติ และห้ามอนุมัติใบที่ตัวเองสร้าง) / ยกเลิก (ผู้ดูแลระบบ) / พิมพ์เอกสาร'
WHERE `step_order` = 4
  AND `guide_id` = (SELECT `guide_id` FROM `tb_guide` WHERE `page_key` = 'adjust_history');

SELECT s.`step_order`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_history'
ORDER BY s.`step_order`;
