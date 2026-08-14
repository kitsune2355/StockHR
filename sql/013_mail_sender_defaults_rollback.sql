-- ============================================================
--  013_mail_sender_defaults_rollback.sql
--  คืนค่าผู้ส่งอีเมลกลับเป็นค่าตั้งต้นของ 011
--
--  ล้างบัญชีผู้ส่งและปิดสวิตช์ส่งเมลด้วย เพราะถ้าคืนค่าโฮสต์กลับไปเป็น
--  465/SSL แต่ยังเปิดสวิตช์ค้างไว้ ระบบจะพยายามส่งด้วยค่าที่ใช้ไม่ได้
--  แล้วเมลทั้งคิวจะกลายเป็น failed ทีละฉบับจนครบจำนวนครั้งที่ยอมให้ลอง
--
--  ไม่ล้าง smtp_pass — รหัสผ่านไม่ได้ถูกตั้งโดยไฟล์ 013 จึงไม่ใช่หน้าที่
--  ของไฟล์นี้ที่จะไปลบ ถ้าต้องการล้างให้ทำที่หน้าตั้งค่าระบบ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/013_mail_sender_defaults_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_app_setting` SET `setting_value` = '465' WHERE `setting_key` = 'smtp_port';
UPDATE `tb_app_setting` SET `setting_value` = 'ssl' WHERE `setting_key` = 'smtp_secure';
UPDATE `tb_app_setting` SET `setting_value` = ''    WHERE `setting_key` = 'smtp_user';
UPDATE `tb_app_setting` SET `setting_value` = ''    WHERE `setting_key` = 'mail_from';
UPDATE `tb_app_setting` SET `setting_value` = '0'   WHERE `setting_key` = 'mail_enabled';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `setting_key`, `setting_value`
FROM `tb_app_setting`
WHERE `setting_key` IN ('smtp_host', 'smtp_port', 'smtp_secure',
                        'smtp_user', 'mail_from', 'mail_enabled')
ORDER BY `setting_key`;
