/* ============================================================
 *  print-settings.js — Admin mode > เอกสารพิมพ์ (pages/print_settings.php)
 *
 *  ตั้งค่าแยกรายเอกสาร 5 แบบ: เลือกแท็บด้านขวา = ฟอร์มด้านซ้ายแสดงค่าของเอกสารนั้น
 *  ค่าของทุกแบบอยู่ในหน่วยความจำ (vals) สลับแท็บไปมาได้โดยค่าที่แก้ไม่หาย
 *  กดบันทึกครั้งเดียว = บันทึกทุกแบบที่แก้ไว้
 *
 *  ทุกช่องในฟอร์มมี data-key = ชื่อช่อง (title, sign1, fs_table, notes …) ไม่มีชื่อประเภทนำหน้า
 *  ช่องที่ไม่เกี่ยวกับเอกสารที่เลือก (applies จาก API) ถูกซ่อน
 *
 *  ตัวอย่างเป็นเอกสารสมมติ (print_doc.php?mock=<ประเภท> — ดู print_mock_doc) ไม่ใช่เอกสารจริงในระบบ
 *  ตัวอย่างทันที: ทุกครั้งที่แก้ (หน่วง 350ms) ส่งค่าที่ยังไม่บันทึกไปที่
 *  print_doc.php แบบ POST (preview_cfg) ให้วาดใบใหม่ในเฟรมที่ซ่อนอยู่ เสร็จแล้วสลับเฟรม
 *  หน้าพิมพ์ใช้ค่านั้นวาดครั้งเดียว ไม่ลงฐานข้อมูล
 *
 *  หมายเหตุท้ายฟอร์ม: contenteditable + execCommand (ไม่ต้องโหลดไลบรารี editor)
 *  ค่าจริงคือ HTML ในช่องซ่อน #notes — เซิร์ฟเวอร์ทำความสะอาดซ้ำเสมอ
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.PRINTSET || {};
    var saved = {};      // type -> ค่าที่บันทึกล่าสุด
    var vals = {};       // type -> ค่าที่กำลังแก้ (รวมของแท็บที่ไม่ได้เปิดอยู่)
    var defaults = {};
    var applies = {};    // ช่อง -> ประเภทที่ช่องนี้มีผล
    var cur = 'request';
    var ready = false;

    var TYPES = [
        { key: 'request',   label: 'ใบเบิก' },
        { key: 'uniform',   label: 'เอกสารรับชุดเครื่องแบบ' },
        { key: 'issue',     label: 'ใบจ่าย' },
        { key: 'receive',   label: 'ใบรับเข้า' },
        { key: 'adjust',    label: 'ใบปรับยอด' }
    ];

    function el(id) { return document.getElementById(id); }
    function fields() { return document.querySelectorAll('#psBody [data-key]'); }
    function labelOf(t) {
        for (var i = 0; i < TYPES.length; i++) { if (TYPES[i].key === t) { return TYPES[i].label; } }
        return t;
    }
    function copy(o) { var r = {}; for (var k in o) { if (o.hasOwnProperty(k)) { r[k] = o[k]; } } return r; }

    function post(payload, done, fail) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
            if (!res) { fail('เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง'); return; }
            if (!res.ok) { fail(res.message || 'ทำรายการไม่สำเร็จ'); return; }
            done(res);
        };
        xhr.onerror = function () { fail('ติดต่อเซิร์ฟเวอร์ไม่ได้'); };
        xhr.send(JSON.stringify(payload));
    }

    function bad(msg) {
        if (typeof window.appError === 'function') { window.appError(msg); } else { alert(msg); }
    }

    /* ============================================================
     *  ตัวแก้ไขหมายเหตุ
     * ============================================================ */
    function editor() { return el('psNotesEditor'); }

    /* HTML ที่ได้จาก contenteditable มีของแถมต่างกันไปแต่ละเบราว์เซอร์
       เก็บไว้แค่โครง (ย่อหน้า/รายการ/ตัวหนา…) ตัด attribute ทิ้ง ให้ตรงกับที่เซิร์ฟเวอร์ยอมรับ */
    function cleanHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html || '';
        var keep = { P: 1, BR: 1, DIV: 1, OL: 1, UL: 1, LI: 1, B: 1, STRONG: 1, I: 1, EM: 1, U: 1 };

        function walk(node) {
            for (var i = node.childNodes.length - 1; i >= 0; i--) {
                var c = node.childNodes[i];
                if (c.nodeType === 1) {
                    walk(c);
                    if (!keep[c.tagName]) {
                        while (c.firstChild) { node.insertBefore(c.firstChild, c); }
                        node.removeChild(c);
                    } else {
                        while (c.attributes.length) { c.removeAttribute(c.attributes[0].name); }
                    }
                } else if (c.nodeType !== 3) {
                    node.removeChild(c);
                }
            }
        }
        walk(box);
        var out = box.innerHTML
            .replace(/<div>/gi, '<p>').replace(/<\/div>/gi, '</p>')
            .replace(/<p>(\s|&nbsp;|<br>)*<\/p>/gi, '')
            .replace(/(<br>\s*)+$/i, '');
        return out.trim();
    }

    function hasText(html) {
        var d = document.createElement('div');
        d.innerHTML = html;
        return (d.textContent || '').replace(/ /g, ' ').trim() !== '';
    }

    function normNotes(html) {
        var h = cleanHtml(html);
        return hasText(h) ? h : '';
    }

    function syncEditor() {
        el('notes').value = normNotes(editor().innerHTML);
        changed();
    }

    function setupEditor() {
        var ed = editor();

        /* ปุ่มเครื่องมือ — mousedown + preventDefault ไม่ให้โฟกัสหลุดจากตัวแก้ไข */
        document.querySelector('.ps-tools').addEventListener('mousedown', function (e) {
            var b = e.target.closest ? e.target.closest('[data-cmd]') : null;
            if (!b) { return; }
            e.preventDefault();
            ed.focus();
            var cmd = b.getAttribute('data-cmd');
            if (cmd === 'plain') {
                /* เอาเลข/จุดออก: สั่งคำสั่งเดิมซ้ำกับรายการชนิดนั้น = ถอดรายการ */
                if (document.queryCommandState('insertOrderedList')) { document.execCommand('insertOrderedList'); }
                if (document.queryCommandState('insertUnorderedList')) { document.execCommand('insertUnorderedList'); }
            } else {
                document.execCommand(cmd, false, null);
            }
            syncEditor();
            toolState();
        });

        ed.addEventListener('input', syncEditor);
        ed.addEventListener('keyup', toolState);
        ed.addEventListener('mouseup', toolState);

        /* วางจาก Word/เว็บ: เหลือแต่ตัวอักษร ไม่ติดสี ฟอนต์ ตาราง มาด้วย */
        ed.addEventListener('paste', function (e) {
            var cb = e.clipboardData || window.clipboardData;
            if (!cb) { return; }
            e.preventDefault();
            document.execCommand('insertText', false, cb.getData('text/plain') || cb.getData('Text') || '');
        });

        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (err) { /* เบราว์เซอร์เก่า */ }
    }

    function toolState() {
        var btns = document.querySelectorAll('.ps-tool[data-cmd]');
        for (var i = 0; i < btns.length; i++) {
            var cmd = btns[i].getAttribute('data-cmd');
            var on = false;
            if (cmd !== 'plain' && cmd !== 'removeFormat') {
                try { on = document.queryCommandState(cmd); } catch (e) { on = false; }
            }
            btns[i].classList.toggle('is-on', on);
        }
    }

    /* ============================================================
     *  ฟอร์ม <-> ค่าของเอกสารที่เลือก
     * ============================================================ */
    function fill(values) {
        var list = fields();
        for (var i = 0; i < list.length; i++) {
            var f = list[i];
            var v = values[f.getAttribute('data-key')];
            if (v === undefined) { v = ''; }
            if (f.type === 'checkbox') { f.checked = (v === '1'); } else { f.value = v; }
        }
        editor().innerHTML = values.notes || '';
        applyStart();
    }

    function applyStart() {
        var st = el('notes_start');
        var ol = editor().querySelector('ol');
        if (!st || !ol) { return; }
        var n = parseInt(st.value, 10);
        if (n > 1) { ol.setAttribute('start', String(n)); } else { ol.removeAttribute('start'); }
    }

    function read() {
        var out = {};
        var list = fields();
        for (var i = 0; i < list.length; i++) {
            var f = list[i];
            out[f.getAttribute('data-key')] = (f.type === 'checkbox') ? (f.checked ? '1' : '0') : f.value;
        }
        return out;
    }

    /* แสดงเฉพาะช่องที่มีผลกับเอกสารนี้ — section ที่ไม่เหลือช่องเลยถูกซ่อนทั้งก้อน */
    function applyVisibility() {
        var list = fields();
        for (var i = 0; i < list.length; i++) {
            var key = list[i].getAttribute('data-key');
            var on = !applies[key] || applies[key].indexOf(cur) >= 0;
            var box = list[i].closest('.ps-fld, .ps-toggle, .ps-font, .ps-sec');
            if (box && !box.classList.contains('ps-sec')) { box.hidden = !on; }
        }
        var secs = document.querySelectorAll('#psBody .ps-sec');
        for (var s = 0; s < secs.length; s++) {
            var shown = secs[s].querySelectorAll('[data-key]');
            var any = false;
            for (var j = 0; j < shown.length; j++) {
                var bx = shown[j].closest('.ps-fld, .ps-toggle, .ps-font');
                if (!bx || !bx.hidden) { any = true; break; }
            }
            secs[s].hidden = !any;
        }
        /* ใบจ่ายที่ใช้หมายเหตุของเอกสารรับชุดเครื่องแบบ — ไม่ต้องมีตัวแก้ไข/เลขข้อของตัวเอง */
        var same = document.querySelector('[data-key="notes_same_uniform"]');   // ps_toggle ไม่มี id
        var linked = !!same && cur === 'issue' && same.checked;
        if (el('psNotesOwn') && linked) { el('psNotesOwn').hidden = true; }
        if (el('psNotesStartRow') && linked) { el('psNotesStartRow').hidden = true; }
        el('psForName').textContent = labelOf(cur);
        el('psNsNote').hidden = (cur !== 'uniform');
    }

    function sameVals(a, b) {
        for (var k in a) {
            if (!applies[k]) { continue; }   // ช่องที่ API ไม่รู้จัก ไม่นับ
            if (a.hasOwnProperty(k) &&
                String(a[k]).replace(/\r/g, '').trim() !== String(b[k] === undefined ? '' : b[k]).trim()) {
                return false;
            }
        }
        return true;
    }

    function dirtyTypes() {
        vals[cur] = read();
        var out = [];
        for (var i = 0; i < TYPES.length; i++) {
            var t = TYPES[i].key;
            if (vals[t] && !sameVals(vals[t], saved[t])) { out.push(t); }
        }
        return out;
    }

    function refreshBar() {
        var d = dirtyTypes();
        el('psSave').disabled = d.length === 0;
        el('psDirty').hidden = d.length === 0;
        if (d.length) {
            var names = [];
            for (var i = 0; i < d.length; i++) { names.push(labelOf(d[i])); }
            el('psDirtyText').textContent = 'ยังไม่บันทึก: ' + names.join(', ');
        }
        var tabs = document.querySelectorAll('.ps-type');
        for (var j = 0; j < tabs.length; j++) {
            tabs[j].classList.toggle('is-dirty', d.indexOf(tabs[j].getAttribute('data-type')) >= 0);
        }
    }

    /* ทุกการแก้ = อัปเดตแถบบันทึกทันที + วาดตัวอย่างใหม่แบบหน่วงเวลา */
    var pvTimer = null;
    function changed() {
        if (!ready) { return; }
        refreshBar();
        if (pvTimer) { clearTimeout(pvTimer); }
        pvTimer = setTimeout(renderPreview, 350);
    }

    function selectType(t) {
        if (ready) { vals[cur] = read(); }
        cur = t;
        fill(vals[cur]);
        applyVisibility();
        renderTypes();
        refreshBar();
        renderPreview();
        el('psBody').scrollTop = 0;
    }

    /* ============================================================
     *  ตัวอย่างทันที
     * ============================================================ */
    var PAPER_W = 860;      // ใบพิมพ์กว้าง A4 (210mm) — วาดเต็มขนาดแล้วย่อทั้งก้อนให้พอดีช่อง
    var front = 'psFrame';
    var pvSeq = 0;

    function fitFrame() {
        var wrap = el('psFrameWrap');
        if (!wrap) { return; }
        var sc = Math.min(1, wrap.clientWidth / PAPER_W);
        var ids = ['psFrame', 'psFrameB'];
        for (var i = 0; i < ids.length; i++) {
            var fr = el(ids[i]);
            fr.style.width = PAPER_W + 'px';
            fr.style.height = Math.max(wrap.clientHeight, 200) / sc + 'px';
            fr.style.transform = 'scale(' + sc + ')';
        }
    }

    function renderTypes() {
        var html = '';
        for (var i = 0; i < TYPES.length; i++) {
            var t = TYPES[i];
            html += '<button type="button" role="tab" class="ps-type' + (t.key === cur ? ' is-on' : '') + '"' +
                ' data-type="' + t.key + '" aria-selected="' + (t.key === cur ? 'true' : 'false') + '">' +
                t.label + '<span class="ps-type-dot" aria-hidden="true"></span></button>';
        }
        el('psTypes').innerHTML = html;
    }

    function renderPreview() {
        pvTimer = null;
        /* เอกสารสมมติของประเภทที่เลือก — มีครบทุกส่วนที่ตั้งค่าได้ เหมือนกันทุกครั้งที่เปิด */
        var url = 'print_doc.php?mock=' + encodeURIComponent(cur);
        el('psOpen').href = url;

        /* วาดในเฟรมที่ซ่อนอยู่ แล้วสลับเมื่อโหลดเสร็จ — รอบเก่าที่ตามไม่ทันถูกทิ้ง (pvSeq) */
        var back = (front === 'psFrame') ? 'psFrameB' : 'psFrame';
        var seq = ++pvSeq;
        var bf = el(back);
        bf.onload = function () {
            if (seq !== pvSeq) { return; }
            try { bf.contentWindow.scrollTo(0, el(front).contentWindow.scrollY || 0); } catch (e) { /* ไม่ซีเรียส */ }
            bf.style.visibility = 'visible';
            el(front).style.visibility = 'hidden';
            front = back;
            el('psPvState').textContent = '';
        };
        el('psPvState').textContent = '· กำลังอัปเดต…';

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url + '&embed=1';
        form.target = bf.name;
        form.style.display = 'none';
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'preview_cfg';
        inp.value = JSON.stringify({ type: cur, values: read() });
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        fitFrame();
    }

    /* ============================================================
     *  เริ่มทำงาน
     * ============================================================ */
    function init() {
        setupEditor();

        post({ action: 'get' }, function (res) {
            defaults = res.defaults || {};
            applies = res.applies || {};
            var v = res.values || {};
            for (var i = 0; i < TYPES.length; i++) {
                var t = TYPES[i].key;
                /* หมายเหตุผ่านตัวทำความสะอาดฝั่งนี้ก่อน ให้ค่าที่ "บันทึกแล้ว" กับค่าในฟอร์มเทียบกันได้ตรง
                   ไม่งั้นเปิดหน้ามาก็ขึ้นว่ามีการแก้ค้างทั้งที่ยังไม่ได้แตะอะไร */
                var o = copy(v[t] || {});
                o.notes = normNotes(o.notes);
                saved[t] = o;
                vals[t] = copy(o);
                if (defaults[t]) { defaults[t].notes = normNotes(defaults[t].notes); }
            }
            el('psLoading').hidden = true;
            el('psBody').hidden = false;
            selectType(cur);
            ready = true;
            refreshBar();
            if (window.lucide) { window.lucide.createIcons(); }
        }, function (msg) {
            el('psLoading').textContent = msg;
        });

        el('psBody').addEventListener('input', function (e) {
            if (e.target === editor()) { return; }   // ตัวแก้ไขจัดการเองใน syncEditor
            if (e.target.id === 'notes_start') { applyStart(); }
            if (e.target.getAttribute('data-key') === 'notes_same_uniform') { applyVisibility(); }
            changed();
        });
        el('psBody').addEventListener('change', function (e) {
            if (e.target === editor()) { return; }
            if (e.target.getAttribute('data-key') === 'notes_same_uniform') { applyVisibility(); }
            changed();
        });

        /* ปุ่ม − / + ข้างช่องขนาดตัวอักษร (ทีละ 0.5 อยู่ในช่วงของช่องนั้น) */
        el('psBody').addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.ps-step-btn') : null;
            if (!b) { return; }
            var inp = el(b.getAttribute('data-for'));
            var n = Number(inp.value) || 0;
            n = Math.round((n + Number(b.getAttribute('data-step'))) * 2) / 2;
            n = Math.max(Number(inp.min), Math.min(Number(inp.max), n));
            inp.value = String(n);
            changed();
        });

        el('psTypes').addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-type]') : null;
            if (!b) { return; }
            selectType(b.getAttribute('data-type'));
        });

        /* ค่าตั้งต้นของเอกสารที่เลือกอยู่เท่านั้น (ยังไม่บันทึกจนกว่าจะกดบันทึก) */
        el('psReset').addEventListener('click', function () {
            fill(copy(defaults[cur] || {}));
            syncEditor();
        });

        el('psSave').addEventListener('click', function () {
            var d = dirtyTypes();
            if (!d.length) { return; }
            var payload = {};
            for (var i = 0; i < d.length; i++) { payload[d[i]] = vals[d[i]]; }
            el('psSave').disabled = true;
            post({ action: 'save', csrf_token: CFG.csrfToken, values: payload }, function (res) {
                for (var j = 0; j < d.length; j++) { saved[d[j]] = copy(vals[d[j]]); }
                refreshBar();
                if (typeof window.appSuccess === 'function') { window.appSuccess(res.message); }
            }, function (msg) {
                refreshBar();
                bad(msg);
            });
        });

        window.addEventListener('resize', fitFrame);

        window.addEventListener('beforeunload', function (e) {
            if (ready && dirtyTypes().length) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
