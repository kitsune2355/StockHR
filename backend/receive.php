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

        /* ราคาที่กรอกมาคือราคาซื้อจริงของครั้งนี้ ส่งเข้าไปให้บันทึกคู่กับรายการ */
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

        /* ซื้อมาราคาใหม่ ก็ให้ราคาในทะเบียนสินค้าตามไปด้วย
           ครั้งหน้าฟอร์มจะได้เติมราคาล่าสุดให้ ไม่ใช่ราคาเมื่อปีที่แล้ว */
        if (!receive_reprice((int)$items[$i]['product_id'], $actor)) {
            db_rollback();
            return db_fail('บรรทัดที่ ' . ($i + 1) . ': ปรับราคาในทะเบียนสินค้าไม่สำเร็จ');
        }
    }

    db_commit();

    $out = db_ok('บันทึกใบรับเข้า ' . $doc_no . ' เรียบร้อย — เพิ่มสต๊อกแล้ว', $doc_id);
    $out['doc_no'] = $doc_no;
    return $out;
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
              AND h.`status` <> 'cancelled'
              AND i.`unit_cost` > 0";

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

    $reason = trim($reason);
    if ($reason === '') {
        return db_fail('กรุณาระบุเหตุผลที่ยกเลิก — จะได้ตรวจย้อนได้ว่ายกเลิกเพราะอะไร');
    }

    db_begin();

    $move_date = date('Y-m-d H:i:s');
    for ($i = 0; $i < count($doc['items']); $i++) {
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
