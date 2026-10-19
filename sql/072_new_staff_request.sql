-- ============================================================
--  072_new_staff_request.sql
--  ใบเบิกวัสดุสำนักงาน "พนักงานใหม่"
--
--  ---------------------------------------------------------------
--  แนวคิด
--  ---------------------------------------------------------------
--  ใบเบิกพนักงานใหม่ "เป็นใบเบิกธรรมดาหนึ่งใบ" (doc_type='request')
--  ไม่ได้แยกเป็นเอกสารประเภทใหม่ เพราะเส้นทางเดินเหมือนกันทุกขั้น
--      ส่งใบ -> waiting -> approved | rejected -> คลังทำใบจ่าย -> เซ็นรับ
--  จึงได้การอนุมัติ การแจ้งเตือน การตัดสต๊อก และการเซ็นรับของไปฟรีทั้งหมด
--  และรายงานความเคลื่อนไหว/ค่าใช้จ่ายนับรวมให้เองโดยไม่ต้องแก้
--
--  สิ่งที่ใบพนักงานใหม่มีเพิ่มจากใบเบิกธรรมดา เก็บแยกไว้ในตารางส่วนขยาย
--  ไม่ได้เพิ่มคอลัมน์ลง tb_doc_header ซึ่งเป็นของทุกประเภทเอกสาร
--  ใบเบิกไหนมีแถวใน tb_doc_new_staff = ใบพนักงานใหม่ (ไม่ต้องมี flag ซ้ำอีกที่)
--
--  ---------------------------------------------------------------
--  ไฟล์นี้ทำ 3 อย่าง
--  ---------------------------------------------------------------
--    1) tb_doc_new_staff   ข้อมูลพนักงานใหม่ของใบเบิก (1 ใบ : 1 แถว)
--    2) tb_new_staff_kit   ชุดของพื้นฐานที่ตั้งไว้ล่วงหน้า (แทนรายการ 1-4 ในฟอร์มกระดาษ)
--    3) tb_doc_item.must_return   ธงรายบรรทัด "ต้องคืนเมื่อพ้นสภาพพนักงาน"
--       แช่แข็งไว้ในบรรทัดตอนส่งใบ — เป็นเงื่อนไขที่พนักงานรับทราบ ณ วันนั้น
--       ถ้าภายหลังแก้ชุดของพื้นฐาน ใบเก่าต้องไม่เปลี่ยนตาม
--       ใบเบิกธรรมดา/ใบรับเข้า/ใบจ่าย ได้ค่า 0 เสมอ ไม่กระทบของเดิม
--
--  ---------------------------------------------------------------
--  ข้อจำกัด MySQL 5.0.51b ที่เลี่ยง (เหมือนไฟล์ก่อนหน้า)
--  ---------------------------------------------------------------
--    - utf8 เท่านั้น
--    - DATETIME ตั้ง DEFAULT CURRENT_TIMESTAMP ไม่ได้ แอปส่ง NOW() มาเอง
--    - TIMESTAMP ที่ฐานข้อมูลเติมเองมีได้ตารางละตัว ยกให้ updated_at
--
--  ลำดับ deploy: รันไฟล์นี้ "ก่อน" อัปโหลดโค้ด เพราะ doc_list()/doc_find()
--  join ตารางใหม่นี้แล้ว (แนวทางเดียวกับ 071)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/072_new_staff_request.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/072_new_staff_request_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) tb_doc_new_staff — ข้อมูลพนักงานใหม่ของใบเบิก
--
--  พนักงานใหม่ส่วนใหญ่ยังไม่มีบัญชีในระบบตอนทำใบ (ทำก่อนวันเริ่มงาน)
--  จึงเก็บชื่อเป็นข้อความ ไม่ผูกกับ tb_user
--  ผู้ขอเบิก (requester ใน tb_doc_header) คือคนที่ทำใบแทน เช่น หัวหน้าหรือ HR
--  หน่วยงานของพนักงานใหม่ = tb_doc_header.department_id ไม่เก็บซ้ำ
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_doc_new_staff` (

  `doc_id`        INT(10) UNSIGNED NOT NULL              COMMENT 'ใบเบิกต้นสังกัด (1:1)',
  `employee_name` VARCHAR(150)     NOT NULL              COMMENT 'ชื่อ-นามสกุลพนักงานใหม่',
  `employee_code` VARCHAR(30)      NOT NULL DEFAULT ''   COMMENT 'รหัสพนักงาน ถ้ามีแล้ว',
  `position_name` VARCHAR(150)     NOT NULL DEFAULT ''   COMMENT 'ตำแหน่ง',
  `start_date`    DATE             NOT NULL              COMMENT 'วันที่เริ่มงาน (ล่วงหน้าได้)',

  `created_at`    DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                         COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`    VARCHAR(50)      NULL     DEFAULT NULL,
  `updated_at`    TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`doc_id`),
  KEY `idx_new_staff_name`       (`employee_name`),
  KEY `idx_new_staff_start`      (`start_date`),
  KEY `idx_new_staff_created_by` (`created_by`),
  KEY `idx_new_staff_updated_by` (`updated_by`),

  -- ลบใบเบิก = ข้อมูลพนักงานใหม่ของใบนั้นหมดความหมาย
  CONSTRAINT `fk_new_staff_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_new_staff_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_new_staff_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ข้อมูลพนักงานใหม่ของใบทำรายการเบิกพนักงานใหม่';


-- ------------------------------------------------------------
--  2) tb_new_staff_kit — ชุดของพื้นฐานของพนักงานใหม่
--
--  หนึ่งแถว = สินค้าหนึ่งตัวที่ใส่ให้อัตโนมัติในฟอร์ม พร้อมจำนวนตั้งต้น
--  ผู้ทำใบยังปรับจำนวน/เอาออกได้ในฟอร์ม ตารางนี้แค่ "ตั้งค่าล่วงหน้า"
--
--  ผูกกับ tb_product ไม่ใช่ข้อความ เพราะต้องตัดสต๊อกได้จริงตอนจ่าย
--  สินค้าในชุดนี้เบิกได้ทุกหน่วยงาน ไม่ติดการจำกัดใน tb_product_department
--  (เป็นนโยบายของบริษัท ไม่ใช่ของหน่วยงาน — บังคับที่ backend/new_staff.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_new_staff_kit` (

  `kit_id`      INT(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
  `product_id`  INT(10) UNSIGNED     NOT NULL             COMMENT 'สินค้าในชุด',
  `default_qty` DECIMAL(12,2)        NOT NULL DEFAULT 1   COMMENT 'จำนวนที่ใส่ให้ตั้งต้น',
  `must_return` TINYINT(1)           NOT NULL DEFAULT 0   COMMENT '1 = ต้องคืนเมื่อพ้นสภาพพนักงาน',
  `sort_order`  SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'ลำดับในฟอร์มและใบพิมพ์',
  `is_active`   TINYINT(1)           NOT NULL DEFAULT 1   COMMENT '1 = ใช้งาน, 0 = ปิดไว้',

  `created_at`  DATETIME             NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                          COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`  VARCHAR(50)          NULL     DEFAULT NULL,
  `updated_at`  TIMESTAMP            NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`  VARCHAR(50)          NULL     DEFAULT NULL,

  PRIMARY KEY (`kit_id`),
  -- สินค้าซ้ำในชุดทำให้ฟอร์มมีสองบรรทัดของเดียวกัน แล้ว doc_validate จะปฏิเสธ
  UNIQUE KEY `uq_kit_product` (`product_id`),
  KEY `idx_kit_active_sort`  (`is_active`, `sort_order`),
  KEY `idx_kit_created_by`   (`created_by`),
  KEY `idx_kit_updated_by`   (`updated_by`),

  CONSTRAINT `fk_kit_product` FOREIGN KEY (`product_id`)
      REFERENCES `tb_product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_kit_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_kit_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ชุดของพื้นฐานสำหรับพนักงานใหม่';


-- ------------------------------------------------------------
--  3) tb_doc_item.must_return
-- ------------------------------------------------------------
ALTER TABLE `tb_doc_item`
  ADD COLUMN `must_return` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = ต้องคืนเมื่อพ้นสภาพพนักงาน (ใบพนักงานใหม่) แช่แข็งตอนส่งใบ'
  AFTER `note`;


-- ------------------------------------------------------------
--  4) ชุดของตั้งต้น — ตามฟอร์มกระดาษเดิม รายการที่ 1-4
--     อ้างด้วย product_code ไม่ใช่ product_id เพราะ id ของเครื่องพัฒนา
--     กับเซิร์ฟเวอร์จริงอาจไม่ตรงกัน รหัสไหนไม่มีในฐานข้อมูลก็ข้ามไปเอง
--     กล่องใส่เครื่องเขียนเป็นของที่ต้องคืน (ฟอร์มเดิม: "ตั้งแต่ลำดับที่ 4 ขึ้นไป")
-- ------------------------------------------------------------
INSERT IGNORE INTO `tb_new_staff_kit`
       (`product_id`, `default_qty`, `must_return`, `sort_order`, `is_active`, `created_at`, `created_by`)
SELECT p.`product_id`, 1, k.`must_return`, k.`sort_order`, 1, NOW(), NULL
FROM (
          SELECT 'OF-STA-083' AS `code`, 0 AS `must_return`, 1 AS `sort_order`   -- ดินสอดำ HB
    UNION SELECT 'OF-STA-142',           0,                  2                   -- ปากกาลูกลื่น สีแดง
    UNION SELECT 'OF-STA-141',           0,                  3                   -- ปากกาลูกลื่น สีน้ำเงิน
    UNION SELECT 'OF-STA-036',           1,                  4                   -- กล่องใส่เครื่องเขียน
) k
INNER JOIN `tb_product` p ON p.`product_code` = k.`code`;


-- ------------------------------------------------------------
--  5) ข้อความเงื่อนไขท้ายฟอร์ม — แก้จากฐานข้อมูลได้โดยไม่ต้องแก้โค้ด
--     (แนวเดียวกับ request_notice) บรรทัดละหนึ่งข้อ
-- ------------------------------------------------------------
INSERT IGNORE INTO `tb_app_setting`
  (`setting_key`, `setting_value`, `setting_group`, `description`, `created_at`, `created_by`)
VALUES ('new_staff_terms',
 'รายการที่ 1-4 เป็นรายการพื้นฐานสำหรับพนักงานเข้าใหม่\nพนักงานที่รับรายการเครื่องเขียนจะต้องดูแลรักษาอุปกรณ์มิให้สูญหาย (ตั้งแต่ลำดับที่ 4 ขึ้นไป) และส่งคืนฝ่ายทรัพยากรบุคคลเมื่อลาออก หรือสิ้นสภาพจากการเป็นพนักงาน หากสูญหายพนักงานต้องชดใช้ค่าความเสียหายตามมูลค่าของวัสดุสำนักงานหลังหักค่าเสื่อมแล้ว\nกรณีวัสดุสำนักงานลำดับ 4 และวัสดุสำนักงานถาวร และมีมูลค่าที่เบิกเพิ่ม ชำรุดเสียหาย และต้องการเบิกทดแทนต้องนำของเดิมมาแลกกับของใหม่ด้วยทุกครั้ง',
 'general', 'หมายเหตุท้ายใบเบิกพนักงานใหม่ (บรรทัดละข้อ แก้ได้ที่หน้าอุปกรณ์พนักงานใหม่)', NOW(), NULL);


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT k.`sort_order`, p.`product_code`, p.`product_name`, k.`default_qty`, k.`must_return`
FROM `tb_new_staff_kit` k
INNER JOIN `tb_product` p ON p.`product_id` = k.`product_id`
ORDER BY k.`sort_order`;

SELECT `COLUMN_NAME`, `COLUMN_TYPE`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`   = 'tb_doc_item'
  AND `COLUMN_NAME`  = 'must_return';
