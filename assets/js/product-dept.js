/* ============================================================
 *  assets/js/product-dept.js — สินค้าที่แต่ละหน่วยงานเบิกได้
 *
 *  เลือกหน่วยงาน -> ติ๊กสินค้า -> กดบันทึก
 *
 *  ใช้โครงสองฝั่งเดียวกับหน้าทำรายการเบิก (doc-transfer.js) เพื่อให้ผู้ใช้
 *  ไม่ต้องเรียนรู้รูปแบบใหม่ แต่เขียนแยกเพราะงานคนละเรื่อง:
 *  ที่นั่นเลือก "ของกับจำนวน" ส่วนที่นี่เลือก "สิทธิ์" ไม่มีจำนวนเข้ามาเกี่ยวข้อง
 *
 *  ---------------------------------------------------------------
 *  กติกาที่หน้านี้ต้องสื่อให้ชัด
 *  ---------------------------------------------------------------
 *  สินค้าที่ไม่ได้ติ๊กให้หน่วยงานใดเลย = ของกลาง ทุกหน่วยงานเบิกได้
 *  การติ๊กคือการ "จำกัด" ไม่ใช่การ "อนุญาต"
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */

(function () {
    'use strict';

    var CFG = window.PRODUCTDEPT;
    if (!CFG) { return; }

    var products = [];      // สินค้าทั้งหมด
    var byId     = {};
    var picked   = {};      // product_id ที่ติ๊กไว้ตอนนี้
    var original = '';      // สภาพตอนโหลดมา ใช้เทียบว่าแก้แล้วหรือยัง
    var deptId   = 0;

    /* สินค้าตัวไหนถูกหน่วยงานใดจองไว้แล้วบ้าง (จากฐานข้อมูล ไม่รวมที่เพิ่งติ๊กในจอ)
       ใช้บอกว่ารายการที่กำลังจะติ๊กเป็น "ของกลาง" หรือ "ของที่ฝ่ายอื่นกันไว้แล้ว"
       ซึ่งเป็นคนละเรื่องกัน: ติ๊กของกลาง = ตัดสิทธิ์ทั้งบริษัทที่เคยเบิกได้ */
    var owners    = {};
    var deptNames  = {};
    var deptLabels = {};   // ป้ายเต็มพร้อมชื่อบริษัท ใช้ในข้อความตอนชี้ค้าง

    /* หมวดหมู่ย่อยทั้งชุด (แต่ละอันมี category_id ของหมวดหลักติดมาด้วย)
       เก็บไว้ทั้งก้อนเพราะช่อง "หมวดหมู่ย่อย" ต้องถูกสร้างใหม่ทุกครั้งที่
       เปลี่ยนหมวดหลัก ให้เหลือเฉพาะของหมวดนั้น */
    var subCats = [];

    function el(id) { return document.getElementById(id); }
    function setHidden(node, on) { if (node) { node.hidden = !!on; } }

    /* ตัวเลขจำนวนรายการมีสองที่: ป้ายในหัวของแต่ละฝั่ง กับตัวเลขบนแท็บของจอเล็ก
       ต้องเขียนพร้อมกันเสมอ เพราะบนจอเล็กหัวของฝั่งที่ไม่ได้เลือกถูกซ่อนอยู่
       ตัวเลขบนแท็บจึงเป็นทางเดียวที่จะรู้ว่าอีกฝั่งมีกี่รายการ */
    function setCount(headId, tabId, value) {
        var text = String(value);
        if (el(headId)) { el(headId).textContent = text; }
        if (el(tabId))  { el(tabId).textContent  = text; }
    }

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ตัดวรรณยุกต์และช่องว่างออกก่อนเทียบ คนพิมพ์ค้นหามักพิมพ์ไม่ตรงเป๊ะ */
    function norm(text) {
        return String(text || '').toLowerCase().replace(/\s+/g, '');
    }


    /* ---------- คุยกับ API ---------- */
    function post(payload, ok, bad) {
        payload.csrf_token = CFG.csrfToken;

        fetch(CFG.endpoint, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              if (!res || !res.ok) {
                  bad(res && res.message ? res.message : 'ดึงข้อมูลไม่สำเร็จ');
                  return;
              }
              ok(res);
          })['catch'](function () { bad('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); });
    }

    function fail(message) { if (window.appError) { window.appError(message); } }


    /* ---------- สภาพปัจจุบันเป็นข้อความ ใช้เทียบว่าแก้แล้วหรือยัง ----------
     * เรียงเลขก่อนต่อเป็นข้อความ ไม่งั้นการติ๊กแล้วเอาออกแล้วติ๊กใหม่
     * จะได้ลำดับต่างจากเดิมทั้งที่ผลลัพธ์เหมือนกัน แล้วปุ่มบันทึกจะค้างเปิดอยู่ */
    function stateOf() {
        return ids().sort(function (a, b) { return a - b; }).join(',');
    }

    function ids() {
        var out = [];
        var k;
        for (k in picked) { if (picked.hasOwnProperty(k)) { out.push(Number(k)); } }
        return out;
    }

    function refreshDirty() {
        var dirty = (stateOf() !== original);

        if (el('pdSave'))  { el('pdSave').disabled  = !dirty; }
        if (el('pdReset')) { el('pdReset').disabled = !dirty; }
    }


    /* ---------- หน่วยงานอื่นที่ใช้สินค้าตัวนี้อยู่ ----------
     * คืนรายชื่อหน่วยงานอื่น (ไม่รวมหน่วยงานที่กำลังตั้งค่าอยู่)
     * อ่านจากข้อมูลที่โหลดมาตอนแรก ไม่ใช่จากสิ่งที่เพิ่งติ๊กในจอ
     * เพราะสิ่งที่ติ๊กในจอยังไม่ได้บันทึก และเป็นของหน่วยงานนี้อยู่แล้ว
     *
     * สินค้าหนึ่งตัวอยู่ได้หลายหน่วยงานพร้อมกัน การมีรายชื่อในนี้จึงไม่ได้แปลว่า
     * "ตัวนี้ถูกจองไปแล้ว เพิ่มไม่ได้" — ติ๊กเพิ่มได้เสมอ แล้วจะกลายเป็นใช้ร่วมกัน
     */
    function otherOwners(productId) {
        var list = owners[productId];
        if (!list || list.length === 0) { return []; }

        var out = [];
        for (var i = 0; i < list.length; i++) {
            if (Number(list[i]) !== deptId) { out.push(list[i]); }
        }
        return out;
    }

    function ownerLabel(ids, full) {
        var src   = full ? deptLabels : deptNames;
        var names = [];
        for (var i = 0; i < ids.length && i < 2; i++) {
            names.push(src[ids[i]] || deptNames[ids[i]] || ('หน่วยงาน ' + ids[i]));
        }
        if (ids.length > 2) { names.push('อีก ' + (ids.length - 2) + ' หน่วยงาน'); }
        return names.join(', ');
    }

    /* ---------- ป้ายบอกว่าใครใช้สินค้าตัวนี้ร่วมกันบ้าง ----------
     * แสดงรายชื่อ "หน่วยงานอื่น" เสมอ ไม่ว่าหน่วยงานที่กำลังตั้งค่าจะติ๊กหรือไม่
     * เพราะสิ่งที่ผู้ตั้งค่าต้องรู้คือ "ถ้าติ๊กแล้วจะไปใช้ร่วมกับใคร"
     *
     * ข้อความต่างกันตามว่าติ๊กอยู่หรือยัง:
     *   ติ๊กแล้ว    -> "ใช้ร่วมกับ ก, ข"      (สภาพปัจจุบัน)
     *   ยังไม่ติ๊ก  -> "ตอนนี้เป็นของ ก, ข"    (สภาพก่อนติ๊ก)
     * ทั้งสองแบบไม่ใช้คำว่า "จอง" หรือไอคอนกุญแจ เพราะสองอย่างนั้นสื่อว่า
     * ปิดกั้นและเพิ่มไม่ได้ ซึ่งตรงข้ามกับความจริง
     */
    function shareTag(productId, isPicked) {
        var others = otherOwners(productId);
        if (others.length === 0) { return ''; }

        /* ป้ายในรายการใช้ชื่อสั้น เพราะที่มีจำกัดและต้องกวาดตาได้เร็ว
           ส่วนข้อความตอนชี้ค้างใช้ป้ายเต็มที่มีชื่อบริษัท เพราะ 11 ชื่อจาก 62
           หน่วยงานซ้ำกัน ป้ายที่เขียนว่า "ใช้ร่วมกับ จัดซื้อ" จึงตอบไม่ได้ว่าบริษัทไหน */
        var label = ownerLabel(others);
        var full  = ownerLabel(others, true);
        var word  = isPicked ? 'ใช้ร่วมกับ ' : 'ตอนนี้เป็นของ ';

        return '<span class="pd-owner' + (isPicked ? ' is-shared' : '') + '"'
             + ' title="' + escapeHtml(word + full)
             + (isPicked ? ' — ติ๊กไว้แล้ว จึงเบิกได้ทั้งหมด ' + (others.length + 1) + ' หน่วยงาน'
                         : ' — ติ๊กเพิ่มได้ จะกลายเป็นใช้ร่วมกัน') + '">'
             + '<i data-lucide="users" class="h-3 w-3"></i>'
             + escapeHtml(word + label) + '</span>';
    }


    /* ---------- ป้ายหมวดหมู่ของสินค้าแต่ละตัว ----------
     * ของเดิมบรรทัดล่างมีแค่ชื่อหมวดย่อยเป็นตัวจาง ๆ ซึ่งตอบไม่ได้ว่า
     * หมวดย่อยนั้นอยู่ใต้หมวดหลักไหน ทั้งที่ชื่อหมวดย่อยซ้ำกันข้ามหมวดได้
     * ("รายการหมึก" มีอยู่ทั้งสองหมวดหลัก) พอเปิดตัวกรองหมวดหมู่ย่อยแล้ว
     * คำถามนี้ยิ่งเกิดบ่อย เพราะช่องกรองแสดงชื่อหมวดแม่กำกับไว้ แต่ตัวรายการไม่มี
     *
     * แยกเป็นสองป้ายคนละสีแทนการต่อเป็นข้อความเดียว เพราะสองชั้นนี้ใช้คนละ
     * ตัวกรอง การกวาดตาหาว่า "แถวนี้อยู่หมวดไหน" จึงต้องแยกออกจากกันได้ทันที
     * โดยไม่ต้องอ่านตัวคั่น
     */
    function catTags(p) {
        var out = '';

        /* ใส่ title ไว้ทุกป้าย เพราะ CSS ตัดชื่อยาวด้วย … เมื่อ pane แคบ
           ชื่อเต็มจึงต้องยังอ่านได้จากการชี้ค้าง ไม่ใช่หายไปเฉย ๆ */
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


    /* ---------- เติมรายการในช่อง "หมวดหมู่ย่อย" ----------
     * เรียกทุกครั้งที่หมวดหลักเปลี่ยน เพื่อตัดรายการให้เหลือเฉพาะหมวดย่อย
     * ที่อยู่ใต้หมวดหลักนั้น ไม่งั้นผู้ใช้จะเลือกคู่ที่ไม่มีสินค้าตรงเงื่อนไขได้
     * (เช่น หมวดหลัก "วัสดุสิ้นเปลือง" + หมวดย่อย "อุปกรณ์เครื่องเขียน"
     *  ซึ่งอยู่ใต้หมวดหลักอีกอัน) แล้วเจอรายการว่างโดยไม่รู้ว่าทำอะไรผิด
     *
     * ค่าที่เลือกไว้เดิมจะถูกเก็บไว้ถ้ายังอยู่ในรายการชุดใหม่ เพราะการเปลี่ยน
     * หมวดหลักไปเป็นหมวดแม่ของหมวดย่อยที่เลือกไว้อยู่แล้ว ไม่ควรล้างค่านั้นทิ้ง
     */
    function fillSub() {
        var box = el('pdSubCategory');
        if (!box) { return; }

        var cat  = el('pdCategory') ? Number(el('pdCategory').value) || 0 : 0;
        var keep = Number(box.value) || 0;
        var stillThere = false;

        box.innerHTML = '';

        var head = document.createElement('option');
        head.value       = '';
        head.textContent = 'ทุกหมวดหมู่ย่อย';
        box.appendChild(head);

        for (var i = 0; i < subCats.length; i++) {
            var s = subCats[i];
            if (cat > 0 && Number(s.category_id) !== cat) { continue; }

            var op = document.createElement('option');
            op.value       = s.sub_category_id;
            op.textContent = s.sub_category_name;

            /* ยังไม่เลือกหมวดหลัก = เห็นหมวดย่อยครบทุกอัน ซึ่งมีชื่อซ้ำกันข้ามหมวดได้
               ("รายการหมึก" มีอยู่ทั้งสองหมวดหลัก) ถ้าไม่บอกหมวดแม่ จะขึ้นสองบรรทัด
               ที่เขียนเหมือนกันเป๊ะ เลือกไปแล้วไม่รู้ว่าได้อันไหน
               พอเลือกหมวดหลักแล้วบรรทัดนั้นซ้ำกันทุกแถว จึงไม่ต้องใส่ */
            if (cat <= 0 && s.category_name) { op.setAttribute('data-sub', s.category_name); }

            box.appendChild(op);
            if (Number(s.sub_category_id) === keep) { stillThere = true; }
        }

        box.value = stillThere ? String(keep) : '';

        if (window.SelectSearch) { window.SelectSearch.refresh(box); }
    }


    /* ---------- ฝั่งซ้าย : สินค้าทั้งหมด ---------- */
    function renderLeft() {
        var box = el('pdLeft');
        if (!box) { return; }

        var q     = norm(el('pdSearch') ? el('pdSearch').value : '');
        var cat   = el('pdCategory') ? Number(el('pdCategory').value) || 0 : 0;
        var sub   = el('pdSubCategory') ? Number(el('pdSubCategory').value) || 0 : 0;
        var state = el('pdState') ? el('pdState').value : '';

        var html = '';
        var n    = 0;

        for (var i = 0; i < products.length; i++) {
            var p  = products[i];
            var on = !!picked[p.product_id];

            if (cat > 0 && p.category_id !== cat) { continue; }

            /* เลือกหมวดย่อยได้โดยไม่ต้องเลือกหมวดหลักก่อน — หมวดย่อยหนึ่งอัน
               อยู่ใต้หมวดหลักได้อันเดียวอยู่แล้ว เงื่อนไขจึงไม่ขัดกันเอง */
            if (sub > 0 && p.sub_category_id !== sub) { continue; }

            /* ค้นชื่อหมวดหลักได้ด้วย เพราะตอนนี้มันขึ้นเป็นป้ายอยู่บนแถวแล้ว
               สิ่งที่มองเห็นบนจอแล้วพิมพ์ตามไม่เจอ อ่านเหมือนระบบค้นหาพัง */
            if (q !== '' && norm(p.product_code + ' ' + p.product_name + ' '
                                 + p.category_name + ' '
                                 + p.sub_category_name).indexOf(q) === -1) { continue; }

            var others = otherOwners(p.product_id);
            var isCommon = (others.length === 0 && !on
                            && (!owners[p.product_id] || owners[p.product_id].length === 0));

            if (state === 'mine'   && !on)               { continue; }
            if (state === 'other'  && others.length === 0) { continue; }
            if (state === 'common' && !isCommon)         { continue; }

            n++;

            /* ป้ายบอกว่าใครใช้ตัวนี้อยู่ — ข้อมูลที่เปลี่ยนการตัดสินใจ
               ติ๊กของกลาง = ตัดสิทธิ์ทั้งบริษัท / ติ๊กของที่ฝ่ายอื่นใช้อยู่ = ใช้ร่วมกัน */
            var tag = shareTag(p.product_id, on);

            html += '<button type="button" class="tf-item' + (on ? ' is-picked' : '') + '"'
                 +  ' data-id="' + p.product_id + '">'
                 +  '<span class="tf-item-main">'
                 +    '<span class="tf-code">' + escapeHtml(p.product_code) + '</span>'
                 +    '<span class="tf-name">' + escapeHtml(p.product_name) + '</span>'
                 +    '<span class="tf-sub">' + catTags(p) + tag + '</span>'
                 +  '</span>'
                 +  '<span class="tf-mark" aria-hidden="true">' + (on ? '&#10003;' : '+') + '</span>'
                 +  '</button>';
        }

        /* จำตำแหน่งเลื่อนไว้ก่อนเขียนทับ — คนที่ไล่ติ๊กของตัวที่ 80
           ไม่ควรถูกดีดกลับไปบนสุดทุกครั้งที่เปลี่ยนตัวกรอง */
        var keep = box.scrollTop;

        box.innerHTML = (n === 0)
            ? '<div class="tf-empty">ไม่พบสินค้าตามเงื่อนไขที่ค้นหา</div>'
            : html;

        box.scrollTop = keep;

        setCount('pdLeftCount', 'pdTabSrc', n);

        /* ไอคอนกุญแจในป้าย "ฝ่ายอื่นจองไว้" ต้องถูกแปลงเป็น svg ทุกครั้งที่วาดใหม่ */
        if (window.renderIcons) { window.renderIcons(); }
    }


    /* ---------- ตัวเลขสรุป ----------
     * แปลงกติกาที่เป็นนามธรรมให้เป็นตัวเลขที่ดูแล้วเข้าใจทันที
     * "เบิกได้ 313 = ของกลาง 310 + เฉพาะฝ่ายนี้ 3" อธิบายกติกา
     * "ไม่ติ๊ก = ของกลาง" ได้ดีกว่าคำอธิบายสามบรรทัด
     *
     * คิดจากสิ่งที่เห็นบนจอตอนนี้ (รวมที่เพิ่งติ๊กแต่ยังไม่บันทึก)
     * ตัวเลขจึงขยับทันทีที่กด ทำให้เห็นผลของการกดก่อนตัดสินใจบันทึก
     */
    function refreshStats() {
        var box = el('pdStats');
        if (!box) { return; }

        /* ระหว่างสาธิตในคู่มือ deptId ถูกตั้งเป็น 0 ไว้เป็นด่านกัน save()
           แต่ตัวเลขสรุปยังต้องคำนวณให้เห็น ไม่งั้นชิปจะขึ้น 0 ทุกช่อง
           ทั้งที่บนจอมีของสาธิตอยู่ 5 รายการ ซึ่งขัดกับสิ่งที่ผู้ใช้เห็นตรงหน้า */
        if (deptId <= 0 && !demoBackup) { setHidden(box, true); return; }

        var own = 0, common = 0;

        for (var i = 0; i < products.length; i++) {
            var id = products[i].product_id;

            if (picked[id]) { own++; continue; }

            /* ไม่ได้ติ๊กไว้ และไม่มีฝ่ายไหนจองเลย = ของกลาง หน่วยงานนี้ก็เบิกได้
               ถ้ามีฝ่ายอื่นจองไว้ หน่วยงานนี้เบิกไม่ได้ จึงไม่นับเข้าช่องไหนเลย */
            var list = owners[id] || [];
            if (list.length === 0) { common++; }
        }

        el('pdStatTotal').textContent  = String(own + common);
        el('pdStatCommon').textContent = String(common);
        el('pdStatOwn').textContent    = String(own);

        setHidden(box, false);
    }


    /* ---------- ฝั่งขวา : ที่ติ๊กไว้ ---------- */
    function renderRight() {
        var box = el('pdRight');
        if (!box) { return; }

        var list = ids();

        if (list.length === 0) {
            box.innerHTML = '<div class="tf-empty">ยังไม่ได้เลือกสินค้า<br>'
                          + '<span class="tf-empty-hint">หน่วยงานนี้จะเห็นเฉพาะสินค้าที่เป็นของกลาง</span></div>';
            setCount('pdCount', 'pdTabDst', 0);
            if (el('pdSearchRight')) { el('pdSearchRight').disabled = true; }
            return;
        }

        if (el('pdSearchRight')) { el('pdSearchRight').disabled = false; }

        /* เรียงตามรหัสสินค้า ไม่ใช่ตามลำดับที่กด
           เพราะรายการนี้คือ "ผลลัพธ์ที่จะบันทึก" ซึ่งต้องอ่านทานง่าย
           ต่างจากหน้าทำใบเบิกที่เรียงตัวใหม่ไว้บนสุดเพื่อให้เห็นสิ่งที่เพิ่งกด */
        var qr = norm(el('pdSearchRight') ? el('pdSearchRight').value : '');

        var rows = [];
        for (var i = 0; i < list.length; i++) {
            var p = byId[list[i]];
            if (!p) { continue; }

            if (qr !== '' && norm(p.product_code + ' ' + p.product_name).indexOf(qr) === -1) {
                continue;
            }
            rows.push(p);
        }
        rows.sort(function (a, b) {
            return String(a.product_code).localeCompare(String(b.product_code));
        });

        var html = '';
        for (var j = 0; j < rows.length; j++) {
            /* ฝั่งขวาก็ต้องบอกว่าตัวไหนใช้ร่วมกับฝ่ายอื่นอยู่
               คนที่กำลังจะเอาออก ควรรู้ว่าเอาออกแล้วกระทบใครบ้าง — หรือไม่กระทบใครเลย */
            html += '<button type="button" class="tf-item is-picked" data-id="' + rows[j].product_id + '">'
                 +  '<span class="tf-item-main">'
                 +    '<span class="tf-code">' + escapeHtml(rows[j].product_code) + '</span>'
                 +    '<span class="tf-name">' + escapeHtml(rows[j].product_name) + '</span>'
                 +    '<span class="tf-sub">' + catTags(rows[j])
                 +      shareTag(rows[j].product_id, true) + '</span>'
                 +  '</span>'
                 +  '<span class="tf-mark" aria-hidden="true">&#10005;</span>'
                 +  '</button>';
        }

        var keep = box.scrollTop;

        box.innerHTML = (rows.length === 0)
            ? '<div class="tf-empty">ไม่พบในรายการที่เลือกไว้</div>'
            : html;

        box.scrollTop = keep;

        if (window.renderIcons) { window.renderIcons(); }

        /* ตัวเลขบนหัวคือจำนวนที่ผูกไว้ทั้งหมด ไม่ใช่จำนวนที่ผ่านคำค้น
           เพราะตัวเลขนี้คือ "สิ่งที่จะบันทึก" ซึ่งต้องไม่เปลี่ยนตามการค้นหา */
        setCount('pdCount', 'pdTabDst', list.length);
    }


    /* ---------- ติ๊ก / เอาออก ----------
     * ทาสีเฉพาะปุ่มตัวที่กด ไม่วาดฝั่งซ้ายใหม่ทั้งก้อน
     * ฝั่งซ้ายมี 313 ปุ่ม การวาดใหม่ทุกครั้งที่กดทำให้กดรัวแล้วตกหล่น
     * และดีดตำแหน่งเลื่อนกลับไปบนสุด (บทเรียนจากหน้าทำรายการเบิก)
     */
    function toggle(id) {
        if (picked[id]) { delete picked[id]; }
        else            { picked[id] = true; }

        paintLeftItem(id);
        renderRight();
        refreshStats();
        refreshDirty();
    }

    function paintLeftItem(id) {
        var node = el('pdLeft').querySelector('.tf-item[data-id="' + id + '"]');
        if (!node) { return; }

        var on = !!picked[id];
        node.classList.toggle('is-picked', on);

        var mark = node.querySelector('.tf-mark');
        if (mark) { mark.innerHTML = on ? '&#10003;' : '+'; }

        /* ป้ายต้องพลิกข้อความทันทีที่ติ๊ก จาก "ตอนนี้เป็นของ ก" เป็น "ใช้ร่วมกับ ก"
           เพราะนี่คือวินาทีที่ผู้ตั้งค่าต้องเห็นว่ากำลังจะทำให้ใช้ร่วมกัน ไม่ใช่แย่งมา
           ถ้ารอให้เห็นตอนกดบันทึก ก็สายไปแล้วสำหรับการตัดสินใจ */
        var sub = node.querySelector('.tf-sub');
        if (!sub) { return; }

        var old = sub.querySelector('.pd-owner');
        if (old) { old.parentNode.removeChild(old); }

        var tag = shareTag(id, on);
        if (tag !== '') {
            sub.insertAdjacentHTML('beforeend', tag);
            if (window.renderIcons) { window.renderIcons(); }
        }
    }


    /* ---------- โหลดสิ่งที่หน่วยงานนี้ถูกผูกไว้ ---------- */
    function loadDept(id) {
        deptId = id;

        if (id <= 0) {
            setHidden(el('pdBody'), true);
            setHidden(el('pdEmpty'), false);
            picked   = {};
            original = '';
            refreshDirty();
            return;
        }

        post({ action: 'get', department_id: id }, function (res) {
            picked = {};
            for (var i = 0; i < res.productIds.length; i++) {
                picked[res.productIds[i]] = true;
            }
            original = stateOf();

            setHidden(el('pdEmpty'), true);
            setHidden(el('pdBody'), false);

            renderLeft();
            renderRight();
            refreshStats();
            refreshDirty();
        }, fail);
    }


    /* ---------- ปรับตารางเจ้าของให้ตรงกับที่เพิ่งบันทึก ----------
     * เอา deptId ออกจากทุกแถวก่อน แล้วใส่กลับเฉพาะรายการที่บันทึกไป
     * ผลเท่ากับโหลดข้อมูลใหม่จากเซิร์ฟเวอร์ แต่ไม่ต้องยิงคำขออีกรอบ
     */
    function syncOwners(list) {
        var k;
        for (k in owners) {
            if (!owners.hasOwnProperty(k)) { continue; }

            var keep = [];
            for (var i = 0; i < owners[k].length; i++) {
                if (Number(owners[k][i]) !== deptId) { keep.push(owners[k][i]); }
            }
            if (keep.length === 0) { delete owners[k]; }
            else                   { owners[k] = keep; }
        }

        for (var j = 0; j < list.length; j++) {
            var id = list[j];
            if (!owners[id]) { owners[id] = []; }
            owners[id].push(deptId);
        }
    }


    function save() {
        if (deptId <= 0) { return; }

        var list = ids();
        var btn  = el('pdSave');
        if (btn) { btn.disabled = true; }

        post({ action: 'save', department_id: deptId, product_ids: list }, function (res) {
            original = stateOf();

            /* อัปเดตตารางเจ้าของให้ตรงกับที่เพิ่งบันทึกลงฐานข้อมูล
               ไม่งั้นป้าย "ฝ่ายอื่นจองไว้" กับตัวเลขสรุปจะยังอ้างสภาพก่อนกดบันทึก
               ซึ่งทำให้คนที่ตั้งค่าต่อทันทีตัดสินใจจากข้อมูลเก่า */
            syncOwners(list);
            renderLeft();
            refreshStats();
            refreshDirty();

            /* อัปเดตตัวเลขข้างชื่อหน่วยงานในช่องเลือกทันที
               ไม่ต้องโหลดหน้าใหม่เพื่อให้เห็นว่าหน่วยงานนี้ตั้งค่าแล้ว */
            var sel = el('pdDept');
            if (sel && sel.selectedOptions && sel.selectedOptions[0]) {
                var op = sel.selectedOptions[0];
                op.textContent = op.getAttribute('data-name')
                               + (list.length > 0 ? ' (' + list.length + ' รายการ)' : '');
                if (window.SelectSearch) { window.SelectSearch.refresh(sel); }
            }

            if (window.appSuccess) { window.appSuccess(res.message); }
        }, function (message) {
            if (btn) { btn.disabled = false; }
            fail(message);
        });
    }


    /* ---------- เลือกทั้งหมดที่เห็นอยู่ ----------
     * "ที่เห็นอยู่" ไม่ใช่ "ทั้งหมด" — ผูกกับตัวกรองที่เปิดอยู่ตอนนั้น
     * ใช้ตอนอยากให้ทั้งหมวดหมู่เป็นของฝ่ายเดียว ซึ่งเป็นวิธีที่คนใช้จริง
     * ถ้าเลือกทั้ง 313 รายการรวดเดียว จะเท่ากับไม่ได้จำกัดอะไรเลย
     */
    function pickVisible() {
        var nodes = el('pdLeft').querySelectorAll('.tf-item');
        if (nodes.length === 0) { return; }

        for (var i = 0; i < nodes.length; i++) {
            picked[Number(nodes[i].getAttribute('data-id'))] = true;
        }

        renderLeft();
        renderRight();
        refreshStats();
        refreshDirty();
    }


    function clearAll() {
        if (ids().length === 0) { return; }

        picked = {};
        renderLeft();
        renderRight();
        refreshStats();
        refreshDirty();
    }


    /* ---------- เริ่มทำงาน ---------- */
    function init() {
        post({ action: 'bootstrap' }, function (res) {

            products  = res.products || [];
            owners    = res.map || {};
            deptNames  = res.deptNames  || {};
            deptLabels = res.deptLabels || {};
            subCats    = res.subCategories || [];

            byId = {};
            for (var i = 0; i < products.length; i++) {
                byId[products[i].product_id] = products[i];
            }

            var sel = el('pdDept');
            for (var d = 0; d < res.departments.length; d++) {
                var dep = res.departments[d];
                var op  = document.createElement('option');

                op.value = dep.department_id;
                op.setAttribute('data-name', dep.department_name);

                /* ติดจำนวนที่ผูกไว้ข้างชื่อ จะได้เห็นทันทีว่าหน่วยงานไหนตั้งค่าแล้ว
                   โดยไม่ต้องกดเข้าไปดูทีละหน่วยงาน (มี 62 หน่วยงาน) */
                op.textContent = dep.department_name
                               + (dep.picked > 0 ? ' (' + dep.picked + ' รายการ)' : '');

                /* ชื่อบริษัทเป็นบรรทัดรอง ไม่ใช่ของประดับ — ใน 62 หน่วยงานมี 11 ชื่อ
                   ที่ซ้ำกันข้ามบริษัท เช่น "จัดซื้อ" มีสองอัน ถ้าไม่มีบรรทัดนี้
                   รายการจะขึ้นสองบรรทัดที่เขียนเหมือนกันเป๊ะ เลือกไปแล้วไม่รู้ว่าได้อันไหน
                   (label_sub มาจาก department_add_labels() ซึ่งเติมเลขหน่วยงานให้ด้วย
                    เฉพาะคู่ที่ชื่อซ้ำกันในบริษัทเดียวกัน) */
                if (dep.label_sub) { op.setAttribute('data-sub', dep.label_sub); }
                sel.appendChild(op);
            }

            var cat = el('pdCategory');
            for (var c = 0; c < res.categories.length; c++) {
                var o = document.createElement('option');
                o.value       = res.categories[c].category_id;
                o.textContent = res.categories[c].category_name;
                cat.appendChild(o);
            }

            if (window.SelectSearch) {
                window.SelectSearch.refresh(sel);
                window.SelectSearch.refresh(cat);
            }

            /* ยังไม่เลือกหมวดหลัก จึงได้หมวดย่อยครบทุกอัน
               (fillSub เรียก SelectSearch.refresh ให้เองอยู่แล้ว) */
            fillSub();

            sel.addEventListener('change', function () {
                var next = Number(this.value) || 0;

                /* แก้ค้างไว้แล้วเปลี่ยนหน่วยงาน = งานที่ติ๊กมาหายทั้งหมด ต้องเตือนก่อน */
                if (stateOf() !== original && typeof window.appConfirm === 'function') {
                    var back = deptId;
                    var self = this;

                    window.appConfirm({
                        title: 'ยังไม่ได้บันทึก',
                        text:  'เปลี่ยนหน่วยงานตอนนี้ สิ่งที่ติ๊กไว้จะหายไป',
                        confirmText: 'เปลี่ยนโดยไม่บันทึก'
                    }).then(function (yes) {
                        if (yes) { loadDept(next); }
                        else     { self.value = String(back);
                                   if (window.SelectSearch) { window.SelectSearch.refresh(self); } }
                    });
                    return;
                }
                loadDept(next);
            });

            el('pdSearch').addEventListener('input', renderLeft);

            /* เปลี่ยนหมวดหลัก = ต้องเติมรายการหมวดย่อยใหม่ก่อนวาดรายการสินค้า
               ไม่งั้นจะกรองด้วยหมวดย่อยที่ไม่มีอยู่ในหมวดใหม่แล้ว */
            el('pdCategory').addEventListener('change', function () {
                fillSub();
                renderLeft();
            });

            el('pdSubCategory').addEventListener('change', renderLeft);
            el('pdState').addEventListener('change', renderLeft);
            el('pdSearchRight').addEventListener('input', renderRight);

            el('pdLeft').addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.tf-item') : null;
                if (b) { toggle(Number(b.getAttribute('data-id'))); }
            });
            el('pdRight').addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.tf-item') : null;
                if (b) { toggle(Number(b.getAttribute('data-id'))); }
            });

            el('pdAll').addEventListener('click', pickVisible);
            el('pdClear').addEventListener('click', clearAll);
            el('pdSave').addEventListener('click', save);
            el('pdReset').addEventListener('click', function () { loadDept(deptId); });

            /* ปิดหน้าไปตอนที่ยังไม่บันทึก = งานที่ติ๊กมาหายทั้งหมด */
            window.addEventListener('beforeunload', function (e) {
                if (stateOf() !== original) {
                    e.preventDefault();
                    e.returnValue = '';
                    return '';
                }
            });

            if (window.renderIcons) { window.renderIcons(); }
        }, fail);
    }

    /* ============================================================
     *  ชุดข้อมูลสาธิตสำหรับคู่มือการใช้งาน
     * ============================================================
     *  คู่มือหน้านี้อธิบายว่า "ฝั่งซ้ายคือสินค้าทั้งหมด ฝั่งขวาคือผลลัพธ์"
     *  แต่คนที่กดเปิดคู่มือส่วนใหญ่ยังไม่ได้เลือกหน่วยงาน หน้าจอจึงว่างเปล่า
     *  คำอธิบายเลยชี้ไปที่ที่ไม่มีอะไรให้ดู และผู้ใช้ต้องจินตนาการเอาเอง
     *
     *  ตรงนี้ใส่ของสมมติลงไปชั่วคราวระหว่างเปิดคู่มือ แล้วคืนสภาพเดิมตอนปิด
     *  (ตัวเรียกอยู่ที่ assets/js/app-guide.js — ดู window.GuideDemo)
     *
     *  ---------------------------------------------------------------
     *  ของสมมติต้องไปถึงเซิร์ฟเวอร์ไม่ได้เด็ดขาด
     *  ---------------------------------------------------------------
     *  ระหว่างสาธิตจึงปิดปุ่มบันทึกกับปุ่มยกเลิกไว้ และตั้ง deptId เป็น 0
     *  ต่อให้มีอะไรหลุดไปเรียก save() ก็จะถูกด่านแรกของ save() ปฏิเสธ
     *
     *  มีแถบสีกำกับไว้ชัด ๆ ว่าเป็นตัวอย่าง ไม่งั้นคนที่เห็นตัวเลข
     *  "3 รายการ" จะเข้าใจว่าเป็นข้อมูลจริงของหน่วยงานตัวเอง
     * ============================================================ */
    var demoBackup = null;

    var DEMO_PRODUCTS = [
        { product_id: -1, product_code: 'EX-PLB-001', product_name: 'เสื้อฟอร์ม ผู้ชาย PRO (ดำ) ไซซ์ M',
          category_id: -1, category_name: 'ตัวอย่าง', sub_category_id: -11, sub_category_name: 'เสื้อฟอร์ม', qty: 120, unit_name: 'ตัว' },
        { product_id: -2, product_code: 'EX-PLB-002', product_name: 'เสื้อฟอร์ม ผู้ชาย PRO (ดำ) ไซซ์ L',
          category_id: -1, category_name: 'ตัวอย่าง', sub_category_id: -11, sub_category_name: 'เสื้อฟอร์ม', qty: 60, unit_name: 'ตัว' },
        { product_id: -3, product_code: 'EX-TSH-001', product_name: 'เสื้อช่างเทคนิค ไซซ์ XL',
          category_id: -1, category_name: 'ตัวอย่าง', sub_category_id: -12, sub_category_name: 'ชุดช่างเทคนิค', qty: 24, unit_name: 'ตัว' },
        { product_id: -4, product_code: 'EX-TPT-001', product_name: 'กางเกงช่างเทคนิค เอว 34',
          category_id: -1, category_name: 'ตัวอย่าง', sub_category_id: -12, sub_category_name: 'ชุดช่างเทคนิค', qty: 300, unit_name: 'ตัว' },
        { product_id: -5, product_code: 'EX-SHO-001', product_name: 'รองเท้าเซฟตี้ เบอร์ 41',
          category_id: -1, category_name: 'ตัวอย่าง', sub_category_id: -13, sub_category_name: 'รองเท้าเซฟตี้', qty: 45, unit_name: 'คู่' }
    ];

    /* หมวดหมู่ย่อยของชุดสาธิต — ต้องสลับเข้าไปแทนของจริงด้วย ไม่งั้นช่อง
       "หมวดหมู่ย่อย" จะยังเป็นรายการของจริงซึ่งไม่ตรงกับสินค้าตัวอย่างบนจอสักอัน
       คนที่ลองกดตามคู่มือจะเจอรายการว่างทันที */
    var DEMO_SUBS = [
        { sub_category_id: -11, category_id: -1, sub_category_name: 'เสื้อฟอร์ม',        category_name: 'ตัวอย่าง' },
        { sub_category_id: -12, category_id: -1, sub_category_name: 'ชุดช่างเทคนิค', category_name: 'ตัวอย่าง' },
        { sub_category_id: -13, category_id: -1, sub_category_name: 'รองเท้าเซฟตี้',            category_name: 'ตัวอย่าง' }
    ];

    function demoSetup() {
        if (demoBackup) { return true; }        // เปิดคู่มือซ้อนกัน ไม่ต้องทำใหม่

        demoBackup = {
            products: products, byId: byId, picked: picked,
            original: original, deptId: deptId,
            owners: owners, deptNames: deptNames, deptLabels: deptLabels,
            subCats: subCats,
            bodyHidden:  el('pdBody')  ? el('pdBody').hidden  : true,
            emptyHidden: el('pdEmpty') ? el('pdEmpty').hidden : false,
            search:   el('pdSearch')   ? el('pdSearch').value   : '',
            category: el('pdCategory') ? el('pdCategory').value : '',
            sub:      el('pdSubCategory') ? el('pdSubCategory').value : '',
            state:    el('pdState')    ? el('pdState').value    : ''
        };

        products = DEMO_PRODUCTS;
        subCats  = DEMO_SUBS;
        byId = {};
        for (var i = 0; i < products.length; i++) { byId[products[i].product_id] = products[i]; }

        /* ติ๊กไว้ให้สองรายการ จะได้เห็นทั้งสองฝั่งพร้อมกัน
           ฝั่งขวาที่ว่างเปล่าอธิบายคำว่า "ผลลัพธ์ที่จะบันทึก" ไม่ได้ */
        picked = {};
        picked[-1] = true;
        picked[-3] = true;

        /* ให้กระดาษ A4 ดูเหมือนถูกฝ่ายอื่นใช้อยู่ด้วย เพื่อให้ป้าย "ใช้ร่วมกับ"
           โผล่มาจริง ซึ่งเป็นกติกาสำคัญที่คำอธิบายพูดถึงพอดี */
        owners     = { '-1': [-90, -91] };
        deptNames  = { '-90': 'ฝ่ายบุคคล', '-91': 'ฝ่ายบัญชี' };
        deptLabels = { '-90': 'ฝ่ายบุคคล — บริษัทตัวอย่าง', '-91': 'ฝ่ายบัญชี — บริษัทตัวอย่าง' };

        /* deptId = 0 คือด่านสุดท้ายกัน save() ไม่ให้ส่งของสมมติขึ้นเซิร์ฟเวอร์ */
        deptId   = 0;
        original = stateOf();

        if (el('pdSearch'))   { el('pdSearch').value   = ''; }
        if (el('pdCategory')) { el('pdCategory').value = ''; }
        if (el('pdState'))    { el('pdState').value    = ''; }

        /* ตั้งค่า .value ด้วยโค้ดไม่ทำให้หน้ากากของ select-search.js อัปเดตตาม
           ต้องสั่ง refresh เอง ไม่งั้นปุ่มจะยังโชว์ชื่อหมวดหมู่ที่กรองค้างไว้ก่อนเปิดคู่มือ
           ทั้งที่รายการบนจอไม่ได้ถูกกรองด้วยค่านั้นแล้ว
           (fillSub จัดการช่องหมวดหมู่ย่อยให้เอง รวมถึงล้างค่าที่เลือกไว้) */
        if (window.SelectSearch && el('pdCategory')) { window.SelectSearch.refresh(el('pdCategory')); }
        fillSub();

        setHidden(el('pdEmpty'), true);
        setHidden(el('pdBody'),  false);

        renderLeft();
        renderRight();
        refreshStats();

        /* ต้องโชว์ชิปตัวเลข "หลัง" refreshStats เพราะฟังก์ชันนั้นซ่อนชิปเองเมื่อ
           ยังไม่ได้เลือกหน่วยงาน ซึ่งระหว่างสาธิตเราตั้ง deptId เป็น 0 ไว้ */
        setHidden(el('pdStats'), false);

        if (el('pdSave'))  { el('pdSave').disabled  = true; }
        if (el('pdReset')) { el('pdReset').disabled = true; }

        demoBanner(true);
        if (window.renderIcons) { window.renderIcons(); }
        return true;
    }

    function demoTeardown() {
        if (!demoBackup) { return; }

        var b = demoBackup;
        demoBackup = null;

        products = b.products; byId = b.byId; picked = b.picked;
        original = b.original; deptId = b.deptId;
        owners = b.owners; deptNames = b.deptNames; deptLabels = b.deptLabels;
        subCats = b.subCats;

        if (el('pdSearch'))   { el('pdSearch').value   = b.search; }
        if (el('pdCategory')) { el('pdCategory').value = b.category; }
        if (el('pdState'))    { el('pdState').value    = b.state; }

        /* คืนช่องหมวดหมู่: สร้างรายการหมวดย่อยของจริงกลับมาก่อน แล้วค่อยใส่ค่าเดิม
           ลำดับนี้สลับไม่ได้ ถ้าใส่ค่าก่อน รายการยังเป็นของสาธิตอยู่ ค่านั้นจะไม่มี
           ตัวเลือกรองรับแล้วถูกล้างทิ้ง ตัวกรองที่ผู้ใช้ตั้งไว้ก่อนเปิดคู่มือจะหาย */
        if (window.SelectSearch && el('pdCategory')) { window.SelectSearch.refresh(el('pdCategory')); }
        fillSub();
        if (el('pdSubCategory')) {
            el('pdSubCategory').value = b.sub;
            if (window.SelectSearch) { window.SelectSearch.refresh(el('pdSubCategory')); }
        }

        setHidden(el('pdBody'),  b.bodyHidden);
        setHidden(el('pdEmpty'), b.emptyHidden);
        setHidden(el('pdStats'), b.deptId <= 0);

        demoBanner(false);

        if (b.deptId > 0) {
            renderLeft();
            renderRight();
            refreshStats();
        } else {
            /* ยังไม่เคยเลือกหน่วยงาน = สองฝั่งถูกซ่อนอยู่ แต่ของสาธิตยังค้างใน DOM
               ล้างทิ้งให้หมด ไม่ปล่อยไว้ให้โผล่มาแวบหนึ่งตอนเลือกหน่วยงานครั้งแรก */
            if (el('pdLeft'))  { el('pdLeft').innerHTML  = ''; }
            if (el('pdRight')) { el('pdRight').innerHTML = ''; }
        }
        refreshDirty();
        if (window.renderIcons) { window.renderIcons(); }
    }

    function demoBanner(on) {
        var old = el('pdDemoNote');
        if (old && old.parentNode) { old.parentNode.removeChild(old); }
        if (!on) { return; }

        var wrap = el('pdBody');
        if (!wrap || !wrap.parentNode) { return; }

        var note = document.createElement('p');
        note.id        = 'pdDemoNote';
        note.className = 'pd-demo-note';
        note.innerHTML = '<i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>'
                       + '<span><b>ชุดข้อมูลตัวอย่างสำหรับสาธิตเท่านั้น</b> '
                       + 'ไม่ใช่ข้อมูลจริง และจะไม่ถูกบันทึก — ปิดคู่มือแล้วหน้าจะกลับเป็นเหมือนเดิม</span>';

        wrap.parentNode.insertBefore(note, wrap);
    }

    window.GuideDemo = {
        page:     'product_dept',
        setup:    demoSetup,
        teardown: demoTeardown
    };


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
