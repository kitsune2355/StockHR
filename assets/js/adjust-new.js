/* ============================================================
 *  adjust-new.js — ฟอร์มยื่นใบปรับยอดสินค้า (ใบตรวจนับ)
 *
 *  1) กดปุ่ม "เพิ่มรายการ" -> เพิ่มบรรทัดว่าง แล้วเลือกสินค้าในช่องแรกของบรรทัด
 *     (พิมพ์ค้นได้) แบบเดียวกับหน้าบันทึกรับเข้า
 *  2) เลือกสินค้าแล้ว ช่อง "นับได้จริง" ตั้งค่าเริ่มเท่ายอดในระบบ
 *     ผู้ใช้แก้เฉพาะตัวที่ไม่ตรง ระบบคิดส่วนต่างให้สด ๆ
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

    function trimNum(n) {
        n = Number(n) || 0;
        return (Math.abs(n - Math.round(n)) < 1e-9)
               ? String(Math.round(n))
               : String(parseFloat(n.toFixed(2)));
    }

    var PRODUCTS = {};       // product_id -> ข้อมูลสินค้า
    var OPT_HTML = '';       // <option> ของสินค้าทุกตัว สร้างครั้งเดียว ใช้ซ้ำทุกบรรทัด
    var ROWS = [];           // { pid, tr, sel, unitCell, sysCell, countInput, noteInput, diffCell, sysQty, code, name }
    var saved = false;

    var CELL_SELECT = 'input-control h-9 w-full min-w-[13rem] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';
    var CELL_NUM   = 'num h-9 w-28 rounded-lg border border-slate-200 bg-white px-2 text-right text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 disabled:bg-slate-50 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:disabled:bg-slate-900';
    var CELL_TEXT  = 'h-9 w-full min-w-[10rem] rounded-lg border border-slate-200 bg-white px-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100';


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

            var parts = [];
            for (var i = 0; i < res.rows.length; i++) {
                var r = res.rows[i];
                PRODUCTS[r.product_id] = r;
                parts.push(
                    '<option value="' + r.product_id + '" data-sub="' +
                    esc('คงเหลือ ' + fmtQty(r.qty) + (r.unit_name ? ' ' + r.unit_name : '')) + '">' +
                    esc(r.product_code + ' — ' + r.product_name) + '</option>'
                );
            }
            OPT_HTML = parts.join('');

            setHidden(el('formLoading'), true);
            setHidden(el('formBody'), false);
            if (window.renderIcons) { window.renderIcons(el('formBody')); }
            bind();
        });
    }


    function bind() {
        /* เพิ่มบรรทัดว่างทีละหลายแถวตามจำนวนที่กรอก (แบบเดียวกับหน้าผู้ใช้งาน)
           กรอก 1 = เพิ่มบรรทัดเดียวแล้วกางช่องเลือกสินค้าให้เลย */
        el('btnAddRow').addEventListener('click', function () {
            var n = parseInt(el('addCount').value, 10);
            if (isNaN(n) || n < 1) { n = 1; }
            if (n > 50) { n = 50; }
            for (var i = 0; i < n; i++) { addBlankRow(n === 1); }
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
     *  บรรทัดในใบ
     * ============================================================ */
    function addBlankRow(openPicker) {
        var tr = document.createElement('tr');
        tr.className = 'align-middle';

        /* สินค้า — ช่องเลือก (พิมพ์ค้นได้) */
        var tdProd = document.createElement('td');
        tdProd.className = 'px-4 py-2';
        var sel = document.createElement('select');
        sel.className = CELL_SELECT;
        sel.setAttribute('data-search', '');
        sel.setAttribute('data-search-placeholder', 'พิมพ์รหัสหรือชื่อสินค้า');
        sel.innerHTML = '<option value="">— เลือกสินค้า —</option>' + OPT_HTML;
        tdProd.appendChild(sel);

        /* หน่วย */
        var tdUnit = document.createElement('td');
        tdUnit.className = 'px-3 py-2 text-slate-400 dark:text-slate-500';
        tdUnit.textContent = '—';

        /* ยอดในระบบ */
        var tdSys = document.createElement('td');
        tdSys.className = 'num px-3 py-2 text-right text-slate-400 dark:text-slate-500';
        tdSys.textContent = '—';

        /* นับได้จริง */
        var tdCount = document.createElement('td');
        tdCount.className = 'px-3 py-2 text-right';
        var input = document.createElement('input');
        input.type = 'number';
        input.step = 'any';
        input.min = '0';
        input.disabled = true;
        input.placeholder = '—';
        input.className = CELL_NUM;
        tdCount.appendChild(input);

        /* ส่วนต่าง */
        var tdDiff = document.createElement('td');
        tdDiff.className = 'num px-3 py-2 text-right font-medium text-slate-300 dark:text-slate-600';
        tdDiff.textContent = '—';

        /* หมายเหตุบรรทัด */
        var tdNote = document.createElement('td');
        tdNote.className = 'px-3 py-2';
        var note = document.createElement('input');
        note.type = 'text';
        note.maxLength = 300;
        note.placeholder = 'ไม่บังคับ';
        note.className = CELL_TEXT;
        tdNote.appendChild(note);

        /* ปุ่มลบ */
        var tdDel = document.createElement('td');
        tdDel.className = 'px-3 py-2 text-right';
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10';
        del.setAttribute('aria-label', 'ลบรายการนี้');
        del.innerHTML = '<i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>';
        tdDel.appendChild(del);

        tr.appendChild(tdProd);
        tr.appendChild(tdUnit);
        tr.appendChild(tdSys);
        tr.appendChild(tdCount);
        tr.appendChild(tdDiff);
        tr.appendChild(tdNote);
        tr.appendChild(tdDel);

        var row = {
            pid: 0, code: '', name: '', sysQty: 0,
            tr: tr, sel: sel, unitCell: tdUnit, sysCell: tdSys,
            countInput: input, noteInput: note, diffCell: tdDiff
        };
        ROWS.push(row);
        el('rows').appendChild(tr);

        if (window.SelectSearch) { window.SelectSearch.enhance(sel); }
        sel.addEventListener('change', function () { onPick(row); });
        input.addEventListener('input', function () { paintDiff(row); refreshSummary(); clearError(); });
        del.addEventListener('click', function () { removeRow(row); });

        if (window.renderIcons) { window.renderIcons(tr); }
        refreshSummary();
        clearError();

        tr.scrollIntoView({ block: 'nearest' });
        if (openPicker && sel.__ss) {
            sel.__ss.btn.click();          // กางรายการให้เลือกทันที
        }
    }

    function onPick(row) {
        var pid = parseInt(row.sel.value, 10);

        if (!pid) {
            resetRow(row);
            return;
        }

        /* สินค้าซ้ำกับบรรทัดอื่น — ย้อนค่ากลับ แล้วชี้ไปที่บรรทัดเดิม */
        for (var i = 0; i < ROWS.length; i++) {
            if (ROWS[i] !== row && ROWS[i].pid === pid) {
                var dupTr = ROWS[i].tr;
                var dupNo = i + 1;

                row.sel.value = row.pid ? String(row.pid) : '';
                if (window.SelectSearch) { window.SelectSearch.refresh(row.sel); }

                if (typeof window.appWarning === 'function') {
                    /* กะพริบหลังผู้ใช้ปิดกล่องข้อความ ไม่งั้นเอฟเฟกต์อยู่หลังกล่องที่บังจอ */
                    window.appWarning('สินค้านี้อยู่ในใบแล้ว (บรรทัดที่ ' + dupNo + ')')
                        .then(function () { flash(dupTr); });
                } else {
                    flash(dupTr);
                }
                return;
            }
        }

        var p = PRODUCTS[pid];
        if (!p) { resetRow(row); return; }

        row.pid = pid;
        row.code = p.product_code;
        row.name = p.product_name;
        row.sysQty = Number(p.qty) || 0;

        row.unitCell.className = 'px-3 py-2 text-slate-500 dark:text-slate-400';
        row.unitCell.textContent = p.unit_name || '-';
        row.sysCell.className = 'num px-3 py-2 text-right text-slate-600 dark:text-slate-300';
        row.sysCell.textContent = fmtQty(p.qty);

        row.countInput.disabled = false;
        row.countInput.placeholder = '';
        row.countInput.value = trimNum(p.qty);

        paintDiff(row);
        refreshSummary();
        clearError();

        row.countInput.focus();
        row.countInput.select();
    }

    function resetRow(row) {
        row.pid = 0; row.code = ''; row.name = ''; row.sysQty = 0;
        row.unitCell.className = 'px-3 py-2 text-slate-400 dark:text-slate-500';
        row.unitCell.textContent = '—';
        row.sysCell.className = 'num px-3 py-2 text-right text-slate-400 dark:text-slate-500';
        row.sysCell.textContent = '—';
        row.countInput.value = '';
        row.countInput.disabled = true;
        row.countInput.placeholder = '—';
        paintDiff(row);
        refreshSummary();
    }

    function removeRow(row) {
        var i = ROWS.indexOf(row);
        if (i === -1) { return; }
        ROWS.splice(i, 1);
        if (row.tr.parentNode) { row.tr.parentNode.removeChild(row.tr); }
        refreshSummary();
    }

    function clearAll() {
        if (ROWS.length === 0) { return; }
        var go = function () {
            for (var i = ROWS.length - 1; i >= 0; i--) { removeRow(ROWS[i]); }
        };
        if (typeof window.appConfirm === 'function') {
            window.appConfirm({
                title: 'ล้างรายการทั้งหมด',
                text: 'จะเอาทุกบรรทัดออกจากใบตรวจนับ (' + ROWS.length + ' บรรทัด)',
                confirmText: 'ล้างรายการ'
            }).then(function (ok) { if (ok) { go(); } });
        } else { go(); }
    }


    /* ---------- ส่วนต่างของบรรทัด ---------- */
    function rowDiff(row) {
        if (row.pid <= 0) { return null; }
        var v = row.countInput.value;
        if (v === '' || isNaN(Number(v))) { return null; }
        return Number(v) - row.sysQty;
    }

    function paintDiff(row) {
        var d = rowDiff(row);
        var c = row.diffCell;
        c.className = 'num px-3 py-2 text-right font-medium';

        if (d === null) {
            c.textContent = '—';
            c.className += ' text-slate-300 dark:text-slate-600';
            row.tr.classList.remove('bg-amber-50/60', 'dark:bg-amber-500/5');
            return;
        }
        if (Math.abs(d) < 0.005) {
            c.textContent = '0';
            c.className += ' text-slate-400 dark:text-slate-500';
            row.tr.classList.remove('bg-amber-50/60', 'dark:bg-amber-500/5');
            return;
        }

        c.textContent = (d > 0 ? '+' : '') + fmtQty(d);
        c.className += (d > 0)
            ? ' text-emerald-600 dark:text-emerald-400'
            : ' text-red-600 dark:text-red-400';
        row.tr.classList.add('bg-amber-50/60', 'dark:bg-amber-500/5');
    }

    function chosenCount() {
        var n = 0;
        for (var i = 0; i < ROWS.length; i++) { if (ROWS[i].pid > 0) { n++; } }
        return n;
    }

    function changedCount() {
        var n = 0;
        for (var i = 0; i < ROWS.length; i++) {
            var d = rowDiff(ROWS[i]);
            if (d !== null && Math.abs(d) >= 0.005) { n++; }
        }
        return n;
    }

    function refreshSummary() {
        el('sumLines').textContent = fmtQty(chosenCount());
        el('sumChanged').textContent = fmtQty(changedCount());
        setHidden(el('emptyRows'), ROWS.length > 0);
        el('btnSave').disabled = (chosenCount() === 0);
    }

    /* กะพริบแถวครั้งเดียวแล้วจาง + เลื่อนให้เห็น (แบบเดียวกับ flashRow ในหน้าบันทึกรับเข้า)
       ใช้ตอนผู้ใช้เลือกสินค้าที่มีอยู่ในใบแล้ว จะได้รู้ทันทีว่าไปดูบรรทัดไหน */
    function flash(tr) {
        if (!tr) { return; }
        tr.classList.remove('is-flash');
        void tr.offsetWidth;                 // บังคับให้ animation เริ่มใหม่ทุกครั้งที่เรียกซ้ำ
        tr.classList.add('is-flash');
        setTimeout(function () { tr.classList.remove('is-flash'); }, 1300);
        try {
            tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) {
            tr.scrollIntoView(false);
        }
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

    function save() {
        clearError();

        if (chosenCount() === 0) {
            showError('ยังไม่ได้เลือกสินค้าในบรรทัดใดเลย');
            return;
        }

        var date = el('docDate').value;
        if (!date) { showError('กรุณาเลือกวันที่ตรวจนับ'); el('docDate').focus(); return; }

        var items = [];
        var lineNo = 0;
        for (var i = 0; i < ROWS.length; i++) {
            var row = ROWS[i];
            if (row.pid <= 0) { continue; }          // บรรทัดว่างที่ยังไม่ได้เลือกสินค้า — ข้าม
            lineNo++;

            var v = row.countInput.value;
            if (v === '' || isNaN(Number(v))) {
                showError('บรรทัดที่ ' + lineNo + ' (' + row.code + '): ยังไม่ได้กรอกจำนวนที่นับได้');
                row.countInput.focus();
                return;
            }
            if (Number(v) < 0) {
                showError('บรรทัดที่ ' + lineNo + ' (' + row.code + '): จำนวนติดลบไม่ได้');
                row.countInput.focus();
                return;
            }
            items.push({
                product_id: row.pid,
                counted: Number(v),
                note: row.noteInput.value,
                product_code: row.code
            });
        }

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
                items: items
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
            for (var i = ROWS.length - 1; i >= 0; i--) { removeRow(ROWS[i]); }
            el('docNote').value = '';
            saved = false;

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
