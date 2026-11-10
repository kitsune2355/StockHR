-- ============================================================
--  088_request_approver_rollback.sql
--  ย้อน 088: ลบตารางผู้อนุมัติใบเบิกและคู่มือหน้า request_approval
--  (ต้องย้อนโค้ดกลับไปใช้กติกาผูกหน่วยงานด้วย ไม่งั้นจะไม่มีใครอนุมัติใบเบิกได้)
--
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/088_request_approver_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'request_approval';

DROP TABLE IF EXISTS `tb_request_approver`;
