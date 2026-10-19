-- ============================================================
--  078_notify_welfare_request.sql
--  แจ้งเตือนประเภทใหม่: ใบเบิกจากระบบสวัสดิการรอคลังจ่าย
--
--  ใบจากระบบสวัสดิการ (ดู 075) ออกมา "อนุมัติแล้ว" ทันที และผู้ขอคือบัญชีระบบ hl-welfare
--  แจ้งเตือนเดิมของใบเบิก (รออนุมัติ -> ผู้อนุมัติ / ตัดสินแล้ว -> ผู้ขอ) จึงไม่ถึงใครเลย
--  ประเภทนี้ส่งหาผู้ดูแลระบบ (คนคลัง) ให้รู้ว่ามีใบรอจัดของ
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/078_notify_welfare_request.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/078_notify_welfare_request_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_notification`
  MODIFY `noti_type` ENUM('request_waiting','request_approved','request_rejected','request_cancelled',
                          'request_returned','request_edited','issue_created','issue_acknowledged',
                          'issue_cancelled','adjust_waiting','adjust_approved','adjust_rejected',
                          'stock_low','system','welfare_request')
                     NOT NULL DEFAULT 'system';
