-- ============================================================
--  094_guide_sign_on_doc.sql
--  ไอคอนปากกาในหน้าประวัติการจ่ายออก ไม่มีกล่องเซ็นแล้ว — พาไปเซ็นที่ sign.php
--  (แผงเซ็นซ่อนไว้ กางเมื่อกดปุ่ม "เซ็นรับของ" หรือไอคอนปากกาในช่องผู้รับของ)
--  ปรับข้อความคู่มือขั้นไอคอนปากกาให้ตรง
--
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/094_guide_sign_on_doc.sql
-- ============================================================

SET NAMES utf8;

UPDATE `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`title` = 'ไอคอนปากกา — เซ็นรับของ',
    s.`description` = 'เปิดหน้าเซ็นรับของในแท็บใหม่ (หน้าเดียวกับลิงก์เซ็นรับในอีเมล) ตรวจรายการที่จ่ายให้ครบ กรอกชื่อผู้รับ แล้วเซ็นด้วยนิ้วหรือเมาส์ กดยืนยันแล้วสถานะเป็น "รับของแล้ว" ตารางหน้านี้อัปเดตเอง และลายเซ็นไปขึ้นในช่องผู้รับของบนใบจ่ายที่พิมพ์ ไอคอนนี้ขึ้นเฉพาะผู้ขอเบิกของใบนั้นและผู้ดูแลระบบ (เซ็นแทนได้)'
WHERE s.`target_selector` = '.rowact[data-row-act="ack"]';

SELECT g.`page_key`, s.`step_order`, s.`title` FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE s.`target_selector` = '.rowact[data-row-act="ack"]';
