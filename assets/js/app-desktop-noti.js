/* ============================================================
 *  assets/js/app-desktop-noti.js
 *  แจ้งเตือนที่เด้งนอกหน้าเว็บ (Web Notifications API)
 *
 *  ใช้ตอนที่ผู้ใช้สลับไปทำงานอย่างอื่นหรือย่อหน้าต่างไว้
 *  กระดิ่งบนแถบบนไม่มีประโยชน์ถ้าไม่มีใครมองจอนั้นอยู่
 *
 *  ---------------------------------------------------------------
 *  ขอบเขตที่ทำได้จริง — ต้องพูดให้ตรง
 *  ---------------------------------------------------------------
 *  แจ้งเตือนนี้เด้งได้เมื่อ "เปิดหน้าระบบค้างไว้ในแท็บใดแท็บหนึ่ง"
 *  ไม่ต้องเป็นแท็บที่กำลังดูอยู่ ย่อหน้าต่างไว้ก็ได้ แต่ถ้าปิดเบราว์เซอร์ไปเลย
 *  จะไม่มีอะไรเด้ง เพราะไม่มีโค้ดของเราทำงานอยู่แล้ว
 *
 *  การเด้งตอนปิดเบราว์เซอร์ต้องใช้ Service Worker + Push Server
 *  ซึ่งต้องรันบน https และต้องมีเซิร์ฟเวอร์ push แยกต่างหาก
 *  ระบบนี้รันบน http ในวงแลน จึงทำไม่ได้ในสภาพปัจจุบัน
 *
 *  ---------------------------------------------------------------
 *  ข้อจำกัดเรื่อง http (สำคัญ)
 *  ---------------------------------------------------------------
 *  เบราว์เซอร์สมัยใหม่ให้ขอสิทธิ์แจ้งเตือนได้เฉพาะหน้าที่เป็น "secure context"
 *  คือ https หรือ localhost/127.0.0.1 เท่านั้น
 *
 *  เครื่องที่เปิดระบบผ่าน http://<ไอพีของเซิร์ฟเวอร์>/stock_hr/ จะขอสิทธิ์ไม่ได้
 *  ตรงนี้จึงตรวจก่อนแล้วบอกเหตุผลให้ผู้ใช้เห็น แทนที่จะปล่อยให้กดสวิตช์
 *  แล้วไม่มีอะไรเกิดขึ้นโดยไม่รู้ว่าทำไม
 *
 *  ---------------------------------------------------------------
 *  สวิตช์เก็บที่เครื่อง ไม่ใช่ในฐานข้อมูล
 *  ---------------------------------------------------------------
 *  สิทธิ์แจ้งเตือนเป็นของ "เบราว์เซอร์บนเครื่องนั้น" ไม่ใช่ของบัญชีผู้ใช้
 *  คนเดียวกันเปิดที่เครื่องอื่นก็ต้องกดอนุญาตใหม่อยู่ดี
 *  ถ้าเก็บค่าไว้ในฐานข้อมูล จะกลายเป็นว่าเปิดไว้ที่เครื่องหนึ่งแล้วอีกเครื่อง
 *  ขึ้นว่าเปิดอยู่ทั้งที่เด้งไม่ได้ — เก็บที่ localStorage ตรงกับความจริงมากกว่า
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CTX  = window.APP_CTX || {};
    var USER = CTX.user || 'guest';

    var KEY_ON   = 'hl_desktop_noti_' + USER;   // สวิตช์เปิด/ปิด
    var KEY_SEEN = 'hl_desktop_seen_' + USER;   // id ล่าสุดที่เด้งไปแล้ว


    function store(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (e) { return null; }   // โหมดส่วนตัวบางตัวห้ามเขียน
        return value;
    }


    /* ---------- เบราว์เซอร์นี้ทำได้แค่ไหน ----------
     * คืนรหัสสถานะเดียว เพื่อให้ทุกที่ที่ถามได้คำตอบชุดเดียวกัน
     *
     *   unsupported  เบราว์เซอร์ไม่มี Notification API เลย
     *   insecure     มี API แต่หน้านี้ไม่ใช่ https/localhost จึงขอสิทธิ์ไม่ได้
     *   denied       ผู้ใช้เคยกดปฏิเสธไว้ ต้องไปแก้ที่ตั้งค่าเบราว์เซอร์เอง
     *   default      ยังไม่เคยถาม กดสวิตช์แล้วจะขึ้นกล่องขออนุญาต
     *   granted      อนุญาตแล้ว
     */
    function state() {
        if (typeof window.Notification === 'undefined') { return 'unsupported'; }

        /* isSecureContext ครอบคลุมทั้ง https และ localhost/127.0.0.1 ให้แล้ว
           ไม่ต้องมาไล่เช็ค hostname เอง (และเช็คเองมักพลาดกรณี ::1 กับ *.localhost) */
        if (window.isSecureContext === false) { return 'insecure'; }

        return window.Notification.permission;   // default | granted | denied
    }

    function isOn() {
        return store(KEY_ON) === '1' && state() === 'granted';
    }


    /* ---------- ข้อความอธิบายสถานะ ----------
     * แยกข้อความตามเหตุผล ไม่ใช้ประโยคเดียวครอบทุกกรณี
     * เพราะแต่ละกรณีผู้ใช้ต้องทำคนละอย่างถึงจะแก้ได้
     */
    function hint() {
        var s = state();

        if (s === 'unsupported') {
            return 'เบราว์เซอร์นี้ไม่รองรับการแจ้งเตือนนอกหน้าเว็บ';
        }
        if (s === 'insecure') {
            return 'เปิดใช้ไม่ได้เพราะหน้านี้ไม่ได้เข้าผ่าน https '
                 + '(เบราว์เซอร์อนุญาตเฉพาะ https หรือ localhost) '
                 + 'ต้องติดตั้งใบรับรอง https ที่เซิร์ฟเวอร์ก่อน';
        }
        if (s === 'denied') {
            return 'เคยกดไม่อนุญาตไว้ ต้องไปเปิดที่ตั้งค่าเบราว์เซอร์เอง '
                 + '(กดรูปแม่กุญแจข้างที่อยู่เว็บ แล้วเปิด "การแจ้งเตือน")';
        }
        if (isOn()) {
            return 'เปิดอยู่ — จะเด้งเมื่อมีเรื่องใหม่ แม้สลับไปทำอย่างอื่น '
                 + 'แต่ต้องเปิดหน้าระบบค้างไว้ในแท็บใดแท็บหนึ่ง';
        }
        return 'ปิดอยู่ — เห็นแจ้งเตือนได้จากกระดิ่งบนแถบบนเท่านั้น';
    }

    function canTurnOn() {
        var s = state();
        return (s === 'default' || s === 'granted');
    }


    /* ---------- เปิด/ปิด ----------
     * done(ok, message) ถูกเรียกเสมอ ผู้เรียกจะได้อัปเดตหน้าจอได้ทางเดียว
     */
    function toggle(done) {
        done = done || function () {};

        if (isOn()) {
            store(KEY_ON, '0');
            done(false, 'ปิดการแจ้งเตือนนอกหน้าเว็บแล้ว');
            return;
        }

        var s = state();
        if (s !== 'default' && s !== 'granted') {
            done(false, hint());
            return;
        }

        if (s === 'granted') {
            store(KEY_ON, '1');
            markSeenNow();
            done(true, 'เปิดการแจ้งเตือนนอกหน้าเว็บแล้ว');
            return;
        }

        /* requestPermission มีสองแบบตามอายุเบราว์เซอร์: คืน Promise กับรับ callback
           รองรับทั้งคู่ ไม่งั้นเครื่องที่ใช้เบราว์เซอร์เก่าจะกดแล้วค้างไม่มีอะไรเกิดขึ้น */
        var handle = function (result) {
            if (result === 'granted') {
                store(KEY_ON, '1');
                markSeenNow();
                done(true, 'เปิดการแจ้งเตือนนอกหน้าเว็บแล้ว');
            } else {
                store(KEY_ON, '0');
                done(false, 'ยังไม่ได้รับอนุญาตจากเบราว์เซอร์ จึงยังเปิดใช้ไม่ได้');
            }
        };

        try {
            var p = window.Notification.requestPermission(handle);
            if (p && typeof p.then === 'function') { p.then(handle); }
        } catch (e) {
            done(false, 'ขอสิทธิ์แจ้งเตือนจากเบราว์เซอร์ไม่สำเร็จ');
        }
    }


    /* ---------- กันเด้งซ้ำ ----------
     * จำ id ที่เด้งไปแล้วไว้ที่เครื่อง ไม่ใช่ในตัวแปรของหน้า
     * เพราะการเปลี่ยนหน้าในระบบนี้คือการโหลดหน้าใหม่ทั้งหน้า
     * ถ้าเก็บในตัวแปร ทุกครั้งที่กดเมนูจะเด้งเรื่องเดิมซ้ำอีกรอบ
     */
    function seenId() {
        var v = parseInt(store(KEY_SEEN), 10);
        return isNaN(v) ? 0 : v;
    }

    function setSeen(id) {
        if (id > seenId()) { store(KEY_SEEN, String(id)); }
    }

    /* ตอนเพิ่งเปิดสวิตช์ ให้ถือว่าของที่ค้างอยู่ตอนนี้ "เห็นแล้ว"
       ไม่งั้นกดเปิดปุ๊บจะโดนเด้งย้อนหลังรัวทีเดียวสิบเรื่อง */
    function markSeenNow() {
        if (typeof window.__notiMaxId === 'number') { setSeen(window.__notiMaxId); }
    }


    /* ---------- เด้งจริง ----------
     * รับรายการที่ app-notify.js โหลดมาแล้ว ไม่ยิงขอข้อมูลเอง
     * จะได้ไม่มีสองที่ถามเซิร์ฟเวอร์เรื่องเดียวกัน
     */
    function push(items) {
        if (!isOn() || !items || items.length === 0) { return; }

        /* เรียงจากเก่าไปใหม่ก่อนเด้ง เพื่อให้เรื่องล่าสุดไปกองอยู่บนสุด
           ตามลำดับที่ระบบปฏิบัติการเรียงให้ */
        var fresh = [];
        for (var i = 0; i < items.length; i++) {
            if (Number(items[i].noti_id) > seenId()) { fresh.push(items[i]); }
        }
        if (fresh.length === 0) { return; }
        fresh.reverse();

        /* เด้งทีละเรื่องได้ไม่เกิน 3 เรื่อง เกินกว่านั้นรวบเป็นอันเดียว
           สิบกล่องซ้อนกันบนมุมจอคือสิ่งที่คนปิดทิ้งทั้งชุดโดยไม่อ่าน */
        if (fresh.length > 3) {
            show('มีแจ้งเตือนใหม่ ' + fresh.length + ' เรื่อง',
                 fresh[fresh.length - 1].title, 'main.php?page=notifications');
        } else {
            for (var j = 0; j < fresh.length; j++) {
                show(fresh[j].title, fresh[j].message, linkOf(fresh[j]));
            }
        }

        setSeen(Number(fresh[fresh.length - 1].noti_id));
    }

    function linkOf(n) {
        if (!n.link_page) { return 'main.php?page=notifications'; }

        var url = 'main.php?page=' + encodeURIComponent(n.link_page);
        if (n.link_doc_id > 0) { url += '&doc=' + n.link_doc_id; }
        return url;
    }

    function show(title, body, url) {
        try {
            var n = new window.Notification(title, {
                body: body || '',
                icon: 'assets/img/hl_logo-removebg-preview.png',
                /* tag เดียวกันทำให้กล่องเก่าถูกแทนที่แทนที่จะกองซ้อนกัน
                   ตั้งตามที่อยู่ปลายทาง เรื่องคนละใบจึงยังเด้งแยกกันได้ */
                tag: 'hl-' + url
            });

            n.onclick = function () {
                window.focus();
                window.location.href = url;
                n.close();
            };

            /* ปิดเองใน 20 วินาที บางระบบปฏิบัติการค้างไว้จนกว่าจะกด
               ซึ่งจะกองเต็มมุมจอถ้าไม่มีใครนั่งอยู่หน้าเครื่อง */
            window.setTimeout(function () { try { n.close(); } catch (e) {} }, 20000);

        } catch (e) { /* เด้งไม่ได้ก็ไม่ควรทำให้หน้าเว็บพัง */ }
    }


    /* ---------- ทดสอบว่าเด้งได้จริงไหม ---------- */
    function test() {
        if (!isOn()) { return false; }
        show('ทดสอบการแจ้งเตือน', 'ถ้าเห็นข้อความนี้ แปลว่าเปิดใช้งานได้เรียบร้อยแล้ว',
             'main.php?page=notifications');
        return true;
    }


    window.DesktopNoti = {
        state:     state,
        isOn:      isOn,
        canTurnOn: canTurnOn,
        hint:      hint,
        toggle:    toggle,
        push:      push,
        test:      test,
        setSeen:   setSeen,
        seenId:    seenId
    };
}());
