-- ============================================================
--  075_welfare_bridge_rollback.sql — ย้อน 075_welfare_bridge.sql
--
--  ใบเบิกที่ออกจากระบบสวัสดิการไปแล้วยังอยู่ (เป็นใบเบิกธรรมดา WRQ)
--  แค่ไม่มีข้อมูลส่วนขยายแล้ว — บัญชี hl-welfare จึงต้องเก็บไว้
--  เพราะใบเหล่านั้นอ้าง requester / approved_by ถึง
--
--  ต้องย้อนโค้ดก่อนรันไฟล์นี้ เพราะ doc_list()/doc_find() join tb_doc_welfare
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_welfare_site_map`;
DROP TABLE IF EXISTS `tb_welfare_item_map`;
DROP TABLE IF EXISTS `tb_doc_welfare`;
