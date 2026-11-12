-- ============================================================
--  092_guide_receive_detail.sql
--  คู่มือของกล่องรายละเอียดใบรับเข้า (กล่องเทียบใบบิล) — page_key = receive_detail
--
--  ไม่ใช่คู่มือของหน้า แต่เป็นของกล่อง popup ในหน้าประวัติการรับเข้า
--  กล่องนี้บังปุ่มคู่มือบนแถบบน จึงเปิดจากปุ่ม ? บนหัวกล่อง (#btnDocGuide)
--  และเปิดให้เองครั้งแรกที่แต่ละคนเปิดกล่อง (auto_start = 1, จำว่าดูแล้วแยกต่อคน)
--  ดู AppGuide.openKey ใน assets/js/app-guide.js และ detailGuide ใน doc-list.js
--
--  รันซ้ำได้ (ลบชุดเดิมของ receive_detail แล้วใส่ใหม่)
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/092_guide_receive_detail.sql
--  ย้อนกลับ:
--      DELETE FROM tb_guide WHERE page_key = 'receive_detail';
-- ============================================================

SET NAMES utf8;

DELETE FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('receive_detail', 'กล่องเทียบใบบิล (ประวัติการรับเข้า)',
        'วิธีตรวจใบรับเข้าเทียบกับใบบิล แล้วอนุมัติให้สินค้าเข้าคลัง', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'กล่องนี้ใช้ตรวจใบรับเข้าเทียบกับใบบิล',
       'ซ้ายคือไฟล์ใบบิลที่ผู้บันทึกแนบมา ขวาคือรายการที่เขาคีย์เข้าระบบ ผู้ตรวจไล่เทียบทีละบรรทัดว่า สินค้า จำนวน และราคา ตรงกับบิลไหม แล้วจึงกดอนุมัติ — สต๊อกเพิ่มตอนอนุมัติเท่านั้น (เปิดคู่มือนี้อีกครั้งได้จากปุ่ม ? มุมขวาบนของกล่อง)',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#rcStage', 'ช่องดูใบบิล',
       'แสดงไฟล์ใหญ่ค้างไว้ตลอดระหว่างตรวจ แถบด้านบน: ลูกศรซ้าย/ขวา = สลับไฟล์ · แว่นขยาย = ซูม 100–300% (ภาพใหญ่เลื่อนดูได้) · ลูกศรวน = หมุนทีละ 90° สำหรับรูปบิลที่ถ่ายตะแคง · ลูกศรสี่มุม = ดูเต็มจอ · ลูกศรออก = เปิดในแท็บใหม่ ไฟล์ PDF ใช้ตัวดู PDF ของเบราว์เซอร์',
       'right', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#docAttach', 'รายการไฟล์ใบบิล',
       'คลิกชื่อไฟล์เพื่อแสดงในช่องด้านบน (ไฟล์ที่แสดงอยู่มีแถบสีซ้าย) ไอคอนลูกศรลง = ดาวน์โหลด ไอคอนถังขยะ = ลบ (เฉพาะคนที่แนบเองหรือผู้ดูแลระบบ) "+ แนบ" เพิ่มไฟล์ได้ถึง 10 ไฟล์ ใบที่ไม่มีบิลแนบมา ไม่อนุมัติพร้อมขอให้แนบบิลได้',
       'right', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#docMeta', 'หัวเอกสาร',
       'วันที่เอกสาร ผู้บันทึก และหมายเหตุของใบ ช่อง "ผู้อนุมัติ" ของใบที่ยังรออยู่ บอกรายชื่อคนที่ตรวจใบนี้ได้ ถ้าขึ้นว่า "ยังไม่ได้ตั้งผู้อนุมัติใบรับเข้า" ให้ผู้ดูแลระบบเพิ่มที่เมนู "ผู้อนุมัติเอกสาร" แท็บใบรับเข้า',
       'left', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#docItems', 'ไล่ตรวจทีละบรรทัด',
       'คลิกแถว (หรือกด Space) เมื่อบรรทัดนั้นตรงกับบิล แถวจะเป็นสีเขียวมีเครื่องหมายถูก แล้วระบบเลื่อนไปแถวถัดไปที่ยังไม่ได้ตรวจให้ — กด Space ต่อกันไปได้เลย ลูกศรขึ้น/ลงเลื่อนแถว คลิกซ้ำเพื่อเอาเครื่องหมายออก ตัวสีส้มใต้ชื่อสินค้าคือหมายเหตุของบรรทัดนั้น หัวตารางและแถวรวมเงินติดขอบไว้ ใบที่รายการเยอะเลื่อนเฉพาะในตาราง',
       'left', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#rcCheckCount', 'ตรวจไปแล้วกี่บรรทัด',
       'นับให้ว่าติ๊กไปแล้วกี่จากทั้งหมด ครบแล้วขึ้นป้าย "ครบทุกรายการ" สีเขียว การติ๊กเป็นตัวช่วยจำระหว่างตรวจเท่านั้น ไม่ได้บันทึก ปิดกล่องแล้วเปิดใหม่จะเริ่มนับใหม่',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '.bv-hide-checked', 'ซ่อนที่ตรวจแล้ว',
       'ติ๊กเพื่อซ่อนบรรทัดที่ตรวจแล้ว เหลือแต่บรรทัดที่ยังไม่ได้ดู เหมาะกับใบที่มีหลายสิบรายการ',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '#docActions', 'ตัดสินใบนี้',
       'อนุมัติ = สินค้าเข้าคลังทันที · ไม่อนุมัติ = ต้องระบุว่าบรรทัดไหนไม่ตรงบิล สต๊อกไม่เปลี่ยน ผู้บันทึกได้รับแจ้งพร้อมเหตุผล ปุ่มอนุมัติขึ้นเฉพาะคนในรายชื่อผู้อนุมัติใบรับเข้า ใบที่คุณบันทึกเองปุ่มจะจาง (อนุมัติใบตัวเองไม่ได้) นอกจากนี้มี พิมพ์เอกสาร · ยกเลิกใบ · ส่งอีเมลซ้ำ (ส่งลิงก์อนุมัติชุดใหม่ให้ผู้ตรวจ)',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_detail';

SELECT g.`page_key`, COUNT(s.`step_id`) AS `steps`
FROM `tb_guide` g LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
WHERE g.`page_key` = 'receive_detail' GROUP BY g.`page_key`;
