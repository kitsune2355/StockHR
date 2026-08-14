/* ============================================================
 *  grid-export.js — ส่งออกตาราง Tabulator เป็นไฟล์ Excel (.xlsx)
 *
 *  ใช้ร่วมกันทุกหน้าที่มีตาราง เรียกครั้งเดียวหลังสร้างตารางเสร็จ:
 *
 *      GridExport.attach({
 *          table:    table,            // instance ของ Tabulator
 *          button:   document.getElementById('btnExport'),
 *          filename: 'ผู้ใช้งาน',       // ไม่ต้องใส่วันที่และนามสกุล เติมให้เอง
 *          sheetName:'ผู้ใช้งาน',
 *          pending:  function () { ... }   // (ไม่บังคับ) จำนวนแถวที่ยังไม่บันทึก
 *      });
 *
 *  สิ่งที่ได้ออกมา
 *    - ไฟล์ .xlsx จริง เปิดใน Excel ได้เลย ไม่มีหน้าต่างเตือนรูปแบบไฟล์
 *      และภาษาไทยไม่เพี้ยน (ต่างจาก CSV ที่ Excel มักอ่านเป็นภาษาต่างดาว)
 *    - ส่งออก "เท่าที่ค้นหาอยู่ตอนนั้น" ครบทุกหน้า ไม่ใช่เฉพาะหน้าที่เห็น
 *      (Tabulator ใช้ downloadRowRange = 'active' เป็นค่าเริ่มต้น)
 *    - คอลัมน์ที่ตั้ง download:false ไว้จะไม่ติดไปด้วย เช่น ลำดับ ปุ่มลบ รหัสผ่าน
 *    - ค่าที่เป็นรหัส (id บริษัท / 0-1 ของสถานะ) ให้แต่ละหน้าตั้ง accessorDownload
 *      แปลงเป็นข้อความที่คนอ่านรู้เรื่องก่อนลงไฟล์
 *
 *  เรื่องขนาดไฟล์: ตัวเขียน xlsx (SheetJS) หนัก 270 KB จึงโหลดตอนกดปุ่มครั้งแรก
 *  เท่านั้น คนที่เข้ามาดูเฉย ๆ ไม่ต้องรับน้ำหนักส่วนนี้
 *  ที่อยู่ของไฟล์อยู่ใน window.APP_LIB.xlsx (ตั้งไว้ที่ includes/head_assets.php)
 * ============================================================ */
(function () {
    'use strict';

    var libState = 'idle';   // idle | loading | ready
    var waiting  = [];       // คนที่กดปุ่มระหว่างที่ไลบรารียังโหลดไม่เสร็จ

    /* ---------- โหลด SheetJS ตอนใช้จริง ---------- */
    function loadXlsx(done, fail) {
        if (libState === 'ready' || window.XLSX) { libState = 'ready'; done(); return; }

        waiting.push({ done: done, fail: fail });
        if (libState === 'loading') { return; }

        var url = (window.APP_LIB && window.APP_LIB.xlsx) ? window.APP_LIB.xlsx : 'assets/js/xlsx.mini.min.js';
        libState = 'loading';

        var tag = document.createElement('script');
        tag.src = url;

        tag.onload = function () {
            libState = window.XLSX ? 'ready' : 'idle';
            flush(libState === 'ready');
        };
        tag.onerror = function () {
            libState = 'idle';        // ให้กดใหม่ได้ เผื่อเน็ตสะดุดชั่วคราว
            flush(false);
        };

        document.head.appendChild(tag);
    }

    function flush(ok) {
        var list = waiting;
        waiting = [];
        for (var i = 0; i < list.length; i++) {
            if (ok) { list[i].done(); } else { list[i].fail(); }
        }
    }

    /* ---------- ชื่อไฟล์ ---------- */
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function stampedName(base) {
        var d = new Date();
        return base + '_' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
                    + '_' + pad(d.getHours()) + pad(d.getMinutes()) + '.xlsx';
    }

    /* ชื่อชีตของ Excel ยาวได้ 31 ตัว และห้ามอักขระชุดนี้ ถ้าเกินจะเปิดไฟล์ไม่ได้ */
    function safeSheetName(name) {
        var s = String(name || 'Sheet1').replace(/[\[\]\*\/\\\?\:]/g, ' ').replace(/\s+/g, ' ');
        s = s.replace(/^\s+|\s+$/g, '');
        if (s === '') { s = 'Sheet1'; }
        return s.length > 31 ? s.substring(0, 31) : s;
    }

    /* ============================================================
     *  เก็บกวาดไฟล์ที่ Tabulator สร้างเสร็จ ก่อนส่งให้เบราว์เซอร์บันทึก
     *
     *  ทำ 2 อย่าง
     *    1) แก้ขอบเขตของชีต — Tabulator คำนวณเกินไป 1 ทั้งแถวและคอลัมน์
     *       (ใส่ e.r = จำนวนแถว ทั้งที่ต้องเป็น จำนวนแถว-1) ผลคือใน Excel
     *       จะมีคอลัมน์ว่างและแถวว่างติดมาท้ายตาราง กด Ctrl+End แล้วเลยของจริง
     *       ไปหนึ่งช่อง เวลาเอาไปทำ pivot หรือ filter ต่อจะกินช่องว่างเข้าไปด้วย
     *    2) ตั้งความกว้างคอลัมน์ตามความยาวข้อความจริง ไม่งั้นชื่อบริษัทยาว ๆ
     *       จะโดนตัดเหลือ ##### ต้องมานั่งลากขยายเองทุกครั้ง
     * ============================================================ */
    function tidyWorkbook(buffer) {
        var X = window.XLSX;
        var wb = X.read(new Uint8Array(buffer), { type: 'array' });

        for (var s = 0; s < wb.SheetNames.length; s++) {
            var ws    = wb.Sheets[wb.SheetNames[s]];
            var maxR  = -1, maxC = -1;
            var width = [];
            var key, at, len;

            for (key in ws) {
                if (!ws.hasOwnProperty(key) || key.charAt(0) === '!') { continue; }

                at = X.utils.decode_cell(key);
                if (at.r > maxR) { maxR = at.r; }
                if (at.c > maxC) { maxC = at.c; }

                len = String(ws[key].v === undefined ? '' : ws[key].v).length;
                if (!width[at.c] || len > width[at.c]) { width[at.c] = len; }
            }

            if (maxR < 0) { continue; }   // ชีตว่าง ปล่อยไว้อย่างนั้น

            ws['!ref'] = X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: maxR, c: maxC } });

            var cols = [];
            for (var c = 0; c <= maxC; c++) {
                /* +4 เผื่อระยะขอบ ต่ำสุด 12 กว้างสุด 60 กันคอลัมน์เดียวกินทั้งจอ */
                var w = (width[c] || 0) + 4;
                cols.push({ wch: Math.max(12, Math.min(60, w)) });
            }
            ws['!cols'] = cols;
        }

        return X.write(wb, { bookType: 'xlsx', bookSST: true, type: 'array' });
    }

    /* ---------- กล่องข้อความ (ถ้า swal โหลดไม่ขึ้นก็ยังใช้งานได้) ---------- */
    function ok(text)   { if (window.appSuccess) { window.appSuccess(text); } }
    function warn(text) { if (window.appWarning) { window.appWarning(text); } else { alert(text); } }
    function bad(text)  { if (window.appError)   { window.appError(text); }   else { alert(text); } }

    function setBusy(button, busy) {
        if (!button) { return; }
        button.disabled = busy;

        var icon    = button.querySelector('.btn-icon');
        var spinner = button.querySelector('.btn-spinner');
        if (icon)    { if (busy) { icon.setAttribute('hidden', ''); }    else { icon.removeAttribute('hidden'); } }
        if (spinner) { if (busy) { spinner.removeAttribute('hidden'); }  else { spinner.setAttribute('hidden', ''); } }
    }

    /* ============================================================
     *  ผูกปุ่มเข้ากับตาราง
     * ============================================================ */
    function attach(opt) {
        if (!opt || !opt.table || !opt.button) { return; }

        var table  = opt.table;
        var button = opt.button;
        var sheet  = safeSheetName(opt.sheetName || opt.filename);

        /* Tabulator เรียกตัวนี้เป็นด่านสุดท้ายก่อนสั่งบันทึกไฟล์
           จึงเป็นที่เดียวที่แทรกการเก็บกวาดได้ โดยไม่ต้องแก้ไฟล์ไลบรารี
           (ถ้าเก็บกวาดไม่ผ่าน ให้ปล่อยไฟล์ดิบไป ดีกว่าไม่ได้ไฟล์เลย) */
        table.options.downloadEncoder = function (contents, mime) {
            if (contents && contents.byteLength !== undefined) {
                try { contents = tidyWorkbook(contents); } catch (err) { /* ใช้ของเดิม */ }
            }
            return new Blob([contents], { type: mime });
        };

        function run() {
            var rows = table.getData('active');   // 'active' = ผ่านตัวกรองค้นหาแล้ว ครบทุกหน้า

            if (rows.length === 0) {
                warn('ไม่มีข้อมูลให้ส่งออก ลองล้างคำค้นหาแล้วกดใหม่อีกครั้ง');
                return;
            }

            setBusy(button, true);

            loadXlsx(function () {
                try {
                    table.download('xlsx', stampedName(opt.filename || 'export'), { sheetName: sheet });
                    ok('ส่งออก ' + rows.length + ' รายการเป็นไฟล์ Excel เรียบร้อย');
                } catch (err) {
                    bad('สร้างไฟล์ Excel ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
                }
                setBusy(button, false);
            }, function () {
                setBusy(button, false);
                bad('โหลดตัวสร้างไฟล์ Excel ไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่แล้วลองอีกครั้ง');
            });
        }

        button.addEventListener('click', function () {
            var pending = (typeof opt.pending === 'function') ? opt.pending() : 0;

            /* ไฟล์ที่ได้เป็นภาพของตารางบนจอ ณ ตอนนั้น ซึ่งรวมของที่ยังไม่ได้บันทึกด้วย
               ต้องบอกก่อน ไม่งั้นคนใช้จะเข้าใจว่าไฟล์นี้ตรงกับฐานข้อมูล */
            if (pending > 0 && window.appConfirm) {
                window.appConfirm({
                    icon:        'warning',
                    title:       'ยังมีการแก้ไขที่ไม่ได้บันทึก',
                    text:        'ไฟล์ที่ได้จะรวมข้อมูลที่ยังไม่ได้บันทึก ' + pending + ' รายการไปด้วย ต้องการส่งออกต่อหรือไม่',
                    confirmText: 'ส่งออกเลย',
                    cancelText:  'ยกเลิก'
                }).then(function (yes) {
                    if (yes) { run(); }
                });
                return;
            }

            run();
        });
    }

    /* ============================================================
     *  ส่งออกข้อมูลที่ไม่ได้อยู่ในตาราง
     * ============================================================
     *  attach() ส่งออก "สิ่งที่เห็นบนตาราง" ซึ่งเป็นหนึ่งแถวต่อหนึ่งเอกสาร
     *  แต่บางคำถามตอบด้วยแถวระดับเอกสารไม่ได้ เช่น
     *
     *      "เดือนนี้รับผ้าพันแผลเข้ามากี่ครั้ง ครั้งละราคาเท่าไร"
     *      -> ครั้งแรก 5 ม้วน ม้วนละ 15 / ครั้งที่สอง 3 ม้วน ม้วนละ 10
     *
     *  ราคาต่อหน่วยอยู่ที่บรรทัด ไม่ได้อยู่ที่หัวเอกสาร ใบเดียวมีหลายราคาได้
     *  ไฟล์ที่มีแถวละเอกสารจึงเฉลี่ยราคาทิ้งไปโดยปริยาย
     *
     *  rows() รับข้อมูลที่ผู้เรียกเตรียมมาเองแล้วเขียนเป็นไฟล์ให้
     *      GridExport.rows({
     *          button, filename, sheetName,
     *          columns: [{ key:'doc_no', label:'เลขที่' }, ...],
     *          fetch:   function (done, fail) { ... done(arrayOfObjects) ... }
     *      })
     *
     *  fetch เป็นฟังก์ชัน ไม่ใช่ข้อมูลตรง ๆ เพราะรายละเอียดบรรทัดต้องไปขอจาก
     *  เซิร์ฟเวอร์ตอนกดปุ่ม จะได้ไม่ต้องลากมาทั้งก้อนตั้งแต่ตอนเปิดหน้า
     * ============================================================ */
    function rows(opt) {
        if (!opt || !opt.button || typeof opt.fetch !== 'function') { return; }

        var button = opt.button;
        var sheet  = safeSheetName(opt.sheetName || opt.filename);

        button.addEventListener('click', function () {
            setBusy(button, true);

            opt.fetch(function (data) {
                if (!data || data.length === 0) {
                    setBusy(button, false);
                    warn('ไม่มีข้อมูลให้ส่งออก ลองปรับตัวกรองแล้วกดใหม่อีกครั้ง');
                    return;
                }

                loadXlsx(function () {
                    try {
                        writeRows(data, opt.columns, sheet, stampedName(opt.filename || 'export'));
                        ok('ส่งออก ' + data.length + ' บรรทัดเป็นไฟล์ Excel เรียบร้อย');
                    } catch (err) {
                        bad('สร้างไฟล์ Excel ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
                    }
                    setBusy(button, false);
                }, function () {
                    setBusy(button, false);
                    bad('โหลดตัวสร้างไฟล์ Excel ไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่แล้วลองอีกครั้ง');
                });

            }, function (message) {
                setBusy(button, false);
                bad(message || 'ดึงข้อมูลสำหรับส่งออกไม่สำเร็จ');
            });
        });
    }

    function writeRows(data, columns, sheet, filename) {
        var X    = window.XLSX;
        var head = [];
        var body = [];
        var i, c;

        for (c = 0; c < columns.length; c++) { head.push(columns[c].label); }

        for (i = 0; i < data.length; i++) {
            var line = [];
            for (c = 0; c < columns.length; c++) {
                var v = data[i][columns[c].key];
                /* ตัวเลขต้องเป็นตัวเลขจริงในไฟล์ ไม่ใช่ข้อความ
                   ไม่งั้น Excel รวมยอดหรือทำ pivot ต่อไม่ได้ ต้องมาแปลงเองทีหลัง */
                line.push(v === null || v === undefined ? '' : v);
            }
            body.push(line);
        }

        var ws = X.utils.aoa_to_sheet([head].concat(body));
        var wb = X.utils.book_new();
        X.utils.book_append_sheet(wb, ws, sheet);

        var buf = X.write(wb, { bookType: 'xlsx', bookSST: true, type: 'array' });
        try { buf = tidyWorkbook(buf); } catch (err) { /* ใช้ของดิบ */ }

        var url = URL.createObjectURL(new Blob([buf],
            { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));

        var a = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        /* คืนหน่วยความจำของ blob หลังจากเบราว์เซอร์เริ่มบันทึกแล้ว
           เพิกถอนทันทีจะทำให้บางเบราว์เซอร์ได้ไฟล์เปล่า */
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
    }


    window.GridExport = { attach: attach, rows: rows };
})();
