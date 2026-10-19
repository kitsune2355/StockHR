-- ============================================================
--  016_product_department_rollback.sql
--  เอาการจำกัดสินค้าตามหน่วยงานออก
--
--  ลบตารางเชื่อมทิ้ง ผลคือทุกหน่วยงานกลับไปเห็นสินค้าครบทุกรายการเหมือนเดิม
--  (กติกา "ไม่มีแถว = เบิกได้ทุกหน่วยงาน" ทำให้การลบตารางเท่ากับเปิดหมด)
--
--  คำเตือน: การตั้งค่าว่าหน่วยงานไหนเบิกอะไรได้ จะหายถาวร
--  ถ้าตั้งไว้เยอะแล้ว ควรสำรองก่อน:
--      mysqldump -u root -p1234 stock_hr tb_product_department > backup_pd.sql
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/016_product_department_rollback.sql
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_product_department`;

SELECT COUNT(*) AS `ตารางที่เหลือ`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tb_product_department';