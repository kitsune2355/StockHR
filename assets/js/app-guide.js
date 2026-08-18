/* ============================================================
 *  assets/js/app-guide.js
 *  คู่มือการใช้งานแบบพาชี้ทีละจุด — ตัวเชื่อมระหว่างระบบกับ driver.js
 *
 *  ทำงานทุกหน้าโดยอัตโนมัติ หน้าไหนไม่ต้องแก้อะไรเลย
 *
 *  ลำดับการทำงาน:
 *    1. ถาม api/guide.php ว่าหน้านี้ (APP_CTX.page) มีคู่มือไหม
 *    2. ไม่มี            -> ซ่อนปุ่มคู่มือบนแถบบนไว้เหมือนเดิม จบ
 *    3. มี               -> เปิดปุ่มให้กดได้
 *    4. ยังไม่เคยดู และคู่มือตั้ง auto_start ไว้ -> เปิดให้เองครั้งแรกครั้งเดียว
 *
 *  ขั้นที่หา element ไม่เจอจะถูกข้ามทิ้ง — คู่มือชุดเดียวใช้กับทุกสิทธิ์
 *  แต่หน้าจอแต่ละสิทธิ์ไม่เหมือนกัน ถ้าไม่กรอง driver.js จะไฮไลต์ที่ว่างกลางจอ
 *  ต้องกรองที่นี่ ไม่ใช่ฝั่งเซิร์ฟเวอร์ เพราะที่นี่ที่เดียวที่รู้ว่าจอจริงมีอะไร
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์ (ไม่ใช้ let/const/arrow)
 * ============================================================ */

(function () {
    'use strict';

    var CTX = window.APP_CTX || {};
    var API = 'api/guide.php';

    var btn      = document.getElementById('appGuideBtn');
    var current  = null;    // ตัวคุม driver.js ที่กำลังเปิดอยู่ (null = ปิดอยู่)
    var loaded   = null;    // คู่มือที่โหลดมาแล้ว กันโหลดซ้ำทุกครั้งที่กดปุ่ม
    var starting = false;   // กันกดรัวจนเปิดซ้อนกันสองชุด
    var leaving  = false;   // กำลังออกจากหน้านี้ (ดูเหตุผลที่ markSeen)


    /* ---------- คุยกับ API ---------- */
    function post(payload) {
        payload.csrf_token = CTX.csrf;

        return fetch(API, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }


    /* ---------- driver.js พร้อมใช้หรือยัง ----------
     * ไลบรารีเปิดตัวเองไว้ที่ window.driver.js.driver
     * ถ้าไฟล์โหลดไม่ผ่าน (เน็ตบริษัทบล็อก หรือไฟล์หาย) ต้องไม่ทำให้หน้าพัง
     * แค่ปล่อยให้ปุ่มคู่มือซ่อนอยู่ต่อไป ผู้ใช้ยังทำงานได้ครบทุกอย่าง */
    function driverFn() {
        if (window.driver && window.driver.js && typeof window.driver.js.driver === 'function') {
            return window.driver.js.driver;
        }
        return null;
    }


    /* ---------- แปลงขั้นตอนจากฐานข้อมูลเป็นรูปแบบที่ driver.js เข้าใจ ----------
     * ขั้นตอนที่ไม่ระบุ selector = กล่องข้อความกลางจอ ไม่ชี้ที่ไหน (ใช้เปิด/ปิดเรื่อง)
     * ขั้นตอนที่ระบุ selector แต่หา element ไม่เจอ = ข้ามทิ้ง
     */
    function buildSteps(steps) {
        var out = [];

        for (var i = 0; i < steps.length; i++) {
            var s   = steps[i];
            var sel = (s.target_selector || '').trim();

            var popover = {
                title:       s.title || '',
                description: s.description || '',
                side:        s.side  || 'bottom',
                align:       s.align || 'start'
            };

            if (sel === '') {
                out.push({ popover: popover });
                continue;
            }

            /* selector ที่พิมพ์ผิดจะทำให้ querySelector โยน error ทั้งชุด
               ดักไว้ทีละขั้น คู่มือที่เหลือจะได้ยังใช้ได้ */
            var el = null;
            try {
                el = document.querySelector(sel);
            } catch (e) {
                el = null;
            }

            /* บนจอเล็ก ตัวเลือกสินค้าแบบสองฝั่งแสดงทีละฝั่งผ่านแท็บ ฝั่งที่ไม่ได้เลือก
               ถูกซ่อนด้วย display:none ขั้นตอนที่ชี้ไปที่ฝั่งนั้นจึงจะถูกกรองทิ้งตรงนี้
               พาไปที่ฝั่งของมันก่อนวัด ขั้นตอนจะได้อยู่ครบ
               (ตอนเดินคู่มือจริง onHighlighted สลับให้อีกทีตามขั้นที่กำลังอยู่) */
            if (el && window.TfTabs && window.TfTabs.reveal) { window.TfTabs.reveal(el); }

            if (!el || !isVisible(el)) { continue; }

            out.push({ element: el, popover: popover });
        }
        return out;
    }


    /* ---------- element นี้เห็นได้จริงบนจอไหม ----------
     * ไม่ได้เช็คแค่ว่ามีอยู่ใน DOM เพราะหลายส่วนถูกซ่อนด้วย hidden หรือ display:none
     * เช่นแถบกรองด้านขวาที่ยังไม่ได้กดเปิด หรือเมนูข้างบนจอมือถือ
     * ไฮไลต์สิ่งที่มองไม่เห็นคือการชี้ไปที่ความว่างเปล่า
     */
    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function isVisible(el) {
        if (el.hidden) { return false; }

        var rect = el.getBoundingClientRect();
        if (rect.width <= 0 && rect.height <= 0) { return false; }

        var style = window.getComputedStyle(el);
        return !(style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0');
    }


    /* ---------- เปิดคู่มือ ---------- */
    function start(guide) {
        var factory = driverFn();
        if (!factory) { return; }

        /* เมนูข้างบนจอเล็กถูกซ่อนไว้นอกจอ ขั้นตอนที่ชี้ไปที่เมนูจึงถูกกรองทิ้งไปแล้ว
           แต่ฉากหลังทึบของเมนูอาจค้างอยู่และไปบังกล่องคู่มือ ปิดให้เรียบร้อยก่อน */
        closeMobileSidebar();

        /* ---------- ชุดข้อมูลสาธิต ----------
         * บางหน้าตอนเปิดคู่มือยังว่างอยู่ คำอธิบายจึงชี้ไปที่ที่ว่างเปล่า
         * หน้าที่ต้องการสาธิตประกาศ window.GuideDemo ไว้ ที่นี่แค่เรียกให้ถูกจังหวะ
         *
         * ต้องทำก่อน buildSteps เสมอ เพราะ buildSteps ตัดขั้นที่เป้าหมายยังไม่โผล่
         * ใส่ทีหลังแล้วขั้นที่ชี้ไปยังของสาธิตจะถูกตัดทิ้งไปก่อน
         * teardown อยู่ใน onDestroyed จึงทำงานทั้งตอนดูจบและกดปิดกลางคัน */
        var demo   = window.GuideDemo;
        var demoOn = false;

        if (demo && demo.page === guide.page_key && typeof demo.setup === 'function') {
            try { demoOn = (demo.setup() !== false); } catch (e) { demoOn = false; }
        }

        var steps = buildSteps(guide.steps || []);
        if (steps.length === 0) {
            if (demoOn && typeof demo.teardown === 'function') {
                try { demo.teardown(); } catch (e) { /* ไม่ให้ค้างของสาธิตไว้ */ }
            }
            if (typeof window.appInfo === 'function') {
                window.appInfo('คู่มือของหน้านี้ยังไม่มีขั้นตอนที่ใช้ได้กับสิทธิ์ของคุณ');
            }
            return;
        }

        current = factory({
            showProgress:     true,
            allowClose:       true,
            /* ---------- ความเข้มของฉากหลัง ----------
             * โหมดสว่าง: หน้าเว็บสว่าง ฉากทึบเข้ม 72% ก็พอให้ของที่ถูกไฮไลต์เด่นขึ้นมา
             *
             * โหมดมืด: หน้าเว็บเข้มอยู่แล้ว การเอาสีเข้มไปทับสีเข้มไม่ได้สร้างความต่าง
             * ของที่ถูกไฮไลต์เลยจมหายไปกับฉากหลัง (ผู้ใช้แจ้งว่ามองไม่เห็นว่ากำลังชี้อะไร)
             * จึงเพิ่มความทึบเป็น 88% ให้ส่วนที่ไม่ได้ไฮไลต์จมลงไปมากกว่าเดิม
             * แล้วตีกรอบเรืองแสงรอบตัวที่ไฮไลต์อีกชั้นใน app.css (.driver-active-element)
             */
            overlayColor:     isDark() ? 'rgba(2, 6, 23, 0.88)' : 'rgba(15, 23, 42, 0.72)',
            stagePadding:     6,
            stageRadius:      10,
            popoverClass:     'hl-guide',
            nextBtnText:      'ถัดไป',
            prevBtnText:      'ย้อนกลับ',
            doneBtnText:      'เข้าใจแล้ว',
            progressText:     'ขั้นที่ {{current}} จาก {{total}}',
            steps:            steps,

            /* เรียกทุกครั้งที่ย้ายไปขั้นใหม่ หลัง driver.js เลื่อนจอเสร็จแล้ว
               หน่วง 0 มิลลิวินาทีอีกชั้นเพราะเบราว์เซอร์บางตัวเลื่อนจริงในเฟรมถัดไป
               ถ้าดึงกลับทันทีจะโดนการเลื่อนรอบนั้นทับอีกที */
            /* เรียกทุกครั้งที่ย้ายไปขั้นใหม่ หลัง driver.js เลื่อนจอและวางกล่องเสร็จแล้ว
             *
             * ปกติ #appMain ตั้ง overflow: clip ไว้ (ดู app.css) จึงเลื่อนไม่ได้ตั้งแต่แรก
             * ตรงนี้เป็นตาข่ายรับกรณีที่ยังมีกล่อง overflow: hidden ตัวอื่นถูกเลื่อน
             *
             * ถ้าต้องดึงกลับจริง ต้องสั่ง refresh() ตามด้วยเสมอ เพราะการดึงกลับ
             * ทำให้ทุกอย่างบนหน้าขยับ แต่กล่องคำอธิบายกับกรอบไฮไลต์ถูกวางไว้
             * ตามตำแหน่งเก่าไปแล้ว ถ้าไม่คำนวณใหม่จะเหลื่อมกันเท่ากับระยะที่ดึงกลับ
             */
            onHighlighted: function (element) {

                /* ขั้นตอนนี้ชี้ไปที่ฝั่งที่ถูกซ่อนอยู่หลังแท็บบนจอเล็กหรือเปล่า
                   ถ้าใช่ ต้องสลับไปฝั่งนั้นก่อน ไม่งั้นกรอบไฮไลต์จะไปตีที่ว่างเปล่า
                   การสลับทำให้ทุกอย่างขยับ จึงนับเป็น moved เพื่อสั่งคำนวณใหม่ */
                var moved = false;
                if (element && window.TfTabs && window.TfTabs.reveal) {
                    moved = window.TfTabs.reveal(element);
                }

                if (undoHiddenScroll(element || document.body)) { moved = true; }

                window.setTimeout(function () {
                    if (undoHiddenScroll(element || document.body)) { moved = true; }

                    if (moved && current && typeof current.refresh === 'function') {
                        current.refresh();
                    }
                }, 0);
            },

            /* ดูจบหรือปิดกลางคัน ถือว่าเห็นแล้วทั้งคู่
               คนที่กดปิดตั้งแต่ขั้นแรกคือคนที่ไม่อยากดู การเด้งซ้ำครั้งหน้าคือการไม่ฟัง */
            onDestroyed: function () {
                current = null;
                setPressed(false);

                /* ปิดคู่มือแล้วต้องไม่ทิ้งหน้าไว้ในสภาพเลื่อนผิดที่
                   ขั้นสุดท้ายที่ไฮไลต์อาจเพิ่งดันกล่องแม่ไปก่อนจะถูกทำลาย */
                undoHiddenScroll(document.body);

                /* คืนสภาพก่อนสาธิต ต้องทำก่อนอย่างอื่นเสมอ
                   ข้อมูลสมมติที่ค้างอยู่บนหน้าจออันตรายกว่าไม่มีสาธิตเลย
                   เพราะผู้ใช้จะเข้าใจว่าเป็นข้อมูลจริงของหน่วยงานที่เลือกไว้ */
                if (demoOn && demo && typeof demo.teardown === 'function') {
                    try { demo.teardown(); } catch (e) { /* ไม่ให้กระทบการปิดคู่มือ */ }
                }

                markSeen(guide.page_key);
            }
        });

        current.drive();
        setPressed(true);
    }


    /* ---------- รอให้กล่องข้อความอื่นปิดก่อนค่อยเปิดคู่มือ ----------
     * บางหน้ามีกล่อง SweetAlert2 ขึ้นเองตอนเข้าหน้า เช่นประกาศของฝ่ายธุรการ
     * ที่หน้าทำรายการเบิก ถ้าคู่มือเด้งซ้อนขึ้นมา ฉากหลังทึบสองชั้นจะทับกัน
     * และผู้ใช้จะปิดกล่องหนึ่งทิ้งไปโดยไม่ทันได้อ่าน
     *
     * ประกาศสำคัญกว่าเพราะเป็นเงื่อนไขที่มีผลกับของที่จะได้รับจริง
     * คู่มือจึงเป็นฝ่ายรอ ตรวจซ้ำทุกครึ่งวินาที เลิกรอหลัง 30 วินาที
     * เผื่อกรณีที่ผู้ใช้เปิดกล่องอื่นค้างไว้แล้วเดินออกจากโต๊ะ
     */
    function startWhenClear(guide, tries) {
        var busy = (window.Swal && typeof window.Swal.isVisible === 'function')
                   ? window.Swal.isVisible() : false;

        if (!busy) { start(guide); return; }
        if (tries >= 60) { return; }

        window.setTimeout(function () { startWhenClear(guide, tries + 1); }, 500);
    }


    function stop() {
        if (current && typeof current.destroy === 'function') {
            current.destroy();      // onDestroyed จะเคลียร์ current ให้เอง
        }
        current = null;
        setPressed(false);
    }


    /* ---------- ดึงหน้าที่ถูกเลื่อนหลุดกรอบให้กลับที่เดิม ----------
     * driver.js เรียก scrollIntoView ซึ่งเบราว์เซอร์จะไล่เลื่อนกล่องแม่ทุกชั้น
     * รวมถึงกล่องที่ตั้ง overflow: hidden ซึ่งไม่มีแถบเลื่อนให้ผู้ใช้ลากกลับ
     * เนื้อหาจึงค้างผิดที่จนกว่าจะโหลดหน้าใหม่
     * แก้โดยไล่กล่องแม่ ตัวไหน overflow เป็น hidden แล้วมี scroll ค้าง ให้ดึงกลับ 0
     * กล่องที่ผู้ใช้เลื่อนเองได้ (auto/scroll) ไม่แตะ */
    function undoHiddenScroll(el) {
        var node  = el;
        var fixed = false;

        while (node && node !== document.documentElement) {
            if (resetIfHidden(node)) { fixed = true; }
            node = node.parentElement;
        }

        /* กันพลาดกรณีที่ขั้นตอนนั้นไม่ได้ชี้ที่ element ใด (กล่องกลางจอ)
           หรือถูกเรียกตอนปิดคู่มือซึ่งไม่มีตัวไฮไลต์ให้ไล่ขึ้นไปแล้ว
           สองตัวนี้คือกรอบของทั้งระบบ ตรวจไว้เสมอไม่เสียหาย */
        if (resetIfHidden(document.getElementById('appMain'))) { fixed = true; }
        if (resetIfHidden(document.body))                      { fixed = true; }

        return fixed;
    }


    /* คืน true ถ้ามีการดึงกลับจริง — ผู้เรียกต้องรู้ เพราะการดึงกลับ
       ทำให้ทุกอย่างบนหน้าขยับ ตำแหน่งที่ driver.js คำนวณไว้ก่อนหน้าจึงคลาดไปด้วย */
    function resetIfHidden(node) {
        if (!node) { return false; }

        var style = window.getComputedStyle(node);
        var fixed = false;

        if (style.overflowX === 'hidden' && node.scrollLeft !== 0) { node.scrollLeft = 0; fixed = true; }
        if (style.overflowY === 'hidden' && node.scrollTop  !== 0) { node.scrollTop  = 0; fixed = true; }

        return fixed;
    }


    function closeMobileSidebar() {
        var overlay = document.getElementById('sidebarOverlay');
        var sidebar = document.getElementById('sidebar');

        if (overlay && !overlay.hidden && sidebar) {
            overlay.hidden = true;
            sidebar.classList.add('-translate-x-full');
        }
    }


    function setPressed(on) {
        if (!btn) { return; }
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.title = on ? 'ปิดคู่มือ' : 'คู่มือการใช้งานหน้านี้';
        btn.setAttribute('aria-label', btn.title);
    }


    /* ---------- บันทึกว่าดูแล้ว ----------
     * ยิงแล้วไม่รอผล และไม่แจ้ง error ถ้าล้มเหลว
     * ผลเสียสูงสุดคือคู่มือเด้งอีกครั้งในการเข้าครั้งหน้า ซึ่งไม่คุ้มกับการ
     * ขึ้นกล่อง error มาขวางจอคนที่เพิ่งอ่านคู่มือจบพอดี
     *
     * ข้ามถ้ากำลังออกจากหน้าอยู่ — เบราว์เซอร์รื้อ DOM ทิ้งตอนเปลี่ยนหน้า
     * ซึ่งทำให้ driver.js เรียก onDestroyed ตามไปด้วย ทั้งที่ผู้ใช้ไม่ได้ปิดเอง
     * ถ้านับว่าดูแล้ว คนที่เพิ่งเข้าระบบแล้วรีบกดเมนูอื่นทันที จะไม่มีวันได้เห็น
     * คู่มือของหน้าแรกอีกเลย ทั้งที่ยังไม่ทันได้อ่านสักตัวอักษร
     */
    function markSeen(pageKey) {
        if (!pageKey || leaving) { return; }

        if (loaded) { loaded.seen = true; }

        post({ action: 'seen', page_key: pageKey })['catch'](function () { });
    }


    /* ---------- กดปุ่มคู่มือ = เปิด ถ้าเปิดอยู่ = ปิด ---------- */
    function toggle() {
        if (current) { stop(); return; }
        if (starting) { return; }

        if (loaded) { start(loaded); return; }

        starting = true;
        load(function (guide) {
            starting = false;
            if (guide) { start(guide); }
        });
    }


    function load(done) {
        post({ action: 'get', page_key: CTX.page || '' })
            .then(function (res) {
                loaded = (res && res.ok && res.guide) ? res.guide : null;
                done(loaded);
            })['catch'](function () {
                done(null);
            });
    }


    /* ---------- เริ่มทำงาน ---------- */
    function init() {
        if (!btn || !CTX.page) { return; }
        if (!driverFn()) { return; }        // ไลบรารีโหลดไม่ผ่าน — ซ่อนปุ่มไว้ดีกว่าโชว์ปุ่มที่กดไม่ได้

        btn.addEventListener('click', toggle);

        /* ตั้งธงไว้ก่อนที่เบราว์เซอร์จะรื้อหน้าทิ้ง เพื่อให้ markSeen รู้ว่า
           การปิดที่กำลังจะเกิดขึ้นไม่ได้มาจากการตัดสินใจของผู้ใช้ */
        window.addEventListener('beforeunload', function () { leaving = true; });
        window.addEventListener('pagehide',     function () { leaving = true; });

        /* Esc ปิดคู่มือ — driver.js จัดการให้อยู่แล้ว แต่ปุ่มบนแถบบนต้องกลับเป็นสถานะปกติด้วย
           ผูกไว้ที่นี่เพราะ onDestroyed อาจถูกเรียกช้ากว่าที่ผู้ใช้เห็นกล่องหายไป */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && current) { setPressed(false); }
        });

        load(function (guide) {
            if (!guide) { return; }         // หน้านี้ไม่มีคู่มือ ปุ่มยังซ่อนอยู่

            btn.hidden = false;

            /* เปิดให้เองเฉพาะครั้งแรกที่คนนี้เข้าหน้านี้
               หน่วงไว้เล็กน้อยเพราะหลายหน้าโหลดตารางด้วย fetch หลัง DOM พร้อม
               ถ้าเปิดทันทีจะไปไฮไลต์ตารางที่ยังเป็นกล่อง "กำลังโหลด" อยู่ */
            if (guide.auto_start && !guide.seen) {
                window.setTimeout(function () { startWhenClear(guide, 0); }, 900);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }


    /* ---------- เปิดให้หน้าอื่นสั่งได้ ----------
     * หน้าตั้งค่าระบบใช้ปุ่ม "ดูตัวอย่างคู่มือหน้านี้" ผ่านทางนี้ */
    window.AppGuide = {
        open:  toggle,
        close: stop
    };
})();
