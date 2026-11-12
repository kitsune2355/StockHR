-- ============================================================
--  090_user_all_depts.sql
--  "หน่วยงานที่ดูแล" เลือกได้ว่า "ทุกหน่วยงาน ทุกบริษัท"
--
--  สำหรับคนที่มีอำนาจดูแลทั้งองค์กร (เช่น HR กลาง) ไม่ต้องไล่ติ๊กหน่วยงานทีละตัว
--  และหน่วยงานที่เพิ่มใหม่ภายหลังก็ครอบคลุมเองโดยไม่ต้องกลับมาแก้
--
--  all_depts = 1  เห็นเอกสาร (ใบเบิก/ใบจ่าย) ของทุกหน่วยงานทุกบริษัท
--                 ไม่ใช้แถวใน tb_user_department (โค้ดลบทิ้งให้ตอนบันทึก)
--  all_depts = 0  ใช้รายการหน่วยงานใน tb_user_department ตามเดิม
--
--  ไม่เกี่ยวกับสิทธิ์อนุมัติ — ใครอนุมัติได้ตั้งที่เมนู "ผู้อนุมัติเอกสาร" (sql/088, 089)
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/090_user_all_depts.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/090_user_all_depts_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_user`
  ADD COLUMN `all_depts` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = ดูแลทุกหน่วยงานทุกบริษัท (ไม่ใช้ tb_user_department)'
    AFTER `dept_id`;

SELECT `username`, `role`, `all_depts` FROM `tb_user` ORDER BY `username`;
