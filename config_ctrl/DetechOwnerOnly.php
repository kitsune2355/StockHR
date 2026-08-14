<?php
/* อนุญาตให้เข้าถึงได้เฉพาะข้อมูลของตัวเอง
   หน้าที่เรียกใช้ต้องส่ง ?username=<ชื่อผู้ใช้> มาด้วย */
include "checksession.php";

$target_username = isset($_GET['username']) ? trim($_GET['username']) : '';

if ($target_username === '') {
    $_SESSION['login_error'] = 'ไม่ได้ระบุผู้ใช้งานที่ต้องการเข้าถึง';
    header('Location: ../main.php');
    exit();
}

if ($sess_username !== $target_username) {
    $_SESSION['login_error'] = 'คุณไม่สามารถเข้าถึงข้อมูลของผู้ใช้อื่นได้ กรุณาติดต่อผู้ดูแลระบบ';
    header('Location: ../main.php');
    exit();
}
