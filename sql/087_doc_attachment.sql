-- ============================================================
--  087_doc_attachment.sql
--  ไฟล์แนบของเอกสาร — เริ่มใช้กับใบรับเข้า (รูป/ไฟล์ใบบิล เป็นหลักฐานให้ฝ่ายบัญชี)
--
--  ไฟล์จริงเก็บที่ storage/doc_attach/ (เปิดตรงผ่านเว็บไม่ได้ — ดู storage/.htaccess)
--  ดู/ดาวน์โหลดผ่าน api/attachment.php ซึ่งตรวจสิทธิ์แบบเดียวกับการเปิดเอกสารใบนั้น
--  ตารางนี้เก็บแค่ชื่อไฟล์ที่ระบบตั้งเอง + ชื่อเดิมไว้แสดง (ดู backend/doc_attachment.php)
--
--  ลบเอกสาร (ไม่มีในระบบ แต่เผื่อไว้) = แถวไฟล์แนบหายตาม ON DELETE CASCADE
--  ตัวไฟล์บนดิสก์ไม่หายตาม ต้องลบผ่าน doc_attachment_delete() เท่านั้น
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/087_doc_attachment.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/087_doc_attachment_rollback.sql
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `tb_doc_attachment` (

  `attach_id`   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_id`      INT(10) UNSIGNED NOT NULL              COMMENT 'เอกสารต้นสังกัด',
  `file_name`   VARCHAR(80)      NOT NULL              COMMENT 'ชื่อไฟล์ที่ระบบตั้งเองใน storage/doc_attach/',
  `orig_name`   VARCHAR(200)     NOT NULL DEFAULT ''   COMMENT 'ชื่อไฟล์เดิมจากเครื่องผู้ใช้ — ไว้แสดงเท่านั้น',
  `mime_type`   VARCHAR(50)      NOT NULL              COMMENT 'image/jpeg | image/png | image/gif | application/pdf',
  `file_size`   INT(10) UNSIGNED NOT NULL DEFAULT 0    COMMENT 'ขนาดไฟล์ที่เก็บจริง (ไบต์)',

  `created_at`  DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                       COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`  VARCHAR(50)      NULL     DEFAULT NULL COMMENT 'ผู้แนบ',
  `updated_at`  TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`  VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`attach_id`),
  UNIQUE KEY `uq_attach_file`       (`file_name`),
  KEY `idx_attach_doc`              (`doc_id`),
  KEY `idx_attach_created_by`       (`created_by`),
  KEY `idx_attach_updated_by`       (`updated_by`),

  CONSTRAINT `fk_attach_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attach_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_attach_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ไฟล์แนบของเอกสาร (ใบบิลของใบรับเข้า)';
