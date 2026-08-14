-- ============================================================
--  012_guide_more_pages.sql
--  เติมคู่มือให้ครบทุกหน้าที่มีในระบบ
--
--  ที่มา: 011 เติมคู่มือให้ 13 หน้าที่ใช้บ่อยที่สุด เหลืออีก 5 หน้าที่ยังว่าง
--  ผลคือปุ่มคู่มือบนแถบบนหายไปเฉพาะบางหน้า ซึ่งดูเหมือนระบบทำงานไม่สม่ำเสมอ
--  มากกว่าจะดูเหมือน "หน้านี้ไม่จำเป็นต้องมีคู่มือ"
--
--  ห้าหน้าที่เติม:
--    product_category   หมวดหมู่สินค้า
--    receive_history    ประวัติการรับเข้า
--    report_movement    ความเคลื่อนไหว
--    agencies           หน่วยงาน
--    permissions        สิทธิ์การใช้งาน
--
--  ใช้ INSERT ... SELECT ที่กรองด้วย NOT EXISTS เพื่อให้รันซ้ำได้โดยไม่เกิดข้อมูลซ้ำ
--  (page_key มี UNIQUE อยู่แล้ว แต่การรันซ้ำจะพังกลางคันแทนที่จะข้ามไปเงียบ ๆ)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/012_guide_more_pages.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hl < sql/012_guide_more_pages_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  หมวดหมู่สินค้า
-- ------------------------------------------------------------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'product_category', 'จัดการหมวดหมู่สินค้า',
       'หมวดหมู่หลักและหมวดหมู่ย่อย ซึ่งเป็นตัวกำหนดรหัสสินค้า', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'product_category');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'หมวดหมู่ทั้งหมด',
       'หมวดหมู่ย่อยเป็นตัวกำหนดรหัสสินค้า เช่นหมวด CS-MED จะออกรหัสให้เป็น CS-MED-001, CS-MED-002 ไปเรื่อย ๆ เปลี่ยนรหัสนำหน้าทีหลังจะไม่ย้อนไปแก้สินค้าเดิมที่ออกรหัสไปแล้ว',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_category';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnAddRows', 'เพิ่มหมวดหมู่',
       'เลือกจำนวนแถวแล้วกดเพิ่ม กรอกชื่อและเลือกหมวดหมู่แม่ให้ครบก่อนบันทึก',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_category';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '', 'ลบหมวดหมู่ที่มีสินค้าอยู่ไม่ได้',
       'ถ้ามีสินค้าผูกอยู่ ระบบจะไม่ยอมให้ลบ เพราะสินค้าที่ไม่มีหมวดหมู่จะหายไปจากทุกรายงาน ถ้าเลิกใช้แล้วให้ปิดการใช้งานแทน ข้อมูลเก่าจะยังอ้างถึงได้เหมือนเดิม',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'product_category';


-- ------------------------------------------------------------
--  ประวัติการรับเข้า
-- ------------------------------------------------------------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'receive_history', 'ประวัติการรับสินค้าเข้า',
       'ตรวจย้อนหลังและยกเลิกใบที่บันทึกผิด', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'receive_history');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ใบรับเข้าทั้งหมด',
       'กดที่แถวเพื่อดูรายการสินค้าในใบนั้น พร้อมราคาต่อหน่วยที่บันทึกไว้',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'บันทึกผิดต้องยกเลิก ไม่ใช่แก้ตัวเลข',
       'เปิดรายละเอียดใบนั้นแล้วกด &quot;ยกเลิก&quot; ระบบจะถอนของออกจากสต๊อกและคืนมูลค่าต้นทุนให้เองอย่างถูกต้อง แล้วค่อยทำใบใหม่ การไปแก้ยอดคงเหลือตรง ๆ จะทำให้ตัวเลขกับประวัติไม่ตรงกันถาวร',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnExport', 'ส่งออกเป็น Excel',
       'ได้ไฟล์ตามเงื่อนไขที่กรองอยู่ตอนนั้น ใช้ส่งให้ฝ่ายบัญชีตรวจกับใบเสร็จได้',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';


-- ------------------------------------------------------------
--  ความเคลื่อนไหว
-- ------------------------------------------------------------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'report_movement', 'อ่านรายงานความเคลื่อนไหว',
       'ไล่ดูว่าของหายไปไหนและเข้ามาเมื่อไร', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'report_movement');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ทุกการเคลื่อนไหวของสต๊อก',
       'หนึ่งบรรทัดคือของเข้าหรือออกหนึ่งครั้ง พร้อมยอดคงเหลือหลังรายการนั้น ใช้ไล่ย้อนได้ว่ายอดปัจจุบันมาจากไหน เวลาตรวจนับแล้วตัวเลขไม่ตรง ให้เริ่มจากหน้านี้',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'report_movement';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#fpToggle', 'กรองตามประเภทและช่วงวันที่',
       'เลือกดูเฉพาะรับเข้า จ่ายออก หรือรายการที่เกิดจากการยกเลิก ใช้คู่กับช่วงวันที่เพื่อปิดยอดรายเดือน',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'report_movement';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '', 'รายการที่นี่ลบไม่ได้',
       'ทุกบรรทัดถูกเขียนอัตโนมัติตอนบันทึกเอกสาร และไม่มีใครแก้ได้ รวมถึงผู้ดูแลระบบ นี่คือสิ่งที่ทำให้ตัวเลขในระบบเชื่อถือได้ ถ้าบันทึกผิดให้ยกเลิกที่ตัวเอกสาร ระบบจะเขียนบรรทัดกลับรายการให้เอง',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'report_movement';


-- ------------------------------------------------------------
--  หน่วยงาน
-- ------------------------------------------------------------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'agencies', 'จัดการหน่วยงาน',
       'หน่วยงานคือตัวจับคู่ใบเบิกกับผู้อนุมัติ', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'agencies');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'หน่วยงานทั้งหมด',
       'แก้ชื่อในช่องได้เลย หน่วยงานที่ปิดใช้งานจะไม่ขึ้นให้เลือกในหน้าทำใบเบิกอีก แต่เอกสารเก่ายังอ้างถึงได้ตามปกติ',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'agencies';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'หน่วยงานเชื่อมกับเส้นทางอนุมัติอย่างไร',
       'ใบเบิกจะถูกส่งให้ &quot;ผู้อนุมัติที่สังกัดหน่วยงานเดียวกับผู้ขอ&quot; เท่านั้น หน่วยงานที่ยังไม่มีผู้อนุมัติ ระบบจะส่งให้ผู้ดูแลระบบแทนเพื่อไม่ให้ใบค้างโดยไม่มีใครรู้',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'agencies';


-- ------------------------------------------------------------
--  สิทธิ์การใช้งาน
-- ------------------------------------------------------------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'permissions', 'กำหนดสิทธิ์เข้าถึงเมนู',
       'เลือกว่าสิทธิ์ไหนเห็นเมนูใดบ้าง', 1, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'permissions');

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ตารางสิทธิ์',
       'ติ๊กเพื่อเปิดสิทธิ์เข้าถึงเมนูนั้นให้แต่ละกลุ่มผู้ใช้ ปิดเมนูแล้วพิมพ์ที่อยู่ตรง ๆ ก็เข้าไม่ได้ เพราะระบบตรวจซ้ำที่ฝั่งเซิร์ฟเวอร์อีกชั้น',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'permissions';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'ผู้ดูแลระบบสูงสุดปิดไม่ได้',
       'ช่องของผู้ดูแลระบบสูงสุดถูกล็อกเปิดไว้เสมอ เพราะถ้าเผลอปิดสิทธิ์ตัวเองจนหมด จะไม่มีใครเข้ามาแก้คืนได้อีกเลย',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'permissions';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnReset', 'คืนค่าเริ่มต้น',
       'ลบค่าที่ตั้งไว้ทั้งหมด แล้วกลับไปใช้สิทธิ์ตั้งต้นที่กำหนดไว้ในโค้ด ใช้ตอนตั้งค่าจนงงแล้วอยากเริ่มใหม่',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'permissions';


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์ — ต้องได้ครบ 18 หน้า
-- ------------------------------------------------------------
SELECT g.`page_key`, g.`title`, COUNT(s.`step_id`) AS `จำนวนขั้นตอน`
FROM `tb_guide` g
LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
GROUP BY g.`guide_id`, g.`page_key`, g.`title`
ORDER BY g.`page_key`;

SELECT COUNT(*) AS `คู่มือทั้งหมด` FROM `tb_guide`;
SELECT COUNT(*) AS `ขั้นตอนทั้งหมด` FROM `tb_guide_step`;
