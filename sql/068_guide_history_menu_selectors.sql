-- ============================================================
--  068_guide_history_menu_selectors.sql
--  คู่มือหน้าประวัติ (รับเข้า / จ่ายออก / ปรับยอด): ชี้ขั้นของคำสั่งในเมนู ⋮
--  ให้เจาะจงเมนูสาธิต เหมือนที่ทำกับหน้ารายการใบเบิกใน sql/067
--
--  ที่มา: คอลัมน์จัดการยุบไอคอนที่เกินเข้าเมนูจุดสามจุด (ดู doc-list.js)
--  แถวสาธิตของคู่มือก็ใช้เมนู ⋮ เหมือนแถวจริงแล้ว โดยคู่มือกางเมนูค้างไว้
--  เมนูที่กางค้างมีคลาส .rowmenu-demo
--
--  ขั้นที่ยัง selector เป็น .rowact[data-row-act="..."] จะ:
--    - หาไม่เจอ (ปุ่มย้ายเข้าเมนูแล้ว) แล้วถูกข้าม  หรือ
--    - ไปโดนปุ่มในแถวจริง กรณีที่บางแถวเหลือคำสั่งเดียวแล้วถูกดันขึ้นแถว
--      (เช่นใบที่ยกเลิก/จ่ายแล้ว ที่ผู้ดูแลระบบเห็นแต่ปุ่ม "ส่งอีเมลซ้ำ")
--
--  แก้เป็น '.rowmenu-demo [data-row-act="..."]' — จับเฉพาะรายการในเมนูสาธิต
--
--  ขั้น open / print / approve / reject / ack ไม่แตะ (ยังเป็นปุ่มในแถว)
--
--  WHERE จับได้ทุกรูปแบบที่เคยตั้งไว้ รันซ้ำได้ไม่พัง
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hr < sql/068_guide_history_menu_selectors.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/068_guide_history_menu_selectors_rollback.sql
-- ============================================================

SET NAMES utf8;

-- ---------- ประวัติการปรับยอด (adjust_history) ----------
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="approvelink"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="approvelink"]', '[data-row-act="approvelink"]', '.rowmenu-demo [data-row-act="approvelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="openapprovelink"]', '[data-row-act="openapprovelink"]', '.rowmenu-demo [data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="mail"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="mail"]', '[data-row-act="mail"]', '.rowmenu-demo [data-row-act="mail"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="cancel"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="cancel"]', '[data-row-act="cancel"]', '.rowmenu-demo [data-row-act="cancel"]');


-- ---------- ประวัติการจ่ายออก (issue_history) ----------
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="copylink"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="copylink"]', '[data-row-act="copylink"]', '.rowmenu-demo [data-row-act="copylink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="openlink"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="openlink"]', '[data-row-act="openlink"]', '.rowmenu-demo [data-row-act="openlink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="cancel"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="cancel"]', '[data-row-act="cancel"]', '.rowmenu-demo [data-row-act="cancel"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="mail"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="mail"]', '[data-row-act="mail"]', '.rowmenu-demo [data-row-act="mail"]');


-- ---------- ประวัติการรับเข้า (receive_history) ----------
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowmenu-demo [data-row-act="cancel"]'
WHERE g.`page_key` = 'receive_history'
  AND s.`target_selector` IN ('.rowact[data-row-act="cancel"]', '[data-row-act="cancel"]', '.rowmenu-demo [data-row-act="cancel"]');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT g.`page_key`, s.`step_order`, s.`target_selector`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('receive_history', 'issue_history', 'adjust_history')
ORDER BY g.`page_key`, s.`step_order`;
