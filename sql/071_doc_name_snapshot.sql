-- ============================================================
--  071_doc_name_snapshot.sql
--  แช่แข็งชื่อคนในเอกสาร ไม่ให้เปลี่ยนตามตอนแก้ชื่อใน tb_user ภายหลัง
--
--  ---------------------------------------------------------------
--  ปัญหาที่แก้
--  ---------------------------------------------------------------
--  ผู้ขอ/ผู้อนุมัติ/ผู้บันทึกในเอกสาร (และผู้ทำในแต่ละเหตุการณ์ของ timeline)
--  เดิมไม่มีชื่อเก็บอยู่ในแถวเอง หน้าจอต้อง JOIN ไปอ่าน tb_user.full_name สด ๆ
--  ทุกครั้งที่แสดงผล ผลคือถ้าใครเปลี่ยนชื่อใน tb_user ภายหลัง เอกสารเก่าทุกใบ
--  ที่คนนั้นเคยเกี่ยวข้องจะเปลี่ยนชื่อไปด้วยทันที ทั้งที่ตอนทำรายการจริง
--  เขาใช้อีกชื่อหนึ่ง — เอกสาร/หลักฐานที่พิมพ์ไปแล้วควรตรงกับตอนที่เซ็นจริง
--  ไม่ใช่ตามชื่อปัจจุบันของ username นั้น
--
--  แนวทางเดียวกับที่ระบบทำอยู่แล้วกับ tb_doc_header.receiver_name
--  (เก็บชื่อผู้รับของเป็นข้อความตรง ๆ ตอนเซ็นรับ ไม่ join) และกับยอด/ราคา
--  ที่ "แช่แข็ง" ไว้ในแถวประวัติสต๊อกตอนอนุมัติ (ดูคอมเมนต์ backend/adjust.php)
--
--  ---------------------------------------------------------------
--  เพิ่มคอลัมน์
--  ---------------------------------------------------------------
--  tb_doc_header.requester_name    ชื่อผู้ขอ ณ ตอนสร้างเอกสาร
--  tb_doc_header.approved_by_name  ชื่อผู้อนุมัติ ณ ตอนอนุมัติ/ไม่อนุมัติ
--  tb_doc_header.created_by_name   ชื่อผู้บันทึกเอกสาร ณ ตอนบันทึก
--  tb_doc_history.actor_name       ชื่อผู้ทำเหตุการณ์นั้น ณ ตอนที่เกิดเหตุการณ์
--
--  แอป (backend/document.php ฯลฯ) ต้องเขียนคอลัมน์เหล่านี้เองทุกครั้งที่บันทึก/
--  เปลี่ยนสถานะ ไม่ใช่ให้ฐานข้อมูลคำนวณให้ — ไฟล์นี้แค่เตรียมที่เก็บ
--
--  ---------------------------------------------------------------
--  ข้อมูลเก่า (backfill)
--  ---------------------------------------------------------------
--  เอกสารที่มีอยู่ก่อนไฟล์นี้ไม่มีชื่อ ณ ตอนนั้นจริง ๆ ให้ backfill
--  (ไม่เคยถูกเก็บไว้ที่ไหนเลย) จึงทำได้แค่ "ดีที่สุดเท่าที่มี" คือคัดลอกชื่อ
--  ปัจจุบันจาก tb_user มาเติมครั้งเดียว ตั้งแต่แถวถัดไปที่เขียนใหม่หลังไฟล์นี้
--  จะแช่แข็งชื่อ ณ ตอนนั้นจริง ไม่ขยับตามอีก
--
--  ข้อจำกัด MySQL 5.0.51b: utf8 เท่านั้น, ไม่มี window function/CTE
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/071_doc_name_snapshot.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/071_doc_name_snapshot_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  [1] tb_doc_header — เพิ่มคอลัมน์ชื่อแช่แข็ง
-- ------------------------------------------------------------
ALTER TABLE `tb_doc_header`
  ADD COLUMN `requester_name` VARCHAR(150) NOT NULL DEFAULT ''
  COMMENT 'ชื่อผู้ขอ ณ ตอนสร้างเอกสาร (แช่แข็ง ไม่ join tb_user)'
  AFTER `requester`;

ALTER TABLE `tb_doc_header`
  ADD COLUMN `approved_by_name` VARCHAR(150) NOT NULL DEFAULT ''
  COMMENT 'ชื่อผู้อนุมัติ/ไม่อนุมัติ ณ ตอนตัดสิน (แช่แข็ง)'
  AFTER `approved_by`;

ALTER TABLE `tb_doc_header`
  ADD COLUMN `created_by_name` VARCHAR(150) NOT NULL DEFAULT ''
  COMMENT 'ชื่อผู้บันทึกเอกสาร ณ ตอนบันทึก (แช่แข็ง)'
  AFTER `created_by`;


-- ------------------------------------------------------------
--  [2] tb_doc_history — เพิ่มชื่อแช่แข็งของผู้ทำแต่ละเหตุการณ์
-- ------------------------------------------------------------
ALTER TABLE `tb_doc_history`
  ADD COLUMN `actor_name` VARCHAR(150) NOT NULL DEFAULT ''
  COMMENT 'ชื่อผู้ทำเหตุการณ์นี้ ณ ตอนที่เกิด (แช่แข็ง ไม่ join tb_user)'
  AFTER `actor`;


-- ------------------------------------------------------------
--  [3] Backfill ข้อมูลเก่า — คัดลอกชื่อปัจจุบันมาเติมครั้งเดียว
--      (ดูคำเตือนหัวไฟล์: ไม่ใช่ชื่อ ณ ตอนนั้นจริง เพราะไม่เคยถูกเก็บไว้)
-- ------------------------------------------------------------
UPDATE `tb_doc_header` h
LEFT JOIN `tb_user` ru ON ru.`username` = h.`requester`
SET h.`requester_name` = COALESCE(ru.`full_name`, h.`requester`, '')
WHERE h.`requester` IS NOT NULL AND h.`requester` <> '';

UPDATE `tb_doc_header` h
LEFT JOIN `tb_user` au ON au.`username` = h.`approved_by`
SET h.`approved_by_name` = COALESCE(au.`full_name`, h.`approved_by`, '')
WHERE h.`approved_by` IS NOT NULL AND h.`approved_by` <> '';

UPDATE `tb_doc_header` h
LEFT JOIN `tb_user` cu ON cu.`username` = h.`created_by`
SET h.`created_by_name` = COALESCE(cu.`full_name`, h.`created_by`, '')
WHERE h.`created_by` IS NOT NULL AND h.`created_by` <> '';

UPDATE `tb_doc_history` dh
LEFT JOIN `tb_user` u ON u.`username` = dh.`actor`
SET dh.`actor_name` = COALESCE(u.`full_name`, dh.`actor`, '')
WHERE dh.`actor` IS NOT NULL AND dh.`actor` <> '';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SHOW COLUMNS FROM `tb_doc_header` LIKE '%_name';
SHOW COLUMNS FROM `tb_doc_history` LIKE 'actor_name';

SELECT COUNT(*) AS `แถวที่ backfill ชื่อผู้ขอแล้ว`
FROM `tb_doc_header` WHERE `requester_name` <> '';

SELECT COUNT(*) AS `แถวที่ backfill ชื่อผู้อนุมัติแล้ว`
FROM `tb_doc_header` WHERE `approved_by_name` <> '';

SELECT COUNT(*) AS `แถวที่ backfill ชื่อผู้บันทึกแล้ว`
FROM `tb_doc_header` WHERE `created_by_name` <> '';

SELECT COUNT(*) AS `แถวประวัติที่ backfill ชื่อแล้ว`
FROM `tb_doc_history` WHERE `actor_name` <> '';
