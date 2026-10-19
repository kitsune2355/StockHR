-- ============================================================
--  070_guide_request_history_rollback.sql
--  ถอนขั้น "ประวัติเอกสาร" ออกจากคู่มือหน้ารายการใบเบิก
--  และคืนข้อความขั้น "คอลัมน์จัดการ — เมนู ⋮" กลับเป็นของ sql/066
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/070_guide_request_history_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowmenu-demo [data-row-act="history"]';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 13;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ในการใช้งานจริงคอลัมน์นี้โชว์เฉพาะปุ่มที่ใช้บ่อย (ดูรายละเอียด / พิมพ์ / อนุมัติ / ไม่อนุมัติ) ปุ่มที่เหลือ — แก้ไขใบเบิก, ส่งกลับแก้ไข, คัดลอก/เปิดลิงก์อนุมัติ, ยกเลิก, ส่งอีเมลซ้ำ — รวมอยู่ในเมนู "จุดสามจุดแนวตั้ง" ท้ายแถว กดที่ไอคอนนั้นแล้วเลือกคำสั่งได้เลย ขั้นถัดไปคู่มือจะกางเมนูนั้นค้างไว้แล้วพาดูทีละคำสั่ง'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.cell-rowacts';
