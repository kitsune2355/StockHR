<?php
/* ============================================================
 *  backend/document.php — เอกสารรับเข้า / ใบเบิก / ใบจ่าย
 *
 *  ทั้ง 3 ประเภทใช้ตารางคู่เดียวกัน (tb_doc_header + tb_doc_item)
 *  ไฟล์นี้จึงรวมงานที่เหมือนกันไว้ที่เดียว: ออกเลขที่ อ่าน ค้น บันทึกหัว+บรรทัด
 *  ส่วนกฎเฉพาะประเภท (ใครทำได้ ตัดสต๊อกตอนไหน) อยู่ในฟังก์ชันของประเภทนั้น
 *
 *  เส้นทางเดินของเอกสาร (ย้ำจากไฟล์ sql/008)
 *    รับเข้า  บันทึก -> received                      บวกสต๊อกทันที
 *    ใบเบิก  บันทึก -> waiting -> approved | rejected  ไม่แตะสต๊อก
 *                              approved -> issued      เมื่อจ่ายครบทุกบรรทัด
 *    ใบจ่าย  บันทึก -> issued -> received              ลบสต๊อกทันที
 *
 *  กติกาของชั้น backend ยังเหมือนเดิม: ไม่ echo ไม่ redirect ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/stock.php';

/* doc_scope_for()/doc_can_read() ต้องรู้หน่วยงานต้นสังกัดของผู้ใช้ จึงต้องมี user_find()
   ดึงเข้ามาที่นี่ ไม่ปล่อยให้ผู้เรียกแต่ละรายรับผิดชอบเอง — ไม่งั้นสคริปต์ที่
   include เฉพาะ document.php จะพังตอนเรียกสองฟังก์ชันนี้ */
require_once dirname(__FILE__) . '/user.php';


/* ---------- ชนิดเอกสาร : รหัส => array(ชื่อ, คำนำหน้าเลขที่) ---------- */
function doc_types()
{
    return array(
        'receive' => array('label' => 'รับสินค้าเข้า', 'prefix' => 'RC'),
        'request' => array('label' => 'ใบเบิกสินค้า',  'prefix' => 'RQ'),
        'issue'   => array('label' => 'ใบจ่ายสินค้า',  'prefix' => 'IS'),
        'adjust'  => array('label' => 'ใบปรับยอดสินค้า', 'prefix' => 'ADJ')
    );
}

function doc_type_label($type)
{
    $types = doc_types();
    return isset($types[$type]) ? $types[$type]['label'] : $type;
}

/* ---------- สถานะ : รหัส => ชื่อที่แสดง ---------- */
function doc_statuses()
{
    return array(
        'waiting'   => 'รออนุมัติ',
        'approved'  => 'อนุมัติแล้ว',
        'rejected'  => 'ไม่อนุมัติ',
        'issued'    => 'จ่ายแล้ว',
        'received'  => 'รับของแล้ว',
        'cancelled' => 'ยกเลิก'
    );
}

function doc_status_label($status)
{
    $labels = doc_statuses();
    return isset($labels[$status]) ? $labels[$status] : $status;
}


/* ============================================================
 *  ออกเลขที่เอกสาร  <คำนำหน้า>-<ปีเดือน พ.ศ. 2 หลัก + เดือน>-<เลขรัน 4 หลัก>
 *  เช่น RQ-2608-0001
 *
 *  ต้องเรียกอยู่ใน transaction ที่ล็อกอยู่แล้ว
 *  ใช้ FOR UPDATE เพื่อกันสองคนกดบันทึกพร้อมกันแล้วได้เลขซ้ำ
 *  (คอลัมน์ doc_no เป็น UNIQUE อยู่แล้ว ถ้าหลุดจริงจะบันทึกไม่ผ่าน
 *   ไม่ใช่เขียนทับกันเงียบ ๆ — การล็อกช่วยไม่ให้ผู้ใช้เจอ error เฉย ๆ)
 * ============================================================ */
function doc_next_no($doc_type, $doc_date)
{
    $connect = db_connect();
    $types   = doc_types();
    $prefix  = isset($types[$doc_type]) ? $types[$doc_type]['prefix'] : 'DC';

    /* ปี พ.ศ. 2 หลัก + เดือน 2 หลัก — ตรงกับที่คนไทยอ่านเอกสารจริง
     *
     * ตรงนี้ตั้งใจไม่ใช้ thai_date_config() ต่างจากที่อื่นทั้งระบบ
     * เพราะ doc_no เป็นค่าที่ "เก็บลงฐานข้อมูล" ไม่ใช่ค่าที่แสดงผล
     * ถ้าไปผูกกับรูปแบบที่แก้ได้ วันหนึ่งมีคนเปลี่ยนรูปแบบวันที่ของหน้าจอ
     * เลขเอกสารที่ออกใหม่จะไม่ตรงกับของเก่า แล้ว LIKE ข้างล่างจะหาเลขล่าสุดไม่เจอ
     * ผลคือเลขรันเริ่มนับหนึ่งใหม่และชนกับเอกสารเดิม
     */
    $ts    = strtotime($doc_date !== '' ? $doc_date : date('Y-m-d'));
    $year  = (int)date('Y', $ts) + 543;
    $block = substr((string)$year, -2) . date('m', $ts);

    $like = $prefix . '-' . $block . '-%';

    $stmt = mysqli_prepare($connect,
        "SELECT `doc_no` FROM `tb_doc_header`
         WHERE `doc_no` LIKE ?
         ORDER BY `doc_no` DESC LIMIT 1 FOR UPDATE");
    if (!$stmt) { return ''; }

    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $last_no);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    $running = 1;
    if ($found && $last_no !== null) {
        $parts = explode('-', $last_no);
        $running = (int)$parts[count($parts) - 1] + 1;
    }

    return $prefix . '-' . $block . '-' . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}


/* ============================================================
 *  อ่านเอกสาร
 * ============================================================ */

/* ---------- ค้นรายการเอกสาร (ใช้ทำหน้าประวัติทุกหน้า) ----------
 * $options
 *   doc_type     receive | request | issue  (บังคับ)
 *   status       สถานะเดียว หรือ array ของสถานะ
 *   search       ค้นจากเลขที่เอกสารหรือหมายเหตุ
 *   date_from / date_to
 *   requester    เอาเฉพาะใบที่คนนี้เป็นผู้ขอเบิก (ใช้ทำหน้า "ใบเบิกของฉัน")
 *   department_id / department_ids
 *   limit
 */
function doc_list($options = array())
{
    $connect = db_connect();

    $where  = array();
    $types  = '';
    $values = array();

    $doc_type = isset($options['doc_type']) ? $options['doc_type'] : '';
    if ($doc_type !== '') {
        $where[]  = "h.`doc_type` = ?";
        $types   .= 's';
        $values[] = $doc_type;
    }

    if (isset($options['status']) && $options['status'] !== '' && $options['status'] !== null) {
        $status = is_array($options['status']) ? $options['status'] : array($options['status']);
        $marks  = array();
        foreach ($status as $s) {
            $marks[]  = '?';
            $types   .= 's';
            $values[] = $s;
        }
        $where[] = "h.`status` IN (" . implode(',', $marks) . ")";
    }

    $search = isset($options['search']) ? trim($options['search']) : '';
    if ($search !== '') {
        $where[]  = "(h.`doc_no` LIKE ? OR h.`note` LIKE ?)";
        $types   .= 'ss';
        $like     = '%' . $search . '%';
        $values[] = $like;
        $values[] = $like;
    }

    $from = isset($options['date_from']) ? trim($options['date_from']) : '';
    if ($from !== '') {
        $where[]  = "h.`doc_date` >= ?";
        $types   .= 's';
        $values[] = $from;
    }

    $to = isset($options['date_to']) ? trim($options['date_to']) : '';
    if ($to !== '') {
        $where[]  = "h.`doc_date` <= ?";
        $types   .= 's';
        $values[] = $to;
    }

    $requester = isset($options['requester']) ? trim($options['requester']) : '';
    if ($requester !== '') {
        $where[]  = "h.`requester` = ?";
        $types   .= 's';
        $values[] = $requester;
    }

    if (isset($options['department_id']) && (int)$options['department_id'] > 0) {
        $where[]  = "h.`department_id` = ?";
        $types   .= 'i';
        $values[] = (int)$options['department_id'];
    }

    if (isset($options['department_ids']) && is_array($options['department_ids'])
        && count($options['department_ids']) > 0) {
        $marks = array();
        for ($i = 0; $i < count($options['department_ids']); $i++) {
            $marks[] = '?';
            $types .= 'i';
            $values[] = (int)$options['department_ids'][$i];
        }
        $where[] = "h.`department_id` IN (" . implode(',', $marks) . ")";
    }

    /* นับจำนวนบรรทัดและจำนวนรวมมาด้วยเลย หน้าประวัติจะได้ไม่ต้องยิงถามทีละใบ */
    $sql = "SELECT h.`doc_id`, h.`doc_type`, h.`doc_no`, h.`doc_date`, h.`status`,
                   h.`department_id`, h.`requester`, h.`ref_doc_id`, h.`note`,
                   h.`approved_by`, h.`approved_at`, h.`approve_note`,
                   h.`received_by`, h.`received_at`, h.`receiver_name`,
                   h.`cancel_note`, h.`created_at`, h.`created_by`,
                   dp.`department_name`,
                   /* ชื่อบริษัทของหน่วยงาน — ชื่อหน่วยงานซ้ำกันข้ามบริษัทได้
                      เช่น จัดซื้อ มีอยู่หลายบริษัท ถ้าไม่บอกบริษัทกำกับ
                      จะแยกไม่ออกว่าใบนี้เป็นของที่ไหน
                      (ห้ามใส่อัญประกาศคู่ในคอมเมนต์นี้ สตริง SQL คร่อมด้วยอัญประกาศคู่อยู่) */
                   cp.`company_name`,
                   ru.`full_name` AS `requester_name`,
                   r.`doc_no`     AS `ref_doc_no`,
                   (SELECT COUNT(*)          FROM `tb_doc_item` i WHERE i.`doc_id` = h.`doc_id`) AS `line_count`,
                   (SELECT COALESCE(SUM(i.`qty`),0) FROM `tb_doc_item` i WHERE i.`doc_id` = h.`doc_id`) AS `total_qty`,
                   /* มูลค่ารวมของใบ — หน้าประวัติการรับเข้าใช้ตอบว่าใบนี้เป็นเงินเท่าไร
                      total_cost ถูกคำนวณและเก็บไว้ตอนบันทึกแล้ว จึงไม่ต้องคูณใหม่ตรงนี้
                      และได้ตัวเลขเดียวกับที่ลงบัญชีไปจริง ไม่ใช่ค่าที่คำนวณสดจากราคาปัจจุบัน */
                   (SELECT COALESCE(SUM(i.`total_cost`),0) FROM `tb_doc_item` i WHERE i.`doc_id` = h.`doc_id`) AS `total_cost`
            FROM `tb_doc_header` h
            LEFT JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
            LEFT JOIN `tb_company`    cp ON cp.`company_id`    = dp.`company_id`
            LEFT JOIN `tb_user`       ru ON ru.`username`      = h.`requester`
            LEFT JOIN `tb_doc_header` r  ON r.`doc_id`         = h.`ref_doc_id`";

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY h.`doc_date` DESC, h.`doc_id` DESC";

    $limit = isset($options['limit']) ? (int)$options['limit'] : 500;
    if ($limit > 0) { $sql .= ' LIMIT ' . $limit; }

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return array(); }

    if ($types !== '') {
        $args = array($stmt, $types);
        for ($i = 0; $i < count($values); $i++) { $args[] = &$values[$i]; }
        call_user_func_array('mysqli_stmt_bind_param', $args);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $doc_id, $dtype, $doc_no, $doc_date, $status,
        $department_id, $req, $ref_doc_id, $note,
        $approved_by, $approved_at, $approve_note,
        $received_by, $received_at, $receiver_name,
        $cancel_note, $created_at, $created_by,
        $department_name, $company_name, $requester_name, $ref_doc_no, $line_count, $total_qty, $total_cost);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $rows[] = array(
            'doc_id'          => (int)$doc_id,
            'doc_type'        => $dtype,
            /* หน้าจอเรียกใช้ตอนตั้งชื่อกล่องยืนยัน เช่น "ยกเลิกใบจ่าย IS-6908-0001"
               ก่อนหน้านี้มีเฉพาะใน doc_find() ปุ่มที่กดจากตารางตรง ๆ จึงได้ undefined */
            'doc_type_label'  => doc_type_label($dtype),
            'doc_no'          => $doc_no,
            'doc_date'        => $doc_date,
            'status'          => $status,
            'status_label'    => doc_status_label($status),
            'department_id'   => (int)$department_id,
            'department_name' => ($department_name === null) ? '' : $department_name,
            'company_name'    => ($company_name === null) ? '' : $company_name,
            'requester'       => ($req === null) ? '' : $req,
            'requester_name'  => ($requester_name === null) ? '' : $requester_name,
            'ref_doc_id'      => (int)$ref_doc_id,
            'ref_doc_no'      => ($ref_doc_no === null) ? '' : $ref_doc_no,
            'note'            => $note,
            'approved_by'     => ($approved_by === null) ? '' : $approved_by,
            'approved_at'     => ($approved_at === null) ? '' : $approved_at,
            'approve_note'    => $approve_note,
            'received_by'     => ($received_by === null) ? '' : $received_by,
            'received_at'     => ($received_at === null) ? '' : $received_at,
            'receiver_name'   => $receiver_name,
            'cancel_note'     => $cancel_note,
            'created_at'      => $created_at,
            'created_by'      => ($created_by === null) ? '' : $created_by,
            'line_count'      => (int)$line_count,
            'total_qty'       => (float)$total_qty,
            'total_cost'      => (float)$total_cost
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ---------- อ่านเอกสารใบเดียว พร้อมรายการสินค้า ---------- */
function doc_find($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;

    $stmt = mysqli_prepare($connect,
        "SELECT h.`doc_id`, h.`doc_type`, h.`doc_no`, h.`doc_date`, h.`status`,
                h.`department_id`, h.`requester`, h.`ref_doc_id`, h.`note`,
                h.`approved_by`, h.`approved_at`, h.`approve_note`,
                h.`received_by`, h.`received_at`, h.`receiver_name`,
                h.`cancel_note`, h.`created_at`, h.`created_by`,
                dp.`department_name`, cp.`company_name`, ru.`full_name`, r.`doc_no`
         FROM `tb_doc_header` h
         LEFT JOIN `tb_department` dp ON dp.`department_id` = h.`department_id`
         LEFT JOIN `tb_company`    cp ON cp.`company_id`    = dp.`company_id`
         LEFT JOIN `tb_user`       ru ON ru.`username`      = h.`requester`
         LEFT JOIN `tb_doc_header` r  ON r.`doc_id`         = h.`ref_doc_id`
         WHERE h.`doc_id` = ? LIMIT 1");
    if (!$stmt) { return null; }

    mysqli_stmt_bind_param($stmt, 'i', $doc_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $id, $dtype, $doc_no, $doc_date, $status,
        $department_id, $req, $ref_doc_id, $note,
        $approved_by, $approved_at, $approve_note,
        $received_by, $received_at, $receiver_name,
        $cancel_note, $created_at, $created_by,
        $department_name, $company_name, $requester_name, $ref_doc_no);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) { return null; }

    return array(
        'doc_id'          => (int)$id,
        'doc_type'        => $dtype,
        'doc_type_label'  => doc_type_label($dtype),
        'doc_no'          => $doc_no,
        'doc_date'        => $doc_date,
        'status'          => $status,
        'status_label'    => doc_status_label($status),
        'department_id'   => (int)$department_id,
        'department_name' => ($department_name === null) ? '' : $department_name,
        /* ชื่อบริษัทของหน่วยงาน — เหตุผลเดียวกับใน doc_list()
           ชื่อหน่วยงานซ้ำกันข้ามบริษัทได้ ต้องมีบริษัทกำกับถึงจะรู้ว่าใบนี้ของที่ไหน */
        'company_name'    => ($company_name === null) ? '' : $company_name,
        'requester'       => ($req === null) ? '' : $req,
        'requester_name'  => ($requester_name === null) ? '' : $requester_name,
        'ref_doc_id'      => (int)$ref_doc_id,
        'ref_doc_no'      => ($ref_doc_no === null) ? '' : $ref_doc_no,
        'note'            => $note,
        'approved_by'     => ($approved_by === null) ? '' : $approved_by,
        'approved_at'     => ($approved_at === null) ? '' : $approved_at,
        'approve_note'    => $approve_note,
        'received_by'     => ($received_by === null) ? '' : $received_by,
        'received_at'     => ($received_at === null) ? '' : $received_at,
        'receiver_name'   => $receiver_name,
        'cancel_note'     => $cancel_note,
        'created_at'      => $created_at,
        'created_by'      => ($created_by === null) ? '' : $created_by,
        'items'           => doc_items($doc_id)
    );
}


/* ============================================================
 *  ขอบเขตที่แต่ละคนมองเห็น
 *
 *  คืน array ที่เอาไปรวมกับ options ของ doc_list() ได้เลย
 *      array('department_id' => 4)   เห็นทั้งหน่วยงาน
 *      array('requester' => 'somchai') เห็นเฉพาะใบของตัวเอง
 *      array()                        เห็นทุกใบ (ผู้ดูแลระบบ)
 *
 *  แยกออกมาจากชั้น api เพราะกติกานี้ต้องเหมือนกันทั้งตอนแสดงรายการ
 *  ตอนเปิดใบเดียว และตอนพิมพ์เอกสาร ถ้าเขียนซ้ำสามที่ วันหนึ่งจะแก้ไม่ครบ
 *  แล้วจะเหลือรูรั่วที่เปิดใบของหน่วยงานอื่นได้ทางใดทางหนึ่ง
 *
 *  กติกา:
 *    ผู้ดูแลระบบ  เห็นทุกหน่วยงาน — ต้องเข้าไปแก้ให้ได้ทุกที่เวลามีปัญหา
 *    ผู้อนุมัติ    เห็นเฉพาะหน่วยงานที่ตัวเองสังกัด (ตรงกับขอบเขตที่อนุมัติได้จริง)
 *    ผู้ใช้ทั่วไป  เห็นเฉพาะใบที่ตัวเองเป็นคนขอ
 *
 *  ผู้อนุมัติที่ยังไม่ได้ระบุหน่วยงานจะถูกบีบให้เห็นเฉพาะใบของตัวเอง
 *  ปลอดภัยกว่าปล่อยให้เห็นทั้งระบบเพราะข้อมูลไม่ครบ
 * ============================================================ */
function doc_scope_for($actor, $actor_role)
{
    if (in_array($actor_role, array('super_admin', 'admin'))) {
        return array();
    }

    if ($actor_role === 'approver') {
        $me   = user_find($actor);
        if ($me !== null && count($me['dept_ids']) > 0) {
            return array('department_ids' => $me['dept_ids']);
        }
    }

    return array('requester' => $actor);
}


/* ---------- คนนี้เปิดเอกสารใบนี้ได้ไหม ----------
 * คู่กับ doc_scope_for() — ตัวนั้นคุมตอนดึงรายการ ตัวนี้คุมตอนเปิดทีละใบ
 * ต้องมีทั้งคู่ ถ้าคุมแค่ตอนดึงรายการ คนที่รู้เลข doc_id จะพิมพ์เข้ามา
 * เปิดใบของหน่วยงานอื่นได้ตรง ๆ แล้วการกรองในหน้ารายการจะไม่มีความหมายเลย
 */
function doc_can_read($doc, $actor, $actor_role)
{
    if ($doc === null) { return false; }

    if (in_array($actor_role, array('super_admin', 'admin'))) {
        return true;
    }

    /* ใบปรับยอดไม่มีหน่วยงานและไม่มีผู้ขอ — ไม่เข้ากติกาหน่วยงานด้านล่าง
       เปิดให้ผู้ดูแลระบบ (ครอบด้านบนแล้ว) และคนที่อยู่ในรายชื่อผู้อนุมัติที่ตั้งไว้ */
    if ($doc['doc_type'] === 'adjust') {
        require_once dirname(__FILE__) . '/adjust_approver.php';
        return adjust_approver_can($actor, $actor_role);
    }

    if ($doc['requester'] !== '' && $doc['requester'] === $actor) {
        return true;
    }

    if ($actor_role === 'approver') {
        $me = user_find($actor);
        return ($me !== null && user_has_department($actor, $doc['department_id']));
    }

    return false;
}


/* ---------- ลายเซ็นผู้รับของ ----------
 *
 *  แยกออกมาจาก doc_find() โดยเจตนา ห้ามเอากลับไปรวม
 *
 *  receiver_sign เป็น MEDIUMTEXT ซึ่ง mysqli_stmt_bind_result() จะจองหน่วยความจำ
 *  ตาม "ความยาวสูงสุดที่ชนิดคอลัมน์รับได้" คือ 16 MB ไม่ใช่ตามข้อมูลจริงที่มีอยู่
 *  PHP 5.2 บนเครื่องนี้ตั้ง memory_limit ไว้ 24 MB จึงระเบิดทันทีที่เรียก
 *      Fatal error: Allowed memory size exhausted (tried to allocate 50331646 bytes)
 *  ผลคือ doc_find() พังทั้งฟังก์ชัน และกล่องรายละเอียดเอกสารเปิดไม่ได้ทั้งระบบ
 *
 *  ใช้ mysqli_query + fetch_assoc แทน prepared statement เพราะทางนี้จองหน่วยความจำ
 *  ตามขนาดข้อมูลจริง (ลายเซ็นจริงราว 10-30 KB) ไม่ใช่ตามขนาดสูงสุดของชนิดคอลัมน์
 *  ค่าที่ต่อเข้าไปใน SQL เป็น (int) ที่แปลงแล้ว ไม่ใช่ค่าดิบจากผู้ใช้
 *
 *  คนที่ต้องใช้ลายเซ็นมีแค่หน้าพิมพ์เอกสาร ซึ่งเปิดทีละใบ
 *  ส่วนกล่องรายละเอียดกับรายการเอกสารไม่ต้องใช้เลย จึงไม่มีเหตุให้ลากมาด้วยทุกครั้ง
 */
function doc_receiver_sign($doc_id)
{
    $doc_id  = (int)$doc_id;
    if ($doc_id <= 0) { return ''; }

    $connect = db_connect();
    $result  = @mysqli_query($connect,
        "SELECT `receiver_sign` FROM `tb_doc_header` WHERE `doc_id` = " . $doc_id . " LIMIT 1");
    if (!$result) { return ''; }

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    return ($row && $row['receiver_sign'] !== null) ? $row['receiver_sign'] : '';
}


/* ---------- รายการสินค้าในเอกสารใบหนึ่ง ---------- */
function doc_items($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;

    $stmt = mysqli_prepare($connect,
        "SELECT i.`item_id`, i.`line_no`, i.`product_id`, i.`qty`, i.`qty_issued`, i.`note`,
                i.`unit_cost`, i.`total_cost`,
                p.`product_code`, p.`product_name`, u.`unit_name`,
                COALESCE(b.`qty`, 0), p.`unit_cost`
         FROM `tb_doc_item` i
         INNER JOIN `tb_product` p ON p.`product_id` = i.`product_id`
         LEFT  JOIN `tb_unit`    u ON u.`unit_id`    = p.`unit_id`
         LEFT  JOIN `tb_stock_balance` b ON b.`product_id` = i.`product_id`
         WHERE i.`doc_id` = ?
         ORDER BY i.`line_no` ASC, i.`item_id` ASC");
    if (!$stmt) { return array(); }

    mysqli_stmt_bind_param($stmt, 'i', $doc_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,
        $item_id, $line_no, $product_id, $qty, $qty_issued, $note,
        $unit_cost, $total_cost,
        $product_code, $product_name, $unit_name, $balance, $last_price);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {

        /* ---------- ราคาที่เอาไปแสดงบนเอกสารที่พิมพ์ ----------
         * ใบจ่ายมี unit_cost แช่แข็งไว้ในบรรทัดตั้งแต่วินาทีที่ตัดสต๊อก จึงใช้ค่านั้น
         * ส่วนใบเบิกยังไม่เคยแตะสต๊อก บรรทัดจึงเป็น 0 เสมอ — ใช้ราคาซื้อล่าสุด
         * ของสินค้าแทน เพื่อให้ผู้อนุมัติเห็นว่าใบนี้มีมูลค่าประมาณเท่าไร
         *
         * เป็น "ราคาประมาณการ" ไม่ใช่ยอดที่ลงบัญชี ตัวเลขจริงจะถูกยืนยัน
         * อีกครั้งตอนจ่ายของ (หน้าพิมพ์เขียนกำกับไว้ให้ผู้อ่านรู้)
         */
        $price = ((float)$unit_cost > 0) ? (float)$unit_cost : (float)$last_price;

        $rows[] = array(
            'item_id'      => (int)$item_id,
            'line_no'      => (int)$line_no,
            'product_id'   => (int)$product_id,
            'product_code' => $product_code,
            'product_name' => $product_name,
            'unit_name'    => ($unit_name === null) ? '' : $unit_name,
            'qty'          => (float)$qty,
            'qty_issued'   => (float)$qty_issued,
            'qty_pending'  => (float)$qty - (float)$qty_issued,
            'note'         => $note,
            'unit_cost'    => (float)$unit_cost,
            'total_cost'   => (float)$total_cost,
            'balance'      => (float)$balance,
            'last_price'   => (float)$last_price,
            'price'        => $price,
            'amount'       => $price * (float)$qty
        );
    }
    mysqli_stmt_close($stmt);

    return $rows;
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก — ใช้ร่วมกันทุกประเภท
 *  คืนข้อความผิดพลาด หรือ '' ถ้าผ่าน
 * ============================================================ */
function doc_validate($data)
{
    $doc_date = isset($data['doc_date']) ? trim($data['doc_date']) : '';
    if ($doc_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $doc_date)) {
        return 'กรุณาเลือกวันที่ของเอกสาร';
    }

    /* กันวันที่ในอนาคต เพราะของยังไม่ได้เข้าหรือออกจริง
       ถ้ายอมให้ลงวันที่ล่วงหน้า รายงานย้อนหลังจะมีรายการที่ยังไม่เกิดขึ้นปนอยู่ */
    if (strtotime($doc_date) > strtotime(date('Y-m-d'))) {
        return 'ลงวันที่ล่วงหน้าไม่ได้ — เอกสารต้องเป็นวันที่ของจริงที่ทำรายการ';
    }

    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    if (count($items) === 0) {
        return 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ';
    }

    $seen = array();
    for ($i = 0; $i < count($items); $i++) {
        $line       = $i + 1;
        $product_id = isset($items[$i]['product_id']) ? (int)$items[$i]['product_id'] : 0;
        $qty        = isset($items[$i]['qty']) ? (float)$items[$i]['qty'] : 0;

        if ($product_id <= 0) {
            return 'บรรทัดที่ ' . $line . ': ยังไม่ได้เลือกสินค้า';
        }
        if ($qty <= 0) {
            return 'บรรทัดที่ ' . $line . ': จำนวนต้องมากกว่า 0';
        }

        /* สินค้าซ้ำในใบเดียวกันทำให้ยอดรวมอ่านผิด และตอนจ่ายจะตัดสองรอบ */
        if (isset($seen[$product_id])) {
            return 'บรรทัดที่ ' . $line . ': สินค้าซ้ำกับบรรทัดที่ ' . $seen[$product_id]
                 . ' — รวมจำนวนไว้บรรทัดเดียวแทน';
        }
        $seen[$product_id] = $line;
    }

    return '';
}


/* ============================================================
 *  บันทึกหัวเอกสาร + รายการสินค้า
 *
 *  ใช้ภายในไฟล์ receive.php / request.php / issue.php เท่านั้น
 *  ต้องเรียกอยู่ใน transaction ที่ผู้เรียกเปิดไว้แล้ว
 *  คืน array('ok', 'message', 'id' => doc_id, 'doc_no' => ...)
 * ============================================================ */
function doc_insert($doc_type, $data, $status, $actor)
{
    $connect = db_connect();

    $doc_date = trim($data['doc_date']);
    $doc_no   = doc_next_no($doc_type, $doc_date);
    if ($doc_no === '') {
        return db_fail('ออกเลขที่เอกสารไม่สำเร็จ');
    }

    $department_id = isset($data['department_id']) && (int)$data['department_id'] > 0
                     ? (int)$data['department_id'] : null;
    $requester     = isset($data['requester']) && $data['requester'] !== ''
                     ? $data['requester'] : null;
    $ref_doc_id    = isset($data['ref_doc_id']) && (int)$data['ref_doc_id'] > 0
                     ? (int)$data['ref_doc_id'] : null;
    $note          = isset($data['note']) ? trim($data['note']) : '';
    $receiver_name = isset($data['receiver_name']) ? trim($data['receiver_name']) : '';

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_doc_header`
         SET `doc_type` = ?, `doc_no` = ?, `doc_date` = ?, `status` = ?,
             `department_id` = ?, `requester` = ?, `ref_doc_id` = ?,
             `note` = ?, `receiver_name` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { return db_fail('เตรียมคำสั่งบันทึกเอกสารไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'ssssisisss',
        $doc_type, $doc_no, $doc_date, $status,
        $department_id, $requester, $ref_doc_id, $note, $receiver_name, $actor);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        if ($errno === 1062) {
            return db_fail('เลขที่เอกสารซ้ำ กรุณากดบันทึกอีกครั้ง', $errno);
        }
        return db_fail(db_error_message($errno), $errno);
    }

    $doc_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);

    /* ---------- รายการสินค้า ---------- */
    /* ราคาต่อหน่วยเก็บตั้งแต่ตอนสร้างบรรทัด
       ใบรับเข้า = ราคาที่ซื้อจริงซึ่งผู้ใช้กรอกมา
       ใบจ่าย    = ยังไม่รู้ตอนนี้ (ต้องรอราคาที่ใช้ตัดสต๊อกจริงจาก stock_apply)
                   จึงใส่ 0 ไปก่อนแล้วให้ issue_save เขียนทับทีหลัง */
    $items = $data['items'];
    $stmt  = mysqli_prepare($connect,
        "INSERT INTO `tb_doc_item`
         SET `doc_id` = ?, `line_no` = ?, `product_id` = ?, `qty` = ?,
             `unit_cost` = ?, `total_cost` = ?, `note` = ?,
             `created_at` = NOW(), `created_by` = ?");
    if (!$stmt) { return db_fail('เตรียมคำสั่งบันทึกรายการไม่สำเร็จ'); }

    for ($i = 0; $i < count($items); $i++) {
        $line_no    = $i + 1;
        $product_id = (int)$items[$i]['product_id'];
        $qty        = (float)$items[$i]['qty'];
        $item_note  = isset($items[$i]['note']) ? trim($items[$i]['note']) : '';
        $unit_cost  = isset($items[$i]['unit_cost']) ? (float)$items[$i]['unit_cost'] : 0;
        $line_total = $qty * $unit_cost;

        mysqli_stmt_bind_param($stmt, 'iiidddss',
            $doc_id, $line_no, $product_id, $qty, $unit_cost, $line_total, $item_note, $actor);

        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            return db_fail('บรรทัดที่ ' . $line_no . ': ' . db_error_message($errno), $errno);
        }
    }
    mysqli_stmt_close($stmt);

    $res = db_ok('บันทึกเรียบร้อย', $doc_id);
    $res['doc_no'] = $doc_no;
    return $res;
}


/* ---------- เปลี่ยนสถานะเอกสาร ---------- */
function doc_set_status($doc_id, $status, $actor)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header` SET `status` = ?, `updated_by` = ? WHERE `doc_id` = ?");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'ssi', $status, $actor, $doc_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


/* ============================================================
 *  ประวัติเอกสาร (timeline) — tb_doc_history
 *
 *  บันทึกทุกครั้งที่เอกสารเปลี่ยนสถานะหรือรายการ เรียกภายใน transaction
 *  ของผู้เรียก (คู่กับการเปลี่ยนแปลงจริง)
 *
 *  ห้ามทำให้งานหลักพัง: ถ้าเขียนประวัติไม่ได้ (เช่นยังไม่รัน sql/061)
 *  ให้คืน false เฉย ๆ ผู้เรียกไม่ต้องตรวจผล และไม่ต้อง rollback ใบเพราะเหตุนี้
 * ============================================================ */
function doc_history_add($doc_id, $event, $actor, $note = '')
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;
    if ($doc_id <= 0 || trim((string)$event) === '') { return false; }

    $actor = trim((string)$actor);
    $by    = ($actor === '') ? null : $actor;
    $note  = (string)$note;
    if (db_text_length($note) > 500) {
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 500, 'UTF-8') : substr($note, 0, 500);
    }

    $stmt = @mysqli_prepare($connect,
        "INSERT INTO `tb_doc_history` (`doc_id`, `event`, `actor`, `note`, `created_at`)
         VALUES (?, ?, ?, ?, NOW())");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'isss', $doc_id, $event, $by, $note);
    $ok = @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok ? true : false;
}


/* ---------- อ่าน timeline ของเอกสารใบหนึ่ง ----------
 * เรียงตามลำดับที่เกิด (hist_id) พร้อมชื่อเต็มของผู้ทำ
 * @ กันกรณีตารางยังไม่ถูกสร้าง — คืน array ว่าง
 */
function doc_history($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;

    $res = @mysqli_query($connect,
        "SELECT h.`event`, h.`actor`, u.`full_name`, h.`note`, h.`created_at`
         FROM `tb_doc_history` h
         LEFT JOIN `tb_user` u ON u.`username` = h.`actor`
         WHERE h.`doc_id` = " . $doc_id . "
         ORDER BY h.`hist_id` ASC");
    if (!$res) { return array(); }

    $rows = array();
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = array(
            'event'      => $r['event'],
            'actor'      => ($r['actor'] === null) ? '' : $r['actor'],
            'actor_name' => ($r['full_name'] !== null && trim($r['full_name']) !== '')
                            ? $r['full_name']
                            : (($r['actor'] === null) ? 'ระบบ' : $r['actor']),
            'note'       => $r['note'],
            'created_at' => $r['created_at']
        );
    }
    mysqli_free_result($res);

    return $rows;
}


/* ---------- ชนิดเหตุการณ์ล่าสุดของเอกสาร (ไว้ตัดสินว่า edit นี้เป็น resubmitted ไหม) ---------- */
function doc_history_last_event($doc_id)
{
    $connect = db_connect();
    $doc_id  = (int)$doc_id;

    $res = @mysqli_query($connect,
        "SELECT `event` FROM `tb_doc_history`
         WHERE `doc_id` = " . $doc_id . "
         ORDER BY `hist_id` DESC LIMIT 1");
    if (!$res) { return ''; }

    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);

    return $row ? $row[0] : '';
}
