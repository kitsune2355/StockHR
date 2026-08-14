<?php
/* ============================================================
 *  api/catalog.php — endpoint ของหน้าข้อมูลสินค้า
 *                    (หมวดหมู่หลัก / หมวดหมู่ย่อย / หน่วยนับ / รายการสินค้า)
 *
 *  ทุกคำสั่งส่งมาแบบ POST พร้อม body เป็น JSON
 *
 *  1) ขอข้อมูลทั้งหมดมาลงตาราง
 *     { "action": "list" }
 *     -> { ok, categories, subCategories, units, products, unitOptions, subOptions }
 *
 *  2) บันทึกการแก้ไขทั้งชุด
 *     {
 *       "action": "save",
 *       "csrf_token": "...",
 *       "tab": "category" | "sub_category" | "unit" | "product",
 *       "creates": [ {...} ],
 *       "updates": [ {id, ...} ],
 *       "deletes": [ id, ... ]
 *     }
 *     -> { ok, saved, failed, errors: [ {id, action, label, message} ] }
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/ ทั้งหมด
 *  จึงได้กติกาตรวจข้อมูลชุดเดียวกับหน้าเว็บโดยอัตโนมัติ
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/category.php';
require_once dirname(dirname(__FILE__)) . '/backend/sub_category.php';
require_once dirname(dirname(__FILE__)) . '/backend/product.php';
require_once dirname(dirname(__FILE__)) . '/backend/unit.php';

api_require_post();
$actor = api_require_login();
api_require_role(array('super_admin', 'admin'));

$input  = api_input();
$action = isset($input['action']) ? $input['action'] : '';


/* ============================================================
 *  ขอข้อมูลทั้งหมด
 *
 *  ส่งทั้งก้อนเพราะตารางฝั่งหน้าเว็บค้นหา เรียงลำดับ และแบ่งหน้าเอง
 *  ตอนนี้สินค้ามี 313 รายการ ส่งทีเดียวยังเบามาก
 *  ถ้าวันหนึ่งแตะหลักหมื่น ค่อยเปลี่ยนไปโหลดทีละหน้าจากเซิร์ฟเวอร์
 * ============================================================ */
if ($action === 'list') {

    api_ok(array(
        'categories'    => category_list(),
        'subCategories' => sub_category_list(),
        'units'         => unit_list(),
        'products'      => product_list(),
        'catOptions'    => category_options(),
        'subOptions'    => sub_category_options(),
        'unitOptions'   => unit_options()
    ));
}


/* ============================================================
 *  บันทึกทั้งชุด
 * ============================================================ */
if ($action === 'save') {

    api_require_csrf($input);

    $tab     = isset($input['tab']) ? $input['tab'] : '';
    $creates = isset($input['creates']) && is_array($input['creates']) ? $input['creates'] : array();
    $updates = isset($input['updates']) && is_array($input['updates']) ? $input['updates'] : array();
    $deletes = isset($input['deletes']) && is_array($input['deletes']) ? $input['deletes'] : array();

    $tabs = array('category', 'sub_category', 'unit', 'product');
    if (!in_array($tab, $tabs)) {
        api_fail('ไม่รู้จักตารางที่ส่งมา');
    }

    $saved  = 0;
    $errors = array();

    /* ---------- ลบก่อน ----------
     * ทำก่อนเพราะถ้าผู้ใช้ลบสินค้าแล้วลบหมวดหมู่ย่อยของสินค้านั้นในครั้งเดียวกัน
     * ต้องให้สินค้าหายไปก่อน หมวดหมู่ถึงจะลบผ่าน FK ได้
     */
    foreach ($deletes as $id) {
        $id = (int)$id;
        if ($id <= 0) { continue; }

        if ($tab === 'category')          { $res = category_delete($id); }
        elseif ($tab === 'sub_category')  { $res = sub_category_delete($id); }
        elseif ($tab === 'unit')          { $res = unit_delete($id); }
        else                              { $res = product_delete($id); }

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => $id, 'action' => 'delete', 'message' => $res['message']);
        }
    }

    /* ---------- แก้ของเดิม ---------- */
    foreach ($updates as $row) {

        if ($tab === 'category') {
            $id  = isset($row['category_id']) ? (int)$row['category_id'] : 0;
            $res = category_update($id, array(
                'category_code' => isset($row['category_code']) ? $row['category_code'] : '',
                'category_name' => isset($row['category_name']) ? $row['category_name'] : '',
                'is_active'     => isset($row['is_active']) ? (int)$row['is_active'] : 0
            ), $actor);
            $label = isset($row['category_name']) ? $row['category_name'] : ('#' . $id);

        } elseif ($tab === 'sub_category') {
            $id  = isset($row['sub_category_id']) ? (int)$row['sub_category_id'] : 0;
            $res = sub_category_update($id, array(
                'category_id'       => isset($row['category_id']) ? (int)$row['category_id'] : 0,
                'sub_category_code' => isset($row['sub_category_code']) ? $row['sub_category_code'] : '',
                'sub_category_name' => isset($row['sub_category_name']) ? $row['sub_category_name'] : '',
                'is_active'         => isset($row['is_active']) ? (int)$row['is_active'] : 0
            ), $actor);
            $label = isset($row['sub_category_name']) ? $row['sub_category_name'] : ('#' . $id);

        } elseif ($tab === 'unit') {
            $id  = isset($row['unit_id']) ? (int)$row['unit_id'] : 0;
            $res = unit_update($id, array(
                'unit_name' => isset($row['unit_name']) ? $row['unit_name'] : '',
                'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 0
            ), $actor);
            $label = isset($row['unit_name']) ? $row['unit_name'] : ('#' . $id);

        } else {
            $id  = isset($row['product_id']) ? (int)$row['product_id'] : 0;
            $res = product_update($id, catalog_product_input($row, $actor), $actor);
            $label = isset($row['product_name']) ? $row['product_name'] : ('#' . $id);
        }

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => $id, 'action' => 'update', 'label' => $label, 'message' => $res['message']);
        }
    }

    /* ---------- เพิ่มแถวใหม่ ---------- */
    foreach ($creates as $row) {

        if ($tab === 'category') {
            $res = category_create(array(
                'category_code' => isset($row['category_code']) ? $row['category_code'] : '',
                'category_name' => isset($row['category_name']) ? $row['category_name'] : '',
                'is_active'     => isset($row['is_active']) ? (int)$row['is_active'] : 1
            ), $actor);
            $label = isset($row['category_name']) ? $row['category_name'] : '(แถวใหม่)';

        } elseif ($tab === 'sub_category') {
            $res = sub_category_create(array(
                'category_id'       => isset($row['category_id']) ? (int)$row['category_id'] : 0,
                'sub_category_code' => isset($row['sub_category_code']) ? $row['sub_category_code'] : '',
                'sub_category_name' => isset($row['sub_category_name']) ? $row['sub_category_name'] : '',
                'is_active'         => isset($row['is_active']) ? (int)$row['is_active'] : 1
            ), $actor);
            $label = isset($row['sub_category_name']) ? $row['sub_category_name'] : '(แถวใหม่)';

        } elseif ($tab === 'unit') {
            $res = unit_create(array(
                'unit_name' => isset($row['unit_name']) ? $row['unit_name'] : '',
                'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1
            ), $actor);
            $label = isset($row['unit_name']) ? $row['unit_name'] : '(แถวใหม่)';

        } else {
            $res = product_create(catalog_product_input($row, $actor, 1), $actor);
            $label = isset($row['product_name']) ? $row['product_name'] : '(แถวใหม่)';
        }

        if ($res['ok']) {
            $saved++;
        } else {
            $errors[] = array('id' => 0, 'action' => 'create', 'label' => $label, 'message' => $res['message']);
        }
    }

    api_ok(array(
        'saved'  => $saved,
        'failed' => count($errors),
        'errors' => $errors
    ));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');


/* ---------- แปลงแถวสินค้าจากตารางหน้าเว็บให้เป็นค่าที่ backend รับ ----------
 * แยกออกมาเพราะทั้งตอนเพิ่มและตอนแก้ใช้ชุดเดียวกัน
 *
 * unit_name: ถ้าหน้าเว็บส่งชื่อหน่วยที่ยังไม่มีในระบบมา ให้สร้างให้เลย
 * ผู้ใช้จึงไม่ต้องออกไปเพิ่มหน่วยนับที่หน้าอื่นก่อนแล้วค่อยกลับมา
 */
function catalog_product_input($row, $actor, $default_active = 0)
{
    $unit_id = isset($row['unit_id']) ? (int)$row['unit_id'] : 0;

    if ($unit_id <= 0 && isset($row['unit_name']) && trim($row['unit_name']) !== '') {
        $unit_id = unit_ensure($row['unit_name'], $actor);
    }

    return array(
        'product_name'    => isset($row['product_name'])    ? $row['product_name']         : '',
        'old_code'        => isset($row['old_code'])        ? $row['old_code']             : '',
        'sub_category_id' => isset($row['sub_category_id']) ? (int)$row['sub_category_id'] : 0,
        'unit_id'         => $unit_id,
        'unit_cost'       => isset($row['unit_cost'])       ? $row['unit_cost']            : 0,
        'min_qty'         => isset($row['min_qty'])         ? $row['min_qty']              : 0,
        'max_qty'         => isset($row['max_qty'])         ? $row['max_qty']              : 0,
        'is_active'       => isset($row['is_active'])       ? (int)$row['is_active']       : $default_active
    );
}
