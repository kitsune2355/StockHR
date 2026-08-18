/* ============================================================
 *  doc-transfer.js — ตัวเลือกสินค้าแบบสองฝั่ง (transfer) ของหน้าทำใบเบิก
 *
 *      ซ้าย  = สินค้าทั้งหมดในทะเบียน ค้นและกรองได้
 *      ขวา   = รายการที่เลือกจะเบิก พร้อมช่องจำนวน
 *
 *  ทำไมหน้าเบิกไม่ใช้ตารางแบบหน้ารับเข้า
 *    คนเบิกส่วนใหญ่ไม่ได้จำรหัสสินค้า ต้องกวาดสายตาหาจากรายการ
 *    ตารางที่ต้องกด "เพิ่มบรรทัด" แล้วค่อยเปิด dropdown ทีละบรรทัด
 *    ทำให้เลือกของ 10 อย่างต้องกด 20 ครั้ง แบบสองฝั่งกดครั้งเดียวต่อชิ้น
 *    และเห็นตลอดว่าเลือกอะไรไปแล้วบ้างโดยไม่ต้องเลื่อนกลับไปดู
 *
 *  ไฟล์นี้เปิดเผยเฉพาะ window.DocTransfer.create() ซึ่งคืนวัตถุที่มี
 *  getRows / setRows / clear เหมือนกับที่ doc-form.js ใช้กับตาราง
 *  doc-form.js จึงเรียกใช้ได้เหมือนกันโดยไม่ต้องรู้ว่าข้างในเป็นอะไร
 * ============================================================ */
(function () {
    'use strict';

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function norm(text) { return String(text).toLowerCase().replace(/\s+/g, ''); }

    function fmt(v) {
        var n = Number(v);
        if (isNaN(n)) { return '0'; }
        if (n === Math.floor(n)) { return String(n); }
        return String(Math.round(n * 100) / 100);
    }


    /* ============================================================
     *  create(options)
     *    products  รายการสินค้าพร้อมยอดคงเหลือ
     *    onChange  เรียกทุกครั้งที่รายการฝั่งขวาเปลี่ยน
     * ============================================================ */
    function create(options) {

        var products = options.products || [];
        var onChange = options.onChange || function () {};

        /* เก็บของที่เลือกเป็น map เพื่อให้เช็ค "เลือกไปหรือยัง" ได้ทันที
           ส่วนลำดับเก็บแยกไว้ใน order เพราะลำดับที่เลือกคือลำดับบรรทัดในเอกสาร */
        var chosen = {};        // product_id -> { product_id, qty, note }
        var order  = [];        // ลำดับที่เลือก

        var byId = {};
        for (var i = 0; i < products.length; i++) { byId[products[i].product_id] = products[i]; }

        var elLeft   = el('tfLeft');
        var elRight  = el('tfRight');
        var elSearch = el('tfSearch');
        var elCat    = el('tfCategory');
        var elSub    = el('tfSubCategory');
        var elStock  = el('tfInStock');
        var elCount  = el('tfCount');
        var elLeftN  = el('tfLeftCount');

        /* ตัวเลขบนแท็บของจอเล็ก — ต้องเขียนคู่กับป้ายในหัวของแต่ละฝั่งเสมอ
           เพราะบนจอเล็กหัวของฝั่งที่ไม่ได้เลือกถูกซ่อนอยู่ ตัวเลขบนแท็บจึงเป็น
           ทางเดียวที่จะรู้ว่าแตะเลือกของไปกี่รายการแล้ว */
        var elTabSrc = el('tfTabSrc');
        var elTabDst = el('tfTabDst');

        function setLeftCount(n)  {
            if (elLeftN)  { elLeftN.textContent  = String(n); }
            if (elTabSrc) { elTabSrc.textContent = String(n); }
        }
        function setPickCount(n) {
            if (elCount)  { elCount.textContent  = String(n); }
            if (elTabDst) { elTabDst.textContent = String(n); }
        }


        /* ---------- ตัวเลือกหมวดหมู่หลัก ----------
         * สร้างใหม่ทั้งชุดทุกครั้ง ไม่ใช่ต่อท้ายของเดิม เพราะ setProducts()
         * เรียกฟังก์ชันนี้ซ้ำได้เมื่อรายการสินค้าเปลี่ยนทั้งชุด
         * (เปลี่ยนหน่วยงานในใบเบิก — สินค้าที่เบิกได้ของแต่ละหน่วยงานไม่เหมือนกัน)
         *
         * ของเดิมต่อท้ายอย่างเดียว รายการจึงค้างเป็นหมวดหมู่ของหน่วยงานก่อนหน้า
         * และถ้าค่าที่เลือกไว้ไม่มีในชุดใหม่ รายการสินค้าจะว่างโดยไม่มีอะไรอธิบาย
         */
        function fillCategories() {
            var keep = Number(elCat.value) || 0;
            var stillThere = false;

            var seen = {};
            var list = [];
            for (var i = 0; i < products.length; i++) {
                var p = products[i];
                if (p.category_id > 0 && !seen[p.category_id]) {
                    seen[p.category_id] = true;
                    list.push({ id: p.category_id, name: p.category_name });
                }
            }
            list.sort(function (a, b) { return a.name > b.name ? 1 : -1; });

            elCat.innerHTML = '';

            var head = document.createElement('option');
            head.value       = '';
            head.textContent = 'ทุกหมวดหมู่';
            elCat.appendChild(head);

            for (var j = 0; j < list.length; j++) {
                var op = document.createElement('option');
                op.value       = list[j].id;
                op.textContent = list[j].name;
                elCat.appendChild(op);
                if (list[j].id === keep) { stillThere = true; }
            }

            elCat.value = stillThere ? String(keep) : '';

            if (window.SelectSearch) { window.SelectSearch.refresh(elCat); }
        }


        /* ---------- ตัวเลือกหมวดหมู่ย่อย ----------
         * สร้างใหม่ทุกครั้งที่หมวดหลักเปลี่ยน ให้เหลือเฉพาะหมวดย่อยที่อยู่ใต้
         * หมวดหลักนั้น ไม่งั้นเลือกคู่ที่ไม่มีทางมีของได้ แล้วเจอรายการว่าง
         * โดยไม่รู้ว่าทำอะไรผิด
         *
         * อ่านจาก products เหมือน fillCategories() ไม่ได้ไปดึงทะเบียนหมวดหมู่
         * ทั้งชุด เพราะรายการสินค้าที่ส่งมาให้หน้านี้ถูกกรองตามสิทธิ์หน่วยงาน
         * ของคนที่เปิดอยู่แล้ว หมวดย่อยที่หน่วยงานนี้เบิกอะไรไม่ได้เลย
         * จึงไม่ควรโผล่มาให้เลือกแล้วเจอรายการว่าง
         */
        function fillSubCategories() {
            if (!elSub) { return; }

            var cat  = Number(elCat.value) || 0;
            var keep = Number(elSub.value) || 0;
            var stillThere = false;

            var seen = {};
            var list = [];
            for (var i = 0; i < products.length; i++) {
                var p = products[i];
                if (!p.sub_category_id || p.sub_category_id <= 0) { continue; }
                if (cat > 0 && p.category_id !== cat) { continue; }
                if (seen[p.sub_category_id]) { continue; }

                seen[p.sub_category_id] = true;
                list.push({ id: p.sub_category_id, name: p.sub_category_name,
                            cat: p.category_name });
            }
            list.sort(function (a, b) { return a.name > b.name ? 1 : -1; });

            elSub.innerHTML = '';

            var head = document.createElement('option');
            head.value       = '';
            head.textContent = 'ทุกหมวดหมู่ย่อย';
            elSub.appendChild(head);

            for (var j = 0; j < list.length; j++) {
                var op = document.createElement('option');
                op.value       = list[j].id;
                op.textContent = list[j].name;

                /* ยังไม่เลือกหมวดหลัก = เห็นหมวดย่อยครบทุกอัน ซึ่งชื่อซ้ำกันข้ามหมวดได้
                   ("รายการหมึก" มีอยู่ทั้งสองหมวดหลัก) ถ้าไม่บอกหมวดแม่ จะขึ้นสองบรรทัด
                   ที่เขียนเหมือนกันเป๊ะ เลือกไปแล้วไม่รู้ว่าได้อันไหน */
                if (cat <= 0 && list[j].cat) { op.setAttribute('data-sub', list[j].cat); }

                elSub.appendChild(op);
                if (list[j].id === keep) { stillThere = true; }
            }

            elSub.value = stillThere ? String(keep) : '';

            if (window.SelectSearch) { window.SelectSearch.refresh(elSub); }
        }


        /* ---------- ป้ายหมวดหมู่บนแถวสินค้า ----------
         * ชื่อหมวดย่อยอย่างเดียวตอบไม่ได้ว่าอยู่ใต้หมวดหลักไหน
         * ทั้งที่ชื่อหมวดย่อยซ้ำกันข้ามหมวดได้ ("รายการหมึก" มีอยู่ทั้งสองหมวดหลัก)
         * แยกสองป้ายคนละสีเพราะสองชั้นนี้ใช้คนละตัวกรอง ต้องกวาดตาแยกออกได้ทันที
         */
        function catTags(p) {
            var out = '';

            /* title ไว้ให้ชื่อยาวที่ถูก CSS ตัดด้วย … ยังอ่านเต็มได้จากการชี้ค้าง */
            if (p.category_name) {
                out += '<span class="tf-tag is-cat" title="หมวดหมู่หลัก: '
                     + escapeHtml(p.category_name) + '">'
                     + escapeHtml(p.category_name) + '</span>';
            }
            if (p.sub_category_name) {
                out += '<span class="tf-tag is-sub" title="หมวดหมู่ย่อย: '
                     + escapeHtml(p.sub_category_name) + '">'
                     + escapeHtml(p.sub_category_name) + '</span>';
            }
            return out;
        }


        /* ---------- ฝั่งซ้าย : สินค้าทั้งหมด ---------- */
        function renderLeft() {
            var q    = elSearch.value.trim();
            var cat  = Number(elCat.value) || 0;
            var sub  = elSub ? Number(elSub.value) || 0 : 0;
            var only = elStock.checked;

            var html = '';
            var n    = 0;

            for (var i = 0; i < products.length; i++) {
                var p = products[i];

                if (cat > 0 && p.category_id !== cat) { continue; }

                /* เลือกหมวดย่อยได้โดยไม่ต้องเลือกหมวดหลักก่อน
                   หมวดย่อยหนึ่งอันอยู่ใต้หมวดหลักได้อันเดียว เงื่อนไขจึงไม่ขัดกันเอง */
                if (sub > 0 && p.sub_category_id !== sub) { continue; }

                if (only && p.qty <= 0) { continue; }

                /* ค้นชื่อหมวดหลักได้ด้วย เพราะตอนนี้มันขึ้นเป็นป้ายอยู่บนแถวแล้ว
                   สิ่งที่มองเห็นบนจอแล้วพิมพ์ตามไม่เจอ อ่านเหมือนระบบค้นหาพัง */
                if (q !== '' && norm(p.product_code + ' ' + p.product_name + ' '
                                     + p.category_name + ' '
                                     + p.sub_category_name).indexOf(norm(q)) === -1) { continue; }

                n++;
                var picked = !!chosen[p.product_id];

                var stockCls = 'tf-stock';
                if (p.qty <= 0)                               { stockCls += ' is-out'; }
                else if (p.min_qty > 0 && p.qty <= p.min_qty) { stockCls += ' is-low'; }

                html += '<button type="button" class="tf-item' + (picked ? ' is-picked' : '') + '"'
                     +  ' data-id="' + p.product_id + '">'
                     +  '<span class="tf-item-main">'
                     +    '<span class="tf-code">' + escapeHtml(p.product_code) + '</span>'
                     +    '<span class="tf-name">' + escapeHtml(p.product_name) + '</span>'
                     +    '<span class="tf-sub">' + catTags(p) + '</span>'
                     +  '</span>'
                     +  '<span class="tf-item-side">'
                     +    '<span class="' + stockCls + '">' + fmt(p.qty) + '</span>'
                     +    '<span class="tf-unit">' + escapeHtml(p.unit_name) + '</span>'
                     +  '</span>'
                     +  '<span class="tf-mark" aria-hidden="true">' + (picked ? '&#10003;' : '+') + '</span>'
                     +  '</button>';
            }

            /* จำตำแหน่งเลื่อนไว้ก่อนเขียนทับ แล้วคืนให้เหมือนเดิม
               กรณีเปลี่ยนตัวกรองอาจสั้นลงจนตำแหน่งเดิมเกินความยาว
               เบราว์เซอร์จะปัดลงมาให้เองอยู่แล้ว ไม่ต้องคำนวณเพิ่ม */
            var keepTop = elLeft.scrollTop;

            elLeft.innerHTML = (n === 0)
                ? '<div class="tf-empty">ไม่พบสินค้าตามเงื่อนไขที่ค้นหา</div>'
                : html;

            elLeft.scrollTop  = keepTop;
            setLeftCount(n);
        }


        /* ---------- ฝั่งขวา : รายการที่เลือก ---------- */
        function renderRight() {
            if (order.length === 0) {
                elRight.innerHTML = '<div class="tf-empty">'
                    + 'ยังไม่ได้เลือกสินค้า<br><span class="tf-empty-hint">'
                    + 'แตะรายการทางซ้ายเพื่อเพิ่มเข้ามา</span></div>';
                setPickCount(0);
                return;
            }

            var html = '';
            for (var i = 0; i < order.length; i++) {
                var id = order[i];
                var p  = byId[id];
                var c  = chosen[id];
                if (!p) { continue; }

                /* เตือนเมื่อขอเกินยอดคงเหลือ แต่ยังส่งได้
                   เพราะกว่าจะอนุมัติและจ่ายจริง ของอาจเข้ามาเพิ่มแล้ว */
                var over = (c.qty > p.qty);

                html += '<div class="tf-pick' + (over ? ' is-over' : '') + '" data-id="' + id + '">'
                     +  '<div class="tf-pick-head">'
                     +    '<span class="tf-code">' + escapeHtml(p.product_code) + '</span>'
                     +    '<button type="button" class="tf-remove" data-act="remove"'
                     +    ' aria-label="เอาออกจากรายการ">&#10005;</button>'
                     +  '</div>'
                     +  '<div class="tf-name">' + escapeHtml(p.product_name) + '</div>'
                     +  '<div class="tf-pick-foot">'
                     +    '<span class="tf-step">'
                     +      '<button type="button" data-act="minus" aria-label="ลดจำนวน">&minus;</button>'
                     +      '<input type="number" class="tf-qty num" min="0" step="1"'
                     +      ' value="' + c.qty + '" aria-label="จำนวนที่เบิก ' + escapeHtml(p.product_name) + '">'
                     +      '<button type="button" data-act="plus" aria-label="เพิ่มจำนวน">+</button>'
                     +    '</span>'
                     +    '<span class="tf-unit">' + escapeHtml(p.unit_name) + '</span>'
                     +    '<span class="tf-avail">คงเหลือ ' + fmt(p.qty) + '</span>'
                     +  '</div>'
                     +  (over ? '<div class="tf-warn">ขอเกินยอดคงเหลือ — ส่งได้ แต่ตอนจ่ายจริงอาจได้ไม่ครบ</div>' : '')
                     +  '</div>';
            }

            var keepTop = elRight.scrollTop;
            elRight.innerHTML = html;
            elRight.scrollTop = keepTop;

            setPickCount(order.length);
        }

        function render() {
            renderLeft();
            renderRight();
            onChange();
        }


        /* ---------- เพิ่ม / เอาออก / แก้จำนวน ----------
         * ห้ามวาดรายการฝั่งซ้ายใหม่ทั้งก้อนตอนแตะเลือก และห้ามเลื่อนจอไปไหน
         *
         * ของเดิมทำสองอย่างนี้แล้วบนมือถือใช้งานไม่ได้เลย:
         *   1. innerHTML ใหม่ทั้งฝั่ง -> ตำแหน่งเลื่อนของรายการเด้งกลับไปบนสุด
         *      คนที่ไล่หาสินค้าตัวที่ 80 แตะเลือกทีเดียวต้องเลื่อนใหม่หมด
         *   2. scrollIntoView ไปที่การ์ดฝั่งขวา -> จอกระโดดลงไปอีกที่หนึ่ง
         *      ทั้งที่คนยังเลือกของไม่เสร็จ
         *
         * เปลี่ยนเป็นแตะแล้วติ๊กถูกที่ปุ่มนั้นตัวเดียว จอไม่ขยับเลย
         * ตัวนับที่หัวฝั่งขวายังเดินให้เห็นว่าเลือกไปกี่รายการแล้ว
         */
        function add(id) {
            if (chosen[id]) { remove(id); return; }      // แตะซ้ำ = เอาออก

            chosen[id] = { product_id: id, qty: 1, note: '' };

            /* ตัวที่เพิ่งเลือกไปอยู่บนสุด ไม่ใช่ต่อท้าย
               เพราะสิ่งที่เพิ่งแตะคือสิ่งที่อยากเห็นและอยากแก้จำนวนต่อทันที
               ถ้าต่อท้าย พอเลือกไป 10 ตัว ตัวใหม่จะไปโผล่ใต้จอทุกครั้ง
               ลำดับนี้เป็นลำดับบรรทัดในเอกสารด้วย — สิ่งที่เห็นบนจอกับที่บันทึกจึงตรงกัน */
            order.unshift(id);

            paintLeftItem(id);
            renderRight();

            /* เลื่อนกล่องฝั่งขวาขึ้นบนสุดให้เห็นตัวที่เพิ่งเพิ่ม
               เลื่อนเฉพาะภายในกล่องนี้ ไม่ใช่ทั้งหน้า จอจึงไม่กระโดด */
            elRight.scrollTop = 0;

            onChange();
        }

        function remove(id) {
            delete chosen[id];
            for (var i = 0; i < order.length; i++) {
                if (order[i] === id) { order.splice(i, 1); break; }
            }

            paintLeftItem(id);
            renderRight();
            onChange();
        }

        /* อัปเดตหน้าตาของปุ่มสินค้าตัวเดียวในฝั่งซ้าย โดยไม่แตะตัวอื่น */
        function paintLeftItem(id) {
            var node = elLeft.querySelector('.tf-item[data-id="' + id + '"]');
            if (!node) { return; }

            var picked = !!chosen[id];
            node.classList.toggle('is-picked', picked);

            var mark = node.querySelector('.tf-mark');
            if (mark) { mark.innerHTML = picked ? '&#10003;' : '+'; }
        }

        function setQty(id, qty, keepInput) {
            if (!chosen[id]) { return; }

            qty = Number(qty);
            if (isNaN(qty) || qty < 0) { qty = 0; }

            /* กรอก 0 = ตั้งใจเอาออก ไม่ต้องให้ไปกดปุ่มกากบาทอีกที */
            if (qty === 0) { remove(id); return; }

            chosen[id].qty = qty;
            paintCard(id, keepInput);
            onChange();
        }

        /* ---------- อัปเดตเฉพาะการ์ดใบเดียว ----------
         * ห้ามวาดฝั่งขวาใหม่ทั้งหมดตอนแก้จำนวน เพราะปุ่ม + / − ที่ผู้ใช้กดค้างอยู่
         * จะถูกลบทิ้งแล้วสร้างใหม่ การกดรัว ๆ ครั้งที่สองเป็นต้นไปจะตกไปเฉย ๆ
         * (กดเพิ่มทีละ 1 ให้ครบ 5 คือพฤติกรรมปกติของหน้านี้)
         * วาดใหม่ทั้งฝั่งเฉพาะตอนเพิ่ม/เอาออก ซึ่งจำนวนการ์ดเปลี่ยนจริง
         */
        function paintCard(id, keepInput) {
            var card = elRight.querySelector('.tf-pick[data-id="' + id + '"]');
            if (!card) { renderRight(); return; }

            var p = byId[id];
            var c = chosen[id];
            if (!p || !c) { return; }

            /* ตอนผู้ใช้พิมพ์เอง ห้ามเขียนทับค่าในช่อง ไม่งั้นเคอร์เซอร์กระโดดไปท้าย */
            if (!keepInput) {
                var input = card.querySelector('.tf-qty');
                if (input) { input.value = c.qty; }
            }

            var over = (c.qty > p.qty);
            card.classList.toggle('is-over', over);

            var warn = card.querySelector('.tf-warn');
            if (over && !warn) {
                var n = document.createElement('div');
                n.className   = 'tf-warn';
                n.textContent = 'ขอเกินยอดคงเหลือ — ส่งได้ แต่ตอนจ่ายจริงอาจได้ไม่ครบ';
                card.appendChild(n);
            } else if (!over && warn) {
                warn.parentNode.removeChild(warn);
            }
        }


        /* ---------- ผูก event ---------- */
        elLeft.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.tf-item') : null;
            if (btn) { add(Number(btn.getAttribute('data-id'))); }
        });

        elRight.addEventListener('click', function (e) {
            var act = e.target.closest ? e.target.closest('[data-act]') : null;
            if (!act) { return; }

            var card = act.closest('.tf-pick');
            var id   = Number(card.getAttribute('data-id'));
            var kind = act.getAttribute('data-act');

            if (kind === 'remove') { remove(id); }
            else if (kind === 'plus')  { setQty(id, chosen[id].qty + 1); }
            else if (kind === 'minus') { setQty(id, chosen[id].qty - 1); }
        });

        /* พิมพ์ในช่องจำนวน อัปเดตยอดรวมทันทีแต่ไม่แตะค่าที่กำลังพิมพ์อยู่ */
        elRight.addEventListener('input', function (e) {
            if (!e.target.classList.contains('tf-qty')) { return; }
            var card = e.target.closest('.tf-pick');
            var v    = Number(e.target.value);

            /* ช่องว่างหรือ 0 ระหว่างพิมพ์ ยังไม่เอาการ์ดออก
               ผู้ใช้อาจกำลังลบเลขเก่าเพื่อพิมพ์เลขใหม่ รอจนออกจากช่องก่อน */
            if (e.target.value === '' || isNaN(v) || v <= 0) { return; }

            setQty(Number(card.getAttribute('data-id')), v, true);
        });

        elRight.addEventListener('change', function (e) {
            if (!e.target.classList.contains('tf-qty')) { return; }
            var card = e.target.closest('.tf-pick');
            setQty(Number(card.getAttribute('data-id')), e.target.value);
        });

        /* Enter ในช่องจำนวน = ยืนยันค่า ไม่ใช่ส่งฟอร์มทั้งใบ */
        elRight.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.classList.contains('tf-qty')) {
                e.preventDefault();
                e.target.blur();
            }
        });

        elSearch.addEventListener('input', renderLeft);

        /* เปลี่ยนหมวดหลัก = ต้องสร้างรายการหมวดย่อยใหม่ก่อนวาดรายการสินค้า
           ไม่งั้นจะกรองด้วยหมวดย่อยที่ไม่มีอยู่ในหมวดใหม่แล้ว */
        elCat.addEventListener('change', function () {
            fillSubCategories();
            renderLeft();
        });

        if (elSub) { elSub.addEventListener('change', renderLeft); }
        elStock.addEventListener('change', renderLeft);

        var clearBtn = el('tfClear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (order.length === 0) { return; }
                chosen = {};
                order  = [];
                render();
            });
        }

        fillCategories();
        fillSubCategories();
        render();


        /* ---------- หน้าตาเดียวกับที่ doc-form.js ใช้กับตาราง ---------- */
        return {
            getRows: function () {
                var out = [];
                for (var i = 0; i < order.length; i++) {
                    var c = chosen[order[i]];
                    if (c) { out.push({ product_id: c.product_id, qty: c.qty, note: c.note }); }
                }
                return out;
            },
            setRows: function (rows) {
                chosen = {};
                order  = [];
                for (var i = 0; i < rows.length; i++) {
                    chosen[rows[i].product_id] = {
                        product_id: rows[i].product_id,
                        qty:  rows[i].qty,
                        note: rows[i].note || ''
                    };
                    order.push(rows[i].product_id);
                }
                render();
            },
            clear: function () {
                chosen = {};
                order  = [];
                render();
            },
            setProducts: function (list) {
                products = list || [];
                byId = {};
                for (var i = 0; i < products.length; i++) { byId[products[i].product_id] = products[i]; }

                /* ตัวเลือกในช่องกรองสร้างมาจาก products จึงต้องสร้างใหม่ด้วย
                   ไม่งั้นจะยังเป็นหมวดหมู่ของชุดสินค้าก่อนหน้า และค่าที่เลือกค้างไว้
                   อาจกรองรายการใหม่ทิ้งจนหมดโดยไม่มีอะไรบอกว่าทำไม
                   (ทั้งสองฟังก์ชันเก็บค่าที่เลือกไว้ให้ถ้ายังใช้ได้กับชุดใหม่) */
                fillCategories();
                fillSubCategories();

                render();
            },

            /* ---------- เพิ่มจากการยิงบาร์โค้ด ----------
             * ใช้ add() ตรง ๆ ไม่ได้ เพราะ add() เป็นการ "สลับ" — แตะซ้ำแล้วเอาออก
             * ซึ่งถูกสำหรับการแตะด้วยนิ้ว แต่ผิดสำหรับการยิง
             * คนที่ยิงของชิ้นที่สองของสินค้าเดียวกัน หมายถึงอยากได้สองชิ้น
             * ไม่ได้หมายถึงอยากยกเลิกสิ่งที่เพิ่งยิงไป
             */
            scan: function (id) {
                if (chosen[id]) {
                    var next = Number(chosen[id].qty || 0) + 1;
                    chosen[id].qty = next;
                    renderRight();
                    onChange();
                    return { action: 'increment', qty: next };
                }
                add(id);
                return { action: 'added', qty: 1 };
            }
        };
    }

    window.DocTransfer = { create: create };
})();
