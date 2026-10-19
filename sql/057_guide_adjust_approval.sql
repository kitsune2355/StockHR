-- ============================================================
--  057_guide_adjust_approval.sql
--  ปรับคู่มือของหน้าปรับยอดให้ตรงกับของใหม่ (มีขั้นอนุมัติ)
--  และเพิ่มคู่มือหน้า "ตั้งค่าอนุมัติ"
--
--  ไฟล์ 055 เดิมบอกผู้ใช้ว่า "กดบันทึกแล้วยอดเปลี่ยนทันที ไม่มีขั้นอนุมัติ"
--  ซึ่งตอนนี้ไม่จริงแล้ว — ใบปรับยอดเป็นเอกสารเดินเส้นทาง waiting -> approved
--  ยอดคงเหลือเปลี่ยนตอนอนุมัติเท่านั้น
--
--  ไฟล์นี้ลบคู่มือ adjust_new / adjust_history เดิมทิ้ง แล้วใส่ชุดใหม่
--  พร้อมเพิ่ม page_key = 'adjust_approval'
--
--  selector ที่ชี้ (id ที่มีจริงและมองเห็นได้):
--    บันทึกปรับยอด : #docDate #docNote #adjPickBox #btnSave
--    ประวัติ       : #quickStatusFilters #fpToggle #btnReload
--    ตั้งค่าอนุมัติ : #userSearch #btnSave
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hr < sql/057_guide_adjust_approval.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/057_guide_adjust_approval_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` IN ('adjust_new', 'adjust_history', 'adjust_approval');


-- ============================================================
--  หน้า "บันทึกปรับยอด" (page_key = adjust_new)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'adjust_new', 'บันทึกปรับยอด',
       'ยื่นใบปรับยอดคงเหลือให้ตรงกับที่นับได้จริง ยอดจะเปลี่ยนเมื่อผู้อนุมัติเห็นชอบ', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'adjust_new');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ใช้ตอนยอดในระบบไม่ตรงกับของจริง',
       'เช่น ตรวจนับประจำปี ของชำรุดหรือสูญหาย หรือยอดยกมาผิด กดยื่นแล้วจะกลายเป็นเอกสารมีเลขที่ รอผู้อนุมัติเห็นชอบก่อน ยอดคงเหลือจึงจะถูกปรับ ยังไม่เปลี่ยนตอนกดยื่น',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#docDate', 'วันที่ที่นับของจริง',
       'ใส่วันที่ที่ลงไปนับ ไม่ใช่วันที่คีย์ข้อมูล รายงานย้อนหลังจะได้ตรงช่วง ลงวันที่ล่วงหน้าไม่ได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#docNote', 'บอกเหตุผลไว้เสมอ',
       'ทุกบรรทัดที่ไม่ได้กรอกหมายเหตุของตัวเอง จะใช้ข้อความช่องนี้ไปลงในประวัติ เขียนให้ผู้อนุมัติและคนอ่านทีหลังเข้าใจว่าปรับเพราะอะไร เช่น "ตรวจนับประจำปี 2568"',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#adjPickBox', 'เลือกสินค้าทีละตัวเข้ามาในใบ',
       'พิมพ์รหัสหรือชื่อสินค้าเพื่อค้นหา ช่อง "นับได้จริง" ตั้งค่าเริ่มไว้เท่ากับยอดในระบบให้แล้ว แก้เฉพาะตัวที่นับได้ไม่ตรง ระบบคำนวณส่วนต่างให้เอง (เขียว = เกิน แดง = ขาด)',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#btnSave', 'กดยื่น = ส่งขออนุมัติ',
       'ระบบจะยืนยันจำนวนบรรทัดที่จะปรับก่อน กดยืนยันแล้วได้เลขใบ ADJ-xxxx สถานะ "รออนุมัติ" ยอดคงเหลือยังไม่เปลี่ยน จนกว่าผู้อนุมัติจะกดอนุมัติที่หน้า "ประวัติการปรับยอด" ผู้อนุมัติจะได้รับแจ้งเตือนทันที',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '', 'ยื่นแล้วดูสถานะได้ที่ "ประวัติการปรับยอด"',
       'หลังยื่น เปิดเมนู "ประวัติการปรับยอด" เพื่อดูว่าใบไหนรออนุมัติ / อนุมัติแล้ว / ไม่อนุมัติ และพิมพ์ใบเก็บเป็นหลักฐานได้ ปรับผิดหรืออยากยกเลิก ให้ผู้ดูแลระบบยกเลิกใบที่นั่น',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_new';


-- ============================================================
--  หน้า "ประวัติการปรับยอด" (page_key = adjust_history)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'adjust_history', 'ประวัติการปรับยอด',
       'ใบปรับยอดทั้งหมดและสถานะ ผู้อนุมัติกดอนุมัติ/ไม่อนุมัติได้ที่กล่องรายละเอียด', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'adjust_history');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'รายการใบปรับยอดทั้งหมด',
       'แต่ละแถวคือใบปรับยอดหนึ่งใบ พร้อมสถานะ (รออนุมัติ / อนุมัติแล้ว / ไม่อนุมัติ / ยกเลิก) ยอดคงเหลือจะถูกปรับก็ต่อเมื่อใบมีสถานะ "อนุมัติแล้ว" เท่านั้น',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#quickStatusFilters', 'กรองสถานะเร็ว',
       'กดชิปเพื่อดูเฉพาะใบที่ "รออนุมัติ" ได้ในคลิกเดียว ผู้อนุมัติเปิดหน้านี้มาส่วนใหญ่ก็เพื่อเคลียร์ใบที่รออยู่',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#fpToggle', 'ตัวกรองเพิ่มเติมอยู่แถบด้านขวา',
       'ช่วงวันที่และคำค้น (รหัส/ชื่อสินค้า/เลขที่เอกสาร) อยู่ในแถบด้านขวา จอกว้างกางค้างไว้แล้ว จอแคบกดปุ่มนี้เปิด',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '', 'กด "ดูรายละเอียด" เพื่อทำงานกับใบ',
       'ในกล่องรายละเอียดของแต่ละใบจะเห็นยอดเดิม/นับได้จริง/ส่วนต่างทุกบรรทัด และปุ่ม อนุมัติ / ไม่อนุมัติ (เฉพาะผู้อนุมัติ และห้ามอนุมัติใบที่ตัวเองสร้าง) / ยกเลิก (ผู้ดูแลระบบ) / พิมพ์เอกสาร',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';


-- ============================================================
--  หน้า "ตั้งค่าอนุมัติ" (page_key = adjust_approval)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'adjust_approval', 'ตั้งค่าอนุมัติ',
       'กำหนดว่าใครกดอนุมัติใบปรับยอดสินค้าได้บ้าง ตั้งได้หลายคน', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'adjust_approval');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ใครอนุมัติใบปรับยอดได้บ้าง',
       'super_admin และ admin อนุมัติได้เสมอ ไม่ต้องตั้งในหน้านี้ หน้านี้ไว้เพิ่ม "คนอื่น" เช่น หัวหน้าคลัง ให้กดอนุมัติได้ ตั้งได้มากกว่าหนึ่งคน ใครก็ได้ในรายชื่อกดอนุมัติได้ (ไม่ต้องครบทุกคน)',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#userSearch', 'ค้นหาคนที่จะเพิ่ม',
       'พิมพ์ชื่อ ชื่อผู้ใช้ หน่วยงาน หรือตำแหน่ง เพื่อกรองรายชื่อ แล้วติ๊กช่องหน้าคนที่ต้องการให้อนุมัติได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'ติ๊กแล้วกดบันทึก',
       'รายชื่อจะมีผลทันที คนที่ติ๊กไว้จะได้รับแจ้งเตือนทุกครั้งที่มีใบปรับยอดใหม่รออนุมัติ ถ้าไม่ติ๊กใครเลย จะเหลือแต่ super_admin / admin ที่อนุมัติได้',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_approval';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT g.`page_key`, g.`title`, COUNT(s.`step_id`) AS `จำนวนขั้นตอน`
FROM `tb_guide` g
LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
WHERE g.`page_key` IN ('adjust_new', 'adjust_history', 'adjust_approval')
GROUP BY g.`guide_id`, g.`page_key`, g.`title`;

SELECT COUNT(*) AS `คู่มือทั้งหมด` FROM `tb_guide`;
