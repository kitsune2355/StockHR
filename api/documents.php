<?php
/* ============================================================
 *  api/documents.php — endpoint ของเอกสารรับเข้า / ใบเบิก / ใบจ่าย
 *
 *  ทุกคำสั่งส่งมาแบบ POST พร้อม body เป็น JSON
 *
 *    { "action": "bootstrap", "doc_type": "receive|request|issue" }
 *        ข้อมูลตั้งต้นของหน้าทำเอกสาร (สินค้า+ยอดคงเหลือ, หน่วยงาน, ใบเบิกที่รอจ่าย)
 *
 *    { "action": "list", "doc_type": "...", "status": "...", "mine": true }
 *    { "action": "get",  "doc_id": 12 }
 *
 *    { "action": "save", "doc_type": "...", "csrf_token": "...",
 *      "doc_date": "2026-08-11", "department_id": 3, "ref_doc_id": 5,
 *      "receiver_name": "...", "note": "...",
 *      "items": [ {"product_id":1, "qty":5, "note":""} ] }
 *
 *    { "action": "decide",      "doc_id": 5, "decision": "approved|rejected", "reason": "" }
 *    { "action": "acknowledge", "doc_id": 9, "receiver_name": "..." }
 *    { "action": "cancel",      "doc_id": 9, "reason": "..." }
 *
 *  ไม่มี SQL ในไฟล์นี้ — เรียกฟังก์ชันจาก backend/ ทั้งหมด
 *  กฎว่าใครทำอะไรได้อยู่ในชั้น backend จึงบังคับเหมือนกันทุกทางเข้า
 * ============================================================ */

require_once dirname(__FILE__) . '/_bootstrap.php';
require_once dirname(dirname(__FILE__)) . '/backend/receive.php';
require_once dirname(dirname(__FILE__)) . '/backend/request.php';
require_once dirname(dirname(__FILE__)) . '/backend/issue.php';
require_once dirname(dirname(__FILE__)) . '/backend/department.php';

api_require_post();
$actor      = api_require_login();
$actor_role = isset($_SESSION['sess_role']) ? $_SESSION['sess_role'] : '';

$input    = api_input();
$action   = isset($input['action'])   ? $input['action']   : '';
$doc_type = isset($input['doc_type']) ? $input['doc_type'] : '';


/* ---------- สิทธิ์ประจำแต่ละประเภทเอกสาร ----------
 * แยก "อ่าน" กับ "เขียน" ออกจากกัน เพราะสองอย่างนี้ไม่เท่ากัน
 *
 * อ่าน (doc_read_roles_for)
 *   ใบเบิก  ทุกคน — ทุกแผนกต้องติดตามใบของตัวเองได้
 *   ใบจ่าย  ทุกคน — ผู้ขอเบิกต้องเปิดใบจ่ายของตัวเองเพื่อกด "เซ็นรับของ"
 *           ถ้ากันไว้เฉพาะคนคลัง เส้นทางจะขาดตรงขั้นสุดท้าย
 *           (ผู้ใช้ทั่วไปยังเห็นเฉพาะใบที่ตัวเองเป็นผู้ขอ — บังคับที่ action list/get)
 *   รับเข้า เฉพาะคนคลัง เพราะไม่เกี่ยวกับผู้ใช้ทั่วไปเลย
 *
 * เขียน (doc_write_roles_for)
 *   ใบเบิก  ทุกคน
 *   รับเข้า/ใบจ่าย เฉพาะคนที่ทำงานกับคลังจริง
 */
function doc_read_roles_for($doc_type)
{
    if ($doc_type === 'request' || $doc_type === 'issue') {
        return array('super_admin', 'admin', 'approver', 'general');
    }
    return array('super_admin', 'admin', 'approver');
}

function doc_write_roles_for($doc_type)
{
    if ($doc_type === 'request') {
        return array('super_admin', 'admin', 'approver', 'general');
    }
    return array('super_admin', 'admin', 'approver');
}


/* ============================================================
 *  ข้อมูลตั้งต้นของหน้าทำเอกสาร
 * ============================================================ */
if ($action === 'bootstrap') {

    api_require_role(doc_write_roles_for($doc_type));

    /* ---------- สินค้าที่เอาไปให้เลือก ----------
     * ใบเบิกเห็นเฉพาะของที่หน่วยงานนั้นเบิกได้ (ดู backend/product_department.php)
     * ส่วนใบรับเข้ากับใบจ่ายเห็นครบทุกรายการ เพราะคนคลังต้องจัดการของทั้งคลัง
     *
     * หน่วยงานมาจากสองทาง:
     *   ผู้ใช้ทั่วไป  ล็อกไว้ที่หน่วยงานต้นสังกัด
     *   ผู้ดูแลระบบ  เลือกได้ในฟอร์ม จึงส่ง department_id มาเองตอนเปลี่ยน
     *                (หน้าจอเรียก bootstrap ซ้ำเมื่อเปลี่ยนหน่วยงาน)
     */
    $filter = array('only_active' => true);

    if ($doc_type === 'request') {
        $me   = user_find($actor);
        $dept = ($me === null) ? 0 : (int)$me['dept_id'];

        if (in_array($actor_role, array('super_admin', 'admin'))
            && isset($input['department_id']) && (int)$input['department_id'] > 0) {
            $dept = (int)$input['department_id'];
        }

        if ($dept > 0) { $filter['department_id'] = $dept; }
    }

    $out = array(
        'products'  => stock_list($filter),
        'today'     => date('Y-m-d'),
        'statuses'  => doc_statuses()
    );

    if ($doc_type === 'request') {
        /* ให้เลือกเฉพาะหน่วยงานที่ยังเปิดใช้งาน จะได้ไม่มีใบเบิกของหน่วยงานที่ยุบไปแล้ว */
        $out['departments'] = department_list(array('is_active' => 1));

        /* หน่วยงานต้นสังกัดของคนที่กำลังทำใบเบิก ใช้เลือกให้ล่วงหน้า
           คนส่วนใหญ่เบิกในนามหน่วยงานตัวเองเสมอ จึงไม่ควรต้องเลือกซ้ำทุกครั้ง */
        $me = user_find($actor);
        $out['myDept']     = ($me === null) ? 0  : (int)$me['dept_id'];
        $out['myDeptName'] = ($me === null) ? '' : $me['department_name'];

        /* ผู้ดูแลระบบทำใบเบิกแทนหน่วยงานอื่นได้ คนอื่นล็อกไว้ที่หน่วยงานตัวเอง
           (backend บังคับซ้ำอีกชั้นใน request_save) */
        $out['lockDept'] = (!in_array($actor_role, array('super_admin', 'admin'))
                            && $out['myDept'] > 0);
    }

    if ($doc_type === 'issue') {
        /* หน้าทำใบจ่ายเลือกจากใบเบิกที่อนุมัติแล้วและยังจ่ายไม่ครบเท่านั้น */
        $out['requests'] = request_ready_list();
    }

    api_ok($out);
}


/* ============================================================
 *  รายการเอกสาร (หน้าประวัติ)
 * ============================================================ */
if ($action === 'list') {

    api_require_role(doc_read_roles_for($doc_type));

    $options = array(
        'doc_type'  => $doc_type,
        'status'    => isset($input['status'])    && $input['status']    !== '' ? $input['status']    : null,
        'search'    => isset($input['search'])    ? $input['search']    : '',
        'date_from' => isset($input['date_from']) ? $input['date_from'] : '',
        'date_to'   => isset($input['date_to'])   ? $input['date_to']   : '',
        'limit'     => 500
    );

    /* ---------- ขอบเขตที่แต่ละคนมองเห็น ----------
     * กติกาอยู่ที่ doc_scope_for() ใน backend/document.php ที่เดียว
     * บังคับจากฝั่งเซิร์ฟเวอร์เสมอ ไม่ใช่ให้หน้าจอส่งมา
     * ไม่งั้นแก้ค่าใน request แล้วดูของหน่วยงานอื่นได้
     */
    $scope = doc_scope_for($actor, $actor_role);
    foreach ($scope as $k => $v) {
        $options[$k] = $v;
    }

    /* "เห็นได้มากกว่าใบของตัวเอง" — ใช้ตัดสินว่าจะโชว์คอลัมน์ผู้ขอเบิกไหม */
    $can_see_all = !isset($scope['requester']);

    /* ติ๊ก "เฉพาะเอกสารของฉัน" บีบให้แคบลงได้อีกขั้น แต่ขยายกว้างขึ้นไม่ได้ */
    if (!empty($input['mine'])) {
        $options['requester'] = $actor;
    }

    api_ok(array(
        'docs'     => doc_list($options),
        'statuses' => doc_statuses(),
        'me'       => $actor,
        'canSeeAll'=> $can_see_all
    ));
}


/* ============================================================
 *  เอกสารใบเดียว พร้อมรายการสินค้า
 * ============================================================ */
if ($action === 'get') {

    $doc = doc_find(isset($input['doc_id']) ? (int)$input['doc_id'] : 0);
    if ($doc === null) {
        api_fail('ไม่พบเอกสารที่ต้องการ');
    }

    api_require_role(doc_read_roles_for($doc['doc_type']));

    /* เปิดใบเดียวก็ต้องคุมขอบเขตเดียวกับตอนแสดงรายการ
       ใช้ฟังก์ชันตัวเดียวกับที่ print_doc.php ใช้ กติกาจึงตรงกันเสมอ */
    if (!doc_can_read($doc, $actor, $actor_role)) {
        api_fail('คุณไม่มีสิทธิ์ดูเอกสารใบนี้', 403);
    }

    api_ok(array('doc' => $doc));
}


/* ============================================================
 *  บันทึกเอกสารใหม่
 * ============================================================ */
if ($action === 'save') {

    api_require_csrf($input);
    api_require_role(doc_write_roles_for($doc_type));

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : array();

    $data = array(
        'doc_date'      => isset($input['doc_date'])      ? $input['doc_date']            : '',
        'department_id' => isset($input['department_id']) ? (int)$input['department_id']  : 0,
        'ref_doc_id'    => isset($input['ref_doc_id'])    ? (int)$input['ref_doc_id']     : 0,
        'receiver_name' => isset($input['receiver_name']) ? $input['receiver_name']       : '',
        'note'          => isset($input['note'])          ? $input['note']                : '',
        'items'         => $items
    );

    if ($doc_type === 'receive') {
        $res = receive_save($data, $actor);
    } elseif ($doc_type === 'request') {
        $res = request_save($data, $actor, $actor_role);
    } elseif ($doc_type === 'issue') {
        $res = issue_save($data, $actor);
    } else {
        api_fail('ไม่รู้จักประเภทเอกสารที่ส่งมา');
    }

    if (!$res['ok']) {
        api_fail($res['message']);
    }

    api_ok(array(
        'message' => $res['message'],
        'doc_id'  => $res['id'],
        'doc_no'  => isset($res['doc_no']) ? $res['doc_no'] : ''
    ));
}


/* ============================================================
 *  ผู้อนุมัติตัดสินใบเบิก
 * ============================================================ */
if ($action === 'decide') {

    api_require_csrf($input);

    $res = request_decide(
        isset($input['doc_id'])   ? (int)$input['doc_id'] : 0,
        isset($input['decision']) ? $input['decision']    : '',
        isset($input['reason'])   ? $input['reason']      : '',
        $actor, $actor_role
    );

    if (!$res['ok']) { api_fail($res['message']); }
    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  ผู้เบิกเซ็นรับของ
 * ============================================================ */
if ($action === 'acknowledge') {

    api_require_csrf($input);

    $res = issue_acknowledge(
        isset($input['doc_id'])        ? (int)$input['doc_id']   : 0,
        isset($input['receiver_name']) ? $input['receiver_name'] : '',
        $actor, $actor_role,
        isset($input['receiver_sign']) ? $input['receiver_sign'] : ''
    );

    if (!$res['ok']) { api_fail($res['message']); }
    api_ok(array('message' => $res['message']));
}


/* ============================================================
 *  ส่งอีเมลแจ้งเตือนซ้ำ
 *
 *  ผู้ใช้หาเมลไม่เจอ (เข้าถังขยะ หรือลบไปแล้ว) เป็นเรื่องที่เกิดจริงและบ่อย
 *  ถ้าไม่มีทางส่งซ้ำ ผู้ดูแลระบบต้องไปนั่งหาข้อมูลแล้วพิมพ์เมลเองทีละฉบับ
 *
 *  ส่งเนื้อหาชุดเดียวกับตอนที่ระบบส่งอัตโนมัติ ไม่ใช่เมลแบบอื่น
 *  ผู้รับจะได้เห็นสิ่งเดียวกับที่ควรได้รับตั้งแต่แรก
 *
 *  จำกัดเฉพาะผู้ดูแลระบบ — เป็นการส่งของออกไปหาคนอื่น ไม่ใช่การอ่านข้อมูล
 * ============================================================ */
/* ============================================================
 *  รายการระดับบรรทัด สำหรับส่งออก Excel
 * ============================================================
 *  ตารางบนจอเป็นหนึ่งแถวต่อหนึ่งเอกสาร ซึ่งตอบคำถามเรื่องราคาไม่ได้
 *  เพราะราคาต่อหน่วยอยู่ที่บรรทัด ใบเดียวมีหลายราคาได้
 *
 *  คำถามจริงที่ต้องตอบ: "เดือนนี้รับผ้าพันแผลเข้ามากี่ครั้ง ครั้งละราคาเท่าไร"
 *      ครั้งแรก 5 ม้วน ม้วนละ 15 / ครั้งที่สอง 3 ม้วน ม้วนละ 10
 *  ไฟล์ที่มีแถวละเอกสารจะกลืนสองราคานั้นรวมกันจนแยกไม่ออก
 *
 *  ใช้ตัวกรองชุดเดียวกับ action 'list' เป๊ะ ๆ ไฟล์ที่ได้จึงตรงกับที่เห็นบนจอเสมอ
 *  ไม่ใช่ส่งออกทั้งฐานข้อมูลโดยไม่สนตัวกรองที่ผู้ใช้ตั้งไว้
 * ============================================================ */
if ($action === 'export_lines') {

    $doc_type = isset($input['doc_type']) ? $input['doc_type'] : '';
    if (!in_array($doc_type, array('receive', 'request', 'issue'))) {
        api_fail('ไม่รู้จักประเภทเอกสารที่ขอ');
    }

    $opts = array(
        'doc_type'  => $doc_type,
        'status'    => isset($input['status'])    ? $input['status']    : '',
        'date_from' => isset($input['date_from']) ? $input['date_from'] : '',
        'date_to'   => isset($input['date_to'])   ? $input['date_to']   : '',
        'limit'     => 2000
    );

    /* ขอบเขตการมองเห็นต้องเหมือน action 'list' ทุกประการ
       ไม่งั้นคนที่เห็นบนจอแค่ของหน่วยงานตัวเอง จะกดส่งออกแล้วได้ของทั้งบริษัท */
    $scope = doc_scope_for($actor, $actor_role);
    foreach ($scope as $k => $v) {
        $opts[$k] = $v;
    }

    /* ติ๊ก "เฉพาะเอกสารของฉัน" บีบให้แคบลงได้อีกขั้น แต่ขยายกว้างขึ้นไม่ได้ */
    if (!empty($input['mine'])) { $opts['requester'] = $actor; }

    $docs  = doc_list($opts);
    $lines = array();

    for ($i = 0; $i < count($docs); $i++) {
        $doc = doc_find($docs[$i]['doc_id']);
        if ($doc === null) { continue; }

        for ($j = 0; $j < count($doc['items']); $j++) {
            $it = $doc['items'][$j];

            $lines[] = array(
                'doc_no'          => $doc['doc_no'],
                'doc_date'        => $doc['doc_date'],
                'status_label'    => $doc['status_label'],
                'department_name' => $doc['department_name'],
                'requester_name'  => ($doc['requester_name'] !== '') ? $doc['requester_name'] : $doc['requester'],
                'ref_doc_no'      => $doc['ref_doc_no'],
                'line_no'         => $j + 1,
                'product_code'    => $it['product_code'],
                'product_name'    => $it['product_name'],
                'qty'             => (float)$it['qty'],
                'unit_name'       => $it['unit_name'],
                'unit_cost'       => (float)$it['unit_cost'],
                'amount'          => (float)$it['qty'] * (float)$it['unit_cost'],
                'item_note'       => $it['note'],
                'doc_note'        => $doc['note']
            );
        }
    }

    api_ok(array('lines' => $lines, 'docs' => count($docs)));
}


/* ============================================================
 *  ขอลิงก์เซ็นรับของของใบจ่าย
 *
 *  ใช้กับปุ่ม "คัดลอกลิงก์" และ "เปิดหน้าเซ็นรับ" ในหน้าประวัติการจ่ายออก
 *  มีไว้เพื่อกรณีที่ผู้เบิกหาเมลไม่เจอ แล้วคลังอยากส่งลิงก์ให้ทางไลน์แทน
 *
 *  ใช้ของเดิมถ้ายังไม่หมดอายุ ไม่ออกดอกใหม่ทุกครั้งที่กด
 *  ไม่งั้นกดคัดลอกสองครั้งจะได้คนละลิงก์ แล้วอันที่ส่งไปเมื่อกี้ใช้ไม่ได้ทันที
 *
 *  จำกัดที่ผู้ดูแลระบบ เพราะลิงก์นี้เซ็นรับแทนคนอื่นได้
 *  ใครขอดูได้ทุกใบก็เท่ากับเซ็นรับแทนได้ทุกใบ
 * ============================================================ */
if ($action === 'sign_link') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin'));

    require_once dirname(dirname(__FILE__)) . '/backend/sign_link.php';

    $doc = doc_find(isset($input['doc_id']) ? (int)$input['doc_id'] : 0);
    if ($doc === null)                { api_fail('ไม่พบเอกสารที่ต้องการ'); }
    if ($doc['doc_type'] !== 'issue') { api_fail('ลิงก์เซ็นรับของมีเฉพาะใบจ่ายเท่านั้น'); }

    $url = sign_link_for($doc['doc_id'], $actor);
    if ($url === '') {
        api_fail('สร้างลิงก์ไม่สำเร็จ — ยังไม่ได้ตั้งค่า "ที่อยู่เว็บ" (site_url) '
               . 'ที่หน้าตั้งค่าระบบ ลิงก์ที่ไม่มีชื่อเครื่องส่งให้ใครก็กดไม่ได้');
    }

    api_ok(array(
        'message' => 'ลิงก์เซ็นรับของใบ ' . $doc['doc_no'],
        'url'     => $url,
        'days'    => SIGN_LINK_DAYS,
        'signed'  => ($doc['status'] === 'received')
    ));
}


if ($action === 'resend_mail') {

    api_require_csrf($input);
    api_require_role(array('super_admin', 'admin'));

    require_once dirname(dirname(__FILE__)) . '/backend/notify_events.php';

    $doc = doc_find(isset($input['doc_id']) ? (int)$input['doc_id'] : 0);
    if ($doc === null) { api_fail('ไม่พบเอกสารที่ต้องการ'); }

    /* เลือกเหตุการณ์ให้ตรงกับสถานะปัจจุบันของเอกสาร
       ไม่ใช่ให้หน้าจอส่งมาว่าจะส่งเมลแบบไหน ไม่งั้นจะส่งเมล "อนุมัติแล้ว"
       ให้ใบที่เพิ่งถูกปฏิเสธได้ ซึ่งเป็นข้อมูลผิดที่ส่งออกไปแล้วเรียกคืนไม่ได้ */
    $sent = 0;
    $what = '';

    if ($doc['doc_type'] === 'request') {

        if ($doc['status'] === 'waiting') {
            $sent = notify_event_request_submitted($doc['doc_id'], '');
            $what = 'แจ้งผู้อนุมัติว่ามีใบเบิกรอพิจารณา';

        } elseif ($doc['status'] === 'approved' || $doc['status'] === 'rejected') {
            $sent = notify_event_request_decided($doc['doc_id'],
                        $doc['status'], $doc['approve_note'], '');
            $what = 'แจ้งผู้ขอเบิกว่าใบถูกตัดสินแล้ว';

        } elseif ($doc['status'] === 'cancelled') {
            $sent = notify_event_doc_cancelled($doc['doc_id'], $doc['cancel_note'], '');
            $what = 'แจ้งเรื่องการยกเลิก';

        } else {
            api_fail('ใบเบิกนี้จ่ายของครบแล้ว ไม่มีเมลที่ต้องส่งซ้ำ '
                   . '— ถ้าต้องการแจ้งเรื่องการรับของ ให้ส่งจากใบจ่ายแทน');
        }

    } else {

        if ($doc['status'] === 'issued') {
            $sent = notify_event_issue_created($doc['doc_id'], '');
            $what = 'แจ้งผู้ขอเบิกให้มารับของและเซ็นรับ';

        } elseif ($doc['status'] === 'received') {
            $sent = notify_event_issue_acknowledged($doc['doc_id'], '');
            $what = 'แจ้งคนคลังว่าผู้เบิกเซ็นรับแล้ว';

        } elseif ($doc['status'] === 'cancelled') {
            $sent = notify_event_doc_cancelled($doc['doc_id'], $doc['cancel_note'], '');
            $what = 'แจ้งเรื่องการยกเลิก';

        } else {
            api_fail('เอกสารสถานะนี้ยังไม่มีเมลที่ต้องส่ง');
        }
    }

    if ($sent === 0) {
        api_fail('ไม่มีผู้รับที่ส่งได้ — ปลายทางอาจยังไม่ได้กรอกอีเมล '
               . 'หรือหน่วยงานนี้ยังไม่มีผู้อนุมัติ');
    }

    api_ok(array(
        'message' => 'เข้าคิวเมล ' . $what . ' ให้ ' . $sent . ' คนแล้ว '
                   . '— ระบบจะส่งออกภายในไม่กี่นาที หรือกด "ส่งคิวเดี๋ยวนี้" ที่หน้าตั้งค่าระบบ',
        'sent'    => $sent
    ));
}


/* ============================================================
 *  ยกเลิกเอกสาร — ส่งต่อให้ฟังก์ชันของประเภทนั้น
 *  เพราะการยกเลิกแต่ละประเภทคืนสต๊อกคนละทิศ และมีเงื่อนไขต่างกัน
 * ============================================================ */
if ($action === 'cancel') {

    api_require_csrf($input);

    $doc_id = isset($input['doc_id']) ? (int)$input['doc_id'] : 0;
    $reason = isset($input['reason']) ? $input['reason']      : '';

    $doc = doc_find($doc_id);
    if ($doc === null) { api_fail('ไม่พบเอกสารที่ต้องการยกเลิก'); }

    if ($doc['doc_type'] === 'receive') {
        api_require_role(array('super_admin', 'admin'));
        $res = receive_cancel($doc_id, $reason, $actor);
    } elseif ($doc['doc_type'] === 'request') {
        $res = request_cancel($doc_id, $reason, $actor, $actor_role);
    } else {
        $res = issue_cancel($doc_id, $reason, $actor, $actor_role);
    }

    if (!$res['ok']) { api_fail($res['message']); }
    api_ok(array('message' => $res['message']));
}


api_fail('ไม่รู้จักคำสั่งที่ส่งมา');
