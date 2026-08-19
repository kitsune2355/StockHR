<?php
/* ============================================================
 *  backend/product_image.php — รูปสินค้า สูงสุด 3 รูปต่อรายการ
 *
 *  ไฟล์จริงเก็บที่ assets/img/product/ ส่วนตารางเก็บแค่ชื่อไฟล์
 *  (ดูเหตุผลที่ sql/047_product_image.sql)
 *
 *  ---------------------------------------------------------------
 *  กติกาความปลอดภัยของการรับไฟล์จากผู้ใช้ — ชุดเดียวกับโลโก้บริษัท
 *  ---------------------------------------------------------------
 *  1. ไม่เชื่อชื่อไฟล์ที่ส่งมา — ตั้งชื่อใหม่เองทั้งหมด
 *     ชื่อจากผู้ใช้พาทั้ง ../ และ .php มาได้ ซึ่งกลายเป็นการยึดเครื่อง
 *  2. ไม่เชื่อนามสกุลและไม่เชื่อ MIME ที่เบราว์เซอร์แจ้ง — ทั้งคู่ปลอมได้
 *     ใช้ getimagesize() อ่านหัวไฟล์จริงว่าเป็นรูปไหม
 *  3. เขียนไฟล์ด้วยนามสกุลที่เราเลือกเองจากผลการอ่านหัวไฟล์เท่านั้น
 *  4. เขียนรูปใหม่ผ่าน GD เสมอถ้ามี — อะไรที่แอบฝังมาในไฟล์รูปจะหายไปด้วย
 *
 *  ตัวจัดการไฟล์ใช้ร่วมกับ backend/company_logo.php ไม่ได้ เพราะที่นั่น
 *  ผูกกับ tb_company และมีรูปเดียวต่อบริษัท ส่วนที่นี่เป็นตารางลูกหลายแถว
 *  แต่กติกาข้างบนลอกมาตรงกันทุกข้อโดยตั้งใจ แก้ที่ไหนควรไปดูอีกที่ด้วย
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

/* จำนวนรูปสูงสุดต่อสินค้าหนึ่งรายการ — บังคับที่นี่ที่เดียว
   ฐานข้อมูลไม่ได้บังคับให้ (MySQL 5.0 ไม่มี CHECK ที่ใช้งานได้จริง) */
define('PRODUCT_IMAGE_MAX', 3);

/* ขนาดสูงสุดที่รับ 3 MB — รูปจากมือถือสมัยนี้ใหญ่กว่าโลโก้บริษัทมาก
   แต่ถ้าเกินนี้แปลว่ายังไม่ได้ย่อ และจะทำให้หน้าเบิกที่โหลดรูปหลายสิบตัวช้า */
define('PRODUCT_IMAGE_MAX_BYTES', 3 * 1024 * 1024);

/* ด้านที่ยาวที่สุดหลังย่อ — พอสำหรับดูเต็มจอบนมือถือและจอคอมทั่วไป
   รูปสินค้าไม่ได้เอาไปพิมพ์ จึงไม่ต้องเผื่อความละเอียดระดับกระดาษเหมือนโลโก้ */
define('PRODUCT_IMAGE_MAX_SIDE', 900);


function product_image_dir()
{
    return dirname(dirname(__FILE__)) . '/assets/img/product/';
}

/* ---------- ที่อยู่ไว้ใส่ใน src ของ <img> ----------
 * คืนค่าว่างถ้าไฟล์หายไปจากดิสก์ (มีคนไปลบไฟล์เองได้)
 * ต้องคืนค่าว่างไม่ใช่ที่อยู่ที่เปิดไม่ได้ ไม่งั้นหน้าเบิกจะเต็มไปด้วยไอคอนรูปเสีย
 */
function product_image_url($file, $base = '')
{
    $file = trim((string)$file);
    if ($file === '') { return ''; }

    if (!file_exists(product_image_dir() . $file)) { return ''; }

    return $base . 'assets/img/product/' . rawurlencode($file);
}


/* ---------- ชนิดรูปที่รับ ----------
 * ไม่รับ SVG เพราะ SVG คือ XML ที่ฝัง <script> ได้ การเปิดไฟล์ SVG
 * ที่ผู้ใช้อัปโหลดในโดเมนเดียวกันจึงเท่ากับเปิดช่อง XSS
 */
function product_image_types()
{
    return array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif'
    );
}


/* ---------- ตรวจไฟล์ที่อัปโหลดเข้ามา ----------
 * $file = แถวหนึ่งใน $_FILES
 * คืนข้อความผิดพลาด หรือค่าว่างถ้าผ่าน
 */
function product_image_validate($file)
{
    if (!is_array($file) || !isset($file['error'])) {
        return 'ไม่พบไฟล์ที่อัปโหลด';
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้';
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'ยังไม่ได้เลือกไฟล์';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'อัปโหลดไม่สำเร็จ (รหัสข้อผิดพลาด ' . (int)$file['error'] . ')';
    }

    /* ยืนยันว่าไฟล์นี้มาจากการอัปโหลดจริง ไม่ใช่ path ที่ถูกยัดเข้ามาในตัวแปร
       ถ้าข้ามข้อนี้ไป จะถูกหลอกให้ไปอ่านไฟล์อื่นบนเครื่องแล้วก๊อปออกมาเป็นรูปได้ */
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'ไฟล์ไม่ถูกต้อง';
    }

    if ((int)$file['size'] <= 0) {
        return 'ไฟล์ว่างเปล่า';
    }
    if ((int)$file['size'] > PRODUCT_IMAGE_MAX_BYTES) {
        return 'ไฟล์ใหญ่เกิน 3 MB กรุณาย่อรูปก่อนอัปโหลด';
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return 'ไฟล์นี้ไม่ใช่รูปภาพ';
    }

    $types = product_image_types();
    if (!isset($types[$info[2]])) {
        return 'รองรับเฉพาะไฟล์ JPG, PNG และ GIF';
    }

    return '';
}


/* ============================================================
 *  อ่านรูป
 * ============================================================ */

/* ---------- รูปของสินค้าตัวเดียว ----------
 * คืน array ของ array('image_id', 'sort_no', 'image_file', 'url')
 * เรียงตาม sort_no แล้ว รูปแรกในผลลัพธ์คือรูปหลัก
 */
function product_image_list($product_id)
{
    $rows = product_image_map(array((int)$product_id));
    $key  = (int)$product_id;

    return isset($rows[$key]) ? $rows[$key] : array();
}


/* ---------- รูปของสินค้าหลายตัวพร้อมกัน ----------
 * คืน array ที่ key เป็น product_id ค่าเป็นรายการรูปของสินค้าตัวนั้น
 *
 * มีไว้เพื่อไม่ให้หน้าที่แสดงสินค้าเป็นร้อยรายการต้องยิง query ทีละตัว
 * ($product_ids = null คือเอาทั้งหมด ใช้ตอนหน้าเบิกโหลดสินค้าทั้งคลังมาให้เลือก)
 *
 * ไม่ทำเป็น JOIN เข้าไปใน stock_list() เพราะสินค้าหนึ่งตัวมีได้ 3 รูป
 * แถวสินค้าจะกลายเป็นสามแถว แล้วทุกที่ที่นับจำนวนสินค้าจะได้เลขผิดทันที
 */
function product_image_map($product_ids = null)
{
    $connect = db_connect();

    $sql = "SELECT `image_id`, `product_id`, `sort_no`, `image_file`
            FROM `tb_product_image`";

    /* กรองด้วยรายการ id ที่ผู้เรียกส่งมา — ค่าทั้งหมดถูกแปลงเป็น int แล้ว
       จึงต่อเข้าไปในสตริงได้โดยไม่เปิดช่อง SQL injection
       (ใช้ prepared statement ไม่ได้เพราะจำนวน ? เปลี่ยนตามความยาวรายการ) */
    if (is_array($product_ids)) {
        if (count($product_ids) === 0) { return array(); }

        $ids = array();
        for ($i = 0; $i < count($product_ids); $i++) {
            $id = (int)$product_ids[$i];
            if ($id > 0) { $ids[] = $id; }
        }
        if (count($ids) === 0) { return array(); }

        $sql .= ' WHERE `product_id` IN (' . implode(',', $ids) . ')';
    }

    $sql .= ' ORDER BY `product_id` ASC, `sort_no` ASC';

    $res = mysqli_query($connect, $sql);
    if (!$res) { return array(); }

    $out = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $pid = (int)$row['product_id'];
        if (!isset($out[$pid])) { $out[$pid] = array(); }

        $out[$pid][] = array(
            'image_id'   => (int)$row['image_id'],
            'sort_no'    => (int)$row['sort_no'],
            'image_file' => $row['image_file'],
            'url'        => product_image_url($row['image_file'])
        );
    }
    mysqli_free_result($res);

    return $out;
}


/* ---------- ช่องว่างช่องแรกของสินค้าตัวนี้ ----------
 * คืน 0 ถ้าเต็มแล้ว ผู้เรียกเอาไปตัดสินว่าจะรับรูปเพิ่มไหม
 */
function product_image_free_slot($product_id)
{
    $used = array();
    $rows = product_image_list($product_id);

    for ($i = 0; $i < count($rows); $i++) {
        $used[(int)$rows[$i]['sort_no']] = true;
    }

    for ($n = 1; $n <= PRODUCT_IMAGE_MAX; $n++) {
        if (!isset($used[$n])) { return $n; }
    }
    return 0;
}


/* ============================================================
 *  เพิ่มรูป
 * ============================================================ */
function product_image_save($product_id, $file, $actor)
{
    $product_id = (int)$product_id;

    /* สินค้าที่ยังไม่ได้บันทึกไม่มี id จริง จะแนบรูปให้ไม่ได้
       หน้าจอกันไว้อีกชั้นแล้ว แต่ต้องกันที่นี่ด้วยเผื่อมีคนยิง API ตรง ๆ */
    if ($product_id <= 0) {
        return db_fail('กรุณาบันทึกสินค้าก่อน แล้วจึงแนบรูปได้');
    }

    $connect = db_connect();

    /* ยืนยันว่าสินค้ามีอยู่จริง ไม่งั้นจะได้แถวรูปที่ชี้ไปหาสินค้าที่ไม่มี
       (foreign key กันให้อยู่แล้ว แต่ข้อความผิดพลาดจากฐานข้อมูลอ่านไม่รู้เรื่อง) */
    $stmt = mysqli_prepare($connect,
        "SELECT `product_name` FROM `tb_product` WHERE `product_id` = ? LIMIT 1");
    if (!$stmt) { return db_fail('ตรวจสอบสินค้าไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $product_name);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return db_fail('ไม่พบสินค้าที่ต้องการ'); }

    $error = product_image_validate($file);
    if ($error !== '') { return db_fail($error); }

    $slot = product_image_free_slot($product_id);
    if ($slot === 0) {
        return db_fail('สินค้านี้มีรูปครบ ' . PRODUCT_IMAGE_MAX . ' รูปแล้ว '
                     . 'กรุณาลบรูปเดิมออกก่อนถ้าต้องการเปลี่ยน');
    }

    $info  = @getimagesize($file['tmp_name']);
    $types = product_image_types();
    $ext   = $types[$info[2]];

    $dir = product_image_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return db_fail('สร้างโฟลเดอร์เก็บรูปไม่สำเร็จ: assets/img/product/');
    }
    if (!is_writable($dir)) {
        return db_fail('เขียนไฟล์ลงโฟลเดอร์ assets/img/product/ ไม่ได้ กรุณาตรวจสิทธิ์โฟลเดอร์');
    }

    /* ตั้งชื่อไฟล์เองทั้งหมด ไม่แตะชื่อเดิมจากเครื่องผู้ใช้
       ต่อท้ายด้วยเวลาและเลขสุ่ม เพราะอัปโหลดสองรูปในวินาทีเดียวกันเกิดได้จริง
       ตอนผู้ใช้เลือกหลายไฟล์พร้อมกัน */
    $name = 'p' . $product_id . '-' . $slot . '-' . time()
          . mt_rand(100, 999) . '.' . $ext;
    $dest = $dir . $name;

    if (!product_image_write($file['tmp_name'], $dest, $info, $ext)) {
        return db_fail('บันทึกไฟล์รูปไม่สำเร็จ');
    }

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_product_image`
         SET `product_id` = ?, `sort_no` = ?, `image_file` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        @unlink($dest);                     // เขียนไฟล์ไปแล้วแต่บันทึกไม่ได้ ต้องเก็บกวาด
        return db_fail('บันทึกรูปไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'iiss', $product_id, $slot, $name, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        @unlink($dest);
        return db_fail(db_error_message($errno), $errno);
    }
    $image_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    $out = db_ok('เพิ่มรูปของ "' . $product_name . '" เรียบร้อย', $image_id);
    $out['images'] = product_image_list($product_id);
    return $out;
}


/* ============================================================
 *  ลบรูป
 * ============================================================ */
function product_image_delete($image_id, $actor)
{
    $image_id = (int)$image_id;
    if ($image_id <= 0) { return db_fail('ไม่พบรูปที่ต้องการลบ'); }

    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT `product_id`, `image_file` FROM `tb_product_image`
         WHERE `image_id` = ? LIMIT 1");
    if (!$stmt) { return db_fail('อ่านข้อมูลรูปไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $image_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $product_id, $image_file);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return db_fail('ไม่พบรูปที่ต้องการลบ'); }

    $stmt = mysqli_prepare($connect,
        "DELETE FROM `tb_product_image` WHERE `image_id` = ?");
    if (!$stmt) { return db_fail('ลบรูปไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $image_id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* ลบไฟล์หลังลบแถวสำเร็จเท่านั้น ถ้าลบไฟล์ก่อนแล้วลบแถวพลาด
       จะเหลือแถวที่ชี้ไปยังไฟล์ที่ไม่มีอยู่ ซึ่งกลายเป็นรูปเสียบนหน้าจอ

       $actor ไม่ได้ถูกใช้เพราะแถวถูกลบทิ้งไปแล้ว ไม่มีที่ให้เขียน updated_by
       รับพารามิเตอร์ไว้ให้เหมือนฟังก์ชันอื่นในระบบ และเผื่อวันหนึ่ง
       เปลี่ยนไปเก็บเป็นประวัติแทนการลบจริง */
    @unlink(product_image_dir() . $image_file);

    $out = db_ok('ลบรูปเรียบร้อย', $image_id);
    $out['images'] = product_image_list($product_id);
    return $out;
}


/* ============================================================
 *  หน่วยความจำที่ต้องใช้ย่อรูป
 * ============================================================
 *  GD คลายรูปเป็นบิตแมปเต็มความละเอียดก่อนย่อเสมอ กินหน่วยความจำ
 *  เท่ากับ กว้าง x สูง x 4 ไบต์ — ไม่เกี่ยวกับขนาดไฟล์เลยแม้แต่น้อย
 *
 *  รูปจากมือถือหรือกล้องสมัยนี้ 4K (3840x2160) ต้องใช้ราว 32 MB
 *  ทั้งที่ไฟล์อาจเล็กแค่ 127 KB — การตรวจขนาดไฟล์อย่างเดียวจึงไม่พอ
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องตรวจก่อน ไม่ปล่อยให้ GD ล้มเอง
 *  ---------------------------------------------------------------
 *  หน่วยความจำไม่พอทำให้ PHP ตายกลางคันด้วย fatal error ซึ่งดักไม่ได้
 *  และบนเครื่องที่เปิด display_errors ข้อความ error จะถูกพ่นออกไปปนกับ JSON
 *  หน้าจอจึงได้คำตอบที่แปลงไม่ได้ ทั้งที่ HTTP ตอบ 200
 *
 *  เกิดขึ้นจริงมาแล้วและหาสาเหตุยากมาก เพราะทุกอย่างดูเหมือนสำเร็จ
 *  ทั้งสถานะ 200 และไม่มีอะไรใน error log
 * ============================================================ */

/* แปลงค่า memory_limit ("24M", "512K", "-1") เป็นจำนวนไบต์
   คืน 0 เมื่อไม่จำกัด */
function product_image_memory_limit()
{
    $raw = trim((string)@ini_get('memory_limit'));
    if ($raw === '' || $raw === '-1') { return 0; }

    $unit = strtolower(substr($raw, -1));
    $num  = (float)$raw;

    if ($unit === 'g') { return (int)($num * 1024 * 1024 * 1024); }
    if ($unit === 'm') { return (int)($num * 1024 * 1024); }
    if ($unit === 'k') { return (int)($num * 1024); }

    return (int)$num;
}


/* พอจะย่อรูปขนาดนี้ไหวไหม — ขอเพิ่มหน่วยความจำให้เองถ้าโฮสต์ยอม */
function product_image_memory_ok($info)
{
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w <= 0 || $h <= 0) { return false; }

    /* ต้นทาง (กว้าง x สูง x 4) + ปลายทางหลังย่อ + เผื่อที่ทำงานของ GD เอง */
    $need = ($w * $h * 4)
          + (PRODUCT_IMAGE_MAX_SIDE * PRODUCT_IMAGE_MAX_SIDE * 4)
          + (4 * 1024 * 1024);

    $limit = product_image_memory_limit();
    if ($limit === 0) { return true; }                       // ไม่จำกัด

    $free = $limit - memory_get_usage();
    if ($need <= $free) { return true; }

    /* ขอเพิ่มให้พอดีกับที่ต้องใช้ บวกเผื่ออีกนิด
       โฮสต์ที่ล็อกค่านี้ไว้จะไม่ยอมเปลี่ยน ซึ่งตรวจซ้ำข้างล่างแล้ว */
    $want = (int)ceil(($need + memory_get_usage()) / (1024 * 1024)) + 8;
    @ini_set('memory_limit', $want . 'M');

    $limit = product_image_memory_limit();
    if ($limit === 0) { return true; }

    return ($need <= ($limit - memory_get_usage()));
}


/* ============================================================
 *  เขียนไฟล์ลงดิสก์ พร้อมย่อถ้าใหญ่เกิน
 * ============================================================
 *  ย่อด้วย GD ถ้ามี — ได้ผลพลอยได้ที่สำคัญกว่าขนาดไฟล์คือ
 *  รูปที่ผ่าน imagecreatefrom* แล้วเขียนใหม่จะเหลือแต่ข้อมูลภาพจริง
 *  โค้ดอะไรก็ตามที่แอบฝังมาในไฟล์รูปจะหายไปด้วย
 *
 *  ไม่มี GD หรือย่อไม่สำเร็จ ก็ก๊อปไฟล์เดิมไป — ผ่าน getimagesize มาแล้ว
 *  ว่าเป็นรูปจริง และนามสกุลก็ถูกบังคับจากผลการอ่านหัวไฟล์
 * ============================================================ */
function product_image_write($src, $dest, $info, $ext)
{
    $w   = (int)$info[0];
    $h   = (int)$info[1];
    $max = PRODUCT_IMAGE_MAX_SIDE;

    if (!extension_loaded('gd') || $w <= 0 || $h <= 0) {
        return @copy($src, $dest);
    }

    /* หน่วยความจำไม่พอย่อ — ก๊อปไฟล์เดิมไปแทน ห้ามเรียก GD เด็ดขาด
       เพราะจะตายกลางคันแล้วพ่น fatal error ปนไปกับ JSON (ดูหัวข้อข้างบน)

       ไฟล์ที่ได้จะใหญ่กว่าที่ควรเป็น แต่ผ่านการตรวจหัวไฟล์มาแล้วว่าเป็นรูปจริง
       และยังถูกจำกัดขนาดไฟล์ไว้ที่ 3 MB อยู่ดี
       ผู้เรียกเป็นคนตัดสินว่าจะเตือนผู้ใช้ไหม (ดู product_image_save) */
    if (!product_image_memory_ok($info)) {
        return @copy($src, $dest);
    }

    if ($ext === 'jpg')      { $img = @imagecreatefromjpeg($src); }
    elseif ($ext === 'png')  { $img = @imagecreatefrompng($src); }
    else                     { $img = @imagecreatefromgif($src); }

    if (!$img) { return @copy($src, $dest); }

    /* เล็กกว่าเพดานอยู่แล้วก็ไม่ต้องย่อ แต่ยังเขียนใหม่ผ่าน GD เหมือนกัน
       เพื่อให้ได้ผลพลอยได้เรื่องการล้างของที่ฝังมาในไฟล์ */
    $nw = $w;
    $nh = $h;
    if ($w > $max || $h > $max) {
        if ($w >= $h) {
            $nw = $max;
            $nh = (int)round($h * ($max / $w));
        } else {
            $nh = $max;
            $nw = (int)round($w * ($max / $h));
        }
    }
    if ($nw < 1) { $nw = 1; }
    if ($nh < 1) { $nh = 1; }

    $out = @imagecreatetruecolor($nw, $nh);
    if (!$out) { @imagedestroy($img); return @copy($src, $dest); }

    /* PNG กับ GIF มีพื้นโปร่งได้ ถ้าไม่บอกให้เก็บ alpha ไว้
       พื้นโปร่งจะกลายเป็นสีดำทึบหลังย่อ */
    if ($ext === 'png' || $ext === 'gif') {
        @imagealphablending($out, false);
        @imagesavealpha($out, true);
        $clear = @imagecolorallocatealpha($out, 0, 0, 0, 127);
        @imagefilledrectangle($out, 0, 0, $nw, $nh, $clear);
    }

    @imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    if ($ext === 'jpg')      { $ok = @imagejpeg($out, $dest, 85); }
    elseif ($ext === 'png')  { $ok = @imagepng($out, $dest, 6); }
    else                     { $ok = @imagegif($out, $dest); }

    @imagedestroy($img);
    @imagedestroy($out);

    if (!$ok) { return @copy($src, $dest); }
    return true;
}
