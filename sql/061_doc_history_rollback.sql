-- ============================================================
--  061_doc_history_rollback.sql
--  ย้อน 061_doc_history.sql
--
--  หมายเหตุ: การถอนค่า ENUM ของ noti_type จะทำให้แถวแจ้งเตือนที่ใช้ค่าใหม่
--  (request_returned / request_edited / adjust_*) กลายเป็น '' — ยอมรับได้
--  เพราะกระดิ่งจะ fallback ไปแสดงเป็นชนิด 'system'
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/061_doc_history_rollback.sql
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_doc_history`;

ALTER TABLE `tb_notification`
  MODIFY COLUMN `noti_type` ENUM(
    'request_waiting','request_approved','request_rejected','request_cancelled',
    'issue_created','issue_acknowledged','issue_cancelled',
    'stock_low','system'
  ) NOT NULL DEFAULT 'system'
  COMMENT 'ชนิดแจ้งเตือน';

SELECT `COLUMN_TYPE` AS `ชนิดแจ้งเตือนหลังย้อน`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME`  = 'tb_notification'
  AND `COLUMN_NAME` = 'noti_type';
