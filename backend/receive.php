<?php
/* ============================================================
 *  backend/receive.php — รับสินค้าเข้าคลัง
 *
 *  บันทึกแล้วบวกสต๊อกทันที ไม่มีขั้นอนุมัติ
 *  เพราะของเข้าคลังจริงไปแล้วตอนที่คนบันทึก ถ้าให้รออนุมัติ
 *  ยอดในระบบจะต่ำกว่าของจริงระหว่างนั้น แล้วคนเบิกจะเบิกไม่ได้ทั้งที่มีของ
 *
 *  ไม่ระบุผู้ขาย ตามที่ตกลงไว้ — ที่มาของของบันทึกไว้ในช่องหมายเหตุแทน
 *
 *  ยกเลิกเอกสารได้ แต่ไม่ลบประวัติ ระบบจะบันทึกรายการกลับทิศทางแทน
 *  (move_type = cancel_receive) จะได้ตอบได้ว่ายอดเคยขึ้นแล้วถูกดึงกลับเมื่อไร
 * ============================================================ */

require_once dirname(__FILE__) . '/document.php';


/* ---------- บันทึกใบรับเข้า ----------
 * $data = array('doc_date', 'note', 'items' => array(array('product_id','qty','note')))
 */
function receive_save($data, $actor)
{
    $error = doc_validate($data);
    if ($error !== '') { return db_fail($error); }

    db_begin();

    $res = doc_insert('receive', $data, 'received', $actor);
    if (!$res['ok']) {
        db_rollback();
        return $res;
    }

    $doc_id = $res['id'];
    $doc_no = $res['doc_no'];

    /* เวลาที่บันทึกลงประวัติใช้ "วันที่เอกสาร + เวลาปัจจุบัน"
       วันที่ต้องตรงกับเอกสารเพื่อให้รายงานตามช่วงวันที่ถูกต้อง
       ส่วนเวลาใช้ของจริง เพื่อให้เรียงลำดับรายการในวันเดียวกันได้ถูก */
    $move_date = $data['doc_date'] . ' ' . date('H:i:s');

    $items = $data['items'];
    for ($i = 0; $i < count($items); $i++) {

        /* ราคาที่กรอกมาคือราคาซื้อจริงของครั้งนี้ ส่งเข้าไปให้คิดต้นทุนเฉลี่ยใหม่ */
        $applied = stock_apply(
            (int)$items[$i]['product_id'],
            (float)$items[$i]['qty'],
            'receive',
            array('doc_id' => $doc_id, 'doc_no' => $doc_no, 'move_date' => $move_date),
            $actor,
            isset($items[$i]['note']) ? $items[$i]['note'] : '',
            isset($items[$i]['unit_cost']) ? (float)$items[$i]['unit_cost'] : 0
        );

        if (!$applied['ok']) {
            db_rollback();
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ' . $applied['message']);
        }
    }

    db_commit();

    $out = db_ok('บันทึกใบรับเข้า ' . $doc_no . ' เรียบร้อย — เพิ่มสต๊อกแล้ว', $doc_id);
    $out['doc_no'] = $doc_no;
    return $out;
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

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก — จะได้ตรวจย้อนได้ว่ายกเลิกเพราะอะไร');
    }

    db_begin();

    $move_date = date('Y-m-d H:i:s');
    for ($i = 0; $i < count($doc['items']); $i++) {
        $item = $doc['items'][$i];

        /* ถอนมูลค่าออกด้วย "ราคาที่รับเข้ามาตอนนั้น" ไม่ใช่ต้นทุนเฉลี่ยวันนี้
           ไม่งั้นถ้าราคาระหว่างนั้นขยับ มูลค่าคงเหลือจะค้างเป็นเศษที่อธิบายไม่ได้ */
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
    }

    $connect = db_connect();
    $stmt = mysqli_prepare($connect,
        "UPDATE `tb_doc_header`
         SET `status` = 'cancelled', `cancelled_by` = ?, `cancelled_at` = NOW(),
             `cancel_note` = ?, `updated_by` = ?
         WHERE `doc_id` = ?");
    if (!$stmt) { db_rollback(); return db_fail('ยกเลิกเอกสารไม่สำเร็จ'); }

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

    return db_ok('ยกเลิกใบรับเข้า ' . $doc['doc_no'] . ' เรียบร้อย — ดึงยอดออกจากสต๊อกแล้ว', $id);
}
