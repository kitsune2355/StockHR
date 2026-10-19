-- ============================================================
--  016_product_department.sql
--  จำกัดว่าหน่วยงานไหนเบิกสินค้าตัวใดได้บ้าง
--
--  ที่มา: ทะเบียนสินค้ามี 313 รายการรวมของทุกฝ่ายไว้ด้วยกัน
--  คนที่เปิดหน้าทำใบเบิกจึงต้องเลื่อนผ่านของที่ไม่เกี่ยวกับตัวเองเกือบทั้งหมด
--  และเบิกของที่ฝ่ายอื่นดูแลอยู่ได้โดยไม่มีอะไรกั้น
--
--  ---------------------------------------------------------------
--  ทำไมเป็นตารางเชื่อม ไม่ใช่คอลัมน์ department_id ใน tb_product
--  ---------------------------------------------------------------
--  สินค้าหนึ่งตัวอยู่ได้หลายหน่วยงาน เช่นกระดาษ A4 ที่ทุกฝ่ายเบิกได้
--  ถ้าใช้คอลัมน์เดียวจะเก็บได้แค่หน่วยงานเดียว แล้วต้องสร้างสินค้าซ้ำ
--  ตัวเดียวกันหลายแถวเพื่อให้ครบทุกฝ่าย ซึ่งทำให้ยอดคงเหลือแตกเป็นหลายก้อน
--  และรายงานรวมยอดผิดทันที
--
--  ---------------------------------------------------------------
--  สินค้าที่ยังไม่ได้ผูกกับหน่วยงานใดเลย = ทุกหน่วยงานเบิกได้
--  ---------------------------------------------------------------
--  เป็นกติกาที่จงใจเลือก ไม่ใช่ผลข้างเคียง
--
--  ทางเลือกอีกทางคือ "ไม่ผูก = ไม่มีใครเบิกได้" ซึ่งแปลว่าวินาทีที่รัน
--  ไฟล์นี้เสร็จ สินค้าทั้ง 313 รายการจะหายไปจากหน้าทำใบเบิกของทุกคนพร้อมกัน
--  จนกว่าจะมีคนไล่ผูกให้ครบ — ระบบพังทั้งระบบเพื่อรอการตั้งค่า
--
--  กติกาที่เลือกทำให้ของเดิมใช้ได้เหมือนเดิมทุกอย่าง แล้วค่อย ๆ ผูกเฉพาะ
--  ของที่ต้องการจำกัดจริง ๆ ทีละหน่วยงาน
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/016_product_department.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/016_product_department_rollback.sql
-- ============================================================

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `tb_product_department` (
  `product_id`    INT(10) UNSIGNED NOT NULL,
  `department_id` INT(10) UNSIGNED NOT NULL,

  `created_at`    DATETIME    NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`    VARCHAR(50) NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP   NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50) NULL DEFAULT NULL,

  /* คู่สินค้า-หน่วยงานต้องไม่ซ้ำ ใช้เป็นกุญแจหลักไปเลย
     ไม่ต้องมีคอลัมน์ id ที่เพิ่มเองเพราะไม่มีใครต้องอ้างถึงแถวนี้ทีละแถว */
  PRIMARY KEY (`product_id`, `department_id`),

  /* index กลับทาง สำหรับคำถาม "หน่วยงานนี้เบิกอะไรได้บ้าง"
     ซึ่งเป็นคำถามที่หน้าทำใบเบิกถามทุกครั้งที่เปิด กุญแจหลักช่วยไม่ได้
     เพราะเรียงจาก product_id ก่อน */
  KEY `idx_pd_department` (`department_id`, `product_id`),
  KEY `idx_pd_created_by` (`created_by`),
  KEY `idx_pd_updated_by` (`updated_by`),

  /* ลบสินค้าหรือหน่วยงานแล้วให้ความสัมพันธ์หายตามไปเอง (CASCADE)
     ต่างจากเอกสารที่ใช้ RESTRICT — แถวในตารางนี้ไม่ใช่หลักฐานทางธุรกิจ
     เป็นแค่การตั้งค่าว่าใครเห็นอะไร ซึ่งไม่มีความหมายถ้าปลายทางหายไปแล้ว */
  CONSTRAINT `fk_pd_product` FOREIGN KEY (`product_id`)
      REFERENCES `tb_product` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pd_department` FOREIGN KEY (`department_id`)
      REFERENCES `tb_department` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pd_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pd_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='สินค้าที่แต่ละหน่วยงานเบิกได้ (ไม่มีแถว = ทุกหน่วยงานเบิกได้)';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tb_product_department';

SELECT COUNT(*) AS `สินค้าทั้งหมด` FROM `tb_product` WHERE `is_active` = 1;
SELECT COUNT(*) AS `ที่ผูกหน่วยงานแล้ว` FROM `tb_product_department`;
