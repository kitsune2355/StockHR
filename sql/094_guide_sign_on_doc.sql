-- ============================================================
--  094_guide_sign_on_doc.sql
--  หน้าประวัติการจ่ายออก: ใบที่ยังรอเซ็นรับ ซ่อนไอคอนพิมพ์ เหลือไอคอนปากกา
--  กดแล้วไปหน้าเอกสาร (print_doc.php) อ่านรายละเอียดก่อน แล้วกดปุ่มเซ็นเอง เซ็นแล้วไอคอนพิมพ์จึงขึ้น
--  ปรับข้อความคู่มือขั้นไอคอนปากกา และขั้นไอคอนเครื่องพิมพ์ให้ตรง
--
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/094_guide_sign_on_doc.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ไอคอนปากกา — เซ็นรับของ',
    s.`description` = 'ใบที่ยังไม่เซ็นรับจะมีไอคอนนี้แทนไอคอนพิมพ์ กดแล้วเปิดหน้าเอกสารใบจ่ายในแท็บใหม่ อ่านรายการและเงื่อนไขบนเอกสารให้ครบก่อน แล้วกดปุ่ม "เซ็นรับของ" บนแถบบน (หรือไอคอนปากกาในช่องผู้รับของ) แผงเซ็นจะกางออกใต้ช่องลงนาม กรอกชื่อผู้รับ แล้วเซ็นด้วยนิ้วหรือเมาส์ (ปากกาสีน้ำเงิน) กดยืนยันแล้วลายเซ็นลงช่อง "ผู้รับของ" บนเอกสารทันที พิมพ์ได้เลย และไอคอนพิมพ์ในตารางจะกลับมา ไอคอนนี้ขึ้นเฉพาะผู้ขอเบิกของใบนั้นและผู้ดูแลระบบ (เซ็นแทนได้)'
WHERE g.`page_key` = 'issue_history' AND s.`target_selector` = '.rowact[data-row-act="ack"]';

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ขึ้นเมื่อใบนั้นเซ็นรับแล้ว (หรือคุณไม่ใช่คนที่ต้องเซ็น) ใบที่เซ็นรับแล้วมีภาพลายเซ็นติดไปบนกระดาษด้วย ใช้เป็นหลักฐานการส่งมอบได้ ใบที่ยังรอเซ็นจะเห็นไอคอนปากกาแทน — เซ็นก่อนแล้วจึงพิมพ์'
WHERE g.`page_key` = 'issue_history' AND s.`target_selector` = '.rowact[href^="print_doc.php"]';

SELECT g.`page_key`, s.`step_order`, s.`title` FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'issue_history' AND s.`target_selector` IN ('.rowact[data-row-act="ack"]', '.rowact[href^="print_doc.php"]');
