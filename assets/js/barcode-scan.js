/* ============================================================
 *  barcode-scan.js — รับรหัสจากเครื่องอ่านบาร์โค้ดแบบเสียบ USB
 *
 *  เครื่องอ่าน USB ประกาศตัวเป็นคีย์บอร์ด แล้ว "พิมพ์" รหัสทีละตัวปิดท้ายด้วย Enter
 *  จึงไม่ต้องลงไดรเวอร์ ไม่ต้องขอสิทธิ์เบราว์เซอร์ ไม่ต้องแตะฝั่งเซิร์ฟเวอร์
 *
 *  แต่เพราะเป็นคีย์บอร์ด สิ่งที่ส่งมาคือ "ตำแหน่งปุ่ม" แล้ว Windows แปลงเป็น
 *  ตัวอักษรตามภาษาที่เปิดอยู่ ถ้าเปิดไทยค้างไว้จะได้ตัวอักษรไทยมั่ว ๆ
 *  ซึ่งเกิดบ่อยในออฟฟิศไทย จึงอ่าน event.code (KeyC, Digit0, Minus)
 *  ที่ไม่ขึ้นกับภาษา แล้วแปลงเป็นตัวอักษรเอง
 *
 *  ใช้ช่องเฉพาะแทนการดักทั้งหน้า เพราะหน้านี้มีช่องจำนวนกับหมายเหตุที่ต้อง
 *  พิมพ์จริง คนพิมพ์เร็วจะถูกตีความเป็นการยิงแล้วตัวเลขหายไป
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    /* ---------- ตำแหน่งปุ่มบนแป้น -> ตัวอักษร ----------
     * ครอบคลุมเฉพาะอักขระที่รหัสสินค้าใช้จริง คือ A-Z 0-9 และขีดกลาง
     * รวมแป้นตัวเลขด้านขวาด้วย เพราะเครื่องอ่านบางรุ่นตั้งค่ามาให้ส่งทางนั้น
     *
     * ไม่แปลง Shift เพราะรหัสสินค้าเป็นตัวพิมพ์ใหญ่ล้วนอยู่แล้ว
     * กด Shift หรือไม่กดก็ได้ตัวเดียวกัน
     */
    function charOf(code) {
        if (!code) { return null; }

        if (code.length === 4 && code.indexOf('Key') === 0) {
            return code.charAt(3);                       /* KeyC -> C */
        }
        if (code.length === 6 && code.indexOf('Digit') === 0) {
            return code.charAt(5);                       /* Digit7 -> 7 */
        }
        if (code.length === 7 && code.indexOf('Numpad') === 0) {
            var c = code.charAt(6);
            if (c >= '0' && c <= '9') { return c; }
            return null;
        }
        if (code === 'Minus' || code === 'NumpadSubtract') { return '-'; }

        return null;
    }

    function isEnter(code, key) {
        return code === 'Enter' || code === 'NumpadEnter' || key === 'Enter';
    }

    /* ---------- ผูกช่องยิงบาร์โค้ด ----------
     *  opts:
     *    input     ช่อง <input> ที่จะใช้ยิง
     *    onScan    function (code) — เรียกเมื่อยิงจบหนึ่งรหัส
     *    minLength ความยาวต่ำสุดที่ถือว่าเป็นรหัส (กัน Enter เปล่า ๆ)
     */
    function attach(opts) {
        var input = opts.input;
        if (!input) { return null; }

        var buffer = '';
        var minLength = opts.minLength || 3;

        function flush() {
            var code = buffer;
            buffer = '';
            input.value = '';
            if (code.length >= minLength && typeof opts.onScan === 'function') {
                opts.onScan(code);
            }
        }

        input.addEventListener('keydown', function (e) {
            /* ปล่อยให้คีย์ผสม (Ctrl+V ฯลฯ) ทำงานตามปกติ
               บางคนจะสะดวกกว่าถ้าคัดลอกรหัสมาวางแทนการยิง */
            if (e.ctrlKey || e.altKey || e.metaKey) { return; }

            if (isEnter(e.code, e.key)) {
                e.preventDefault();
                flush();
                return;
            }

            if (e.code === 'Backspace' || e.key === 'Backspace') {
                e.preventDefault();
                buffer = buffer.slice(0, -1);
                input.value = buffer;
                return;
            }

            if (e.code === 'Escape' || e.key === 'Escape') {
                e.preventDefault();
                buffer = '';
                input.value = '';
                return;
            }

            var ch = charOf(e.code);

            /* เบราว์เซอร์เก่ามาก ๆ ไม่มี event.code ให้ใช้ตัวอักษรที่ได้มาแทน
               กรณีนั้นจะพังถ้าเปิดภาษาไทยค้างไว้ แต่ก็ยังดีกว่าใช้ไม่ได้เลย */
            if (ch === null && !('code' in e) && e.key && e.key.length === 1) {
                ch = e.key.toUpperCase();
            }

            /* ปุ่มที่ไม่ได้อยู่ในชุดอักขระของรหัสสินค้า ไม่รับเข้าบัฟเฟอร์
               และห้ามให้มันตกลงช่อง ไม่งั้นสิ่งที่เห็นกับสิ่งที่เก็บไว้จะไม่ตรงกัน */
            e.preventDefault();
            if (ch !== null) {
                buffer += ch;
                input.value = buffer;
            }
        });

        /* วางด้วย Ctrl+V — ตัดอักขระที่ใช้ไม่ได้ทิ้งแล้วยิงเลย
           คนที่ได้รหัสมาทางแชทจะได้ไม่ต้องพิมพ์ตามทีละตัว */
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = '';
            if (e.clipboardData) { text = e.clipboardData.getData('text') || ''; }
            else if (window.clipboardData) { text = window.clipboardData.getData('Text') || ''; }

            buffer = text.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
            input.value = buffer;
            flush();
        });

        input.addEventListener('blur', function () {
            buffer = '';
            input.value = '';
        });

        return {
            focus: function () { input.focus(); },
            clear: function () { buffer = ''; input.value = ''; }
        };
    }

    window.BarcodeScan = {
        attach: attach,
        charOf: charOf
    };
})();
