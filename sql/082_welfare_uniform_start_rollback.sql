-- ============================================================
--  082_welfare_uniform_start_rollback.sql — ย้อน 082
--  ย้อนโค้ดก่อนก็ได้หรือไม่ก็ได้ — โค้ดตรวจว่ามีตารางก่อนใช้
--  (ไม่มี = แผงชุดฟอร์มขึ้นว่า "ยังไม่ได้รัน 082" และ sync ที่ส่ง uniform_start มาจะถูกปฏิเสธ)
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_welfare_uniform_rule`;

ALTER TABLE `tb_welfare_site_map` DROP COLUMN `uniform_synced_at`;
