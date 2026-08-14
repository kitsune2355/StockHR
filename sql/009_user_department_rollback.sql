-- ============================================================
--  009_user_department_rollback.sql
--  ย้อนไฟล์ 009 — ถอด foreign key และ index ของ tb_user.dept_id
--  ค่าที่กรอกไว้ในคอลัมน์ยังอยู่ ไม่ได้ล้างทิ้ง
-- ============================================================
ALTER TABLE `tb_user` DROP FOREIGN KEY `fk_user_dept`;
ALTER TABLE `tb_user` DROP KEY `idx_user_dept`;