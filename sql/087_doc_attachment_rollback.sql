-- ============================================================
--  087_doc_attachment_rollback.sql — ย้อน 087
--
--  ลบตารางเท่านั้น ไฟล์ใน storage/doc_attach/ ยังอยู่ ลบเองถ้าไม่ต้องการแล้ว
--  ต้องย้อนโค้ดก่อน (api/attachment.php, backend/doc_attachment.php อ่านตารางนี้)
-- ============================================================

SET NAMES utf8;

DROP TABLE IF EXISTS `tb_doc_attachment`;
