-- ============================================================
--  072_new_staff_request_rollback.sql
--  ย้อน 072_new_staff_request.sql
--
--  ใบเบิกพนักงานใหม่ที่ส่งไปแล้วยังอยู่ครบในฐานะใบเบิกธรรมดา
--  (tb_doc_header/tb_doc_item ไม่ถูกลบ) แค่เสียชื่อพนักงานใหม่กับธง "ต้องคืน"
--  ถ้าต้องเก็บไว้ ให้ export สองตารางนี้ออกก่อนรัน
--
--  ย้อนโค้ดก่อนแล้วค่อยรันไฟล์นี้ ไม่งั้นหน้ารายการเอกสารจะ query ไม่ผ่าน
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/072_new_staff_request_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_app_setting` WHERE `setting_key` = 'new_staff_terms';

ALTER TABLE `tb_doc_item` DROP COLUMN `must_return`;

DROP TABLE IF EXISTS `tb_new_staff_kit`;
DROP TABLE IF EXISTS `tb_doc_new_staff`;
