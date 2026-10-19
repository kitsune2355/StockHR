-- ============================================================
--  039_reset_transactions.sql
--  ล้างข้อมูลการเคลื่อนไหวทั้งหมด เพื่อเตรียมนำเข้าทะเบียนสินค้าชุดใหม่
--
--  ทำไมต้องล้าง:
--    ไฟล์ 006 ฉบับใหม่ (18-8-69) จัดรหัสสินค้าใหม่ทั้งชุด ทั้ง product_id
--    และ product_code ไม่ตรงกับของเดิม เช่น "ผ้าพันแผล" ย้ายจาก
--    CS-MED-001 (id 63) ไปเป็น CS-MED-003 (id 307) ส่วน id 63 กลายเป็น
--    "เครื่องเหลาดินสอ" ถ้าเก็บเอกสารเก่าไว้ ใบเบิก/รับ/จ่ายที่พิมพ์ออกมา
--    จะแสดงชื่อสินค้าผิดโดยไม่มีอะไรเตือน
--
--  ตารางที่ "ไม่" แตะ: ผู้ใช้ บริษัท แผนก ตั้งค่าระบบ สิทธิ์เมนู คู่มือ
--
--  ลำดับการรัน:
--    1) mysql -u root -p1234 stock_hr < sql/039_reset_transactions.sql
--    2) mysql -u root -p1234 stock_hr < sql/006_import_product_catalog.sql
--    3) mysql -u root -p1234 stock_hr < sql/040_seed_stock_balance.sql
--
--  ย้อนกลับ: ไม่มี — ใช้ไฟล์สำรองใน C:\AppServ\backup_stock_hr\ แทน
-- ============================================================

SET NAMES utf8;
START TRANSACTION;

-- tb_doc_header มี foreign key อ้างตัวเอง (ref_doc_id -> doc_id) ใบจ่ายออกผูก
-- กลับไปที่ใบเบิกต้นทาง ลบรวดเดียวจึงชนคีย์ ต้องปิดการตรวจ FK ชั่วคราว
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM `tb_doc_item`;
DELETE FROM `tb_doc_header`;
DELETE FROM `tb_stock_movement`;
DELETE FROM `tb_stock_balance`;
DELETE FROM `tb_product_department`;

-- แจ้งเตือนและคิวเมลอ้างเลขเอกสารที่กำลังจะหายไป ต้องล้างตาม
DELETE FROM `tb_notification`;
DELETE FROM `tb_mail_queue`;

SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `tb_doc_item`           AUTO_INCREMENT = 1;
ALTER TABLE `tb_doc_header`         AUTO_INCREMENT = 1;
ALTER TABLE `tb_stock_movement`     AUTO_INCREMENT = 1;
ALTER TABLE `tb_stock_balance`      AUTO_INCREMENT = 1;
ALTER TABLE `tb_product_department` AUTO_INCREMENT = 1;
ALTER TABLE `tb_notification`       AUTO_INCREMENT = 1;
ALTER TABLE `tb_mail_queue`         AUTO_INCREMENT = 1;

COMMIT;

SELECT 'tb_doc_header' AS ตาราง, COUNT(*) AS เหลือ FROM tb_doc_header
UNION ALL SELECT 'tb_doc_item',           COUNT(*) FROM tb_doc_item
UNION ALL SELECT 'tb_stock_movement',     COUNT(*) FROM tb_stock_movement
UNION ALL SELECT 'tb_stock_balance',      COUNT(*) FROM tb_stock_balance
UNION ALL SELECT 'tb_product_department', COUNT(*) FROM tb_product_department
UNION ALL SELECT 'tb_notification',       COUNT(*) FROM tb_notification
UNION ALL SELECT 'tb_mail_queue',         COUNT(*) FROM tb_mail_queue
UNION ALL SELECT 'tb_user (ต้องคงเดิม)',  COUNT(*) FROM tb_user;
