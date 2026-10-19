-- ============================================================
--  011_guide_notify.sql
--  คู่มือการใช้งานในหน้าเว็บ (driver.js) + ระบบแจ้งเตือน + คิวอีเมล
--
--  ที่มา: ระบบโตขึ้นจนมี 4 สิทธิ์ 17 หน้า และเส้นทางใบเบิกมี 4 ขั้น
--  (ขอ -> อนุมัติ -> จ่าย -> เซ็นรับ) คนใหม่ที่เข้ามาจึงต้องมีคนสอนทุกครั้ง
--  และคนที่ต้องอนุมัติก็ไม่รู้ว่ามีงานเข้าจนกว่าจะเปิดระบบมาดูเอง
--
--  ไฟล์นี้เพิ่ม 6 ตาราง แบ่งเป็น 3 เรื่อง:
--
--  [คู่มือ]
--    tb_guide        คู่มือ 1 ชุดต่อ 1 หน้า (ผูกกับ ?page= ใน main.php)
--    tb_guide_step   ขั้นตอนในคู่มือ — ชี้ไปที่ element ด้วย CSS selector
--    tb_guide_seen   ใครดูคู่มือของหน้าไหนไปแล้ว (กันเด้งซ้ำทุกครั้งที่เข้าหน้า)
--
--  [แจ้งเตือน]
--    tb_notification แจ้งเตือนในระบบ ราย "ผู้รับ" 1 แถวต่อ 1 คน
--
--  [อีเมล]
--    tb_mail_queue   คิวอีเมลขาออก เขียนลงคิวก่อนเสมอ แล้วค่อยส่งทีหลัง
--    tb_app_setting  ค่าตั้งระบบแบบ key/value (โฮสต์ SMTP, ผู้ส่ง, เปิด/ปิด)
--
--  ---------------------------------------------------------------
--  ทำไมอีเมลต้องเข้าคิวก่อน ไม่ส่งทันทีตอนกดบันทึก
--  ---------------------------------------------------------------
--  การต่อ SMTP ออกอินเทอร์เน็ตใช้เวลา 1-5 วินาที ถ้าส่งคาไว้ในจังหวะที่
--  ผู้ใช้กด "บันทึกใบเบิก" หน้าจอจะค้างรอเมลทุกครั้ง และถ้าเมลเซิร์ฟเวอร์
--  ล่ม การบันทึกใบเบิกจะพังตามไปด้วยทั้งที่เป็นคนละเรื่องกัน
--
--  เขียนลงคิวใช้เวลาไม่ถึง 1 มิลลิวินาที แล้วให้ตัวส่ง (cron/mail_worker.php)
--  มาหยิบไปส่งทีหลัง ส่งไม่ผ่านก็ลองใหม่ได้ ไม่มีเมลตกหาย
--
--  ---------------------------------------------------------------
--  ข้อจำกัดของ MySQL 5.0.51b ที่ไฟล์นี้ต้องเลี่ยง
--  ---------------------------------------------------------------
--    - ไม่มี utf8mb4 ใช้ utf8 / utf8_unicode_ci เหมือนตารางเดิมทั้งระบบ
--    - คอลัมน์ที่ฐานข้อมูลเติมเวลาให้เองมีได้ตารางละตัวเดียว จึงยกให้ updated_at
--      ส่วน created_at และเวลาอื่น ๆ ให้แอปส่ง NOW() มา
--    - ไม่มี generated column / CHECK constraint
--
--  วิธีรัน:
--      mysql -u root -p1234 stock_hr < sql/011_guide_notify.sql
--
--  ย้อนกลับ:
--      mysql -u root -p1234 stock_hr < sql/011_guide_notify_rollback.sql
-- ============================================================

SET NAMES utf8;


-- ============================================================
--  1) tb_app_setting — ค่าตั้งระบบแบบ key/value
--
--  ทำไมเป็นตาราง ไม่ใช่ไฟล์ config:
--  รหัสผ่าน SMTP ต้องเปลี่ยนได้จากหน้าเว็บโดยไม่ต้องให้คนแก้ไฟล์บนเซิร์ฟเวอร์
--  และเวลาย้ายเครื่อง ค่าจะติดไปกับฐานข้อมูลเอง ไม่ต้องจำว่าต้องก๊อปไฟล์ไหนด้วย
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_app_setting` (
  `setting_key`   VARCHAR(60)   NOT NULL,
  `setting_value` VARCHAR(500)  NOT NULL DEFAULT '',
  `setting_group` VARCHAR(30)   NOT NULL DEFAULT 'general',
  `description`   VARCHAR(255)  NOT NULL DEFAULT '',

  `created_at`    DATETIME      NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`    VARCHAR(50)   NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    VARCHAR(50)   NULL DEFAULT NULL,

  PRIMARY KEY (`setting_key`),
  KEY `idx_setting_group`      (`setting_group`),
  KEY `idx_setting_created_by` (`created_by`),
  KEY `idx_setting_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ค่าตั้งระบบแบบ key/value';


-- ============================================================
--  2) tb_guide — คู่มือ 1 ชุดต่อ 1 หน้า
--
--  page_key ตรงกับค่า ?page= ใน main.php (ดู includes/menu.php)
--  ใช้ค่า '_welcome' สำหรับคู่มือแนะนำภาพรวมที่เด้งครั้งแรกสุดหลังเข้าระบบ
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_guide` (
  `guide_id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_key`    VARCHAR(50)      NOT NULL,
  `title`       VARCHAR(150)     NOT NULL DEFAULT '',
  `description` VARCHAR(500)     NOT NULL DEFAULT '',

  -- คู่มือชุดนี้เด้งเองอัตโนมัติตอนคนเข้าหน้านี้ครั้งแรกไหม
  -- 0 = เงียบไว้ ให้กดปุ่มคู่มือบนแถบบนเอง
  `auto_start`  TINYINT(1)       NOT NULL DEFAULT 1,
  `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,

  `created_at`  DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`  VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`  VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`guide_id`),
  UNIQUE KEY `uq_guide_page`    (`page_key`),
  KEY `idx_guide_active`        (`is_active`),
  KEY `idx_guide_created_by`    (`created_by`),
  KEY `idx_guide_updated_by`    (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='คู่มือการใช้งาน 1 ชุดต่อ 1 หน้า';


-- ============================================================
--  3) tb_guide_step — ขั้นตอนในคู่มือ
--
--  target_selector = CSS selector ของสิ่งที่จะไฮไลต์ เช่น '#btnSave'
--  ปล่อยว่างไว้ = ขั้นตอนนั้นเป็นกล่องข้อความกลางจอ ไม่ชี้ที่ไหน
--  (ใช้กับขั้นเปิดเรื่องและขั้นปิดท้าย)
--
--  หมายเหตุสำคัญ: ขั้นตอนที่หา element ไม่เจอบนหน้าจริง จะถูกข้ามไปเงียบ ๆ
--  โดย assets/js/app-guide.js ไม่ใช่ค้างหรือไฮไลต์มั่ว เพราะบางปุ่มขึ้นกับสิทธิ์
--  เช่น #btnExport ที่ผู้ใช้ทั่วไปไม่เห็น คู่มือชุดเดียวจึงใช้ได้ทุกสิทธิ์
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_guide_step` (
  `step_id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `guide_id`        INT(10) UNSIGNED NOT NULL,
  `step_order`      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,

  `target_selector` VARCHAR(255)     NOT NULL DEFAULT '',
  `title`           VARCHAR(150)     NOT NULL DEFAULT '',
  `description`     VARCHAR(1000)    NOT NULL DEFAULT '',

  -- ตำแหน่งกล่องคำอธิบายเทียบกับสิ่งที่ไฮไลต์
  `side`            ENUM('top','right','bottom','left','over') NOT NULL DEFAULT 'bottom',
  `align`           ENUM('start','center','end')               NOT NULL DEFAULT 'start',

  `is_active`       TINYINT(1)       NOT NULL DEFAULT 1,

  `created_at`      DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`      VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`      VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`step_id`),
  KEY `idx_step_guide`      (`guide_id`, `step_order`),
  KEY `idx_step_active`     (`is_active`),
  KEY `idx_step_created_by` (`created_by`),
  KEY `idx_step_updated_by` (`updated_by`),

  CONSTRAINT `fk_step_guide` FOREIGN KEY (`guide_id`)
      REFERENCES `tb_guide` (`guide_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_step_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_step_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ขั้นตอนในคู่มือการใช้งาน';


-- ============================================================
--  4) tb_guide_seen — ใครดูคู่มือของหน้าไหนไปแล้ว
--
--  เก็บในฐานข้อมูลไม่ใช่ localStorage เพราะพนักงานคนเดียวกันเปิดจากทั้ง
--  เครื่องที่โต๊ะและมือถือ ถ้าเก็บที่เบราว์เซอร์ คู่มือจะเด้งใหม่ทุกเครื่อง
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_guide_seen` (
  `username`   VARCHAR(50)  NOT NULL,
  `page_key`   VARCHAR(50)  NOT NULL,
  `seen_count` INT(10) UNSIGNED NOT NULL DEFAULT 1,

  `created_at` DATETIME     NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by` VARCHAR(50)  NULL DEFAULT NULL,
  `updated_at` TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(50)  NULL DEFAULT NULL,

  PRIMARY KEY (`username`, `page_key`),
  KEY `idx_seen_created_by` (`created_by`),
  KEY `idx_seen_updated_by` (`updated_by`),

  CONSTRAINT `fk_seen_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='ผู้ใช้ดูคู่มือของหน้าไหนไปแล้วบ้าง';


-- ============================================================
--  5) tb_notification — แจ้งเตือนในระบบ
--
--  1 แถว = 1 คนได้รับ 1 เรื่อง
--  ใบเบิกใบเดียวที่มีผู้อนุมัติ 3 คน จะเกิด 3 แถว ไม่ใช่แถวเดียวแล้วแตกทีหลัง
--  เพราะสถานะ "อ่านแล้ว" เป็นของแต่ละคน ไม่ใช่ของเอกสาร
--
--  link_page/link_doc_id เก็บ "ปลายทางที่จะพาไป" แยกเป็นคนละคอลัมน์
--  ไม่ใช่ URL สำเร็จรูป เพราะถ้าวันหลังเปลี่ยนรูปแบบ URL จะได้ไม่ต้อง
--  ไล่แก้ข้อมูลเก่าทั้งตาราง
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_notification` (
  `noti_id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(50)      NOT NULL COMMENT 'ผู้รับแจ้งเตือน',

  `noti_type`   ENUM('request_waiting','request_approved','request_rejected',
                     'request_cancelled','issue_created','issue_acknowledged',
                     'issue_cancelled','stock_low','system') NOT NULL DEFAULT 'system',

  `title`       VARCHAR(150)     NOT NULL DEFAULT '',
  `message`     VARCHAR(500)     NOT NULL DEFAULT '',

  `link_page`   VARCHAR(50)      NOT NULL DEFAULT '' COMMENT 'ค่า ?page= ปลายทาง',
  `link_doc_id` INT(10) UNSIGNED NULL DEFAULT NULL,

  `is_read`     TINYINT(1)       NOT NULL DEFAULT 0,
  `read_at`     DATETIME         NULL DEFAULT NULL,

  `created_at`  DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`  VARCHAR(50)      NULL DEFAULT NULL COMMENT 'คนที่ทำให้เกิดเรื่องนี้',
  `updated_at`  TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`  VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`noti_id`),

  -- index หลักของหน้าจอ: "ของฉัน ที่ยังไม่อ่าน เรียงใหม่ก่อน"
  -- กระดิ่งบนแถบบนถามคำถามนี้ทุกครั้งที่เปิดหน้า จึงต้องเร็วที่สุด
  KEY `idx_noti_inbox`      (`username`, `is_read`, `noti_id`),
  KEY `idx_noti_doc`        (`link_doc_id`),
  KEY `idx_noti_created_by` (`created_by`),
  KEY `idx_noti_updated_by` (`updated_by`),

  CONSTRAINT `fk_noti_user` FOREIGN KEY (`username`)
      REFERENCES `tb_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_noti_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_noti_updated_by` FOREIGN KEY (`updated_by`)
      REFERENCES `tb_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='แจ้งเตือนในระบบ รายผู้รับ';


-- ============================================================
--  6) tb_mail_queue — คิวอีเมลขาออก
--
--  to_email เก็บเป็นข้อความ ไม่ผูก foreign key ไปที่ tb_user
--  เพราะเมลที่ส่งไปแล้วต้องเป็นหลักฐานว่า "ส่งไปที่อยู่นี้" ถ้าวันหลัง
--  ผู้ใช้เปลี่ยนอีเมลหรือถูกลบ ประวัติต้องไม่เปลี่ยนตามไปด้วย
-- ============================================================
CREATE TABLE IF NOT EXISTS `tb_mail_queue` (
  `mail_id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,

  `to_email`    VARCHAR(150)     NOT NULL,
  `to_name`     VARCHAR(150)     NOT NULL DEFAULT '',
  `subject`     VARCHAR(255)     NOT NULL DEFAULT '',
  `body_html`   TEXT             NULL,

  `ref_type`    VARCHAR(30)      NOT NULL DEFAULT '' COMMENT 'เรื่องที่ทำให้เกิดเมลนี้',
  `ref_doc_id`  INT(10) UNSIGNED NULL DEFAULT NULL,

  `status`      ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `try_count`   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `last_error`  VARCHAR(500)     NOT NULL DEFAULT '',
  `sent_at`     DATETIME         NULL DEFAULT NULL,

  `created_at`  DATETIME         NOT NULL DEFAULT '1000-01-01 00:00:00',
  `created_by`  VARCHAR(50)      NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`  VARCHAR(50)      NULL DEFAULT NULL,

  PRIMARY KEY (`mail_id`),

  -- index หลักของตัวส่ง: "ที่ยังไม่ได้ส่ง ลองมาไม่เกิน N ครั้ง เก่าก่อน"
  KEY `idx_mail_pending`     (`status`, `try_count`, `mail_id`),
  KEY `idx_mail_doc`         (`ref_doc_id`),
  KEY `idx_mail_created_by`  (`created_by`),
  KEY `idx_mail_updated_by`  (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='คิวอีเมลขาออก';


-- ============================================================
--  ค่าตั้งต้นของระบบ
--
--  smtp_pass เว้นว่างไว้ ต้องไปกรอกที่หน้า "ตั้งค่าระบบ" เอง
--  ไม่ใส่รหัสผ่านจริงลงไฟล์ .sql เพราะไฟล์นี้อยู่ในโปรเจกต์และถูกอ่านได้ทั่วไป
--
--  mail_enabled เริ่มที่ 0 — ยังไม่ส่งเมลจริงจนกว่าจะตั้งค่าเสร็จและทดสอบผ่าน
--  แจ้งเตือนในระบบ (กระดิ่ง) ทำงานทันทีโดยไม่ขึ้นกับค่านี้
-- ============================================================
INSERT INTO `tb_app_setting`
  (`setting_key`, `setting_value`, `setting_group`, `description`, `created_at`, `created_by`)
VALUES
  ('mail_enabled',   '0',                 'mail',  'เปิดใช้งานการส่งอีเมลแจ้งเตือน (1=เปิด 0=ปิด)', NOW(), NULL),
  ('smtp_host',      'smtp.zoho.com',     'mail',  'โฮสต์ SMTP ของ Zoho Mail',                      NOW(), NULL),
  ('smtp_port',      '465',               'mail',  'พอร์ต SMTP (465=SSL, 587=TLS)',                 NOW(), NULL),
  ('smtp_secure',    'ssl',               'mail',  'วิธีเข้ารหัส: ssl หรือ tls',                    NOW(), NULL),
  ('smtp_user',      '',                  'mail',  'บัญชีอีเมลที่ใช้ส่ง',                            NOW(), NULL),
  ('smtp_pass',      '',                  'mail',  'รหัสผ่านแอป (App Password) ของ Zoho',           NOW(), NULL),
  ('mail_from',      '',                  'mail',  'อีเมลผู้ส่งที่แสดงในหัวเมล',                     NOW(), NULL),
  ('mail_from_name', 'ระบบสต็อก HR Happyland', 'mail', 'ชื่อผู้ส่งที่แสดงในหัวเมล',                NOW(), NULL),
  ('mail_max_try',   '3',                 'mail',  'ลองส่งซ้ำได้กี่ครั้งก่อนยอมแพ้',                 NOW(), NULL),
  ('site_url',       'http://localhost/stock_hr/', 'general', 'ที่อยู่ระบบ ใช้ทำลิงก์ในอีเมล',       NOW(), NULL),
  ('guide_enabled',  '1',                 'guide', 'เปิดใช้งานคู่มือการใช้งาน (1=เปิด 0=ปิด)',      NOW(), NULL);


-- ============================================================
--  คู่มือชุดเริ่มต้น
--
--  เขียนเป็น "ทำอะไรได้ที่ตรงนี้" ไม่ใช่ "นี่คือปุ่มอะไร"
--  เพราะคนอ่านคู่มือกำลังพยายามทำงานให้เสร็จ ไม่ได้อยากรู้จักชื่อปุ่ม
-- ============================================================

-- ---------- ภาพรวมระบบ ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('dashboard', 'เริ่มต้นใช้งานระบบสต็อก HR',
        'พาดูส่วนประกอบหลักของหน้าจอ และงานที่ต้องทำของคุณ', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'ยินดีต้อนรับ',
       'คู่มือนี้จะพาดูหน้าจอทีละส่วน ใช้เวลาไม่ถึงหนึ่งนาที กด &quot;ถัดไป&quot; เพื่อเริ่ม หรือกดปิดแล้วเปิดใหม่ได้ทุกเมื่อจากปุ่มเครื่องหมายคำถามบนแถบด้านบน',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#sidebar', 'เมนูหลัก',
       'ทุกหน้าของระบบอยู่ในเมนูนี้ หัวข้อที่มีลูกศรกดเพื่อกางเมนูย่อย ตัวเลขสีแดงหรือส้มท้ายเมนูคือจำนวนงานที่รอคุณอยู่',
       'right', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#notiBtn', 'กระดิ่งแจ้งเตือน',
       'มีคนส่งใบเบิกมาให้อนุมัติ หรือใบเบิกของคุณถูกตัดสินแล้ว จะขึ้นที่นี่ ตัวเลขสีแดงคือจำนวนที่ยังไม่ได้อ่าน กดที่รายการเพื่อกระโดดไปยังเอกสารใบนั้นได้ทันที',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#appGuideBtn', 'ปุ่มคู่มือการใช้งาน',
       'เปิดคู่มือของหน้าที่กำลังเปิดอยู่ได้ทุกเมื่อจากปุ่มนี้ แต่ละหน้ามีคู่มือของตัวเอง กดอีกครั้งเพื่อปิด หรือกดปุ่ม Esc ก็ได้',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#themeToggle', 'สลับโหมดสว่าง/มืด',
       'ไอคอนบอกโหมดที่จะเปลี่ยนไป ไม่ใช่โหมดปัจจุบัน ระบบจะจำค่าไว้ให้เครื่องนี้',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#bnHero', 'ตัวเลขสรุปของคุณ',
       'ตัวเลขชุดนี้เปลี่ยนตามสิทธิ์ที่คุณมี ผู้อนุมัติจะเห็นจำนวนใบที่รอตัดสิน ส่วนผู้ใช้ทั่วไปจะเห็นใบเบิกของตัวเอง',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#bnQueue', 'งานที่ต้องทำ',
       'รายการที่ค้างอยู่ที่ตัวคุณจริง ๆ กดที่แต่ละบรรทัดเพื่อเปิดเอกสารใบนั้นได้เลย ถ้าว่างเปล่าแปลว่าไม่มีงานค้าง',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'dashboard';


-- ---------- ทำรายการเบิก ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('request_new', 'วิธีทำใบเบิกสินค้า',
        'ตั้งแต่เลือกสินค้าจนกดส่งให้ผู้อนุมัติ', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '', 'เบิกของใน 4 ขั้นตอน',
       'คุณทำใบเบิก &rarr; หัวหน้าหน่วยงานอนุมัติ &rarr; คลังจ่ายของและตัดสต๊อก &rarr; คุณเซ็นรับของ ระบบจะแจ้งเตือนคุณทุกครั้งที่ใบเบิกขยับไปขั้นถัดไป',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#docDate', 'วันที่เบิก',
       'ตั้งเป็นวันนี้ให้อัตโนมัติแล้ว เปลี่ยนได้ถ้าต้องการลงวันย้อนหลัง',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#departmentId', 'หน่วยงานที่ขอเบิก',
       'ล็อกไว้ที่หน่วยงานต้นสังกัดของคุณ เพราะใบเบิกจะถูกส่งให้ผู้อนุมัติของหน่วยงานนั้น ถ้าช่องนี้ว่างและเลือกไม่ได้ แปลว่าบัญชีคุณยังไม่ได้ระบุหน่วยงาน ให้แจ้งผู้ดูแลระบบ',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#tfSearch', 'ค้นหาสินค้า',
       'พิมพ์รหัสหรือชื่อสินค้าเพื่อกรองรายการทางซ้าย ใช้ร่วมกับตัวเลือกหมวดหมู่และ &quot;เฉพาะที่มีของ&quot; ข้าง ๆ ได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 5, '#tfLeft', 'สินค้าทั้งหมด',
       'แตะที่รายการเพื่อเพิ่มเข้าใบเบิก จะมีเครื่องหมายถูกขึ้นทันที แตะซ้ำอีกครั้งเพื่อเอาออก ตัวเลขทางขวาของแต่ละแถวคือยอดคงเหลือ สีแดงคือของหมด สีส้มคือใกล้หมด',
       'right', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 6, '#tfRight', 'รายการที่จะเบิก',
       'สินค้าที่เพิ่งเลือกจะมาอยู่บนสุดเสมอ กดปุ่มลบหรือบวกเพื่อปรับจำนวน ขอเกินยอดคงเหลือได้ ระบบจะเตือนไว้เฉย ๆ เพราะกว่าจะถึงวันจ่ายของอาจเข้ามาเพิ่มแล้ว',
       'left', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 7, '#docNote', 'หมายเหตุ',
       'เขียนเหตุผลที่ขอเบิกไว้ตรงนี้ ผู้อนุมัติจะเห็นข้อความนี้ตอนตัดสิน ยิ่งชัดยิ่งอนุมัติเร็ว',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 8, '#btnSave', 'ส่งใบเบิก',
       'กดแล้วใบเบิกจะถูกส่งให้ผู้อนุมัติของหน่วยงานคุณทันที ทั้งกระดิ่งแจ้งเตือนและอีเมล ตอนนี้ยังไม่มีการตัดสต๊อก ของจะถูกตัดตอนคลังจ่ายจริงเท่านั้น',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'request_new';


-- ---------- รายการใบเบิก ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('request_list', 'ติดตามและอนุมัติใบเบิก',
        'ดูสถานะใบเบิก และวิธีอนุมัติสำหรับผู้อนุมัติ', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ตารางใบเบิก',
       'ผู้ใช้ทั่วไปเห็นเฉพาะใบของตัวเอง ผู้อนุมัติเห็นใบของหน่วยงานตัวเอง กดที่แถวใดก็ได้เพื่อเปิดรายละเอียดพร้อมรายการสินค้าในใบนั้น',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#gridSearch', 'ค้นหาในตาราง',
       'พิมพ์เลขที่ใบเบิก ชื่อผู้ขอ หรือหน่วยงาน เพื่อกรองแถวที่แสดง',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'request_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#fpToggle', 'ตัวกรอง',
       'เปิดแถบกรองด้านขวา เลือกสถานะและช่วงวันที่ได้ ตัวเลขบนปุ่มบอกว่ากำลังกรองอยู่กี่เงื่อนไข',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'request_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '', 'ปุ่มอนุมัติอยู่ตรงไหน',
       'เปิดรายละเอียดใบเบิกที่สถานะ &quot;รออนุมัติ&quot; แล้วปุ่ม &quot;อนุมัติ&quot; กับ &quot;ไม่อนุมัติ&quot; จะอยู่ท้ายกล่อง เห็นเฉพาะคนที่มีสิทธิ์อนุมัติของหน่วยงานนั้น และอนุมัติใบของตัวเองไม่ได้',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'request_list';


-- ---------- บันทึกรับเข้า ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('receive_new', 'วิธีบันทึกรับสินค้าเข้า',
        'บันทึกของที่เข้าคลัง พร้อมราคาต่อหน่วย', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#docDate', 'วันที่รับเข้า',
       'วันที่ของจริงเข้าคลัง มีผลกับรายงานค่าใช้จ่ายรายเดือน',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#items', 'รายการสินค้าที่รับเข้า',
       'เลือกสินค้า ใส่จำนวนและราคาต่อหน่วย ถ้าสินค้าตัวเดิมเคยรับเข้ามาแล้วคนละราคา ระบบจะคิดต้นทุนแบบถัวเฉลี่ยเคลื่อนที่ให้เอง ไม่ต้องคำนวณเอง',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnAddRow', 'เพิ่มบรรทัด',
       'กดเพื่อเพิ่มสินค้าอีกรายการในใบเดียวกัน ของที่มาพร้อมกันควรอยู่ใบเดียวกัน จะได้ตรวจย้อนหลังง่าย',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnSave', 'บันทึกรับเข้า',
       'กดแล้วสต๊อกจะเพิ่มทันที ถ้าบันทึกผิดให้ยกเลิกใบนั้นจากหน้าประวัติ ระบบจะคืนตัวเลขให้เอง อย่าแก้ตัวเลขตรง ๆ',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'receive_new';


-- ---------- บันทึกจ่ายออก ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('issue_new', 'วิธีจ่ายสินค้าออก',
        'จ่ายของตามใบเบิกที่อนุมัติแล้ว', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#refDocId', 'เลือกใบเบิก',
       'เลือกได้เฉพาะใบที่ผ่านการอนุมัติแล้วและยังจ่ายไม่ครบ พอเลือกแล้วรายการสินค้าจะถูกเติมให้อัตโนมัติ จ่ายของนอกใบเบิกไม่ได้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'issue_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#items', 'จำนวนที่จ่ายจริง',
       'ลดจำนวนลงได้ถ้าของไม่พอ ส่วนที่เหลือจะค้างอยู่ในใบเบิกให้จ่ายรอบหน้า แต่จ่ายเกินที่ขอไม่ได้',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'issue_new';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnSave', 'บันทึกจ่ายออก',
       'กดแล้วสต๊อกถูกตัดทันทีและระบบจะแจ้งผู้ขอเบิกให้มาเซ็นรับของ ต้นทุนต่อหน่วยจะถูกแช่แข็งไว้ที่ค่าเฉลี่ย ณ วินาทีนี้ รายงานย้อนหลังจึงได้ตัวเลขเดิมเสมอ',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'issue_new';


-- ---------- ประวัติการจ่ายออก ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('issue_history', 'เซ็นรับของและดูประวัติการจ่าย',
        'ขั้นสุดท้ายของเส้นทางเบิกของ', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ใบจ่ายทั้งหมด',
       'กดที่แถวเพื่อเปิดรายละเอียด ใบที่คุณเป็นผู้ขอเบิกและยังไม่ได้เซ็นรับ จะมีปุ่ม &quot;เซ็นรับของ&quot; อยู่ท้ายกล่องรายละเอียด',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'issue_history';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'ทำไมต้องเซ็นรับ',
       'การเซ็นรับคือหลักฐานว่าของถึงมือผู้เบิกจริง ตราบใดที่ยังไม่เซ็น ใบนั้นจะค้างอยู่ในงานของคุณและมีตัวเลขเตือนอยู่ที่เมนู',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'issue_history';


-- ---------- รายการสินค้า ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('product_list', 'จัดการข้อมูลสินค้า',
        'ตารางแบบแก้ในช่องได้ และวิธีบันทึก', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'แก้ไขในช่องได้เลย',
       'กดที่ช่องแล้วพิมพ์แก้ได้ทันที ช่องที่แก้แล้วจะเปลี่ยนสีให้เห็นว่ายังไม่ได้บันทึก',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#btnAddRows', 'เพิ่มสินค้าใหม่',
       'เลือกจำนวนแถวที่จะเพิ่มแล้วกด รหัสสินค้าจะถูกออกให้อัตโนมัติตามหมวดหมู่ย่อยที่เลือก',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#pendingBar', 'แถบรายการที่ยังไม่บันทึก',
       'บอกว่ามีกี่แถวที่แก้ค้างไว้ ปิดหน้าไปตอนนี้จะหายหมด ต้องกดบันทึกก่อน',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'product_list';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#btnSave', 'บันทึกทั้งหมดทีเดียว',
       'ส่งทุกแถวที่แก้ไปพร้อมกัน ถ้าแถวไหนข้อมูลไม่ครบ ระบบจะทำเครื่องหมายไว้ที่ช่องนั้นและบอกว่าต้องแก้อะไร',
       'top', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'product_list';


-- ---------- ยอดคงเหลือ ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('report_stock', 'อ่านรายงานยอดคงเหลือ',
        'ดูของในคลังและส่งออกเป็นไฟล์', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ยอดคงเหลือปัจจุบัน',
       'ตัวเลขนี้มาจากการเคลื่อนไหวจริงทุกครั้ง ไม่ได้พิมพ์เข้าไปตรง ๆ จึงตรงกับของบนชั้นเสมอถ้าบันทึกครบ',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'report_stock';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#fpToggle', 'ตัวกรอง',
       'กรองตามหมวดหมู่หรือสถานะของ เช่น ดูเฉพาะที่ใกล้หมด',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'report_stock';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnExport', 'ส่งออกเป็น Excel',
       'ได้ไฟล์ .xlsx ตามเงื่อนไขที่กรองอยู่ตอนนั้น ไม่ใช่ทั้งตาราง',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'report_stock';


-- ---------- ต่ำกว่าจุดสั่งซื้อ ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('report_low', 'สินค้าที่ถึงจุดต้องสั่งซื้อ',
        'รู้ล่วงหน้าว่าต้องสั่งอะไรเพิ่ม', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'รายการที่ต้องสั่งเพิ่ม',
       'แสดงเฉพาะสินค้าที่ตั้งจุดสั่งซื้อต่ำสุดไว้แล้ว และยอดคงเหลือลงมาถึงหรือต่ำกว่าจุดนั้น สินค้าที่ยังไม่ได้ตั้งจุดสั่งซื้อจะไม่ขึ้นที่นี่',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'report_low';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'ตั้งจุดสั่งซื้อได้ที่ไหน',
       'ไปที่เมนู ข้อมูลสินค้า &rarr; รายการสินค้า แล้วกรอกช่อง &quot;จุดสั่งซื้อต่ำสุด&quot; ของสินค้าตัวนั้น ใส่ 0 แปลว่าไม่ต้องเฝ้า',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'report_low';


-- ---------- ค่าใช้จ่ายรายเดือน ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('report_cost', 'รายงานค่าใช้จ่ายสำหรับบัญชี',
        'สรุปรายเดือนแบบส่งให้บัญชีได้เลย', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#rcMonth', 'เลือกเดือน',
       'เลือกเดือนที่ต้องการปิดยอด รายการทั้งหน้าจะเปลี่ยนตาม',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'report_cost';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#tblRecv', 'กรณีรับเข้าหลายราคาในเดือนเดียว',
       'ถ้าสินค้าตัวเดียวกันรับเข้ามาคนละราคา ตารางนี้จะแยกเป็นคนละบรรทัดให้เห็นทุกราคา ส่วนค่าใช้จ่ายที่ตัดออกคิดแบบถัวเฉลี่ยเคลื่อนที่ ผลรวมจึงตรงกับเงินที่จ่ายซื้อจริงเสมอ',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'report_cost';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnPrint', 'พิมพ์หรือบันทึกเป็น PDF',
       'จัดหน้าไว้ให้พิมพ์ได้พอดีแล้ว เลือกปลายทางเป็น &quot;บันทึกเป็น PDF&quot; ในหน้าต่างพิมพ์เพื่อส่งเมลให้บัญชี',
       'bottom', 'end', NOW() FROM `tb_guide` WHERE `page_key` = 'report_cost';


-- ---------- ผู้ใช้งาน ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('users', 'จัดการผู้ใช้งาน',
        'เพิ่มบัญชี กำหนดสิทธิ์และหน่วยงาน', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#grid', 'ตารางผู้ใช้',
       'แก้ในช่องได้เหมือนหน้าอื่น แต่บัญชีผู้ดูแลระบบสูงสุดจะแก้จากหน้านี้ไม่ได้ เจ้าของบัญชีต้องเปลี่ยนรหัสผ่านเองที่หน้า &quot;เปลี่ยนรหัสผ่าน&quot;',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'users';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '', 'หน่วยงานเป็นช่องที่ห้ามลืม',
       'ผู้ใช้ที่ไม่มีหน่วยงานจะทำใบเบิกไม่ได้ และผู้อนุมัติที่ไม่มีหน่วยงานจะอนุมัติอะไรไม่ได้เลย เพราะระบบใช้หน่วยงานเป็นตัวจับคู่ว่าใบเบิกใบไหนไปหาใคร',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'users';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '', 'อีเมลใช้ทำอะไร',
       'ระบบส่งอีเมลแจ้งเตือนไปตามที่อยู่ในช่องนี้ ถ้าเว้นว่างไว้ คนนั้นจะได้รับแจ้งเตือนแค่ในระบบ (กระดิ่ง) ไม่ได้รับอีเมล',
       'over', 'center', NOW() FROM `tb_guide` WHERE `page_key` = 'users';


-- ---------- เปลี่ยนรหัสผ่าน ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('change_password', 'เปลี่ยนรหัสผ่านของคุณ',
        'ควรเปลี่ยนทันทีหลังได้รับบัญชีใหม่', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#currentPassword', 'รหัสผ่านปัจจุบัน',
       'กรอกรหัสที่ใช้เข้าระบบอยู่ตอนนี้ ถ้าเป็นบัญชีที่เพิ่งได้รับมา ให้ใช้รหัสตั้งต้นที่ผู้ดูแลระบบให้ไว้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'change_password';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#newPassword', 'รหัสผ่านใหม่',
       'แถบด้านล่างช่องจะบอกความแข็งแรงของรหัสที่พิมพ์ ยาวกว่าดีกว่าซับซ้อน',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'change_password';


-- ---------- ตั้งค่าระบบ ----------
INSERT INTO `tb_guide` (`page_key`, `title`, `description`, `auto_start`, `is_active`, `created_at`)
VALUES ('settings', 'ตั้งค่าการแจ้งเตือนทางอีเมล',
        'ตั้งค่า Zoho Mail และดูคิวเมลขาออก', 1, 1, NOW());

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 1, '#mailEnabled', 'สวิตช์เปิด/ปิดอีเมล',
       'ปิดไว้ = ระบบยังสร้างแจ้งเตือนในกระดิ่งตามปกติ แต่ไม่ส่งอีเมลออกไป เหมาะกับตอนกำลังทดสอบระบบ',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'settings';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 2, '#smtpPass', 'ต้องใช้รหัสผ่านแอป ไม่ใช่รหัสเข้า Zoho',
       'สร้างที่ Zoho Mail &rarr; Settings &rarr; Security &rarr; App Passwords แล้วเอารหัสที่ได้มาใส่ตรงนี้ ใช้รหัสผ่านปกติจะถูกปฏิเสธถ้าเปิดยืนยันสองชั้นไว้',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'settings';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 3, '#btnTestMail', 'ทดสอบก่อนเปิดใช้จริง',
       'ส่งเมลทดสอบหนึ่งฉบับไปที่อีเมลที่ระบุ ถ้าไม่ผ่านระบบจะบอกสาเหตุจาก SMTP ตรง ๆ',
       'bottom', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'settings';

INSERT INTO `tb_guide_step`
  (`guide_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`, `created_at`)
SELECT `guide_id`, 4, '#mailQueue', 'คิวเมลขาออก',
       'เมลทุกฉบับเข้าคิวก่อนเสมอ แล้วตัวส่งค่อยมาหยิบไป ฉบับที่ส่งไม่ผ่านจะเก็บสาเหตุไว้และลองใหม่ให้ กดปุ่มส่งคิวเพื่อสั่งส่งเดี๋ยวนี้',
       'top', 'start', NOW() FROM `tb_guide` WHERE `page_key` = 'settings';


-- ============================================================
--  ตรวจผลลัพธ์
-- ============================================================
SELECT `TABLE_NAME`, `ENGINE`, `TABLE_COLLATION`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('tb_app_setting', 'tb_guide', 'tb_guide_step',
                       'tb_guide_seen', 'tb_notification', 'tb_mail_queue');

SELECT g.`page_key`, g.`title`, COUNT(s.`step_id`) AS `จำนวนขั้นตอน`
FROM `tb_guide` g
LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id`
GROUP BY g.`guide_id`, g.`page_key`, g.`title`
ORDER BY g.`page_key`;

SELECT `setting_group`, COUNT(*) AS `จำนวนค่าตั้ง`
FROM `tb_app_setting` GROUP BY `setting_group`;
