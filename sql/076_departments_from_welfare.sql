-- ============================================================
--  076_departments_from_welfare.sql
--  ตั้งบริษัท/หน่วยงานให้ตรงกับระบบสวัสดิการ (hl-welfare หน้า "หน่วยงาน")
--
--  ของเดิม (003) เป็นแผนกของฝ่ายธุรการ 62 แผนกใน 10 บริษัท
--  ระบบสต็อก HR เบิกเสื้อให้พนักงานตามหน่วยงานที่ระบบสวัสดิการใช้
--  จึงเหลือ 5 บริษัท 11 หน่วยงาน ตามรายการนั้นทุกตัว
--
--    บริษัท (รหัสในระบบสวัสดิการ -> tb_company เดิม ใช้ชื่อตามทะเบียน
--            เพราะชื่อบริษัทพิมพ์อยู่บนหัวเอกสาร):
--      HL  -> 12 บริษัท แฮปปี้แลนด์ จำกัด
--      PRO ->  4 บริษัท โปรแอ็คทีฟ แมเนจเม้นท์ จำกัด   (คงรหัสแบบฟอร์ม/โลโก้เดิม)
--      CNS -> 11 บริษัท คลีนนิ่ง โซลูชั่น จำกัด        (คงรหัสแบบฟอร์ม/โลโก้เดิม)
--      CIC ->  1 บริษัท คอน.อิน.คอน จำกัด
--      AS  -> 14 บริษัท อาเชี่ยน คอนสตรั๊คชั่น จำกัด
--
--    หน่วยงาน: department_id = id หน่วยงานของระบบสวัสดิการ (1-11)
--      tb_welfare_site_map (075) จึงจับคู่ตรงตัว 1:1 ไม่ต้องจำว่าอะไรคู่กับอะไร
--
--  ตัวกันพลาด (รันบนฐานที่ใช้งานแล้วจะไม่ทำให้ข้อมูลหาย):
--    - มีผู้ใช้ผูกกับหน่วยงานเดิม -> หยุดก่อนลบ (ไม่งั้น FK จะล้างการผูกเงียบ ๆ)
--      ข้อความที่เห็น: Duplicate entry 'มีผู้ใช้ผูกกับหน่วยงานเดิม ...'
--    - มีใบเบิก/รับ/จ่ายอ้างหน่วยงานเดิม -> คำสั่ง DELETE ล้มเอง (FK RESTRICT)
--    ทั้งสองกรณี transaction ถูกย้อนกลับ
--
--  ต้องรันหลัง 075 (ตาราง tb_welfare_site_map)
--
--  วิธีรัน (ส่งเป็นไฟล์ ไม่งั้นภาษาไทยเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/076_departments_from_welfare.sql
-- ============================================================

SET NAMES utf8;

-- ---------- ตัวกันพลาด: ผู้ใช้ที่ยังผูกกับหน่วยงานเดิม ----------
-- MySQL 5.0 ไม่มี SIGNAL จึงใส่ข้อความเดียวกันลง PRIMARY KEY สองครั้ง
-- ถ้ามีผู้ใช้ผูกอยู่ ครั้งที่สองชนคีย์ mysql หยุดทั้งไฟล์ทันที
CREATE TEMPORARY TABLE `_guard_076` (`msg` VARCHAR(120) NOT NULL PRIMARY KEY);
INSERT INTO `_guard_076` SELECT 'มีผู้ใช้ผูกกับหน่วยงานเดิม — ย้ายผู้ใช้ออกก่อนรันไฟล์นี้' FROM DUAL
WHERE EXISTS (SELECT 1 FROM `tb_user` WHERE `dept_id` IS NOT NULL)
   OR EXISTS (SELECT 1 FROM `tb_user_department`);
INSERT INTO `_guard_076` SELECT 'มีผู้ใช้ผูกกับหน่วยงานเดิม — ย้ายผู้ใช้ออกก่อนรันไฟล์นี้' FROM DUAL
WHERE EXISTS (SELECT 1 FROM `tb_user` WHERE `dept_id` IS NOT NULL)
   OR EXISTS (SELECT 1 FROM `tb_user_department`);
DROP TEMPORARY TABLE `_guard_076`;

START TRANSACTION;

-- ---------- ล้างหน่วยงานเดิม ----------
DELETE FROM `tb_welfare_site_map`;     -- FK RESTRICT ต้องลบก่อน แล้วใส่ใหม่ด้านล่าง
DELETE FROM `tb_product_department`;   -- การจำกัดสินค้าตามหน่วยงานเดิม ไม่มีความหมายแล้ว
DELETE FROM `tb_department`;           -- ล้มเองถ้ามีเอกสารอ้างอยู่

-- ---------- บริษัท: เหลือ 5 บริษัทของระบบสวัสดิการ ----------
DELETE FROM `tb_company` WHERE `company_id` NOT IN (1, 4, 11, 12, 14);
UPDATE `tb_company` SET `is_active` = 1 WHERE `company_id` IN (1, 4, 11, 12, 14);

-- ---------- หน่วยงาน (ลำดับและชื่อตามระบบสวัสดิการ) ----------
INSERT INTO `tb_department`
  (`department_id`, `department_name`, `company_id`, `is_active`, `create_date`, `created_by`)
VALUES
  ( 1, 'สำนักงานใหญ่ HL',             12, 1, NOW(), NULL),
  ( 2, 'คลังสินค้า HL',               12, 1, NOW(), NULL),
  ( 3, 'สำนักงาน PRO',                 4, 1, NOW(), NULL),
  ( 4, 'Pro Site — โครงการพระราม 9',   4, 1, NOW(), NULL),
  ( 5, 'Pro Site — โครงการบางนา',      4, 1, NOW(), NULL),
  ( 6, 'สำนักงาน CNS',                11, 1, NOW(), NULL),
  ( 7, 'หน่วยงาน อาคารสีลม',          11, 1, NOW(), NULL),
  ( 8, 'หน่วยงาน โรงพยาบาลรามอินทรา', 11, 1, NOW(), NULL),
  ( 9, 'สำนักงาน CIC',                 1, 1, NOW(), NULL),
  (10, 'สำนักงาน AS',                 14, 1, NOW(), NULL),
  (11, 'ไซต์ก่อสร้าง AS',             14, 1, NOW(), NULL);

-- ---------- จับคู่กับระบบสวัสดิการ 1:1 ----------
INSERT INTO `tb_welfare_site_map`
  (`welfare_site_id`, `welfare_site_name`, `department_id`, `created_at`, `created_by`)
SELECT `department_id`, `department_name`, `department_id`, NOW(), NULL
FROM `tb_department`;

COMMIT;

-- ALTER TABLE ทำให้ commit อัตโนมัติ จึงต้องอยู่หลัง COMMIT
ALTER TABLE `tb_department` AUTO_INCREMENT = 1;

-- ---------- ตรวจผลลัพธ์ ----------
SELECT d.`department_id` AS `id`, c.`company_name` AS `บริษัท`, d.`department_name` AS `หน่วยงาน`,
       m.`welfare_site_id` AS `site_สวัสดิการ`
FROM `tb_department` d
JOIN `tb_company` c ON c.`company_id` = d.`company_id`
LEFT JOIN `tb_welfare_site_map` m ON m.`department_id` = d.`department_id`
ORDER BY d.`department_id`;
