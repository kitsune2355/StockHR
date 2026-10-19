-- ============================================================
--  075_welfare_bridge.sql
--  รับคำขอเบิกชุดฟอร์มจาก "ระบบสวัสดิการ" (hl-welfare)
--
--  ---------------------------------------------------------------
--  แนวคิด
--  ---------------------------------------------------------------
--  ระบบสวัสดิการตรวจสิทธิ์ชุดฟอร์มของพนักงานเอง (เริ่มงาน / ผ่านโปร / ประจำปี)
--  แล้วส่งคำขอมาทาง api/welfare.php ระบบสต็อกออก "ใบเบิกธรรมดาหนึ่งใบ"
--  (doc_type='request', ชุดเลข WRQ) ที่ "อนุมัติแล้ว" ทันที
--  เพราะสิทธิ์ผ่านการตรวจมาแล้ว ไม่ต้องให้ผู้อนุมัติดูซ้ำ
--  จากนั้นคลังจ่าย/เซ็นรับตามเส้นทางเดิมทุกอย่าง
--
--  ข้อมูลที่เพิ่มจากใบเบิกธรรมดาเก็บในตารางส่วนขยาย (แนวทางเดียวกับ 072)
--  ใบเบิกไหนมีแถวใน tb_doc_welfare = ใบจากระบบสวัสดิการ
--
--  ---------------------------------------------------------------
--  ไฟล์นี้ทำ 4 อย่าง
--  ---------------------------------------------------------------
--    1) tb_doc_welfare        ข้อมูลพนักงาน/สิทธิ์ของใบ (1 ใบ : 1 แถว)
--    2) tb_welfare_item_map   รายการของระบบสวัสดิการ -> หมวดย่อยสินค้า (แบบชาย/หญิง)
--    3) tb_welfare_site_map   หน่วยงานของระบบสวัสดิการ -> หน่วยงานในสต็อก
--    4) บัญชีระบบ hl-welfare  ผู้ขอเบิก/ผู้อนุมัติของใบ (ล็อกอินไม่ได้)
--
--  ค่าที่ใส่ในข้อ 2-3 เป็นค่าตั้งต้นสำหรับจำลอง (รหัสหน่วยงานของ mock ในระบบสวัสดิการ)
--  ของจริงต้องให้ HR ยืนยันการจับคู่อีกครั้ง
--
--  ข้อจำกัด MySQL 5.0 ที่เลี่ยง: utf8 เท่านั้น / DATETIME ให้แอปส่ง NOW() เอง
--  / TIMESTAMP อัตโนมัติตารางละตัว (ยกให้ updated_at)
--
--  วิธีรัน (ส่งเป็นไฟล์ ไม่งั้นภาษาไทยเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/075_welfare_bridge.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/075_welfare_bridge_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  4) บัญชีระบบ — สร้างก่อน เพราะตารางอื่นอ้าง created_by ถึง
--
--  is_active = 0 ล็อกอินหน้าเว็บไม่ได้ รหัสผ่านสุ่มทิ้ง
--  ใช้เป็น requester / approved_by / created_by ของใบจากระบบสวัสดิการเท่านั้น
-- ------------------------------------------------------------
INSERT IGNORE INTO `tb_user`
  (`username`, `password`, `is_active`, `first_name`, `last_name`, `full_name`,
   `role`, `created_at`)
VALUES
  ('hl-welfare', MD5(CONCAT(RAND(), NOW())), 0, 'ระบบสวัสดิการ', '', 'ระบบสวัสดิการ (อัตโนมัติ)',
   'general', NOW());


-- ------------------------------------------------------------
--  1) tb_doc_welfare — ข้อมูลพนักงาน/สิทธิ์ของใบเบิกจากระบบสวัสดิการ
--
--  source_ref  รหัสอ้างอิงสิทธิ์ของระบบสวัสดิการ "<employeeId>:<entitlementKey>"
--              เช่น "12:ANNUAL-2026" ส่งซ้ำได้หลายใบตลอดอายุ (ใบเก่าถูกยกเลิกแล้วส่งใหม่)
--  active_ref  = source_ref ตอนใบยังมีผล, NULL เมื่อใบถูกยกเลิก
--              UNIQUE กันสองคำขอสร้างใบซ้อนกันสำหรับสิทธิ์เดียวกัน
--              (MySQL ยอมให้ NULL ซ้ำได้ ใบที่ยกเลิกแล้วจึงไม่ขวาง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_doc_welfare` (

  `doc_id`            INT(10) UNSIGNED NOT NULL              COMMENT 'ใบเบิกต้นสังกัด (1:1)',
  `source_ref`        VARCHAR(80)      NOT NULL              COMMENT 'รหัสสิทธิ์ของระบบสวัสดิการ <employeeId>:<entitlementKey>',
  `active_ref`        VARCHAR(80)      NULL     DEFAULT NULL COMMENT '= source_ref ตอนใบมีผล, NULL เมื่อยกเลิก',
  `employee_id`       INT(10) UNSIGNED NOT NULL              COMMENT 'id พนักงานในระบบสวัสดิการ',
  `employee_code`     VARCHAR(30)      NOT NULL DEFAULT ''   COMMENT 'รหัสพนักงาน',
  `employee_name`     VARCHAR(150)     NOT NULL              COMMENT 'ชื่อ-นามสกุล',
  `position_name`     VARCHAR(150)     NOT NULL DEFAULT ''   COMMENT 'ตำแหน่ง',
  `company_code`      VARCHAR(10)      NOT NULL DEFAULT ''   COMMENT 'รหัสบริษัทในระบบสวัสดิการ เช่น HL / PRO',
  `site_id`           INT(10) UNSIGNED NOT NULL              COMMENT 'หน่วยงานในระบบสวัสดิการ',
  `site_name`         VARCHAR(150)     NOT NULL DEFAULT ''   COMMENT 'ชื่อหน่วยงาน ณ ตอนส่ง',
  `event`             ENUM('START','PROBATION','ANNUAL') NOT NULL COMMENT 'รอบสิทธิ์',
  `entitlement_key`   VARCHAR(30)      NOT NULL              COMMENT 'START / PROBATION / ANNUAL-YYYY',
  `requested_by_name` VARCHAR(150)     NOT NULL DEFAULT ''   COMMENT 'ชื่อ HR ที่กดส่งในระบบสวัสดิการ',

  `created_at`        DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                             COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`        VARCHAR(50)      NULL     DEFAULT NULL,
  `updated_at`        TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`        VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`doc_id`),
  UNIQUE KEY `uq_welfare_active_ref` (`active_ref`),
  KEY `idx_welfare_source_ref`  (`source_ref`),
  KEY `idx_welfare_employee`    (`employee_id`),
  KEY `idx_welfare_created_by`  (`created_by`),
  KEY `idx_welfare_updated_by`  (`updated_by`),

  CONSTRAINT `fk_welfare_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ข้อมูลพนักงาน/สิทธิ์ของใบเบิกที่มาจากระบบสวัสดิการ';


-- ------------------------------------------------------------
--  2) tb_welfare_item_map — รายการชุดฟอร์มของระบบสวัสดิการ -> หมวดย่อยสินค้า
--
--  item_code  รหัสรายการของระบบสวัสดิการ: POLO_BLACK / POLO_NAVY / TECH_SHIRT
--             / TECH_PANTS / SAFETY_SHOES
--  logo       โลโก้บริษัทบนเสื้อ (HL / PRO / AS ...) '' = ใช้ได้ทุกบริษัท
--  หนึ่งรายการได้หลายหมวดย่อย (แบบชาย/หญิง) ผู้ส่งเลือกแบบและไซซ์ตอนส่งเบิก
--  ไซซ์ = สินค้าแต่ละตัวในหมวดย่อย (1 ไซซ์ = 1 สินค้า ดู 074)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_welfare_item_map` (

  `map_id`          INT(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
  `item_code`       VARCHAR(30)          NOT NULL             COMMENT 'รหัสรายการของระบบสวัสดิการ',
  `logo`            VARCHAR(10)          NOT NULL DEFAULT ''  COMMENT 'โลโก้บริษัท, ว่าง = ทุกบริษัท',
  `sub_category_id` INT(10) UNSIGNED     NOT NULL             COMMENT 'หมวดย่อยสินค้า (แบบเสื้อ)',
  `variant_label`   VARCHAR(50)          NOT NULL DEFAULT ''  COMMENT 'ชื่อแบบที่แสดงให้เลือก เช่น ผู้ชาย / ผู้หญิง',
  `sort_order`      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,

  `created_at`      DATETIME             NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                              COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`      VARCHAR(50)          NULL     DEFAULT NULL,
  `updated_at`      TIMESTAMP            NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`      VARCHAR(50)          NULL     DEFAULT NULL,

  PRIMARY KEY (`map_id`),
  UNIQUE KEY `uq_welfare_item_map` (`item_code`, `logo`, `sub_category_id`),
  KEY `idx_welfare_item_sub`        (`sub_category_id`),
  KEY `idx_welfare_item_created_by` (`created_by`),
  KEY `idx_welfare_item_updated_by` (`updated_by`),

  CONSTRAINT `fk_welfare_item_sub` FOREIGN KEY (`sub_category_id`)
      REFERENCES `tb_sub_category` (`sub_category_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_item_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_item_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='จับคู่รายการชุดฟอร์มของระบบสวัสดิการกับหมวดย่อยสินค้า';


-- ------------------------------------------------------------
--  3) tb_welfare_site_map — หน่วยงานของระบบสวัสดิการ -> หน่วยงานในสต็อก
--  ใบเบิกตัดค่าใช้จ่ายเข้าหน่วยงานของพนักงาน หน่วยงานที่ยังไม่จับคู่ส่งเบิกไม่ได้
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_welfare_site_map` (

  `welfare_site_id`   INT(10) UNSIGNED NOT NULL              COMMENT 'id หน่วยงานในระบบสวัสดิการ',
  `welfare_site_name` VARCHAR(150)     NOT NULL DEFAULT ''   COMMENT 'ชื่อไว้อ่าน ไม่ใช้ตัดสินใจ',
  `department_id`     INT(10) UNSIGNED NOT NULL              COMMENT 'หน่วยงานในสต็อก',

  `created_at`        DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                             COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`        VARCHAR(50)      NULL     DEFAULT NULL,
  `updated_at`        TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`        VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`welfare_site_id`),
  KEY `idx_welfare_site_dept`       (`department_id`),
  KEY `idx_welfare_site_created_by` (`created_by`),
  KEY `idx_welfare_site_updated_by` (`updated_by`),

  CONSTRAINT `fk_welfare_site_dept` FOREIGN KEY (`department_id`)
      REFERENCES `tb_department` (`department_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_site_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_site_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='จับคู่หน่วยงานของระบบสวัสดิการกับหน่วยงานในสต็อก';


-- ---------- ค่าตั้งต้น: รายการ -> หมวดย่อย (ดู 074) ----------
-- โปโลแยกตามโลโก้บริษัท สต็อกยังไม่มีเสื้อโลโก้ CNS / CIC จึงยังไม่จับคู่
-- (ส่งเบิกจะได้ข้อความบอกตรง ๆ ว่ายังไม่ได้จับคู่)
INSERT IGNORE INTO `tb_welfare_item_map`
  (`item_code`, `logo`, `sub_category_id`, `variant_label`, `sort_order`, `created_at`, `created_by`)
VALUES
  ('POLO_BLACK',   'PRO',  4, 'ผู้ชาย',  1, NOW(), 'hl-welfare'),
  ('POLO_BLACK',   'PRO',  5, 'ผู้หญิง', 2, NOW(), 'hl-welfare'),
  ('POLO_NAVY',    'PRO',  6, 'ผู้ชาย',  1, NOW(), 'hl-welfare'),
  ('POLO_NAVY',    'PRO',  7, 'ผู้หญิง', 2, NOW(), 'hl-welfare'),
  ('POLO_BLACK',   'HL',   8, 'ผู้ชาย',  1, NOW(), 'hl-welfare'),
  ('POLO_BLACK',   'HL',   9, 'ผู้หญิง', 2, NOW(), 'hl-welfare'),
  ('POLO_BLACK',   'AS',  10, 'ผู้ชาย',  1, NOW(), 'hl-welfare'),
  ('POLO_BLACK',   'AS',  11, 'ผู้หญิง', 2, NOW(), 'hl-welfare'),
  ('TECH_SHIRT',   '',     1, '',        1, NOW(), 'hl-welfare'),
  ('TECH_PANTS',   '',     2, '',        1, NOW(), 'hl-welfare'),
  ('SAFETY_SHOES', '',     3, '',        1, NOW(), 'hl-welfare');


-- ---------- ค่าตั้งต้น: หน่วยงาน (รหัสหน่วยงานของ mock ในระบบสวัสดิการ) ----------
INSERT IGNORE INTO `tb_welfare_site_map`
  (`welfare_site_id`, `welfare_site_name`, `department_id`, `created_at`, `created_by`)
VALUES
  ( 1, 'สำนักงานใหญ่ HL',               96, NOW(), 'hl-welfare'),
  ( 2, 'คลังสินค้า HL',                 93, NOW(), 'hl-welfare'),
  ( 3, 'สำนักงาน PRO',                  25, NOW(), 'hl-welfare'),
  ( 4, 'Pro Site — โครงการพระราม 9',    77, NOW(), 'hl-welfare'),
  ( 5, 'Pro Site — โครงการบางนา',       77, NOW(), 'hl-welfare'),
  ( 6, 'สำนักงาน CNS',                  71, NOW(), 'hl-welfare'),
  ( 7, 'หน่วยงาน อาคารสีลม',            26, NOW(), 'hl-welfare'),
  ( 8, 'หน่วยงาน โรงพยาบาลรามอินทรา',   73, NOW(), 'hl-welfare'),
  ( 9, 'สำนักงาน CIC',                  37, NOW(), 'hl-welfare'),
  (10, 'สำนักงาน AS',                   95, NOW(), 'hl-welfare'),
  (11, 'ไซต์ก่อสร้าง AS',               95, NOW(), 'hl-welfare');
