-- ============================================================
--  086_guide_review_pane_rollback.sql — ย้อน 086 (เอาขั้นแผงรายละเอียดออก เลื่อนขั้นเดิมกลับที่)
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
INNER JOIN (
    SELECT s2.`guide_id`, COUNT(*) AS `c`
    FROM `tb_guide_step` s2
    INNER JOIN `tb_guide` g2 ON g2.`guide_id` = s2.`guide_id`
    WHERE g2.`page_key` IN ('request_list', 'new_staff_list')
      AND (s2.`target_selector` LIKE '#rv%' OR s2.`target_selector` = '#fpToggle')
    GROUP BY s2.`guide_id`
) x ON x.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - x.`c`
WHERE s.`step_order` >= 6
  AND s.`target_selector` NOT LIKE '#rv%' AND s.`target_selector` <> '#fpToggle';

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('request_list', 'new_staff_list')
  AND (s.`target_selector` LIKE '#rv%' OR s.`target_selector` = '#fpToggle');
