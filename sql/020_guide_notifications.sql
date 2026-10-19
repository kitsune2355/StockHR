-- ============================================================
--  020_guide_notifications.sql
--  คู่มือของหน้า "การแจ้งเตือน" ซึ่งเป็นหน้าเดียวที่ยังไม่มี
--
--  ที่มา: ตรวจความครบของคู่มือแล้วพบว่า 19 จาก 20 หน้ามีคู่มือครบ
--  ขาดเฉพาะ notifications ที่เพิ่งเพิ่มเข้ามาทีหลัง
--  ปุ่มคู่มือบนแถบบนจึงหายไปเฉพาะหน้านี้หน้าเดียว ซึ่งดูเหมือนระบบทำงาน
--  ไม่สม่ำเสมอ มากกว่าจะดูเหมือน "หน้านี้ไม่จำเป็นต้องมีคู่มือ"
--
--  ---------------------------------------------------------------
--  ทำไมต้องมีขั้นตอนเรื่องแจ้งเตือนนอกหน้าเว็บ
--  ---------------------------------------------------------------
--  สวิตช์นั้นเปิดใช้ได้เฉพาะเครื่องที่เข้าผ่าน https หรือ localhost
--  เครื่องในวงแลนที่เข้าผ่าน http://<ไอพี> จะเห็นสวิตช์ที่กดไม่ลง
--  ถ้าไม่อธิบายไว้ ผู้ใช้จะคิดว่าระบบเสีย ทั้งที่เป็นข้อจำกัดของเบราว์เซอร์
--
--  auto_start = 0 ต่างจากหน้าอื่น เพราะคนเข้าหน้านี้มาเพื่อ "อ่านของที่ค้าง"
--  ไม่ได้มาเรียนวิธีใช้ การเด้งคู่มือขึ้นมาขวางทุกครั้งจะกวนมากกว่าช่วย
--  กดปุ่มคู่มือบนแถบบนเมื่อไรก็เปิดดูได้
--
--  ใช้ INSERT ... SELECT ที่กรองด้วย NOT EXISTS เพื่อให้รันซ้ำได้โดยไม่เกิดข้อมูลซ้ำ
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/020_guide_notifications.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/020_guide_notifications_rollback.sql
-- ============================================================

SET NAMES utf8;

INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
SELECT 'notifications', 'การแจ้งเตือนทั้งหมด',
       'ที่รวมแจ้งเตือนย้อนหลังทั้งหมดของคุณ และที่ตั้งค่าให้เด้งนอกหน้าเว็บ', 0, 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `tb_guide` WHERE `page_key` = 'notifications');


INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#ntList', 'แจ้งเตือนของคุณทั้งหมด',
       'รวมทุกเรื่องที่เคยแจ้งคุณ เรียงใหม่สุดอยู่บน และจัดกลุ่มตามวันให้แล้ว โหลดมาทีละ 10 เรื่อง เลื่อนลงถึงท้ายรายการแล้วจะโหลดต่อให้เอง ที่นี่เห็นเฉพาะของคุณเท่านั้น แม้แต่ผู้ดูแลระบบสูงสุดก็ดูของคนอื่นทางนี้ไม่ได้',
       'top', 'center', NOW()
FROM `tb_guide` WHERE `page_key` = 'notifications'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` s
                  WHERE s.`guide_id` = `tb_guide`.`guide_id` AND s.`step_order` = 1);

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#ntDesk', 'ให้เด้งนอกหน้าเว็บ',
       'เปิดสวิตช์นี้แล้วเรื่องใหม่จะเด้งเป็นกล่องมุมจอ แม้คุณสลับไปทำอย่างอื่นอยู่ แต่ต้องเปิดหน้าระบบค้างไว้ในแท็บใดแท็บหนึ่ง ปิดเบราว์เซอร์ไปเลยจะไม่มีอะไรเด้ง ถ้าสวิตช์กดไม่ลง ให้อ่านเหตุผลที่เขียนกำกับไว้ข้าง ๆ — ส่วนใหญ่เป็นเพราะเบราว์เซอร์อนุญาตเฉพาะเว็บที่เข้าผ่าน https',
       'bottom', 'center', NOW()
FROM `tb_guide` WHERE `page_key` = 'notifications'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` s
                  WHERE s.`guide_id` = `tb_guide`.`guide_id` AND s.`step_order` = 2);

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#ntFilter', 'ดูเฉพาะที่ยังไม่ได้อ่าน',
       'สลับไปมาได้ระหว่างทั้งหมดกับเฉพาะที่ยังไม่ได้อ่าน ตัวกรองทำงานที่เซิร์ฟเวอร์ ไม่ใช่ซ่อนเฉพาะที่โหลดมาแล้ว ผลที่ได้จึงครบเสมอ',
       'bottom', 'end', NOW()
FROM `tb_guide` WHERE `page_key` = 'notifications'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` s
                  WHERE s.`guide_id` = `tb_guide`.`guide_id` AND s.`step_order` = 3);

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#ntReadAll', 'อ่านทั้งหมดทีเดียว',
       'ทำเครื่องหมายว่าอ่านแล้วทุกเรื่องพร้อมกัน รายการยังอยู่ครบ แค่จุดสีหายไปและตัวเลขบนกระดิ่งกลับเป็นศูนย์ ใช้ตอนที่ปล่อยค้างไว้หลายวันจนกองเยอะ',
       'bottom', 'end', NOW()
FROM `tb_guide` WHERE `page_key` = 'notifications'
  AND NOT EXISTS (SELECT 1 FROM `tb_guide_step` s
                  WHERE s.`guide_id` = `tb_guide`.`guide_id` AND s.`step_order` = 4);
