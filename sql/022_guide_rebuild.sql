-- ============================================================
--  022_guide_rebuild.sql
--  เขียนคู่มือการใช้งานใหม่ทั้งหมดให้ตรงกับระบบตอนนี้
--
--  ---------------------------------------------------------------
--  ทำไมต้องเขียนใหม่ทั้งชุด ไม่ใช่แก้ทีละขั้น
--  ---------------------------------------------------------------
--  คู่มือชุดเดิมเขียนไว้ตอนที่ระบบยังไม่มีของพวกนี้:
--    - คอลัมน์ "จัดการ" แบบไอคอนในหน้าประวัติเอกสารทั้งสามหน้า
--    - ลิงก์เซ็นรับของที่เปิดได้โดยไม่ต้องล็อกอิน
--    - ปุ่มส่งออกแบบรายบรรทัด ที่เห็นราคาต่อหน่วยของแต่ละครั้ง
--    - ราคาที่เติมให้อัตโนมัติจากทะเบียนสินค้าตอนรับเข้า
--    - กราฟบนหน้าภาพรวมที่เลือกช่วงและความละเอียดได้
--    - กระดิ่งที่จัดกลุ่มตามวัน โหลดเพิ่มตอนเลื่อน และเด้งนอกหน้าเว็บได้
--    - หน้าการแจ้งเตือนทั้งหมด
--    - การพับกลุ่มในหน้าสิทธิ์การใช้งาน
--
--  การไล่แก้ทีละขั้นจะได้คู่มือที่เป็นชั้นตะกอน — ประโยคเก่าที่ยังจริงอยู่
--  ปนกับประโยคใหม่ที่แทรกเข้าไป แล้วลำดับการเล่าจะไม่ตรงกับลำดับที่คนทำงานจริง
--  ล้างแล้วเขียนใหม่ทั้งชุดจบกว่า และตรวจได้ว่าครบทุกหน้าจริง
--
--  ---------------------------------------------------------------
--  กติกาในการเขียนขั้นตอน
--  ---------------------------------------------------------------
--  1. ทุกขั้นต้องชี้ไปที่ของที่มองเห็นได้จริงบนหน้านั้น
--     ยกเว้นขั้นเปิดเรื่องที่อธิบายกติกาของทั้งหน้า ซึ่งลอยกลางจอได้
--  2. เขียนสิ่งที่ผู้ใช้ต้อง "ตัดสินใจ" ไม่ใช่บรรยายว่าปุ่มนี้ชื่ออะไร
--     ปุ่มมีป้ายกำกับอยู่แล้ว สิ่งที่ป้ายบอกไม่ได้คือผลที่ตามมา
--  3. บอกกับดักที่ทำให้ข้อมูลเสียหายเสมอ เช่นเปลี่ยนหน่วยงานแล้วรายการหาย
--  4. หน้าที่เปิดมาแล้วว่างเปล่า จะมีชุดข้อมูลสาธิตขึ้นให้ระหว่างเปิดคู่มือ
--     (สินค้าของหน่วยงาน / บันทึกรับเข้า / ทำรายการเบิก — ดู window.GuideDemo
--      ใน assets/js/product-dept.js กับ assets/js/doc-form.js)
--
--  auto_start = 1 เฉพาะหน้าที่คนเปิดครั้งแรกแล้วทำผิดได้ง่าย
--  หน้าที่เปิดมาเพื่ออ่านอย่างเดียวตั้งเป็น 0 เพราะการเด้งขวางทุกครั้งกวนกว่าช่วย
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hl < sql/022_guide_rebuild.sql
--
--  ย้อนกลับ:
--      ไม่มีไฟล์ย้อนกลับที่คืนของเดิมได้ เพราะไฟล์นี้ลบเนื้อหาเก่าทิ้ง
--      ถ้าต้องการของเดิม ให้รัน sql/011, 012, 017, 018, 020, 021 ใหม่ตามลำดับ
--      (สถานะการอ่านคู่มือของผู้ใช้ใน tb_guide_seen ไม่ถูกแตะ)
--
--  รองรับ MySQL 5.0.51b
-- ============================================================

SET NAMES utf8;

-- ล้างของเดิมทั้งหมด ลูกก่อนแม่เพราะ step อ้าง guide_id อยู่
DELETE FROM `tb_guide_step`;
DELETE FROM `tb_guide`;


-- ============================================================
--  ภาพรวมระบบ
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('dashboard', 'ภาพรวมระบบ', 'จุดเริ่มต้นของทุกวัน — งานที่รอคุณ ตัวเลขของคลัง และกราฟความเคลื่อนไหว', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#bnHero', 'เริ่มที่ตรงนี้ทุกครั้ง',
 'ตัวเลขใหญ่คือจำนวนเอกสารที่รอ "คุณ" ทำอะไรสักอย่าง ไม่ใช่จำนวนงานทั้งระบบ ถ้าเป็นศูนย์แปลว่าไม่มีอะไรค้างอยู่ที่คุณ กดที่รายการด้านล่างเพื่อกระโดดไปที่ใบนั้นได้เลย ไม่ต้องไปไล่หาในหน้าประวัติ',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#bnChartTile', 'กราฟความเคลื่อนไหว',
 'แท่งเขียวคือของเข้า แท่งส้มคือของออก ชี้ค้างที่แท่งไหนก็ได้เพื่อดูตัวเลขของช่วงนั้น ถ้าเลือกช่วงยาวจนแท่งเบียดกัน กราฟจะเปลี่ยนเป็นเส้นให้เองเพื่อให้ยังอ่านแนวโน้มได้',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#bnCfQuick', 'เลือกช่วงที่อยากดู',
 'สี่ปุ่มนี้ครอบช่วงที่ถามกันบ่อยที่สุด กดแล้วจะตั้งความละเอียดที่เหมาะกับช่วงนั้นให้ด้วย เช่น "3 ปี" จะดูเป็นรายปี เพราะสามปีที่ดูรายวันคือกว่าพันแท่งซึ่งอ่านไม่ออก',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#bnCfUnit', 'รายวัน รายเดือน หรือรายปี',
 'เปลี่ยนความละเอียดแยกจากช่วงได้ เช่นดูทั้งปีแบบรายวันเพื่อหาว่าของออกหนักวันไหน หรือดูสามปีแบบรายเดือนเพื่อดูฤดูกาล ถ้าช่วงยาวเกินจนเกิน 400 ช่วง ระบบจะตัดให้แล้วบอกไว้ใต้กราฟ',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#notiBtn', 'กระดิ่งแจ้งเตือน',
 'ตัวเลขแดงคือเรื่องที่ยังไม่ได้อ่าน กดแล้วจะเห็นรายการจัดกลุ่มตามวัน เลื่อนลงถึงท้ายจะโหลดเพิ่มให้เอง ท้ายกล่องมีสวิตช์ "แจ้งเตือนนอกหน้าเว็บ" ที่ทำให้เรื่องใหม่เด้งเป็นกล่องมุมจอแม้คุณสลับไปทำอย่างอื่น',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#appGuideBtn', 'เปิดคู่มือได้ทุกหน้า',
 'ทุกหน้าในระบบมีคู่มือของตัวเอง กดปุ่มนี้เมื่อไรก็ได้ บางหน้าจะใส่ข้อมูลตัวอย่างให้ดูระหว่างเปิดคู่มือ แล้วเอาออกให้เองตอนปิด',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#themeToggle', 'โหมดสว่าง/มืด',
 'สลับได้ตามสภาพแสงที่นั่งทำงาน ระบบจำค่าไว้ให้ในเครื่องนี้ ไม่ต้องตั้งใหม่ทุกครั้งที่เข้าระบบ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='dashboard';


-- ============================================================
--  รายการสินค้า
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('product_list', 'ทะเบียนสินค้า', 'แก้ไขในช่องได้เลย แล้วกดบันทึกทีเดียวทั้งหมด', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'แก้ในช่องได้เลย',
 'ดับเบิลคลิกที่ช่องไหนก็แก้ได้ทันที ไม่ต้องเปิดหน้าต่างแยก ลากคลุมหลายช่องแล้วกด Ctrl+C คัดลอกไปวางใน Excel ได้ และลากจุดมุมขวาล่างของช่องเพื่อคัดลอกค่าลงแถวถัดไปได้เหมือน Excel',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='product_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#grid', 'ช่องราคามีผลกับหน้ารับเข้า',
 'ราคาที่ตั้งไว้ที่นี่จะถูกเติมให้อัตโนมัติเป็นค่าตั้งต้นตอนบันทึกรับสินค้าเข้า คนคลังยังแก้เป็นราคาที่ซื้อจริงของครั้งนั้นได้ และการแก้ตรงนั้นจะไม่ย้อนมาทับราคาที่ตั้งไว้ที่นี่',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='product_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnAddRows', 'เพิ่มสินค้าใหม่',
 'กดแล้วได้แถวว่างมาให้กรอก รหัสสินค้าออกให้อัตโนมัติตามหมวดหมู่ย่อยที่เลือก จึงต้องตั้งหมวดหมู่ให้เรียบร้อยก่อนเพิ่มสินค้า',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnSave', 'ต้องกดบันทึกเสมอ',
 'ทุกอย่างที่แก้ยังอยู่แค่บนหน้าจอจนกว่าจะกดปุ่มนี้ ตัวเลขบนปุ่มบอกว่ามีกี่แถวที่รอบันทึกอยู่ ปิดหน้าไปก่อนกด สิ่งที่แก้จะหายทั้งหมด',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_list';


-- ============================================================
--  หมวดหมู่สินค้า
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('product_category', 'หมวดหมู่สินค้า', 'หมวดหมู่ย่อยเป็นตัวกำหนดรหัสสินค้า', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'หมวดหมู่ย่อยกำหนดรหัสสินค้า',
 'หมวด CS-MED จะออกรหัสให้สินค้าเป็น CS-MED-001, CS-MED-002 ไปเรื่อย ๆ การเปลี่ยนรหัสนำหน้าทีหลังจะมีผลกับสินค้าที่เพิ่มใหม่เท่านั้น ไม่ย้อนไปแก้ของเดิมที่ออกรหัสไปแล้ว',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='product_category';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnAddRows', 'เพิ่มหมวดหมู่',
 'ตั้งรหัสนำหน้าให้สั้นและสื่อความ เพราะจะติดไปกับรหัสสินค้าทุกตัวในหมวดนั้นตลอดอายุการใช้งาน',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_category';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'ลบหมวดที่มีสินค้าอยู่ไม่ได้',
 'ระบบจะปฏิเสธการลบหมวดที่ยังมีสินค้าผูกอยู่ ถ้าเลิกใช้แล้วให้ปิดใช้งานแทน สินค้าเดิมจะยังอยู่ครบและรายงานย้อนหลังยังอ่านได้เหมือนเดิม',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_category';


-- ============================================================
--  สินค้าของหน่วยงาน  (มีชุดข้อมูลสาธิต)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('product_dept', 'สินค้าของหน่วยงาน', 'จำกัดว่าหน่วยงานไหนเบิกสินค้าตัวใดได้บ้าง', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ไม่ติ๊ก = ของกลาง',
 'สินค้าที่ไม่ได้ติ๊กให้หน่วยงานใดเลย ทุกหน่วยงานเบิกได้ตามปกติ การติ๊กที่หน้านี้คือการ "จำกัด" ว่ารายการนั้นให้เฉพาะหน่วยงานที่เลือกไว้เบิกเท่านั้น จึงควรติ๊กเฉพาะของที่ต้องกันไว้จริง ๆ ไม่ใช่ไล่ติ๊กให้ครบทุกหน่วยงาน (ระหว่างเปิดคู่มือนี้ ระบบใส่ข้อมูลตัวอย่างไว้ให้ดู จะหายไปเองตอนปิด)',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#pdDeptBox', 'เลือกหน่วยงานก่อน',
 'ตัวเลขในวงเล็บข้างชื่อคือจำนวนสินค้าที่ผูกไว้แล้ว หน่วยงานที่ไม่มีตัวเลขคือยังไม่เคยตั้งค่า ซึ่งแปลว่าเบิกได้ทุกอย่าง หลายหน่วยงานชื่อซ้ำกันข้ามบริษัท จึงมีชื่อบริษัทกำกับไว้บรรทัดล่าง และพิมพ์ค้นจากชื่อบริษัทได้ด้วย',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#pdLeft', 'ติ๊กสินค้า',
 'ฝั่งซ้ายคือสินค้าทั้งหมด แตะที่รายการเพื่อผูกเข้ากับหน่วยงานที่เลือกไว้ แตะซ้ำเพื่อเอาออก ป้าย "ใช้ร่วมกับ" บอกว่าตัวนั้นมีหน่วยงานอื่นใช้อยู่แล้ว ซึ่งติ๊กเพิ่มได้ ไม่ได้แย่งกัน',
 'right', 'start', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#pdAll', 'เลือกทีเดียวทั้งหมวด',
 'ใช้คู่กับตัวกรองหมวดหมู่หรือคำค้นหา กดแล้วจะติ๊กเฉพาะรายการที่กำลังแสดงอยู่ ไม่ใช่ทั้ง 313 รายการ เหมาะกับตอนที่อยากยกทั้งหมวดให้ฝ่ายเดียว',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#pdRight', 'ฝั่งขวาคือผลลัพธ์',
 'ทุกอย่างที่อยู่ฝั่งนี้คือสิ่งที่หน่วยงานที่เลือกไว้จะเบิกได้หลังกดบันทึก แตะที่รายการเพื่อเอาออก ถ้าฝั่งนี้ว่างเปล่า แปลว่าหน่วยงานนั้นเบิกได้ทุกอย่างตามปกติ',
 'left', 'start', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#pdSave', 'บันทึก',
 'ต้องกดทุกครั้ง เปลี่ยนหน่วยงานหรือปิดหน้าไปก่อนกด สิ่งที่ติ๊กไว้จะหายทั้งหมด หลังบันทึกแล้วหน้าทำใบเบิกจะเห็นผลทันที',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='product_dept';


-- ============================================================
--  บันทึกรับเข้า  (มีชุดข้อมูลสาธิต)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('receive_new', 'บันทึกรับสินค้าเข้า', 'บันทึกแล้วสต๊อกเพิ่มทันที และราคาที่กรอกมีผลกับต้นทุน', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'บันทึกแล้วสต๊อกเพิ่มทันที',
 'ใบรับเข้าไม่มีขั้นตอนอนุมัติ กดบันทึกปุ๊บยอดคงเหลือขึ้นเลย ถ้าบันทึกผิดต้องไปยกเลิกทั้งใบที่หน้าประวัติการรับเข้า ไม่ใช่กลับมาแก้ตัวเลข (ระหว่างเปิดคู่มือนี้ ระบบใส่รายการตัวอย่างไว้ให้ดู จะหายไปเองตอนปิด)',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#docDate', 'วันที่เอกสาร',
 'ใช้วันที่ของใบส่งของจริง ไม่ใช่วันที่คีย์เข้าระบบ เพราะเลขที่เอกสารกับรายงานรายเดือนอ้างอิงวันที่นี้ ลงวันที่ล่วงหน้าไม่ได้',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#items', 'ราคาเติมให้อัตโนมัติ',
 'เลือกสินค้าแล้วช่องราคาจะเติมราคาจากทะเบียนสินค้าให้เอง ถ้ารอบนี้ซื้อได้ราคาอื่นก็แก้ทับได้เลย ราคาที่กรอกตรงนี้คือราคาที่ระบบเอาไปคิดต้นทุนถัวเฉลี่ย จึงควรกรอกให้ครบทุกบรรทัด ไม่งั้นรายงานค่าใช้จ่ายจะขึ้นศูนย์',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnAddRow', 'เพิ่มทีละบรรทัด',
 'ของตัวเดียวกันที่รับเข้ามาคนละราคาให้แยกเป็นคนละบรรทัด ระบบเก็บราคาไว้ที่บรรทัด ไม่ใช่ที่หัวเอกสาร รายงานจึงย้อนดูได้ว่าครั้งไหนซื้อเท่าไร',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='receive_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#btnSave', 'ตรวจยอดรวมก่อนกด',
 'ยอดรวมเป็นเงินอยู่เหนือปุ่มนี้ ควรตรงกับใบส่งของที่ถืออยู่ก่อนกดบันทึก เพราะหลังจากนี้แก้ไม่ได้แล้ว ต้องยกเลิกทั้งใบแล้วคีย์ใหม่',
 'top', 'end', NOW() FROM `tb_guide` WHERE `page_key`='receive_new';


-- ============================================================
--  ประวัติการรับเข้า
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('receive_history', 'ประวัติการรับสินค้าเข้า', 'ตรวจย้อนหลัง ดูราคาที่รับมาแต่ละครั้ง และยกเลิกใบที่ผิด', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'คอลัมน์มูลค่ารวม',
 'บอกว่าใบนั้นเป็นเงินเท่าไร คิดจากราคาที่กรอกไว้ตอนรับเข้าจริง ไม่ใช่ราคาปัจจุบันของสินค้า ใบที่ขึ้นขีดกลางคือใบที่ยังไม่ได้กรอกราคา',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#grid', 'คอลัมน์จัดการ',
 'ไอคอนท้ายแถวทำงานกับใบนั้นได้เลยโดยไม่ต้องเปิดกล่องรายละเอียดก่อน — รูปตาคือดูรายละเอียด รูปเครื่องพิมพ์คือพิมพ์เอกสาร รูปห้ามคือยกเลิกใบ ชี้ค้างที่ไอคอนจะมีคำอธิบายขึ้น',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัดพร้อมราคาต่อหน่วย ใช้ตอบคำถามแบบ "เดือนนี้รับผ้าพันแผลกี่ครั้ง ครั้งละราคาเท่าไร" ซึ่งไฟล์แบบแรกกลืนสองราคารวมกันจนแยกไม่ออก ไฟล์ที่ได้ใช้ตัวกรองชุดเดียวกับที่เห็นบนจอ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#fpToggle', 'ตัวกรอง',
 'กรองตามช่วงวันที่ สถานะ หรือค้นเลขที่เอกสารได้ ทั้งตารางและไฟล์ที่ส่งออกใช้เงื่อนไขเดียวกัน สิ่งที่เห็นบนจอกับในไฟล์จึงตรงกันเสมอ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='receive_history';


-- ============================================================
--  ทำรายการเบิก  (มีชุดข้อมูลสาธิต)
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('request_new', 'ทำรายการเบิก', 'เลือกของที่ต้องการแล้วส่งให้ผู้อนุมัติ', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ใบเบิกยังไม่ตัดสต๊อก',
 'ส่งใบเบิกแล้วของยังไม่ถูกตัดออกจากคลัง ต้องรอผู้อนุมัติตัดสิน แล้วคลังทำใบจ่ายอีกที ยอดคงเหลือที่เห็นจึงเป็นยอดจริงตอนนี้ ไม่ได้หักใบเบิกที่คนอื่นส่งไปแล้ว (ระหว่างเปิดคู่มือนี้ ระบบใส่รายการตัวอย่างไว้ให้ดู จะหายไปเองตอนปิด)',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#deptBox', 'หน่วยงานที่ขอเบิก',
 'ระบบเลือกหน่วยงานต้นสังกัดให้ล่วงหน้าแล้ว ผู้ดูแลระบบเปลี่ยนเป็นหน่วยงานอื่นได้เพื่อทำแทน แต่ระวัง — เปลี่ยนหน่วยงานแล้วรายการที่เลือกไว้จะถูกล้าง เพราะสินค้าที่แต่ละหน่วยงานเบิกได้ไม่เหมือนกัน',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='request_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#tfSearch', 'ค้นหาสินค้า',
 'พิมพ์รหัส ชื่อสินค้า หรือหมวดหมู่ก็ได้ รายการที่เห็นคือของที่หน่วยงานของคุณเบิกได้เท่านั้น ถ้าหาของที่ต้องการไม่เจอ อาจเป็นเพราะถูกจำกัดไว้ให้หน่วยงานอื่น ให้แจ้งผู้ดูแลระบบ',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='request_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#tfLeft', 'แตะเพื่อเลือก',
 'แตะที่รายการเพื่อเพิ่มเข้าใบเบิก ตัวเลขคงเหลือกำกับไว้ทุกตัว สีส้มคือใกล้หมด สีแดงคือหมดแล้ว ของที่หมดยังขอเบิกได้ แต่คลังจะจ่ายให้ไม่ได้จนกว่าจะมีของเข้ามา',
 'right', 'start', NOW() FROM `tb_guide` WHERE `page_key`='request_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#tfRight', 'รายการที่จะเบิก',
 'ของที่เพิ่งเลือกจะขึ้นบนสุดเสมอ กรอกจำนวนที่ต้องการจริงในช่อง และใส่หมายเหตุรายบรรทัดได้ถ้าต้องอธิบายเหตุผล ซึ่งช่วยให้ผู้อนุมัติตัดสินใจได้เร็วขึ้น',
 'left', 'start', NOW() FROM `tb_guide` WHERE `page_key`='request_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#btnSave', 'ส่งใบเบิก',
 'กดแล้วใบจะเข้าสถานะรออนุมัติทันที และระบบจะแจ้งผู้อนุมัติของหน่วยงานให้เอง ทั้งกระดิ่งในระบบและอีเมล ติดตามสถานะได้ที่หน้ารายการใบเบิก',
 'top', 'end', NOW() FROM `tb_guide` WHERE `page_key`='request_new';


-- ============================================================
--  รายการใบเบิก
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('request_list', 'รายการใบเบิก', 'ติดตามสถานะ อนุมัติ และยกเลิกใบเบิก', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'สถานะบอกว่าใบอยู่ตรงไหน',
 'รออนุมัติ คือรอผู้อนุมัติตัดสิน · อนุมัติแล้ว คือรอคลังจ่ายของ · จ่ายแล้ว คือคลังทำใบจ่ายครบทุกบรรทัดแล้ว จุดสีข้างไอคอนแรกหมายถึงใบนั้นมีงานรออยู่ที่คุณ',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#grid', 'อนุมัติจากคอลัมน์จัดการ',
 'ผู้อนุมัติจะเห็นไอคอนถูกกับกากบาทท้ายแถวของใบที่รออยู่ กดได้จากตารางเลย แต่ควรกดรูปตาเพื่อดูรายการสินค้าก่อนทุกครั้ง เพราะการอนุมัติคือการยืนยันว่าของทั้งใบสมเหตุสมผล ไม่ใช่แค่ยืนยันว่ามีใบเข้ามา',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#gridSearch', 'ค้นและกรอง',
 'ค้นได้จากเลขที่เอกสาร ชื่อผู้ขอ หน่วยงาน หรือหมายเหตุ ในแถบตัวกรองยังเลือกช่วงวันที่และสถานะได้ ผู้ที่เห็นใบของคนอื่นจะมีตัวเลือก "เฉพาะเอกสารของฉัน" เพิ่มมาด้วย',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='request_list';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ปุ่มซ้ายให้ไฟล์แถวละหนึ่งใบ ส่วนปุ่มนี้แตกเป็นรายบรรทัด เห็นว่าแต่ละใบขอของอะไรจำนวนเท่าไร เหมาะกับตอนสรุปว่าเดือนนี้ฝ่ายไหนเบิกอะไรไปบ้าง',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='request_list';


-- ============================================================
--  บันทึกจ่ายออก
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('issue_new', 'บันทึกจ่ายสินค้าออก', 'จ่ายของตามใบเบิกที่อนุมัติแล้ว', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#refBox', 'เลือกใบเบิกก่อน',
 'หน้านี้เริ่มจากใบเบิกที่อนุมัติแล้วและยังจ่ายไม่ครบเท่านั้น เลือกไม่ได้เองว่าจะจ่ายอะไร ถ้าไม่มีตัวเลือกขึ้นมาเลย แปลว่าไม่มีใบเบิกที่รอจ่ายอยู่',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='issue_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#items', 'จ่ายไม่ครบก็ได้',
 'ระบบเติมจำนวนที่ยังค้างจ่ายให้ล่วงหน้า แก้ให้น้อยลงได้ถ้าของมีไม่พอ ส่วนที่เหลือจะยังค้างอยู่ที่ใบเบิกเดิม ทำใบจ่ายรอบสองทีหลังได้ ใบเบิกจะปิดก็ต่อเมื่อจ่ายครบทุกบรรทัด',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_new';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'บันทึกแล้วตัดสต๊อกทันที',
 'กดแล้วยอดคงเหลือลดทันที และระบบจะส่งอีเมลพร้อมลิงก์ให้ผู้ขอเบิกมาเซ็นรับ ลิงก์นั้นเปิดได้เลยไม่ต้องล็อกอิน บันทึกผิดต้องยกเลิกทั้งใบที่หน้าประวัติการจ่ายออก',
 'top', 'end', NOW() FROM `tb_guide` WHERE `page_key`='issue_new';


-- ============================================================
--  ประวัติการจ่ายออก
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('issue_history', 'ประวัติการจ่ายออก', 'ตามเรื่องการเซ็นรับ ส่งลิงก์ให้ผู้เบิก และยกเลิกใบที่ผิด', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ใบที่ยังไม่ถูกเซ็นรับ',
 'สถานะ "จ่ายแล้ว" คือของออกจากคลังไปแล้วแต่ผู้เบิกยังไม่ได้เซ็นรับ ส่วน "รับของแล้ว" คือปิดเรื่องเรียบร้อย ใบที่ค้างสถานะแรกนาน ๆ คือใบที่ต้องตามเรื่อง',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#grid', 'คัดลอกลิงก์เซ็นรับของ',
 'ไอคอนรูปโซ่ท้ายแถวคัดลอกลิงก์สำหรับให้ผู้เบิกเซ็นรับ เปิดได้โดยไม่ต้องล็อกอิน ส่งทางไลน์ได้เลยตอนที่เขาหาอีเมลไม่เจอ ส่วนไอคอนลูกศรเปิดหน้านั้นให้ดูว่าผู้เบิกจะเห็นอะไร ลิงก์ใช้ได้ 7 วันและเซ็นรับแทนได้ จึงส่งให้เฉพาะผู้เบิกเท่านั้น',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#grid', 'เซ็นรับแทน และส่งอีเมลซ้ำ',
 'ไอคอนปากกาคือเซ็นรับของด้วยลายเซ็นจริงบนจอ ผู้ดูแลระบบเซ็นแทนได้เมื่อผู้เบิกมารับที่คลังโดยตรง ส่วนไอคอนซองจดหมายคือส่งอีเมลแจ้งซ้ำ ใช้ตอนที่ผู้เบิกหาเมลฉบับเดิมไม่เจอ',
 'left', 'center', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnExportLines', 'ส่งออกแบบรายบรรทัด',
 'ได้ไฟล์ที่แตกเป็นรายบรรทัดพร้อมจำนวนและมูลค่า ใช้สรุปว่าเดือนนี้จ่ายของอะไรออกไปให้ใครบ้าง ไฟล์ใช้ตัวกรองชุดเดียวกับที่เห็นบนจอ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='issue_history';


-- ============================================================
--  รายงาน
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('report_stock', 'รายงานยอดคงเหลือ', 'ยอดจริงของทุกสินค้า ณ ตอนนี้', 0, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ยอด ณ ตอนนี้',
 'เป็นยอดจริงที่นับได้ในคลังตอนนี้ ยังไม่หักใบเบิกที่รออนุมัติอยู่ ถ้าอยากรู้ว่ามีของถูกจองไว้เท่าไร ต้องดูที่หน้ารายการใบเบิก',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_stock';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnExport', 'ส่งออก Excel',
 'ได้ไฟล์ตามที่กรองไว้บนจอ ไม่ใช่ทั้งฐานข้อมูล กรองก่อนแล้วค่อยกด จะได้ไฟล์ที่เอาไปใช้ต่อได้เลยโดยไม่ต้องมาตัดแถวทิ้งใน Excel',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='report_stock';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('report_movement', 'รายงานความเคลื่อนไหว', 'ทุกครั้งที่ยอดเปลี่ยน มีบรรทัดบันทึกไว้เสมอ', 0, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ตามรอยของทุกชิ้น',
 'ทุกครั้งที่ยอดคงเหลือเปลี่ยน จะมีบรรทัดที่นี่เสมอ พร้อมเลขที่เอกสารต้นทาง ใช้ตอบคำถามว่า "ของหายไปไหน" ได้ตรงที่สุด เพราะไล่ย้อนไปถึงใบที่ทำให้ยอดเปลี่ยนได้ทุกครั้ง',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_movement';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnExport', 'ส่งออก Excel',
 'กรองช่วงวันที่กับประเภทการเคลื่อนไหวก่อนกด จะได้ไฟล์ที่ตรงกับสิ่งที่ต้องการตรวจ ไม่ต้องมาตัดแถวทิ้งทีหลัง',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='report_movement';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('report_low', 'ต่ำกว่าจุดสั่งซื้อ', 'รายการที่ควรสั่งเพิ่มแล้ว', 0, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ต้องตั้งจุดสั่งซื้อก่อน',
 'รายงานนี้เทียบยอดคงเหลือกับจุดสั่งซื้อที่ตั้งไว้ในทะเบียนสินค้า สินค้าที่ยังไม่ได้ตั้งจุดสั่งซื้อจะไม่มีวันโผล่ที่นี่ แม้ของจะหมดแล้วก็ตาม จึงควรไล่ตั้งให้ครบก่อนใช้รายงานนี้ตัดสินใจ',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_low';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('report_cost', 'ค่าใช้จ่ายรายเดือน', 'เงินเข้าออกของเดือน แยกตามบริษัทและหน่วยงาน', 0, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#rcMonth', 'เลือกเดือน',
 'ตัวเลขทั้งหน้าเปลี่ยนตามเดือนที่เลือก ค่าใช้จ่ายคิดจากราคาที่กรอกไว้ตอนรับเข้าจริง ใบที่ไม่ได้กรอกราคาจะไม่ถูกนับ ทำให้ยอดต่ำกว่าความจริง',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#tblComp', 'แยกตามบริษัท',
 'กดที่แถวไหนก็ได้เพื่อดูว่ายอดนั้นมาจากเอกสารใบไหนบ้าง เป็นคำถามที่บัญชีถามเสมอเวลาตัวเลขไม่ตรงกับที่คาดไว้',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnPrint', 'พิมพ์ส่งบัญชี',
 'ได้หน้ากระดาษที่ตัดเมนูและปุ่มออกหมดแล้ว เหลือแต่ตัวเลขที่ส่งต่อได้เลย',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='report_cost';


-- ============================================================
--  บัญชีและระบบ
-- ============================================================
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('users', 'ผู้ใช้งาน', 'เพิ่มบัญชี ตั้งสิทธิ์ และผูกหน่วยงาน', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'หน่วยงานกับอีเมลสำคัญกว่าที่คิด',
 'หน่วยงานเป็นตัวกำหนดว่าคนนั้นเบิกอะไรได้และใบเบิกจะไปหาผู้อนุมัติคนไหน ส่วนอีเมลคือช่องทางที่ระบบใช้แจ้งเรื่อง คนที่ไม่ได้กรอกอีเมลจะได้แค่กระดิ่งในระบบ ไม่ได้รับเมล',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='users';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnAddRows', 'เพิ่มผู้ใช้',
 'บัญชีใหม่ได้รหัสผ่านตั้งต้นเหมือนกันทุกคน ต้องบอกให้เจ้าตัวเข้าไปเปลี่ยนเองที่เมนูเปลี่ยนรหัสผ่านทันทีที่เข้าระบบครั้งแรก',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='users';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'ปิดใช้งานดีกว่าลบ',
 'คนที่ลาออกให้ปิดใช้งาน อย่าลบ เพราะเอกสารเก่าอ้างชื่อผู้ใช้นั้นอยู่ ลบแล้วประวัติจะอ่านไม่รู้เรื่องว่าใครเป็นคนทำ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='users';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('agencies', 'บริษัทและหน่วยงาน', 'โครงสร้างที่ทุกอย่างในระบบอ้างอิงถึง', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'หน่วยงานผูกกับบริษัท',
 'หน่วยงานทุกหน่วยต้องสังกัดบริษัทใดบริษัทหนึ่ง เพราะโลโก้และรหัสแบบฟอร์มบนใบเบิกที่พิมพ์ออกมาถูกเลือกจากบริษัทของหน่วยงานนั้น หลายบริษัทมีหน่วยงานชื่อซ้ำกัน เช่นจัดซื้อหรือบัญชี ซึ่งเป็นคนละหน่วยงานกัน',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='agencies';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnSave', 'เปลี่ยนแล้วมีผลทันที',
 'ปิดหน่วยงานแล้วจะไม่มีใครทำใบเบิกในนามหน่วยงานนั้นได้อีก แต่เอกสารเก่ายังอยู่ครบและรายงานย้อนหลังยังอ่านได้เหมือนเดิม',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='agencies';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('permissions', 'สิทธิ์การใช้งาน', 'กำหนดว่าสิทธิ์ไหนเปิดเมนูใดได้', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'จัดกลุ่มตามเมนูหลัก',
 'เมนูถูกจัดกลุ่มตามหัวข้อบนแถบซ้าย กดที่หัวกลุ่มเพื่อกางหรือพับ กลุ่มที่มีเมนูเดียวจะไม่มีหัวกลุ่ม เพราะพับแล้วไม่ได้อะไร ติ๊กในช่องเพื่อเปิดสิทธิ์ ติ๊กออกเพื่อปิด',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='permissions';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnExpand', 'กางทั้งหมดทีเดียว',
 'ใช้ตอนอยากเห็นภาพรวมทั้งระบบพร้อมกัน แล้วกดอีกครั้งเพื่อพับกลับให้เรียบร้อย พิมพ์ค้นหาก็จะกางกลุ่มที่เจอให้เองอัตโนมัติ',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='permissions';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'ปิดเมนูแล้วเข้าไม่ได้จริง',
 'ไม่ใช่แค่ซ่อนเมนู คนที่ไม่มีสิทธิ์พิมพ์ที่อยู่หน้านั้นตรง ๆ ก็จะเจอหน้าไม่มีสิทธิ์เข้าถึง ส่วนผู้ดูแลระบบสูงสุดเข้าได้ทุกเมนูเสมอ ปรับไม่ได้ เพื่อไม่ให้เผลอปิดสิทธิ์ตัวเองจนไม่มีใครแก้คืนได้',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='permissions';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('settings', 'ตั้งค่าระบบ', 'อีเมลแจ้งเตือนและข้อความในคู่มือ', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#mailEnabled', 'สวิตช์ส่งอีเมล',
 'ปิดสวิตช์นี้แล้วเมลจะยังเข้าคิวไว้เหมือนเดิม ไม่หายไปไหน แค่ไม่ถูกส่งออก เปิดกลับเมื่อไรก็ทยอยส่งต่อได้ ใช้ตอนย้ายเซิร์ฟเวอร์หรือตอนทดสอบระบบ',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='settings';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#smtpPass', 'ใช้รหัสผ่านแอป ไม่ใช่รหัสเข้าเมล',
 'Zoho และผู้ให้บริการอีเมลส่วนใหญ่ให้สร้างรหัสผ่านสำหรับแอปแยกต่างหาก ใส่รหัสที่ใช้ล็อกอินเมลตรง ๆ จะถูกปฏิเสธ ช่องนี้แสดงเป็นจุดเสมอ ระบบไม่ส่งรหัสกลับมาให้หน้าจอเห็น',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='settings';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnTestMail', 'ทดสอบก่อนเสมอ',
 'ส่งเมลจริงหนึ่งฉบับไปที่อยู่ที่ระบุ ถ้าตั้งค่าผิดจะบอกสาเหตุกลับมาตรง ๆ ควรกดทุกครั้งหลังแก้ค่าเชื่อมต่อ ไม่งั้นจะรู้ว่าพังก็ตอนที่มีใบเบิกเข้ามาจริงแล้วไม่มีใครได้เมล',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='settings';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#mailQueue', 'คิวเมลขาออก',
 'เมลทุกฉบับเข้าคิวก่อนเสมอ แล้วตัวส่งเบื้องหลังหยิบไปส่งภายในสิบวินาที ฉบับที่ส่งไม่ผ่านสามครั้งจะกลายเป็นล้มเหลวและหยุดลองใหม่ ต้องกด "เอากลับเข้าคิว" เอง ปุ่ม "ส่งคิวเดี๋ยวนี้" ใช้ตอนอยากส่งทันทีโดยไม่รอรอบ',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='settings';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#tabGuideBtn', 'แก้ข้อความในคู่มือได้',
 'ข้อความทุกขั้นตอนของคู่มือทุกหน้าแก้ได้จากแท็บนี้ ผู้ใช้จะเห็นข้อความใหม่ทันทีที่เปิดคู่มือครั้งถัดไป ใช้ปรับถ้อยคำให้ตรงกับวิธีทำงานจริงของที่นี่ได้โดยไม่ต้องแก้โปรแกรม',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='settings';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('notifications', 'การแจ้งเตือนทั้งหมด', 'ที่รวมแจ้งเตือนย้อนหลัง และที่ตั้งค่าให้เด้งนอกหน้าเว็บ', 0, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#ntList', 'แจ้งเตือนของคุณทั้งหมด',
 'รวมทุกเรื่องที่เคยแจ้งคุณ เรียงใหม่สุดอยู่บน จัดกลุ่มตามวันให้แล้ว โหลดมาทีละ 10 เรื่อง เลื่อนลงถึงท้ายจะโหลดต่อเอง ที่นี่เห็นเฉพาะของคุณ แม้แต่ผู้ดูแลระบบสูงสุดก็ดูของคนอื่นทางนี้ไม่ได้',
 'top', 'center', NOW() FROM `tb_guide` WHERE `page_key`='notifications';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#ntDesk', 'ให้เด้งนอกหน้าเว็บ',
 'เปิดสวิตช์แล้วเรื่องใหม่จะเด้งเป็นกล่องมุมจอ แม้คุณสลับไปทำอย่างอื่น แต่ต้องเปิดหน้าระบบค้างไว้ในแท็บใดแท็บหนึ่ง ปิดเบราว์เซอร์ไปเลยจะไม่มีอะไรเด้ง ถ้าสวิตช์กดไม่ลง ให้อ่านเหตุผลข้าง ๆ — ส่วนใหญ่เพราะเบราว์เซอร์อนุญาตเฉพาะเว็บที่เข้าผ่าน https',
 'bottom', 'center', NOW() FROM `tb_guide` WHERE `page_key`='notifications';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#ntFilter', 'ดูเฉพาะที่ยังไม่ได้อ่าน',
 'สลับไปมาได้ระหว่างทั้งหมดกับเฉพาะที่ยังไม่ได้อ่าน ตัวกรองทำงานที่เซิร์ฟเวอร์ ผลที่ได้จึงครบเสมอ ไม่ใช่ซ่อนเฉพาะที่โหลดมาแล้ว',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='notifications';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#ntReadAll', 'อ่านทั้งหมดทีเดียว',
 'ทำเครื่องหมายว่าอ่านแล้วทุกเรื่องพร้อมกัน รายการยังอยู่ครบ แค่จุดสีหายไปและตัวเลขบนกระดิ่งกลับเป็นศูนย์',
 'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key`='notifications';


INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`) VALUES
('change_password', 'เปลี่ยนรหัสผ่าน', 'เปลี่ยนรหัสของตัวเอง', 1, 1, NOW());

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#currentPassword', 'ยืนยันตัวตนก่อน',
 'ต้องกรอกรหัสปัจจุบันให้ถูกก่อน เพื่อกันคนอื่นมาเปลี่ยนรหัสตอนที่คุณลุกจากโต๊ะโดยไม่ได้ออกจากระบบ',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='change_password';

INSERT INTO `tb_guide_step` (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#newPassword', 'รหัสใหม่',
 'บัญชีที่ผู้ดูแลเพิ่งสร้างให้จะมีรหัสตั้งต้นเหมือนกันทุกคน ควรเปลี่ยนทันทีที่เข้าระบบครั้งแรก เปลี่ยนเสร็จแล้วระบบจะให้เข้าสู่ระบบใหม่ด้วยรหัสใหม่',
 'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key`='change_password';
