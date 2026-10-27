-- ============================================================
--  084_guide_scan_vs_search_rollback.sql — ย้อน 084 (ตัดประโยคที่เติมออก)
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step`
SET `description` = REPLACE(`description`, ' ช่องยิงเปิดด้วยปุ่ม "ยิงบาร์โค้ด" ข้างช่องค้นหา ปิดด้วย × หรือกด Esc ถ้าเปิดค้างไว้ เข้าหน้านี้ครั้งต่อไปจะเปิดรอไว้ให้', '')
WHERE `step_id` = 207;

UPDATE `tb_guide_step`
SET `description` = REPLACE(`description`, ' ถ้ามีเครื่องยิงบาร์โค้ด กดปุ่ม "ยิงบาร์โค้ด" ข้างช่องนี้เพื่อเปิดช่องยิง', '')
WHERE `step_id` = 105;

UPDATE `tb_guide_step`
SET `description` = REPLACE(`description`, ' ช่องยิงอยู่ในกรอบโหมดยิงบาร์โค้ด ถ้าปิดไว้ กดปุ่ม "ยิงบาร์โค้ด" ที่แถบด้านบนเพื่อเปิด', '')
WHERE `step_id` IN (206, 208);

UPDATE `tb_guide_step`
SET `description` = REPLACE(`description`, 'ยอดรวมเป็นเงินอยู่ทางซ้ายของปุ่มนี้ในแถบเดียวกัน', 'ยอดรวมเป็นเงินอยู่เหนือปุ่มนี้')
WHERE `step_id` = 98;
