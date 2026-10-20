<?php
/* ============================================================
 *  backend/notify_events.php — แปลง "เหตุการณ์ทางธุรกิจ" เป็นการแจ้งเตือน
 *
 *  ไฟล์นี้เป็นชั้นเดียวที่รู้ว่า "ใบเบิกถูกส่ง" แปลว่าต้องเตือนใคร ด้วยข้อความอะไร
 *  backend/request.php กับ backend/issue.php แค่เรียกฟังก์ชันที่นี่ตอนงานสำเร็จ
 *  จึงไม่ต้องรู้เรื่องกระดิ่งหรืออีเมลเลย
 *
 *  ---------------------------------------------------------------
 *  กติกาเหล็กของไฟล์นี้: ห้ามทำให้งานหลักพัง
 *  ---------------------------------------------------------------
 *  ทุกฟังก์ชันถูกเรียก "หลัง" ธุรกรรมหลัก commit ไปแล้ว
 *  ถ้าเขียนแจ้งเตือนไม่สำเร็จหรือเมลเข้าคิวไม่ได้ ใบเบิกที่บันทึกไปแล้วต้องยังอยู่ครบ
 *  จึงไม่มีฟังก์ชันไหนโยน error ออกไป และผู้เรียกไม่ต้องตรวจผลลัพธ์
 *
 *  เหตุผล: ใบเบิกคือความจริงทางธุรกิจ ส่วนแจ้งเตือนคือความสะดวก
 *  ถ้าเมลเซิร์ฟเวอร์ล่มแล้วทำให้เบิกของไม่ได้ทั้งบริษัท นั่นคือการออกแบบที่ผิด
 *
 *  ---------------------------------------------------------------
 *  สองช่องทางที่ส่งพร้อมกันเสมอ
 *  ---------------------------------------------------------------
 *    กระดิ่งในระบบ  ทำงานทันทีตลอด ไม่ขึ้นกับค่าตั้งใด ๆ
 *    อีเมล          เข้าคิวเสมอ แล้วตัวส่งค่อยหยิบไปทีหลัง
 *                   ถ้าปิดสวิตช์ mail_enabled ไว้ เมลจะค้างอยู่ในคิวเฉย ๆ
 *                   ไม่หายไปไหน เปิดสวิตช์เมื่อไรก็ส่งออกได้
 *
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/thai_date.php';
require_once dirname(__FILE__) . '/notify.php';
require_once dirname(__FILE__) . '/mailer.php';
require_once dirname(__FILE__) . '/user.php';
require_once dirname(__FILE__) . '/document.php';


/* ---------- หน้าที่ลิงก์ในแจ้งเตือน/อีเมลของใบเบิกควรพาไป ----------
 * ทุกใบ (รวมใบพนักงานใหม่ NRQ-) อยู่ใน "รายการใบเบิก" — เมนู "ใบเบิกพนักงานใหม่" ถูกซ่อนแล้ว
 * คงฟังก์ชันไว้ เผื่อวันหลังแยกหน้าอีก จะได้แก้ที่เดียว
 */
function notify_request_page($doc)
{
    return 'request_list';
}


/* ---------- สรุปรายการสินค้าในเอกสารเป็นข้อความสั้น ----------
 * "ปากกาลูกลื่น 5 ด้าม และอีก 3 รายการ"
 *
 * ใส่ชื่อสินค้าตัวแรกจริง ๆ ไม่ใช่แค่ "4 รายการ" เพราะคนที่เห็นแจ้งเตือน
 * ต้องตัดสินใจได้ว่าเรื่องนี้ด่วนไหมโดยไม่ต้องกดเข้าไปดู
 */
function notify_items_summary($items)
{
    if (!is_array($items) || count($items) === 0) {
        return 'ไม่มีรายการสินค้า';
    }

    $first = $items[0];
    $name  = isset($first['product_name']) ? $first['product_name'] : 'สินค้า';
    $qty   = isset($first['qty']) ? $first['qty'] : 0;
    $unit  = isset($first['unit_name']) ? $first['unit_name'] : '';

    $text = $name . ' ' . stock_format($qty) . ' ' . $unit;

    $more = count($items) - 1;
    if ($more > 0) {
        $text .= ' และอีก ' . $more . ' รายการ';
    }
    return trim($text);
}


/* ---------- ตารางรายละเอียดที่ใช้ในเนื้ออีเมล ---------- */
function notify_doc_pairs($doc, $extra = array())
{
    $pairs = array(
        array('เลขที่เอกสาร', $doc['doc_no']),
        array('วันที่',        thai_date_long($doc['doc_date'])),
        array('หน่วยงาน',      $doc['department_name'] !== '' ? $doc['department_name'] : '-'),
        array('ผู้ขอเบิก',      $doc['requester_name'] !== '' ? $doc['requester_name'] : $doc['requester'])
    );

    for ($i = 0; $i < count($extra); $i++) {
        $pairs[] = $extra[$i];
    }

    if (trim($doc['note']) !== '') {
        $pairs[] = array('หมายเหตุ', $doc['note']);
    }
    return $pairs;
}


/* วันที่แบบไทยย้ายไปอยู่ที่ backend/thai_date.php แล้ว
   อีเมลใช้แบบปีเต็ม เพราะคนรับอาจเปิดอ่านย้อนหลังหลายเดือน */


/* ---------- รายการสินค้าเป็นตาราง HTML สำหรับอีเมล ----------
 * ผู้อนุมัติหลายคนตัดสินใจจากมือถือโดยไม่เปิดระบบ ถ้าเมลบอกแค่
 * "มีใบเบิกใหม่" เขาต้องเปิดคอมพิวเตอร์ก่อนถึงจะรู้ว่าเรื่องอะไร
 * ใส่ของทั้งใบมาให้เลย จะได้ตัดสินใจได้ตั้งแต่อ่านเมล
 *
 * จำกัดที่ 15 บรรทัด เมลที่ยาวเป็นหน้าจอไม่มีใครอ่าน และ Gmail ตัดเนื้อหา
 * ที่ยาวเกิน 102 KB ทิ้งพร้อมขึ้นลิงก์ "ดูข้อความทั้งหมด" ซึ่งกดยากบนมือถือ
 */
function notify_items_html($items, $max = 15)
{
    if (!is_array($items) || count($items) === 0) { return ''; }

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
          . 'style="width:100%;margin:14px 0 4px;font-size:14px;border-collapse:collapse">'
          . '<tr>'
          . '<th align="left"  style="padding:7px 8px;background:#f1f5f9;color:#475569;'
          . 'font-weight:600;border-bottom:1px solid #e2e8f0">รายการ</th>'
          . '<th align="right" style="padding:7px 8px;background:#f1f5f9;color:#475569;'
          . 'font-weight:600;border-bottom:1px solid #e2e8f0;white-space:nowrap">จำนวน</th>'
          . '</tr>';

    $n = count($items);
    $show = ($n > $max) ? $max : $n;

    for ($i = 0; $i < $show; $i++) {
        $it   = $items[$i];
        $name = isset($it['product_name']) ? $it['product_name'] : '';
        $code = isset($it['product_code']) ? $it['product_code'] : '';
        $qty  = isset($it['qty'])       ? $it['qty']       : 0;
        $unit = isset($it['unit_name']) ? $it['unit_name'] : '';

        $html .= '<tr>'
               . '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9">'
               . '<span style="color:#94a3b8;font-size:12px">'
               . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span><br>'
               . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td align="right" style="padding:7px 8px;border-bottom:1px solid #f1f5f9;'
               . 'white-space:nowrap;font-weight:600">'
               . htmlspecialchars(stock_format($qty) . ' ' . $unit, ENT_QUOTES, 'UTF-8')
               . '</td></tr>';
    }

    if ($n > $show) {
        $html .= '<tr><td colspan="2" style="padding:7px 8px;color:#64748b;font-size:13px">'
               . 'และอีก ' . ($n - $show) . ' รายการ — เปิดในระบบเพื่อดูทั้งหมด</td></tr>';
    }

    return $html . '</table>';
}


/* ---------- ส่งทั้งกระดิ่งและอีเมลให้คนกลุ่มหนึ่ง ----------
 * $people = array(array('username','full_name','email'), ...)
 *
 * รวมสองช่องทางไว้ในฟังก์ชันเดียว เพราะทุกเหตุการณ์ส่งทั้งคู่เสมอ
 * ถ้าแยกเรียก จะมีวันที่ใครสักคนเพิ่มเหตุการณ์ใหม่แล้วลืมเรียกอันใดอันหนึ่ง
 */
function notify_deliver($people, $args)
{
    if (!is_array($people) || count($people) === 0) { return 0; }

    $type      = $args['type'];
    $title     = $args['title'];
    $message   = $args['message'];
    $link_page = isset($args['link_page']) ? $args['link_page'] : '';
    $doc_id    = isset($args['doc_id'])    ? (int)$args['doc_id'] : 0;
    $actor     = isset($args['actor'])     ? $args['actor'] : null;

    $subject   = isset($args['subject'])   ? $args['subject']   : $title;
    $heading   = isset($args['heading'])   ? $args['heading']   : $title;
    $body      = isset($args['body_html']) ? $args['body_html'] : '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    $btn_text  = isset($args['button'])    ? $args['button']    : 'เปิดดูในระบบ';
    $ref_type  = isset($args['ref_type'])  ? $args['ref_type']  : $type;

    /* ---------- ปุ่มในเมลจะพาไปที่ไหน ----------
     * ปกติชี้เข้าหน้าในระบบ ซึ่งต้องล็อกอินก่อน
     *
     * ยกเว้นเมล "ของพร้อมให้รับแล้ว" ที่ส่งหาผู้ขอเบิก — คนกลุ่มนี้ส่วนใหญ่
     * ไม่มีบัญชี หรือมีแต่ไม่มีสิทธิ์เข้าหน้าประวัติการจ่ายออก กดปุ่มแล้วไปจบที่
     * หน้าล็อกอิน ใบจ่ายเลยค้างสถานะ "จ่ายแล้ว" จนคลังต้องโทรตามให้มาเซ็น
     *
     * ฉบับนั้นจึงใช้ลิงก์กุญแจของ sign.php แทน ซึ่งเปิดได้เลยไม่ต้องล็อกอิน
     * ถ้าออกกุญแจไม่สำเร็จ (เช่นยังไม่ได้ตั้ง site_url) ค่อยตกกลับไปใช้ลิงก์เดิม
     * ดีกว่าส่งเมลที่ไม่มีปุ่มอะไรให้กดเลย
     */
    $url = '';
    if (isset($args['sign_link']) && $args['sign_link'] && $doc_id > 0) {
        require_once dirname(__FILE__) . '/sign_link.php';
        $url = sign_link_for($doc_id, $actor);
    }
    if ($url === '') { $url = mail_page_url($link_page, $doc_id); }

    /* ---------- ลิงก์อนุมัติเป็นของ "รายคน" ไม่ใช่ของทั้งฉบับ ----------
     * เมล "ใบเบิกรอคุณอนุมัติ" ส่งหาผู้อนุมัติทุกคนของหน่วยงาน แต่กุญแจ
     * อนุมัติออกให้เป็นรายคน เพราะระบบต้องบันทึกให้ได้ว่าใครเป็นคนกด
     * (ดู backend/approve_link.php) ปุ่มในเมลจึงต้องต่างกันทุกฉบับ
     *
     * โหมดนี้เท่านั้นที่ประกอบ HTML ใหม่ทีละคน ทางปกติยังสร้างครั้งเดียว
     * เหมือนเดิม เพราะเหตุการณ์ส่วนใหญ่ส่งข้อความเดียวกันให้ทุกคน
     */
    $per_person = (isset($args['approve_link']) && $args['approve_link'] && $doc_id > 0);
    if ($per_person) {
        require_once dirname(__FILE__) . '/approve_link.php';
    }

    $html = $per_person ? '' : mail_layout($heading, $body, ($url !== '' ? $btn_text : ''), $url);

    $n = 0;
    for ($i = 0; $i < count($people); $i++) {

        $u = isset($people[$i]['username']) ? $people[$i]['username'] : '';
        if ($u === '' || $u === $actor) { continue; }   // ไม่เตือนคนที่เพิ่งกดเอง

        notify_push($u, $type, $title, $message, $link_page, $doc_id, $actor);

        /* คนที่ยังไม่ได้กรอกอีเมลจะข้ามส่วนนี้ไปเงียบ ๆ (mail_enqueue คืน false)
           แต่ยังได้กระดิ่งครบ — ไม่มีใครพลาดแจ้งเตือนเพราะไม่มีอีเมล */
        $email = isset($people[$i]['email']) ? $people[$i]['email'] : '';
        $name  = isset($people[$i]['full_name']) ? $people[$i]['full_name'] : '';

        $mine = $html;
        if ($per_person) {
            /* ออกกุญแจไม่สำเร็จ (เช่นยังไม่ได้ตั้ง site_url, ยังไม่ได้รัน sql/043
               หรือใบถูกตัดสินไปแล้วระหว่างทาง) ให้ตกกลับไปใช้ลิงก์เข้าระบบแบบเดิม
               ดีกว่าส่งเมลที่ไม่มีปุ่มอะไรให้กดเลย

               ครอบ try ไว้อีกชั้นตามกติกาเหล็กที่หัวไฟล์: แจ้งเตือนห้ามทำให้
               งานหลักพัง ใบเบิกถูกบันทึกไปแล้วตั้งแต่ก่อนเข้าฟังก์ชันนี้
               ต่อให้ตัวออกกุญแจพังยับก็ต้องไม่มีอะไรเด้งกลับไปหาคนกดส่งใบ */
            $one = '';
            try {
                $one = approve_link_for($doc_id, $u, $actor);
            } catch (Exception $e) {
                $one = '';
            }
            if ($one === '') { $one = $url; }

            $mine = mail_layout($heading, $body, ($one !== '' ? $btn_text : ''), $one);
        }

        mail_enqueue($email, $name, $subject, $mine, $ref_type, $doc_id, $actor);

        $n++;
    }

    /* ส่งออกทันทีถ้า .env ตั้ง MAIL_SEND_MODE=instant
       แต่ฝากไว้ให้ทำ "หลังตอบผู้ใช้ไปแล้ว" ไม่ใช่ตรงนี้
       ไม่งั้นคนกดอนุมัติต้องนั่งรอ SMTP หลายวินาทีทั้งที่งานของเขาเสร็จแล้ว

       มีตัวรับฝากสองตัว ต่างกันแค่ทางเข้า ไม่ต่างกันเรื่องพฤติกรรม
         api_after_response  หน้าในระบบที่คุยผ่าน JSON (ดู api/_bootstrap.php)
         after_response      หน้าที่เปิดจากลิงก์ในเมล (ดู backend/after_response.php)

       เหลือทางที่ไม่มีตัวรับฝากจริง ๆ คือ cron กับสคริปต์บรรทัดคำสั่ง
       ตรงนั้นไม่มีใครนั่งรออยู่หน้าจอ ส่งคาที่เดิมได้เลย */
    if ($n > 0) {
        if (function_exists('api_after_response')) {
            api_after_response('mail_flush_instant');
        } elseif (function_exists('after_response')) {
            after_response('mail_flush_instant');
        } else {
            mail_flush_instant($actor);
        }
    }

    return $n;
}


/* ---------- หาแถวผู้ใช้หนึ่งคนในรูปแบบที่ notify_deliver ใช้ได้ ---------- */
function notify_person($username)
{
    $u = user_find($username);
    if ($u === null) { return array(); }

    return array(array(
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'email'     => $u['email']
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 1 — ผู้ขอส่งใบเบิกเข้ามา
 *  ปลายทาง: ผู้อนุมัติของหน่วยงานนั้น (ไม่มีก็ถอยไปหาผู้ดูแลระบบ)
 * ============================================================ */
function notify_event_request_submitted($doc_id, $actor)
{
    /* ต้องโหลดก่อนประกอบข้อความ เพราะเนื้อเมลอ้างถึง APPROVE_LINK_DAYS
       ถ้ารอไปโหลดตอน notify_deliver ค่าคงที่ยังไม่มี แล้วข้อความจะขึ้นเป็น
       ชื่อค่าคงที่ดิบ ๆ ในเมลที่ส่งออกไปแล้ว */
    require_once dirname(__FILE__) . '/approve_link.php';

    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'request') { return 0; }

    $people = user_approvers_for_dept($doc['department_id'], $actor);
    if (count($people) === 0) { return 0; }

    $who     = ($doc['requester_name'] !== '') ? $doc['requester_name'] : $doc['requester'];
    $summary = notify_items_summary($doc['items']);

    return notify_deliver($people, array(
        'type'      => 'request_waiting',
        'title'     => 'ใบเบิก ' . $doc['doc_no'] . ' รอคุณอนุมัติ',
        'message'   => $who . ' (' . $doc['department_name'] . ') ขอเบิก ' . $summary,
        'link_page' => notify_request_page($doc),
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[รออนุมัติ] ใบเบิก ' . $doc['doc_no'] . ' จาก ' . $who,
        'heading'   => 'มีใบเบิกรอการอนุมัติ',
        'button'    => 'เปิดหน้าอนุมัติ',
        'ref_type'  => 'request_waiting',

        /* ปุ่มในเมลพาไปหน้าอนุมัติที่กดได้เลยโดยไม่ต้องล็อกอิน
           ของเดิมชี้เข้าหน้าในระบบ ผู้อนุมัติที่อ่านเมลจากมือถือจึงต้องพิมพ์
           รหัสผ่านบนจอเล็ก ๆ ก่อน ส่วนใหญ่เลยเลื่อนไว้ทำทีหลังแล้วลืม
           กุญแจออกให้เป็นรายคน เมลแต่ละฉบับจึงมีปุ่มคนละอัน */
        'approve_link' => true,

        'body_html' =>
              '<p>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8')
            . ' ส่งใบเบิกเข้ามาให้คุณพิจารณา '
            . 'กดปุ่มด้านล่างเพื่ออนุมัติหรือไม่อนุมัติได้เลย ไม่ต้องเข้าสู่ระบบ</p>'
            . mail_detail_table(notify_doc_pairs($doc))
            . notify_items_html($doc['items'])
            . '<p style="font-size:13px;color:#94a3b8;margin-top:16px">'
            . 'ลิงก์นี้ใช้ได้ครั้งเดียวและหมดอายุใน ' . (int)APPROVE_LINK_DAYS . ' วัน '
            . 'กรุณาอย่าส่งต่อให้ผู้อื่น เพราะผู้ที่ถือลิงก์จะตัดสินใบนี้ในนามของคุณได้</p>'
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 1ข — ระบบสวัสดิการส่งใบเบิกชุดฟอร์มเข้ามา (backend/welfare_bridge.php)
 *  ปลายทาง: ผู้ดูแลระบบ (คนคลัง)
 *
 *  ใบนี้ออกมา "อนุมัติแล้ว" และผู้ขอคือบัญชีระบบ hl-welfare
 *  เหตุการณ์ที่ 1 (รออนุมัติ) กับ 2 (ตัดสินแล้ว) จึงไม่ถึงใครเลย
 *  ถ้าไม่เตือนตรงนี้ คลังจะรู้ว่ามีใบรอจ่ายก็ต่อเมื่อเปิดแดชบอร์ดเอง
 * ============================================================ */
function notify_event_welfare_request($doc_id, $actor, $by_name)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'request') { return 0; }

    $people = user_admins($actor);
    if (count($people) === 0) { return 0; }

    $kind     = !empty($doc['is_new_staff']) ? 'ใบเบิกพนักงานใหม่' : 'ใบเบิกชุดฟอร์ม';
    $employee = !empty($doc['welfare']) ? $doc['welfare']['employee_name'] : '';
    $by       = (trim($by_name) !== '') ? trim($by_name) : 'ระบบสวัสดิการ';

    $extra = array(array('ส่งโดย', $by . ' (ระบบสวัสดิการ)'));
    if ($employee !== '') { $extra[] = array('พนักงานที่รับ', $employee); }

    return notify_deliver($people, array(
        'type'      => 'welfare_request',
        'title'     => $kind . ' ' . $doc['doc_no'] . ' รอคลังจ่าย',
        'message'   => 'ระบบสวัสดิการส่งเบิกให้ ' . ($employee !== '' ? $employee : '-') . ' (อนุมัติแล้ว) — '
                     . notify_items_summary($doc['items']),
        'link_page' => notify_request_page($doc),
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[รอจ่าย] ' . $kind . ' ' . $doc['doc_no'] . ' จากระบบสวัสดิการ',
        'heading'   => 'มีใบเบิกจากระบบสวัสดิการรอจ่าย',
        'button'    => 'เปิดใบเบิก',
        'ref_type'  => 'welfare_request',
        'body_html' =>
              '<p>ระบบสวัสดิการส่ง' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8')
            . 'เข้ามา ใบนี้<b>อนุมัติอัตโนมัติแล้ว</b> เพราะผ่านการตรวจสิทธิ์จากระบบสวัสดิการ '
            . 'จัดของและบันทึกจ่ายได้เลย</p>'
            . mail_detail_table(notify_doc_pairs($doc, $extra))
            . notify_items_html($doc['items'])
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 2 — ผู้อนุมัติตัดสินใบเบิก
 *  ปลายทาง: ผู้ขอเบิก
 * ============================================================ */
function notify_event_request_decided($doc_id, $decision, $reason, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'request') { return 0; }

    $people = notify_person($doc['requester']);
    if (count($people) === 0) { return 0; }

    $approver = user_find($actor);
    $by       = ($approver !== null && $approver['full_name'] !== '')
                ? $approver['full_name'] : $actor;

    $approved = ($decision === 'approved');

    $extra = array(array('ผู้ตัดสิน', $by));
    if (trim($reason) !== '') {
        $extra[] = array($approved ? 'หมายเหตุจากผู้อนุมัติ' : 'เหตุผลที่ไม่อนุมัติ', $reason);
    }

    /* ข้อความต่อจากนี้ต่างกันคนละเรื่อง จึงเขียนแยกไม่ใช่ใช้ประโยคเดียวแล้วสลับคำ
       อนุมัติแล้ว = รอของ ยังไม่ต้องทำอะไร
       ไม่อนุมัติ  = ต้องลงมือแก้แล้วยื่นใหม่ */
    if ($approved) {
        $body = '<p>ใบเบิกของคุณผ่านการอนุมัติแล้ว ขั้นต่อไปคลังจะจัดของและบันทึกจ่ายออก '
              . 'ระบบจะแจ้งคุณอีกครั้งเมื่อของพร้อมให้มาเซ็นรับ</p>';
    } else {
        $body = '<p>ใบเบิกของคุณไม่ได้รับอนุมัติ กรุณาดูเหตุผลด้านล่าง '
              . 'แก้ไขแล้วทำใบเบิกใหม่ได้ทันที ใบเดิมจะถูกปิดไว้เป็นประวัติ</p>';
    }

    return notify_deliver($people, array(
        'type'      => $approved ? 'request_approved' : 'request_rejected',
        'title'     => ($approved ? 'อนุมัติใบเบิก ' : 'ไม่อนุมัติใบเบิก ') . $doc['doc_no'],
        'message'   => $approved
                       ? 'ผู้อนุมัติเห็นชอบแล้ว รอคลังจ่ายของ'
                       : 'เหตุผล: ' . ($reason !== '' ? $reason : 'ไม่ได้ระบุ'),
        'link_page' => notify_request_page($doc),
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => ($approved ? '[อนุมัติแล้ว] ' : '[ไม่อนุมัติ] ')
                     . 'ใบเบิก ' . $doc['doc_no'],
        'heading'   => $approved ? 'ใบเบิกได้รับการอนุมัติ' : 'ใบเบิกไม่ได้รับการอนุมัติ',
        'button'    => 'เปิดใบเบิก',
        'ref_type'  => $approved ? 'request_approved' : 'request_rejected',
        'body_html' => $body
                     . mail_detail_table(notify_doc_pairs($doc, $extra))
                     . notify_items_html($doc['items'])
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 2ก — ผู้ทำเบิกแก้ไขใบที่ยัง "รออนุมัติ" แล้วยื่นใหม่
 *  ปลายทาง: ผู้อนุมัติของหน่วยงาน (มีกุญแจอนุมัติในเมลชุดใหม่ ของเดิมถูกเผาแล้ว)
 * ============================================================ */
function notify_event_request_edited($doc_id, $actor)
{
    /* โหลดก่อนประกอบข้อความ เพราะเนื้อเมลอ้าง APPROVE_LINK_DAYS
       เหตุผลเดียวกับ notify_event_request_submitted */
    require_once dirname(__FILE__) . '/approve_link.php';

    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'request') { return 0; }

    $people = user_approvers_for_dept($doc['department_id'], $actor);
    if (count($people) === 0) { return 0; }

    $who     = ($doc['requester_name'] !== '') ? $doc['requester_name'] : $doc['requester'];
    $summary = notify_items_summary($doc['items']);

    return notify_deliver($people, array(
        'type'      => 'request_edited',
        'title'     => 'ใบเบิก ' . $doc['doc_no'] . ' ถูกแก้ไข รอพิจารณาใหม่',
        'message'   => $who . ' (' . $doc['department_name'] . ') แก้ไขใบเบิกแล้วยื่นใหม่ ' . $summary,
        'link_page' => notify_request_page($doc),
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[แก้ไขแล้ว] ใบเบิก ' . $doc['doc_no'] . ' จาก ' . $who,
        'heading'   => 'ใบเบิกถูกแก้ไข กรุณาพิจารณาใหม่',
        'button'    => 'เปิดหน้าอนุมัติ',
        'ref_type'  => 'request_edited',

        /* กุญแจในเมลอนุมัติรอบก่อนถือ snapshot รายการเก่า และถูก
           approve_link_close_doc() เผาทิ้งตอนบันทึกการแก้ไขแล้ว
           เมลฉบับนี้จึงต้องออกกุญแจใหม่ให้ผู้อนุมัติแต่ละคน */
        'approve_link' => true,

        'body_html' =>
              '<p>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8')
            . ' แก้ไขใบเบิกที่เคยยื่นไว้ และส่งกลับเข้ามาให้คุณพิจารณาใหม่ '
            . 'กรุณาตรวจรายการด้านล่างอีกครั้งก่อนอนุมัติ</p>'
            . mail_detail_table(notify_doc_pairs($doc))
            . notify_items_html($doc['items'])
            . '<p style="font-size:13px;color:#94a3b8;margin-top:16px">'
            . 'ลิงก์อนุมัติที่ส่งไปในเมลรอบก่อนถูกยกเลิกแล้ว กรุณาใช้ปุ่มในเมลฉบับนี้ '
            . 'ลิงก์ใหม่ใช้ได้ครั้งเดียวและหมดอายุใน ' . (int)APPROVE_LINK_DAYS . ' วัน</p>'
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 2ข — ผู้อนุมัติถอยใบที่ "อนุมัติแล้ว" กลับให้ผู้ทำเบิกแก้
 *  ปลายทาง: ผู้ขอเบิก
 * ============================================================ */
function notify_event_request_returned($doc_id, $reason, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'request') { return 0; }

    $people = notify_person($doc['requester']);
    if (count($people) === 0) { return 0; }

    $returner = user_find($actor);
    $by       = ($returner !== null && $returner['full_name'] !== '')
                ? $returner['full_name'] : $actor;

    $reason = trim((string)$reason);
    $extra  = array(array('ผู้ส่งกลับ', $by));
    if ($reason !== '') {
        $extra[] = array('สิ่งที่ต้องแก้', $reason);
    }

    return notify_deliver($people, array(
        'type'      => 'request_returned',
        'title'     => 'ส่งกลับใบเบิก ' . $doc['doc_no'] . ' ให้แก้ไข',
        'message'   => $reason !== '' ? 'เหตุผล: ' . $reason : 'ผู้อนุมัติส่งใบเบิกกลับมาให้แก้ไข',
        'link_page' => notify_request_page($doc),
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[ส่งกลับแก้ไข] ใบเบิก ' . $doc['doc_no'],
        'heading'   => 'ใบเบิกถูกส่งกลับมาให้แก้ไข',
        'button'    => 'เปิดใบเบิก',
        'ref_type'  => 'request_returned',
        'body_html' =>
              '<p>ผู้อนุมัติถอยใบเบิกที่อนุมัติไปแล้วกลับมาให้คุณแก้ไข '
            . 'ใบนี้กลับไปอยู่สถานะ "รออนุมัติ" — เปิดใบเบิกแล้วกดแก้ไข '
            . 'แก้เสร็จกดยื่นใหม่ ผู้อนุมัติจะพิจารณาอีกครั้ง</p>'
            . mail_detail_table(notify_doc_pairs($doc, $extra))
            . notify_items_html($doc['items'])
    ));
}


/* ---------- คู่ข้อมูลหัวใบสำหรับใบปรับยอด (ไม่มีหน่วยงาน/ผู้ขอเบิก) ---------- */
function notify_adjust_pairs($doc, $extra = array())
{
    $pairs = array(
        array('เลขที่เอกสาร',  $doc['doc_no']),
        array('วันที่ตรวจนับ', thai_date_long($doc['doc_date'])),
        array('ผู้บันทึก',     $doc['created_by'] !== '' ? $doc['created_by'] : '-')
    );
    for ($i = 0; $i < count($extra); $i++) { $pairs[] = $extra[$i]; }
    if (trim($doc['note']) !== '') {
        $pairs[] = array('เหตุผล', $doc['note']);
    }
    return $pairs;
}


/* ============================================================
 *  ใบปรับยอด — ยื่นเข้ามารออนุมัติ
 *  ปลายทาง: คนในรายชื่อผู้อนุมัติ (มีลิงก์อนุมัติในเมลแบบเดียวกับใบเบิก)
 *
 *  ยังไม่ได้ตั้งผู้อนุมัติ -> ไม่ส่งอะไรเลย ปล่อยใบค้างเป็น "รออนุมัติ"
 *  ในหน้าประวัติ ผู้ดูแลระบบเปิดหน้านั้นก็เห็นเอง ไม่ต้องมีเมลกวน
 * ============================================================ */
function notify_event_adjust_submitted($doc_id, $actor)
{
    require_once dirname(__FILE__) . '/adjust_approver.php';
    require_once dirname(__FILE__) . '/approve_link.php';   // APPROVE_LINK_DAYS ในเนื้อเมล

    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'adjust') { return 0; }

    $by      = user_find($actor);
    $who     = ($by !== null && $by['full_name'] !== '') ? $by['full_name'] : $actor;
    $summary = notify_items_summary($doc['items']);

    /* ผู้รับ = รายชื่อผู้อนุมัติที่ตั้งไว้ (ตัดคนยื่นออก) */
    $names  = adjust_approver_usernames();
    $people = array();
    for ($i = 0; $i < count($names); $i++) {
        if ($names[$i] === $actor) { continue; }
        $p = notify_person($names[$i]);
        if (count($p) > 0) { $people[] = $p[0]; }
    }

    /* ยังไม่ได้ตั้งผู้อนุมัติ = ไม่มีใครให้ส่งหา ปล่อยใบค้างไว้ในหน้าประวัติ ไม่ส่งเมล */
    if (count($people) === 0) { return 0; }

    /* ส่งลิงก์กดอนุมัติได้เลยไม่ต้องล็อกอิน (รายคน) แบบใบเบิก */
    return notify_deliver($people, array(
        'type'      => 'adjust_waiting',
        'title'     => 'ใบปรับยอด ' . $doc['doc_no'] . ' รอคุณอนุมัติ',
        'message'   => $who . ' ยื่นปรับยอด ' . $summary,
        'link_page' => 'adjust_history',
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[รออนุมัติ] ใบปรับยอด ' . $doc['doc_no'] . ' จาก ' . $who,
        'heading'   => 'มีใบปรับยอดรอการอนุมัติ',
        'button'    => 'เปิดหน้าอนุมัติ',
        'ref_type'  => 'adjust_waiting',
        'approve_link' => true,
        'body_html' =>
              '<p>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8')
            . ' ยื่นใบปรับยอดเข้ามาให้คุณพิจารณา '
            . 'กดปุ่มด้านล่างเพื่ออนุมัติหรือไม่อนุมัติได้เลย ไม่ต้องเข้าสู่ระบบ '
            . 'ยอดคงเหลือจะเปลี่ยนก็ต่อเมื่อได้รับอนุมัติ</p>'
            . mail_detail_table(notify_adjust_pairs($doc))
            . notify_adjust_lines_html($doc)
            . '<p style="font-size:13px;color:#94a3b8;margin-top:16px">'
            . 'ลิงก์นี้ใช้ได้ครั้งเดียวและหมดอายุใน ' . (int)APPROVE_LINK_DAYS . ' วัน '
            . 'กรุณาอย่าส่งต่อให้ผู้อื่น เพราะผู้ที่ถือลิงก์จะตัดสินใบนี้ในนามของคุณได้</p>'
    ));
}


/* ---------- ตารางบรรทัดของใบปรับยอดสำหรับเนื้อเมล — ยอดเดิม / นับได้ / ส่วนต่าง ---------- */
function notify_adjust_lines_html($doc)
{
    require_once dirname(__FILE__) . '/adjust.php';
    $lines = adjust_doc_lines($doc);
    if (count($lines) === 0) { return ''; }

    $th = 'style="padding:7px 8px;background:#f1f5f9;color:#475569;font-weight:600;border-bottom:1px solid #e2e8f0"';
    $td = 'style="padding:6px 8px;border-bottom:1px solid #f1f5f9;font-size:14px"';

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
          . 'style="width:100%;margin:14px 0 4px;font-size:14px;border-collapse:collapse">'
          . '<tr><th align="left" ' . $th . '>รายการ</th>'
          . '<th align="right" ' . $th . '>ยอดเดิม</th>'
          . '<th align="right" ' . $th . '>นับได้จริง</th>'
          . '<th align="right" ' . $th . '>ส่วนต่าง</th></tr>';

    for ($i = 0; $i < count($lines); $i++) {
        $ln   = $lines[$i];
        $diff = (float)$ln['diff'];
        $sign = ($diff > 0) ? '+' : '';
        $col  = ($diff > 0) ? '#059669' : (($diff < 0) ? '#dc2626' : '#64748b');
        $html .= '<tr>'
              . '<td ' . $td . '>' . htmlspecialchars($ln['product_name'], ENT_QUOTES, 'UTF-8')
              . ' <span style="color:#94a3b8">' . htmlspecialchars($ln['product_code'], ENT_QUOTES, 'UTF-8') . '</span></td>'
              . '<td align="right" ' . $td . '>' . stock_format($ln['before']) . '</td>'
              . '<td align="right" ' . $td . '>' . stock_format($ln['counted']) . '</td>'
              . '<td align="right" ' . $td . ' color:' . $col . '"><b style="color:' . $col . '">'
              . $sign . stock_format($diff) . '</b></td>'
              . '</tr>';
    }
    return $html . '</table>';
}


/* ============================================================
 *  ใบปรับยอด — ผู้อนุมัติตัดสิน
 *  ปลายทาง: ผู้สร้างใบ
 * ============================================================ */
function notify_event_adjust_decided($doc_id, $decision, $reason, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'adjust') { return 0; }

    $people = notify_person($doc['created_by']);
    if (count($people) === 0) { return 0; }

    $approver = user_find($actor);
    $by       = ($approver !== null && $approver['full_name'] !== '') ? $approver['full_name'] : $actor;
    $approved = ($decision === 'approved');

    $extra = array(array('ผู้ตัดสิน', $by));
    if (trim($reason) !== '') {
        $extra[] = array($approved ? 'หมายเหตุจากผู้อนุมัติ' : 'เหตุผลที่ไม่อนุมัติ', $reason);
    }

    $body = $approved
          ? '<p>ใบปรับยอดของคุณได้รับอนุมัติแล้ว ยอดคงเหลือถูกปรับตามที่นับไว้เรียบร้อย</p>'
          : '<p>ใบปรับยอดของคุณไม่ได้รับอนุมัติ ยอดคงเหลือไม่เปลี่ยน กรุณาดูเหตุผลด้านล่าง แล้วทำใบใหม่ได้</p>';

    return notify_deliver($people, array(
        'type'      => $approved ? 'adjust_approved' : 'adjust_rejected',
        'title'     => ($approved ? 'อนุมัติใบปรับยอด ' : 'ไม่อนุมัติใบปรับยอด ') . $doc['doc_no'],
        'message'   => $approved
                       ? 'ผู้อนุมัติเห็นชอบแล้ว ยอดคงเหลือถูกปรับเรียบร้อย'
                       : 'เหตุผล: ' . ($reason !== '' ? $reason : 'ไม่ได้ระบุ'),
        'link_page' => 'adjust_history',
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => ($approved ? '[อนุมัติแล้ว] ' : '[ไม่อนุมัติ] ') . 'ใบปรับยอด ' . $doc['doc_no'],
        'heading'   => $approved ? 'ใบปรับยอดได้รับการอนุมัติ' : 'ใบปรับยอดไม่ได้รับการอนุมัติ',
        'button'    => 'เปิดใบปรับยอด',
        'ref_type'  => $approved ? 'adjust_approved' : 'adjust_rejected',
        'body_html' => $body
                     . mail_detail_table(notify_adjust_pairs($doc, $extra))
                     . notify_items_html($doc['items'])
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 3 — คลังบันทึกจ่ายของ
 *  ปลายทาง: ผู้ขอเบิก (ต้องมาเซ็นรับ)
 *           ใบจากระบบสวัสดิการ -> HR ที่กดส่ง หรือคลัง (ดู เหตุการณ์ที่ 3ข)
 * ============================================================ */
function notify_event_issue_created($doc_id, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'issue') { return 0; }

    $summary = notify_items_summary($doc['items']);

    $extra = array();
    if ($doc['ref_doc_no'] !== '') {
        $extra[] = array('อ้างอิงใบเบิก', $doc['ref_doc_no']);
    }

    /* ใบเบิกจากระบบสวัสดิการ ผู้ขอคือบัญชีระบบ hl-welfare ไม่ใช่คนจริง
       ถ้าส่งตามทางปกติ ลิงก์เซ็นรับจะไปค้างที่บัญชีที่ไม่มีใครเปิด — แยกไปทางของมันเอง */
    $req = ($doc['ref_doc_id'] > 0) ? doc_find($doc['ref_doc_id']) : null;
    if ($req !== null && !empty($req['is_welfare'])) {
        return notify_event_welfare_issue_created($doc, $req, $extra, $summary, $actor);
    }

    $people = notify_person($doc['requester']);
    if (count($people) === 0) { return 0; }

    return notify_deliver($people, array(
        'type'      => 'issue_created',
        'title'     => 'ของพร้อมให้รับแล้ว — ใบจ่าย ' . $doc['doc_no'],
        'message'   => $summary . ' กรุณามารับของและเซ็นรับในระบบ',
        'link_page' => 'issue_history',
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[รับของ] ใบจ่าย ' . $doc['doc_no']
                     . ($doc['ref_doc_no'] !== '' ? ' ตามใบเบิก ' . $doc['ref_doc_no'] : ''),
        'heading'   => 'คลังจ่ายของให้แล้ว',
        'button'    => 'เปิดหน้าเซ็นรับของ',
        'ref_type'  => 'issue_created',
        /* ฉบับเดียวในระบบที่ใช้ลิงก์แบบไม่ต้องล็อกอิน เพราะปลายทางคือผู้ขอเบิก
           ซึ่งส่วนใหญ่ไม่มีบัญชีหรือไม่มีสิทธิ์เข้าหน้าประวัติการจ่ายออก */
        'sign_link' => true,
        'body_html' =>
              '<p>คลังบันทึกจ่ายของตามใบเบิกของคุณเรียบร้อยแล้ว '
            . 'เมื่อรับของครบ กรุณากดปุ่มด้านล่างเพื่อเซ็นรับ '
            . 'ไม่ต้องเข้าสู่ระบบ เปิดจากมือถือได้เลย</p>'
            . '<p style="color:#64748b;font-size:13px;margin:8px 0 0">'
            . 'ลิงก์นี้ใช้ได้เฉพาะใบจ่ายใบนี้ และหมดอายุใน 7 วัน '
            . 'กรุณาอย่าส่งต่อให้ผู้อื่น เพราะผู้ที่ได้รับลิงก์เซ็นรับแทนคุณได้</p>'
            . mail_detail_table(notify_doc_pairs($doc, $extra))
            . notify_items_html($doc['items'])
    ));
}


/* ---------- ใครควรได้ลิงก์เซ็นรับของใบจากระบบสวัสดิการ ----------
 * คืน array('people' => ..., 'is_hr' => bool)
 *
 *   1) HR ที่กดส่งในระบบสวัสดิการ — ถ้ามีบัญชีที่ใช้งานอยู่ในระบบนี้
 *      ชื่อผู้ใช้ตรงกับ tb_doc_welfare.requested_by_user (ดู sql/079)
 *   2) ไม่อย่างนั้นผู้ดูแลระบบ (คนคลัง) — ให้พนักงานเซ็นที่เคาน์เตอร์ หรือส่งลิงก์ต่อให้
 *      ครอบคลุมใบเก่าที่ยังไม่มีชื่อผู้ใช้, HR ที่ไม่มีบัญชีที่นี่, และเครื่องที่ยังไม่ได้รัน 079
 *
 * query ใช้ @ และถอยไปทางที่ 2 เมื่อพัง ตามกติกาเหล็กที่หัวไฟล์
 */
function notify_welfare_sign_people($req, $actor)
{
    $connect = db_connect();
    $stmt = @mysqli_prepare($connect,
        "SELECT `requested_by_user` FROM `tb_doc_welfare` WHERE `doc_id` = ? LIMIT 1");

    $by_user = '';
    if ($stmt) {
        $id = (int)$req['doc_id'];
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (@mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $by_user);
            if (!mysqli_stmt_fetch($stmt)) { $by_user = ''; }
        }
        mysqli_stmt_close($stmt);
    }
    $by_user = trim((string)$by_user);

    /* ต้องเป็นคนจริงที่ยังใช้งานอยู่ ไม่ใช่บัญชีระบบเอง */
    if ($by_user !== '' && $by_user !== $req['requester']) {
        $u = user_find($by_user);
        if ($u !== null && (int)$u['is_active'] === 1) {
            return array('people' => notify_person($by_user), 'is_hr' => true);
        }
    }

    return array('people' => user_admins($actor), 'is_hr' => false);
}


/* ---------- เหตุการณ์ที่ 3ข — คลังจ่ายของตามใบจากระบบสวัสดิการ ----------
 * ผู้รับของจริงคือพนักงานในใบ (tb_doc_welfare.employee_name) ซึ่งไม่มีบัญชีในระบบนี้
 * จึงส่งลิงก์เซ็นรับให้คนกลางที่ส่งต่อได้ (ดู notify_welfare_sign_people)
 * ลิงก์ยังเป็นกุญแจ sign.php แบบไม่ต้องล็อกอิน เปิดให้พนักงานเซ็นบนมือถือได้เลย
 */
function notify_event_welfare_issue_created($doc, $req, $extra, $summary, $actor)
{
    $to = notify_welfare_sign_people($req, $actor);
    if (count($to['people']) === 0) { return 0; }

    $employee = $req['welfare']['employee_name'];
    $by_name  = $req['welfare']['requested_by_name'];

    $extra[] = array('พนักงานที่รับ', $employee);
    if ($by_name !== '') { $extra[] = array('ส่งโดย', $by_name . ' (ระบบสวัสดิการ)'); }

    $intro = $to['is_hr']
        ? 'คลังจ่ายของตามใบเบิกที่คุณส่งจากระบบสวัสดิการเรียบร้อยแล้ว '
        : 'คลังจ่ายของตามใบเบิกจากระบบสวัสดิการเรียบร้อยแล้ว '
        . '(ส่งถึงผู้ดูแลระบบ เพราะไม่พบบัญชีของ HR ที่กดส่งในระบบนี้) ';

    return notify_deliver($to['people'], array(
        'type'      => 'issue_created',
        'title'     => 'ของพร้อมให้รับแล้ว — ใบจ่าย ' . $doc['doc_no'] . ' (' . $employee . ')',
        'message'   => $summary . ' — ให้ ' . $employee . ' เซ็นรับ หรือส่งลิงก์เซ็นรับให้พนักงาน',
        'link_page' => 'issue_history',
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[รับของ] ใบจ่าย ' . $doc['doc_no'] . ' ของ ' . $employee
                     . ($doc['ref_doc_no'] !== '' ? ' ตามใบเบิก ' . $doc['ref_doc_no'] : ''),
        'heading'   => 'คลังจ่ายของให้พนักงานแล้ว',
        'button'    => 'เปิดหน้าเซ็นรับของ',
        'ref_type'  => 'issue_created',
        'sign_link' => true,
        'body_html' =>
              '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')
            . 'เมื่อ <b>' . htmlspecialchars($employee, ENT_QUOTES, 'UTF-8') . '</b> รับของครบ '
            . 'ให้พนักงานกดปุ่มด้านล่างเพื่อเซ็นรับ — เปิดให้เซ็นบนมือถือ '
            . 'หรือส่งต่อลิงก์ให้พนักงานก็ได้ ไม่ต้องเข้าสู่ระบบ</p>'
            . '<p style="color:#64748b;font-size:13px;margin:8px 0 0">'
            . 'ลิงก์นี้ใช้ได้เฉพาะใบจ่ายใบนี้ และหมดอายุใน 7 วัน '
            . 'ส่งต่อให้เฉพาะพนักงานที่รับของเท่านั้น เพราะผู้ที่ได้รับลิงก์เซ็นรับแทนได้</p>'
            . mail_detail_table(notify_doc_pairs($doc, $extra))
            . notify_items_html($doc['items'])
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 4 — ผู้เบิกเซ็นรับของ
 *  ปลายทาง: คนที่ทำใบจ่าย (คลัง) — เป็นการปิดวงรอบให้คนคลังรู้ว่างานจบ
 * ============================================================ */
function notify_event_issue_acknowledged($doc_id, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null || $doc['doc_type'] !== 'issue') { return 0; }

    $people = notify_person($doc['created_by']);
    if (count($people) === 0) { return 0; }

    $who = ($doc['receiver_name'] !== '') ? $doc['receiver_name'] : $doc['requester_name'];

    return notify_deliver($people, array(
        'type'      => 'issue_acknowledged',
        'title'     => 'เซ็นรับของแล้ว — ใบจ่าย ' . $doc['doc_no'],
        'message'   => $who . ' เซ็นรับของเรียบร้อย รายการนี้ปิดแล้ว',
        'link_page' => 'issue_history',
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[ปิดรายการ] ใบจ่าย ' . $doc['doc_no'] . ' เซ็นรับแล้ว',
        'heading'   => 'ผู้เบิกเซ็นรับของแล้ว',
        'button'    => 'เปิดใบจ่าย',
        'ref_type'  => 'issue_acknowledged',
        'body_html' =>
              '<p>ใบจ่ายที่คุณบันทึกไว้ถูกเซ็นรับเรียบร้อยแล้ว รายการนี้ถือว่าปิดสมบูรณ์</p>'
            . mail_detail_table(notify_doc_pairs($doc, array(
                  array('ผู้เซ็นรับ', $who),
                  array('เวลาที่เซ็นรับ', $doc['received_at'])
              )))
    ));
}


/* ============================================================
 *  เหตุการณ์ที่ 5 — ยกเลิกเอกสาร
 *  ปลายทาง: อีกฝ่ายที่ไม่ใช่คนกด
 *    ผู้ขอยกเลิกเอง  -> เตือนผู้อนุมัติที่กำลังจะต้องตัดสิน จะได้ไม่เสียเวลาเปิดดู
 *    ผู้ดูแลยกเลิกให้ -> เตือนผู้ขอ เพราะเป็นคนที่เสียประโยชน์และต้องรู้เหตุผล
 * ============================================================ */
function notify_event_doc_cancelled($doc_id, $reason, $actor)
{
    $doc = doc_find($doc_id);
    if ($doc === null) { return 0; }

    /* ---------- ใบปรับยอด: เตือนผู้สร้างใบ (ถ้าไม่ใช่คนกดยกเลิกเอง) ---------- */
    if ($doc['doc_type'] === 'adjust') {
        $people = ($doc['created_by'] !== '' && $doc['created_by'] !== $actor)
                  ? notify_person($doc['created_by']) : array();
        if (count($people) === 0) { return 0; }

        return notify_deliver($people, array(
            'type'      => 'adjust_rejected',
            'title'     => 'ยกเลิกใบปรับยอด ' . $doc['doc_no'],
            'message'   => 'เหตุผล: ' . (trim($reason) !== '' ? $reason : 'ไม่ได้ระบุ'),
            'link_page' => 'adjust_history',
            'doc_id'    => $doc['doc_id'],
            'actor'     => $actor,
            'subject'   => '[ยกเลิก] ใบปรับยอด ' . $doc['doc_no'],
            'heading'   => 'มีการยกเลิกใบปรับยอด',
            'button'    => 'เปิดเอกสาร',
            'ref_type'  => 'adjust_rejected',
            'body_html' => '<p>ใบปรับยอดที่คุณสร้างไว้ถูกยกเลิก กรุณาดูเหตุผลด้านล่าง</p>'
                         . mail_detail_table(array(
                               array('เลขที่เอกสาร', $doc['doc_no']),
                               array('วันที่ตรวจนับ', thai_date_long($doc['doc_date'])),
                               array('ผู้ยกเลิก', $actor),
                               array('เหตุผล', trim($reason) !== '' ? $reason : 'ไม่ได้ระบุ')
                           ))
        ));
    }

    if ($doc['doc_type'] !== 'request' && $doc['doc_type'] !== 'issue') { return 0; }

    $is_request = ($doc['doc_type'] === 'request');

    if ($is_request && $doc['requester'] === $actor) {
        $people    = user_approvers_for_dept($doc['department_id'], $actor);
        $link_page = notify_request_page($doc);
        $lead      = '<p>ผู้ขอเบิกยกเลิกใบนี้เอง ไม่ต้องพิจารณาแล้ว</p>';
    } else {
        $people    = notify_person($doc['requester']);
        $link_page = $is_request ? notify_request_page($doc) : 'issue_history';
        $lead      = '<p>เอกสารของคุณถูกยกเลิก กรุณาดูเหตุผลด้านล่าง</p>';
    }

    if (count($people) === 0) { return 0; }

    $label = $is_request ? 'ใบเบิก' : 'ใบจ่าย';

    return notify_deliver($people, array(
        'type'      => $is_request ? 'request_cancelled' : 'issue_cancelled',
        'title'     => 'ยกเลิก' . $label . ' ' . $doc['doc_no'],
        'message'   => 'เหตุผล: ' . (trim($reason) !== '' ? $reason : 'ไม่ได้ระบุ'),
        'link_page' => $link_page,
        'doc_id'    => $doc['doc_id'],
        'actor'     => $actor,
        'subject'   => '[ยกเลิก] ' . $label . ' ' . $doc['doc_no'],
        'heading'   => 'มีการยกเลิก' . $label,
        'button'    => 'เปิดเอกสาร',
        'ref_type'  => $is_request ? 'request_cancelled' : 'issue_cancelled',
        'body_html' => $lead
                     . mail_detail_table(notify_doc_pairs($doc, array(
                           array('ผู้ยกเลิก', $actor),
                           array('เหตุผล', trim($reason) !== '' ? $reason : 'ไม่ได้ระบุ')
                       )))
    ));
}
