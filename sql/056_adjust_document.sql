-- ============================================================
--  056_adjust_document.sql
--  ทำให้ "การปรับยอดสินค้า" เป็นเอกสารมีเลขที่ + มีขั้นอนุมัติ
--
--  เดิม (ไฟล์ที่เพิ่มก่อนหน้า) การปรับยอดเขียนตรงลง tb_stock_movement
--  (move_type='adjust', doc_id=NULL) ยอดคงเหลือเปลี่ยนทันที ไม่มีเอกสาร ไม่มีเลขที่
--
--  ต่อจากนี้ การปรับยอดหนึ่งครั้ง = เอกสารหนึ่งใบใน tb_doc_header/tb_doc_item
--  เดินเส้นทาง waiting -> approved | rejected เหมือนใบเบิก
--  ยอดคงเหลือจะเปลี่ยน "ตอนอนุมัติ" เท่านั้น (ผ่าน stock_set() ใน backend/stock.php)
--
--  ไฟล์นี้ทำ 2 อย่าง:
--    1) เพิ่มค่า 'adjust' ต่อท้าย ENUM ของ tb_doc_header.doc_type
--       (status ENUM มี waiting/approved/rejected/cancelled อยู่แล้ว ไม่ต้องแก้
--        move_type ENUM ของ tb_stock_movement มี 'adjust' อยู่แล้ว ไม่ต้องแก้)
--    2) สร้างตาราง tb_adjust_approver = รายชื่อผู้ที่อนุมัติใบปรับยอดได้
--       (super_admin/admin อนุมัติได้เสมอ ไม่ต้องมีแถวในตารางนี้)
--
--  ---------------------------------------------------------------
--  ข้อจำกัด MySQL 5.0.51b ที่เลี่ยง
--  ---------------------------------------------------------------
--    - เพิ่มค่า ENUM ต่อท้ายอย่างเดียว ดัชนีตัวเลขของค่าเดิมไม่ขยับ
--    - ไม่มี generated column / CHECK
--    - คอลัมน์ที่ฐานข้อมูลเติมเวลาเองมีได้ตารางละตัว ยกให้ updated_at
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/056_adjust_document.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/056_adjust_document_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) เพิ่ม 'adjust' ต่อท้าย ENUM ของ doc_type
--     ยังไม่มีเอกสารประเภทนี้ในตาราง จึงไม่ต้องมีขั้นย้ายข้อมูลก่อน
-- ------------------------------------------------------------
ALTER TABLE `tb_doc_header`
  MODIFY COLUMN `doc_type` ENUM('receive','request','issue','adjust') NOT NULL
    COMMENT 'ประเภทเอกสาร';


-- ------------------------------------------------------------
--  2) tb_adjust_approver — รายชื่อผู้อนุมัติใบปรับยอด
--
--  หนึ่งแถว = ผู้ใช้หนึ่งคนที่กดอนุมัติ/ไม่อนุมัติใบปรับยอดได้
--  ไม่มีมิติหน่วยงาน เพราะการปรับยอดเป็นของคลังรวมทั้งระบบ
--  ตารางนี้ว่าง = ไม่มีใครนอกจาก super_admin/admin อนุมัติได้ (โค้ดถอยให้เอง)
--
--  โครงตาม tb_user_department (sql/054): PK เป็น username, FK ผูก tb_user,
--  audit ครบ 4 คอลัมน์ เพิ่ม/ลบทั้งชุดด้วย DELETE-all + INSERT (adjust_approver_save)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_adjust_approver` (

  `username`   VARCHAR(50) NOT NULL              COMMENT 'ผู้ใช้ที่อนุมัติใบปรับยอดได้',

  `created_at` DATETIME    NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by` VARCHAR(50) NULL     DEFAULT NULL,
  `updated_at` TIMESTAMP   NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50) NULL     DEFAULT NULL,

  PRIMARY KEY (`username`),
  KEY `idx_adjust_approver_created_by` (`created_by`),
  KEY `idx_adjust_approver_updated_by` (`updated_by`),

  CONSTRAINT `fk_adjust_approver_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adjust_approver_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_adjust_approver_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='รายชื่อผู้อนุมัติใบปรับยอดสินค้า';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `COLUMN_TYPE` AS `ชุดประเภทเอกสารใหม่`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`   = 'tb_doc_header'
  AND `COLUMN_NAME`  = 'doc_type';

SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'tb_adjust_approver';
