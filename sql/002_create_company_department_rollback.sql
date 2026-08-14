-- ============================================================
--  002_create_company_department_rollback.sql
--  ย้อนสิ่งที่ 002_create_company_department.sql ทำไว้
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/002_create_company_department_rollback.sql
--
--  คำเตือน: ลบตารางบริษัทและแผนก พร้อมข้อมูลทั้งหมด
--  สำรองก่อนเสมอ:
--      mysqldump -u root -p1234 stock_hl tb_company tb_department > backup_company_YYYYMMDD.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ลบจากตารางลูกไปหาตารางแม่
DROP TABLE IF EXISTS `tb_department`;
DROP TABLE IF EXISTS `tb_company`;

-- ตารางประเภทแผนกที่เคยสร้างไว้ก่อนตัดสินใจไม่ใช้
DROP TABLE IF EXISTS `tb_departmenttype`;

-- ตารางชุดแรกที่เคยออกแบบไว้ก่อนได้ไฟล์ตัวอย่างจากระบบเดิม
DROP TABLE IF EXISTS `department`;
DROP TABLE IF EXISTS `company`;

SET FOREIGN_KEY_CHECKS = 1;
