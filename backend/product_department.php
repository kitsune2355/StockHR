<?php
/* ============================================================
 *  backend/product_department.php — สินค้าที่แต่ละหน่วยงานเบิกได้
 *
 *  ความสัมพันธ์แบบหลายต่อหลาย: สินค้าหนึ่งตัวอยู่ได้หลายหน่วยงาน
 *  และหน่วยงานหนึ่งมีสินค้าได้หลายตัว (ดู sql/016_product_department.sql)
 *
 *  ---------------------------------------------------------------
 *  กติกาที่ทั้งระบบต้องตีความเหมือนกัน
 *  ---------------------------------------------------------------
 *  สินค้าที่ "ยังไม่ได้ผูกกับหน่วยงานใดเลย" = ทุกหน่วยงานเบิกได้
 *
 *  ไม่ใช่ "ไม่มีใครเบิกได้" เพราะกติกานั้นจะทำให้สินค้าทั้ง 313 รายการ
 *  หายไปจากหน้าทำใบเบิกของทุกคนทันทีที่เปิดใช้ฟีเจอร์นี้
 *
 *  ผลที่ตามมาคือการจำกัดเป็นแบบ "เลือกจำกัดเฉพาะที่ต้องการ" ไม่ใช่
 *  "ต้องอนุญาตทุกตัวก่อนถึงใช้ได้" ซึ่งเหมาะกับของใช้สำนักงานที่
 *  ส่วนใหญ่เป็นของกลาง มีแค่บางรายการที่เป็นของเฉพาะฝ่าย
 *
 *  ฟังก์ชันที่ใช้กติกานี้:
 *      product_dept_where()      ท่อน SQL สำหรับกรองในคำสั่งอื่น
 *      product_dept_allows()     ตรวจทีละตัวตอนบันทึกใบเบิก
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';


/* ---------- ท่อน WHERE สำหรับกรองสินค้าตามหน่วยงาน ----------
 * คืนข้อความ SQL ที่เอาไปต่อใน WHERE ของคำสั่งที่มีตาราง tb_product เป็น p
 * รับ department_id ที่แปลงเป็น (int) แล้วเท่านั้น จึงต่อเข้าไปตรง ๆ ได้
 *
 * เงื่อนไขอ่านว่า "สินค้าตัวนี้ผูกกับหน่วยงานที่ถาม หรือไม่ได้ผูกกับใครเลย"
 */
function product_dept_where($department_id)
{
    $department_id = (int)$department_id;
    if ($department_id <= 0) { return ''; }

    return "(EXISTS (SELECT 1 FROM `tb_product_department` pd
                     WHERE pd.`product_id` = p.`product_id`
                       AND pd.`department_id` = " . $department_id . ")
             OR NOT EXISTS (SELECT 1 FROM `tb_product_department` pd2
                            WHERE pd2.`product_id` = p.`product_id`))";
}


/* ---------- หน่วยงานนี้เบิกสินค้าตัวนี้ได้ไหม ----------
 * ใช้ตอนบันทึกใบเบิก ซึ่งต้องตรวจอีกครั้งที่ฝั่งเซิร์ฟเวอร์เสมอ
 * การกรองที่หน้าจอเป็นเรื่องความสะดวก ไม่ใช่การบังคับ — คนที่ยิง JSON
 * ตรงเข้ามาข้ามหน้าจอไปเลยก็ต้องโดนกันที่นี่
 */
function product_dept_allows($product_id, $department_id)
{
    $product_id    = (int)$product_id;
    $department_id = (int)$department_id;

    if ($product_id <= 0)    { return false; }
    if ($department_id <= 0) { return true; }   // ไม่ระบุหน่วยงาน = ไม่จำกัด

    $connect = db_connect();

    /* นับสองอย่างในคำสั่งเดียว: ผูกกับหน่วยงานนี้กี่แถว และผูกกับใครก็ได้กี่แถว
       ถ้าไม่ผูกกับใครเลย (ทั้งหมด = 0) แปลว่าเป็นของกลาง เบิกได้ */
    $stmt = mysqli_prepare($connect,
        "SELECT
            SUM(CASE WHEN `department_id` = ? THEN 1 ELSE 0 END) AS `mine`,
            COUNT(*) AS `total`
         FROM `tb_product_department` WHERE `product_id` = ?");
    if (!$stmt) { return true; }     // ตารางยังไม่มี = ยังไม่ได้เปิดใช้ฟีเจอร์นี้

    mysqli_stmt_bind_param($stmt, 'ii', $department_id, $product_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $mine, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return ((int)$total === 0 || (int)$mine > 0);
}


/* ---------- รหัสสินค้าที่หน่วยงานหนึ่งถูกผูกไว้ ----------
 * คืนเฉพาะที่ผูกไว้จริง ไม่รวมของกลาง — หน้าตั้งค่าต้องรู้ว่า
 * "ติ๊กอะไรไว้บ้าง" ไม่ใช่ "เบิกอะไรได้บ้าง" ซึ่งเป็นคนละคำถาม
 */
function product_dept_ids($department_id)
{
    $department_id = (int)$department_id;
    $out = array();
    if ($department_id <= 0) { return $out; }

    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT `product_id` FROM `tb_product_department`
         WHERE `department_id` = ? ORDER BY `product_id` ASC");
    if (!$stmt) { return $out; }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pid);

    while (mysqli_stmt_fetch($stmt)) { $out[] = (int)$pid; }
    mysqli_stmt_close($stmt);

    return $out;
}


/* ---------- จำนวนสินค้าที่ผูกไว้ แยกตามหน่วยงาน ----------
 * ใช้แสดงตัวเลขข้างชื่อหน่วยงานในช่องเลือก จะได้รู้ว่าอันไหนตั้งค่าไว้แล้ว
 * โดยไม่ต้องกดเข้าไปดูทีละหน่วยงาน
 */
function product_dept_counts()
{
    $connect = db_connect();
    $out     = array();

    $result = @mysqli_query($connect,
        "SELECT `department_id`, COUNT(*) AS `n`
         FROM `tb_product_department` GROUP BY `department_id`");
    if (!$result) { return $out; }

    while ($row = mysqli_fetch_assoc($result)) {
        $out[(int)$row['department_id']] = (int)$row['n'];
    }
    return $out;
}


/* ---------- หน่วยงานที่ผูกกับสินค้าแต่ละตัว ----------
 * คืน array: product_id => array(department_id, ...)
 * หน้าทะเบียนสินค้าเอาไปแสดงว่าตัวนี้เป็นของฝ่ายไหนบ้าง
 */
function product_dept_map()
{
    $connect = db_connect();
    $out     = array();

    $result = @mysqli_query($connect,
        "SELECT `product_id`, `department_id` FROM `tb_product_department`
         ORDER BY `product_id` ASC, `department_id` ASC");
    if (!$result) { return $out; }

    while ($row = mysqli_fetch_assoc($result)) {
        $pid = (int)$row['product_id'];
        if (!isset($out[$pid])) { $out[$pid] = array(); }
        $out[$pid][] = (int)$row['department_id'];
    }
    return $out;
}


/* ---------- บันทึกรายการสินค้าของหน่วยงานหนึ่ง ----------
 * $product_ids = รายการทั้งหมดที่ต้องการให้หน่วยงานนี้เบิกได้ (แทนที่ของเดิมทั้งชุด)
 *
 * ---------------------------------------------------------------
 * ทำไมลบทิ้งแล้วเขียนใหม่ ไม่ไล่เทียบว่าอะไรเพิ่มอะไรลด
 * ---------------------------------------------------------------
 * หน้าจอส่ง "สถานะสุดท้ายที่ต้องการ" มาให้ ไม่ได้ส่งว่าผู้ใช้กดอะไรไปบ้าง
 * การไล่เทียบต้องเดาเจตนาจากผลต่าง ซึ่งพลาดได้ถ้าสองคนแก้พร้อมกัน
 * ลบแล้วเขียนใหม่ในธุรกรรมเดียวได้ผลลัพธ์ที่ตรงกับที่เห็นบนจอเสมอ
 *
 * ตารางนี้เล็ก (สินค้าไม่กี่ร้อยตัวต่อหน่วยงาน) การเขียนใหม่ทั้งชุดจึงถูกกว่า
 * ความซับซ้อนของการไล่เทียบมาก
 */
function product_dept_save($department_id, $product_ids, $actor)
{
    $department_id = (int)$department_id;
    if ($department_id <= 0) {
        return db_fail('กรุณาเลือกหน่วยงาน');
    }
    if (!is_array($product_ids)) {
        $product_ids = array();
    }

    /* กรองให้เหลือแต่เลขที่ใช้ได้ และตัดตัวซ้ำออก
       หน้าจอไม่ควรส่งตัวซ้ำมาอยู่แล้ว แต่ถ้าส่งมา INSERT จะชนกุญแจหลัก
       แล้วทั้งชุดล้มทั้งที่เจตนาชัดเจนว่าต้องการตัวนั้นแค่ครั้งเดียว */
    $clean = array();
    for ($i = 0; $i < count($product_ids); $i++) {
        $pid = (int)$product_ids[$i];
        if ($pid > 0) { $clean[$pid] = true; }
    }
    $ids = array_keys($clean);

    $connect = db_connect();
    db_begin();

    $stmt = mysqli_prepare($connect,
        "DELETE FROM `tb_product_department` WHERE `department_id` = ?");
    if (!$stmt) { db_rollback(); return db_fail('บันทึกไม่สำเร็จ'); }

    mysqli_stmt_bind_param($stmt, 'i', $department_id);
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);
        db_rollback();
        return db_fail(db_error_message($errno), $errno);
    }
    mysqli_stmt_close($stmt);

    if (count($ids) > 0) {

        $stmt = mysqli_prepare($connect,
            "INSERT INTO `tb_product_department`
                (`product_id`, `department_id`, `created_at`, `created_by`)
             VALUES (?, ?, NOW(), ?)");
        if (!$stmt) { db_rollback(); return db_fail('บันทึกไม่สำเร็จ'); }

        for ($i = 0; $i < count($ids); $i++) {
            $pid = $ids[$i];
            mysqli_stmt_bind_param($stmt, 'iis', $pid, $department_id, $actor);

            if (!mysqli_stmt_execute($stmt)) {
                $errno = mysqli_stmt_errno($stmt);
                mysqli_stmt_close($stmt);
                db_rollback();

                /* 1452 = ชี้ไปที่สินค้าที่ไม่มีอยู่จริง เกิดได้ถ้ามีคนลบสินค้า
                   ระหว่างที่อีกคนเปิดหน้านี้ค้างไว้ */
                if ($errno === 1452) {
                    return db_fail('มีสินค้าบางรายการถูกลบไปแล้ว กรุณารีเฟรชหน้าแล้วเลือกใหม่');
                }
                return db_fail(db_error_message($errno), $errno);
            }
        }
        mysqli_stmt_close($stmt);
    }

    db_commit();

    if (count($ids) === 0) {
        return db_ok('ล้างรายการสินค้าของหน่วยงานนี้แล้ว '
                   . '— ตอนนี้หน่วยงานนี้จะเห็นเฉพาะสินค้าที่เป็นของกลาง', 0);
    }
    return db_ok('บันทึกสินค้าของหน่วยงานนี้ ' . count($ids) . ' รายการเรียบร้อย', count($ids));
}
