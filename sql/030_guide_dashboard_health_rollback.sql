-- ============================================================
--  030_guide_dashboard_health_rollback.sql
--  เอาขั้น "ความพร้อมของข้อมูล" ออก และคืนข้อความสองขั้นที่แก้ไป
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/030_guide_dashboard_health_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'dashboard' AND s.`target_selector` = '#bnHealth';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'dashboard' AND s.`step_order` >= 5;

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ต้องสั่งเพิ่ม',
    s.`description` = 'รายการที่ยอดคงเหลือถึงหรือต่ำกว่าจุดสั่งซื้อที่ตั้งไว้ในทะเบียนสินค้า ระวังเลขศูนย์ตรงนี้ — มันแปลได้สองอย่าง คือ "ของครบทุกรายการ" หรือ "ยังไม่ได้ตั้งจุดสั่งซื้อให้สินค้าเลยสักตัว" สินค้าที่ไม่ได้ตั้งจุดสั่งซื้อจะไม่มีวันโผล่ที่นี่ แม้ของจะหมดแล้วก็ตาม'
WHERE g.`page_key` = 'dashboard' AND s.`target_selector` = '#bnLows';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'สองตัวเลขนี้คนละเรื่องกัน — "ซื้อเข้า" คือเงินที่จ่ายไปกับของที่รับเข้าคลังเดือนนี้ ส่วน "เบิกไปใช้" คือมูลค่าของที่หน่วยงานเบิกออกไปใช้ ไม่จำเป็นต้องเท่ากัน เพราะของที่ซื้อเดือนนี้อาจถูกเบิกเดือนหน้า ทั้งคู่คิดจากราคาที่กรอกไว้ตอนรับเข้าจริง ใบที่ไม่ได้กรอกราคาจะถูกนับเป็นศูนย์ กดที่ไทล์เพื่อไปดูรายละเอียดแยกตามบริษัทและหน่วยงานได้'
WHERE g.`page_key` = 'dashboard' AND s.`target_selector` = '#bnMoney';
