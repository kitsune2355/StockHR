-- ============================================================
--  077_rename_uniform_stock_rollback.sql — ย้อน 077 (ชื่อผู้ส่งอีเมลกลับเป็นชื่อเดิม)
-- ============================================================

SET NAMES utf8;

UPDATE `tb_app_setting`
SET `setting_value` = 'ระบบสต็อก HR Happyland'
WHERE `setting_key` = 'mail_from_name'
  AND `setting_value` = 'ระบบสต็อก Uniform Happyland';
