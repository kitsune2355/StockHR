/* ============================================================
 *  assets/js/signpad.js
 *  ผืนสำหรับเซ็นชื่อด้วยนิ้วหรือเมาส์
 *
 *  เขียนเองแทนการดึงไลบรารีจากภายนอก เพราะสิ่งที่ต้องการมีแค่
 *  "ลากเส้นแล้วได้รูป" ซึ่งเป็นโค้ดร้อยกว่าบรรทัด ไม่คุ้มกับการเพิ่ม
 *  ไฟล์ vendor อีกตัวที่ต้องคอยตามอัปเดตและตรวจใบอนุญาต
 *
 *  วิธีใช้:
 *      var pad = SignPad.create(document.getElementById('ackCanvas'));
 *      pad.isEmpty();      // ยังไม่ได้เซ็นเลยไหม
 *      pad.clear();
 *      pad.toDataURL();    // 'data:image/png;base64,...'
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */

(function () {
    'use strict';

    function create(canvas) {
        if (!canvas || !canvas.getContext) { return null; }

        var ctx     = canvas.getContext('2d');
        var drawing = false;
        var dirty   = false;      // เคยลากเส้นจริงไปแล้วหรือยัง
        var last    = null;
        var onStartFns = [];


        /* ---------- ปรับความละเอียดผืนให้ตรงกับจอ ----------
         * canvas มีสองขนาดที่ต้องตั้งแยกกัน: ขนาดที่แสดง (CSS) กับขนาดของภาพจริง
         * ถ้าตั้งแต่ CSS อย่างเดียว ภาพจะถูกยืดจากค่าเริ่มต้น 300x150 จนเบลอ
         * และตำแหน่งที่นิ้วแตะจะไม่ตรงกับเส้นที่วาดออกมา
         *
         * คูณด้วย devicePixelRatio ด้วย เพราะมือถือจอละเอียดสูงจะได้ลายเซ็นคมชัด
         * ไม่งั้นลายเซ็นบนกระดาษที่พิมพ์ออกมาจะหยาบเป็นขั้นบันได
         */
        function resize() {
            var ratio = window.devicePixelRatio || 1;
            var rect  = canvas.getBoundingClientRect();

            if (rect.width === 0 || rect.height === 0) { return; }

            /* เก็บภาพเดิมไว้ก่อน เพราะการตั้ง width/height ล้างผืนเสมอ
               ผู้ใช้ที่หมุนจอระหว่างเซ็นจะได้ไม่เสียลายเซ็นที่เซ็นไปแล้ว */
            var backup = dirty ? canvas.toDataURL('image/png') : null;

            canvas.width  = Math.round(rect.width  * ratio);
            canvas.height = Math.round(rect.height * ratio);

            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth   = 2.2;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.strokeStyle = '#0f172a';

            if (backup) {
                var img = new Image();
                img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
                img.src = backup;
            }
        }


        /* ---------- ตำแหน่งที่แตะ เทียบกับมุมซ้ายบนของผืน ----------
         * รองรับทั้งเมาส์และนิ้ว โดยดู touches ก่อนเสมอ
         * เพราะเบราว์เซอร์บนมือถือส่ง event ทั้งสองแบบ และค่าของ touch แม่นกว่า
         */
        function pointOf(e) {
            var rect = canvas.getBoundingClientRect();
            var src  = (e.touches && e.touches.length > 0) ? e.touches[0] : e;

            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }


        function start(e) {
            e.preventDefault();
            drawing = true;
            last    = pointOf(e);

            for (var i = 0; i < onStartFns.length; i++) { onStartFns[i](); }
        }


        function move(e) {
            if (!drawing) { return; }
            e.preventDefault();

            var p = pointOf(e);

            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();

            last  = p;
            dirty = true;
        }


        function end() {
            /* แตะแล้วปล่อยโดยไม่ลาก = จุดเดียว ยังไม่นับว่าเซ็นแล้ว
               กันคนกดผ่านไปโดยแตะจอครั้งเดียวแล้วผ่านการตรวจ */
            drawing = false;
            last    = null;
        }


        canvas.addEventListener('mousedown',  start);
        canvas.addEventListener('mousemove',  move);
        window.addEventListener('mouseup',    end);

        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove',  move,  { passive: false });
        canvas.addEventListener('touchend',   end);
        canvas.addEventListener('touchcancel', end);

        window.addEventListener('resize', resize);

        /* ผืนอยู่ในกล่อง SweetAlert2 ที่กำลังเล่นอนิเมชันขยายเข้ามา
         * วัดขนาดตอนนี้จะได้ค่าระหว่างทาง ไม่ใช่ขนาดสุดท้าย (เคยได้ 288 ทั้งที่จริง 412)
         * ผลคือภาพลายเซ็นถูกยืดจนเบลอ และตำแหน่งที่นิ้วแตะไม่ตรงกับเส้นที่วาดออกมา
         *
         * วัดสองรอบ: รอบแรกให้ใช้งานได้ทันที รอบที่สองหลังอนิเมชันจบเพื่อความคมชัด
         * resize() เก็บภาพเดิมไว้ก่อนปรับขนาดอยู่แล้ว คนที่เซ็นเร็วจึงไม่เสียลายเซ็น
         */
        window.setTimeout(resize, 0);
        window.setTimeout(resize, 350);


        return {
            clear: function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                dirty = false;

                var hint = document.querySelector('.ack-pad-hint');
                if (hint) { hint.hidden = false; }
            },

            isEmpty: function () { return !dirty; },

            onStart: function (fn) { onStartFns.push(fn); },

            /* ---------- ส่งออกเป็นรูป ----------
             * วาดพื้นขาวรองไว้ก่อน ไม่ส่งภาพที่พื้นหลังโปร่งใสออกไป
             * เพราะลายเซ็นสีเข้มบนพื้นโปร่ง พอไปวางบนพื้นเข้มในโหมดมืด
             * หรือในโปรแกรมอ่าน PDF บางตัว จะกลายเป็นมองไม่เห็นอะไรเลย
             */
            toDataURL: function () {
                var out = document.createElement('canvas');
                out.width  = canvas.width;
                out.height = canvas.height;

                var octx = out.getContext('2d');
                octx.fillStyle = '#ffffff';
                octx.fillRect(0, 0, out.width, out.height);
                octx.drawImage(canvas, 0, 0);

                return out.toDataURL('image/png');
            }
        };
    }

    window.SignPad = { create: create };
})();
