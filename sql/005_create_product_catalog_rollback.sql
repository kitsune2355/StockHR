-- ============================================================
--  005_create_product_catalog_rollback.sql
--  ย้อนกลับ 005 — ลบตารางทะเบียนสินค้าทั้งหมด
--
--  ลบจากลูกไปหาแม่ ไม่งั้น FOREIGN KEY จะกันไว้
--      tb_product -> tb_sub_category -> tb_category
--      tb_product -> tb_unit
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/005_create_product_catalog_rollback.sql
-- ============================================================

DROP TABLE IF EXISTS `tb_product`;
DROP TABLE IF EXISTS `tb_sub_category`;
DROP TABLE IF EXISTS `tb_category`;
DROP TABLE IF EXISTS `tb_unit`;

SHOW TABLES;
