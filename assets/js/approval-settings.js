/* ============================================================
 *  approval-settings.js — หน้า "ตั้งค่าอนุมัติ"
 *
 *  จัดการรายชื่อผู้อนุมัติใบปรับยอด (tb_adjust_approver) แบบทั้งชุด
 *
 *    ซ้าย  ผู้ใช้ทั้งหมด — ช่องติ๊กอยู่ติดชื่อ กดได้ทั้งแถว (ไม่ต้องเล็งสวิตช์ที่ขอบขวาจอ)
 *    ขวา   รายชื่อหลังบันทึก — คนที่เพิ่งเพิ่มมีป้าย "เพิ่มใหม่"
 *          คนเดิมที่ถูกติ๊กออกยังเห็นอยู่ในกลุ่ม "จะถูกเอาออก" พร้อมปุ่มย้อน
 *    บันทึก กล่องยืนยันสรุปว่าเพิ่มใคร เอาใครออก ก่อนส่งจริง
 *
 *  ส่ง usernames ที่เลือกทั้งหมดกลับไปแทนที่ของเดิม (API เดิม ไม่เปลี่ยน)
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
    function nameOf(u) { return u.full_name || u.username; }
    function metaOf(u) {
        return u.username + ' · ' + u.role_label + (u.department ? ' · ' + u.department : '');
    }
    function icons(root) { if (window.renderIcons) { window.renderIcons(root); } }

    var USERS   = [];    // [{username, full_name, role, role_label, position, department}]
    var byName  = {};    // username -> user
    var checked = {};    // username -> true  (สถานะที่กำลังแก้)
    var base    = {};    // username -> true  (สถานะที่บันทึกไว้)
    var saved   = false;


    /* ---------- ความต่างจากที่บันทึกไว้ ---------- */
    function changes() {
        var added = [], removed = [];
        for (var i = 0; i < USERS.length; i++) {
            var n = USERS[i].username;
            if (checked[n] && !base[n]) { added.push(USERS[i]); }
            if (base[n] && !checked[n]) { removed.push(USERS[i]); }
        }
        return { added: added, removed: removed };
    }

    function dirty() {
        var c = changes();
        return c.added.length + c.removed.length > 0;
    }

    function pickedList() {
        var out = [];
        for (var i = 0; i < USERS.length; i++) {
            if (checked[USERS[i].username]) { out.push(USERS[i]); }
        }
        return out;
    }

    function setChecked(username, on) {
        if (on) { checked[username] = true; } else { delete checked[username]; }
    }

    function toggle(username) {
        setChecked(username, !checked[username]);
        renderAll();
        bump();
    }


    /* ============================================================
     *  แท็บสลับฝั่ง (เฉพาะจอ < lg — จอกว้างซ่อนแท็บด้วย CSS แล้วเห็นสองฝั่งพร้อมกัน)
     * ============================================================ */
    function showTab(name, focus) {
        el('apWrap').setAttribute('data-tab', name);
        var tabs = document.querySelectorAll('.ap-tabs .tf-tab');
        for (var i = 0; i < tabs.length; i++) {
            var on = tabs[i].getAttribute('data-tab') === name;
            tabs[i].classList.toggle('is-active', on);
            tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
            tabs[i].setAttribute('tabindex', on ? '0' : '-1');
            if (on && focus) { tabs[i].focus(); }
        }
    }

    /* แตะเลือกในฝั่ง "ผู้ใช้ทั้งหมด" แล้วคนนั้นไปโผล่อีกแท็บที่มองไม่เห็น
       ให้ตัวเลขบนแท็บ "ผู้อนุมัติ" เด้งหนึ่งที บอกว่ามีอะไรเปลี่ยนตรงนั้น */
    function bump() {
        var n = el('tabPickNum');
        if (!n) { return; }
        n.classList.remove('ap-bump');
        void n.offsetWidth;          // รีสตาร์ต animation
        n.classList.add('ap-bump');
    }


    /* ============================================================
     *  ซ้าย: ผู้ใช้ทั้งหมด
     *  ทั้งแถวเป็นปุ่มเดียว (role="checkbox") — กดตรงไหนของแถวก็ได้ คีย์บอร์ดใช้ Space/Enter ได้
     * ============================================================ */
    function renderAllUsers() {
        var q    = norm(el('userSearch').value);
        var only = el('onlyPicked').checked;
        var list = el('userList');
        var html = '';
        var shown = 0;

        for (var i = 0; i < USERS.length; i++) {
            var u = USERS[i];
            var on = !!checked[u.username];
            if (only && !on) { continue; }

            var hay = norm(u.username + ' ' + u.full_name + ' ' + u.department + ' ' + u.position + ' ' + u.role_label);
            if (q !== '' && hay.indexOf(q) === -1) { continue; }
            shown++;

            html +=
                '<li>' +
                '<button type="button" role="checkbox" aria-checked="' + (on ? 'true' : 'false') + '"' +
                ' data-user="' + esc(u.username) + '" class="ap-row' + (on ? ' is-on' : '') + '">' +
                    '<span class="ap-box" aria-hidden="true"><i data-lucide="check" class="h-3.5 w-3.5"></i></span>' +
                    '<span class="ap-avatar" aria-hidden="true">' + esc(initial(nameOf(u))) + '</span>' +
                    '<span class="ap-text">' +
                        '<span class="ap-name">' + esc(nameOf(u)) + '</span>' +
                        '<span class="ap-meta">' + esc(metaOf(u)) + '</span>' +
                    '</span>' +
                    (on ? '<span class="ap-tag">ผู้อนุมัติ</span>' : '') +
                '</button>' +
                '</li>';
        }

        list.innerHTML = html;
        setHidden(el('empty'), shown > 0);
        el('empty').textContent = only ? 'ยังไม่ได้เลือกใคร' : 'ไม่พบผู้ใช้ที่ค้นหา';
        el('allCount').textContent = USERS.length + ' คน';
        el('tabAllNum').textContent = String(USERS.length);
        icons(list);
    }


    /* ============================================================
     *  ขวา: รายชื่อหลังบันทึก + คนที่จะถูกเอาออก
     * ============================================================ */
    function personHtml(u, kind) {
        var tag = '';
        if (kind === 'new')     { tag = '<span class="ap-tag is-new">เพิ่มใหม่</span>'; }

        var btn = (kind === 'removed')
            ? '<button type="button" class="ap-undo" data-undo="' + esc(u.username) + '">' +
                  '<i data-lucide="undo-2" class="h-3.5 w-3.5" aria-hidden="true"></i>ย้อนกลับ</button>'
            : '<button type="button" class="ap-remove" data-remove="' + esc(u.username) + '"' +
                  ' aria-label="เอา ' + esc(nameOf(u)) + ' ออกจากผู้อนุมัติ" title="เอาออก">' +
                  '<i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button>';

        return '<li class="ap-pick' + (kind === 'removed' ? ' is-removed' : (kind === 'new' ? ' is-new' : '')) + '">' +
                   '<span class="ap-avatar" aria-hidden="true">' + esc(initial(nameOf(u))) + '</span>' +
                   '<span class="ap-text">' +
                       '<span class="ap-name">' + esc(nameOf(u)) + tag + '</span>' +
                       '<span class="ap-meta">' + esc(metaOf(u)) + '</span>' +
                   '</span>' +
                   btn +
               '</li>';
    }

    function renderPicked() {
        var picked = pickedList();
        var c = changes();
        var html = '';

        /* คนเดิมขึ้นก่อน คนที่เพิ่งเพิ่มต่อท้าย — คนที่เพิ่งกดจะได้อยู่ที่เดิมทุกครั้งให้ตรวจง่าย */
        for (var i = 0; i < picked.length; i++) {
            if (base[picked[i].username]) { html += personHtml(picked[i], 'kept'); }
        }
        for (var j = 0; j < picked.length; j++) {
            if (!base[picked[j].username]) { html += personHtml(picked[j], 'new'); }
        }
        el('pickList').innerHTML = html;

        var rhtml = '';
        for (var k = 0; k < c.removed.length; k++) { rhtml += personHtml(c.removed[k], 'removed'); }
        el('removedList').innerHTML = rhtml;
        setHidden(el('removedBox'), c.removed.length === 0);

        setHidden(el('chipsEmpty'), picked.length > 0);
        el('pickCount').textContent = picked.length + ' คน';
        el('tabPickNum').textContent = String(picked.length);
        icons(el('pickList'));
        icons(el('removedList'));
    }


    function refreshBar() {
        var c = changes();
        var d = c.added.length + c.removed.length > 0;
        var parts = [];
        if (c.added.length)   { parts.push('เพิ่ม ' + c.added.length + ' คน'); }
        if (c.removed.length) { parts.push('เอาออก ' + c.removed.length + ' คน'); }
        el('dirtyText').textContent = parts.join(' · ') + ' — ยังไม่ได้บันทึก';

        el('btnSave').disabled = !d;
        el('btnRevert').disabled = !d;
        setHidden(el('dirtyHint'), !d);
    }

    function renderAll() {
        renderAllUsers();
        renderPicked();
        refreshBar();
    }


    /* ============================================================
     *  บันทึก — ยืนยันพร้อมสรุปสิ่งที่จะเปลี่ยนก่อนเสมอ
     * ============================================================ */
    function busy(on) {
        var b = el('btnSave');
        b.disabled = on || !dirty();
        setHidden(b.querySelector('.btn-icon'), on);
        setHidden(b.querySelector('.btn-spinner'), !on);
    }

    function names(list) {
        var out = [];
        for (var i = 0; i < list.length; i++) { out.push(nameOf(list[i])); }
        return out.join(', ');
    }

    function save() {
        var c = changes();
        var total = pickedList().length;

        var lines = [];
        if (c.added.length)   { lines.push('เพิ่ม: ' + names(c.added)); }
        if (c.removed.length) { lines.push('เอาออก: ' + names(c.removed)); }
        lines.push('หลังบันทึกจะมีผู้อนุมัติ ' + total + ' คน');
        if (total === 0) {
            lines.push('ไม่มีผู้อนุมัติเลย ใบปรับยอดที่ยื่นเข้ามาจะค้างรอจนกว่าจะเลือกใหม่');
        }

        var go = function (yes) {
            if (!yes) { return; }
            doSave();
        };

        /* แยกบรรทัดด้วย html — ข้อความธรรมดาของ SweetAlert ยุบ \n เป็นช่องว่าง
           ทุกบรรทัดผ่าน esc() แล้ว ชื่อผู้ใช้จึงฉีด html เข้ามาไม่ได้ */
        var html = '<div style="text-align:left;line-height:1.7">';
        for (var i = 0; i < lines.length; i++) { html += '<div>' + esc(lines[i]) + '</div>'; }
        html += '</div>';

        var opts = { title: 'บันทึกรายชื่อผู้อนุมัติ?', html: html, confirmText: 'บันทึก' };
        if (total === 0 && typeof window.appConfirmDanger === 'function') {
            window.appConfirmDanger(opts).then(go);
        } else if (typeof window.appConfirm === 'function') {
            window.appConfirm(opts).then(go);
        } else {
            go(window.confirm(lines.join('\n')));
        }
    }

    function doSave() {
        var list = pickedList().map(function (u) { return u.username; });

        busy(true);
        post({ action: 'save', csrf_token: CFG.csrfToken, usernames: list }, function (res) {
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
        for (var k in base) { if (base[k]) { checked[k] = true; } }
        renderAll();
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


    function bind() {
        el('userList').addEventListener('click', function (e) {
            var row = e.target.closest('[data-user]');
            if (row) { toggle(row.getAttribute('data-user')); }
        });

        el('pickList').addEventListener('click', function (e) {
            var b = e.target.closest('[data-remove]');
            if (b) { setChecked(b.getAttribute('data-remove'), false); renderAll(); }
        });

        /* แท็บ: คลิก หรือลูกศรซ้าย/ขวาสลับ (แบบ tablist มาตรฐาน) */
        var bar = document.querySelector('.ap-tabs');
        if (bar) {
            bar.addEventListener('click', function (e) {
                var t = e.target.closest('[data-tab]');
                if (t) { showTab(t.getAttribute('data-tab'), false); }
            });
            bar.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
                e.preventDefault();
                var cur = el('apWrap').getAttribute('data-tab');
                showTab(cur === 'all' ? 'picked' : 'all', true);
            });
        }

        el('removedList').addEventListener('click', function (e) {
            var b = e.target.closest('[data-undo]');
            if (b) { setChecked(b.getAttribute('data-undo'), true); renderAll(); }
        });

        el('userSearch').addEventListener('input', renderAllUsers);
        el('onlyPicked').addEventListener('change', renderAllUsers);
        el('btnSave').addEventListener('click', save);
        el('btnRevert').addEventListener('click', revert);

        window.addEventListener('beforeunload', function (e) {
            if (!saved && dirty()) { e.preventDefault(); e.returnValue = ''; }
        });
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
            bind();
            renderAll();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
