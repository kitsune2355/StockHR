/* ============================================================
 *  login.js — พฤติกรรมของหน้าเข้าสู่ระบบ
 *  1) ตรวจฟอร์มฝั่ง client (แสดง error ใต้ field ที่ผิด)
 *  2) ปุ่มแสดง/ซ่อนรหัสผ่าน
 *  3) เตือนเมื่อเปิด Caps Lock
 *  4) กันกดปุ่ม submit ซ้ำ
 *
 *  หมายเหตุ:
 *   - การตรวจสอบจริงอยู่ที่ฝั่ง PHP (config_ctrl/checkmember.php) เสมอ
 *   - รอ DOMContentLoaded ก่อนเริ่ม เพราะ app-icons.js จะแทน <i data-lucide>
 *     ด้วย <svg> ตอนนั้น ถ้าจับ element ก่อนหน้านั้นจะได้ตัวที่ถูกทิ้งไปแล้ว
 * ============================================================ */
(function () {
    'use strict';

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        var form      = document.getElementById('loginForm');
        var userInput = document.getElementById('LogInName');
        var passInput = document.getElementById('LogInPassWord');
        var submitBtn = document.getElementById('submitBtn');
        var toggleBtn = document.getElementById('togglePassword');
        var capsHint  = document.getElementById('caps-hint');

        if (!form || !userInput || !passInput || !submitBtn) { return; }

        /* ---------- ซ่อน/แสดง element ----------
         * ต้องสั่งผ่าน attribute ไม่ใช่ property (el.hidden = true)
         * เพราะ .hidden มีเฉพาะใน HTMLElement ส่วน <svg> ที่ Lucide สร้าง
         * เป็น SVGElement จึงไม่มี property ตัวนี้ สั่งแล้วไม่มีผลกับหน้าจอ
         * ตัวที่ทำให้ซ่อนจริงคือกฎ [hidden][hidden] ใน assets/css/app.css
         */
        function setHidden(el, hide) {
            if (!el) { return; }
            if (hide) {
                el.setAttribute('hidden', '');
            } else {
                el.removeAttribute('hidden');
            }
        }

        /* ---------- โลโก้บริษัท ----------
         * ถ้ายังไม่ได้วางไฟล์ assets/img/logo-happyland.png หรือโหลดรูปไม่สำเร็จ
         * ให้สลับไปใช้ไอคอนแทน จะได้ไม่เห็นสัญลักษณ์รูปเสียบนหน้า login
         */
        (function () {
            var logo     = document.querySelector('.logo-img');
            var fallback = document.querySelector('.logo-fallback');
            if (!logo || !fallback) { return; }

            function useFallback() {
                setHidden(logo, true);
                setHidden(fallback, false);
            }

            logo.addEventListener('error', useFallback);

            // เผื่อกรณีรูปโหลดพังไปแล้วก่อนสคริปต์นี้จะทำงาน
            if (logo.complete && logo.naturalWidth === 0) { useFallback(); }
        })();

        /* class ของช่องกรอกในสถานะปกติ / สถานะผิดพลาด (Tailwind utility) */
        var CLASS_NORMAL = ['border-slate-200', 'focus:border-brand-500', 'focus:ring-brand-100'];
        var CLASS_ERROR  = ['border-red-400', 'focus:border-red-500', 'focus:ring-red-100'];

        var ERROR_BOX = {
            LogInName:     document.getElementById('err-user'),
            LogInPassWord: document.getElementById('err-pass')
        };

        /* ---------- แสดง / ซ่อน error ใต้ field ---------- */
        function setFieldError(input, message) {
            var box = ERROR_BOX[input.id];
            if (!box) { return; }

            var text = box.querySelector('.err-text');

            if (message) {
                input.classList.remove.apply(input.classList, CLASS_NORMAL);
                input.classList.add.apply(input.classList, CLASS_ERROR);
                input.setAttribute('aria-invalid', 'true');
                if (text) { text.textContent = message; }
                setHidden(box, false);
            } else {
                input.classList.remove.apply(input.classList, CLASS_ERROR);
                input.classList.add.apply(input.classList, CLASS_NORMAL);
                input.removeAttribute('aria-invalid');
                if (text) { text.textContent = ''; }
                setHidden(box, true);
            }
        }

        function hasError(input) {
            return input.getAttribute('aria-invalid') === 'true';
        }

        function validate() {
            var ok = true;

            if (userInput.value.trim() === '') {
                setFieldError(userInput, 'กรุณากรอกชื่อผู้ใช้งาน');
                ok = false;
            } else {
                setFieldError(userInput, '');
            }

            if (passInput.value === '') {
                setFieldError(passInput, 'กรุณากรอกรหัสผ่าน');
                ok = false;
            } else {
                setFieldError(passInput, '');
            }

            return ok;
        }

        /* ล้าง error ทันทีที่ผู้ใช้เริ่มพิมพ์แก้ */
        [userInput, passInput].forEach(function (input) {
            input.addEventListener('input', function () {
                if (hasError(input)) { setFieldError(input, ''); }
            });
        });

        /* ---------- ปุ่มแสดง/ซ่อนรหัสผ่าน ----------
         * ใช้ไอคอน Lucide 2 ตัวที่รูปทรงต่างกันชัดเจน
         *   eye-closed = รหัสผ่านถูกซ่อนอยู่ → กดเพื่อ "แสดง"
         *   eye        = รหัสผ่านกำลังแสดง  → กดเพื่อ "ซ่อน" (ย้ำสถานะด้วยสีส้ม)
         */
        if (toggleBtn) {
            var eyeClosed = toggleBtn.querySelector('.icon-eye-closed');
            var eyeOpen   = toggleBtn.querySelector('.icon-eye-open');

            toggleBtn.addEventListener('click', function () {
                var willShow = passInput.type === 'password';
                var label    = willShow ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน';

                passInput.type = willShow ? 'text' : 'password';
                toggleBtn.setAttribute('aria-pressed', willShow ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', label);
                toggleBtn.setAttribute('title', label);

                setHidden(eyeClosed, willShow);
                setHidden(eyeOpen, !willShow);

                /* ตอนรหัสผ่านเปิดอยู่ ทำปุ่มให้เด่นขึ้น เตือนว่ารหัสกำลังโชว์บนจอ */
                toggleBtn.classList.toggle('text-brand-600', willShow);
                toggleBtn.classList.toggle('bg-brand-50', willShow);
                toggleBtn.classList.toggle('text-slate-400', !willShow);

                passInput.focus();
            });
        }

        /* ---------- เตือน Caps Lock ---------- */
        if (capsHint) {
            var updateCapsHint = function (event) {
                if (typeof event.getModifierState !== 'function') { return; }
                setHidden(capsHint, !event.getModifierState('CapsLock'));
            };

            passInput.addEventListener('keydown', updateCapsHint);
            passInput.addEventListener('keyup', updateCapsHint);
            passInput.addEventListener('blur', function () { setHidden(capsHint, true); });
        }

        /* ---------- submit: ตรวจก่อน แล้วกันกดซ้ำ ---------- */
        form.addEventListener('submit', function (event) {
            if (!validate()) {
                event.preventDefault();
                var firstError = form.querySelector('[aria-invalid="true"]');
                if (firstError) { firstError.focus(); }
                return;
            }

            var btnIcon    = submitBtn.querySelector('.btn-icon');
            var btnSpinner = submitBtn.querySelector('.btn-spinner');
            var btnLabel   = submitBtn.querySelector('.btn-label');

            submitBtn.disabled = true;
            setHidden(btnIcon, true);
            setHidden(btnSpinner, false);
            if (btnLabel) { btnLabel.textContent = 'กำลังเข้าสู่ระบบ...'; }
        });

        /* โฟกัสช่องแรกที่ยังว่าง */
        if (userInput.value.trim() === '') {
            userInput.focus();
        } else {
            passInput.focus();
        }
    }
})();
