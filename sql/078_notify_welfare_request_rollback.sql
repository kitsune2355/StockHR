-- ============================================================
--  078_notify_welfare_request_rollback.sql — ย้อน 078
--  แจ้งเตือนที่เป็นประเภทนี้อยู่แล้วเปลี่ยนเป็น 'system' ก่อน (ไม่ลบทิ้ง)
--  ต้องย้อนโค้ดก่อน เพราะ welfare_submit() ส่งแจ้งเตือนประเภทนี้
-- ============================================================

SET NAMES utf8;

UPDATE `tb_notification` SET `noti_type` = 'system' WHERE `noti_type` = 'welfare_request';

ALTER TABLE `tb_notification`
  MODIFY `noti_type` ENUM('request_waiting','request_approved','request_rejected','request_cancelled',
                          'request_returned','request_edited','issue_created','issue_acknowledged',
                          'issue_cancelled','adjust_waiting','adjust_approved','adjust_rejected',
                          'stock_low','system')
                     NOT NULL DEFAULT 'system';
