<?php
/* ============================================================
 *  config_ctrl/welfare_key.example.php — กุญแจเชื่อมต่อกับระบบสวัสดิการ (hl-welfare)
 *
 *  คัดลอกเป็น welfare_key.php แล้วใส่กุญแจลับ (สุ่มยาว 64 ตัวอักษรขึ้นไป)
 *      copy config_ctrl\welfare_key.example.php config_ctrl\welfare_key.php
 *
 *  ฝั่งระบบสวัสดิการต้องใช้กุญแจตัวเดียวกันเซ็นคำขอ (ดู api/welfare.php)
 *  welfare_key.php อยู่ใน .gitignore ห้ามเอาขึ้น repo
 *  ไม่มีไฟล์นี้ = ปิดการเชื่อมต่อ api/welfare.php ตอบปฏิเสธทุกคำขอ
 * ============================================================ */

$welfare_api_secret = '';
