<?php
/* pages/request_approval.php — ผู้อนุมัติใบเบิก
 * ใช้โครงหน้าเดียวกับ "ผู้อนุมัติใบปรับยอด" (pages/approval_settings.php)
 * ต่างกันที่รายชื่อ: tb_request_approver (ดู backend/doc_approver.php) */
$approval_kind = 'request';
include dirname(__FILE__) . '/approval_settings.php';
