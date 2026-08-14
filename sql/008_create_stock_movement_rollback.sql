-- ============================================================
--  008_create_stock_movement_rollback.sql
--  ย้อนไฟล์ 008 — ลบตารางเอกสารและสต๊อกทั้งหมด
--
--  คำเตือน: ลบแล้วประวัติการรับเข้า/เบิก/จ่ายหายทั้งหมด กู้ไม่ได้
--  ใช้ตอนติดตั้งใหม่หรือทดสอบเท่านั้น
--
--  ลำดับการลบต้องกลับทางกับตอนสร้าง ไม่งั้น foreign key ขวาง
--    movement -> อ้าง doc_header กับ product
--    balance  -> อ้าง product
--    doc_item -> อ้าง doc_header กับ product
--    doc_header -> อ้างตัวเอง (ref_doc_id) และ department
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/008_create_stock_movement_rollback.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tb_stock_movement`;
DROP TABLE IF EXISTS `tb_stock_balance`;
DROP TABLE IF EXISTS `tb_doc_item`;
DROP TABLE IF EXISTS `tb_doc_header`;

SET FOREIGN_KEY_CHECKS = 1;

SELECT `TABLE_NAME`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_doc_header', 'tb_doc_item', 'tb_stock_balance', 'tb_stock_movement');
