<?php
/* ============================================================
 *  api/product_dept.php — สินค้าที่แต่ละหน่วยงานเบิกได้
 *
 *    { "action": "bootstrap" }
 *        รายชื่อหน่วยงาน (พร้อมจำนวนที่ผูกไว้แล้ว) + สินค้าทั้งหมด + หมวดหมู่
 *
 *    { "action": "get",  "department_id": 4 }
 *        รหัสสินค้าที่หน่วยงานนี้ถูกผูกไว้
 *
 *    { "action": "save", "department_id": 4, "product_ids": [1,2,3], "csrf_token": "..." }
 *        เขียนทับรายการทั้งชุดของหน่วยงานนั้น
 *
 *  เฉพาะผู้ดูแลระบบ — เป็นการตั้งค่าว่าใครเห็นอะไร ซึ่งกระทบทั้งองค์กร
 *  ไม่มี SQL ในไฟล์นี้ เรียกฟังก์ชันจาก backend/ ทั้งหมด
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/product_department.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';
require_once dirname(dirname(__FILE__)) . '/backend/stock.php';
require_once dirname(dirname(__FILE__)) . '/backend/category.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


if ($action === 'bootstrap') {

    /* หน่วยงานที่ปิดใช้งานไปแล้วไม่ต้องเอามาให้ตั้งค่า
       เพราะทำใบเบิกในนามหน่วยงานนั้นไม่ได้อยู่แล้ว */
    $departments = department_list(array('is_active' => 1));
    $counts      = product_dept_counts();

    /* ติดจำนวนที่ผูกไว้ไปกับหน่วยงานเลย จะได้รู้ว่าอันไหนตั้งค่าแล้ว
       โดยไม่ต้องกดเข้าไปดูทีละหน่วยงาน (มี 62 หน่วยงาน) */
    for ($i = 0; $i < count($departments); $i++) {
        $did = (int)$departments[$i]['department_id'];
        $departments[$i]['picked'] = isset($counts[$did]) ? $counts[$did] : 0;
    }

    /* ชื่อหน่วยงานแบบค้นเร็ว ใช้บอกว่าสินค้าตัวนี้ถูกฝ่ายไหนจองไว้แล้วบ้าง
     *
     * ส่งไปสองชุด: ชื่อสั้นสำหรับป้ายในรายการซึ่งมีที่จำกัด
     * กับป้ายเต็มที่มีชื่อบริษัทสำหรับข้อความตอนชี้ค้าง
     *
     * เพราะ 11 ชื่อจาก 62 หน่วยงานซ้ำกันข้ามบริษัท ป้ายที่เขียนว่า
     * "ใช้ร่วมกับ จัดซื้อ" จึงตอบไม่ได้ว่าจัดซื้อของบริษัทไหน
     * ป้ายสั้นไว้กวาดตา ส่วนคำตอบที่แน่นอนอยู่ในข้อความตอนชี้ค้าง
     */
    $names  = array();
    $labels = array();
    for ($i = 0; $i < count($departments); $i++) {
        $did = (int)$departments[$i]['department_id'];
        $names[$did]  = $departments[$i]['department_name'];
        $labels[$did] = $departments[$i]['label'];
    }

    api_ok(array(
        'departments' => $departments,
        /* ส่งสินค้าทั้งหมดที่ยังเปิดใช้งาน ไม่กรองตามหน่วยงาน
           เพราะหน้านี้คือที่ที่ใช้ "เลือก" ว่าจะให้เห็นอะไร ต้องเห็นครบก่อน */
        'products'    => stock_list(array('only_active' => true)),
        'categories'  => category_options(true),

        /* ---------- สินค้าตัวไหนถูกหน่วยงานใดจองไว้แล้วบ้าง ----------
         * ข้อมูลชิ้นนี้เปลี่ยนหน้าจอจาก "ติ๊กถูก/ไม่ติ๊ก" เป็นการตัดสินใจที่มีข้อมูลครบ
         *
         * ถ้าไม่มี ผู้ดูแลระบบจะไม่มีทางรู้ว่ารายการที่กำลังจะติ๊กเป็นของกลาง
         * หรือเป็นของที่ฝ่ายอื่นกันไว้แล้ว ซึ่งเป็นคนละเรื่องกันโดยสิ้นเชิง:
         * ติ๊กของกลาง = ตัดสิทธิ์ฝ่ายอื่นทั้งบริษัทที่เคยเบิกได้
         * ติ๊กของที่ถูกจองแล้ว = แค่เพิ่มฝ่ายตัวเองเข้าไปในรายชื่อ
         *
         * 313 รายการที่ส่วนใหญ่ยังไม่ถูกจอง ทำให้ก้อนนี้เล็กมาก */
        'map'         => product_dept_map(),
        'deptNames'   => $names,
        'deptLabels'  => $labels
    ));
}


if ($action === 'get') {

    $dept = isset($input['department_id']) ? (int)$input['department_id'] : 0;
    if ($dept <= 0) { api_fail('กรุณาเลือกหน่วยงาน'); }

    api_ok(array(
        'departmentId' => $dept,
        'productIds'   => product_dept_ids($dept)
    ));
}


if ($action === 'save') {

    api_require_csrf($input);

    $ids = isset($input['product_ids']) && is_array($input['product_ids'])
           ? $input['product_ids'] : array();

    $res = product_dept_save(
        isset($input['department_id']) ? (int)$input['department_id'] : 0,
        $ids, $actor
    );

    if (!$res['ok']) { api_fail($res['message']); }

    api_ok(array('message' => $res['message'], 'picked' => $res['id']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
