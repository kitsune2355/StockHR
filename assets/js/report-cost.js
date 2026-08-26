/* ============================================================
 *  report-cost.js — รายงานค่าใช้จ่ายรายเดือน (ส่งฝ่ายบัญชี)
 *
 *  หน้านี้เป็นเอกสารที่จะส่งต่อ ไม่ใช่ตารางไว้เล่นกับข้อมูล
 *  จึงวาดเป็นตาราง HTML ธรรมดา ไม่โหลด Tabulator (ประหยัด 430 KB)
 *  แลกกับการที่เรียง/กรองในหน้าไม่ได้ ซึ่งรายงานปิดเดือนไม่ต้องใช้อยู่แล้ว
 *
 *  ส่งออก Excel เป็นไฟล์เดียว 4 ชีต — บัญชีเปิดแล้วได้ครบทุกมุมมอง
 *  ไม่ต้องกดส่งออกทีละตาราง แล้วไปรวมไฟล์เอง
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.REPORTCOST;
    if (!CFG) { return; }

    var DATA = null;
    var DRILL = null;

    function el(id) { return document.getElementById(id); }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }

    function esc(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    /* เงินใช้ทศนิยม 2 ตำแหน่งเสมอ แม้ลงท้ายด้วย .00
       เพราะบัญชีอ่านคอลัมน์ตัวเลขแล้วต้องเรียงจุดทศนิยมตรงกันทุกบรรทัด */
    function money(v) {
        var n = Number(v);
        if (isNaN(n)) { n = 0; }
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* จำนวนนับไม่ต้องมี .00 ห้อยท้าย ให้ตรงกับ stock_format() ฝั่ง PHP */
    function qty(v) {
        var n = Number(v);
        if (isNaN(n)) { n = 0; }
        if (n === Math.floor(n)) { return n.toLocaleString('en-US'); }
        return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    /* วันที่แบบไทยใช้ตัวกลางที่ assets/js/thai-date.js
       เดิมไฟล์นี้มีตารางชื่อเดือนของตัวเอง ซึ่งเป็นชุดที่สามในระบบ */
    function thaiMonth(ym) {
        return window.ThaiDate ? window.ThaiDate.month(ym) : String(ym);
    }

    function dateTime(v) {
        return window.ThaiDate ? window.ThaiDate.dateTime(v, '') : String(v || '');
    }


    /* ============================================================
     *  วาดตาราง
     *
     *  cols = [{ key, title, align, fmt }]
     *  แถวรวมท้ายตารางคำนวณจากข้อมูลชุดเดียวกับที่แสดง
     *  ตัวเลขรวมจึงตรงกับที่ตาเห็นเสมอ ไม่ใช่มาจากคนละที่
     * ============================================================ */
    /* $opts (ไม่ใส่ก็ได้)
         groups    หัวชั้นบนแบบพาดหลายคอลัมน์ [{ title, span }]
         footLabel ป้ายของแถวรวม ถ้าไม่ใส่จะเป็น "รวม" */
    /* คอลัมน์ที่ตั้ง fix ไว้จะถูกตรึงไม่ให้เลื่อนหายไปทางซ้าย
       ตัวเลขคือลำดับที่ตรึง ซึ่ง CSS เอาไปคิดเป็นระยะ left ให้ (ดู .rc-fix-1/-2) */
    function fixCls(col) {
        return (col.align || '') + (col.fix ? ' rc-fix rc-fix-' + col.fix : '');
    }

    function drawTable(node, cols, rows, totalOf, drill, opts) {
        opts = opts || {};
        if (!rows || rows.length === 0) {
            node.innerHTML = '<tbody><tr><td class="rc-empty">ไม่มีรายการในเดือนนี้</td></tr></tbody>';
            return;
        }

        var head = '<thead>';

        /* หัวชั้นบน — ใช้กับตารางที่คอลัมน์กลางเป็นชุดเดียวกัน (เช่นรายหน่วยงาน)
           ถ้าไม่มีก็ข้ามไป ตารางอื่นในหน้านี้ยังเป็นหัวชั้นเดียวเหมือนเดิม */
        if (opts.groups && opts.groups.length > 0) {
            head += '<tr>';
            for (var gi = 0; gi < opts.groups.length; gi++) {
                var gg = opts.groups[gi];
                head += '<th colspan="' + gg.span + '" class="rc-grouphead'
                     +  (gg.fix ? ' rc-fix rc-fix-' + gg.fix : '') + '">'
                     +  esc(gg.title || '') + '</th>';
            }
            head += '</tr>';
        }

        head += '<tr>';
        for (var c = 0; c < cols.length; c++) {
            head += '<th class="' + fixCls(cols[c]) + '"'
                 +  (cols[c].note ? ' title="' + esc(cols[c].note) + '"' : '') + '>'
                 +  esc(cols[c].title)
                 +  (cols[c].note ? '<small class="rc-colnote">' + esc(cols[c].note) + '</small>' : '')
                 +  '</th>';
        }
        head += '</tr></thead>';

        var body = '<tbody>';
        var totals = {};

        for (var i = 0; i < rows.length; i++) {

            /* แถวที่กดดูที่มาได้ ติดข้อมูลตัวกรองไว้กับแถวเลย
               เก็บเป็น data-* บน <tr> ไม่ใช่ผูก closure ไว้ทีละแถว
               เพราะตารางถูกวาดใหม่ทั้งก้อนทุกครั้งที่เปลี่ยนเดือน */
            var attrs = '';
            if (drill) {
                var f = drill(rows[i]);
                if (f) {
                    attrs = ' class="rc-drill" tabindex="0" role="button"'
                          + ' title="กดเพื่อดูว่ายอดนี้มาจากเอกสารใบไหนบ้าง"'
                          + ' data-drill="' + esc(JSON.stringify(f)) + '"';
                }
            }

            body += '<tr' + attrs + '>';
            for (var j = 0; j < cols.length; j++) {
                var col = cols[j];
                var raw = rows[i][col.key];
                var txt = col.fmt ? col.fmt(raw, rows[i]) : esc(raw);
                body += '<td class="' + fixCls(col) + '">' + txt + '</td>';

                if (totalOf && totalOf.indexOf(col.key) >= 0) {
                    totals[col.key] = (totals[col.key] || 0) + Number(raw || 0);
                }
            }
            body += '</tr>';
        }
        body += '</tbody>';

        var foot = '';
        if (totalOf && totalOf.length > 0) {
            foot = '<tfoot><tr>';
            for (var k = 0; k < cols.length; k++) {
                var cc = cols[k];
                var fc = cc.fix ? ' rc-fix rc-fix-' + cc.fix : '';
                if (k === 0) {
                    foot += '<td class="rc-sum-label' + fc + '">' + esc(opts.footLabel || 'รวม') + '</td>';
                } else if (totalOf.indexOf(cc.key) >= 0) {
                    foot += '<td class="num' + fc + '">' + (cc.key === 'qty' ? qty(totals[cc.key]) : money(totals[cc.key])) + '</td>';
                } else {
                    foot += '<td class="' + fc.replace(/^ /, '') + '"></td>';
                }
            }
            foot += '</tr></tfoot>';
        }

        node.innerHTML = head + body + foot;
    }


    /* ---------- โครงคอลัมน์ของตารางสรุปหมวด x หน่วยงาน ----------
     * คอลัมน์กลางเป็นหน่วยงานที่เบิกจริงในเดือนนั้น จำนวนจึงไม่คงที่
     * สร้างจากข้อมูลทุกครั้งที่เปลี่ยนเดือน ใช้ร่วมกันทั้งบนจอและตอนส่งออก
     */
    function catDeptCols(cd) {
        /* ไม่มีคอลัมน์ลำดับ — หมวดหมู่หลักมีอยู่ไม่กี่หมวดและมีชื่อกำกับอยู่แล้ว
           เลขลำดับจึงไม่ได้ช่วยอ้างอิงอะไร มีแต่กินที่ที่ควรเป็นของคอลัมน์ที่ตรึง

           สองคอลัมน์แรกตรึงไว้ เลื่อนดูหน่วยงานที่อยู่ขวาสุดแค่ไหน
           ก็ยังเห็นว่ากำลังดูหมวดไหนและหมวดนั้นซื้อเข้ามาเท่าไร
           ซึ่งเป็นสองตัวเลขที่ต้องใช้เทียบกับยอดเบิกตลอดเวลา */
        var cols = [
            { key: 'category_name', title: 'รายการ', fix: 1 },
            { key: 'buy',  title: 'ยอดรวมสั่งซื้อทั้งหมด', align: 'num', fix: 2,
              fmt: function (v) { return v ? money(v) : '<span class="grid-empty">-</span>'; } }
        ];

        for (var i = 0; i < cd.departments.length; i++) {
            cols.push(deptCol(cd.departments[i]));
        }

        cols.push({ key: 'out', title: 'รวมเบิกทั้งหมด', align: 'num', fix: 'right',
                    fmt: function (v) { return v ? '<b>' + money(v) + '</b>'
                                                 : '<span class="grid-empty">-</span>'; } });
        return cols;
    }

    /* ปิด id ของหน่วยงานไว้ใน closure ทีละตัว ไม่ใช้ตัวแปรวนซ้ำร่วมกัน
       ไม่งั้นทุกคอลัมน์จะไปอ่านค่าของหน่วยงานสุดท้ายเหมือนกันหมด */
    function deptCol(d) {
        return {
            key:   'd' + d.id,
            title: String(d.name || '').replace(/^\s+|\s+$/g, ''),
            note:  d.company,
            align: 'num',
            fmt:   function (v) { return v ? money(v) : '<span class="grid-empty">-</span>'; }
        };
    }

    /* แบนข้อมูลหน่วยงานออกมาเป็นคีย์ระดับบนสุด drawTable จะได้อ่านตรง ๆ */
    function catDeptRows(cd) {
        var out = [];
        for (var i = 0; i < cd.rows.length; i++) {
            var r = cd.rows[i];
            var one = { category_name: r.category_name, buy: r.buy, out: r.out };
            for (var k in r.dept) {
                if (r.dept.hasOwnProperty(k)) { one[k] = r.dept[k]; }
            }
            out.push(one);
        }
        return out;
    }

    function catDeptTotalOf(cd) {
        var keys = ['buy'];
        for (var i = 0; i < cd.departments.length; i++) { keys.push('d' + cd.departments[i].id); }
        keys.push('out');
        return keys;
    }

    function render(res) {
        DATA = res;

        el('rcMonthText').textContent = thaiMonth(res.range.month);

        var s = res.summary;
        el('sumOut').textContent    = money(s.total_out);
        el('sumQtyOut').textContent = qty(s.qty_out);
        el('sumDept').textContent   = qty(s.dept_count);
        el('sumIn').textContent     = money(s.total_in);
        el('sumQtyIn').textContent  = qty(s.qty_in);
        el('sumStock').textContent  = money(s.stock_value);

        /* ---------- สรุปหมวดหมู่ x หน่วยงาน ----------
           ใบเดียวที่ตอบได้ทั้ง "เดือนนี้ซื้อของกลุ่มไหนไปเท่าไร" และ
           "แต่ละหน่วยงานเบิกของกลุ่มนั้นไปคนละเท่าไร" ซึ่งเป็นสองคำถามที่
           บัญชีถามคู่กันเสมอ แต่เดิมต้องเปิดสองตารางแล้วไล่จับคู่เอง */
        var cd = res.byCatDept;
        drawTable(el('tblCatDept'), catDeptCols(cd), catDeptRows(cd), catDeptTotalOf(cd), null, {
            footLabel: 'ยอดรวมแต่ละหน่วยงาน',
            /* สองช่องแรกแยกเป็นเซลล์ละคอลัมน์ ไม่รวบเป็น colspan เดียว
               เพราะเซลล์ที่ตรึงต้องมีความกว้างตรงกับคอลัมน์ที่มันตรึงอยู่
               ถ้ารวบไว้ หัวกลุ่มของหน่วยงานจะมุดเข้าไปใต้คอลัมน์ที่ตรึงตอนเลื่อน */
            groups: [
                { title: '', span: 1, fix: 1 },
                { title: '', span: 1, fix: 2 },
                { title: 'ยอดรวมการเบิกใช้ของแต่ละหน่วยงาน', span: cd.departments.length },
                { title: '', span: 1, fix: 'right' }
            ]
        });

        /* ---------- 0) บริษัท ----------
           แต่ละบริษัทปิดงบแยกกัน บัญชีจึงต้องได้ยอดรายบริษัทโดยไม่ต้องนั่งบวก
           จากตารางรายหน่วยงานเอง */
        drawTable(el('tblComp'), [
            { key: 'name',   title: 'บริษัท' },
            { key: 'depts',  title: 'หน่วยงาน', align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'docs',   title: 'ใบจ่าย',   align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'qty',    title: 'จำนวน',    align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'amount', title: 'เป็นเงิน (บาท)', align: 'num money', fmt: function (v) { return money(v); } }
        ], res.byComp, ['qty', 'amount'], function (r) {
            return { company_id: r.company_id, label: r.name };
        });

        /* ---------- 1) หน่วยงาน ---------- */
        drawTable(el('tblDept'), [
            { key: 'name',    title: 'หน่วยงาน' },
            { key: 'company', title: 'บริษัท' },
            { key: 'docs',    title: 'ใบจ่าย', align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'qty',     title: 'จำนวน',  align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'amount',  title: 'เป็นเงิน (บาท)', align: 'num money', fmt: function (v) { return money(v); } }
        ], res.byDept, ['qty', 'amount'], function (r) {
            return { department: r.name, label: 'หน่วยงาน ' + r.name };
        });

        /* ---------- 2) หมวดหมู่ ---------- */
        drawTable(el('tblCat'), [
            { key: 'name',     title: 'หมวดหมู่หลัก' },
            { key: 'sub_name', title: 'หมวดหมู่ย่อย' },
            { key: 'qty',      title: 'จำนวน', align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'amount',   title: 'เป็นเงิน (บาท)', align: 'num money', fmt: function (v) { return money(v); } }
        ], res.byCat, ['qty', 'amount']);

        /* ---------- 3) รับเข้า ---------- */
        drawTable(el('tblRecv'), [
            { key: 'code',   title: 'รหัสสินค้า', fmt: function (v) { return '<span class="rc-code">' + esc(v) + '</span>'; } },
            { key: 'name',   title: 'ชื่อสินค้า' },
            { key: 'unit',   title: 'หน่วย' },
            { key: 'price',  title: 'ราคา/หน่วย', align: 'num money', fmt: function (v) { return money(v); } },
            { key: 'qty',    title: 'จำนวน', align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'amount', title: 'เป็นเงิน (บาท)', align: 'num money', fmt: function (v) { return money(v); } }
        ], res.receipts, ['qty', 'amount']);

        /* ---------- 4) รายละเอียด ---------- */
        drawTable(el('tblDetail'), [
            { key: 'dept',   title: 'หน่วยงาน' },
            { key: 'code',   title: 'รหัสสินค้า', fmt: function (v) { return '<span class="rc-code">' + esc(v) + '</span>'; } },
            { key: 'name',   title: 'ชื่อสินค้า' },
            { key: 'unit',   title: 'หน่วย' },
            { key: 'qty',    title: 'จำนวน', align: 'num', fmt: function (v) { return qty(v); } },
            { key: 'amount', title: 'เป็นเงิน (บาท)', align: 'num money', fmt: function (v) { return money(v); } }
        ], res.detail, ['qty', 'amount'], function (r) {
            return { department: r.dept, product_code: r.code,
                     label: r.code + ' ' + r.name + ' — ' + r.dept };
        });

        setHidden(el('rcLoading'), true);
        setHidden(el('rcBody'), false);
        if (window.lucide) { window.lucide.createIcons(); }
    }


    /* ============================================================
     *  เจาะดูที่มาของยอด
     *
     *  กดที่บรรทัดแล้วถามต่อว่ายอดนี้มาจากเอกสารใบไหนบ้าง
     *  ตอบด้วยการเคลื่อนไหวรายครั้ง ซึ่งเป็นข้อมูลดิบที่สุดที่ระบบมี
     *  ต่อกันเป็นสายจนถึงเลขที่เอกสาร จึงตามไปเปิดใบจริงต่อได้
     * ============================================================ */
    function openDrill(filter) {
        var panel = el('rcDrill');
        if (!panel) { return; }

        DRILL = null;
        setDrillExportBusy(false);
        el('rcDrillExport').disabled = true;
        el('rcDrillTitle').textContent = filter.label || 'รายละเอียด';
        el('rcDrillBody').innerHTML =
            '<p class="rc-drill-empty">กำลังโหลด…</p>';

        setHidden(el('rcDrillBackdrop'), false);
        setHidden(panel, false);

        post({
            action:       'cost_drill',
            /* ใช้เดือนที่ข้อมูลบนจอเป็นของจริง ไม่ใช่ CFG.month ซึ่งเป็นค่าตอนเปิดหน้า
               ถ้าผู้ใช้เปลี่ยนเดือนแล้วค่อยกดดูรายละเอียด สองอย่างจะไม่ตรงกัน */
            month:        (DATA && DATA.range) ? DATA.range.month : CFG.month,
            company_id:   (filter.company_id !== undefined && filter.company_id !== null) ? filter.company_id : '',
            department:   filter.department   || '',
            product_code: filter.product_code || ''
        }, function (res) {
            renderDrill(res);
        }, function (message) {
            el('rcDrillBody').innerHTML =
                '<p class="rc-drill-empty is-bad">' + esc(message) + '</p>';
        });
    }


    function renderDrill(res) {
        var rows = res.rows || [];

        DRILL = {
            label: el('rcDrillTitle').textContent || 'รายละเอียด',
            month: (DATA && DATA.range) ? DATA.range.month : CFG.month,
            rows: rows,
            total: Number(res.total || 0),
            qty: Number(res.qty || 0),
            capped: !!res.capped
        };
        el('rcDrillExport').disabled = rows.length === 0;

        el('rcDrillSum').textContent =
            money(res.total) + ' บาท · ' + qty(res.qty) + ' หน่วย · ' + rows.length + ' รายการ';

        if (rows.length === 0) {
            el('rcDrillBody').innerHTML =
                '<p class="rc-drill-empty">ไม่พบการเคลื่อนไหวที่ตรงกับเงื่อนไขนี้</p>';
            return;
        }

        var html = '<table class="rc-table"><thead><tr>'
                 + '<th>วันที่</th><th>เลขที่ใบจ่าย</th><th>หน่วยงาน</th>'
                 + '<th>สินค้า</th><th class="num">จำนวน</th>'
                 + '<th class="num money">ราคา/หน่วย</th><th class="num money">เป็นเงิน</th>'
                 + '<th>ผู้ขอเบิก</th>'
                 + '</tr></thead><tbody>';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];

            html += '<tr' + (r.is_cancel ? ' class="is-cancel"' : '') + '>'
                  + '<td>' + esc(dateTime(r.move_date)) + '</td>'
                  + '<td>' + (r.doc_id > 0
                      ? '<a href="main.php?page=issue_history&doc=' + r.doc_id + '" class="rc-doc-link">'
                        + esc(r.doc_no) + '</a>'
                      : esc(r.doc_no)) + '</td>'
                  + '<td>' + esc(r.dept) + '</td>'
                  + '<td><span class="rc-code">' + esc(r.code) + '</span> ' + esc(r.name) + '</td>'
                  + '<td class="num">' + qty(r.qty) + ' ' + esc(r.unit) + '</td>'
                  + '<td class="num money">' + money(r.price) + '</td>'
                  + '<td class="num money">' + money(r.amount) + '</td>'
                  + '<td>' + esc(r.requester) + '</td>'
                  + '</tr>';
        }
        html += '</tbody></table>';

        /* ยอดติดลบมาจากการยกเลิกใบจ่าย ซึ่งคืนของกลับเข้าคลัง
           ไม่บอกไว้ คนอ่านจะคิดว่าตัวเลขคำนวณผิด */
        var hasCancel = false;
        for (var k = 0; k < rows.length; k++) { if (rows[k].is_cancel) { hasCancel = true; } }
        if (hasCancel) {
            html += '<p class="rc-drill-note">แถวสีจางคือรายการที่เกิดจากการยกเลิกใบจ่าย '
                  + 'ซึ่งคืนของกลับเข้าคลัง จึงหักออกจากยอดรวม</p>';
        }

        if (res.capped) {
            html += '<p class="rc-drill-note is-bad">แสดงได้สูงสุด 500 รายการ '
                  + 'ยอดรวมด้านบนจึงนับเฉพาะที่แสดงอยู่ ไม่ใช่ทั้งเดือน</p>';
        }

        el('rcDrillBody').innerHTML = html;
    }


    function closeDrill() {
        setHidden(el('rcDrill'), true);
        setHidden(el('rcDrillBackdrop'), true);
    }

    function setDrillExportBusy(on) {
        var btn = el('rcDrillExport');
        if (!btn) { return; }
        btn.disabled = on;
        setHidden(btn.querySelector('.rc-drill-export-icon'), on);
        setHidden(btn.querySelector('.rc-drill-export-spinner'), !on);
    }

    function exportDrillExcel() {
        if (!DRILL || !DRILL.rows || DRILL.rows.length === 0) { return; }

        setDrillExportBusy(true);
        var go = function () {
            try {
                var aoa = [
                    [DRILL.label],
                    ['เดือน', DRILL.month],
                    ['รวมเงิน', DRILL.total],
                    ['รวมจำนวน', DRILL.qty],
                    []
                ];
                aoa.push(['วันที่', 'เลขที่ใบจ่าย', 'หน่วยงาน', 'รหัสสินค้า', 'สินค้า', 'หน่วย', 'จำนวน', 'ราคา/หน่วย', 'เป็นเงิน', 'ผู้ขอเบิก', 'สถานะ']);

                for (var i = 0; i < DRILL.rows.length; i++) {
                    var r = DRILL.rows[i];
                    aoa.push([
                        dateTime(r.move_date), r.doc_no || '', r.dept || '', r.code || '', r.name || '', r.unit || '',
                        Number(r.qty || 0), Number(r.price || 0), Number(r.amount || 0), r.requester || '',
                        r.is_cancel ? 'ยกเลิกใบจ่าย' : 'ปกติ'
                    ]);
                }
                if (DRILL.capped) { aoa.push([]); aoa.push(['หมายเหตุ: ไฟล์นี้มีเฉพาะ 500 รายการที่แสดงในหน้าต่างรายละเอียด']); }

                var ws = XLSX.utils.aoa_to_sheet(aoa);
                ws['!cols'] = [{ wch: 18 }, { wch: 18 }, { wch: 24 }, { wch: 16 }, { wch: 38 }, { wch: 12 }, { wch: 12 }, { wch: 15 }, { wch: 15 }, { wch: 24 }, { wch: 18 }];
                var wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'รายละเอียด');
                XLSX.writeFile(wb, 'รายละเอียดค่าใช้จ่าย_' + DRILL.month + '.xlsx');
                if (typeof window.appSuccess === 'function') { window.appSuccess('ส่งออกรายละเอียด ' + DRILL.rows.length + ' รายการเป็น Excel เรียบร้อย'); }
            } catch (err) {
                if (typeof window.appError === 'function') { window.appError('สร้างไฟล์ Excel ไม่สำเร็จ'); }
            }
            setDrillExportBusy(false);
            if (DRILL && DRILL.rows.length > 0) { el('rcDrillExport').disabled = false; }
        };

        if (window.XLSX) { go(); return; }
        var s = document.createElement('script');
        s.src = (window.APP_LIB && window.APP_LIB.xlsx) ? window.APP_LIB.xlsx : 'assets/js/xlsx.mini.min.js';
        s.onload = go;
        s.onerror = function () {
            setDrillExportBusy(false);
            if (DRILL && DRILL.rows.length > 0) { el('rcDrillExport').disabled = false; }
            if (typeof window.appError === 'function') { window.appError('โหลดตัวช่วยสร้างไฟล์ Excel ไม่สำเร็จ'); }
        };
        document.head.appendChild(s);
    }


    /* ---------- ยิงคำสั่งไปที่ API ----------
     * หน้านี้ใช้ XMLHttpRequest ไม่ใช่ fetch มาตั้งแต่แรก จึงเขียนตัวช่วยให้เข้าชุดกัน
     * ตัวโหลดรายงานหลักยังใช้โค้ดเดิมของมัน เพราะมีการจัดการหน้าจอเฉพาะตัว
     */
    function post(payload, ok, bad) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

            if (!res || !res.ok) {
                bad((res && res.message) ? res.message : 'ดึงข้อมูลไม่สำเร็จ');
                return;
            }
            ok(res);
        };
        xhr.onerror = function () { bad('ติดต่อเซิร์ฟเวอร์ไม่ได้'); };
        xhr.send(JSON.stringify(payload));
    }


    /* ============================================================
     *  โหลดข้อมูล
     * ============================================================ */
    function load(month) {
        setHidden(el('rcBody'), true);
        setHidden(el('rcLoading'), false);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

            if (!res || !res.ok) {
                setHidden(el('rcLoading'), true);
                var msg = (res && res.message) ? res.message : 'ดึงข้อมูลรายงานไม่สำเร็จ';
                if (typeof window.appError === 'function') { window.appError(msg); }
                return;
            }
            render(res);
        };
        xhr.onerror = function () {
            setHidden(el('rcLoading'), true);
            if (typeof window.appError === 'function') { window.appError('ติดต่อเซิร์ฟเวอร์ไม่ได้'); }
        };
        xhr.send(JSON.stringify({ action: 'cost', month: month }));
    }


    /* ============================================================
     *  ส่งออก Excel — ไฟล์เดียว 4 ชีต
     *  โหลด SheetJS ตอนกดเท่านั้น เพราะหนัก 273 KB และคนส่วนใหญ่แค่เปิดดู
     * ============================================================ */
    function busy(on) {
        var btn = el('btnExport');
        btn.disabled = on;
        setHidden(btn.querySelector('.btn-icon'), on);
        setHidden(btn.querySelector('.btn-spinner'), !on);
    }

    function sheetFrom(cols, rows, totalOf, footLabel) {
        var aoa = [];
        var head = [];
        for (var c = 0; c < cols.length; c++) { head.push(cols[c].title); }
        aoa.push(head);

        var totals = {};
        for (var i = 0; i < rows.length; i++) {
            var line = [];
            for (var j = 0; j < cols.length; j++) {
                var key = cols[j].key;
                var v   = rows[i][key];
                /* ตัวเลขต้องส่งเป็น number ไม่ใช่ข้อความ ไม่งั้นบัญชีเอาไปรวมใน Excel ไม่ได้ */
                line.push(cols[j].numeric ? Number(v || 0) : String(v === null || v === undefined ? '' : v));
                if (totalOf && totalOf.indexOf(key) >= 0) {
                    totals[key] = (totals[key] || 0) + Number(v || 0);
                }
            }
            aoa.push(line);
        }

        if (totalOf && totalOf.length > 0 && rows.length > 0) {
            var sum = [];
            for (var k = 0; k < cols.length; k++) {
                if (k === 0) { sum.push(footLabel || 'รวม'); }
                else if (totalOf.indexOf(cols[k].key) >= 0) { sum.push(Number(totals[cols[k].key] || 0)); }
                else { sum.push(''); }
            }
            aoa.push(sum);
        }

        var ws = XLSX.utils.aoa_to_sheet(aoa);

        /* ความกว้างคอลัมน์ตามความยาวข้อความจริง ไม่งั้นชื่อสินค้ายาว ๆ โดนตัด */
        var widths = [];
        for (var w = 0; w < cols.length; w++) {
            var max = String(cols[w].title).length;
            for (var r = 1; r < aoa.length; r++) {
                var len = String(aoa[r][w]).length;
                if (len > max) { max = len; }
            }
            widths.push({ wch: Math.min(50, Math.max(10, max + 2)) });
        }
        ws['!cols'] = widths;

        return ws;
    }

    function exportExcel() {
        if (!DATA) { return; }
        busy(true);

        var go = function () {
            var wb = XLSX.utils.book_new();

            /* ---------- สรุปหมวดหมู่ x หน่วยงาน ----------
               คอลัมน์หน่วยงานไม่คงที่ จึงสร้างจากโครงเดียวกับที่วาดบนจอ
               ไฟล์ที่ได้จึงมีคอลัมน์เท่ากับที่ตาเห็นเสมอ */
            var cd = DATA.byCatDept;
            var cdCols = [
                { key: 'category_name', title: 'รายการ' },
                { key: 'buy', title: 'ยอดรวมสั่งซื้อทั้งหมด', numeric: true }
            ];
            for (var ci = 0; ci < cd.departments.length; ci++) {
                var d = cd.departments[ci];
                cdCols.push({
                    key: 'd' + d.id, numeric: true,
                    title: String(d.name || '').replace(/^s+|s+$/g, '')
                         + (d.company ? ' (' + d.company + ')' : '')
                });
            }
            cdCols.push({ key: 'out', title: 'รวมเบิกทั้งหมด', numeric: true });

            XLSX.utils.book_append_sheet(wb,
                sheetFrom(cdCols, catDeptRows(cd), catDeptTotalOf(cd), 'ยอดรวมแต่ละหน่วยงาน'),
                'สรุปหมวดและหน่วยงาน');

            XLSX.utils.book_append_sheet(wb, sheetFrom([
                { key: 'name', title: 'หน่วยงาน' }, { key: 'company', title: 'บริษัท' },
                { key: 'docs', title: 'ใบจ่าย', numeric: true },
                { key: 'qty', title: 'จำนวน', numeric: true },
                { key: 'amount', title: 'เป็นเงิน', numeric: true }
            ], DATA.byDept, ['qty', 'amount']), 'ตามหน่วยงาน');

            XLSX.utils.book_append_sheet(wb, sheetFrom([
                { key: 'name', title: 'หมวดหมู่หลัก' }, { key: 'sub_name', title: 'หมวดหมู่ย่อย' },
                { key: 'qty', title: 'จำนวน', numeric: true },
                { key: 'amount', title: 'เป็นเงิน', numeric: true }
            ], DATA.byCat, ['qty', 'amount']), 'ตามหมวดหมู่');

            XLSX.utils.book_append_sheet(wb, sheetFrom([
                { key: 'code', title: 'รหัสสินค้า' }, { key: 'name', title: 'ชื่อสินค้า' },
                { key: 'unit', title: 'หน่วย' },
                { key: 'price', title: 'ราคา/หน่วย', numeric: true },
                { key: 'qty', title: 'จำนวน', numeric: true },
                { key: 'amount', title: 'เป็นเงิน', numeric: true }
            ], DATA.receipts, ['qty', 'amount']), 'การรับเข้า');

            XLSX.utils.book_append_sheet(wb, sheetFrom([
                { key: 'dept', title: 'หน่วยงาน' }, { key: 'code', title: 'รหัสสินค้า' },
                { key: 'name', title: 'ชื่อสินค้า' }, { key: 'unit', title: 'หน่วย' },
                { key: 'qty', title: 'จำนวน', numeric: true },
                { key: 'amount', title: 'เป็นเงิน', numeric: true }
            ], DATA.detail, ['qty', 'amount']), 'รายละเอียด');

            XLSX.writeFile(wb, 'ค่าใช้จ่ายวัสดุ_' + DATA.range.month + '.xlsx');
            busy(false);
        };

        if (window.XLSX) { go(); return; }

        var s = document.createElement('script');
        s.src = 'assets/js/xlsx.mini.min.js';
        s.onload  = go;
        s.onerror = function () {
            busy(false);
            if (typeof window.appError === 'function') {
                window.appError('โหลดตัวช่วยสร้างไฟล์ Excel ไม่สำเร็จ');
            }
        };
        document.head.appendChild(s);
    }


    function init() {

        /* ---------- ย้ายกล่องเจาะรายละเอียดไปเป็นลูกของ <body> ----------
         * หน้านี้ถูก include อยู่ใน #appMain ซึ่งตั้ง overflow ไว้
         * กล่องที่เป็น position: fixed แต่อยู่ข้างในนั้นจะถูกตัดตามกรอบของ #appMain
         * ผลคือขอบซ้ายของกล่องหายเข้าไปใต้เมนูข้างจนหัวข้อโดนตัดครึ่งคำ
         *
         * z-index แก้ไม่ได้ เพราะเป็นการ "ตัด" ไม่ใช่การ "ทับ"
         * ต้องย้ายออกมาให้พ้นกรอบที่ตัด — วิธีเดียวกับกล่องรายละเอียดเอกสาร
         * ใน doc-list.js และแถบกรองใน filter-panel.js
         */
        var moveOut = ['rcDrill', 'rcDrillBackdrop'];
        for (var m = 0; m < moveOut.length; m++) {
            var node = el(moveOut[m]);
            if (node && node.parentElement !== document.body) {
                document.body.appendChild(node);
            }
        }

        el('rcMonth').addEventListener('change', function () {
            if (this.value) { load(this.value); }
        });
        el('btnPrint').addEventListener('click', function () { window.print(); });
        el('btnExport').addEventListener('click', exportExcel);

        /* ---------- กดบรรทัดเพื่อดูที่มา ----------
         * ผูกไว้ที่ตัวหน้าเพียงจุดเดียว ไม่ผูกทีละแถว เพราะตารางทุกตัว
         * ถูกวาดใหม่ทั้งก้อนทุกครั้งที่เปลี่ยนเดือน ถ้าผูกรายแถวจะต้องผูกใหม่ทุกครั้ง
         * และ listener เก่าจะค้างอยู่กับ DOM ที่ถูกทิ้งไปแล้ว
         */
        document.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('tr.rc-drill') : null;
            if (!row) { return; }

            try { openDrill(JSON.parse(row.getAttribute('data-drill'))); }
            catch (err) { /* แถวที่ข้อมูลเสีย ไม่ต้องทำอะไร ดีกว่าขึ้น error ขวางจอ */ }
        });

        /* กดด้วยแป้นพิมพ์ได้ด้วย — แถวถูกตั้ง tabindex ไว้แล้ว
           คนที่ใช้แป้นพิมพ์อย่างเดียวต้องเข้าถึงข้อมูลชุดเดียวกันได้ */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeDrill(); return; }
            if (e.key !== 'Enter' && e.key !== ' ') { return; }

            var row = e.target.closest ? e.target.closest('tr.rc-drill') : null;
            if (!row) { return; }

            e.preventDefault();
            try { openDrill(JSON.parse(row.getAttribute('data-drill'))); } catch (err) { }
        });

        if (el('rcDrillClose'))    { el('rcDrillClose').addEventListener('click', closeDrill); }
        if (el('rcDrillExport'))   { el('rcDrillExport').addEventListener('click', exportDrillExcel); }
        if (el('rcDrillBackdrop')) { el('rcDrillBackdrop').addEventListener('click', closeDrill); }

        load(CFG.month);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
