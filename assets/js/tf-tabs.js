/* ============================================================
 *  tf-tabs.js — แท็บสลับสองฝั่งบนจอเล็ก (ใช้กับตัวเลือกสินค้าแบบสองฝั่ง)
 *
 *      <div class="tf-wrap">
 *        <div class="tf-tabs">
 *          <button class="tf-tab is-active" data-pane="src">...</button>
 *          <button class="tf-tab"           data-pane="dst">...</button>
 *        </div>
 *        <section class="tf-pane tf-pane-src">...</section>
 *        <section class="tf-pane tf-pane-dst">...</section>
 *      </div>
 *
 *  ---------------------------------------------------------------
 *  ทำไมต้องมีแท็บ
 *  ---------------------------------------------------------------
 *  จอกว้างวางสองฝั่งคู่กัน เห็นพร้อมกันทั้ง "ของทั้งหมด" กับ "ที่เลือกไว้"
 *  พอจอแคบสองฝั่งต้องเรียงบนล่าง ฝั่งที่เลือกไว้จึงไปอยู่ใต้รายการที่ยาว 335 ตัว
 *  ผู้ใช้ต้องเลื่อนผ่านรายการทั้งก้อนกว่าจะเจอ — หลายคนเลื่อนไม่ถึงและไม่รู้ว่ามีอยู่
 *  แถมมีกล่องที่เลื่อนได้ซ้อนกันหลายชั้น นิ้วไปโดนชั้นไหนก็ไม่แน่นอน
 *
 *  แท็บแก้ทั้งสองเรื่องพร้อมกัน: เห็นทีละฝั่ง เหลือกล่องที่เลื่อนได้ชั้นเดียว
 *  และตัวเลขบนแท็บบอกตลอดว่าอีกฝั่งมีอยู่กี่รายการ โดยไม่ต้องสลับไปดู
 *
 *  ไฟล์นี้ทำแค่การสลับ ส่วนการซ่อน/แสดงจริงเป็นหน้าที่ของ CSS
 *  (คลาส .is-tab-dst บน .tf-wrap ใน assets/css/app.css)
 *  กฎทั้งหมดอยู่ใน @media จอเล็ก จอกว้างจึงเห็นสองฝั่งพร้อมกันเหมือนเดิม
 *  แม้คลาสจะยังติดอยู่
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    function init(wrap) {
        if (!wrap || wrap.getAttribute('data-tabs') === 'on') { return; }

        var bar = wrap.querySelector('.tf-tabs');
        if (!bar) { return; }

        var nodes = bar.querySelectorAll('.tf-tab');
        if (nodes.length === 0) { return; }

        wrap.setAttribute('data-tabs', 'on');

        function listOf(key) {
            var pane = wrap.querySelector('.tf-pane-' + key);
            return pane ? pane.querySelector('.tf-list') : null;
        }

        function current() {
            return wrap.classList.contains('is-tab-dst') ? 'dst' : 'src';
        }

        function show(key) {
            /* จำตำแหน่งเลื่อนของฝั่งที่กำลังจะซ่อนไว้ก่อน
               การซ่อนด้วย display:none ทำให้กล่องไม่มีขนาด scrollTop จึงกลับเป็น 0
               คนที่ไล่ดูมาถึงรายการที่ 80 แล้วแวะดูอีกฝั่ง ไม่ควรถูกดีดกลับไปบนสุด */
            var from = listOf(current());
            if (from) { from.setAttribute('data-keep', String(from.scrollTop)); }

            wrap.classList.toggle('is-tab-dst', key === 'dst');

            for (var i = 0; i < nodes.length; i++) {
                var on = (nodes[i].getAttribute('data-pane') === key);
                nodes[i].classList.toggle('is-active', on);
                nodes[i].setAttribute('aria-selected', on ? 'true' : 'false');

                /* แท็บที่ไม่ได้เลือกออกจากลำดับ Tab ตามแบบของ tablist
                   กดลูกศรซ้ายขวาเพื่อสลับ ไม่ใช่กด Tab ทีละปุ่ม */
                nodes[i].tabIndex = on ? 0 : -1;
            }

            var to = listOf(key);
            if (to) {
                var keep = Number(to.getAttribute('data-keep'));
                if (keep > 0) { to.scrollTop = keep; }
            }
        }

        for (var j = 0; j < nodes.length; j++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    show(btn.getAttribute('data-pane'));
                });

                btn.addEventListener('keydown', function (e) {
                    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
                    e.preventDefault();

                    var next = (current() === 'src') ? 'dst' : 'src';
                    show(next);

                    var focus = bar.querySelector('.tf-tab[data-pane="' + next + '"]');
                    if (focus) { focus.focus(); }
                });
            })(nodes[j]);
        }

        show(current());

        /* ให้หน้าอื่นสั่งสลับได้ เช่น อยากพาไปดูฝั่งที่เลือกหลังทำอะไรบางอย่าง */
        wrap.tfTabsShow = show;
    }

    function initAll(root) {
        var nodes = (root || document).querySelectorAll('.tf-wrap');
        for (var i = 0; i < nodes.length; i++) { init(nodes[i]); }
    }


    /* ---------- พาไปที่ฝั่งที่ element นี้อยู่ ----------
     * ใช้โดยคู่มือการใช้งาน (app-guide.js) ซึ่งไฮไลต์ทีละจุดตาม selector
     * บนจอเล็กฝั่งที่ไม่ได้เลือกถูกซ่อนไว้ ขั้นตอนที่ชี้ไปที่ฝั่งนั้นจะถูกกรองทิ้ง
     * ตั้งแต่ตอนสร้างขั้นตอน (ดู isVisible() ใน app-guide.js)
     * ถ้าไม่มีตัวนี้ คู่มือบนมือถือจะหายไปครึ่งหนึ่งโดยไม่มีอะไรบอก
     *
     * คืน true เมื่อสลับจริง ผู้เรียกจะได้รู้ว่าต้องคำนวณตำแหน่งกล่องใหม่
     */
    function reveal(node) {
        if (!node || !node.closest) { return false; }

        var pane = node.closest('.tf-pane-src, .tf-pane-dst');
        if (!pane) { return false; }

        var wrap = pane.closest('.tf-wrap');
        if (!wrap || typeof wrap.tfTabsShow !== 'function') { return false; }

        var want = pane.classList.contains('tf-pane-dst') ? 'dst' : 'src';
        var now  = wrap.classList.contains('is-tab-dst')  ? 'dst' : 'src';
        if (want === now) { return false; }

        wrap.tfTabsShow(want);
        return true;
    }

    window.TfTabs = { init: init, initAll: initAll, reveal: reveal };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); });
    } else {
        initAll();
    }
})();
