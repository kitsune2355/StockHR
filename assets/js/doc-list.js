/* ============================================================
 *  doc-list.js — หน้าประวัติเอกสาร ใช้ร่วมกันทุกประเภท
 *      ประวัติรับเข้า / รายการใบเบิก (+อนุมัติ) / ประวัติใบจ่าย (+เซ็นรับของ)
 *
 *  โครงเดียวกันหมด: ตารางรายการเอกสาร -> กดดูรายละเอียด -> ทำงานกับใบนั้น
 *  ปุ่มที่โผล่ในแต่ละใบขึ้นกับ "ประเภท + สถานะ + สิทธิ์ของคนที่เปิดดู"
 *  ตัดสินใจที่ actionsFor() ที่เดียว จะได้ไม่กระจายเงื่อนไขไปทั่วไฟล์
 *
 *  ฝั่ง backend ตรวจสิทธิ์ซ้ำทุกคำสั่ง การซ่อนปุ่มตรงนี้เป็นเรื่องของ
 *  ความสะดวก ไม่ใช่ด่านความปลอดภัย
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.DOCLIST;
    if (!CFG) { return; }

    var TYPE  = CFG.docType;
    var table = null;
    var STAT  = {};
    var ME    = CFG.me;
    var ROLE  = CFG.role;
    var canSeeAll = false;

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }

    function fmt(v) {
        var n = Number(v);
        if (isNaN(n)) { return '0'; }
        if (n === Math.floor(n)) { return String(n); }
        return String(Math.round(n * 100) / 100);
    }

    /* เงินแสดงทศนิยมสองตำแหน่งเสมอ ต่างจากจำนวนของที่ตัดทศนิยมทิ้งเมื่อเป็นจำนวนเต็ม
       เพราะ "15" กับ "15.00" ในคอลัมน์เงินอ่านแล้วสะดุด และเทียบกันในแนวตั้งยาก */
    function money(v) {
        var n = Number(v);
        if (isNaN(n)) { return '0.00'; }
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function isManager() { return ROLE === 'super_admin' || ROLE === 'admin'; }
    function isApprover() { return isManager() || ROLE === 'approver'; }


    function post(payload, done, fail) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
            if (!res)    { fail('เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง'); return; }
            if (!res.ok) { fail(res.message || 'ทำรายการไม่สำเร็จ'); return; }
            done(res);
        };
        xhr.onerror = function () { fail('ติดต่อเซิร์ฟเวอร์ไม่ได้'); };
        xhr.send(JSON.stringify(payload));
    }

    function ok(message) {
        if (typeof window.appSuccess === 'function') { window.appSuccess(message); }
        else { alert(message); }
    }

    function bad(message) {
        if (typeof window.appError === 'function') { window.appError(message); }
        else { alert(message); }
    }


    /* ============================================================
     *  โหลดรายการ
     * ============================================================ */
    function load() {
        var payload = {
            action:    'list',
            doc_type:  TYPE,
            status:    el('filterStatus') ? el('filterStatus').value : '',
            date_from: el('dateFrom') ? el('dateFrom').value : '',
            date_to:   el('dateTo')   ? el('dateTo').value   : ''
        };
        if (el('onlyMine') && el('onlyMine').checked) { payload.mine = true; }

        post(payload, function (res) {
            STAT      = res.statuses || {};
            canSeeAll = !!res.canSeeAll;

            /* ครั้งแรกส่งข้อมูลเข้าไปตอนสร้างตารางเลย
               Tabulator 6 สั่ง setData ก่อน tableBuilt ไม่ได้ จะพังตอนคำนวณความสูง */
            if (!table) { build(res.docs || []); }
            else        { table.setData(res.docs || []); }

            refreshCount(res.docs ? res.docs.length : 0);

            setHidden(el('listLoading'), true);
            setHidden(el('listBody'), false);
            if (window.lucide) { window.lucide.createIcons(); }

            openFromUrl();
        }, function (message) {
            setHidden(el('listLoading'), true);
            bad(message);
        });
    }


    /* ---------- เปิดเอกสารที่ถูกส่งมาทาง ?doc=... ----------
     * ใช้กับลิงก์ในแจ้งเตือนและในอีเมล ที่ต้องพาไปถึงเอกสารใบนั้นเลย
     * ไม่ใช่แค่พามาที่ตารางแล้วให้ผู้ใช้ไล่หาเองว่าใบไหน
     *
     * ทำครั้งเดียวต่อการเข้าหน้าหนึ่งครั้ง — กดปุ่มโหลดใหม่หรือเปลี่ยนตัวกรอง
     * แล้วกล่องเด้งขึ้นมาซ้ำทุกครั้งจะกลายเป็นสิ่งที่ปิดไม่ลง
     */
    var urlDocDone = false;

    function openFromUrl() {
        if (urlDocDone) { return; }
        urlDocDone = true;

        var m = String(window.location.search || '').match(/[?&]doc=(\d+)/);
        if (!m) { return; }

        var id = parseInt(m[1], 10);
        if (id > 0) { openDetail(id); }
    }

    function refreshCount(n) {
        if (el('rowCount')) { el('rowCount').textContent = String(n); }
    }


    /* ---------- ป้ายสถานะ ---------- */
    function statusPill(status, label) {
        var tone = {
            waiting:   'is-wait',
            approved:  'is-ok',
            rejected:  'is-bad',
            issued:    'is-info',
            received:  'is-done',
            cancelled: 'is-off'
        }[status] || '';

        return '<span class="doc-pill ' + tone + '">' + escapeHtml(label || status) + '</span>';
    }


    /* ============================================================
     *  ปุ่มที่ทำได้กับเอกสารใบหนึ่ง
     *  รวมเงื่อนไขไว้ที่เดียว ทั้งตารางและกล่องรายละเอียดใช้ชุดเดียวกัน
     * ============================================================ */
    function actionsFor(doc) {
        var out = [];

        if (doc.doc_type === 'request') {
            /* อนุมัติได้เมื่อ: รออนุมัติ + เป็นผู้อนุมัติ + ไม่ใช่ใบของตัวเอง */
            if (doc.status === 'waiting' && isApprover() && doc.requester !== ME) {
                out.push({ key: 'approve', label: 'อนุมัติ',    icon: 'check',  tone: 'ok' });
                out.push({ key: 'reject',  label: 'ไม่อนุมัติ', icon: 'x',      tone: 'bad' });
            }
            /* ยกเลิกใบของตัวเองที่ยังไม่ถูกตัดสิน หรือผู้ดูแลยกเลิกใบที่อนุมัติแล้ว */
            if ((doc.status === 'waiting' && (doc.requester === ME || isManager())) ||
                (doc.status === 'approved' && isManager())) {
                out.push({ key: 'cancel', label: 'ยกเลิกใบเบิก', icon: 'ban', tone: 'bad' });
            }
        }

        if (doc.doc_type === 'issue') {
            /* เซ็นรับของ: ผู้ขอเบิกของใบนั้น หรือผู้ดูแลเซ็นแทน */
            if (doc.status === 'issued' && (doc.requester === ME || isManager())) {
                out.push({ key: 'ack', label: 'เซ็นรับของ', icon: 'pen-line', tone: 'ok' });
            }
            if (doc.status === 'issued' && isApprover()) {
                out.push({ key: 'cancel', label: 'ยกเลิกใบจ่าย', icon: 'ban', tone: 'bad' });
            }
        }

        if (doc.doc_type === 'receive') {
            if (doc.status === 'received' && isManager()) {
                out.push({ key: 'cancel', label: 'ยกเลิกใบรับเข้า', icon: 'ban', tone: 'bad' });
            }
        }

        /* ---------- ส่งอีเมลซ้ำ ----------
         * เฉพาะผู้ดูแลระบบ และเฉพาะเอกสารที่มีปลายทางให้ส่งจริง
         * ใบรับเข้าไม่มีใครต้องรับแจ้งเตือน จึงไม่มีปุ่มนี้
         * ใบเบิกที่จ่ายครบแล้วก็ไม่มี เพราะเรื่องย้ายไปอยู่ที่ใบจ่ายแล้ว */
        if (isManager() && doc.doc_type !== 'receive'
            && !(doc.doc_type === 'request' && doc.status === 'issued')) {
            out.push({ key: 'mail', label: 'ส่งอีเมลซ้ำ', icon: 'mail', tone: 'ghost' });
        }

        return out;
    }


    /* ============================================================
     *  ปุ่มไอคอนในคอลัมน์จัดการ
     * ============================================================
     *  ลำดับคงที่เสมอ ไม่เรียงตามว่าปุ่มไหนกดได้บ้าง
     *  ถ้าลำดับขยับตามสถานะ ตำแหน่งของปุ่ม "ยกเลิก" จะไปอยู่ตรงที่เมื่อกี้
     *  เป็นปุ่ม "พิมพ์" ในอีกแถวหนึ่ง แล้วคนที่กดตามความเคยชินจะกดผิด
     *
     *  ปุ่มที่ทำไม่ได้ในสถานะนั้นถูกตัดออก ไม่ได้ทำเป็นสีจาง
     *  เพราะปุ่มจางที่กดไม่ได้ก็ยังชวนให้กด แล้วไม่มีอะไรบอกว่าทำไมกดไม่ได้
     * ============================================================ */
    function rowActions(doc) {
        var out = [];

        out.push({ key: 'open', icon: 'eye', tone: 'ghost', label: 'ดูรายละเอียด' });
        out.push({ key: 'print', icon: 'printer', tone: 'ghost', label: 'พิมพ์เอกสาร' });

        /* ---------- ลิงก์เซ็นรับของ ----------
         * มีเฉพาะใบจ่ายที่ยังรอเซ็น และเฉพาะผู้ดูแลระบบ
         * เพราะลิงก์นี้เปิดได้โดยไม่ต้องล็อกอิน ใครถือก็เซ็นรับแทนได้
         *
         * ใบที่เซ็นไปแล้วไม่มีปุ่มนี้ ส่งลิงก์ให้ใครก็ทำอะไรไม่ได้แล้ว
         */
        if (doc.doc_type === 'issue' && doc.status === 'issued' && isManager()) {
            out.push({ key: 'copylink', icon: 'link',          tone: 'ghost', label: 'คัดลอกลิงก์เซ็นรับของ' });
            out.push({ key: 'openlink', icon: 'external-link', tone: 'ghost', label: 'เปิดหน้าเซ็นรับของ' });
        }

        /* ---------- ลิงก์อนุมัติ ----------
         * มีเฉพาะใบเบิกที่ยังรออนุมัติ และเฉพาะผู้ดูแลระบบ ด้วยเหตุผลเดียวกับ
         * ลิงก์เซ็นรับ: เปิดได้โดยไม่ต้องล็อกอิน ใครถือก็ตัดสินแทนได้
         *
         * ต่างกันตรงที่ลิงก์อนุมัติออกให้เป็นรายคน ปุ่มนี้จึงเปิดรายชื่อ
         * ผู้อนุมัติให้เลือกก่อน ไม่ได้คัดลอกทันทีเหมือนฝั่งเซ็นรับ
         */
        if (doc.doc_type === 'request' && doc.status === 'waiting' && isManager()) {
            out.push({ key: 'approvelink', icon: 'link', tone: 'ghost', label: 'คัดลอกลิงก์อนุมัติ' });
        }

        /* ที่เหลือใช้เงื่อนไขชุดเดียวกับกล่องรายละเอียด จะได้ไม่มีปุ่มที่โผล่
           ในตารางแต่หายไปในกล่อง หรือกลับกัน */
        var acts = actionsFor(doc);
        for (var i = 0; i < acts.length; i++) {
            out.push(acts[i]);
        }
        return out;
    }

    /* ความกว้างคิดจากจำนวนปุ่มมากสุดที่เป็นไปได้ของหน้านั้น
       ตั้งค่าคงที่ทีละหน้า ไม่ใช่ต่อแถว เพราะคอลัมน์มีความกว้างเดียวทั้งตาราง
       ถ้าตั้งแคบตามแถวที่ปุ่มน้อย แถวที่ปุ่มเยอะจะถูกตัดหาย */
    function rowActionWidth() {
        if (TYPE === 'issue')   { return 232; }   // ดู พิมพ์ ลิงก์ เปิด เซ็น ยกเลิก เมล
        if (TYPE === 'request') { return 232; }   // ดู พิมพ์ ลิงก์ อนุมัติ ไม่อนุมัติ ยกเลิก เมล
        return 100;                               // ใบรับเข้า: ดู พิมพ์ ยกเลิก
    }

    function rowActionHtml(doc) {
        var acts = rowActions(doc);
        var html = '<span class="rowacts">';

        for (var i = 0; i < acts.length; i++) {
            var a = acts[i];

            /* พิมพ์เป็น <a> ไม่ใช่ <button> เพราะเปิดแท็บใหม่
               ทำเป็นปุ่มแล้วสั่ง window.open จะโดนตัวกันป๊อปอัพบล็อก
               และเสียความสามารถพื้นฐานอย่างคลิกขวาเพื่อคัดลอกที่อยู่ */
            if (a.key === 'print') {
                html += '<a class="rowact is-' + a.tone + '" target="_blank" rel="noopener"'
                      + ' href="print_doc.php?doc_id=' + doc.doc_id + '"'
                      + ' title="' + escapeHtml(a.label) + '"'
                      + ' aria-label="' + escapeHtml(a.label) + '">'
                      + '<i data-lucide="' + a.icon + '" aria-hidden="true"></i></a>';
                continue;
            }

            html += '<button type="button" class="rowact is-' + a.tone + '"'
                  + ' data-row-act="' + a.key + '" data-doc="' + doc.doc_id + '"'
                  + ' title="' + escapeHtml(a.label) + '"'
                  + ' aria-label="' + escapeHtml(a.label) + '">'
                  + '<i data-lucide="' + a.icon + '" aria-hidden="true"></i></button>';
        }

        return html + '</span>';
    }


    /* ============================================================
     *  ตาราง
     * ============================================================ */
    function build(rows) {
        var G = window.GridCommon;

        table = new Tabulator('#grid', G.options({
            data:        rows || [],
            layout:      'fitColumns',
            height:      '100%',
            placeholder: 'ไม่พบเอกสารตามเงื่อนไขที่เลือก',
            index:       'doc_id',
            columns:     columns(),
            initialSort: [{ column: 'doc_date', dir: 'desc' }],

            paginationSize: 20,
            paginationSizeSelector: [10, 20, 50, 100]
        }));

        G.guardRanges(table);
        G.enableFilterToggles(table);

        /* Lucide แปลง <i data-lucide> เป็น <svg> ให้ครั้งเดียวตอนหน้าโหลดเสร็จ
           แต่ตารางวาดแถวใหม่ทุกครั้งที่เลื่อน กรอง เรียง หรือเปลี่ยนหน้า
           ปุ่มในคอลัมน์จัดการจึงเกิดหลังจากนั้นเสมอ และเหลือเป็นช่องว่าง
           (วัดได้จริง: เปิดหน้ามาแล้วมี <i data-lucide> ค้างอยู่ 7 ตัว)

           renderComplete ยิงหลังวาดเสร็จทุกรอบ จึงเป็นที่เดียวที่เชื่อได้ */
        table.on('renderComplete', function () {
            if (typeof window.renderIcons === 'function') { window.renderIcons(); }
            else if (window.lucide) { window.lucide.createIcons(); }
        });

        if (window.GridExport) {
            /* attach() รับอ็อบเจกต์ก้อนเดียว ไม่ใช่ (table, options)
               และ button ต้องเป็นตัวโหนดจริง ไม่ใช่ชื่อ id

               ของเดิมเรียกเป็น attach(table, {...}) ซึ่ง opt กลายเป็นตัวตาราง
               แล้วด่านแรกในฟังก์ชัน (!opt.button) ก็คืนค่าออกไปเงียบ ๆ
               ผลคือปุ่มส่งออกของหน้าประวัติทั้งสามหน้ากดแล้วไม่มีอะไรเกิดขึ้นเลย */
            window.GridExport.attach({
                table:     table,
                button:    el('btnExport'),
                filename:  CFG.exportName,
                sheetName: CFG.exportName
            });

            attachLineExport();
        }
    }


    /* ---------- ส่งออกแยกรายบรรทัด ----------
     * ไฟล์ปกติเป็นหนึ่งแถวต่อหนึ่งใบ ซึ่งตอบคำถามเรื่องราคาไม่ได้
     * เพราะราคาต่อหน่วยอยู่ที่บรรทัด ใบเดียวมีหลายราคาได้
     *
     * ไฟล์นี้แตกเป็นรายบรรทัด จึงเห็นว่าของตัวเดียวกันรับเข้ามาหลายครั้ง
     * ครั้งไหนราคาเท่าไร แทนที่จะเห็นแค่ยอดรวมที่กลืนทุกราคารวมกัน
     *
     * ขอจากเซิร์ฟเวอร์ตอนกดปุ่ม ไม่ได้ลากรายละเอียดทุกใบมาตั้งแต่เปิดหน้า
     * และส่งตัวกรองชุดเดียวกับที่ใช้อยู่ไปด้วย ไฟล์จะได้ตรงกับที่เห็นบนจอ
     */
    function attachLineExport() {
        var btn = el('btnExportLines');
        if (!btn || !window.GridExport.rows) { return; }

        var cols = [
            { key: 'doc_no',       label: 'เลขที่เอกสาร' },
            { key: 'doc_date',     label: 'วันที่' },
            { key: 'status_label', label: 'สถานะ' }
        ];

        if (TYPE !== 'receive') {
            cols.push({ key: 'department_name', label: 'หน่วยงาน' });
            cols.push({ key: 'requester_name',  label: 'ผู้ขอเบิก' });
        }
        if (TYPE === 'issue') {
            cols.push({ key: 'ref_doc_no', label: 'อ้างอิงใบเบิก' });
        }

        cols.push({ key: 'line_no',      label: 'บรรทัดที่' });
        cols.push({ key: 'product_code', label: 'รหัสสินค้า' });
        cols.push({ key: 'product_name', label: 'ชื่อสินค้า' });
        cols.push({ key: 'qty',          label: 'จำนวน' });
        cols.push({ key: 'unit_name',    label: 'หน่วย' });
        cols.push({ key: 'unit_cost',    label: 'ราคา/หน่วย' });
        cols.push({ key: 'amount',       label: 'เป็นเงิน' });
        cols.push({ key: 'item_note',    label: 'หมายเหตุบรรทัด' });
        cols.push({ key: 'doc_note',     label: 'หมายเหตุเอกสาร' });

        window.GridExport.rows({
            button:    btn,
            filename:  CFG.exportName + '_รายบรรทัด',
            sheetName: 'รายบรรทัด',
            columns:   cols,

            fetch: function (done, fail) {
                var payload = {
                    action:    'export_lines',
                    doc_type:  TYPE,
                    status:    el('filterStatus') ? el('filterStatus').value : '',
                    date_from: el('dateFrom') ? el('dateFrom').value : '',
                    date_to:   el('dateTo')   ? el('dateTo').value   : ''
                };
                if (el('onlyMine') && el('onlyMine').checked) { payload.mine = true; }

                post(payload, function (res) { done(res.lines || []); }, fail);
            }
        });
    }

    function columns() {
        var G = window.GridCommon;

        var cols = [
            G.col(G.control, {
                formatter: 'responsiveCollapse', width: 36, minWidth: 36,
                hozAlign: 'center', resizable: false, headerSort: false, responsive: 0
            }),

            G.col(G.filterText('ค้นเลขที่'), {
                title: 'เลขที่', field: 'doc_no', width: 150, responsive: 0,
                download: true, clipboard: true,
                formatter: function (cell) {
                    return '<span class="grid-user doc-no">' + escapeHtml(cell.getValue()) + '</span>';
                }
            }),

            {
                title: 'วันที่', field: 'doc_date', width: 116, responsive: 1,
                download: true, clipboard: true, hozAlign: 'center',
                /* แสดงเป็น "13 ส.ค. 69" แต่ค่าดิบยังเป็น 2026-08-13
                   การเรียงคอลัมน์ ตัวกรองช่วงวันที่ และไฟล์ Excel ที่ส่งออก
                   จึงยังอ่านจากค่าดิบตัวเดิม ไม่ได้อ่านข้อความที่เห็นบนจอ */
                formatter: window.ThaiDate ? window.ThaiDate.cell : undefined
            },

            G.col(G.labelled(function (v, d) { return d ? d.status_label : v; }),
                  G.filterPick(statusValues()), {
                title: 'สถานะ', field: 'status', width: 130, responsive: 1, hozAlign: 'center',
                download: true, clipboard: true,
                formatter: function (cell) {
                    return statusPill(cell.getValue(), cell.getRow().getData().status_label);
                }
            })
        ];

        if (TYPE !== 'receive') {
            cols.push(G.col(G.filterOwn('ทั้งหมด'), {
                title: 'หน่วยงาน', field: 'department_name', minWidth: 150, responsive: 3,
                download: true, clipboard: true,
                formatter: function (cell) {
                    var v = cell.getValue();
                    return v ? escapeHtml(v) : '<span class="grid-empty">—</span>';
                }
            }));

            cols.push(G.col(G.filterText('ค้นผู้ขอ'), {
                title: 'ผู้ขอเบิก', field: 'requester_name', minWidth: 130, responsive: 4,
                download: true, clipboard: true,
                formatter: function (cell) {
                    var d = cell.getRow().getData();
                    var name = cell.getValue() || d.requester;
                    if (!name) { return '<span class="grid-empty">—</span>'; }
                    var html = escapeHtml(name);
                    if (d.requester === ME) { html += ' <span class="grid-tag grid-tag-self">คุณ</span>'; }
                    return html;
                }
            }));
        }

        if (TYPE === 'issue') {
            cols.push({
                title: 'อ้างใบเบิก', field: 'ref_doc_no', width: 150, responsive: 5,
                download: true, clipboard: true,
                formatter: function (cell) {
                    var v = cell.getValue();
                    return v ? '<span class="grid-user">' + escapeHtml(v) + '</span>'
                             : '<span class="grid-empty">—</span>';
                }
            });
        }

        cols.push({
            title: 'รายการ', field: 'line_count', width: 92, hozAlign: 'right', responsive: 6,
            download: true, clipboard: true,
            formatter: function (cell) { return fmt(cell.getValue()); }
        });

        cols.push({
            title: 'จำนวนรวม', field: 'total_qty', width: 104, hozAlign: 'right', responsive: 6,
            download: true, clipboard: true,
            formatter: function (cell) { return '<b class="num">' + fmt(cell.getValue()) + '</b>'; }
        });

        /* ---------- มูลค่าของใบ : เฉพาะประวัติการรับเข้า ----------
         * ใบรับเข้าเป็นที่เดียวที่ผู้ใช้กรอกราคาเอง ตัวเลขนี้จึงเป็นเงินที่จ่ายจริง
         * ใบเบิกกับใบจ่ายมีราคาเหมือนกัน แต่เป็นราคาที่ระบบคิดจากต้นทุนถัวเฉลี่ย
         * ซึ่งเป็นคนละความหมาย จึงไม่เอามาวางไว้ในคอลัมน์เดียวกัน
         *
         * นี่คือ "ทั้งใบเป็นเงินเท่าไร" ส่วนราคาต่อหน่วยของแต่ละบรรทัด
         * ดูได้ในกล่องรายละเอียด และในไฟล์ Excel ที่ส่งออก
         */
        if (TYPE === 'receive') {
            cols.push({
                title: 'มูลค่ารวม', field: 'total_cost', width: 118, hozAlign: 'right', responsive: 5,
                download: true, clipboard: true,
                formatter: function (cell) {
                    var v = Number(cell.getValue());
                    if (!v) { return '<span class="grid-empty">—</span>'; }
                    return '<b class="doc-money">' + money(v) + '</b>';
                }
            });
        }

        cols.push(G.col(G.filterText('ค้นหมายเหตุ'), {
            title: 'หมายเหตุ', field: 'note', minWidth: 150, responsive: 7,
            download: true, clipboard: true,
            formatter: function (cell) {
                var v = cell.getValue();
                return (v && String(v).trim() !== '') ? escapeHtml(v)
                                                      : '<span class="grid-empty">—</span>';
            }
        }));

        /* ---------- คอลัมน์จัดการ ----------
         * เดิมเป็นปุ่ม "ดูรายละเอียด" อย่างเดียว ที่เหลือต้องเปิดกล่องก่อนถึงจะกดได้
         * งานที่ทำบ่อยที่สุด (พิมพ์ / คัดลอกลิงก์ / ส่งเมล) จึงต้องกดสามครั้งเสมอ
         *
         * รวมมาไว้ในแถวเดียวเป็นไอคอนล้วน กดได้จากตารางเลย
         * ไอคอนล้วนเพราะห้าปุ่มที่มีตัวหนังสือกินความกว้างเกิน 400px
         * ซึ่งจะเบียดคอลัมน์ข้อมูลจนอ่านไม่ออก — ทุกปุ่มมี title และ aria-label กำกับ
         */
        cols.push(G.col(G.control, {
            title: 'จัดการ', field: '_open', width: rowActionWidth(), hozAlign: 'center',
            headerSort: false, resizable: false, responsive: 0,
            formatter: function (cell) {
                return rowActionHtml(cell.getRow().getData());
            },
            /* ไม่ผูก cellClick เพราะช่องนี้มีหลายปุ่ม ต้องรู้ว่ากดปุ่มไหน
               ตัวจับเหตุการณ์อยู่ที่ตัวตารางใน bindRowActions() ที่เดียว */
            cssClass: 'cell-rowacts'
        }));

        return cols;
    }

    function statusValues() {
        var out = {};
        var k;
        for (k in STAT) { if (STAT.hasOwnProperty(k)) { out[k] = STAT[k]; } }
        return out;
    }


    /* ============================================================
     *  กล่องรายละเอียดเอกสาร
     * ============================================================ */
    var openDoc = null;

    function openDetail(doc_id) {
        post({ action: 'get', doc_id: doc_id }, function (res) {
            openDoc = res.doc;
            renderDetail(res.doc);
            setHidden(el('docPanel'), false);
            if (window.lucide) { window.lucide.createIcons(); }
        }, bad);
    }

    function closeDetail() {
        setHidden(el('docPanel'), true);
        openDoc = null;
    }

    function line(label, value) {
        if (value === '' || value === null || value === undefined) { return ''; }
        return '<div class="doc-meta-row"><dt>' + escapeHtml(label) + '</dt>'
             + '<dd>' + escapeHtml(value) + '</dd></div>';
    }

    function renderDetail(doc) {
        el('docTitle').textContent    = doc.doc_type_label + ' ' + doc.doc_no;
        el('docStatus').innerHTML     = statusPill(doc.status, doc.status_label);

        /* วันที่ในกล่องนี้แสดงแบบไทย ส่วนค่าใน doc ยังเป็น YYYY-MM-DD เหมือนเดิม
           ปุ่มต่าง ๆ ที่ยิงกลับไปหาเซิร์ฟเวอร์จึงส่งค่าดิบไปเหมือนเดิมทุกอย่าง */
        var dt   = function (v) { return window.ThaiDate ? window.ThaiDate.dateTime(v, '') : v; };
        var d    = function (v) { return window.ThaiDate ? window.ThaiDate.short(v, '') : v; };

        var meta = '';
        meta += line('วันที่เอกสาร', d(doc.doc_date));
        meta += line('หน่วยงาน',     doc.department_name);
        meta += line('ผู้ขอเบิก',    doc.requester_name || doc.requester);
        meta += line('อ้างใบเบิก',   doc.ref_doc_no);
        meta += line('ผู้บันทึก',    doc.created_by);
        meta += line('เวลาบันทึก',   dt(doc.created_at));
        meta += line('ผู้อนุมัติ',   doc.approved_by ? (doc.approved_by + ' · ' + dt(doc.approved_at)) : '');
        meta += line('หมายเหตุการอนุมัติ', doc.approve_note);
        meta += line('ผู้รับของ',    doc.receiver_name ? (doc.receiver_name
                     + (doc.received_at ? ' · ' + dt(doc.received_at) : '')) : '');
        meta += line('หมายเหตุ',     doc.note);
        meta += line('เหตุผลที่ยกเลิก', doc.cancel_note);
        el('docMeta').innerHTML = meta;

        /* ---------- รายการสินค้า ----------
         * ใบเบิกแสดงแค่ "ขออะไร เท่าไร" เท่านั้น
         * ตัดคอลัมน์ จ่ายแล้ว / ค้างจ่าย / หน่วย / คงเหลือ ออก เพราะเป็น
         * เรื่องของฝั่งคลัง ไม่ใช่สิ่งที่ผู้ขอเบิกต้องตัดสินใจจากมัน
         * (ยอดค้างจ่ายยังดูได้จากหน้าทำใบจ่าย ซึ่งเป็นที่ที่ต้องใช้จริง)
         * ส่วนใบรับเข้ากับใบจ่ายยังแสดงครบเหมือนเดิม
         */
        var slim  = (doc.doc_type === 'request');

        /* ---------- ราคาต่อหน่วย : เฉพาะใบรับเข้า ----------
         * เป็นราคาที่ผู้ใช้กรอกตอนรับของ ซึ่งเปลี่ยนได้ทุกครั้งที่ซื้อ
         * ของตัวเดียวกันรับเข้าสองครั้งคนละราคาจึงเห็นแยกกันได้ตรงนี้
         * ไม่ใช่เห็นแต่ราคาเฉลี่ยที่กลืนสองครั้งนั้นรวมกัน
         */
        var money_col = (doc.doc_type === 'receive');
        var rows  = '';
        var total = 0;
        var baht  = 0;

        for (var i = 0; i < doc.items.length; i++) {
            var it = doc.items[i];
            total += Number(it.qty);

            rows += '<tr>'
                  + '<td class="num">' + (i + 1) + '</td>'
                  + '<td><span class="grid-user">' + escapeHtml(it.product_code) + '</span><br>'
                  + '<span class="doc-pname">' + escapeHtml(it.product_name) + '</span></td>'
                  + '<td class="num"><b>' + fmt(it.qty) + '</b>'
                  + (slim && it.unit_name ? ' <span class="doc-unit">' + escapeHtml(it.unit_name) + '</span>' : '')
                  + '</td>';

            if (!slim) {
                rows += '<td>' + escapeHtml(it.unit_name) + '</td>'
                      + '<td class="num">' + fmt(it.balance) + '</td>';
            }

            if (money_col) {
                var cost = Number(it.unit_cost) || 0;
                var amt  = Number(it.qty) * cost;
                baht    += amt;

                rows += '<td class="num">'
                      + (cost > 0 ? '<span class="doc-money">' + money(cost) + '</span>'
                                  : '<span class="grid-empty">—</span>') + '</td>'
                      + '<td class="num">'
                      + (amt > 0 ? '<b class="doc-money">' + money(amt) + '</b>'
                                 : '<span class="grid-empty">—</span>') + '</td>';
            }

            rows += '<td>' + (it.note ? escapeHtml(it.note) : '<span class="grid-empty">—</span>') + '</td>'
                  + '</tr>';
        }

        var head = '<tr><th>#</th><th>สินค้า</th><th class="num">จำนวน</th>'
                 + (slim ? '' : '<th>หน่วย</th><th class="num">คงเหลือ</th>')
                 + (money_col ? '<th class="num">ราคา/หน่วย</th><th class="num">เป็นเงิน</th>' : '')
                 + '<th>หมายเหตุ</th></tr>';

        /* แถวรวมเงินท้ายตาราง มีเฉพาะใบรับเข้าที่มีราคาจริง
           ใบที่กรอกราคาไม่ครบจะได้ยอดรวมที่ต่ำกว่าความจริง จึงบอกไว้ตรง ๆ */
        var foot = '';
        if (money_col) {
            var missing = 0;
            for (var k = 0; k < doc.items.length; k++) {
                if (!(Number(doc.items[k].unit_cost) > 0)) { missing++; }
            }

            foot = '<tfoot><tr>'
                 + '<td colspan="' + (slim ? 3 : 5) + '" class="doc-foot-label">รวมเป็นเงิน'
                 + (missing > 0
                    ? ' <span class="grid-empty">(ยังไม่ได้กรอกราคา ' + missing + ' บรรทัด)</span>'
                    : '')
                 + '</td>'
                 + '<td class="num"></td>'
                 + '<td class="num"><b class="doc-money">' + money(baht) + '</b></td>'
                 + '<td></td></tr></tfoot>';
        }

        el('docItems').innerHTML = '<thead>' + head + '</thead><tbody>' + rows + '</tbody>' + foot;
        el('docTotal').textContent = fmt(total);

        /* ---------- ปุ่มทำงาน ----------
         * ปุ่มพิมพ์อยู่นอกรายการ actionsFor() เพราะเปิดหน้าใหม่ ไม่ได้เปลี่ยนสถานะเอกสาร
         * และต้องมีให้กดได้เสมอทุกสถานะ รวมถึงใบที่ยกเลิกไปแล้ว (ยังต้องเก็บเป็นหลักฐาน) */
        var acts = actionsFor(doc);
        var html = '<a class="doc-act is-ghost" target="_blank" rel="noopener"'
                 + ' href="print_doc.php?doc_id=' + doc.doc_id + '">'
                 + '<i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>'
                 + 'พิมพ์เอกสาร</a>';
        for (var j = 0; j < acts.length; j++) {
            html += '<button type="button" class="doc-act is-' + acts[j].tone + '"'
                  + ' data-act="' + acts[j].key + '">'
                  + '<i data-lucide="' + acts[j].icon + '" class="h-4 w-4" aria-hidden="true"></i>'
                  + escapeHtml(acts[j].label) + '</button>';
        }
        el('docActions').innerHTML = html;
        setHidden(el('docActions'), acts.length === 0);
    }


    /* ============================================================
     *  ทำงานกับเอกสาร
     * ============================================================ */
    function runAction(key) {
        if (!openDoc) { return; }

        if (key === 'approve') { decide('approved'); return; }
        if (key === 'reject')  { decide('rejected'); return; }
        if (key === 'ack')     { acknowledge();      return; }
        if (key === 'cancel')  { cancelDoc();        return; }
        if (key === 'mail')    { resendMail();       return; }
    }


    /* ============================================================
     *  ปุ่มไอคอนในตาราง
     * ============================================================
     *  ผูกตัวจับคลิกไว้ที่ #grid ครั้งเดียว ไม่ใช่ผูกทีละปุ่ม
     *  เพราะ Tabulator วาดแถวใหม่ทุกครั้งที่เลื่อน กรอง หรือโหลดข้อมูล
     *  ปุ่มที่ผูกไว้ตอนวาดรอบก่อนจะหายไปพร้อมโหนดเดิม
     *
     *  ใช้ข้อมูลจากแถวในตารางเป็น openDoc เลย ไม่ต้องยิงขอเอกสารก่อน
     *  ทุกอย่างที่ปุ่มพวกนี้ใช้ (doc_no, doc_type_label, status_label, ชื่อผู้ขอ)
     *  มาพร้อมกับรายการอยู่แล้ว การยิงซ้ำจะเพิ่มแค่ความหน่วงก่อนกล่องยืนยันเด้ง
     * ============================================================ */
    function bindRowActions() {
        var grid = el('grid');
        if (!grid) { return; }

        grid.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-row-act]') : null;
            if (!btn) { return; }

            /* กันไม่ให้คลิกทะลุไปโดนตัวจับของ Tabulator ที่ระดับแถว */
            e.preventDefault();
            e.stopPropagation();

            var id  = Number(btn.getAttribute('data-doc'));
            var row = table ? table.getRow(id) : null;
            if (!row) { return; }

            var doc = row.getData();
            var key = btn.getAttribute('data-row-act');

            /* แถวตัวอย่างของคู่มือไม่มีอยู่จริงในฐานข้อมูล
               ปล่อยให้กดแล้วยิงคำสั่งไปจะได้ error จากเซิร์ฟเวอร์ที่อธิบายอะไรไม่ได้
               ตอบกลับด้วยข้อความที่ตรงกับสิ่งที่เกิดขึ้นแทน */
            if (doc._demo) {
                if (typeof window.appInfo === 'function') {
                    window.appInfo('นี่คือแถวตัวอย่างสำหรับสาธิต กดใช้งานจริงไม่ได้ '
                                 + 'ปิดคู่มือแล้วลองกับเอกสารจริงได้เลย');
                }
                return;
            }

            if (key === 'open')        { openDetail(id);        return; }
            if (key === 'copylink')    { signLink(doc, 'copy'); return; }
            if (key === 'openlink')    { signLink(doc, 'open'); return; }
            if (key === 'approvelink') { approveLink(doc);      return; }

            openDoc = doc;
            runAction(key);
        });
    }


    /* ---------- ลิงก์เซ็นรับของ ----------
     * ผู้ขอเบิกส่วนใหญ่ไม่มีสิทธิ์เข้าหน้าในระบบ ลิงก์นี้จึงเปิดได้เลยไม่ต้องล็อกอิน
     * มีไว้ให้คลังส่งทางไลน์ได้ ตอนที่ผู้เบิกหาเมลไม่เจอ
     *
     * ขอลิงก์จากเซิร์ฟเวอร์ทุกครั้ง ไม่ประกอบ URL เองในหน้าจอ
     * เพราะกุญแจอยู่ในฐานข้อมูล และเซิร์ฟเวอร์เป็นคนตัดสินว่าต้องออกดอกใหม่ไหม
     */
    function signLink(doc, mode) {
        post({
            action: 'sign_link', csrf_token: CFG.csrfToken, doc_id: doc.doc_id
        }, function (res) {

            if (mode === 'open') {
                window.open(res.url, '_blank', 'noopener');
                return;
            }
            copyText(res.url, doc, res.days);

        }, bad);
    }

    /* ---------- ลิงก์อนุมัติใบเบิก ----------
     * ผู้อนุมัติที่หาเมลไม่เจอ ผู้ดูแลส่งลิงก์ให้ทางไลน์ได้
     *
     * ต่างจากลิงก์เซ็นรับตรงที่กุญแจออกให้เป็นรายคน เพราะระบบต้องบันทึกได้ว่า
     * ใครอนุมัติ จึงคัดลอกทันทีไม่ได้ ต้องให้เลือกก่อนว่าจะส่งให้ใคร
     * ส่งผิดคน = คนนั้นตัดสินใบนี้ในนามของคนที่ลิงก์ออกให้
     *
     * มีผู้อนุมัติคนเดียวก็ยังต้องยืนยัน เพื่อให้เห็นชื่อก่อนส่งเสมอ
     */
    function approveLink(doc) {
        post({
            action: 'approve_link', csrf_token: CFG.csrfToken, doc_id: doc.doc_id
        }, function (res) {
            showApproveLinks(doc, res.links, res.days);
        }, bad);
    }

    function showApproveLinks(doc, links, days) {
        if (!window.Swal) {
            /* ไม่มีกล่องให้เลือก ก็ยังต้องใช้งานได้ — เอาคนแรกไปเลย
               แล้วบอกชื่อไว้ในกล่อง prompt ให้ตรวจก่อนส่ง */
            window.prompt('ลิงก์อนุมัติของ ' + links[0].full_name, links[0].url);
            return;
        }

        var html = '<p class="ack-lead">เลือกผู้อนุมัติที่จะส่งลิงก์ให้ '
                 + 'ลิงก์ใช้ได้ ' + days + ' วัน กดได้ครั้งเดียว <br/>'
                 + 'และผู้ที่ถือลิงก์จะตัดสินใบนี้<b>ในนามของคนนั้น</b></p>';

        for (var i = 0; i < links.length; i++) {
            html += '<p class="ack-lead" style="margin-bottom:2px"><b>'
                 +  escapeHtml(links[i].full_name) + '</b></p>'
                 +  '<input type="text" class="swal2-input ack-input" readonly value="'
                 +  escapeHtml(links[i].url) + '" onclick="this.select()">';
        }

        window.Swal.fire({
            title: 'ลิงก์อนุมัติใบ ' + doc.doc_no,
            html: html,
            confirmButtonText: 'ปิด',
            confirmButtonColor: '#4a5568',
            buttonsStyling: false,
            heightAuto: false,
            customClass: { popup: 'app-swal', confirmButton: 'app-swal-confirm' },
            width: 640
        });
    }


    /* คัดลอกลิงก์ลงคลิปบอร์ด
     * navigator.clipboard ใช้ได้เฉพาะบนหน้าที่เป็น https หรือ localhost
     * ระบบนี้รันบน http ในวงแลน จึงต้องมีทางสำรองที่ใช้ execCommand เสมอ
     * ถ้ายังไม่ได้อีก ค่อยเปิดกล่องให้ผู้ใช้ลากคัดลอกเอง — ดีกว่าบอกว่าคัดลอกไม่ได้
     */
    function copyText(text, doc, days) {
        var done = function () {
            ok('คัดลอกลิงก์เซ็นรับของ ' + doc.doc_no + ' แล้ว — ลิงก์ใช้ได้ ' + days + ' วัน');
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { showLink(text, doc, days); });
            return;
        }

        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();

        var okCopy = false;
        try { okCopy = document.execCommand('copy'); } catch (err) { okCopy = false; }
        document.body.removeChild(ta);

        if (okCopy) { done(); } else { showLink(text, doc, days); }
    }

    function showLink(text, doc, days) {
        if (!window.Swal) { window.prompt('คัดลอกลิงก์นี้', text); return; }

        window.Swal.fire({
            title: 'ลิงก์เซ็นรับของ ' + doc.doc_no,
            html: '<p class="ack-lead">คัดลอกอัตโนมัติไม่ได้บนเบราว์เซอร์นี้ '
                + 'กรุณาลากคลุมแล้วคัดลอกเอง</p>'
                + '<input type="text" class="swal2-input ack-input" readonly value="'
                + escapeHtml(text) + '" onclick="this.select()">'
                + '<p class="ack-lead">ลิงก์ใช้ได้ ' + days + ' วัน '
                + 'และเซ็นรับแทนได้ กรุณาส่งให้ผู้เบิกเท่านั้น</p>',
            confirmButtonText: 'ปิด',
            buttonsStyling: false,
            heightAuto: false,
            customClass: { popup: 'app-swal', confirmButton: 'app-swal-confirm' }
        });
    }


    /* ---------- ส่งอีเมลแจ้งเตือนซ้ำ ----------
     * ผู้ใช้หาเมลไม่เจอเป็นเรื่องที่เกิดจริงและบ่อย (เข้าถังขยะ หรือลบไปแล้ว)
     * ถ้าไม่มีปุ่มนี้ ผู้ดูแลระบบต้องไปหาข้อมูลแล้วพิมพ์เมลเองทีละฉบับ
     *
     * ถามยืนยันก่อนเสมอ เพราะเป็นการส่งของออกไปหาคนอื่น — กดพลาดแล้วเรียกคืนไม่ได้
     * และเมลที่ซ้ำซ้อนทำให้คนรับเลิกสนใจแจ้งเตือนจากระบบไปเลย
     */
    function resendMail() {
        var send = function () {
            post({
                action: 'resend_mail', csrf_token: CFG.csrfToken, doc_id: openDoc.doc_id
            }, function (res) { ok(res.message); }, bad);
        };

        if (typeof window.appConfirm === 'function') {
            window.appConfirm({
                title: 'ส่งอีเมลแจ้งเตือนซ้ำ',
                text:  'ส่งอีเมลของ ' + openDoc.doc_no + ' ไปหาผู้ที่เกี่ยวข้องอีกครั้ง '
                     + 'ตามสถานะปัจจุบันของเอกสาร (' + openDoc.status_label + ')',
                confirmText: 'ส่งอีเมล'
            }).then(function (yes) { if (yes) { send(); } });
        } else if (window.confirm('ส่งอีเมลซ้ำ?')) {
            send();
        }
    }

    /* ---------- อนุมัติ / ไม่อนุมัติ ---------- */
    function decide(decision) {
        var isNo = (decision === 'rejected');

        askReason({
            title:   isNo ? 'ไม่อนุมัติใบเบิก ' + openDoc.doc_no : 'อนุมัติใบเบิก ' + openDoc.doc_no,
            text:    isNo ? 'ระบุเหตุผล เพื่อให้ผู้ขอเบิกรู้ว่าต้องแก้อะไร'
                          : 'อนุมัติแล้วคลังจะทำใบจ่ายตามใบนี้ได้ (ยังไม่ตัดสต๊อกตอนนี้)',
            required: isNo,
            danger:   isNo,
            confirm:  isNo ? 'ยืนยันไม่อนุมัติ' : 'ยืนยันอนุมัติ'
        }, function (reason) {
            post({
                action: 'decide', csrf_token: CFG.csrfToken,
                doc_id: openDoc.doc_id, decision: decision, reason: reason
            }, function (res) { ok(res.message); closeDetail(); load(); }, bad);
        });
    }

    /* ---------- เซ็นรับของ ----------
     * เดิมกรอกแค่ชื่อ ซึ่งใครพิมพ์ก็ได้และไม่มีน้ำหนักเป็นหลักฐาน
     * ตอนนี้ต้องเซ็นด้วยนิ้วหรือเมาส์จริง แล้วเก็บภาพลายเซ็นไว้กับเอกสาร
     * ใบจ่ายที่พิมพ์ออกมาจะมีลายเซ็นติดไปด้วย ใช้เป็นหลักฐานส่งมอบได้
     *
     * ยังเก็บชื่อไว้เหมือนเดิม เพราะลายเซ็นหลายคนอ่านไม่ออกว่าเป็นใคร
     * และรายงานกับหน้าจอต้องแสดงชื่อที่ค้นหาได้
     */
    function acknowledge() {
        var suggest = openDoc.receiver_name || openDoc.requester_name || openDoc.requester || '';

        var run = function (name, sign) {
            if (name === null || String(name).trim() === '') { return; }
            post({
                action: 'acknowledge', csrf_token: CFG.csrfToken,
                doc_id: openDoc.doc_id,
                receiver_name: String(name).trim(),
                receiver_sign: sign || ''
            }, function (res) { ok(res.message); closeDetail(); load(); }, bad);
        };

        if (!window.Swal || !window.SignPad) {
            run(window.prompt('ชื่อผู้รับของ', suggest), '');
            return;
        }

        var pad = null;

        window.Swal.fire({
            title: 'เซ็นรับของ ' + openDoc.doc_no,
            html:
                  '<p class="ack-lead">ยืนยันว่าได้รับของครบตามใบจ่ายนี้แล้ว</p>'
                + '<label class="ack-label" for="ackName">ชื่อผู้รับของ</label>'
                + '<input type="text" id="ackName" class="swal2-input ack-input"'
                + ' placeholder="ชื่อ-นามสกุลผู้รับ" value="' + escapeHtml(suggest) + '">'
                + '<div class="ack-sign-head">'
                + '<span class="ack-label">ลายเซ็น</span>'
                + '<button type="button" id="ackClear" class="ack-clear">ล้างลายเซ็น</button>'
                + '</div>'
                + '<div class="ack-pad"><canvas id="ackCanvas"></canvas>'
                + '<span class="ack-pad-hint">เซ็นตรงนี้</span></div>',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันรับของ',
            cancelButtonText:  'ยกเลิก',
            reverseButtons: true,
            buttonsStyling: false,
            heightAuto: false,
            width: '32rem',
            customClass: {
                popup: 'app-swal ack-swal',
                confirmButton: 'app-swal-confirm',
                cancelButton:  'app-swal-cancel'
            },

            didOpen: function () {
                pad = window.SignPad.create(document.getElementById('ackCanvas'));

                document.getElementById('ackClear').addEventListener('click', function () {
                    pad.clear();
                });

                /* ผืนเซ็นซ่อนคำใบ้ทันทีที่เริ่มลากเส้น ไม่งั้นข้อความจะทับลายเซ็น */
                pad.onStart(function () {
                    var hint = document.querySelector('.ack-pad-hint');
                    if (hint) { hint.hidden = true; }
                });
            },

            preConfirm: function () {
                var name = String(document.getElementById('ackName').value || '').trim();

                if (name === '') {
                    window.Swal.showValidationMessage('กรุณากรอกชื่อผู้รับของ');
                    return false;
                }
                if (pad.isEmpty()) {
                    window.Swal.showValidationMessage('กรุณาเซ็นชื่อในกรอบด้านล่าง');
                    return false;
                }
                return { name: name, sign: pad.toDataURL() };
            }
        }).then(function (r) {
            if (r.isConfirmed && r.value) { run(r.value.name, r.value.sign); }
        });
    }

    /* ---------- ยกเลิกเอกสาร ---------- */
    function cancelDoc() {
        var warn = {
            receive: 'ยอดที่รับเข้าไปจะถูกดึงออกจากสต๊อก ถ้าของถูกเบิกไปแล้วจะยกเลิกไม่ได้',
            request: 'ใบเบิกนี้จะถูกปิด ผู้ขอต้องทำใบใหม่ถ้ายังต้องการของ',
            issue:   'ของจะถูกคืนเข้าสต๊อก และยอดค้างจ่ายจะกลับไปที่ใบเบิก'
        }[openDoc.doc_type];

        askReason({
            title:    'ยกเลิก' + openDoc.doc_type_label + ' ' + openDoc.doc_no,
            text:     warn,
            required: true,
            danger:   true,
            confirm:  'ยืนยันยกเลิกเอกสาร'
        }, function (reason) {
            post({
                action: 'cancel', csrf_token: CFG.csrfToken,
                doc_id: openDoc.doc_id, reason: reason
            }, function (res) { ok(res.message); closeDetail(); load(); }, bad);
        });
    }

    /* ---------- กล่องถามเหตุผล ---------- */
    function askReason(opts, done) {
        if (!window.Swal) {
            var r = window.prompt(opts.title + '\n' + opts.text, '');
            if (r === null) { return; }
            if (opts.required && r.trim() === '') { bad('กรุณาระบุเหตุผล'); return; }
            done(r.trim());
            return;
        }

        window.Swal.fire({
            title: opts.title,
            text:  opts.text,
            input: 'textarea',
            inputPlaceholder: opts.required ? 'ระบุเหตุผล (จำเป็น)' : 'หมายเหตุ (ไม่บังคับ)',
            inputAttributes: { 'aria-label': 'เหตุผล' },
            icon: opts.danger ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: opts.confirm,
            cancelButtonText:  'ยกเลิก',
            focusCancel: !!opts.danger,
            reverseButtons: true,
            buttonsStyling: false,
            heightAuto: false,
            customClass: {
                popup: 'app-swal',
                confirmButton: opts.danger ? 'app-swal-danger' : 'app-swal-confirm',
                cancelButton:  'app-swal-cancel'
            },
            inputValidator: function (v) {
                if (opts.required && (!v || v.trim() === '')) { return 'กรุณาระบุเหตุผล'; }
                return undefined;
            }
        }).then(function (r) {
            if (r.isConfirmed) { done((r.value || '').trim()); }
        });
    }


    /* ============================================================
     *  เริ่มทำงาน
     * ============================================================ */
    function init() {
        var ids = ['filterStatus', 'dateFrom', 'dateTo', 'onlyMine'];
        for (var i = 0; i < ids.length; i++) {
            if (el(ids[i])) { el(ids[i]).addEventListener('change', load); }
        }
        if (el('btnReload')) { el('btnReload').addEventListener('click', load); }

        if (el('gridSearch')) {
            el('gridSearch').addEventListener('input', function () {
                var v = this.value.trim();
                if (!table) { return; }
                if (v === '') { table.clearFilter(true); }
                else {
                    table.setFilter([
                        [{ field: 'doc_no',          type: 'like', value: v },
                         { field: 'note',            type: 'like', value: v },
                         { field: 'department_name', type: 'like', value: v },
                         { field: 'requester_name',  type: 'like', value: v }]
                    ]);
                }
                refreshCount(table.getDataCount('active'));
            });
        }

        /* ---------- ย้ายกล่องรายละเอียดไปไว้ใต้ <body> ----------
         * ในหน้าเว็บ กล่องนี้ถูกเขียนไว้ใน #appMain ซึ่งตั้ง overflow: hidden
         * ผลคือกล่องถูกจำกัดอยู่แค่กรอบเนื้อหา ทับแถบบนกับเมนูข้างไม่ได้
         * ถึงจะตั้ง z-index สูงกว่าก็ไม่ช่วย เพราะเป็นเรื่องการตัดขอบ ไม่ใช่ลำดับชั้น
         *
         * ย้ายมาเป็นลูกของ body ตอนเริ่มทำงาน กล่องจึงคลุมได้ทั้งหน้าต่าง
         * (SweetAlert2 ก็ใช้วิธีเดียวกัน จึงไม่เคยเจอปัญหานี้)
         *
         * ทำใน JavaScript ไม่ใช่ย้ายใน HTML เพราะไฟล์หน้าถูก include
         * เข้ามากลาง #appMain โดย main.php จะเขียนไว้นอกกรอบไม่ได้
         */
        var panel = el('docPanel');
        if (panel && panel.parentElement !== document.body) {
            document.body.appendChild(panel);
        }

        el('btnCloseDoc').addEventListener('click', closeDetail);
        el('docPanel').addEventListener('click', function (e) {
            if (e.target === el('docPanel')) { closeDetail(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && el('docPanel') && !el('docPanel').hasAttribute('hidden')) {
                closeDetail();
            }
        });

        el('docActions').addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-act]') : null;
            if (btn) { runAction(btn.getAttribute('data-act')); }
        });

        bindRowActions();
        load();
    }

    /* ============================================================
     *  แถวตัวอย่างสำหรับคู่มือการใช้งาน
     * ============================================================
     *  คู่มือหน้านี้ต้องชี้ไปที่ไอคอนในคอลัมน์จัดการทีละอัน แล้วอธิบายว่าอันไหนทำอะไร
     *  แต่ไอคอนที่โผล่ขึ้นมาขึ้นกับสถานะของเอกสารในแถวนั้น
     *
     *  ใบที่ยกเลิกไปแล้วมีแค่สองไอคอน ส่วนใบที่รอเซ็นรับมีเจ็ด
     *  ถ้าไปชี้แถวจริงที่บังเอิญอยู่บนสุด คู่มืออาจข้ามไอคอนที่กำลังจะอธิบายไปเลย
     *
     *  แถวตัวอย่างจึงถูกยัดไว้บนสุดด้วยสถานะที่ทำให้ไอคอนขึ้นครบทุกอัน
     *  คู่มือจะได้ชี้ได้ทุกขั้นเสมอ ไม่ว่าตอนนั้นในระบบมีเอกสารอะไรอยู่บ้าง
     *
     *  ---------------------------------------------------------------
     *  แถวนี้กดใช้งานจริงไม่ได้
     *  ---------------------------------------------------------------
     *  ตั้งธง _demo ไว้ แล้ว bindRowActions() ดักไว้ก่อนถึงคำสั่งจริงทุกอัน
     *  ต่อให้กดปุ่มไหนก็จะได้ข้อความอธิบายแทน ไม่มีคำขอวิ่งไปหาเซิร์ฟเวอร์
     * ============================================================ */
    var demoOn = false;

    function demoRow() {
        var today = CFG.today || '';

        var base = {
            doc_id:          -1,
            _demo:           true,
            doc_type:        TYPE,
            doc_type_label:  { receive: 'ใบรับเข้า', request: 'ใบเบิก', issue: 'ใบจ่าย' }[TYPE] || 'เอกสาร',
            doc_no:          'ตัวอย่าง-0000',
            doc_date:        today,
            department_id:   0,
            department_name: 'หน่วยงานตัวอย่าง',
            requester:       '__demo__',
            requester_name:  'ชื่อผู้ขอเบิกตัวอย่าง',
            ref_doc_id:      0,
            ref_doc_no:      (TYPE === 'issue') ? 'ตัวอย่าง-RQ' : '',
            note:            'แถวนี้มีไว้สาธิตคู่มือเท่านั้น',
            approved_by:     '', approved_at: '', approve_note: '',
            received_by:     '', received_at: '', receiver_name: '',
            cancel_note:     '', created_at: '', created_by: '__demo__',
            line_count:      2,
            total_qty:       15,
            total_cost:      1312
        };

        /* เลือกสถานะที่ทำให้ไอคอนขึ้นครบที่สุดของเอกสารแต่ละประเภท
           ใบเบิก: รออนุมัติ = มีอนุมัติ/ไม่อนุมัติ/ยกเลิก/เมล
           ใบจ่าย: จ่ายแล้ว  = มีลิงก์เซ็นรับ/เปิดหน้าเซ็น/เซ็นรับ/ยกเลิก/เมล
           ใบรับเข้า: รับของแล้ว = มียกเลิก */
        base.status = { receive: 'received', request: 'waiting', issue: 'issued' }[TYPE];
        base.status_label = { receive: 'รับของแล้ว', request: 'รออนุมัติ', issue: 'จ่ายแล้ว' }[TYPE];

        return base;
    }

    function demoSetup() {
        if (demoOn || !table) { return false; }
        demoOn = true;

        table.addRow(demoRow(), true);   // true = บนสุด คู่มือจะได้ชี้แถวแรกเสมอ
        demoBanner(true);

        if (typeof window.renderIcons === 'function') { window.renderIcons(); }
        return true;
    }

    function demoTeardown() {
        if (!demoOn) { return; }
        demoOn = false;

        var row = table ? table.getRow(-1) : null;
        if (row) { row.delete(); }

        demoBanner(false);
    }

    function demoBanner(on) {
        var old = el('dlDemoNote');
        if (old && old.parentNode) { old.parentNode.removeChild(old); }
        if (!on) { return; }

        var body = el('listBody');
        if (!body || !body.parentNode) { return; }

        var note = document.createElement('p');
        note.id        = 'dlDemoNote';
        note.className = 'pd-demo-note';
        note.innerHTML = '<i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>'
                       + '<span><b>แถวบนสุดเป็นตัวอย่างสำหรับสาธิตเท่านั้น</b> '
                       + 'ไม่ใช่เอกสารจริง กดปุ่มในแถวนั้นจะไม่เกิดอะไรขึ้น — ปิดคู่มือแล้วจะหายไปเอง</span>';

        body.parentNode.insertBefore(note, body);
    }

    window.GuideDemo = {
        page:     { receive: 'receive_history', request: 'request_list', issue: 'issue_history' }[TYPE],
        setup:    demoSetup,
        teardown: demoTeardown
    };


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
