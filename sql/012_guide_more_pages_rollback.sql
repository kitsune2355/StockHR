-- ============================================================
--  012_guide_more_pages_rollback.sql
--  เอาคู่มือ 5 หน้าที่ 012 เพิ่มไว้ออก
--
--  ลบเฉพาะแถวใน tb_guide ของห้าหน้านั้น ส่วนขั้นตอนถูกลบตามไปเองด้วย
--  foreign key fk_step_guide ที่ตั้ง ON DELETE CASCADE ไว้
--
--  ไม่แตะคู่มือ 13 หน้าที่มาจาก 011 และไม่แตะตารางอื่นเลย
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/012_guide_more_pages_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide`
WHERE `page_key` IN ('product_category', 'receive_history', 'report_movement',
                     'agencies', 'permissions');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — ต้องเหลือ 13 หน้า
-- ------------------------------------------------------------
SELECT COUNT(*) AS `คู่มือที่เหลือ` FROM `tb_guide`;
