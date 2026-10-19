-- ============================================================
--  060_guide_adjust_history_icons_rollback.sql
--  คืนคู่มือหน้า "ประวัติการปรับยอด" กลับเป็นชุดของ sql/057 + sql/059
--  (4 ขั้น: intro / #quickStatusFilters / #fpToggle / "ปุ่มบนแต่ละแถว")
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/060_guide_adjust_history_icons_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'รายการใบปรับยอดทั้งหมด',
 'แต่ละแถวคือใบปรับยอดหนึ่งใบ พร้อมสถานะ (รออนุมัติ / อนุมัติแล้ว / ไม่อนุมัติ / ยกเลิก) ยอดคงเหลือจะถูกปรับก็ต่อเมื่อใบมีสถานะ "อนุมัติแล้ว" เท่านั้น',
 'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#quickStatusFilters', 'กรองสถานะเร็ว',
 'กดชิปเพื่อดูเฉพาะใบที่ "รออนุมัติ" ได้ในคลิกเดียว ผู้อนุมัติเปิดหน้านี้มาส่วนใหญ่ก็เพื่อเคลียร์ใบที่รออยู่',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#fpToggle', 'ตัวกรองเพิ่มเติมอยู่แถบด้านขวา',
 'ช่วงวันที่และคำค้น (รหัส/ชื่อสินค้า/เลขที่เอกสาร) อยู่ในแถบด้านขวา จอกว้างกางค้างไว้แล้ว จอแคบกดปุ่มนี้เปิด',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '', 'ปุ่มบนแต่ละแถว',
 'บนแถวใบที่ "รออนุมัติ" ผู้ดูแลระบบจะเห็นไอคอน "คัดลอกลิงก์อนุมัติ" กับ "เปิดลิงก์อนุมัติ" ไว้ส่งลิงก์ให้ผู้อนุมัติทางไลน์/แชต หรือเปิดหน้าอนุมัติเองได้เลยโดยไม่ต้องรออีเมล (ต้องตั้งผู้อนุมัติที่หน้า "ตั้งค่าอนุมัติ" ก่อน) ส่วนกด "ดูรายละเอียด" จะเห็นยอดเดิม/นับได้จริง/ส่วนต่างทุกบรรทัด และปุ่ม อนุมัติ / ไม่อนุมัติ (เฉพาะผู้อนุมัติ ห้ามอนุมัติใบที่ตัวเองสร้าง) / ยกเลิก (ผู้ดูแลระบบ) / พิมพ์เอกสาร / ส่งอีเมลซ้ำ',
 'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

SELECT s.`step_order`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_history'
ORDER BY s.`step_order`;
