/* ============================================================
 *  filter-panel.js — แถบตัวกรองด้านขวาของพื้นที่เนื้อหา
 *
 *  ใช้ร่วมกันทุกหน้าที่มีตัวกรอง (ประวัติเอกสาร / รายงาน / หน้าเบิก)
 *  หน้าไหนมี #filterPanel อยู่ ไฟล์นี้จะทำงานให้เองโดยไม่ต้องเรียก
 *
 *  หน้าที่ของไฟล์นี้:
 *    1. เปิด/ปิดแถบบนจอเล็ก (จอกว้างแถบกางค้างไว้ตลอด ไม่ต้องกดเปิด)
 *    2. นับว่าตอนนี้กรองอะไรอยู่กี่อย่าง แล้วขึ้นเป็นตัวเลขบนปุ่ม
 *       เพื่อไม่ให้เกิดกรณี "ทำไมไม่มีข้อมูล" ทั้งที่ลืมว่ายังกรองค้างไว้
 *    3. ปุ่มล้างตัวกรอง คืนทุกช่องเป็นค่าตั้งต้นแล้วสั่งโหลดใหม่
 *
 *  ช่องไหนไม่อยากให้นับเป็นตัวกรอง ใส่ data-fp-ignore
 *  ช่องไหนมีค่าตั้งต้นที่ไม่ใช่ค่าว่าง (เช่นช่วงวันที่ของรายงาน)
 *  ระบบจะจำค่าแรกที่เจอไว้เป็นค่าตั้งต้นเอง แล้วนับเฉพาะตอนที่ถูกแก้
 * ============================================================ */
(function () {
    'use strict';

    function el(id) { return document.getElementById(id); }

    function setHidden(node, hide) {
        if (!node) { return; }
        if (hide) { node.setAttribute('hidden', ''); } else { node.removeAttribute('hidden'); }
    }

    function fire(node, name) {
        var ev;
        if (typeof Event === 'function') {
            ev = new Event(name, { bubbles: true });
        } else {
            ev = document.createEvent('HTMLEvents');
            ev.initEvent(name, true, false);
        }
        node.dispatchEvent(ev);
    }


    function init() {
        var panel = el('filterPanel');
        if (!panel) { return; }

        var toggle   = el('fpToggle');
        var closeBtn = el('fpClose');
        var clearBtn = el('fpClear');
        var backdrop = el('fpBackdrop');
        var count    = el('fpCount');

        /* ห้ามย้ายฉากหลังไปไว้ที่ body — #appMain เป็น position: fixed
           จึงสร้างชั้นซ้อนของตัวเอง ฉากหลังที่ไปอยู่ชั้นนอกจะทับแถบเสมอ
           แม้แถบจะตั้ง z-index สูงกว่า (อาการ: มือถือกางตัวกรองแล้วกดอะไรไม่ได้)
           เก็บไว้ที่เดิมทั้งคู่อยู่ชั้นเดียวกัน และ position: fixed
           ก็ไม่ได้ถูก overflow ของ #appMain ตัดอยู่แล้ว */

        /* จำค่าตั้งต้นของทุกช่องไว้ตั้งแต่ตอนโหลด
           ใช้ทั้งตอนนับว่ากรองอยู่กี่อย่าง และตอนกดล้างตัวกรอง */
        var fields = panel.querySelectorAll('input, select');
        var base   = [];
        for (var i = 0; i < fields.length; i++) {
            base.push(fields[i].type === 'checkbox' ? fields[i].checked : fields[i].value);
        }


        /* ---------- นับตัวกรองที่ใช้อยู่ ---------- */
        function refreshCount() {
            var n = 0;
            for (var i = 0; i < fields.length; i++) {
                var f = fields[i];
                if (f.hasAttribute('data-fp-ignore')) { continue; }

                if (f.type === 'checkbox') {
                    if (f.checked !== base[i]) { n++; }
                } else if (String(f.value) !== String(base[i])) {
                    n++;
                }
            }

            if (count) {
                count.textContent = String(n);
                setHidden(count, n === 0);
            }
            panel.classList.toggle('has-filter', n > 0);
            if (clearBtn) { clearBtn.disabled = (n === 0); }
        }


        /* ---------- เปิด/ปิดบนจอเล็ก ---------- */
        function open() {
            panel.classList.add('is-open');
            if (backdrop) { setHidden(backdrop, false); }
            if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }

            /* โฟกัสช่องแรกให้พิมพ์ค้นได้เลย แต่ไม่ทำบนจอสัมผัส
               ไม่งั้นคีย์บอร์ดเด้งขึ้นมาบังตัวกรองที่เพิ่งเปิด */
            if (window.matchMedia('(pointer: fine)').matches && fields.length > 0) {
                fields[0].focus();
            }
        }

        function close() {
            panel.classList.remove('is-open');
            if (backdrop) { setHidden(backdrop, true); }
            if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
        }

        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.addEventListener('click', function () {
                if (panel.classList.contains('is-open')) { close(); } else { open(); }
            });
        }
        if (closeBtn) { closeBtn.addEventListener('click', close); }
        if (backdrop) { backdrop.addEventListener('click', close); }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('is-open')) { close(); }
        });

        /* จอกว้างแถบกางค้างอยู่แล้ว ถ้ายังค้างสถานะ "เปิด" จากตอนจอเล็กไว้
           พอหมุนจอกลับมาจะมีฉากหลังทึบค้างบังหน้าจอ */
        window.addEventListener('resize', function () {
            if (window.matchMedia('(min-width: 1280px)').matches) { close(); }
        });


        /* ---------- ล้างตัวกรอง ---------- */
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                for (var i = 0; i < fields.length; i++) {
                    var f = fields[i];
                    if (f.type === 'checkbox') { f.checked = base[i]; }
                    else { f.value = base[i]; }

                    /* select ที่ถูกครอบด้วยช่องค้นหา ต้องสั่งให้วาดข้อความบนปุ่มใหม่ */
                    if (f.tagName === 'SELECT' && window.SelectSearch) {
                        window.SelectSearch.refresh(f);
                    }
                    fire(f, f.tagName === 'SELECT' || f.type === 'checkbox' ? 'change' : 'input');
                }
                refreshCount();
            });
        }

        panel.addEventListener('input',  refreshCount);
        panel.addEventListener('change', refreshCount);

        refreshCount();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
