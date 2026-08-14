-- ============================================================
--  023_guide_icons_and_gaps.sql
--  ชี้ไอคอนในคอลัมน์จัดการทีละอัน และเติมส่วนที่คู่มืออธิบายไม่ครบ
--
--  ---------------------------------------------------------------
--  1. คอลัมน์จัดการ: ชี้ทีละไอคอน แทนที่จะบรรยายรวม
--  ---------------------------------------------------------------
--  ของเดิมชี้ที่ #grid แล้วเขียนบรรยายว่า "รูปตาคือดู รูปเครื่องพิมพ์คือพิมพ์"
--  ซึ่งผู้ใช้ต้องอ่านแล้วไปไล่จับคู่กับไอคอนบนจอเอง
--
--  ตอนนี้ชี้ที่ตัวไอคอนโดยตรงด้วย [data-row-act=...] ทีละขั้น
--  ระบบจะใส่แถวตัวอย่างไว้บนสุดระหว่างเปิดคู่มือ (ดู window.GuideDemo
--  ใน assets/js/doc-list.js) เพื่อให้ไอคอนขึ้นครบทุกอันเสมอ
--  ไม่ว่าตอนนั้นในระบบจะมีเอกสารสถานะอะไรอยู่บ้าง
--
--  จำเป็นต้องมีแถวตัวอย่าง เพราะไอคอนที่โผล่ขึ้นกับสถานะของเอกสารในแถวนั้น
--  ใบที่ยกเลิกแล้วมีสองไอคอน ส่วนใบที่รอเซ็นรับมีเจ็ด ถ้าไปชี้แถวจริง
--  ที่บังเอิญอยู่บนสุด คู่มืออาจข้ามไอคอนที่กำลังจะอธิบายไปเลย
--
--  ---------------------------------------------------------------
--  2. ภาพรวมระบบ: ผู้ใช้ทั่วไปเห็นไม่ครบ
--  ---------------------------------------------------------------
--  สามขั้นที่พูดถึงกราฟมีเฉพาะสิทธิ์ที่เห็นภาพรวมคลัง คนทั่วไปจึงเหลือ 4 ขั้น
--  แล้วกระโดดจาก "งานที่รอคุณ" ไปที่ไอคอนบนแถบบนเลย ไม่เคยพูดถึงไทล์ทางลัด
--  ซึ่งเป็นสิ่งที่คนกลุ่มนั้นใช้บ่อยที่สุด เพิ่มขั้นทางลัดที่ทุกสิทธิ์เห็นเหมือนกัน
--
--  ---------------------------------------------------------------
--  3. ค่าใช้จ่ายรายเดือน: อธิบายแค่ 3 ใน 8 ส่วนของหน้า
--  ---------------------------------------------------------------
--  หน้านั้นมีการ์ดสรุปสามใบ ตารางห้าชุด และแผงรายละเอียดที่กดเปิดได้
--  แต่คู่มือเดิมพูดถึงแค่ช่องเลือกเดือน ตารางบริษัท และปุ่มพิมพ์
--  ส่วนที่เหลือคือส่วนที่บัญชีใช้จริง กลับไม่มีใครอธิบายว่าต่างกันอย่างไร
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/023_guide_icons_and_gaps.sql
--
--  ย้อนกลับ:
--      รัน sql/022_guide_rebuild.sql ใหม่ จะได้คู่มือชุดก่อนหน้าไฟล์นี้กลับมา
-- ============================================================

SET NAMES utf8;


-- ============================================================
--  ประวัติการรับเข้า — ชี้ไอคอนทีละอัน
-- ============================================================
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'คอลัมน์มูลค่ารวม',
 'บอกว่าใบนั้นเป็นเงินเท่าไร คิดจากราคาที่กรอกไว้ตอนรับเข้าจริง ไม่ใช่ราคาปัจจุบันของสินค้า ใบที่ขึ้นขีดกลางคือใบที่ยังไม่ได้กรอกราคา (แถวบนสุดตอนนี้เป็นตัวอย่างสำหรับสาธิต จะหายไปเองตอนปิดคู่มือ)',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — ดูรายละเอียด',
 'เปิดกล่องที่แสดงรายการสินค้าทั้งใบ พร้อมราคาต่อหน่วยและยอดรวมท้ายตาราง ใบที่กรอกราคาไม่ครบจะบอกไว้ตรงนั้นว่าขาดกี่บรรทัด',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '.rowact[href^="print_doc.php"]', 'ไอคอนเครื่องพิมพ์',
 'เปิดหน้ากระดาษในแท็บใหม่ ตัดเมนูและปุ่มออกหมด เหลือแต่ตัวเอกสารพร้อมโลโก้บริษัทของหน่วยงานนั้น กดแล้วสั่งพิมพ์ได้เลย',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '.rowact[data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิกใบ',
 'ใบรับเข้าแก้ตัวเลขทีหลังไม่ได้ บันทึกผิดต้องยกเลิกทั้งใบแล้วคีย์ใหม่ การยกเลิกจะดึงยอดที่รับเข้าไปออกจากสต๊อก ถ้าของถูกเบิกออกไปแล้วจะยกเลิกไม่ได้ และต้องระบุเหตุผลทุกครั้ง',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัดพร้อมราคาต่อหน่วย ใช้ตอบคำถามแบบ "เดือนนี้รับผ้าพันแผลกี่ครั้ง ครั้งละราคาเท่าไร" ซึ่งไฟล์แบบแรกกลืนสองราคารวมกันจนแยกไม่ออก',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#gridSearch', 'ค้นและกรอง',
 'ค้นจากเลขที่เอกสารหรือหมายเหตุได้ ด้านล่างยังกรองตามช่วงวันที่และสถานะได้อีก ทั้งตารางและไฟล์ที่ส่งออกใช้เงื่อนไขชุดเดียวกัน สิ่งที่เห็นบนจอกับในไฟล์จึงตรงกันเสมอ (บนจอแคบ ตัวกรองจะพับอยู่หลังปุ่มบนแถบเครื่องมือ)',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';


-- ============================================================
--  รายการใบเบิก — ชี้ไอคอนทีละอัน
-- ============================================================
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'สถานะบอกว่าใบอยู่ตรงไหน',
 'รออนุมัติ คือรอผู้อนุมัติตัดสิน · อนุมัติแล้ว คือรอคลังจ่ายของ · จ่ายแล้ว คือคลังจ่ายครบทุกบรรทัดแล้ว ไอคอนท้ายแถวเปลี่ยนตามสถานะ ใบที่ไม่มีอะไรให้ทำจะเหลือแค่ดูกับพิมพ์ (แถวบนสุดตอนนี้เป็นตัวอย่างสำหรับสาธิต จะหายไปเองตอนปิดคู่มือ)',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — ดูรายละเอียด',
 'เปิดดูรายการสินค้าทั้งใบก่อนตัดสินใจ ควรกดทุกครั้งก่อนอนุมัติ เพราะการอนุมัติคือการยืนยันว่าของทั้งใบสมเหตุสมผล ไม่ใช่แค่ยืนยันว่ามีใบเข้ามา จุดสีข้างไอคอนนี้แปลว่าใบนั้นมีงานรออยู่ที่คุณ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '.rowact[data-row-act="approve"]', 'ไอคอนถูก — อนุมัติ',
 'อนุมัติแล้วใบจะไปรอที่คลังเพื่อทำใบจ่าย ยังไม่ตัดสต๊อกตอนนี้ ระบบจะแจ้งผู้ขอเบิกให้เองทั้งกระดิ่งและอีเมล ผู้อนุมัติอนุมัติใบของตัวเองไม่ได้ ไอคอนนี้จะไม่ขึ้นในใบของคุณเอง',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '.rowact[data-row-act="reject"]', 'ไอคอนกากบาท — ไม่อนุมัติ',
 'ต้องระบุเหตุผลเสมอ เพราะเหตุผลนั้นถูกส่งไปให้ผู้ขอเบิกอ่าน เขาจะได้รู้ว่าต้องแก้อะไรก่อนทำใบใหม่ ใบที่ไม่อนุมัติแล้วแก้กลับไม่ได้ ต้องทำใบใหม่',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '.rowact[data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิก',
 'ผู้ขอเบิกยกเลิกใบของตัวเองที่ยังไม่ถูกตัดสินได้ ส่วนผู้ดูแลระบบยกเลิกใบที่อนุมัติไปแล้วได้ด้วย ใช้ตอนที่ไม่ต้องการของแล้ว จะได้ไม่ค้างอยู่ในคิวของคลัง',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '.rowact[data-row-act="mail"]', 'ไอคอนซองจดหมาย — ส่งอีเมลซ้ำ',
 'ส่งอีเมลแจ้งเรื่องตามสถานะปัจจุบันของใบนั้นอีกครั้ง ใช้ตอนที่ปลายทางหาเมลฉบับเดิมไม่เจอหรือลบไปแล้ว เห็นเฉพาะผู้ดูแลระบบ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัด เห็นว่าแต่ละใบขอของอะไรจำนวนเท่าไร เหมาะกับตอนสรุปว่าเดือนนี้ฝ่ายไหนเบิกอะไรไปบ้าง',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='request_list';


-- ============================================================
--  ประวัติการจ่ายออก — ชี้ไอคอนทีละอัน
-- ============================================================
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ใบที่ยังไม่ถูกเซ็นรับ',
 'สถานะ "จ่ายแล้ว" คือของออกจากคลังไปแล้วแต่ผู้เบิกยังไม่ได้เซ็นรับ ส่วน "รับของแล้ว" คือปิดเรื่องเรียบร้อย ใบที่ค้างสถานะแรกนาน ๆ คือใบที่ต้องตามเรื่อง (แถวบนสุดตอนนี้เป็นตัวอย่างสำหรับสาธิต จะหายไปเองตอนปิดคู่มือ)',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '.rowact[data-row-act="open"]', 'ไอคอนรูปตา — ดูรายละเอียด',
 'เปิดดูรายการที่จ่ายไปทั้งใบ พร้อมชื่อผู้รับและเวลาที่เซ็นรับถ้าเซ็นแล้ว',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '.rowact[href^="print_doc.php"]', 'ไอคอนเครื่องพิมพ์',
 'ใบที่เซ็นรับแล้วจะมีภาพลายเซ็นติดไปบนกระดาษด้วย ใช้เป็นหลักฐานการส่งมอบได้เลย',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '.rowact[data-row-act="copylink"]', 'ไอคอนโซ่ — คัดลอกลิงก์เซ็นรับ',
 'คัดลอกลิงก์สำหรับให้ผู้เบิกเซ็นรับ เปิดได้โดยไม่ต้องล็อกอิน ส่งทางไลน์ได้เลยตอนที่เขาหาอีเมลไม่เจอ ลิงก์ใช้ได้ 7 วันและใครถือก็เซ็นรับแทนได้ จึงส่งให้เฉพาะผู้เบิกเท่านั้น กดส่งเมลซ้ำเมื่อไร ลิงก์เดิมจะถูกยกเลิกทันที',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '.rowact[data-row-act="openlink"]', 'ไอคอนลูกศร — เปิดหน้าเซ็นรับ',
 'เปิดหน้าเดียวกับที่ผู้เบิกจะเห็น ใช้ตรวจว่าเขาจะเจออะไรก่อนส่งลิงก์ให้ หรือใช้เปิดให้เขาเซ็นตรงหน้าเคาน์เตอร์คลังก็ได้',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '.rowact[data-row-act="ack"]', 'ไอคอนปากกา — เซ็นรับของ',
 'เปิดผืนให้เซ็นด้วยนิ้วหรือเมาส์ ลายเซ็นจะถูกเก็บไว้กับเอกสารและพิมพ์ติดไปบนกระดาษ ผู้ดูแลระบบเซ็นแทนได้เมื่อผู้เบิกมารับที่คลังโดยตรง เซ็นแล้วใบจะปิดและแก้ไม่ได้อีก',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '.rowact[data-row-act="cancel"]', 'ไอคอนวงกลมขีด — ยกเลิกใบจ่าย',
 'คืนของกลับเข้าสต๊อกและคืนยอดค้างจ่ายให้ใบเบิกต้นทาง ใบที่เซ็นรับไปแล้วยกเลิกไม่ได้ เพราะของอยู่ที่ผู้เบิกแล้ว ถ้าจะเอาของคืนต้องทำใบรับเข้าใหม่',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '.rowact[data-row-act="mail"]', 'ไอคอนซองจดหมาย — ส่งอีเมลซ้ำ',
 'ส่งอีเมลพร้อมลิงก์เซ็นรับให้ผู้เบิกอีกครั้ง ระบบเลือกเนื้อหาตามสถานะปัจจุบันของใบให้เอง จึงไม่มีทางส่งเมล "รอเซ็นรับ" ให้ใบที่เซ็นไปแล้ว',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 9, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ได้ไฟล์ที่แตกเป็นรายบรรทัดพร้อมจำนวนและมูลค่า ใช้สรุปว่าเดือนนี้จ่ายของอะไรออกไปให้ใครบ้าง',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';


-- ============================================================
--  ภาพรวมระบบ — เพิ่มขั้นที่ทุกสิทธิ์เห็นเหมือนกัน
-- ============================================================
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` + 1
WHERE g.`page_key` = 'dashboard' AND s.`step_order` >= 2;

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#bnLinks', 'ทางลัดไปหน้าที่ใช้บ่อย',
 'กดจากตรงนี้ได้เลยโดยไม่ต้องไปหาในเมนูซ้าย รายการที่ขึ้นเปลี่ยนตามสิทธิ์ของคุณ จึงมีเฉพาะหน้าที่คุณเข้าได้จริง ไม่มีลิงก์ที่กดแล้วเจอ "ไม่มีสิทธิ์เข้าถึง"',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';


-- ============================================================
--  ค่าใช้จ่ายรายเดือน — อธิบายให้ครบทุกส่วนของหน้า
-- ============================================================
DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#rcMonth', 'เลือกเดือน',
 'ตัวเลขทั้งหน้าเปลี่ยนตามเดือนที่เลือก ทุกยอดคิดจากราคาที่กรอกไว้ตอนรับเข้าจริง ใบที่ไม่ได้กรอกราคาจะถูกนับเป็นศูนย์ ทำให้ยอดต่ำกว่าความจริงโดยไม่มีอะไรฟ้อง จึงควรไล่กรอกราคาให้ครบก่อนใช้หน้านี้สรุปส่งบัญชี',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#rcTotals', 'สามตัวเลขที่ต้องแยกให้ออก',
 'จ่ายออก คือมูลค่าของที่เบิกออกไปใช้ในเดือนนี้ — เป็นค่าใช้จ่ายจริงของหน่วยงาน · รับเข้า คือเงินที่จ่ายซื้อของเข้าคลังในเดือนนี้ — เป็นเงินที่ออกจากบริษัท · มูลค่าคงคลัง คือของที่ยังเหลืออยู่ ณ สิ้นเดือน สองตัวแรกไม่จำเป็นต้องเท่ากัน เพราะของที่ซื้อเดือนนี้อาจถูกเบิกเดือนหน้า',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#tblComp', 'แยกตามบริษัท',
 'ยอดค่าใช้จ่ายของแต่ละบริษัท คิดจากบริษัทที่หน่วยงานผู้เบิกสังกัดอยู่ กดที่แถวไหนก็ได้เพื่อเปิดแผงรายละเอียดว่ายอดนั้นมาจากเอกสารใบไหนบ้าง เป็นคำถามที่บัญชีถามเสมอเวลาตัวเลขไม่ตรงกับที่คาดไว้',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#tblDept', 'แยกตามหน่วยงานที่เบิก',
 'ละเอียดกว่าระดับบริษัทหนึ่งชั้น ใช้ตอบว่าฝ่ายไหนใช้ของมากที่สุดในเดือนนั้น กดที่แถวเพื่อดูว่าเป็นของอะไรบ้าง',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#tblCat', 'แยกตามหมวดหมู่สินค้า',
 'มองจากมุมของ "ของอะไร" แทนที่จะเป็น "ใครเบิก" ใช้ตอนวางแผนจัดซื้อ ว่าหมวดไหนกินงบมากที่สุดและควรต่อรองราคากับผู้ขายก่อน',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#tblRecv', 'ของที่รับเข้ามาในเดือนนี้',
 'คนละเรื่องกับสามตารางบน — ตารางนี้คือเงินที่จ่ายซื้อของเข้าคลัง ไม่ใช่ของที่ถูกเบิกไปใช้ ราคาต่อหน่วยที่เห็นคือราคาที่คีย์ไว้ตอนรับเข้าจริง ของตัวเดียวกันที่รับหลายครั้งคนละราคาจะแยกบรรทัดให้เห็น',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#tblDetail', 'ลงลึกถึงรายตัวสินค้า',
 'ตารางล่างสุดแตกทุกอย่างออกเป็นรายสินค้าคูณหน่วยงาน ใช้ตอนที่ต้องตอบคำถามเจาะจงแบบ "ฝ่ายบัญชีเบิกกระดาษ A4 ไปกี่รีมเดือนนี้ เป็นเงินเท่าไร" ซึ่งตารางสรุปด้านบนตอบไม่ได้',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '#btnExport', 'ส่งออก Excel',
 'ได้ทุกตารางในหน้านี้เป็นไฟล์เดียว แยกเป็นชีตละตาราง เอาไปทำ pivot หรือรวมกับข้อมูลอื่นต่อได้ ตัวเลขในไฟล์เป็นตัวเลขจริง ไม่ใช่ข้อความ จึงคำนวณต่อได้ทันที',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 9, '#btnPrint', 'พิมพ์ส่งบัญชี',
 'ได้หน้ากระดาษที่ตัดเมนูและปุ่มออกหมดแล้ว เหลือแต่ตัวเลขที่ส่งต่อได้เลย เหมาะกับตอนที่ต้องแนบเป็นเอกสารประกอบ ไม่ใช่ไฟล์ที่เอาไปคำนวณต่อ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';
