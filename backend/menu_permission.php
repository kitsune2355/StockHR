<?php
/* ============================================================
 *  backend/menu_permission.php — สิทธิ์การเข้าถึงเมนูของแต่ละ role
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/menu_permission.php';
 *
 *      $map = menu_permission_map();          // array[menu_key][role] = 1|0
 *      $res = menu_permission_save($rows, $username);
 *
 *  กติกาสำคัญ:
 *
 *    1. super_admin ไม่ถูกเก็บในตารางนี้ และแก้ไม่ได้ — เข้าได้ทุกเมนูเสมอ
 *       กันกรณีเผลอปิดสิทธิ์ตัวเองแล้วไม่มีใครเข้าไปแก้คืนได้อีก
 *
 *    2. เมนูที่ยังไม่มีแถวในตาราง = ใช้ค่าที่เขียนไว้ในโค้ด includes/menu.php
 *       เพิ่มเมนูใหม่ในโค้ดแล้วใช้ได้ทันที ไม่ต้องมา INSERT ก่อน
 *
 *    3. ถ้าตารางยังไม่ถูกสร้าง (ยังไม่ได้รัน migration 007) ให้คืนค่าว่าง
 *       ระบบจะใช้ค่าในโค้ดทั้งหมด ไม่พัง ไม่ขวางการใช้งาน
 *
 *  ไฟล์นี้ไม่ echo และไม่ redirect — คืนค่ากลับให้ผู้เรียกตัดสินใจ
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

/* role ที่ปรับสิทธิ์ได้จากหน้าเว็บ — ไม่รวม super_admin โดยตั้งใจ */
function menu_permission_roles()
{
    return array('admin', 'approver', 'general');
}


/* ---------- ใครแก้สิทธิ์ของ role ไหนได้บ้าง ----------
 * ห้ามแก้ของ role ตัวเอง ไม่งั้นการจำกัดสิทธิ์ admin ไม่มีความหมาย
 * เพราะเมนูที่ super_admin ปิดไว้ admin เปิดคืนเองได้ในคลิกเดียว
 * แต่ยังจัดสิทธิ์ของผู้อนุมัติกับผู้ใช้ทั่วไปได้ตามปกติ ที่กันคือ "ยกระดับตัวเอง"
 * super_admin ไม่มีคอลัมน์ให้ปรับอยู่แล้ว จึงไม่มีกรณีแก้ของตัวเอง */
function menu_permission_editable_roles($actor_role)
{
    $all = menu_permission_roles();

    if ($actor_role === 'super_admin') {
        return $all;                    // แก้ได้ทุก role เพราะไม่ได้แก้ของตัวเอง
    }

    $out = array();
    foreach ($all as $r) {
        if ($r !== $actor_role) { $out[] = $r; }
    }
    return $out;
}


/* ---------- อ่านสิทธิ์ทั้งหมดออกมาเป็นตาราง ----------
 * คืน array('menu_key' => array('admin' => 1, 'approver' => 0, ...))
 * เมนูที่ไม่มีในผลลัพธ์ = ยังไม่เคยตั้งค่า ให้ผู้เรียกใช้ค่าตั้งต้นของตัวเอง
 */
function menu_permission_map()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache   = array();
    $connect = db_connect();

    /* ปิดคำเตือนไว้ เพราะถ้ายังไม่ได้รัน migration ตารางจะยังไม่มี
       ซึ่งเป็นสถานะที่ยอมรับได้ ไม่ใช่ความผิดพลาดที่ต้องแจ้งผู้ใช้ */
    $result = @mysqli_query($connect, "SELECT `menu_key`, `role`, `can_access` FROM `tb_menu_permission`");
    if (!$result) {
        return $cache;
    }

    while ($r = mysqli_fetch_assoc($result)) {
        $key = $r['menu_key'];
        if (!isset($cache[$key])) {
            $cache[$key] = array();
        }
        $cache[$key][$r['role']] = ((int)$r['can_access'] === 1) ? 1 : 0;
    }

    return $cache;
}


/* ---------- บันทึกสิทธิ์ทั้งชุด ----------
 * $rows = array(
 *     array('menu_key' => 'dashboard', 'role' => 'admin', 'can_access' => 1),
 *     ...
 * )
 * ใช้ REPLACE เพื่อให้เขียนทับของเดิมได้ในคำสั่งเดียว ไม่ต้องเช็คก่อนว่ามีหรือยัง
 */
function menu_permission_save($rows, $actor, $allowed_keys = null, $actor_role = 'super_admin')
{
    if (!is_array($rows) || count($rows) === 0) {
        return db_fail('ไม่มีข้อมูลให้บันทึก');
    }

    $connect = db_connect();

    /* role ที่คนกดบันทึกคนนี้แก้ได้จริง ไม่ใช่ทุก role ที่ระบบมี
       หน้าเว็บซ่อนช่องที่แก้ไม่ได้ไว้แล้ว แต่ถ้ายิง API ตรง ๆ ต้องกันที่นี่ด้วย
       เพราะการยกสิทธิ์ให้ตัวเองเป็นสิ่งที่ห้ามในทุกทาง ไม่ใช่แค่ทางหน้าจอ */
    $roles = menu_permission_editable_roles($actor_role);

    $stmt = mysqli_prepare($connect,
        "REPLACE INTO `tb_menu_permission`
         SET `menu_key` = ?, `role` = ?, `can_access` = ?,
             `created_at` = NOW(), `created_by` = ?, `updated_by` = ?");
    if (!$stmt) {
        return db_fail('ยังไม่ได้สร้างตารางสิทธิ์เมนู กรุณารัน sql/007_create_menu_permission.sql ก่อน');
    }

    $saved   = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $key  = isset($row['menu_key']) ? trim($row['menu_key']) : '';
        $role = isset($row['role'])     ? trim($row['role'])     : '';
        $can  = (isset($row['can_access']) && (int)$row['can_access'] === 1) ? 1 : 0;

        /* กันค่าที่ไม่รู้จักหลุดลงตาราง — โดยเฉพาะ super_admin ที่ห้ามแก้
           และ menu_key ที่ไม่มีอยู่จริงในเมนู ซึ่งจะกลายเป็นขยะค้างในตาราง
           รวมถึง role ของคนกดเอง ซึ่งอยู่นอกรายการ $roles ตั้งแต่ต้น */
        if ($key === '' || !in_array($role, $roles)) {
            $skipped++;
            continue;
        }
        if ($allowed_keys !== null && !in_array($key, $allowed_keys)) {
            $skipped++;
            continue;
        }

        mysqli_stmt_bind_param($stmt, 'ssiss', $key, $role, $can, $actor, $actor);
        if (mysqli_stmt_execute($stmt)) {
            $saved++;
        } else {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            return db_fail(db_error_message($errno), $errno);
        }
    }
    mysqli_stmt_close($stmt);

    if ($saved === 0) {
        /* กรณีที่พบบ่อยที่สุดคือพยายามแก้สิทธิ์ของ role ตัวเอง
           บอกเหตุผลไปเลย ดีกว่าบอกกว้าง ๆ ว่าข้อมูลไม่ถูกต้อง */
        return db_fail('ไม่มีรายการที่บันทึกได้ — สิทธิ์ของ role ตัวเองปรับเองไม่ได้ '
                     . 'ต้องให้ผู้ดูแลระบบสูงสุดเป็นคนเปิดให้');
    }

    $message = 'บันทึกสิทธิ์เมนูเรียบร้อย ' . $saved . ' รายการ';
    if ($skipped > 0) {
        $message .= ' (ข้าม ' . $skipped . ' รายการที่ไม่ถูกต้อง)';
    }
    return db_ok($message, $saved);
}


/* ---------- ล้างค่าที่ตั้งไว้ กลับไปใช้ค่าตั้งต้นในโค้ด ---------- */
function menu_permission_reset()
{
    $connect = db_connect();

    if (!@mysqli_query($connect, "DELETE FROM `tb_menu_permission`")) {
        return db_fail('ล้างค่าไม่สำเร็จ');
    }
    return db_ok('คืนค่าสิทธิ์เมนูเป็นค่าตั้งต้นเรียบร้อย');
}
