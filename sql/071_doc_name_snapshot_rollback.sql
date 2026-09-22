-- ============================================================
--  071_doc_name_snapshot_rollback.sql
--  ย้อน 071_doc_name_snapshot.sql
--
--  คำเตือน: ชื่อที่แช่แข็งไว้ทั้งหมด (ทั้งของเก่าที่ backfill และของใหม่
--  ที่บันทึกเข้ามาหลังไฟล์ 071) จะหายถาวร กลับไป join สดกับ tb_user
--  เหมือนเดิมทันทีที่ย้อนคืน — ถ้าต้องการเก็บไว้ดูย้อนหลัง สำรองก่อน:
--      mysqldump -u root -p1234 stock_hl tb_doc_header tb_doc_history > backup_doc_name_snapshot.sql
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/071_doc_name_snapshot_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_doc_header`  DROP COLUMN `requester_name`;
ALTER TABLE `tb_doc_header`  DROP COLUMN `approved_by_name`;
ALTER TABLE `tb_doc_header`  DROP COLUMN `created_by_name`;
ALTER TABLE `tb_doc_history` DROP COLUMN `actor_name`;

SHOW COLUMNS FROM `tb_doc_header`;
SHOW COLUMNS FROM `tb_doc_history`;
