/* ============================================================
 *  change-password.js — ฟอร์มเปลี่ยนรหัสผ่านของบัญชีตัวเอง
 *
 *  1) ปุ่มรูปตาเปิด/ปิดการแสดงรหัสผ่านของแต่ละช่อง
 *  2) ตรวจเบื้องต้นในหน้าก่อนยิงไปเซิร์ฟเวอร์ (เซิร์ฟเวอร์ยังตรวจซ้ำอยู่ดี)
 *  3) สำเร็จแล้วล้างฟอร์ม และเตือนว่าครั้งหน้าต้องใช้รหัสใหม่
 *  4) แจ้งผลสองแบบ ตามว่าผู้ใช้กำลังมองที่ไหนตอนนั้น
 *       - ตรวจไม่ผ่านตั้งแต่ในหน้า -> กล่องแดงในฟอร์ม สายตาอยู่ที่ช่องกรอกอยู่แล้ว
 *       - เซิร์ฟเวอร์ตอบไม่ผ่าน    -> กล่องแดงในฟอร์ม + กล่องเด้งกลางจอ
 *         เพราะเพิ่งกดปุ่มเสร็จ ถ้าขึ้นแค่ในฟอร์มจะแยกไม่ออกจากตอนสำเร็จ
 *
 *  หมายเหตุ: ต้องสั่งซ่อน/แสดงไอคอนผ่าน attribute ไม่ใช่ property
 *  เพราะ .hidden มีเฉพาะใน HTMLElement ส่วน <svg> ที่ Lucide สร้างเป็น
 *  SVGElement จึงไม่มี property ตัวนี้ (กฎ [hidden][hidden] ใน app.css)
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.ACCOUNT;
    if (!CFG) { return; }

    function el(id) { return document.getElementById(id); }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }

    /* ข้อผิดพลาดที่เกิดจากการกรอก ตรวจได้ในหน้าเลย ไม่ต้องเด้งกล่อง
       เพราะผู้ใช้เห็นพร้อมกับช่องที่ถูกโฟกัสอยู่แล้ว */
    function showError(message) {
        el('pwErrorText').textContent = message;
        setHidden(el('pwError'), false);
    }

    /* ข้อผิดพลาดที่เซิร์ฟเวอร์ตอบกลับมา ต้องเด้งกล่องกลางจอด้วย
       เพราะผู้ใช้เพิ่งกดปุ่มแล้วสายตายังอยู่ที่ปุ่ม ถ้าขึ้นแค่กล่องในฟอร์ม
       จะเหมือนกดแล้วไม่มีอะไรเกิดขึ้น แยกไม่ออกจากตอนที่สำเร็จ */
    function showServerError(message) {
        showError(message);
        if (typeof window.appError === 'function') {
            window.appError(message, 'เปลี่ยนรหัสผ่านไม่สำเร็จ');
        }
    }

    function clearError() {
        setHidden(el('pwError'), true);
    }

    function busy(on) {
        var btn = el('btnChange');
        btn.disabled = on;
        setHidden(btn.querySelector('.btn-icon'), on);
        setHidden(btn.querySelector('.btn-spinner'), !on);
    }


    function init() {

        /* ---------- ปุ่มรูปตา ---------- */
        var toggles = document.querySelectorAll('.pw-toggle');
        for (var i = 0; i < toggles.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var input = el(btn.getAttribute('data-target'));
                    if (!input) { return; }

                    var show = (input.type === 'password');
                    input.type = show ? 'text' : 'password';

                    setHidden(btn.querySelector('.pw-show'), show);
                    setHidden(btn.querySelector('.pw-hide'), !show);
                    btn.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
                    input.focus();
                });
            })(toggles[i]);
        }

        /* พิมพ์แก้แล้วให้ข้อความผิดพลาดเก่าหายไป ไม่ค้างจนสับสน */
        var fields = ['currentPassword', 'newPassword', 'confirmPassword'];
        for (var f = 0; f < fields.length; f++) {
            el(fields[f]).addEventListener('input', clearError);
        }

        el('pwForm').addEventListener('submit', function (e) {
            e.preventDefault();
            submit();
        });
    }


    function submit() {
        var current = el('currentPassword').value;
        var next    = el('newPassword').value;
        var confirm = el('confirmPassword').value;

        if (current === '') { showError('กรุณากรอกรหัสผ่านปัจจุบัน'); el('currentPassword').focus(); return; }
        if (next === '')    { showError('กรุณากรอกรหัสผ่านใหม่');    el('newPassword').focus();     return; }
        if (next !== confirm) {
            showError('รหัสผ่านใหม่กับช่องยืนยันไม่ตรงกัน');
            el('confirmPassword').focus();
            return;
        }
        if (next === current) {
            showError('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
            el('newPassword').focus();
            return;
        }

        clearError();
        busy(true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            busy(false);

            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }

            if (!res) {
                showServerError('เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่');
                return;
            }
            if (!res.ok) {
                showServerError(res.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ');
                el('currentPassword').focus();
                el('currentPassword').select();
                return;
            }

            el('pwForm').reset();

            if (typeof window.appSuccess === 'function') {
                window.appSuccess('เปลี่ยนรหัสผ่านเรียบร้อย ครั้งหน้าให้เข้าระบบด้วยรหัสใหม่');
            } else {
                alert('เปลี่ยนรหัสผ่านเรียบร้อย');
            }
        };

        xhr.onerror = function () {
            busy(false);
            showServerError('ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่');
        };

        xhr.send(JSON.stringify({
            action: 'change_password',
            csrf_token: CFG.csrfToken,
            current_password: current,
            new_password: next,
            confirm_password: confirm
        }));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
