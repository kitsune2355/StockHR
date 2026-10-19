-- ============================================================
--  056_adjust_document_rollback.sql
--  ย้อน 056_adjust_document.sql
--
--  ต้องไม่มีเอกสาร doc_type='adjust' เหลืออยู่ ไม่งั้น MODIFY ENUM จะทำให้
--  แถวเหล่านั้นกลายเป็นค่าว่าง — ลบใบปรับยอดทั้งหมดก่อนถ้ามี
--      DELETE FROM `tb_doc_header` WHERE `doc_type` = 'adjust';
--  (movement ที่ผูกอยู่จะเหลือ doc_id = NULL เพราะ fk_move_doc เป็น SET NULL)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/056_adjust_document_rollback.sql
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_adjust_approver`;

ALTER TABLE `tb_doc_header`
  MODIFY COLUMN `doc_type` ENUM('receive','request','issue') NOT NULL
    COMMENT 'ประเภทเอกสาร';

SELECT `COLUMN_TYPE` AS `ชุดประเภทเอกสารหลังย้อน`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`   = 'tb_doc_header'
  AND `COLUMN_NAME`  = 'doc_type';
