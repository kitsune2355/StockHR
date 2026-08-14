-- ============================================================
--  009_user_department.sql
--  ผูกผู้ใช้เข้ากับหน่วยงาน เพื่อใช้กำหนดขอบเขตการอนุมัติใบเบิก
--
--  ที่มา: ใบเบิกผูกกับหน่วยงานอยู่แล้ว (tb_doc_header.department_id)
--  แต่ผู้อนุมัติยังอนุมัติได้ทุกหน่วยงาน ซึ่งไม่ตรงกับการทำงานจริง
--  ที่หัวหน้าแต่ละหน่วยงานอนุมัติเฉพาะของหน่วยงานตัวเอง
--
--  ไม่ได้เพิ่มคอลัมน์ใหม่ — tb_user มี dept_id อยู่แล้วตั้งแต่ระบบเดิม
--  แต่ยังว่างทั้งตารางและไม่มีใครอ้างถึง จึงหยิบมาใช้แทนการสร้างคอลัมน์
--  ที่ความหมายซ้ำกัน (ถ้าเพิ่ม department_id อีกตัว จะมีสองช่องที่แปลว่า
--  หน่วยงานเหมือนกัน แล้วต้องมาเดาทุกครั้งว่าอันไหนคือของจริง)
--
--  สิ่งที่ไฟล์นี้ทำ:
--    1. ล้างค่าที่ชี้ไปยังหน่วยงานที่ไม่มีอยู่จริง (ถ้ามีตกค้าง)
--       ต้องทำก่อน ไม่งั้นสร้าง foreign key ไม่ผ่าน
--    2. เพิ่ม index + foreign key ไปที่ tb_department
--       ON DELETE SET NULL — ลบหน่วยงานแล้วผู้ใช้ยังอยู่ แค่ไม่มีต้นสังกัด
--       (ต่างจากเอกสารที่ใช้ RESTRICT เพราะเอกสารที่ไม่รู้ว่าใครเบิกไม่มีประโยชน์)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/009_user_department.sql
-- ============================================================

-- ------------------------------------------------------------
--  1) ล้างค่ากำพร้า
-- ------------------------------------------------------------
UPDATE `tb_user`
SET `dept_id` = NULL
WHERE `dept_id` IS NOT NULL
  AND `dept_id` NOT IN (SELECT `department_id` FROM `tb_department`);


-- ------------------------------------------------------------
--  2) index + foreign key
-- ------------------------------------------------------------
ALTER TABLE `tb_user`
  ADD KEY `idx_user_dept` (`dept_id`);

ALTER TABLE `tb_user`
  ADD CONSTRAINT `fk_user_dept` FOREIGN KEY (`dept_id`)
      REFERENCES `tb_department` (`department_id`)
      ON DELETE SET NULL ON UPDATE CASCADE;


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `CONSTRAINT_NAME`, `COLUMN_NAME`, `REFERENCED_TABLE_NAME`, `REFERENCED_COLUMN_NAME`
FROM `information_schema`.`KEY_COLUMN_USAGE`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'tb_user'
  AND `REFERENCED_TABLE_NAME` = 'tb_department';

SELECT COUNT(*) AS `ผู้ใช้ที่ยังไม่ได้ระบุหน่วยงาน`
FROM `tb_user` WHERE `dept_id` IS NULL;
