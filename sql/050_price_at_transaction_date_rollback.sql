-- ============================================================
--  050_price_at_transaction_date_rollback.sql
--  คืนคำอธิบายเดิมที่พูดถึงต้นทุนถัวเฉลี่ยเคลื่อนที่
--
--  ใช้คู่กับการย้อนโค้ดฝั่ง PHP เท่านั้น ถ้าย้อนแค่ไฟล์นี้ไฟล์เดียว
--  คู่มือจะกลับไปบอกผู้ใช้ว่าระบบคิดถัวเฉลี่ย ทั้งที่โค้ดไม่ได้คิดแล้ว
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/050_price_at_transaction_date_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_stock_movement`
  MODIFY COLUMN `unit_cost` DECIMAL(12,4) NOT NULL DEFAULT 0
    COMMENT 'ราคาต่อหน่วยที่ใช้กับรายการนี้';

UPDATE `tb_guide_step` s
  JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'เลือกสินค้าแล้วช่องราคาจะเติมราคาจากทะเบียนสินค้าให้เอง รอบนี้ซื้อได้ราคาอื่นก็แก้ทับได้ ราคาที่กรอกตรงนี้คือตัวที่ระบบเอาไปคิดต้นทุนถัวเฉลี่ย ควรกรอกให้ครบทุกบรรทัด ไม่งั้นรายงานค่าใช้จ่ายจะขึ้นศูนย์'
WHERE g.`page_key` = 'receive_new'
  AND s.`description` LIKE '%ราคาซื้อล่าสุด%';

UPDATE `tb_guide_step` s
  JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'เปิดหน้ากระดาษในแท็บใหม่ ตัดเมนูและปุ่มออกหมด เหลือแต่ตัวเอกสารพร้อมโลโก้บริษัทของหน่วยงานนั้น มีช่องเซ็นผู้ขอเบิกและผู้อนุมัติให้ครบ ราคาบนใบเบิกเป็นราคาประมาณการจากต้นทุนเฉลี่ยปัจจุบัน ไม่ใช่ยอดที่ลงบัญชี ตัวเลขจริงยืนยันอีกครั้งตอนจ่ายของ'
WHERE g.`page_key` = 'request_list'
  AND s.`description` LIKE '%ราคาซื้อล่าสุด%';

SELECT COUNT(*) AS `คู่มือที่พูดถึงค่าเฉลี่ย`
FROM `tb_guide_step`
WHERE `description` LIKE '%ถัวเฉลี่ย%' OR `description` LIKE '%ต้นทุนเฉลี่ย%';
