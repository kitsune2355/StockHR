-- ============================================================
--  008_create_stock_movement.sql
--  เอกสารรับเข้า / ใบเบิก / ใบจ่าย + ยอดคงเหลือ + ประวัติความเคลื่อนไหว
--
--  ขอบเขตที่ตกลงไว้:
--    - คลังเดียวรวมศูนย์ ยอดคงเหลือของสินค้าแต่ละตัวมีค่าเดียวทั้งระบบ
--      จึงไม่มี warehouse_id / agency_id ในตารางยอดคงเหลือ
--    - นับเฉพาะจำนวน ไม่คิดมูลค่า จึงไม่มีคอลัมน์ต้นทุนหรือราคาในเอกสาร
--      (tb_product.unit_cost ยังอยู่ ใช้เป็นข้อมูลอ้างอิงของทะเบียนสินค้าเท่านั้น)
--    - รับเข้าไม่ระบุผู้ขาย จึงไม่มีตาราง supplier
--
--  เส้นทางเดินของเอกสาร:
--    รับเข้า   บันทึก -> received                       (บวกสต๊อกทันทีที่บันทึก)
--    ใบเบิก   บันทึก -> waiting -> approved | rejected  (ยังไม่แตะสต๊อก)
--                                approved -> issued     (เมื่อคลังจ่ายครบทุกรายการ)
--    ใบจ่าย   บันทึก -> issued -> received              (ลบสต๊อกทันทีที่บันทึก
--                                                        received = ผู้เบิกเซ็นรับของแล้ว)
--
--  ทำไมรวมทั้ง 3 ประเภทไว้ในตารางเดียว (tb_doc_header + tb_doc_item)
--    โครงสร้างเหมือนกันเกือบทั้งหมด (เลขที่ วันที่ สถานะ + รายการสินค้าหลายบรรทัด)
--    ถ้าแยก 3 คู่ตาราง จะต้องเขียนโค้ดอ่าน/เขียน/ค้นหาซ้ำ 3 ชุด
--    และรายงานความเคลื่อนไหวที่ต้องดูข้ามประเภทจะต้อง UNION ทุกครั้ง
--    ส่วนคอลัมน์ที่ใช้เฉพาะบางประเภท (ผู้อนุมัติ ผู้รับของ) ปล่อยเป็น NULL
--
--  ข้อจำกัดของ MySQL 5.0.51b ที่ต้องเลี่ยง (เหมือนไฟล์ 005)
--    - utf8 เท่านั้น ไม่มี utf8mb4
--    - ไม่มี generated column จึงคำนวณตอน query
--    - DATETIME ตั้ง DEFAULT CURRENT_TIMESTAMP ไม่ได้ และ TIMESTAMP ที่ให้
--      ฐานข้อมูลเติมเวลาเองมีได้ตารางละคอลัมน์เดียว จึงยกให้ updated_at
--      ส่วน created_at กับเวลาอื่น ๆ ให้แอปส่ง NOW() มา
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/008_create_stock_movement.sql
-- ============================================================


-- ------------------------------------------------------------
--  ตารางที่ 1 : tb_doc_header (หัวเอกสาร)
--
--  doc_no  เลขที่เอกสารที่ระบบออกให้ รูปแบบ <คำนำหน้า>-<ปีเดือน>-<เลขรัน 4 หลัก>
--          RC = รับเข้า, RQ = ใบเบิก, IS = ใบจ่าย  เช่น RQ-2608-0001
--          เลขรันเริ่มใหม่ทุกเดือน จะได้ไม่ยาวขึ้นเรื่อย ๆ และดูออกว่าเอกสารเดือนไหน
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_doc_header` (

  `doc_id`        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_type`      ENUM('receive','request','issue') NOT NULL
                                                    COMMENT 'ประเภทเอกสาร',
  `doc_no`        VARCHAR(30)      NOT NULL         COMMENT 'เลขที่เอกสาร ระบบออกให้',
  `doc_date`      DATE             NOT NULL         COMMENT 'วันที่ของเอกสาร',

  `status`        ENUM('waiting','approved','rejected','issued','received','cancelled')
                                   NOT NULL DEFAULT 'waiting'
                                                    COMMENT 'สถานะปัจจุบัน ดูเส้นทางเดินที่หัวไฟล์',

  -- ---------- ใช้กับใบเบิกและใบจ่าย ----------
  `department_id` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'หน่วยงานผู้เบิก',
  `requester`     VARCHAR(50)      NULL DEFAULT NULL COMMENT 'username ผู้ขอเบิก',
  `ref_doc_id`    INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'ใบจ่ายอ้างถึงใบเบิกใบไหน',

  `note`          VARCHAR(500)     NOT NULL DEFAULT '' COMMENT 'หมายเหตุของเอกสาร',

  -- ---------- การอนุมัติ (ใบเบิกเท่านั้น) ----------
  `approved_by`   VARCHAR(50)      NULL DEFAULT NULL COMMENT 'username ผู้อนุมัติ/ผู้ไม่อนุมัติ',
  `approved_at`   DATETIME         NULL DEFAULT NULL COMMENT 'เวลาที่ตัดสิน',
  `approve_note`  VARCHAR(300)     NOT NULL DEFAULT '' COMMENT 'เหตุผล จำเป็นเมื่อไม่อนุมัติ',

  -- ---------- การเซ็นรับของ (ใบจ่ายเท่านั้น) ----------
  `received_by`   VARCHAR(50)      NULL DEFAULT NULL COMMENT 'username คนที่กดเซ็นรับ',
  `received_at`   DATETIME         NULL DEFAULT NULL COMMENT 'เวลาที่เซ็นรับ',
  `receiver_name` VARCHAR(150)     NOT NULL DEFAULT '' COMMENT 'ชื่อผู้รับของจริง เผื่อคนรับไม่ได้มีบัญชีในระบบ',

  -- ---------- การยกเลิก ----------
  `cancelled_by`  VARCHAR(50)      NULL DEFAULT NULL,
  `cancelled_at`  DATETIME         NULL DEFAULT NULL,
  `cancel_note`   VARCHAR(300)     NOT NULL DEFAULT '' COMMENT 'เหตุผลที่ยกเลิก',

  `created_at`    DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                    COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by`    VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`doc_id`),
  UNIQUE KEY `uq_doc_no`          (`doc_no`),

  -- ค้นตามประเภท+สถานะ คือรูปแบบที่ทุกหน้าใช้ (เช่น ใบเบิกที่รออนุมัติ)
  KEY `idx_doc_type_status`  (`doc_type`, `status`),
  KEY `idx_doc_type_date`    (`doc_type`, `doc_date`),
  KEY `idx_doc_department`   (`department_id`),
  KEY `idx_doc_requester`    (`requester`),
  KEY `idx_doc_ref`          (`ref_doc_id`),
  KEY `idx_doc_created_by`   (`created_by`),
  KEY `idx_doc_updated_by`   (`updated_by`),

  -- หน่วยงานที่ยังมีเอกสารอ้างอยู่ ห้ามลบ กันเอกสารกำพร้าจนดูไม่ออกว่าใครเบิก
  CONSTRAINT `fk_doc_department` FOREIGN KEY (`department_id`)
      REFERENCES `tb_department` (`department_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- ใบจ่ายอ้างใบเบิก ลบใบเบิกที่ถูกอ้างไม่ได้
  CONSTRAINT `fk_doc_ref` FOREIGN KEY (`ref_doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_doc_requester`  FOREIGN KEY (`requester`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_doc_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_doc_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='หัวเอกสารรับเข้า/ใบเบิก/ใบจ่าย';


-- ------------------------------------------------------------
--  ตารางที่ 2 : tb_doc_item (รายการสินค้าในเอกสาร)
--
--  qty        ใบเบิก = จำนวนที่ขอ, รับเข้า/ใบจ่าย = จำนวนที่ทำจริง
--  qty_issued ใช้เฉพาะใบเบิก = จ่ายไปแล้วกี่หน่วย
--             เก็บไว้เพื่อให้ทยอยจ่ายได้ และรู้ว่าใบเบิกใบนี้ยังค้างอยู่เท่าไร
--             ถ้าคำนวณสดจากใบจ่ายทุกครั้งต้อง JOIN ย้อนทุกหน้าที่แสดงใบเบิก
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_doc_item` (

  `item_id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc_id`     INT(10) UNSIGNED NOT NULL              COMMENT 'เอกสารต้นสังกัด',
  `line_no`    SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ลำดับบรรทัดในเอกสาร',
  `product_id` INT(10) UNSIGNED NOT NULL              COMMENT 'สินค้า',
  `qty`        DECIMAL(12,2)    NOT NULL DEFAULT 0    COMMENT 'จำนวนตามเอกสาร',
  `qty_issued` DECIMAL(12,2)    NOT NULL DEFAULT 0    COMMENT 'ใบเบิก: จ่ายไปแล้วเท่าไร',
  `note`       VARCHAR(300)     NOT NULL DEFAULT ''   COMMENT 'หมายเหตุรายบรรทัด',

  `created_at` DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by` VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at` TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`item_id`),
  KEY `idx_item_doc`        (`doc_id`, `line_no`),
  KEY `idx_item_product`    (`product_id`),
  KEY `idx_item_created_by` (`created_by`),
  KEY `idx_item_updated_by` (`updated_by`),

  -- ลบหัวเอกสาร = ลบบรรทัดตามไปด้วย บรรทัดที่ไม่มีหัวไม่มีความหมาย
  CONSTRAINT `fk_item_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE CASCADE ON UPDATE CASCADE,

  -- สินค้าที่เคยถูกเบิก/รับ ห้ามลบทิ้ง ไม่งั้นประวัติจะอ่านไม่ออก
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`)
      REFERENCES `tb_product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_item_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_item_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='รายการสินค้าในเอกสาร';


-- ------------------------------------------------------------
--  ตารางที่ 3 : tb_stock_balance (ยอดคงเหลือปัจจุบัน)
--
--  ยอดนี้คำนวณสดจาก tb_stock_movement ก็ได้ แต่เก็บไว้ต่างหาก เพราะ
--    - ทุกหน้าที่ให้เลือกสินค้าต้องแสดงยอดคงเหลือ ถ้ารวมสดทุกครั้ง
--      ต้อง SUM ประวัติทั้งหมดของสินค้า 313 ตัว ทุกครั้งที่เปิดหน้า
--    - ตอนตัดสต๊อกต้องล็อกแถวเพื่อกันสองคนเบิกพร้อมกันจนยอดติดลบ
--      ล็อกแถวเดียวในตารางนี้ทำได้ แต่ล็อกผลรวมของประวัติทำไม่ได้
--  ความถูกต้องยังตรวจย้อนได้เสมอ เพราะทุกครั้งที่ยอดเปลี่ยนจะมีแถวใน
--  tb_stock_movement คู่กันเสมอ (เขียนใน transaction เดียวกัน)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_stock_balance` (

  `product_id` INT(10) UNSIGNED NOT NULL            COMMENT 'สินค้า — คลังเดียว จึงเป็น key ได้เลย',
  `qty`        DECIMAL(12,2)    NOT NULL DEFAULT 0  COMMENT 'ยอดคงเหลือปัจจุบัน',

  `created_at` DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by` VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at` TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`product_id`),
  KEY `idx_balance_qty`        (`qty`),
  KEY `idx_balance_created_by` (`created_by`),
  KEY `idx_balance_updated_by` (`updated_by`),

  CONSTRAINT `fk_balance_product` FOREIGN KEY (`product_id`)
      REFERENCES `tb_product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_balance_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_balance_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ยอดคงเหลือปัจจุบันของแต่ละสินค้า';


-- ------------------------------------------------------------
--  ตารางที่ 4 : tb_stock_movement (ประวัติความเคลื่อนไหว)
--
--  ตารางนี้เขียนอย่างเดียว ไม่แก้ ไม่ลบ (append only)
--  การยกเลิกเอกสารจะไม่ลบแถวเดิม แต่บันทึกแถวใหม่ที่กลับทิศทางแทน
--  จะได้ตอบได้เสมอว่า "ยอดเปลี่ยนเมื่อไร เพราะเอกสารใบไหน ใครทำ"
--
--  balance_after เก็บยอดหลังรายการนี้ไว้ด้วย เพื่อให้เปิดรายงานย้อนหลัง
--  แล้วเห็นยอดคงเหลือ ณ ตอนนั้นได้ทันที ไม่ต้องไล่บวกสะสมตั้งแต่แถวแรก
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_stock_movement` (

  `move_id`       INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT(10) UNSIGNED NOT NULL           COMMENT 'สินค้า',
  `move_date`     DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                      COMMENT 'เวลาที่ยอดเปลี่ยนจริง',
  `move_type`     ENUM('receive','issue','cancel_receive','cancel_issue','adjust')
                                   NOT NULL           COMMENT 'สาเหตุที่ยอดเปลี่ยน',
  `doc_id`        INT(10) UNSIGNED NULL DEFAULT NULL  COMMENT 'เอกสารที่ทำให้ยอดเปลี่ยน',
  `doc_no`        VARCHAR(30)      NOT NULL DEFAULT '' COMMENT 'สำเนาเลขที่เอกสาร ไว้อ่านรายงานโดยไม่ต้อง JOIN',

  `qty_in`        DECIMAL(12,2)    NOT NULL DEFAULT 0 COMMENT 'จำนวนเข้า',
  `qty_out`       DECIMAL(12,2)    NOT NULL DEFAULT 0 COMMENT 'จำนวนออก',
  `balance_after` DECIMAL(12,2)    NOT NULL DEFAULT 0 COMMENT 'ยอดคงเหลือหลังรายการนี้',
  `note`          VARCHAR(300)     NOT NULL DEFAULT '',

  `created_at`    DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`    VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`move_id`),
  KEY `idx_move_product_date` (`product_id`, `move_date`),
  KEY `idx_move_date`         (`move_date`),
  KEY `idx_move_doc`          (`doc_id`),
  KEY `idx_move_type`         (`move_type`),
  KEY `idx_move_created_by`   (`created_by`),
  KEY `idx_move_updated_by`   (`updated_by`),

  CONSTRAINT `fk_move_product` FOREIGN KEY (`product_id`)
      REFERENCES `tb_product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- ประวัติต้องอยู่ต่อแม้เอกสารถูกลบ จึงเป็น SET NULL ไม่ใช่ CASCADE
  -- (doc_no ที่สำเนาไว้ยังบอกได้ว่ามาจากเอกสารเลขที่อะไร)
  CONSTRAINT `fk_move_doc` FOREIGN KEY (`doc_id`)
      REFERENCES `tb_doc_header` (`doc_id`) ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT `fk_move_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_move_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ประวัติความเคลื่อนไหวของสต๊อก เขียนอย่างเดียวไม่แก้ไม่ลบ';


-- ------------------------------------------------------------
--  ตั้งยอดคงเหลือเริ่มต้นให้สินค้าทุกตัวเป็น 0
--
--  ทำตรงนี้เลย เพื่อให้ทุกสินค้ามีแถวในตารางยอดคงเหลือแน่นอน
--  ตอนตัดสต๊อกจะได้ล็อกแถวได้ทันทีโดยไม่ต้องเผื่อกรณี "ยังไม่มีแถว"
--  ซึ่งเป็นช่องที่ทำให้สองคนบันทึกพร้อมกันแล้วยอดเพี้ยน
--  (สินค้าที่เพิ่มทีหลัง backend จะสร้างแถวให้เองตอนบันทึกครั้งแรก)
-- ------------------------------------------------------------
INSERT INTO `tb_stock_balance` (`product_id`, `qty`, `created_at`, `created_by`)
SELECT p.`product_id`, 0, NOW(), NULL
FROM `tb_product` p
WHERE p.`product_id` NOT IN (SELECT `product_id` FROM `tb_stock_balance`);


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_doc_header', 'tb_doc_item', 'tb_stock_balance', 'tb_stock_movement');

SELECT COUNT(*) AS `แถวยอดคงเหลือที่ตั้งต้นไว้` FROM `tb_stock_balance`;
