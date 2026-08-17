/* ============================================================
 *  qr-cam.js — ส่อง QR ด้วยกล้องของเครื่อง (ใช้ร่วมกันทุกหน้า)
 *
 *  ตัวถอดรหัส: ใช้ BarcodeDetector ในตัวเบราว์เซอร์ก่อน (Android Chrome มี)
 *  ถ้าไม่มีค่อยโหลด jsQR 252 KB มาเป็นตัวสำรอง (กรณีหลักคือ iPhone)
 *
 *  เบราว์เซอร์ไม่ยอมเปิดกล้องถ้าเว็บไม่ได้มาทาง https (localhost ยกเว้น)
 *  เลี่ยงด้วยโค้ดไม่ได้ check() จึงคืนเหตุผลเป็นข้อความไทยให้ผู้เรียกไปแสดง
 *
 *  วิธีใช้:
 *      var c = QRCAM.check();  if (!c.ok) { แสดง c.why }
 *      QRCAM.start({ video: <video>, onCode: fn, onError: fn, onReady: fn });
 *      QRCAM.stop();
 *  ต้องตั้ง window.QR_CAM_LIB เป็นที่อยู่ของ assets/js/jsqr.js ก่อน
 * ============================================================ */
(function () {
    'use strict';

    var stream   = null;
    var running  = false;
    var detector = null;
    var rafId    = 0;
    var facing   = 'environment';
    var opts     = null;

    var canvas = null, ctx = null;
    var lastCode = '', missAt = 0;

    /* กันนับซ้ำ: รหัสเดิมจะนับใหม่ได้ก็ต่อเมื่อหายออกจากภาพไปก่อน
       ตรงกับการกดไกเครื่องยิงหนึ่งครั้งต่อชิ้น เล็งค้างไว้นานแค่ไหนก็ได้แค่หนึ่ง
       (ใช้ "เว้น 2 วินาทีแล้วนับใหม่" ไม่ได้ เพราะเล็งค้างเฉย ๆ จำนวนจะขึ้นเอง)
       เผื่อครึ่งวินาทีให้มือสั่นหรือหลุดโฟกัสชั่วขณะ */
    var GONE_MS = 500;


    function check() {
        if (!window.isSecureContext) {
            return {
                ok: false,
                why: 'เบราว์เซอร์ไม่ยอมให้เปิดกล้อง เพราะหน้านี้เข้าผ่าน http ธรรมดา ' +
                     'ไม่ใช่ https เป็นกฎความปลอดภัยของเบราว์เซอร์เอง ' +
                     'ต้องให้ผู้ดูแลระบบเปิด https ให้ก่อนจึงจะใช้กล้องได้'
            };
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return { ok: false, why: 'เบราว์เซอร์รุ่นนี้ไม่รองรับการเปิดกล้องผ่านเว็บ' };
        }
        return { ok: true, why: '' };
    }


    /* เตรียมตัวถอดรหัสให้เสร็จก่อนเปิดกล้อง จะได้ไม่เปิดกล้องค้างไว้
       แล้วมาพบทีหลังว่าถอดรหัสไม่ได้ */
    function prepare(done) {
        if (typeof window.BarcodeDetector !== 'undefined') {
            try {
                detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                done(true, 'ตัวถอดรหัสในตัวของเบราว์เซอร์');
                return;
            } catch (e) { detector = null; }
        }

        if (window.jsQR) { done(true, 'ตัวถอดรหัสสำรอง'); return; }

        /* เบราว์เซอร์นี้ไม่มีตัวถอดในตัว (Chrome บน Windows เป็นแบบนั้น
           มีเฉพาะบน Android, ChromeOS และ macOS) ต้องโหลดตัวสำรองมาใช้ */
        var url = window.QR_CAM_LIB;
        if (!url) {
            done(false, '', 'หน้านี้ไม่ได้บอกที่อยู่ของตัวถอดรหัสสำรอง');
            return;
        }

        var s = document.createElement('script');
        s.src = url;
        s.onload  = function () {
            if (window.jsQR) { done(true, 'ตัวถอดรหัสสำรอง'); }
            else { done(false, '', 'ไฟล์ตัวถอดรหัสสำรองโหลดมาแล้วแต่ใช้ไม่ได้'); }
        };
        /* บอกไปเลยว่าไฟล์ไหนหาย ไม่งั้นคนแก้ต้องไปไล่เปิด console เอง
           เคสที่เจอจริง: อัปโหลดขึ้นเซิร์ฟเวอร์ไม่ครบ ไฟล์นี้เลยได้ 404 */
        s.onerror = function () {
            done(false, '', 'โหลดตัวถอดรหัสสำรองไม่ได้ — ตรวจว่ามีไฟล์ ' + url + ' บนเซิร์ฟเวอร์หรือยัง');
        };
        document.body.appendChild(s);
    }


    function start(o) {
        opts = o || {};

        var c = check();
        if (!c.ok) { fail(c.why); return; }

        prepare(function (ready, engine, why) {
            if (!ready) { fail(why ? why : 'เตรียมตัวถอดรหัสไม่สำเร็จ'); return; }

            navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: facing } }
            }).then(function (s) {
                stream  = s;
                running = true;
                /* เปิดกล้องใหม่ต้องเริ่มนับใหม่ ไม่งั้นถ้าปิดแล้วเปิดโดยที่ QR
                   ตัวเดิมยังอยู่ตรงหน้า จะถูกมองว่าเป็นตัวเดิมแล้วไม่นับให้ */
                lastCode = '';
                missAt   = 0;

                var video = opts.video;
                video.srcObject = s;
                video.setAttribute('playsinline', '');
                video.muted = true;
                var p = video.play();
                if (p && p.then) { p.then(function () {}, function () {}); }

                if (opts.onReady) { opts.onReady(engine); }
                loop();
            }).catch(function (err) {
                var name = (err && err.name) || '';
                if (name === 'NotAllowedError') {
                    fail('ยังไม่ได้อนุญาตให้เว็บนี้ใช้กล้อง กดที่รูปแม่กุญแจข้างที่อยู่เว็บ แล้วเปิดสิทธิ์กล้อง');
                } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                    fail('ไม่พบกล้องบนเครื่องนี้');
                } else if (name === 'NotReadableError') {
                    fail('กล้องถูกโปรแกรมอื่นใช้อยู่ ปิดโปรแกรมนั้นก่อนแล้วลองใหม่');
                } else {
                    fail('เปิดกล้องไม่สำเร็จ' + (name ? ' (' + name + ')' : ''));
                }
            });
        });
    }


    function fail(msg) {
        if (opts && opts.onError) { opts.onError(msg); }
    }


    function stop() {
        running = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }

        if (stream) {
            var tracks = stream.getTracks();
            for (var i = 0; i < tracks.length; i++) { tracks[i].stop(); }
            stream = null;
        }
        if (opts && opts.video) { opts.video.srcObject = null; }
    }


    function flip() {
        facing = (facing === 'environment') ? 'user' : 'environment';
        if (!running) { return; }
        var keep = opts;
        stop();
        start(keep);
    }


    /* เรียกทุกเฟรม ไม่ว่าจะอ่านออกหรือไม่ — ตัวที่อ่านไม่ออกก็สำคัญ
       เพราะเป็นสัญญาณว่า QR หลุดออกจากภาพไปแล้ว */
    function seen(text) {
        var now = new Date().getTime();

        if (!text) {
            if (missAt === 0) { missAt = now; }
            else if (now - missAt >= GONE_MS) { lastCode = ''; }
            return;
        }

        missAt = 0;
        if (text === lastCode) { return; }

        lastCode = text;
        if (opts.onCode) { opts.onCode(text); }
    }


    /* วนอ่านทีละเฟรม
       ตัวถอดในตัวรับ video ตรง ๆ ได้ ส่วน jsQR ต้องวาดลง canvas ก่อน
       จึงย่อภาพเหลือกว้างไม่เกิน 640 ก่อนอ่าน เพราะกล้องมือถือส่งภาพ
       ระดับ 1080p ขึ้นไป ซึ่งใหญ่เกินจำเป็นและทำให้อ่านช้าจนภาพกระตุก */
    function loop() {
        if (!running) { return; }

        var video = opts.video;
        if (!video || video.readyState < 2) { rafId = requestAnimationFrame(loop); return; }

        if (detector) {
            detector.detect(video).then(function (found) {
                seen(found && found.length ? found[0].rawValue : '');
                if (running) { rafId = requestAnimationFrame(loop); }
            }).catch(function () {
                if (running) { rafId = requestAnimationFrame(loop); }
            });
            return;
        }

        if (window.jsQR) {
            if (!canvas) { canvas = document.createElement('canvas'); ctx = canvas.getContext('2d'); }

            var vw = video.videoWidth, vh = video.videoHeight;
            if (vw && vh) {
                var scale = Math.min(1, 640 / vw);
                canvas.width  = Math.round(vw * scale);
                canvas.height = Math.round(vh * scale);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var res = window.jsQR(img.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
                seen(res && res.data ? res.data : '');
            }
        }

        rafId = requestAnimationFrame(loop);
    }


    /* ปิดกล้องเมื่อออกจากหน้าหรือสลับไปแอปอื่น
       ไฟกล้องที่ค้างอยู่ทั้งที่ไม่ได้ใช้ ทั้งกินแบตและทำให้คนไม่ไว้ใจระบบ */
    window.addEventListener('beforeunload', stop);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden && running) { stop(); }
    });

    window.QRCAM = {
        check:   check,
        start:   start,
        stop:    stop,
        flip:    flip,
        running: function () { return running; },
        facing:  function () { return facing; }
    };
})();
