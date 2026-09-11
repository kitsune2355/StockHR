/* ============================================================
 *  adjust-new.js — ฟอร์มยื่นใบปรับยอดสินค้า (ใบตรวจนับ)
 *
 *  ตารางเป็น Tabulator แบบเดียวกับหน้าบันทึกรับเข้า (ดู buildTable ใน doc-form.js)
 *  ทำงานแบบ Excel: คลิกช่องเพื่อแก้ ลากคลุมเลือกหลายช่อง Ctrl+C คัดลอกได้
 *  (ค่าตั้งต้นร่วมมาจาก grid-common.js) — ไม่ใช้ตาราง HTML ธรรมดาเหมือนเดิมอีกแล้ว
 *
 *  1) กดปุ่ม "เพิ่มรายการ" -> เพิ่มบรรทัดว่างตามจำนวนที่กรอกไว้ข้าง ๆ ปุ่ม
 *     กรอก 1 = เปิดช่องเลือกสินค้าให้เลย (เหมือนหน้าบันทึกรับเข้า)
 *  2) เลือกสินค้าแล้ว ช่อง "นับได้จริง" ตั้งค่าเริ่มเท่ายอดในระบบ
 *     ผู้ใช้แก้เฉพาะตัวที่ไม่ตรง ระบบคิดส่วนต่างให้สด ๆ
 *     เลือกสินค้าที่มีอยู่ในใบแล้ว — ย้อนค่ากลับ แล้วกะพริบชี้ไปที่บรรทัดเดิม
 *  3) กดยื่น -> ยืนยัน -> ยิงไป api/adjust.php (action=save)
 *     สร้างเอกสาร doc_type='adjust' สถานะ waiting ยอดยังไม่เปลี่ยนจนกว่าจะอนุมัติ
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.ADJUST;
    if (!CFG) { return; }

    function el(id) { return document.getElementById(id); }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function num(v) {
        var n = Number(v);
        return isNaN(n) ? 0 : n;
    }

    /* จำนวนแบบไม่มี .00 ห้อยท้ายเวลาที่เป็นจำนวนเต็ม + คั่นหลักพัน (ให้ตรงกับ stock_format() ฝั่ง PHP) */
    function fmtQty(n) {
        n = Number(n);
        if (!isFinite(n)) { n = 0; }
        var neg = n < 0;
        n = Math.abs(n);
        var s = (Math.abs(n - Math.round(n)) < 1e-9)
                ? String(Math.round(n))
                : String(parseFloat(n.toFixed(2)));
        var p = s.split('.');
        p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (neg ? '-' : '') + p.join('.');
    }

    var LIST     = [];       // สินค้าทั้งหมด เรียงตามที่ API ส่งมา (ใช้สร้างช่องเลือก)
    var PRODUCTS = {};       // product_id -> ข้อมูลสินค้า
    var table    = null;
    var seq      = 0;
    var saved    = false;

    /* Tabulator 6 สั่ง addRow/setData ก่อน tableBuilt ไม่ได้ พักคำสั่งไว้ก่อนแล้วยิงตอนพร้อม
       (ตารางสร้างเสร็จเร็วมาก แต่กันไว้เผื่อผู้ใช้กด "เพิ่มรายการ" ทันทีที่หน้าโผล่) */
    var ready   = false;
    var pending = [];
    function whenReady(fn) {
        if (ready) { fn(); } else { pending.push(fn); }
    }


    /* ============================================================
     *  โหลดข้อมูลตั้งต้น
     * ============================================================ */
    function init() {
        el('docDate').value = CFG.today;

        post({ action: 'products' }, function (res) {
            if (!res || !res.ok) {
                el('formLoading').textContent = (res && res.message)
                    ? res.message : 'โหลดข้อมูลสินค้าไม่สำเร็จ';
                return;
            }

            LIST = res.rows || [];
            for (var i = 0; i < LIST.length; i++) { PRODUCTS[LIST[i].product_id] = LIST[i]; }

            setHidden(el('formLoading'), true);
            setHidden(el('formBody'), false);

            buildTable();
            bind();

            if (window.lucide) { window.lucide.createIcons(); }
        });
    }


    function bind() {
        /* เพิ่มบรรทัดว่างทีละหลายแถวตามจำนวนที่กรอก (แบบเดียวกับหน้าผู้ใช้งาน)
           กรอก 1 = เพิ่มบรรทัดเดียวแล้วกางช่องเลือกสินค้าให้เลย */
        el('btnAddRow').addEventListener('click', function () {
            var n = parseInt(el('addCount').value, 10);
            if (isNaN(n) || n < 1) { n = 1; }
            if (n > 50) { n = 50; }
            addBlankRows(n);
        });
        el('btnClear').addEventListener('click', clearAll);
        el('btnSave').addEventListener('click', save);
        el('docNote').addEventListener('input', clearError);

        window.addEventListener('beforeunload', function (e) {
            if (!saved && chosenCount() > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }


    /* ============================================================
     *  ตาราง (Tabulator แบบเดียวกับหน้าบันทึกรับเข้า)
     * ============================================================ */
    function productValues() {
        var out = [];
        for (var i = 0; i < LIST.length; i++) {
            var p = LIST[i];
            out.push({
                label: p.product_code + ' — ' + p.product_name +
                       '  (คงเหลือ ' + fmtQty(p.qty) + ' ' + p.unit_name + ')',
                value: p.product_id
            });
        }
        return out;
    }

    function buildTable() {
        var G = window.GridCommon;

        table = new Tabulator('#items', G.options({
            data: [],
            layout: 'fitColumns',
            height: '100%',
            placeholder: 'ยังไม่มีรายการ — กรอกจำนวนแล้วกด "เพิ่มรายการ" (เพิ่มพร้อมกันได้หลายบรรทัด)',
            index: '_id',

            /* ตารางนี้เป็นฟอร์ม ไม่ใช่ตารางข้อมูล จึงไม่ต้องแบ่งหน้าและไม่ต้องเรียง
               ลำดับบรรทัดคือลำดับที่ผู้ใช้กรอก ซึ่งจะกลายเป็นลำดับในเอกสาร */
            pagination: false,
            movableRows: true,

            /* ห้ามยุบคอลัมน์บนจอแคบ (ค่าปริยายของทั้งระบบคือ 'collapse')
               ตารางนี้เป็นฟอร์มที่ต้องกรอกทุกช่อง ช่องที่ถูกยุบแก้ไม่ได้
               ปิดการยุบแล้วให้เลื่อนแนวนอนในกรอบตารางแทน */
            responsiveLayout: false,

            /* ฟอร์มเปล่าที่ต้องกรอกเกือบทุกช่องอยู่แล้ว คลิกเดียวแก้ได้เลย
               เหมือนหน้าบันทึกรับเข้า (ค่าปริยายของตารางอื่นในระบบคือดับเบิลคลิก) */
            editTriggerEvent: 'click',

            columns: buildColumns()
        }));

        table.on('tableBuilt', function () {
            ready = true;
            while (pending.length > 0) { pending.shift()(); }
        });

        table.on('cellEdited', onCellEdited);
        table.on('rowMoved', refreshSummary);
        table.on('dataChanged', refreshSummary);

        G.guardRanges(table);
    }

    function buildColumns() {
        var G = window.GridCommon;

        return [
            G.col(G.control, {
                title: '#',
                field: '_seq',
                width: 46,
                hozAlign: 'right',
                headerSort: false,
                resizable: false,
                formatter: function (cell) {
                    return cell.getRow().getPosition(true);
                }
            }),

            {
                title: 'สินค้า',
                field: 'product_id',
                minWidth: 240,
                editor: 'list',
                editorParams: G.pickerParams(productValues()),
                formatter: function (cell) {
                    var p = PRODUCTS[cell.getValue()];
                    if (!p) {
                        return '<span class="grid-empty">— เลือกสินค้า —</span>';
                    }
                    return '<span class="grid-user">' + esc(p.product_code) + '</span> ' + esc(p.product_name);
                }
            },

            G.col(G.control, {
                title: 'หน่วย',
                field: '_unit',
                width: 92,
                headerSort: false,
                formatter: function (cell) {
                    var p = PRODUCTS[cell.getRow().getData().product_id];
                    return p ? esc(p.unit_name) : '';
                }
            }),

            /* ยอดในระบบ ณ ตอนเลือกสินค้าเข้ามา (แช่แข็งไว้ที่ sysQty ของแถว
               ไม่อ่านยอดสดจาก PRODUCTS อีกที เผื่อมีคนอื่นบันทึกรับเข้า/จ่ายออกคาบเกี่ยว) */
            G.col(G.control, {
                title: 'ยอดในระบบ',
                field: 'sysQty',
                width: 112,
                hozAlign: 'right',
                headerSort: false,
                formatter: function (cell) {
                    var d = cell.getRow().getData();
                    if (num(d.product_id) <= 0) {
                        return '<span class="grid-empty">—</span>';
                    }
                    return '<span class="doc-stock">' + fmtQty(cell.getValue()) + '</span>';
                }
            }),

            {
                title: 'นับได้จริง',
                field: 'qty',
                width: 120,
                hozAlign: 'right',
                editor: 'number',
                editorParams: { min: 0, step: 1, selectContents: true },
                /* กรอกได้ต่อเมื่อเลือกสินค้าแล้ว — ไม่งั้นไม่รู้จะเทียบยอดกับอะไร */
                editable: function (cell) {
                    return num(cell.getRow().getData().product_id) > 0;
                },
                formatter: function (cell) {
                    var d = cell.getRow().getData();
                    if (num(d.product_id) <= 0) {
                        return '<span class="grid-empty">—</span>';
                    }
                    var v = cell.getValue();
                    if (v === '' || v === null || v === undefined) {
                        return '<span class="grid-empty">— ยังไม่นับ —</span>';
                    }
                    return '<span class="doc-qty">' + fmtQty(v) + '</span>';
                }
            },

            G.col(G.control, {
                title: 'ส่วนต่าง',
                field: '_diff',
                width: 112,
                hozAlign: 'right',
                headerSort: false,
                formatter: function (cell) {
                    var d = cell.getRow().getData();
                    if (num(d.product_id) <= 0 || d.qty === '' || d.qty === null || d.qty === undefined) {
                        return '<span class="grid-empty">—</span>';
                    }
                    var diff = num(d.qty) - num(d.sysQty);
                    if (Math.abs(diff) < 0.005) {
                        return '<span class="grid-empty">0</span>';
                    }
                    return '<b class="' + (diff > 0 ? 'doc-in' : 'doc-out') + '">' +
                           (diff > 0 ? '+' : '') + fmtQty(diff) + '</b>';
                }
            }),

            {
                title: 'หมายเหตุ',
                field: 'note',
                minWidth: 150,
                editor: 'input',
                formatter: function (cell) {
                    var v = cell.getValue();
                    return v && String(v).trim() !== '' ? esc(v) : '<span class="grid-empty">—</span>';
                }
            },

            G.col(G.control, {
                title: '',
                field: '_remove',
                width: 46,
                hozAlign: 'center',
                headerSort: false,
                resizable: false,
                formatter: function () {
                    return '<button type="button" class="grid-remove" title="เอาบรรทัดนี้ออก">&#10005;</button>';
                },
                cellClick: function (e, cell) {
                    cell.getRow().delete();
                    refreshSummary();
                }
            })
        ];
    }

    /* ---------- แก้ไขในตาราง ---------- */
    function onCellEdited(cell) {
        var row = cell.getRow();

        if (cell.getField() !== 'product_id') {
            row.reformat();
            refreshSummary();
            clearError();
            return;
        }

        var pid = num(cell.getValue());

        if (pid <= 0) {
            row.update({ sysQty: 0, qty: '' });
            row.reformat();
            refreshSummary();
            clearError();
            return;
        }

        /* สินค้าซ้ำกับบรรทัดอื่น — ย้อนค่ากลับ แล้วกะพริบชี้ไปที่บรรทัดเดิม */
        var dupRow = findRowByProduct(pid, row);
        if (dupRow) {
            row.update({ product_id: cell.getOldValue() || 0 });
            row.reformat();

            var dupNo = dupRow.getPosition(true);
            if (typeof window.appWarning === 'function') {
                /* กะพริบหลังผู้ใช้ปิดกล่องข้อความ ไม่งั้นเอฟเฟกต์อยู่หลังกล่องที่บังจอ */
                window.appWarning('สินค้านี้อยู่ในใบแล้ว (บรรทัดที่ ' + dupNo + ')')
                    .then(function () { flashRow(dupRow); });
            } else {
                flashRow(dupRow);
            }
            return;
        }

        var p  = PRODUCTS[pid];
        var sq = p ? num(p.qty) : 0;
        row.update({ sysQty: sq, qty: sq });
        row.reformat();
        refreshSummary();
        clearError();

        /* เปิดช่อง "นับได้จริง" ให้แก้ต่อทันที ส่วนใหญ่นับได้ไม่ตรงกับระบบ */
        setTimeout(function () {
            try { row.getCell('qty').edit(); } catch (e) { /* แก้ไม่ได้ก็ไม่เป็นไร */ }
        }, 0);
    }

    function findRowByProduct(pid, exceptRow) {
        var rows = table.getRows();
        for (var i = 0; i < rows.length; i++) {
            if (rows[i] === exceptRow) { continue; }
            if (num(rows[i].getData().product_id) === pid) { return rows[i]; }
        }
        return null;
    }

    /* กะพริบแถวครั้งเดียวแล้วจาง + เลื่อนให้เห็น (คลาสเดียวกับ .row-scanned ของหน้าบันทึกรับเข้า) */
    function flashRow(row) {
        var rowEl = row.getElement();
        if (!rowEl) { return; }

        rowEl.classList.remove('row-scanned');
        void rowEl.offsetWidth; /* บังคับให้ animation เริ่มใหม่ */
        rowEl.classList.add('row-scanned');

        try {
            row.scrollTo().then(function () {}, function () {});
        } catch (e) { /* ไม่สำคัญ */ }
    }

    /* ---------- เพิ่ม/ลบ/ล้างบรรทัด ---------- */
    function addBlankRows(n) {
        whenReady(function () {
            var last = null;
            for (var i = 0; i < n; i++) {
                last = table.addRow({ _id: ++seq, product_id: 0, sysQty: 0, qty: '', note: '' });
            }
            if (!last) { return; }
            last.then(function (row) {
                refreshSummary();
                clearError();
                if (n === 1) {
                    row.getCell('product_id').edit(); // เปิดช่องเลือกสินค้าให้เลย
                }
                try { row.getElement().scrollIntoView({ block: 'nearest' }); } catch (e) { /* ไม่สำคัญ */ }
            });
        });
    }

    function clearAll() {
        if (!table || table.getDataCount() === 0) { return; }
        var n = table.getDataCount();

        var go = function () {
            table.clearData().then(refreshSummary);
        };
        if (typeof window.appConfirm === 'function') {
            window.appConfirm({
                title: 'ล้างรายการทั้งหมด',
                text: 'จะเอาทุกบรรทัดออกจากใบตรวจนับ (' + n + ' บรรทัด)',
                confirmText: 'ล้างรายการ'
            }).then(function (ok) { if (ok) { go(); } });
        } else { go(); }
    }

    /* ---------- สรุปท้ายตาราง ---------- */
    function chosenCount() {
        var rows = table ? table.getData() : [];
        var n = 0;
        for (var i = 0; i < rows.length; i++) {
            if (num(rows[i].product_id) > 0) { n++; }
        }
        return n;
    }

    function changedCount() {
        var rows = table ? table.getData() : [];
        var n = 0;
        for (var i = 0; i < rows.length; i++) {
            if (num(rows[i].product_id) <= 0) { continue; }
            var qv = rows[i].qty;
            if (qv === '' || qv === null || qv === undefined) { continue; }
            if (Math.abs(num(qv) - num(rows[i].sysQty)) >= 0.005) { n++; }
        }
        return n;
    }

    function refreshSummary() {
        var chosen = chosenCount();
        el('sumLines').textContent = fmtQty(chosen);
        el('sumChanged').textContent = fmtQty(changedCount());
        if (el('btnSave')) { el('btnSave').disabled = chosen === 0; }
    }


    /* ============================================================
     *  ยื่นขออนุมัติ
     * ============================================================ */
    function showError(msg) {
        el('formErrorText').textContent = msg;
        setHidden(el('formError'), false);
    }
    function clearError() { setHidden(el('formError'), true); }

    function busy(on) {
        var b = el('btnSave');
        b.disabled = on || chosenCount() === 0;
        setHidden(b.querySelector('.btn-icon'), on);
        setHidden(b.querySelector('.btn-spinner'), !on);
    }

    function collect() {
        var rows   = table.getData();
        var out    = [];
        var seen   = {};
        var lineNo = 0;

        for (var i = 0; i < rows.length; i++) {
            var r   = rows[i];
            var pid = num(r.product_id);
            if (pid <= 0) { continue; } // บรรทัดว่างที่ยังไม่ได้เลือกสินค้า — ข้าม
            lineNo++;

            var p    = PRODUCTS[pid];
            var who  = p ? ' (' + p.product_code + ')' : '';
            var qv   = r.qty;

            if (qv === '' || qv === null || qv === undefined || isNaN(Number(qv))) {
                return { error: 'บรรทัดที่ ' + lineNo + who + ': ยังไม่ได้กรอกจำนวนที่นับได้' };
            }
            if (Number(qv) < 0) {
                return { error: 'บรรทัดที่ ' + lineNo + who + ': จำนวนติดลบไม่ได้' };
            }
            if (seen[pid]) {
                return { error: 'บรรทัดที่ ' + lineNo + ': สินค้าซ้ำกับบรรทัดที่ ' + seen[pid] };
            }
            seen[pid] = lineNo;

            out.push({ product_id: pid, counted: Number(qv), note: r.note || '' });
        }

        if (out.length === 0) {
            return { error: 'กรุณาเลือกสินค้าในบรรทัดอย่างน้อย 1 รายการ' };
        }
        return { items: out };
    }

    function save() {
        clearError();

        var got = collect();
        if (got.error) {
            showError(got.error);
            return;
        }

        var date = el('docDate').value;
        if (!date) { showError('กรุณาเลือกวันที่ตรวจนับ'); el('docDate').focus(); return; }

        var changed = changedCount();
        if (changed === 0) {
            if (typeof window.appWarning === 'function') {
                window.appWarning('ทุกรายการมียอดตรงกับระบบอยู่แล้ว ไม่มีอะไรต้องปรับ');
            } else {
                showError('ทุกรายการมียอดตรงกับระบบอยู่แล้ว');
            }
            return;
        }

        var ask = (typeof window.appConfirm === 'function')
            ? window.appConfirm({
                title: 'ยืนยันยื่นขออนุมัติ',
                text: 'จะยื่นใบปรับยอด ' + changed + ' รายการเข้าขออนุมัติ '
                    + 'ยอดคงเหลือจะยังไม่เปลี่ยนจนกว่าจะมีผู้อนุมัติ',
                confirmText: 'ยืนยันยื่นขออนุมัติ'
            })
            : Promise.resolve(window.confirm('ยื่นใบปรับยอด ' + changed + ' รายการ?'));

        ask.then(function (ok) {
            if (!ok) { return; }
            send({
                action: 'save',
                csrf_token: CFG.csrfToken,
                doc_date: date,
                note: el('docNote').value,
                items: got.items
            });
        });
    }

    function send(payload) {
        busy(true);
        post(payload, function (res) {
            busy(false);

            if (!res) {
                showError('เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่');
                if (window.appError) { window.appError('เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง', 'ยื่นใบปรับยอดไม่สำเร็จ'); }
                return;
            }
            if (!res.ok) {
                showError(res.message || 'ยื่นใบปรับยอดไม่สำเร็จ');
                if (window.appError) { window.appError(res.message || 'ยื่นใบปรับยอดไม่สำเร็จ', 'ยื่นใบปรับยอดไม่สำเร็จ'); }
                return;
            }

            saved = true;
            table.clearData().then(function () {
                refreshSummary();
                saved = false;
            });
            el('docNote').value = '';

            var msg = res.message || 'ยื่นใบปรับยอดเรียบร้อย';
            if (typeof window.appSuccess === 'function') {
                window.appSuccess(msg);
            } else {
                alert(msg);
            }
            if (res.doc_id && typeof window.appConfirm === 'function') {
                window.appConfirm({
                    title: 'ยื่นใบ ' + (res.doc_no || '') + ' แล้ว',
                    text: 'ต้องการเปิดหน้าพิมพ์ใบปรับยอดนี้เลยไหม (เปิดหน้าประวัติเพื่อดูสถานะได้ทีหลัง)',
                    confirmText: 'เปิดหน้าพิมพ์',
                    cancelText: 'ไว้ทีหลัง'
                }).then(function (go) {
                    if (go) { window.open('print_doc.php?doc_id=' + res.doc_id, '_blank'); }
                });
            }
        });
    }


    /* ============================================================
     *  ยิง POST JSON
     * ============================================================ */
    function post(payload, done) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
            done(res);
        };
        xhr.onerror = function () {
            done({ ok: false, message: 'ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่' });
        };
        xhr.send(JSON.stringify(payload));
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
