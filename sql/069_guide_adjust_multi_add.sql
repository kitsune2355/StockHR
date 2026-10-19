-- ============================================================
--  069_guide_adjust_multi_add.sql
--  คู่มือหน้าบันทึกปรับยอด: ปรับขั้น "เพิ่มรายการ" ให้ตรงกับปุ่มแบบใหม่
--
--  ที่มา: หน้าบันทึกปรับยอดเพิ่มช่องกรอก "จำนวนบรรทัด" ข้างปุ่ม "เพิ่มรายการ"
--  (#addCount) กดครั้งเดียวเพิ่มได้หลายบรรทัดพร้อมกัน — แบบเดียวกับหน้าผู้ใช้งาน
--
--  ไฟล์นี้ยังลบขั้น #btnPickMany ที่เคยเพิ่มไว้รอบก่อน (แนวทางกล่องเลือกสินค้า
--  ที่ยกเลิกไปแล้ว) ทิ้ง แล้วเลื่อนลำดับกลับ
--
--  รันซ้ำได้ (DELETE ก่อน, UPDATE เป็น idempotent)
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hr < sql/069_guide_adjust_multi_add.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/069_guide_adjust_multi_add_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ---------- ลบขั้น #btnPickMany (แนวทางที่ยกเลิก) ---------- */
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_new'
  AND s.`target_selector` = '#btnPickMany';

/* ---------- เลื่อนลำดับที่เคยถูกดันลงเพราะ #btnPickMany กลับขึ้นมา ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'adjust_new'
  AND s.`step_order` >= 5;

/* ---------- ข้อความขั้นปุ่ม "เพิ่มรายการ" (selector #btnAddRow) ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'เพิ่มรายการ — เพิ่มได้หลายบรรทัด',
    s.`description` = 'กรอกจำนวนบรรทัดที่ต้องการในช่องซ้ายมือ แล้วกดปุ่มนี้ เพิ่มทีเดียวได้หลายบรรทัดพร้อมกัน (กรอก 1 = เพิ่มบรรทัดเดียวแล้วเปิดช่องเลือกสินค้าให้เลย) จากนั้นเลือกสินค้าในช่องแรกของแต่ละบรรทัด (พิมพ์รหัสหรือชื่อค้นได้) ช่อง "นับได้จริง" ตั้งค่าเริ่มไว้เท่ายอดในระบบ แก้เฉพาะตัวที่นับได้ไม่ตรง ระบบคิดส่วนต่างให้เอง (เขียว = เกิน แดง = ขาด)'
WHERE g.`page_key` = 'adjust_new'
  AND s.`target_selector` = '#btnAddRow';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s
JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'adjust_new'
ORDER BY s.`step_order`;
