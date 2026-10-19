-- ============================================================
--  080_new_staff_kit_department.sql
--  ชุดของพนักงานใหม่แยกตามแผนก
--
--  แต่ละแผนกให้ชุดฟอร์มพื้นฐานไม่เหมือนกัน (เช่นฝ่ายช่างได้ชุดช่าง + รองเท้าเซฟตี้)
--  จึงเพิ่ม department_id ให้ tb_new_staff_kit
--
--    department_id = 0   ชุดกลาง — ใช้กับแผนกที่ไม่ได้ตั้งชุดของตัวเอง (แถวเดิมทั้งหมดเป็นชุดกลาง)
--    department_id > 0   ชุดของแผนกนั้น — "แทน" ชุดกลางทั้งชุด ไม่ได้บวกเพิ่ม
--                        ลบรายการของแผนกจนว่าง = กลับไปใช้ชุดกลาง
--
--  ใช้ 0 แทน NULL เพราะ UNIQUE (department_id, product_id) ต้องกันสินค้าซ้ำในชุดกลางด้วย
--  (MySQL ยอมให้ NULL ซ้ำใน UNIQUE) ผลคือผูก FK ไป tb_department ไม่ได้
--  ลบแผนกแล้วแถวของแผนกนั้นค้างอยู่เฉย ๆ ไม่มีใครเรียกใช้ — ไม่กระทบใบเบิก
--
--  หมายเหตุท้ายฟอร์ม (tb_app_setting.new_staff_terms) ยังใช้ร่วมกันทุกแผนก
--
--  ลำดับ deploy: รันไฟล์นี้ "ก่อน" อัปโหลดโค้ด (โค้ดใหม่อ่านคอลัมน์ department_id)
--
--  วิธีรัน:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/080_new_staff_kit_department.sql
--  ย้อนกลับ:
--      mysql -u root -p1234 --default-character-set=utf8 stock_hr < sql/080_new_staff_kit_department_rollback.sql
-- ============================================================

SET NAMES utf8;

ALTER TABLE `tb_new_staff_kit`
  ADD COLUMN `department_id` INT(10) UNSIGNED NOT NULL DEFAULT 0
      COMMENT '0 = ชุดกลาง, >0 = ชุดของแผนกนั้น (แทนชุดกลาง)' AFTER `kit_id`;

-- fk_kit_product ต้องมี index ที่ขึ้นต้นด้วย product_id ก่อนจะลบ UNIQUE เดิมได้
ALTER TABLE `tb_new_staff_kit`
  ADD KEY `idx_kit_product` (`product_id`);

ALTER TABLE `tb_new_staff_kit`
  DROP KEY `uq_kit_product`,
  ADD UNIQUE KEY `uq_kit_dept_product` (`department_id`, `product_id`),
  DROP KEY `idx_kit_active_sort`,
  ADD KEY `idx_kit_dept_active_sort` (`department_id`, `is_active`, `sort_order`);
