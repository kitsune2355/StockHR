<?php
/* ============================================================
 *  backend/adjust_approver.php — รายชื่อผู้อนุมัติใบปรับยอดสินค้า
 *
 *  ตาราง tb_adjust_approver เก็บ username ของคนที่กดอนุมัติ/ไม่อนุมัติ
 *  ใบปรับยอด (doc_type='adjust') ได้
 *
 *  ใครอนุมัติได้ = "เฉพาะคนที่อยู่ในตารางนี้" เท่านั้น — ไม่ผูกกับ role
 *  จะเป็น super_admin / admin / approver / general ก็ได้ แต่ต้องถูกเลือก
 *  มาจากหน้า "ตั้งค่าอนุมัติ" ก่อน (ตารางว่าง = ยังไม่มีใครอนุมัติได้)
 *
 *  แก้รายชื่อทั้งชุดด้วย DELETE-all + INSERT (adjust_approver_save)
 *  เหตุผลเดียวกับ product_dept_save: หน้าจอส่ง "สถานะสุดท้ายที่ต้องการ" มาให้
 *
 *  กติกาชั้น backend เหมือนเดิม: ไม่ echo ไม่ redirect ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/stock.php';   // db_begin / db_commit / db_rollback
require_once dirname(__FILE__) . '/user.php';


/* ---------- รายชื่อ username ผู้อนุมัติที่ตั้งไว้ ----------
 * @ กันกรณีตาราง tb_adjust_approver ยังไม่ถูกสร้าง (ยังไม่รัน sql/056)
 * คืน array ว่าง = ยังไม่ตั้งใคร -> ยังไม่มีใครอนุมัติใบปรับยอดได้
 * (ผู้ดูแลระบบต้องมาตั้งรายชื่อที่หน้า "ตั้งค่าอนุมัติ" ก่อน)
 */
function adjust_approver_usernames()
{
    $connect = db_connect();

    $res = @mysqli_query($connect,
        "SELECT `username` FROM `tb_adjust_approver` ORDER BY `username` ASC");
    if (!$res) { return array(); }

    $out = array();
    while ($row = mysqli_fetch_row($res)) {
        $out[] = $row[0];
    }
    mysqli_free_result($res);

    return $out;
}


/* ---------- คนนี้กดอนุมัติใบปรับยอดได้ไหม ----------
 * true เมื่อ username อยู่ในตาราง tb_adjust_approver เท่านั้น — ไม่ดู role
 * (ผู้ดูแลระบบที่ไม่ได้ถูกเลือกไว้ก็อนุมัติไม่ได้ ต้องไปติ๊กชื่อตัวเองที่หน้าตั้งค่าก่อน)
 *
 * $actor_role ไม่ใช้แล้ว แต่คงพารามิเตอร์ไว้ให้ผู้เรียกเดิมไม่ต้องแก้
 */
function adjust_approver_can($username, $actor_role = null)
{
    $username = trim((string)$username);
    if ($username === '') { return false; }

    return in_array($username, adjust_approver_usernames());
}


/* ---------- บันทึกรายชื่อผู้อนุมัติทั้งชุด ----------
 * $usernames = รายชื่อทั้งหมดที่ต้องการให้เป็นผู้อนุมัติ (แทนที่ของเดิมทั้งชุด)
 *
 * ตัด username ที่ไม่มีจริงในระบบ / ปิดใช้งานอยู่ / ซ้ำ ออกก่อนเขียน
 */
function adjust_approver_save($usernames, $actor)
{
    if (!is_array($usernames)) { $usernames = array(); }

    /* กรองให้เหลือแต่ผู้ใช้ที่มีจริงและเปิดใช้งาน + ตัดตัวซ้ำ */
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

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_adjust_approver`");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกไม่สำเร็จ'); }
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    if (count($names) > 0) {
        $stmt = mysqli_prepare($connect,
            "INSERT INTO `tb_adjust_approver` (`username`, `created_at`, `created_by`)
             VALUES (?, NOW(), ?)");
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
    return db_ok('บันทึกรายชื่อผู้อนุมัติเรียบร้อย — ทั้งหมด ' . count($names) . ' คน');
}
