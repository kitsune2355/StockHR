/* ============================================================
 *  qr.js — วาด QR Code ลง <canvas>
 *
 *  ใช้ไลบรารี ต่างจาก Code 128 ที่เขียนเอง เพราะ QR ต้องมี Reed-Solomon
 *  บน GF(256), เลือกเวอร์ชัน 1-40, มาส์ก 8 แบบ, BCH — ราว 500 บรรทัด
 *  และจุดพลาดเยอะ โดยไม่ได้อะไรกลับมา
 *  ตัวที่ใช้คือ qrcode-generator (Kazuhiko Arase, MIT) 20 KB
 *  ไฟล์นี้เป็นชั้นบาง ๆ ที่ห่อให้เรียกใช้เหมือน BARCODE.draw()
 *
 *  กันพลาดระดับ M (กู้ได้ราว 15%) เป็นค่าเริ่มต้น — สำหรับข้อความยาวราว
 *  80 ตัวอักษร ระดับ M ให้ QR ขนาดเท่ากับ L พอดี จึงได้ความทนทานมาฟรี
 *
 *  โมดูลต้องกว้างอย่างน้อย 0.4 มม. กล้องมือถือถึงจะอ่านติด (บาร์โค้ด 1D
 *  ใช้ 0.25) เพราะกล้องไม่ได้เล็งเป็นเส้นตรงและมักถ่ายเอียง
 *  ขอบขาวรอบรหัสต้องกว้าง 4 โมดูล ห้ามตัด เป็นตัวบอกกล้องว่ารหัสเริ่ม/จบตรงไหน
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var QUIET = 4;              /* ขอบขาวรอบรหัส หน่วยเป็นโมดูล */
    var MIN_MM = 0.4;           /* ความกว้างโมดูลต่ำสุดที่กล้องมือถือยังอ่านไหว */

    /* บังคับเข้ารหัสเป็น UTF-8 — ไลบรารีใช้ Latin-1 ตามค่าเริ่มต้น
       ภาษาไทยจะเพี้ยนแบบเงียบ ๆ คือได้ QR ที่อ่านได้แต่ข้อความคนละเรื่อง
       ตอนนี้เนื้อหาเป็น ASCII ล้วนจึงไม่กระทบ แต่ตั้งไว้กันวันหลัง */
    var utf8Ready = false;

    function lib() {
        if (typeof window.qrcode !== 'function') { return null; }

        if (!utf8Ready) {
            var f = window.qrcode.stringToBytesFuncs;
            if (f && f['UTF-8']) { window.qrcode.stringToBytes = f['UTF-8']; }
            utf8Ready = true;
        }
        return window.qrcode;
    }

    /* ---------- สร้างรหัสจากข้อความ ----------
     * คืนวัตถุที่ถามได้ว่ามีกี่โมดูล และช่องไหนเป็นสีดำ
     * ecc: 'L' | 'M' | 'Q' | 'H'
     */
    function build(text, ecc) {
        var make = lib();
        if (!make) {
            return { ok: false, message: 'ยังไม่ได้โหลดตัวสร้าง QR (assets/js/qrcode.min.js)' };
        }

        var s = String(text === null || text === undefined ? '' : text);
        if (s === '') {
            return { ok: false, message: 'ไม่มีข้อมูลให้สร้าง QR' };
        }

        /* เวอร์ชัน 0 = ให้ไลบรารีเลือกขนาดเล็กที่สุดที่ใส่ข้อมูลได้เอง
           ถ้าข้อมูลยาวเกินเวอร์ชัน 40 ไลบรารีจะโยน error ออกมา ต้องดักไว้ */
        var q;
        try {
            q = make(0, ecc || 'M');
            q.addData(s);
            q.make();
        } catch (e) {
            return { ok: false, message: 'ข้อมูลยาวเกินกว่าที่ QR หนึ่งรหัสจะเก็บได้' };
        }

        var n = q.getModuleCount();
        return {
            ok: true,
            text: s,
            code: q,
            modules: n,
            /* เวอร์ชันคำนวณกลับจากจำนวนโมดูล (v1 = 21 โมดูล เพิ่มทีละ 4) */
            version: (n - 17) / 4,
            totalModules: n + QUIET * 2
        };
    }


    /* ---------- ขนาดโมดูลที่พอดีกับพื้นที่ที่มี ----------
     * ปัดลงเป็นจำนวนเต็มพิกเซลเสมอ ด้วยเหตุผลเดียวกับบาร์โค้ด
     * ถ้าโมดูลกว้าง 3.4 พิกเซล ขอบแต่ละช่องจะถูกปัดไม่เท่ากัน
     * ตารางที่ควรเป็นสี่เหลี่ยมจัตุรัสเป๊ะ ๆ จะเบี้ยวทีละนิดจนกล้องอ่านพลาด
     */
    function fit(text, availablePx, dpi, ecc) {
        var res = build(text, ecc);
        if (!res.ok) { return res; }

        var size = Math.floor(availablePx / res.totalModules);
        if (size < 1) { size = 1; }

        var minPx = (MIN_MM / 25.4) * (Number(dpi) > 0 ? Number(dpi) : 96);

        return {
            ok: true,
            moduleSize: size,
            modules: res.totalModules,
            version: res.version,
            tooSmall: size < minPx,
            minModuleSize: minPx,
            /* ขนาดจริงที่จะวาด — ผู้เรียกใช้จัดกึ่งกลางเองได้ */
            sizePx: size * res.totalModules
        };
    }


    /* ---------- วาดลง canvas ----------
     * opts:
     *   moduleSize  พิกเซลต่อหนึ่งโมดูล
     *   ecc         ระดับการกันพลาด (ค่าเริ่มต้น M)
     *   background  สีพื้น (ค่าเริ่มต้นขาวทึบ)
     *
     * พื้นต้องขาวทึบเสมอ ไม่ใช้โปร่งใส ด้วยเหตุผลเดียวกับบาร์โค้ด
     * ไฟล์ที่เอาไปเปิดบนพื้นเข้มจะกลายเป็นดำบนดำ อ่านไม่ได้ทั้งภาพ
     */
    function draw(canvas, text, opts) {
        opts = opts || {};

        var res = build(text, opts.ecc);
        if (!res.ok) { return res; }

        var size = Number(opts.moduleSize) > 0 ? Math.floor(Number(opts.moduleSize)) : 4;
        var side = (res.modules + QUIET * 2) * size;

        canvas.width  = side;
        canvas.height = side;

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = opts.background || '#ffffff';
        ctx.fillRect(0, 0, side, side);

        /* วาดทีละโมดูลด้วยพิกัดจำนวนเต็ม ไม่มีการปัดเศษระหว่างทาง
           จึงไม่มีเส้นขนหนูระหว่างช่องและไม่มีช่องไหนกว้างไม่เท่าเพื่อน */
        ctx.fillStyle = '#000000';
        for (var r = 0; r < res.modules; r++) {
            for (var c = 0; c < res.modules; c++) {
                if (res.code.isDark(r, c)) {
                    ctx.fillRect((c + QUIET) * size, (r + QUIET) * size, size, size);
                }
            }
        }

        return {
            ok: true,
            size: side,
            moduleSize: size,
            modules: res.modules,
            version: res.version
        };
    }

    window.QRCODE = {
        build: build,
        draw: draw,
        fit: fit,
        QUIET_MODULES: QUIET,
        MIN_MODULE_MM: MIN_MM
    };
})();
