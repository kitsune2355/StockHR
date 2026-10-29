-- ============================================================
--  085_doc_form_code_snapshot.sql
--  เก็บรหัสแบบฟอร์มไว้กับเอกสารตอนสร้าง
--
--  เดิม print_doc.php อ่านรหัสแบบฟอร์มสด ๆ ทุกครั้งที่พิมพ์
--  พอแก้รหัส (เช่น Rev 01 -> Rev 02) ใบเก่าที่พิมพ์ซ้ำก็ได้รหัสใหม่ไปด้วย
--  ซึ่งผิดหลักเอกสารควบคุม — ใบที่ออกไปแล้วต้องอ้างแบบฟอร์มรุ่นที่ใช้ตอนออกใบ
--
--  tb_doc_header.form_code         = tb_company.form_code ณ ตอนสร้างใบ
--  tb_doc_header.uniform_form_code = รหัสเอกสารรับชุดเครื่องแบบ (tb_app_setting uniform_receipt_form_code
--                                    ไม่ตั้ง = 019-HRM : Rev.01 :08/04/2569) ณ ตอนสร้างใบ
--  บันทึกตอน doc_insert / ตอนแก้ใบเบิกที่ยังรออนุมัติ (request_update)
--
--  เอกสารที่มีอยู่แล้ว: เติมด้วยรหัสปัจจุบัน (ข้อมูลรุ่นเก่าไม่เคยถูกเก็บ — ค่าที่ดีที่สุดที่มี)
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/085_doc_form_code_snapshot.sql
--  ย้อนกลับ: sql/085_doc_form_code_snapshot_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_doc_header`
    ADD COLUMN `form_code` varchar(120) NOT NULL DEFAULT ''
        COMMENT 'รหัสแบบฟอร์มของบริษัท ณ ตอนสร้างเอกสาร (snapshot จาก tb_company.form_code)'
        AFTER `note`,
    ADD COLUMN `uniform_form_code` varchar(120) NOT NULL DEFAULT ''
        COMMENT 'รหัสเอกสารรับชุดเครื่องแบบ ณ ตอนสร้างเอกสาร (ใช้เมื่อพิมพ์เป็นเอกสารรับชุด)'
        AFTER `form_code`;

UPDATE `tb_doc_header` h
INNER JOIN `tb_department` d ON d.`department_id` = h.`department_id`
INNER JOIN `tb_company`    c ON c.`company_id`    = d.`company_id`
SET h.`form_code` = c.`form_code`;

UPDATE `tb_doc_header`
SET `uniform_form_code` = COALESCE(
    (SELECT TRIM(s.`setting_value`) FROM `tb_app_setting` s
      WHERE s.`setting_key` = 'uniform_receipt_form_code' AND TRIM(s.`setting_value`) <> ''),
    '019-HRM : Rev.01 :08/04/2569');
