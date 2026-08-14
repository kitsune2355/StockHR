<?php
/* กันไม่ให้เข้าถึงหน้าโดยไม่ได้ล็อกอิน (สำเนาของ DetechGuest.php)
   checksession.php เด้งกลับหน้า login ให้อยู่แล้ว ไฟล์นี้เป็นชั้นกันพลาดอีกชั้น */
include "checksession.php";

if ($sess_username === '') {
    header('Location: ../login.php');
    exit();
}
