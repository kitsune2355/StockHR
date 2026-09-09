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
    function lenOf(v) { return (v === undefined || v === null) ? 0 : String(v).length; }

    function tidyWorkbook(buffer) {
        var X = window.XLSX;
        var wb = X.read(new Uint8Array(buffer), { type: 'array' });

        for (var s = 0; s < wb.SheetNames.length; s++) {
            var name = wb.SheetNames[s];
            var ws   = wb.Sheets[name];
            if (!ws || !ws['!ref']) { continue; }   // ชีตว่าง ปล่อยไว้อย่างนั้น

            var rng  = X.utils.decode_range(ws['!ref']);
            var maxC = rng.e.c;
            var r, c, k, width = [];

            /* มีเซลล์หลายบรรทัดหรือไม่ (เช่น "หน่วยงานที่ดูแล" ของผู้อนุมัติที่ดูแลหลายหน่วยงาน) */
            var hasNl = false;
            for (var key in ws) {
                if (!ws.hasOwnProperty(key) || key.charAt(0) === '!') { continue; }
                var cv = ws[key].v;
                if (typeof cv === 'string' && cv.indexOf('\n') !== -1) { hasNl = true; break; }
            }

            if (!hasNl) {
                /* ไม่มีอะไรต้องแตกแถว — แค่ตั้งความกว้างคอลัมน์ (เก็บชนิดเซลล์เดิมไว้ครบ)
                   พร้อมตัด !ref ให้พอดีเซลล์จริง กันคอลัมน์/แถวว่างงอกท้ายตาราง
                   (Tabulator ตั้ง e.r เกินมาหนึ่ง) */
                var mr = -1, mc = -1;
                for (key in ws) {
                    if (!ws.hasOwnProperty(key) || key.charAt(0) === '!') { continue; }
                    var a = X.utils.decode_cell(key), l = lenOf(ws[key].v);
                    if (a.r > mr) { mr = a.r; }
                    if (a.c > mc) { mc = a.c; }
                    if (!width[a.c] || l > width[a.c]) { width[a.c] = l; }
                }
                if (mr >= 0) {
                    ws['!ref'] = X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: mr, c: mc } });
                    maxC = mc;
                }
            } else {
                /* ---------- แตกเซลล์หลายบรรทัดออกเป็นหลายแถว ----------
                 * แถวแรกของกลุ่มมีค่าครบทุกคอลัมน์ แถวที่เหลือมีเฉพาะคอลัมน์ที่แตก
                 * (ไม่ merge ตามที่ผู้ใช้เลือก) — เก็บชนิดค่าเดิมไว้ (ตัวเลขยังเป็นตัวเลข)
                 */
                var out = [];
                for (r = rng.s.r; r <= rng.e.r; r++) {
                    var cells = [];
                    for (c = 0; c <= maxC; c++) {
                        var cc = ws[X.utils.encode_cell({ r: r, c: c })];
                        cells.push(cc && cc.v !== undefined && cc.v !== null ? cc.v : '');
                    }

                    var groupN = 1;
                    for (c = 0; c <= maxC; c++) {
                        if (typeof cells[c] === 'string' && cells[c].indexOf('\n') !== -1) {
                            var nn = cells[c].split('\n').length;
                            if (nn > groupN) { groupN = nn; }
                        }
                    }

                    if (groupN === 1) { out.push(cells); continue; }

                    for (k = 0; k < groupN; k++) {
                        var line = [];
                        for (c = 0; c <= maxC; c++) {
                            if (typeof cells[c] === 'string' && cells[c].indexOf('\n') !== -1) {
                                var parts = cells[c].split('\n');
                                line.push(parts[k] !== undefined ? parts[k] : '');
                            } else {
                                line.push(k === 0 ? cells[c] : '');
                            }
                        }
                        out.push(line);
                    }
                }

                for (r = 0; r < out.length; r++) {
                    for (c = 0; c <= maxC; c++) {
                        var lc = lenOf(out[r][c]);
                        if (!width[c] || lc > width[c]) { width[c] = lc; }
                    }
                }

                ws = X.utils.aoa_to_sheet(out);
                wb.Sheets[name] = ws;
            }

            var cols = [];
            for (c = 0; c <= maxC; c++) {
                /* +4 เผื่อระยะขอบ ต่ำสุด 12 กว้างสุด 60 กันคอลัมน์เดียวกินทั้งจอ */
                cols.push({ wch: Math.max(12, Math.min(60, (width[c] || 0) + 4)) });
            }
            ws['!cols'] = cols;
        }

        var out2 = X.write(wb, { bookType: 'xlsx', bookSST: true, type: 'array' });

        /* SheetJS ฉบับฟรีเขียน cell style ไม่ได้ — แก้ XML ในไฟล์ zip โดยตรง
           ให้ทุกเซลล์จัดกึ่งกลางแนวตั้ง (middle align) ล้มเหลว = คืนไฟล์เดิม */
        try { out2 = applyMiddleAlign(out2); } catch (err) { /* ปล่อยไฟล์ธรรมดา */ }
        return out2;
    }

    /* ============================================================
     *  เปิด "ตัดคำ" ให้เซลล์ที่มีหลายบรรทัด (แก้ XML ในไฟล์ .xlsx โดยตรง)
     * ============================================================
     *  ไฟล์ที่ SheetJS เขียนออกมาเป็น zip แบบ Stored (ไม่บีบอัด) จึงแยกไฟล์ย่อย
     *  ออกมาแก้ข้อความแล้วประกอบกลับได้เอง โดยไม่ต้องมีตัวบีบอัด
     * ============================================================ */
    var CRC_TAB = (function () {
        var t = [], c, n, k;
        for (n = 0; n < 256; n++) {
            c = n;
            for (k = 0; k < 8; k++) { c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1); }
            t[n] = c >>> 0;
        }
        return t;
    })();
    function crc32(str) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < str.length; i++) {
            c = CRC_TAB[(c ^ (str.charCodeAt(i) & 0xFF)) & 0xFF] ^ (c >>> 8);
        }
        return (c ^ 0xFFFFFFFF) >>> 0;
    }
    function le16(n) { return String.fromCharCode(n & 0xFF, (n >>> 8) & 0xFF); }
    function le32(n) {
        n >>>= 0;
        return String.fromCharCode(n & 0xFF, (n >>> 8) & 0xFF, (n >>> 16) & 0xFF, (n >>> 24) & 0xFF);
    }

    /* แปลงไบต์เป็นสตริงแบบ 1 ไบต์ = 1 อักขระ (charCode 0..255)
       ห้ามใช้ TextDecoder('latin1') เพราะเบราว์เซอร์ตีความเป็น windows-1252
       ไบต์ 0x80-0x9F จะเพี้ยน แล้วประกอบ zip กลับไม่ได้ */
    function bytesToBinary(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i += 8192) {
            s += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192));
        }
        return s;
    }

    function unzipStored(bin) {
        var entries = [], p = 0;
        while (bin.substr(p, 4) === 'PK\x03\x04') {
            var method   = bin.charCodeAt(p + 8)  | (bin.charCodeAt(p + 9)  << 8);
            var size     = (bin.charCodeAt(p + 22) | (bin.charCodeAt(p + 23) << 8) |
                            (bin.charCodeAt(p + 24) << 16) | (bin.charCodeAt(p + 25) << 24)) >>> 0;
            var nameLen  = bin.charCodeAt(p + 26) | (bin.charCodeAt(p + 27) << 8);
            var extraLen = bin.charCodeAt(p + 28) | (bin.charCodeAt(p + 29) << 8);
            if (method !== 0) { return null; }   // ไม่ใช่ Stored — เลิก ปล่อยไฟล์เดิม
            var name   = bin.substr(p + 30, nameLen);
            var dataAt = p + 30 + nameLen + extraLen;
            entries.push({ name: name, data: bin.substr(dataAt, size) });
            p = dataAt + size;
        }
        return entries.length ? entries : null;
    }

    function zipStored(entries) {
        var local = '', central = '', offset = 0, i;
        for (i = 0; i < entries.length; i++) {
            var e = entries[i], crc = crc32(e.data), sz = e.data.length;
            var lh = 'PK\x03\x04' + le16(20) + le16(0) + le16(0) + le16(0) + le16(0) +
                     le32(crc) + le32(sz) + le32(sz) + le16(e.name.length) + le16(0) + e.name + e.data;
            /* central directory header: ... namelen, extralen(0), commentlen(0),
               disk#(0), internal-attr(0, 2B), external-attr(0, 4B), local-offset(4B) */
            central += 'PK\x01\x02' + le16(20) + le16(20) + le16(0) + le16(0) + le16(0) + le16(0) +
                       le32(crc) + le32(sz) + le32(sz) + le16(e.name.length) +
                       le16(0) + le16(0) + le16(0) + le16(0) + le32(0) + le32(offset) + e.name;
            local  += lh;
            offset += lh.length;
        }
        var eocd = 'PK\x05\x06' + le16(0) + le16(0) + le16(entries.length) + le16(entries.length) +
                   le32(central.length) + le32(local.length) + le16(0);
        var all = local + central + eocd;
        var bytes = new Uint8Array(all.length);
        for (i = 0; i < all.length; i++) { bytes[i] = all.charCodeAt(i) & 0xFF; }
        return bytes;
    }

    function applyMiddleAlign(buffer) {
        var bin = bytesToBinary(new Uint8Array(buffer));
        var entries = unzipStored(bin);
        if (!entries) { return buffer; }

        var byName = {}, i;
        for (i = 0; i < entries.length; i++) { byName[entries[i].name] = entries[i]; }

        var sty = byName['xl/styles.xml'];
        if (!sty) { return buffer; }

        /* เพิ่ม cellXf ตัวใหม่ที่จัดกึ่งกลางแนวตั้ง แล้วจำ index ไว้ */
        var centerXf = -1;
        sty.data = sty.data.replace(/<cellXfs count="(\d+)">([\s\S]*?)<\/cellXfs>/, function (all, n, body) {
            centerXf = parseInt(n, 10);
            return '<cellXfs count="' + (centerXf + 1) + '">' + body +
                   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">' +
                   '<alignment vertical="center"/></xf></cellXfs>';
        });
        if (centerXf < 0) { return buffer; }

        /* ใส่ style ระดับคอลัมน์ให้ทุก <col> ในทุกชีต — เซลล์ที่ไม่มี s ของตัวเอง
           จะรับสไตล์ของคอลัมน์ (จัดกึ่งกลางแนวตั้ง) */
        var touched = 0;
        for (i = 0; i < entries.length; i++) {
            var e = entries[i];
            if (e.name.indexOf('xl/worksheets/sheet') !== 0) { continue; }
            e.data = e.data.replace(/<col ([^>]*?)\s*\/>/g, function (all, attrs) {
                touched++;
                attrs = attrs.replace(/\s*style="[^"]*"/, '').replace(/\s+$/, '');
                return '<col ' + attrs + ' style="' + centerXf + '"/>';
            });
        }
        if (touched === 0) { return buffer; }   // ไม่มี <col> ให้แก้ ไม่ต้องประกอบใหม่

        var rebuilt = zipStored(entries);

        /* กันพลาด: ประกอบกลับแล้วต้องยังเป็น zip ที่อ่านไฟล์ย่อยได้ครบเท่าเดิม
           ถ้าเพี้ยน คืนไฟล์เดิม (ยังเปิดได้ แค่ไม่จัดกึ่งกลาง) */
        var check = unzipStored(bytesToBinary(rebuilt));
        if (!check || check.length !== entries.length) { return buffer; }

        return rebuilt;
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
