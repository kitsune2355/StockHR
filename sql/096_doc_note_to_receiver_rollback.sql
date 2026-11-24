-- ============================================================
--  096_doc_note_to_receiver_rollback.sql
--  ย้อน 096_doc_note_to_receiver.sql จากตารางสำรอง tb_doc_note_backup_096
--
--  คืนเฉพาะใบที่ค่ายังเป็นค่าที่ 096 ใส่ไว้ — ใบที่มีคนแก้ต่อหลังจากนั้นไม่แตะ
--  ตารางสำรองไม่ถูกลบ (ลบเองได้เมื่อไม่ต้องใช้แล้ว)
--
--  วิธีรัน:
--      mysql -u USER -p --default-character-set=utf8 <ฐาน> < sql/096_doc_note_to_receiver_rollback.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_doc_header` h
JOIN `tb_doc_note_backup_096` b ON b.`doc_id` = h.`doc_id`
SET h.`note` = b.`old_note`, h.`receiver_name` = b.`old_receiver`, h.`updated_at` = h.`updated_at`
WHERE h.`note` = b.`new_note` COLLATE utf8_bin
  AND h.`receiver_name` = b.`new_receiver` COLLATE utf8_bin;

SELECT ROW_COUNT() AS `restored`;
