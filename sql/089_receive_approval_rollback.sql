-- ============================================================
--  089_receive_approval_rollback.sql
--  ย้อน 089 (ต้องย้อนโค้ดกลับด้วย ไม่งั้นใบรับเข้าจะค้างรออนุมัติโดยไม่มีใครอนุมัติได้)
--
--  ไม่ย้อน: ชื่อผู้ขอเบิกของใบสวัสดิการ (ชื่อคนจริงถูกต้องกว่าชื่อบัญชีกลางอยู่แล้ว)
--  ไม่ย้อน: ENUM แจ้งเตือน ถ้ามีแถว receive_* อยู่แล้ว — ลบแถวเหล่านั้นก่อนถึงจะตัดค่าออกได้
--
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/089_receive_approval_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'doc_approval';

DROP TABLE IF EXISTS `tb_receive_approver`;

DELETE FROM `tb_notification` WHERE `noti_type` IN ('receive_waiting','receive_approved','receive_rejected');

ALTER TABLE `tb_notification`
  MODIFY COLUMN `noti_type` ENUM('request_waiting','request_approved','request_rejected','request_cancelled',
                                 'request_returned','request_edited','issue_created','issue_acknowledged',
                                 'issue_cancelled','adjust_waiting','adjust_approved','adjust_rejected',
                                 'stock_low','system','welfare_request') NOT NULL DEFAULT 'system';
