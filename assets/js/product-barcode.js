/* ============================================================
 *  product-barcode.js — หน้าสร้างฉลากบาร์โค้ดสำหรับรหัสสินค้า
 *
 *  ---------------------------------------------------------------
 *  ทำไมทั้งดวงเป็นภาพ ไม่ใช่ HTML
 *  ---------------------------------------------------------------
 *  ฉลากทั้งดวง (บาร์โค้ด + รหัส + ชื่อสินค้า + หน่วย) วาดลง <canvas> ใบเดียว
 *  แล้วค่อยย่อด้วย CSS ให้เท่าขนาดจริงเป็นมิลลิเมตร
 *
 *  ที่ทำแบบนี้เพราะสามอย่างต้องตรงกันเป๊ะ ๆ คือ ภาพที่เห็นบนจอ ภาพที่พิมพ์ลงกระดาษ
 *  และไฟล์ PNG ที่ดาวน์โหลด ถ้าฉลากเป็น HTML ทั้งสามทางจะวาดคนละเครื่องยนต์
 *  ตัวหนังสือขยับไม่กี่พิกเซลก็หลุดขอบดวงสติกเกอร์แล้ว ส่วน PNG ยังต้องไปวาดซ้ำอีกรอบ
 *  พอเป็น canvas ใบเดียว ทั้งสามทางใช้ภาพเดียวกันหมด เห็นอย่างไรได้อย่างนั้น
 *
 *  ---------------------------------------------------------------
 *  เรื่องความละเอียด
 *  ---------------------------------------------------------------
 *  วาดที่ 300 DPI เสมอ ไม่ได้วาดตามขนาดที่เห็นบนจอ (ราว 96 DPI)
 *  เพราะบาร์โค้ดที่พิมพ์จากภาพความละเอียดจอ ขอบแท่งจะเบลอจนเครื่องอ่านสับสน
 *  ตัวเลข 300 มาจากความละเอียดต่ำสุดที่เครื่องพิมพ์เลเซอร์สำนักงานทั่วไปทำได้
 *
 *  ---------------------------------------------------------------
 *  ข้อจำกัดที่ต้องบอกผู้ใช้ก่อนพิมพ์
 *  ---------------------------------------------------------------
 *  ฉลากยิ่งเล็ก แท่งบาร์โค้ดยิ่งแคบ ถ้าแท่งที่แคบที่สุดเหลือต่ำกว่า 0.25 มม.
 *  เครื่องอ่านทั่วไปจะเริ่มยิงไม่ติด — เป็นเรื่องที่ต้องรู้ "ก่อน" พิมพ์ทั้งแผ่น
 *  ไม่ใช่หลังจากตัดสติกเกอร์ไปแปะของหมดแล้ว หน้านี้จึงคำนวณให้ดูตลอดเวลา
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.BARCODE_PAGE;
    if (!CFG || !window.BARCODE) { return; }

    var DPI = 300;
    var MM  = DPI / 25.4;          /* พิกเซลต่อหนึ่งมิลลิเมตร ที่ความละเอียดพิมพ์ */

    /* ---------- ขนาดฉลากที่เลือกได้ ----------
     * ตัวเลขอ้างจากสติกเกอร์ A4 ที่หาซื้อได้ทั่วไป ผู้ใช้จะได้ไม่ต้องวัดเอง
     * perRow ใช้จัดตารางบนกระดาษให้ตรงกับแผ่นสติกเกอร์จริง
     */
    var SIZES = {
        '70x37':   { w: 70,   h: 37,   perRow: 3, label: 'A4 24 ดวง (70 × 37 มม.)' },
        '48x25':   { w: 48.5, h: 25.4, perRow: 4, label: 'A4 40 ดวง (48.5 × 25.4 มม.)' },
        '38x21':   { w: 38.1, h: 21.2, perRow: 5, label: 'A4 65 ดวง (38.1 × 21.2 มม.)' },
        '50x25':   { w: 50,   h: 25,   perRow: 4, label: 'ม้วนสติกเกอร์ (50 × 25 มม.)' }
    };

    var PAD_MM = 2;                /* ขอบในของดวงสติกเกอร์ กันเนื้อหาชิดรอยตัด */

    var products = CFG.products || [];
    var picked   = {};             /* product_id -> true */
    var filtered = products.slice();

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); }
        else { node.removeAttribute('hidden'); }
    }

    /* ---------- ค่าที่ตั้งไว้ตอนนี้ ---------- */
    /* ชนิดรหัสที่เลือกอยู่ — 'barcode' หรือ 'qr'
       เก็บไว้เป็นตัวแปรแทนการอ่านจาก DOM ทุกครั้ง เพราะเป็นปุ่มไม่ใช่ input */
    var codeType = 'barcode';

    /* การจัดวางของฉลากแบบ QR — 'landscape' หรือ 'portrait'
       ใช้เฉพาะตอน codeType เป็น 'qr' แบบบาร์โค้ดไม่มีให้เลือก
       เก็บค่าไว้แม้ตอนสลับไปบาร์โค้ด จะได้ไม่ต้องเลือกใหม่ทุกครั้งที่สลับกลับมา */
    var qrLayout = 'landscape';

    function options() {
        var key = el('bcSize').value;
        return {
            type:     codeType,
            layout:   qrLayout,
            size:     SIZES[key] || SIZES['48x25'],
            copies:   Math.max(1, Math.min(200, parseInt(el('bcCopies').value, 10) || 1)),
            showName: el('bcShowName').checked,
            showUnit: el('bcShowUnit').checked,
            showCode: el('bcShowCode').checked
        };
    }


    /* ============================================================
     *  วาดฉลากหนึ่งดวงลง canvas
     *
     *  จัดวางจากล่างขึ้นบน: ชื่อสินค้าอยู่บนสุด บาร์โค้ดกินพื้นที่ที่เหลือ
     *  เพราะบาร์โค้ดคือสิ่งเดียวบนฉลากที่ "เล็กลงแล้วใช้ไม่ได้"
     *  ส่วนตัวหนังสือเล็กลงแล้วยังพออ่านออก จึงให้ตัวหนังสือเป็นฝ่ายเสียสละพื้นที่
     * ============================================================ */
    function drawLabel(canvas, product, opt) {
        var w = Math.round(opt.size.w * MM);
        var h = Math.round(opt.size.h * MM);
        var pad = Math.round(PAD_MM * MM);

        canvas.width  = w;
        canvas.height = h;

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);

        var inner = w - pad * 2;
        var top = pad;

        /* ---------- QR วางคนละแบบกับบาร์โค้ด ----------
         * บาร์โค้ดเป็นแถบเตี้ยกว้าง จึงวางเรียงลงมาเป็นชั้น ๆ ได้ดี
         * QR เป็นสี่เหลี่ยมจัตุรัส ถ้าวางแบบเดียวกันจะสูงเท่าความสูงฉลาก
         * แล้วเหลือที่ว่างทางขวาเป็นแถบยาวโดยไม่ได้ใช้
         *
         * จึงวาง QR ชิดซ้ายเต็มความสูง แล้วเอาข้อความไปเรียงทางขวาแทน
         * ได้ QR ใหญ่ที่สุดเท่าที่ดวงสติกเกอร์จะให้ได้ ซึ่งเป็นตัวชี้ขาด
         * ว่ากล้องมือถือจะส่องติดหรือไม่
         */
        if (opt.type === 'qr') {
            return (opt.layout === 'portrait')
                ? drawQrPortrait(ctx, canvas, product, opt, w, h, pad)
                : drawQrLabel(ctx, canvas, product, opt, w, h, pad);
        }

        /* ---------- ชื่อสินค้า ---------- */
        if (opt.showName) {
            var nameSize = Math.round(opt.size.h * MM * 0.085);
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + nameSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            var lines = wrapText(ctx, product.product_name, inner, 2);
            for (var i = 0; i < lines.length; i++) {
                ctx.fillText(lines[i], w / 2, top);
                top += Math.round(nameSize * 1.2);
            }
            top += Math.round(nameSize * 0.35);
        }

        /* ---------- หน่วยนับ ----------
         * อยู่ใต้ชื่อ ไม่ใช่ใต้บาร์โค้ด เพราะเป็นข้อมูลของสินค้า
         * ส่วนพื้นที่ใต้บาร์โค้ดสงวนไว้ให้รหัส ซึ่งต้องอยู่ติดกับแท่งเสมอ
         * (คนอ่านใช้พิมพ์มือเวลาเครื่องยิงไม่ติด ถ้าอยู่ห่างจะจับคู่ไม่ถูกว่ารหัสของดวงไหน)
         */
        if (opt.showUnit && product.unit_name) {
            var unitSize = Math.round(opt.size.h * MM * 0.07);
            ctx.fillStyle = '#444444';
            ctx.font = unitSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.fillText('หน่วย: ' + product.unit_name, w / 2, top);
            top += Math.round(unitSize * 1.35);
        }

        /* ---------- บาร์โค้ด ---------- */
        var codeSize = opt.showCode ? Math.round(opt.size.h * MM * 0.095) : 0;
        var codeGap  = opt.showCode ? Math.round(codeSize * 0.3) : 0;
        var barH = h - pad - top - codeSize - codeGap;
        if (barH < Math.round(4 * MM)) { barH = Math.round(4 * MM); }

        var fit = window.BARCODE.fit(product.product_code, inner, DPI);
        if (!fit.ok) {
            ctx.fillStyle = '#b91c1c';
            ctx.font = Math.round(3 * MM) + 'px Prompt, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('รหัสนี้สร้างบาร์โค้ดไม่ได้', w / 2, h / 2);
            return { ok: false, message: fit.message };
        }

        /* วาดบาร์โค้ดลงผืนย่อยก่อน แล้วค่อยแปะลงฉลาก
           แยกขั้นเพราะ BARCODE.draw คุมความกว้างของตัวเองทั้งหมด (รวมขอบขาว)
           ถ้าวาดลงฉลากตรง ๆ จะต้องคำนวณตำแหน่งซ้อนกันสองชั้นและพลาดง่าย */
        var bc = document.createElement('canvas');
        var drawn = window.BARCODE.draw(bc, product.product_code, {
            moduleWidth: fit.moduleWidth,
            height: barH,
            showText: false
        });
        if (!drawn.ok) { return drawn; }

        ctx.drawImage(bc, Math.round((w - bc.width) / 2), top);
        top += barH;

        /* ---------- รหัสใต้บาร์โค้ด ---------- */
        if (opt.showCode) {
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + codeSize + 'px ui-monospace, Consolas, "Courier New", monospace';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            if ('letterSpacing' in ctx) { ctx.letterSpacing = Math.round(codeSize * 0.08) + 'px'; }
            ctx.fillText(product.product_code, w / 2, top + codeGap);
            if ('letterSpacing' in ctx) { ctx.letterSpacing = '0px'; }
        }

        return {
            ok: true,
            moduleWidth: fit.moduleWidth,
            xDimensionMm: fit.moduleWidth / MM,
            tooSmall: fit.tooSmall
        };
    }


    /* ---------- คำในแผงตั้งค่า เปลี่ยนตามชนิดรหัส ----------
     * บาร์โค้ดกับ QR วัดคนละอย่างและมีเกณฑ์คนละตัว
     * ถ้าใช้คำเดียวกันหมด ผู้ใช้จะเทียบเลขกับเกณฑ์ที่ผิดตัว
     *   บาร์โค้ด  ความกว้างแท่งที่แคบที่สุด  ต่ำสุด 0.25 มม. (เครื่องยิงเลเซอร์)
     *   QR       ความกว้างของโมดูล        ต่ำสุด 0.40 มม. (กล้องมือถือ)
     * กล้องมือถือต้องการมากกว่าเพราะไม่ได้เล็งเป็นเส้นตรงเหมือนเลเซอร์
     * และมักถ่ายเอียงกับสั่นด้วย
     */
    function applyTypeWording(type) {
        var isQr = (type === 'qr');

        var label = el('bcXlabel');
        var note  = el('bcXnote');
        var warn  = el('bcWarnText');
        var code  = el('bcShowCode');

        if (label) { label.textContent = isQr ? 'ความกว้างของโมดูล' : 'ความกว้างแท่งที่แคบที่สุด'; }
        if (note)  {
            note.textContent = isQr
                ? 'กล้องมือถือต้องการอย่างน้อย 0.40 มม.'
                : 'เครื่องอ่านทั่วไปต้องการอย่างน้อย 0.25 มม.';
        }
        if (warn) {
            warn.textContent = isQr
                ? 'ฉลากขนาดนี้ทำให้โมดูลของ QR เล็กกว่าเกณฑ์ กล้องมือถืออาจส่องไม่ติด ' +
                  'แนะนำให้เลือกดวงที่ใหญ่ขึ้น QR ต้องการพื้นที่มากกว่าบาร์โค้ดเพราะเป็นสี่เหลี่ยมจัตุรัส'
                : 'ฉลากขนาดนี้ทำให้แท่งบาร์โค้ดแคบกว่าเกณฑ์ เครื่องอ่านอาจยิงไม่ติด ' +
                  'แนะนำให้เลือกดวงที่ใหญ่ขึ้น หรือปิดการแสดงชื่อสินค้าเพื่อคืนพื้นที่ให้บาร์โค้ด';
        }

        /* ป้ายกำกับช่องติ๊กก็ต้องตรงกับสิ่งที่เห็นจริงบนฉลาก
           แบบบาร์โค้ดรหัสอยู่ใต้แท่ง แบบ QR อยู่ข้างหรือใต้ QR ตามการจัดวาง */
        if (code && code.nextElementSibling) {
            code.nextElementSibling.textContent = isQr
                ? (qrLayout === 'portrait' ? 'แสดงรหัสสินค้าใต้ QR' : 'แสดงรหัสสินค้าข้าง QR')
                : 'แสดงรหัสสินค้าใต้บาร์โค้ด';
        }

        /* ตัวเลือกการจัดวางมีความหมายเฉพาะกับ QR
           บาร์โค้ดเป็นแถบเตี้ยกว้าง วางได้ทางเดียวคือเรียงลงมา
           ถ้าปล่อยให้เห็นตอนเลือกบาร์โค้ด จะเป็นปุ่มที่กดแล้วไม่มีอะไรเกิดขึ้น */
        setHidden(el('bcLayoutBox'), !isQr);
    }

    /* คำอธิบายใต้ปุ่มจัดวาง — บอกว่าแต่ละแบบแลกอะไรกับอะไร
       ไม่ใช่แค่บอกว่าอันไหนถูกเลือกอยู่ ซึ่งดูจากปุ่มก็รู้แล้ว */
    function applyLayoutWording(layout) {
        var hint = el('bcLayoutHint');
        if (hint) {
            hint.textContent = (layout === 'portrait')
                ? 'แนวตั้ง QR อยู่บน ข้อความอยู่ล่าง ชื่อสินค้ายาว ๆ อ่านง่ายกว่า แต่ QR เล็กลง'
                : 'แนวนอน QR อยู่ซ้าย ข้อความอยู่ขวา ได้ QR ใหญ่กว่า';
        }

        /* ป้ายช่องติ๊กเปลี่ยนตามการจัดวางด้วย เพราะตำแหน่งรหัสย้ายจริง */
        var code = el('bcShowCode');
        if (code && code.nextElementSibling && codeType === 'qr') {
            code.nextElementSibling.textContent = (layout === 'portrait')
                ? 'แสดงรหัสสินค้าใต้ QR'
                : 'แสดงรหัสสินค้าข้าง QR';
        }
    }
    /* ============================================================
     *  ฉลากแบบ QR
     *
     *  ผัง: QR ชิดซ้ายเต็มความสูง | ข้อความเรียงลงมาทางขวา
     *
     *  QR ใหญ่ที่สุดเท่าที่ความสูงฉลากจะให้ได้ เพราะขนาดโมดูลคือตัวชี้ขาด
     *  ว่ากล้องมือถือส่องติดไหม ต่างจากบาร์โค้ดที่ตัวกำหนดคือความกว้าง
     *
     *  ถ้าเนื้อที่ทางขวาแคบเกินจะใส่ข้อความ ก็ไม่ใส่ ปล่อยให้ QR อยู่กลางดวงแทน
     *  ฉลากที่มีตัวหนังสือทับกันอ่านไม่ออกแย่กว่าฉลากที่มีแต่ QR
     * ============================================================ */
    function drawQrLabel(ctx, canvas, product, opt, w, h, pad) {
        var side = h - pad * 2;                 /* QR กินความสูงทั้งดวง */
        if (side > w - pad * 2) { side = w - pad * 2; }

        var fit = window.QRCODE.fit(product.product_code, side, DPI);
        if (!fit.ok) {
            ctx.fillStyle = '#b91c1c';
            ctx.font = Math.round(3 * MM) + 'px Prompt, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('รหัสนี้สร้าง QR ไม่ได้', w / 2, h / 2);
            return { ok: false, message: fit.message };
        }

        /* วาดลงผืนย่อยก่อนแล้วค่อยแปะ ด้วยเหตุผลเดียวกับบาร์โค้ด
           คือให้ตัววาดคุมขนาดของตัวเองทั้งหมดรวมขอบขาว */
        var qc = document.createElement('canvas');
        var drawn = window.QRCODE.draw(qc, product.product_code, { moduleSize: fit.moduleSize });
        if (!drawn.ok) { return drawn; }

        var qx = pad;
        var qy = Math.round((h - qc.height) / 2);
        ctx.drawImage(qc, qx, qy);

        /* ---------- ข้อความทางขวา ---------- */
        var textLeft  = qx + qc.width + Math.round(1.5 * MM);
        var textWidth = w - pad - textLeft;

        /* แคบกว่านี้ใส่อะไรก็อ่านไม่ออก เอา QR ไว้กลางดวงแทน */
        if (textWidth < Math.round(8 * MM)) {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(qc, Math.round((w - qc.width) / 2), qy);
            return {
                ok: true, moduleWidth: fit.moduleSize,
                xDimensionMm: fit.moduleSize / MM, tooSmall: fit.tooSmall
            };
        }

        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';

        /* วัดความสูงรวมก่อน แล้วค่อยวาง เพื่อให้ข้อความอยู่กึ่งกลางแนวตั้งเทียบกับ QR
           ไม่ใช่ลอยอยู่ด้านบนโดยมีที่ว่างห้อยอยู่ข้างล่าง */
        var nameSize = Math.round(opt.size.h * MM * 0.085);
        var codeSize = Math.round(opt.size.h * MM * 0.10);
        var unitSize = Math.round(opt.size.h * MM * 0.07);

        var nameLines = [];
        if (opt.showName) {
            ctx.font = '600 ' + nameSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            nameLines = wrapText(ctx, product.product_name, textWidth, 3);
        }

        var blockH = nameLines.length * Math.round(nameSize * 1.2)
                   + (opt.showCode ? Math.round(codeSize * 1.35) : 0)
                   + (opt.showUnit && product.unit_name ? Math.round(unitSize * 1.3) : 0);

        var ty = Math.round((h - blockH) / 2);
        if (ty < pad) { ty = pad; }

        if (nameLines.length > 0) {
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + nameSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            for (var i = 0; i < nameLines.length; i++) {
                ctx.fillText(nameLines[i], textLeft, ty);
                ty += Math.round(nameSize * 1.2);
            }
        }

        if (opt.showCode) {
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + codeSize + 'px ui-monospace, Consolas, "Courier New", monospace';
            ctx.fillText(product.product_code, textLeft, ty);
            ty += Math.round(codeSize * 1.35);
        }

        if (opt.showUnit && product.unit_name) {
            ctx.fillStyle = '#444444';
            ctx.font = unitSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            ctx.fillText('หน่วย: ' + product.unit_name, textLeft, ty);
        }

        return {
            ok: true,
            moduleWidth: fit.moduleSize,
            xDimensionMm: fit.moduleSize / MM,
            tooSmall: fit.tooSmall
        };
    }


    /* ---------- ฉลากแบบ QR จัดวางแนวตั้ง ----------
     * ผัง: QR อยู่บน ข้อความอยู่ล่าง — ข้อความได้ความกว้างเต็มดวง ชื่อยาวจึงอ่านง่ายกว่า
     * แลกกับ QR ที่เล็กลงเพราะต้องแบ่งความสูงให้ข้อความ
     *
     * จองที่ให้ข้อความก่อน ที่เหลือค่อยเป็นของ QR — กลับกันไม่ได้
     * ไม่งั้นข้อความจะล้นออกนอกดวงตอนเปิดครบทั้งสามอย่างบนสติกเกอร์เล็ก */
    function drawQrPortrait(ctx, canvas, product, opt, w, h, pad) {
        var nameSize = Math.round(opt.size.h * MM * 0.085);
        var codeSize = Math.round(opt.size.h * MM * 0.10);
        var unitSize = Math.round(opt.size.h * MM * 0.07);

        /* ตัดสินใจว่าจะวาดอะไรที่เดียว แล้วใช้ค่าเดียวกันทั้งตอนจองที่และตอนวาด
           ถ้าแยกกันคิด จะมีกรณีคำนวณว่าไม่วาดแต่ตอนวาดยังวาด แล้วล้นออกนอกดวง */
        var wantName = !!opt.showName;
        var wantCode = !!opt.showCode;
        var wantUnit = !!(opt.showUnit && product.unit_name);

        /* ชื่อสินค้าจำกัดสองบรรทัดในแนวตั้ง (แนวนอนได้สามบรรทัด)
           เพราะที่นี่ทุกบรรทัดที่เพิ่มคือความสูงที่ QR เสียไปตรง ๆ */
        var nameLines = [];
        if (wantName) {
            ctx.font = '600 ' + nameSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            nameLines = wrapText(ctx, product.product_name, w - pad * 2, 2);
        }

        function measure() {
            var t = nameLines.length * Math.round(nameSize * 1.2)
                  + (wantCode ? Math.round(codeSize * 1.35) : 0)
                  + (wantUnit ? Math.round(unitSize * 1.3) : 0);
            var g = t > 0 ? Math.round(1 * MM) : 0;
            var s = h - pad * 2 - t - g;
            if (s > w - pad * 2) { s = w - pad * 2; }
            return { textH: t, gap: g, side: s };
        }

        var m = measure();

        /* QR เล็กกว่านี้ใช้ไม่ได้ ค่อย ๆ ตัดข้อความคืนที่ให้ QR
           ตัดหน่วยก่อน แล้วชื่อ เหลือรหัสไว้ท้ายสุด เพราะเป็นตัวเดียวที่พิมพ์มือแทนได้ */
        var MIN_SIDE = Math.round(8 * MM);
        if (m.side < MIN_SIDE && wantUnit) { wantUnit = false;   m = measure(); }
        if (m.side < MIN_SIDE && nameLines.length) { nameLines = []; m = measure(); }

        var textH = m.textH;
        var gap   = m.gap;
        var side  = m.side;

        var fit = window.QRCODE.fit(product.product_code, side, DPI);
        if (!fit.ok) {
            ctx.fillStyle = '#b91c1c';
            ctx.font = Math.round(3 * MM) + 'px Prompt, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('รหัสนี้สร้าง QR ไม่ได้', w / 2, h / 2);
            return { ok: false, message: fit.message };
        }

        var qc = document.createElement('canvas');
        var drawn = window.QRCODE.draw(qc, product.product_code, { moduleSize: fit.moduleSize });
        if (!drawn.ok) { return drawn; }

        /* จัดทั้งก้อน (QR + ข้อความ) ให้อยู่กึ่งกลางแนวตั้ง
           ไม่ใช่ดัน QR ชิดบนแล้วปล่อยที่ว่างห้อยอยู่ข้างล่าง */
        var blockH = qc.height + gap + textH;
        var ty = Math.round((h - blockH) / 2);
        if (ty < pad) { ty = pad; }

        ctx.drawImage(qc, Math.round((w - qc.width) / 2), ty);
        ty += qc.height + gap;

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';

        if (nameLines.length > 0) {
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + nameSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            for (var i = 0; i < nameLines.length; i++) {
                ctx.fillText(nameLines[i], w / 2, ty);
                ty += Math.round(nameSize * 1.2);
            }
        }

        if (wantCode) {
            ctx.fillStyle = '#000000';
            ctx.font = '600 ' + codeSize + 'px ui-monospace, Consolas, "Courier New", monospace';
            ctx.fillText(product.product_code, w / 2, ty);
            ty += Math.round(codeSize * 1.35);
        }

        if (wantUnit) {
            ctx.fillStyle = '#444444';
            ctx.font = unitSize + 'px Prompt, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif';
            ctx.fillText('หน่วย: ' + product.unit_name, w / 2, ty);
        }

        return {
            ok: true,
            moduleWidth: fit.moduleSize,
            xDimensionMm: fit.moduleSize / MM,
            tooSmall: fit.tooSmall
        };
    }


    /* ตัดข้อความให้พอดีความกว้าง ได้ไม่เกิน maxLines บรรทัด บรรทัดสุดท้ายเติม …
       ตัดทีละตัวอักษร ไม่ใช่ทีละคำ เพราะภาษาไทยไม่มีช่องว่างระหว่างคำ
       ตัดทีละคำจะได้บรรทัดเดียวยาวเลยขอบไปเลยสำหรับชื่อสินค้าภาษาไทย */
    function wrapText(ctx, text, maxWidth, maxLines) {
        var s = String(text === null || text === undefined ? '' : text).trim();
        if (s === '') { return []; }
        if (ctx.measureText(s).width <= maxWidth) { return [s]; }

        var lines = [];
        var line = '';

        for (var i = 0; i < s.length; i++) {
            var next = line + s.charAt(i);

            if (ctx.measureText(next).width > maxWidth) {
                if (lines.length === maxLines - 1) {
                    /* บรรทัดสุดท้ายแล้ว ถอยตัวอักษรจนกว่าจะใส่ … ลงได้ */
                    var cut = line;
                    while (cut.length > 1 && ctx.measureText(cut + '…').width > maxWidth) {
                        cut = cut.slice(0, -1);
                    }
                    lines.push(cut + '…');
                    return lines;
                }
                lines.push(line);
                line = s.charAt(i);
            } else {
                line = next;
            }
        }

        if (line !== '') { lines.push(line); }
        return lines;
    }


    /* ============================================================
     *  รายการสินค้าฝั่งซ้าย
     * ============================================================ */
    function renderList() {
        var box = el('bcList');
        var term = el('bcSearch').value.trim().toLowerCase();

        filtered = [];
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            if (term === '' ||
                (p.product_code + ' ' + p.product_name + ' ' + (p.old_code || '')).toLowerCase().indexOf(term) !== -1) {
                filtered.push(p);
            }
        }

        if (filtered.length === 0) {
            box.innerHTML = '<p class="bc-none">ไม่พบสินค้าที่ตรงกับคำค้น</p>';
            return;
        }

        var html = [];
        for (var j = 0; j < filtered.length; j++) {
            var q = filtered[j];
            html.push(
                '<label class="bc-item' + (picked[q.product_id] ? ' is-on' : '') + '" data-id="' + q.product_id + '">' +
                  '<input type="checkbox" class="bc-check"' + (picked[q.product_id] ? ' checked' : '') + '>' +
                  '<span class="bc-item-code">' + escapeHtml(q.product_code) + '</span>' +
                  '<span class="bc-item-name">' + escapeHtml(q.product_name) + '</span>' +
                  '<span class="bc-item-unit">' + escapeHtml(q.unit_name || '') + '</span>' +
                '</label>'
            );
        }
        box.innerHTML = html.join('');
    }

    function pickedList() {
        var out = [];
        for (var i = 0; i < products.length; i++) {
            if (picked[products[i].product_id]) { out.push(products[i]); }
        }
        return out;
    }

    function refreshCount() {
        var n = pickedList().length;
        el('bcPicked').textContent = n;

        el('btnPrint').disabled = (n === 0);
        el('btnSheetPng').disabled = (n === 0);
        el('btnClear').disabled = (n === 0);
    }


    /* ============================================================
     *  แผ่นตัวอย่าง
     *
     *  วาดใหม่ทั้งแผ่นทุกครั้งที่มีอะไรเปลี่ยน ไม่ได้วาดเพิ่มทีละดวง
     *  เพราะการเปลี่ยนขนาดฉลากทำให้ทุกดวงต้องวาดใหม่หมดอยู่แล้ว
     *
     *  ---------------------------------------------------------------
     *  สิ่งที่เห็นบนแผ่นตัวอย่าง คือสิ่งที่จะถูกพิมพ์ ไม่มากไม่น้อยกว่านั้น
     *  ---------------------------------------------------------------
     *  ตอนสั่งพิมพ์ เบราว์เซอร์พิมพ์สิ่งที่อยู่บนหน้าจริง ๆ เท่านั้น
     *  ถ้าแผ่นตัวอย่างแสดงแค่บางส่วนแล้วบอกว่า "ตอนพิมพ์จะได้ครบ" คือคำโกหก
     *  เพราะดวงที่ไม่ได้วาดไว้ก็ไม่มีอะไรให้พิมพ์
     *
     *  จึงวาดทุกดวงที่เลือกไว้เสมอ แล้วตั้งเพดานไว้ที่จำนวนเดียวกันทั้งสองทาง
     *  วัดแล้ว 313 ดวงใช้เวลา 119 มิลลิวินาที และหน่วยความจำภาพ 205 MB
     *  เพดาน 500 ดวง (ราว 13 แผ่น A4) จึงยังอยู่ในวิสัยที่เครื่องสำนักงานรับไหว
     *  เกินกว่านั้นให้แบ่งพิมพ์เป็นชุด ซึ่งบอกไว้ตรง ๆ บนหน้าจอ
     * ============================================================ */
    var MAX_LABELS = 500;

    function renderSheet() {
        var sheet = el('bcSheet');
        var list = pickedList();
        var opt = options();

        sheet.style.setProperty('--bc-w', opt.size.w + 'mm');
        sheet.style.setProperty('--bc-h', opt.size.h + 'mm');
        sheet.style.setProperty('--bc-cols', opt.size.perRow);

        /* เส้นตัดเป็นเรื่องของตอนพิมพ์ล้วน ๆ (ดู @media print ใน app.css)
           บนจอยังเห็นเส้นประเสมอ เพราะเป็นตัวช่วยดูว่าเนื้อหาชิดขอบเกินไปไหม */
        sheet.classList.toggle('no-cut', !el('bcCutLine').checked);

        /* ถ้อยคำต้องเปลี่ยนตามชนิดรหัสเสมอ แม้ยังไม่ได้เลือกสินค้าสักตัว
           ไม่งั้นคนที่สลับไป QR ก่อนแล้วค่อยเลือกสินค้า จะไม่เห็นตัวเลือกการจัดวาง
           จนกว่าจะติ๊กสินค้าตัวแรก ซึ่งดูเหมือนตัวเลือกหายไปเฉย ๆ */
        applyTypeWording(opt.type);

        if (list.length === 0) {
            sheet.innerHTML = '';
            setHidden(el('bcEmpty'), false);
            setHidden(el('bcSheetWrap'), true);
            setHidden(el('bcWarn'), true);
            el('bcXdim').textContent = '—';
            return;
        }

        setHidden(el('bcEmpty'), true);
        setHidden(el('bcSheetWrap'), false);

        /* จำนวนดวงทั้งหมดที่จะพิมพ์ = สินค้าที่เลือก × จำนวนดวงต่อสินค้า */
        var total = list.length * opt.copies;
        var capped = Math.min(total, MAX_LABELS);

        sheet.innerHTML = '';
        var frag = document.createDocumentFragment();
        var xdim = 0;
        var tooSmall = false;
        var drawnCount = 0;

        for (var i = 0; i < list.length && drawnCount < capped; i++) {
            for (var c = 0; c < opt.copies && drawnCount < capped; c++) {

                var cell = document.createElement('div');
                cell.className = 'bc-cell';

                var canvas = document.createElement('canvas');
                canvas.className = 'bc-canvas';
                var res = drawLabel(canvas, list[i], opt);

                if (res.ok) {
                    xdim = res.xDimensionMm;
                    if (res.tooSmall) { tooSmall = true; }
                }

                cell.appendChild(canvas);

                /* ปุ่มดาวน์โหลดของดวงนี้ ซ่อนตอนพิมพ์ (.no-print) */
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bc-dl no-print';
                btn.title = 'ดาวน์โหลดฉลากนี้เป็นรูป PNG';
                btn.setAttribute('data-code', list[i].product_code);
                btn.innerHTML = '<i data-lucide="download" class="h-3.5 w-3.5"></i>';
                cell.appendChild(btn);

                frag.appendChild(cell);
                drawnCount++;
            }
        }

        sheet.appendChild(frag);

        el('bcXdim').textContent = xdim > 0 ? (xdim.toFixed(3) + ' มม.') : '—';
        setHidden(el('bcWarn'), !tooSmall);
        applyTypeWording(opt.type);

        /* บอกตรง ๆ เมื่อทำให้ไม่ครบ — ทั้งบนจอและตอนพิมพ์จะได้เท่ากันคือ capped ดวง
           ต้องพูดให้ชัดว่าที่หายไปคือหายไปจริง ไม่ใช่แค่ไม่แสดงตัวอย่าง */
        var note = el('bcCapped');
        if (total > capped) {
            note.textContent = 'เลือกไว้ ' + total + ' ดวง แต่ทำได้ครั้งละไม่เกิน ' + MAX_LABELS +
                               ' ดวง — ตอนนี้จะได้เฉพาะ ' + capped + ' ดวงแรกทั้งบนจอและตอนพิมพ์ ' +
                               'ถ้าต้องการครบให้แบ่งเลือกเป็นหลายชุด';
            note.className = 'text-xs font-medium text-amber-600 dark:text-amber-400';
        } else {
            note.textContent = 'ทั้งหมด ' + total + ' ดวง';
            note.className = 'text-xs text-slate-500 dark:text-slate-400';
        }
        setHidden(note, false);

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }


    /* ============================================================
     *  ดาวน์โหลดรูป
     * ============================================================ */
    function download(dataUrl, filename) {
        var a = document.createElement('a');
        a.href = dataUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    /* ฉลากดวงเดียว — วาดใหม่จากต้นฉบับ ไม่ได้ก๊อปจากที่แสดงบนจอ
       เพื่อให้ได้ไฟล์ความละเอียดเต็มเสมอ ไม่ขึ้นกับว่าจอย่อไว้เท่าไร */
    function downloadOne(code) {
        var product = null;
        for (var i = 0; i < products.length; i++) {
            if (products[i].product_code === code) { product = products[i]; break; }
        }
        if (!product) { return; }

        var canvas = document.createElement('canvas');
        var res = drawLabel(canvas, product, options());
        if (!res.ok) {
            if (typeof window.appError === 'function') { window.appError(res.message); }
            return;
        }
        download(canvas.toDataURL('image/png'), 'barcode-' + code + '.png');
    }

    /* ทั้งแผ่นเป็นรูปเดียว — สำหรับส่งให้ร้านพิมพ์ หรือเก็บไว้พิมพ์ทีหลัง
       เรียงตามตารางเดียวกับที่เห็นบนจอ เพื่อให้ตรงกับแผ่นสติกเกอร์จริง */
    function downloadSheet() {
        var list = pickedList();
        var opt = options();
        if (list.length === 0) { return; }

        /* ใช้เพดานเดียวกับแผ่นตัวอย่าง ไฟล์ที่ได้จะได้ตรงกับสิ่งที่เห็นบนจอ */
        var jobs = [];
        for (var i = 0; i < list.length && jobs.length < MAX_LABELS; i++) {
            for (var c = 0; c < opt.copies && jobs.length < MAX_LABELS; c++) { jobs.push(list[i]); }
        }

        var cols = opt.size.perRow;
        var rows = Math.ceil(jobs.length / cols);
        var cw = Math.round(opt.size.w * MM);
        var ch = Math.round(opt.size.h * MM);

        /* กันภาพใหญ่เกินจนเบราว์เซอร์สร้าง canvas ไม่ไหวแล้วได้ไฟล์ว่างเปล่า
           ขีดที่ 268 ล้านพิกเซลตามข้อจำกัดที่เบราว์เซอร์ส่วนใหญ่ยอมรับ */
        if (cols * cw * rows * ch > 268000000) {
            if (typeof window.appError === 'function') {
                window.appError('ฉลากรวมกันมากเกินกว่าจะรวมเป็นรูปเดียวได้ ' +
                                'ลองลดจำนวนดวงต่อสินค้า หรือแบ่งเลือกสินค้าเป็นหลายชุด ' +
                                'ถ้าต้องการพิมพ์ทั้งหมดให้ใช้ปุ่มพิมพ์ฉลากแทน');
            }
            return;
        }

        var sheet = document.createElement('canvas');
        sheet.width  = cols * cw;
        sheet.height = rows * ch;

        var ctx = sheet.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, sheet.width, sheet.height);

        var one = document.createElement('canvas');
        for (var j = 0; j < jobs.length; j++) {
            var res = drawLabel(one, jobs[j], opt);
            if (!res.ok) { continue; }
            ctx.drawImage(one, (j % cols) * cw, Math.floor(j / cols) * ch);
        }

        download(sheet.toDataURL('image/png'), 'barcode-sheet-' + jobs.length + '.png');
    }


    /* ============================================================
     *  ผูกเหตุการณ์
     * ============================================================ */
    /* ---------- แผงตั้งค่าแบบพับเก็บบนจอแคบ ----------
     * ทำงานเหมือนแถบตัวกรองของหน้าประวัติเอกสาร (assets/js/filter-panel.js)
     * จอกว้างแผงกางค้างอยู่แล้ว โค้ดชุดนี้จึงไม่มีผลอะไร เพราะ CSS
     * เป็นตัวตัดสินว่าจะพับหรือไม่ ส่วนตรงนี้แค่สลับ class กับฉากหลัง
     */
    function initSide() {
        var side     = el('bcSide');
        var toggle   = el('bcSideToggle');
        var closeBtn = el('bcSideClose');
        var backdrop = el('bcBackdrop');
        if (!side || !toggle) { return; }

        /* ห้ามย้ายฉากหลังไปไว้ที่ body — เหตุผลเดียวกับ assets/js/filter-panel.js
           #appMain เป็น position: fixed จึงสร้างชั้นซ้อนของตัวเอง
           ฉากหลังที่ไปอยู่ชั้นนอกจะทับแผงจนกดอะไรไม่ได้ */

        function open(yes) {
            side.classList.toggle('is-open', yes);
            setHidden(backdrop, !yes);
            toggle.setAttribute('aria-expanded', yes ? 'true' : 'false');
        }

        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', function () {
            open(!side.classList.contains('is-open'));
        });
        if (closeBtn) { closeBtn.addEventListener('click', function () { open(false); }); }
        if (backdrop) { backdrop.addEventListener('click', function () { open(false); }); }

        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && side.classList.contains('is-open')) {
                open(false);
            }
        });

        /* ขยายจอจนแผงกางค้างเองแล้ว ต้องเก็บฉากหลังทิ้ง
           ไม่งั้นจะเหลือแผ่นทึบคลุมทั้งหน้าโดยไม่มีอะไรกางอยู่ */
        if (window.matchMedia) {
            var wide = window.matchMedia('(min-width: 1024px)');
            var onWide = function (m) { if (m.matches) { open(false); } };
            if (wide.addEventListener) { wide.addEventListener('change', onWide); }
            else if (wide.addListener) { wide.addListener(onWide); }
        }
    }


    function init() {
        renderList();
        refreshCount();
        renderSheet();
        initSide();

        el('bcSearch').addEventListener('input', renderList);

        /* ติ๊กเลือก — ผูกที่กล่องรายการทีเดียว ไม่ผูกทีละแถว
           เพราะรายการถูกวาดใหม่ทุกครั้งที่ค้นหา ถ้าผูกทีละแถวจะต้องผูกใหม่ทุกรอบ */
        el('bcList').addEventListener('change', function (e) {
            var item = e.target.closest ? e.target.closest('.bc-item') : null;
            if (!item) { return; }

            var id = Number(item.getAttribute('data-id'));
            if (e.target.checked) {
                picked[id] = true;
                item.classList.add('is-on');
            } else {
                delete picked[id];
                item.classList.remove('is-on');
            }
            refreshCount();
            renderSheet();
        });

        el('btnPickAll').addEventListener('click', function () {
            for (var i = 0; i < filtered.length; i++) { picked[filtered[i].product_id] = true; }
            renderList();
            refreshCount();
            renderSheet();
        });

        el('btnClear').addEventListener('click', function () {
            picked = {};
            renderList();
            refreshCount();
            renderSheet();
        });

        /* ---------- ปุ่มเลือกชนิดรหัส ----------
           ผูกที่กล่องครอบทีเดียว ไม่ผูกทีละปุ่ม เผื่อวันหลังเพิ่มชนิดที่สาม */
        var typeBox = el('bcType');
        if (typeBox) {
            typeBox.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.bc-type-btn') : null;
                if (!btn) { return; }

                codeType = btn.getAttribute('data-type') || 'barcode';

                var all = typeBox.querySelectorAll('.bc-type-btn');
                for (var i = 0; i < all.length; i++) {
                    all[i].classList.toggle('is-on', all[i] === btn);
                }
                renderSheet();
            });
        }

        /* ---------- ปุ่มเลือกการจัดวาง (โผล่เฉพาะตอนเลือก QR) ---------- */
        var layoutBox = el('bcLayout');
        if (layoutBox) {
            layoutBox.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.bc-type-btn') : null;
                if (!btn) { return; }

                qrLayout = btn.getAttribute('data-layout') || 'landscape';

                var all = layoutBox.querySelectorAll('.bc-type-btn');
                for (var i = 0; i < all.length; i++) {
                    all[i].classList.toggle('is-on', all[i] === btn);
                }
                applyLayoutWording(qrLayout);
                renderSheet();
            });
        }

        var settings = ['bcSize', 'bcCopies', 'bcShowName', 'bcShowUnit', 'bcShowCode', 'bcCutLine'];
        for (var s = 0; s < settings.length; s++) {
            el(settings[s]).addEventListener('change', renderSheet);
        }

        el('bcSheet').addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.bc-dl') : null;
            if (btn) { downloadOne(btn.getAttribute('data-code')); }
        });

        el('btnSheetPng').addEventListener('click', downloadSheet);

        el('btnPrint').addEventListener('click', function () {
            window.print();
        });
    }

    /* ============================================================
     *  ข้อมูลสาธิตสำหรับคู่มือ
     *
     *  คู่มือหน้านี้มีขั้นที่อธิบายแผ่นตัวอย่าง ปุ่มพิมพ์ และปุ่มดาวน์โหลด
     *  ซึ่งทั้งหมดยังไม่โผล่ขึ้นมาจนกว่าจะมีสินค้าถูกเลือกอย่างน้อยหนึ่งรายการ
     *  ถ้าไม่จำลองให้ ผู้ใช้ที่เพิ่งเข้าหน้านี้ครั้งแรกจะได้คู่มือที่ข้ามส่วนสำคัญไปหมด
     *
     *  เลือกให้ 4 รายการแรกชั่วคราว แล้วคืนสภาพเดิมตอนปิดคู่มือ
     *  ไม่ได้แตะฐานข้อมูลอยู่แล้วทั้งหน้า การจำลองจึงไม่ทิ้งร่องรอยอะไรเลย
     * ============================================================ */
    window.GuideDemo = {
        page: 'product_barcode',

        setup: function () {
            if (products.length === 0) { return false; }

            /* จำของเดิมไว้ทั้งก้อน เผื่อผู้ใช้เลือกอะไรค้างไว้ก่อนกดดูคู่มือ */
            this._backup = picked;
            this._search = el('bcSearch').value;

            picked = {};
            for (var i = 0; i < products.length && i < 4; i++) {
                picked[products[i].product_id] = true;
            }

            el('bcSearch').value = '';
            renderList();
            refreshCount();
            renderSheet();
            return true;
        },

        teardown: function () {
            if (!this._backup) { return; }
            picked = this._backup;
            this._backup = null;
            el('bcSearch').value = this._search || '';
            renderList();
            refreshCount();
            renderSheet();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
