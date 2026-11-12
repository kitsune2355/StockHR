-- ============================================================
--  089_receive_approval.sql
--  ใบรับเข้ามีขั้นอนุมัติ + รวมเมนูผู้อนุมัติเป็นหน้าเดียว + ชื่อผู้ขอเบิกจากระบบสวัสดิการ
--
--  1) tb_receive_approver — รายชื่อผู้ตรวจสอบ/อนุมัติใบรับเข้า (โครงเดียวกับ tb_request_approver)
--     ใบรับเข้าใหม่: บันทึก -> waiting -> received (อนุมัติ = เพิ่มสต๊อก) | rejected
--     ใบเดิมที่ received อยู่แล้วไม่แตะ (สต๊อกเข้าไปแล้ว)
--     รายชื่อตั้งต้น = super_admin / admin ที่ยังเปิดใช้งาน
--  2) เพิ่มชนิดแจ้งเตือน receive_waiting / receive_approved / receive_rejected
--     ต่อท้าย ENUM ของ tb_notification.noti_type (ค่าเดิมไม่ขยับ)
--  3) คู่มือหน้า "ผู้อนุมัติเอกสาร" (page_key = doc_approval) แทน request_approval / adjust_approval
--  4) ใบเบิกจากระบบสวัสดิการ: ผู้ขอเบิก = คนที่กดเบิกจริง (tb_doc_welfare.requested_by_name)
--     แทน "ระบบสวัสดิการ (ผู้ขอเบิก)" — ใบใหม่โค้ดใส่ให้เองแล้ว ข้อนี้ย้อนแก้ใบเก่า
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/089_receive_approval.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/089_receive_approval_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) tb_receive_approver
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tb_receive_approver` (

  `username`   VARCHAR(50) NOT NULL              COMMENT 'ผู้ใช้ที่ตรวจ/อนุมัติใบรับเข้าได้',

  `created_at` DATETIME    NOT NULL DEFAULT '1000-01-01 00:00:00'
                                                COMMENT 'แอปส่ง NOW() มาเสมอ',
  `created_by` VARCHAR(50) NULL     DEFAULT NULL,
  `updated_at` TIMESTAMP   NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50) NULL     DEFAULT NULL,

  PRIMARY KEY (`username`),
  KEY `idx_receive_approver_created_by` (`created_by`),
  KEY `idx_receive_approver_updated_by` (`updated_by`),

  CONSTRAINT `fk_receive_approver_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_receive_approver_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_receive_approver_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='รายชื่อผู้อนุมัติใบรับเข้า';

INSERT IGNORE INTO `tb_receive_approver` (`username`, `created_at`, `created_by`)
SELECT `username`, NOW(), NULL
FROM `tb_user`
WHERE `is_active` = 1 AND `role` IN ('super_admin', 'admin')
  -- รันซ้ำได้: ใส่รายชื่อตั้งต้นเฉพาะตอนตารางยังว่าง
  AND NOT EXISTS (SELECT 1 FROM `tb_receive_approver`);


-- ------------------------------------------------------------
--  2) ชนิดแจ้งเตือนของใบรับเข้า
-- ------------------------------------------------------------
ALTER TABLE `tb_notification`
  MODIFY COLUMN `noti_type` ENUM('request_waiting','request_approved','request_rejected','request_cancelled',
                                 'request_returned','request_edited','issue_created','issue_acknowledged',
                                 'issue_cancelled','adjust_waiting','adjust_approved','adjust_rejected',
                                 'stock_low','system','welfare_request',
                                 'receive_waiting','receive_approved','receive_rejected') NOT NULL DEFAULT 'system';


-- ------------------------------------------------------------
--  3) คู่มือหน้า "ผู้อนุมัติเอกสาร"
-- ------------------------------------------------------------
DELETE FROM `tb_guide` WHERE `page_key` IN ('doc_approval', 'request_approval', 'adjust_approval');

INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'doc_approval', 'ผู้อนุมัติเอกสาร',
       'กำหนดว่าใครอนุมัติใบเบิก ใบรับเข้า และใบปรับยอดได้บ้าง', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'doc_approval');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ผู้อนุมัติแยกตามประเภทเอกสาร',
       'เฉพาะคนในรายชื่อเท่านั้นที่อนุมัติเอกสารประเภทนั้นได้ ไม่ขึ้นกับสิทธิ์หรือหน่วยงานของผู้ใช้ ผู้ดูแลระบบที่ไม่ได้อยู่ในรายชื่อก็อนุมัติไม่ได้ และไม่มีใครอนุมัติเอกสารที่ตัวเองทำได้',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'doc_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#apKindTabs', 'เลือกประเภทเอกสาร',
       'ใบเบิก = อนุมัติใบเบิกได้ทุกหน่วยงาน · ใบรับเข้า = ผู้ตรวจเทียบรายการกับใบบิล อนุมัติแล้วสินค้าจึงเข้าคลัง · ใบปรับยอด = อนุมัติแล้วยอดจึงเปลี่ยน ตัวเลขบนแท็บคือจำนวนผู้อนุมัติที่ตั้งไว้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'doc_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#userSearch', 'ค้นหาคนที่จะเพิ่ม',
       'พิมพ์ชื่อ ชื่อผู้ใช้ หน่วยงาน หรือตำแหน่ง เพื่อกรองรายชื่อ แล้วติ๊กช่องหน้าคนที่ต้องการให้อนุมัติได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'doc_approval';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnSave', 'ติ๊กแล้วกดบันทึก',
       'บันทึกทีละแท็บ รายชื่อมีผลทันที คนในรายชื่อจะได้อีเมลพร้อมลิงก์กดอนุมัติทุกครั้งที่มีเอกสารใหม่ ถ้าไม่มีใครในรายชื่อ เอกสารที่ยื่นเข้ามาจะค้างรออนุมัติ',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'doc_approval';


-- ------------------------------------------------------------
--  4) ชื่อผู้ขอเบิกของใบจากระบบสวัสดิการ (ใบเก่า)
-- ------------------------------------------------------------
UPDATE `tb_doc_header` h
INNER JOIN `tb_doc_welfare` w ON w.`doc_id` = h.`doc_id`
SET h.`requester_name` = LEFT(w.`requested_by_name`, 150)
WHERE w.`requested_by_name` <> '';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT 'receive approvers' AS `what`, COUNT(*) AS `n` FROM `tb_receive_approver`
UNION ALL
SELECT 'welfare docs renamed', COUNT(*) FROM `tb_doc_header` h
  INNER JOIN `tb_doc_welfare` w ON w.`doc_id` = h.`doc_id` WHERE h.`requester_name` = w.`requested_by_name`;
