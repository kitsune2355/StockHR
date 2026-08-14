<?php
/* index.php — ประตูทางเข้าระบบ ส่งต่อไปหน้า login
 * (checkmember.php redirect มาที่ ../index.php เมื่อไม่ได้กรอก user/pass)
 */
header('Location: login.php');
exit();
