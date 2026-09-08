/* ============================================================
 *  approval-settings.js — หน้า "ตั้งค่าอนุมัติ"
 *
 *  จัดการรายชื่อผู้อนุมัติใบปรับยอด (tb_adjust_approver) แบบทั้งชุด
 *  โหลด bootstrap -> เรนเดอร์รายชื่อผู้ใช้พร้อมสวิตช์เปิด/ปิด
 *  ติ๊กใครไว้ แถบ "ผู้อนุมัติที่เลือกไว้" ด้านบนขึ้นเป็นชิป กด × ที่ชิปเอาออกได้
 *  กดบันทึก -> ส่ง usernames ที่เปิดสวิตช์ทั้งหมดกลับไปแทนที่ของเดิม
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.APPROVAL;
    if (!CFG) { return; }

    function el(id) { return document.getElementById(id); }
    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function initial(name) {
        name = String(name || '').replace(/^\s+/, '');
        if (name === '') { return '?'; }
        var ch = name.charAt(0);
        return /[a-z]/i.test(ch) ? ch.toUpperCase() : ch;
    }
    function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ''); }

    var USERS   = [];    // [{username, full_name, role, role_label, position, department}]
    var byName  = {};    // username -> user
    var checked = {};    // username -> true
    var base    = {};    // สถานะตอนโหลด
    var saved   = false;


    function dirty() {
        var k;
        for (k in checked) { if (checked[k] && !base[k]) { return true; } }
        for (k in base)    { if (base[k] && !checked[k]) { return true; } }
        return false;
    }

    function pickedList() {
        var out = [];
        for (var i = 0; i < USERS.length; i++) {
            if (checked[USERS[i].username]) { out.push(USERS[i]); }
        }
        return out;
    }


    /* ---------- ชิปผู้อนุมัติที่เลือกไว้ ---------- */
    function renderChips() {
        var picked = pickedList();
        var box = el('chips');
        box.innerHTML = '';

        for (var i = 0; i < picked.length; i++) {
            var u = picked[i];
            var chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 py-1 pl-2.5 pr-1 text-sm text-brand-800 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200';
            chip.innerHTML =
                '<span class="font-medium"></span>' +
                '<button type="button" class="flex h-5 w-5 items-center justify-center rounded-full text-brand-500 transition hover:bg-brand-100 hover:text-brand-700 dark:hover:bg-brand-500/20" aria-label="เอาออก">' +
                '<i data-lucide="x" class="h-3.5 w-3.5" aria-hidden="true"></i></button>';
            chip.children[0].textContent = u.full_name || u.username;

            (function (username) {
                chip.querySelector('button').addEventListener('click', function () {
                    setChecked(username, false);
                    render();
                    renderChips();
                    refreshBar();
                });
            })(u.username);

            box.appendChild(chip);
        }

        setHidden(el('chips'), picked.length === 0);
        setHidden(el('chipsEmpty'), picked.length > 0);
        el('pickCount').textContent = picked.length + ' คน';
        if (window.renderIcons) { window.renderIcons(box); }
    }


    /* ---------- รายชื่อผู้ใช้ทั้งหมด ---------- */
    function render() {
        var q = norm(el('userSearch').value);
        var list = el('userList');
        list.innerHTML = '';
        var shown = 0;

        for (var i = 0; i < USERS.length; i++) {
            var u = USERS[i];
            var hay = norm(u.username + ' ' + u.full_name + ' ' + u.department + ' ' + u.position + ' ' + u.role_label);
            if (q !== '' && hay.indexOf(q) === -1) { continue; }
            shown++;

            var on = !!checked[u.username];
            var li = document.createElement('li');
            li.className = 'flex items-center gap-3 px-4 py-2.5 transition ' +
                (on ? 'bg-brand-50/40 dark:bg-brand-500/5' : '');

            var meta = u.username + ' · ' + u.role_label + (u.department ? ' · ' + u.department : '');

            li.innerHTML =
                '<span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-300">' +
                    esc(initial(u.full_name || u.username)) + '</span>' +
                '<span class="min-w-0 flex-1">' +
                    '<span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">' + esc(u.full_name || u.username) + '</span>' +
                    '<span class="block truncate text-xs text-slate-400 dark:text-slate-500">' + esc(meta) + '</span>' +
                '</span>' +
                '<label class="relative inline-flex flex-none cursor-pointer items-center">' +
                    '<input type="checkbox" class="peer sr-only"' + (on ? ' checked' : '') + '>' +
                    '<span class="block h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-brand-600 dark:bg-slate-700"></span>' +
                    '<span class="pointer-events-none absolute left-[3px] top-[3px] h-[18px] w-[18px] rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>' +
                '</label>';

            (function (username, row) {
                row.querySelector('input').addEventListener('change', function () {
                    setChecked(username, this.checked);
                    row.className = 'flex items-center gap-3 px-4 py-2.5 transition ' +
                        (this.checked ? 'bg-brand-50/40 dark:bg-brand-500/5' : '');
                    renderChips();
                    refreshBar();
                });
            })(u.username, li);

            list.appendChild(li);
        }

        setHidden(el('empty'), shown > 0);
    }


    function setChecked(username, on) {
        if (on) { checked[username] = true; } else { delete checked[username]; }
    }

    function refreshBar() {
        var d = dirty();
        el('btnSave').disabled = !d;
        el('btnRevert').disabled = !d;
        setHidden(el('dirtyHint'), !d);
    }


    function busy(on) {
        var b = el('btnSave');
        b.disabled = on || !dirty();
        setHidden(b.querySelector('.btn-icon'), on);
        setHidden(b.querySelector('.btn-spinner'), !on);
    }

    function save() {
        var names = pickedList().map(function (u) { return u.username; });

        busy(true);
        post({ action: 'save', csrf_token: CFG.csrfToken, usernames: names }, function (res) {
            busy(false);
            if (!res || !res.ok) {
                if (window.appError) { window.appError((res && res.message) || 'บันทึกไม่สำเร็จ', 'บันทึกไม่สำเร็จ'); }
                return;
            }
            saved = true;
            try { sessionStorage.setItem('approvalFlash', res.message || 'บันทึกเรียบร้อย'); } catch (e) {}
            window.location.reload();
        });
    }

    function revert() {
        checked = {};
        var k;
        for (k in base) { if (base[k]) { checked[k] = true; } }
        render();
        renderChips();
        refreshBar();
    }


    function post(payload, done) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
            done(res);
        };
        xhr.onerror = function () { done({ ok: false, message: 'ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่' }); };
        xhr.send(JSON.stringify(payload));
    }


    function init() {
        try {
            var flash = sessionStorage.getItem('approvalFlash');
            if (flash) {
                sessionStorage.removeItem('approvalFlash');
                if (window.appSuccess) { window.appSuccess(flash); }
            }
        } catch (e) {}

        post({ action: 'bootstrap' }, function (res) {
            if (!res || !res.ok) {
                el('loading').textContent = (res && res.message) ? res.message : 'โหลดข้อมูลไม่สำเร็จ';
                return;
            }

            USERS = res.users || [];
            byName = {};
            for (var i = 0; i < USERS.length; i++) { byName[USERS[i].username] = USERS[i]; }

            checked = {};
            base = {};
            var cur = res.current || [];
            for (var j = 0; j < cur.length; j++) {
                if (byName[cur[j]]) { checked[cur[j]] = true; base[cur[j]] = true; }
            }

            setHidden(el('loading'), true);
            setHidden(el('body'), false);
            render();
            renderChips();
            refreshBar();

            el('userSearch').addEventListener('input', render);
            el('btnSave').addEventListener('click', save);
            el('btnRevert').addEventListener('click', revert);

            window.addEventListener('beforeunload', function (e) {
                if (!saved && dirty()) { e.preventDefault(); e.returnValue = ''; }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
