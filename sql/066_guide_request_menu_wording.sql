-- ============================================================
--  066_guide_request_menu_wording.sql
--  คู่มือหน้ารายการใบเบิก: แก้ข้อความขั้น "คอลัมน์จัดการ — ปุ่มหลักกับเมนู ⋮"
--  ให้ตรงกับพฤติกรรมใหม่ (แถวสาธิตใช้เมนู ⋮ เหมือนแถวจริง คู่มือกางเมนูค้างไว้)
--
--  เดิม (064) เขียนว่า "แถวตัวอย่างกางปุ่มออกให้เห็นครบทุกอัน" ซึ่งไม่จริงแล้ว
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/066_guide_request_menu_wording.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/066_guide_request_menu_wording_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ในการใช้งานจริงคอลัมน์นี้โชว์เฉพาะปุ่มที่ใช้บ่อย (ดูรายละเอียด / พิมพ์ / อนุมัติ / ไม่อนุมัติ) ปุ่มที่เหลือ — แก้ไขใบเบิก, ส่งกลับแก้ไข, คัดลอก/เปิดลิงก์อนุมัติ, ยกเลิก, ส่งอีเมลซ้ำ — รวมอยู่ในเมนู "จุดสามจุดแนวตั้ง" ท้ายแถว กดที่ไอคอนนั้นแล้วเลือกคำสั่งได้เลย ขั้นถัดไปคู่มือจะกางเมนูนั้นค้างไว้แล้วพาดูทีละคำสั่ง'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.cell-rowacts';

SELECT s.`step_order`, s.`target_selector`, LEFT(s.`description`, 60) AS `desc_head`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list' AND s.`target_selector` = '.cell-rowacts';
