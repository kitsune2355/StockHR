-- ============================================================
--  083_guide_new_staff_no_kit_rollback.sql — ย้อน 083 (คืนข้อความตาม 073)
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '#nsUniform';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`is_active` = 1
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` IN ('#nsSecKit', '#nsKitLink');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = REPLACE(s.`description`, 'กรอก 2 ขั้น', 'กรอก 3 ขั้น')
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ขั้นที่ 3 วัสดุสำนักงานถาวร และมีมูลค่า',
    s.`description` = 'ของที่ตำแหน่งนี้ต้องใช้เพิ่ม เช่น เครื่องคิดเลข แม็กเย็บ ใช้แบบเดียวกับหน้าทำรายการเบิก: ค้นหาหรือกรองหมวดหมู่ด้านซ้าย แตะเพื่อเลือก แล้วกรอกจำนวนด้านขวา ของในชุดพื้นฐานจะไม่อยู่ในรายการนี้เพื่อไม่ให้เลือกซ้ำ ทุกรายการในขั้นนี้ถือว่า "ต้องคืน" ไม่มีก็ข้ามขั้นนี้ได้'
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '#nsSecExtra';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = REPLACE(s.`description`, 'ขั้นที่ 2', 'ขั้นที่ 3')
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '#nsSecNote';
