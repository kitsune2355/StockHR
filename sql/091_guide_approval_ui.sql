-- ============================================================
--  091_guide_approval_ui.sql
--  ปรับคู่มือ (driver.js) ให้ตรงกับของที่เปลี่ยนไป
--
--    - ใบรับเข้ามีขั้นอนุมัติ (sql/089) + แนบใบบิล + กล่องเทียบบิล
--    - เมนู "ตั้งค่าอนุมัติ" กลายเป็น "ผู้อนุมัติเอกสาร" (แท็บ ใบเบิก/ใบรับเข้า/ใบปรับยอด)
--    - ผู้อนุมัติใบเบิกเป็นรายชื่อเฉพาะ ไม่ใช่ "ผู้อนุมัติของหน่วยงาน" แล้ว (sql/088)
--    - ตัวกรองทุกหน้าพับอยู่หลังปุ่ม "ตัวกรอง" (ไม่กางค้างทางขวาแล้ว)
--    - คลิกแถวในตารางเอกสาร = เปิดรายละเอียด
--    - หน่วยงานที่ดูแลเลือก "ทุกหน่วยงาน ทุกบริษัท" ได้ (sql/090)
--
--  อ้างขั้นตอนด้วย page_key + target_selector/step_order ไม่ใช้ step_id
--  เพราะ step_id บนเครื่องจริงกับเครื่องพัฒนาไม่ตรงกัน
--  รันซ้ำได้ (REPLACE เดิมไม่เจอก็ไม่เปลี่ยน, ชุด receive_* ลบแล้วใส่ใหม่ทั้งชุด)
--
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/091_guide_approval_ui.sql
--  ย้อนกลับ: ไม่มีไฟล์ rollback — ข้อความคู่มือเดิมไม่ตรงกับระบบแล้ว
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) หน้า "บันทึกรับเข้า" — ลบแล้วใส่ใหม่ทั้งชุด
-- ------------------------------------------------------------
DELETE s FROM `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'receive_new';

UPDATE `tb_guide` SET `description` = 'บันทึกของที่เข้าคลังพร้อมแนบใบบิล ผู้ตรวจสอบเทียบกับบิลแล้วอนุมัติ สต๊อกจึงเพิ่ม'
WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'บันทึกแล้วรอผู้ตรวจสอบ ก่อนสต๊อกเพิ่ม',
       'ใบรับเข้าต้องผ่านผู้ตรวจสอบก่อน — ผู้ตรวจเทียบรายการ จำนวน และราคากับใบบิลที่แนบ แล้วกดอนุมัติ สต๊อกจึงเพิ่ม ถ้าไม่อนุมัติ สต๊อกไม่เปลี่ยน และคุณจะได้แจ้งเตือนพร้อมเหตุผล (ระหว่างเปิดคู่มือมีรายการตัวอย่างให้ดู หายเองตอนปิด)',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#docDate', 'วันที่เอกสาร',
       'ใช้วันที่ของใบส่งของจริง ไม่ใช่วันที่คีย์เข้าระบบ เพราะเลขที่เอกสารกับรายงานรายเดือนอ้างอิงวันที่นี้ ลงวันที่ล่วงหน้าไม่ได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#attachBox', 'แนบรูปหรือ PDF ของใบบิล',
       'ผู้ตรวจสอบใช้ไฟล์นี้เทียบกับรายการที่คุณคีย์ และฝ่ายบัญชีใช้เป็นหลักฐาน แนบได้หลายไฟล์ (สูงสุด 10 ไฟล์ ไฟล์ละไม่เกิน 10 MB) กดชื่อไฟล์เพื่อพรีวิวก่อนบันทึกได้ ลืมแนบก็เพิ่มทีหลังได้ที่หน้าประวัติการรับเข้า',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#scanInput', 'ยิงรหัสแทนการค้นหา',
       'คลิกที่ช่องนี้แล้วยิงได้เลย ระบบเพิ่มบรรทัดพร้อมสินค้าและราคาให้ทันที ยิงซ้ำตัวเดิมจะบวกจำนวน ไม่สร้างบรรทัดใหม่ เสียงสูงสั้นคือเจอ เสียงต่ำยาวคือไม่เจอ ช่องยิงอยู่ในกรอบโหมดยิงบาร์โค้ด ถ้าปิดไว้ กดปุ่ม "ยิงบาร์โค้ด" ที่แถบด้านบนเพื่อเปิด',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#btnScanCam', 'ไม่มีเครื่องยิงก็ใช้กล้องได้',
       'เปิดกล้องส่องฉลากแทนการยิง เหมาะกับตอนเปิดหน้านี้จากมือถือขณะยืนอยู่ที่กองของ ส่องได้ทั้งบาร์โค้ดและ QR ส่องติดแล้วเพิ่มบรรทัดให้เหมือนการยิง แล้วปิดกล้องให้เอง',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#items', 'ราคาเติมให้อัตโนมัติ',
       'เลือกสินค้าแล้วช่องราคาจะเติมราคาซื้อล่าสุดให้เอง รอบนี้ซื้อได้ราคาอื่นก็แก้ทับได้ ให้ตรงกับบิล เพราะผู้ตรวจเทียบราคากับบิลด้วย ของตัวเดียวกันที่รับเข้ามาคนละราคาให้แยกเป็นคนละบรรทัด',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#btnSave', 'ตรวจยอดรวมก่อนกด',
       'ยอดรวมเป็นเงินอยู่ทางซ้ายของปุ่มนี้ ควรตรงกับใบบิลก่อนกดบันทึก กดแล้วใบจะรอผู้ตรวจสอบ และระบบแจ้งผู้อนุมัติใบรับเข้าให้เอง (กระดิ่ง + อีเมลพร้อมลิงก์อนุมัติ) ถ้าขึ้นว่า "ยังไม่มีใครได้รับแจ้ง" แปลว่ายังไม่มีผู้อนุมัติคนอื่นนอกจากคุณ ให้ผู้ดูแลระบบเพิ่มที่เมนู "ผู้อนุมัติเอกสาร"',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';


-- ------------------------------------------------------------
--  2) หน้า "ประวัติการรับเข้า" — ลบแล้วใส่ใหม่ทั้งชุด
--     แถวสาธิตของคู่มือหน้านี้อยู่สถานะ "รออนุมัติ" (doc-list.js demoRow)
--     ขั้นที่ชี้ไอคอนอนุมัติจะขึ้นเฉพาะคนที่เป็นผู้อนุมัติใบรับเข้า (คนอื่นข้ามไปเอง)
-- ------------------------------------------------------------
DELETE s FROM `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'receive_history';

UPDATE `tb_guide` SET `description` = 'ติดตามใบรับเข้า ตรวจเทียบกับใบบิล แล้วอนุมัติให้สินค้าเข้าคลัง'
WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'สถานะและผู้ทำรายการ',
       'รออนุมัติ = ยังไม่เพิ่มสต๊อก รอผู้ตรวจสอบ · รับของแล้ว = อนุมัติแล้ว สต๊อกเพิ่มแล้ว · ไม่อนุมัติ = สต๊อกไม่เปลี่ยน · ยกเลิก = ถูกยกเลิก คอลัมน์ "ผู้ทำรายการ" บอกว่าใครคีย์ใบนั้น คลิกที่แถวไหนก็ได้เพื่อเปิดรายละเอียด (แถวบนสุดเป็นตัวอย่างสาธิต หายเองตอนปิดคู่มือ)',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#quickStatusFilters', 'กรองสถานะเร็ว',
       'กดชิป "รออนุมัติ" เพื่อดูเฉพาะใบที่รอตรวจในคลิกเดียว ตัวเลขบนเมนู "ประวัติการรับเข้า" คือจำนวนใบที่รอคุณตรวจอยู่',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — เปิดกล่องเทียบบิล',
       'ซ้ายคือไฟล์ใบบิล (ซูม หมุน สลับไฟล์ ดูเต็มจอได้) ขวาคือรายการที่คีย์ไว้ ไล่ตรวจทีละบรรทัด คลิกแถวหรือกด Space เพื่อติ๊กว่าตรงกับบิลแล้ว ระบบเลื่อนไปแถวถัดไปให้ และนับ "ตรวจแล้ว x/y" ติ๊ก "ซ่อนที่ตรวจแล้ว" เพื่อเหลือแต่ที่ยังไม่ได้ดู (การติ๊กเป็นตัวช่วยจำ ไม่ได้บันทึก)',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '.rowact[data-row-act="approve"]', 'ไอคอนถูก — อนุมัติ (สต๊อกเพิ่ม)',
       'ขึ้นเฉพาะคนในรายชื่อผู้อนุมัติใบรับเข้า (เมนู "ผู้อนุมัติเอกสาร") อนุมัติแล้วสินค้าเข้าคลังทันที ใบที่คุณบันทึกเองจะเห็นไอคอนจาง ๆ กดแล้วจะบอกว่าต้องให้ผู้อนุมัติคนอื่นตรวจ',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '.rowact[data-row-act="reject"]', 'ไอคอนกากบาท — ไม่อนุมัติ',
       'ใช้เมื่อรายการไม่ตรงกับบิล ต้องระบุว่าบรรทัดไหนผิด เหตุผลถูกส่งไปให้ผู้บันทึก เขาจะได้บันทึกใบใหม่ให้ถูก สต๊อกไม่เปลี่ยน',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '.rowact[href^="print_doc.php"]', 'ไอคอนเครื่องพิมพ์',
       'เปิดหน้ากระดาษในแท็บใหม่ ตัดเมนูและปุ่มออกหมด เหลือแต่ตัวเอกสาร กดแล้วสั่งพิมพ์ได้เลย',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '.rowmenu-demo [data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิกใบ',
       'ใบที่ยังรออนุมัติ ยกเลิกได้เลย (สต๊อกยังไม่เคยเพิ่ม) ใบที่รับของแล้ว ยกเลิกจะดึงยอดออกจากสต๊อก ถ้าของถูกเบิกออกไปแล้วจะยกเลิกไม่ได้ ต้องระบุเหตุผลทุกครั้ง',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '#fpToggle', 'ตัวกรอง',
       'ช่วงวันที่ สถานะ และคำค้น อยู่ในแถบที่เปิดจากปุ่มนี้ ตัวเลขบนปุ่มคือจำนวนตัวกรองที่ตั้งอยู่ ตารางกับไฟล์ที่ส่งออกใช้เงื่อนไขชุดเดียวกัน',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 9, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
       'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัดพร้อมราคาต่อหน่วย ใช้ตอบคำถามแบบ "เดือนนี้รับเสื้อไซซ์ L กี่ครั้ง ครั้งละเท่าไร"',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';


-- ------------------------------------------------------------
--  3) หน้า "ผู้ใช้งาน" ขั้นแรก — หน่วยงานไม่ได้กำหนดผู้อนุมัติแล้ว
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'หน่วยงานที่ดูแลกับอีเมล',
    s.`description` = 'หน่วยงานที่ดูแลกำหนดว่าผู้อนุมัติเห็นใบเบิก/ใบจ่ายของหน่วยงานไหน ดับเบิลคลิกช่องนั้นเพื่อเลือกหลายหน่วยงาน หรือติ๊ก "ทุกหน่วยงาน ทุกบริษัท" สำหรับคนที่ดูแลทั้งองค์กร ส่วนใครอนุมัติเอกสารได้ ตั้งแยกที่เมนู "ผู้อนุมัติเอกสาร" อีเมลคือช่องทางที่ระบบใช้แจ้งเรื่อง คนที่ไม่ได้กรอกจะได้แค่กระดิ่งในระบบ'
WHERE g.`page_key` = 'users' AND s.`target_selector` = '#grid' AND s.`step_order` = 1;


-- ------------------------------------------------------------
--  4) ตัวกรองพับหลังปุ่มทุกหน้าแล้ว
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ตัวกรอง',
    s.`description` = 'ช่วงวันที่และคำค้น (เลขที่เอกสาร / รหัส-ชื่อสินค้า / หมายเหตุ) อยู่ในแถบที่เปิดจากปุ่มนี้ ตัวเลขบนปุ่มคือจำนวนตัวกรองที่ตั้งอยู่ ทั้งตารางและไฟล์ที่ส่งออกใช้เงื่อนไขชุดเดียวกัน'
WHERE g.`page_key` = 'adjust_history' AND s.`target_selector` = '#fpToggle';

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ตัวกรองทุกหน้าพับอยู่หลังปุ่มนี้ ตารางจะได้กว้างเต็มที่ กดเพื่อเปิดแถบตัวกรองจากด้านข้าง ตัวเลขบนปุ่มคือจำนวนตัวกรองที่ตั้งอยู่'
WHERE g.`page_key` IN ('request_list', 'new_staff_list') AND s.`target_selector` = '#fpToggle';


-- ------------------------------------------------------------
--  5) คลิกแถว = เปิดรายละเอียด (หน้าเอกสารที่เปิดเป็นกล่อง)
-- ------------------------------------------------------------
UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = CONCAT(s.`description`, ' — หรือคลิกที่แถวไหนก็ได้ ก็เปิดกล่องนี้เหมือนกัน')
WHERE g.`page_key` IN ('adjust_history', 'issue_history', 'new_staff_list')
  AND s.`target_selector` = '.rowact[data-row-act="open"]'
  AND s.`description` NOT LIKE '%คลิกที่แถวไหนก็ได้%';


-- ------------------------------------------------------------
--  6) ชื่อเมนูและผู้รับแจ้งที่เปลี่ยนไป
-- ------------------------------------------------------------
UPDATE `tb_guide_step` SET `description` = REPLACE(`description`, 'เมนู "ตั้งค่าอนุมัติ"', 'เมนู "ผู้อนุมัติเอกสาร"');
UPDATE `tb_guide_step` SET `description` = REPLACE(`description`, 'หน้า "ตั้งค่าอนุมัติ"', 'เมนู "ผู้อนุมัติเอกสาร" แท็บใบปรับยอด');
UPDATE `tb_guide_step` SET `description` = REPLACE(`description`, 'แจ้งผู้อนุมัติของหน่วยงานให้เอง', 'แจ้งผู้อนุมัติใบเบิกให้เอง');


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT g.`page_key`, COUNT(s.`step_id`) AS `steps`
FROM `tb_guide` g LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
WHERE g.`page_key` IN ('receive_new', 'receive_history', 'users', 'adjust_history', 'doc_approval')
GROUP BY g.`page_key`;

SELECT COUNT(*) AS `ยังมีคำว่าตั้งค่าอนุมัติ` FROM `tb_guide_step` WHERE `description` LIKE '%ตั้งค่าอนุมัติ%';
