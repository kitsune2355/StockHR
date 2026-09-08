-- ============================================================
--  058_guide_adjust_addrow_rollback.sql
--  คืนคู่มือขั้นที่ 4 ของหน้า "บันทึกปรับยอด" กลับเป็นแบบไฟล์ 057
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/058_guide_adjust_addrow_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step`
SET `target_selector` = '#adjPickBox',
    `title`           = 'เลือกสินค้าทีละตัวเข้ามาในใบ',
    `description`      = 'พิมพ์รหัสหรือชื่อสินค้าเพื่อค้นหา ช่อง "นับได้จริง" ตั้งค่าเริ่มไว้เท่ากับยอดในระบบให้แล้ว แก้เฉพาะตัวที่นับได้ไม่ตรง ระบบคำนวณส่วนต่างให้เอง (เขียว = เกิน แดง = ขาด)'
WHERE `step_order` = 4
  AND `guide_id` = (SELECT `guide_id` FROM `tb_guide` WHERE `page_key` = 'adjust_new');

SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_new'
ORDER BY s.`step_order`;
