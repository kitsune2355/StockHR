-- ============================================================
--  058_guide_adjust_addrow.sql
--  แก้คู่มือขั้นที่ 4 ของหน้า "บันทึกปรับยอด" ให้ตรงกับ UI ใหม่
--
--  เดิม (ไฟล์ 057) ขั้นที่ 4 ชี้ที่ #adjPickBox ซึ่งเป็นช่องเลือกสินค้าเดี่ยว ๆ
--  ด้านบนตาราง ตอนนี้เปลี่ยนเป็นปุ่ม "เพิ่มรายการ" (#btnAddRow) แล้วเลือกสินค้า
--  ในช่องแรกของบรรทัด แบบเดียวกับหน้าบันทึกรับเข้า
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/058_guide_adjust_addrow.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/058_guide_adjust_addrow_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step`
SET `target_selector` = '#btnAddRow',
    `title`           = 'กดเพิ่มรายการ แล้วเลือกสินค้าในบรรทัด',
    `description`      = 'กดปุ่มนี้เพื่อเพิ่มบรรทัดว่าง แล้วเลือกสินค้าในช่องแรกของบรรทัด (พิมพ์รหัสหรือชื่อค้นได้) ช่อง "นับได้จริง" จะตั้งค่าเริ่มไว้เท่ากับยอดในระบบให้ แก้เฉพาะตัวที่นับได้ไม่ตรง ระบบคำนวณส่วนต่างให้เอง (เขียว = เกิน แดง = ขาด)'
WHERE `step_order` = 4
  AND `guide_id` = (SELECT `guide_id` FROM `tb_guide` WHERE `page_key` = 'adjust_new');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_new'
ORDER BY s.`step_order`;
