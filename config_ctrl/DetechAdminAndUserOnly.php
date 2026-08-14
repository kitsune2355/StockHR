<?php
/* หน้าสำหรับ admin และผู้ใช้ทั่วไป (general) เท่านั้น
   หมายเหตุ: ของเดิมเทียบกับค่า "user" ซึ่งไม่มีอยู่ใน role ของระบบ
   ค่าที่ถูกต้องคือ "general" ตาม ENUM ของ tb_user.role */
include "checksession.php";

if ($sess_role !== 'admin' && $sess_role !== 'general') {
    $_SESSION['login_error'] = 'หน้านี้สำหรับเจ้าหน้าที่ผู้ดูแลระบบและผู้ใช้งานทั่วไปเท่านั้น';
    header('Location: ../main.php');
    exit();
}
