<?php
/* หน้าสำหรับ admin เท่านั้น — วางไว้บรรทัดแรกของหน้าที่ต้องการจำกัดสิทธิ์ */
include "checksession.php";

if ($sess_role !== 'admin') {
    $_SESSION['login_error'] = 'หน้านี้สำหรับเจ้าหน้าที่ผู้ดูแลระบบเท่านั้น';
    header('Location: ../main.php');
    exit();
}
