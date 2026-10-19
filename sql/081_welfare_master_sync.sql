-- ============================================================
--  081_welfare_master_sync.sql
--  ระบบสวัสดิการ (hl-welfare) เป็นต้นทางของบริษัท/หน่วยงาน — ส่งมาที่สต็อก
--
--  1) tb_company.company_code — รหัสบริษัทของระบบสวัสดิการ (HL / PRO / CNS / CIC / AS)
--     ใช้จับคู่ตอน sync (api/welfare.php action "sync_master" -> backend/welfare_master.php)
--     NULL = บริษัทที่ระบบสวัสดิการไม่รู้จัก (UNIQUE ยอมให้ NULL ซ้ำได้)
--     เติม 5 บริษัทเดิมตาม 076: HL->12, PRO->4, CNS->11, CIC->1, AS->14
--     ชื่อเดิมเป็นชื่อเต็ม "บริษัท … จำกัด" อยู่แล้ว ไม่แตะ
--
--  2) tb_welfare_sync_log — ประวัติการ sync แต่ละครั้ง (ใครส่ง, เปลี่ยนอะไรบ้าง)
--     ไม่มีตารางประวัติกลางของข้อมูลหลัก จึงเก็บแยกตรงนี้
--
--  ต้องรันหลัง 076
--
--  วิธีรัน (ส่งเป็นไฟล์ ไม่งั้นภาษาไทยเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/081_welfare_master_sync.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/081_welfare_master_sync_rollback.sql
-- ============================================================

SET NAMES utf8;

-- ---------- 1) รหัสบริษัท ----------
ALTER TABLE `tb_company`
  ADD COLUMN `company_code` VARCHAR(10) NULL DEFAULT NULL
      COMMENT 'รหัสบริษัทในระบบสวัสดิการ เช่น HL / PRO (NULL = ไม่ได้มาจากระบบสวัสดิการ)'
      AFTER `company_id`,
  ADD UNIQUE KEY `uq_company_code` (`company_code`);

UPDATE `tb_company` SET `company_code` = 'HL'  WHERE `company_id` = 12;
UPDATE `tb_company` SET `company_code` = 'PRO' WHERE `company_id` = 4;
UPDATE `tb_company` SET `company_code` = 'CNS' WHERE `company_id` = 11;
UPDATE `tb_company` SET `company_code` = 'CIC' WHERE `company_id` = 1;
UPDATE `tb_company` SET `company_code` = 'AS'  WHERE `company_id` = 14;


-- ---------- 2) ประวัติการ sync ----------
CREATE TABLE IF NOT EXISTS `tb_welfare_sync_log` (

  `sync_id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `requested_by_name` VARCHAR(150)     NOT NULL DEFAULT ''  COMMENT 'ชื่อ HR ที่กดส่งในระบบสวัสดิการ (ถ้าส่งมา)',
  `company_count`     INT(10) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'จำนวนบริษัทที่ส่งมา',
  `site_count`        INT(10) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'จำนวนหน่วยงานที่ส่งมา',
  `created_count`     INT(10) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'จำนวนบริษัท+หน่วยงานที่สร้างใหม่',
  `changed_count`     INT(10) UNSIGNED NOT NULL DEFAULT 0   COMMENT 'จำนวนบริษัท+หน่วยงานที่ค่าเปลี่ยน',
  `detail`            TEXT             NOT NULL             COMMENT 'รายการที่สร้าง/เปลี่ยน บรรทัดละรายการ (ว่าง = ไม่มีอะไรเปลี่ยน)',

  `created_at`        DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                            COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`        VARCHAR(50)      NULL     DEFAULT NULL,
  `updated_at`        TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`        VARCHAR(50)      NULL     DEFAULT NULL,

  PRIMARY KEY (`sync_id`),
  KEY `idx_welfare_sync_created_at` (`created_at`),
  KEY `idx_welfare_sync_created_by` (`created_by`),
  KEY `idx_welfare_sync_updated_by` (`updated_by`),

  CONSTRAINT `fk_welfare_sync_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_sync_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ประวัติการรับบริษัท/หน่วยงานจากระบบสวัสดิการ';


-- ---------- ตรวจผลลัพธ์ ----------
SELECT `company_id`, `company_code`, `company_name`, `is_active` FROM `tb_company` ORDER BY `company_id`;
