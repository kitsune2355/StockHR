-- ============================================================
--  005_create_product_catalog.sql
--  ทะเบียนสินค้า : หมวดหมู่หลัก -> หมวดหมู่ย่อย -> สินค้า (+ หน่วยนับ)
--
--  ที่มา: ไฟล์ stock_system.sql ที่แนบมา ซึ่งเขียนไว้สำหรับ MySQL 8.0
--  แต่เครื่องนี้เป็น MySQL 5.0.51b จึงรันไฟล์นั้นตรง ๆ ไม่ได้
--  รายการที่ต้องแก้และเหตุผล:
--
--    1. utf8mb4 -> utf8
--       MySQL 5.0 ยังไม่มี utf8mb4 (มาใน 5.5) ภาษาไทยใช้ utf8 3 ไบต์ได้ครบ
--       ที่เสียไปคืออีโมจิ ซึ่งไม่ได้ใช้ในชื่อสินค้า
--
--    2. ตัดคอลัมน์คำนวณอัตโนมัติ (stock_value, amount ที่เป็น ... AS (...) STORED)
--       generated column มาใน MySQL 5.7 ให้คำนวณตอน query แทน
--
--    3. DATETIME ที่ตั้ง DEFAULT CURRENT_TIMESTAMP ไม่ได้
--       MySQL 5.0 ให้ค่าเวลาอัตโนมัติได้เฉพาะชนิด TIMESTAMP และได้แค่
--       คอลัมน์เดียวต่อตาราง จึงยกให้ updated_at ส่วน created_at ให้แอปส่ง NOW() มา
--       (กติกาเดียวกับ tb_user / tb_company ที่ทำไว้แล้ว)
--
--    4. ตั้งชื่อตารางขึ้นต้นด้วย tb_ ให้ตรงกับของเดิมในฐานข้อมูลนี้
--       และไม่สร้างตาราง department ของไฟล์ที่แนบมา เพราะชื่อชนกับ
--       tb_department ที่มีอยู่แล้ว (ของเดิมคือแผนกในบริษัท ส่วนของไฟล์นั้น
--       คือหน่วยงานผู้เบิก คนละความหมายกัน) ไว้ค่อยตัดสินใจตอนทำหน้าเบิกจ่าย
--
--    5. เพิ่มคอลัมน์ audit ครบ 4 ตัว (created_at/by, updated_at/by) ตามกฎของโปรเจกต์
--       ไฟล์ที่แนบมามีแค่บางตาราง และไม่มี created_by/updated_by เลย
--
--  ไฟล์นี้สร้างเฉพาะตารางทะเบียนที่หน้า "ข้อมูลสินค้า" ต้องใช้
--  ส่วนตารางเคลื่อนไหวสต็อก (doc_header, doc_item, stock_movement,
--  stock_balance, supplier) จะสร้างตอนทำหน้ารับเข้า/เบิกออกจริง
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/005_create_product_catalog.sql
--      mysql -u root -p1234 stock_hr < sql/006_import_product_catalog.sql
-- ============================================================


-- ------------------------------------------------------------
--  ตารางที่ 1 : tb_unit (หน่วยนับ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_unit` (

  `unit_id`    INT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_name`  VARCHAR(50)     NOT NULL              COMMENT 'ชื่อหน่วยนับ เช่น กล่อง ม้วน ชุด',
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `created_at` DATETIME        NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                     COMMENT 'เวลาที่สร้าง — แอปส่ง NOW() มาเสมอ',
  `created_by` VARCHAR(50)     NULL     DEFAULT NULL COMMENT 'username ของคนที่สร้าง',
  `updated_at` TIMESTAMP       NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                                                     COMMENT 'เวลาที่แก้ล่าสุด — ฐานข้อมูลจัดการเอง',
  `updated_by` VARCHAR(50)     NULL     DEFAULT NULL COMMENT 'username ของคนที่แก้ล่าสุด',

  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uq_unit_name`      (`unit_name`),
  KEY `idx_unit_is_active`  (`is_active`),
  KEY `idx_unit_created_by` (`created_by`),
  KEY `idx_unit_updated_by` (`updated_by`),

  CONSTRAINT `fk_unit_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_unit_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='หน่วยนับสินค้า';


-- ------------------------------------------------------------
--  ตารางที่ 2 : tb_category (หมวดหมู่หลัก)
--  เช่น CS = วัสดุสิ้นเปลือง, OF = เครื่องเขียน
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_category` (

  `category_id`   INT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_code` VARCHAR(20)     NOT NULL              COMMENT 'รหัสหมวดหมู่หลัก ใช้ประกอบรหัสสินค้า',
  `category_name` VARCHAR(150)    NOT NULL              COMMENT 'ชื่อหมวดหมู่หลัก',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `created_at`    DATETIME        NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`    VARCHAR(50)     NULL     DEFAULT NULL,
  `updated_at`    TIMESTAMP       NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50)     NULL     DEFAULT NULL,

  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_category_code`     (`category_code`),
  KEY `idx_category_name`      (`category_name`),
  KEY `idx_category_is_active` (`is_active`),
  KEY `idx_category_created_by`(`created_by`),
  KEY `idx_category_updated_by`(`updated_by`),

  CONSTRAINT `fk_category_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_category_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='หมวดหมู่หลักของสินค้า';


-- ------------------------------------------------------------
--  ตารางที่ 3 : tb_sub_category (หมวดหมู่ย่อย)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_sub_category` (

  `sub_category_id`   INT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`       INT(5) UNSIGNED NOT NULL              COMMENT 'หมวดหมู่หลักต้นสังกัด',
  `sub_category_code` VARCHAR(20)     NOT NULL              COMMENT 'รหัสหมวดหมู่ย่อย ใช้ประกอบรหัสสินค้า',
  `sub_category_name` VARCHAR(150)    NOT NULL              COMMENT 'ชื่อหมวดหมู่ย่อย',
  `is_active`         TINYINT(1)      NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `created_at`        DATETIME        NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`        VARCHAR(50)     NULL     DEFAULT NULL,
  `updated_at`        TIMESTAMP       NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`        VARCHAR(50)     NULL     DEFAULT NULL,

  PRIMARY KEY (`sub_category_id`),
  UNIQUE KEY `uq_sub_category_code`     (`sub_category_code`),
  KEY `idx_sub_category_parent`    (`category_id`),
  KEY `idx_sub_category_name`      (`sub_category_name`),
  KEY `idx_sub_category_is_active` (`is_active`),
  KEY `idx_sub_category_created_by`(`created_by`),
  KEY `idx_sub_category_updated_by`(`updated_by`),

  -- ห้ามลบหมวดหมู่หลักที่ยังมีหมวดหมู่ย่อยอยู่ กันข้อมูลกำพร้า
  CONSTRAINT `fk_sub_category_parent` FOREIGN KEY (`category_id`)
      REFERENCES `tb_category` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_sub_category_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_category_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='หมวดหมู่ย่อยของสินค้า';


-- ------------------------------------------------------------
--  ตารางที่ 4 : tb_product (สินค้า / วัสดุ)
--
--  product_code  รหัสที่ระบบสร้างเอง รูปแบบ <รหัสหมวดย่อย>-<เลขรัน 3 หลัก>
--  old_code      รหัสเดิมจากไฟล์ Excel เก็บไว้ให้ค้นย้อนได้ ไม่บังคับไม่ซ้ำ
--                (ข้อมูลจริงมีรหัสเดิมซ้ำกันหลายรายการ เช่น 110301 มี 4 แถว
--                 จึงใส่ UNIQUE ไม่ได้ ทำได้แค่ index ธรรมดา)
--
--  unit_cost/min_qty/max_qty เป็น DECIMAL ไม่ใช่ FLOAT
--  เพราะเป็นจำนวนเงินและจำนวนนับ ต้องบวกลบแล้วไม่มีเศษหลุด
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_product` (

  `product_id`      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_code`    VARCHAR(30)      NOT NULL              COMMENT 'รหัสสินค้าของระบบ',
  `old_code`        VARCHAR(30)      NULL     DEFAULT NULL COMMENT 'รหัสเดิมจาก Excel',
  `product_name`    VARCHAR(300)     NOT NULL              COMMENT 'ชื่อสินค้า',
  `sub_category_id` INT(5) UNSIGNED  NOT NULL              COMMENT 'หมวดหมู่ย่อยที่สังกัด',
  `unit_id`         INT(5) UNSIGNED  NOT NULL              COMMENT 'หน่วยนับ',
  `unit_cost`       DECIMAL(12,4)    NOT NULL DEFAULT 0    COMMENT 'ต้นทุนต่อหน่วยล่าสุด',
  `min_qty`         DECIMAL(12,2)    NOT NULL DEFAULT 0    COMMENT 'จุดสั่งซื้อ — ต่ำกว่านี้ให้เตือน',
  `max_qty`         DECIMAL(12,2)    NOT NULL DEFAULT 0    COMMENT 'ระดับสูงสุดที่ควรเก็บ',
  `is_active`       TINYINT(1)       NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `created_at`      DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`      VARCHAR(50)      NULL     DEFAULT NULL,
  `updated_at`      TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`      VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`product_id`),
  UNIQUE KEY `uq_product_code`     (`product_code`),
  KEY `idx_product_sub`        (`sub_category_id`),
  KEY `idx_product_unit`       (`unit_id`),
  KEY `idx_product_name`       (`product_name`(100)),
  KEY `idx_product_old_code`   (`old_code`),
  KEY `idx_product_is_active`  (`is_active`),
  KEY `idx_product_created_by` (`created_by`),
  KEY `idx_product_updated_by` (`updated_by`),

  -- ห้ามลบหมวดหมู่ย่อย/หน่วยนับที่ยังมีสินค้าอ้างอยู่
  CONSTRAINT `fk_product_sub` FOREIGN KEY (`sub_category_id`)
      REFERENCES `tb_sub_category` (`sub_category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_product_unit` FOREIGN KEY (`unit_id`)
      REFERENCES `tb_unit` (`unit_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_product_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_product_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ทะเบียนสินค้า/วัสดุ';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — ต้องเป็น InnoDB ทั้งหมด
-- ------------------------------------------------------------
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_unit', 'tb_category', 'tb_sub_category', 'tb_product');
