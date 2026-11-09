<?php
/* ============================================================
 *  api/attachment.php — ไฟล์แนบของเอกสาร (ใบบิลของใบรับเข้า)
 *
 *  GET  ?action=view&id=..          เปิดดูไฟล์ในแท็บ (รูป/PDF)
 *  GET  ?action=download&id=..      ดาวน์โหลด
 *  POST action=upload   (multipart) doc_id + file + csrf_token
 *  POST action=delete               attach_id + csrf_token
 *
 *  ไฟล์เปิดตรงผ่านเว็บไม่ได้ (storage/.htaccess) — ทางเดียวคือที่นี่ ซึ่งตรวจสิทธิ์ทุกครั้ง
 *  ใครดู/แนบได้: คนที่บันทึกใบนั้นเอง หรือคนที่เปิดใบนั้นได้ (doc_can_read — กติกาเดียวกับ
 *  การเปิดรายละเอียดเอกสารใน api/documents.php action get และ print_doc.php)
 *  ใครลบได้: ผู้ดูแลระบบ หรือคนที่แนบไฟล์นั้น (ดู doc_attachment_can_delete)
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/document.php';
require_once dirname(dirname(__FILE__)) . '/backend/user.php';
require_once dirname(dirname(__FILE__)) . '/backend/doc_attachment.php';

$actor      = api_require_login();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

/* ---------- สิทธิ์ต่อเอกสาร ----------
 * role ต้องอยู่ในกลุ่มที่ทำงานกับใบรับเข้าได้ (ชุดเดียวกับ doc_write_roles_for('receive'))
 * แล้วต้องเป็นคนบันทึกใบนั้น หรือเปิดใบนั้นได้ตามกติกาปกติ
 * (ใบรับเข้าไม่มีหน่วยงาน ผู้อนุมัติที่บันทึกใบเองจึงต้องผ่านทางข้อแรก) */
function att_can_access($doc, $actor, $actor_role)
{
    if ($doc === null) { return false; }
    if (!in_array($actor_role, array('super_admin', 'admin', 'approver'))) { return false; }
    if ($doc['created_by'] !== '' && $doc['created_by'] === $actor) { return true; }
    return doc_can_read($doc, $actor, $actor_role);
}


/* ============================================================
 *  ดู / ดาวน์โหลด (GET — เปิดจากลิงก์ในหน้าเว็บ)
 * ============================================================ */
$get_action = isset($_GET['action']) ? $_GET['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($get_action === 'view' || $get_action === 'download')) {

    $row = doc_attachment_find(isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $doc = ($row !== null) ? doc_find($row['doc_id']) : null;

    if ($row === null || !att_can_access($doc, $actor, $actor_role)) {
        /* ไม่บอกว่าไฟล์มีอยู่จริงไหม — ไม่มีสิทธิ์กับไม่มีไฟล์ตอบเหมือนกัน */
        api_fail('ไม่พบไฟล์ หรือคุณไม่มีสิทธิ์เปิดไฟล์นี้', 404);
    }

    doc_attachment_send($row, $get_action === 'download');
    exit;
}


api_require_post();

/* ค่ามาทาง $_POST (multipart) ไม่ใช่ JSON body — ประกอบให้ api_require_csrf ใช้ต่อได้ */
api_require_csrf(array(
    'csrf_token' => isset($_POST['csrf_token']) ? $_POST['csrf_token'] : ''
));

$action = isset($_POST['action']) ? $_POST['action'] : '';

if (!doc_attachment_ready()) {
    api_fail('ยังไม่ได้เปิดใช้ไฟล์แนบ (ต้องรัน sql/087_doc_attachment.sql)');
}


/* ============================================================
 *  แนบไฟล์ — ทีละไฟล์ต่อคำขอ หน้าเว็บวนส่งเอง (ไฟล์ใหญ่ทีละหลายไฟล์ชน post_max_size)
 * ============================================================ */
if ($action === 'upload') {

    /* $_FILES ว่างทั้งก้อน = ไฟล์ใหญ่เกิน post_max_size — PHP ตัดทิ้งก่อนสคริปต์เริ่ม
       และไม่ตั้งรหัสข้อผิดพลาดไว้ให้ ไม่ดักตรงนี้จะได้ข้อความว่า "ยังไม่ได้เลือกไฟล์" */
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])
        && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        api_fail('ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ (post_max_size = '
               . ini_get('post_max_size') . ')');
    }
    if (!isset($_FILES['file'])) {
        api_fail('ยังไม่ได้เลือกไฟล์');
    }

    $doc = doc_find(isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0);
    if ($doc === null) {
        api_fail('ไม่พบเอกสารที่ต้องการ');
    }
    if (!att_can_access($doc, $actor, $actor_role)) {
        api_fail('คุณไม่มีสิทธิ์แนบไฟล์กับเอกสารใบนี้', 403);
    }

    $res = doc_attachment_save($doc, $_FILES['file'], $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'],
                 'attachments' => doc_attachment_list_for($doc['doc_id'], $actor, $actor_role)));
}


/* ============================================================
 *  ลบไฟล์แนบ
 * ============================================================ */
if ($action === 'delete') {

    $row = doc_attachment_find(isset($_POST['attach_id']) ? (int)$_POST['attach_id'] : 0);
    $doc = ($row !== null) ? doc_find($row['doc_id']) : null;
    if ($row === null || !att_can_access($doc, $actor, $actor_role)) {
        api_fail('ไม่พบไฟล์ หรือคุณไม่มีสิทธิ์', 404);
    }

    $res = doc_attachment_delete($row, $actor, $actor_role);
    if (!$res['ok']) { api_fail($res['message'], 403); }

    api_ok(array('message' => $res['message'],
                 'attachments' => doc_attachment_list_for($doc['doc_id'], $actor, $actor_role)));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
