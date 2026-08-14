/* ============================================================
 *  assets/js/app-notify.js
 *  กระดิ่งแจ้งเตือนบนแถบบน — ทำงานทุกหน้าโดยอัตโนมัติ
 *
 *  สิ่งที่ทำ:
 *    - แสดงจำนวนที่ยังไม่ได้อ่าน (ค่าเริ่มต้นมาจาก PHP ตั้งแต่ตอนวาดหน้า)
 *    - กดกระดิ่งแล้วโหลดรายการล่าสุด 30 เรื่อง
 *    - กดที่รายการ = ทำเครื่องหมายว่าอ่านแล้ว + กระโดดไปยังเอกสารใบนั้น
 *    - ถามตัวเลขใหม่เป็นระยะ เพื่อให้คนที่เปิดหน้าค้างไว้เห็นงานที่เพิ่งเข้ามา
 *
 *  ---------------------------------------------------------------
 *  ทำไมโหลดรายการตอนกด ไม่โหลดพร้อมหน้า
 *  ---------------------------------------------------------------
 *  ตัวเลขบนกระดิ่งคือสิ่งเดียวที่ทุกคนต้องเห็นทุกครั้ง ส่วนเนื้อหาข้างในมี
 *  คนกดดูไม่ถึงหนึ่งในสิบของการเปิดหน้า การลากข้อความ 30 รายการมาพร้อมทุกหน้า
 *  จึงเป็นน้ำหนักที่เสียเปล่าเกือบทั้งหมด
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */

(function () {
    'use strict';

    var CTX = window.APP_CTX || {};
    var API = 'api/notify.php';

    /* ถามตัวเลขใหม่ทุก 90 วินาที
       ถี่กว่านี้ไม่ได้ช่วยอะไร เพราะงานอนุมัติไม่ได้เร่งเป็นวินาที
       และห่างกว่านี้คนที่เปิดหน้าค้างทั้งวันจะพลาดงานที่เข้ามาตอนบ่าย */
    var POLL_MS = 90000;

    var btn      = document.getElementById('notiBtn');
    var dot      = document.getElementById('notiDot');
    var panel    = document.getElementById('notiPanel');
    var backdrop = document.getElementById('notiBackdrop');
    var listEl   = document.getElementById('notiList');
    var footEl   = document.getElementById('notiFoot');
    var countEl  = document.getElementById('notiCount');
    var btnAll   = document.getElementById('notiReadAll');
    var btnClose = document.getElementById('notiClose');

    var unread  = (typeof CTX.unread === 'number') ? CTX.unread : 0;
    var open    = false;
    var loading = false;

    /* ---------- โหลดทีละหน้า ----------
     * 10 รายการแรกพอสำหรับคนที่เปิดกระดิ่งมาดูว่ามีอะไรใหม่ ซึ่งเป็นเหตุผลหลัก
     * ที่คนกดปุ่มนี้ ที่เหลือโหลดต่อเมื่อเลื่อนลงไปถึงจริง ๆ
     *
     * เดิมดึงมา 30 รายการทุกครั้งที่กด แม้คนส่วนใหญ่จะอ่านแค่สองสามอันบนสุด
     */
    var PAGE_SIZE = 10;

    var nextId  = 0;        // ตัวชี้ตำแหน่ง: ขอของที่เก่ากว่า id นี้
    var hasMore = false;
    var lastDay = '';       // วันของรายการสุดท้ายที่วาดไป ใช้ตัดสินว่าต้องขึ้นหัวกลุ่มใหม่ไหม
    var total   = 0;


    /* ---------- คุยกับ API ---------- */
    function post(payload) {
        payload.csrf_token = CTX.csrf;

        return fetch(API, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }


    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }


    /* ---------- ตัวเลขบนกระดิ่ง ---------- */
    function setUnread(n) {
        unread = (typeof n === 'number' && n > 0) ? n : 0;

        if (dot) {
            dot.hidden      = (unread === 0);
            dot.textContent = (unread > 99) ? '99+' : String(unread);
        }
        if (countEl) {
            countEl.hidden      = (unread === 0);
            countEl.textContent = String(unread);
        }
        if (btnAll) {
            btnAll.disabled = (unread === 0);
        }
    }


    /* ---------- สีประจำประเภทแจ้งเตือน ----------
     * โทนสีมาจากฝั่งเซิร์ฟเวอร์ (notify_type_meta) แต่ชื่อคลาสของ Tailwind
     * ต้องเขียนเต็มตัวไว้ที่นี่ ต่อสตริงเอาไม่ได้เพราะตัวสร้าง CSS
     * มองไม่เห็นคลาสที่ประกอบขึ้นตอนรันไทม์ แล้วสีจะหายไปทั้งชุด
     */
    function toneClass(tone) {
        var map = {
            amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
            green: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            red:   'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
            brand: 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
            slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
        };
        return map[tone] || map.slate;
    }


    /* ---------- หัวกลุ่มวันที่ ----------
     * แจ้งเตือนที่ไล่กันมาเรื่อย ๆ ไม่มีจุดพัก คนอ่านต้องอ่าน "3 ชั่วโมงที่แล้ว"
     * ของทุกบรรทัดเพื่อประกอบเองว่าอันไหนเรื่องเดียวกันวันเดียวกัน
     *
     * หัวกลุ่มตอบให้ทีเดียวว่าตั้งแต่บรรทัดนี้ลงไปคือวันไหน
     * และทำให้เห็นช่องว่างของวันที่ไม่มีอะไรเกิดขึ้นด้วย
     */
    function dayHead(text) {
        return '<div class="noti-day sticky top-0 z-[1] border-b border-slate-100 bg-slate-50/95 px-4 py-1.5'
             + ' text-[11px] font-semibold text-slate-500 backdrop-blur'
             + ' dark:border-slate-800 dark:bg-slate-900/95 dark:text-slate-400">'
             + escapeHtml(text) + '</div>';
    }

    function emptyHtml() {
        return '<div class="flex flex-col items-center px-6 py-12 text-center">'
             + '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">'
             + '<i data-lucide="bell-off" class="h-6 w-6" aria-hidden="true"></i></span>'
             + '<p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-300">ยังไม่มีการแจ้งเตือน</p>'
             + '<p class="mt-1 text-xs text-slate-400">เมื่อมีใบเบิกเข้ามาหรือเอกสารของคุณคืบหน้า จะขึ้นที่นี่</p>'
             + '</div>';
    }


    /* ---------- วาดรายการ ----------
     * $append = true คือต่อท้ายของเดิม (ตอนเลื่อนลงไปโหลดเพิ่ม)
     * ไม่ล้างของเดิมทิ้ง ไม่งั้นตำแหน่งที่เลื่อนอยู่จะกระโดดกลับขึ้นบนสุด
     */
    function render(items, append) {
        if (!listEl) { return; }

        if (!append) { lastDay = ''; }

        if ((!items || items.length === 0) && !append) {
            listEl.innerHTML = emptyHtml();
            renderIconsSafe();
            return;
        }

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

            html +=
                '<button type="button" class="noti-row flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60'
              + (isNew ? ' bg-brand-50/40 dark:bg-brand-500/5' : '') + '"'
              + ' data-id="' + n.noti_id + '"'
              + ' data-href="' + escapeHtml(target) + '">'

              + '<span class="mt-0.5 flex h-9 w-9 flex-none items-center justify-center rounded-xl '
              + toneClass(n.tone) + '">'
              + '<i data-lucide="' + escapeHtml(n.icon) + '" class="h-[18px] w-[18px]" aria-hidden="true"></i>'
              + '</span>'

              + '<span class="min-w-0 flex-1">'
              + '<span class="flex items-start gap-2">'
              + '<span class="min-w-0 flex-1 text-[13px] font-semibold leading-snug text-slate-800 dark:text-slate-100">'
              + escapeHtml(n.title) + '</span>'
              + (isNew ? '<span class="mt-1 h-2 w-2 flex-none rounded-full bg-brand-500" aria-label="ยังไม่ได้อ่าน"></span>' : '')
              + '</span>'
              + '<span class="mt-0.5 block text-xs leading-relaxed text-slate-500 dark:text-slate-400">'
              + escapeHtml(n.message) + '</span>'
              + '<span class="mt-1 block text-[11px] text-slate-400">' + escapeHtml(n.ago) + '</span>'
              + '</span>'
              + '</button>';
        }

        if (append) { listEl.insertAdjacentHTML('beforeend', html); }
        else        { listEl.innerHTML = html; }

        renderIconsSafe();
    }


    /* ---------- แถบล่างสุดของรายการ ----------
     * บอกสองอย่าง: กำลังโหลดของถัดไปอยู่ไหม และไปดูทั้งหมดได้ที่ไหน
     *
     * ปุ่ม "ดูการแจ้งเตือนทั้งหมด" ต้องมี เพราะกล่องนี้เลื่อนดูของเก่าได้ก็จริง
     * แต่ค้นไม่ได้ กรองไม่ได้ และสูงแค่ครึ่งจอ คนที่ตามหาเรื่องเมื่อสองสัปดาห์ก่อน
     * ต้องมีที่ที่กว้างกว่านี้ให้ไป
     */
    function setFoot(state) {
        if (!footEl) { return; }

        if (state === 'loading') {
            footEl.innerHTML = '<div class="px-4 py-3 text-center text-xs text-slate-400">กำลังโหลดเพิ่ม…</div>';
            return;
        }

        var more = hasMore
            ? '<div class="px-4 pt-2 text-center text-[11px] text-slate-400">เลื่อนลงเพื่อดูเพิ่ม</div>'
            : '';

        footEl.innerHTML = more
            + '<a class="noti-all flex items-center justify-center gap-1.5 px-4 py-3 text-sm font-semibold'
            + ' text-brand-600 transition hover:bg-slate-50 dark:text-brand-300 dark:hover:bg-slate-800/60"'
            + ' href="main.php?page=notifications">'
            + 'ดูการแจ้งเตือนทั้งหมด'
            + (total > 0 ? ' <span class="text-xs font-normal text-slate-400">(' + total + ')</span>' : '')
            + '<i data-lucide="chevron-right" class="h-4 w-4" aria-hidden="true"></i></a>';

        renderIconsSafe();
    }

    function onDesktopToggle() {
        window.DesktopNoti.toggle(function (on, message) {
            setFoot();

            if (on && typeof window.appSuccess === 'function') {
                window.appSuccess(message);
                window.DesktopNoti.test();
            } else if (!on && typeof window.appInfo === 'function') {
                window.appInfo(message);
            }
        });
    }


    function renderIconsSafe() {
        if (typeof window.renderIcons === 'function') {
            window.renderIcons();
        } else if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }


    /* ---------- ปลายทางของแต่ละรายการ ----------
     * ส่ง doc ไปด้วยเพื่อให้หน้าปลายทางเปิดกล่องรายละเอียดใบนั้นให้เลย
     * ไม่ใช่พาไปที่ตารางเปล่า ๆ แล้วให้ผู้ใช้ไปไล่หาเองว่าใบไหน
     */
    function linkOf(n) {
        if (!n.link_page) { return ''; }

        var url = 'main.php?page=' + encodeURIComponent(n.link_page);
        if (n.link_doc_id > 0) {
            url += '&doc=' + n.link_doc_id;
        }
        return url;
    }


    /* ---------- เปิด/ปิดกล่อง ---------- */
    function place() {
        if (!panel || !btn) { return; }

        var r   = btn.getBoundingClientRect();
        var gap = 8;

        panel.style.top = (r.bottom + gap) + 'px';

        /* จัดขอบขวาของกล่องให้ตรงกับขอบขวาของปุ่ม แล้วดันกลับเข้าจอถ้าล้น
           คิดจากขอบขวาเพราะกระดิ่งอยู่ชิดขวาเสมอ ถ้าคิดจากซ้ายกล่องจะล้นออกนอกจอ */
        var width = panel.offsetWidth || 384;
        var left  = r.right - width;
        if (left < 12) { left = 12; }
        if (left + width > window.innerWidth - 12) {
            left = Math.max(12, window.innerWidth - width - 12);
        }
        panel.style.left = left + 'px';
    }


    function openPanel() {
        if (!panel) { return; }

        open = true;
        panel.hidden = false;
        if (backdrop) { backdrop.hidden = false; }
        if (btn) { btn.setAttribute('aria-expanded', 'true'); }

        place();
        loadList();
    }


    function closePanel() {
        if (!panel) { return; }

        open = false;
        panel.hidden = true;
        if (backdrop) { backdrop.hidden = true; }
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
    }


    function toggle() {
        if (open) { closePanel(); } else { openPanel(); }
    }


    /* ---------- โหลดรายการ ----------
     * เรียกโดยไม่มีพารามิเตอร์ = เริ่มใหม่ตั้งแต่หน้าแรก
     * เรียกด้วย true = ต่อจากที่ค้างไว้ (ตอนเลื่อนถึงท้ายรายการ)
     */
    function loadList(more) {
        if (loading) { return; }
        if (more && !hasMore) { return; }
        loading = true;

        if (!more) {
            nextId  = 0;
            hasMore = false;
            if (listEl) {
                listEl.innerHTML =
                    '<div class="px-4 py-10 text-center text-sm text-slate-400">กำลังโหลด…</div>';
            }
            if (footEl) { footEl.innerHTML = ''; }
        } else {
            setFoot('loading');
        }

        post({ action: 'list', limit: PAGE_SIZE, before_id: more ? nextId : 0 })
            .then(function (res) {
                loading = false;
                if (!res || !res.ok) {
                    if (more) { setFoot(); }
                    else { showListError(res && res.message ? res.message : 'โหลดการแจ้งเตือนไม่สำเร็จ'); }
                    return;
                }

                setUnread(res.unread);
                hasMore = !!res.has_more;
                nextId  = res.next_id || nextId;
                total   = res.total || 0;

                /* บอก id ล่าสุดที่มีอยู่ให้ตัวแจ้งเตือนนอกหน้าเว็บรู้
                   ใช้ตอนเพิ่งกดเปิดสวิตช์ เพื่อไม่ให้เด้งย้อนหลังรัวทีเดียวสิบเรื่อง */
                if (!more && res.items && res.items.length > 0) {
                    window.__notiMaxId = Number(res.items[0].noti_id);
                }

                render(res.items, !!more);
                setFoot();
            })['catch'](function () {
                loading = false;
                if (more) { setFoot(); }
                else { showListError('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); }
            });
    }


    /* ---------- เลื่อนถึงท้ายแล้วโหลดต่อ ----------
     * เผื่อระยะไว้ 60px ไม่รอให้ถึงก้นจริง ๆ
     * เพื่อให้ของหน้าถัดไปมาถึงก่อนที่ผู้ใช้จะเลื่อนไปเจอที่ว่าง
     */
    function onScroll() {
        if (!listEl || loading || !hasMore) { return; }

        if (listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 60) {
            loadList(true);
        }
    }


    function showListError(message) {
        if (!listEl) { return; }
        listEl.innerHTML =
            '<div class="px-4 py-10 text-center text-sm text-red-500">' + escapeHtml(message) + '</div>';
    }


    /* ---------- กดที่รายการ ----------
     * ทำเครื่องหมายว่าอ่านแล้วก่อน แล้วค่อยพาไป
     * ไม่รอผลจากเซิร์ฟเวอร์ก่อนเปลี่ยนหน้า เพราะการหน่วงตรงนี้ผู้ใช้รู้สึกได้
     * และผลเสียสูงสุดถ้าคำสั่งไม่ถึงคือจุดสีน้ำเงินยังค้างอยู่ ซึ่งกดซ้ำได้
     */
    function onListClick(e) {
        var row = e.target.closest ? e.target.closest('.noti-row') : null;
        if (!row) { return; }

        var id   = parseInt(row.getAttribute('data-id'), 10);
        var href = row.getAttribute('data-href');

        if (id > 0) {
            post({ action: 'read', noti_id: id })['catch'](function () { });
            setUnread(unread - 1);
            row.classList.remove('bg-brand-50/40', 'dark:bg-brand-500/5');
        }

        if (href) {
            window.location.href = href;
        } else {
            closePanel();
        }
    }


    function readAll() {
        if (unread === 0) { return; }

        post({ action: 'read_all' })
            .then(function (res) {
                if (res && res.ok) {
                    setUnread(0);
                    loadList();
                    if (typeof window.appSuccess === 'function') {
                        window.appSuccess(res.message);
                    }
                }
            })['catch'](function () { });
    }


    /* ---------- ถามตัวเลขใหม่เป็นระยะ ----------
     * ปกติหยุดถามตอนที่แท็บถูกซ่อน คนที่เปิดระบบทิ้งไว้ข้ามคืน
     * จะได้ไม่ยิงคำขอเปล่า ๆ ทั้งคืน
     *
     * ยกเว้นตอนเปิด "แจ้งเตือนนอกหน้าเว็บ" ไว้ — ซึ่งเป็นช่วงเวลาที่แท็บถูกซ่อน
     * อยู่แล้วโดยธรรมชาติ ถ้าหยุดถามตอนนั้นก็เท่ากับปิดฟีเจอร์ไปในตัว
     */
    function poll() {
        var desktop = window.DesktopNoti && window.DesktopNoti.isOn();
        if (document.hidden && !desktop) { return; }

        post({ action: 'count' })
            .then(function (res) {
                if (!res || !res.ok) { return; }

                var before = unread;
                setUnread(res.unread);

                /* มีของใหม่เข้ามาเท่านั้นถึงจะไปขอเนื้อหามาเด้ง
                   ไม่ได้ลากรายการมาทุกรอบการถาม ซึ่งจะกลายเป็นภาระเปล่า
                   ในรอบส่วนใหญ่ที่ไม่มีอะไรเปลี่ยน */
                if (desktop && res.unread > before) { pullForDesktop(); }
            })['catch'](function () { });
    }


    /* ดึงเรื่องล่าสุดมาเด้งนอกหน้าเว็บ
       ขอแค่ 5 เรื่องพอ ถ้ามีมากกว่านั้น DesktopNoti จะรวบเป็นกล่องเดียวอยู่แล้ว */
    function pullForDesktop() {
        post({ action: 'list', limit: 5 })
            .then(function (res) {
                if (res && res.ok) { window.DesktopNoti.push(res.items); }
            })['catch'](function () { });
    }


    /* ---------- เริ่มทำงาน ---------- */
    function init() {
        if (!btn || !panel) { return; }

        setUnread(unread);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggle();
        });

        if (btnClose) { btnClose.addEventListener('click', closePanel); }
        if (backdrop) { backdrop.addEventListener('click', closePanel); }
        if (btnAll)   { btnAll.addEventListener('click', readAll); }
        if (listEl)   { listEl.addEventListener('click', onListClick); }

        /* โหลดหน้าถัดไปตอนเลื่อนถึงท้ายรายการ
           ผูกที่ #notiList เพราะกล่องนี้เลื่อนในตัวเอง หน้าข้างหลังไม่ขยับ */
        if (listEl) { listEl.addEventListener('scroll', onScroll); }

        /* ผูกที่แถบล่างครั้งเดียว ไม่ใช่ผูกกับปุ่มโดยตรง
           เพราะ setFoot() วาดแถบนั้นใหม่ทุกครั้งที่โหลดรายการ ปุ่มเดิมจะหายไปพร้อมโหนดเก่า */
        if (footEl) {
            footEl.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('#notiDeskBtn') : null;
                if (b && !b.disabled) { onDesktopToggle(); }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && open) { closePanel(); }
        });

        /* หน้าต่างเปลี่ยนขนาดหรือเลื่อน กล่องต้องตามปุ่มไป
           แถบบนตรึงอยู่กับที่ ตำแหน่งจึงเปลี่ยนเฉพาะตอน resize แต่ผูก scroll ไว้ด้วย
           เผื่อวันหลังมีหน้าที่ปล่อยให้แถบบนเลื่อนตาม */
        window.addEventListener('resize', function () { if (open) { place(); } });

        window.setInterval(poll, POLL_MS);

        /* กลับมาที่แท็บนี้อีกครั้ง ถามตัวเลขทันทีโดยไม่ต้องรอครบรอบ
           คนที่สลับไปทำอย่างอื่นครึ่งชั่วโมงแล้วกลับมา ควรเห็นเลขที่ถูกต้องเลย */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { poll(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }


    /* เปิดให้หน้าอื่นสั่งรีเฟรชได้ หลังทำรายการที่สร้างแจ้งเตือนให้ตัวเอง */
    window.AppNotify = {
        refresh: poll,
        open:    openPanel,
        close:   closePanel
    };
})();
