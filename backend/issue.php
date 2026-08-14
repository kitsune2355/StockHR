<?php
/* ============================================================
 *  backend/issue.php — จ่ายสินค้าออกจากคลัง
 *
 *  ใบจ่ายต้องอ้างใบเบิกที่ "อนุมัติแล้ว" เสมอ ตามเส้นทางที่ตกลงไว้
 *      ทำใบเบิก -> อนุมัติ -> ทำจ่าย -> เซ็นรับของ
 *  จ่ายลอย ๆ โดยไม่มีใบเบิกไม่ได้ ไม่งั้นขั้นอนุมัติจะข้ามได้
 *
 *  บันทึกแล้วตัดสต๊อกทันที ไม่มีอนุมัติซ้ำอีกชั้น (ผ่านมาแล้วตอนใบเบิก)
 *  ขั้นสุดท้ายคือผู้เบิกกด "เซ็นรับของ" ซึ่งไม่แตะสต๊อก
 *  เป็นแค่หลักฐานว่าของถึงมือผู้เบิกแล้วจริง
 *
 *  จ่ายทีละส่วนได้ (partial) — เช่นใบเบิกขอ 10 มีของ 6 ก็จ่าย 6 ก่อน
 *  ที่เหลือค้างไว้ในใบเบิกใบเดิม แล้วค่อยทำใบจ่ายใบที่สองทีหลัง
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/notify_events.php';


/* ---------- บันทึกใบจ่าย ----------
 * $data = array(
 *   'doc_date', 'ref_doc_id' (ใบเบิก), 'receiver_name', 'note',
 *   'items' => array(array('product_id', 'qty', 'note'))
 * )
 */
function issue_save($data, $actor)
{
    $ref_doc_id = isset($data['ref_doc_id']) ? (int)$data['ref_doc_id'] : 0;
    if ($ref_doc_id <= 0) {
        return db_fail('กรุณาเลือกใบเบิกที่จะจ่ายของ');
    }

    $request = doc_find($ref_doc_id);
    if ($request === null)                  { return db_fail('ไม่พบใบเบิกที่อ้างถึง'); }
    if ($request['doc_type'] !== 'request') { return db_fail('เอกสารที่อ้างถึงไม่ใช่ใบเบิก'); }

    if ($request['status'] !== 'approved') {
        return db_fail('ใบเบิก ' . $request['doc_no'] . ' ยังจ่ายไม่ได้ '
                     . '(สถานะปัจจุบัน: ' . $request['status_label'] . ') '
                     . 'ต้องผ่านการอนุมัติก่อน');
    }

    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    /* ---------- จ่ายเกินที่ขอไม่ได้ ----------
       ทำเป็นตารางค้นเร็วก่อน จะได้ตรวจทีละบรรทัดโดยไม่ต้องวนซ้อน */
    $pending = array();
    $names   = array();
    for ($i = 0; $i < count($request['items']); $i++) {
        $it = $request['items'][$i];
        $pending[$it['product_id']] = $it['qty_pending'];
        $names[$it['product_id']]   = $it['product_code'] . ' ' . $it['product_name'];
    }

    $items = $data['items'];
    for ($i = 0; $i < count($items); $i++) {
        $pid = (int)$items[$i]['product_id'];
        $qty = (float)$items[$i]['qty'];

        if (!isset($pending[$pid])) {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': สินค้านี้ไม่ได้อยู่ในใบเบิก '
                         . $request['doc_no'] . ' — จ่ายของนอกใบเบิกไม่ได้');
        }
        if ($qty > $pending[$pid] + 0.00001) {
            return db_fail($names[$pid] . ': จ่ายได้ไม่เกิน ' . stock_format($pending[$pid])
                         . ' (ที่ขอไว้และยังไม่ได้จ่าย) แต่กรอกมา ' . stock_format($qty));
        }
    }

    /* ---------- เขียนจริง ----------
       ทั้งใบต้องสำเร็จหรือล้มพร้อมกัน ถ้าบรรทัดที่ 3 ของไม่พอ
       บรรทัดที่ 1-2 ที่ตัดไปแล้วต้องถูกย้อนคืนด้วย */
    db_begin();

    $data['department_id'] = $request['department_id'];
    $data['requester']     = $request['requester'];

    $res = doc_insert('issue', $data, 'issued', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    $doc_id    = $res['id'];
    $doc_no    = $res['doc_no'];
    $move_date = $data['doc_date'] . ' ' . date('H:i:s');
    $note_head = 'จ่ายตามใบเบิก ' . $request['doc_no'];

    for ($i = 0; $i < count($items); $i++) {
        $pid = (int)$items[$i]['product_id'];
        $qty = (float)$items[$i]['qty'];

        $applied = stock_apply(
            $pid, -$qty, 'issue',
            array('doc_id' => $doc_id, 'doc_no' => $doc_no, 'move_date' => $move_date),
            $actor, $note_head
        );

        if (!$applied['ok']) {
            db_rollback();
            return db_fail($names[$pid] . ': ' . $applied['message']);
        }

        /* แช่แข็งต้นทุนเฉลี่ย ณ วินาทีที่จ่าย ลงบรรทัดของใบจ่าย
           รายงานย้อนหลังจะได้ตัวเลขเดิมเสมอ ไม่เปลี่ยนตามราคาที่ซื้อทีหลัง */
        if (!issue_set_line_cost($doc_id, $pid, $applied['unit_cost'], $actor)) {
            db_rollback();
            return db_fail($names[$pid] . ': บันทึกต้นทุนของบรรทัดไม่สำเร็จ');
        }

        /* บวกยอดที่จ่ายแล้วกลับเข้าใบเบิก เพื่อให้รู้ว่ายังค้างเท่าไร
           บวกเพิ่มจากค่าเดิม ไม่ใช่เขียนทับ เพราะอาจเคยจ่ายบางส่วนไปแล้ว */
        if (!issue_mark_request_line($ref_doc_id, $pid, $qty, $actor)) {
            db_rollback();
            return db_fail($names[$pid] . ': ปรับยอดค้างจ่ายในใบเบิกไม่สำเร็จ');
        }
    }

    /* จ่ายครบทุกบรรทัดแล้ว ปิดใบเบิก */
    if (issue_request_is_complete($ref_doc_id)) {
        doc_set_status($ref_doc_id, 'issued', $actor);
    }

    db_commit();

    /* เรียกผู้ขอเบิกมารับของและเซ็นรับ — เป็นการส่งไม้ต่อขั้นสุดท้าย
       ต้องอยู่หลัง commit เพราะเนื้อเมลอ่านรายการสินค้าจากฐานข้อมูลอีกรอบ */
    notify_event_issue_created($doc_id, $actor);

    $out = db_ok('บันทึกใบจ่าย ' . $doc_no . ' เรียบร้อย — ตัดสต๊อกแล้ว', $doc_id);
    $out['doc_no'] = $doc_no;
    return $out;
}


/* ---------- เขียนต้นทุนลงบรรทัดของใบจ่าย ---------- */
function issue_set_line_cost($doc_id, $product_id, $unit_cost, $actor)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_item`
         SET `unit_cost` = ?, `total_cost` = `qty` * ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `product_id` = ?");
    if (!$stmt) { return false; }

    $doc_id     = (int)$doc_id;
    $product_id = (int)$product_id;
    $unit_cost  = (float)$unit_cost;

    mysqli_stmt_bind_param($stmt, 'ddsii', $unit_cost, $unit_cost, $actor, $doc_id, $product_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


/* ---------- บวกจำนวนที่จ่ายแล้วเข้าบรรทัดของใบเบิก ---------- */
function issue_mark_request_line($request_id, $product_id, $qty, $actor)
{
    $connect = db_connect();

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_item`
         SET `qty_issued` = `qty_issued` + ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `product_id` = ?");
    if (!$stmt) { return false; }

    $request_id = (int)$request_id;
    $product_id = (int)$product_id;
    $qty        = (float)$qty;

    mysqli_stmt_bind_param($stmt, 'dsii', $qty, $actor, $request_id, $product_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


/* ---------- ใบเบิกใบนี้จ่ายครบทุกบรรทัดหรือยัง ---------- */
function issue_request_is_complete($request_id)
{
    $connect    = db_connect();
    $request_id = (int)$request_id;

    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_doc_item`
         WHERE `doc_id` = ? AND `qty_issued` < `qty`");
    if (!$stmt) { return false; }

    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $remaining);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return ((int)$remaining === 0);
}


/* ============================================================
 *  ผู้เบิกเซ็นรับของ — ขั้นสุดท้ายของเส้นทาง
 *  ไม่แตะสต๊อก (ตัดไปแล้วตอนบันทึกใบจ่าย) เป็นหลักฐานว่าของถึงมือแล้ว
 * ============================================================ */
function issue_acknowledge($doc_id, $receiver_name, $actor, $actor_role, $receiver_sign = '')
{
    $doc = doc_find($doc_id);
    if ($doc === null)                { return db_fail('ไม่พบใบจ่ายที่ต้องการ'); }
    if ($doc['doc_type'] !== 'issue') { return db_fail('เอกสารนี้ไม่ใช่ใบจ่าย'); }

    if ($doc['status'] === 'received') {
        return db_fail('ใบจ่ายนี้เซ็นรับของไปแล้วเมื่อ ' . $doc['received_at']);
    }
    if ($doc['status'] !== 'issued') {
        return db_fail('ใบจ่ายนี้เซ็นรับไม่ได้ (สถานะปัจจุบัน: ' . $doc['status_label'] . ')');
    }

    /* คนเซ็นรับต้องเป็นผู้ขอเบิกเอง หรือผู้ดูแลระบบที่เซ็นแทนได้
       ถ้าใครกดก็ได้ ลายเซ็นรับของจะไม่มีน้ำหนักเป็นหลักฐาน */
    $is_owner   = ($doc['requester'] !== '' && $doc['requester'] === $actor);
    $is_manager = in_array($actor_role, array('super_admin', 'admin'));

    /* ---------- เซ็นผ่านลิงก์ในอีเมล ----------
     * sign.php ตรวจกุญแจใน sign_token เสร็จแล้วจึงเรียกเข้ามาด้วยบทบาทนี้
     * ตัวกุญแจคือสิทธิ์ในตัวมันเอง — เดาไม่ได้ หมดอายุได้ และเพิกถอนได้
     * จึงไม่ต้องมีบัญชีผู้ใช้อีกชั้น
     *
     * มีที่เรียกได้ที่เดียวคือ sign.php ซึ่งไม่มีทางไปถึงบรรทัดนี้
     * ถ้ากุญแจไม่ผ่าน ส่วน api/documents.php ส่ง role ของผู้ล็อกอินมาเสมอ
     * จึงปลอมเป็น sign_link จากทางนั้นไม่ได้
     */
    $is_link = ($actor_role === 'sign_link');

    if (!$is_owner && !$is_manager && !$is_link) {
        return db_fail('เซ็นรับของได้เฉพาะผู้ขอเบิกของใบนี้ '
                     . '(ผู้ขอเบิกคือ ' . $doc['requester'] . ')');
    }

    $receiver_name = trim($receiver_name);
    if ($receiver_name === '') {
        return db_fail('กรุณากรอกชื่อผู้รับของ');
    }

    /* ---------- ลายเซ็น ----------
     * รับเป็น data URL ของ PNG ที่หน้าจอวาดมาให้ ต้องตรวจสองอย่างก่อนเก็บ
     *
     * 1. รูปแบบ — ยอมรับเฉพาะ data:image/png;base64,<ตัวอักษร base64>
     *    ถ้าไม่ตรวจ จะถูกยัด "data:text/html,<script>..." เข้ามา แล้วเมื่อไร
     *    ที่ค่านี้ถูกเอาไปใส่ src ของ <img> บนหน้าพิมพ์ ก็กลายเป็น XSS ทันที
     *
     * 2. ขนาด — ผืน 600x200 ที่เซ็นเต็มพื้นที่หนักราว 30 KB
     *    เกิน 2 MB แปลว่าไม่ใช่ลายเซ็นแล้ว ปฏิเสธไปดีกว่าปล่อยให้แถวบวม
     *
     * ลายเซ็นไม่บังคับ — เซ็นผ่านหน้าจอเก่าหรือผ่าน API ตรงยังทำได้เหมือนเดิม
     * แค่จะไม่มีภาพลายเซ็นบนใบที่พิมพ์ออกมา
     */
    $receiver_sign = trim((string)$receiver_sign);

    if ($receiver_sign !== '') {
        if (strlen($receiver_sign) > 2 * 1024 * 1024) {
            return db_fail('ไฟล์ลายเซ็นใหญ่เกินไป กรุณาเซ็นใหม่');
        }
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $receiver_sign)) {
            return db_fail('รูปแบบลายเซ็นไม่ถูกต้อง');
        }
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'received', `received_by` = ?, `received_at` = NOW(),
             `receiver_name` = ?, `receiver_sign` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'issued'");
    if (!$stmt) { return db_fail('บันทึกการรับของไม่สำเร็จ'); }

    $id   = (int)$doc['doc_id'];
    $sign = ($receiver_sign === '') ? null : $receiver_sign;
    mysqli_stmt_bind_param($stmt, 'ssssi', $actor, $receiver_name, $sign, $actor, $id);

    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    /* ปิดวงรอบให้คนคลังรู้ว่าของถึงมือแล้ว ไม่ต้องเดินไปถามเอง
       ปลายทางคือคนที่ทำใบจ่าย ไม่ใช่ผู้อนุมัติ เพราะงานค้างอยู่ที่คลัง */
    notify_event_issue_acknowledged($id, $actor);

    return db_ok('เซ็นรับของใบจ่าย ' . $doc['doc_no'] . ' เรียบร้อย', $id);
}


/* ============================================================
 *  ยกเลิกใบจ่าย — คืนของกลับเข้าสต๊อก และคืนยอดค้างให้ใบเบิก
 *  เซ็นรับของไปแล้วยกเลิกไม่ได้ เพราะของอยู่ที่ผู้เบิกแล้ว
 *  ถ้าจะเอาของคืนต้องทำใบรับเข้าใหม่ ซึ่งเป็นเหตุการณ์คนละเรื่อง
 * ============================================================ */
function issue_cancel($doc_id, $reason, $actor, $actor_role)
{
    if (!in_array($actor_role, array('super_admin', 'admin', 'approver'))) {
        return db_fail('คุณไม่มีสิทธิ์ยกเลิกใบจ่าย');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                { return db_fail('ไม่พบใบจ่ายที่ต้องการยกเลิก'); }
    if ($doc['doc_type'] !== 'issue') { return db_fail('เอกสารนี้ไม่ใช่ใบจ่าย'); }

    if ($doc['status'] === 'cancelled') {
        return db_fail('ใบจ่ายนี้ถูกยกเลิกไปแล้ว');
    }
    if ($doc['status'] === 'received') {
        return db_fail('ใบจ่ายนี้ผู้เบิกเซ็นรับของไปแล้ว ยกเลิกไม่ได้ '
                     . '— ถ้าต้องคืนของ ให้ทำใบรับเข้าใหม่แทน');
    }

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก');
    }

    db_begin();

    $move_date = date('Y-m-d H:i:s');
    for ($i = 0; $i < count($doc['items']); $i++) {
        $item = $doc['items'][$i];

        /* ของกลับเข้าคลังด้วยต้นทุนที่จ่ายออกไป ไม่ใช่ค่าเฉลี่ยปัจจุบัน
           มูลค่ารวมจึงกลับไปเท่าเดิมเป๊ะ เหมือนไม่เคยมีใบจ่ายใบนี้ */
        $applied = stock_apply(
            $item['product_id'], $item['qty'], 'cancel_issue',
            array('doc_id' => $doc['doc_id'], 'doc_no' => $doc['doc_no'], 'move_date' => $move_date),
            $actor, 'ยกเลิกใบจ่าย: ' . $reason,
            $item['unit_cost']
        );

        if (!$applied['ok']) {
            db_rollback();
            return db_fail($item['product_code'] . ': ' . $applied['message']);
        }

        /* คืนยอดค้างจ่ายให้ใบเบิก จะได้ทำใบจ่ายใหม่ได้ */
        if ($doc['ref_doc_id'] > 0) {
            if (!issue_mark_request_line($doc['ref_doc_id'], $item['product_id'],
                                         -$item['qty'], $actor)) {
                db_rollback();
                return db_fail('คืนยอดค้างจ่ายให้ใบเบิกไม่สำเร็จ');
            }
        }
    }

    /* ใบเบิกที่เคยปิดเพราะจ่ายครบ ต้องกลับมาเป็น approved ให้จ่ายต่อได้ */
    if ($doc['ref_doc_id'] > 0 && !issue_request_is_complete($doc['ref_doc_id'])) {
        doc_set_status($doc['ref_doc_id'], 'approved', $actor);
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ?");
    if (!$stmt) { db_rollback(); return db_fail('ยกเลิกใบจ่ายไม่สำเร็จ'); }

    $id = (int)$doc['doc_id'];
    mysqli_stmt_bind_param($stmt, 'sssi', $actor, $reason, $actor, $id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    db_commit();

    /* ผู้ขอเบิกกำลังรอของอยู่ ต้องรู้ทันทีว่าของไม่มาแล้วและเพราะอะไร */
    notify_event_doc_cancelled($id, $reason, $actor);

    return db_ok('ยกเลิกใบจ่าย ' . $doc['doc_no'] . ' เรียบร้อย — คืนของเข้าสต๊อกแล้ว', $id);
}


/* ---------- จำนวนใบจ่ายที่ยังไม่ได้เซ็นรับ — ทำ badge บนเมนู ---------- */
function issue_pending_ack_count($username = '')
{
    $connect = db_connect();

    if ($username !== '') {
        $stmt = mysqli_prepare($connect,
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'issue' AND `status` = 'issued' AND `requester` = ?");
        if (!$stmt) { return 0; }
        mysqli_stmt_bind_param($stmt, 's', $username);
    } else {
        $stmt = mysqli_prepare($connect,
            "SELECT COUNT(*) FROM `tb_doc_header`
             WHERE `doc_type` = 'issue' AND `status` = 'issued'");
        if (!$stmt) { return 0; }
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total;
}
