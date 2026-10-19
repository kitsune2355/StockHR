-- ============================================================
--  001_create_tb_user.sql
--  ตารางผู้ใช้งานระบบสต็อก HR stock_hr
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/001_create_tb_user.sql
--
--  เซิร์ฟเวอร์เป้าหมาย: MySQL 5.0.51b (AppServ) — มีข้อจำกัดหลายอย่าง
--  อ่าน "ขั้นที่ 0" ให้จบก่อนรัน
-- ============================================================


-- ------------------------------------------------------------
--  ขั้นที่ 0 — เปิด InnoDB ก่อน (ทำครั้งเดียว)
-- ------------------------------------------------------------
--  ตอนนี้ C:\AppServ\MySQL\my.ini บรรทัดที่ 183 มีคำสั่ง  skip-innodb
--  อยู่ ทำให้เซิร์ฟเวอร์ไม่รองรับ InnoDB เลย
--
--  ถ้ายังไม่เปิด InnoDB แล้วรันไฟล์นี้ จะได้ error ตรง ๆ ว่า
--      ERROR 1289 (HY000): The 'InnoDB' feature is disabled
--  (ทดสอบกับเซิร์ฟเวอร์ตัวนี้แล้ว — ไม่ได้เงียบ ๆ สร้างเป็น MyISAM ให้
--   ซึ่งถือว่าดี เพราะจะไม่มีทางได้ตารางที่ FK หายไปโดยไม่รู้ตัว)
--
--  วิธีเปิด:
--    1) ปิด service MySQL
--    2) แก้ C:\AppServ\MySQL\my.ini บรรทัด 183 จาก
--           skip-innodb
--       เป็น
--           #skip-innodb
--    3) เปิด service MySQL ใหม่
--    4) ตรวจว่าได้ผล:  SHOW ENGINES;  ต้องเห็น InnoDB เป็น YES ไม่ใช่ DISABLED
--
--  ค่า innodb_* อื่น ๆ ใน my.ini ตั้งไว้ครบอยู่แล้ว ไม่ต้องแก้เพิ่ม


-- ------------------------------------------------------------
--  ขั้นที่ 1 — สร้างตาราง
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_user` (

  -- ---------- ข้อมูลสำหรับเข้าสู่ระบบ ----------
  `username`        VARCHAR(50)   NOT NULL                COMMENT 'ชื่อผู้ใช้สำหรับ login และเป็น PK ของตาราง',
  `password`        CHAR(32)      NOT NULL                COMMENT 'md5(md5(md5(รหัสผ่าน))) ตามที่ checkmember.php ใช้',
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '1 = เข้าใช้งานได้, 0 = ถูกระงับ',

  -- ---------- ข้อมูลส่วนตัว ----------
  `first_name`      VARCHAR(100)  NOT NULL DEFAULT ''     COMMENT 'ชื่อ',
  `last_name`       VARCHAR(100)  NOT NULL DEFAULT ''     COMMENT 'นามสกุล',
  `full_name`       VARCHAR(150)  NOT NULL DEFAULT ''     COMMENT 'ชื่อเต็มที่แสดงบนหน้าจอ',
  `email`           VARCHAR(150)  NOT NULL DEFAULT ''     COMMENT 'อีเมล',

  -- ---------- สิทธิ์และสังกัด ----------
  `role`            ENUM('super_admin','employer','admin','general')
                                  NOT NULL DEFAULT 'general'
                                                          COMMENT 'ระดับสิทธิ์ ตรงกับเงื่อนไขใน checkmember.php',
  `position`        VARCHAR(100)  NOT NULL DEFAULT ''     COMMENT 'ตำแหน่ง',
  `position_level`  VARCHAR(20)   NOT NULL DEFAULT ''     COMMENT 'ระดับตำแหน่ง',
  `dept_id`         INT UNSIGNED  NULL     DEFAULT NULL   COMMENT 'รหัสแผนก',
  `agency_id`       INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT 'ag_id ของหน่วยงานหลัก, 0 = ยังไม่เลือก/มีหลายหน่วยงาน',

  -- ---------- audit (ต้องมีครบ 4 ตัวทุกตาราง) ----------
  -- created_at ให้แอปส่ง NOW() มาเอง เพราะ MySQL 5.0 ยอมให้มีคอลัมน์ที่ใช้
  -- CURRENT_TIMESTAMP อัตโนมัติได้แค่คอลัมน์เดียวต่อตาราง เราจึงยกสิทธิ์นั้น
  -- ให้ updated_at ซึ่งได้ประโยชน์มากกว่า (อัปเดตเองทุกครั้งที่แถวถูกแก้)
  `created_at`      DATETIME      NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                          COMMENT 'เวลาที่สร้าง — แอปต้องส่ง NOW() มาเสมอ',
  `created_by`      VARCHAR(50)   NULL     DEFAULT NULL   COMMENT 'username ของคนที่สร้าง',
  `updated_at`      TIMESTAMP     NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                                                          COMMENT 'เวลาที่แก้ล่าสุด — ฐานข้อมูลจัดการเอง',
  `updated_by`      VARCHAR(50)   NULL     DEFAULT NULL   COMMENT 'username ของคนที่แก้ล่าสุด',

  PRIMARY KEY (`username`),

  -- index สำหรับ query ที่ใช้จริงในระบบ
  KEY `idx_tb_user_agency_id`  (`agency_id`),
  KEY `idx_tb_user_is_active`  (`is_active`),
  KEY `idx_tb_user_created_at` (`created_at`),
  KEY `idx_tb_user_created_by` (`created_by`),
  KEY `idx_tb_user_updated_by` (`updated_by`),

  -- FK ชี้กลับมาที่ตารางตัวเอง เพราะคนสร้าง/คนแก้ ก็คือผู้ใช้ในระบบนี้
  -- ลบผู้ใช้แล้วไม่ลบประวัติตาม (SET NULL) และเปลี่ยน username แล้วตามไปแก้ให้ (CASCADE)
  CONSTRAINT `fk_tb_user_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tb_user_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ผู้ใช้งานระบบสต็อก HR';


-- ------------------------------------------------------------
--  ขั้นที่ 2 — ตรวจว่าได้ InnoDB จริง ไม่ใช่ MyISAM
-- ------------------------------------------------------------
--  ถ้าผลลัพธ์ออกมาเป็น MyISAM แปลว่ายังไม่ได้ทำขั้นที่ 0
--  ให้ DROP TABLE tb_user ทิ้ง เปิด InnoDB แล้วรันไฟล์นี้ใหม่
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`   = 'tb_user';


-- ------------------------------------------------------------
--  ขั้นที่ 3 — สร้างผู้ใช้ super_admin คนแรก
-- ------------------------------------------------------------
--  แถวแรกของระบบไม่มีคนสร้าง created_by จึงเป็น NULL ได้
--  (ถ้าใส่ username ที่ยังไม่มีในตาราง FK จะปฏิเสธทันที)
--
--  รหัสผ่านด้านล่างคือ md5(md5(md5('admin1234'))) — เปลี่ยนทันทีหลังเข้าระบบครั้งแรก
INSERT INTO `tb_user`
  (`username`, `password`, `is_active`,
   `first_name`, `last_name`, `full_name`, `email`,
   `role`, `position`, `position_level`, `agency_id`,
   `created_at`, `created_by`)
VALUES
  ('admin', MD5(MD5(MD5('admin1234'))), 1,
   'ผู้ดูแล', 'ระบบ', 'ผู้ดูแลระบบ', 'admin@happylandgroup.biz',
   'super_admin', 'ผู้ดูแลระบบ', '1', 0,
   NOW(), NULL);
