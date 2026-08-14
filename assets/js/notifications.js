/* ============================================================
 *  notifications.js — หน้าการแจ้งเตือนทั้งหมด (pages/notifications.php)
 *
 *  โครงเดียวกับกล่องกระดิ่งใน app-notify.js: จัดกลุ่มตามวัน โหลดทีละ 10
 *  เลื่อนถึงท้ายแล้วโหลดต่อ
 *
 *  ---------------------------------------------------------------
 *  ทำไมไม่ใช้โค้ดวาดร่วมกับ app-notify.js
 *  ---------------------------------------------------------------
 *  สองที่นี้ตอบคนละคำถาม กล่องกระดิ่งตอบ "มีอะไรใหม่ไหม" จึงต้องแคบ เตี้ย
 *  และอ่านจบในสองวินาที ส่วนหน้านี้ตอบ "เรื่องเมื่อสองสัปดาห์ก่อนไปไหน"
 *  จึงมีตัวกรอง มีตัวเลขรวม และวางรายการเต็มความกว้าง
 *
 *  การบังคับให้ใช้ตัววาดตัวเดียวกันจะได้ฟังก์ชันที่มีธงเปิดปิดเต็มไปหมด
 *  ซึ่งอ่านยากกว่าการเขียนสองที่ให้ตรงกับงานของแต่ละที่
 *
 *  สิ่งที่ต้องใช้ร่วมกันจริง ๆ คือ API และกติกาการจัดกลุ่ม ซึ่งอยู่ที่
 *  api/notify.php กับ ThaiDate.dayKey/dayLabel อยู่แล้ว
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.NOTIPAGE;
    if (!CFG) { return; }

    var PAGE_SIZE = 10;

    var listEl = document.getElementById('ntList');
    /* กรอบที่เลื่อนได้ย้ายออกมาอยู่ชั้นนอกของ #ntList แล้ว (ดู pages/notifications.php)
       เพราะสวิตช์กับท้ายรายการต้องเลื่อนไปพร้อมกับรายการ ไม่ใช่ตรึงค้างไว้ */
    var scrollEl = document.getElementById('ntScroll') || listEl;
    var footEl = document.getElementById('ntFoot');
    var btnAll = document.getElementById('ntReadAll');
    var btnFil = document.getElementById('ntFilter');

    var nextId     = 0;
    var hasMore    = false;
    var loading    = false;
    var lastDay    = '';
    var onlyUnread = false;
    /* รอบล่าสุดมีรายการให้แสดงจริงไหม — ไม่ใช่ "ทั้งระบบมีกี่รายการ"
       สองอย่างนี้ต่างกันตอนกรองเฉพาะที่ยังไม่ได้อ่าน */
    var hasRows    = false;
    var total      = CFG.total  || 0;
    var unread     = CFG.unread || 0;


    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icons() {
        if (typeof window.renderIcons === 'function') { window.renderIcons(); }
        else if (window.lucide) { window.lucide.createIcons(); }
    }

    function post(payload) {
        payload.csrf_token = CFG.csrfToken;

        return fetch(CFG.endpoint, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }


    /* ---------- สีของไอคอนตามชนิดเรื่อง ----------
     * ชุดเดียวกับกล่องกระดิ่ง เพื่อให้แจ้งเตือนเรื่องเดียวกันหน้าตาเหมือนกัน
     * ทั้งสองที่ ไม่งั้นผู้ใช้จะนึกว่าเป็นคนละเรื่อง
     */
    function toneClass(tone) {
        var map = {
            brand:  'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
            green:  'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
            red:    'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300',
            amber:  'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
            indigo: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300',
            slate:  'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'
        };
        return map[tone] || map.slate;
    }

    function linkOf(n) {
        if (!n.link_page) { return ''; }

        var url = 'main.php?page=' + encodeURIComponent(n.link_page);
        if (n.link_doc_id > 0) { url += '&doc=' + n.link_doc_id; }
        return url;
    }


    /* ---------- หัวกลุ่มวันที่ ----------
     * ค้างไว้บนสุดขณะเลื่อน (sticky) เพราะรายการหน้านี้ยาวกว่าในกล่องกระดิ่งมาก
     * เลื่อนไปกลางกลุ่มแล้วต้องยังตอบได้ว่ากำลังอ่านของวันไหนอยู่
     */
    function dayHead(text) {
        return '<div class="nt-day"><span class="nt-day-text">' + escapeHtml(text) + '</span></div>';
    }


    function render(items, append) {
        if (!listEl) { return; }
        if (!append) { lastDay = ''; }

        if ((!items || items.length === 0) && !append) {
            listEl.innerHTML =
                '<div class="nt-empty">'
              + '<span class="nt-empty-icon">'
              + '<i data-lucide="' + (onlyUnread ? 'check-check' : 'bell-off') + '" class="h-8 w-8" aria-hidden="true"></i></span>'
              + '<p class="nt-empty-title">'
              + (onlyUnread ? 'อ่านครบทุกรายการแล้ว' : 'ยังไม่มีการแจ้งเตือน') + '</p>'
              + '<p class="nt-empty-hint">'
              + (onlyUnread ? 'กดปุ่ม "แสดงทั้งหมด" ด้านบนเพื่อกลับไปดูรายการที่อ่านแล้ว'
                            : 'เมื่อมีใบเบิกเข้ามาหรือเอกสารของคุณคืบหน้า จะขึ้นที่นี่') + '</p>'
              + '</div>';
            hasRows = false;
            icons();
            return;
        }

        hasRows = true;
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var n      = items[i];
            var isNew  = (n.is_read === 0);
            var target = linkOf(n);

            /* จับกลุ่มด้วยคีย์ที่ไม่ขึ้นกับว่าวันนี้วันอะไร แต่แสดงเป็น "วันนี้/เมื่อวาน"
               ถ้าจับกลุ่มด้วยข้อความที่แสดง พอข้ามเที่ยงคืนคาหน้าจอ
               กลุ่ม "วันนี้" กับกลุ่มวันที่จริงของวันเดียวกันจะแตกเป็นสองกลุ่ม */
            if (window.ThaiDate) {
                var key = window.ThaiDate.dayKey(n.created_at);
                if (key !== '' && key !== lastDay) {
                    html   += dayHead(window.ThaiDate.dayLabel(n.created_at));
                    lastDay = key;
                }
            }

            /* แต่ละรายการเป็นการ์ดของตัวเอง ไม่ใช่แถวในรายการยาว ๆ
               เพราะเนื้อหาสามบรรทัดต่อรายการ (หัวข้อ/รายละเอียด/เวลา) ยาวพอ
               ที่จะกลืนกันจนแยกไม่ออกว่าบรรทัดไหนเป็นของรายการเดียวกัน
               ถ้ามีแค่เส้นคั่นบาง ๆ กั้น */
            html +=
                '<button type="button" class="nt-card' + (isNew ? ' is-new' : '') + '"'
              + ' data-id="' + n.noti_id + '"'
              + ' data-href="' + escapeHtml(target) + '">'

              + '<span class="nt-card-icon ' + toneClass(n.tone) + '">'
              + '<i data-lucide="' + escapeHtml(n.icon) + '" class="h-5 w-5" aria-hidden="true"></i>'
              + '</span>'

              + '<span class="nt-card-body">'
              + '<span class="nt-card-top">'
              + '<span class="nt-card-title">' + escapeHtml(n.title) + '</span>'
              + (isNew ? '<span class="nt-dot" aria-label="ยังไม่ได้อ่าน"></span>' : '')
              + '</span>'
              + '<span class="nt-card-msg">' + escapeHtml(n.message) + '</span>'

              /* เวลาจริงกำกับไว้ด้วย ไม่ใช่แค่ "3 ชั่วโมงที่แล้ว"
                 หน้านี้ใช้ตามหาเรื่องย้อนหลัง ซึ่งต้องเทียบกับเวลาในเอกสารได้ */
              + '<span class="nt-card-time">'
              + '<i data-lucide="clock" class="h-3 w-3" aria-hidden="true"></i>'
              + escapeHtml(n.ago)
              + (window.ThaiDate ? ' · ' + escapeHtml(window.ThaiDate.dateTime(n.created_at, '')) : '')
              + '</span>'
              + '</span>'

              /* ลูกศรบอกว่ากดแล้วไปต่อได้ เฉพาะรายการที่มีปลายทางจริง
                 รายการที่ไม่มีลิงก์กดแล้วแค่ทำเครื่องหมายว่าอ่านแล้ว ไม่ควรชวนให้กด */
              + (target ? '<span class="nt-card-go" aria-hidden="true">'
                        + '<i data-lucide="chevron-right" class="h-4 w-4"></i></span>' : '')
              + '</button>';
        }

        if (append) { listEl.insertAdjacentHTML('beforeend', html); }
        else        { listEl.innerHTML = html; }

        icons();
    }


    /* ---------- ท้ายรายการ ----------
     * ข้อความ "แสดงครบทุกรายการแล้ว" มีประโยชน์ต่อเมื่อมีรายการอยู่จริง
     * ถ้ายังไม่มีอะไรเลย มันจะไปอยู่ใต้กล่อง "ยังไม่มีการแจ้งเตือน"
     * แล้วอ่านออกมาเป็นการยืนยันสิ่งที่เพิ่งบอกไปเมื่อบรรทัดที่แล้ว
     */
    function setFoot(state) {
        if (!footEl) { return; }

        if (state === 'loading') {
            footEl.innerHTML = '<div class="nt-foot"><i data-lucide="loader-circle"'
                             + ' class="h-3.5 w-3.5 animate-spin" aria-hidden="true"></i>กำลังโหลดเพิ่ม…</div>';
            icons();
            return;
        }

        if (!hasRows) { footEl.innerHTML = ''; return; }

        footEl.innerHTML = hasMore
            ? '<div class="nt-foot"><i data-lucide="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i>เลื่อนลงเพื่อดูเพิ่ม</div>'
            : '<div class="nt-foot nt-foot-end">แสดงครบทุกรายการแล้ว</div>';
        icons();
    }


    function setCounts(res) {
        if (typeof res.total === 'number')  { total  = res.total; }
        if (typeof res.unread === 'number') { unread = res.unread; }

        if (el('ntTotal'))  { el('ntTotal').textContent  = total; }
        if (el('ntUnread')) { el('ntUnread').textContent = unread; }
        if (el('ntUnreadWrap')) {
            el('ntUnreadWrap').classList.toggle('hidden', unread === 0);
        }
        if (btnAll) { btnAll.disabled = (unread === 0); }
    }


    function load(more) {
        if (loading) { return; }
        if (more && !hasMore) { return; }
        loading = true;

        if (!more) {
            nextId  = 0;
            hasMore = false;
            listEl.innerHTML =
                '<div class="nt-state">กำลังโหลด…</div>';
            if (footEl) { footEl.innerHTML = ''; }
        } else {
            setFoot('loading');
        }

        post({
            action:      'list',
            limit:       PAGE_SIZE,
            before_id:   more ? nextId : 0,
            only_unread: onlyUnread
        }).then(function (res) {
            loading = false;

            if (!res || !res.ok) {
                if (!more) {
                    listEl.innerHTML = '<div class="nt-state is-bad">'
                                     + escapeHtml(res && res.message ? res.message : 'โหลดไม่สำเร็จ') + '</div>';
                }
                setFoot();
                return;
            }

            hasMore = !!res.has_more;
            nextId  = res.next_id || nextId;
            setCounts(res);
            render(res.items, !!more);
            setFoot();

            /* ถ้าของหน้าแรกยังไม่สูงพอให้เกิดแถบเลื่อน จะไม่มีเหตุการณ์ scroll
               มาปลุกให้โหลดต่อ แล้วรายการจะค้างอยู่แค่ 10 รายการทั้งที่ยังมีอีก */
            if (!more) { window.setTimeout(fillIfShort, 0); }

        })['catch'](function () {
            loading = false;
            if (!more) {
                listEl.innerHTML =
                    '<div class="nt-state is-bad">เชื่อมต่อไม่ได้ กรุณาลองใหม่</div>';
            }
            setFoot();
        });
    }

    function fillIfShort() {
        if (!hasMore || loading || !scrollEl) { return; }
        if (scrollEl.scrollHeight <= scrollEl.clientHeight + 8) { load(true); }
    }

    function onScroll() {
        if (loading || !hasMore) { return; }
        if (scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 80) { load(true); }
    }


    /* ---------- กดที่รายการ ----------
     * ทำเครื่องหมายว่าอ่านแล้วโดยไม่รอผลจากเซิร์ฟเวอร์ แล้วค่อยพาไป
     * ผลเสียสูงสุดถ้าคำสั่งไม่ถึงคือจุดยังค้างอยู่ ซึ่งกดซ้ำได้
     */
    function onClick(e) {
        var row = e.target.closest ? e.target.closest('.nt-row') : null;
        if (!row) { return; }

        var id   = parseInt(row.getAttribute('data-id'), 10);
        var href = row.getAttribute('data-href');

        if (id > 0 && row.className.indexOf('bg-brand-50/40') !== -1) {
            post({ action: 'read', noti_id: id })['catch'](function () { });
            row.classList.remove('bg-brand-50/40', 'dark:bg-brand-500/5');

            var dot = row.querySelector('.bg-brand-500');
            if (dot) { dot.parentNode.removeChild(dot); }

            setCounts({ unread: unread > 0 ? unread - 1 : 0 });
        }

        if (href) { window.location.href = href; }
    }


    function readAll() {
        if (unread === 0) { return; }

        var run = function () {
            post({ action: 'read_all' })
                .then(function (res) {
                    if (res && res.ok) {
                        setCounts({ unread: 0 });
                        load();
                        if (typeof window.appSuccess === 'function') { window.appSuccess(res.message); }
                    }
                })['catch'](function () { });
        };

        if (typeof window.appConfirm === 'function') {
            window.appConfirm({
                title: 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด',
                text:  'แจ้งเตือนที่ค้างอยู่ ' + unread + ' รายการจะถูกทำเครื่องหมายว่าอ่านแล้ว '
                     + 'รายการยังอยู่ครบ แค่จุดสีจะหายไป',
                confirmText: 'ทำเครื่องหมายทั้งหมด'
            }).then(function (yes) { if (yes) { run(); } });
        } else {
            run();
        }
    }


    /* ---------- สลับระหว่างทั้งหมดกับเฉพาะที่ยังไม่ได้อ่าน ----------
     * กรองที่เซิร์ฟเวอร์ ไม่ใช่ซ่อนแถวที่โหลดมาแล้ว
     * เพราะโหลดมาทีละ 10 การซ่อนจะได้ผลลัพธ์ไม่ครบโดยที่ผู้ใช้ไม่รู้ตัว
     */
    function toggleFilter() {
        onlyUnread = !onlyUnread;

        btnFil.setAttribute('data-mode', onlyUnread ? 'unread' : 'all');
        btnFil.querySelector('.btn-label').textContent =
            onlyUnread ? 'เฉพาะที่ยังไม่ได้อ่าน' : 'แสดงทั้งหมด';

        load();
    }


    /* ---------- สวิตช์แจ้งเตือนนอกหน้าเว็บ ----------
     * มีทั้งที่นี่และในกล่องกระดิ่ง เพราะคนหาสวิตช์นี้ได้จากสองทาง
     * ที่นี่มีที่มากกว่า จึงมีปุ่ม "ทดสอบ" ให้กดดูว่าเด้งจริงไหมด้วย
     *
     * ปุ่มทดสอบสำคัญกว่าที่คิด: บางเครื่องอนุญาตแล้วแต่ระบบปฏิบัติการ
     * ปิดการแจ้งเตือนของเบราว์เซอร์ไว้อีกชั้น กดสวิตช์แล้วขึ้นว่าเปิดสำเร็จ
     * แต่ไม่มีอะไรเด้งจริง ซึ่งรู้ได้ทางเดียวคือลองเด้งดู
     */
    function paintDesk() {
        var box = el('ntDesk');
        var D   = window.DesktopNoti;
        if (!box || !D) { return; }

        var on   = D.isOn();
        var able = D.canTurnOn();

        box.innerHTML =
              '<button type="button" id="ntDeskBtn" class="noti-switch' + (on ? ' is-on' : '') + '"'
            + (able ? '' : ' disabled')
            + ' role="switch" aria-checked="' + (on ? 'true' : 'false') + '"'
            + ' aria-label="เปิดหรือปิดการแจ้งเตือนนอกหน้าเว็บ"><span class="noti-switch-dot"></span></button>'
            + '<span class="nt-desk-text">'
            + '<span class="nt-desk-title">แจ้งเตือนนอกหน้าเว็บ</span>'
            + '<span class="nt-desk-hint">' + escapeHtml(D.hint()) + '</span>'
            + '</span>'
            + (on ? '<button type="button" id="ntDeskTest" class="nt-desk-test">ทดสอบ</button>' : '');
    }

    function onDeskClick(e) {
        if (!e.target.closest) { return; }

        if (e.target.closest('#ntDeskTest')) {
            window.DesktopNoti.test();
            if (typeof window.appInfo === 'function') {
                window.appInfo('ส่งแจ้งเตือนทดสอบแล้ว — ถ้าไม่เห็นอะไรเด้ง '
                             + 'ให้ตรวจการตั้งค่าแจ้งเตือนของระบบปฏิบัติการอีกชั้น');
            }
            return;
        }

        var b = e.target.closest('#ntDeskBtn');
        if (!b || b.disabled) { return; }

        window.DesktopNoti.toggle(function (on, message) {
            paintDesk();
            if (on) {
                if (typeof window.appSuccess === 'function') { window.appSuccess(message); }
                window.DesktopNoti.test();
            } else if (typeof window.appInfo === 'function') {
                window.appInfo(message);
            }
        });
    }


    function init() {
        if (!listEl) { return; }

        scrollEl.addEventListener('scroll', onScroll);
        listEl.addEventListener('click', onClick);
        if (btnAll) { btnAll.addEventListener('click', readAll); }
        if (btnFil) { btnFil.addEventListener('click', toggleFilter); }

        paintDesk();
        if (el('ntDesk')) { el('ntDesk').addEventListener('click', onDeskClick); }

        window.addEventListener('resize', fillIfShort);

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
