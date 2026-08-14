<?php
/* ============================================================
 *  backend/guide.php — คู่มือการใช้งานในหน้าเว็บ (driver.js)
 *
 *  คู่มือ 1 ชุดผูกกับ 1 หน้า ผ่าน page_key ซึ่งตรงกับค่า ?page= ใน main.php
 *  ขั้นตอนแต่ละขั้นชี้ไปที่ element ด้วย CSS selector
 *
 *  ---------------------------------------------------------------
 *  ทำไมเก็บคำอธิบายในฐานข้อมูล ไม่ฝังไว้ในโค้ด
 *  ---------------------------------------------------------------
 *  คำอธิบายวิธีใช้เปลี่ยนบ่อยกว่าโค้ดมาก ทุกครั้งที่มีคนถามซ้ำ ๆ เรื่องเดิม
 *  แปลว่าคำอธิบายตรงนั้นยังไม่ดีพอและควรแก้ทันที ถ้าฝังไว้ในไฟล์ JS
 *  การแก้ประโยคเดียวจะกลายเป็นงานที่ต้องรอโปรแกรมเมอร์และรอ deploy
 *
 *  ---------------------------------------------------------------
 *  ขั้นตอนที่หา element ไม่เจอ จะถูกข้ามที่ฝั่งเบราว์เซอร์
 *  ---------------------------------------------------------------
 *  ปุ่มหลายตัวขึ้นกับสิทธิ์ เช่น #btnExport ที่ผู้ใช้ทั่วไปไม่เห็น
 *  ถ้าไม่ข้าม คู่มือจะไฮไลต์ที่ว่างเปล่าและดูเหมือนระบบพัง
 *  การข้ามทำที่ assets/js/app-guide.js เพราะที่นั่นเท่านั้นที่รู้ว่า
 *  หน้าจอจริงของคนคนนี้มีอะไรอยู่บ้าง
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/setting.php';


/* ---------- คู่มือของหน้าหนึ่ง พร้อมขั้นตอนทั้งหมด ----------
 * คืน null ถ้าหน้านี้ไม่มีคู่มือ หรือคู่มือถูกปิดไว้
 *
 * $username ใช้ตรวจว่าคนนี้เคยดูคู่มือของหน้านี้ไปแล้วหรือยัง
 * ส่งค่าว่างมาได้ ถ้าไม่สนใจเรื่องเคยดูหรือยัง (เช่นตอนดูตัวอย่างจากหน้าตั้งค่า)
 */
function guide_for_page($page_key, $username = '')
{
    $page_key = trim((string)$page_key);
    if ($page_key === '') { return null; }

    /* ปิดคู่มือทั้งระบบได้จากหน้าตั้งค่า — ใช้ตอนอบรมที่ไม่อยากให้มีกล่องเด้ง */
    if (!setting_is_on('guide_enabled')) { return null; }

    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "SELECT `guide_id`, `page_key`, `title`, `description`, `auto_start`
         FROM `tb_guide`
         WHERE `page_key` = ? AND `is_active` = 1 LIMIT 1");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 's', $page_key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $gid, $pkey, $title, $description, $auto_start);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }

    $steps = guide_steps((int)$gid);
    if (count($steps) === 0) { return null; }   // มีหัวเรื่องแต่ไม่มีขั้นตอน = ยังใช้ไม่ได้

    return array(
        'guide_id'    => (int)$gid,
        'page_key'    => $pkey,
        'title'       => $title,
        'description' => $description,
        'auto_start'  => ((int)$auto_start === 1),
        'seen'        => guide_is_seen($username, $pkey),
        'steps'       => $steps
    );
}


/* ---------- ขั้นตอนของคู่มือชุดหนึ่ง ---------- */
function guide_steps($guide_id)
{
    $connect  = db_connect();
    $guide_id = (int)$guide_id;

    $stmt = @mysqli_prepare($connect,
        "SELECT `step_id`, `step_order`, `target_selector`, `title`, `description`, `side`, `align`
         FROM `tb_guide_step`
         WHERE `guide_id` = ? AND `is_active` = 1
         ORDER BY `step_order` ASC, `step_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $guide_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $order, $selector, $title, $description, $side, $align);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'step_id'         => (int)$id,
            'step_order'      => (int)$order,
            'target_selector' => $selector,
            'title'           => $title,
            'description'     => $description,
            'side'            => $side,
            'align'           => $align
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ---------- คนนี้เคยดูคู่มือของหน้านี้ไปแล้วหรือยัง ---------- */
function guide_is_seen($username, $page_key)
{
    $username = trim((string)$username);
    if ($username === '') { return false; }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT 1 FROM `tb_guide_seen` WHERE `username` = ? AND `page_key` = ? LIMIT 1");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'ss', $username, $page_key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $one);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return $found ? true : false;
}


/* ---------- บันทึกว่าดูจบแล้ว ----------
 * นับจำนวนครั้งที่ดูไว้ด้วย ตัวเลขนี้บอกได้ว่าหน้าไหนคนต้องกลับมาเปิดคู่มือซ้ำ
 * ซึ่งแปลว่าหน้านั้นออกแบบให้เข้าใจยาก และควรแก้ที่หน้าจอ ไม่ใช่แก้ที่คู่มือ
 */
function guide_mark_seen($username, $page_key, $actor = null)
{
    $username = trim((string)$username);
    $page_key = trim((string)$page_key);
    if ($username === '' || $page_key === '') {
        return db_fail('ข้อมูลไม่ครบ');
    }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_guide_seen` (`username`, `page_key`, `seen_count`, `created_at`, `created_by`)
         VALUES (?, ?, 1, NOW(), ?)
         ON DUPLICATE KEY UPDATE `seen_count` = `seen_count` + 1,
                                 `updated_by` = VALUES(`created_by`)");
    if (!$stmt) { return db_fail('บันทึกสถานะคู่มือไม่สำเร็จ'); }

    $by = ($actor === null) ? $username : $actor;
    mysqli_stmt_bind_param($stmt, 'sss', $username, $page_key, $by);

    $ok = @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) { return db_fail('บันทึกสถานะคู่มือไม่สำเร็จ'); }
    return db_ok('บันทึกแล้ว');
}


/* ---------- ล้างประวัติการดูคู่มือของคนหนึ่งคน ----------
 * ใช้กับปุ่ม "ให้คู่มือเด้งใหม่ทุกหน้า" ที่หน้าตั้งค่า
 * มีประโยชน์ตอนอบรมพนักงานใหม่ที่ใช้บัญชีเดิมที่เคยดูไปแล้ว
 *
 * ---------------------------------------------------------------
 * ต้องเปิด auto_start ให้ด้วย ไม่ใช่แค่ล้างประวัติ
 * ---------------------------------------------------------------
 * หน้าจอตัดสินใจเด้งคู่มือจากเงื่อนไข "auto_start และยังไม่เคยดู"
 * การล้างประวัติอย่างเดียวจึงไม่พอ — คู่มือที่ปิด auto_start ไว้จะไม่เด้งอยู่ดี
 * (ห้ารายงานกับหน้าการแจ้งเตือนตั้งไว้เป็น 0 เพราะเป็นหน้าที่เปิดมาอ่านอย่างเดียว)
 *
 * ผลคือกดปุ่มแล้วบางหน้าเงียบ ซึ่งขัดกับคำว่า "ทุกหน้า" บนปุ่ม
 * และผู้ใช้ไม่มีทางรู้ว่าทำไม
 *
 * ปุ่มนี้จึงเปิด auto_start ให้ทุกคู่มือที่ยังใช้งานอยู่ด้วย
 * ผู้ดูแลระบบปิดรายหน้าทีหลังได้จากสวิตช์ในตารางเดียวกันบนหน้านั้น
 */
function guide_reset_seen($username)
{
    $username = trim((string)$username);
    if ($username === '') { return db_fail('ไม่พบผู้ใช้'); }

    $connect = db_connect();

    $stmt = @mysqli_prepare($connect,
        "DELETE FROM `tb_guide_seen` WHERE `username` = ?");
    if (!$stmt) { return db_fail('ล้างประวัติคู่มือไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    /* นับเฉพาะที่เคยปิดไว้ เพื่อบอกผู้ใช้ได้ว่าปุ่มนี้ไปเปลี่ยนอะไรให้บ้าง */
    $opened = 0;
    $res = @mysqli_query($connect,
        "SELECT COUNT(*) AS `n` FROM `tb_guide` WHERE `is_active` = 1 AND `auto_start` = 0");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $opened = (int)$row['n'];
        mysqli_free_result($res);
    }

    @mysqli_query($connect,
        "UPDATE `tb_guide` SET `auto_start` = 1 WHERE `is_active` = 1 AND `auto_start` = 0");

    $total = 0;
    $res = @mysqli_query($connect, "SELECT COUNT(*) AS `n` FROM `tb_guide` WHERE `is_active` = 1");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $total = (int)$row['n'];
        mysqli_free_result($res);
    }

    $msg = 'คู่มือจะเด้งใหม่อีกครั้งครบทั้ง ' . $total . ' หน้า';
    if ($opened > 0) {
        $msg .= ' (เปิดการเด้งอัตโนมัติให้อีก ' . $opened . ' หน้าที่เคยปิดไว้)';
    }

    return db_ok($msg, $n);
}


/* ============================================================
 *  ส่วนที่ผู้ดูแลระบบใช้แก้เนื้อหาคู่มือ
 * ============================================================ */

/* ---------- ขั้นตอนทั้งหมดทุกหน้า พร้อมชื่อคู่มือ ----------
 * ตารางแก้ในช่องได้ที่หน้าตั้งค่าระบบใช้ตัวนี้
 * ดึงมาทั้งหมดทีเดียว เพราะทั้งระบบมีไม่ถึงร้อยขั้นตอน
 * และการแก้คำอธิบายมักแก้ทีละหลายหน้าพร้อมกัน
 */
function guide_all_steps()
{
    $connect = db_connect();

    $result = @mysqli_query($connect,
        "SELECT s.`step_id`, s.`guide_id`, g.`page_key`, g.`title` AS `guide_title`,
                s.`step_order`, s.`target_selector`, s.`title`, s.`description`,
                s.`side`, s.`align`, s.`is_active`
         FROM `tb_guide_step` s
         INNER JOIN `tb_guide` g ON g.`guide_id` = s.`guide_id`
         ORDER BY g.`page_key` ASC, s.`step_order` ASC, s.`step_id` ASC");
    if (!$result) { return array(); }

    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'step_id'         => (int)$row['step_id'],
            'guide_id'        => (int)$row['guide_id'],
            'page_key'        => $row['page_key'],
            'guide_title'     => $row['guide_title'],
            'step_order'      => (int)$row['step_order'],
            'target_selector' => $row['target_selector'],
            'title'           => $row['title'],
            'description'     => $row['description'],
            'side'            => $row['side'],
            'align'           => $row['align'],
            'is_active'       => (int)$row['is_active']
        );
    }
    return $rows;
}


/* ---------- รายชื่อคู่มือทั้งหมด พร้อมจำนวนขั้นตอน ---------- */
function guide_list()
{
    $connect = db_connect();

    $result = @mysqli_query($connect,
        "SELECT g.`guide_id`, g.`page_key`, g.`title`, g.`description`,
                g.`auto_start`, g.`is_active`,
                COUNT(s.`step_id`) AS `step_count`
         FROM `tb_guide` g
         LEFT JOIN `tb_guide_step` s ON s.`guide_id` = g.`guide_id` AND s.`is_active` = 1
         GROUP BY g.`guide_id`, g.`page_key`, g.`title`, g.`description`,
                  g.`auto_start`, g.`is_active`
         ORDER BY g.`page_key` ASC");
    if (!$result) { return array(); }

    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = array(
            'guide_id'    => (int)$row['guide_id'],
            'page_key'    => $row['page_key'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'auto_start'  => (int)$row['auto_start'],
            'is_active'   => (int)$row['is_active'],
            'step_count'  => (int)$row['step_count']
        );
    }
    return $rows;
}


/* ---------- ตรวจข้อมูลขั้นตอนก่อนบันทึก ---------- */
function guide_step_validate($data)
{
    $title = isset($data['title']) ? trim($data['title']) : '';
    $desc  = isset($data['description']) ? trim($data['description']) : '';
    $sel   = isset($data['target_selector']) ? trim($data['target_selector']) : '';

    if ($title === '') {
        return 'กรุณากรอกหัวข้อของขั้นตอน';
    }
    if (db_text_length($title) > 150) {
        return 'หัวข้อยาวเกิน 150 ตัวอักษร';
    }
    if ($desc === '') {
        return 'กรุณากรอกคำอธิบาย — ขั้นตอนที่ไม่มีคำอธิบายไม่ช่วยอะไรผู้ใช้';
    }
    if (db_text_length($desc) > 1000) {
        return 'คำอธิบายยาวเกิน 1000 ตัวอักษร';
    }
    if (db_text_length($sel) > 255) {
        return 'CSS selector ยาวเกิน 255 ตัวอักษร';
    }

    /* selector ที่มีอัญประกาศหรือวงเล็บปีกกาปนมา มักเกิดจากการก๊อปโค้ดมาทั้งบรรทัด
       ปล่อยผ่านไปจะได้ขั้นตอนที่หา element ไม่เจอตลอดกาลโดยไม่มีใครรู้ */
    if ($sel !== '' && preg_match('/[{}"\'<>]/', $sel)) {
        return 'CSS selector มีอักขระที่ใช้ไม่ได้ ({ } " \' < >) — ใส่เฉพาะตัวเลือก เช่น #btnSave';
    }

    $side  = isset($data['side'])  ? $data['side']  : '';
    $align = isset($data['align']) ? $data['align'] : '';

    if (!in_array($side, array('top', 'right', 'bottom', 'left', 'over'))) {
        return 'ตำแหน่งกล่องไม่ถูกต้อง';
    }
    if (!in_array($align, array('start', 'center', 'end'))) {
        return 'การจัดวางกล่องไม่ถูกต้อง';
    }

    return '';
}


/* ---------- บันทึกขั้นตอนที่ถูกแก้จากตาราง ----------
 * $rows = แถวที่ผู้ใช้แก้ ส่งมาทั้งก้อน
 * แก้ได้เฉพาะขั้นตอนที่มีอยู่แล้ว ไม่รองรับการเพิ่มขั้นตอนใหม่จากหน้านี้
 *
 * เหตุผลที่ไม่ให้เพิ่มจากหน้าเว็บ: ขั้นตอนใหม่ต้องรู้ selector ของ element จริง
 * ซึ่งต้องเปิดเครื่องมือนักพัฒนาดู เป็นงานของคนเขียนโค้ดไม่ใช่งานของผู้ดูแลระบบ
 * สิ่งที่ผู้ดูแลระบบต้องทำได้จริงคือ "แก้คำอธิบายให้เข้าใจง่ายขึ้น" ซึ่งทำได้ครบที่นี่
 */
function guide_steps_save($rows, $actor)
{
    if (!is_array($rows) || count($rows) === 0) {
        return db_fail('ไม่มีรายการที่จะบันทึก');
    }

    $connect = db_connect();
    $saved   = 0;

    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];

        $step_id = isset($row['step_id']) ? (int)$row['step_id'] : 0;
        if ($step_id <= 0) {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ไม่พบรหัสขั้นตอน');
        }

        $error = guide_step_validate($row);
        if ($error !== '') {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ' . $error);
        }

        $stmt = mysqli_prepare($connect,
            "UPDATE `tb_guide_step`
             SET `step_order` = ?, `target_selector` = ?, `title` = ?, `description` = ?,
                 `side` = ?, `align` = ?, `is_active` = ?, `updated_by` = ?
             WHERE `step_id` = ?");
        if (!$stmt) { return db_fail('บันทึกขั้นตอนคู่มือไม่สำเร็จ'); }

        $order  = isset($row['step_order']) ? (int)$row['step_order'] : 1;
        $sel    = trim($row['target_selector']);
        $title  = trim($row['title']);
        $desc   = trim($row['description']);
        $side   = $row['side'];
        $align  = $row['align'];
        $active = (isset($row['is_active']) && (int)$row['is_active'] === 0) ? 0 : 1;

        mysqli_stmt_bind_param($stmt, 'isssssisi',
            $order, $sel, $title, $desc, $side, $align, $active, $actor, $step_id);

        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            return db_fail(db_error_message($errno), $errno);
        }
        mysqli_stmt_close($stmt);
        $saved++;
    }

    return db_ok('บันทึกคำอธิบาย ' . $saved . ' ขั้นตอนเรียบร้อย', $saved);
}


/* ---------- เปิด/ปิดคู่มือของหน้าหนึ่ง และตั้งว่าจะเด้งเองไหม ---------- */
function guide_toggle($guide_id, $field, $actor)
{
    $guide_id = (int)$guide_id;
    if (!in_array($field, array('is_active', 'auto_start'))) {
        return db_fail('คำสั่งไม่ถูกต้อง');
    }

    $connect = db_connect();

    /* สลับค่าในคำสั่งเดียวด้วย 1 - ค่าเดิม ไม่ต้องอ่านก่อนแล้วค่อยเขียน
       ชื่อคอลัมน์มาจากรายการที่กำหนดเองด้านบน ไม่ใช่ค่าจากผู้ใช้ จึงต่อสตริงได้ */
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_guide` SET `" . $field . "` = 1 - `" . $field . "`, `updated_by` = ?
         WHERE `guide_id` = ?");
    if (!$stmt) { return db_fail('เปลี่ยนสถานะคู่มือไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $guide_id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะคู่มือเรียบร้อย', $guide_id);
}
