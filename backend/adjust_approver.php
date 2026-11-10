<?php
/* ============================================================
 *  backend/adjust_approver.php — รายชื่อผู้อนุมัติใบปรับยอดสินค้า
 *
 *  ตัวจริงย้ายไปอยู่ที่ backend/doc_approver.php (ใช้ร่วมกับผู้อนุมัติใบเบิก)
 *  ไฟล์นี้เหลือชื่อฟังก์ชันเดิมไว้ให้ผู้เรียกเดิมไม่ต้องแก้
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/doc_approver.php';


function adjust_approver_usernames()
{
    return doc_approver_usernames('adjust');
}

/* $actor_role ไม่ใช้แล้ว (ไม่ผูกกับ role) คงพารามิเตอร์ไว้ให้ผู้เรียกเดิม */
function adjust_approver_can($username, $actor_role = null)
{
    return doc_approver_can('adjust', $username);
}

function adjust_approver_save($usernames, $actor)
{
    return doc_approver_save('adjust', $usernames, $actor);
}
