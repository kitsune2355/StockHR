<?php
/* ============================================================
 *  backend/adjust.php — ใบปรับยอดสินค้า (มีเลขที่ + ขั้นอนุมัติ)
 *
 *  ใช้ตอนยอดในระบบไม่ตรงกับของจริงในคลัง เช่น ตรวจนับประจำปี
 *  ของชำรุด/สูญหาย หรือยอดยกมาผิด
 *
 *  เดินเส้นทางเดียวกับใบเบิก:
 *      บันทึก -> waiting -> approved | rejected     (ยกเลิกได้ทุกช่วงก่อน rejected)
 *      ยอดคงเหลือเปลี่ยน "ตอนอนุมัติ" เท่านั้น ไม่ใช่ตอนบันทึก
 *
 *  ใช้โครงเอกสารเดิม tb_doc_header/tb_doc_item (doc_type='adjust')
 *      tb_doc_item.qty  = จำนวนที่นับได้จริง (ยอดปลายทางที่อยากให้เป็น)
 *      tb_doc_item.note = หมายเหตุรายบรรทัด
 *  ตอนอนุมัติ ระบบคิดผลต่างเทียบยอดที่ล็อก FOR UPDATE ณ วินาทีนั้น แล้วเขียน
 *  tb_stock_movement (move_type='adjust', doc_id ชี้กลับมาที่ใบนี้) ผ่าน stock_set()
 *
 *  ผู้สร้างใบ = super_admin/admin (บังคับที่ api/adjust.php)
 *  ผู้อนุมัติ = เฉพาะคนในตาราง tb_adjust_approver (ตั้งที่หน้า "ตั้งค่าอนุมัติ")
 *              ไม่ผูกกับ role และต้องไม่ใช่คนที่สร้างใบเอง
 *
 *  กติกาชั้น backend เหมือนเดิม: ไม่ echo ไม่ redirect ไม่อ่าน $_SESSION
 *  เขียนให้รองรับ PHP 5.2.6 และ MySQL 5.0.51b
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';
require_once dirname(__FILE__) . '/adjust_approver.php';


/* ---------- ตัดข้อความให้ยาวไม่เกินช่อง note (VARCHAR 300) ---------- */
function adjust_clip_note($text)
{
    $text = trim($text);
    if (db_text_length($text) <= 300) {
        return $text;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 300, 'UTF-8');
    }
    return substr($text, 0, 300);
}


/* ============================================================
 *  ตรวจข้อมูลก่อนบันทึก — คืนข้อความผิดพลาด หรือ '' ถ้าผ่าน
 *
 *  ต่างจาก doc_validate() ตรงที่อนุญาต "นับได้จริง = 0" (ของหมด/สูญหายหมด)
 *  ส่วนกติกาห้ามวันที่ล่วงหน้าและห้ามสินค้าซ้ำในใบเดียว ใช้เหมือนกัน
 * ============================================================ */
function adjust_validate($data)
{
    $doc_date = isset($data['doc_date']) ? trim($data['doc_date']) : '';
    if ($doc_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $doc_date)) {
        return 'กรุณาเลือกวันที่ตรวจนับ';
    }
    if (strtotime($doc_date) > strtotime(date('Y-m-d'))) {
        return 'ลงวันที่ล่วงหน้าไม่ได้ — ต้องเป็นวันที่ตรวจนับจริง';
    }

    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    if (count($items) === 0) {
        return 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ';
    }

    $seen = array();
    for ($i = 0; $i < count($items); $i++) {
        $line       = $i + 1;
        $product_id = isset($items[$i]['product_id']) ? (int)$items[$i]['product_id'] : 0;
        $counted    = isset($items[$i]['counted']) ? $items[$i]['counted'] : null;

        if ($product_id <= 0) {
            return 'บรรทัดที่ ' . $line . ': ยังไม่ได้เลือกสินค้า';
        }
        if ($counted === null || $counted === '' || !is_numeric($counted)) {
            return 'บรรทัดที่ ' . $line . ': ยังไม่ได้กรอกจำนวนที่นับได้';
        }
        if ((float)$counted < 0) {
            return 'บรรทัดที่ ' . $line . ': จำนวนที่นับได้ติดลบไม่ได้';
        }
        if (isset($seen[$product_id])) {
            return 'บรรทัดที่ ' . $line . ': สินค้าซ้ำกับบรรทัดที่ ' . $seen[$product_id]
                 . ' — เก็บไว้บรรทัดเดียว';
        }
        $seen[$product_id] = $line;
    }

    return '';
}


/* ============================================================
 *  บันทึกใบปรับยอด (ยังไม่แตะสต๊อก — รออนุมัติ)
 *
 *  $data  = array('doc_date', 'note', 'items' => array(
 *               array('product_id', 'counted', 'note')))
 *  คืน array('ok', 'message', 'id' => doc_id) + 'doc_no'
 * ============================================================ */
function adjust_save($data, $actor)
{
    $error = adjust_validate($data);
    if ($error !== '') { return db_fail($error); }

    /* map ให้ตรงกับที่ doc_insert() ต้องการ: counted -> qty */
    $items = array();
    for ($i = 0; $i < count($data['items']); $i++) {
        $it = $data['items'][$i];
        $items[] = array(
            'product_id' => (int)$it['product_id'],
            'qty'        => (float)$it['counted'],
            'note'       => isset($it['note']) ? adjust_clip_note($it['note']) : ''
        );
    }

    $doc_data = array(
        'doc_date' => trim($data['doc_date']),
        'note'     => isset($data['note']) ? trim($data['note']) : '',
        'items'    => $items
    );

    db_begin();

    $res = doc_insert('adjust', $doc_data, 'waiting', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    db_commit();

    $doc_id = $res['id'];
    $doc_no = $res['doc_no'];

    /* แจ้งผู้อนุมัติ — หลัง commit และผลลัพธ์ไม่กระทบการบันทึก */
    require_once dirname(__FILE__) . '/notify_events.php';
    notify_event_adjust_submitted($doc_id, $actor);

    $out = db_ok('ยื่นใบปรับยอด ' . $doc_no . ' เรียบร้อย — รอการอนุมัติก่อนยอดจึงจะเปลี่ยน', $doc_id);
    $out['doc_no'] = $doc_no;
    return $out;
}


/* ============================================================
 *  ผู้อนุมัติตัดสินใบปรับยอด
 *  $decision = 'approved' | 'rejected'
 * ============================================================ */
function adjust_decide($doc_id, $decision, $reason, $actor, $actor_role)
{
    if ($decision !== 'approved' && $decision !== 'rejected') {
        return db_fail('คำสั่งไม่ถูกต้อง');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                 { return db_fail('ไม่พบเอกสารที่ต้องการตัดสิน'); }
    if ($doc['doc_type'] !== 'adjust') { return db_fail('เอกสารนี้ไม่ใช่ใบปรับยอด'); }
    if ($doc['status'] !== 'waiting')  {
        return db_fail('ใบนี้ถูกตัดสินไปแล้ว (สถานะ: ' . $doc['status_label'] . ')');
    }

    if (!adjust_approver_can($actor, $actor_role)) {
        return db_fail('คุณไม่มีสิทธิ์อนุมัติใบปรับยอด');
    }

    /* ห้ามอนุมัติใบที่ตัวเองสร้าง — ต้องให้คนอื่นตรวจ */
    if ($doc['created_by'] !== '' && $doc['created_by'] === $actor) {
        return db_fail('อนุมัติใบที่ตัวเองสร้างไม่ได้ ต้องให้ผู้อนุมัติคนอื่นตัดสิน');
    }

    $reason = trim($reason);
    if ($decision === 'rejected' && $reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
    }

    $connect = db_connect();
    $id      = (int)$doc['doc_id'];

    /* แช่แข็งชื่อผู้อนุมัติไว้ตอนตัดสินจริง — ดูเหตุผลที่ doc_user_name() */
    $actor_name = doc_user_name($actor);

    /* ---------- ไม่อนุมัติ: อัปเดตหัวใบอย่างเดียว ---------- */
    if ($decision === 'rejected') {
        $stmt = mysqli_prepare($connect,
            "UPDATE `tb_doc_header`
             SET `status` = 'rejected', `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(),
                 `approve_note` = ?, `updated_by` = ?
             WHERE `doc_id` = ? AND `status` = 'waiting'");
        if (!$stmt) { return db_fail('บันทึกผลไม่สำเร็จ'); }

        mysqli_stmt_bind_param($stmt, 'ssssi', $actor, $actor_name, $reason, $actor, $id);
        if (!mysqli_stmt_execute($stmt)) {
            $errno = mysqli_stmt_errno($stmt);
            mysqli_stmt_close($stmt);
            return db_fail(db_error_message($errno), $errno);
        }
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected < 1) {
            return db_fail('มีคนตัดสินใบนี้ไปแล้ว กรุณารีเฟรชหน้าแล้วลองใหม่');
        }

        /* เผากุญแจอนุมัติทางอีเมลของใบนี้ทั้งหมด — เรื่องจบแล้ว ลิงก์ที่เหลือไม่ควรกดได้อีก */
        require_once dirname(__FILE__) . '/approve_link.php';
        approve_link_close_doc($id, $actor);

        require_once dirname(__FILE__) . '/notify_events.php';
        notify_event_adjust_decided($id, 'rejected', $reason, $actor);

        return db_ok('ไม่อนุมัติใบปรับยอด ' . $doc['doc_no'] . ' เรียบร้อย', $id);
    }

    /* ---------- อนุมัติ: ล็อกหัวใบก่อน แล้วปรับสต๊อกทีละบรรทัด ---------- */
    db_begin();

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'approved', `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(),
             `approve_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` = 'waiting'");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกผลไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'ssssi', $actor, $actor_name, $reason, $actor, $id);
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

    /* เวลาที่ลงประวัติ = วันที่ตรวจนับ + เวลาปัจจุบัน (เหมือนใบรับเข้า) */
    $move_date = $doc['doc_date'] . ' ' . date('H:i:s');
    $head_note = trim($doc['note']);

    $items   = $doc['items'];
    $applied = 0;

    for ($i = 0; $i < count($items); $i++) {
        $it   = $items[$i];
        $note = ($it['note'] !== '') ? $it['note'] : $head_note;
        if ($note === '') { $note = 'ปรับยอดตามการตรวจนับ ' . $doc['doc_no']; }
        $note = adjust_clip_note($note);

        $r = stock_set(
            (int)$it['product_id'],
            (float)$it['qty'],
            'adjust',
            array('doc_id' => $id, 'doc_no' => $doc['doc_no'], 'move_date' => $move_date),
            $actor,
            $note
        );

        if (!$r['ok']) {
            db_rollback();
            return db_fail('บรรทัดที่ ' . ($i + 1) . ' (' . $it['product_code'] . '): ' . $r['message']);
        }
        if (empty($r['skipped'])) { $applied++; }
    }

    db_commit();

    require_once dirname(__FILE__) . '/approve_link.php';
    approve_link_close_doc($id, $actor);

    require_once dirname(__FILE__) . '/notify_events.php';
    notify_event_adjust_decided($id, 'approved', $reason, $actor);

    $msg = 'อนุมัติใบปรับยอด ' . $doc['doc_no'] . ' เรียบร้อย';
    $msg .= ($applied > 0) ? ' — ปรับยอด ' . $applied . ' รายการแล้ว' : ' — ทุกบรรทัดยอดตรงกับระบบพอดี';
    return db_ok($msg, $id);
}


/* ============================================================
 *  ยกเลิกใบปรับยอด
 *  - waiting  -> cancelled  (ยังไม่แตะสต๊อก ยกเลิกได้เลย)
 *  - approved -> cancelled  (กลับรายการที่ปรับไปแล้วออกจากสต๊อก)
 *  เฉพาะ super_admin / admin
 * ============================================================ */
function adjust_cancel($doc_id, $reason, $actor, $actor_role)
{
    if (!in_array($actor_role, array('super_admin', 'admin'))) {
        return db_fail('เฉพาะผู้ดูแลระบบยกเลิกใบปรับยอดได้');
    }

    $doc = doc_find($doc_id);
    if ($doc === null)                 { return db_fail('ไม่พบเอกสารที่ต้องการยกเลิก'); }
    if ($doc['doc_type'] !== 'adjust') { return db_fail('เอกสารนี้ไม่ใช่ใบปรับยอด'); }
    if ($doc['status'] === 'cancelled') { return db_fail('เอกสารนี้ถูกยกเลิกไปแล้ว'); }
    if ($doc['status'] === 'rejected')  { return db_fail('ใบที่ไม่อนุมัติแล้ว ไม่ต้องยกเลิก'); }

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก — จะได้ตรวจย้อนได้ว่ายกเลิกเพราะอะไร');
    }

    $connect = db_connect();
    $id      = (int)$doc['doc_id'];

    db_begin();

    /* ถ้าอนุมัติไปแล้ว ต้องถอนผลต่างที่เคยปรับออกจากสต๊อกก่อน
       อ่านผลต่างจากแถวประวัติของใบนี้ (qty_in - qty_out ต่อสินค้า) */
    if ($doc['status'] === 'approved') {

        $stmt = mysqli_prepare($connect,
            "SELECT `product_id`, SUM(`qty_in`) - SUM(`qty_out`)
             FROM `tb_stock_movement`
             WHERE `doc_id` = ? AND `move_type` = 'adjust'
             GROUP BY `product_id`");
        if (!$stmt) { db_rollback(); return db_fail('อ่านประวัติการปรับยอดไม่สำเร็จ'); }

        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $pid, $delta);

        $rows = array();
        while (mysqli_stmt_fetch($stmt)) {
            $rows[] = array('pid' => (int)$pid, 'delta' => (float)$delta);
        }
        mysqli_stmt_close($stmt);

        $move_date = date('Y-m-d H:i:s');
        for ($i = 0; $i < count($rows); $i++) {
            if ($rows[$i]['delta'] == 0) { continue; }

            $r = stock_apply(
                $rows[$i]['pid'],
                -$rows[$i]['delta'],
                'adjust',
                array('doc_id' => $id, 'doc_no' => $doc['doc_no'], 'move_date' => $move_date),
                $actor,
                'ยกเลิกใบปรับยอด ' . $doc['doc_no'] . ': ' . $reason
            );

            if (!$r['ok']) {
                db_rollback();
                return db_fail($r['message'] . ' (ของถูกเบิก/จ่ายออกไปหลังปรับยอดแล้ว จึงถอนคืนไม่ได้)');
            }
        }
    }

    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ? AND `status` IN ('waiting','approved')");
    if (!$stmt) { db_rollback(); return db_fail('ยกเลิกเอกสารไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'sssi', $actor, $reason, $actor, $id);
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

    require_once dirname(__FILE__) . '/notify_events.php';
    notify_event_doc_cancelled($id, $reason, $actor);

    return db_ok('ยกเลิกใบปรับยอด ' . $doc['doc_no'] . ' เรียบร้อย', $id);
}


/* ---------- จำนวนใบปรับยอดที่รออนุมัติ — ใช้ทำ badge บนเมนู ---------- */
function adjust_waiting_count()
{
    $connect = db_connect();
    $res = mysqli_query($connect,
        "SELECT COUNT(*) FROM `tb_doc_header`
         WHERE `doc_type` = 'adjust' AND `status` = 'waiting'");
    if (!$res) { return 0; }
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    return (int)$row[0];
}


/* ============================================================
 *  รายการในใบปรับยอด พร้อม ยอดเดิม / นับได้ / ส่วนต่าง
 *  ใช้กับกล่องรายละเอียดและหน้าพิมพ์
 *
 *  ใบที่อนุมัติแล้ว: อ่านยอดเดิม/ใหม่ที่ "แช่แข็ง" ไว้ในแถวประวัติ ณ ตอนอนุมัติ
 *  ใบที่ยังรออยู่:   ใช้ยอดคงเหลือปัจจุบันเป็นยอดเดิม (ยังไม่แช่แข็ง)
 * ============================================================ */
function adjust_doc_lines($doc)
{
    if ($doc === null || !isset($doc['items'])) { return array(); }

    $frozen = array();
    if ($doc['status'] === 'approved') {
        $connect = db_connect();
        $id = (int)$doc['doc_id'];
        $res = @mysqli_query($connect,
            "SELECT `product_id`, SUM(`qty_in`) AS `qin`, SUM(`qty_out`) AS `qout`,
                    SUBSTRING_INDEX(GROUP_CONCAT(`balance_after` ORDER BY `move_id` DESC), ',', 1) AS `after_bal`
             FROM `tb_stock_movement`
             WHERE `doc_id` = " . $id . " AND `move_type` = 'adjust'
             GROUP BY `product_id`");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $after = (float)$r['after_bal'];
                $diff  = (float)$r['qin'] - (float)$r['qout'];
                $frozen[(int)$r['product_id']] = array(
                    'after'  => $after,
                    'before' => $after - $diff,
                    'diff'   => $diff
                );
            }
            mysqli_free_result($res);
        }
    }

    $out = array();
    for ($i = 0; $i < count($doc['items']); $i++) {
        $it     = $doc['items'][$i];
        $pid    = (int)$it['product_id'];
        $counted = (float)$it['qty'];

        if (isset($frozen[$pid])) {
            $before = $frozen[$pid]['before'];
            $after  = $frozen[$pid]['after'];
            $diff   = $frozen[$pid]['diff'];
        } else {
            $before = (float)$it['balance'];   // ยอดคงเหลือปัจจุบัน
            $after  = $counted;
            $diff   = $counted - $before;
        }

        $out[] = array(
            'line_no'      => (int)$it['line_no'],
            'product_code' => $it['product_code'],
            'product_name' => $it['product_name'],
            'unit_name'    => $it['unit_name'],
            'note'         => $it['note'],
            'before'       => $before,
            'counted'      => $counted,
            'after'        => $after,
            'diff'         => $diff
        );
    }
    return $out;
}
