-- ============================================================
--  067_guide_request_menu_selectors_v2.sql
--  คู่มือหน้ารายการใบเบิก: ชี้ขั้นของคำสั่งในเมนู ⋮ ให้เจาะจงเมนูสาธิตเท่านั้น
--
--  ปัญหาของ 065: ใช้ selector กว้าง [data-row-act="mail"] ฯลฯ
--  แต่บางแถวจริง (ใบที่ไม่อนุมัติ/จ่ายแล้ว/ยกเลิก ที่ผู้ดูแลระบบเห็นปุ่ม "ส่งอีเมลซ้ำ"
--  เป็นคำสั่งเดียวที่เหลือ) จะดัน "ส่งอีเมลซ้ำ" ขึ้นมาเป็นปุ่มในแถว ไม่ยุบเข้าเมนู
--  querySelector จึงไปเจอปุ่มในแถวจริง (อยู่ก่อนในลำดับ DOM) แทนรายการในเมนูสาธิต
--  คู่มือเลยชี้ผิดที่ (ผู้ใช้แจ้ง: ไอคอนซองจดหมายชี้ไปที่แถวล่างแทนเมนู)
--
--  แก้: เมนูที่คู่มือกางค้างไว้มีคลาส .rowmenu-demo (ดู openRowMenu ใน doc-list.js)
--  เปลี่ยน selector ของหกขั้นเป็น '.rowmenu-demo [data-row-act="..."]'
--  ซึ่งจับได้เฉพาะรายการในเมนูสาธิต ไม่โดนปุ่มในแถวจริง
--
--    approvelink · openapprovelink · edit · sendback · cancel · mail
--
--  ขั้น open / approve / reject ไม่แตะ (ยังเป็นปุ่มในแถว .rowact ตามเดิม)
--
--  WHERE จับได้ทุกรูปแบบที่เคยตั้งไว้ (.rowact[...] จาก 044/046/060/062/063,
--  [data-row-act="..."] จาก 065) รันซ้ำได้ไม่พัง
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hl < sql/067_guide_request_menu_selectors_v2.sql
--
--  ย้อนกลับ (กลับไปเป็น [data-row-act="..."] แบบ 065):
--      mysql -u root -p1234 stock_hl < sql/067_guide_request_menu_selectors_v2_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="approvelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="approvelink"]', '[data-row-act="approvelink"]', '.rowmenu-demo [data-row-act="approvelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="openapprovelink"]', '[data-row-act="openapprovelink"]', '.rowmenu-demo [data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="edit"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="edit"]', '[data-row-act="edit"]', '.rowmenu-demo [data-row-act="edit"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="sendback"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="sendback"]', '[data-row-act="sendback"]', '.rowmenu-demo [data-row-act="sendback"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="cancel"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="cancel"]', '[data-row-act="cancel"]', '.rowmenu-demo [data-row-act="cancel"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="mail"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowact[data-row-act="mail"]', '[data-row-act="mail"]', '.rowmenu-demo [data-row-act="mail"]');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
ORDER BY s.`step_order`;
