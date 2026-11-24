-- ============================================================
--  096_doc_note_to_receiver.sql
--  ย้ายชื่อพนักงานออกจากหมายเหตุของใบเบิกเก่า ไปช่อง "ผู้รับของ"
--
--  ใบจากระบบสวัสดิการที่ออกก่อนมีช่องผู้รับของ เก็บชื่อพนักงานไว้ในหมายเหตุ 2 รูปแบบ
--      ก)  ชื่อ
--      ข)  ชุดฟอร์มสวัสดิการ (รอบ) — ชื่อ            (ใบช่วงแรก ก่อน 20 ต.ค. 2569)
--      ทั้งสองแบบอาจต่อท้ายด้วย " — ข้อความเพิ่มเติม"
--
--  ทำอะไรบ้าง (เฉพาะใบเบิก doc_type = 'request')
--    1) สำรองค่าเดิมไว้ที่ tb_doc_note_backup_096
--    2) ผู้รับของที่ยังว่าง = ชื่อพนักงานในใบ (สวัสดิการ / พนักงานใหม่)
--    3) ตัดชื่อออกจากหมายเหตุของใบสวัสดิการ เหลือเฉพาะข้อความเพิ่มเติม (ไม่มี = ว่าง)
--       หมายเหตุที่ไม่ตรงสองรูปแบบข้างบน (มีคนแก้เอง) ไม่แตะ
--
--  ไม่แตะ: ใบจ่าย ใบรับเข้า ใบปรับยอด สต็อก สถานะ และประวัติเอกสาร
--  รอบ (เริ่มงาน/ผ่านทดลองงาน/ประจำปี) ของรูปแบบ ข) ยังอยู่ใน tb_doc_welfare
--
--  รันซ้ำได้ · อัปโหลดโค้ดชุดช่องผู้รับของก่อน แล้วค่อยรันไฟล์นี้
--  (ใบที่ออกด้วยโค้ดเก่าหลังรัน รันซ้ำอีกครั้งได้)
--  ต้องมีสิทธิ์ CREATE
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u USER -p --default-character-set=utf8 <ฐาน> < sql/096_doc_note_to_receiver.sql
--  ย้อนกลับ: sql/096_doc_note_to_receiver_rollback.sql
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `tb_doc_note_backup_096` (
  `doc_id`       INT UNSIGNED NOT NULL,
  `old_note`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'หมายเหตุก่อนย้าย',
  `new_note`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'หมายเหตุหลังย้าย',
  `old_receiver` VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ผู้รับของก่อนย้าย',
  `new_receiver` VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ผู้รับของหลังย้าย',
  `created_at`   DATETIME     NULL DEFAULT NULL,
  `created_by`   VARCHAR(50)  NULL DEFAULT NULL,
  `updated_at`   DATETIME     NULL DEFAULT NULL,
  `updated_by`   VARCHAR(50)  NULL DEFAULT NULL,
  PRIMARY KEY (`doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='สำรองก่อนย้ายชื่อจากหมายเหตุ (sql/096)';

START TRANSACTION;

-- ------------------------------------------------------------
--  คำนวณค่าใหม่ใส่ตารางชั่วคราว
--  body = หมายเหตุที่ตัดหัว "ชุดฟอร์มสวัสดิการ (รอบ) — " ออกแล้ว
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_096`;
CREATE TEMPORARY TABLE `tmp_096` (
  `doc_id`       INT UNSIGNED NOT NULL PRIMARY KEY,
  `is_welfare`   TINYINT      NOT NULL,
  `emp`          VARCHAR(150) NOT NULL,
  `old_note`     VARCHAR(500) NOT NULL,
  `body`         VARCHAR(500) NOT NULL,
  `new_note`     VARCHAR(500) NOT NULL,
  `old_receiver` VARCHAR(150) NOT NULL,
  `new_receiver` VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `tmp_096`
SELECT h.`doc_id`, IF(w.`doc_id` IS NULL, 0, 1),
       COALESCE(w.`employee_name`, n.`employee_name`),
       h.`note`,
       IF(LEFT(h.`note`, CHAR_LENGTH('ชุดฟอร์มสวัสดิการ (')) = 'ชุดฟอร์มสวัสดิการ (' COLLATE utf8_unicode_ci
            AND LOCATE(') — ', h.`note`) > 0,
          SUBSTRING(h.`note`, LOCATE(') — ', h.`note`) + CHAR_LENGTH(') — ')),
          h.`note`),
       h.`note`,
       h.`receiver_name`,
       IF(h.`receiver_name` = '', COALESCE(w.`employee_name`, n.`employee_name`), h.`receiver_name`)
FROM `tb_doc_header` h
LEFT JOIN `tb_doc_welfare`   w ON w.`doc_id` = h.`doc_id`
LEFT JOIN `tb_doc_new_staff` n ON n.`doc_id` = h.`doc_id`
WHERE h.`doc_type` = 'request'
  AND (w.`doc_id` IS NOT NULL OR n.`doc_id` IS NOT NULL);

-- หมายเหตุ = ชื่อล้วน
UPDATE `tmp_096` SET `new_note` = ''
WHERE `is_welfare` = 1 AND `body` = `emp` COLLATE utf8_bin AND `emp` <> '';

-- หมายเหตุ = ชื่อ — ข้อความเพิ่มเติม
UPDATE `tmp_096`
SET `new_note` = SUBSTRING(`body`, CHAR_LENGTH(`emp`) + CHAR_LENGTH(' — ') + 1)
WHERE `is_welfare` = 1 AND `emp` <> ''
  AND LEFT(`body`, CHAR_LENGTH(`emp`) + CHAR_LENGTH(' — ')) = CONCAT(`emp`, ' — ') COLLATE utf8_bin;

-- เหลือเฉพาะแถวที่เปลี่ยนจริง
DELETE FROM `tmp_096`
WHERE `new_note` = `old_note` COLLATE utf8_bin AND `new_receiver` = `old_receiver` COLLATE utf8_bin;

-- สำรอง (แถวที่เคยสำรองแล้วคงค่าเดิมสุดไว้)
INSERT INTO `tb_doc_note_backup_096`
  (`doc_id`, `old_note`, `new_note`, `old_receiver`, `new_receiver`, `created_at`, `created_by`, `updated_at`, `updated_by`)
SELECT `doc_id`, `old_note`, `new_note`, `old_receiver`, `new_receiver`, NOW(), 'sql/096', NOW(), 'sql/096'
FROM `tmp_096`
ON DUPLICATE KEY UPDATE `new_note` = VALUES(`new_note`), `new_receiver` = VALUES(`new_receiver`),
                        `updated_at` = NOW(), `updated_by` = 'sql/096';

UPDATE `tb_doc_header` h
JOIN `tmp_096` t ON t.`doc_id` = h.`doc_id`
SET h.`note` = t.`new_note`, h.`receiver_name` = t.`new_receiver`,
    h.`updated_at` = h.`updated_at`;   -- คงเวลาแก้ไขล่าสุดเดิม

COMMIT;

-- ------------------------------------------------------------
--  ผลลัพธ์
-- ------------------------------------------------------------
SELECT COUNT(*) AS `changed_now` FROM `tmp_096`;
SELECT h.`doc_no`, t.`old_note`, t.`new_note`, t.`new_receiver`
FROM `tmp_096` t JOIN `tb_doc_header` h ON h.`doc_id` = t.`doc_id` ORDER BY h.`doc_id`;

-- ใบสวัสดิการที่หมายเหตุยังมีข้อความ (ข้อความเพิ่มเติม หรือมีคนแก้เอง) — ดูเผื่อต้องแก้มือ
SELECT h.`doc_no`, h.`receiver_name`, h.`note`
FROM `tb_doc_header` h JOIN `tb_doc_welfare` w ON w.`doc_id` = h.`doc_id`
WHERE h.`note` <> '' ORDER BY h.`doc_id`;

DROP TEMPORARY TABLE IF EXISTS `tmp_096`;
