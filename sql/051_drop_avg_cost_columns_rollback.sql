-- ============================================================
--  051_drop_avg_cost_columns_rollback.sql
--  เอาคอลัมน์ต้นทุนถัวเฉลี่ยกลับคืน
--
--  ⚠ ได้คืนแค่ "โครงสร้าง" ค่าเฉลี่ยเดิมที่เคยเก็บไว้หายไปแล้ว
--    ทุกแถวจะกลับมาเป็น 0 ซึ่งไม่เท่ากับของเดิม
--
--  ถ้าจะกลับไปใช้วิธีถัวเฉลี่ยจริง ๆ ต้องย้อนโค้ดฝั่ง PHP ด้วย
--  (backend/stock.php, document.php, dashboard.php, report_cost.php)
--  แล้วไล่คำนวณค่าเฉลี่ยขึ้นมาใหม่จากประวัติทั้งหมดใน tb_stock_movement
--
--  ไฟล์นี้ไม่ไล่คำนวณให้ เพราะการเดาค่าที่หายแล้วเขียนลงไปเงียบ ๆ
--  อันตรายกว่าปล่อยให้เป็น 0 ที่เห็นชัดว่ายังไม่ได้คำนวณ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/051_drop_avg_cost_columns_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_stock_balance`
  ADD COLUMN `avg_cost` DECIMAL(12,4) NOT NULL DEFAULT 0
    COMMENT 'ต้นทุนถัวเฉลี่ยเคลื่อนที่ ณ ปัจจุบัน' AFTER `qty`;

ALTER TABLE `tb_stock_movement`
  ADD COLUMN `avg_cost_after` DECIMAL(12,4) NOT NULL DEFAULT 0
    COMMENT 'ต้นทุนเฉลี่ยหลังรายการนี้ — ไว้ตรวจย้อนว่าเฉลี่ยขยับเพราะอะไร' AFTER `balance_after`;

SELECT COUNT(*) AS `คอลัมน์ค่าเฉลี่ยที่มี`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `COLUMN_NAME` IN ('avg_cost', 'avg_cost_after');
