<?php
/* ============================================================
 *  backend/mailer.php — คิวอีเมลขาออก + ตัวส่งผ่าน SMTP ของ Zoho Mail
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องมีคิว ไม่ส่งทันทีตอนกดปุ่ม
 *  ---------------------------------------------------------------
 *  การต่อ SMTP ออกอินเทอร์เน็ตใช้เวลา 1-5 วินาที ถ้าส่งคาไว้ในจังหวะที่
 *  ผู้ใช้กด "บันทึกใบเบิก" หน้าจอจะค้างรอเมลทุกครั้ง และถ้า Zoho ล่ม
 *  หรือเน็ตหลุด การบันทึกใบเบิกจะพังตามไปด้วยทั้งที่เป็นคนละเรื่องกัน
 *
 *  เขียนลงคิวใช้เวลาไม่ถึงหนึ่งมิลลิวินาที แล้วให้ตัวส่งมาหยิบไปทีหลัง
 *  ส่งไม่ผ่านก็ลองใหม่ได้ ไม่มีเมลตกหายและไม่มีใครต้องรอ
 *
 *  ตัวส่งถูกเรียกได้ 3 ทาง:
 *    1. cron/mail_worker.php   ตั้ง Task Scheduler ให้วิ่งทุก 1-5 นาที
 *    2. ปุ่ม "ส่งคิวเดี๋ยวนี้" ที่หน้าตั้งค่าระบบ
 *    3. mail_dispatch() เรียกตรงจากสคริปต์ใด ๆ
 *
 *  ---------------------------------------------------------------
 *  ข้อกำหนดของเครื่อง
 *  ---------------------------------------------------------------
 *  Zoho บังคับให้ต่อแบบเข้ารหัสเท่านั้น (465 = SSL, 587 = STARTTLS)
 *  PHP จึงต้องเปิดส่วนขยาย openssl ไว้ ถ้ายังไม่เปิด mail_env_check()
 *  จะบอกวิธีเปิดให้ชัด ๆ แทนที่จะปล่อยให้เจอ error ดิบจาก socket
 *
 *  เขียนให้รองรับ PHP 5.2.6 (ใช้ PHPMailer 5.0.2 ที่ vendor ไว้ในโปรเจกต์)
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/setting.php';


/* ---------- ที่อยู่ของ PHPMailer ---------- */
function mail_lib_dir()
{
    return dirname(dirname(__FILE__)) . '/PHPMailer_v5.0.2/';
}


/* ---------- ค่าเชื่อมต่อ SMTP ที่ตั้งไว้ ---------- */
function mail_config()
{
    $c = setting_group('mail');

    $host = isset($c['smtp_host']) ? trim($c['smtp_host']) : '';
    $user = isset($c['smtp_user']) ? trim($c['smtp_user']) : '';
    $from = isset($c['mail_from']) ? trim($c['mail_from']) : '';

    return array(
        'enabled'   => (isset($c['mail_enabled']) && $c['mail_enabled'] === '1'),
        'host'      => $host,
        'port'      => isset($c['smtp_port'])   ? (int)$c['smtp_port'] : 465,
        'secure'    => isset($c['smtp_secure']) ? strtolower(trim($c['smtp_secure'])) : 'ssl',
        'user'      => $user,
        'pass'      => isset($c['smtp_pass'])   ? $c['smtp_pass'] : '',
        /* ไม่ได้ตั้งอีเมลผู้ส่งไว้ ก็ใช้บัญชีที่ล็อกอิน SMTP นั่นแหละ
           Zoho ปฏิเสธเมลที่ From ไม่ตรงกับบัญชีที่ยืนยันตัวตนอยู่แล้ว */
        'from'      => ($from !== '') ? $from : $user,
        'from_name' => isset($c['mail_from_name']) ? $c['mail_from_name'] : 'ระบบสต็อกธุรการ',
        'max_try'   => isset($c['mail_max_try']) ? (int)$c['mail_max_try'] : 3
    );
}


/* ---------- เครื่องนี้ส่งเมลได้จริงไหม ----------
 * แยกออกมาเป็นฟังก์ชันของตัวเอง เพราะปัญหาที่เจอบ่อยที่สุดคือ
 * "ตั้งค่าถูกหมดแล้วแต่ส่งไม่ออก" ซึ่งสาเหตุจริงอยู่ที่ php.ini ไม่ใช่ที่ค่าตั้ง
 * ถ้าไม่บอกตรงนี้ ผู้ใช้จะเห็นแค่ข้อความ error ดิบจาก socket ที่อ่านไม่รู้เรื่อง
 */
function mail_env_check()
{
    if (!class_exists('PHPMailer', false) && !file_exists(mail_lib_dir() . 'class.phpmailer.php')) {
        return array('ok' => false,
            'message' => 'ไม่พบไลบรารี PHPMailer ที่โฟลเดอร์ PHPMailer_v5.0.2/');
    }

    $transports = function_exists('stream_get_transports') ? stream_get_transports() : array();

    if (!in_array('ssl', $transports) && !in_array('tls', $transports)) {
        return array('ok' => false,
            'message' => 'PHP บนเครื่องนี้ยังต่อแบบเข้ารหัสไม่ได้ (ไม่มี transport ssl/tls) '
                       . 'ซึ่ง Zoho Mail บังคับใช้ วิธีแก้: เปิดไฟล์ ' . php_ini_loaded_file()
                       . ' หาบรรทัด ;extension=php_openssl.dll แล้วลบเครื่องหมาย ; ข้างหน้าออก '
                       . 'จากนั้นรีสตาร์ต Apache แล้วลองใหม่');
    }

    return array('ok' => true, 'message' => 'พร้อมส่งเมล');
}


/* ---------- ตรวจว่าค่าตั้งครบพอจะส่งได้ไหม ---------- */
function mail_config_check()
{
    $cfg = mail_config();

    if ($cfg['host'] === '') { return array('ok' => false, 'message' => 'ยังไม่ได้ตั้งโฮสต์ SMTP'); }
    if ($cfg['user'] === '') { return array('ok' => false, 'message' => 'ยังไม่ได้ตั้งบัญชีอีเมลที่ใช้ส่ง'); }
    if ($cfg['pass'] === '') {
        return array('ok' => false,
            'message' => 'ยังไม่ได้ตั้งรหัสผ่านแอป (App Password) ของ Zoho');
    }
    if ($cfg['port'] <= 0) { return array('ok' => false, 'message' => 'พอร์ต SMTP ไม่ถูกต้อง'); }

    return array('ok' => true, 'message' => 'ค่าตั้งครบ');
}


/* ---------- ที่อยู่อีเมลใช้ได้ไหม ----------
 * filter_var มีตั้งแต่ PHP 5.2 แต่ FILTER_VALIDATE_EMAIL ของรุ่นนี้หลวมมาก
 * จึงตรวจด้วย regex ง่าย ๆ ที่ครอบเคสจริงได้พอ แทนที่จะเชื่อ filter_var อย่างเดียว
 */
function mail_valid_address($email)
{
    $email = trim((string)$email);
    if ($email === '' || db_text_length($email) > 150) { return false; }
    return preg_match('/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/', $email) ? true : false;
}


/* ============================================================
 *  คิว
 * ============================================================ */

/* ---------- เขียนเมลหนึ่งฉบับลงคิว ----------
 * คืน true/false เฉย ๆ — เหมือน notify_push คือห้ามทำให้งานหลักพัง
 *
 * ที่อยู่ที่ใช้ไม่ได้ (ว่าง หรือรูปแบบผิด) จะถูกทิ้งเงียบ ๆ ตรงนี้
 * ผู้ใช้ที่ยังไม่ได้กรอกอีเมลจึงไม่ทำให้เกิดแถวขยะที่ส่งไม่ออกสะสมในคิว
 */
function mail_enqueue($to_email, $to_name, $subject, $body_html,
                      $ref_type = '', $ref_doc_id = null, $actor = null)
{
    if (!mail_valid_address($to_email)) { return false; }

    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_mail_queue`
            (`to_email`, `to_name`, `subject`, `body_html`,
             `ref_type`, `ref_doc_id`, `status`, `created_at`, `created_by`)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
    if (!$stmt) { return false; }

    $to_email = trim($to_email);
    $to_name  = notify_clip_safe($to_name, 150);
    $subject  = notify_clip_safe($subject, 255);
    $doc_id   = ($ref_doc_id === null || (int)$ref_doc_id <= 0) ? null : (int)$ref_doc_id;

    mysqli_stmt_bind_param($stmt, 'sssssis',
        $to_email, $to_name, $subject, $body_html, $ref_type, $doc_id, $actor);

    $ok = @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok ? true : false;
}

/* ตัดข้อความแบบไม่พึ่ง backend/notify.php (ไฟล์นี้ถูกเรียกเดี่ยว ๆ จาก cron ได้) */
function notify_clip_safe($text, $max)
{
    $text = trim((string)$text);
    if (db_text_length($text) <= $max) { return $text; }
    if (function_exists('mb_substr')) { return mb_substr($text, 0, $max - 1, 'UTF-8') . '…'; }
    return preg_replace('/[\x80-\xBF]+$/', '', substr($text, 0, $max - 1)) . '…';
}


/* ---------- รายการในคิว (หน้าตั้งค่าระบบเอาไปแสดง) ---------- */
function mail_queue_list($options = array())
{
    $status = isset($options['status']) ? $options['status'] : '';
    $limit  = isset($options['limit'])  ? (int)$options['limit'] : 50;
    if ($limit < 1)   { $limit = 1; }
    if ($limit > 500) { $limit = 500; }

    $connect = db_connect();

    $sql = "SELECT `mail_id`, `to_email`, `to_name`, `subject`, `ref_type`, `ref_doc_id`,
                   `status`, `try_count`, `last_error`, `sent_at`, `created_at`
            FROM `tb_mail_queue`";
    $types  = '';
    $values = array();

    if (in_array($status, array('pending', 'sending', 'sent', 'failed'))) {
        $sql .= " WHERE `status` = ?";
        $types    = 's';
        $values[] = $status;
    }
    $sql .= " ORDER BY `mail_id` DESC LIMIT " . $limit;

    $stmt = @mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, $values[0]);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $id, $to_email, $to_name, $subject, $ref_type, $ref_doc_id,
        $st, $try_count, $last_error, $sent_at, $created_at);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'mail_id'    => (int)$id,
            'to_email'   => $to_email,
            'to_name'    => $to_name,
            'subject'    => $subject,
            'ref_type'   => $ref_type,
            'ref_doc_id' => ($ref_doc_id === null) ? 0 : (int)$ref_doc_id,
            'status'     => $st,
            'status_label' => mail_status_label($st),
            'try_count'  => (int)$try_count,
            'last_error' => $last_error,
            'sent_at'    => ($sent_at === null) ? '' : $sent_at,
            'created_at' => $created_at
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

function mail_status_label($status)
{
    $map = array(
        'pending' => 'รอส่ง',
        'sending' => 'กำลังส่ง',
        'sent'    => 'ส่งแล้ว',
        'failed'  => 'ส่งไม่สำเร็จ'
    );
    return isset($map[$status]) ? $map[$status] : $status;
}


/* ---------- จำนวนในคิวแยกตามสถานะ ---------- */
function mail_queue_summary()
{
    $out = array('pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0);

    $connect = db_connect();
    $result  = @mysqli_query($connect,
        "SELECT `status`, COUNT(*) AS `n` FROM `tb_mail_queue` GROUP BY `status`");
    if (!$result) { return $out; }

    while ($row = mysqli_fetch_assoc($result)) {
        $out[$row['status']] = (int)$row['n'];
    }
    return $out;
}


/* ---------- ให้ฉบับที่ส่งไม่สำเร็จกลับมาเข้าคิวใหม่ ----------
 * ใช้หลังแก้ค่าตั้ง SMTP ให้ถูกแล้ว จะได้ไม่ต้องรอให้เกิดเมลใบใหม่
 */
function mail_retry_failed($actor)
{
    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_mail_queue`
         SET `status` = 'pending', `try_count` = 0, `last_error` = '', `updated_by` = ?
         WHERE `status` = 'failed'");
    if (!$stmt) { return db_fail('สั่งส่งซ้ำไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 's', $actor);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return db_ok('นำเมลที่ส่งไม่สำเร็จ ' . (int)$n . ' ฉบับกลับเข้าคิวแล้ว', $n);
}


/* ============================================================
 *  ตัวส่ง
 * ============================================================ */

/* ---------- สร้างวัตถุ PHPMailer ที่ตั้งค่าครบแล้ว ----------
 * แยกออกมาเพราะทั้งการส่งจากคิวและการส่งเมลทดสอบใช้ตัวเดียวกัน
 * คืน null ถ้าโหลดไลบรารีไม่ได้
 */
function mail_build($cfg)
{
    $dir = mail_lib_dir();

    if (!class_exists('PHPMailer', false)) {
        if (!file_exists($dir . 'class.phpmailer.php')) { return null; }
        require_once $dir . 'class.phpmailer.php';
    }

    $mail = new PHPMailer();

    /* PHPMailer 5.0.2 หา class.smtp.php จาก PluginDir ซึ่งค่าเริ่มต้นเป็นค่าว่าง
       แปลว่ามันจะไปหาที่โฟลเดอร์ปัจจุบันของสคริปต์ที่เรียก ซึ่งไม่ใช่ที่นี่
       ต้องชี้ให้ตรง ไม่งั้นพอถึงจังหวะต่อ SMTP จริงจะ fatal error */
    $mail->PluginDir = $dir;
    $mail->SetLanguage('en', $dir . 'language/');

    $mail->IsSMTP();
    $mail->Host       = $cfg['host'];
    $mail->Port       = $cfg['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['pass'];
    $mail->SMTPSecure = ($cfg['secure'] === 'tls') ? 'tls' : 'ssl';

    /* ต้องตั้งก่อนใส่เนื้อหา ไม่งั้นหัวเรื่องภาษาไทยจะถูกเข้ารหัสด้วยชุดอักขระผิด
       แล้วกลายเป็นตัวยึกยือในกล่องจดหมายปลายทาง */
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';

    /* เครือข่ายในไทยไปหา Zoho บางช่วงช้า 10 วินาทีคือรอนานพอแล้ว
       ตัวส่งวิ่งอยู่เบื้องหลัง ไม่มีใครนั่งรอหน้าจอ แต่ก็ไม่ควรค้างยาว */
    $mail->Timeout  = 10;

    $mail->SetFrom($cfg['from'], $cfg['from_name']);

    return $mail;
}


/* ---------- ส่งเมลหนึ่งฉบับออกไปจริง ๆ ----------
 * คืน array('ok' => bool, 'message' => string)
 * ไม่แตะตาราง — ผู้เรียกเป็นคนบันทึกผล
 */
function mail_send_raw($to_email, $to_name, $subject, $body_html)
{
    $env = mail_env_check();
    if (!$env['ok']) { return $env; }

    $chk = mail_config_check();
    if (!$chk['ok']) { return $chk; }

    $cfg  = mail_config();
    $mail = mail_build($cfg);
    if ($mail === null) {
        return array('ok' => false, 'message' => 'โหลดไลบรารี PHPMailer ไม่สำเร็จ');
    }

    $mail->AddAddress($to_email, $to_name);
    $mail->Subject = $subject;
    $mail->MsgHTML($body_html);

    /* PHPMailer 5.0.2 คืน false พร้อมข้อความใน ErrorInfo แทนการโยน exception
       แต่ชั้น socket ข้างในอาจโยน warning ดิบออกมาปนกับ JSON ของ API ได้
       จึงกด @ ไว้แล้วอ่านสาเหตุจาก ErrorInfo ซึ่งละเอียดกว่าอยู่แล้ว */
    $sent = @$mail->Send();

    if (!$sent) {
        $err = trim($mail->ErrorInfo);
        if ($err === '') { $err = 'ไม่ทราบสาเหตุ'; }
        return array('ok' => false, 'message' => $err);
    }

    return array('ok' => true, 'message' => 'ส่งเมลสำเร็จ');
}


/* ---------- หยิบเมลในคิวออกไปส่ง ----------
 * $limit = ส่งได้สูงสุดกี่ฉบับต่อการเรียกหนึ่งครั้ง
 * จำกัดไว้เพราะถ้าคิวค้างอยู่ 500 ฉบับ การเรียกครั้งเดียวจะกินเวลาเป็นนาที
 * แล้วชนกับรอบถัดไปของ Task Scheduler
 *
 * คืน array('sent', 'failed', 'skipped', 'message')
 */
function mail_dispatch($limit = 20, $actor = 'system')
{
    $out = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => '');

    $cfg = mail_config();
    if (!$cfg['enabled']) {
        $out['message'] = 'การส่งอีเมลถูกปิดอยู่ที่หน้าตั้งค่าระบบ เมลยังค้างอยู่ในคิว';
        return $out;
    }

    $env = mail_env_check();
    if (!$env['ok']) { $out['message'] = $env['message']; return $out; }

    $chk = mail_config_check();
    if (!$chk['ok']) { $out['message'] = $chk['message']; return $out; }

    $limit = (int)$limit;
    if ($limit < 1)   { $limit = 1; }
    if ($limit > 100) { $limit = 100; }

    $connect = db_connect();
    $max_try = ($cfg['max_try'] > 0) ? $cfg['max_try'] : 3;

    /* หยิบรายการก่อน แล้วค่อยส่งทีละฉบับ
       ไม่ถือ result set ไว้ระหว่างส่ง เพราะการส่งใช้เวลาหลายวินาทีต่อฉบับ
       ถ้าคาไว้จะกิน connection ยาวโดยไม่จำเป็น */
    $stmt = @mysqli_prepare($connect,
        "SELECT `mail_id`, `to_email`, `to_name`, `subject`, `body_html`
         FROM `tb_mail_queue`
         WHERE `status` = 'pending' AND `try_count` < ?
         ORDER BY `mail_id` ASC LIMIT " . $limit);
    if (!$stmt) {
        $out['message'] = 'อ่านคิวเมลไม่สำเร็จ';
        return $out;
    }

    mysqli_stmt_bind_param($stmt, 'i', $max_try);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $to_email, $to_name, $subject, $body);

    $jobs = array();
    while (mysqli_stmt_fetch($stmt)) {
        $jobs[] = array('id' => (int)$id, 'to' => $to_email, 'name' => $to_name,
                        'subject' => $subject, 'body' => $body);
    }
    mysqli_stmt_close($stmt);

    if (count($jobs) === 0) {
        $out['message'] = 'ไม่มีเมลค้างในคิว';
        return $out;
    }

    for ($i = 0; $i < count($jobs); $i++) {
        $job = $jobs[$i];

        $res = mail_send_raw($job['to'], $job['name'], $job['subject'], $job['body']);

        if ($res['ok']) {
            mail_mark_sent($job['id'], $actor);
            $out['sent']++;
        } else {
            mail_mark_failed($job['id'], $res['message'], $max_try, $actor);
            $out['failed']++;
        }
    }

    $out['message'] = 'ส่งสำเร็จ ' . $out['sent'] . ' ฉบับ'
                    . ($out['failed'] > 0 ? ' / ไม่สำเร็จ ' . $out['failed'] . ' ฉบับ' : '');
    return $out;
}


function mail_mark_sent($mail_id, $actor)
{
    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_mail_queue`
         SET `status` = 'sent', `sent_at` = NOW(), `try_count` = `try_count` + 1,
             `last_error` = '', `updated_by` = ?
         WHERE `mail_id` = ?");
    if (!$stmt) { return false; }

    $id = (int)$mail_id;
    mysqli_stmt_bind_param($stmt, 'si', $actor, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? true : false;
}


/* ---------- บันทึกว่าส่งไม่ผ่าน ----------
 * ยังไม่ครบจำนวนครั้งที่ยอมให้ลอง = คงสถานะ pending ไว้ รอบหน้าจะหยิบไปลองใหม่
 * ครบแล้ว = ตีเป็น failed เพื่อไม่ให้วนลองไม่จบ แล้วให้คนไปดูสาเหตุที่หน้าตั้งค่า
 */
function mail_mark_failed($mail_id, $error, $max_try, $actor)
{
    $connect = db_connect();

    $error = notify_clip_safe($error, 500);
    $id    = (int)$mail_id;
    $max   = (int)$max_try;

    $stmt = @mysqli_prepare($connect,
        "UPDATE `tb_mail_queue`
         SET `try_count`  = `try_count` + 1,
             `last_error` = ?,
             `status`     = IF(`try_count` + 1 >= ?, 'failed', 'pending'),
             `updated_by` = ?
         WHERE `mail_id` = ?");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'sisi', $error, $max, $actor, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? true : false;
}


/* ---------- ส่งเมลทดสอบหนึ่งฉบับทันที ----------
 * ไม่ผ่านคิว เพราะจุดประสงค์คือ "อยากรู้เดี๋ยวนี้ว่าค่าตั้งถูกไหม"
 * ถ้าเอาเข้าคิวแล้วรอรอบส่ง จะไม่ได้คำตอบทันทีและ debug ยากขึ้น
 *
 * ทำงานได้แม้ mail_enabled = 0 เพราะต้องทดสอบให้ผ่านก่อนถึงจะกล้าเปิดใช้จริง
 */
function mail_send_test($to_email, $actor)
{
    if (!mail_valid_address($to_email)) {
        return db_fail('รูปแบบอีเมลปลายทางไม่ถูกต้อง');
    }

    $subject = '[ทดสอบ] ระบบสต็อกธุรการ Happyland ส่งอีเมลได้แล้ว';
    $body    = mail_layout(
        'ทดสอบการส่งอีเมล',
        '<p>ถ้าคุณอ่านข้อความนี้อยู่ แปลว่าการตั้งค่า SMTP ของระบบสต็อกธุรการถูกต้องแล้ว '
      . 'ระบบจะเริ่มส่งอีเมลแจ้งเตือนใบเบิกให้ผู้อนุมัติและผู้ขอเบิกได้ทันทีที่เปิดสวิตช์</p>'
      . '<p style="color:#64748b;font-size:13px">ส่งโดย: ' . htmlspecialchars($actor, ENT_QUOTES, 'UTF-8')
      . ' &middot; เวลา: ' . date('j/n/Y H:i:s') . '</p>',
        '', ''
    );

    $res = mail_send_raw($to_email, '', $subject, $body);

    /* เก็บลงคิวเป็นประวัติด้วย ผลออกมาอย่างไรก็บันทึกตามนั้น
       เวลามีปัญหาภายหลังจะได้ย้อนดูได้ว่าเคยทดสอบผ่านตอนไหน */
    mail_log_test($to_email, $subject, $res['ok'], $res['message'], $actor);

    if (!$res['ok']) {
        return db_fail('ส่งไม่สำเร็จ: ' . $res['message']);
    }
    return db_ok('ส่งเมลทดสอบไปที่ ' . $to_email . ' เรียบร้อย กรุณาตรวจกล่องจดหมาย');
}


function mail_log_test($to_email, $subject, $ok, $error, $actor)
{
    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_mail_queue`
            (`to_email`, `to_name`, `subject`, `body_html`, `ref_type`,
             `status`, `try_count`, `last_error`, `sent_at`, `created_at`, `created_by`)
         VALUES (?, '', ?, NULL, 'test', ?, 1, ?, ?, NOW(), ?)");
    if (!$stmt) { return false; }

    $status  = $ok ? 'sent' : 'failed';
    $err     = $ok ? '' : notify_clip_safe($error, 500);
    $sent_at = $ok ? date('Y-m-d H:i:s') : null;

    mysqli_stmt_bind_param($stmt, 'ssssss',
        $to_email, $subject, $status, $err, $sent_at, $actor);

    $r = @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $r ? true : false;
}


/* ============================================================
 *  หน้าตาของอีเมล
 * ============================================================ */

/* ---------- กรอบจดหมายมาตรฐาน ----------
 * ใช้ตารางกับ style ฝังในแท็ก ไม่ใช่ CSS แยกหรือ flexbox
 * เพราะ Outlook กับ Gmail ตัด <style> ทิ้งและไม่รู้จัก flex
 * เมลที่สวยในเบราว์เซอร์แต่พังใน Outlook คือเมลที่พัง
 *
 * $button_url เว้นว่างไว้ = ไม่แสดงปุ่ม (ใช้กับเมลที่ไม่ต้องให้กดอะไรต่อ)
 */
function mail_layout($heading, $body_html, $button_text = '', $button_url = '')
{
    $brand = '#0f766e';

    $button = '';
    if ($button_text !== '' && $button_url !== '') {
        $button =
            '<tr><td style="padding:8px 28px 4px">'
          . '<a href="' . htmlspecialchars($button_url, ENT_QUOTES, 'UTF-8') . '" '
          . 'style="display:inline-block;background:' . $brand . ';color:#ffffff;'
          . 'text-decoration:none;padding:11px 22px;border-radius:8px;'
          . 'font-size:15px;font-weight:600">'
          . htmlspecialchars($button_text, ENT_QUOTES, 'UTF-8')
          . '</a></td></tr>';
    }

    return
      '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</title></head>'
    . '<body style="margin:0;padding:24px 12px;background:#f1f5f9;'
    . 'font-family:\'Segoe UI\',Tahoma,\'Prompt\',sans-serif;color:#1e293b">'

    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
    . '<tr><td align="center">'

    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
    . 'style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;'
    . 'overflow:hidden;border:1px solid #e2e8f0">'

    /* หัวจดหมาย */
    . '<tr><td style="background:' . $brand . ';padding:18px 28px">'
    . '<div style="color:#ffffff;font-size:17px;font-weight:700">ระบบสต็อกธุรการ Happyland</div>'
    . '</td></tr>'

    . '<tr><td style="padding:24px 28px 4px">'
    . '<div style="font-size:19px;font-weight:700;color:#0f172a">'
    . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</div></td></tr>'

    . '<tr><td style="padding:8px 28px 4px;font-size:15px;line-height:1.7">'
    . $body_html . '</td></tr>'

    . $button

    . '<tr><td style="padding:22px 28px 26px">'
    . '<div style="border-top:1px solid #e2e8f0;padding-top:14px;'
    . 'color:#94a3b8;font-size:12px;line-height:1.6">'
    . 'อีเมลฉบับนี้ส่งอัตโนมัติจากระบบสต็อกธุรการ กรุณาอย่าตอบกลับ<br>'
    . 'หากไม่ต้องการรับอีเมลแจ้งเตือน กรุณาแจ้งผู้ดูแลระบบ'
    . '</div></td></tr>'

    . '</table></td></tr></table></body></html>';
}


/* ---------- ตารางรายละเอียดเอกสารในเนื้อเมล ----------
 * $pairs = array(array('หัวข้อ', 'ค่า'), ...)
 * ค่าถูก escape ให้แล้ว ผู้เรียกส่งข้อความดิบมาได้เลย
 */
function mail_detail_table($pairs)
{
    if (!is_array($pairs) || count($pairs) === 0) { return ''; }

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
          . 'style="width:100%;margin:6px 0 2px;font-size:14px">';

    for ($i = 0; $i < count($pairs); $i++) {
        $label = htmlspecialchars($pairs[$i][0], ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars($pairs[$i][1], ENT_QUOTES, 'UTF-8');

        $html .= '<tr>'
               . '<td style="padding:5px 12px 5px 0;color:#64748b;white-space:nowrap;'
               . 'vertical-align:top;width:34%">' . $label . '</td>'
               . '<td style="padding:5px 0;font-weight:600;vertical-align:top">' . $value . '</td>'
               . '</tr>';
    }
    return $html . '</table>';
}


/* ---------- ที่อยู่ของหน้าในระบบ ใช้ทำปุ่มในเมล ----------
 * เอาจากค่าตั้ง site_url ไม่ใช่ $_SERVER เพราะตัวส่งอาจถูกเรียกจาก
 * บรรทัดคำสั่ง (Task Scheduler) ซึ่งไม่มี $_SERVER['HTTP_HOST'] ให้อ่าน
 */
function mail_page_url($page, $doc_id = 0)
{
    $base = trim(setting_get('site_url', ''));
    if ($base === '') { return ''; }
    if (substr($base, -1) !== '/') { $base .= '/'; }

    $url = $base . 'main.php?page=' . urlencode($page);
    if ((int)$doc_id > 0) {
        $url .= '&doc=' . (int)$doc_id;
    }
    return $url;
}
