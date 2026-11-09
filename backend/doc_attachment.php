<?php
/* ============================================================
 *  backend/doc_attachment.php — ไฟล์แนบของเอกสาร (รูป/ไฟล์ใบบิลของใบรับเข้า)
 *
 *  ใช้เป็นหลักฐานให้ฝ่ายบัญชี: คลังถ่ายรูปหรือสแกนใบบิลแนบตอนบันทึกรับเข้า
 *  แล้วฝ่ายบัญชีเปิดดู/ดาวน์โหลดได้จากประวัติรับเข้า
 *
 *  ---------------------------------------------------------------
 *  ที่เก็บไฟล์: storage/doc_attach/ — ไม่ใช่ assets/
 *  ---------------------------------------------------------------
 *  ใบบิลเป็นเอกสารการเงิน ห้ามเปิดได้ด้วยการเดา URL
 *  storage/.htaccess ปิดการเปิดตรงผ่านเว็บ (สร้างให้เองถ้ายังไม่มี)
 *  ดู/ดาวน์โหลดได้ทางเดียวคือ api/attachment.php ซึ่งตรวจสิทธิ์
 *  แบบเดียวกับการเปิดเอกสารใบนั้น (doc_can_read)
 *  ชื่อไฟล์ยังสุ่มยาวอีกชั้น เผื่อโฮสต์ปิด AllowOverride จน .htaccess ไม่มีผล
 *
 *  ---------------------------------------------------------------
 *  กติกาการรับไฟล์ — ชุดเดียวกับรูปสินค้า (backend/product_image.php)
 *  ---------------------------------------------------------------
 *  1. ไม่เชื่อชื่อไฟล์ นามสกุล และ MIME ที่ส่งมา — ทั้งหมดปลอมได้
 *  2. รูป: getimagesize() อ่านหัวไฟล์จริง แล้วเขียนใหม่ผ่าน GD
 *     (ของที่แอบฝังมาในไฟล์รูปหายไปด้วย) ย่อเหลือด้านยาว 2400 px
 *     ใหญ่กว่ารูปสินค้ามาก เพราะตัวหนังสือในบิลต้องยังอ่านออก
 *  3. PDF: ตรวจลายเซ็นหัวไฟล์ "%PDF-" เก็บตามเดิม (PHP 5.2 ไม่มีตัวอ่าน PDF ให้เขียนใหม่)
 *  4. ชื่อไฟล์บนดิสก์ระบบตั้งเอง นามสกุลเลือกจากผลการตรวจเท่านั้น
 *     ชื่อเดิมเก็บไว้แสดงอย่างเดียว ไม่เคยเอาไปประกอบ path
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/product_image.php';   // product_image_write() ย่อ/เขียนรูปใหม่

/* จำนวนไฟล์สูงสุดต่อเอกสาร — บิลหลายหน้า + ใบกำกับภาษี + ใบส่งของ ยังไม่เกิน */
define('DOC_ATTACH_MAX', 10);

/* ขนาดสูงสุดต่อไฟล์ 10 MB — รูปจากมือถือรุ่นใหม่กับ PDF สแกนหลายหน้า
   (post_max_size ของเซิร์ฟเวอร์ต้องไม่ต่ำกว่านี้ ดูข้อความผิดพลาดใน api/attachment.php) */
define('DOC_ATTACH_MAX_BYTES', 10 * 1024 * 1024);

/* ด้านยาวสุดของรูปหลังย่อ */
define('DOC_ATTACH_IMAGE_SIDE', 2400);

/* ประเภทเอกสารที่แนบไฟล์ได้ — ตอนนี้ใบรับเข้าอย่างเดียว */
function doc_attachment_types_allowed()
{
    return array('receive');
}


/* ---------- รัน sql/087 แล้วหรือยัง ----------
 * ยังไม่รัน = ฟีเจอร์ปิดเงียบ ๆ หน้าเดิมทำงานต่อได้ ไม่พังทั้งหน้า */
function doc_attachment_ready()
{
    static $ready = null;
    if ($ready !== null) { return $ready; }

    $res = @mysqli_query(db_connect(), "SHOW TABLES LIKE 'tb_doc_attachment'");
    $ready = ($res && mysqli_num_rows($res) > 0);
    if ($res) { mysqli_free_result($res); }
    return $ready;
}


/* ---------- โฟลเดอร์เก็บไฟล์ ----------
 * สร้าง storage/.htaccess ปิดการเปิดตรงทุกครั้งที่ยังไม่มี
 * เขียนไว้ที่ระดับ storage/ ทั้งก้อน วันหลังเก็บไฟล์อย่างอื่นก็ได้รับการป้องกันด้วย */
function doc_attachment_dir()
{
    $root = dirname(dirname(__FILE__)) . '/storage/';
    $dir  = $root . 'doc_attach/';

    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $guard = $root . '.htaccess';
    if (is_dir($root) && !file_exists($guard)) {
        @file_put_contents($guard,
              "# ไฟล์ใน storage/ เปิดตรงผ่านเว็บไม่ได้ — ดู/ดาวน์โหลดผ่าน api/attachment.php เท่านั้น\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}


/* ---------- ชื่อไฟล์เดิมสำหรับแสดง ----------
 * ตัดส่วน path ทิ้ง (เบราว์เซอร์เก่าบางตัวส่ง C:\fakepath\... มา) ตัดอักขระควบคุม
 * และไม่ให้ยาวเกินคอลัมน์ ค่านี้ใช้แสดงผลอย่างเดียว ห้ามเอาไปประกอบ path */
function doc_attachment_display_name($name)
{
    $name = str_replace('\\', '/', (string)$name);
    $pos  = strrpos($name, '/');
    if ($pos !== false) { $name = substr($name, $pos + 1); }
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $name));
    if ($name === '') { $name = 'ไฟล์แนบ'; }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 150, 'UTF-8');
    } elseif (strlen($name) > 150) {
        $name = substr($name, 0, 150);
    }
    return $name;
}


/* ---------- ตรวจไฟล์ที่อัปโหลด ----------
 * คืน array('error' => '...') หรือ array('kind' => 'image'|'pdf', 'ext', 'mime', 'info')
 */
function doc_attachment_inspect($file)
{
    if (!is_array($file) || !isset($file['error'])) {
        return array('error' => 'ไม่พบไฟล์ที่อัปโหลด');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return array('error' => 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('error' => 'ยังไม่ได้เลือกไฟล์');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return array('error' => 'อัปโหลดไม่สำเร็จ (รหัสข้อผิดพลาด ' . (int)$file['error'] . ')');
    }

    /* ต้องเป็นไฟล์ที่อัปโหลดมาจริง ไม่ใช่ path ที่ถูกยัดเข้ามาในตัวแปร */
    if (!is_uploaded_file($file['tmp_name'])) {
        return array('error' => 'ไฟล์ไม่ถูกต้อง');
    }
    if ((int)$file['size'] <= 0) {
        return array('error' => 'ไฟล์ว่างเปล่า');
    }
    if ((int)$file['size'] > DOC_ATTACH_MAX_BYTES) {
        return array('error' => 'ไฟล์ใหญ่เกิน 10 MB');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info !== false) {
        $types = product_image_types();      // jpg / png / gif
        if (!isset($types[$info[2]])) {
            return array('error' => 'รูปภาพรองรับเฉพาะ JPG, PNG และ GIF');
        }
        $ext  = $types[$info[2]];
        $mime = ($ext === 'jpg') ? 'image/jpeg' : 'image/' . $ext;
        return array('kind' => 'image', 'ext' => $ext, 'mime' => $mime, 'info' => $info);
    }

    $fh   = @fopen($file['tmp_name'], 'rb');
    $head = $fh ? fread($fh, 5) : '';
    if ($fh) { fclose($fh); }
    if ($head === '%PDF-') {
        return array('kind' => 'pdf', 'ext' => 'pdf', 'mime' => 'application/pdf', 'info' => null);
    }

    return array('error' => 'รองรับเฉพาะรูปภาพ (JPG, PNG, GIF) และไฟล์ PDF');
}


/* ============================================================
 *  อ่าน
 * ============================================================ */

function doc_attachment_row_out($r)
{
    return array(
        'attach_id'  => (int)$r['attach_id'],
        'doc_id'     => (int)$r['doc_id'],
        'orig_name'  => $r['orig_name'],
        'mime_type'  => $r['mime_type'],
        'is_image'   => (strpos($r['mime_type'], 'image/') === 0),
        'file_size'  => (int)$r['file_size'],
        'created_at' => $r['created_at'],
        'created_by' => ($r['created_by'] === null) ? '' : $r['created_by'],
        'url'        => 'api/attachment.php?action=view&id=' . (int)$r['attach_id']
    );
}

function doc_attachment_list($doc_id)
{
    $out = array();
    if (!doc_attachment_ready()) { return $out; }

    $stmt = mysqli_prepare(db_connect(),
        "SELECT `attach_id`, `doc_id`, `file_name`, `orig_name`, `mime_type`, `file_size`,
                `created_at`, `created_by`
         FROM `tb_doc_attachment` WHERE `doc_id` = ? ORDER BY `attach_id`");
    if (!$stmt) { return $out; }

    $doc_id = (int)$doc_id;
    mysqli_stmt_bind_param($stmt, 'i', $doc_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $did, $fname, $oname, $mime, $size, $cat, $cby);
    while (mysqli_stmt_fetch($stmt)) {
        $out[] = doc_attachment_row_out(array(
            'attach_id' => $id, 'doc_id' => $did, 'orig_name' => $oname, 'mime_type' => $mime,
            'file_size' => $size, 'created_at' => $cat, 'created_by' => $cby
        ));
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/* รายการสำหรับส่งให้หน้าเว็บ — ติดธง can_delete ของผู้ใช้คนนี้ไว้ทุกไฟล์
   ทุกคำตอบที่ส่งรายการไฟล์กลับไป (เปิดใบ / แนบเพิ่ม / ลบ) ต้องใช้ตัวนี้
   ไม่งั้นหน้าเว็บวาดใหม่แล้วปุ่มลบหายไปจนกว่าจะเปิดใบใหม่ */
function doc_attachment_list_for($doc_id, $actor, $actor_role)
{
    $list = doc_attachment_list($doc_id);
    for ($i = 0; $i < count($list); $i++) {
        $list[$i]['can_delete'] = doc_attachment_can_delete($list[$i], $actor, $actor_role);
    }
    return $list;
}

/* แถวเดียว รวมชื่อไฟล์บนดิสก์ (file_name) — ใช้ภายในเท่านั้น ห้ามส่งออกไปหน้าเว็บ */
function doc_attachment_find($attach_id)
{
    if (!doc_attachment_ready()) { return null; }

    $stmt = mysqli_prepare(db_connect(),
        "SELECT `attach_id`, `doc_id`, `file_name`, `orig_name`, `mime_type`, `file_size`,
                `created_at`, `created_by`
         FROM `tb_doc_attachment` WHERE `attach_id` = ? LIMIT 1");
    if (!$stmt) { return null; }

    $attach_id = (int)$attach_id;
    mysqli_stmt_bind_param($stmt, 'i', $attach_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $did, $fname, $oname, $mime, $size, $cat, $cby);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    if (!$found) { return null; }

    return array(
        'attach_id' => (int)$id, 'doc_id' => (int)$did, 'file_name' => $fname,
        'orig_name' => $oname, 'mime_type' => $mime, 'file_size' => (int)$size,
        'created_at' => $cat, 'created_by' => ($cby === null) ? '' : $cby
    );
}

function doc_attachment_count($doc_id)
{
    if (!doc_attachment_ready()) { return 0; }
    $doc_id = (int)$doc_id;
    $res = mysqli_query(db_connect(),
        "SELECT COUNT(*) FROM `tb_doc_attachment` WHERE `doc_id` = " . $doc_id);
    if (!$res) { return 0; }
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    return (int)$row[0];
}


/* ============================================================
 *  เพิ่ม / ลบ
 * ============================================================ */

/* $doc = ผลจาก doc_find() — ผู้เรียก (api) ตรวจสิทธิ์และประเภทเอกสารมาแล้ว */
function doc_attachment_save($doc, $file, $actor)
{
    if (!doc_attachment_ready()) {
        return db_fail('ยังไม่ได้เปิดใช้ไฟล์แนบ (ต้องรัน sql/087_doc_attachment.sql)');
    }
    if (!in_array($doc['doc_type'], doc_attachment_types_allowed())) {
        return db_fail('เอกสารประเภทนี้แนบไฟล์ไม่ได้');
    }
    if ($doc['status'] === 'cancelled') {
        return db_fail('ใบนี้ถูกยกเลิกแล้ว แนบไฟล์เพิ่มไม่ได้');
    }
    if (doc_attachment_count($doc['doc_id']) >= DOC_ATTACH_MAX) {
        return db_fail('แนบได้สูงสุด ' . DOC_ATTACH_MAX . ' ไฟล์ต่อใบ กรุณาลบไฟล์เดิมก่อนถ้าต้องการเปลี่ยน');
    }

    $chk = doc_attachment_inspect($file);
    if (isset($chk['error'])) { return db_fail($chk['error']); }

    $dir = doc_attachment_dir();
    if (!is_dir($dir)) {
        return db_fail('สร้างโฟลเดอร์เก็บไฟล์ไม่สำเร็จ: storage/doc_attach/');
    }
    if (!is_writable($dir)) {
        return db_fail('เขียนไฟล์ลงโฟลเดอร์ storage/doc_attach/ ไม่ได้ กรุณาตรวจสิทธิ์โฟลเดอร์');
    }

    /* ชื่อสุ่มยาว ทายไม่ได้ — กันอีกชั้นเผื่อ .htaccess ไม่มีผลบนโฮสต์ */
    $name = 'd' . (int)$doc['doc_id'] . '-' . md5(uniqid(mt_rand(), true)) . '.' . $chk['ext'];
    $dest = $dir . $name;

    if ($chk['kind'] === 'image') {
        $ok = product_image_write($file['tmp_name'], $dest, $chk['info'], $chk['ext'], DOC_ATTACH_IMAGE_SIDE);
    } else {
        $ok = @move_uploaded_file($file['tmp_name'], $dest);
    }
    if (!$ok || !file_exists($dest)) {
        @unlink($dest);
        return db_fail('บันทึกไฟล์ไม่สำเร็จ');
    }

    $size   = (int)@filesize($dest);
    $orig   = doc_attachment_display_name(isset($file['name']) ? $file['name'] : '');
    $mime   = $chk['mime'];
    $doc_id = (int)$doc['doc_id'];

    $stmt = mysqli_prepare(db_connect(),
        "INSERT INTO `tb_doc_attachment`
         SET `doc_id` = ?, `file_name` = ?, `orig_name` = ?, `mime_type` = ?, `file_size` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) {
        @unlink($dest);                 // เขียนไฟล์ไปแล้วแต่บันทึกไม่ได้ ต้องเก็บกวาด
        return db_fail('บันทึกไฟล์แนบไม่สำเร็จ');
    }
    mysqli_stmt_bind_param($stmt, 'isssis', $doc_id, $name, $orig, $mime, $size, $actor);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        @unlink($dest);
        return db_fail(db_error_message($errno), $errno);
    }
    $id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    $out = db_ok('แนบไฟล์ "' . $orig . '" เรียบร้อย', $id);
    $out['attachments'] = doc_attachment_list($doc_id);
    return $out;
}

/* ใครลบได้: ผู้ดูแลระบบ หรือคนที่แนบไฟล์นั้นเอง */
function doc_attachment_can_delete($row, $actor, $actor_role)
{
    return in_array($actor_role, array('super_admin', 'admin'))
        || ($row['created_by'] !== '' && $row['created_by'] === $actor);
}

function doc_attachment_delete($row, $actor, $actor_role)
{
    if (!doc_attachment_can_delete($row, $actor, $actor_role)) {
        return db_fail('ลบได้เฉพาะไฟล์ที่คุณแนบเอง หรือผู้ดูแลระบบ');
    }

    $stmt = mysqli_prepare(db_connect(),
        "DELETE FROM `tb_doc_attachment` WHERE `attach_id` = ? LIMIT 1");
    if (!$stmt) { return db_fail('ลบไฟล์แนบไม่สำเร็จ'); }
    $id = (int)$row['attach_id'];
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) { return db_fail('ลบไฟล์แนบไม่สำเร็จ'); }

    /* ลบแถวก่อนแล้วค่อยลบไฟล์ — ลบไฟล์ไม่สำเร็จ เหลือแค่ไฟล์กำพร้าบนดิสก์ ไม่มีใครเห็น
       กลับกันจะเหลือแถวที่ชี้ไปหาไฟล์ที่ไม่มีแล้ว ซึ่งผู้ใช้เห็นเป็นลิงก์เสีย */
    @unlink(doc_attachment_dir() . basename($row['file_name']));

    $out = db_ok('ลบไฟล์ "' . $row['orig_name'] . '" แล้ว', $id);
    $out['attachments'] = doc_attachment_list($row['doc_id']);
    return $out;
}


/* ---------- ส่งไฟล์ให้เบราว์เซอร์ ----------
 * $download = true บังคับดาวน์โหลด, false เปิดดูในแท็บ (รูป/PDF เปิดได้ในเบราว์เซอร์อยู่แล้ว)
 * ผู้เรียกตรวจสิทธิ์มาแล้ว */
function doc_attachment_send($row, $download)
{
    $path = doc_attachment_dir() . basename($row['file_name']);
    if (!is_file($path)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ไม่พบไฟล์ (อาจถูกลบออกจากเครื่องเซิร์ฟเวอร์)';
        return;
    }

    /* ส่งชื่อไฟล์ภาษาไทยได้ทั้งเบราว์เซอร์ใหม่ (filename*) และเก่า (filename ตัวสำรอง) */
    $name  = $row['orig_name'];
    $ext   = strtolower(pathinfo(basename($row['file_name']), PATHINFO_EXTENSION));
    $base  = preg_replace('/\.[^.]*$/', '', $name);
    $ascii = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '_.');
    /* ชื่อภาษาไทยล้วนเหลือแต่ "_" — ใช้ชื่อกลาง ๆ แทน (เบราว์เซอร์ใหม่ใช้ filename* อยู่แล้ว) */
    if (strlen(str_replace(array('_', '-', '.'), '', $ascii)) < 2) {
        $ascii = 'attachment-' . (int)$row['attach_id'];
    }
    $ascii .= '.' . $ext;

    header('Content-Type: ' . $row['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
         . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    readfile($path);
}
