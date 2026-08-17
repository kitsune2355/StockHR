<?php
/* ============================================================
 *  backend/setting.php — ค่าตั้งระบบแบบ key/value (tb_app_setting)
 *
 *  ใช้เก็บของที่ต้องเปลี่ยนได้จากหน้าเว็บโดยไม่ต้องแก้ไฟล์บนเซิร์ฟเวอร์
 *  ตอนนี้คือค่าเชื่อมต่อ SMTP ของ Zoho Mail และสวิตช์เปิด/ปิดคู่มือ
 *
 *  วิธีใช้:
 *      require_once dirname(__FILE__) . '/setting.php';
 *      $host = setting_get('smtp_host');
 *      setting_set_many(array('smtp_host' => 'smtp.zoho.com'), $actor);
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';


/* ---------- อ่านค่าทั้งตารางครั้งเดียว แล้วเก็บไว้ในหน่วยความจำ ----------
 * หน้าเว็บหนึ่งหน้าถามค่าตั้งหลายตัว (โฮสต์ เมล พอร์ต ผู้ส่ง ...)
 * ถ้ายิง query ทีละตัวจะได้ query เปล่า ๆ 8 ครั้งต่อการโหลดหนึ่งหน้า
 * ทั้งตารางมีไม่ถึง 20 แถว อ่านทีเดียวจึงถูกกว่าเสมอ
 *
 * $force = true ใช้หลังบันทึกค่าใหม่ เพื่อให้อ่านค่าที่เพิ่งเขียนได้ถูกต้อง
 */
function setting_all($force = false)
{
    static $cache = null;

    if ($cache !== null && !$force) {
        return $cache;
    }

    $connect = db_connect();
    $cache   = array();

    $result = @mysqli_query($connect,
        "SELECT `setting_key`, `setting_value`, `setting_group`, `description`
         FROM `tb_app_setting` ORDER BY `setting_group`, `setting_key`");

    if (!$result) {
        return $cache;      // ยังไม่ได้รัน migration 011 — คืนค่าว่าง ไม่ให้ระบบล้ม
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $cache[$row['setting_key']] = array(
            'key'         => $row['setting_key'],
            'value'       => $row['setting_value'],
            'group'       => $row['setting_group'],
            'description' => $row['description']
        );
    }

    return $cache;
}


/* ---------- อ่านค่าเดียว ---------- */
function setting_get($key, $default = '')
{
    $all = setting_all();
    return isset($all[$key]) ? $all[$key]['value'] : $default;
}

function setting_get_int($key, $default = 0)
{
    $all = setting_all();
    return isset($all[$key]) ? (int)$all[$key]['value'] : $default;
}

function setting_is_on($key)
{
    return (setting_get($key, '0') === '1');
}


/* ---------- ที่อยู่ของระบบ ลงท้ายด้วย / ----------
 * ใช้ทำลิงก์ที่ต้องมีชื่อโฮสต์เต็ม เช่นปุ่มในอีเมลกับลิงก์เซ็นรับของ
 *
 * ลำดับความสำคัญ
 *   1. ค่า "ที่อยู่ระบบ" ที่ผู้ดูแลตั้งไว้ — ตั้งไว้แล้วต้องชนะเสมอ
 *   2. เดาจากคำขอที่กำลังเข้ามา — สำหรับที่ที่ยังไม่ได้ตั้งค่า จะได้ใช้ได้เลย
 *
 * เคยสลับลำดับกันแล้วผิด: ผู้ดูแลตั้ง https://โดเมน/stock_hl/ ไว้ถูกต้อง
 * แต่โค้ดไปเดาเอง แล้วเดาโฟลเดอร์ไม่เจอ ลิงก์ในเมลจึงกลายเป็น
 * https://โดเมน/main.php?... ซึ่งกดแล้ว 404
 */
function app_base_url()
{
    /* 1. ค่าที่ตั้งไว้มาก่อน */
    $base = trim(setting_get('site_url', ''));
    if ($base !== '') {
        return (substr($base, -1) === '/') ? $base : ($base . '/');
    }

    /* 2. เดาจากคำขอ — ไม่มีคำขอ (บรรทัดคำสั่ง) ก็จนปัญญา คืนค่าว่าง
          ผู้เรียกจะได้รู้ว่าสร้างลิงก์ไม่ได้ แทนที่จะส่งลิงก์เสียออกไป */
    $host = isset($_SERVER['HTTP_HOST']) ? trim($_SERVER['HTTP_HOST']) : '';
    if ($host === '') { return ''; }

    /* https หรือไม่ — โฮสต์ที่มีตัวคั่นหน้าจะบอกผ่าน X-Forwarded-Proto
       ส่วน HTTPS = 'off' คือค่าที่ IIS ใส่มาเมื่อไม่ได้เข้ารหัส ต้องดักด้วย */
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
              && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $scheme = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $scheme = 'https';
    }

    return $scheme . '://' . $host . app_base_dir() . '/';
}


/* ---------- โฟลเดอร์ของโปรเจกต์เทียบกับรากเว็บ ----------
 * คืน '/stock_hl' หรือ '' ถ้าวางไว้ที่รากเว็บเลย
 *
 * วิธีแรกคือเทียบเส้นทางของสคริปต์ที่กำลังรัน (SCRIPT_FILENAME) กับ SCRIPT_NAME
 * ซึ่งเป็นเส้นทางเดียวกันคนละมุม — อันหนึ่งเป็นของดิสก์ อีกอันเป็นของ URL
 * ตัดส่วนที่อยู่ใต้รากโปรเจกต์ออกจากทั้งคู่ ที่เหลือคือโฟลเดอร์ที่ต้องการ
 *
 * ไม่เทียบกับ DOCUMENT_ROOT เป็นวิธีหลัก เพราะโฮสต์แบบ DirectAdmin ทำ
 * public_html เป็นลิงก์ไปยัง domains/<โดเมน>/public_html อีกที
 * เส้นทางสองฝั่งจึงไม่ขึ้นต้นเหมือนกัน เทียบแล้วไม่ตรง ได้ค่าว่างออกมา
 * แล้วลิงก์ในเมลก็ตกหล่นชื่อโฟลเดอร์ไป (เจอมาแล้วบน develophpg.net)
 */
function app_base_dir()
{
    $proj = rtrim(str_replace('\\', '/', dirname(dirname(__FILE__))), '/');

    /* วิธีที่ 1 — เทียบ SCRIPT_FILENAME กับ SCRIPT_NAME */
    if (!empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['SCRIPT_NAME'])) {
        $file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
        $name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

        if (strcasecmp(substr($file, 0, strlen($proj)), $proj) === 0) {
            $tail = substr($file, strlen($proj));        /* เช่น /api/documents.php */
            $len  = strlen($tail);

            if ($len > 0 && strcasecmp(substr($name, -$len), $tail) === 0) {
                return rtrim(substr($name, 0, strlen($name) - $len), '/');
            }
        }
    }

    /* วิธีที่ 2 — เทียบกับ DOCUMENT_ROOT เผื่อวิธีแรกใช้ไม่ได้ */
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        if ($root !== '' && strcasecmp(substr($proj, 0, strlen($root)), $root) === 0) {
            return rtrim(substr($proj, strlen($root)), '/');
        }
    }

    return '';
}


/* ---------- อ่านทั้งกลุ่ม ----------
 * คืน array แบน key => value เอาไปใช้ต่อได้เลย
 */
function setting_group($group)
{
    $all = setting_all();
    $out = array();
    foreach ($all as $key => $row) {
        if ($row['group'] === $group) {
            $out[$key] = $row['value'];
        }
    }
    return $out;
}


/* ---------- บันทึกค่าหลายตัวพร้อมกัน ----------
 * $values = array('smtp_host' => 'smtp.zoho.com', 'smtp_port' => '465')
 *
 * $allowed_keys จำกัดว่าคีย์ไหนบ้างที่แก้ได้จากหน้าเว็บ
 * ส่งมาเสมอจากชั้น api เพื่อกันคนยิง JSON แปลก ๆ เข้ามาสร้างคีย์มั่ว
 * หรือแก้คีย์ที่ตั้งใจให้แก้ได้จากไฟล์ migration เท่านั้น
 *
 * คีย์ที่ยังไม่มีในตารางจะถูกสร้างให้ (INSERT) คีย์ที่มีแล้วจะทับค่าเดิม
 */
function setting_set_many($values, $actor, $allowed_keys = null)
{
    if (!is_array($values) || count($values) === 0) {
        return db_fail('ไม่มีค่าที่จะบันทึก');
    }

    $connect = db_connect();
    $saved   = 0;

    foreach ($values as $key => $value) {

        $key = trim((string)$key);
        if ($key === '' || !preg_match('/^[a-z0-9_]{1,60}$/', $key)) {
            continue;                                   // คีย์ผิดรูป ข้ามไปเงียบ ๆ
        }
        if ($allowed_keys !== null && !in_array($key, $allowed_keys)) {
            continue;
        }

        $value = (string)$value;
        if (db_text_length($value) > 500) {
            return db_fail('ค่าของ "' . $key . '" ยาวเกิน 500 ตัวอักษร');
        }

        /* ON DUPLICATE KEY UPDATE ทำให้ "มีอยู่แล้วก็ทับ ไม่มีก็สร้าง" จบในคำสั่งเดียว
           ไม่ต้อง SELECT ก่อนแล้วค่อยตัดสินใจ ซึ่งจะมีช่องว่างให้สองคนเขียนชนกัน */
        $stmt = mysqli_prepare($connect,
            "INSERT INTO `tb_app_setting`
                (`setting_key`, `setting_value`, `created_at`, `created_by`)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`),
                                     `updated_by`    = VALUES(`created_by`)");
        if (!$stmt) { return db_fail('บันทึกค่าตั้งระบบไม่สำเร็จ'); }

        mysqli_stmt_bind_param($stmt, 'sss', $key, $value, $actor);

        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            return db_fail(db_error_message($errno), $errno);
        }
        mysqli_stmt_close($stmt);
        $saved++;
    }

    setting_all(true);      // ล้าง cache ในหน่วยความจำ ไม่งั้นคำสั่งถัดไปยังเห็นค่าเก่า

    if ($saved === 0) {
        return db_fail('ไม่มีค่าที่บันทึกได้ — คีย์ที่ส่งมาไม่อยู่ในรายการที่แก้ได้');
    }
    return db_ok('บันทึกค่าตั้งระบบ ' . $saved . ' รายการเรียบร้อย', $saved);
}
