-- ============================================================
--  065_guide_request_menu_selectors_rollback.sql
--  คืน selector ของหกขั้นในคู่มือหน้ารายการใบเบิก กลับเป็น .rowact[data-row-act="..."]
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/065_guide_request_menu_selectors_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="approvelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="approvelink"]', '.rowact[data-row-act="approvelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="openapprovelink"]', '.rowact[data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="edit"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="edit"]', '.rowact[data-row-act="edit"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="sendback"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="sendback"]', '.rowact[data-row-act="sendback"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="cancel"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="cancel"]', '.rowact[data-row-act="cancel"]');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="mail"]'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` IN ('[data-row-act="mail"]', '.rowact[data-row-act="mail"]');
