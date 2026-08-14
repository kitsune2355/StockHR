<?php
/* ============================================================
 *  config_ctrl/connect.example.php — ตัวอย่างค่าเชื่อมต่อฐานข้อมูล
 *
 *  คัดลอกไฟล์นี้เป็น connect.php แล้วแก้ค่าให้ตรงกับเครื่องที่ติดตั้ง
 *      copy config_ctrl\connect.example.php config_ctrl\connect.php
 *
 *  connect.php ตัวจริงถูกกันไว้ใน .gitignore เพราะมีรหัสผ่านอยู่ข้างใน
 *  ห้ามเอาขึ้น repo
 * ============================================================ */

    error_reporting(E_ALL ^ E_NOTICE);

    $host_mysql     = 'localhost';
    $user_mysql     = 'ชื่อผู้ใช้ฐานข้อมูล';
    $password_mysql = 'รหัสผ่านฐานข้อมูล';

    $dbname = 'stock_hl';

    $connect = mysqli_connect($host_mysql, $user_mysql, $password_mysql, $dbname)
               or die('SERVER CONNECTION ERROR');

    if ($connect->connect_error) {
        die('Connection failed: ' . $connect->connect_error);
    }

    /* ต้องตั้งชุดอักขระทั้งสามตัว ไม่งั้นภาษาไทยที่อ่านออกมาจะเป็นเครื่องหมายคำถาม */
    mysqli_query($connect, "SET character_set_results=UTF8")    or die(mysqli_error($connect));
    mysqli_query($connect, "SET character_set_client='UTF8'")   or die(mysqli_error($connect));
    mysqli_query($connect, "SET character_set_connection='UTF8'") or die(mysqli_error($connect));

    $iChkConnection = true;
?>
