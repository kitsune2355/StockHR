/* ============================================================
 *  qr-cam.js — ส่อง QR / บาร์โค้ด ด้วยกล้องของเครื่อง (ใช้ร่วมกันทุกหน้า)
 *
 *  ตัวถอดรหัส: ใช้ BarcodeDetector ในตัวเบราว์เซอร์ก่อน (Android Chrome มี)
 *  ถ้าไม่มีค่อยโหลด ZXing 337 KB มาเป็นตัวสำรอง (กรณีหลักคือ iPhone)
 *  ทั้งสองทางอ่านได้ทั้ง QR และบาร์โค้ดแท่ง รวม Code 128 ที่ระบบนี้ใช้
 *  พิมพ์ฉลาก (ดู assets/js/barcode.js)
 *
 *  ---------------------------------------------------------------
 *  ทำไมเปลี่ยนจาก jsQR มาเป็น ZXing
 *  ---------------------------------------------------------------
 *  jsQR อ่านได้เฉพาะ QR โดยธรรมชาติของไลบรารี ไม่มีทางอ่านบาร์โค้ดแท่งได้
 *  ต่อให้ภาพชัดแค่ไหน เครื่องที่ต้องใช้ตัวสำรองจึงส่องฉลากสินค้าไม่ได้เลย
 *  ทั้งที่ฉลากทุกใบมีบาร์โค้ด Code 128 อยู่
 *
 *  ZXing อ่านได้ทั้งสองแบบ ใหญ่กว่า 85 KB แต่เร็วกว่ามาก — วัดบนเฟรม
 *  640x480 ที่ไม่มีรหัสอยู่ (กรณีที่เกิดบ่อยที่สุดตอนส่อง) jsQR ใช้ 206 ms
 *  ส่วน ZXing ที่จำกัดรูปแบบไว้ใช้ 6.5 ms ต่อเฟรม
 *
 *  ทั้งสองตัวโหลดเมื่อจำเป็นเท่านั้น เครื่องที่มีตัวถอดในตัวไม่โหลดเลย
 *
 *  onReady บอกความสามารถจริงกลับไป ให้ผู้เรียกเขียนบนจอได้ตรง
 *  ไม่ใช่ปล่อยให้ผู้ใช้เล็งค้างไว้แล้วสงสัยว่าทำไมไม่ติด
 *
 *  เบราว์เซอร์ไม่ยอมเปิดกล้องถ้าเว็บไม่ได้มาทาง https (localhost ยกเว้น)
 *  เลี่ยงด้วยโค้ดไม่ได้ check() จึงคืนเหตุผลเป็นข้อความไทยให้ผู้เรียกไปแสดง
 *
 *  วิธีใช้:
 *      var c = QRCAM.check();  if (!c.ok) { แสดง c.why }
 *      QRCAM.start({ video: <video>, onCode: fn, onError: fn, onReady: fn });
 *      QRCAM.stop();
 *  onReady ได้ { engine: 'ชื่อตัวถอดรหัส', barcode: true/false, formats: [] }
 *  ต้องตั้ง window.SCAN_CAM_LIB เป็นที่อยู่ของ assets/js/zxing.js ก่อน
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

    /* ตัวสำรอง ZXing — สร้างครั้งเดียวตอนเตรียมตัวถอดรหัส
       zxGray คือบัฟเฟอร์ความสว่างที่ใช้ซ้ำทุกเฟรม ไม่จองใหม่ */
    var zxReader = null, zxHints = null, zxGray = null;

    /* กันนับซ้ำ: รหัสเดิมจะนับใหม่ได้ก็ต่อเมื่อหายออกจากภาพไปก่อน
       ตรงกับการกดไกเครื่องยิงหนึ่งครั้งต่อชิ้น เล็งค้างไว้นานแค่ไหนก็ได้แค่หนึ่ง
       (ใช้ "เว้น 2 วินาทีแล้วนับใหม่" ไม่ได้ เพราะเล็งค้างเฉย ๆ จำนวนจะขึ้นเอง)
       เผื่อครึ่งวินาทีให้มือสั่นหรือหลุดโฟกัสชั่วขณะ */
    var GONE_MS = 500;

    /* ---------- รูปแบบรหัสที่จะให้กล้องอ่าน ----------
     * qr_code   ป้าย QR ที่ระบบสร้างเอง
     * code_128  บาร์โค้ดที่ระบบสร้างเอง — รหัสสินค้าทุกตัวเป็นแบบนี้
     *           (assets/js/barcode.js วาด Code 128 ชุดอักขระ B)
     * ที่เหลือ   บาร์โค้ดที่ติดมากับสินค้าจากผู้ขาย ใส่ไว้เพราะไม่มีต้นทุนเพิ่ม
     *           ตัวถอดรหัสในตัวรองรับอยู่แล้ว ยิงติดก็เข้าเส้นทางเดิมที่หารหัส
     *           ในทะเบียน ถ้าไม่มีในระบบก็ได้ข้อความว่าไม่รู้จักรหัสนี้ตามปกติ
     *
     * ต้องคัดเฉพาะรูปแบบที่เครื่องนั้นรองรับจริงก่อนสร้างตัวถอดรหัส
     * ขอรูปแบบที่ไม่รองรับปนไป ตัวถอดจะพังทั้งชุด แล้วเสียการอ่าน QR
     * ที่เคยใช้ได้ไปด้วย ซึ่งแย่กว่าเดิม
     */
    var WANT = ['qr_code', 'code_128', 'code_39', 'code_93', 'codabar',
                'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf'];

    /* รูปแบบที่ตัวถอดรหัสตัวปัจจุบันอ่านได้จริง */
    var active = [];

    function has(list, value) {
        for (var i = 0; i < list.length; i++) {
            if (list[i] === value) { return true; }
        }
        return false;
    }

    /* ความสามารถที่ได้จริง ส่งกลับให้ผู้เรียกไปเขียนบนจอ
       barcode = อ่านบาร์โค้ดแท่งที่ระบบนี้พิมพ์ (Code 128) ได้หรือไม่
       ซึ่งเป็นคำถามเดียวที่ผู้ใช้สนใจ ไม่ใช่รายชื่อรูปแบบทั้งหมด */
    function info(engine) {
        return {
            engine:  engine,
            barcode: has(active, 'code_128'),
            formats: active
        };
    }


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


    /* ---------- ถามเครื่องว่าอ่านรูปแบบไหนได้บ้าง ----------
     * getSupportedFormats() เป็น static แบบ Promise เพราะบางเครื่องต้องถาม
     * ระบบปฏิบัติการก่อน จึงตอบทันทีไม่ได้
     *
     * รุ่นเก่าบางรุ่นมี BarcodeDetector แต่ไม่มีตัวบอกรายการ กรณีนั้นขอแค่ QR
     * ซึ่งเป็นรูปแบบที่ทุกรุ่นรองรับแน่นอน ดีกว่าเดาแล้วเสียการอ่าน QR ไปทั้งชุด
     */
    function pickFormats(cb) {
        var BD = window.BarcodeDetector;

        if (typeof BD.getSupportedFormats !== 'function') { cb(['qr_code']); return; }

        BD.getSupportedFormats().then(function (able) {
            var use = [];
            for (var i = 0; i < WANT.length; i++) {
                if (has(able, WANT[i])) { use.push(WANT[i]); }
            }
            cb(use);
        })['catch'](function () { cb(['qr_code']); });
    }


    /* เตรียมตัวถอดรหัสให้เสร็จก่อนเปิดกล้อง จะได้ไม่เปิดกล้องค้างไว้
       แล้วมาพบทีหลังว่าถอดรหัสไม่ได้ */
    function prepare(done) {
        detector = null;
        active   = [];

        if (typeof window.BarcodeDetector !== 'undefined') {
            pickFormats(function (use) {
                if (use.length > 0) {
                    try {
                        detector = new window.BarcodeDetector({ formats: use });
                        active   = use;
                        done(true, info('ตัวถอดรหัสในตัวของเบราว์เซอร์'));
                        return;
                    } catch (e) { detector = null; active = []; }
                }
                /* สร้างตัวถอดในตัวไม่สำเร็จ ตกไปใช้ตัวสำรองซึ่งอ่านได้เฉพาะ QR
                   ดีกว่าไม่ได้อะไรเลย */
                fallback(done);
            });
            return;
        }

        fallback(done);
    }


    /* ---------- ตัวสำรอง ZXing ----------
     * รูปแบบที่เปิดให้ตัวสำรองอ่าน จำกัดไว้แค่ที่ระบบนี้ใช้จริง
     * ไม่ใช่เพราะอ่านแบบอื่นไม่ได้ แต่เพราะทุกรูปแบบที่เปิดคือตัวอ่านอีกตัว
     * ที่ต้องวิ่งจนจบทุกเฟรมที่ไม่พบรหัส ซึ่งเป็นเฟรมส่วนใหญ่ตอนกำลังเล็ง
     * (วัดจริงบนเฟรม 640x480 ที่ไม่มีรหัส: 4 รูปแบบ 6.5 ms · ทุกรูปแบบ 25.7 ms)
     */
    function zxFormats() {
        var B = window.ZXing.BarcodeFormat;
        return [B.QR_CODE, B.CODE_128, B.CODE_39, B.EAN_13];
    }

    function zxSetup() {
        var Z = window.ZXing;

        /* hints ต้องส่งเข้า decode() ทุกครั้ง ไม่ใช่ตั้งไว้ครั้งเดียวด้วย setHints()
           เพราะ decode(image) ที่ไม่ได้ส่ง hints มาด้วย จะเรียก setHints(undefined)
           ทับของเดิมทิ้ง แล้วกลับไปลองทุกรูปแบบ ช้าลงสี่เท่าโดยไม่มีอะไรฟ้อง */
        zxHints = new Map();
        zxHints.set(Z.DecodeHintType.POSSIBLE_FORMATS, zxFormats());

        zxReader = new Z.MultiFormatReader();
        active   = ['qr_code', 'code_128', 'code_39', 'ean_13'];
    }

    function fallback(done) {
        if (window.ZXing) {
            zxSetup();
            done(true, info('ตัวถอดรหัสสำรอง'));
            return;
        }

        /* เบราว์เซอร์นี้ไม่มีตัวถอดในตัว (Chrome บน Windows เป็นแบบนั้น
           มีเฉพาะบน Android, ChromeOS และ macOS) ต้องโหลดตัวสำรองมาใช้ */
        var url = window.SCAN_CAM_LIB;
        if (!url) {
            done(false, null, 'หน้านี้ไม่ได้บอกที่อยู่ของตัวถอดรหัสสำรอง');
            return;
        }

        var s = document.createElement('script');
        s.src = url;
        s.onload  = function () {
            if (window.ZXing) {
                zxSetup();
                done(true, info('ตัวถอดรหัสสำรอง'));
            } else {
                done(false, null, 'ไฟล์ตัวถอดรหัสสำรองโหลดมาแล้วแต่ใช้ไม่ได้');
            }
        };
        /* บอกไปเลยว่าไฟล์ไหนหาย ไม่งั้นคนแก้ต้องไปไล่เปิด console เอง
           เคสที่เจอจริง: อัปโหลดขึ้นเซิร์ฟเวอร์ไม่ครบ ไฟล์นี้เลยได้ 404 */
        s.onerror = function () {
            done(false, null, 'โหลดตัวถอดรหัสสำรองไม่ได้ — ตรวจว่ามีไฟล์ ' + url + ' บนเซิร์ฟเวอร์หรือยัง');
        };
        document.body.appendChild(s);
    }


    /* ---------- อ่านหนึ่งเฟรมด้วยตัวสำรอง ----------
     * ZXing รับภาพเป็นค่าความสว่างช่องเดียว (w x h) ไม่ใช่ RGBA จาก canvas
     * ส่ง RGBA เข้าไปตรง ๆ จะไม่พังแต่หาไม่เจอสักครั้ง เพราะมันตีความว่า
     * ภาพกว้างกว่าความจริงสี่เท่า
     *
     * สูตรถ่วงน้ำหนักเป็นตัวเดียวกับ ZXing ต้นฉบับ คิดด้วยจำนวนเต็มล้วน
     * เพราะต้องวิ่ง 307,200 รอบต่อเฟรมบนภาพ 640x480
     */
    function zxRead(img) {
        var Z = window.ZXing;
        var n = img.width * img.height;

        /* ใช้บัฟเฟอร์เดิมซ้ำ จองใหม่ทุกเฟรมคือให้ตัวเก็บขยะทำงานตลอดเวลา
           แล้วภาพจะกระตุกเป็นจังหวะ */
        if (!zxGray || zxGray.length !== n) { zxGray = new Uint8ClampedArray(n); }

        var d = img.data;
        for (var i = 0, p = 0; i < n; i++, p += 4) {
            zxGray[i] = (306 * d[p] + 601 * d[p + 1] + 117 * d[p + 2] + 512) >> 10;
        }

        try {
            var src = new Z.RGBLuminanceSource(zxGray, img.width, img.height);
            var res = zxReader.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(src)), zxHints);
            return (res && res.getText) ? res.getText() : '';
        } catch (e) {
            /* ไม่พบรหัสในเฟรมนี้ = เรื่องปกติของเกือบทุกเฟรม ไม่ใช่ข้อผิดพลาด */
            return '';
        } finally {
            zxReader.reset();
        }
    }


    function start(o) {
        opts = o || {};

        var c = check();
        if (!c.ok) { fail(c.why); return; }

        prepare(function (ready, able, why) {
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

                if (opts.onReady) { opts.onReady(able); }
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
       เพราะเป็นสัญญาณว่ารหัสหลุดออกจากภาพไปแล้ว */
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
       ตัวถอดในตัวรับ video ตรง ๆ ได้ ส่วนตัวสำรองต้องวาดลง canvas ก่อน
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

        if (zxReader) {
            if (!canvas) { canvas = document.createElement('canvas'); ctx = canvas.getContext('2d'); }

            var vw = video.videoWidth, vh = video.videoHeight;
            if (vw && vh) {
                var scale = Math.min(1, 640 / vw);
                canvas.width  = Math.round(vw * scale);
                canvas.height = Math.round(vh * scale);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                seen(zxRead(ctx.getImageData(0, 0, canvas.width, canvas.height)));
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
        facing:  function () { return facing; },

        /* ความสามารถของรอบที่กำลังทำงานอยู่ ตอบได้หลัง start() เท่านั้น
           เพราะต้องถามเครื่องก่อนว่ารองรับรูปแบบไหนบ้าง */
        able:    function () { return info(detector ? 'ตัวถอดรหัสในตัวของเบราว์เซอร์' : 'ตัวถอดรหัสสำรอง'); }
    };
})();
