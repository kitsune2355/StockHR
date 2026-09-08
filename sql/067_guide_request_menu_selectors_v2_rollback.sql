-- ============================================================
--  067_guide_request_menu_selectors_v2_rollback.sql
--  คืน selector ของหกขั้นในคู่มือหน้ารายการใบเบิก กลับเป็น [data-row-act="..."]
--  (สถานะแบบ sql/065)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/067_guide_request_menu_selectors_v2_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="approvelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="approvelink"]', '[data-row-act="approvelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="openapprovelink"]', '[data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="edit"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="edit"]', '[data-row-act="edit"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="sendback"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="sendback"]', '[data-row-act="sendback"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="cancel"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="cancel"]', '[data-row-act="cancel"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '[data-row-act="mail"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="mail"]', '[data-row-act="mail"]');
