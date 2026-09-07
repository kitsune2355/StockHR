-- ============================================================
-- 054_user_departments.sql
-- ผู้ใช้หนึ่งคนดูแล/อนุมัติได้หลายหน่วยงาน
-- เก็บ dept_id เดิมไว้เป็นหน่วยงานหลักสำหรับการทำใบเบิกของผู้ใช้
-- ============================================================

CREATE TABLE IF NOT EXISTS `tb_user_department` (
  `username`   VARCHAR(50) NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `created_by` VARCHAR(50) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50) NULL DEFAULT NULL,
  PRIMARY KEY (`username`, `department_id`),
  KEY `idx_user_department_department` (`department_id`),
  CONSTRAINT `fk_user_department_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_department_department` FOREIGN KEY (`department_id`)
      REFERENCES `tb_department` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ขอบเขตหน่วยงานที่ผู้ใช้ดูแลหรืออนุมัติ';

INSERT IGNORE INTO `tb_user_department` (`username`, `department_id`, `created_at`, `created_by`)
SELECT `username`, `dept_id`, NOW(), `created_by`
FROM `tb_user`
WHERE `dept_id` IS NOT NULL AND `dept_id` > 0;
