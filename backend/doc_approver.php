<?php
/* ============================================================
 *  backend/doc_approver.php — รายชื่อผู้อนุมัติเอกสาร (ใช้ร่วมกันทุกประเภทที่มีขั้นอนุมัติ)
 *
 *    'request'  ใบเบิก       -> tb_request_approver (sql/088)
 *    'adjust'   ใบปรับยอด    -> tb_adjust_approver  (sql/056)
 *
 *  ใครอนุมัติได้ = "เฉพาะคนที่อยู่ในรายชื่อของประเภทนั้น" — ไม่ผูกกับ role ไม่ผูกกับหน่วยงาน
 *  คนในรายชื่ออนุมัติได้ทุกใบของประเภทนั้น (ยกเว้นใบที่ตัวเองยื่น — เช็คที่ฟังก์ชันตัดสินแต่ละตัว)
 *  รายชื่อว่าง = ยังไม่มีใครอนุมัติได้ ต้องไปตั้งที่หน้า "ผู้อนุมัติใบเบิก / ใบปรับยอด" ก่อน
 *
 *  แก้รายชื่อทั้งชุดด้วย DELETE-all + INSERT — หน้าจอส่ง "สถานะสุดท้ายที่ต้องการ" มาให้
 *
 *  กติกาชั้น backend: ไม่ echo ไม่ redirect ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/stock.php';   // db_begin / db_commit / db_rollback
require_once dirname(__FILE__) . '/user.php';


/* ---------- ชื่อตารางของแต่ละประเภท ----------
 * whitelist ตายตัว ชื่อตารางต่อเข้า SQL ตรง ๆ จึงห้ามรับค่าจากผู้ใช้มาใช้เป็นชื่อตาราง
 * คืน '' เมื่อไม่รู้จักประเภท
 */
function doc_approver_table($kind)
{
    if ($kind === 'request') { return 'tb_request_approver'; }
    if ($kind === 'adjust')  { return 'tb_adjust_approver'; }
    return '';
}

/* ชื่อเรียกบนหน้าจอ/ข้อความแจ้ง */
function doc_approver_label($kind)
{
    return ($kind === 'adjust') ? 'ใบปรับยอด' : 'ใบเบิก';
}


/* ---------- รายชื่อ username ผู้อนุมัติที่ตั้งไว้ ----------
 * @ กันกรณีตารางยังไม่ถูกสร้าง (ยังไม่รัน migration) — คืน array ว่าง
 * เก็บผลไว้ตลอด request เพราะถูกถามซ้ำหลายรอบต่อหน้า (เมนู ตัวนับ สิทธิ์ปุ่ม)
 */
function doc_approver_usernames($kind, $fresh = false)
{
    static $cache = array();

    $table = doc_approver_table($kind);
    if ($table === '') { return array(); }
    if (!$fresh && isset($cache[$kind])) { return $cache[$kind]; }

    $connect = db_connect();
    $res = @mysqli_query($connect, "SELECT `username` FROM `" . $table . "` ORDER BY `username` ASC");

    $out = array();
    if ($res) {
        while ($row = mysqli_fetch_row($res)) { $out[] = $row[0]; }
        mysqli_free_result($res);
    }

    $cache[$kind] = $out;
    return $out;
}


/* ---------- คนนี้อนุมัติเอกสารประเภทนี้ได้ไหม ---------- */
function doc_approver_can($kind, $username)
{
    $username = trim((string)$username);
    if ($username === '') { return false; }

    return in_array($username, doc_approver_usernames($kind));
}


/* ---------- ผู้อนุมัติที่ยังเปิดใช้งาน พร้อมอีเมล (ปลายทางแจ้งเตือน/ลิงก์อนุมัติ) ----------
 * $exclude = คนที่ยื่นเอกสารเอง (อนุมัติใบตัวเองไม่ได้ ไม่ต้องแจ้ง)
 * คืน array of array('username','full_name','email')
 */
function doc_approver_people($kind, $exclude = '')
{
    $names = doc_approver_usernames($kind);
    $out   = array();

    for ($i = 0; $i < count($names); $i++) {
        if ($names[$i] === $exclude) { continue; }
        $u = user_find($names[$i]);
        if ($u === null || (int)$u['is_active'] !== 1) { continue; }
        $out[] = array(
            'username'  => $u['username'],
            'full_name' => $u['full_name'],
            'email'     => isset($u['email']) ? $u['email'] : ''
        );
    }
    return $out;
}


/* ---------- ชื่อที่ใช้แสดง "ใบนี้รอใครพิจารณา" ---------- */
function doc_approver_names($kind, $exclude = '')
{
    $people = doc_approver_people($kind, $exclude);
    $out    = array();
    for ($i = 0; $i < count($people); $i++) {
        $out[] = (trim($people[$i]['full_name']) !== '') ? $people[$i]['full_name'] : $people[$i]['username'];
    }
    return $out;
}


/* ---------- บันทึกรายชื่อผู้อนุมัติทั้งชุด ----------
 * $usernames = รายชื่อทั้งหมดที่ต้องการ (แทนที่ของเดิมทั้งชุด)
 * ตัด username ที่ไม่มีจริง / ปิดใช้งาน / ซ้ำ ออกก่อนเขียน
 */
function doc_approver_save($kind, $usernames, $actor)
{
    $table = doc_approver_table($kind);
    if ($table === '') { return db_fail('ไม่รู้จักประเภทเอกสาร'); }
    if (!is_array($usernames)) { $usernames = array(); }

    $clean = array();
    for ($i = 0; $i < count($usernames); $i++) {
        $u = trim((string)$usernames[$i]);
        if ($u === '' || isset($clean[$u])) { continue; }

        $row = user_find($u);
        if ($row === null || (int)$row['is_active'] !== 1) { continue; }

        $clean[$u] = true;
    }
    $names = array_keys($clean);

    $connect = db_connect();
    db_begin();

    if (!@mysqli_query($connect, "DELETE FROM `" . $table . "`")) {
        $errno = mysqli_errno($connect);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }

    if (count($names) > 0) {
        $stmt = mysqli_prepare($connect,
            "INSERT INTO `" . $table . "` (`username`, `created_at`, `created_by`) VALUES (?, NOW(), ?)");
        if (!$stmt) { db_rollback(); return db_fail('บันทึกไม่สำเร็จ'); }

        for ($i = 0; $i < count($names); $i++) {
            $u = $names[$i];
            mysqli_stmt_bind_param($stmt, 'ss', $u, $actor);
            if (!mysqli_stmt_execute($stmt)) {
                $errno = mysqli_stmt_errno($stmt);
                mysqli_stmt_close($stmt);
                db_rollback();
                if ($errno === 1452) {
                    return db_fail('มีผู้ใช้บางคนถูกลบไปแล้ว กรุณารีเฟรชหน้าแล้วเลือกใหม่');
                }
                return db_fail(db_error_message($errno), $errno);
            }
        }
        mysqli_stmt_close($stmt);
    }

    db_commit();
    doc_approver_usernames($kind, true);   // ล้างค่าที่จำไว้

    return db_ok('บันทึกรายชื่อผู้อนุมัติ' . doc_approver_label($kind) . 'เรียบร้อย — ทั้งหมด ' . count($names) . ' คน');
}


/* ============================================================
 *  ทางลัดของใบเบิก
 * ============================================================ */
function request_approver_can($username)
{
    return doc_approver_can('request', $username);
}
