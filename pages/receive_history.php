<?php
/* pages/receive_history.php — ประวัติการรับเข้า
 * โครงหน้าอยู่ใน _doc_list.php ยกเลิกใบรับเข้าได้เฉพาะผู้ดูแลระบบ */

$doc_type    = 'receive';
$page_title  = 'ประวัติการรับเข้า';
$page_hint   = 'เอกสารรับเข้าทั้งหมด กดดูรายละเอียดเพื่อดูรายการสินค้าในใบนั้น';
$export_name = 'ประวัติรับเข้า';

include dirname(__FILE__) . '/_doc_list.php';