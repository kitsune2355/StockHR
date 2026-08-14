-- ============================================================
--  007_create_menu_permission_rollback.sql
--  ย้อนกลับ 007 — ลบตารางสิทธิ์เมนู
--
--  ลบแล้วระบบจะกลับไปใช้สิทธิ์ที่เขียนไว้ในโค้ด includes/menu.php
--  ไม่มีอะไรพัง เพราะโค้ดถือค่าตั้งต้นไว้อยู่แล้ว
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/007_create_menu_permission_rollback.sql
-- ============================================================

DROP TABLE IF EXISTS `tb_menu_permission`;

SHOW TABLES;
