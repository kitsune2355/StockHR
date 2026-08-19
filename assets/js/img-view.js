/* ============================================================
 *  img-view.js — กล่องดูรูปขนาดใหญ่ (ใช้ร่วมกันทุกหน้า)
 *
 *  วิธีใช้:
 *      ImageView.open(['a.jpg', 'b.jpg'], 1);   // เปิดที่รูปที่สอง
 *      ImageView.open('a.jpg');                 // รูปเดียวก็ส่งเป็นสตริงได้
 *
 *  ---------------------------------------------------------------
 *  ทำไมไม่ใช้ SweetAlert ที่ระบบมีอยู่แล้ว
 *  ---------------------------------------------------------------
 *  ที่ที่ต้องใช้มากที่สุดคือกล่องจัดการรูปในหน้าทะเบียนสินค้า ซึ่งตัวมันเอง
 *  เป็นกล่อง SweetAlert อยู่แล้ว การเรียก Swal.fire ซ้อนจะไปปิดกล่องเดิมทิ้ง
 *  ผู้ใช้กดดูรูปแล้วกล่องจัดการหายไปเลย ต้องกดเข้าใหม่ทุกครั้ง
 *
 *  ตัวนี้เป็น element ของตัวเองที่ต่อไว้กับ body จึงลอยทับได้ทุกอย่าง
 *  รวมถึงกล่อง SweetAlert โดยไม่ไปยุ่งกับมัน
 *
 *  โหลดทุกหน้าใน includes/head_assets.php เพราะเล็กมาก
 *  และตอนนี้มีสองหน้าที่ใช้แล้ว (ทะเบียนสินค้า + ทำรายการเบิก)
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var box   = null;      // กล่องทั้งหมด
    var imgEl = null;
    var capEl = null;
    var prevB = null;
    var nextB = null;

    var urls = [];
    var at   = 0;

    /* ตัวที่โฟกัสอยู่ก่อนเปิดกล่อง — ปิดแล้วต้องคืนโฟกัสกลับไปให้
       ไม่งั้นคนที่ใช้แป้นพิมพ์จะหลุดไปเริ่มนับจากต้นหน้าใหม่ทุกครั้งที่ปิด */
    var lastFocus = null;

    /* เราเป็นคนล็อกการเลื่อนหน้าเองหรือเปล่า
       ถ้าตอนเปิดมีคนอื่นล็อกไว้อยู่ก่อนแล้ว (ลิ้นชักเมนู หรือกล่องอื่น)
       ตอนปิดต้องไม่ไปปลดให้เขา ไม่งั้นหน้าข้างหลังจะเลื่อนได้ทั้งที่กล่องนั้นยังเปิดอยู่ */
    var didLock = false;


    function build() {
        if (box) { return; }

        box = document.createElement('div');
        box.className = 'iv';
        box.setAttribute('hidden', '');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', 'ดูรูปขนาดใหญ่');

        box.innerHTML =
            '<button type="button" class="iv-close" aria-label="ปิด">&#10005;</button>'
          + '<button type="button" class="iv-nav is-prev" aria-label="รูปก่อนหน้า">&#10094;</button>'
          + '<img class="iv-img" alt="">'
          + '<button type="button" class="iv-nav is-next" aria-label="รูปถัดไป">&#10095;</button>'
          + '<span class="iv-cap num"></span>';

        document.body.appendChild(box);

        imgEl = box.querySelector('.iv-img');
        capEl = box.querySelector('.iv-cap');
        prevB = box.querySelector('.is-prev');
        nextB = box.querySelector('.is-next');

        /* กดที่พื้นหลังเพื่อปิด แต่กดที่ตัวรูปหรือปุ่มลูกศรไม่ปิด
           ไม่งั้นคนที่กดลูกศรรัว ๆ แล้วพลาดไปโดนขอบรูปจะเจอกล่องปิดใส่หน้า */
        box.addEventListener('click', function (e) {
            if (e.target === box || e.target.className === 'iv-close') { close(); }
        });

        prevB.addEventListener('click', function () { step(-1); });
        nextB.addEventListener('click', function () { step(1); });

        document.addEventListener('keydown', function (e) {
            if (box.hasAttribute('hidden')) { return; }

            if (e.key === 'Escape')     { close(); }
            if (e.key === 'ArrowLeft')  { step(-1); }
            if (e.key === 'ArrowRight') { step(1); }
        });
    }


    function show() {
        imgEl.src = urls[at];

        /* ป้ายบอกว่าเป็นรูปที่เท่าไรจากทั้งหมด ซ่อนเมื่อมีรูปเดียว
           เพราะ "1 / 1" ไม่ได้บอกอะไรที่ยังไม่รู้ */
        var many = urls.length > 1;

        capEl.textContent = many ? ((at + 1) + ' / ' + urls.length) : '';
        capEl.hidden = !many;
        prevB.hidden = !many;
        nextB.hidden = !many;
    }

    /* วนรอบทั้งสองทาง มีแค่ 3 รูป การกดเลยขอบแล้วต้องกดย้อนกลับ
       น่ารำคาญกว่าการวนกลับไปรูปแรก */
    function step(by) {
        if (urls.length < 2) { return; }

        at = (at + by + urls.length) % urls.length;
        show();
    }


    function open(list, start) {
        var arr = (typeof list === 'string') ? [list] : (list || []);

        /* คัดเฉพาะที่มีที่อยู่จริง รูปที่ไฟล์หายไปจากดิสก์จะได้ url ว่าง
           (ดู product_image_url ฝั่ง PHP) ถ้าเปิดมาจะเห็นเป็นไอคอนรูปเสีย */
        urls = [];
        for (var i = 0; i < arr.length; i++) {
            var u = (typeof arr[i] === 'string') ? arr[i] : (arr[i] && arr[i].url);
            if (u) { urls.push(u); }
        }
        if (urls.length === 0) { return; }

        at = Number(start) || 0;
        if (at < 0 || at >= urls.length) { at = 0; }

        build();
        lastFocus = document.activeElement;

        show();
        box.removeAttribute('hidden');

        /* ล็อกไม่ให้หน้าข้างหลังเลื่อนตอนปัดบนมือถือ
           คลาสนี้มีใช้อยู่แล้วในลิ้นชักเมนู (ดู app.js) */
        didLock = !document.body.classList.contains('overflow-hidden');
        if (didLock) { document.body.classList.add('overflow-hidden'); }

        box.querySelector('.iv-close').focus();
    }


    function close() {
        if (!box || box.hasAttribute('hidden')) { return; }

        box.setAttribute('hidden', '');

        if (didLock) {
            document.body.classList.remove('overflow-hidden');
            didLock = false;
        }

        /* ล้าง src ทิ้ง ไม่งั้นเบราว์เซอร์ยังกันหน่วยความจำให้รูปใหญ่ที่ไม่ได้ดูแล้ว */
        imgEl.src = '';

        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        lastFocus = null;
    }


    window.ImageView = { open: open, close: close };
}());
