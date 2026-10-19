-- ============================================================
--  007_create_menu_permission.sql
--  สิทธิ์การเข้าถึงเมนู : เมนูไหน เปิดให้สิทธิ์ไหนเข้าได้บ้าง
--
--  เดิมกำหนดไว้ตายตัวในโค้ดที่ includes/menu.php (ตัวแปร $ROLE_ALL /
--  $ROLE_STAFF / $ROLE_ADMIN) จะเปลี่ยนทีต้องแก้โค้ดแล้วอัปโหลดใหม่
--  ตารางนี้ทำให้ปรับได้จากหน้าเว็บ โดยยังใช้ค่าในโค้ดเป็นค่าตั้งต้น
--
--  วิธีทำงาน:
--    - ถ้าเมนูไหน "ไม่มีแถวในตารางนี้เลย" ระบบใช้ค่าที่เขียนไว้ในโค้ด
--      (เพิ่มเมนูใหม่ในโค้ดแล้วใช้งานได้ทันที ไม่ต้องมารันคำสั่ง INSERT ก่อน)
--    - ถ้ามีแถว ระบบใช้ค่าจากตารางนี้แทนทั้งหมด
--
--  หมายเหตุเรื่อง super_admin:
--    ไม่เก็บในตารางนี้ และแก้จากหน้าเว็บไม่ได้ — เข้าได้ทุกเมนูเสมอ
--    เพื่อกันกรณีเผลอปิดสิทธิ์ตัวเองแล้วไม่มีใครเข้าไปแก้คืนได้อีกเลย
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/007_create_menu_permission.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `tb_menu_permission` (

  `menu_key`   VARCHAR(50) NOT NULL              COMMENT 'key ของเมนูตาม includes/menu.php',
  `role`       VARCHAR(20) NOT NULL              COMMENT 'admin / approver / general',
  `can_access` TINYINT(1)  NOT NULL DEFAULT 0    COMMENT '1 = เข้าได้, 0 = ไม่ให้เข้า',

  `created_at` DATETIME    NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                 COMMENT 'เวลาที่สร้าง — แอปส่ง NOW() มาเสมอ',
  `created_by` VARCHAR(50) NULL     DEFAULT NULL,
  `updated_at` TIMESTAMP   NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50) NULL     DEFAULT NULL,

  PRIMARY KEY (`menu_key`, `role`),
  KEY `idx_menu_permission_role`       (`role`),
  KEY `idx_menu_permission_created_by` (`created_by`),
  KEY `idx_menu_permission_updated_by` (`updated_by`),

  CONSTRAINT `fk_menu_permission_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_menu_permission_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='สิทธิ์การเข้าถึงเมนูของแต่ละ role';


SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'tb_menu_permission';
