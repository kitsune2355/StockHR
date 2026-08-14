-- ============================================================
--  028_guide_settings_split_rollback.sql
--  คืนคู่มือหน้าตั้งค่ากลับเป็นหน้าเดียวสองแท็บ
--
--  ใช้คู่กับการคืนเมนูใน includes/menu.php และแท็บใน pages/settings.php
--  ลำพัง SQL อย่างเดียวจะได้คู่มือที่ชี้ไปยังปุ่มแท็บที่ยังไม่มีในหน้า
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/028_guide_settings_split_rollback.sql
-- ============================================================

SET NAMES utf8;

-- คู่มือของหน้าคู่มือ
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'settings_guide';

DELETE FROM `tb_guide` WHERE `page_key` = 'settings_guide';

-- ชื่อคู่มือหน้าตั้งค่ากลับเป็นของเดิม
UPDATE `tb_guide`
SET `title` = 'ตั้งค่าระบบ',
    `description` = 'อีเมลแจ้งเตือนและข้อความในคู่มือ'
WHERE `page_key` = 'settings';

-- ขั้นสุดท้ายกลับไปชี้ที่ปุ่มแท็บ
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`target_selector` = '#tabGuideBtn',
    s.`title` = 'แก้ข้อความในคู่มือได้',
    s.`description` = 'ข้อความทุกขั้นตอนของคู่มือทุกหน้าแก้ได้จากแท็บนี้ ผู้ใช้จะเห็นข้อความใหม่ทันทีที่เปิดคู่มือครั้งถัดไป ใช้ปรับถ้อยคำให้ตรงกับวิธีทำงานจริงของที่นี่ได้โดยไม่ต้องแก้โปรแกรม'
WHERE g.`page_key` = 'settings' AND s.`title` = 'คู่มือย้ายไปเมนูของตัวเองแล้ว';
