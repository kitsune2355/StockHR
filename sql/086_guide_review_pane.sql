-- ============================================================
--  086_guide_review_pane.sql
--  คู่มือหน้ารายการใบเบิก: แผงรายละเอียดด้านขวา + ปุ่มตัวกรอง
--
--  แทรกเป็นขั้น 2-5 ของ request_list และ new_staff_list
--  แผงมีเฉพาะผู้อนุมัติ/ผู้ดูแลระบบบนจอกว้าง คนอื่นคู่มือจะข้ามขั้นเหล่านี้เอง
--  (buildSteps ใน assets/js/app-guide.js ข้ามเป้าที่มองไม่เห็น)
--  ระหว่างเปิดคู่มือ doc-list.js เปิดใบสาธิตไว้ในแผงให้ (reviewDemo) กดใช้งานจริงไม่ได้
--
--  รันซ้ำได้: ลบขั้น #rv / #fpToggle ของสองหน้านี้ทิ้ง เลื่อนขั้นที่เหลือกลับ แล้วใส่ใหม่
--
--  วิธีรัน (ต้องส่งเป็นไฟล์ ไม่ใช่ mysql -e ไม่งั้นภาษาไทยจะเพี้ยน):
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/086_guide_review_pane.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/086_guide_review_pane_rollback.sql
-- ============================================================

SET NAMES utf8;

/* ---------- รันซ้ำ: เอาของรอบก่อนออก แล้วเลื่อนขั้นเดิมกลับที่ ----------
   ตารางนับต้องอยู่ใน derived table — MySQL ห้าม UPDATE ตารางที่ subquery อ่านอยู่ตรง ๆ (1093) */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
INNER JOIN (
    SELECT s2.`guide_id`, COUNT(*) AS `c`
    FROM `tb_guide_step` s2
    INNER JOIN `tb_guide` g2 ON g2.`guide_id` = s2.`guide_id`
    WHERE g2.`page_key` IN ('request_list', 'new_staff_list')
      AND (s2.`target_selector` LIKE '#rv%' OR s2.`target_selector` = '#fpToggle')
    GROUP BY s2.`guide_id`
) x ON x.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - x.`c`
WHERE s.`step_order` >= 6
  AND s.`target_selector` NOT LIKE '#rv%' AND s.`target_selector` <> '#fpToggle';

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('request_list', 'new_staff_list')
  AND (s.`target_selector` LIKE '#rv%' OR s.`target_selector` = '#fpToggle');

/* ---------- เปิดช่องขั้น 2-5 (4 ขั้น) ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` + 4
WHERE g.`page_key` IN ('request_list', 'new_staff_list') AND s.`step_order` >= 2;

/* ---------- ขั้นของแผง (ใช้ชุดเดียวกันทั้งสองหน้า) ---------- */
INSERT INTO `tb_guide_step`
    (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT g.`guide_id`, t.`step_order`, t.`sel`, t.`title`, t.`descr`, t.`side`, t.`align`, NOW()
FROM `tb_guide` g
INNER JOIN (
          SELECT 2 AS `step_order`, '#rvPane' AS `sel`,
                 'แผงรายละเอียด — ดูและอนุมัติในหน้าเดียว' AS `title`,
                 'คลิกแถวไหนก็ได้ในตาราง ใบนั้นจะเปิดในแผงนี้ทันที เห็นหน่วยงาน ผู้ขอเบิก และรายการที่ขอ โดยไม่ต้องเปิดกล่องซ้อนกล่อง' AS `descr`,
                 'left' AS `side`, 'start' AS `align`
    UNION SELECT 3, '#rvDecide', 'อนุมัติ / ไม่อนุมัติ',
                 'ปุ่มนี้ขึ้นเฉพาะใบที่รออนุมัติและไม่ใช่ใบของคุณเอง กดอนุมัติแล้วบันทึกทันที ส่วนไม่อนุมัติต้องมีเหตุผล ถ้าเขียนไว้ในช่องหมายเหตุแล้วใช้ข้อความนั้นเลย เสร็จแล้วระบบพาไปใบถัดไปที่รออนุมัติให้เอง',
                 'top', 'center'
    UNION SELECT 4, '#rvFull', 'รายละเอียดเต็ม และพิมพ์',
                 'ไอคอนลูกศรขยายเปิดกล่องรายละเอียดเต็มสำหรับงานอื่น เช่นดูประวัติ ส่งกลับแก้ไข หรือยกเลิกใบ ส่วนไอคอนเครื่องพิมพ์ข้าง ๆ เปิดใบพิมพ์ในแท็บใหม่ และ × ปิดแผงนี้',
                 'bottom', 'end'
    UNION SELECT 5, '#fpToggle', 'ตัวกรอง',
                 'หน้านี้พับตัวกรองเป็นปุ่มเพื่อเหลือที่ให้แผงรายละเอียด กดเพื่อเปิดตัวกรองจากด้านข้าง',
                 'bottom', 'end'
) t
WHERE g.`page_key` IN ('request_list', 'new_staff_list');

SELECT g.`page_key`, s.`step_order`, s.`target_selector`, s.`title`
FROM `tb_guide_step` s INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` IN ('request_list', 'new_staff_list') AND s.`step_order` <= 6
ORDER BY g.`page_key`, s.`step_order`;
