<?php
/* ============================================================
 *  backend/receive.php — รับสินค้าเข้าคลัง (มีขั้นอนุมัติ)
 *
 *  เส้นทาง:
 *      บันทึก -> waiting -> received (อนุมัติ = เพิ่มสต๊อก) | rejected
 *      ยอดคงเหลือเพิ่ม "ตอนอนุมัติ" เท่านั้น ไม่ใช่ตอนบันทึก
 *
 *  ทำไมต้องรออนุมัติ: มีผู้ตรวจสอบเทียบรายการในใบบิล (ไฟล์แนบ) กับที่ผู้ทำรายการ
 *  คีย์เข้ามา ถ้าบวกสต๊อกไปก่อนแล้วตรวจเจอว่าผิด ของอาจถูกเบิกออกไปแล้วจนถอยกลับไม่ได้
 *  สถานะหลังอนุมัติใช้ 'received' (รับของแล้ว) เหมือนใบเดิมทั้งหมด รายงานจึงไม่ต้องแก้
 *
 *  ผู้อนุมัติ = คนในรายชื่อ "ผู้อนุมัติใบรับเข้า" (tb_receive_approver, backend/doc_approver.php)
 *              ไม่ผูกกับ role และต้องไม่ใช่คนที่บันทึกใบเอง
 *
 *  ไม่ระบุผู้ขาย ตามที่ตกลงไว้ — ที่มาของของบันทึกไว้ในช่องหมายเหตุแทน
 *
 *  ยกเลิกเอกสารได้ แต่ไม่ลบประวัติ ใบที่รับเข้าแล้ว ระบบจะบันทึกรายการกลับทิศทางแทน
 *  (move_type = cancel_receive) จะได้ตอบได้ว่ายอดเคยขึ้นแล้วถูกดึงกลับเมื่อไร
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';


/* ---------- บันทึกใบรับเข้า (รออนุมัติ ยังไม่แตะสต๊อก) ----------
 * $data = array('doc_date', 'note', 'items' => array(array('product_id','qty','note','unit_cost')))
 */
function receive_save($data, $actor)
{
    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    db_begin();

    $res = doc_insert('receive', $data, 'waiting', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    db_commit();

    $doc_id = $res['id'];
    $doc_no = $res['doc_no'];

    doc_history_add($doc_id, 'submitted', $actor, isset($data['note']) ? trim($data['note']) : '');

    /* ไม่แจ้งผู้อนุมัติตรงนี้ — ไฟล์ใบบิลยังไม่ถูกอัปโหลด (หน้าเว็บอัปโหลดต่อหลังบันทึก)
       หน้าเว็บเรียก action 'receive_submitted' เมื่ออัปโหลดเสร็จ จะได้เมลที่มีไฟล์ครบ
       ดู receive_notify_submitted() */

    $msg = 'บันทึกใบรับเข้า ' . $doc_no . ' เรียบร้อย — รอผู้ตรวจสอบอนุมัติก่อนจึงเพิ่มสต๊อก';
    /* ไม่มีใครอนุมัติได้ = ไม่มีใครได้แจ้งเตือน ใบจะค้างเงียบ ๆ — ต้องบอกคนบันทึกตรงนี้
       รวมกรณีที่ผู้อนุมัติมีแค่คนบันทึกเอง (อนุมัติใบตัวเองไม่ได้ ระบบจึงไม่แจ้งเขา) */
    $warn = '';
    if (count(doc_approver_usernames('receive')) === 0) {
        $warn = 'ยังไม่ได้ตั้งผู้อนุมัติใบรับเข้า ใบ ' . $doc_no . ' จะค้างรออนุมัติ '
              . '— แจ้งผู้ดูแลระบบให้ตั้งที่เมนู "ผู้อนุมัติเอกสาร" แท็บใบรับเข้า';
    } elseif (count(doc_approver_people('receive', $actor)) === 0) {
        $warn = 'ผู้อนุมัติใบรับเข้ามีแค่คุณคนเดียว แต่อนุมัติใบที่ตัวเองบันทึกไม่ได้ จึงไม่มีใครได้รับแจ้ง '
              . '— เพิ่มผู้อนุมัติคนอื่นที่เมนู "ผู้อนุมัติเอกสาร" แท็บใบรับเข้า แล้วกด "ส่งอีเมลซ้ำ" ที่ใบ ' . $doc_no;
    }

    $out = db_ok($msg, $doc_id);
    $out['doc_no'] = $doc_no;
    /* แยกเป็นคำเตือนต่างหาก หน้าเว็บแสดงเป็นกล่องที่ต้องกดปิดเอง (ข้อความสำเร็จเป็น toast หายใน 3 วิ อ่านไม่ทัน) */
    if ($warn !== '') { $out['warning'] = $warn; }
    return $out;
}


/* ---------- แจ้งผู้อนุมัติว่ามีใบรับเข้ารอตรวจ ----------
 * เรียกหลังหน้าเว็บอัปโหลดไฟล์ใบบิลเสร็จ (หรือไม่มีไฟล์) — ส่งได้ครั้งเดียวต่อใบ
 * กันกดซ้ำ: ส่งเฉพาะใบ waiting ที่ยังไม่เคยมีแจ้งเตือน receive_waiting
 */
function receive_notify_submitted($doc_id, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'receive' || $doc['status'] !== 'waiting') { return 0; }
    if ($doc['created_by'] !== $actor) { return 0; }

    $connect = db_connect();
    $id  = (int)$doc['doc_id'];
    $res = @mysqli_query($connect,
        "SELECT COUNT(*) FROM `tb_notification` WHERE `link_doc_id` = " . $id . " AND `noti_type` = 'receive_waiting'");
    if ($res) {
        $row = mysqli_fetch_row($res);
        mysqli_free_result($res);
        if ((int)$row[0] > 0) { return 0; }
    }

    require_once dirname(__FILE__) . '/notify_events.php';
    return notify_event_receive_submitted($id, $actor);
}


/* ---------- บวกสต๊อกตามบรรทัดของใบ (เรียกใน transaction ตอนอนุมัติ) ---------- */
function receive_apply_stock($doc, $actor)
{
    /* เวลาที่บันทึกลงประวัติใช้ "วันที่เอกสาร + เวลาปัจจุบัน"
       วันที่ต้องตรงกับเอกสารเพื่อให้รายงานตามช่วงวันที่ถูกต้อง
       ส่วนเวลาใช้ของจริง เพื่อให้เรียงลำดับรายการในวันเดียวกันได้ถูก */
    $move_date = $doc['doc_date'] . ' ' . date('H:i:s');

    $items = $doc['items'];
    for ($i = 0; $i < count($items); $i++) {
        $it = $items[$i];

        /* ราคาที่กรอกตอนบันทึกคือราคาซื้อจริงของครั้งนี้ ส่งเข้าไปให้บันทึกคู่กับรายการ */
        $applied = stock_apply(
            (int)$it['product_id'],
            (float)$it['qty'],
            'receive',
            array('doc_id' => $doc['doc_id'], 'doc_no' => $doc['doc_no'], 'move_date' => $move_date),
            $actor,
            $it['note'],
            (float)$it['unit_cost']
        );
        if (!$applied['ok']) {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ' . $applied['message']);
        }

        /* ซื้อมาราคาใหม่ ก็ให้ราคาในทะเบียนสินค้าตามไปด้วย
           ครั้งหน้าฟอร์มจะได้เติมราคาล่าสุดให้ ไม่ใช่ราคาเมื่อปีที่แล้ว */
        if (!receive_reprice((int)$it['product_id'], $actor)) {
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ปรับราคาในทะเบียนสินค้าไม่สำเร็จ');
        }
    }
    return db_ok('');
}


/* ============================================================
 *  ผู้ตรวจสอบตัดสินใบรับเข้า
 *  $decision = 'approved' -> สถานะ received + เพิ่มสต๊อก
 *              'rejected' -> สถานะ rejected ไม่แตะสต๊อก (ต้องมีเหตุผล)
 * ============================================================ */
function receive_decide($doc_id, $decision, $reason, $actor)
{
    if ($decision !== 'approved' && $decision !== 'rejected') {
        return db_fail('คำสั่งไม่ถูกต้อง');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                  { return db_fail('ไม่พบใบรับเข้าที่ต้องการ'); }
    if ($doc['doc_type'] !== 'receive') { return db_fail('เอกสารนี้ไม่ใช่ใบรับเข้า'); }
    if ($doc['status'] !== 'waiting') {
        return db_fail('ใบนี้ถูกตัดสินไปแล้ว (สถานะ: ' . $doc['status_label'] . ')');
    }

    if (!doc_approver_can('receive', $actor)) {
        return db_fail('คุณไม่ได้อยู่ในรายชื่อผู้อนุมัติใบรับเข้า — ให้ผู้ดูแลระบบเพิ่มชื่อที่เมนู "ผู้อนุมัติเอกสาร" ก่อน');
    }

    /* คนบันทึกตรวจของตัวเองไม่ได้ — ขั้นตรวจจะไม่มีความหมาย */
    if ($doc['created_by'] !== '' && $doc['created_by'] === $actor) {
        return db_fail('อนุมัติใบรับเข้าที่ตัวเองบันทึกไม่ได้ ต้องให้ผู้ตรวจสอบคนอื่นตัดสิน');
    }

    $reason = trim($reason);
    if ($decision === 'rejected' && $reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ไม่อนุมัติ — ผู้บันทึกต้องรู้ว่ารายการไหนไม่ตรงกับบิล');
    }

    $connect    = db_connect();
    $id         = (int)$doc['doc_id'];
    $actor_name = doc_user_name($actor);    // แช่แข็งชื่อผู้อนุมัติ — ดู doc_user_name()
    $new_status = ($decision === 'approved') ? 'received' : 'rejected';

    db_begin();

    /* WHERE status = 'waiting' กันสองคนกดพร้อมกันแล้วบวกสต๊อกซ้ำ */
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = ?, `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(),
             `approve_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'waiting'");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกผลไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sssssi', $new_status, $actor, $actor_name, $reason, $actor, $id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected < 1) {
        db_rollback();
        return db_fail('มีคนตัดสินใบนี้ไปแล้ว กรุณารีเฟรชหน้าแล้วลองใหม่');
    }

    if ($decision === 'approved') {
        $applied = receive_apply_stock($doc, $actor);
        if (!$applied['ok']) { db_rollback(); return $applied; }
    }

    db_commit();

    /* เผากุญแจอนุมัติทางอีเมลของใบนี้ — เรื่องจบแล้ว */
    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);

    doc_history_add($id, $decision, $actor, $reason);

    require_once dirname(__FILE__) . '/notify_events.php';
    notify_event_receive_decided($id, $decision, $reason, $actor);

    return db_ok(($decision === 'approved')
        ? 'อนุมัติใบรับเข้า ' . $doc['doc_no'] . ' เรียบร้อย — เพิ่มสต๊อกแล้ว'
        : 'ไม่อนุมัติใบรับเข้า ' . $doc['doc_no'] . ' เรียบร้อย — สต๊อกไม่เปลี่ยน', $id);
}


/* ---------- จำนวนใบรับเข้าที่รอคนนี้อนุมัติ — badge บนเมนู ----------
 * คนที่ไม่อยู่ในรายชื่อได้ 0 เสมอ และไม่นับใบที่ตัวเองบันทึก (อนุมัติเองไม่ได้)
 */
function receive_waiting_count($actor)
{
    if (!doc_approver_can('receive', $actor)) { return 0; }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "SELECT COUNT(*) FROM `tb_doc_header`
         WHERE `doc_type` = 'receive' AND `status` = 'waiting'
           AND (`created_by` IS NULL OR `created_by` <> ?)");
    if (!$stmt) { return 0; }
    mysqli_stmt_bind_param($stmt, 's', $actor);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int)$n;
}


/* ============================================================
 *  ราคาในทะเบียนสินค้า = ราคาที่ซื้อเข้าครั้งล่าสุด
 * ============================================================
 *  ช่อง "ราคา" ในหน้ารายการสินค้า (tb_product.unit_cost) เป็นราคาอ้างอิง
 *  ที่หน้าบันทึกรับเข้าหยิบไปเติมให้เป็นค่าตั้งต้นของช่องราคา/หน่วย
 *
 *  เดิมค่านี้ขยับเฉพาะตอนมีคนเข้าไปพิมพ์แก้ในทะเบียนเอง ซื้อของราคาใหม่
 *  มากี่รอบมันก็ค้างอยู่ที่ราคาเดิม แล้วครั้งถัดไปฟอร์มก็เติมราคาเก่าให้อีก
 *  คนกรอกต้องคอยลบทิ้งแล้วพิมพ์ใหม่ทุกครั้ง ซึ่งเป็นงานที่ระบบทำให้ได้
 *
 *  ตรงนี้จึงตามไปเขียนราคาล่าสุดให้หลังบันทึกใบรับเข้าและหลังยกเลิก
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องอ่านย้อนจากประวัติ ไม่ใช่เอาราคาที่เพิ่งกรอกมาเขียนทับ
 *  ---------------------------------------------------------------
 *  ใบรับเข้าลงวันที่ย้อนหลังได้ ถ้าเขียนทับตรง ๆ คนที่เพิ่งคีย์ใบเดือนที่แล้ว
 *  ตกหล่นเข้าไป จะทำให้ราคาในทะเบียนถอยกลับไปเป็นของเดือนนั้น
 *  ทั้งที่มีใบใหม่กว่าอยู่แล้ว จึงเลือก "ใบที่ใหม่ที่สุดที่ยังไม่ถูกยกเลิก"
 *  ด้วย query ชุดเดียวกันทั้งตอนบันทึกและตอนยกเลิก ผลจึงตรงกันเสมอ
 *
 *  ไม่มีใบรับเข้าเหลืออยู่เลย = ไม่แตะ ปล่อยราคาที่ตั้งไว้ในทะเบียนไว้เหมือนเดิม
 *  ดีกว่าล้างเป็นศูนย์ ซึ่งจะทำให้สินค้าตัวนั้นไปโผล่ในรายการ
 *  "ยังไม่ได้ตั้งราคา" บนหน้าภาพรวม ทั้งที่คนตั้งไว้แล้วแต่ใบถูกยกเลิก
 *
 *  หมายเหตุ: ค่านี้คือราคาที่ระบบจะใช้ตัดสต๊อกตอนเบิกครั้งถัดไป แต่ไม่กระทบ
 *  รายการที่เกิดไปแล้ว เพราะรายงานทุกตัวอ่านราคาที่แช่แข็งไว้ใน tb_stock_movement
 *  แก้ค่านี้จึงไม่ทำให้ตัวเลขของเดือนที่ปิดไปแล้วขยับแม้แต่บาทเดียว
 * ============================================================ */
function receive_reprice($product_id, $actor, $skip_doc_id = 0)
{
    $connect = db_connect();

    $sql = "SELECT i.`unit_cost`
            FROM `tb_doc_item` i
            JOIN `tb_doc_header` h ON h.`doc_id` = i.`doc_id`
            WHERE i.`product_id` = ?
              AND h.`doc_type` = 'receive'
              AND h.`status` = 'received'
              AND i.`unit_cost` > 0";
    /* เฉพาะใบที่อนุมัติแล้ว (received) — ใบที่ยังรอ/ไม่อนุมัติยังไม่ใช่ราคาที่ซื้อเข้าจริง */

    if ((int)$skip_doc_id > 0) { $sql .= " AND h.`doc_id` <> ?"; }

    /* ใหม่ที่สุดตามวันที่เอกสารก่อน แล้วค่อยตัดสินด้วยลำดับที่บันทึก
       สองใบลงวันเดียวกันจะได้ผลลัพธ์เดิมทุกครั้ง ไม่ขึ้นกับลำดับที่ MySQL คืนมา */
    $sql .= " ORDER BY h.`doc_date` DESC, h.`doc_id` DESC LIMIT 1";

    $stmt = mysqli_prepare($connect, $sql);
    if (!$stmt) { return false; }

    $pid  = (int)$product_id;
    $skip = (int)$skip_doc_id;
    if ($skip > 0) { mysqli_stmt_bind_param($stmt, 'ii', $pid, $skip); }
    else           { mysqli_stmt_bind_param($stmt, 'i',  $pid); }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $cost);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    /* ไม่เหลือใบรับเข้าที่ใช้ได้ = ไม่มีข้อมูลใหม่กว่าที่จะเขียนทับ ปล่อยไว้เหมือนเดิม */
    if (!$found) { return true; }

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_product` SET `unit_cost` = ?, `updated_by` = ? WHERE `product_id` = ?");
    if (!$stmt) { return false; }

    $cost = (float)$cost;
    mysqli_stmt_bind_param($stmt, 'dsi', $cost, $actor, $pid);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok ? true : false;
}


/* ---------- ยกเลิกใบรับเข้า ----------
 * ดึงของที่เคยรับเข้าออกจากสต๊อก ถ้าของถูกเบิกไปแล้วจนยอดไม่พอ จะยกเลิกไม่ได้
 * (stock_apply ปฏิเสธเองเมื่อยอดจะติดลบ ไม่ต้องตรวจซ้ำที่นี่)
 */
function receive_cancel($doc_id, $reason, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null)                { return db_fail('ไม่พบเอกสารที่ต้องการยกเลิก'); }
    if ($doc['doc_type'] !== 'receive') { return db_fail('เอกสารนี้ไม่ใช่ใบรับเข้า'); }
    if ($doc['status'] === 'cancelled') { return db_fail('เอกสารนี้ถูกยกเลิกไปแล้ว'); }
    if ($doc['status'] === 'rejected')  { return db_fail('ใบที่ไม่อนุมัติแล้ว ไม่ต้องยกเลิก — สต๊อกไม่เคยเพิ่ม'); }

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก — จะได้ตรวจย้อนได้ว่ายกเลิกเพราะอะไร');
    }

    db_begin();

    /* ใบที่ยังรออนุมัติ ยังไม่เคยบวกสต๊อก — เปลี่ยนสถานะอย่างเดียว ไม่มีอะไรให้ดึงคืน */
    $was_in_stock = ($doc['status'] === 'received');

    $move_date = date('Y-m-d H:i:s');
    for ($i = 0; $was_in_stock && $i < count($doc['items']); $i++) {
        $item = $doc['items'][$i];

        /* ถอนมูลค่าออกด้วย "ราคาที่รับเข้ามาตอนนั้น" ไม่ใช่ราคาซื้อล่าสุดวันนี้
           ไม่งั้นถ้าราคาระหว่างนั้นขยับ มูลค่าที่ถอนจะไม่เท่ากับที่เคยใส่เข้าไป */
        $applied = stock_apply(
            $item['product_id'],
            -$item['qty'],
            'cancel_receive',
            array('doc_id' => $doc['doc_id'], 'doc_no' => $doc['doc_no'], 'move_date' => $move_date),
            $actor,
            'ยกเลิกใบรับเข้า: ' . $reason,
            $item['unit_cost']
        );

        if (!$applied['ok']) {
            db_rollback();
            return db_fail($item['product_code'] . ' ' . $item['product_name']
                         . ': ' . $applied['message']
                         . ' (ของถูกเบิกออกไปแล้ว จึงดึงคืนไม่ได้)');
        }

        /* ใบนี้กำลังจะถูกยกเลิก ราคาในทะเบียนต้องถอยกลับไปเป็นของใบก่อนหน้า
           ส่งเลขที่เอกสารไปให้ตัดทิ้ง เพราะสถานะในตารางยังไม่ได้เปลี่ยนตอนนี้
           (UPDATE ที่ตั้ง cancelled อยู่หลังลูปนี้) */
        if (!receive_reprice((int)$item['product_id'], $actor, (int)$doc['doc_id'])) {
            db_rollback();
            return db_fail($item['product_code'] . ': ปรับราคาในทะเบียนสินค้าไม่สำเร็จ');
        }
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = ?");
    if (!$stmt) { db_rollback(); return db_fail('ยกเลิกเอกสารไม่สำเร็จ'); }

    /* AND status = สถานะที่อ่านมา — กันผู้อนุมัติกดอนุมัติแทรกเข้ามาระหว่างนี้
       (ไม่งั้นใบที่เพิ่งบวกสต๊อกจะถูกยกเลิกโดยไม่ดึงยอดคืน) */
    $id  = (int)$doc['doc_id'];
    $was = $doc['status'];
    mysqli_stmt_bind_param($stmt, 'sssis', $actor, $reason, $actor, $id, $was);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected < 1) {
        db_rollback();
        return db_fail('สถานะใบเปลี่ยนไประหว่างทาง กรุณารีเฟรชหน้าแล้วลองใหม่');
    }

    db_commit();

    doc_history_add($id, 'cancelled', $actor, $reason);

    if (!$was_in_stock) {
        require_once dirname(__FILE__) . '/approve_link.php';
        approve_link_close_doc($id, $actor);
    }

    return db_ok('ยกเลิกใบรับเข้า ' . $doc['doc_no'] . ' เรียบร้อย'
               . ($was_in_stock ? ' — ดึงยอดออกจากสต๊อกแล้ว' : ''), $id);
}
