-- ============================================================
--  070_guide_request_history.sql
--  คู่มือหน้ารายการใบเบิก: เพิ่มขั้นของคำสั่ง "ประวัติเอกสาร" ในเมนู ⋮
--
--  ที่มา: ไทม์ไลน์ประวัติเอกสารย้ายจากในกล่องรายละเอียด มาเปิดเป็น modal
--  จากเมนูจุดสามจุด (คำสั่ง history) — ดู showHistory ใน assets/js/doc-list.js
--
--  ---------------------------------------------------------------
--  เลขลำดับขั้น (ให้ตรงกับลำดับในเมนู ⋮)
--  ---------------------------------------------------------------
--  เมนู: คัดลอกลิงก์ · เปิดลิงก์ · แก้ไข · ส่งกลับ · ยกเลิก · ประวัติเอกสาร · เมล
--  แทรก history เป็นขั้นที่ 12 (หลัง "ยกเลิก" ก่อน "ส่งอีเมลซ้ำ") แล้วเลื่อนลง
--
--  รันซ้ำจะเลื่อนซ้ำและได้ขั้นซ้ำ จึงลบขั้นเดิมของ selector นี้ทิ้งก่อนเสมอ
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hl < sql/070_guide_request_history.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/070_guide_request_history_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ---------- ลบขั้นเดิมก่อน กันรันซ้ำแล้วได้ขั้นซ้ำ ---------- */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowmenu-demo [data-row-act="history"]';

/* ---------- เปิดช่องที่ 12 ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` + 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 12;

/* ---------- ขั้นใหม่: ประวัติเอกสาร ---------- */
INSERT INTO `tb_guide_step`
    (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 12, '.rowmenu-demo [data-row-act="history"]', 'ไอคอนนาฬิกา — ประวัติเอกสาร',
 'เปิดกล่องไทม์ไลน์ของใบนี้ ไล่ทุกเหตุการณ์ตั้งแต่ยื่น แก้ไข อนุมัติ ส่งกลับ จนถึงจ่ายของครบ พร้อมชื่อผู้ทำ เวลา และเหตุผล (เช่นเหตุผลที่ไม่อนุมัติ หรือสิ่งที่ต้องแก้ตอนส่งกลับ) ใบเบิกทุกใบมีอย่างน้อยเหตุการณ์ "ยื่นใบเบิก" ใบเก่าที่ทำก่อนระบบเก็บประวัติจะขึ้นว่ายังไม่มีประวัติ',
 'bottom', 'start', NOW()
FROM `tb_guide` WHERE `page_key` = 'request_list';

/* ---------- ปรับข้อความขั้น "คอลัมน์จัดการ — เมนู ⋮" ให้รวม "ประวัติเอกสาร" ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ในการใช้งานจริงคอลัมน์นี้โชว์เฉพาะปุ่มที่ใช้บ่อย (ดูรายละเอียด / พิมพ์ / อนุมัติ / ไม่อนุมัติ) ปุ่มที่เหลือ — แก้ไขใบเบิก, ส่งกลับแก้ไข, คัดลอก/เปิดลิงก์อนุมัติ, ยกเลิก, ประวัติเอกสาร, ส่งอีเมลซ้ำ — รวมอยู่ในเมนู "จุดสามจุดแนวตั้ง" ท้ายแถว กดที่ไอคอนนั้นแล้วเลือกคำสั่งได้เลย ขั้นถัดไปคู่มือจะกางเมนูนั้นค้างไว้แล้วพาดูทีละคำสั่ง'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.cell-rowacts';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
ORDER BY s.`step_order`;
