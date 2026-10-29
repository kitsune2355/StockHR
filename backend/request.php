<?php
/* ============================================================
 *  backend/request.php — ใบเบิกสินค้า (ขั้นก่อนจ่ายจริง)
 *
 *  เส้นทาง:  ผู้ใช้ทำใบเบิก -> waiting
 *            ผู้อนุมัติกด   -> approved | rejected
 *            คลังจ่ายครบ   -> issued (ตั้งให้จาก backend/issue.php)
 *
 *  ใบเบิก "ไม่แตะสต๊อก" เลย ตัวเลขจะเปลี่ยนตอนทำใบจ่ายเท่านั้น
 *  เหตุผล: ถ้าจองยอดตั้งแต่ตอนขอ ของที่ยังอยู่บนชั้นจะถูกกันไว้ให้ใบที่
 *  อาจไม่ได้รับอนุมัติ คนอื่นที่ต้องใช้ด่วนจะเบิกไม่ได้ทั้งที่ของมีอยู่
 *  แลกกับการที่ตอนจ่ายจริงอาจพบว่าของไม่พอ — ซึ่งหน้าจ่ายจะบอกทันที
 *
 *  ใครทำใบเบิกได้: ทุกคนที่ล็อกอิน (เบิกในนามหน่วยงานของตัวเอง)
 *  ใครอนุมัติได้ : ผู้อนุมัติและผู้ดูแลระบบ — และห้ามอนุมัติใบของตัวเอง
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/user.php';
require_once dirname(__FILE__) . '/notify_events.php';


/* ---------- ผู้ขอเบิกสร้างใบเบิก ----------
 * $data = array('doc_date', 'department_id', 'note', 'items' => [...])
 * $actor = username ผู้ขอ (มาจาก session เท่านั้น ห้ามรับจากฟอร์ม)
 */
function request_save($data, $actor, $actor_role = '')
{
    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    /* ธง "ต้องคืน" เป็นของใบพนักงานใหม่ (backend/new_staff.php ตั้งจากชุดของในฐานข้อมูล)
       ใบเบิกธรรมดาไม่มีความหมายนี้ ล้างทิ้งกันคนยิง JSON มาตั้งเอง */
    for ($i = 0; $i < count($data['items']); $i++) {
        unset($data['items'][$i]['must_return']);
    }

    /* ---------- หน่วยงานที่ขอเบิก ----------
     * คนทั่วไปเบิกได้เฉพาะในนามหน่วยงานที่ตัวเองสังกัด บังคับที่นี่ ไม่เชื่อค่าจากฟอร์ม
     * ไม่งั้นจะยิงใบเบิกในนามหน่วยงานอื่น แล้วไปโผล่ให้ผู้อนุมัติของหน่วยงานนั้นเซ็น
     * ผู้ดูแลระบบยังเลือกได้อิสระ เพราะต้องทำแทนหน่วยงานอื่นเวลามีปัญหา
     */
    $me = user_find($actor);

    if (!in_array($actor_role, array('super_admin', 'admin'))
        && $me !== null && (int)$me['dept_id'] > 0) {
        $data['department_id'] = (int)$me['dept_id'];
    }

    if (!isset($data['department_id']) || (int)$data['department_id'] <= 0) {
        return db_fail('กรุณาเลือกหน่วยงานที่ขอเบิก '
                     . '(ถ้าเลือกไม่ได้ แปลว่าบัญชีของคุณยังไม่ได้ระบุหน่วยงาน '
                     . 'กรุณาแจ้งผู้ดูแลระบบ)');
    }

    /* ---------- สินค้าทุกบรรทัดต้องเป็นของที่หน่วยงานนี้เบิกได้ ----------
     * ตรวจซ้ำที่นี่แม้หน้าจอจะกรองให้แล้ว เพราะการกรองที่หน้าจอเป็นเรื่อง
     * ความสะดวก ไม่ใช่การบังคับ — คนที่ยิง JSON ตรงเข้ามาข้ามหน้าจอไปเลย
     * ต้องโดนกันที่ชั้นนี้ ไม่งั้นการจำกัดสิทธิ์ไม่มีความหมายอะไรเลย
     *
     * บอกชื่อสินค้าที่มีปัญหาออกไปด้วย ไม่ใช่แค่ "ไม่มีสิทธิ์"
     * เพราะกรณีที่เกิดจริงบ่อยที่สุดคือผู้ดูแลระบบเปลี่ยนหน่วยงานในฟอร์ม
     * หลังจากเลือกสินค้าไปแล้ว ซึ่งเป็นความสับสน ไม่ใช่การโจมตี
     */
    require_once dirname(__FILE__) . '/product_department.php';
    require_once dirname(__FILE__) . '/product.php';   /* ใช้ product_find เพื่อบอกชื่อสินค้าที่ผิด */

    $dept  = (int)$data['department_id'];
    $items = $data['items'];

    for ($i = 0; $i < count($items); $i++) {
        $pid = (int)$items[$i]['product_id'];

        if (!product_dept_allows($pid, $dept)) {
            $p = product_find($pid);
            $name = ($p === null) ? ('รหัส ' . $pid)
                                  : ($p['product_code'] . ' ' . $p['product_name']);

            return db_fail($name . ': หน่วยงานที่เลือกไม่มีสิทธิ์เบิกสินค้ารายการนี้ '
                         . '— เอาออกจากรายการ หรือแจ้งผู้ดูแลระบบให้เพิ่มสิทธิ์ให้');
        }
    }

    /* ผู้ขอเบิกคือคนที่ล็อกอินอยู่เสมอ ไม่ให้ส่งชื่อคนอื่นมา
       ไม่งั้นจะเปิดใบเบิกในนามคนอื่นได้ แล้วประวัติจะโยนความรับผิดชอบผิดคน */
    $data['requester'] = $actor;

    db_begin();

    $res = doc_insert('request', $data, 'waiting', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    doc_history_add($res['id'], 'submitted', $actor, trim($data['note']));

    db_commit();

    /* ---------- แจ้งผู้อนุมัติของหน่วยงาน ----------
     * เรียกหลัง commit เสมอ ไม่ใช่ก่อน เพราะถ้าธุรกรรมย้อนกลับทีหลัง
     * จะมีคนได้รับแจ้งเตือนถึงใบเบิกที่ไม่มีอยู่จริงในระบบ
     *
     * ไม่ตรวจผลลัพธ์ — ใบเบิกบันทึกสำเร็จแล้ว การแจ้งเตือนล้มเหลว
     * ต้องไม่ทำให้ผู้ใช้เห็นข้อความว่าบันทึกไม่สำเร็จ */
    notify_event_request_submitted($res['id'], $actor);

    $out = db_ok('ส่งใบเบิก ' . $res['doc_no'] . ' เรียบร้อย — รอผู้อนุมัติตรวจสอบ', $res['id']);
    $out['doc_no'] = $res['doc_no'];
    return $out;
}


/* ---------- ผู้อนุมัติตัดสินใบเบิก ----------
 * $decision = 'approved' | 'rejected'
 */
function request_decide($doc_id, $decision, $reason, $actor, $actor_role)
{
    if ($decision !== 'approved' && $decision !== 'rejected') {
        return db_fail('คำสั่งไม่ถูกต้อง');
    }

    if (!in_array($actor_role, array('super_admin', 'admin', 'approver'))) {
        return db_fail('คุณไม่มีสิทธิ์อนุมัติใบเบิก');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                  { return db_fail('ไม่พบใบเบิกที่ต้องการ'); }
    if ($doc['doc_type'] !== 'request') { return db_fail('เอกสารนี้ไม่ใช่ใบเบิก'); }

    if ($doc['status'] !== 'waiting') {
        return db_fail('ใบเบิกนี้ถูกตัดสินไปแล้ว (สถานะปัจจุบัน: '
                     . $doc['status_label'] . ')');
    }

    /* ห้ามอนุมัติใบของตัวเอง — ไม่งั้นขั้นอนุมัติไม่มีความหมาย
       คนคนเดียวจะขอแล้วอนุมัติเองจบในคนเดียว */
    if ($doc['requester'] === $actor) {
        return db_fail('อนุมัติใบเบิกของตัวเองไม่ได้ — ต้องให้ผู้อนุมัติคนอื่นตรวจ');
    }

    /* ---------- ขอบเขตการอนุมัติผูกกับหน่วยงาน ----------
     * ผู้อนุมัติอนุมัติได้เฉพาะใบเบิกของหน่วยงานที่ตัวเองสังกัด
     * ตรงกับการทำงานจริงที่หัวหน้าแต่ละหน่วยงานดูของหน่วยงานตัวเอง
     * ผู้ดูแลระบบข้ามข้อนี้ได้ เพราะต้องเข้าไปแก้ให้ได้ทุกหน่วยงานเวลามีปัญหา
     */
    if ($actor_role === 'approver') {
        $me = user_find($actor);

        if ($me === null || count($me['dept_ids']) === 0) {
            return db_fail('บัญชีของคุณยังไม่ได้ระบุหน่วยงาน จึงอนุมัติใบเบิกไม่ได้ '
                         . 'กรุณาให้ผู้ดูแลระบบตั้งหน่วยงานให้ก่อน');
        }

        if (!user_has_department($actor, $doc['department_id'])) {
            return db_fail('บัญชีของคุณไม่มีสิทธิ์อนุมัติใบเบิกของหน่วยงานนี้ '
                         . 'ใบนี้เป็นของหน่วยงาน "' . $doc['department_name'] . '"');
        }
    }

    $reason = trim($reason);
    if ($decision === 'rejected' && $reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ไม่อนุมัติ — ผู้ขอเบิกต้องรู้ว่าต้องแก้อะไร');
    }

    $connect = db_connect();
    /* แช่แข็งชื่อผู้อนุมัติไว้ตอนตัดสินจริง — ดูเหตุผลที่ doc_user_name() */
    $actor_name = doc_user_name($actor);

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = ?, `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(),
             `approve_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'waiting'");
    if (!$stmt) { return db_fail('บันทึกผลการอนุมัติไม่สำเร็จ'); }

    $id = (int)$doc['doc_id'];
    mysqli_stmt_bind_param($stmt, 'sssssi', $decision, $actor, $actor_name, $reason, $actor, $id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }

    /* เงื่อนไข status = 'waiting' ใน WHERE กันกรณีผู้อนุมัติสองคนกดพร้อมกัน
       คนที่สองจะได้ 0 แถว ไม่ใช่เขียนทับผลของคนแรก */
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($changed < 1) {
        return db_fail('ใบเบิกนี้เพิ่งถูกผู้อนุมัติคนอื่นตัดสินไปแล้ว กรุณารีเฟรชหน้า');
    }

    doc_history_add($id, $decision, $actor, $reason);

    /* ---------- ปิดลิงก์อนุมัติที่ยังค้างอยู่ทุกดอกของใบนี้ ----------
     * ใบถูกตัดสินแล้ว ลิงก์ของผู้อนุมัติคนอื่นที่ยังไม่ได้กดต้องตายตามด้วย
     * ไม่งั้นเขาเปิดลิงก์แล้วเห็นหน้าปุ่มเต็ม ๆ กดไปแล้วเจอข้อความว่า
     * ใบถูกตัดสินไปแล้ว ซึ่งเสียเที่ยวและอ่านเหมือนระบบพัง
     *
     * ต้องอยู่ตรงนี้ ไม่ใช่แค่ใน approve.php เพราะการตัดสินผ่านหน้าเว็บ
     * ก็ต้องปิดลิงก์เหมือนกัน — ไม่งั้นลิงก์จะค้างอยู่ทุกครั้งที่อนุมัติแบบล็อกอิน
     */
    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);

    /* แจ้งผู้ขอเบิกว่าใบถูกตัดสินแล้ว
       เรื่องนี้สำคัญกว่าฝั่งอนุมัติ เพราะผู้ขอไม่มีทางรู้เองว่าเรื่องคืบหน้าไปถึงไหน
       ถ้าไม่แจ้ง เขาจะเปิดระบบมาเช็คเองวันละหลายรอบ หรือเดินมาถามที่คลัง */
    notify_event_request_decided($id, $decision, $reason, $actor);

    /* ใบจากระบบสวัสดิการ: ผู้ขอคือบัญชีระบบ ไม่มีใครรับแจ้งเตือนข้างบน
       อนุมัติแล้วต้องบอกคลังให้จัดของ (ไม่อนุมัติ = ระบบสวัสดิการดึงสถานะไปเอง สิทธิ์กลับเป็นค้างแจก) */
    if ($decision === 'approved' && !empty($doc['is_welfare'])) {
        notify_event_welfare_request($id, $actor, $actor_name);
    }

    $word = ($decision === 'approved') ? 'อนุมัติ' : 'ไม่อนุมัติ';
    return db_ok($word . 'ใบเบิก ' . $doc['doc_no'] . ' เรียบร้อย', $id);
}


/* ---------- ผู้ขอเบิกยกเลิกใบของตัวเอง ----------
 * ยกเลิกได้เฉพาะใบที่ยังไม่ถูกตัดสิน ถ้าอนุมัติแล้วต้องให้คลังจัดการ
 */
function request_cancel($doc_id, $reason, $actor, $actor_role)
{
    $doc = doc_find($doc_id);
    if ($doc === null)                  { return db_fail('ไม่พบใบเบิกที่ต้องการยกเลิก'); }
    if ($doc['doc_type'] !== 'request') { return db_fail('เอกสารนี้ไม่ใช่ใบเบิก'); }

    $is_owner   = ($doc['requester'] === $actor);
    $is_manager = in_array($actor_role, array('super_admin', 'admin'));

    if (!$is_owner && !$is_manager) {
        return db_fail('ยกเลิกได้เฉพาะใบเบิกของตัวเอง');
    }

    if ($doc['status'] === 'cancelled') {
        return db_fail('ใบเบิกนี้ถูกยกเลิกไปแล้ว');
    }
    if ($doc['status'] === 'issued') {
        return db_fail('ใบเบิกนี้จ่ายของไปแล้ว ยกเลิกไม่ได้ — ให้ยกเลิกที่ใบจ่ายแทน');
    }
    if ($doc['status'] === 'approved' && !$is_manager) {
        return db_fail('ใบเบิกนี้ผ่านการอนุมัติแล้ว ต้องให้ผู้ดูแลระบบเป็นคนยกเลิก');
    }

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก');
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ?");
    if (!$stmt) { return db_fail('ยกเลิกใบเบิกไม่สำเร็จ'); }

    $id = (int)$doc['doc_id'];
    mysqli_stmt_bind_param($stmt, 'sssi', $actor, $reason, $actor, $id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    doc_history_add($id, 'cancelled', $actor, $reason);

    /* ใบถูกยกเลิกแล้ว ลิงก์อนุมัติที่ส่งไปในเมลต้องใช้ไม่ได้ทันที
       ไม่งั้นผู้อนุมัติเปิดลิงก์แล้วยังเห็นปุ่มอนุมัติของใบที่ไม่มีอยู่แล้ว */
    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);

    /* ผู้ขอยกเลิกเอง -> เตือนผู้อนุมัติจะได้ไม่เสียเวลาเปิดดู
       ผู้ดูแลยกเลิกให้ -> เตือนผู้ขอเพราะเป็นคนที่ต้องรู้เหตุผล
       ตรรกะการเลือกปลายทางอยู่ใน notify_event_doc_cancelled ที่เดียว */
    notify_event_doc_cancelled($id, $reason, $actor);

    return db_ok('ยกเลิกใบเบิก ' . $doc['doc_no'] . ' เรียบร้อย', $id);
}


/* ---------- จำนวนใบเบิกที่รออนุมัติ — ใช้ทำ badge บนเมนู ----------
 * นับเฉพาะใบที่คนนี้กดอนุมัติได้จริง ไม่ใช่ทุกใบที่รออยู่ในระบบ
 * ถ้านับทั้งหมด ผู้อนุมัติของหน่วยงาน ก. จะเห็นเลข 5 แล้วเปิดเข้าไปพบว่า
 * กดอะไรไม่ได้สักใบ เพราะทั้งหมดเป็นของหน่วยงาน ข.
 */
function request_waiting_count($actor = '', $actor_role = '', $kind = '')
{
    $connect = db_connect();

    /* $kind แยกตัวเลขตามหน้าที่ใบไปแสดง (badge บนเมนูต้องตรงกับสิ่งที่เปิดเข้าไปเห็น)
         'general'   ใบเบิกปกติ      -> เมนู "รายการใบเบิก"
         'new_staff' ใบพนักงานใหม่   -> เมนู "ใบเบิกพนักงานใหม่"
         ''          ทุกใบ (ผู้เรียกแบบเดิม เช่น หน้าภาพรวม) */
    $kind_sql = '';
    if ($kind === 'general') {
        $kind_sql = " AND NOT EXISTS (SELECT 1 FROM `tb_doc_new_staff` ns WHERE ns.`doc_id` = `tb_doc_header`.`doc_id`)";
    } elseif ($kind === 'new_staff') {
        $kind_sql = " AND EXISTS (SELECT 1 FROM `tb_doc_new_staff` ns WHERE ns.`doc_id` = `tb_doc_header`.`doc_id`)";
    }

    /* คนที่อนุมัติไม่ได้ ต้องได้ 0 เสมอ ไม่ใช่จำนวนใบที่รออยู่ทั้งระบบ
       ตอนนี้ main.php เรียกเฉพาะสิทธิ์ที่อนุมัติได้อยู่แล้ว แต่ถ้าวันหลัง
       มีที่อื่นเรียกโดยไม่กรอง ผู้ใช้ทั่วไปจะเห็นตัวเลขงานที่ตัวเองแตะไม่ได้ */
    if ($actor_role !== '' &&
        !in_array($actor_role, array('super_admin', 'admin', 'approver'))) {
        return 0;
    }

    $depts = array();
    if ($actor_role === 'approver' && $actor !== '') {
        $me = user_find($actor);
        if ($me === null || count($me['dept_ids']) === 0) {
            return 0;                       // ไม่มีหน่วยงาน = อนุมัติอะไรไม่ได้
        }
        $depts = $me['dept_ids'];
    }

    if (count($depts) > 0) {
        $marks = array();
        $types = '';
        $values = array();
        for ($i = 0; $i < count($depts); $i++) {
            $marks[] = '?';
            $types .= 'i';
            $values[] = (int)$depts[$i];
        }
        $types .= 's';
        $values[] = $actor;
        $stmt = mysqli_prepare($connect,
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'request' AND `status` = 'waiting'
               AND `department_id` IN (" . implode(',', $marks) . ")
               AND (`requester` IS NULL OR `requester` <> ?)" . $kind_sql);
        if (!$stmt) { return 0; }
        user_bind($stmt, $types, $values);
    } else {
        $stmt = mysqli_prepare($connect,
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'request' AND `status` = 'waiting'" . $kind_sql);
        if (!$stmt) { return 0; }
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}


/* ---------- ใบเบิกที่อนุมัติแล้วและยังจ่ายไม่ครบ ----------
 * หน้าทำใบจ่ายเอาไปให้เลือก
 */
function request_ready_list()
{
    $rows = doc_list(array('doc_type' => 'request', 'status' => 'approved', 'limit' => 200));

    $out = array();
    for ($i = 0; $i < count($rows); $i++) {
        $items   = doc_items($rows[$i]['doc_id']);
        $pending = 0;
        for ($j = 0; $j < count($items); $j++) {
            $pending += $items[$j]['qty_pending'];
        }
        if ($pending <= 0) { continue; }        // จ่ายครบแล้ว ไม่ต้องเอามาให้เลือกอีก

        $rows[$i]['items']       = $items;
        $rows[$i]['pending_qty'] = $pending;
        $out[] = $rows[$i];
    }
    return $out;
}


/* ============================================================
 *  ผู้ขอเบิกแก้ไขใบเบิกที่ยัง "รออนุมัติ"
 *
 *  แก้ได้เฉพาะเจ้าของใบ หรือผู้ดูแลระบบ และเฉพาะใบสถานะ waiting
 *  (ใบ waiting การันตีว่ายังไม่มีการจ่าย qty_issued = 0 ทุกบรรทัด)
 *
 *  แก้แล้ว: ลิงก์อนุมัติในอีเมลเดิมถือ snapshot เก่า ต้องเผาทิ้งแล้วส่งใหม่
 *
 *  $data = array('doc_date', 'department_id', 'note', 'items' => [...])
 * ============================================================ */
function request_update($doc_id, $data, $actor, $actor_role = '')
{
    $doc = doc_find($doc_id);
    if ($doc === null)                  { return db_fail('ไม่พบใบเบิกที่ต้องการแก้ไข'); }
    if ($doc['doc_type'] !== 'request') { return db_fail('เอกสารนี้ไม่ใช่ใบเบิก'); }

    /* ใบพนักงานใหม่มีข้อมูลพนักงานและธง "ต้องคืน" ที่ฟอร์มแก้ไขใบเบิกธรรมดาไม่รู้จัก
       ถ้าปล่อยให้แก้ผ่านทางนี้ ธงจะหายและสินค้าในชุดพื้นฐานจะติดสิทธิ์หน่วยงาน
       ให้ยกเลิกแล้วทำใบใหม่แทน (หน้ารายการใบเบิกซ่อนปุ่มแก้ไขของใบประเภทนี้อยู่แล้ว) */
    if (!empty($doc['is_new_staff'])) {
        return db_fail('ใบเบิกพนักงานใหม่แก้ไขไม่ได้ — ถ้าต้องเปลี่ยน ให้ยกเลิกใบนี้แล้วทำใบใหม่');
    }
    /* ใบจากระบบสวัสดิการ: ผู้ขอคือบัญชีระบบ ไม่มีคนมาแก้ใบในสต็อก (ดู backend/welfare_bridge.php) */
    if (!empty($doc['is_welfare'])) {
        return db_fail('ใบเบิกจากระบบสวัสดิการแก้ไขในระบบสต็อกไม่ได้ — ถ้าต้องเปลี่ยน ให้ยกเลิกแล้วส่งเบิกใหม่จากระบบสวัสดิการ');
    }

    $is_owner   = ($doc['requester'] === $actor);
    $is_manager = in_array($actor_role, array('super_admin', 'admin'));
    if (!$is_owner && !$is_manager) {
        return db_fail('แก้ไขได้เฉพาะใบเบิกของตัวเอง');
    }
    if ($doc['status'] !== 'waiting') {
        return db_fail('ใบเบิกนี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว จึงแก้ไขไม่ได้ '
                     . '(สถานะปัจจุบัน: ' . $doc['status_label'] . ')');
    }

    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    /* หน่วยงาน: คนทั่วไปแก้ใบของตัวเอง หน่วยงานคงเดิม ห้ามย้าย
       ผู้ดูแลระบบเปลี่ยนได้ (ทำแทนหน่วยงานอื่นเวลามีปัญหา) */
    $dept = $is_manager
            ? (isset($data['department_id']) && (int)$data['department_id'] > 0
               ? (int)$data['department_id'] : (int)$doc['department_id'])
            : (int)$doc['department_id'];
    if ($dept <= 0) {
        return db_fail('ใบเบิกนี้ยังไม่มีหน่วยงาน กรุณาแจ้งผู้ดูแลระบบ');
    }

    /* สินค้าทุกบรรทัดต้องเป็นของที่หน่วยงานนี้เบิกได้ (เหมือน request_save) */
    require_once dirname(__FILE__) . '/product_department.php';
    require_once dirname(__FILE__) . '/product.php';

    $items = $data['items'];
    for ($i = 0; $i < count($items); $i++) {
        $pid = (int)$items[$i]['product_id'];
        if (!product_dept_allows($pid, $dept)) {
            $p = product_find($pid);
            $name = ($p === null) ? ('รหัส ' . $pid)
                                  : ($p['product_code'] . ' ' . $p['product_name']);
            return db_fail($name . ': หน่วยงานที่เลือกไม่มีสิทธิ์เบิกสินค้ารายการนี้ '
                         . '— เอาออกจากรายการ หรือแจ้งผู้ดูแลระบบให้เพิ่มสิทธิ์ให้');
        }
    }

    $connect  = db_connect();
    $id       = (int)$doc['doc_id'];
    $doc_date = trim($data['doc_date']);
    $note     = isset($data['note']) ? trim($data['note']) : '';

    db_begin();

    /* WHERE ... AND status='waiting' กันกรณีผู้อนุมัติเพิ่งกดอนุมัติพอดี */
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `doc_date` = ?, `department_id` = ?, `note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'waiting'");
    if (!$stmt) { db_rollback(); return db_fail('แก้ไขใบเบิกไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sissi', $doc_date, $dept, $note, $actor, $id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    /* แก้ใบที่ยังรออนุมัติ เปลี่ยนหน่วยงานข้ามบริษัทได้ — ถือว่าออกใบใหม่ รหัสแบบฟอร์มตามปัจจุบัน */
    doc_snapshot_form_code($id);

    /* affected_rows < 1 อาจแปลว่าค่าไม่เปลี่ยน (แก้แต่รายการ) หรือสถานะเปลี่ยนไปแล้ว
       เช็คสถานะซ้ำให้ชัด */
    $recheck = doc_find($id);
    if ($recheck === null || $recheck['status'] !== 'waiting') {
        db_rollback();
        return db_fail('ใบเบิกนี้เพิ่งถูกตัดสินไปแล้ว กรุณารีเฟรชหน้า');
    }

    /* เขียนรายการใหม่ทั้งชุด (ใบ waiting ยังไม่มี qty_issued จึงลบทิ้งได้ปลอดภัย) */
    $stmt = mysqli_prepare($connect, "DELETE FROM `tb_doc_item` WHERE `doc_id` = ?");
    if (!$stmt) { db_rollback(); return db_fail('แก้ไขรายการไม่สำเร็จ'); }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($connect,
        "INSERT INTO `tb_doc_item`
         SET `doc_id` = ?, `line_no` = ?, `product_id` = ?, `qty` = ?,
             `unit_cost` = 0, `total_cost` = 0, `note` = ?,
             `created_at` = NOW(), `created_by` = ?, `updated_by` = ?");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกรายการไม่สำเร็จ'); }

    for ($i = 0; $i < count($items); $i++) {
        $line_no   = $i + 1;
        $pid       = (int)$items[$i]['product_id'];
        $qty       = (float)$items[$i]['qty'];
        $item_note = isset($items[$i]['note']) ? trim($items[$i]['note']) : '';

        mysqli_stmt_bind_param($stmt, 'iiidsss', $id, $line_no, $pid, $qty, $item_note, $actor, $actor);
        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            db_rollback();
            return db_fail('บรรทัดที่ ' . $line_no . ': ' . db_error_message($errno), $errno);
        }
    }
    mysqli_stmt_close($stmt);

    /* แก้หลังโดน "ส่งกลับ" = ยื่นใหม่ ไม่ใช่แค่แก้ */
    $event = (doc_history_last_event($id) === 'returned') ? 'resubmitted' : 'edited';
    doc_history_add($id, $event, $actor, 'แก้ไข ' . count($items) . ' รายการ');

    db_commit();

    /* เผากุญแจอนุมัติเดิม (ถือ snapshot เก่า) แล้วแจ้งผู้อนุมัติให้พิจารณาใหม่ */
    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);
    notify_event_request_edited($id, $actor);

    $out = db_ok('บันทึกการแก้ไขใบเบิก ' . $doc['doc_no'] . ' เรียบร้อย — ส่งให้ผู้อนุมัติพิจารณาใหม่แล้ว', $id);
    $out['doc_no'] = $doc['doc_no'];
    return $out;
}


/* ============================================================
 *  ผู้อนุมัติ "ส่งกลับแก้ไข" ใบที่อนุมัติไปแล้ว
 *
 *  approved -> waiting  พร้อมล้างข้อมูลการอนุมัติเดิม (ย้ายเข้าประวัติแทน)
 *  ใช้ตอนผู้ทำเบิกแจ้งว่าเบิกผิดหลังใบผ่านอนุมัติแล้ว แต่คลังยังไม่ได้จ่ายของ
 *
 *  ถ้าคลังจ่ายไปบางส่วนแล้ว (qty_issued > 0) จะส่งกลับไม่ได้ ต้องยกเลิกใบจ่ายก่อน
 * ============================================================ */
function request_return($doc_id, $reason, $actor, $actor_role)
{
    if (!in_array($actor_role, array('super_admin', 'admin', 'approver'))) {
        return db_fail('คุณไม่มีสิทธิ์ส่งกลับใบเบิก');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                  { return db_fail('ไม่พบใบเบิกที่ต้องการส่งกลับ'); }
    if ($doc['doc_type'] !== 'request') { return db_fail('เอกสารนี้ไม่ใช่ใบเบิก'); }
    /* ส่งกลับ = ให้ผู้ทำเบิกแก้ แต่ใบพนักงานใหม่แก้ไม่ได้ (ดู request_update) จะค้างอยู่เฉย ๆ */
    if (!empty($doc['is_new_staff'])) {
        return db_fail('ใบเบิกพนักงานใหม่ส่งกลับแก้ไขไม่ได้ — ถ้าเบิกผิด ให้ยกเลิกใบนี้แล้วทำใบใหม่');
    }
    if (!empty($doc['is_welfare'])) {
        return db_fail('ใบเบิกจากระบบสวัสดิการส่งกลับแก้ไขไม่ได้ — ถ้าเบิกผิด ให้ยกเลิกแล้วส่งเบิกใหม่จากระบบสวัสดิการ');
    }
    if ($doc['status'] !== 'approved') {
        return db_fail('ส่งกลับได้เฉพาะใบที่ "อนุมัติแล้ว" เท่านั้น '
                     . '(สถานะปัจจุบัน: ' . $doc['status_label'] . ')');
    }

    if ($doc['requester'] === $actor) {
        return db_fail('ส่งกลับใบเบิกของตัวเองไม่ได้');
    }

    /* ผู้อนุมัติทั่วไปส่งกลับได้เฉพาะหน่วยงานของตัวเอง (เหมือน request_decide) */
    if ($actor_role === 'approver') {
        $me = user_find($actor);
        if ($me === null || count($me['dept_ids']) === 0
            || !user_has_department($actor, $doc['department_id'])) {
            return db_fail('บัญชีของคุณไม่มีสิทธิ์กับใบเบิกของหน่วยงานนี้');
        }
    }

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผล/สิ่งที่ต้องแก้ — ผู้ทำเบิกต้องรู้ว่าจะแก้อะไร');
    }

    $id = (int)$doc['doc_id'];

    /* ---------- กันใบที่คลังจ่ายไปแล้วบางส่วน ----------
       จ่ายได้เฉพาะใบ approved ถ้าจ่ายไปบ้างแล้ว การถอยกลับ waiting จะทำให้
       ของที่จ่ายออกไปแล้วค้างอยู่โดยไม่มีใบรองรับ ต้องยกเลิกใบจ่ายก่อน */
    $connect = db_connect();
    $chk = @mysqli_query($connect,
        "SELECT COALESCE(SUM(`qty_issued`), 0) FROM `tb_doc_item` WHERE `doc_id` = " . $id);
    if ($chk) {
        $r = mysqli_fetch_row($chk);
        mysqli_free_result($chk);
        if ((float)$r[0] > 0) {
            return db_fail('คลังจ่ายของใบนี้ไปบางส่วนแล้ว — ต้องยกเลิกใบจ่ายที่อ้างใบนี้ก่อน '
                         . 'จึงจะส่งกลับแก้ไขได้');
        }
    }

    db_begin();

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'waiting', `approved_by` = NULL, `approved_by_name` = '', `approved_at` = NULL,
             `approve_note` = '', `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'approved'");
    if (!$stmt) { db_rollback(); return db_fail('ส่งกลับใบเบิกไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'si', $actor, $id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($changed < 1) {
        db_rollback();
        return db_fail('สถานะใบเบิกเปลี่ยนไประหว่างทาง (อาจถูกจ่ายของหรือยกเลิก) กรุณารีเฟรชหน้า');
    }

    doc_history_add($id, 'returned', $actor, $reason);

    db_commit();

    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);
    notify_event_request_returned($id, $reason, $actor);

    return db_ok('ส่งใบเบิก ' . $doc['doc_no'] . ' กลับให้ผู้ทำเบิกแก้ไขแล้ว', $id);
}
