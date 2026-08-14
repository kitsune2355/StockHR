<?php
/* ============================================================
 *  includes/menu.php
 *  นิยามเมนูและ route ของทั้งระบบ — แหล่งความจริงแหล่งเดียว
 *
 *  ทั้งแถบเมนูด้านซ้าย และตัว router ใน main.php อ่านจากไฟล์นี้ไฟล์เดียว
 *
 *  โครงสร้าง:
 *    section  = หัวข้อกลุ่มบนเมนู (OVERVIEW / ACCOUNT ตามภาพต้นแบบ)
 *      item   = รายการเมนู 1 บรรทัด
 *        - ถ้ามี 'file'     = เป็นหน้าจริง กดแล้วเปิดหน้านั้น
 *        - ถ้ามี 'children' = เป็นหัวข้อพับเก็บได้ กดแล้วกางเมนูย่อย (ไม่มีหน้าของตัวเอง)
 *        - ถ้ามี 'url'      = ลิงก์ออกนอก router เช่น ออกจากระบบ
 *
 *  คีย์ที่ใส่ได้ในแต่ละ item:
 *    key    ค่าที่ส่งมาทาง ?page=...  (ต้องไม่ซ้ำทั้งระบบ)
 *    title  ชื่อที่แสดง
 *    icon   ชื่อไอคอน Lucide
 *    roles  role ที่เข้าถึงได้
 *    file   ไฟล์เนื้อหาที่ main.php จะ include
 *    badge  ตัวเลขแจ้งเตือนบนเมนู (ไม่ใส่ = ไม่แสดง) ดูวิธีตั้งค่าที่ app_set_badge()
 *    tone   สีของ badge : 'brand' (ค่าเริ่มต้น) | 'red' | 'amber'
 *    full   true = หน้านี้ขอคุมพื้นที่เลื่อนเอง (กรอบเนื้อหาสูงคงที่ ไม่เลื่อน)
 *           เหมาะกับหน้าตารางที่อยากตรึงแถบเครื่องมือกับแถบแบ่งหน้าไว้
 *           ไม่ใส่ = หน้าเลื่อนได้ทั้งก้อนตามปกติ
 *
 *  เพิ่มหน้าใหม่ = เพิ่ม 1 รายการที่นี่ แล้วสร้างไฟล์ตาม 'file'
 *  เขียนให้รองรับ PHP 5.2.6 (ใช้ array() ไม่ใช้ [])
 * ============================================================ */

/* กลุ่มสิทธิ์ที่ใช้กำหนดว่าใครเห็นเมนูไหน
     $ROLE_ALL   ทุกคนที่ล็อกอินได้
     $ROLE_STAFF คนที่ทำงานกับสต๊อกจริง (รับเข้า จ่ายออก ตรวจนับ)
     $ROLE_ADMIN คนที่ตั้งค่าระบบได้ */
$ROLE_ALL   = array('super_admin', 'admin', 'approver', 'general');
$ROLE_STAFF = array('super_admin', 'admin', 'approver');
$ROLE_ADMIN = array('super_admin', 'admin');

$APP_NAV = array(

    array(
        'section' => 'ภาพรวม',
        'items'   => array(

            array('key' => 'dashboard', 'title' => 'ภาพรวมระบบ', 'icon' => 'layout-dashboard',
                  'roles' => $ROLE_ALL, 'file' => 'pages/dashboard.php'),

            /* ทะเบียนสินค้าเป็นตารางแก้ในช่องได้ ยังไม่มีโหมดดูอย่างเดียว
               จึงจำกัดไว้ที่ $ROLE_ADMIN ให้ตรงกับสิทธิ์ที่ api/catalog.php บังคับ
               ไม่งั้นคนทั่วไปจะเห็นเมนู เปิดหน้าได้ แต่พอกดบันทึกจะโดนปฏิเสธ
               ถ้าวันหลังอยากให้ทุกคนดูได้ ค่อยทำหน้าดูอย่างเดียวเพิ่ม */
            array('key' => 'products', 'title' => 'ข้อมูลสินค้า', 'icon' => 'package',
                  'roles' => $ROLE_ADMIN, 'children' => array(
                array('key' => 'product_list',     'title' => 'รายการสินค้า',  'roles' => $ROLE_ADMIN, 'file' => 'pages/product_list.php',     'full' => true),
                /* หน้าเดียวสามแท็บ: หมวดหมู่หลัก / หมวดหมู่ย่อย / หน่วยนับ
                   หน่วยนับมาอยู่รวมที่นี่เพราะเป็นทะเบียนตั้งต้นของสินค้าเหมือนกัน
                   และเล็กเกินกว่าจะกินเมนูของตัวเองหนึ่งบรรทัด */
                array('key' => 'product_category', 'title' => 'หมวดหมู่และหน่วยนับ', 'roles' => $ROLE_ADMIN, 'file' => 'pages/product_category.php', 'full' => true),

                /* กำหนดว่าหน่วยงานไหนเบิกสินค้าตัวใดได้บ้าง
                   สินค้าที่ไม่ได้ผูกกับหน่วยงานใดเลย = ของกลาง ทุกหน่วยงานเบิกได้
                   (ดู backend/product_department.php) */
                array('key' => 'product_dept',     'title' => 'สินค้าของหน่วยงาน', 'roles' => $ROLE_ADMIN, 'file' => 'pages/product_dept.php', 'full' => true),

                /* สร้างฉลากบาร์โค้ดจากรหัสสินค้าที่มีอยู่แล้ว ไม่ได้เขียนอะไรลงฐานข้อมูล
                   ตั้ง 'full' เพราะแผงตั้งค่าต้องตรึงอยู่ข้างจอตลอด และแผ่นตัวอย่าง
                   ต้องอยู่บนจอพร้อมกับแผงตั้งค่า จะได้เห็นผลทันทีที่ปรับค่า
                   แต่ละกล่องเลื่อนในตัวเอง (ดู .bc-shell ใน app.css) */
                array('key' => 'product_barcode',  'title' => 'บาร์โค้ดและคิวอาร์โค้ด', 'roles' => $ROLE_ADMIN, 'file' => 'pages/product_barcode.php', 'full' => true),
            )),

            array('key' => 'receive', 'title' => 'รับสินค้าเข้า', 'icon' => 'package-plus',
                  'roles' => $ROLE_STAFF, 'children' => array(
                array('key' => 'receive_new',     'title' => 'บันทึกรับเข้า',    'roles' => $ROLE_STAFF, 'file' => 'pages/receive_new.php',     'full' => true),
                array('key' => 'receive_history', 'title' => 'ประวัติการรับเข้า', 'roles' => $ROLE_STAFF, 'file' => 'pages/receive_history.php', 'full' => true),
            )),

            /* เบิกสินค้าเปิดให้ทุกสิทธิ์ เพราะทุกแผนกต้องเบิกของใช้เองได้
               ส่วนการอนุมัติกับการดูใบของคนอื่น จำกัดอยู่ในหน้าเดียวกันนี้แล้ว
               (api/documents.php บังคับให้ผู้ใช้ทั่วไปเห็นเฉพาะใบของตัวเอง) */
            array('key' => 'request', 'title' => 'เบิกสินค้า', 'icon' => 'clipboard-list',
                  'roles' => $ROLE_ALL, 'children' => array(
                array('key' => 'request_new',  'title' => 'ทำรายการเบิก', 'roles' => $ROLE_ALL, 'file' => 'pages/request_new.php',  'full' => true),
                array('key' => 'request_list', 'title' => 'รายการใบเบิก', 'roles' => $ROLE_ALL, 'file' => 'pages/request_list.php', 'full' => true),
            )),

            array('key' => 'issue', 'title' => 'จ่ายสินค้าออก', 'icon' => 'package-minus',
                  'roles' => $ROLE_STAFF, 'children' => array(
                array('key' => 'issue_new',     'title' => 'บันทึกจ่ายออก',    'roles' => $ROLE_STAFF, 'file' => 'pages/issue_new.php',     'full' => true),
                /* ผู้เบิกต้องเข้าหน้านี้ได้ เพราะเป็นที่ที่กด "เซ็นรับของ" */
                array('key' => 'issue_history', 'title' => 'ประวัติการจ่ายออก', 'roles' => $ROLE_ALL,   'file' => 'pages/issue_history.php', 'full' => true),
            )),

            array('key' => 'reports', 'title' => 'รายงาน', 'icon' => 'bar-chart-3',
                  'roles' => $ROLE_ALL, 'children' => array(
                array('key' => 'report_stock',    'title' => 'ยอดคงเหลือ',    'roles' => $ROLE_ALL, 'file' => 'pages/report_stock.php',    'full' => true),
                array('key' => 'report_movement', 'title' => 'ความเคลื่อนไหว', 'roles' => $ROLE_ALL, 'file' => 'pages/report_movement.php', 'full' => true),
                array('key' => 'report_low',      'title' => 'ต่ำกว่าจุดสั่งซื้อ', 'roles' => $ROLE_ALL, 'file' => 'pages/report_low.php',      'full' => true),

                /* รายงานค่าใช้จ่ายมีตัวเลขเงินของทั้งองค์กร จำกัดเฉพาะผู้ดูแลระบบ
                   ต่างจากรายงานอื่นที่มีแค่จำนวน ซึ่งทุกคนดูได้ */
                array('key' => 'report_cost',     'title' => 'ค่าใช้จ่ายรายเดือน', 'roles' => $ROLE_ADMIN, 'file' => 'pages/report_cost.php'),
            )),
        )
    ),

    array(
        'section' => 'ตั้งค่าระบบ',
        'items'   => array(

            /* จับกลุ่มตาม "ใครทำและทำตอนไหน" — ผู้ใช้และสิทธิ์ = งานตอนรับคนเข้าใหม่
               ตั้งค่าระบบ = งานติดตั้งครั้งเดียว
               การแจ้งเตือนกับเปลี่ยนรหัสผ่านอยู่เป็นบรรทัดเดี่ยว เพราะเป็นของส่วนตัว
               และผู้ใช้ทั่วไปเห็นแค่สองอันนี้ทั้งหมวด ถ้าซ่อนในกลุ่มจะต้องกดกางก่อน */
            array('key' => 'accounts', 'title' => 'ผู้ใช้และสิทธิ์', 'icon' => 'users',
                  'roles' => $ROLE_ADMIN, 'children' => array(
                array('key' => 'users',       'title' => 'ผู้ใช้งาน',       'roles' => $ROLE_ADMIN,              'file' => 'pages/users.php',       'full' => true),
                array('key' => 'agencies',    'title' => 'หน่วยงาน',        'roles' => array('super_admin'),     'file' => 'pages/agencies.php',    'full' => true),
                array('key' => 'permissions', 'title' => 'สิทธิ์การใช้งาน', 'roles' => array('super_admin'),     'file' => 'pages/permissions.php', 'full' => true),
            )),

            /* เดิมสองเรื่องนี้เป็นแท็บอยู่ในหน้า "ตั้งค่าระบบ" หน้าเดียว
               แยกออกมาเป็นสองเมนูเพราะเป็นคนละงานที่คนละคนเข้ามาทำคนละเวลา
               ตั้งค่าอีเมลทำครั้งเดียวตอนติดตั้ง ส่วนคู่มือแก้เรื่อย ๆ ตามที่ระบบเปลี่ยน
               การซ่อนไว้หลังแท็บทำให้คนที่อยากแก้คู่มือต้องรู้ก่อนว่ามันอยู่ในหน้าตั้งค่า
               ซึ่งเดาไม่ได้จากชื่อเมนู

               ทั้งคู่ยังใช้ไฟล์เดียวกัน เพราะโครงหน้าและสคริปต์ใช้ร่วมกันอยู่แล้ว
               ตัวหน้าดูจาก $ACTIVE_PAGE ว่าจะแสดงส่วนไหน (ดู pages/settings.php)

               มีรหัสผ่านแอปของ Zoho อยู่ จึงจำกัดที่ $ROLE_ADMIN ให้ตรงกับ
               สิทธิ์ที่ api/settings.php บังคับ ไม่งั้นจะเห็นเมนูแต่กดเข้าไปแล้วโดนปฏิเสธ
               'full' => true เพราะรายการขั้นตอนคู่มือยาว ต้องเลื่อนในกรอบของตัวเอง */
            /* ห้ามตั้งชื่อซ้ำกับชื่อหมวด ("ตั้งค่าระบบ") ที่ครอบมันอยู่
               นอกจากอ่านแล้วซ้ำซ้อนบนแถบเมนู ยังไปทำให้หน้าสิทธิ์การใช้งาน
               ยุบกลุ่มผิด เพราะที่นั่นจัดกลุ่มด้วยชื่อ ไม่ใช่ด้วยคีย์
               (แก้ที่ groupOf ใน permissions.js แล้ว แต่ชื่อซ้ำก็ยังไม่ควรอยู่ดี) */
            array('key' => 'setup', 'title' => 'อีเมลและคู่มือ', 'icon' => 'mail',
                  'roles' => $ROLE_ADMIN, 'children' => array(
                array('key' => 'settings',       'title' => 'อีเมลแจ้งเตือน',  'icon' => 'mail',        'roles' => $ROLE_ADMIN, 'file' => 'pages/settings.php', 'full' => true),
                array('key' => 'settings_guide', 'title' => 'คู่มือการใช้งาน', 'icon' => 'circle-help', 'roles' => $ROLE_ADMIN, 'file' => 'pages/settings.php', 'full' => true),
            )),

            /* กล่องกระดิ่งเลื่อนดูของเก่าได้ก็จริง แต่ค้นไม่ได้ กรองไม่ได้
               และสูงแค่ครึ่งจอ คนที่ตามหาเรื่องเมื่อสองสัปดาห์ก่อนต้องมีที่ที่กว้างกว่านั้น
               ทุกสิทธิ์เข้าได้ เพราะแต่ละคนเห็นเฉพาะแจ้งเตือนของตัวเองอยู่แล้ว */
            array('key' => 'notifications', 'title' => 'การแจ้งเตือน', 'icon' => 'bell',
                  'roles' => $ROLE_ALL, 'file' => 'pages/notifications.php', 'full' => true),

            /* ทุกคนต้องเปลี่ยนรหัสผ่านของตัวเองได้ เพราะบัญชีใหม่ได้รหัสตั้งต้นเหมือนกันหมด
               ถ้าไม่มีหน้านี้ ทุกคนจะติดอยู่กับรหัสตั้งต้นตลอดไป */
            array('key' => 'change_password', 'title' => 'เปลี่ยนรหัสผ่าน', 'icon' => 'key-round',
                  'roles' => $ROLE_ALL, 'file' => 'pages/change_password.php'),

            /* หมายเหตุ: "ออกจากระบบ" ไม่ได้อยู่ในรายการนี้
               แต่ถูกตรึงไว้ท้ายเมนูใน includes/layout_top.php เพื่อให้อยู่ตำแหน่งเดิมเสมอ
               ไม่ว่ารายการเมนูจะยาวแค่ไหน และไม่เลื่อนหายไปกับ scroll */
        )
    ),
);

/* ============================================================
 *  ทับสิทธิ์ด้วยค่าที่ตั้งไว้จากหน้า "สิทธิ์การใช้งาน"
 *
 *  ค่าใน $APP_NAV ด้านบนเป็น "ค่าตั้งต้น" ที่ใช้เมื่อยังไม่เคยตั้งค่าจากหน้าเว็บ
 *  เมนูไหนมีแถวในตาราง tb_menu_permission แล้ว ให้ใช้ค่าจากตารางแทน
 *
 *  super_admin ติดมาให้เสมอไม่ว่าตารางจะว่าอย่างไร — กันเผลอปิดสิทธิ์ตัวเอง
 *  แล้วไม่มีใครเข้าไปแก้คืนได้อีก
 *
 *  ทำตรงนี้ที่เดียว ทั้งแถบเมนูและตัว router ใน main.php จึงเห็นค่าเดียวกัน
 *  (main.php เรียก app_can_access ก่อน include หน้า ปิดเมนูแล้วพิมพ์ URL ตรง ๆ ก็เข้าไม่ได้)
 * ============================================================ */
require_once dirname(dirname(__FILE__)) . '/backend/menu_permission.php';

function app_apply_permissions()
{
    global $APP_NAV;

    $map = menu_permission_map();
    if (count($map) === 0) {
        return;                     // ยังไม่เคยตั้งค่า ใช้ค่าตั้งต้นในโค้ดทั้งหมด
    }

    foreach ($APP_NAV as $si => $section) {
        foreach ($section['items'] as $ii => $item) {

            if (isset($item['children'])) {
                foreach ($item['children'] as $ci => $child) {
                    $roles = app_roles_from_map($map, $child['key'], $child['roles']);
                    $APP_NAV[$si]['items'][$ii]['children'][$ci]['roles'] = $roles;
                }
                /* หัวข้อแม่ไม่มีหน้าของตัวเอง สิทธิ์จึงเป็นผลรวมของลูก
                   ไม่งั้นปิดสิทธิ์ที่หัวข้อแม่ไว้ ลูกที่เปิดอยู่จะโดนซ่อนตามไปด้วย */
                $union = array();
                foreach ($APP_NAV[$si]['items'][$ii]['children'] as $child) {
                    foreach ($child['roles'] as $r) {
                        if (!in_array($r, $union)) { $union[] = $r; }
                    }
                }
                $APP_NAV[$si]['items'][$ii]['roles'] = $union;
                continue;
            }

            $APP_NAV[$si]['items'][$ii]['roles'] =
                app_roles_from_map($map, $item['key'], $item['roles']);
        }
    }
}

/* แปลงแถวในตารางของเมนูหนึ่งตัวให้เป็นรายการ role ที่เข้าได้ */
function app_roles_from_map($map, $key, $default_roles)
{
    if (!isset($map[$key])) {
        return $default_roles;      // เมนูนี้ยังไม่เคยตั้งค่า ใช้ของเดิม
    }

    $roles = array('super_admin');  // เข้าได้ทุกเมนูเสมอ ไม่ขึ้นกับตาราง
    foreach ($map[$key] as $role => $allowed) {
        if ((int)$allowed === 1 && $role !== 'super_admin') {
            $roles[] = $role;
        }
    }
    return $roles;
}

app_apply_permissions();


/* ---------- แปลงเมนูเป็นตาราง route : key => รายการ (เฉพาะที่เป็นหน้าจริง) ---------- */
function app_routes()
{
    global $APP_NAV;

    $routes = array();
    foreach ($APP_NAV as $section) {
        foreach ($section['items'] as $item) {

            if (isset($item['children'])) {
                foreach ($item['children'] as $child) {
                    $child['parent'] = $item['key'];
                    $child['icon']   = isset($child['icon']) ? $child['icon'] : $item['icon'];
                    $routes[$child['key']] = $child;
                }
                continue;
            }

            if (isset($item['file'])) {
                $routes[$item['key']] = $item;
            }
        }
    }
    return $routes;
}

/* ---------- role นี้เข้าถึงรายการนี้ได้ไหม ---------- */
function app_can_access($item, $role)
{
    return in_array($role, $item['roles']);
}


/* ---------- หน้าแรกของแต่ละสิทธิ์ ----------
 * ใช้ตอนเข้าระบบ ตอนกดโลโก้ และตอนกดปุ่มกลับจากหน้าที่ไม่พบ/ไม่มีสิทธิ์
 *
 * ผู้ใช้งานทั่วไปไปที่ "ทำรายการเบิก" ไม่ใช่หน้าภาพรวมระบบ
 * เพราะคนกลุ่มนี้เปิดระบบมาเพื่อเบิกของเป็นหลัก การให้ลงหน้าภาพรวม
 * ที่เต็มไปด้วยตัวเลขของคลัง แล้วต้องกดต่ออีกหนึ่งครั้งทุกครั้ง คือขั้นตอนที่เกินมา
 *
 * ไล่ตามลำดับที่ตั้งไว้แล้วหยุดที่ตัวแรกที่สิทธิ์นั้นเข้าได้จริง
 * เพราะสิทธิ์เข้าเมนูปรับได้จากหน้า "สิทธิ์การใช้งาน" ถ้าปักไว้ตายตัว
 * แล้วหน้านั้นถูกปิด ผู้ใช้จะเข้าระบบมาเจอหน้า "ไม่มีสิทธิ์" ทันที
 */
function app_home_page($role)
{
    $prefer = ($role === 'general')
              ? array('request_new', 'request_list', 'dashboard', 'change_password')
              : array('dashboard', 'request_list', 'report_stock', 'change_password');

    $routes = app_routes();

    foreach ($prefer as $key) {
        if (isset($routes[$key]) && app_can_access($routes[$key], $role)) {
            return $key;
        }
    }

    /* ยังไม่เจอ ก็เอาหน้าแรกสุดที่เข้าได้ — ดีกว่าเด้งไปหน้าที่เข้าไม่ได้ */
    foreach ($routes as $key => $item) {
        if (app_can_access($item, $role)) { return $key; }
    }

    return 'change_password';   // ทุกคนเข้าหน้านี้ได้เสมอ
}

/* ---------- รายการย่อยที่ role นี้เห็นได้ ---------- */
function app_visible_children($item, $role)
{
    $out = array();
    if (!isset($item['children'])) {
        return $out;
    }
    foreach ($item['children'] as $child) {
        if (app_can_access($child, $role)) {
            $out[] = $child;
        }
    }
    return $out;
}

/* ---------- รายการในกลุ่มที่ role นี้เห็นได้ (ตัดหัวข้อที่ลูกไม่เหลือทิ้ง) ---------- */
function app_visible_items($section, $role)
{
    $out = array();
    foreach ($section['items'] as $item) {

        if (!app_can_access($item, $role)) {
            continue;
        }

        if (isset($item['children'])) {
            $children = app_visible_children($item, $role);
            if (count($children) === 0) {
                continue;               // ลูกไม่เหลือสักตัว ก็ไม่ต้องแสดงหัวข้อ
            }
            $item['children'] = $children;
        }

        $out[] = $item;
    }
    return $out;
}

/* ---------- ตั้งตัวเลขแจ้งเตือนบนเมนู ----------
 * เรียกก่อน include layout_top.php เช่น
 *     app_set_badge('report_low', $จำนวนสินค้าต่ำกว่าจุดสั่งซื้อ, 'red');
 * ค่าที่เป็น 0 หรือ null จะไม่แสดง badge
 */
function app_set_badge($key, $count, $tone = 'brand')
{
    global $APP_NAV;

    foreach ($APP_NAV as $si => $section) {
        foreach ($section['items'] as $ii => $item) {

            if ($item['key'] === $key) {
                $APP_NAV[$si]['items'][$ii]['badge'] = $count;
                $APP_NAV[$si]['items'][$ii]['tone']  = $tone;
                return true;
            }

            if (isset($item['children'])) {
                foreach ($item['children'] as $ci => $child) {
                    if ($child['key'] === $key) {
                        $APP_NAV[$si]['items'][$ii]['children'][$ci]['badge'] = $count;
                        $APP_NAV[$si]['items'][$ii]['children'][$ci]['tone']  = $tone;
                        return true;
                    }
                }
            }
        }
    }
    return false;
}

/* ---------- ชื่อ role ที่แสดงให้ผู้ใช้อ่าน ----------
 * ชื่อจริงนิยามไว้ที่ backend/user.php ที่เดียว
 * เมนู หน้าจัดการผู้ใช้ และ API จึงใช้ข้อความชุดเดียวกันเสมอ */
require_once dirname(dirname(__FILE__)) . '/backend/user.php';

function app_role_label($role)
{
    return user_role_label($role);
}

/* ---------- ตัวย่อชื่อสำหรับวงกลม avatar ---------- */
function app_initials($name)
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    if (preg_match('/^./u', $name, $m)) {
        return $m[0];
    }
    return substr($name, 0, 1);
}
