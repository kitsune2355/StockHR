-- ============================================================
--  002_create_company_department.sql
--  โครงสร้างหน่วยงาน : บริษัท -> แผนก
--
--  อ้างอิงจากระบบเดิม (developh_stockN) ตามไฟล์ tb_company.sql
--  และ tb_department.sql จึงคงชื่อตารางและชื่อคอลัมน์เดิมไว้
--  เพื่อให้ย้ายข้อมูลจริงเข้ามาได้โดยไม่ต้องแก้ชื่อ
--
--  ที่เพิ่มจากของเดิม:
--    1. is_active   สถานะเปิด/ปิดการใช้งาน (ของเดิมไม่มี)
--    2. created_by / updated_at / updated_by  ตามกฎ audit ของโปรเจกต์นี้
--       (create_date ของเดิมทำหน้าที่เป็น created_at อยู่แล้ว จึงคงชื่อไว้)
--    3. InnoDB + FOREIGN KEY  (ของเดิมเป็น MyISAM ไม่มี FK)
--
--  ที่แก้จากของเดิม:
--    - company_name / department_name ขยายจาก VARCHAR(50) เป็น VARCHAR(200)
--      เพราะข้อมูลจริงมีชื่อบริษัทยาว 50 ตัวพอดีและอ่านแล้วขาดคำ
--      (…แฮปปี้แลนด์ อินเตอร์เนชั่น ซึ่งน่าจะเป็น อินเตอร์เนชั่นแนล)
--      ขยายขึ้นไม่ทำให้ข้อมูลเดิมเสีย และรับชื่อยาวได้ครบ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/002_create_company_department.sql
--      mysql -u root -p1234 stock_hr < sql/003_import_company_department.sql
--
--  ใช้ได้ทั้ง MySQL 5.0 (เครื่อง local) และ 5.7 (เซิร์ฟเวอร์จริง)
--  create_date ให้แอปส่ง NOW() มาเอง เพราะ MySQL 5.0 ยอมให้มีคอลัมน์ที่ใช้
--  CURRENT_TIMESTAMP อัตโนมัติได้แค่คอลัมน์เดียวต่อตาราง เราจึงยกให้ updated_at
-- ============================================================


-- ------------------------------------------------------------
--  ตารางที่ 1 : tb_company (บริษัท)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_company` (

  `company_id`   INT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name` VARCHAR(200)    NOT NULL              COMMENT 'ชื่อบริษัท (ขยายจาก 50 เป็น 200)',
  `is_active`    TINYINT(1)      NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `create_date`  DATETIME        NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                       COMMENT 'เวลาที่สร้าง — แอปส่ง NOW() มาเสมอ',
  `created_by`   VARCHAR(50)     NULL     DEFAULT NULL COMMENT 'username ของคนที่สร้าง',
  `updated_at`   TIMESTAMP       NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                                                       COMMENT 'เวลาที่แก้ล่าสุด — ฐานข้อมูลจัดการเอง',
  `updated_by`   VARCHAR(50)     NULL     DEFAULT NULL COMMENT 'username ของคนที่แก้ล่าสุด',

  PRIMARY KEY (`company_id`),
  KEY `idx_company_name`        (`company_name`),
  KEY `idx_company_is_active`   (`is_active`),
  KEY `idx_company_create_date` (`create_date`),
  KEY `idx_company_created_by`  (`created_by`),
  KEY `idx_company_updated_by`  (`updated_by`),

  CONSTRAINT `fk_company_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_company_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='บริษัท';


-- ------------------------------------------------------------
--  ตารางที่ 2 : tb_department (แผนก)
--
--  หมายเหตุ 1 — departmenttype_ID:
--    คงคอลัมน์นี้ไว้เพื่อให้ข้อมูลเดิมย้ายเข้ามาได้ครบทุกคอลัมน์
--    แต่ระบบใหม่ยังไม่ได้ใช้ จึงไม่มีตาราง tb_departmenttype
--    และไม่มี FOREIGN KEY ผูกไว้ ค่าที่ import เข้ามาจะถูกเก็บไว้เฉย ๆ
--    ถ้าวันหลังจะใช้จริง ค่อยสร้างตารางประเภทแล้วเพิ่ม FK ทีหลังได้
--
--  หมายเหตุ 2 — ไม่ได้ใส่ UNIQUE (company_id, department_name):
--    ข้อมูลจริงมีชื่อแผนกซ้ำในบริษัทเดียวกันอยู่แล้ว เช่น
--      company_id 3 มี 'กรรมการ/เลขา'      2 แถว (department_id 57, 58)
--      company_id 3 มี 'ฝ่ายบริหารกิจกรรม' 2 แถว (department_id 75, 76)
--    ถ้าใส่ UNIQUE จะย้ายข้อมูลเดิมเข้ามาไม่ได้
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_department` (

  `department_id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_name`   VARCHAR(200)     NOT NULL              COMMENT 'ชื่อแผนก (ขยายจาก 50 เป็น 200)',
  `company_id`        INT(5) UNSIGNED  NOT NULL              COMMENT 'บริษัทต้นสังกัด',
  `departmenttype_ID` INT(10) UNSIGNED NOT NULL DEFAULT 0    COMMENT 'ค่าจากระบบเดิม ระบบใหม่ยังไม่ได้ใช้',
  `is_active`         TINYINT(1)       NOT NULL DEFAULT 1    COMMENT '1 = เปิดใช้งาน, 0 = ปิดใช้งาน',

  `create_date`       DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                             COMMENT 'เวลาที่สร้าง — แอปส่ง NOW() มาเสมอ',
  `created_by`        VARCHAR(50)      NULL     DEFAULT NULL COMMENT 'username ของคนที่สร้าง',
  `updated_at`        TIMESTAMP        NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                                                             COMMENT 'เวลาที่แก้ล่าสุด — ฐานข้อมูลจัดการเอง',
  `updated_by`        VARCHAR(50)      NULL     DEFAULT NULL COMMENT 'username ของคนที่แก้ล่าสุด',

  PRIMARY KEY (`department_id`),
  KEY `idx_department_company`     (`company_id`),
  KEY `idx_department_name`        (`department_name`),
  KEY `idx_department_is_active`   (`is_active`),
  KEY `idx_department_create_date` (`create_date`),
  KEY `idx_department_created_by`  (`created_by`),
  KEY `idx_department_updated_by`  (`updated_by`),

  -- ห้ามลบบริษัทที่ยังมีแผนกอยู่ กันข้อมูลกำพร้า
  CONSTRAINT `fk_department_company` FOREIGN KEY (`company_id`)
      REFERENCES `tb_company` (`company_id`) ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT `fk_department_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_department_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='แผนกภายในบริษัท';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — ต้องเป็น InnoDB ทั้งคู่
-- ------------------------------------------------------------
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_company', 'tb_department');
