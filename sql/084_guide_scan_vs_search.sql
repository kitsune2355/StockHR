-- ============================================================
--  084_guide_scan_vs_search.sql
--  คู่มือหน้าทำรายการเบิก: ช่องยิงบาร์โค้ดย้ายไปอยู่หลังปุ่ม "ยิงบาร์โค้ด"
--
--  เดิมช่องยิง (#scanInput) ยาวเต็มแถวอยู่เหนือสองฝั่ง หน้าตาเหมือนช่องค้นหา (#tfSearch)
--  ผู้ใช้จึงพิมพ์ผิดช่อง ตอนนี้หน้าเบิกเหลือช่องพิมพ์ช่องเดียวคือช่องค้นหา
--  ช่องยิงต้องกดปุ่ม "ยิงบาร์โค้ด" ข้างช่องค้นหาเพื่อเปิดก่อน (แบบเดียวกับ stock_hl)
--  ปุ่มบันทึกย้ายไปอยู่แถบสรุปด้านล่าง
--
--  ไฟล์นี้เติมประโยคบอกวิธีเปิด/ปิดลงในสองขั้นตอนคู่มือที่ชี้ไปสองช่องนี้
--  รันซ้ำได้: เติมเฉพาะแถวที่ยังไม่มีประโยคนี้
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/084_guide_scan_vs_search.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/084_guide_scan_vs_search_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step`
SET `description` = CONCAT(`description`, ' ช่องยิงเปิดด้วยปุ่ม "ยิงบาร์โค้ด" ข้างช่องค้นหา ปิดด้วย × หรือกด Esc ถ้าเปิดค้างไว้ เข้าหน้านี้ครั้งต่อไปจะเปิดรอไว้ให้')
WHERE `step_id` = 207 AND `target_selector` = '#scanInput'
  AND `description` NOT LIKE '%ช่องยิงเปิดด้วยปุ่ม%';

UPDATE `tb_guide_step`
SET `description` = CONCAT(`description`, ' ถ้ามีเครื่องยิงบาร์โค้ด กดปุ่ม "ยิงบาร์โค้ด" ข้างช่องนี้เพื่อเปิดช่องยิง')
WHERE `step_id` = 105 AND `target_selector` = '#tfSearch'
  AND `description` NOT LIKE '%เพื่อเปิดช่องยิง%';

-- ---------- หน้าบันทึกรับเข้า / บันทึกจ่ายออก: แบบเดียวกัน ----------
-- ช่องยิงอยู่ในกรอบโหมดยิง (เปิดรอไว้ตั้งแต่แรก ปิดแล้วระบบจำไว้)
-- ปุ่มบันทึกย้ายลงแถบสรุปด้านล่าง ยอดรวมจึงอยู่ทางซ้ายของปุ่ม ไม่ใช่ "เหนือ" ปุ่มแล้ว
UPDATE `tb_guide_step`
SET `description` = CONCAT(`description`, ' ช่องยิงอยู่ในกรอบโหมดยิงบาร์โค้ด ถ้าปิดไว้ กดปุ่ม "ยิงบาร์โค้ด" ที่แถบด้านบนเพื่อเปิด')
WHERE `step_id` IN (206, 208) AND `target_selector` = '#scanInput'
  AND `description` NOT LIKE '%อยู่ในกรอบโหมดยิงบาร์โค้ด%';

UPDATE `tb_guide_step`
SET `description` = REPLACE(`description`, 'ยอดรวมเป็นเงินอยู่เหนือปุ่มนี้', 'ยอดรวมเป็นเงินอยู่ทางซ้ายของปุ่มนี้ในแถบเดียวกัน')
WHERE `step_id` = 98 AND `target_selector` = '#btnSave';

SELECT `step_id`, `title`, `description` FROM `tb_guide_step` WHERE `step_id` IN (98, 105, 206, 207, 208);
