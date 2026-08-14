/* ============================================================
 *  permissions.js — ตารางตั้งค่าสิทธิ์การใช้งานเมนู
 *
 *  แถว = เมนูหนึ่งรายการ  คอลัมน์ = สิทธิ์ผู้ใช้งานหนึ่งระดับ
 *  กดสวิตช์เปิด/ปิดได้ทีละช่อง แก้สะสมไว้ก่อนแล้วกดบันทึกทีเดียว
 *
 *  ไม่มีการเพิ่ม/ลบแถว เพราะรายการเมนูมาจากโค้ด includes/menu.php
 *  ที่นี่ทำได้อย่างเดียวคือเปิด/ปิดสิทธิ์ของเมนูที่มีอยู่
 *
 *  "ผู้ดูแลระบบสูงสุด" ไม่มีคอลัมน์ให้ปรับ — เข้าได้ทุกเมนูเสมอ
 *  ถ้าปรับได้แล้วเผลอปิดสิทธิ์หน้านี้ของตัวเอง จะไม่มีใครแก้คืนได้อีก
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.PERMISSIONS;
    if (!CFG || typeof Tabulator === 'undefined') { return; }

    var G     = window.GridCommon;
    var ROLES = CFG.roles || [];
    /* role ที่คนเปิดหน้านี้แก้ได้ — ไม่รวม role ของตัวเอง (ดู menu_permission_editable_roles)
       ใช้ปิดคอลัมน์ไม่ให้กด ส่วนการบังคับจริงอยู่ฝั่งเซิร์ฟเวอร์ */
    var EDITABLE = CFG.editable || ROLES;

    function canEdit(role) {
        for (var i = 0; i < EDITABLE.length; i++) {
            if (EDITABLE[i] === role) { return true; }
        }
        return false;
    }

    /* เครื่องหมายถูกในช่องติ๊ก — วาดเป็น svg ตรง ๆ ไม่ผ่าน Lucide
       เพราะ Lucide แปลงไอคอนครั้งเดียวตอนหน้าโหลดเสร็จ ส่วนช่องพวกนี้
       ถูกวาดใหม่ทุกครั้งที่เปลี่ยนหน้า/กรอง ถ้าใช้ Lucide จะเหลือแต่ช่องเปล่า */
    var CHECK_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M20 6 9 17l-5-5"/></svg>';

    /* ลูกศรพับ/กางของหัวกลุ่ม วาดเป็น svg ตรง ๆ ด้วยเหตุผลเดียวกับ CHECK_SVG
       และเพราะต้องหมุนด้วย transform ซึ่งทำกับสามเหลี่ยม border ของ Tabulator ไม่ได้ */
    var CHEV_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="m6 9 6 6 6-6"/></svg>';

    var table    = null;
    var original = {};
    var skipUnloadWarning = false;

    function reloadPage() {
        skipUnloadWarning = true;
        window.location.reload();
    }

    function setHidden(el, hide) {
        if (!el) { return; }
        if (hide) { el.setAttribute('hidden', ''); } else { el.removeAttribute('hidden'); }
    }

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }


    /* ---------- เตรียมข้อมูลตั้งต้น ---------- */
    function buildRows() {
        var rows = [];

        for (var i = 0; i < CFG.rows.length; i++) {
            var src = CFG.rows[i];
            var row = {
                _key:     'r' + src.menu_key,
                _state:   'clean',
                menu_key:   src.menu_key,
                title:      src.title,
                section:    src.section,
                parent:     src.parent,
                group_icon: src.group_icon,
                icon:       src.icon,
                is_saved:   Number(src.is_saved),
                flat:       Number(src.flat)
            };
            for (var r = 0; r < ROLES.length; r++) {
                row[ROLES[r]] = Number(src[ROLES[r]]);
            }

            rows.push(row);
            original[row._key] = snapshotOf(row);
        }
        return rows;
    }

    function snapshotOf(row) {
        var s = {};
        for (var r = 0; r < ROLES.length; r++) {
            s[ROLES[r]] = String(Number(row[ROLES[r]]));
        }
        return s;
    }

    function snapshot(row) {
        return JSON.stringify(snapshotOf(row));
    }


    function markChanged(row) {
        var data = row.getData();
        data._state = (snapshot(data) === JSON.stringify(original[data._key])) ? 'clean' : 'edited';
        applyRowClass(row);
    }


    /* Lucide แปลง <i data-lucide> เป็น <svg> ครั้งเดียวตอนหน้าโหลดเสร็จ
       หัวกลุ่มถูกวาดใหม่ทุกครั้งที่จัดกลุ่ม กรอง หรือติ๊กสิทธิ์ ถ้าไม่สั่งแปลงซ้ำ
       ไอคอนกลุ่มและไอคอนหน้าชื่อเมนูจะหายไปเป็นช่องว่างเหมือนที่เคยเจอในตารางหน่วยงาน */
    function renderLucideIcons(root) {
        if (typeof window.renderIcons === 'function') { window.renderIcons(root || document.body); }
        else if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons(); }
    }

    /* ระบายเฉพาะช่องที่ค่าเปลี่ยน จะได้เห็นทันทีว่าไปแก้สิทธิ์ไหนของเมนูไหน */
    function applyRowClass(row) {
        var data  = row.getData();
        var was   = (data._state === 'edited') ? original[data._key] : null;
        var now   = was ? snapshotOf(data) : null;
        var cells = row.getCells();

        for (var i = 0; i < cells.length; i++) {
            var cellEl = cells[i].getElement();
            if (!cellEl) { continue; }

            var f = cells[i].getField();
            var changed = !!(was && f && was.hasOwnProperty(f) && now[f] !== was[f]);

            if (changed) { cellEl.classList.add('cell-edited'); }
            else         { cellEl.classList.remove('cell-edited'); }
        }
    }


    /* ---------- คอลัมน์ ---------- */
    function buildColumns() {

        var cols = [
            G.col(G.control, {
                formatter: 'responsiveCollapse', width: 36, minWidth: 36,
                hozAlign: 'center', resizable: false, headerSort: false, responsive: 0
            }),

            /* ไม่มีคอลัมน์ลำดับที่ เพราะเลขนั้นไม่ใช่ข้อมูลของเมนู
               เป็นแค่ตำแหน่งที่บังเอิญอยู่ในตารางรอบนั้น พอจัดกลุ่มแล้วยิ่งไม่มีความหมาย
               เลขจะกระโดดตามกลุ่มที่กางอยู่ และอ้างอิงกลับไปหาอะไรไม่ได้เลย */

            G.col(G.filterText('ค้นชื่อเมนู'), {
                title: 'เมนู', field: 'title', minWidth: 220, responsive: 0,
                download: true, clipboard: true,
                formatter: function (cell) {
                    var row  = cell.getRow().getData();
                    var icon = row.icon ? row.icon : 'circle';

                    /* เมนูย่อยบอกหัวข้อแม่กำกับไว้ ไม่งั้นชื่ออย่าง "บันทึกรับเข้า"
                       กับ "บันทึกจ่ายออก" อ่านแยกกันไม่ออกว่าอยู่ใต้หัวข้อไหน
                       ชื่อหัวข้อแม่จางกว่าเพื่อให้ชื่อเมนูจริงเด่นออกมา */
                    var name = row.parent
                        ? '<span class="pm-menu-parent">' + escapeHtml(row.parent) + ' › </span>'
                          + '<span class="pm-menu-name">' + escapeHtml(cell.getValue()) + '</span>'
                        : '<span class="pm-menu-name">' + escapeHtml(cell.getValue()) + '</span>';

                    return '<span class="pm-menu">'
                         +     '<span class="pm-menu-ico"><i data-lucide="' + escapeHtml(icon) + '" aria-hidden="true"></i></span>'
                         +     '<span class="pm-menu-text">' + name + '</span>'
                         + '</span>';
                }
            }),

            G.col(G.filterOwn(), {
                title: 'กลุ่มเมนู', field: 'section', minWidth: 140, responsive: 5,
                download: true, clipboard: true,
                formatter: function (cell) {
                    return '<span class="grid-pill">' + escapeHtml(cell.getValue()) + '</span>';
                }
            }),

            /* คอลัมน์นี้ไม่มีให้ปรับ มีไว้บอกว่าสิทธิ์สูงสุดเข้าได้ทุกเมนูเสมอ */
            G.col(G.control, {
                title: 'ผู้ดูแลระบบสูงสุด', field: '_super', width: 140, hozAlign: 'center',
                responsive: 6, headerSort: false,
                formatter: function () {
                    return '<span class="grid-check is-on is-locked" role="img"'
                         + ' title="สิทธิ์สูงสุดเข้าได้ทุกเมนูเสมอ ปรับไม่ได้">' + CHECK_SVG + '</span>';
                }
            })
        ];

        /* คอลัมน์สวิตช์ของแต่ละสิทธิ์ที่ปรับได้ */
        for (var i = 0; i < ROLES.length; i++) {
            cols.push(roleColumn(ROLES[i], i + 1));
        }

        return cols;
    }

    function roleColumn(role, order) {
        var label = (CFG.roleNames && CFG.roleNames[role]) ? CFG.roleNames[role] : role;

        return G.col(
            /* ในตารางแสดงเป็นช่องติ๊กเปล่า ๆ เพราะหัวคอลัมน์บอกอยู่แล้วว่าสิทธิ์อะไร
               แต่ตอนคัดลอกหรือส่งออก Excel ต้องเป็นข้อความที่อ่านรู้เรื่อง */
            G.labelled(function (v) { return (Number(v) === 1) ? 'เข้าได้' : 'ไม่ให้เข้า'; }),
            G.filterStatus(),
            {
                title: label, field: role, width: 140, hozAlign: 'center',
                responsive: order, download: true, clipboard: true, cssClass: 'cell-check',
                formatter: function (cell) {
                    var on = Number(cell.getValue()) === 1;

                    /* คอลัมน์สิทธิ์ของคนที่เปิดหน้าอยู่ ต้องอ่านได้แต่กดไม่ได้
                       แสดงเป็น span ไม่ใช่ปุ่ม เพื่อให้เห็นตั้งแต่แรกว่ากดไม่ได้
                       ถ้าปล่อยให้กดแล้วค่อยไปปฏิเสธตอนบันทึก ผู้ใช้จะเสียเวลาติ๊กทั้งหน้า
                       แล้วเพิ่งมารู้ทีหลังว่าทำไม่ได้ */
                    if (!canEdit(role)) {
                        return '<span class="grid-check is-locked' + (on ? ' is-on' : '') + '" role="img"'
                             + ' title="นี่คือสิทธิ์ของคุณเอง ปรับเองไม่ได้ ต้องให้ผู้ดูแลระบบสูงสุดเป็นคนเปิดให้">'
                             + CHECK_SVG + '</span>';
                    }

                    return '<button type="button" role="checkbox" aria-checked="' + (on ? 'true' : 'false') + '"'
                         + ' class="grid-check' + (on ? ' is-on' : '') + '"'
                         + ' title="' + (on ? 'ติ๊กอยู่ = เข้าได้ กดเพื่อปิดสิทธิ์' : 'ยังไม่ติ๊ก = เข้าไม่ได้ กดเพื่อเปิดสิทธิ์') + '">'
                         + CHECK_SVG + '</button>';
                },
                cellClick: function (e, cell) {
                    if (!canEdit(role)) { return; }
                    var row = cell.getRow();
                    cell.setValue(Number(cell.getValue()) === 1 ? 0 : 1);
                    markChanged(row);
                    refreshPending();
                }
            }
        );
    }


    /* ---------- นับและแสดงจำนวนที่ยังไม่บันทึก ---------- */
    function collect() {
        var changed = [];
        if (!table) { return changed; }

        var rows = table.getData();
        for (var i = 0; i < rows.length; i++) {
            if (rows[i]._state === 'edited') { changed.push(rows[i]); }
        }
        return changed;
    }

    function refreshPending() {
        var changed = collect();
        var total   = changed.length;

        el('btnSave').disabled   = (total === 0);
        el('btnRevert').disabled = (total === 0);

        var badge = el('pendingCount');
        badge.textContent = total;
        setHidden(badge, total === 0);

        setHidden(el('pendingBar'), total === 0);
        if (total > 0) {
            el('pendingText').textContent =
                'ยังไม่ได้บันทึก: แก้สิทธิ์ของ ' + total + ' เมนู — กดปุ่มบันทึกเพื่อยืนยัน';
        }
    }

    function showResult(ok, text) {
        if (typeof window.appSuccess !== 'function') { alert(text); return; }
        if (ok) { window.appSuccess(text); } else { window.appError(text); }
    }


    /* ============================================================
     *  สร้างตาราง
     * ============================================================ */
    /* ---------- หัวข้อของกลุ่ม ----------
     * เมนูย่อยจัดกลุ่มใต้หัวข้อแม่ ส่วนเมนูเดี่ยวใช้ชื่อกลุ่มบนแถบเมนูแทน
     * ทุกแถวจึงมีหัวข้อของตัวเองเสมอ ไม่มีกลุ่มชื่อว่างให้เห็น
     */
    function groupOf(data) {
        /* ต้องเติมคำนำหน้าให้คีย์ ไม่ใช่คืนชื่อที่จะแสดงตรง ๆ
         *
         * ชื่อหัวข้อแม่กับชื่อหมวดซ้ำกันได้ และซ้ำจริงมาแล้ว:
         * หมวด "ตั้งค่าระบบ" มีหัวข้อแม่ชื่อ "ตั้งค่าระบบ" อยู่ข้างใน
         * พอคืนเป็นสตริงเดียวกัน Tabulator ถือว่าเป็นกลุ่มเดียวกัน
         * แล้วยุบเมนูที่อยู่คนละชั้นมารวมกันเป็นกล่องเดียว
         *
         * แยกด้วยคำนำหน้า p:/s: แล้วปัญหานี้เกิดซ้ำไม่ได้อีก
         * ไม่ว่าจะไปตั้งชื่ออะไรใน includes/menu.php
         * ส่วนชื่อที่แสดงดึงจากข้อมูลในกลุ่มแทน (ดู groupLabel)
         */
        return data.parent ? ('p:' + data.parent) : ('s:' + data.section);
    }

    /* ชื่อที่แสดงบนหัวกลุ่ม — อ่านจากแถวแรกของกลุ่ม ไม่ใช่จากคีย์
       เพราะคีย์มีคำนำหน้าติดมาแล้ว */
    function groupLabel(data) {
        if (!data || !data[0]) { return ''; }
        return data[0].parent ? data[0].parent : data[0].section;
    }


    /* ---------- กลุ่มที่ไม่ต้องพับ ----------
     * พับได้เฉพาะกลุ่มที่เป็นหัวข้อแม่จริง ๆ ใน includes/menu.php
     * (ข้อมูลสินค้า / รับสินค้าเข้า / เบิกสินค้า / จ่ายสินค้าออก / รายงาน)
     * ซึ่งบนแถบเมนูซ้ายก็กดกางเก็บได้เหมือนกัน
     *
     * ส่วนเมนูที่ยืนอยู่เดี่ยว ๆ (ภาพรวมระบบ, ผู้ใช้งาน, หน่วยงาน, สิทธิ์การใช้งาน,
     * ตั้งค่าระบบ, เปลี่ยนรหัสผ่าน) ถูกจับกลุ่มด้วยชื่อหมวดเท่านั้น
     * ไม่มีหัวข้อแม่ให้พับ การพับจึงเป็นแค่ขั้นตอนที่เพิ่มเข้ามาเปล่า ๆ
     * กลุ่มพวกนี้ไม่แสดงหัวกลุ่มเลย เหลือเป็นแถวเมนูตรง ๆ
     *
     * ชื่อหมวดไม่ได้หายไปไหน ยังอ่านได้จากคอลัมน์ "กลุ่มเมนู" ของทุกแถว
     */
    function isFlat(data) {
        if (!data || !data[0]) { return true; }
        /* ไม่มีหัวข้อแม่ = จัดกลุ่มด้วยชื่อหมวด ไม่มีอะไรให้พับ
           หรือหัวข้อแม่ที่ตั้ง perm_flat ไว้ ซึ่งเป็นแค่ที่รวมชื่อบนแถบเมนู
           ไม่ใช่หมวดงานจริง (ดู includes/menu.php) */
        return !data[0].parent || Number(data[0].flat) === 1;
    }


    /* Tabulator สร้างแถวหัวกลุ่มให้ทุกกลุ่มเสมอ สั่งไม่ให้สร้างไม่ได้
     * จึงต้องมาซ่อนทีหลัง และต้องทำซ้ำหลังวาดทุกครั้งเพราะตารางวาดแบบ virtual
     *
     * ใช้คลาสที่เติมเองแทนการพึ่ง :has() ใน CSS
     * เพราะการซ่อนหัวกลุ่มเป็นเรื่องของการทำงาน ไม่ใช่การตกแต่ง
     * ถ้าเบราว์เซอร์ไหนไม่รองรับ จะได้ไม่โผล่หัวกลุ่มเปล่า ๆ ขึ้นมา
     */
    function hideFlatGroups() {
        var groups = document.querySelectorAll('#grid .tabulator-row.tabulator-group');

        for (var i = 0; i < groups.length; i++) {
            var flat = groups[i].querySelector('.pg-head.is-flat') !== null;
            if (flat) { groups[i].classList.add('pg-hidden'); }
            else      { groups[i].classList.remove('pg-hidden'); }
        }
    }


    /* ---------- หัวข้อกลุ่ม ----------
     * ใช้ <div> ไม่ใช่ <span> เพราะ Tabulator มีกฎของตัวเองว่า
     *   .tabulator-row.tabulator-group span { color: #d00; margin-left: 10px }
     * ซึ่งกินความจำเพาะสูงกว่าคลาสเดี่ยว ๆ ของเรา ทำให้ตัวหนังสือทุกตัว
     * ในหัวกลุ่มกลายเป็นสีแดงและเยื้องออกไป 10px โดยที่ไม่ได้สั่ง
     * เปลี่ยนมาใช้ div แล้วกฎนั้นไม่ตรงกับอะไรเลย ไม่ต้องไปแข่งความจำเพาะกัน
     */
    function groupSummary(value, count, data) {
        var icon = (data[0] && data[0].group_icon) ? data[0].group_icon : 'folder';
        var flat = isFlat(data);

        return '<div class="pg-head' + (flat ? ' is-flat' : '') + '">' +
                   '<div class="pg-ico"><i data-lucide="' + escapeHtml(icon) + '" aria-hidden="true"></i></div>' +
                   '<div class="pg-title">' +
                       '<div class="pg-name">' + escapeHtml(groupLabel(data)) + '</div>' +
                       '<div class="pg-count"><b>' + count + '</b> เมนู</div>' +
                   '</div>' +
                   (flat ? '' : '<div class="pg-chev">' + CHEV_SVG + '</div>') +
               '</div>';
    }


    /* ---------- กาง/พับทุกกลุ่ม ----------
     *
     * ต้องเรียก getGroups() ใหม่ทุกรอบ ห้ามเก็บผลลัพธ์ไว้แล้ววนใช้
     *
     * เหตุผล: การสั่ง show() หนึ่งกลุ่มทำให้ Tabulator สร้างรายการกลุ่มใหม่ทั้งชุด
     * ตัวอ้างอิงที่ดึงมาก่อนหน้าจึงชี้ไปที่ของเก่าที่ถูกทิ้งแล้ว และคำสั่งที่เหลือ
     * ไม่มีผลอะไรเลย วัดได้จริง: ไล่ show() ทั้ง 7 กลุ่มจากรายการเดียว
     * ได้ isVisible = [true,false,false,false,false,false,false] คือทำงานแค่กลุ่มแรก
     *
     * ทางเลือกอื่นที่ลองแล้วไม่ผ่าน: setGroupStartOpen() ใช้ได้เฉพาะตอนกาง
     * เพราะค่านั้นมีผลกับกลุ่มที่ถูกสร้างใหม่เท่านั้น สั่งพับกลับไม่ได้
     */
    function eachGroup(fn) {
        var n = table.getGroups().length;
        for (var i = 0; i < n; i++) { fn(table.getGroups()[i]); }
    }

    function expandAll() { eachGroup(function (g) { g.show(); }); }

    /* กลุ่มที่ไม่มีหัวกลุ่มต้องไม่ถูกพับด้วย ไม่งั้น "พับทั้งหมด" จะซ่อนแถวพวกนั้นไป
       โดยไม่เหลืออะไรให้กดกลับมาดูเลย */
    function collapseAll() {
        eachGroup(function (g) {
            var rows = g.getRows();
            if (!isFlat(rows.length ? [rows[0].getData()] : null)) { g.hide(); }
        });
    }

    /* ป้ายบนปุ่มต้องบอก "สิ่งที่จะเกิดขึ้นถ้ากด" ไม่ใช่สถานะปัจจุบัน
       และต้องตามให้ทันตอนที่การค้นหาสั่งกาง/พับเอง ไม่ใช่อัปเดตเฉพาะตอนกดปุ่ม */
    function setExpandButton(open) {
        var btn = el('btnExpand');
        if (!btn) { return; }

        btn.setAttribute('data-open', open ? '1' : '0');
        btn.querySelector('.btn-label').textContent = open ? 'พับทั้งหมด' : 'กางทั้งหมด';
    }


    function init() {

        table = new Tabulator('#grid', G.options({
            data: buildRows(),
            columns: buildColumns(),
            placeholder: 'ไม่พบเมนูในระบบ',
            paginationSize: 25,

            /* หน้านี้ไม่ให้เพิ่ม/ลบแถว จึงไม่ต้องรับการวางจาก Excel
               กันเผลอวางแล้วได้แถวขยะที่ไม่ตรงกับเมนูจริง */
            clipboard: 'copy',

            /* ---------- จัดกลุ่มตามเมนูหลัก แล้วพับได้ ----------
             * ระบบมี 19 เมนูใน 8 กลุ่ม การไล่หาทีละแถวในตารางแบนทำให้
             * ต้องอ่านชื่อทุกบรรทัดเพื่อหาว่าเมนูที่ต้องการอยู่ตรงไหน
             *
             * เริ่มด้วยการพับไว้ทั้งหมด เพราะคนเปิดหน้านี้มักมาแก้กลุ่มเดียว
             * ยกเว้นกลุ่มที่ไม่มีหัวข้อแม่ ซึ่งกางค้างไว้ตลอดและไม่แสดงหัวกลุ่ม
             */
            groupBy:            groupOf,
            groupStartOpen:     function (value, count, data) { return isFlat(data); },
            groupToggleElement: 'header',
            groupHeader:        groupSummary,

            rowFormatter: applyRowClass
        }));

        G.guardRanges(table);
        G.enableFilterToggles(table);

        /* ลากจุดมุมขวาล่างเพื่อคัดลอกสิทธิ์ลงเมนูถัดไป
           ใช้บ่อยเวลาตั้งสิทธิ์ให้เมนูทั้งกลุ่มเหมือนกันหมด */
        var fillRules = {};
        for (var i = 0; i < ROLES.length; i++) { fillRules[ROLES[i]] = true; }

        G.enableFillHandle(table, function (rows) {
            for (var j = 0; j < rows.length; j++) { markChanged(rows[j]); }
            refreshPending();
        }, fillRules);

        table.on('tableBuilt', function () { refreshPending(); });

        /* ตารางใช้การวาดแบบ virtual หัวกลุ่มจึงถูกสร้างใหม่ทุกครั้งที่เลื่อน กรอง
           หรือกาง/พับ ต้องสั่งแปลงไอคอนซ้ำหลังวาดเสร็จทุกรอบ */
        table.on('renderComplete', function () {
            renderLucideIcons();
            hideFlatGroups();
        });


        /* ---------- ค้นหาทันทีที่พิมพ์ ---------- */
        var search = el('gridSearch');
        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();

            /* การกรองสร้างกลุ่มขึ้นใหม่ทั้งชุด ต้องรอให้วาดเสร็จก่อนสั่งกาง/พับ
               ไม่งั้นคำสั่งจะไปตกกับกลุ่มชุดเก่าที่กำลังจะถูกทิ้ง */
            var after = function (fn) { window.setTimeout(fn, 0); };

            if (term === '') {
                table.clearFilter();
                /* ล้างคำค้นแล้วพับกลับ ไม่งั้นจะเหลือตารางที่กางหมดทุกกลุ่ม
                   ซึ่งเป็นสภาพที่ผู้ใช้ตั้งใจหนีมาตั้งแต่แรก */
                after(collapseAll);
                setExpandButton(false);
                return;
            }

            table.setFilter(function (data) {
                var hay = String(data.title || '') + ' ' + String(data.parent || '')
                        + ' ' + String(data.section || '') + ' ' + String(data.menu_key || '');
                return hay.toLowerCase().indexOf(term) !== -1;
            });

            /* ค้นหาแล้วต้องกางให้เห็นผลลัพธ์ทันที
               ถ้าปล่อยให้พับอยู่ ผู้ใช้จะเห็นแต่หัวข้อกลุ่มแล้วนึกว่าค้นไม่เจอ */
            after(expandAll);
            setExpandButton(true);
        });


        /* ปุ่มกาง/พับทั้งหมด — ใช้ตอนอยากดูภาพรวมทั้งระบบทีเดียว
           หรือกลับมาสภาพเรียบร้อยหลังกางไปหลายกลุ่ม */
        if (el('btnExpand')) {
            el('btnExpand').addEventListener('click', function () {
                var open = this.getAttribute('data-open') === '1';

                if (open) { collapseAll(); } else { expandAll(); }
                setExpandButton(!open);

                /* กาง/พับแล้ว Tabulator สร้างแถวหัวกลุ่มขึ้นใหม่
                   renderComplete ยิงก่อนที่ DOM ชุดใหม่จะครบทุกกลุ่ม
                   หัวกลุ่มที่ต้องซ่อนบางอันจึงโผล่กลับมา สั่งซ่อนซ้ำหลังจบคิวงาน */
                window.setTimeout(hideFlatGroups, 0);
            });
        }

        /* ---------- ยกเลิกการแก้ไข ---------- */
        el('btnRevert').addEventListener('click', function () {
            window.appConfirmDanger({
                title:       'ยกเลิกการแก้ไข',
                text:        'การแก้ไขสิทธิ์ที่ยังไม่ได้บันทึก ' + collect().length + ' เมนูจะหายไปทั้งหมด',
                confirmText: 'ยกเลิกการแก้ไข',
                cancelText:  'กลับไปแก้ต่อ'
            }).then(function (ok) {
                if (ok) { reloadPage(); }
            });
        });

        /* ---------- คืนค่าตั้งต้น ----------
         * ปุ่มนี้ไม่มีอยู่ในหน้าตอนนี้ (pages/permissions.php ไม่มี #btnReset)
         * แต่โค้ดเดิมผูกมันแบบไม่ตรวจก่อน จึงโยน TypeError ตรงนี้ทุกครั้งที่เปิดหน้า
         * แล้ว init() ตายกลางคัน — บรรทัดที่เหลือใต้ตรงนี้ไม่เคยทำงานเลย
         * ผลคือปุ่ม "บันทึก" ไม่มี event listener กดแล้วเงียบสนิท
         * และคำเตือนก่อนออกจากหน้าก็ไม่ทำงานด้วย
         *
         * ตรวจก่อนผูกเหมือนที่ทำกับ #btnExpand ด้านบน
         * ฝั่งเซิร์ฟเวอร์ยังรองรับ action 'reset' อยู่ ถ้าวันหลังอยากได้ปุ่มนี้คืน
         * เพิ่มปุ่ม id="btnReset" ในหน้า แล้วส่วนนี้จะทำงานทันทีโดยไม่ต้องแก้อะไรอีก
         */
        if (el('btnReset')) {
            el('btnReset').addEventListener('click', function () {
                window.appConfirmDanger({
                    title:       'คืนค่าตั้งต้น',
                    text:        'สิทธิ์ทุกเมนูจะกลับไปใช้ค่าที่ตั้งไว้ในระบบตั้งแต่แรก การตั้งค่าที่เคยบันทึกไว้จะถูกลบทั้งหมด',
                    confirmText: 'คืนค่าตั้งต้น',
                    cancelText:  'ยกเลิก'
                }).then(function (ok) {
                    if (ok) { send({ action: 'reset', csrf_token: CFG.csrfToken }, el('btnReset'), 'permissionsSaved'); }
                });
            });
        }

        /* ---------- บันทึก ---------- */
        el('btnSave').addEventListener('click', save);

        window.addEventListener('beforeunload', function (e) {
            if (skipUnloadWarning) { return; }
            if (collect().length > 0) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    }


    /* ============================================================
     *  ส่งไปบันทึก
     * ============================================================ */
    function save() {
        var changed = collect();
        var rows    = [];

        /* ส่งเฉพาะเมนูที่แก้ ไม่ส่งทั้งตาราง เพราะเมนูที่ยังไม่เคยตั้งค่า
           ควรปล่อยให้ใช้ค่าตั้งต้นในโค้ดต่อไป ไม่ต้องไปตรึงค่าไว้ในฐานข้อมูล */
        for (var i = 0; i < changed.length; i++) {
            for (var r = 0; r < ROLES.length; r++) {
                rows.push({
                    menu_key:   changed[i].menu_key,
                    role:       ROLES[r],
                    can_access: Number(changed[i][ROLES[r]]) === 1 ? 1 : 0
                });
            }
        }

        if (rows.length === 0) {
            showResult(false, 'ยังไม่มีการแก้ไขที่ต้องบันทึก');
            return;
        }

        send({ action: 'save', csrf_token: CFG.csrfToken, rows: rows }, el('btnSave'), 'permissionsSaved');
    }


    /* ยิงคำสั่งไปที่ API แล้วโหลดหน้าใหม่เมื่อสำเร็จ
       ต้องโหลดใหม่เพราะแถบเมนูด้านซ้ายถูกวาดจากฝั่งเซิร์ฟเวอร์
       ถ้าไม่โหลด เมนูที่เพิ่งเปิด/ปิดจะยังไม่เปลี่ยนตาม */
    function send(payload, btn, flashKey) {
        btn.disabled = true;
        setHidden(btn.querySelector('.btn-icon'), true);
        setHidden(btn.querySelector('.btn-spinner'), false);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }

            if (!res)    { finish(btn, false, 'เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่'); return; }
            if (!res.ok) { finish(btn, false, res.message || 'บันทึกไม่สำเร็จ'); return; }

            try { sessionStorage.setItem(flashKey, res.message || 'บันทึกเรียบร้อย'); } catch (err) {}
            reloadPage();
        };

        xhr.onerror = function () {
            finish(btn, false, 'ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่');
        };

        xhr.send(JSON.stringify(payload));
    }

    function finish(btn, ok, message) {
        setHidden(btn.querySelector('.btn-icon'), false);
        setHidden(btn.querySelector('.btn-spinner'), true);
        btn.disabled = false;
        showResult(ok, message);
        refreshPending();
    }

    function showSavedMessage() {
        var msg = null;
        try { msg = sessionStorage.getItem('permissionsSaved'); } catch (err) {}
        if (msg) {
            try { sessionStorage.removeItem('permissionsSaved'); } catch (err) {}
            showResult(true, msg);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); showSavedMessage(); });
    } else {
        init();
        showSavedMessage();
    }
})();
