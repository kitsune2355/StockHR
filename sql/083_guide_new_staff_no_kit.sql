-- ============================================================
--  083_guide_new_staff_no_kit.sql
--  คู่มือหน้า "เบิกพนักงานใหม่" หลังซ่อนขั้นชุดพื้นฐาน
--
--  ชุดฟอร์มและอุปกรณ์พนักงานใหม่ตั้งที่ระบบสวัสดิการแล้ว
--  เมนู "อุปกรณ์พนักงานใหม่" (new_staff_kit) ถูกเอาออก และขั้น "ชุดพื้นฐาน" ในฟอร์มถูกซ่อน
--  ฟอร์มเหลือ 2 ขั้น: 1 ข้อมูลพนักงานใหม่ / 2 รายการที่เบิก
--
--  คู่มือเดิม (073) ชี้ไปที่ #nsSecKit และปุ่ม #nsKitLink ที่ไม่มีให้เห็นแล้ว
--    - ปิดสองขั้นนั้น (is_active = 0 ไม่ลบ ย้อนได้)
--    - ใช้ลำดับที่ 3 ที่ว่างอธิบายแผงชุดฟอร์มจากระบบสวัสดิการ (#nsUniform) แทน
--    - แก้เลขขั้นในข้อความ (3 ขั้น -> 2 ขั้น, ขั้นที่ 3 -> ขั้นที่ 2)
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/083_guide_new_staff_no_kit.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/083_guide_new_staff_no_kit_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`is_active` = 0
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` IN ('#nsSecKit', '#nsKitLink');

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = REPLACE(s.`description`, 'กรอก 3 ขั้น', 'กรอก 2 ขั้น')
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ขั้นที่ 2 รายการที่เบิก',
    s.`description` = 'เลือกของที่พนักงานใหม่ต้องใช้ ใช้แบบเดียวกับหน้าทำรายการเบิก: ค้นหาหรือกรองหมวดหมู่ด้านซ้าย แตะเพื่อเลือก แล้วกรอกจำนวนด้านขวา ทุกรายการในขั้นนี้ถือว่า "ต้องคืน" เมื่อพนักงานพ้นสภาพ'
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '#nsSecExtra';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = REPLACE(s.`description`, 'ขั้นที่ 3', 'ขั้นที่ 2')
WHERE g.`page_key` = 'new_staff_request' AND s.`target_selector` = '#nsSecNote';

-- แผงชุดฟอร์ม — ลงที่ลำดับ 3 ซึ่งว่างจากขั้นที่ปิดไป (ไม่ใส่ซ้ำถ้ารันสองรอบ)
INSERT INTO `tb_guide_step`
    (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `is_active`, `created_at`)
SELECT g.`guide_id`, 3, '#nsUniform', 'ชุดฟอร์มจากระบบสวัสดิการ',
       'แสดงชุดฟอร์มรอบเริ่มงานของหน่วยงานที่เลือก ตามเงื่อนไขที่ตั้งในระบบสวัสดิการ (อ่านอย่างเดียว) พนักงานได้ชุดฟอร์มผ่านใบ NRQ ที่ระบบสวัสดิการส่งมา ไม่ต้องใส่ในใบนี้',
       'bottom', 'start', 1, NOW()
FROM `tb_guide` g
WHERE g.`page_key` = 'new_staff_request'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` x
                  WHERE x.`guide_id` = g.`guide_id` AND x.`target_selector` = '#nsUniform');
