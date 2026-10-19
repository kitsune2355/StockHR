-- ============================================================
--  074_uniform_catalog_rollback.sql
--  ย้อนกลับไปใช้ทะเบียนเครื่องเขียนชุดเดิม (006) พร้อมชุดพนักงานใหม่ 4 รายการ (072)
--
--  คำเตือน: 006 ลบสินค้าโดยปิดการตรวจ foreign key ถ้ามีใบเบิก/รับ/จ่าย
--           ชุดฟอร์มอยู่แล้ว เอกสารเหล่านั้นจะชี้ไปสินค้าผิดตัว
--           ต้องรัน 039_reset_transactions.sql ก่อน หรือสำรองฐานข้อมูลไว้
--
--  ต้องรันจากรากโปรเจกต์ (คำสั่ง SOURCE อ้างพาธจากโฟลเดอร์ที่รัน mysql):
--      cd C:/AppServ/www/stock_hr
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/074_uniform_catalog_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_new_staff_kit`;
DELETE FROM `tb_product_department`;
DELETE FROM `tb_product_image`;
DELETE FROM `tb_stock_balance`;

SOURCE sql/006_import_product_catalog.sql;
SOURCE sql/040_seed_stock_balance.sql;

INSERT IGNORE INTO `tb_new_staff_kit`
       (`product_id`, `default_qty`, `must_return`, `sort_order`, `is_active`, `created_at`, `created_by`)
SELECT p.`product_id`, 1, k.`must_return`, k.`sort_order`, 1, NOW(), NULL
FROM (
          SELECT 'OF-STA-083' AS `code`, 0 AS `must_return`, 1 AS `sort_order`
    UNION SELECT 'OF-STA-142',           0,                  2
    UNION SELECT 'OF-STA-141',           0,                  3
    UNION SELECT 'OF-STA-036',           1,                  4
) k
INNER JOIN `tb_product` p ON p.`product_code` = k.`code`;

UPDATE `tb_app_setting`
SET `setting_value` = 'รายการที่ 1-4 เป็นรายการพื้นฐานสำหรับพนักงานเข้าใหม่\nพนักงานที่รับรายการเครื่องเขียนจะต้องดูแลรักษาอุปกรณ์มิให้สูญหาย (ตั้งแต่ลำดับที่ 4 ขึ้นไป) และส่งคืนฝ่ายทรัพยากรบุคคลเมื่อลาออก หรือสิ้นสภาพจากการเป็นพนักงาน หากสูญหายพนักงานต้องชดใช้ค่าความเสียหายตามมูลค่าของวัสดุสำนักงานหลังหักค่าเสื่อมแล้ว\nกรณีวัสดุสำนักงานลำดับ 4 และวัสดุสำนักงานถาวร และมีมูลค่าที่เบิกเพิ่ม ชำรุดเสียหาย และต้องการเบิกทดแทนต้องนำของเดิมมาแลกกับของใหม่ด้วยทุกครั้ง'
WHERE `setting_key` = 'new_staff_terms';

UPDATE `tb_guide_step`
SET `description` = REPLACE(REPLACE(`description`,
        'พิมพ์ UF-PMB เพื่อกรองเฉพาะเสื้อฟอร์มผู้ชาย PRO (ดำ) ทุกไซซ์', 'พิมพ์ CS-MED เพื่อกรองเฉพาะกลุ่มยา'),
        'หมวด UF-PMB จะออกรหัสสินค้าเป็น UF-PMB-001, UF-PMB-002', 'หมวด CS-MED จะออกรหัสสินค้าเป็น CS-MED-001, CS-MED-002')
WHERE `description` LIKE '%UF-PMB%';
