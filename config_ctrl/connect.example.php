<?php
/* ============================================================
 *  config_ctrl/connect.example.php — ตัวอย่างไฟล์เชื่อมต่อฐานข้อมูล
 *
 *  ตั้งแต่ย้ายค่าไปไว้ใน .env แล้ว ไฟล์นี้แทบไม่ต้องแก้อะไร
 *  คัดลอกเป็น connect.php ได้ตรง ๆ แล้วไปกรอกค่าที่ .env แทน
 *      copy config_ctrl\connect.example.php config_ctrl\connect.php
 *      copy .env.example .env
 * ============================================================ */

    error_reporting(E_ALL ^ E_NOTICE);

    require_once dirname(dirname(__FILE__)) . '/backend/env.php';

    $host_mysql     = env_get('DB_HOST', 'localhost');
    $user_mysql     = env_get('DB_USER', 'root');
    $password_mysql = env_get('DB_PASS', '');
    $dbname         = env_get('DB_NAME', 'stock_hl');

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
