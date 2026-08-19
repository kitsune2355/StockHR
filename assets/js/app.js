/* ============================================================
 *  app.js — สคริปต์ร่วมของทุกหน้าที่อยู่ในกรอบ main.php
 *
 *  1) ลิ้นชักเมนูบนจอเล็ก (ปุ่มสามขีด + ฉากหลังทึบ + Esc)
 *  2) ย่อ/ขยายเมนูบนจอใหญ่ (จำค่าไว้ใน localStorage)
 *  3) กาง/พับเมนูย่อย
 *  4) สลับโหมดสว่าง/มืด (จำค่าไว้ใน localStorage)
 *  5) ยืนยันก่อนออกจากระบบ
 *  6) แถบแท็บที่เลื่อนได้ -> เลื่อนแท็บที่เปิดอยู่มาให้เห็น
 *  7) โลโก้โหลดไม่สำเร็จ -> สลับไปใช้ไอคอนแทน
 *
 *  หมายเหตุ: ซ่อน/แสดงต้องสั่งผ่าน attribute ไม่ใช่ property (el.hidden = true)
 *  เพราะ .hidden มีเฉพาะใน HTMLElement ส่วน <svg> ที่ Lucide สร้างเป็น
 *  SVGElement จึงไม่มี property ตัวนี้ ตัวที่ทำให้ซ่อนจริงคือกฎ
 *  [hidden][hidden] ใน assets/css/app.css
 * ============================================================ */
(function () {
    'use strict';

    var KEY_THEME   = 'hl-theme';
    var KEY_SIDEBAR = 'hl-sidebar';
    var DESKTOP     = 1024;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function setHidden(el, hide) {
        if (!el) { return; }
        if (hide) {
            el.setAttribute('hidden', '');
        } else {
            el.removeAttribute('hidden');
        }
    }

    function store(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* ใช้ต่อได้ แค่ไม่จำค่า */ }
    }

    function isDesktop() {
        return window.innerWidth >= DESKTOP;
    }

    function init() {

        var root    = document.documentElement;
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');

        /* ============================================================
         *  1. ลิ้นชักเมนูบนจอเล็ก
         * ============================================================ */
        var drawerBtn = document.getElementById('sidebarToggle');

        function openDrawer() {
            if (!sidebar) { return; }
            sidebar.classList.remove('-translate-x-full');
            setHidden(overlay, false);
            document.body.classList.add('overflow-hidden');
            if (drawerBtn) {
                drawerBtn.setAttribute('aria-expanded', 'true');
                drawerBtn.setAttribute('aria-label', 'ปิดเมนู');
            }
        }

        function closeDrawer() {
            if (!sidebar) { return; }
            sidebar.classList.add('-translate-x-full');
            setHidden(overlay, true);
            document.body.classList.remove('overflow-hidden');
            if (drawerBtn) {
                drawerBtn.setAttribute('aria-expanded', 'false');
                drawerBtn.setAttribute('aria-label', 'เปิดเมนู');
            }
        }

        function drawerIsOpen() {
            return sidebar && !sidebar.classList.contains('-translate-x-full');
        }

        if (drawerBtn) {
            drawerBtn.addEventListener('click', function () {
                if (drawerIsOpen()) { closeDrawer(); } else { openDrawer(); }
            });
        }
        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }

        /* ย่อจอจนเข้าโหมดมือถือแล้วขยายกลับ ต้องไม่มีฉากหลังทึบค้าง */
        window.addEventListener('resize', function () {
            if (isDesktop()) {
                setHidden(overlay, true);
                document.body.classList.remove('overflow-hidden');
            }
        });

        /* ============================================================
         *  2. ย่อ/ขยายเมนูบนจอใหญ่
         * ============================================================ */
        var collapseBtn = document.getElementById('sidebarCollapse');

        function syncCollapseButton() {
            if (!collapseBtn) { return; }
            var collapsed = root.classList.contains('sidebar-collapsed');
            var label     = collapsed ? 'ขยายเมนู' : 'ย่อเมนู';
            var icon      = collapseBtn.querySelector('svg');

            collapseBtn.setAttribute('aria-label', label);
            collapseBtn.setAttribute('title', label);
            if (icon) {
                icon.classList.toggle('rotate-180', collapsed);
            }
        }

        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                var collapsed = root.classList.toggle('sidebar-collapsed');
                store(KEY_SIDEBAR, collapsed ? 'collapsed' : 'expanded');
                syncCollapseButton();
            });
            syncCollapseButton();
        }

        /* ============================================================
         *  3. เมนูย่อย
         * ============================================================ */
        var toggles = document.querySelectorAll('.nav-toggle');

        for (var i = 0; i < toggles.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {

                    /* ตอนเมนูถูกย่อบนจอใหญ่ ไม่มีที่แสดงชื่อเมนูย่อย
                       กดแล้วให้ขยายเมนูออกมาก่อน จะได้เห็นว่ากดอะไรอยู่ */
                    if (isDesktop() && root.classList.contains('sidebar-collapsed')) {
                        root.classList.remove('sidebar-collapsed');
                        store(KEY_SIDEBAR, 'expanded');
                        syncCollapseButton();
                    }

                    var sub   = document.getElementById(btn.getAttribute('data-target'));
                    var caret = btn.querySelector('.nav-caret');
                    if (!sub) { return; }

                    var willOpen = sub.hasAttribute('hidden');
                    setHidden(sub, !willOpen);
                    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    if (caret) { caret.classList.toggle('rotate-90', willOpen); }
                });
            })(toggles[i]);
        }

        /* ============================================================
         *  4. โหมดสว่าง/มืด (ปุ่มอยู่บนแถบบน ข้างชื่อผู้ใช้)
         *     ไอคอนบอก "โหมดที่จะเปลี่ยนไป" ไม่ใช่โหมดปัจจุบัน
         * ============================================================ */
        var themeBtn = document.getElementById('themeToggle');

        function syncThemeButton() {
            if (!themeBtn) { return; }
            var isDark = root.classList.contains('dark');
            var label  = isDark ? 'สลับเป็นโหมดสว่าง' : 'สลับเป็นโหมดมืด';

            setHidden(themeBtn.querySelector('.icon-to-dark'), isDark);    // พระจันทร์ = กดเพื่อเข้าโหมดมืด
            setHidden(themeBtn.querySelector('.icon-to-light'), !isDark);  // พระอาทิตย์ = กดเพื่อเข้าโหมดสว่าง

            themeBtn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeBtn.setAttribute('aria-label', label);
            themeBtn.setAttribute('title', label);
        }

        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var isDark = root.classList.toggle('dark');
                store(KEY_THEME, isDark ? 'dark' : 'light');
                syncThemeButton();
            });
            syncThemeButton();
        }

        /* ============================================================
         *  Esc ปิดทุกอย่างที่เปิดค้างอยู่
         * ============================================================ */
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.keyCode === 27) {
                if (!isDesktop() && drawerIsOpen()) { closeDrawer(); }
            }
        });

        /* ============================================================
         *  5. ยืนยันก่อนออกจากระบบ
         *     ใช้กล่องของระบบ (SweetAlert2) ไม่ใช่ confirm() ของเบราว์เซอร์
         * ============================================================ */
        var logoutLinks = document.querySelectorAll('a[href*="logout.php"]');
        for (var L = 0; L < logoutLinks.length; L++) {
            logoutLinks[L].addEventListener('click', function (event) {
                if (typeof window.appConfirm !== 'function') { return; }   // swal โหลดไม่ขึ้น ปล่อยผ่านตามปกติ

                event.preventDefault();
                var url = this.getAttribute('href');

                window.appConfirm({
                    icon:        'question',
                    title:       'ออกจากระบบ',
                    text:        'ต้องการออกจากระบบตอนนี้หรือไม่',
                    confirmText: 'ออกจากระบบ',
                    cancelText:  'อยู่ต่อ'
                }).then(function (ok) {
                    if (ok) { window.location.href = url; }
                });
            });
        }

        /* ============================================================
         *  6. แถบแท็บที่เลื่อนได้ — เลื่อนแท็บที่เปิดอยู่มาให้เห็น
         * ============================================================
         *  บนจอมือถือแถบแท็บกว้างเกินจอแล้วเลื่อนเอา ซึ่งเริ่มที่ซ้ายสุดเสมอ
         *  ถ้าแท็บที่เปิดอยู่เป็นตัวท้าย ๆ ผู้ใช้จะเห็นแต่แท็บอื่นที่ไม่ได้เปิดอยู่
         *  แล้วเข้าใจว่ากดแท็บไม่ติด เพราะไม่มีอะไรบอกว่ายังมีของเลยขอบขวาไปอีก
         *
         *  ไม่ใช้ scrollIntoView เพราะมันเลื่อนทั้งหน้าในแนวตั้งไปด้วย
         *  ผลคือหัวเรื่องของหน้าถูกดันหายไปตั้งแต่เพิ่งเปิดเข้ามา
         * ============================================================ */
        var strips = document.querySelectorAll('.tab-strip-scroll');
        for (var s = 0; s < strips.length; s++) {
            var strip = strips[s];

            /* ไม่ล้นก็ไม่มีอะไรให้เลื่อน */
            if (strip.scrollWidth <= strip.clientWidth) { continue; }

            var current = strip.querySelector('[aria-current="page"]');
            if (!current) { continue; }

            /* วัดด้วย getBoundingClientRect ไม่ใช่ offsetLeft
               เพราะ offsetLeft นับจาก "บรรพบุรุษที่มี position" ไม่ใช่จากตัวแถบ
               แถบแท็บเป็น static ค่าที่ได้จึงรวมระยะขอบของหน้าเข้ามาด้วย
               แล้วเลื่อนเพี้ยนไปเท่ากับ padding ของกรอบหน้า */
            var stripBox = strip.getBoundingClientRect();
            var itemBox  = current.getBoundingClientRect();
            var left     = itemBox.left - stripBox.left + strip.scrollLeft;

            /* จัดให้อยู่กลางเท่าที่ทำได้ เห็นแท็บข้างเคียงทั้งสองฝั่งด้วย
               จะได้รู้ว่าแถบนี้เลื่อนได้ ไม่ใช่มีอยู่แค่นี้ */
            var want = left - (strip.clientWidth - itemBox.width) / 2;
            var max  = strip.scrollWidth - strip.clientWidth;

            strip.scrollLeft = Math.max(0, Math.min(want, max));
        }


        /* ============================================================
         *  7. โลโก้สำรอง
         * ============================================================ */
        var logo     = document.querySelector('.logo-img');
        var fallback = document.querySelector('.logo-fallback');
        if (logo && fallback) {
            var useFallback = function () {
                setHidden(logo, true);
                setHidden(fallback, false);
            };
            logo.addEventListener('error', useFallback);
            if (logo.complete && logo.naturalWidth === 0) { useFallback(); }
        }
    }
})();
