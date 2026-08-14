-- ============================================================
--  032_guide_barcode_qr_rollback.sql
--  เอาขั้น "เลือกบาร์โค้ดหรือ QR" ออก และคืนข้อความขั้นคุณภาพ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/032_guide_barcode_qr_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'product_barcode' AND s.`target_selector` = '#bcType';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'product_barcode' AND s.`step_order` >= 4;
