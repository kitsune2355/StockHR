-- ============================================================
--  063_guide_request_openapprovelink_rollback.sql
--  ถอนขั้น "เปิดลิงก์อนุมัติ" ออกจากคู่มือหน้ารายการใบเบิก
--  และคืนข้อความขั้น "ส่งกลับแก้ไข" กลับเป็นของ sql/062
--
--  ลบขั้นก่อนแล้วค่อยเลื่อนลำดับกลับ ถ้าเลื่อนก่อนลบ ขั้นที่จะลบจะไปทับ
--  เลขของขั้นอื่นแล้วได้ลำดับซ้ำ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/063_guide_request_openapprovelink_rollback.sql
-- ============================================================

SET NAMES utf8;

DELETE s FROM `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="openapprovelink"]';

UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`step_order` = s.`step_order` - 1
WHERE g.`page_key` = 'request_list'
  AND s.`step_order` >= 6;

/* ---------- คืนข้อความขั้น "ส่งกลับแก้ไข" (ข้อความของ sql/062) ---------- */
UPDATE `tb_guide_step` s
INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
SET s.`description` = 'ใช้ตอนใบผ่านอนุมัติแล้วแต่ผู้ทำเบิกแจ้งว่าเบิกของผิด กดถอยใบกลับไปให้ผู้ทำเบิกแก้ สถานะกลับเป็น "รออนุมัติ" ต้องระบุสิ่งที่ต้องแก้เพื่อให้ผู้ทำเบิกรู้ ส่งกลับใบของตัวเองไม่ได้ และถ้าคลังจ่ายของใบนี้ไปบางส่วนแล้วจะส่งกลับไม่ได้ ต้องยกเลิกใบจ่ายก่อน ไอคอนนี้ขึ้นเฉพาะบนใบที่อนุมัติแล้ว จึงไม่โผล่บนแถวตัวอย่างนี้'
WHERE g.`page_key` = 'request_list'
  AND s.`target_selector` = '.rowact[data-row-act="sendback"]';
