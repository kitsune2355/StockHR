-- ============================================================
--  061_doc_history.sql
--  ประวัติเอกสาร (timeline) + เพิ่มค่า ENUM ให้ tb_notification.noti_type
--
--  [1] tb_doc_history — บันทึกทุกเหตุการณ์ของเอกสารหนึ่งใบ เขียนอย่างเดียว
--      ลบไม่ได้ แก้ไม่ได้ (append only) เขียนคู่กับทุกครั้งที่สถานะ/รายการเปลี่ยน
--      เดิมข้อมูลพวกนี้กระจายอยู่ในคอลัมน์เดี่ยว ๆ ของ tb_doc_header
--      (approved_by/at, cancelled_by/at) ซึ่งเก็บได้ครั้งเดียว พอมีฟีเจอร์
--      "ส่งกลับแก้ไข" ที่เกิดหลายรอบ ข้อมูลรอบก่อนถูกเขียนทับหาย
--
--      คีย์ด้วย doc_id ใช้ได้ทุก doc_type (ตอนนี้ใช้กับใบเบิกเป็นหลัก)
--
--  [2] tb_notification.noti_type — เพิ่มค่าใหม่:
--      request_returned / request_edited  (ฟีเจอร์ใหม่รอบนี้)
--      adjust_waiting / adjust_approved / adjust_rejected
--        — ค่าพวกนี้ถูกใช้ใน notify_types() (backend/notify.php) มาก่อนแล้ว
--          แต่ยังไม่ได้เพิ่มใน ENUM ของคอลัมน์ MySQL จึงเก็บลงจริงเป็น '' (บั๊กแฝง)
--
--  ข้อจำกัด MySQL 5.0.51b: utf8 เท่านั้น, ให้แอปส่ง NOW() มาเอง (created_at),
--  คอลัมน์ที่ฐานข้อมูลเติมเวลาให้เองมีได้ตารางละตัว (ยกให้ updated_at ที่ตารางอื่น)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/061_doc_history.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/061_doc_history_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  [1] tb_doc_history
--
--  event : submitted   ยื่นใบเข้ามาครั้งแรก
--          edited       ผู้ทำเบิกแก้ไขรายการ (ใบยังรออนุมัติ)
--          resubmitted  แก้แล้วยื่นใหม่ (แก้ครั้งที่เกิดหลัง "returned")
--          approved     ผู้อนุมัติเห็นชอบ
--          rejected     ผู้อนุมัติไม่อนุมัติ (เส้นทางตัน)
--          returned     ผู้อนุมัติส่งกลับให้แก้ไข (approved -> waiting)
--          cancelled    ยกเลิกทั้งใบ
--          issued       คลังจ่ายของครบทุกบรรทัดแล้ว
--  ใช้ VARCHAR ไม่ใช่ ENUM เพื่อให้เพิ่มชนิดเหตุการณ์ใหม่ได้โดยไม่ต้อง ALTER อีก
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_doc_history` (

  `hist_id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_id`     INT(10) UNSIGNED NOT NULL              COMMENT 'เอกสารต้นสังกัด',
  `event`      VARCHAR(20)      NOT NULL              COMMENT 'ชนิดเหตุการณ์ — ดูหัวไฟล์',
  `actor`      VARCHAR(50)      NULL DEFAULT NULL     COMMENT 'username คนที่ทำ (NULL = ระบบ)',
  `note`       VARCHAR(500)     NOT NULL DEFAULT ''   COMMENT 'เหตุผล/รายละเอียดของเหตุการณ์',

  `created_at` DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                     COMMENT 'แอปส่ง NOW() มาเสมอ',

  PRIMARY KEY (`hist_id`),
  KEY `idx_dochist_doc`   (`doc_id`, `hist_id`),
  KEY `idx_dochist_actor` (`actor`),

  CONSTRAINT `fk_dochist_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dochist_actor` FOREIGN KEY (`actor`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ประวัติ/timeline ของเอกสาร เขียนอย่างเดียว';


-- ------------------------------------------------------------
--  [2] เพิ่มค่า ENUM ของ tb_notification.noti_type
--      (append เท่านั้น ดัชนีของค่าเดิมไม่ขยับ)
-- ------------------------------------------------------------
ALTER TABLE `tb_notification`
  MODIFY COLUMN `noti_type` ENUM(
    'request_waiting','request_approved','request_rejected','request_cancelled',
    'request_returned','request_edited',
    'issue_created','issue_acknowledged','issue_cancelled',
    'adjust_waiting','adjust_approved','adjust_rejected',
    'stock_low','system'
  ) NOT NULL DEFAULT 'system'
  COMMENT 'ชนิดแจ้งเตือน — สี/ไอคอนดูที่ notify_types() ใน backend/notify.php';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tb_doc_history';

SELECT `COLUMN_TYPE` AS `ชนิดแจ้งเตือนใหม่`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`  = 'tb_notification'
  AND `COLUMN_NAME` = 'noti_type';
