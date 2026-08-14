-- ============================================================
--  013_mail_sender_defaults.sql
--  ตั้งบัญชีผู้ส่งอีเมลของระบบให้เป็น no-reply@happyland.co.th
--
--  ที่มา: 011 ใส่ค่าตั้งต้นไว้เป็นพอร์ต 465/SSL และเว้นบัญชีผู้ส่งว่างไว้
--  แต่บัญชีที่องค์กรใช้จริงคือ no-reply@happyland.co.th ผ่านพอร์ต 587/TLS
--  ไฟล์นี้จึงตั้งค่าให้ตรงกับของจริง เพื่อให้ติดตั้งใหม่ที่ไหนก็ได้ค่าเดียวกัน
--
--  ---------------------------------------------------------------
--  ไฟล์นี้ไม่มีรหัสผ่าน และจะไม่มีวันมี
--  ---------------------------------------------------------------
--  ไฟล์ .sql อยู่ในโปรเจกต์ ถูกก๊อปตามไปทุกที่ที่โค้ดไป และเปิดอ่านได้ทั่วไป
--  รหัสผ่านที่ถูกเขียนลงไปครั้งหนึ่งจะกระจายไปโดยไม่มีใครตามเก็บได้
--
--  ให้ไปกรอกรหัสผ่านที่หน้า "ตั้งค่าระบบ" แทน ค่าจะถูกเก็บใน
--  tb_app_setting.smtp_pass ซึ่งอยู่ในฐานข้อมูลเท่านั้น
--  (เครื่องที่ใช้งานอยู่ตอนนี้กรอกไว้เรียบร้อยแล้ว ไฟล์นี้ไม่ไปแตะของเดิม)
--
--  ใช้ UPDATE ไม่ใช่ INSERT เพราะทุกคีย์ถูกสร้างไว้แล้วใน 011
--  รันซ้ำได้ไม่มีผลข้างเคียง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/013_mail_sender_defaults.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/013_mail_sender_defaults_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_app_setting` SET `setting_value` = 'smtp.zoho.com'
WHERE `setting_key` = 'smtp_host';

-- Zoho รับทั้ง 465/SSL และ 587/TLS — องค์กรนี้ใช้ 587
UPDATE `tb_app_setting` SET `setting_value` = '587'
WHERE `setting_key` = 'smtp_port';

UPDATE `tb_app_setting` SET `setting_value` = 'tls'
WHERE `setting_key` = 'smtp_secure';

UPDATE `tb_app_setting` SET `setting_value` = 'no-reply@happyland.co.th'
WHERE `setting_key` = 'smtp_user';

-- Zoho ปฏิเสธเมลที่ From ไม่ตรงกับบัญชีที่ยืนยันตัวตน จึงต้องเป็นค่าเดียวกัน
UPDATE `tb_app_setting` SET `setting_value` = 'no-reply@happyland.co.th'
WHERE `setting_key` = 'mail_from';

UPDATE `tb_app_setting` SET `setting_value` = 'ระบบสต็อกธุรการ Happyland'
WHERE `setting_key` = 'mail_from_name';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — smtp_pass ต้องไม่โผล่มาในผลลัพธ์
-- ------------------------------------------------------------
SELECT `setting_key`, `setting_value`
FROM `tb_app_setting`
WHERE `setting_key` IN ('smtp_host', 'smtp_port', 'smtp_secure',
                        'smtp_user', 'mail_from', 'mail_from_name', 'mail_enabled')
ORDER BY `setting_key`;

SELECT IF(`setting_value` = '', 'ยังไม่ได้กรอก — ไปกรอกที่หน้าตั้งค่าระบบ', 'กรอกไว้แล้ว')
       AS `สถานะรหัสผ่านแอป`
FROM `tb_app_setting` WHERE `setting_key` = 'smtp_pass';
