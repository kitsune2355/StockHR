<?php
/* ============================================================
 *  api/settings.php — endpoint ของหน้าตั้งค่าระบบ
 *
 *    { "action": "get" }                       ค่าตั้งทั้งหมด + สถานะคิวเมล
 *    { "action": "save",  "values": {...} }    บันทึกค่าตั้ง
 *    { "action": "test",  "to": "a@b.com" }    ส่งเมลทดสอบทันที
 *    { "action": "queue", "status": "failed" } รายการในคิวเมล
 *    { "action": "flush", "limit": 20 }        สั่งส่งคิวเดี๋ยวนี้
 *    { "action": "retry" }                     เอาฉบับที่ล้มเหลวกลับเข้าคิว
 *
 *  ทั้งไฟล์จำกัดเฉพาะผู้ดูแลระบบ เพราะที่นี่มีรหัสผ่าน SMTP
 *
 *  ---------------------------------------------------------------
 *  รหัสผ่าน SMTP ไม่เคยถูกส่งกลับออกไปหาหน้าเว็บ
 *  ---------------------------------------------------------------
 *  action "get" ส่งกลับแค่ว่า "ตั้งไว้แล้วหรือยัง" (has_pass) ไม่ใช่ตัวรหัส
 *  เพราะรหัสที่ถูกส่งออกไปครั้งหนึ่ง จะไปอยู่ในแคชเบราว์เซอร์ ใน DevTools
 *  และในบันทึก proxy ขององค์กร ทั้งที่หน้าจอไม่จำเป็นต้องรู้ค่านี้เลย
 *
 *  ตอนบันทึก ถ้าช่องรหัสผ่านถูกส่งมาเป็นค่าว่าง = ไม่เปลี่ยน ใช้ของเดิมต่อ
 *  ไม่ใช่ล้างรหัสทิ้ง ไม่งั้นทุกครั้งที่แก้แค่ชื่อผู้ส่ง รหัสจะหายไปด้วย
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/setting.php';
require_once dirname(dirname(__FILE__)) . '/backend/mailer.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* คีย์ที่แก้ได้จากหน้าเว็บ — กันคนยิง JSON เข้ามาสร้างคีย์มั่วหรือแก้คีย์ที่ไม่ควรแก้ */
$EDITABLE = array(
    'mail_enabled', 'smtp_host', 'smtp_port', 'smtp_secure',
    'smtp_user', 'smtp_pass', 'mail_from', 'mail_from_name', 'mail_max_try',
    'site_url', 'guide_enabled'
);


/* ============================================================
 *  ค่าตั้งทั้งหมด
 * ============================================================ */
if ($action === 'get') {

    $mail = setting_group('mail');
    $has_pass = (isset($mail['smtp_pass']) && $mail['smtp_pass'] !== '');
    unset($mail['smtp_pass']);          // ห้ามหลุดออกไปเด็ดขาด

    api_ok(array(
        'mail'      => $mail,
        'hasPass'   => $has_pass,
        'general'   => setting_group('general'),
        'guide'     => setting_group('guide'),
        'queue'     => mail_queue_summary(),
        'env'       => mail_env_check(),
        'config'    => mail_config_check()
    ));
}


/* ============================================================
 *  บันทึกค่าตั้ง
 * ============================================================ */
if ($action === 'save') {

    api_require_csrf($input);

    $values = isset($input['values']) && is_array($input['values']) ? $input['values'] : array();

    /* ช่องรหัสผ่านว่าง = ไม่แตะของเดิม (ดูเหตุผลที่หัวไฟล์) */
    if (isset($values['smtp_pass']) && trim($values['smtp_pass']) === '') {
        unset($values['smtp_pass']);
    }

    /* ค่าที่ต้องเป็นตัวเลือกจำกัด ตรวจที่นี่ก่อนลงตาราง
       ไม่งั้นพิมพ์ผิดเป็น "SSL " แล้วจะไปเจอปัญหาตอนส่งเมลจริง ซึ่งดีบักยากกว่ามาก */
    if (isset($values['smtp_secure'])) {
        $sec = strtolower(trim($values['smtp_secure']));
        if (!in_array($sec, array('ssl', 'tls'))) {
            api_fail('วิธีเข้ารหัสต้องเป็น ssl หรือ tls เท่านั้น');
        }
        $values['smtp_secure'] = $sec;
    }

    if (isset($values['smtp_port'])) {
        $port = (int)$values['smtp_port'];
        if ($port < 1 || $port > 65535) {
            api_fail('พอร์ต SMTP ต้องอยู่ระหว่าง 1 ถึง 65535');
        }
        $values['smtp_port'] = (string)$port;
    }

    if (isset($values['smtp_user']) && trim($values['smtp_user']) !== ''
        && !mail_valid_address($values['smtp_user'])) {
        api_fail('บัญชีอีเมลที่ใช้ส่งไม่ถูกต้องตามรูปแบบอีเมล');
    }

    if (isset($values['mail_from']) && trim($values['mail_from']) !== ''
        && !mail_valid_address($values['mail_from'])) {
        api_fail('อีเมลผู้ส่งไม่ถูกต้องตามรูปแบบอีเมล');
    }

    /* เปิดสวิตช์ส่งเมลได้ก็ต่อเมื่อค่าตั้งครบจริง
       ไม่งั้นจะเปิดไว้แล้วเมลกองอยู่ในคิวโดยไม่มีใครรู้ว่าทำไมไม่ออก */
    if (isset($values['mail_enabled']) && $values['mail_enabled'] === '1') {
        $probe = array_merge(setting_group('mail'), $values);
        if (trim($probe['smtp_host']) === '' || trim($probe['smtp_user']) === ''
            || trim($probe['smtp_pass']) === '') {
            api_fail('เปิดการส่งอีเมลไม่ได้ — ต้องกรอกโฮสต์ บัญชีอีเมล และรหัสผ่านแอปให้ครบก่อน');
        }
    }

    $res = setting_set_many($values, $actor, $EDITABLE);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  ส่งเมลทดสอบ
 * ============================================================ */
if ($action === 'test') {

    api_require_csrf($input);

    $to = isset($input['to']) ? trim($input['to']) : '';
    if ($to === '') {
        api_fail('กรุณาระบุอีเมลปลายทางที่จะทดสอบ');
    }

    $res = mail_send_test($to, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'queue' => mail_queue_summary()));
}


/* ============================================================
 *  คิวเมล
 * ============================================================ */
if ($action === 'queue') {

    api_ok(array(
        'items'   => mail_queue_list(array(
                        'status' => isset($input['status']) ? $input['status'] : '',
                        'limit'  => isset($input['limit'])  ? (int)$input['limit'] : 50
                     )),
        'summary' => mail_queue_summary()
    ));
}


/* ============================================================
 *  สั่งส่งคิวเดี๋ยวนี้
 * ============================================================ */
if ($action === 'flush') {

    api_require_csrf($input);

    $res = mail_dispatch(isset($input['limit']) ? (int)$input['limit'] : 20, $actor);

    api_ok(array(
        'message' => $res['message'],
        'sent'    => $res['sent'],
        'failed'  => $res['failed'],
        'queue'   => mail_queue_summary()
    ));
}


/* ============================================================
 *  เอาฉบับที่ล้มเหลวกลับเข้าคิว
 * ============================================================ */
if ($action === 'retry') {

    api_require_csrf($input);

    $res = mail_retry_failed($actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'queue' => mail_queue_summary()));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
