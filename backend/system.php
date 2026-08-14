<?php
/* ============================================================
 *  backend/system.php — ข้อมูลระดับระบบที่หน้าภาพรวมระบบใช้
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/system.php';
 *      $n = user_count();                  (มาจาก backend/user.php)
 *      if (table_exists('tb_company')) { ... }
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

/* user_count() ย้ายไปอยู่กับฟังก์ชันผู้ใช้ตัวอื่นแล้ว
   ดึงเข้ามาที่นี่ด้วย เพื่อให้หน้าที่เคยเรียกผ่าน system.php ใช้ต่อได้เหมือนเดิม */
require_once dirname(__FILE__) . '/user.php';


/* ตารางนี้ถูกสร้างแล้วหรือยัง
 * ชื่อตารางมาจากรายการที่เรากำหนดเองในโค้ด ไม่ใช่ค่าจากผู้ใช้
 * แต่กรองอักขระอีกชั้นไว้ กันพลาดถ้าวันหลังมีคนส่งค่าจากภายนอกเข้ามา
 */
function table_exists($table)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $connect = db_connect();
    $result  = @mysqli_query($connect, "SELECT 1 FROM `" . $table . "` LIMIT 1");

    return ($result) ? true : false;
}
