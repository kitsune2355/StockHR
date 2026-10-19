-- ============================================================
--  076_departments_from_welfare_rollback.sql
--  ย้อนกลับไปใช้บริษัท/แผนกชุดเดิม (003) และการจับคู่ตั้งต้นของ 075
--
--  ใช้ได้เฉพาะตอนยังไม่มีเอกสาร/ผู้ใช้ผูกกับหน่วยงานใหม่
--  (ถ้ามี DELETE จะล้มเอง เพราะ FK ของเอกสารเป็น RESTRICT)
--
--  ต้องรันจากรากโปรเจกต์ (คำสั่ง SOURCE อ้างพาธจากโฟลเดอร์ที่รัน mysql):
--      cd C:/AppServ/www/stock_hr
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/076_departments_from_welfare_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_welfare_site_map`;
DELETE FROM `tb_product_department`;
DELETE FROM `tb_department`;
DELETE FROM `tb_company`;

SOURCE sql/003_import_company_department.sql;

INSERT IGNORE INTO `tb_welfare_site_map`
  (`welfare_site_id`, `welfare_site_name`, `department_id`, `created_at`, `created_by`)
VALUES
  ( 1, 'สำนักงานใหญ่ HL',               96, NOW(), 'hl-welfare'),
  ( 2, 'คลังสินค้า HL',                 93, NOW(), 'hl-welfare'),
  ( 3, 'สำนักงาน PRO',                  25, NOW(), 'hl-welfare'),
  ( 4, 'Pro Site — โครงการพระราม 9',    77, NOW(), 'hl-welfare'),
  ( 5, 'Pro Site — โครงการบางนา',       77, NOW(), 'hl-welfare'),
  ( 6, 'สำนักงาน CNS',                  71, NOW(), 'hl-welfare'),
  ( 7, 'หน่วยงาน อาคารสีลม',            26, NOW(), 'hl-welfare'),
  ( 8, 'หน่วยงาน โรงพยาบาลรามอินทรา',   73, NOW(), 'hl-welfare'),
  ( 9, 'สำนักงาน CIC',                  37, NOW(), 'hl-welfare'),
  (10, 'สำนักงาน AS',                   95, NOW(), 'hl-welfare'),
  (11, 'ไซต์ก่อสร้าง AS',               95, NOW(), 'hl-welfare');
