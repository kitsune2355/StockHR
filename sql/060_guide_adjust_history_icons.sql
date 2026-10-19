-- ============================================================
--  060_guide_adjust_history_icons.sql
--  คู่มือหน้า "ประวัติการปรับยอด" — ชี้ไอคอนในคอลัมน์จัดการทีละอัน
--
--  แทนที่ขั้นตอนเดิมของ page_key='adjust_history' ทั้งหมด (เก็บตัวคู่มือไว้
--  ลบเฉพาะ step แล้วใส่ชุดใหม่ — แบบเดียวกับ sql/023)
--
--  ไอคอนที่ชี้อยู่ในแถว "ตัวอย่างสำหรับสาธิต" ที่ doc-list.js ยัดไว้บนสุด
--  ตอนเปิดคู่มือ (window.GuideDemo page='adjust_history') จึงชี้ได้ครบทุกอัน
--  ไม่ว่าในระบบจะมีใบปรับยอดสถานะไหนอยู่บ้าง
--
--  ขั้นที่ target ไม่เจอ (เช่นไอคอนอนุมัติที่ขึ้นเฉพาะผู้อนุมัติ) app-guide.js
--  ข้ามให้เงียบ ๆ อยู่แล้ว
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/060_guide_adjust_history_icons.sql
--
--  ย้อนกลับ (กลับไปคู่มือชุดของ sql/057 + sql/059):
--      mysql -u root -p1234 stock_hr < sql/060_guide_adjust_history_icons_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_history';


INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'สถานะบอกว่าใบอยู่ตรงไหน',
 'รออนุมัติ = รอผู้อนุมัติตัดสิน · อนุมัติแล้ว = ยอดคงเหลือถูกปรับตามที่นับแล้ว · ไม่อนุมัติ = ยอดไม่เปลี่ยน · ยกเลิก = ถูกยกเลิก (ถ้าอนุมัติไปแล้วจะถอนยอดคืน) ไอคอนท้ายแถวเปลี่ยนตามสถานะและสิทธิ์ (แถวบนสุดตอนนี้เป็นตัวอย่างสำหรับสาธิต จะหายไปเองตอนปิดคู่มือ)',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#quickStatusFilters', 'กรองสถานะเร็ว',
 'กดชิปเพื่อดูเฉพาะใบที่ "รออนุมัติ" ได้ในคลิกเดียว ผู้อนุมัติเปิดหน้านี้มาส่วนใหญ่ก็เพื่อเคลียร์ใบที่รออยู่',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#fpToggle', 'ตัวกรองเพิ่มเติมอยู่แถบด้านขวา',
 'ช่วงวันที่และคำค้น (เลขที่เอกสาร / รหัส-ชื่อสินค้า / หมายเหตุ) อยู่ในแถบด้านขวา จอกว้างกางค้างไว้แล้ว จอแคบกดปุ่มนี้เปิด ทั้งตารางและไฟล์ที่ส่งออกใช้เงื่อนไขชุดเดียวกัน',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — ดูรายละเอียด',
 'เปิดกล่องที่เห็นทุกบรรทัดว่ายอดเดิมเท่าไร นับได้จริงเท่าไร ส่วนต่างเท่าไร (เขียว = เกิน แดง = ขาด) ในกล่องนี้มีปุ่ม อนุมัติ / ไม่อนุมัติ / ยกเลิก / พิมพ์ ให้กดหลังตรวจครบแล้ว จุดสีข้างไอคอนแปลว่าใบนั้นมีงานรออยู่ที่คุณ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '.rowact[href^="print_doc.php"]', 'ไอคอนเครื่องพิมพ์ — พิมพ์ใบปรับยอด',
 'เปิดหน้าพิมพ์ในแท็บใหม่ หัวเรื่อง "ใบปรับยอดสินค้า" มีคอลัมน์ ยอดเดิม / นับได้จริง / ส่วนต่าง และช่องเซ็น ผู้บันทึก / ผู้อนุมัติ ใช้เก็บเป็นหลักฐานได้ทุกสถานะ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '.rowact[data-row-act="approvelink"]', 'ไอคอนโซ่ — คัดลอกลิงก์อนุมัติ',
 'คัดลอกลิงก์กดอนุมัติ (เปิดได้เลยไม่ต้องล็อกอิน) ไว้ส่งให้ผู้อนุมัติทางไลน์/แชต มีผู้อนุมัติคนเดียว = คัดลอกให้ทันที หลายคน = เปิดกล่องให้เลือกชื่อก่อน ต้องตั้งรายชื่อผู้อนุมัติที่เมนู "ตั้งค่าอนุมัติ" ก่อน เห็นเฉพาะผู้ดูแลระบบและเฉพาะใบที่รออนุมัติ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '.rowact[data-row-act="openapprovelink"]', 'ไอคอนลูกศรออก — เปิดลิงก์อนุมัติ',
 'เปิดหน้าอนุมัติในแท็บใหม่ทันที ใช้ตอนอยากอนุมัติเองเลยโดยไม่ต้องรออีเมล มีผู้อนุมัติคนเดียว = เปิดของคนนั้น หลายคน = เปิดกล่องให้เลือกก่อน (ลิงก์ออกให้เป็นรายคน เปิดผิดคน = คนนั้นตัดสินใบนี้ในนามคนที่ลิงก์ออกให้)',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '.rowact[data-row-act="approve"]', 'ไอคอนถูก — อนุมัติ',
 'ขึ้นเฉพาะคนที่ถูกตั้งเป็นผู้อนุมัติที่หน้า "ตั้งค่าอนุมัติ" และห้ามอนุมัติใบที่ตัวเองสร้าง อนุมัติแล้วยอดคงเหลือจะถูกปรับให้ตรงกับ "นับได้จริง" ทันที ระบบแจ้งผู้บันทึกให้เอง',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 9, '.rowact[data-row-act="reject"]', 'ไอคอนกากบาท — ไม่อนุมัติ',
 'ต้องระบุเหตุผลเสมอ ยอดคงเหลือจะไม่เปลี่ยน ระบบส่งเหตุผลไปให้ผู้บันทึก แก้แล้วยื่นใบใหม่ได้ ใบที่ไม่อนุมัติแล้วแก้กลับไม่ได้',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 10, '.rowact[data-row-act="mail"]', 'ไอคอนซองจดหมาย — ส่งอีเมลซ้ำ',
 'ส่งอีเมลตามสถานะปัจจุบันของใบอีกครั้ง — ใบที่รออนุมัติ = ออกลิงก์อนุมัติชุดใหม่ให้ผู้อนุมัติทุกคน (ลิงก์เดิมใช้ไม่ได้) ใบที่ตัดสินแล้ว = แจ้งผู้บันทึก เห็นเฉพาะผู้ดูแลระบบ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 11, '.rowact[data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิกใบปรับยอด',
 'เฉพาะผู้ดูแลระบบ ยกเลิกได้ทั้งใบที่รออนุมัติและใบที่อนุมัติไปแล้ว ถ้าอนุมัติไปแล้วระบบจะถอนยอดที่ปรับคืนออกจากสต๊อกให้ (ถ้าของถูกเบิก/จ่ายออกไปหลังจากนั้นจนยอดไม่พอ จะยกเลิกไม่ได้) ต้องระบุเหตุผลเสมอ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'adjust_history';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_history'
ORDER BY s.`step_order`;
