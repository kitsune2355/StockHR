-- ============================================================
--  082_welfare_uniform_start.sql
--  ชุดฟอร์มของพนักงานใหม่มาจากเงื่อนไขสวัสดิการ (hl-welfare หน้า rules.html แท็บชุดฟอร์ม)
--
--  ระบบสวัสดิการเป็นต้นทางของ "ใครได้ชุดฟอร์มอะไรตอนเริ่มงาน" (รอบ START)
--  ส่งมากับ api/welfare.php action "sync_master" ในช่อง uniform_start
--  สต็อกเก็บไว้แสดงแบบอ่านอย่างเดียวในหน้าอุปกรณ์พนักงานใหม่และฟอร์มเบิกพนักงานใหม่
--  ชุดของของสต็อกเอง (tb_new_staff_kit) จึงเหลือแค่ของที่ไม่ใช่ชุดฟอร์ม (บัตรพนักงาน, เครื่องเขียน …)
--
--  1) tb_welfare_uniform_rule — หนึ่งแถวต่อ (หน่วยงาน, เงื่อนไข, รายการชุดฟอร์ม)
--     เขียนทับทั้งชุดของหน่วยงานนั้นทุกครั้งที่ sync
--     เงื่อนไขที่ไม่มีรายการเก็บเป็นแถวเดียวที่ item_code = '' เพื่อให้ยังเห็นชื่อเงื่อนไข
--  2) tb_welfare_site_map.uniform_synced_at — เวลาที่รับเงื่อนไขชุดฟอร์มของหน่วยงานนี้ล่าสุด
--     NULL = ยังไม่เคยได้รับ
--
--  ต้องรันหลัง 075 / 081
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/082_welfare_uniform_start.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/082_welfare_uniform_start_rollback.sql
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `tb_welfare_uniform_rule` (

  `row_id`          INT(10) UNSIGNED  NOT NULL AUTO_INCREMENT,
  `welfare_site_id` INT(10) UNSIGNED  NOT NULL             COMMENT 'หน่วยงานในระบบสวัสดิการ (tb_welfare_site_map)',
  `rule_id`         INT(10) UNSIGNED  NOT NULL             COMMENT 'id เงื่อนไขในระบบสวัสดิการ',
  `rule_label`      VARCHAR(200)      NOT NULL DEFAULT ''  COMMENT 'ชื่อเงื่อนไข เช่น Pro Site · ช่างทีมโมบาย',
  `rule_sort`       SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ลำดับเงื่อนไขตามที่ส่งมา',
  `item_code`       VARCHAR(30)       NOT NULL DEFAULT ''  COMMENT 'รหัสรายการชุดฟอร์ม (tb_welfare_item_map), ว่าง = เงื่อนไขไม่มีรายการ',
  `qty`             DECIMAL(12,2)     NOT NULL DEFAULT 0   COMMENT 'จำนวนที่ได้',
  `item_sort`       SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ลำดับรายการในเงื่อนไข',

  `created_at`      DATETIME          NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                           COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`      VARCHAR(50)       NULL     DEFAULT NULL,
  `updated_at`      TIMESTAMP         NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`      VARCHAR(50)       NULL     DEFAULT NULL,

  PRIMARY KEY (`row_id`),
  KEY `idx_uniform_site_sort` (`welfare_site_id`, `rule_sort`, `item_sort`),
  KEY `idx_uniform_item`      (`item_code`),
  KEY `idx_uniform_created_by` (`created_by`),
  KEY `idx_uniform_updated_by` (`updated_by`),

  CONSTRAINT `fk_uniform_site` FOREIGN KEY (`welfare_site_id`)
      REFERENCES `tb_welfare_site_map` (`welfare_site_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uniform_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_uniform_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ชุดฟอร์มรอบเริ่มงานตามเงื่อนไขของระบบสวัสดิการ (อ่านอย่างเดียวในสต็อก)';

ALTER TABLE `tb_welfare_site_map`
  ADD COLUMN `uniform_synced_at` DATETIME NULL DEFAULT NULL
      COMMENT 'เวลาที่รับเงื่อนไขชุดฟอร์มรอบเริ่มงานล่าสุด, NULL = ยังไม่เคยรับ'
      AFTER `department_id`;
