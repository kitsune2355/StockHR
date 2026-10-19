-- ============================================================
--  065_guide_request_menu_selectors.sql
--  คู่มือหน้ารายการใบเบิก: ชี้ขั้นของคำสั่งที่ยุบเข้าเมนู ⋮ ให้ตรงเป้า
--
--  ที่มา: แถวสาธิตของคู่มือหน้านี้เปลี่ยนมาใช้เมนู ⋮ เหมือนแถวจริง (ปุ่มเยอะเกิน
--  จนล้น) แล้ว demoSetup() กางเมนูค้างไว้ให้คู่มือชี้ได้ (ดู assets/js/doc-list.js)
--
--  ปุ่มในเมนูเป็น .rowmenu-item ไม่ใช่ .rowact เดิม ขั้นที่ยัง selector เป็น
--  .rowact[data-row-act="..."] จึงหาเป้าไม่เจอแล้วถูกข้ามทิ้ง
--
--  แก้ selector ของหกขั้นนี้เป็น [data-row-act="..."] (ไม่ผูกกับคลาส) ให้จับได้
--  ทั้งปุ่มในแถว (ปุ่มหลักที่ยังโชว์อยู่) และรายการในเมนู ⋮
--
--    approvelink · openapprovelink · edit · sendback · cancel · mail
--
--  ขั้น open / approve / reject ไม่แตะ เพราะยังเป็นปุ่มในแถว (.rowact) ตามเดิม
--
--  ---------------------------------------------------------------
--  หมายเหตุ (migration ordering)
--  ---------------------------------------------------------------
--  044 / 046 / 062 / 063 อ้าง selector แบบ .rowact[data-row-act="..."] ตอน DELETE
--  ถ้าจะรันไฟล์เหล่านั้นซ้ำหลังไฟล์นี้ ต้องแก้ selector ในนั้นตามด้วย
--  (รันไฟล์นี้แล้วรัน rollback ของไฟล์นี้ จะคืน selector กลับเป็น .rowact[...])
--
--  WHERE จับได้ทั้งสองรูปแบบ รันซ้ำได้ไม่พัง
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hr < sql/065_guide_request_menu_selectors.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/065_guide_request_menu_selectors_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="approvelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="approvelink"]', '[data-row-act="approvelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="openapprovelink"]', '[data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="edit"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="edit"]', '[data-row-act="edit"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="sendback"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="sendback"]', '[data-row-act="sendback"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="cancel"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="cancel"]', '[data-row-act="cancel"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="mail"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="mail"]', '[data-row-act="mail"]');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
ORDER BY s.`step_order`;
