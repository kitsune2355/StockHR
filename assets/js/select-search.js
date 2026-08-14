/* ============================================================
 *  select-search.js — เปลี่ยน <select> ธรรมดาให้พิมพ์ค้นหาได้
 *
 *  วิธีใช้: ใส่ data-search ที่ <select> แล้วโหลดไฟล์นี้ จบ
 *      <select id="departmentId" data-search>...</select>
 *
 *  ถ้าเติม <option> ทีหลังด้วย JavaScript ให้เรียก
 *      window.SelectSearch.refresh(selectElement)
 *
 *  ---------------------------------------------------------------
 *  ทำไมไม่ทิ้ง <select> เดิมไปเลย
 *  ---------------------------------------------------------------
 *  ตัวเลือกจริงยังอยู่ใน <select> ที่ซ่อนไว้เสมอ ตัวนี้เป็นแค่หน้ากาก
 *    - โค้ดเดิมที่อ่าน el.value / ดัก event 'change' ใช้ได้เหมือนเดิม
 *      ไม่ต้องแก้ทุกไฟล์ที่เคยใช้ select
 *    - ถ้า JavaScript พังหรือโหลดไม่ทัน ผู้ใช้ยังเห็น select ปกติใช้งานได้
 *    - ฟอร์มที่ส่งแบบ POST ธรรมดายังส่งค่าไปได้ตามเดิม
 *
 *  บนจอเล็กกางเป็นแผ่นจากด้านล่าง (bottom sheet) แทน dropdown
 *  เพราะรายการยาว ๆ บนมือถือถ้ากางลอยจะโดนคีย์บอร์ดบังจนเลือกไม่ได้
 *
 *  เขียนแบบไม่พึ่ง library ใด ๆ และรองรับเบราว์เซอร์เดิมของ Tabulator
 * ============================================================ */
(function () {
    'use strict';

    var open = null;          // ตัวที่กางอยู่ตอนนี้ มีได้ทีละตัว
    var uid  = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls)  { n.className = cls; }
        if (text) { n.textContent = text; }
        return n;
    }

    /* ตัดวรรณยุกต์และช่องว่างออกก่อนเทียบ เพื่อให้พิมพ์ผิดเล็กน้อยยังหาเจอ
       ภาษาไทยไม่มีตัวพิมพ์ใหญ่เล็ก แต่ผู้ใช้พิมพ์เว้นวรรคไม่เหมือนกันบ่อยมาก */
    function norm(text) {
        return String(text).toLowerCase().replace(/\s+/g, '');
    }

    function matches(haystack, needle) {
        if (needle === '') { return true; }
        return norm(haystack).indexOf(norm(needle)) !== -1;
    }


    /* ============================================================
     *  สร้างหน้ากากให้ select หนึ่งตัว
     * ============================================================ */
    function enhance(select) {
        if (!select || select.__ss) { return; }

        var box   = el('div', 'ss');
        var btn   = el('button', 'ss-btn');
        var label = el('span', 'ss-label');
        var caret = el('span', 'ss-caret');

        btn.type = 'button';
        btn.appendChild(label);
        btn.appendChild(caret);

        /* ปุ่มต้องหน้าตาเหมือนช่องกรอกอื่นในหน้า จึงยืม class ของ select เดิมมาใช้
           ถ้าเขียนสไตล์ใหม่เอง เวลาธีมเปลี่ยนจะหลุดกันแค่ช่องนี้ */
        btn.className = 'ss-btn ' + select.className;

        var panel  = el('div', 'ss-panel');
        var search = el('input', 'ss-search');
        var list   = el('div', 'ss-list');
        var empty  = el('div', 'ss-empty', 'ไม่พบรายการที่ค้นหา');

        search.type = 'search';
        search.setAttribute('autocomplete', 'off');
        search.placeholder = select.getAttribute('data-search-placeholder') || 'พิมพ์เพื่อค้นหา';

        var head = el('div', 'ss-head');
        head.appendChild(search);

        panel.appendChild(head);
        panel.appendChild(list);
        panel.appendChild(empty);

        select.parentNode.insertBefore(box, select);
        box.appendChild(select);
        box.appendChild(btn);
        box.appendChild(panel);

        select.classList.add('ss-native');

        /* ต่อ label ของเดิมเข้ากับปุ่ม ไม่งั้นกดที่ label แล้วจะไม่มีอะไรเกิดขึ้น */
        uid++;
        btn.id = 'ss-btn-' + uid;
        if (select.id) {
            var lb = document.querySelector('label[for="' + select.id + '"]');
            if (lb) { lb.setAttribute('for', btn.id); }
        }
        if (select.getAttribute('aria-label')) {
            btn.setAttribute('aria-label', select.getAttribute('aria-label'));
        }

        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        list.setAttribute('role', 'listbox');

        var api = {
            select: select, box: box, btn: btn, label: label,
            panel: panel, search: search, list: list, empty: empty,
            items: [], active: -1
        };
        select.__ss = api;

        build(api);
        paint(api);

        /* ---------- เปิด/ปิด ---------- */
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (box.classList.contains('is-open')) { close(api); } else { show(api); }
        });

        search.addEventListener('input', function () { filter(api); });

        search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(api, 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(api, -1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var vis = visible(api);
                if (vis.length > 0) { pick(api, vis[api.active >= 0 ? api.active : 0]); }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close(api);
                btn.focus();
            }
        });

        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                show(api);
            }
        });

        return api;
    }


    /* ---------- อ่าน <option> มาสร้างรายการ ---------- */
    function build(api) {
        api.list.innerHTML = '';
        api.items = [];

        for (var i = 0; i < api.select.options.length; i++) {
            var op = api.select.options[i];

            var row = el('div', 'ss-item');
            row.setAttribute('role', 'option');
            row.setAttribute('data-value', op.value);

            /* ---------- บรรทัดรอง ----------
             * <option data-sub="ชื่อบริษัท"> จะถูกวาดเป็นสองบรรทัด
             * ชื่อหลักบรรทัดบน ส่วนที่ใช้แยกความต่างอยู่บรรทัดล่างแบบตัวจาง
             *
             * ทำไมไม่ต่อกันเป็นบรรทัดเดียว: .ss-item ตัดข้อความที่ยาวเกินด้วย
             * ellipsis อยู่แล้ว "จัดซื้อ — บริษัท แฮปปี้แลนด์ กรุ๊ป จำกัด" ในช่องกว้าง
             * 350px จะถูกตัดตรงกลางชื่อบริษัทพอดี ซึ่งคือส่วนที่ต้องใช้แยกความต่าง
             * ผลคือกลับไปเห็น "จัดซื้อ — บริษัท แฮปปี้แลนด์…" สองบรรทัดเหมือนเดิม
             *
             * ข้อความยังอยู่ใน textContent ของ row ทั้งก้อน การค้นหาจึงยังหาจาก
             * ชื่อบริษัทได้ด้วยโดยไม่ต้องแก้ตัวค้นหา
             */
            var sub = op.getAttribute('data-sub');

            if (sub) {
                row.classList.add('has-sub');
                row.appendChild(el('span', 'ss-item-main', op.textContent));
                row.appendChild(el('span', 'ss-item-sub',  sub));
            } else {
                row.textContent = op.textContent;
            }

            if (op.disabled) { row.classList.add('is-disabled'); }

            (function (node) {
                node.addEventListener('click', function () {
                    if (!node.classList.contains('is-disabled')) { pick(api, node); }
                });
            })(row);

            api.list.appendChild(row);
            api.items.push(row);
        }
    }

    /* ---------- อัปเดตข้อความบนปุ่มให้ตรงกับค่าที่เลือก ---------- */
    function paint(api) {
        var op = api.select.options[api.select.selectedIndex];
        var text = op ? op.textContent : '';
        var sub  = op ? op.getAttribute('data-sub') : '';

        /* ปุ่มที่หุบอยู่มีบรรทัดเดียว จึงต่อกันด้วยตัวจางแทนการขึ้นบรรทัดใหม่
           ถ้าโชว์แค่ชื่อหลัก ผู้ใช้ที่เพิ่งเลือก "จัดซื้อ" ของบริษัทหนึ่ง
           จะแยกไม่ออกว่าเลือกอันไหนไปทันทีที่รายการหุบลง */
        api.label.innerHTML = '';
        api.label.appendChild(el('span', 'ss-label-main', text));
        if (sub) { api.label.appendChild(el('span', 'ss-label-sub', sub)); }

        /* select เดิมถูกปิดไว้ = ปุ่มหน้ากากต้องปิดตาม ไม่งั้นจะกดกางรายการได้
           ทั้งที่ค่าถูกล็อก แล้วผู้ใช้จะเลือกไปโดยที่ค่าไม่เปลี่ยนจริง */
        api.btn.disabled = api.select.disabled;
        api.box.classList.toggle('is-disabled', api.select.disabled);

        /* ตัวเลือกที่เป็น placeholder (value ว่าง) ให้ดูจางเหมือน placeholder จริง */
        if (!op || op.value === '') { api.label.classList.add('is-placeholder'); }
        else { api.label.classList.remove('is-placeholder'); }

        for (var i = 0; i < api.items.length; i++) {
            var on = (i === api.select.selectedIndex);
            api.items[i].classList.toggle('is-selected', on);
            api.items[i].setAttribute('aria-selected', on ? 'true' : 'false');
        }
    }

    function visible(api) {
        var out = [];
        for (var i = 0; i < api.items.length; i++) {
            if (!api.items[i].classList.contains('is-hidden')) { out.push(api.items[i]); }
        }
        return out;
    }

    function filter(api) {
        var q = api.search.value;
        var n = 0;

        for (var i = 0; i < api.items.length; i++) {
            var hit = matches(api.items[i].textContent, q);
            api.items[i].classList.toggle('is-hidden', !hit);
            api.items[i].classList.remove('is-active');
            if (hit) { n++; }
        }

        api.active = -1;
        api.empty.style.display = (n === 0) ? 'block' : 'none';
    }

    function move(api, step) {
        var vis = visible(api);
        if (vis.length === 0) { return; }

        api.active += step;
        if (api.active < 0) { api.active = vis.length - 1; }
        if (api.active >= vis.length) { api.active = 0; }

        for (var i = 0; i < vis.length; i++) {
            vis[i].classList.toggle('is-active', i === api.active);
        }
        vis[api.active].scrollIntoView({ block: 'nearest' });
    }

    function pick(api, node) {
        api.select.value = node.getAttribute('data-value');

        /* ยิง change เองเพราะการตั้ง .value ด้วยโค้ดไม่ทำให้ event เกิดขึ้น
           โค้ดเดิมที่ดัก change ไว้ (เช่น โหลดใบเบิก, กรองตาราง) จึงยังทำงาน */
        var ev;
        if (typeof Event === 'function') {
            ev = new Event('change', { bubbles: true });
        } else {
            ev = document.createEvent('HTMLEvents');
            ev.initEvent('change', true, false);
        }
        api.select.dispatchEvent(ev);

        paint(api);
        close(api);
        api.btn.focus();
    }

    function show(api) {
        if (open && open !== api) { close(open); }

        api.box.classList.add('is-open');
        api.btn.setAttribute('aria-expanded', 'true');
        api.search.value = '';
        filter(api);
        open = api;

        /* กางขึ้นบนถ้าที่ว่างด้านล่างไม่พอ ไม่งั้นรายการจะทะลุออกนอกจอ
           วัดจากปุ่มจริงทุกครั้ง เพราะตำแหน่งเปลี่ยนตามการเลื่อนหน้า */
        var r = api.btn.getBoundingClientRect();
        api.box.classList.toggle('is-up', (window.innerHeight - r.bottom) < 260 && r.top > 260);

        /* บนมือถือไม่โฟกัสช่องค้นหาทันที ไม่งั้นคีย์บอร์ดเด้งมาบังรายการ
           ให้ผู้ใช้เลื่อนดูก่อน อยากค้นค่อยแตะช่องเอง */
        if (window.matchMedia('(min-width: 640px)').matches) {
            api.search.focus();
        }

        var sel = api.list.querySelector('.is-selected');
        if (sel) { sel.scrollIntoView({ block: 'nearest' }); }
    }

    function close(api) {
        api.box.classList.remove('is-open', 'is-up');
        api.btn.setAttribute('aria-expanded', 'false');
        if (open === api) { open = null; }
    }


    /* ---------- ปิดเมื่อคลิกที่อื่น หรือกด Esc ---------- */
    document.addEventListener('mousedown', function (e) {
        if (open && !open.box.contains(e.target)) { close(open); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && open) { close(open); }
    });

    /* หน้าจอหมุนหรือเปลี่ยนขนาด ตำแหน่งที่คำนวณไว้ใช้ไม่ได้แล้ว ปิดไปก่อน */
    window.addEventListener('resize', function () { if (open) { close(open); } });


    /* ---------- เปิดใช้กับทุก select ที่ติดป้าย data-search ---------- */
    function enhanceAll(root) {
        var nodes = (root || document).querySelectorAll('select[data-search]');
        for (var i = 0; i < nodes.length; i++) { enhance(nodes[i]); }
    }

    /* เติม option ทีหลังแล้วต้องเรียกตัวนี้ ไม่งั้นหน้ากากยังเป็นรายการเดิม */
    function refresh(select) {
        if (!select) { return; }
        if (!select.__ss) { enhance(select); return; }
        build(select.__ss);
        paint(select.__ss);
    }

    window.SelectSearch = { enhance: enhance, enhanceAll: enhanceAll, refresh: refresh };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhanceAll(); });
    } else {
        enhanceAll();
    }
})();
