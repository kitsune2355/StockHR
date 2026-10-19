<?php
/* ============================================================
 *  config_ctrl/connect.example.php — ตัวอย่างค่าเชื่อมต่อฐานข้อมูล
 *
 *  คัดลอกเป็น connect.php แล้วกรอกค่าทั้งสองชุด
 *      copy config_ctrl\connect.example.php config_ctrl\connect.php
 *
 *  ไฟล์เดียวใช้ได้ทั้งเครื่องพัฒนาและเซิร์ฟเวอร์จริง เพราะดูสภาพแวดล้อมเอง
 *  จึงอัปโหลดทั้งโฟลเดอร์ทับได้โดยไม่ต้องแก้ไฟล์นี้ทุกครั้งที่ deploy
 *
 *  connect.php ตัวจริงอยู่ใน .gitignore เพราะมีรหัสผ่าน ห้ามเอาขึ้น repo
 * ============================================================ */

    error_reporting(E_ALL ^ E_NOTICE);

    /* ---------- ที่นี่คือเครื่องไหน ----------
     * ต้องตอบได้ทั้งตอนเปิดผ่านเว็บและตอนถูกเรียกจากบรรทัดคำสั่ง (cron/mail_worker.php)
     * ซึ่งไม่มี HTTP_HOST ให้ดู จึงใช้ระบบปฏิบัติการเป็นตัวช่วยอีกชั้น
     */
    $cfg_http_host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $cfg_on_win    = (DIRECTORY_SEPARATOR === '\\');

    $cfg_is_local = false;
    if ($cfg_http_host !== '') {
        $cfg_host_only = $cfg_http_host;
        $cfg_colon = strpos($cfg_host_only, ':');
        if ($cfg_colon !== false) { $cfg_host_only = substr($cfg_host_only, 0, $cfg_colon); }

        $cfg_is_local = ($cfg_host_only === 'localhost'
                      || $cfg_host_only === '127.0.0.1'
                      || $cfg_host_only === '::1'
                      || substr($cfg_host_only, 0, 8) === '192.168.');
    } else {
        $cfg_is_local = $cfg_on_win;
    }


    if ($cfg_is_local) {

        /* ---------- เครื่องพัฒนา ---------- */
        $host_mysql     = 'localhost';
        $user_mysql     = 'ชื่อผู้ใช้ฐานข้อมูลบนเครื่องพัฒนา';
        $password_mysql = 'รหัสผ่าน';
        $dbname         = 'stock_hr';

    } else {

        /* ---------- เซิร์ฟเวอร์จริง ----------
         * ชื่อฐานข้อมูลกับชื่อผู้ใช้ของโฮสต์มักมีชื่อบัญชีนำหน้า เช่น xxxxx_stock_hr
         */
        $host_mysql     = 'localhost';
        $user_mysql     = 'ชื่อผู้ใช้ฐานข้อมูลบนโฮสต์';
        $password_mysql = 'รหัสผ่านฐานข้อมูลบนโฮสต์';
        $dbname         = 'ชื่อฐานข้อมูลบนโฮสต์';
    }


    $connect = mysqli_connect($host_mysql, $user_mysql, $password_mysql, $dbname)
               or die('SERVER CONNECTION ERROR');

    if ($connect->connect_error) {
        die('Connection failed: ' . $connect->connect_error);
    }

    /* ต้องตั้งครบทั้งสามตัว ไม่งั้นภาษาไทยที่อ่านออกมาจะเป็นเครื่องหมายคำถาม */
    mysqli_query($connect, "SET character_set_results=UTF8")      or die(mysqli_error($connect));
    mysqli_query($connect, "SET character_set_client='UTF8'")     or die(mysqli_error($connect));
    mysqli_query($connect, "SET character_set_connection='UTF8'") or die(mysqli_error($connect));

    $iChkConnection = true;
?>
