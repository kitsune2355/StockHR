-- ============================================================
--  011_guide_notify_rollback.sql
--  ย้อนกลับสิ่งที่ 011_guide_notify.sql สร้างไว้
--
--  ลบจากตารางลูกไปหาตารางแม่ ไม่งั้น foreign key จะขวางไว้
--    tb_guide_step  -> อ้าง tb_guide
--    tb_guide_seen  -> อ้าง tb_user
--    tb_notification-> อ้าง tb_user
--
--  ปิดการตรวจ foreign key ระหว่างลบด้วย เผื่อมีตารางอื่นที่สร้างทีหลัง
--  มาอ้างถึงตารางเหล่านี้โดยที่ไฟล์นี้ไม่รู้
--
--  คำเตือน: ข้อมูลแจ้งเตือน คิวเมล และคู่มือทั้งหมดจะหายถาวร
--  ค่าตั้ง SMTP ก็หายด้วย ต้องกรอกใหม่ทั้งหมด
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/011_guide_notify_rollback.sql
-- ============================================================

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tb_mail_queue`;
DROP TABLE IF EXISTS `tb_notification`;
DROP TABLE IF EXISTS `tb_guide_seen`;
DROP TABLE IF EXISTS `tb_guide_step`;
DROP TABLE IF EXISTS `tb_guide`;
DROP TABLE IF EXISTS `tb_app_setting`;

SET FOREIGN_KEY_CHECKS = 1;


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — ต้องไม่เหลือสักตาราง
-- ------------------------------------------------------------
SELECT COUNT(*) AS `ตารางที่ยังเหลืออยู่`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_app_setting', 'tb_guide', 'tb_guide_step',
                       'tb_guide_seen', 'tb_notification', 'tb_mail_queue');
