-- ============================================================
--  015_form_code_signature_rollback.sql
--  เอารหัสแบบฟอร์มและลายเซ็นผู้รับของออก
--
--  คำเตือน: ลายเซ็นที่ผู้รับของเซ็นไว้ทั้งหมดจะหายถาวร
--  ถ้าเคยใช้เป็นหลักฐานไปแล้ว ควรสำรองตารางไว้ก่อนรันไฟล์นี้:
--      mysqldump -u root -p1234 stock_hr tb_doc_header > backup_doc_header.sql
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/015_form_code_signature_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_company`    DROP COLUMN `form_code`;
ALTER TABLE `tb_doc_header` DROP COLUMN `receiver_sign`;

SHOW COLUMNS FROM `tb_company`;