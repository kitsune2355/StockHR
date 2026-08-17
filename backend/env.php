<?php
/* ============================================================
 *  backend/env.php — อ่านค่าตั้งจากไฟล์ .env ที่รากโปรเจกต์
 *
 *  ค่าที่ต่างกันในแต่ละเครื่อง (รหัสฐานข้อมูล พฤติกรรมการส่งเมล) อยู่ในไฟล์เดียว
 *  ไม่ปนกับโค้ด จึงอัปโหลดโค้ดทับได้โดยไม่กระทบค่าของเซิร์ฟเวอร์
 *
 *  .env อยู่ใน .gitignore และถูกกันไม่ให้เปิดผ่านเว็บด้วย .htaccess ที่รากโปรเจกต์
 *  ถ้าย้ายไปโฮสต์ที่ไม่อ่าน .htaccess ต้องกันด้วยวิธีของโฮสต์นั้นแทน
 *  ไม่งั้นใครก็เปิด https://โดเมน/stock_hl/.env อ่านรหัสผ่านได้
 *
 *  รูปแบบไฟล์ — บรรทัดละหนึ่งค่า # คือคอมเมนต์
 *      DB_USER=root
 *      DB_PASS="มีช่องว่างก็ใส่อัญประกาศได้"
 *
 *  เขียนให้รองรับ PHP 5.2.6 (ไม่มีไลบรารีภายนอก)
 * ============================================================ */

if (!function_exists('env_all')) {

    function env_all()
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }

        $cache = array();
        $file  = dirname(dirname(__FILE__)) . '/.env';
        if (!is_file($file) || !is_readable($file)) { return $cache; }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) { return $cache; }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#') { continue; }

            $pos = strpos($line, '=');
            if ($pos === false) { continue; }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($key === '') { continue; }

            /* ตัดอัญประกาศครอบออก ถ้าใส่มาเพื่อกันช่องว่างท้ายบรรทัดหาย */
            $len = strlen($val);
            if ($len >= 2) {
                $first = substr($val, 0, 1);
                $last  = substr($val, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, $len - 2);
                }
            }

            $cache[$key] = $val;
        }

        return $cache;
    }


    /* คืนค่าจาก .env — ไม่มีคีย์นั้นหรือเป็นค่าว่าง ให้ใช้ค่าตั้งต้นแทน */
    function env_get($key, $default = '')
    {
        $all = env_all();
        if (!isset($all[$key])) { return $default; }
        return ($all[$key] === '') ? $default : $all[$key];
    }

    function env_get_int($key, $default = 0)
    {
        $v = env_get($key, null);
        return ($v === null) ? $default : (int)$v;
    }

    /* ถือว่าเปิดเมื่อค่าเป็น 1 / true / yes / on (ไม่สนตัวพิมพ์) */
    function env_is_on($key, $default = false)
    {
        $v = env_get($key, null);
        if ($v === null) { return $default; }

        $v = strtolower(trim($v));
        return ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on');
    }
}
