-- ============================================================
--  090_user_all_depts_rollback.sql — ย้อน 090
--  คนที่ตั้ง "ทุกหน่วยงาน" ไว้จะกลายเป็นไม่มีหน่วยงานที่ดูแล ต้องไปติ๊กใหม่
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/090_user_all_depts_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_user` DROP COLUMN `all_depts`;
