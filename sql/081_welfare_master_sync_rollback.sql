-- ============================================================
--  081_welfare_master_sync_rollback.sql — ย้อน 081
--  ต้องย้อนโค้ดก่อน เพราะ backend/welfare_master.php ใช้ทั้งคอลัมน์และตารางนี้
--  บริษัท/หน่วยงานที่ sync เข้ามาแล้วยังอยู่ครบ (แค่ไม่มีรหัสไว้จับคู่)
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_welfare_sync_log`;

ALTER TABLE `tb_company`
  DROP INDEX `uq_company_code`,
  DROP COLUMN `company_code`;
