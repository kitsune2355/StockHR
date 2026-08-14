/* ============================================================
 *  barcode.js — วาดบาร์โค้ด Code 128 ลง <canvas>
 *
 *  เขียนเองเพราะตารางรหัสมีแค่ 107 แถว และรหัสสินค้าในระบบนี้เป็น A-Z 0-9
 *  กับขีดกลาง ใช้ชุดอักขระ B ชุดเดียวครบ ไม่ต้องสลับชุดระหว่างทาง
 *  ได้คุมเองด้วยว่าจะวาดกี่พิกเซลต่อโมดูล ซึ่งชี้ขาดว่าฉลากที่พิมพ์จะอ่านติดไหม
 *
 *  "โมดูล" = แท่งที่แคบที่สุด ทุกความกว้างในตารางนับเป็นจำนวนโมดูล ไม่ใช่พิกเซล
 *  ขนาดจริงของโมดูล (X-dimension) ต้องอย่างน้อยราว 0.25 มม. ไม่งั้นเริ่มอ่านไม่ติด
 *  โดยเฉพาะเครื่องพิมพ์สำนักงานที่หมึกบานเล็กน้อยเสมอ
 *
 *  ขอบขาวซ้ายขวาต้องกว้าง 10 โมดูล ห้ามตัด เป็นตัวบอกว่าบาร์โค้ดเริ่ม/จบตรงไหน
 *  ฉลากที่ตัดชิดขอบคือสาเหตุอันดับต้น ๆ ที่ยิงไม่ติดทั้งที่รหัสถูก
 *
 *  โครงสร้าง: [เริ่ม B] [ข้อมูล] [ตัวตรวจสอบ] [จบ]
 *  ทุกตัวกว้าง 11 โมดูล ยกเว้นตัวจบ 13 โมดูล
 *  ตัวตรวจสอบ = (104 + ผลรวมของ ลำดับที่ x ค่าอักขระ) % 103
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    /* ---------- ตารางความกว้างของทั้ง 107 สัญลักษณ์ ----------
     * อ่านทีละหลัก สลับ แท่งดำ-ช่องว่าง-แท่งดำ-... เริ่มด้วยแท่งดำเสมอ
     * เช่น '212222' = ดำ2 ขาว1 ดำ2 ขาว2 ดำ2 ขาว2 รวม 11 โมดูล
     *
     * ตารางนี้พิมพ์ผิดแม้หลักเดียวจะได้บาร์โค้ดที่ดูปกติแต่ยิงไม่ติด
     * จึงมี BARCODE.selfTest() ไว้ตรวจกฎสองข้อที่ทุกแถวต้องเป็นจริง
     * (รวม 11 โมดูล และผลรวมของแท่งดำต้องเป็นเลขคู่) ดูท้ายไฟล์
     */
    var PATTERNS = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'
    ];

    var START_B = 104;   /* เริ่มด้วยชุดอักขระ B */
    var STOP    = 106;
    var QUIET   = 10;    /* ขอบขาวซ้ายขวา หน่วยเป็นโมดูล */

    /* ---------- แปลงข้อความเป็นลำดับค่าของ Code 128 ชุด B ----------
     * ชุด B ครอบคลุม ASCII 32-126 (เว้นวรรคถึง ~) ค่าอักขระ = รหัส ASCII - 32
     * นอกช่วงนี้ (เช่น ภาษาไทย) เข้ารหัสไม่ได้ ต้องปฏิเสธไปตรง ๆ
     * ดีกว่าเงียบ ๆ แล้วได้ฉลากที่ยิงไม่ติดไปแปะบนของจริง
     */
    function encode(text) {
        var s = String(text === null || text === undefined ? '' : text);
        if (s === '') {
            return { ok: false, message: 'ไม่มีรหัสให้สร้างบาร์โค้ด' };
        }

        var values = [];
        for (var i = 0; i < s.length; i++) {
            var code = s.charCodeAt(i);
            if (code < 32 || code > 126) {
                return {
                    ok: false,
                    message: 'บาร์โค้ดรองรับเฉพาะตัวอักษรอังกฤษ ตัวเลข และเครื่องหมายพื้นฐาน ' +
                             'ตัวที่ ' + (i + 1) + ' ของ "' + s + '" ใช้ไม่ได้'
                };
            }
            values.push(code - 32);
        }

        /* ตัวตรวจสอบ: ค่าตัวแรกคูณ 1 ตัวที่สองคูณ 2 ไล่ไปเรื่อย ๆ */
        var sum = START_B;
        for (var j = 0; j < values.length; j++) {
            sum += values[j] * (j + 1);
        }
        var check = sum % 103;

        /* ต่อความกว้างทั้งหมดให้เป็นสายเดียว แล้วค่อยแปลงเป็นแท่งดำ/ช่องว่าง */
        var widths = PATTERNS[START_B];
        for (var k = 0; k < values.length; k++) {
            widths += PATTERNS[values[k]];
        }
        widths += PATTERNS[check] + PATTERNS[STOP];

        /* bars[i] = จำนวนโมดูลของช่วงที่ i โดยช่วงคู่เป็นสีดำ ช่วงคี่เป็นสีขาว */
        var bars = [];
        var modules = 0;
        for (var m = 0; m < widths.length; m++) {
            var w = Number(widths.charAt(m));
            bars.push(w);
            modules += w;
        }

        return {
            ok: true,
            text: s,
            bars: bars,
            check: check,
            modules: modules,           /* ไม่รวมขอบขาว */
            totalModules: modules + QUIET * 2
        };
    }

    /* ---------- วาดลง canvas ----------
     * opts:
     *   moduleWidth  พิกเซลต่อหนึ่งโมดูล (ยิ่งมากยิ่งชัด ยิ่งกว้าง)
     *   height       ความสูงของแท่ง หน่วยพิกเซล
     *   showText     แสดงรหัสใต้บาร์โค้ดหรือไม่
     *   fontSize     ขนาดตัวอักษรของรหัสใต้บาร์โค้ด
     *   background   สีพื้น (ค่าเริ่มต้นขาว)
     *
     * พื้นขาวทึบเสมอ ไม่ใช้โปร่งใส ไม่งั้น PNG ที่เปิดบนพื้นเข้มจะเป็นดำบนดำ
     */
    function draw(canvas, text, opts) {
        opts = opts || {};

        var res = encode(text);
        if (!res.ok) { return res; }

        var mw   = Number(opts.moduleWidth) > 0 ? Number(opts.moduleWidth) : 2;
        var barH = Number(opts.height) > 0 ? Number(opts.height) : 60;
        var showText = opts.showText !== false;
        var fontSize = Number(opts.fontSize) > 0 ? Number(opts.fontSize) : 14;
        var gap      = showText ? Math.round(fontSize * 0.35) : 0;
        var textH    = showText ? Math.round(fontSize * 1.25) : 0;

        var width  = Math.round(res.totalModules * mw);
        var height = Math.round(barH + gap + textH);

        canvas.width  = width;
        canvas.height = height;

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = opts.background || '#ffffff';
        ctx.fillRect(0, 0, width, height);

        /* ปัดตำแหน่งขอบแท่งเป็นจำนวนเต็มพิกเซล ไม่ใช่ปัดความกว้างของแต่ละแท่ง
           ถ้าปัดความกว้างทีละแท่ง ความคลาดเคลื่อนจะสะสมไปเรื่อย ๆ จนแท่งท้าย ๆ
           เพี้ยนพอที่เครื่องอ่านจะตีความผิด วิธีนี้ทำให้ทุกขอบตรงกับพิกเซลจริง
           และความกว้างรวมไม่มีทางเกินที่คำนวณไว้ */
        ctx.fillStyle = '#000000';
        var atModule = QUIET;
        for (var i = 0; i < res.bars.length; i++) {
            var next = atModule + res.bars[i];
            if (i % 2 === 0) {  /* ช่วงคู่คือแท่งดำ */
                var x1 = Math.round(atModule * mw);
                var x2 = Math.round(next * mw);
                ctx.fillRect(x1, 0, x2 - x1, barH);
            }
            atModule = next;
        }

        if (showText) {
            ctx.fillStyle = '#000000';
            ctx.font = fontSize + 'px ui-monospace, Consolas, "Courier New", monospace';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            /* เว้นระยะตัวอักษรให้อ่านง่ายขึ้นเล็กน้อย รหัสสินค้ามีทั้งตัวเลขและขีด */
            if ('letterSpacing' in ctx) {
                ctx.letterSpacing = '1px';
            }
            ctx.fillText(res.text, width / 2, barH + gap);
        }

        return {
            ok: true,
            width: width,
            height: height,
            modules: res.totalModules,
            check: res.check
        };
    }

    /* ---------- ขนาดโมดูลที่พอดีกับความกว้างที่มี ----------
     * ฉลากมีความกว้างจำกัด แต่โมดูลเล็กเกินไปแล้วยิงไม่ติด
     * ฟังก์ชันนี้จึงคืนทั้งขนาดที่ได้และคำเตือนว่าต่ำกว่าเกณฑ์หรือยัง
     * ให้หน้าเว็บเอาไปบอกผู้ใช้ก่อนพิมพ์ทั้งแผ่นแล้วค่อยมารู้ว่าใช้ไม่ได้
     */
    function fit(text, availablePx, dpi) {
        var res = encode(text);
        if (!res.ok) { return res; }

        /* ปัดลงเป็นพิกเซลเต็มเสมอ — โมดูล 2.43 px จะถูกวาดกว้าง 2 บ้าง 3 บ้าง
         * สลับกันตามตำแหน่งที่ตกบนพิกเซล อัตราส่วนแท่งแคบ/กว้างจึงเพี้ยน
         * ซึ่งเป็นสิ่งที่เครื่องอ่านใช้ตัดสินว่าแท่งไหนคือแท่งไหน
         * แลกกับบาร์โค้ดที่แคบกว่าพื้นที่ที่มีอยู่เล็กน้อย ซึ่งไม่เสียหายเพราะวาดกลางฉลากอยู่แล้ว
         * และที่ว่างที่เหลือกลายเป็นขอบขาวเพิ่ม ซึ่งช่วยให้อ่านง่ายขึ้นด้วยซ้ำ
         */
        var exact = availablePx / res.totalModules;
        var mw = Math.floor(exact);
        if (mw < 1) { mw = 1; }

        /* 0.25 มม. คือ X-dimension ต่ำสุดที่เครื่องอ่านทั่วไปยังอ่านได้
           แปลงเป็นพิกเซลตาม dpi ที่จะพิมพ์จริง (1 นิ้ว = 25.4 มม.) */
        var minPx = (0.25 / 25.4) * (Number(dpi) > 0 ? Number(dpi) : 96);

        return {
            ok: true,
            moduleWidth: mw,
            modules: res.totalModules,
            /* วัดจากขนาดที่จะวาดจริง ไม่ใช่ขนาดก่อนปัด
               ไม่งั้นจะบอกผู้ใช้ว่าผ่านเกณฑ์ทั้งที่ของจริงแคบกว่านั้น */
            tooSmall: mw < minPx,
            minModuleWidth: minPx,
            /* ที่ว่างที่เหลือหลังปัด เผื่อผู้เรียกอยากรู้ว่าเสียพื้นที่ไปเท่าไร */
            unusedPx: availablePx - mw * res.totalModules
        };
    }

    /* ---------- ตรวจตารางรหัสด้วยตัวเอง — BARCODE.selfTest() ----------
     * ตารางพิมพ์ผิดหนึ่งหลักก็ได้บาร์โค้ดที่ตาเปล่าดูไม่ออก แต่ยิงไม่ติด
     * กฎสองข้อที่ทุกแถวของมาตรฐานต้องเป็นจริง:
     *   1) ทุกสัญลักษณ์กว้าง 11 โมดูล (ตัวจบ 13)
     *   2) ผลรวมความกว้างของแท่งดำเป็นเลขคู่เสมอ
     * ข้อ 2 จับที่ข้อ 1 จับไม่ได้ เช่นสลับตัวเลขข้ามตำแหน่ง */
    function selfTest() {
        var problems = [];
        var seen = {};

        for (var i = 0; i < PATTERNS.length; i++) {
            var p = PATTERNS[i];
            var expect = (i === STOP) ? 13 : 11;
            var total = 0, bars = 0;

            for (var j = 0; j < p.length; j++) {
                var w = Number(p.charAt(j));
                total += w;
                if (j % 2 === 0) { bars += w; }
            }

            if (total !== expect) {
                problems.push('แถว ' + i + ' กว้าง ' + total + ' โมดูล (ควรเป็น ' + expect + ')');
            }
            if (bars % 2 !== 0) {
                problems.push('แถว ' + i + ' ผลรวมแท่งดำ ' + bars + ' เป็นเลขคี่');
            }
            if (seen[p]) {
                problems.push('แถว ' + i + ' ซ้ำกับแถว ' + seen[p]);
            }
            seen[p] = i;
        }

        if (PATTERNS.length !== 107) {
            problems.push('ตารางมี ' + PATTERNS.length + ' แถว (ควรเป็น 107)');
        }

        return { ok: problems.length === 0, count: PATTERNS.length, problems: problems };
    }

    /* ---------- อ่านบาร์โค้ดกลับจากพิกเซลบน canvas ----------
     * ไม่ถูกเรียกตอนใช้งานปกติ แต่เป็นวิธีเดียวที่พิสูจน์ได้ว่าฉลากที่วาดจริง
     * (หลังปัดพิกเซลแล้ว) ยังถอดกลับเป็นรหัสเดิม */
    /* หาแถบบาร์โค้ดในภาพแล้วอ่านกลับ — ใช้กับทั้งภาพบาร์โค้ดล้วนและฉลากเต็มดวง
     * ตำแหน่งแถบจึงไม่คงที่ เกณฑ์ที่ถูกคือ "แถวไหนถอดรหัสได้สำเร็จ"
     * ไม่ใช่ "แถวที่สลับดำ-ขาวถี่ที่สุด" เพราะชื่อสินค้าภาษาไทยถี่กว่าแท่งบาร์โค้ด */
    function readBack(canvas) {
        var ctx = canvas.getContext('2d');
        var samples = Math.min(canvas.height, 80);
        var firstError = null;

        for (var s = 0; s < samples; s++) {
            var yy = Math.floor((s + 0.5) * canvas.height / samples);
            var line = ctx.getImageData(0, yy, canvas.width, 1).data;
            var got = readRow(line, canvas.width);

            if (got.ok) {
                got.y = yy;
                return got;
            }
            if (!firstError) { firstError = got; }
        }

        return firstError || { ok: false, message: 'ภาพว่างเปล่า' };
    }

    /* ถอดรหัสจากพิกเซลหนึ่งแถว */
    function readRow(px, width) {

        /* นับความกว้างของช่วงสีติดกัน */
        var runs = [], cur = null, len = 0;
        for (var x = 0; x < width; x++) {
            var dark = px[x * 4] < 128;
            if (cur === null) { cur = dark; len = 1; }
            else if (dark === cur) { len++; }
            else { runs.push({ dark: cur, len: len }); cur = dark; len = 1; }
        }
        runs.push({ dark: cur, len: len });

        /* ตัดขอบขาวหัวท้ายออก */
        if (runs.length && !runs[0].dark) { runs.shift(); }
        if (runs.length && !runs[runs.length - 1].dark) { runs.pop(); }
        if (runs.length < 7) { return { ok: false, message: 'ไม่พบแท่งบาร์โค้ด' }; }

        /* จำนวนช่วงต้องลงตัวกับโครงของ Code 128 พอดี
           (ทุกสัญลักษณ์ใช้ 6 ช่วง ตัวจบใช้ 7) ถ้าไม่ลงตัวแปลว่าแถวนี้
           ไม่ใช่แถบบาร์โค้ด — มักเป็นบรรทัดตัวหนังสือที่บังเอิญสลับสีถี่ */
        if ((runs.length - 1) % 6 !== 0) {
            return { ok: false, message: 'จำนวนแท่งไม่เข้าโครงของ Code 128' };
        }

        /* ขนาดโมดูลคำนวณจากความกว้างรวม ไม่ใช่จากแท่งที่แคบที่สุด
           เพราะแท่งที่แคบที่สุดอาจโดนปัดพิกเซลจนคลาดไปหนึ่งพิกเซล */
        var totalPx = 0;
        for (var r = 0; r < runs.length; r++) { totalPx += runs[r].len; }
        var symbols = (runs.length - 1) / 6;
        var mw = totalPx / (symbols * 11 + 13);

        var digits = '';
        for (var q = 0; q < runs.length; q++) {
            digits += String(Math.max(1, Math.round(runs[q].len / mw)));
        }

        /* แปลงกลับเป็นค่าทีละ 6 หลัก */
        var values = [];
        for (var s = 0; s + 6 <= digits.length - 7; s += 6) {
            var chunk = digits.substr(s, 6);
            var idx = PATTERNS.indexOf(chunk);
            if (idx < 0) { return { ok: false, message: 'อ่านสัญลักษณ์ที่ ' + (s / 6) + ' ไม่ออก (' + chunk + ')' }; }
            values.push(idx);
        }

        if (values.length < 3 || values[0] !== START_B) {
            return { ok: false, message: 'ไม่พบสัญลักษณ์เริ่มต้นของชุด B' };
        }

        var check = values[values.length - 1];
        var data = values.slice(1, values.length - 1);

        var sum = START_B;
        for (var d = 0; d < data.length; d++) { sum += data[d] * (d + 1); }
        if (sum % 103 !== check) {
            return { ok: false, message: 'ตัวตรวจสอบไม่ตรง (อ่านได้ ' + check + ' ควรเป็น ' + (sum % 103) + ')' };
        }

        var out = '';
        for (var t = 0; t < data.length; t++) { out += String.fromCharCode(data[t] + 32); }

        return { ok: true, text: out, check: check };
    }

    window.BARCODE = {
        encode: encode,
        draw: draw,
        fit: fit,
        selfTest: selfTest,
        readBack: readBack,
        QUIET_MODULES: QUIET
    };
})();
