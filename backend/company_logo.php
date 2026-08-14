<?php
/* ============================================================
 *  backend/company_logo.php — โลโก้บริษัท (ใช้เป็นหัวกระดาษเอกสาร)
 *
 *  ไฟล์จริงเก็บที่ assets/img/company/ ส่วนตารางเก็บแค่ชื่อไฟล์
 *  (ดูเหตุผลที่ sql/014_company_logo.sql)
 *
 *  ---------------------------------------------------------------
 *  กติกาความปลอดภัยของการรับไฟล์จากผู้ใช้
 *  ---------------------------------------------------------------
 *  1. ไม่เชื่อชื่อไฟล์ที่ส่งมา — ตั้งชื่อใหม่เองทั้งหมด
 *     ชื่อจากผู้ใช้พาทั้ง ../ และ .php มาได้ ซึ่งกลายเป็นการยึดเครื่อง
 *  2. ไม่เชื่อนามสกุลและไม่เชื่อ MIME ที่เบราว์เซอร์แจ้ง — ทั้งคู่ปลอมได้
 *     ใช้ getimagesize() อ่านหัวไฟล์จริงว่าเป็นรูปไหม
 *  3. เขียนไฟล์ด้วยนามสกุลที่เราเลือกเองจากผลการอ่านหัวไฟล์เท่านั้น
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/company.php';

/* ขนาดสูงสุดที่รับ 2 MB — โลโก้ที่ใหญ่กว่านี้คือรูปที่ยังไม่ได้ย่อ
   และจะทำให้หน้าที่แสดงรายการบริษัทสิบกว่าแถวโหลดช้าโดยไม่จำเป็น */
define('COMPANY_LOGO_MAX_BYTES', 2 * 1024 * 1024);

/* ด้านที่ยาวที่สุดหลังย่อ — พอสำหรับหัวกระดาษที่พิมพ์ 300 dpi */
define('COMPANY_LOGO_MAX_SIDE', 600);


function company_logo_dir()
{
    return dirname(dirname(__FILE__)) . '/assets/img/company/';
}

/* ---------- ที่อยู่ไว้ใส่ใน src ของ <img> ----------
 * คืนค่าว่างถ้ายังไม่มีโลโก้ หรือไฟล์หายไปจากดิสก์
 * (ไฟล์หายแต่ชื่อยังอยู่ในตารางเกิดได้ ถ้ามีคนไปลบไฟล์เอง —
 *  ต้องคืนค่าว่างไม่ใช่ที่อยู่ที่เปิดไม่ได้ ไม่งั้นจะเห็นไอคอนรูปเสียบนเอกสาร)
 */
function company_logo_url($file, $base = '')
{
    $file = trim((string)$file);
    if ($file === '') { return ''; }

    if (!file_exists(company_logo_dir() . $file)) { return ''; }

    return $base . 'assets/img/company/' . rawurlencode($file);
}


/* ---------- ชนิดรูปที่รับ ----------
 * รับสามชนิดที่เบราว์เซอร์แสดงได้ทั้งหมดและ GD อ่านได้
 * ไม่รับ SVG เพราะ SVG คือ XML ที่ฝัง <script> ได้ การเปิดไฟล์ SVG
 * ที่ผู้ใช้อัปโหลดในโดเมนเดียวกันจึงเท่ากับเปิดช่อง XSS
 */
function company_logo_types()
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
function company_logo_validate($file)
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
    if ((int)$file['size'] > COMPANY_LOGO_MAX_BYTES) {
        return 'ไฟล์ใหญ่เกิน 2 MB กรุณาย่อรูปก่อนอัปโหลด';
    }

    /* อ่านหัวไฟล์จริง ไม่ดูนามสกุลและไม่ดู MIME ที่เบราว์เซอร์แจ้ง เพราะปลอมได้ทั้งคู่ */
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return 'ไฟล์นี้ไม่ใช่รูปภาพ';
    }

    $types = company_logo_types();
    if (!isset($types[$info[2]])) {
        return 'รองรับเฉพาะไฟล์ JPG, PNG และ GIF';
    }

    return '';
}


/* ---------- บันทึกโลโก้ของบริษัทหนึ่ง ---------- */
function company_logo_save($company_id, $file, $actor)
{
    $company_id = (int)$company_id;
    if ($company_id <= 0) {
        return db_fail('ไม่พบบริษัทที่ต้องการ');
    }

    $company = company_find($company_id);
    if ($company === null) {
        return db_fail('ไม่พบบริษัทที่ต้องการ');
    }

    $error = company_logo_validate($file);
    if ($error !== '') { return db_fail($error); }

    $info  = @getimagesize($file['tmp_name']);
    $types = company_logo_types();
    $ext   = $types[$info[2]];

    $dir = company_logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return db_fail('สร้างโฟลเดอร์เก็บรูปไม่สำเร็จ: assets/img/company/');
    }
    if (!is_writable($dir)) {
        return db_fail('เขียนไฟล์ลงโฟลเดอร์ assets/img/company/ ไม่ได้ กรุณาตรวจสิทธิ์โฟลเดอร์');
    }

    /* ตั้งชื่อไฟล์เองทั้งหมด ไม่แตะชื่อเดิมจากเครื่องผู้ใช้
       ต่อท้ายด้วยเวลาเพื่อให้ URL เปลี่ยนทุกครั้งที่เปลี่ยนรูป
       ไม่งั้นเบราว์เซอร์จะยังโชว์รูปเก่าจากแคชทั้งที่อัปโหลดใหม่ไปแล้ว */
    $name = 'company-' . $company_id . '-' . time() . '.' . $ext;
    $dest = $dir . $name;

    if (!company_logo_write($file['tmp_name'], $dest, $info, $ext)) {
        return db_fail('บันทึกไฟล์รูปไม่สำเร็จ');
    }

    $old = isset($company['logo_file']) ? trim($company['logo_file']) : '';

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_company` SET `logo_file` = ?, `updated_by` = ? WHERE `company_id` = ?");
    if (!$stmt) {
        @unlink($dest);                     // เขียนไฟล์ไปแล้วแต่บันทึกไม่ได้ ต้องเก็บกวาด
        return db_fail('บันทึกโลโก้ไม่สำเร็จ');
    }

    mysqli_stmt_bind_param($stmt, 'ssi', $name, $actor, $company_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        @unlink($dest);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* ลบไฟล์เก่าหลังบันทึกสำเร็จเท่านั้น ถ้าลบก่อนแล้วบันทึกพลาด
       จะเหลือบริษัทที่ไม่มีทั้งรูปเก่าและรูปใหม่ */
    if ($old !== '' && $old !== $name) {
        @unlink($dir . $old);
    }

    $out = db_ok('อัปโหลดโลโก้ของ "' . $company['company_name'] . '" เรียบร้อย', $company_id);
    $out['logo_file'] = $name;
    $out['logo_url']  = 'assets/img/company/' . rawurlencode($name);
    return $out;
}


/* ---------- เขียนไฟล์ลงดิสก์ พร้อมย่อถ้าใหญ่เกิน ----------
 * ย่อด้วย GD ถ้ามี — ได้ผลพลอยได้ที่สำคัญกว่าขนาดไฟล์คือ
 * รูปที่ผ่าน imagecreatefrom* แล้วเขียนใหม่จะเหลือแต่ข้อมูลภาพจริง
 * โค้ดอะไรก็ตามที่แอบฝังมาในไฟล์รูปจะหายไปด้วย
 *
 * ไม่มี GD หรือย่อไม่สำเร็จ ก็ก๊อปไฟล์เดิมไป — ผ่าน getimagesize มาแล้ว
 * ว่าเป็นรูปจริง และนามสกุลก็ถูกบังคับจากผลการอ่านหัวไฟล์
 */
function company_logo_write($src, $dest, $info, $ext)
{
    $w = (int)$info[0];
    $h = (int)$info[1];
    $max = COMPANY_LOGO_MAX_SIDE;

    if (!extension_loaded('gd') || $w <= 0 || $h <= 0) {
        return @move_uploaded_file($src, $dest);
    }

    /* เล็กอยู่แล้วก็ยังเขียนใหม่ผ่าน GD เพื่อล้างสิ่งแปลกปลอมที่แฝงมา */
    $ratio = 1;
    if ($w > $max || $h > $max) {
        $ratio = ($w > $h) ? ($max / $w) : ($max / $h);
    }
    $nw = (int)round($w * $ratio);
    $nh = (int)round($h * $ratio);
    if ($nw < 1) { $nw = 1; }
    if ($nh < 1) { $nh = 1; }

    if ($ext === 'jpg')      { $img = @imagecreatefromjpeg($src); }
    elseif ($ext === 'png')  { $img = @imagecreatefrompng($src); }
    else                     { $img = @imagecreatefromgif($src); }

    if (!$img) { return @move_uploaded_file($src, $dest); }

    $out = @imagecreatetruecolor($nw, $nh);
    if (!$out) { @imagedestroy($img); return @move_uploaded_file($src, $dest); }

    /* โลโก้เกือบทุกอันมีพื้นหลังโปร่งใส ถ้าไม่รักษาช่องอัลฟาไว้
       พื้นโปร่งจะกลายเป็นสีดำทึบเมื่อวางบนหัวกระดาษสีขาว */
    if ($ext === 'png' || $ext === 'gif') {
        @imagealphablending($out, false);
        @imagesavealpha($out, true);
        $transparent = @imagecolorallocatealpha($out, 255, 255, 255, 127);
        @imagefilledrectangle($out, 0, 0, $nw, $nh, $transparent);
    }

    @imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    if ($ext === 'jpg')      { $ok = @imagejpeg($out, $dest, 88); }
    elseif ($ext === 'png')  { $ok = @imagepng($out, $dest, 6); }
    else                     { $ok = @imagegif($out, $dest); }

    @imagedestroy($img);
    @imagedestroy($out);

    if (!$ok) { return @move_uploaded_file($src, $dest); }
    return true;
}


/* ---------- เอาโลโก้ออก ---------- */
function company_logo_remove($company_id, $actor)
{
    $company_id = (int)$company_id;
    $company    = company_find($company_id);
    if ($company === null) { return db_fail('ไม่พบบริษัทที่ต้องการ'); }

    $old = isset($company['logo_file']) ? trim($company['logo_file']) : '';
    if ($old === '') {
        return db_fail('บริษัทนี้ยังไม่มีโลโก้');
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_company` SET `logo_file` = '', `updated_by` = ? WHERE `company_id` = ?");
    if (!$stmt) { return db_fail('ลบโลโก้ไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $company_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    @unlink(company_logo_dir() . $old);

    return db_ok('ลบโลโก้ของ "' . $company['company_name'] . '" เรียบร้อย', $company_id);
}


/* ---------- โลโก้ของหน่วยงานหนึ่ง ----------
 * ใบเบิกผูกกับ "หน่วยงาน" (tb_department) ส่วนโลโก้อยู่ที่ "บริษัท" (tb_company)
 * ฟังก์ชันนี้ต่อสองอย่างเข้าด้วยกันให้หน้าพิมพ์เอกสารเรียกใช้ได้ในบรรทัดเดียว
 */
function company_logo_for_department($department_id)
{
    $department_id = (int)$department_id;

    $out = array('company_id' => 0, 'company_name' => '', 'logo_file' => '', 'form_code' => '');
    if ($department_id <= 0) { return $out; }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT c.`company_id`, c.`company_name`, c.`logo_file`, c.`form_code`
         FROM `tb_department` d
         INNER JOIN `tb_company` c ON c.`company_id` = d.`company_id`
         WHERE d.`department_id` = ? LIMIT 1");
    if (!$stmt) { return $out; }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cid, $cname, $logo, $form_code);

    if (mysqli_stmt_fetch($stmt)) {
        $out['company_id']   = (int)$cid;
        $out['company_name'] = $cname;
        $out['logo_file']    = ($logo === null) ? '' : $logo;
        $out['form_code']    = ($form_code === null) ? '' : $form_code;
    }
    mysqli_stmt_close($stmt);

    return $out;
}
