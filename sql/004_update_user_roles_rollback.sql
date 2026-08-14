-- ============================================================
--  004_update_user_roles_rollback.sql
--  ย้อนชุดสิทธิ์กลับเป็นแบบเดิม
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/004_update_user_roles_rollback.sql
--
--  คำเตือน: ผู้ใช้ที่ถือสิทธิ์ approver จะถูกย้ายไปเป็น general
--  เพราะชุดเดิมไม่มีสิทธิ์นี้ ตรวจก่อนรันว่ามีใครถืออยู่บ้าง:
--      SELECT username FROM tb_user WHERE role = 'approver';
-- ============================================================

UPDATE `tb_user` SET `role` = 'general' WHERE `role` = 'approver';

ALTER TABLE `tb_user`
  MODIFY COLUMN `role` ENUM('super_admin','employer','admin','general')
  NOT NULL DEFAULT 'general'
  COMMENT 'ระดับสิทธิ์ ตรงกับเงื่อนไขใน checkmember.php';
