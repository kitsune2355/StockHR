-- ============================================================
--  088_request_approver.sql
--  เปลี่ยนวิธีกำหนด "ใครอนุมัติใบเบิกได้"
--
--  เดิม: ผูกกับหน่วยงาน — role approver อนุมัติได้เฉพาะใบเบิกของหน่วยงาน
--        ที่ติ๊กไว้ใน "หน่วยงานที่ดูแล" (หน้าผู้ใช้งาน) ส่วน super_admin/admin อนุมัติได้ทุกใบ
--  ใหม่: รายชื่อเฉพาะ แบบเดียวกับผู้อนุมัติใบปรับยอด (tb_adjust_approver, sql/056)
--        คนในรายชื่ออนุมัติใบเบิกได้ทุกใบ ทุกหน่วยงาน ไม่ดู role
--        คนที่ไม่อยู่ในรายชื่ออนุมัติไม่ได้ แม้เป็นผู้ดูแลระบบ
--        ตั้งที่เมนู "ตั้งค่าระบบ › ผู้อนุมัติใบเบิก" (page=request_approval)
--
--  ไฟล์นี้ทำ 3 อย่าง:
--    1) สร้าง tb_request_approver (โครงเดียวกับ tb_adjust_approver)
--    2) ใส่รายชื่อตั้งต้น = ผู้ใช้ที่อนุมัติได้อยู่แล้วตามกติกาเดิม
--       (super_admin / admin / approver ที่ยังเปิดใช้งาน) วันแรกหลังรันจึงไม่มีใคร
--       เสียสิทธิ์และไม่มีใบค้างโดยไม่มีคนอนุมัติ ผู้ดูแลค่อยไปตัดรายชื่อออกเอง
--    3) คู่มือหน้า "ผู้อนุมัติใบเบิก" (page_key = request_approval)
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/088_request_approver.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/088_request_approver_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) tb_request_approver — หนึ่งแถว = ผู้ใช้หนึ่งคนที่อนุมัติใบเบิกได้
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_request_approver` (

  `username`   VARCHAR(50) NOT NULL              COMMENT 'ผู้ใช้ที่อนุมัติใบเบิกได้',

  `created_at` DATETIME    NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by` VARCHAR(50) NULL     DEFAULT NULL,
  `updated_at` TIMESTAMP   NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50) NULL     DEFAULT NULL,

  PRIMARY KEY (`username`),
  KEY `idx_request_approver_created_by` (`created_by`),
  KEY `idx_request_approver_updated_by` (`updated_by`),

  CONSTRAINT `fk_request_approver_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_request_approver_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_request_approver_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='รายชื่อผู้อนุมัติใบเบิก';


-- ------------------------------------------------------------
--  2) รายชื่อตั้งต้น — คนที่อนุมัติได้ตามกติกาเดิม
--     INSERT IGNORE ให้รันซ้ำได้ไม่ error
-- ------------------------------------------------------------
INSERT IGNORE INTO `tb_request_approver` (`username`, `created_at`, `created_by`)
SELECT `username`, NOW(), NULL
FROM `tb_user`
WHERE `is_active` = 1 AND `role` IN ('super_admin', 'admin', 'approver');


-- ------------------------------------------------------------
--  3) คู่มือหน้า "ผู้อนุมัติใบเบิก"
-- ------------------------------------------------------------
DELETE FROM `tb_guide` WHERE `page_key` = 'request_approval';

INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'request_approval', 'ผู้อนุมัติใบเบิก',
       'กำหนดว่าใครกดอนุมัติใบเบิกได้บ้าง ตั้งได้หลายคน', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'request_approval');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ใครอนุมัติใบเบิกได้บ้าง',
       'เฉพาะคนในรายชื่อนี้เท่านั้นที่อนุมัติ / ไม่อนุมัติ / ส่งกลับแก้ไขใบเบิกได้ อนุมัติได้ทุกหน่วยงาน ไม่ขึ้นกับ "หน่วยงานที่ดูแล" ในหน้าผู้ใช้งานแล้ว ผู้ดูแลระบบที่ไม่ได้อยู่ในรายชื่อก็อนุมัติไม่ได้',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'request_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#userSearch', 'ค้นหาคนที่จะเพิ่ม',
       'พิมพ์ชื่อ ชื่อผู้ใช้ หน่วยงาน หรือตำแหน่ง เพื่อกรองรายชื่อ แล้วติ๊กช่องหน้าคนที่ต้องการให้อนุมัติได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'ติ๊กแล้วกดบันทึก',
       'รายชื่อมีผลทันที คนที่ติ๊กไว้จะได้อีเมลพร้อมลิงก์กดอนุมัติทุกครั้งที่มีใบเบิกใหม่ ถ้าไม่ติ๊กใครเลย ใบเบิกที่ยื่นเข้ามาจะค้างรอจนกว่าจะตั้งผู้อนุมัติ',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'request_approval';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT ra.`username`, u.`full_name`, u.`role` FROM `tb_request_approver` ra
INNER JOIN `tb_user` u ON u.`username` = ra.`username` ORDER BY ra.`username`;
