/* ============================================================
 *  assets/js/settings.js — หน้าตั้งค่าระบบ
 *
 *  สองส่วนในไฟล์เดียว:
 *    1. อีเมลแจ้งเตือน — ค่าเชื่อมต่อ SMTP, ส่งเมลทดสอบ, คิวเมลขาออก
 *    2. คู่มือการใช้งาน — แก้คำอธิบายแต่ละขั้นตอน
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */

(function () {
    'use strict';

    var CFG = window.SETTINGS || {};

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function setHidden(node, on) {
        if (node) { node.hidden = !!on; }
    }

    function showPane(node, on) {
        if (node) { node.classList.toggle('is-on', !!on); }
    }

    function icons() {
        if (typeof window.renderIcons === 'function') { window.renderIcons(); }
        else if (window.lucide) { window.lucide.createIcons(); }
    }


    /* ---------- คุยกับ API ----------
     * แยกที่อยู่ปลายทางเป็นพารามิเตอร์ เพราะหน้านี้คุยกับสอง endpoint
     * (settings กับ guide) ด้วยรูปแบบเดียวกันทุกประการ
     */
    function post(url, payload) {
        payload.csrf_token = CFG.csrfToken;

        return fetch(url, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function ok(message)  { if (window.appSuccess) { window.appSuccess(message); } }
    function bad(message) { if (window.appError)   { window.appError(message); } }


    /* ============================================================
     *  แถบเลือกหัวข้อ
     * ============================================================ */
    var guideLoaded = false;

    function initTabs() {
        var tabs = document.querySelectorAll('.st-tab');

        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () {
                var name = this.getAttribute('data-tab');

                var all = document.querySelectorAll('.st-tab');
                for (var j = 0; j < all.length; j++) {
                    all[j].classList.toggle('is-on', all[j] === this);
                }

                /* สลับด้วยคลาส ไม่ใช่แอตทริบิวต์ hidden — ดูเหตุผลที่ .st-pane ใน app.css
                   แท็บที่แสดงอยู่ต้องเป็น flex column ให้รายการข้างในเลื่อนได้เอง */
                showPane(el('tabMail'),  name === 'mail');
                showPane(el('tabGuide'), name === 'guide');

                /* หน้าคู่มือมีขั้นตอนเกือบห้าสิบใบ โหลดตอนกดเข้าดูจริงเท่านั้น
                   คนที่เข้ามาแค่แก้ค่า SMTP จะได้ไม่ต้องรับน้ำหนักส่วนนี้ */
                if (name === 'guide' && !guideLoaded) {
                    guideLoaded = true;
                    loadGuide();
                }

                /* ช่องที่ถูกซ่อนตอนวาดครั้งแรกจะยังไม่มีไอคอน */
                icons();
            });
        }
    }


    /* ============================================================
     *  ส่วนที่ 1 — อีเมล
     * ============================================================ */

    var MAIL_FIELDS = {
        smtpHost:     'smtp_host',
        smtpPort:     'smtp_port',
        smtpSecure:   'smtp_secure',
        smtpUser:     'smtp_user',
        mailFrom:     'mail_from',
        mailFromName: 'mail_from_name',
        mailMaxTry:   'mail_max_try'
    };


    function showFormError(message) {
        var box = el('stError');
        if (!box) { return; }

        if (!message) { box.hidden = true; return; }

        el('stErrorText').textContent = message;
        box.hidden = false;
        box.scrollIntoView({ block: 'nearest' });
    }


    /* ---------- กล่องแจ้งสถานะเครื่อง ----------
     * ปัญหาที่พบบ่อยที่สุดคือ "กรอกถูกหมดแล้วแต่ส่งไม่ออก" ซึ่งสาเหตุจริง
     * อยู่ที่ php.ini ไม่ได้เปิดส่วนขยาย openssl ไม่ใช่ที่ค่าที่กรอก
     * ถ้าไม่บอกไว้บนสุด ผู้ใช้จะไล่แก้ค่าที่ถูกอยู่แล้วซ้ำไปมา
     */
    function showEnv(env, config) {
        var box  = el('envBox');
        var text = el('envText');
        if (!box || !text) { return; }

        box.className = 'mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm';

        if (env && !env.ok) {
            box.className += ' border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
            text.textContent = env.message;
            box.hidden = false;
            return;
        }

        if (config && !config.ok) {
            box.className += ' border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
            text.textContent = config.message + ' — กรอกให้ครบแล้วกดบันทึก จากนั้นลองส่งเมลทดสอบ';
            box.hidden = false;
            return;
        }

        box.hidden = true;
    }


    function loadSettings() {
        post(CFG.apiUrl, { action: 'get' })
            .then(function (res) {
                if (!res || !res.ok) {
                    bad(res && res.message ? res.message : 'โหลดค่าตั้งไม่สำเร็จ');
                    return;
                }

                var m = res.mail || {};
                var k;
                for (k in MAIL_FIELDS) {
                    if (!MAIL_FIELDS.hasOwnProperty(k)) { continue; }
                    var node = el(k);
                    if (node) { node.value = (m[MAIL_FIELDS[k]] !== undefined) ? m[MAIL_FIELDS[k]] : ''; }
                }

                if (el('mailEnabled')) { el('mailEnabled').checked = (m.mail_enabled === '1'); }
                if (el('siteUrl'))     { el('siteUrl').value = (res.general && res.general.site_url) || ''; }

                /* ช่องรหัสผ่านไม่เคยมีค่าจริงส่งกลับมา บอกได้แค่ว่าตั้งไว้แล้วหรือยัง
                   (ดูเหตุผลที่หัวไฟล์ api/settings.php) */
                if (el('passState')) {
                    el('passState').textContent = res.hasPass ? '— ตั้งไว้แล้ว' : '— ยังไม่ได้ตั้ง';
                }

                showEnv(res.env, res.config);
                renderQueueSummary(res.queue);
                loadQueue();
            })['catch'](function () {
                bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
            });
    }


    function collectMail() {
        var values = {};
        var k;

        for (k in MAIL_FIELDS) {
            if (!MAIL_FIELDS.hasOwnProperty(k)) { continue; }
            var node = el(k);
            if (node) { values[MAIL_FIELDS[k]] = String(node.value).trim(); }
        }

        values.mail_enabled = (el('mailEnabled') && el('mailEnabled').checked) ? '1' : '0';
        values.site_url     = el('siteUrl') ? String(el('siteUrl').value).trim() : '';
        values.smtp_pass    = el('smtpPass') ? el('smtpPass').value : '';

        return values;
    }


    /* ---------- ตรวจก่อนส่ง ----------
     * ตรวจซ้ำที่ฝั่งเซิร์ฟเวอร์อยู่แล้ว แต่ตรวจที่นี่ก่อนเพื่อให้คนกรอกรู้ทันที
     * ว่าช่องไหนผิด โดยไม่ต้องรอรอบเครือข่าย และเห็นขอบแดงที่ช่องนั้นตรง ๆ
     */
    function validateMail(values) {
        clearBad();

        if (values.smtp_host === '') {
            return mark('smtpHost', 'กรุณากรอกโฮสต์ SMTP (ของ Zoho คือ smtp.zoho.com)');
        }

        var port = parseInt(values.smtp_port, 10);
        if (!(port >= 1 && port <= 65535)) {
            return mark('smtpPort', 'พอร์ตต้องเป็นตัวเลขระหว่าง 1 ถึง 65535 (SSL ใช้ 465, TLS ใช้ 587)');
        }

        if (values.smtp_user === '') {
            return mark('smtpUser', 'กรุณากรอกบัญชีอีเมลที่ใช้ส่ง');
        }
        if (!isEmail(values.smtp_user)) {
            return mark('smtpUser', 'รูปแบบอีเมลไม่ถูกต้อง');
        }
        if (values.mail_from !== '' && !isEmail(values.mail_from)) {
            return mark('mailFrom', 'รูปแบบอีเมลผู้ส่งไม่ถูกต้อง');
        }

        var tries = parseInt(values.mail_max_try, 10);
        if (!(tries >= 1 && tries <= 10)) {
            return mark('mailMaxTry', 'จำนวนครั้งที่ลองส่งซ้ำต้องอยู่ระหว่าง 1 ถึง 10');
        }

        /* เปิดสวิตช์ได้ต่อเมื่อมีรหัสผ่านแล้วจริง ไม่ว่าจะเพิ่งกรอกหรือเคยตั้งไว้
           กันการเปิดใช้งานแล้วเมลกองอยู่ในคิวโดยไม่มีใครรู้ว่าทำไมไม่ออก */
        var hasPass = (values.smtp_pass !== '')
                   || (el('passState') && el('passState').textContent.indexOf('ตั้งไว้แล้ว') >= 0);

        if (values.mail_enabled === '1' && !hasPass) {
            return mark('smtpPass', 'เปิดการส่งอีเมลไม่ได้ — ยังไม่ได้ตั้งรหัสผ่านแอปของ Zoho');
        }

        return '';
    }


    function isEmail(text) {
        return /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/.test(String(text));
    }

    function mark(id, message) {
        var node = el(id);
        if (node) {
            node.classList.add('is-bad');
            node.focus();
        }
        return message;
    }

    function clearBad() {
        var bads = document.querySelectorAll('.st-input.is-bad');
        for (var i = 0; i < bads.length; i++) { bads[i].classList.remove('is-bad'); }
        showFormError('');
    }


    function saveMail() {
        var values = collectMail();
        var error  = validateMail(values);

        if (error !== '') { showFormError(error); return; }

        /* รหัสผ่านว่าง = ไม่เปลี่ยน อย่าส่งค่าว่างไปทับของเดิม */
        if (values.smtp_pass === '') { delete values.smtp_pass; }

        var btn = el('btnSaveMail');
        if (btn) { btn.disabled = true; }

        post(CFG.apiUrl, { action: 'save', values: values })
            .then(function (res) {
                if (btn) { btn.disabled = false; }

                if (!res || !res.ok) {
                    showFormError(res && res.message ? res.message : 'บันทึกไม่สำเร็จ');
                    return;
                }

                if (el('smtpPass')) { el('smtpPass').value = ''; }
                ok(res.message);
                loadSettings();
            })['catch'](function () {
                if (btn) { btn.disabled = false; }
                showFormError('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
            });
    }


    /* ---------- ส่งเมลทดสอบ ----------
     * ถามที่อยู่ปลายทางทุกครั้ง ไม่เดาจากบัญชีที่ตั้งไว้
     * เพราะส่งหาตัวเองอาจผ่านทั้งที่ส่งออกนอกองค์กรไม่ผ่าน
     */
    function testMail() {
        if (!window.Swal) { return; }

        var guess = el('smtpUser') ? String(el('smtpUser').value).trim() : '';

        window.Swal.fire({
            title:             'ส่งเมลทดสอบ',
            input:             'email',
            inputValue:        guess,
            inputPlaceholder:  'อีเมลปลายทาง',
            text:              'ระบบจะส่งจดหมายหนึ่งฉบับไปที่อยู่นี้ทันที โดยไม่ผ่านคิว',
            showCancelButton:  true,
            confirmButtonText: 'ส่งเลย',
            cancelButtonText:  'ยกเลิก'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) { return; }

            if (window.appLoading) { window.appLoading('กำลังส่ง…'); }

            post(CFG.apiUrl, { action: 'test', to: String(r.value).trim() })
                .then(function (res) {
                    if (window.appClose) { window.appClose(); }

                    if (!res || !res.ok) {
                        bad(res && res.message ? res.message : 'ส่งไม่สำเร็จ');
                    } else {
                        ok(res.message);
                    }
                    loadQueue();
                })['catch'](function () {
                    if (window.appClose) { window.appClose(); }
                    bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
                });
        });
    }


    /* ---------- คิวเมล ---------- */
    function renderQueueSummary(q) {
        var box = el('qSummary');
        if (!box) { return; }

        q = q || {};
        var rows = [
            ['is-pending', q.pending || 0, 'รอส่ง'],
            ['is-sent',    q.sent    || 0, 'ส่งแล้ว'],
            ['is-failed',  q.failed  || 0, 'ล้มเหลว']
        ];

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            html += '<li class="st-stat ' + rows[i][0] + '">'
                  + '<span class="st-stat-num num">' + rows[i][1] + '</span>'
                  + '<span class="st-stat-cap">' + rows[i][2] + '</span></li>';
        }
        box.innerHTML = html;

        if (el('btnRetry')) { el('btnRetry').disabled = ((q.failed || 0) === 0); }
        if (el('btnFlush')) { el('btnFlush').disabled = ((q.pending || 0) === 0); }
    }


    function loadQueue() {
        post(CFG.apiUrl, { action: 'queue', limit: 30 })
            .then(function (res) {
                if (!res || !res.ok) { return; }

                renderQueueSummary(res.summary);
                renderQueue(res.items || []);
            })['catch'](function () { });
    }


    function renderQueue(items) {
        var box = el('qList');
        if (!box) { return; }

        if (items.length === 0) {
            box.innerHTML =
                '<p class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-400 dark:border-slate-700">'
              + 'ยังไม่มีเมลในคิว</p>';
            return;
        }

        var tone = {
            pending: 'text-amber-600 dark:text-amber-400',
            sending: 'text-sky-600 dark:text-sky-400',
            sent:    'text-emerald-600 dark:text-emerald-400',
            failed:  'text-red-600 dark:text-red-400'
        };

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var m = items[i];

            html += '<div class="rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-800">'
                  + '<div class="flex items-start gap-2">'
                  + '<span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-slate-700 dark:text-slate-200">'
                  + escapeHtml(m.subject) + '</span>'
                  + '<span class="flex-none text-[11px] font-semibold ' + (tone[m.status] || '') + '">'
                  + escapeHtml(m.status_label) + '</span>'
                  + '</div>'
                  + '<div class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">'
                  + escapeHtml(m.to_email)
                  + (m.try_count > 0 ? ' · ลองส่ง ' + m.try_count + ' ครั้ง' : '')
                  + '</div>';

            /* สาเหตุที่ส่งไม่ผ่านคือข้อมูลที่มีค่าที่สุดในกล่องนี้
               ต้องอ่านได้โดยไม่ต้องกดอะไรเพิ่ม */
            if (m.last_error) {
                html += '<div class="mt-1.5 rounded-lg bg-red-50 px-2 py-1.5 text-[11px] leading-relaxed text-red-700 dark:bg-red-500/10 dark:text-red-300">'
                      + escapeHtml(m.last_error) + '</div>';
            }

            html += '</div>';
        }
        box.innerHTML = html;
    }


    /* ---------- ตามดูคิวจนกว่าจะไม่มีอะไรค้าง ----------
     * เซิร์ฟเวอร์ส่งเมลอยู่เบื้องหลังหลังตอบเรามาแล้ว จำนวนที่ค้างจึงลดลงเรื่อย ๆ
     * โหลดซ้ำเป็นระยะเพื่อให้เห็นความคืบหน้า แล้วหยุดเมื่อคิวว่างหรือครบรอบ
     * ไม่ถามถี่กว่านี้เพราะการส่งหนึ่งฉบับใช้เวลาหลายวินาทีอยู่แล้ว
     */
    function watchQueue(rounds) {
        var left = rounds || 6;

        function tick() {
            loadQueue();
            left--;
            if (left > 0) { setTimeout(tick, 2500); }
        }
        setTimeout(tick, 1500);
    }

    function flushQueue() {
        if (window.appLoading) { window.appLoading('กำลังสั่งส่ง…'); }

        post(CFG.apiUrl, { action: 'flush', limit: 20 })
            .then(function (res) {
                if (window.appClose) { window.appClose(); }

                if (!res || !res.ok) {
                    bad(res && res.message ? res.message : 'ส่งคิวไม่สำเร็จ');
                    loadQueue();
                    return;
                }

                ok(res.message);
                /* คำตอบกลับมาก่อนที่เมลจะถูกส่งจริง จึงยังไม่มีจำนวนให้รายงาน
                   ตามดูคิวแทน แล้วตัวเลขจะลดลงให้เห็นเอง */
                if (res.working) { watchQueue(6); } else { loadQueue(); }
            })['catch'](function () {
                if (window.appClose) { window.appClose(); }
                bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
            });
    }


    function retryFailed() {
        post(CFG.apiUrl, { action: 'retry' })
            .then(function (res) {
                if (!res || !res.ok) {
                    bad(res && res.message ? res.message : 'สั่งส่งซ้ำไม่สำเร็จ');
                    return;
                }
                ok(res.message);
                loadQueue();
            })['catch'](function () { bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); });
    }


    /* ============================================================
     *  ส่วนที่ 2 — คู่มือการใช้งาน
     * ============================================================ */

    var steps = [];         // ข้อมูลที่โหลดมา
    var dirty = {};         // step_id ที่ถูกแก้แล้วยังไม่บันทึก


    function loadGuide() {
        post(CFG.guideUrl, { action: 'admin_list' })
            .then(function (res) {
                if (!res || !res.ok) {
                    bad(res && res.message ? res.message : 'โหลดคู่มือไม่สำเร็จ');
                    return;
                }
                steps = res.steps || [];
                dirty = {};
                renderGuide('');
                refreshPending();
            })['catch'](function () { bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); });
    }


    function renderGuide(keyword) {
        var box = el('guideList');
        if (!box) { return; }

        var q     = String(keyword || '').trim().toLowerCase();
        var html  = '';
        var page  = '';
        var shown = 0;

        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];

            if (q !== '') {
                var hay = (s.page_key + ' ' + s.guide_title + ' ' + s.title + ' '
                         + s.description + ' ' + s.target_selector).toLowerCase();
                if (hay.indexOf(q) === -1) { continue; }
            }

            /* ---------- จัดกลุ่มตามหน้า แล้วพับเก็บได้ ----------
             * ทั้งระบบมี 63 ขั้นตอนใน 18 หน้า ถ้ากางหมดต้องเลื่อนผ่านการ์ด
             * หลายสิบใบกว่าจะถึงหน้าที่อยากแก้ ซึ่งคือทุกครั้งที่เข้ามาแก้คำอธิบาย
             *
             * ปิดไว้เป็นค่าตั้งต้นทุกกลุ่ม เห็นรายชื่อหน้าทั้งหมดในจอเดียว
             * แล้วค่อยกางเฉพาะหน้าที่ต้องการ
             */
            if (s.page_key !== page) {
                /* ต้องปิดทั้ง body และ section ของกลุ่มก่อนหน้า
                   ปิดแค่ </div> จะทำให้กลุ่มถัดไปไปอยู่ "ข้างใน" กลุ่มก่อนหน้า
                   แล้วพอกางกลุ่มหนึ่ง กลุ่มที่ซ้อนอยู่ข้างในจะกางตามไปหมด */
                if (page !== '') { html += '</div></section>'; }
                page = s.page_key;

                var count = countInPage(s.page_key, q);
                var open  = (q !== '');   // ค้นหาอยู่ = กางให้เลย ไม่งั้นจะเห็นแต่หัวข้อเปล่า

                html += '<section class="st-group' + (open ? ' is-open' : '') + '"'
                      + ' data-page="' + escapeHtml(s.page_key) + '">'
                      + '<button type="button" class="st-group-head">'
                      + '<i data-lucide="chevron-right" class="st-group-caret h-4 w-4" aria-hidden="true"></i>'
                      + '<span class="st-group-title">' + escapeHtml(s.guide_title) + '</span>'
                      + '<span class="st-group-key">' + escapeHtml(s.page_key) + '</span>'
                      + '<span class="st-group-count">' + count + ' ขั้น</span>'
                      + '</button>'
                      + '<div class="st-group-body">';
            }

            shown++;
            html += stepCard(s);
        }

        if (page !== '') { html += '</div></section>'; }

        if (shown === 0) {
            html = '<p class="px-4 py-12 text-center text-sm text-slate-400">ไม่พบขั้นตอนที่ตรงกับคำค้น</p>';
        }

        box.innerHTML = html;
        bindCards();
        bindGroups();
        icons();
    }


    /* นับขั้นตอนของหน้าหนึ่งที่ผ่านคำค้นแล้ว — ตัวเลขบนหัวกลุ่มต้องตรงกับ
       จำนวนการ์ดที่กางออกมาจริง ไม่ใช่จำนวนทั้งหมดที่มีในหน้านั้น */
    function countInPage(pageKey, q) {
        var n = 0;

        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];
            if (s.page_key !== pageKey) { continue; }

            if (q !== '') {
                var hay = (s.page_key + ' ' + s.guide_title + ' ' + s.title + ' '
                         + s.description + ' ' + s.target_selector).toLowerCase();
                if (hay.indexOf(q) === -1) { continue; }
            }
            n++;
        }
        return n;
    }


    function bindGroups() {
        var heads = document.querySelectorAll('#guideList .st-group-head');

        for (var i = 0; i < heads.length; i++) {
            heads[i].addEventListener('click', function () {
                var group = this.closest('.st-group');
                if (!group) { return; }

                /* กางกลุ่มที่มีการ์ดค้างแก้อยู่แล้วพับกลับ = งานที่พิมพ์หายไปจากสายตา
                   แต่ค่ายังอยู่ในหน่วยความจำและกดบันทึกได้ปกติ จึงไม่ต้องห้ามพับ
                   แค่ต้องมีเครื่องหมายบอกที่หัวกลุ่ม (ดู .st-group.has-dirty ใน app.css) */
                group.classList.toggle('is-open');
            });
        }
    }


    function stepCard(s) {
        var target = (s.target_selector || '').trim();

        return '<div class="st-step" data-id="' + s.step_id + '">'

             + '<div class="flex items-center gap-2">'
             + '<span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">'
             + s.step_order + '</span>'
             + '<span class="font-mono text-[11px] text-slate-400">'
             + (target !== '' ? escapeHtml(target) : 'กล่องกลางจอ (ไม่ชี้ที่ใด)')
             + '</span>'
             + '<span class="st-dirty ml-auto text-[11px] font-semibold text-amber-600" hidden>ยังไม่บันทึก</span>'
             + '</div>'

             + '<label class="st-label mt-3">หัวข้อ</label>'
             + '<input type="text" class="st-input st-f" data-field="title" maxlength="150" value="'
             + escapeHtml(s.title) + '">'

             + '<label class="st-label mt-3">คำอธิบาย</label>'
             + '<textarea class="st-input st-f" data-field="description" maxlength="1000">'
             + escapeHtml(s.description) + '</textarea>'

             + '</div>';
    }


    function bindCards() {
        var fields = document.querySelectorAll('#guideList .st-f');

        for (var i = 0; i < fields.length; i++) {
            fields[i].addEventListener('input', function () {
                var card = this.closest('.st-step');
                if (!card) { return; }

                var id = parseInt(card.getAttribute('data-id'), 10);
                var s  = findStep(id);
                if (!s) { return; }

                s[this.getAttribute('data-field')] = this.value;

                dirty[id] = true;
                card.classList.add('is-dirty');

                var flag = card.querySelector('.st-dirty');
                if (flag) { flag.hidden = false; }

                /* ทำเครื่องหมายที่หัวกลุ่มด้วย เผื่อผู้ใช้พับกลุ่มนี้กลับไป
                   จะได้ยังเห็นว่ามีของค้างอยู่ข้างใน ไม่ใช่หายไปเงียบ ๆ */
                var group = card.closest('.st-group');
                if (group) { group.classList.add('has-dirty'); }

                refreshPending();
            });
        }
    }


    function findStep(id) {
        for (var i = 0; i < steps.length; i++) {
            if (steps[i].step_id === id) { return steps[i]; }
        }
        return null;
    }


    function refreshPending() {
        var n = 0;
        var k;
        for (k in dirty) { if (dirty.hasOwnProperty(k)) { n++; } }

        if (el('guidePending'))  { el('guidePending').textContent = String(n); }
        if (el('btnGuideSave'))  { el('btnGuideSave').disabled = (n === 0); }
    }


    function saveGuide() {
        var rows = [];
        var k;

        for (k in dirty) {
            if (!dirty.hasOwnProperty(k)) { continue; }

            var s = findStep(parseInt(k, 10));
            if (!s) { continue; }

            /* ตรวจที่นี่ก่อนยิง เพื่อบอกได้ว่าใบไหนมีปัญหา
               ถ้าปล่อยให้เซิร์ฟเวอร์ตอบกลับมา จะรู้แค่ "บรรทัดที่ 3" ซึ่งหาไม่เจอบนจอ */
            if (String(s.title).trim() === '') {
                bad('ขั้นที่ ' + s.step_order + ' ของหน้า ' + s.page_key + ': หัวข้อว่างไม่ได้');
                return;
            }
            if (String(s.description).trim() === '') {
                bad('ขั้นที่ ' + s.step_order + ' ของหน้า ' + s.page_key
                  + ': คำอธิบายว่างไม่ได้ — ขั้นตอนที่ไม่มีคำอธิบายไม่ช่วยอะไรผู้ใช้');
                return;
            }

            rows.push({
                step_id:         s.step_id,
                step_order:      s.step_order,
                target_selector: s.target_selector,
                title:           s.title,
                description:     s.description,
                side:            s.side,
                align:           s.align,
                is_active:       s.is_active
            });
        }

        if (rows.length === 0) { return; }

        var btn = el('btnGuideSave');
        if (btn) { btn.disabled = true; }

        post(CFG.guideUrl, { action: 'admin_save', rows: rows })
            .then(function (res) {
                if (!res || !res.ok) {
                    if (btn) { btn.disabled = false; }
                    bad(res && res.message ? res.message : 'บันทึกไม่สำเร็จ');
                    return;
                }

                dirty = {};
                refreshPending();

                var cards = document.querySelectorAll('#guideList .st-step.is-dirty');
                for (var i = 0; i < cards.length; i++) {
                    cards[i].classList.remove('is-dirty');
                    var flag = cards[i].querySelector('.st-dirty');
                    if (flag) { flag.hidden = true; }
                }

                var groups = document.querySelectorAll('#guideList .st-group.has-dirty');
                for (var g = 0; g < groups.length; g++) {
                    groups[g].classList.remove('has-dirty');
                }

                ok(res.message);
            })['catch'](function () {
                if (btn) { btn.disabled = false; }
                bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
            });
    }


    /* ปุ่มนี้ทำสองอย่าง: ล้างประวัติการดูของคุณ และเปิดการเด้งอัตโนมัติ
       ให้คู่มือที่เคยปิดไว้ ต้องบอกก่อน เพราะอย่างหลังไปแก้ค่าที่ตั้งไว้รายหน้า
       ซึ่งเป็นของที่ผู้ดูแลคนอื่นอาจตั้งใจปิดเอง */
    function resetSeen() {
        var run = function () {
            post(CFG.guideUrl, { action: 'reset' })
                .then(function (res) {
                    if (!res || !res.ok) {
                        bad(res && res.message ? res.message : 'ล้างประวัติไม่สำเร็จ');
                        return;
                    }
                    ok(res.message);
                    loadGuide();   /* โหลดใหม่ให้สวิตช์ auto_start ในตารางตรงกับที่เพิ่งเปลี่ยน */
                })['catch'](function () { bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); });
        };

        if (typeof window.appConfirm !== 'function') { run(); return; }

        window.appConfirm({
            title: 'ให้คู่มือเด้งใหม่ทุกหน้า',
            text:  'ล้างประวัติการดูคู่มือของคุณ และเปิดการเด้งอัตโนมัติให้ทุกหน้าที่เคยปิดไว้ '
                 + 'ครั้งถัดไปที่เข้าแต่ละหน้า คู่มือจะขึ้นมาเองอีกครั้งหน้าละหนึ่งครั้ง',
            confirmText: 'ให้เด้งใหม่'
        }).then(function (yes) { if (yes) { run(); } });
    }


    /* ============================================================
     *  เริ่มทำงาน
     * ============================================================ */
    function init() {
        initTabs();

        if (el('btnSaveMail'))    { el('btnSaveMail').addEventListener('click', saveMail); }
        if (el('btnTestMail'))    { el('btnTestMail').addEventListener('click', testMail); }
        if (el('btnFlush'))       { el('btnFlush').addEventListener('click', flushQueue); }
        if (el('btnRetry'))       { el('btnRetry').addEventListener('click', retryFailed); }
        if (el('btnReloadQueue')) { el('btnReloadQueue').addEventListener('click', loadQueue); }

        if (el('btnGuideSave'))  { el('btnGuideSave').addEventListener('click', saveGuide); }
        if (el('btnGuideReset')) { el('btnGuideReset').addEventListener('click', resetSeen); }

        if (el('guideSearch')) {
            el('guideSearch').addEventListener('input', function () {
                renderGuide(this.value);
            });
        }

        /* แก้ค้างไว้แล้วปิดหน้าไป งานที่พิมพ์มาจะหายทั้งหมด ต้องเตือนก่อน */
        window.addEventListener('beforeunload', function (e) {
            var k;
            for (k in dirty) {
                if (dirty.hasOwnProperty(k)) {
                    e.preventDefault();
                    e.returnValue = '';
                    return '';
                }
            }
        });

        var inputs = document.querySelectorAll('#tabMail .st-input');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('input', clearBad);
        }

        /* ---------- โหลดเฉพาะข้อมูลของเรื่องที่เปิดอยู่ ----------
         * สองเรื่องนี้แยกเป็นคนละเมนูแล้ว (ดูหัวไฟล์ pages/settings.php)
         * คนที่เข้ามาแก้คู่มือไม่ต้องรอค่า SMTP กับคิวเมล และกลับกันด้วย
         *
         * ค่าเริ่มต้นเป็น 'mail' เพื่อให้พฤติกรรมเดิมไม่เปลี่ยน
         * ถ้าวันหลังมีที่ไหนเรียกหน้านี้โดยไม่ได้ส่ง section มา
         */
        var section = CFG.section || 'mail';

        if (section === 'guide') {
            guideLoaded = true;
            loadGuide();
        } else {
            loadSettings();
        }

        icons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
