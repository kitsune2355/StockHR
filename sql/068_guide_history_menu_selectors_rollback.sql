-- ============================================================
--  068_guide_history_menu_selectors_rollback.sql
--  คืน selector ของขั้นในเมนู ⋮ (หน้าประวัติ รับเข้า/จ่ายออก/ปรับยอด)
--  กลับเป็น .rowact[data-row-act="..."]
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/068_guide_history_menu_selectors_rollback.sql
-- ============================================================

SET NAMES utf8;

-- ---------- adjust_history ----------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="approvelink"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="approvelink"]', '[data-row-act="approvelink"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="openapprovelink"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="openapprovelink"]', '[data-row-act="openapprovelink"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="mail"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="mail"]', '[data-row-act="mail"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="cancel"]'
WHERE g.`page_key` = 'adjust_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="cancel"]', '[data-row-act="cancel"]');


-- ---------- issue_history ----------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="copylink"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="copylink"]', '[data-row-act="copylink"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="openlink"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="openlink"]', '[data-row-act="openlink"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="cancel"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="cancel"]', '[data-row-act="cancel"]');

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="mail"]'
WHERE g.`page_key` = 'issue_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="mail"]', '[data-row-act="mail"]');


-- ---------- receive_history ----------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '.rowact[data-row-act="cancel"]'
WHERE g.`page_key` = 'receive_history'
  AND s.`target_selector` IN ('.rowmenu-demo [data-row-act="cancel"]', '[data-row-act="cancel"]');
