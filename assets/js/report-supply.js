/* ============================================================
 *  report-supply.js — รายละเอียดการสั่งซื้อ-เบิกใช้ ประจำเดือน
 *
 *  ตารางไขว้ที่อ่านเป็นแถวละสินค้า ไล่จากซ้ายไปขวาตามลำดับเวลาจริง
 *      ยกมา  +  ซื้อ  -  เบิก (กางเป็นคอลัมน์ตามหน่วยงาน)  =  คงเหลือ
 *
 *  วาดเป็นตาราง HTML เอง ไม่ใช้ Tabulator เหมือนหน้ารายงานอื่น เพราะ
 *  หัวตารางเป็นสองชั้น และจำนวนคอลัมน์เปลี่ยนไปตามหน่วยงานที่เบิกในเดือนนั้น
 *  (ดูเหตุผลเต็มที่หัวไฟล์ pages/report_supply.php)
 *
 *  โครงคอลัมน์สร้างครั้งเดียวจากข้อมูลที่ได้มา แล้วใช้ชุดเดียวกันทั้งการวาด
 *  บนจอและการส่งออก Excel — ไฟล์ที่ได้จึงหน้าตาเหมือนที่ตาเห็นเสมอ
 *  ถ้าแยกกันเขียนสองที่ วันหนึ่งจะเพิ่มคอลัมน์แล้วลืมแก้อีกฝั่ง
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.REPORTSUPPLY;
    if (!CFG) { return; }

    var DATA = null;

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

    /* เงินสองตำแหน่งเสมอ แม้ลงท้ายด้วย .00 — คอลัมน์เงินต้องเรียงจุดทศนิยมตรงกัน */
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

    function thaiMonth(ym) {
        return window.ThaiDate ? window.ThaiDate.month(ym) : String(ym);
    }

    function thaiShort(d) {
        return window.ThaiDate ? window.ThaiDate.short(d, '') : String(d || '');
    }


    /* ============================================================
     *  โครงคอลัมน์
     * ============================================================
     *  fixed  = คอลัมน์ซ้ายสุดที่ตรึงไว้ ไม่มีหัวสองชั้น
     *  groups = กลุ่มที่มีหัวสองชั้น กลุ่มละสองคอลัมน์ (จำนวน / เป็นเงิน)
     *
     *  ทุกกลุ่มหน้าตาเหมือนกันหมด ต่างแค่ชื่อกับที่มาของค่า จึงเก็บวิธี
     *  หยิบค่าไว้ในตัวกลุ่มเลย (qtyOf / valOf) แล้วส่วนที่วาดไม่ต้องรู้ว่า
     *  ค่านั้นมาจากคอลัมน์ตรง ๆ หรือมาจากหน่วยงานที่กางออกมา
     */
    function buildCols(data) {

        var fixed = [
            { key: 'seq',  title: 'ลำดับ',      cls: 'sp-fix sp-fix-1 num' },
            { key: 'code', title: 'รหัสสินค้า',  cls: 'sp-fix sp-fix-2 sp-code' },
            { key: 'name', title: 'รายการ',     cls: 'sp-fix sp-fix-3' },
            { key: 'unit', title: 'หน่วย',      cls: 'sp-fix sp-fix-4' }
        ];

        var groups = [];

        function plain(title, sub, tone, a, b) {
            groups.push({
                title: title, sub: sub, tone: tone,
                qtyOf: function (r) { return Number(r[a] || 0); },
                valOf: function (r) { return Number(r[b] || 0); }
            });
        }

        plain('คงเหลือยกมา', 'ณ ' + thaiShort(data.range.prev_end), 'open', 'open_qty', 'open_val');
        plain('ซื้อเข้า',     'รับเข้าในเดือน',                      'buy',  'buy_qty',  'buy_val');

        /* คอลัมน์ปรับปรุงยอดโผล่เฉพาะเดือนที่มีการปรับจริง
           เดือนปกติจะได้ตารางหน้าตาเหมือนใบที่ใช้กันอยู่เป๊ะ ๆ */
        if (data.hasAdjust) {
            plain('ปรับปรุงยอด', 'เพิ่ม/ลดนอกเอกสาร', 'adj', 'adj_qty', 'adj_val');
        }

        /* หน่วยงานที่เบิกในเดือนนี้ กลุ่มละหน่วย
           ปิด key ของหน่วยงานไว้ใน closure ทีละตัว ไม่ใช้ตัวแปรวนซ้ำร่วมกัน
           ไม่งั้นทุกกลุ่มจะไปอ่านค่าของหน่วยงานสุดท้ายเหมือนกันหมด */
        function deptGroup(dep, index) {
            return {
                title: dep.name,
                sub:   dep.company,
                /* สลับสีทีละหน่วยงาน เพราะหน่วยงานหนึ่งกินสองคอลัมน์ติดกัน
                   ถ้าพื้นสีเดียวกันหมด ตาจะจับคู่ผิดทันทีที่เลื่อนไปไกล ๆ
                   แล้วอ่านจำนวนของหน่วยหนึ่งคู่กับเงินของอีกหน่วยหนึ่ง */
                tone:  (index % 2 === 0) ? 'dept' : 'dept-alt',
                qtyOf: function (r) { var c = r.dept['d' + dep.id]; return c ? Number(c.qty) : 0; },
                valOf: function (r) { var c = r.dept['d' + dep.id]; return c ? Number(c.val) : 0; }
            };
        }
        for (var i = 0; i < data.departments.length; i++) {
            groups.push(deptGroup(data.departments[i], i));
        }

        plain('รวมเบิกทั้งหมด',    'ทุกหน่วยงาน',      'out',   'out_qty',   'out_val');
        plain('คงเหลือปลายเดือน', 'ยกไปเดือนถัดไป',  'close', 'close_qty', 'close_val');

        return { fixed: fixed, groups: groups, width: fixed.length + groups.length * 2 };
    }


    /* ---------- แถวที่จะแสดง หลังผ่านตัวกรองในหน้า ---------- */
    function visibleRows() {
        var movedOnly = el('spMovedOnly').checked;
        var term      = el('spSearch').value.toLowerCase().replace(/^\s+|\s+$/g, '');

        var out = [];
        for (var i = 0; i < DATA.rows.length; i++) {
            var r = DATA.rows[i];
            if (movedOnly && !r.moved) { continue; }
            if (term !== '') {
                var hay = (r.product_code + ' ' + r.product_name).toLowerCase();
                if (hay.indexOf(term) < 0) { continue; }
            }
            out.push(r);
        }
        return out;
    }


    /* ============================================================
     *  วาดตาราง
     * ============================================================ */
    function cellNum(value, isMoney) {
        /* ศูนย์แสดงเป็นขีด ไม่ใช่เลข 0 — ตารางนี้มีช่องว่างเป็นส่วนใหญ่
           ถ้าพิมพ์ 0 เต็มไปหมด ตัวเลขที่มีค่าจริงจะจมหายไปในกอง */
        if (!value) { return '<span class="sp-zero">-</span>'; }
        return isMoney ? money(value) : qty(value);
    }

    function draw(cols, rows) {

        var html = '';

        /* ---------- หัวสองชั้น ---------- */
        html += '<thead><tr>';
        for (var f = 0; f < cols.fixed.length; f++) {
            html += '<th rowspan="2" class="' + cols.fixed[f].cls + '">'
                 +  esc(cols.fixed[f].title) + '</th>';
        }
        for (var g = 0; g < cols.groups.length; g++) {
            var grp = cols.groups[g];
            html += '<th colspan="2" class="sp-g sp-g-' + grp.tone + '"'
                 +  (grp.sub ? ' title="' + esc(grp.sub) + '"' : '') + '>'
                 +  esc(grp.title)
                 +  (grp.sub ? '<small>' + esc(grp.sub) + '</small>' : '')
                 +  '</th>';
        }
        html += '</tr><tr>';
        for (var s = 0; s < cols.groups.length; s++) {
            html += '<th class="num sp-sub sp-g-' + cols.groups[s].tone + '">จำนวน</th>'
                 +  '<th class="num sp-sub sp-g-' + cols.groups[s].tone + '">เป็นเงิน</th>';
        }
        html += '</tr></thead>';

        /* ---------- เนื้อตาราง ---------- */
        if (rows.length === 0) {
            html += '<tbody><tr><td colspan="' + cols.width + '" class="rc-empty">'
                 +  'ไม่มีรายการที่ตรงกับตัวกรอง</td></tr></tbody>';
            el('spTable').innerHTML = html;
            return;
        }

        var gsum = [];
        for (var z = 0; z < cols.groups.length; z++) { gsum.push({ q: 0, v: 0 }); }

        var body   = '<tbody>';
        var lastCat = null;
        var seq     = 0;

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];

            /* หัวหมวดคั่นเมื่อหมวดเปลี่ยน — ข้อมูลเรียงตามหมวดมาจากฝั่ง SQL แล้ว
               จึงเทียบกับหมวดของแถวก่อนหน้าพอ ไม่ต้องจัดกลุ่มใหม่ในหน้า */
            if (r.category_name !== lastCat) {
                lastCat = r.category_name;
                body += '<tr class="sp-cat"><td colspan="' + cols.width + '">'
                     +  esc('รายการ' + lastCat) + '</td></tr>';
            }

            seq++;
            body += '<tr' + (r.moved ? ' class="is-moved"' : '') + '>'
                 +  '<td class="sp-fix sp-fix-1 num">' + seq + '</td>'
                 +  '<td class="sp-fix sp-fix-2 sp-code">' + esc(r.product_code) + '</td>'
                 +  '<td class="sp-fix sp-fix-3" title="' + esc(r.product_name) + '">' + esc(r.product_name) + '</td>'
                 +  '<td class="sp-fix sp-fix-4">' + esc(r.unit_name) + '</td>';

            for (var c = 0; c < cols.groups.length; c++) {
                var q = cols.groups[c].qtyOf(r);
                var v = cols.groups[c].valOf(r);
                gsum[c].q += q;
                gsum[c].v += v;

                body += '<td class="num sp-g-' + cols.groups[c].tone + '">' + cellNum(q, false) + '</td>'
                     +  '<td class="num money sp-g-' + cols.groups[c].tone + '">' + cellNum(v, true) + '</td>';
            }
            body += '</tr>';
        }
        body += '</tbody>';

        /* ---------- แถวรวม ----------
           รวมจากแถวที่แสดงอยู่จริง ไม่ใช่ยอดรวมทั้งเดือนที่ backend ส่งมา
           กรองอะไรไว้ ยอดท้ายตารางก็ตรงกับที่ตาเห็นเสมอ */
        var foot = '<tfoot><tr>'
                 + '<td colspan="' + cols.fixed.length + '" class="sp-fix sp-fix-all sp-sum-label">'
                 + 'รวม ' + qty(rows.length) + ' รายการ</td>';
        for (var t = 0; t < gsum.length; t++) {
            foot += '<td class="num sp-g-' + cols.groups[t].tone + '">' + cellNum(gsum[t].q, false) + '</td>'
                 +  '<td class="num money sp-g-' + cols.groups[t].tone + '">' + cellNum(gsum[t].v, true) + '</td>';
        }
        foot += '</tr></tfoot>';

        el('spTable').innerHTML = html + body + foot;
    }


    /* ---------- วาดใหม่ทั้งหน้าเมื่อข้อมูลหรือตัวกรองเปลี่ยน ---------- */
    function render() {
        if (!DATA) { return; }

        var cols = buildCols(DATA);
        var rows = visibleRows();

        draw(cols, rows);

        setText('spCount',
            'แสดง ' + qty(rows.length) + ' จาก ' + qty(DATA.rows.length) + ' รายการ'
          + ' · มีความเคลื่อนไหวในเดือนนี้ ' + qty(DATA.summary.moved) + ' รายการ'
          + ' · หน่วยงานที่เบิก ' + qty(DATA.departments.length) + ' หน่วยงาน');
    }

    /* หัวรายงานกับกล่องยอดสรุปเป็นส่วนที่ถอดออกได้ ใครจัดหน้าใหม่แล้วเอาออก
       รายงานต้องยังทำงานเหมือนเดิม ไม่ใช่พังทั้งหน้าเพราะเขียนข้อความลงช่อง
       ที่ไม่มีอยู่แล้ว — ตารางคือของจริงของหน้านี้ ที่เหลือเป็นของแถม */
    function setText(id, text) {
        var node = el(id);
        if (node) { node.textContent = text; }
    }

    function renderHead() {
        var s = DATA.summary;

        setText('spMonthText', thaiMonth(DATA.range.month));
        setText('sumOpenDate', thaiShort(DATA.range.prev_end));

        setText('sumOpen',     money(s.open_val));
        setText('sumBuy',      money(s.buy_val));
        setText('sumBuyQty',   qty(s.buy_qty));
        setText('sumOut',      money(s.out_val));
        setText('sumOutQty',   qty(s.out_qty));
        setText('sumDept',     qty(DATA.departments.length));
        setText('sumClose',    money(s.close_val));
        setText('sumCloseQty', qty(s.close_qty));
    }


    /* ============================================================
     *  โหลดข้อมูล
     * ============================================================ */
    function load(month) {
        setHidden(el('spBody'), true);
        setHidden(el('spLoading'), false);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

            if (!res || !res.ok) {
                setHidden(el('spLoading'), true);
                var msg = (res && res.message) ? res.message : 'ดึงข้อมูลรายงานไม่สำเร็จ';
                if (typeof window.appError === 'function') { window.appError(msg); }
                return;
            }

            /* กำลังเดินคู่มืออยู่บนข้อมูลสาธิต ห้ามวาดทับกลางคัน
               พักไว้ให้ demoTeardown เอาขึ้นจอตอนปิดคู่มือ */
            if (demoOn) { demoBackup.data = res; return; }

            DATA = res;
            setHidden(el('spLoading'), true);
            setHidden(el('spBody'), false);
            renderHead();
            render();
        };
        xhr.onerror = function () {
            setHidden(el('spLoading'), true);
            if (typeof window.appError === 'function') { window.appError('ติดต่อเซิร์ฟเวอร์ไม่ได้'); }
        };
        xhr.send(JSON.stringify({ action: 'supply', month: month }));
    }


    /* ============================================================
     *  ส่งออก Excel
     * ============================================================
     *  ใช้โครงคอลัมน์ชุดเดียวกับที่วาดบนจอ ไฟล์ที่ได้จึงตรงกับที่ตาเห็น
     *  รวมถึงหัวสองชั้นและคอลัมน์หน่วยงานที่กางตามเดือน
     *
     *  ศูนย์ส่งออกเป็นช่องว่าง ไม่ใช่เลข 0 ให้เหมือนบนจอ และ SUM ใน Excel
     *  ยังทำงานได้ตามปกติเพราะช่องว่างไม่ถูกนับ
     *
     *  โหลด SheetJS ตอนกดเท่านั้น เพราะหนัก 273 KB และคนส่วนใหญ่แค่เปิดดู
     */
    function busy(on) {
        var btn = el('btnExport');
        btn.disabled = on;
        setHidden(btn.querySelector('.btn-icon'), on);
        setHidden(btn.querySelector('.btn-spinner'), !on);
    }

    function num0(v) { return v ? Number(v) : ''; }

    function buildSheet(cols, rows) {

        var aoa    = [];
        var merges = [];
        var width  = cols.width;

        /* แถวที่ 1 : ชื่อรายงาน พาดยาวทั้งใบ */
        var title = ['รายละเอียดการสั่งซื้อ - เบิกใช้ ประจำเดือน ' + thaiMonth(DATA.range.month)];
        while (title.length < width) { title.push(''); }
        aoa.push(title);
        merges.push({ s: { r: 0, c: 0 }, e: { r: 0, c: width - 1 } });

        /* แถวที่ 2-3 : หัวสองชั้น */
        var h1 = [], h2 = [], col = 0, i;

        for (i = 0; i < cols.fixed.length; i++) {
            h1.push(cols.fixed[i].title);
            h2.push('');
            merges.push({ s: { r: 1, c: col }, e: { r: 2, c: col } });
            col++;
        }
        for (i = 0; i < cols.groups.length; i++) {
            var g = cols.groups[i];
            h1.push(g.title + (g.sub ? ' (' + g.sub + ')' : ''));
            h1.push('');
            h2.push('จำนวน');
            h2.push('เป็นเงิน');
            merges.push({ s: { r: 1, c: col }, e: { r: 1, c: col + 1 } });
            col += 2;
        }
        aoa.push(h1);
        aoa.push(h2);

        /* ---------- เนื้อตาราง พร้อมหัวหมวดคั่นเหมือนบนจอ ---------- */
        var gsum = [];
        for (i = 0; i < cols.groups.length; i++) { gsum.push({ q: 0, v: 0 }); }

        var lastCat = null;
        var seq     = 0;

        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];

            if (row.category_name !== lastCat) {
                lastCat = row.category_name;
                var cat = ['รายการ' + lastCat];
                while (cat.length < width) { cat.push(''); }
                merges.push({ s: { r: aoa.length, c: 0 }, e: { r: aoa.length, c: width - 1 } });
                aoa.push(cat);
            }

            seq++;
            var line = [seq, row.product_code, row.product_name, row.unit_name];
            for (i = 0; i < cols.groups.length; i++) {
                var q = cols.groups[i].qtyOf(row);
                var v = cols.groups[i].valOf(row);
                gsum[i].q += q;
                gsum[i].v += v;
                line.push(num0(q));
                line.push(num0(v));
            }
            aoa.push(line);
        }

        /* ---------- แถวรวม ---------- */
        /* ป้าย "รวม" พาดสี่ช่องแรกให้เท่ากับที่ทำบนจอ ถ้าปล่อยให้อยู่ช่องเดียว
           มันจะไปชนกับตัวเลขคอลัมน์แรกจนอ่านไม่ออกว่าอันไหนเป็นป้ายอันไหนเป็นยอด */
        var sum = ['รวม ' + rows.length + ' รายการ', '', '', ''];
        merges.push({ s: { r: aoa.length, c: 0 }, e: { r: aoa.length, c: 3 } });
        for (i = 0; i < gsum.length; i++) {
            sum.push(num0(gsum[i].q));
            sum.push(num0(gsum[i].v));
        }
        aoa.push(sum);

        var ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!merges'] = merges;

        /* ความกว้างคอลัมน์: ชื่อสินค้ากว้างสุด ที่เหลือพอให้ตัวเลขไม่ขึ้น #### */
        var widths = [{ wch: 6 }, { wch: 14 }, { wch: 46 }, { wch: 9 }];
        for (i = 0; i < cols.groups.length; i++) {
            widths.push({ wch: 10 });
            widths.push({ wch: 13 });
        }
        ws['!cols'] = widths;

        /* ไม่ตรึงหัวตารางในไฟล์ Excel เพราะตัวเขียนไฟล์ชุดย่อที่ระบบใช้
           (xlsx.mini) ไม่รองรับ freeze panes — ใส่ไปก็เงียบหาย
           คนเปิดไฟล์ตรึงเองได้ที่ มุมมอง > ตรึงแนว > ตรึงแนวที่ E4 */

        return ws;
    }

    function exportExcel() {
        if (!DATA) { return; }
        busy(true);

        var go = function () {
            var cols = buildCols(DATA);
            var wb   = XLSX.utils.book_new();

            XLSX.utils.book_append_sheet(wb, buildSheet(cols, visibleRows()), 'สั่งซื้อ-เบิกใช้');
            XLSX.writeFile(wb, 'สั่งซื้อ-เบิกใช้_' + DATA.range.month + '.xlsx');
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



    /* ============================================================
     *  ชุดข้อมูลสาธิตสำหรับคู่มือการใช้งาน
     * ============================================================
     *  คู่มือของหน้านี้พูดถึงสามอย่างที่ต้องมี "ของจริงให้ดู" ถึงจะเข้าใจ
     *  คือแถวที่บวกลบกันลงตัว คอลัมน์ที่กางตามหน่วยงาน และแถวคั่นหมวด
     *
     *  แต่คู่มือเปิดเองอัตโนมัติตอนเข้าหน้าครั้งแรก ซึ่งเป็นจังหวะที่ข้อมูล
     *  ยังโหลดไม่เสร็จ ทั้งตารางและยอดสรุปยังถูกซ่อนอยู่ ขั้นที่ชี้ไปตรงนั้น
     *  จะถูกตัดทิ้งทั้งหมด (app-guide.js กรองขั้นที่เป้าหมายมองไม่เห็นออก)
     *  เหลือคู่มือที่อธิบายแต่แถบเครื่องมือ ซึ่งเป็นส่วนที่ไม่ต้องอธิบายเลย
     *
     *  และต่อให้โหลดทัน เดือนที่ยังไม่มีใครเบิกอะไรก็ไม่มีคอลัมน์หน่วยงาน
     *  สักคอลัมน์ ขั้นที่อธิบายว่า "คอลัมน์ตรงกลางคือหน่วยงาน" จึงชี้ไปที่
     *  ตารางที่ไม่มีสิ่งที่กำลังพูดถึงอยู่เลย
     *
     *  ตรงนี้จึงใส่ข้อมูลตัวอย่างให้ระหว่างเปิดคู่มือ แล้วคืนสภาพเดิมตอนปิด
     *  (ตัวเรียกอยู่ที่ assets/js/app-guide.js — ดู window.GuideDemo)
     *
     *  ตัวเลขในชุดสาธิตบวกลบกันลงตัวจริงทุกแถวและทุกคอลัมน์ เพราะคู่มือ
     *  ขั้นแรกบอกว่ามันลงตัว ถ้าใส่เลขมั่ว ๆ คนที่ลองบวกตามจะเลิกเชื่อ
     *  ทั้งคู่มือตั้งแต่ขั้นแรก
     *
     *  ---------------------------------------------------------------
     *  ไม่แตะข้อมูลจริงถ้ามีให้ดูอยู่แล้ว
     *  ---------------------------------------------------------------
     *  เดือนที่โหลดเสร็จแล้วและมีหน่วยงานเบิกจริง จะเดินคู่มือบนข้อมูลจริง
     *  ซึ่งดีกว่าเสมอ ของสมมติมีไว้ใช้เฉพาะตอนที่ไม่มีอะไรให้ชี้เท่านั้น
     * ============================================================ */
    var demoOn     = false;
    var demoBackup = null;

    /* วันสุดท้ายของเดือนก่อน — วันที่ 0 ของเดือนนี้คือวันสุดท้ายของเดือนที่แล้ว */
    function prevMonthEnd(ym) {
        var p = String(ym).split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, 0);
        var m = d.getMonth() + 1;
        var day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }

    function demoData() {
        var month = (DATA && DATA.range) ? DATA.range.month : CFG.month;

        /* แถวแรกใช้ตัวเลขชุดเดียวกับใบที่ใช้กันอยู่จริง (67 + 70 - 98 = 39)
           คนที่เคยเห็นใบนั้นจะจับทางได้ทันทีว่ารายงานนี้คือใบเดียวกัน */
        var rows = [
            { product_id: -101, product_code: 'EX-PPR-001',
              product_name: 'กระดาษถ่ายเอกสาร A4 80 แกรม (500 แผ่น/รีม)', unit_name: 'รีม',
              category_id: -1, category_name: 'ตัวอย่าง: วัสดุสำนักงานและเครื่องเขียน',
              open_qty: 67, open_val: 5561, buy_qty: 70, buy_val: 5810, adj_qty: 0, adj_val: 0,
              dept: { 'd-1': { qty: 20, val: 1660 }, 'd-2': { qty: 48, val: 3984 }, 'd-3': { qty: 30, val: 2490 } },
              out_qty: 98, out_val: 8134, close_qty: 39, close_val: 3237, moved: true },

            { product_id: -102, product_code: 'EX-PEN-002',
              product_name: 'ปากกาลูกลื่น สีน้ำเงิน 0.5 มม.', unit_name: 'ด้าม',
              category_id: -1, category_name: 'ตัวอย่าง: วัสดุสำนักงานและเครื่องเขียน',
              open_qty: 60, open_val: 330, buy_qty: 0, buy_val: 0, adj_qty: 0, adj_val: 0,
              dept: { 'd-1': { qty: 12, val: 66 } },
              out_qty: 12, out_val: 66, close_qty: 48, close_val: 264, moved: true },

            /* แถวที่ไม่มีอะไรเกิดขึ้นเลย มีไว้ให้ขั้น "ดูเฉพาะรายการที่ขยับ" มีของหายจริง */
            { product_id: -103, product_code: 'EX-CLP-003',
              product_name: 'คลิปหนีบกระดาษ เบอร์ 111', unit_name: 'กล่อง',
              category_id: -1, category_name: 'ตัวอย่าง: วัสดุสำนักงานและเครื่องเขียน',
              open_qty: 24, open_val: 432, buy_qty: 0, buy_val: 0, adj_qty: 0, adj_val: 0,
              dept: {}, out_qty: 0, out_val: 0, close_qty: 24, close_val: 432, moved: false },

            { product_id: -104, product_code: 'EX-INK-004',
              product_name: 'หมึกเติม สีดำ 500 ml', unit_name: 'ขวด',
              category_id: -2, category_name: 'ตัวอย่าง: วัสดุสิ้นเปลือง',
              open_qty: 4, open_val: 1200, buy_qty: 10, buy_val: 3000, adj_qty: 0, adj_val: 0,
              dept: { 'd-2': { qty: 3, val: 900 } },
              out_qty: 3, out_val: 900, close_qty: 11, close_val: 3300, moved: true },

            { product_id: -105, product_code: 'EX-CLN-005',
              product_name: 'น้ำยาถูพื้น 3.8 ลิตร', unit_name: 'แกลลอน',
              category_id: -2, category_name: 'ตัวอย่าง: วัสดุสิ้นเปลือง',
              open_qty: 8, open_val: 760, buy_qty: 12, buy_val: 1140, adj_qty: 0, adj_val: 0,
              dept: { 'd-3': { qty: 15, val: 1425 } },
              out_qty: 15, out_val: 1425, close_qty: 5, close_val: 475, moved: true }
        ];

        return {
            range: { month: month, from: month + '-01', to: '', prev_end: prevMonthEnd(month) },
            departments: [
                { id: -1, name: 'บัญชี',          company: 'บริษัทตัวอย่าง จำกัด' },
                { id: -2, name: 'ทรัพยากรอาคาร',  company: 'บริษัทตัวอย่าง จำกัด' },
                { id: -3, name: 'รักษาความสะอาด', company: 'บริษัทตัวอย่าง จำกัด' }
            ],
            hasAdjust: false,
            rows: rows,
            summary: {
                open_qty: 163, open_val: 8283, buy_qty: 92, buy_val: 9950,
                adj_qty: 0, adj_val: 0, out_qty: 128, out_val: 10525,
                close_qty: 127, close_val: 7708, moved: 4
            }
        };
    }

    function demoSetup() {
        if (demoOn) { return false; }

        /* มีข้อมูลจริงที่มีหน่วยงานเบิกอยู่แล้ว เดินคู่มือบนของจริงดีกว่า */
        if (DATA && DATA.departments && DATA.departments.length > 0) { return false; }

        demoBackup = DATA;
        demoOn     = true;

        DATA = demoData();

        /* ตัวกรองต้องกลับไปเป็นค่าเริ่มต้นระหว่างสาธิต ไม่งั้นคนที่ติ๊ก
           "เฉพาะรายการที่มีความเคลื่อนไหว" ค้างไว้ จะไม่เห็นแถวที่ขั้นนั้นพูดถึง */
        demoBackup = { data: demoBackup, moved: el('spMovedOnly').checked, search: el('spSearch').value };
        el('spMovedOnly').checked = false;
        el('spSearch').value      = '';

        setHidden(el('spLoading'), true);
        setHidden(el('spBody'), false);
        renderHead();
        render();
        demoBanner(true);

        if (window.lucide) { window.lucide.createIcons(); }
        return true;
    }

    function demoTeardown() {
        if (!demoOn) { return; }

        var b = demoBackup;
        demoOn     = false;
        demoBackup = null;

        el('spMovedOnly').checked = b.moved;
        el('spSearch').value      = b.search;
        demoBanner(false);

        /* ข้อมูลจริงมาถึงระหว่างสาธิตก็ถูกพักไว้ใน backup (ดู load)
           ตรงนี้จึงเป็นจุดเดียวที่วาดของจริงขึ้นจอ ไม่ว่ามันจะมาถึงตอนไหน */
        DATA = b.data;

        if (DATA) {
            setHidden(el('spLoading'), true);
            setHidden(el('spBody'), false);
            renderHead();
            render();
        } else {
            setHidden(el('spBody'), true);
            setHidden(el('spLoading'), false);
        }
    }

    function demoBanner(on) {
        var old = el('spDemoNote');
        if (old && old.parentNode) { old.parentNode.removeChild(old); }
        if (!on) { return; }

        var wrap = el('spWrap');
        if (!wrap || !wrap.parentNode) { return; }

        var note = document.createElement('p');
        note.id        = 'spDemoNote';
        note.className = 'pd-demo-note no-print';
        note.innerHTML = '<i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>'
                       + '<span><b>ตัวเลขตัวอย่างสำหรับสาธิตเท่านั้น</b> '
                       + 'ไม่ใช่ข้อมูลจริงของคลัง — ปิดคู่มือแล้วตารางจะกลับเป็นของเดือนที่เลือกไว้</span>';

        wrap.parentNode.insertBefore(note, wrap);
    }

    window.GuideDemo = {
        page:     'report_supply',
        setup:    demoSetup,
        teardown: demoTeardown
    };


    /* ============================================================
     *  เริ่มทำงาน
     * ============================================================ */
    function init() {

        el('spMonth').addEventListener('change', function () {
            /* เปลี่ยนเดือนระหว่างเปิดคู่มืออยู่ = ตั้งใจจะดูของจริงแล้ว
               ถ้าไม่ปิดโหมดสาธิตก่อน ข้อมูลที่โหลดมาจะถูกพักไว้เฉย ๆ
               แล้วจอค้างอยู่ที่ตัวเลขสมมติทั้งที่ผู้ใช้สั่งเปลี่ยนเดือนไปแล้ว */
            demoTeardown();
            if (this.value) { load(this.value); }
        });

        el('spMovedOnly').addEventListener('change', render);

        /* พิมพ์แล้ววาดใหม่ทันทีได้ เพราะกรองในหน้าล้วน ๆ ไม่ได้ยิงเซิร์ฟเวอร์
           แต่ยังหน่วงไว้นิดหนึ่ง สามร้อยกว่าแถวถ้าวาดทุกตัวอักษรจะสะดุด */
        var timer = null;
        el('spSearch').addEventListener('input', function () {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(render, 150);
        });

        el('btnPrint').addEventListener('click', function () { window.print(); });
        el('btnExport').addEventListener('click', exportExcel);

        load(CFG.month);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
