-- ============================================================
--  093_guide_detail_popup.sql
--  คู่มือของกล่องรายละเอียด ย้ายเข้าไปอยู่ในคู่มือของหน้า (แทนปุ่ม ? ในกล่อง)
--
--  คู่มือหน้าพาดูไอคอนรูปตาแล้ว กล่องรายละเอียดตัวอย่าง (ข้อมูลสมมติ) จะเปิดขึ้น
--  แล้วพาดูของในกล่องต่อทีละขั้น จบแล้วกล่องปิดเอง กลับมาอธิบายหน้าต่อ
--  (doc-list.js: demoDetail / GuideDemo.beforeStep, app-guide.js: onHighlightStarted)
--
--  1) ลบคู่มือ receive_detail ของ sql/092 (เลิกใช้ปุ่ม ? ในกล่องแล้ว)
--  2) ประวัติการรับเข้า: ใส่ชุดใหม่ทั้งหมด — ต่อขั้นไอคอนรูปตาด้วยขั้นในกล่องเทียบบิล
--  3) รายการใบเบิก: แทรกขั้นในกล่องรายละเอียด 3 ขั้นต่อจากไอคอนรูปตา
--
--  รันซ้ำได้
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/093_guide_detail_popup.sql
-- ============================================================

SET NAMES utf8;


-- ------------------------------------------------------------
--  1) เลิกใช้คู่มือแยกของกล่อง
-- ------------------------------------------------------------
DELETE FROM `tb_guide_seen` WHERE `page_key` = 'receive_detail';
DELETE FROM `tb_guide` WHERE `page_key` = 'receive_detail';


-- ------------------------------------------------------------
--  2) ประวัติการรับเข้า — ลบแล้วใส่ใหม่ทั้งชุด
-- ------------------------------------------------------------
DELETE s FROM `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'สถานะและผู้ทำรายการ',
       'รออนุมัติ = ยังไม่เพิ่มสต๊อก รอผู้ตรวจสอบ · รับของแล้ว = อนุมัติแล้ว สต๊อกเพิ่มแล้ว · ไม่อนุมัติ = สต๊อกไม่เปลี่ยน · ยกเลิก = ถูกยกเลิก คอลัมน์ "ผู้ทำรายการ" บอกว่าใครคีย์ใบนั้น คลิกที่แถวไหนก็ได้เพื่อเปิดรายละเอียด (แถวบนสุดเป็นตัวอย่างสาธิต หายเองตอนปิดคู่มือ)',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#quickStatusFilters', 'กรองสถานะเร็ว',
       'กดชิป "รออนุมัติ" เพื่อดูเฉพาะใบที่รอตรวจในคลิกเดียว ตัวเลขบนเมนู "ประวัติการรับเข้า" คือจำนวนใบที่รอคุณตรวจอยู่',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — เปิดกล่องเทียบใบบิล',
       'กดไอคอนนี้ หรือคลิกที่แถว จะเปิดกล่องสำหรับตรวจใบรับเข้าเทียบกับใบบิล ขั้นถัดไปคู่มือจะเปิดกล่องตัวอย่างให้ดูว่ามีอะไรบ้าง',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#rcStage', 'ซ้าย: ไฟล์ใบบิล',
       'ไฟล์ที่ผู้บันทึกแนบมา แสดงค้างไว้ตลอดระหว่างตรวจ แถบด้านบน: ลูกศรซ้าย/ขวา = สลับไฟล์ · แว่นขยาย = ซูม 100–300% (ภาพใหญ่เลื่อนดูได้) · ลูกศรวน = หมุนทีละ 90° สำหรับรูปที่ถ่ายตะแคง · ลูกศรสี่มุม = ดูเต็มจอ · ลูกศรออก = เปิดแท็บใหม่ (ใบบิลในกล่องนี้เป็นภาพตัวอย่าง)',
       'right', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#docAttach', 'รายการไฟล์ใบบิล',
       'คลิกชื่อไฟล์เพื่อแสดงในช่องด้านบน ไอคอนลูกศรลง = ดาวน์โหลด ไอคอนถังขยะ = ลบ (เฉพาะคนที่แนบเองหรือผู้ดูแลระบบ) "+ แนบ" เพิ่มไฟล์ได้ถึง 10 ไฟล์ ใบที่ไม่มีบิลแนบมา ไม่อนุมัติพร้อมขอให้แนบบิลได้',
       'right', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#docItems', 'ขวา: ไล่ตรวจทีละบรรทัด',
       'เทียบ สินค้า จำนวน และราคา กับบิลทางซ้าย บรรทัดไหนตรงแล้วให้คลิกแถว (หรือกด Space) แถวจะเป็นสีเขียวมีเครื่องหมายถูก แล้วระบบเลื่อนไปแถวถัดไปที่ยังไม่ได้ตรวจให้ — กด Space ต่อกันไปได้เลย ตัวสีส้มใต้ชื่อสินค้าคือหมายเหตุของบรรทัดนั้น หัวตารางและแถวรวมเงินติดขอบไว้ ใบที่รายการเยอะเลื่อนเฉพาะในตาราง',
       'left', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#rcCheckCount', 'ตรวจไปแล้วกี่บรรทัด',
       'นับให้ว่าติ๊กไปแล้วกี่จากทั้งหมด ครบแล้วขึ้นป้าย "ครบทุกรายการ" ติ๊ก "ซ่อนที่ตรวจแล้ว" ทางขวาเพื่อเหลือแต่บรรทัดที่ยังไม่ได้ดู การติ๊กเป็นตัวช่วยจำระหว่างตรวจ ไม่ได้บันทึก',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '#docActions', 'ตัดสินใบนี้',
       'อนุมัติ = สินค้าเข้าคลังทันที · ไม่อนุมัติ = ต้องระบุว่าบรรทัดไหนไม่ตรงบิล สต๊อกไม่เปลี่ยน ผู้บันทึกได้รับแจ้งพร้อมเหตุผล ปุ่มอนุมัติขึ้นเฉพาะคนในรายชื่อผู้อนุมัติใบรับเข้า ใบที่คุณบันทึกเองปุ่มจะจาง (อนุมัติใบตัวเองไม่ได้) นอกจากนี้มี พิมพ์เอกสาร · ยกเลิกใบ · ส่งอีเมลซ้ำ',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 9, '.rowact[data-row-act="approve"]', 'อนุมัติจากตารางก็ได้',
       'ไอคอนถูก/กากบาทในแถว ทำงานเหมือนปุ่มในกล่อง เหมาะกับใบที่ตรวจบิลมาแล้ว ขึ้นเฉพาะคนในรายชื่อผู้อนุมัติใบรับเข้า (เมนู "ผู้อนุมัติเอกสาร")',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 10, '.rowact[href^="print_doc.php"]', 'ไอคอนเครื่องพิมพ์',
       'เปิดหน้ากระดาษในแท็บใหม่ ตัดเมนูและปุ่มออกหมด เหลือแต่ตัวเอกสาร กดแล้วสั่งพิมพ์ได้เลย',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 11, '.rowmenu-demo [data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิกใบ',
       'ใบที่ยังรออนุมัติ ยกเลิกได้เลย (สต๊อกยังไม่เคยเพิ่ม) ใบที่รับของแล้ว ยกเลิกจะดึงยอดออกจากสต๊อก ถ้าของถูกเบิกออกไปแล้วจะยกเลิกไม่ได้ ต้องระบุเหตุผลทุกครั้ง',
       'left', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 12, '#fpToggle', 'ตัวกรอง',
       'ช่วงวันที่ สถานะ และคำค้น อยู่ในแถบที่เปิดจากปุ่มนี้ ตัวเลขบนปุ่มคือจำนวนตัวกรองที่ตั้งอยู่ ตารางกับไฟล์ที่ส่งออกใช้เงื่อนไขชุดเดียวกัน',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 13, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
       'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัดพร้อมราคาต่อหน่วย ใช้ตอบคำถามแบบ "เดือนนี้รับเสื้อไซซ์ L กี่ครั้ง ครั้งละเท่าไร"',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_history';


-- ------------------------------------------------------------
--  3) รายการใบเบิก — แทรก 3 ขั้นในกล่องรายละเอียด ต่อจากขั้นไอคอนรูปตา
--     ข้ามถ้าเคยแทรกแล้ว (มีขั้น #docItems อยู่แล้ว)
-- ------------------------------------------------------------
SELECT @rq := `guide_id` FROM `tb_guide` WHERE `page_key` = 'request_list';
SELECT @eye := `step_order` FROM `tb_guide_step`
 WHERE `guide_id` = @rq AND `target_selector` = '.rowact[data-row-act="open"]' LIMIT 1;
SELECT @done := COUNT(*) FROM `tb_guide_step` WHERE `guide_id` = @rq AND `target_selector` = '#docItems';

UPDATE `tb_guide_step` SET `step_order` = `step_order` + 3
 WHERE `guide_id` = @rq AND `step_order` > @eye AND @done = 0 AND @eye IS NOT NULL;

UPDATE `tb_guide_step`
   SET `description` = 'กดไอคอนนี้เพื่อเปิดกล่องรายละเอียดเต็มของใบ ขั้นถัดไปคู่มือจะเปิดกล่องตัวอย่างให้ดูว่ามีอะไรบ้าง'
 WHERE `guide_id` = @rq AND `target_selector` = '.rowact[data-row-act="open"]' AND @done = 0;

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT @rq, @eye + 1, '#docMeta', 'หัวเอกสาร',
       'วันที่ บริษัท หน่วยงาน ผู้ขอเบิก และหมายเหตุของใบ ช่อง "ผู้อนุมัติ" ของใบที่ยังรออยู่ บอกรายชื่อคนที่อนุมัติใบนี้ได้ ใบจากระบบสวัสดิการ ผู้ขอเบิกคือคนที่กดเบิกในระบบสวัสดิการ',
       'left', 'start', NOW()
FROM DUAL WHERE @done = 0 AND @eye IS NOT NULL;
INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT @rq, @eye + 2, '#docItems', 'รายการที่ขอเบิก',
       'สินค้าและจำนวนที่ขอ รายการที่มีป้าย "ต้องคืน" คือของที่ต้องส่งคืนเมื่อพ้นสภาพ ใบเบิกยังไม่ตัดสต๊อก สต๊อกถูกตัดตอนคลังทำใบจ่าย',
       'left', 'start', NOW()
FROM DUAL WHERE @done = 0 AND @eye IS NOT NULL;
INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT @rq, @eye + 3, '#docActions', 'ปุ่มทำงานตามสถานะ',
       'ปุ่มเปลี่ยนตามสถานะของใบและสิทธิ์ของคุณ เช่น อนุมัติ / ไม่อนุมัติ (ผู้อนุมัติใบเบิก) · แก้ไข / ยกเลิก (ผู้ขอ ขณะรออนุมัติ) · พิมพ์เอกสาร · ส่งอีเมลซ้ำ (ผู้ดูแลระบบ)',
       'top', 'end', NOW()
FROM DUAL WHERE @done = 0 AND @eye IS NOT NULL;


-- ------------------------------------------------------------
--  ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT g.`page_key`, COUNT(s.`step_id`) AS `steps`
FROM `tb_guide` g LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
WHERE g.`page_key` IN ('receive_history', 'request_list', 'receive_detail')
GROUP BY g.`page_key`;
