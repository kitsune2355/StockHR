/* ============================================================
 *  font-size.js — หน้าตั้งขนาดตัวอักษรของทั้งระบบ (pages/font_size.php)
 *
 *  ค่าเก็บที่ localStorage คีย์ hl-font เป็นเปอร์เซ็นต์จำนวนเต็ม เช่น 110
 *  ไม่มีการยิงไปหาเซิร์ฟเวอร์เลย เพราะเป็นค่าของเครื่องที่ใช้อยู่ ไม่ใช่ของบัญชี
 *
 *  ---------------------------------------------------------------
 *  ปรับแล้วเห็นผลทันที แต่ยังไม่ถือว่าบันทึก
 *  ---------------------------------------------------------------
 *  ทุกครั้งที่ขยับ จะเขียน font-size ลง <html> ให้เห็นผลจริงทั้งหน้า
 *  ไม่ใช่ดูแค่ในกล่องตัวอย่าง เพราะสิ่งที่คนอยากรู้คือ "เมนูกับปุ่มจะเป็นยังไง"
 *  ซึ่งกล่องตัวอย่างเล็ก ๆ ตอบไม่ได้
 *
 *  แต่ค่าจะลง localStorage ต่อเมื่อกดบันทึกเท่านั้น ถ้าเปลี่ยนหน้าไปเฉย ๆ
 *  หน้าถัดไปอ่านค่าเดิมจาก localStorage จึงกลับไปขนาดเดิมเอง
 *  ไม่ต้องมีโค้ดคืนค่าตอนออกจากหน้า
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var KEY  = 'hl-font';
    var MIN  = 75;
    var MAX  = 150;
    var BASE = 100;

    /* ต้องตรงกับ $levels ใน pages/font_size.php
       ใช้ตอบว่าเลขที่เลือกอยู่ตรงกับระดับไหน เพื่อไปไฮไลต์ปุ่มและบอกชื่อ */
    var LEVELS = [
        { value:  85, title: 'เล็กพิเศษ' },
        { value:  92, title: 'เล็ก' },
        { value: 100, title: 'ปกติ' },
        { value: 110, title: 'ใหญ่' },
        { value: 120, title: 'ใหญ่พิเศษ' }
    ];

    var root  = document.documentElement;
    var range = document.getElementById('fsRange');
    if (!range) { return; }                       // ไม่ได้อยู่หน้านี้

    var elNow     = document.getElementById('fsNow');
    var elNowName = document.getElementById('fsNowName');
    var btnSave   = document.getElementById('fsSave');
    var btnReset  = document.getElementById('fsReset');
    var btnMinus  = document.getElementById('fsMinus');
    var btnPlus   = document.getElementById('fsPlus');
    var levelBtns = document.querySelectorAll('.fs-level');


    function clamp(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) { return BASE; }
        if (n < MIN)  { return MIN; }
        if (n > MAX)  { return MAX; }
        return n;
    }

    /* ค่าที่บันทึกไว้จริง — ใช้ตัดสินว่ามีอะไรค้างยังไม่ได้บันทึกหรือยัง
       ค่าเสียหรือค่านอกช่วงถือเป็นขนาดปกติ ให้ตรงกับที่ head_assets.php ทำ */
    function saved() {
        var n = parseInt(localStorage.getItem(KEY), 10);
        return (n >= MIN && n <= MAX) ? n : BASE;
    }

    function levelName(value) {
        for (var i = 0; i < LEVELS.length; i++) {
            if (LEVELS[i].value === value) { return LEVELS[i].title; }
        }
        return '';
    }


    /* ---------- ทาค่าลงหน้าจอ ----------
     * 100% ล้าง inline style ทิ้งแทนที่จะเขียน '100%' ลงไป
     * เพื่อให้กลับไปใช้ค่าที่ผู้ใช้ตั้งไว้ในเบราว์เซอร์เอง ซึ่งบางคนไม่ใช่ 16px
     * ถ้าเขียนทับไว้จะกลายเป็นบังคับ 16px ให้คนที่ตั้งไว้ใหญ่กว่านั้นมาแต่แรก
     */
    function paint(value) {
        if (value === BASE) {
            root.style.fontSize = '';
        } else {
            root.style.fontSize = value + '%';
        }
    }


    function render() {
        var value = clamp(range.value);
        var name  = levelName(value);

        paint(value);

        if (elNow)     { elNow.textContent     = value + '%'; }
        if (elNowName) { elNowName.textContent = name ? ('(' + name + ')') : ''; }

        /* ไฮไลต์ระดับที่ตรงกับค่าปัจจุบัน ลากแถบเลื่อนไปตรงเลขของระดับไหน
           ปุ่มนั้นก็ติดขึ้นมาเอง ผู้ใช้จะได้รู้ว่าค่าที่ปรับเองอยู่ตรงไหนของสเกล */
        for (var i = 0; i < levelBtns.length; i++) {
            var on = (parseInt(levelBtns[i].getAttribute('data-value'), 10) === value);
            levelBtns[i].classList.toggle('is-on', on);
            levelBtns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        /* ปุ่มบันทึกจางลงเมื่อค่าตรงกับที่บันทึกไว้แล้ว — ไม่มีอะไรให้บันทึก
           ปุ่มคืนค่าเริ่มต้นก็เช่นกัน ถ้าอยู่ที่ 100% อยู่แล้วก็กดไปก็เท่าเดิม */
        if (btnSave)  { btnSave.disabled  = (value === saved()); }
        if (btnReset) { btnReset.disabled = (value === BASE && saved() === BASE); }
    }


    /* เขียนค่าลง input ตัวเดียวเสมอ ไม่ว่าจะมาจากปุ่มไหน
       ทางเดินจึงมีทางเดียว และ render() ถูกเรียกจากที่เดียว */
    function setValue(value) {
        range.value = clamp(value);
        render();
    }


    range.addEventListener('input',  render);
    range.addEventListener('change', render);

    if (btnMinus) {
        btnMinus.addEventListener('click', function () { setValue(clamp(range.value) - 1); });
    }
    if (btnPlus) {
        btnPlus.addEventListener('click', function () { setValue(clamp(range.value) + 1); });
    }

    for (var i = 0; i < levelBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                setValue(btn.getAttribute('data-value'));
            });
        }(levelBtns[i]));
    }

    if (btnReset) {
        btnReset.addEventListener('click', function () { setValue(BASE); });
    }

    if (btnSave) {
        btnSave.addEventListener('click', function () {
            var value = clamp(range.value);

            try {
                if (value === BASE) {
                    /* ลบทิ้งแทนการเก็บเลข 100 ไว้ เพื่อให้ "ไม่เคยตั้ง" กับ
                       "ตั้งเป็นปกติ" เป็นสภาพเดียวกัน จะได้ไม่ต้องแยกสองกรณี */
                    localStorage.removeItem(KEY);
                } else {
                    localStorage.setItem(KEY, String(value));
                }
            } catch (e) {
                /* โหมดส่วนตัวบางเบราว์เซอร์ห้ามเขียน localStorage
                   ขนาดที่เห็นอยู่ตอนนี้ยังใช้ได้จนกว่าจะปิดหน้า จึงบอกตามจริง
                   ว่าจำให้ไม่ได้ ดีกว่าขึ้นว่าบันทึกแล้วทั้งที่ไม่ได้บันทึก */
                if (typeof window.appError === 'function') {
                    window.appError('เบราว์เซอร์นี้ไม่ยอมให้จำค่า ขนาดที่ปรับไว้จะหายเมื่อปิดหน้า');
                }
                return;
            }

            render();

            if (typeof window.appSuccess === 'function') {
                window.appSuccess('บันทึกขนาดตัวอักษร ' + value + '% แล้ว — มีผลกับทุกหน้าในเครื่องนี้');
            }
        });
    }

    /* เริ่มจากค่าที่บันทึกไว้ ไม่ใช่ค่า 100 ที่ติดมากับ HTML
       ไม่งั้นเปิดหน้านี้ทีไร ตัวอักษรทั้งระบบจะเด้งกลับเป็นปกติทันที */
    setValue(saved());
}());
