-- ย้อนกลับ 085_doc_form_code_snapshot.sql
--      mysql -u root -p1234 stock_hr < sql/085_doc_form_code_snapshot_rollback.sql
SET NAMES utf8;

ALTER TABLE `tb_doc_header`
    DROP COLUMN `uniform_form_code`,
    DROP COLUMN `form_code`;
