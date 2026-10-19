<?php
/* ============================================================
 *  token_helper.php
 *  ระบบ "จดจำการเข้าสู่ระบบ" (Remember Me)
 *
 *  แนวคิด:
 *    - Access token  = PHP Session เดิม (อายุสั้น)
 *    - Refresh token = เก็บใน tb_user_token (อายุ 30 วัน) + cookie HttpOnly ที่เครื่อง user
 *
 *  รูปแบบ token (selector : validator)
 *    - selector  : ส่วนสาธารณะ ใช้ค้นหา row (เก็บ plain ใน DB)
 *    - validator : ส่วนลับ เก็บเฉพาะ sha256 ใน DB, เทียบด้วย hash_equals()
 *    - cookie    = "selector:validator"
 *
 *  ทุก query ในไฟล์นี้ใช้การ escape/ตรวจชนิดข้อมูล เพื่อกัน SQL Injection
 * ============================================================ */

if (!defined('ES_REMEMBER_COOKIE')) define('ES_REMEMBER_COOKIE', 'stockhr_remember');
if (!defined('ES_REMEMBER_TTL'))    define('ES_REMEMBER_TTL', 30 * 24 * 60 * 60); // 30 วัน (วินาที)

/* ---------- ตั้งค่า cookie (รองรับทั้ง PHP >=7.3 และเก่ากว่า) ---------- */
function es_set_remember_cookie($value, $expire)
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
              || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if (PHP_VERSION_ID >= 70300) {
        setcookie(ES_REMEMBER_COOKIE, $value, [
            'expires'  => $expire,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // PHP < 7.3 : แทรก SameSite ผ่านช่อง path
        setcookie(ES_REMEMBER_COOKIE, $value, $expire, '/; samesite=Lax', '', $secure, true);
    }
}

function es_clear_remember_cookie()
{
    if (PHP_VERSION_ID >= 70300) {
        setcookie(ES_REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(ES_REMEMBER_COOKIE, '', time() - 3600, '/; samesite=Lax', '', false, true);
    }
    unset($_COOKIE[ES_REMEMBER_COOKIE]);
}

/* ---------- ออก refresh token ใหม่ (เรียกตอน login สำเร็จ หรือตอน rotate) ---------- */
function es_issue_remember_token($connect, $user_id, $ag_id = 0)
{
    $selector  = bin2hex(random_bytes(16)); // 32 hex chars
    $validator = bin2hex(random_bytes(32)); // 64 hex chars
    $hash      = hash('sha256', $validator);

    $uid  = mysqli_real_escape_string($connect, $user_id);
    $sel  = mysqli_real_escape_string($connect, $selector);
    $hsh  = mysqli_real_escape_string($connect, $hash);
    $agid = (int)$ag_id;
    $ua   = mysqli_real_escape_string($connect, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    $ip   = mysqli_real_escape_string($connect, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $exp  = date('Y-m-d H:i:s', time() + ES_REMEMBER_TTL);

    $sql = "INSERT INTO tb_user_token
            SET `user_id`='$uid',
                `selector`='$sel',
                `token_hash`='$hsh',
                `ag_id`=$agid,
                `user_agent`='$ua',
                `ip_address`='$ip',
                `created_at`=NOW(),
                `expires_at`='$exp',
                `last_used_at`=NOW()";

    if (!mysqli_query($connect, $sql)) {
        return false; // ออก token ไม่สำเร็จ ก็แค่ไม่ต้อง remember (login ปกติยังทำงานได้)
    }

    es_set_remember_cookie($selector . ':' . $validator, time() + ES_REMEMBER_TTL);
    return true;
}

/* ---------- ลบ token ตาม selector ---------- */
function es_delete_token_by_selector($connect, $selector)
{
    $sel = mysqli_real_escape_string($connect, $selector);
    mysqli_query($connect, "DELETE FROM tb_user_token WHERE `selector`='$sel'");
}

/* ---------- ต่ออายุ token แบบ sliding (ไม่หมุน selector กัน race หลายรีเควสพร้อมกัน) ---------- */
function es_touch_remember_token($connect, $selector)
{
    $sel = mysqli_real_escape_string($connect, $selector);
    $exp = date('Y-m-d H:i:s', time() + ES_REMEMBER_TTL);
    mysqli_query($connect,
        "UPDATE tb_user_token
         SET `expires_at`='$exp', `last_used_at`=NOW()
         WHERE `selector`='$sel'");

    // ต่ออายุ cookie ฝั่ง browser ด้วย (คงค่าเดิม)
    if (!empty($_COOKIE[ES_REMEMBER_COOKIE])) {
        es_set_remember_cookie($_COOKIE[ES_REMEMBER_COOKIE], time() + ES_REMEMBER_TTL);
    }
}

/* ---------- ตรวจ refresh token จาก cookie ----------
 * return : array(row) ถ้าถูกต้อง / false ถ้าไม่มีหรือไม่ผ่าน
 */
function es_validate_remember_token($connect)
{
    if (empty($_COOKIE[ES_REMEMBER_COOKIE])) return false;

    $parts = explode(':', $_COOKIE[ES_REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) { es_clear_remember_cookie(); return false; }

    list($selector, $validator) = $parts;
    // ต้องเป็น hex ตามที่ออกไว้เท่านั้น
    if (!ctype_xdigit($selector) || !ctype_xdigit($validator)) {
        es_clear_remember_cookie();
        return false;
    }

    $sel = mysqli_real_escape_string($connect, $selector);
    $q   = mysqli_query($connect, "SELECT * FROM tb_user_token WHERE `selector`='$sel' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;

    if (!$row) { es_clear_remember_cookie(); return false; }

    // หมดอายุ ?
    if (strtotime($row['expires_at']) < time()) {
        es_delete_token_by_selector($connect, $selector);
        es_clear_remember_cookie();
        return false;
    }

    // เทียบ validator แบบกัน timing attack
    $calc = hash('sha256', $validator);
    if (!hash_equals($row['token_hash'], $calc)) {
        // validator ไม่ตรง = อาจโดนขโมย/ปลอม → เผาทิ้งทั้ง row
        es_delete_token_by_selector($connect, $selector);
        es_clear_remember_cookie();
        return false;
    }

    return $row; // ผ่าน
}

/* ---------- ตั้งค่า session พื้นฐาน (ใช้ร่วมกันระหว่าง login ปกติ กับ auto-login) ---------- */
function es_set_base_session($result, $server_id, $db_short_name, $server_host)
{
    $_SESSION['sess_id_es']             = session_id();
    $_SESSION['sess_member_last_name']  = $result['member_last_name'] ?? '';
    $_SESSION['sess_user_id_es']        = $result['user_id'];
    $_SESSION['sess_user_name_es']      = $result['user_name'];
    $_SESSION['sess_user_fname_es']     = $result['user_fname'];
    $_SESSION['sess_user_password_es']  = $result['user_password'];
    $_SESSION['sess_user_level_es']     = $result['user_level'];
    $_SESSION['sess_user_email_es']     = $result['user_email'];
    $_SESSION['sess_user_dep_id_es']    = $result['user_dep_id'] ?? '';
    $_SESSION['sess_user_agency']       = $result['user_agency'];
    $_SESSION['sess_status_login_es']   = $result['user_status_login'];
    $_SESSION['sess_user_position_es']  = $result['user_position'];
    $_SESSION['sess_position_level_es'] = $result['user_position_level'];
    $_SESSION['sess_SYSTEM_CTRL']       = $db_short_name;
    $_SESSION['sess_server_id']         = $server_id;
    $_SESSION['sess_tmp_usr_id']        = date('His');
    $_SESSION['sess_SERVER_HOST']       = $server_host;
    $_SESSION['session_time']           = time();
}

/* ---------- สร้าง session ใหม่จาก refresh token (auto-login) ----------
 * เรียกจาก checksession.php เมื่อ session หมดแต่ยังมี cookie ที่ valid
 * return true = สร้าง session สำเร็จ
 */
function es_establish_remember_session($connect, $token_row)
{
    $user_id = $token_row['user_id'];
    $ag_hint = (int)($token_row['ag_id'] ?? 0);

    // ตั้งค่า host / server_id เหมือน checkmember
    $server_host   = $_SERVER['HTTP_HOST'] ?? '';
    $db_short_name = '82';
    $server_id     = ($server_host === 'localhost') ? ('db_job_' . $db_short_name) : 'happylandc_wha';

    // โหลดข้อมูล user (escape user_id ป้องกัน SQLi)
    $uid = mysqli_real_escape_string($connect, $user_id);
    $q   = mysqli_query($connect, "SELECT * FROM tb_user WHERE `user_id`='$uid' LIMIT 1");
    $result = $q ? mysqli_fetch_assoc($q) : null;
    if (!$result) return false;

    // ต้องเป็นบัญชีที่เปิดใช้งาน login (สถานะเดียวกับ login ปกติ)
    if ((string)$result['user_status_login'] !== '1') return false;

    es_set_base_session($result, $server_id, $db_short_name, $server_host);

    // คืนค่า agency ที่เคยใช้อยู่ (ถ้ามี) ให้ไม่ต้องเลือกใหม่
    $_SESSION['needAgencyPopupES'] = 0;
    if ($ag_hint > 0) {
        $qa = mysqli_query($connect,
            "SELECT ag_id, ag_contract, ag_job, ag_start_date, ag_end_date
             FROM tb_agency WHERE ag_id = $ag_hint AND ag_status = 1 LIMIT 1");
        $ra = $qa ? mysqli_fetch_assoc($qa) : null;
        if ($ra) {
            $_SESSION['sess_user_agency']   = (int)$ra['ag_id'];
            $_SESSION['sess_agency_code']   = $ra['ag_job'];
            $_SESSION['sess_agency_name']   = $ra['ag_contract'];
            $_SESSION['sess_ag_start_date'] = $ra['ag_start_date'];
            $_SESSION['sess_ag_end_date']   = $ra['ag_end_date'];
        }
    }

       // ยังไม่มี agency ที่ใช้ได้ → ต้องเลือกใหม่ (เปิด popup)
        if ((int)$_SESSION['sess_user_agency'] <= 0) {
            $_SESSION['sess_user_agency']  = 0;
            $_SESSION['needAgencyPopupES'] = 1;

            // set check=1 เฉพาะ "employer" เท่านั้น เพื่อให้ get_agency_list กรองเฉพาะหน่วยงานของตัวเอง
            // ส่วน super_admin ต้องเห็น "ทุกหน่วยงาน" ห้าม set check=1 (ไม่งั้นรายการหน่วยงานจะหาย)
            if ($result['user_level'] === 'employer') {
                $_SESSION['sess_user_level_es_check'] = 1;
            }
        }


        // ต่ออายุแบบ sliding (ไม่หมุน selector เพื่อกัน race ตอนหลายรีเควสยิงพร้อมกัน)
        es_touch_remember_token($connect, $token_row['selector']);

    // log เวลาเข้าใช้งาน (เหมือน login ปกติ)
    $tl = mysqli_real_escape_string($connect, $user_id);
    mysqli_query($connect, "INSERT INTO tb_time_login SET `tl_name`='$tl', `tl_time`=NOW()");

    return true;
}

/* ---------- ล้าง token ของอุปกรณ์ปัจจุบัน (ใช้ตอน logout) ---------- */
function es_forget_current_device($connect)
{
    if (!empty($_COOKIE[ES_REMEMBER_COOKIE])) {
        $parts = explode(':', $_COOKIE[ES_REMEMBER_COOKIE], 2);
        if (count($parts) === 2 && ctype_xdigit($parts[0])) {
            es_delete_token_by_selector($connect, $parts[0]);
        }
    }
    es_clear_remember_cookie();
}
