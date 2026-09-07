<?php
/* ============================================================
 *  backend/user.php — ทุกอย่างที่แตะตาราง tb_user
 *
 *  วิธีใช้:
 *      require_once dirname(dirname(__FILE__)) . '/backend/user.php';
 *      $rows = user_list(array('search' => 'admin', 'limit' => 20));
 *      $res  = user_create($data, $actor_username, $actor_role);
 *
 *  ตัวเลือกใน $options ของ user_list / user_count
 *      search     ค้นจากชื่อผู้ใช้ ชื่อเต็ม หรืออีเมล
 *      role       กรองตามสิทธิ์
 *      is_active  1 / 0 / null
 *      limit / offset   เฉพาะ user_list
 *
 *  ---------------------------------------------------------------
 *  กฎความปลอดภัยที่บังคับในชั้นนี้ (ไม่ใช่แค่ที่หน้าจอ)
 *  ---------------------------------------------------------------
 *    1. ปิดใช้งานบัญชีตัวเองไม่ได้ — กันล็อกตัวเองออกจากระบบ
 *    2. เปลี่ยนสิทธิ์ของตัวเองไม่ได้ — กันลดสิทธิ์ตัวเองจนกู้ไม่ได้
 *    3. บัญชี super_admin แก้จากหน้าจัดการผู้ใช้ไม่ได้เลย ไม่ว่าใครสั่ง
 *       ทั้งแก้ข้อมูล เปลี่ยนสิทธิ์ ปิดใช้งาน และตั้ง/คืนรหัสผ่าน
 *       เจ้าของบัญชีเปลี่ยนได้เฉพาะรหัสผ่านที่หน้า "เปลี่ยนรหัสผ่าน"
 *    4. ตั้งสิทธิ์ super_admin ให้บัญชีอื่นไม่ได้ — สงวนให้บัญชีเดียว
 *
 *  รหัสผ่านเข้ารหัสด้วย md5(md5(md5())) ตามที่ config_ctrl/checkmember.php ใช้
 *  (วิธีนี้ไม่ปลอดภัยตามมาตรฐานปัจจุบัน แต่ต้องเข้ากับระบบล็อกอินเดิม
 *   ถ้าจะย้ายไป password_hash() ต้องแก้ทั้ง checkmember.php และที่นี่พร้อมกัน)
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';

define('USERNAME_MIN', 3);
define('USERNAME_MAX', 50);
define('FULLNAME_MAX', 150);

/* ชื่อผู้ใช้เดียวที่ถือสิทธิ์ super_admin ได้
   บังคับที่นี่ ไม่ได้บังคับที่ฐานข้อมูล เพราะ MySQL 5.0 ยังไม่รองรับ
   CHECK constraint จริง (ประกาศได้แต่ไม่ทำงาน ซึ่งอันตรายกว่าไม่ประกาศ) */
define('SUPER_ADMIN_USERNAME', 'admin');


/* ---------- สิทธิ์ทั้งหมดที่มีในระบบ : รหัส => ชื่อที่คนอ่าน ----------
 * ใช้สำหรับ "แสดงผล" นิยามไว้ที่เดียว ทั้งหน้าจอ เมนู และ API ใช้ร่วมกัน
 */
function user_roles()
{
    return array(
        'super_admin' => 'ผู้ดูแลระบบสูงสุด',
        'admin'       => 'ผู้ดูแลระบบ',
        'approver'    => 'ผู้อนุมัติ',
        'general'     => 'ผู้ใช้งานทั่วไป'
    );
}

/* ---------- สิทธิ์ที่เลือกให้กันได้จริงบนหน้าจอ ----------
 * ไม่มี super_admin เพราะสงวนไว้ให้บัญชี admin บัญชีเดียว
 * จึงไม่มีเหตุให้ต้องเลือกจาก dropdown เลย
 */
function user_assignable_roles()
{
    $roles = user_roles();
    unset($roles['super_admin']);
    return $roles;
}

function user_role_label($role)
{
    $labels = user_roles();
    return isset($labels[$role]) ? $labels[$role] : $role;
}

/* เข้ารหัสรหัสผ่านให้ตรงกับที่ระบบล็อกอินใช้ตรวจ */
function user_hash_password($plain)
{
    return md5(md5(md5($plain)));
}

/* ---------- รหัสผ่านตั้งต้นของบัญชีใหม่ ----------
 * คนสร้างบัญชีไม่ต้องคิดรหัสผ่านให้ และไม่ต้องพิมพ์รหัสผ่านลงตาราง
 * (ซึ่งเคยทำให้รหัสผ่านถูกลากคัดลอกข้ามบัญชีมาแล้ว)
 * เจ้าของบัญชีเข้าระบบด้วยรหัสนี้ครั้งแรก แล้วไปเปลี่ยนเองที่หน้า "เปลี่ยนรหัสผ่าน"
 */
define('USER_DEFAULT_PASSWORD', '4123');


/* ---------- เงื่อนไข WHERE ที่ list กับ count ใช้ร่วมกัน ---------- */
function user_build_filter($options)
{
    $search    = isset($options['search'])    ? trim($options['search']) : '';
    $role      = isset($options['role'])      ? $options['role']         : '';
    $is_active = isset($options['is_active']) ? $options['is_active']    : null;

    $where  = array();
    $types  = '';
    $values = array();

    if ($search !== '') {
        $where[]  = "(u.`username` LIKE ? OR u.`full_name` LIKE ? OR u.`email` LIKE ?)";
        $types   .= 'sss';
        $like     = '%' . $search . '%';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
    }

    if ($role !== '') {
        $where[]  = "u.`role` = ?";
        $types   .= 's';
        $values[] = $role;
    }

    if ($is_active !== null && $is_active !== '') {
        $where[]  = "u.`is_active` = ?";
        $types   .= 'i';
        $values[] = (int)$is_active;
    }

    return array(
        'sql'    => (count($where) > 0) ? (' WHERE ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'values' => $values
    );
}

/* PHP 5.2 ไม่มี argument unpacking และ bind_param ต้องการ reference */
function user_bind($stmt, $types, $values)
{
    if ($types === '') { return; }

    $args = array($stmt, $types);
    for ($i = 0; $i < count($values); $i++) {
        $args[] = &$values[$i];
    }
    call_user_func_array('mysqli_stmt_bind_param', $args);
}

/* ---------- หน่วยงานที่ผู้ใช้ดูแล ---------- */
function user_department_ids($username)
{
    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT `department_id` FROM `tb_user_department`
         WHERE `username` = ? ORDER BY `department_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $department_id);

    $ids = array();
    while (mysqli_stmt_fetch($stmt)) {
        $ids[] = (int)$department_id;
    }
    mysqli_stmt_close($stmt);
    return $ids;
}

function user_department_save($username, $department_ids, $actor)
{
    $connect = db_connect();
    $ids = array();
    if (is_array($department_ids)) {
        for ($i = 0; $i < count($department_ids); $i++) {
            $id = (int)$department_ids[$i];
            if ($id > 0 && !in_array($id, $ids)) { $ids[] = $id; }
        }
    }

    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_user_department` WHERE `username` = ?");
    if (!$stmt) { return false; }
    mysqli_stmt_bind_param($stmt, 's', $username);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) { return false; }

    if (count($ids) === 0) { return true; }

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_user_department`
         (`username`, `department_id`, `created_at`, `created_by`, `updated_by`)
         VALUES (?, ?, NOW(), ?, ?)");
    if (!$stmt) { return false; }

    for ($i = 0; $i < count($ids); $i++) {
        $department_id = $ids[$i];
        mysqli_stmt_bind_param($stmt, 'siss', $username, $department_id, $actor, $actor);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

function user_has_department($username, $department_id)
{
    $department_id = (int)$department_id;
    if ($department_id <= 0) { return false; }
    return in_array($department_id, user_department_ids($username));
}


/* ============================================================
 *  อ่านข้อมูล
 * ============================================================ */

function user_count($options = array())
{
    $connect = db_connect();
    $filter  = user_build_filter($options);

    $stmt = mysqli_prepare($connect, "SELECT COUNT(*) FROM `tb_user` u" . $filter['sql']);
    if (!$stmt) { return 0; }

    user_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ดึงรายชื่อผู้ใช้ พร้อมชื่อบริษัทต้นสังกัด
 * ไม่คืนคอลัมน์ password ออกไปเด็ดขาด แม้จะเป็นค่าที่เข้ารหัสแล้วก็ตาม */
function user_list($options = array())
{
    $connect = db_connect();
    $filter  = user_build_filter($options);

    $limit  = isset($options['limit'])  ? (int)$options['limit']  : 0;
    $offset = isset($options['offset']) ? (int)$options['offset'] : 0;
    if ($offset < 0) { $offset = 0; }

    $sql = "SELECT u.`username`, u.`full_name`, u.`first_name`, u.`last_name`, u.`email`,
                   u.`role`, u.`position`, u.`agency_id`, u.`is_active`, u.`created_at`,
                   c.`company_name`, u.`dept_id`, d.`department_name`
            FROM `tb_user` u
            LEFT JOIN `tb_company` c    ON c.`company_id`    = u.`agency_id`
            LEFT JOIN `tb_department` d ON d.`department_id` = u.`dept_id`"
         . $filter['sql']
         . " ORDER BY u.`username` ASC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;
    }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    user_bind($stmt, $filter['types'], $filter['values']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $username, $full_name, $first_name, $last_name, $email,
        $role, $position, $agency_id, $is_active, $created_at, $company_name,
        $dept_id, $department_name);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'username'     => $username,
            'full_name'    => $full_name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'email'        => $email,
            'role'         => $role,
            'position'     => $position,
            'agency_id'    => (int)$agency_id,
            'is_active'    => (int)$is_active,
            'created_at'   => $created_at,
            'company_name' => ($company_name === null) ? '' : $company_name,
            'dept_id'      => ($dept_id === null) ? 0 : (int)$dept_id,
            'department_name' => ($department_name === null) ? '' : $department_name
        );
    }
    mysqli_stmt_close($stmt);

    /* ต้องปิด statement หลักก่อน เพราะ user_department_ids() ใช้ connection เดียวกัน
       ถ้าเรียกระหว่าง while ที่ยัง fetch ไม่ครบ mysqli จะเตือนว่า
       "All data must be fetched before a new statement prepare takes place" */
    for ($i = 0; $i < count($rows); $i++) {
        $rows[$i]['dept_ids'] = user_department_ids($rows[$i]['username']);
    }

    return $rows;
}


/* ดึงผู้ใช้คนเดียว — คืน null ถ้าไม่พบ (ไม่คืน password) */
function user_find($username)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "SELECT u.`username`, u.`full_name`, u.`first_name`, u.`last_name`, u.`email`,
                u.`role`, u.`position`, u.`agency_id`, u.`is_active`,
                u.`dept_id`, d.`department_name`
         FROM `tb_user` u
         LEFT JOIN `tb_department` d ON d.`department_id` = u.`dept_id`
         WHERE u.`username` = ? LIMIT 1");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $u, $full_name, $first_name, $last_name, $email, $role, $position, $agency_id, $is_active,
        $dept_id, $department_name);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }

    $dept_ids = user_department_ids($u);
    if (count($dept_ids) === 0 && (int)$dept_id > 0) {
        $dept_ids = array((int)$dept_id);
    }

    return array(
        'username'   => $u,
        'full_name'  => $full_name,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
        'role'       => $role,
        'position'   => $position,
        'agency_id'  => (int)$agency_id,
        'is_active'  => (int)$is_active,
        'dept_id'    => ($dept_id === null) ? 0 : (int)$dept_id,
        'department_name' => ($department_name === null) ? '' : $department_name,
        'dept_ids'   => $dept_ids
    );
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก
 * ============================================================ */

function user_validate($data, $is_new)
{
    $full_name = isset($data['full_name']) ? trim($data['full_name']) : '';
    $email     = isset($data['email'])     ? trim($data['email'])     : '';
    $role      = isset($data['role'])      ? $data['role']            : '';

    if ($is_new) {
        $username = isset($data['username']) ? trim($data['username']) : '';
        $len      = strlen($username);

        if ($username === '') {
            return 'กรุณากรอกชื่อผู้ใช้';
        }
        if ($len < USERNAME_MIN || $len > USERNAME_MAX) {
            return 'ชื่อผู้ใช้ต้องยาว ' . USERNAME_MIN . '-' . USERNAME_MAX . ' ตัวอักษร';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            return 'ชื่อผู้ใช้ใช้ได้เฉพาะ A-Z, a-z, 0-9, จุด, ขีดกลาง และขีดล่าง';
        }
    }

    if ($full_name === '') {
        return 'กรุณากรอกชื่อ-นามสกุล';
    }
    if (db_text_length($full_name) > FULLNAME_MAX) {
        return 'ชื่อ-นามสกุลยาวเกิน ' . FULLNAME_MAX . ' ตัวอักษร';
    }

    if ($email !== '' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
        return 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    $roles = user_roles();
    if (!isset($roles[$role])) {
        return 'กรุณาเลือกสิทธิ์การใช้งาน';
    }

    /* ผู้อนุมัติต้องมีหน่วยงาน เพราะขอบเขตการอนุมัติผูกกับหน่วยงาน
       ถ้าไม่มี จะกลายเป็นบัญชีที่กดอนุมัติอะไรไม่ได้เลยแต่ดูเหมือนมีสิทธิ์
       (ผู้ดูแลระบบไม่ต้องมี เพราะอนุมัติข้ามหน่วยงานได้อยู่แล้ว) */
    $dept_ids = isset($data['dept_ids']) && is_array($data['dept_ids']) ? $data['dept_ids'] : array();
    $dept_id = isset($data['dept_id']) ? (int)$data['dept_id'] : 0;
    if (count($dept_ids) === 0 && $dept_id > 0) { $dept_ids = array($dept_id); }
    if ($role === 'approver' && count($dept_ids) === 0) {
        return 'ผู้อนุมัติต้องระบุหน่วยงาน เพราะอนุมัติได้เฉพาะใบเบิกของหน่วยงานตัวเอง';
    }

    /* รหัสผ่านไม่บังคับกรอกแล้ว
       บัญชีใหม่ที่ไม่ได้ระบุมา จะได้รหัสผ่านตั้งต้น USER_DEFAULT_PASSWORD
       ส่วนตอนแก้ ไม่ใส่ = ใช้รหัสเดิมต่อ */

    return '';
}


/* ---------- ตรวจสิทธิ์ของคนที่กำลังสั่งงาน ----------
 * คืนข้อความผิดพลาด หรือ '' ถ้าผ่าน
 */
function user_guard($target_username, $new_role, $new_is_active, $actor, $actor_role, $current_role)
{
    /* ---------- บัญชีสิทธิ์สูงสุดแตะจากหน้าจัดการผู้ใช้ไม่ได้เลย ----------
     * ปิดทุกทาง ไม่ว่าคนสั่งจะเป็นใคร รวมถึงเจ้าของบัญชีเอง
     *   - ถ้าเปิดให้ผู้ดูแลระบบทั่วไปแก้ได้ จะปิดใช้งานบัญชีสูงสุด
     *     หรือย้ายบริษัทต้นสังกัดจนคนอื่นเข้าระบบไม่ได้
     *   - ถ้าเปิดให้เจ้าของบัญชีแก้ผ่านตารางได้ พลาดลากคัดลอกทับทีเดียว
     *     ก็เสียบัญชีที่กู้ระบบได้บัญชีสุดท้ายไป
     * ทางที่ยังเปิดอยู่คือหน้า "เปลี่ยนรหัสผ่าน" ซึ่งต้องยืนยันรหัสเดิมก่อน
     * และแก้ได้เฉพาะรหัสผ่านของบัญชีที่ล็อกอินอยู่เท่านั้น
     * ถ้าต้องแก้ชื่อหรืออีเมลของบัญชีนี้จริง ๆ ต้องแก้ที่ฐานข้อมูลโดยตรง
     */
    if ($current_role === 'super_admin') {
        return 'บัญชีผู้ดูแลระบบสูงสุดแก้ไขจากหน้าจัดการผู้ใช้ไม่ได้ '
             . 'เจ้าของบัญชีเปลี่ยนได้เฉพาะรหัสผ่านที่หน้า "เปลี่ยนรหัสผ่าน"';
    }

    /* สิทธิ์สูงสุดสงวนไว้ให้บัญชีเดียวเท่านั้น
       ต่อให้เป็น super_admin เองก็ตั้งให้คนอื่นไม่ได้
       (มาถึงตรงนี้แปลว่าเป้าหมายยังไม่ใช่ super_admin จึงเป็นการยกระดับขึ้นมาใหม่) */
    if ($new_role === 'super_admin') {
        return 'สิทธิ์ผู้ดูแลระบบสูงสุดสงวนไว้ให้บัญชี "' . SUPER_ADMIN_USERNAME . '" เท่านั้น '
             . 'และตั้งให้บัญชีอื่นจากหน้านี้ไม่ได้';
    }

    /* ห้ามยุ่งกับบัญชีตัวเองในทางที่อาจล็อกตัวเองออก */
    if ($target_username !== '' && $target_username === $actor) {
        if ($current_role !== '' && $new_role !== $current_role) {
            return 'เปลี่ยนสิทธิ์ของบัญชีตัวเองไม่ได้';
        }
        if ((int)$new_is_active !== 1) {
            return 'ปิดใช้งานบัญชีตัวเองไม่ได้';
        }
    }

    return '';
}


/* ============================================================
 *  เขียนข้อมูล
 * ============================================================ */

function user_create($data, $actor, $actor_role)
{
    $error = user_validate($data, true);
    if ($error !== '') { return db_fail($error); }

    $role      = $data['role'];
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;
    $username  = trim($data['username']);

    /* ส่งชื่อผู้ใช้เข้าไปด้วย เพื่อให้กฎ "super_admin สงวนให้บัญชีเดียว" ตรวจได้ถูกตัว
       ส่วน is_active ส่ง 1 เสมอ เพราะกฎห้ามปิดบัญชีตัวเองไม่เกี่ยวกับการสร้างคนใหม่ */
    $guard = user_guard($username, $role, 1, $actor, $actor_role, '');
    if ($guard !== '') { return db_fail($guard); }

    $connect   = db_connect();
    $full_name = trim($data['full_name']);
    $email     = isset($data['email']) ? trim($data['email']) : '';
    $agency_id = isset($data['agency_id']) ? (int)$data['agency_id'] : 0;

    /* ไม่ได้ระบุรหัสผ่านมา = ใช้รหัสตั้งต้น แล้วให้เจ้าของบัญชีไปเปลี่ยนเอง */
    $plain = isset($data['new_password']) ? $data['new_password'] : '';
    if ($plain === '') { $plain = USER_DEFAULT_PASSWORD; }
    $password = user_hash_password($plain);

    /* แยกชื่อกับนามสกุลจากชื่อเต็ม เอาช่องว่างแรกเป็นตัวแบ่ง
       ถ้าไม่มีช่องว่าง ถือว่าเป็นชื่ออย่างเดียว */
    $space = strpos($full_name, ' ');
    if ($space === false) {
        $first_name = $full_name;
        $last_name  = '';
    } else {
        $first_name = substr($full_name, 0, $space);
        $last_name  = trim(substr($full_name, $space + 1));
    }

    /* หน่วยงานไม่บังคับตอนสร้าง เพราะบางบัญชี (เช่นผู้ดูแลระบบ) ไม่ได้สังกัดหน่วยงานไหน
       แต่ผู้อนุมัติที่ไม่มีหน่วยงานจะอนุมัติใบเบิกไม่ได้เลย — ดู request_decide() */
    $dept_ids = isset($data['dept_ids']) && is_array($data['dept_ids']) ? $data['dept_ids'] : array();
    $dept_id = (isset($data['dept_id']) && (int)$data['dept_id'] > 0)
               ? (int)$data['dept_id'] : ((count($dept_ids) > 0) ? (int)$dept_ids[0] : null);

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_user`
         SET `username` = ?, `password` = ?, `is_active` = ?,
             `full_name` = ?, `first_name` = ?, `last_name` = ?, `email` = ?,
             `role` = ?, `agency_id` = ?, `dept_id` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'ssisssssiis',
        $username, $password, $is_active,
        $full_name, $first_name, $last_name, $email,
        $role, $agency_id, $dept_id, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        if ($errno === 1062) {
            return db_fail('ชื่อผู้ใช้ "' . $username . '" ถูกใช้ไปแล้ว', $errno);
        }
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    if (!user_department_save($username, $dept_ids, $actor)) {
        return db_fail('บันทึกหน่วยงานที่ผู้ใช้ดูแลไม่สำเร็จ');
    }

    return db_ok('เพิ่มผู้ใช้ "' . $username . '" เรียบร้อย');
}


function user_update($username, $data, $actor, $actor_role)
{
    $username = trim($username);
    if ($username === '') { return db_fail('ไม่พบผู้ใช้ที่ต้องการแก้ไข'); }

    $current = user_find($username);
    if ($current === null) { return db_fail('ไม่พบผู้ใช้ "' . $username . '" ในระบบ'); }

    $error = user_validate($data, false);
    if ($error !== '') { return db_fail($error); }

    $role      = $data['role'];
    $is_active = (isset($data['is_active']) && (int)$data['is_active'] === 1) ? 1 : 0;

    $guard = user_guard($username, $role, $is_active, $actor, $actor_role, $current['role']);
    if ($guard !== '') { return db_fail($guard); }

    $connect   = db_connect();
    $full_name = trim($data['full_name']);
    $email     = isset($data['email']) ? trim($data['email']) : '';
    $agency_id = isset($data['agency_id']) ? (int)$data['agency_id'] : 0;

    $dept_ids = isset($data['dept_ids']) && is_array($data['dept_ids']) ? $data['dept_ids'] : array();
    $dept_id = (isset($data['dept_id']) && (int)$data['dept_id'] > 0)
               ? (int)$data['dept_id'] : ((count($dept_ids) > 0) ? (int)$dept_ids[0] : null);

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_user`
         SET `full_name` = ?, `email` = ?, `role` = ?, `agency_id` = ?, `dept_id` = ?,
             `is_active` = ?, `updated_by` = ?
         WHERE `username` = ?");
    if (!$stmt) { return db_fail('เตรียมคำสั่งบันทึกไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sssiiiss',
        $full_name, $email, $role, $agency_id, $dept_id, $is_active, $actor, $username);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    if (!user_department_save($username, $dept_ids, $actor)) {
        return db_fail('บันทึกหน่วยงานที่ผู้ใช้ดูแลไม่สำเร็จ');
    }

    /* เปลี่ยนรหัสผ่านเฉพาะกรณีที่กรอกมา ถ้าเว้นว่างคือใช้รหัสเดิมต่อ */
    $new_password = isset($data['new_password']) ? $data['new_password'] : '';
    if ($new_password !== '') {
        $res = user_set_password($username, $new_password, $actor, $actor_role);
        if (!$res['ok']) { return $res; }
        return db_ok('แก้ไขผู้ใช้ "' . $username . '" และตั้งรหัสผ่านใหม่เรียบร้อย');
    }

    return db_ok('แก้ไขผู้ใช้ "' . $username . '" เรียบร้อย');
}


function user_save($username, $data, $actor, $actor_role)
{
    $username = trim($username);
    if ($username !== '') {
        return user_update($username, $data, $actor, $actor_role);
    }
    return user_create($data, $actor, $actor_role);
}


/* ตั้งรหัสผ่านใหม่ให้ผู้ใช้ */
function user_set_password($username, $plain, $actor, $actor_role)
{
    $username = trim($username);
    if ($plain === '') {
        return db_fail('กรุณากรอกรหัสผ่านใหม่');
    }

    $current = user_find($username);
    if ($current === null) { return db_fail('ไม่พบผู้ใช้ "' . $username . '" ในระบบ'); }

    /* ตั้งรหัสผ่านให้บัญชีสิทธิ์สูงสุดจากทางนี้ไม่ได้ ไม่ว่าใครสั่ง
       เจ้าของบัญชีต้องไปตั้งเองที่หน้า "เปลี่ยนรหัสผ่าน" ซึ่งต้องยืนยันรหัสเดิมก่อน */
    if ($current['role'] === 'super_admin') {
        return db_fail('ตั้งรหัสผ่านให้บัญชีผู้ดูแลระบบสูงสุดจากหน้านี้ไม่ได้ '
                     . 'เจ้าของบัญชีต้องเปลี่ยนเองที่หน้า "เปลี่ยนรหัสผ่าน"');
    }

    $connect = db_connect();
    $hash    = user_hash_password($plain);

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_user` SET `password` = ?, `updated_by` = ? WHERE `username` = ?");
    if (!$stmt) { return db_fail('ตั้งรหัสผ่านไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sss', $hash, $actor, $username);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('ตั้งรหัสผ่านใหม่ให้ "' . $username . '" เรียบร้อย');
}


/* ---------- คืนรหัสผ่านกลับเป็นค่าตั้งต้น ----------
 * ใช้ตอนผู้ใช้ลืมรหัสผ่าน ผู้ดูแลกดปุ่มเดียวจบ ไม่ต้องคิดรหัสใหม่
 * และไม่ต้องพิมพ์รหัสผ่านลงในตาราง ซึ่งเป็นทางที่เคยทำให้รหัสหลุดข้ามบัญชี
 *
 * ยกเว้นบัญชีผู้ดูแลระบบสูงสุด — ห้ามคืนรหัสผ่านผ่านทางนี้ ไม่ว่าใครสั่งก็ตาม
 *   - ถ้าให้ผู้ดูแลระบบทั่วไปกดได้ เท่ากับยึดบัญชีสูงสุดได้ทันที
 *     เพราะรู้อยู่แล้วว่ารหัสตั้งต้นคืออะไร แล้วล็อกอินเข้าไปได้เลย
 *   - แม้แต่เจ้าของบัญชีเองก็ไม่ควรใช้ทางนี้ เพราะเป็นการตั้งรหัสเป็นค่าที่
 *     ทุกคนในระบบรู้ ควรไปตั้งรหัสของตัวเองที่หน้า "เปลี่ยนรหัสผ่าน" แทน
 */
function user_reset_password($username, $actor, $actor_role)
{
    $username = trim($username);
    $current  = user_find($username);

    if ($current === null) {
        return db_fail('ไม่พบผู้ใช้ "' . $username . '" ในระบบ');
    }

    if ($current['role'] === 'super_admin') {
        return db_fail('คืนรหัสผ่านของบัญชีผู้ดูแลระบบสูงสุดไม่ได้ '
                     . 'เจ้าของบัญชีต้องเปลี่ยนเองที่หน้า "เปลี่ยนรหัสผ่าน"');
    }

    $res = user_set_password($username, USER_DEFAULT_PASSWORD, $actor, $actor_role);
    if (!$res['ok']) {
        return $res;
    }
    return db_ok('คืนรหัสผ่านของ "' . $username . '" เป็น ' . USER_DEFAULT_PASSWORD . ' เรียบร้อย');
}


/* ---------- เจ้าของบัญชีเปลี่ยนรหัสผ่านของตัวเอง ----------
 * ต่างจาก user_set_password ตรงที่ต้องยืนยันรหัสผ่านปัจจุบันก่อน
 * และแก้ได้เฉพาะบัญชีตัวเองเท่านั้น ไม่ว่าจะมีสิทธิ์ระดับไหน
 *
 * ผู้เรียกต้องส่ง username ของคนที่ล็อกอินอยู่มาเอง (จาก session)
 * ห้ามรับชื่อผู้ใช้จากฟอร์ม ไม่งั้นจะกลายเป็นเปลี่ยนรหัสผ่านให้คนอื่นได้
 */
function user_change_own_password($username, $current_plain, $new_plain, $confirm_plain)
{
    $username = trim($username);
    if ($username === '') {
        return db_fail('ไม่พบบัญชีที่กำลังใช้งานอยู่ กรุณาเข้าสู่ระบบใหม่');
    }
    if ($current_plain === '') {
        return db_fail('กรุณากรอกรหัสผ่านปัจจุบัน');
    }
    if ($new_plain === '') {
        return db_fail('กรุณากรอกรหัสผ่านใหม่');
    }
    if ($new_plain !== $confirm_plain) {
        return db_fail('รหัสผ่านใหม่กับช่องยืนยันไม่ตรงกัน');
    }
    if ($new_plain === $current_plain) {
        return db_fail('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
    }

    $connect = db_connect();
    $hash    = user_hash_password($current_plain);

    /* ตรวจรหัสผ่านปัจจุบันด้วยการเทียบในฐานข้อมูลโดยตรง
       ไม่ดึงรหัสผ่านที่เข้ารหัสไว้ออกมานอกฟังก์ชันนี้เลย */
    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_user`
         WHERE `username` = ? AND `password` = ? AND `is_active` = 1");
    if (!$stmt) { return db_fail('ตรวจรหัสผ่านไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'ss', $username, $hash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $found);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ((int)$found !== 1) {
        return db_fail('รหัสผ่านปัจจุบันไม่ถูกต้อง');
    }

    $new_hash = user_hash_password($new_plain);

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_user` SET `password` = ?, `updated_by` = ? WHERE `username` = ?");
    if (!$stmt) { return db_fail('เปลี่ยนรหัสผ่านไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sss', $new_hash, $username, $username);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนรหัสผ่านเรียบร้อย');
}


/* สลับสถานะเปิด/ปิดใช้งาน */
function user_toggle($username, $actor, $actor_role)
{
    $username = trim($username);
    $current  = user_find($username);
    if ($current === null) { return db_fail('ไม่พบผู้ใช้ "' . $username . '" ในระบบ'); }

    $next = ($current['is_active'] === 1) ? 0 : 1;

    $guard = user_guard($username, $current['role'], $next, $actor, $actor_role, $current['role']);
    if ($guard !== '') { return db_fail($guard); }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_user` SET `is_active` = ?, `updated_by` = ? WHERE `username` = ?");
    if (!$stmt) { return db_fail('เปลี่ยนสถานะไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'iss', $next, $actor, $username);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    return db_ok('เปลี่ยนสถานะการใช้งานเรียบร้อย');
}


/* ============================================================
 *  ใครควรได้รับแจ้งเตือน
 * ============================================================ */

/* ---------- คนที่กดอนุมัติใบเบิกของหน่วยงานนี้ได้จริง ----------
 * ใช้ตอนมีใบเบิกใหม่เข้ามา เพื่อรู้ว่าต้องเตือนใครบ้าง
 *
 * ลำดับการหา:
 *   1. ผู้อนุมัติที่สังกัดหน่วยงานนั้น — คนกลุ่มนี้คือเจ้าของงานตัวจริง
 *   2. ไม่มีสักคน จึงถอยไปหาผู้ดูแลระบบ
 *
 * ข้อ 2 สำคัญ ไม่ใช่ของแถม: หน่วยงานที่ยังไม่ได้ตั้งผู้อนุมัติจะมีใบเบิก
 * ค้างอยู่โดยไม่มีใครรู้เลยว่ามีของค้าง ผู้ขอก็ได้แต่รอ ระบบต้องไม่เงียบ
 *
 * ไม่แจ้งคนที่ทำรายการเอง (ผู้อนุมัติทำใบเบิกของตัวเองได้ แต่อนุมัติเองไม่ได้)
 * คืนเฉพาะบัญชีที่ยังเปิดใช้งาน — คนที่ถูกปิดบัญชีเข้าระบบไม่ได้อยู่แล้ว
 */
function user_approvers_for_dept($dept_id, $exclude = '')
{
    $dept_id = (int)$dept_id;
    $connect = db_connect();

    $rows = array();

    if ($dept_id > 0) {
        $stmt = mysqli_prepare($connect,
            "SELECT u.`username`, u.`full_name`, u.`email`
             FROM `tb_user` u
             INNER JOIN `tb_user_department` ud ON ud.`username` = u.`username`
             WHERE u.`role` = 'approver' AND u.`is_active` = 1 AND ud.`department_id` = ?
             ORDER BY u.`username` ASC");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $dept_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $u, $fn, $em);
            while (mysqli_stmt_fetch($stmt)) {
                if ($u === $exclude) { continue; }
                $rows[] = array('username' => $u, 'full_name' => $fn, 'email' => $em);
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (count($rows) > 0) {
        return $rows;
    }

    return user_admins($exclude);
}


/* ---------- ผู้ดูแลระบบทั้งหมดที่ยังใช้งานอยู่ ----------
 * ใช้เป็นปลายทางสำรองเมื่อไม่มีผู้อนุมัติของหน่วยงาน
 * และใช้กับแจ้งเตือนระดับระบบที่ไม่มีเจ้าของชัดเจน
 */
function user_admins($exclude = '')
{
    $connect = db_connect();

    $result = @mysqli_query($connect,
        "SELECT `username`, `full_name`, `email`
         FROM `tb_user`
         WHERE `role` IN ('super_admin', 'admin') AND `is_active` = 1
         ORDER BY `username` ASC");
    if (!$result) { return array(); }

    $rows = array();
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['username'] === $exclude) { continue; }
        $rows[] = array(
            'username'  => $row['username'],
            'full_name' => $row['full_name'],
            'email'     => $row['email']
        );
    }
    return $rows;
}
