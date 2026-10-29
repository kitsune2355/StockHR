<?php
/* ============================================================
 *  api/company_logo.php — อัปโหลด/ลบโลโก้บริษัท
 *
 *  แยกออกจาก api/agencies.php เพราะรับข้อมูลคนละรูปแบบ
 *  endpoint อื่นทั้งระบบรับ body เป็น JSON แต่การส่งไฟล์ต้องใช้
 *  multipart/form-data ซึ่ง api_input() อ่านไม่ได้
 *  ถ้ายัดรวมไฟล์เดียวกันจะต้องมีสองทางแยกในทุกฟังก์ชันตั้งแต่ต้น
 *
 *    POST (multipart)  action=upload  company_id=3  csrf_token=...  logo=<ไฟล์>
 *    POST (multipart)  action=remove  company_id=3  csrf_token=...
 *
 *  สิทธิ์เดียวกับเมนู "หน่วยงาน" (ตั้งได้ที่หน้า "สิทธิ์การใช้งาน")
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/company_logo.php';

api_require_post();
$actor = api_require_login();

/* ใครใช้ได้ = ใครเข้าเมนู "หน่วยงาน" ได้ (ตั้งที่หน้า "สิทธิ์การใช้งาน") — กติกาเดียวกับแถบเมนูและ router
   เดิมล็อกไว้ที่ super_admin ตายตัว เปิดเมนูให้ admin แล้วเข้าหน้าได้ แต่กดบันทึกโดนปฏิเสธ */
require_once dirname(dirname(__FILE__)) . '/includes/menu.php';
app_apply_permissions();
$routes = app_routes();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';
if (!isset($routes['agencies']) || !app_can_access($routes['agencies'], $actor_role)) {
    api_fail('คุณไม่มีสิทธิ์ใช้งานส่วนนี้', 403);
}

/* ค่ามาทาง $_POST ไม่ใช่ JSON body — ประกอบให้ api_require_csrf ใช้ต่อได้ */
api_require_csrf(array(
    'csrf_token' => isset($_POST['csrf_token']) ? $_POST['csrf_token'] : ''
));

$action     = isset($_POST['action'])     ? $_POST['action']          : '';
$company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;


if ($action === 'upload') {

    /* $_FILES ว่างทั้งก้อนแปลว่าไฟล์ใหญ่เกิน post_max_size — PHP ตัดทิ้งตั้งแต่
       ก่อนสคริปต์เริ่มทำงาน และไม่ตั้งรหัสข้อผิดพลาดไว้ให้เลย
       ถ้าไม่ดักตรงนี้ ผู้ใช้จะเจอข้อความว่า "ยังไม่ได้เลือกไฟล์" ทั้งที่เลือกแล้ว */
    if (empty($_FILES) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        api_fail('ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ (post_max_size = '
               . ini_get('post_max_size') . ')');
    }

    if (!isset($_FILES['logo'])) {
        api_fail('ยังไม่ได้เลือกไฟล์รูป');
    }

    $res = company_logo_save($company_id, $_FILES['logo'], $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array(
        'message'  => $res['message'],
        'logoFile' => $res['logo_file'],
        'logoUrl'  => $res['logo_url']
    ));
}


if ($action === 'remove') {

    $res = company_logo_remove($company_id, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
