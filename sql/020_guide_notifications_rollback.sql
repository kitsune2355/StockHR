-- ============================================================
--  020_guide_notifications_rollback.sql
--  ย้อนกลับ 020_guide_notifications.sql
--
--  ลบขั้นตอนก่อนลบตัวคู่มือ เพราะขั้นตอนอ้าง guide_id อยู่
--
--  หลังรันไฟล์นี้ ปุ่มคู่มือบนหน้า "การแจ้งเตือน" จะหายไป
--  ส่วนหน้าอื่นไม่กระทบ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/020_guide_notifications_rollback.sql
-- ============================================================

DELETE FROM `tb_guide_step`
WHERE `guide_id` IN (SELECT `guide_id` FROM (
    SELECT `guide_id` FROM `tb_guide` WHERE `page_key` = 'notifications'
) AS x);

DELETE FROM `tb_guide` WHERE `page_key` = 'notifications';
