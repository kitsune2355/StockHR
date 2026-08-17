-- ============================================================
--  038_create_tb_time_login.sql
--  ตารางบันทึกเวลาเข้าใช้งาน
--
--  ตารางนี้เป็นของระบบเดิมที่ config_ctrl/ ถูกคัดลอกมา ไม่เคยมีใน migration
--  ของโปรเจกต์นี้ โค้ดฝั่ง login จึงเขียนลงตารางที่ไม่มีอยู่มาตลอด
--
--  ตอนนี้ checkmember.php ห่อ try/catch ไว้แล้ว ไม่มีตารางก็เข้าระบบได้
--  รันไฟล์นี้ถ้าอยากให้บันทึกจริง ไม่รันก็ไม่มีอะไรเสีย
--
--  ยังไม่มีหน้าจอไหนอ่านตารางนี้ เก็บไว้เป็นร่องรอยสำหรับตรวจย้อนหลัง
--  ว่าใครเข้าระบบเมื่อไร (เช่นตอนสงสัยว่ามีคนใช้บัญชีร่วมกัน)
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 stock_hl < sql/038_create_tb_time_login.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/038_create_tb_time_login_rollback.sql
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `tb_time_login` (
  `tl_id`   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tl_name` VARCHAR(50)      NOT NULL              COMMENT 'username ที่เข้าระบบ',
  `tl_time` DATETIME         NOT NULL              COMMENT 'เวลาที่เข้าระบบ',
  PRIMARY KEY (`tl_id`),
  KEY `idx_tl_name` (`tl_name`),
  KEY `idx_tl_time` (`tl_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='บันทึกเวลาเข้าใช้งาน';
