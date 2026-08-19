<?php
/* ============================================================
 *  api/product_image.php — เพิ่ม/ลบรูปสินค้า
 *
 *  แยกออกจาก api/catalog.php ด้วยเหตุผลเดียวกับที่ api/company_logo.php
 *  แยกออกจาก api/agencies.php — endpoint อื่นทั้งระบบรับ body เป็น JSON
 *  แต่การส่งไฟล์ต้องใช้ multipart/form-data ซึ่ง api_input() อ่านไม่ได้
 *
 *    POST (multipart)  action=upload  product_id=12  csrf_token=...  image=<ไฟล์>
 *    POST (multipart)  action=remove  image_id=45    csrf_token=...
 *
 *  ทั้งสองคำสั่งคืนรายการรูปล่าสุดของสินค้านั้นกลับไปด้วย (images)
 *  หน้าจอจะได้วาดใหม่จากค่าจริงในฐานข้อมูล ไม่ต้องเดาเองว่าตอนนี้เหลือกี่รูป
 *
 *  สิทธิ์เท่ากับหน้าทะเบียนสินค้า (super_admin / admin) เพราะรูปสินค้า
 *  เป็นข้อมูลทะเบียนเหมือนชื่อกับหน่วยนับ ไม่ใช่ของที่คนเบิกแก้ได้
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/product_image.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

/* ค่ามาทาง $_POST ไม่ใช่ JSON body — ประกอบให้ api_require_csrf ใช้ต่อได้ */
api_require_csrf(array(
    'csrf_token' => isset($_POST['csrf_token']) ? $_POST['csrf_token'] : ''
));

$action = isset($_POST['action']) ? $_POST['action'] : '';


if ($action === 'upload') {

    /* $_FILES ว่างทั้งก้อนแปลว่าไฟล์ใหญ่เกิน post_max_size — PHP ตัดทิ้งตั้งแต่
       ก่อนสคริปต์เริ่มทำงาน และไม่ตั้งรหัสข้อผิดพลาดไว้ให้เลย
       ถ้าไม่ดักตรงนี้ ผู้ใช้จะเจอข้อความว่า "ยังไม่ได้เลือกไฟล์" ทั้งที่เลือกแล้ว */
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])
        && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        api_fail('ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ (post_max_size = '
               . ini_get('post_max_size') . ')');
    }

    if (!isset($_FILES['image'])) {
        api_fail('ยังไม่ได้เลือกไฟล์รูป');
    }

    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

    $res = product_image_save($product_id, $_FILES['image'], $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array(
        'message' => $res['message'],
        'images'  => $res['images']
    ));
}


if ($action === 'remove') {

    $image_id = isset($_POST['image_id']) ? (int)$_POST['image_id'] : 0;

    $res = product_image_delete($image_id, $actor);
    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array(
        'message' => $res['message'],
        'images'  => $res['images']
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
