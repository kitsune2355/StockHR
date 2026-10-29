/* ============================================================
 *  grid-common.js — ค่าตั้งต้นที่ตารางทุกหน้าใช้ร่วมกัน
 *
 *  โหลดก่อน agencies.js / users.js เสมอ
 *  หน้าไหนจะสร้างตารางก็เขียนแค่ส่วนที่ต่างกัน (ข้อมูล คอลัมน์ ข้อความว่าง)
 *  ที่เหลือดึงจากที่นี่ ตารางทุกหน้าจะได้ทำงานเหมือนกันหมด
 *
 *      var opts = GridCommon.options({ data: rows, columns: cols, ... });
 *      table = new Tabulator('#grid', opts);
 *
 *  ---------------------------------------------------------------
 *  ทำงานเหมือน Excel
 *  ---------------------------------------------------------------
 *    - คลิกที่ช่องแล้วลากคลุมได้ เลือกทีละหลายช่อง/หลายแถว
 *    - กดหัวคอลัมน์ = เลือกทั้งคอลัมน์  ลากที่หัวคอลัมน์ = เลือกหลายคอลัมน์
 *    - Shift + ลูกศร ขยายพื้นที่ที่เลือก, Ctrl + ลูกศร กระโดดสุดขอบ
 *    - Ctrl+C คัดลอกเฉพาะพื้นที่ที่เลือก วางลง Excel ได้ตรง ๆ
 *    - ดับเบิลคลิก (หรือกดพิมพ์ทับ) เพื่อแก้ค่าในช่อง
 *
 *  ทำไมต้องเปลี่ยนเป็นดับเบิลคลิกถึงจะแก้ได้
 *    ถ้าคลิกเดียวเปิดช่องแก้ทันที การลากคลุมจะกลายเป็นการลากเลือกข้อความ
 *    ในช่องแทน เลือกข้ามช่องไม่ได้เลย ซึ่งเป็นพฤติกรรมเดียวกับ Excel อยู่แล้ว
 *
 *  หมายเหตุ: ไม่เปิด selectableRangeClearCells (กด Delete แล้วล้างค่าทั้งพื้นที่)
 *  เพราะคำสั่งนั้นล้างค่าโดยไม่สนใจกฎ editable ของแต่ละช่อง จะลบชื่อผู้ใช้
 *  หรือสิทธิ์ของบัญชีที่ล็อกไว้ได้ ซึ่งอันตรายกว่าประโยชน์ที่ได้
 * ============================================================ */
(function () {
    'use strict';

    /* ---------- ข้อความแถบแบ่งหน้าเป็นภาษาไทย ----------
     * ไอคอนลูกศรใส่ผ่าน CSS (ดู .tabulator-page[data-page] ใน app.css)
     * เพราะ Tabulator ใส่ข้อความพวกนี้เป็น text ไม่ใช่ HTML
     */
    function langs() {
        return {
            'th-th': {
                pagination: {
                    page_size:   'แสดงต่อหน้า',
                    page_title:  'ไปหน้าที่',
                    first:       'หน้าแรก',
                    first_title: 'หน้าแรก',
                    prev:        'ก่อนหน้า',
                    prev_title:  'หน้าก่อนหน้า',
                    next:        'ถัดไป',
                    next_title:  'หน้าถัดไป',
                    last:        'หน้าสุดท้าย',
                    last_title:  'หน้าสุดท้าย',
                    all:         'ทั้งหมด',
                    counter: {
                        showing: 'แสดง',
                        of:      'จาก',
                        rows:    'รายการ',
                        pages:   'หน้า'
                    }
                },
                data: { loading: 'กำลังโหลด...', error: 'โหลดข้อมูลไม่สำเร็จ' }
            }
        };
    }

    /* ---------- จำนวนปุ่มเลขหน้า ----------
     * จอมือถือกว้างไม่พอให้ปุ่มเลขหน้าห้าปุ่มอยู่แถวเดียวกับปุ่มลูกศรสี่ตัว
     * พอไม่พอก็ตกบรรทัดจนแถบล่างสูงขึ้นไปกินพื้นที่ของตาราง
     *
     * สามปุ่มพอให้เห็นว่าอยู่หน้าไหนและขยับทีละหน้าได้ ส่วนการกระโดดไกล ๆ
     * ใช้ปุ่มหน้าแรก/หน้าสุดท้ายที่อยู่ข้าง ๆ ได้อยู่แล้ว
     *
     * 640px ตรงกับ breakpoint ที่ CSS ของแถบแบ่งหน้าใช้อยู่ (ดู app.css)
     * ต้องเป็นตัวเลขเดียวกันเสมอ ไม่งั้นจะมีช่วงกว้างที่จัดเรียงแบบจอแคบ
     * แต่ยังมีปุ่มห้าปุ่มอยู่ ซึ่งเป็นกรณีที่แถบล่างพังพอดี
     */
    var NARROW_PX = 640;

    function pageButtonCount() {
        return (window.innerWidth || 1024) < NARROW_PX ? 3 : 5;
    }


    /* ---------- ค่าตั้งต้นของทุกตาราง ---------- */
    function base() {
        return {
            index:      '_key',
            layout:     'fitColumns',
            height:     '100%',

            pagination: true,
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            paginationButtonCount: pageButtonCount(),
            paginationCounter: 'rows',

            locale: 'th-th',
            langs:  langs(),

            /* พื้นที่ไม่พอ -> ยุบคอลัมน์ที่สำคัญน้อยกว่าไปไว้ใต้ปุ่ม + ของแต่ละแถว
               ดีกว่าปล่อยให้เลื่อนแนวนอนไปหาช่องที่อยากแก้ */
            responsiveLayout: 'collapse',
            responsiveLayoutCollapseStartOpen: false,

            /* ---- ส่วนที่ทำให้ใช้งานเหมือน Excel ---- */
            selectableRange: 1,             // เลือกได้ครั้งละ 1 พื้นที่ เหมือน Excel
            selectableRangeColumns: true,   // กดหัวคอลัมน์ = เลือกทั้งคอลัมน์
            selectableRangeClearCells: false,

            /* ปิด selectableRangeRows (กดคอลัมน์แรกแล้วเลือกทั้งแถว) ไว้ก่อน
               เพราะ library เลือก "คอลัมน์แรกที่มองเห็น" มาเป็นหัวแถวเอง
               ซึ่งในหน้าทะเบียนสินค้าดันไปตกที่คอลัมน์รหัสสินค้า ผลคือ
                 - คลิกช่องรหัสสินค้าทีเดียว กลายเป็นเลือกทั้งแถว 8 ช่อง
                 - เรียงลำดับตามรหัสสินค้าไม่ทำงาน
               (library เตือนเรื่องนี้เองว่า "may result in unpredictable behavior"
                เมื่อคอลัมน์ที่ถูกเลือกเป็นหัวแถวมี headerSort หรือ editor)
               อยากเลือกทั้งแถวก็ยังลากคลุมข้ามช่องได้ตามปกติ */
            selectableRangeRows: false,

            /* ไม่ต้องไปโฟกัสช่องแรกให้ตั้งแต่เปิดหน้า
               ผู้ใช้ยังไม่ได้เลือกอะไรเลย การขึ้นกรอบไว้ก่อนทำให้เข้าใจผิดว่ากดอะไรค้างไว้
               และยังแย่งโฟกัสไปจากช่องค้นหาด้วย (ดู guardRanges เรื่องการซ่อนกรอบ) */
            selectableRangeAutoFocus: false,
            editTriggerEvent: 'dblclick',

            /* กดที่หัวคอลัมน์ = เลือกทั้งคอลัมน์  กดที่ลูกศร = เรียงลำดับ
               ถ้าไม่แยกกัน การกดหัวคอลัมน์จะทั้งเลือกและเรียงพร้อมกัน สับสนมาก
               (Tabulator เตือนเรื่องนี้ไว้เองเมื่อเปิด selectableRangeColumns) */
            headerSortClickElement: 'icon',

            clipboard: true,
            clipboardCopyStyled: false,          // คัดลอกเป็นข้อความเปล่า ไม่เอาสีพื้นไปด้วย
            clipboardCopyRowRange: 'range',      // คัดลอกเฉพาะพื้นที่ที่เลือก
            clipboardCopyConfig: { columnHeaders: false, rowHeaders: false }
        };
    }

    /* รวมค่าของหน้ากับค่าตั้งต้น — ค่าที่หน้าส่งมาชนะเสมอ */
    function options(page) {
        var opts = base();
        var key;
        for (key in page) {
            if (page.hasOwnProperty(key)) { opts[key] = page[key]; }
        }
        if (opts.columns) { fitHeaders(opts.columns, opts.columnDefaults || {}); }
        return opts;
    }

    /* ============================================================
     *  ชื่อหัวคอลัมน์ต้องเห็นเต็ม ไม่ถูกตัดเป็น "…"
     *
     *  ทุกตารางใช้ layout: fitColumns ซึ่งบีบคอลัมน์ลงได้จนถึง minWidth
     *  หลายคอลัมน์ตั้ง minWidth ไว้แคบกว่าชื่อหัวคอลัมน์ (บวกลูกศรเรียงลำดับ + กรวยกรอง)
     *  ชื่อจึงถูกตัดเหลือ "จำนวนที่จ…" อ่านไม่รู้เรื่อง
     *
     *  แก้ที่นี่ที่เดียวให้ทุกตาราง: ก่อนสร้างตาราง วัดความกว้างจริงของหัวแต่ละคอลัมน์
     *  ด้วยหัวคอลัมน์จำลองที่ใช้ CSS ชุดเดียวกับของจริง (ฟอนต์ ขนาดตัวอักษรที่ผู้ใช้ตั้ง
     *  padding ที่เว้นให้ลูกศร และไอคอนกรวย) แล้วยก minWidth ขึ้นให้พอดีชื่อ
     *  จอแคบจนวางไม่พอ responsiveLayout: collapse จะย้ายคอลัมน์ไปไว้ใต้ปุ่ม + ของแถวแทน
     *  ไม่ตัดชื่อทิ้ง
     *
     *  คอลัมน์ที่ไม่ต้องการให้ยก ใส่ fitHeader: false ในนิยามคอลัมน์
     * ============================================================ */
    function headerProbe() {
        var wrap = document.createElement('div');
        wrap.className = 'tabulator';
        wrap.setAttribute('aria-hidden', 'true');
        wrap.style.cssText = 'position:absolute;left:-10000px;top:0;visibility:hidden;'
                           + 'width:auto;height:auto;border:0;overflow:visible;';
        wrap.innerHTML =
            '<div class="tabulator-header" style="overflow:visible">'
          +   '<div class="tabulator-col" style="position:static;display:inline-block;width:auto;min-width:0">'
          +     '<div class="tabulator-col-content">'
          +       '<div class="tabulator-col-title-holder">'
          +         '<div class="tabulator-col-title" style="width:auto"></div>'
          +         '<span class="grid-filter-toggle"></span>'
          +       '</div>'
          +     '</div>'
          +   '</div>'
          + '</div>';
        document.body.appendChild(wrap);
        return {
            wrap:   wrap,
            col:    wrap.querySelector('.tabulator-col'),
            title:  wrap.querySelector('.tabulator-col-title'),
            funnel: wrap.querySelector('.grid-filter-toggle')
        };
    }

    function headerTitleText(def) {
        var t = (def.title === undefined || def.title === null) ? '' : String(def.title);
        /* ชื่อบางคอลัมน์มีแท็ก HTML ปนมา วัดเฉพาะตัวอักษรที่เห็นจริง */
        return t.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function fitHeaders(columns, defaults) {
        if (!document.body) { return; }
        var probe = null;

        function walk(list) {
            for (var i = 0; i < list.length; i++) {
                var def = list[i];
                if (!def) { continue; }

                /* คอลัมน์กลุ่ม (หัวสองชั้น) — ปรับที่คอลัมน์ลูก ตัวกลุ่มกว้างตามลูกเอง */
                if (def.columns && def.columns.length) { walk(def.columns); continue; }

                var text = headerTitleText(def);
                if (text === '' || def.fitHeader === false) { continue; }

                if (!probe) { probe = headerProbe(); }

                var sortable = (def.headerSort !== undefined) ? def.headerSort
                             : ((defaults.headerSort !== undefined) ? defaults.headerSort : true);
                probe.col.className = 'tabulator-col' + (sortable ? ' tabulator-sortable' : '');
                probe.title.textContent = text;
                probe.funnel.style.display = def.headerFilter ? '' : 'none';

                /* +2 กันเศษ pixel ปัดเศษ ทำให้ตัวสุดท้ายถูกตัดทั้งที่วัดได้พอดี
                   ฟอนต์ Prompt ยังโหลดไม่เสร็จ (เปิดครั้งแรก เน็ตช้า) = วัดได้จากฟอนต์สำรองที่แคบกว่า
                   จึงเผื่อไว้ 20% ไม่งั้นพอฟอนต์จริงมาถึง ชื่อจะกลับไปถูกตัดอีก */
                var w = probe.col.getBoundingClientRect().width;
                if (document.fonts && document.fonts.status !== 'loaded') { w *= 1.2; }
                var need = Math.ceil(w) + 2;

                if (!(def.minWidth >= need)) { def.minWidth = need; }
                if (typeof def.width === 'number' && def.width < need) { def.width = need; }
                if (typeof def.maxWidth === 'number' && def.maxWidth < need) { def.maxWidth = need; }
            }
        }

        try {
            walk(columns);
        } finally {
            if (probe && probe.wrap.parentNode) { probe.wrap.parentNode.removeChild(probe.wrap); }
        }
    }

    /* ---------- คอลัมน์ที่เป็นปุ่ม/ตัวช่วย ไม่ใช่ข้อมูล ----------
     * ใส่ทับลงไปในนิยามคอลัมน์เพื่อกันไม่ให้ติดไปทั้งตอนคัดลอกและตอนส่งออก
     */
    var CONTROL = { download: false, clipboard: false };

    /* ---------- ค่าที่เก็บเป็นรหัส แต่ต้องออกไปเป็นข้อความ ----------
     * ใช้ตัวแปลงเดียวกันทั้งตอนคัดลอกและตอนส่งออก จะได้ไม่หลุดกันคนละแบบ
     */
    function labelled(fn) {
        return { accessorDownload: fn, accessorClipboard: fn };
    }

    /* ============================================================
     *  ตัวกรองที่หัวคอลัมน์
     *
     *  ใส่ทับลงไปในนิยามคอลัมน์ เลือกชนิดให้ตรงกับข้อมูลของคอลัมน์นั้น
     *      filterText()          ช่องพิมพ์ ค้นแบบมีคำนี้อยู่ข้างใน
     *      filterPick(values)    รายการให้เลือก สำหรับคอลัมน์ที่เก็บเป็นรหัส
     *      filterOwn()           รายการที่ดึงค่าที่มีอยู่จริงในคอลัมน์มาเอง
     *      filterMin()           ตัวเลข กรองว่า "ตั้งแต่เท่านี้ขึ้นไป"
     *      filterStatus()        เปิด/ปิดใช้งาน
     *
     *  ตัวกรองหัวคอลัมน์ทำงานร่วมกับช่องค้นหารวมด้านบนแบบ "และ"
     *  (Tabulator เก็บสองอย่างนี้แยกกัน แล้วเอามา and กันตอนกรอง)
     *  จำนวนแถวที่แถบล่างจึงเป็นจำนวนหลังกรองครบทุกชั้นแล้วเสมอ
     * ============================================================ */

    function filterText(placeholder) {
        return {
            headerFilter: 'input',
            headerFilterPlaceholder: placeholder || 'พิมพ์เพื่อกรอง',
            headerFilterFunc: 'like'
        };
    }

    function blankFilter(value) {
        return (value === '' || value === null || value === undefined);
    }

    /* เทียบค่าที่เลือกจากรายการกับค่าในแถว
     * ค่าที่ได้จากรายการเป็นข้อความเสมอ ส่วนค่าในแถวเป็นได้ทั้งตัวเลขและข้อความ
     *   company_id / unit_id  เก็บเป็นตัวเลข  -> ต้องเทียบเป็นตัวเลข ('5' กับ 5)
     *   role                  เก็บเป็นข้อความ -> ต้องเทียบเป็นข้อความ ('admin')
     * ถ้าเทียบเป็นตัวเลขอย่างเดียว Number('admin') จะได้ NaN แล้วไม่เท่ากับอะไรเลย
     * ผลคือกรองสิทธิ์แล้วได้ 0 แถวเสมอ
     */
    function sameValue(headerValue, rowValue) {
        var numeric = (String(headerValue) !== '' && !isNaN(headerValue) &&
                       String(rowValue)    !== '' && !isNaN(rowValue));
        if (numeric) { return Number(rowValue) === Number(headerValue); }
        return String(rowValue) === String(headerValue);
    }

    /* คอลัมน์ที่เก็บเป็นรหัสแต่แสดงเป็นชื่อ (เช่น company_id, unit_id, role)
       รายการให้เลือกจึงต้องโชว์ชื่อ แต่เทียบด้วยรหัส */
    function filterPick(values, placeholder) {
        return {
            headerFilter: 'list',
            headerFilterParams: {
                values: values,
                clearable: true,
                autocomplete: true,
                listOnEmpty: true,
                placeholderEmpty: 'ไม่พบรายการ'
            },
            headerFilterPlaceholder: placeholder || 'ทั้งหมด',
            headerFilterFunc: function (headerValue, rowValue) {
                if (blankFilter(headerValue)) { return true; }
                return sameValue(headerValue, rowValue);
            }
        };
    }

    /* คอลัมน์ที่เก็บเป็นข้อความอยู่แล้ว ให้ library รวบรวมค่าที่มีจริงมาเป็นตัวเลือกเอง
       ไม่ต้องมานั่งประกาศรายการซ้ำ และรายการจะตรงกับข้อมูลจริงเสมอ */
    function filterOwn(placeholder) {
        return {
            headerFilter: 'list',
            headerFilterParams: {
                valuesLookup: true,
                clearable: true,
                autocomplete: true,
                listOnEmpty: true,
                placeholderEmpty: 'ไม่พบรายการ'
            },
            headerFilterPlaceholder: placeholder || 'ทั้งหมด',
            headerFilterFunc: '='
        };
    }

    /* ตัวเลข — กรองแบบ "ตั้งแต่เท่านี้ขึ้นไป"
       เลือกแบบนี้เพราะคำถามที่ใช้จริงคือ "ของที่ราคาเกินเท่าไร"
       ไม่ใช่ "ของที่ราคาเท่ากับเป๊ะ ๆ" ซึ่งแทบไม่มีใครค้น */
    function filterMin(placeholder) {
        return {
            headerFilter: 'number',
            headerFilterPlaceholder: placeholder || 'ตั้งแต่',
            headerFilterFunc: function (headerValue, rowValue) {
                if (blankFilter(headerValue)) { return true; }
                return Number(rowValue) >= Number(headerValue);
            }
        };
    }

    function filterStatus() {
        return {
            headerFilter: 'list',
            headerFilterParams: {
                values: { '1': 'เปิดใช้งาน', '0': 'ปิดใช้งาน' },
                clearable: true
            },
            headerFilterPlaceholder: 'ทั้งหมด',
            headerFilterFunc: function (headerValue, rowValue) {
                if (blankFilter(headerValue)) { return true; }
                return Number(rowValue) === Number(headerValue);
            }
        };
    }

    /* ============================================================
     *  ซ่อนช่องกรองไว้ใต้ไอคอนกรวยที่หัวคอลัมน์
     *
     *  ปัญหาถ้าโชว์ช่องกรองไว้ตลอด: หัวตารางสูงขึ้นทุกตาราง กินที่ของแถวข้อมูล
     *  ทั้งที่ส่วนใหญ่เปิดมาเพื่อดูเฉย ๆ ไม่ได้กรอง
     *
     *  จึงซ่อนไว้ก่อน แล้วมีไอคอนกรวยข้างชื่อคอลัมน์ กดเมื่อไหร่ค่อยกางออกมา
     *
     *  กติกาการกด (ตั้งใจให้ไม่มีทางเผลอซ่อนตัวกรองที่ยังทำงานอยู่):
     *      ปิดอยู่            -> เปิดแล้วโฟกัสให้พิมพ์ได้เลย
     *      เปิดอยู่ ยังไม่ได้กรอง -> ปิด
     *      เปิดอยู่ กรองอยู่     -> ล้างค่าแล้วปิด
     *
     *  คอลัมน์ที่กำลังกรองอยู่ ไอคอนจะเป็นสีธีมและช่องจะกางค้างไว้เสมอ
     *  จะได้ไม่มีกรณี "ข้อมูลหายไปไหน" เพราะตัวกรองที่ซ่อนอยู่
     * ============================================================ */

    var FUNNEL_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>';

    function enableFilterToggles(table) {

        function build() {
            var cols = table.getColumns();

            for (var i = 0; i < cols.length; i++) {
                attach(cols[i]);
            }
            refreshAll();
        }

        function attach(column) {
            var el = column.getElement();
            if (!el || el.querySelector('.grid-filter-toggle')) { return; }

            var filter = el.querySelector('.tabulator-header-filter');
            var holder = el.querySelector('.tabulator-col-title-holder');
            if (!filter || !holder) { return; }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'grid-filter-toggle';
            btn.innerHTML = FUNNEL_SVG;
            btn.setAttribute('title', 'กรองคอลัมน์นี้');
            btn.setAttribute('aria-label', 'กรองคอลัมน์นี้');

            /* กันไม่ให้การกดไอคอนกลายเป็นการเลือกทั้งคอลัมน์แบบ Excel
               หรือไปสั่งเรียงลำดับ ซึ่งทั้งสองอย่างผูกอยู่กับหัวคอลัมน์เดียวกัน */
            btn.addEventListener('mousedown', function (e) { e.stopPropagation(); });
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                toggle(column, el, filter);
            });

            holder.appendChild(btn);
        }

        function hasValue(column) {
            var v = column.getHeaderFilterValue();
            return !(v === '' || v === null || v === undefined);
        }

        /* ความสูงหัวตารางถูกคำนวณไว้ตอนสร้างตาราง ตอนนั้นช่องกรองยังซ่อนอยู่
           พอกางออกมาทีหลัง ช่องจะโผล่เกินกรอบแล้วโดน overflow:hidden ตัดทิ้ง
           ต้องสั่งให้คำนวณความสูงใหม่ทุกครั้งที่กาง/พับ */
        function relayout() {
            try { table.redraw(); } catch (e) { /* ตารางยังไม่พร้อม ข้ามไป */ }
        }

        function toggle(column, el, filter) {
            var open = el.classList.contains('filter-open');

            if (open) {
                if (hasValue(column)) { column.setHeaderFilterValue(''); }
                el.classList.remove('filter-open');
                refreshAll();
                relayout();
            } else {
                el.classList.add('filter-open');
                refreshAll();
                relayout();

                /* โฟกัสหลังคำนวณความสูงใหม่ ไม่งั้นช่องยังโดนตัดอยู่ตอนโฟกัส */
                var input = filter.querySelector('input, select');
                if (input) { input.focus(); }
            }
        }

        /* คอลัมน์ไหนกำลังกรองอยู่ ให้ไอคอนติดสีและกางช่องค้างไว้
           คืนค่า true ถ้ามีคอลัมน์ไหนเพิ่งถูกกางเพิ่ม จะได้รู้ว่าต้องคำนวณความสูงใหม่ */
        function refreshAll() {
            var cols    = table.getColumns();
            var opened  = false;

            for (var i = 0; i < cols.length; i++) {
                var el = cols[i].getElement();
                if (!el || !el.querySelector('.tabulator-header-filter')) { continue; }

                var on = hasValue(cols[i]);
                el.classList.toggle('filter-on', on);
                if (on && !el.classList.contains('filter-open')) {
                    el.classList.add('filter-open');
                    opened = true;
                }

                var btn = el.querySelector('.grid-filter-toggle');
                if (btn) {
                    btn.setAttribute('title', on ? 'กำลังกรองอยู่ — กดเพื่อล้าง' : 'กรองคอลัมน์นี้');
                }
            }
            return opened;
        }

        if (table.initialized) { build(); } else { table.on('tableBuilt', build); }

        /* คอลัมน์ถูกยุบ/กางตอนจอเปลี่ยนขนาด ต้องติดไอคอนให้ตัวที่เพิ่งโผล่ด้วย */
        table.on('columnVisibilityChanged', build);

        /* ตั้งค่ากรองจากที่อื่น (เช่นโค้ดเรียกเอง) ช่องต้องกางเองให้เห็นว่ากรองอยู่
           ถ้ามีการกางเพิ่ม ต้องคำนวณความสูงหัวตารางใหม่ด้วย */
        table.on('dataFiltered', function () {
            if (refreshAll()) { relayout(); }
        });
    }

    /* รวม object หลายก้อนเป็นนิยามคอลัมน์เดียว — เขียนสั้นกว่าไล่ก๊อป key ทีละตัว */
    function col() {
        var out = {}, i, k;
        for (i = 0; i < arguments.length; i++) {
            for (k in arguments[i]) {
                if (arguments[i].hasOwnProperty(k)) { out[k] = arguments[i][k]; }
            }
        }
        return out;
    }

    /* ============================================================
     *  จุดลากเติมค่ามุมขวาล่างแบบ Excel (fill handle)
     *
     *  Tabulator วาดจุดกลม ๆ ไว้ที่มุมกรอบให้ แต่เป็นแค่ ::after ของ CSS
     *  ลากไม่ได้จริง (ทั้งตัว overlay ตั้ง pointer-events: none ไว้ด้วย)
     *  ตรงนี้จึงวางปุ่มจริงทับตำแหน่งนั้น แล้วเขียนการลากเติมค่าเอง
     *
     *  วิธีใช้: ลากจุดขึ้นหรือลง ค่าของแถวที่เลือกไว้จะถูกคัดลอกลงแถวที่ลากคลุม
     *  ถ้าเลือกไว้หลายแถว จะวนซ้ำเป็นชุด เหมือน Excel
     *  รองรับแนวตั้งอย่างเดียว เพราะแต่ละคอลัมน์เก็บคนละชนิดข้อมูล
     *  การเติมข้ามคอลัมน์จึงไม่มีความหมายในตารางแบบนี้
     *
     *  ช่องที่แก้ไม่ได้จะถูกข้าม (เช่น ชื่อผู้ใช้ของบัญชีเดิม หรือสิทธิ์ของตัวเอง)
     *  กำหนดเพิ่มได้ด้วย fillable ในนิยามคอลัมน์ ถ้ากฎต่างจาก editable
     * ============================================================ */
    function canFill(cell, rules) {
        var field = cell.getColumn().getField();
        var rule  = (rules && field && rules.hasOwnProperty(field)) ? rules[field] : undefined;

        /* กฎที่ส่งมาชนะทุกอย่าง — ใช้กับคอลัมน์ที่ไม่มีช่องพิมพ์แต่ยังควรเติมได้
           (เช่น สถานะที่กดสลับด้วยสวิตช์) หรือคอลัมน์ที่ห้ามเติมเป็นกรณีพิเศษ
           เก็บกฎไว้ตรงนี้แทนที่จะใส่ในนิยามคอลัมน์ เพราะ Tabulator ตรวจ key
           ที่ไม่รู้จักแล้วขึ้นคำเตือนใน console */
        if (rule === false) { return false; }
        if (rule === true)  { return true; }
        if (typeof rule === 'function') { return rule(cell) !== false; }

        var def = cell.getColumn().getDefinition();
        if (!def.editor) { return false; }
        if (def.editable === false) { return false; }
        if (typeof def.editable === 'function') { return def.editable(cell) !== false; }
        return true;
    }

    function enableFillHandle(table, onFilled, rules) {
        var holder = table.element.querySelector('.tabulator-tableholder');

        /* ตอนหน้าเรียกฟังก์ชันนี้ Tabulator ยังสร้าง DOM ไม่เสร็จ รอให้เสร็จก่อน */
        if (!holder) {
            table.on('tableBuilt', function () { enableFillHandle(table, onFilled, rules); });
            return;
        }

        var handle = document.createElement('div');
        handle.className = 'grid-fill-handle';
        handle.setAttribute('title', 'ลากเพื่อคัดลอกค่าลงแถวถัดไป');
        handle.setAttribute('hidden', '');
        holder.appendChild(handle);

        /* กรอบเส้นประบอกขอบเขตที่จะโดนเติม วาดเป็นชั้นลอยชั้นเดียว
           ไม่ใส่เส้นขอบที่ตัวช่องทีละช่อง เพราะเส้นขอบจะดันเนื้อหาในช่องขยับ
           และได้เป็นกล่องประหลายใบแทนที่จะเป็นกรอบเดียวแบบ Excel */
        var previewBox = document.createElement('div');
        previewBox.className = 'grid-fill-preview';
        previewBox.setAttribute('hidden', '');
        holder.appendChild(previewBox);

        var dragging = false;
        var preview  = [];      // ช่องที่กำลังจะโดนเติม ใช้ไฮไลต์ให้เห็นก่อนปล่อยเมาส์

        function activeRangeEl() {
            return table.element.querySelector('.tabulator-range-overlay .tabulator-range.tabulator-range-active');
        }

        /* ย้ายจุดไปเกาะมุมขวาล่างของกรอบปัจจุบัน
           คิดจากพิกัดจริงบนจอ ไม่ใช่ค่า left/top ที่ library ตั้งไว้
           เพราะกรอบอยู่ในชั้นที่เลื่อนตามตาราง ส่วนจุดนี้ไม่ได้เลื่อนตาม */
        function place() {
            var box = activeRangeEl();
            var idle = table.element.classList.contains('ranges-idle');

            if (!box || idle) { handle.setAttribute('hidden', ''); return; }

            var r = box.getBoundingClientRect();
            var h = holder.getBoundingClientRect();

            /* กรอบเลื่อนพ้นขอบพื้นที่ที่มองเห็นไปแล้ว ไม่ต้องแสดงจุด */
            if (r.bottom < h.top || r.bottom > h.bottom + 8 || r.right < h.left || r.right > h.right + 8) {
                handle.setAttribute('hidden', '');
                return;
            }

            /* ดึงจุดกลับเข้ามาไม่ให้ไปนั่งอยู่ใต้แถบเลื่อน
               ถ้ากรอบที่เลือกชิดขอบขวาสุด (คอลัมน์สุดท้าย) จุดจะไปตกทับแถบเลื่อนพอดี
               แล้วคลิกจะโดนแถบเลื่อนรับไปแทน กดลากเติมค่าไม่ได้เลย
               clientWidth/clientHeight ไม่รวมความกว้างของแถบเลื่อน จึงใช้เป็นขอบได้ */
            var left = r.right  - h.left - 5;
            var top  = r.bottom - h.top  - 5;
            var maxLeft = holder.clientWidth  - 11;
            var maxTop  = holder.clientHeight - 11;

            if (left > maxLeft) { left = maxLeft; }
            if (top  > maxTop)  { top  = maxTop; }

            handle.style.left = left + 'px';
            handle.style.top  = top + 'px';
            handle.removeAttribute('hidden');
        }

        function clearPreview() {
            for (var i = 0; i < preview.length; i++) {
                preview[i].classList.remove('fill-preview');
            }
            preview = [];
            previewBox.setAttribute('hidden', '');
        }

        /* ไฮไลต์เฉพาะช่องในคอลัมน์ที่เลือกไว้ ไม่ใช่ทั้งแถว
           เพราะการเติมกระทบแค่คอลัมน์เหล่านั้น ถ้าไฮไลต์ทั้งแถวจะเข้าใจผิดว่า
           ข้อมูลคอลัมน์อื่นจะถูกทับไปด้วย */
        function showPreview(src, tgt) {
            var fields = [], i, c;

            for (c = 0; c < src.cols.length; c++) {
                var f = src.cols[c].getField();
                if (f) { fields.push(f); }
            }
            if (!fields.length) { return; }

            var first = null, last = null;

            for (i = tgt.from; i <= tgt.to; i++) {
                for (c = 0; c < fields.length; c++) {
                    var cell = src.shown[i].getCell(fields[c]);
                    if (!cell) { continue; }

                    var el = cell.getElement();
                    if (!el) { continue; }

                    el.classList.add('fill-preview');
                    preview.push(el);

                    if (!first) { first = el; }
                    last = el;
                }
            }

            if (!first || !last) { return; }

            /* กรอบครอบตั้งแต่ช่องซ้ายบนสุดถึงช่องขวาล่างสุดของพื้นที่ที่จะเติม */
            var a = first.getBoundingClientRect();
            var b = last.getBoundingClientRect();
            var h = holder.getBoundingClientRect();

            previewBox.style.left   = (Math.min(a.left, b.left) - h.left) + 'px';
            previewBox.style.top    = (Math.min(a.top, b.top) - h.top) + 'px';
            previewBox.style.width  = (Math.max(a.right, b.right) - Math.min(a.left, b.left)) + 'px';
            previewBox.style.height = (Math.max(a.bottom, b.bottom) - Math.min(a.top, b.top)) + 'px';
            previewBox.removeAttribute('hidden');
        }

        /* แถวที่แสดงอยู่จริงตอนนี้ เรียงตามที่เห็นบนจอ */
        function shownRows() {
            var rows = table.getRows('active');
            var out  = [];
            for (var i = 0; i < rows.length; i++) {
                var el = rows[i].getElement();
                if (el && el.parentNode) { out.push(rows[i]); }
            }
            return out;
        }

        function rowAt(y) {
            var rows = shownRows();
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i].getElement().getBoundingClientRect();
                if (y >= r.top && y <= r.bottom) { return i; }
            }
            /* ลากเลยขอบบน/ล่างไปแล้ว ให้ยึดแถวแรกหรือแถวสุดท้ายไว้ */
            if (!rows.length) { return -1; }
            return (y < rows[0].getElement().getBoundingClientRect().top) ? 0 : rows.length - 1;
        }

        /* หาว่ากรอบที่เลือกไว้กินแถวไหนถึงแถวไหน และมีคอลัมน์อะไรบ้าง */
        function source() {
            var ranges = table.getRanges();
            if (!ranges || !ranges.length) { return null; }

            var rows = ranges[0].getRows();
            var cols = ranges[0].getColumns();
            if (!rows.length || !cols.length) { return null; }

            var shown = shownRows();
            var first = -1, last = -1;
            for (var i = 0; i < shown.length; i++) {
                for (var j = 0; j < rows.length; j++) {
                    if (shown[i].getElement() === rows[j].getElement()) {
                        if (first === -1) { first = i; }
                        last = i;
                    }
                }
            }
            if (first === -1) { return null; }

            return { rows: rows, cols: cols, first: first, last: last, shown: shown };
        }

        function targetRange(src, atIndex) {
            if (atIndex > src.last)  { return { from: src.last + 1, to: atIndex, up: false }; }
            if (atIndex < src.first) { return { from: atIndex, to: src.first - 1, up: true }; }
            return null;
        }

        function onMove(e) {
            if (!dragging) { return; }
            e.preventDefault();

            var src = source();
            if (!src) { return; }

            var at  = rowAt(e.clientY);
            var tgt = (at === -1) ? null : targetRange(src, at);

            clearPreview();
            if (!tgt) { return; }

            showPreview(src, tgt);
        }

        function onUp(e) {
            if (!dragging) { return; }
            dragging = false;
            document.removeEventListener('mousemove', onMove, true);
            document.removeEventListener('mouseup', onUp, true);
            table.element.classList.remove('is-filling');
            clearPreview();

            var src = source();
            if (!src) { return; }

            var at  = rowAt(e.clientY);
            var tgt = (at === -1) ? null : targetRange(src, at);
            if (!tgt) { return; }

            var touched = [];
            var n = src.rows.length;

            for (var i = tgt.from; i <= tgt.to; i++) {
                /* ลากลง = วนชุดต้นทางจากแถวแรกลงมา
                   ลากขึ้น = วนย้อนจากแถวสุดท้ายขึ้นไป ผลลัพธ์จึงต่อเนื่องกันทั้งสองทิศ */
                var step = tgt.up ? (src.first - i) : (i - src.last);
                var pick = tgt.up ? src.rows[(n - (step % n)) % n] : src.rows[(step - 1) % n];
                var changed = false;

                for (var c = 0; c < src.cols.length; c++) {
                    var field = src.cols[c].getField();
                    if (!field) { continue; }

                    var to = src.shown[i].getCell(field);
                    if (!to || !canFill(to, rules)) { continue; }

                    var value = pick.getCell(field).getValue();
                    if (to.getValue() !== value) { to.setValue(value); changed = true; }
                }

                if (changed) { touched.push(src.shown[i]); }
            }

            if (touched.length && typeof onFilled === 'function') { onFilled(touched); }
        }

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dragging = true;
            table.element.classList.add('is-filling');
            document.addEventListener('mousemove', onMove, true);
            document.addEventListener('mouseup', onUp, true);
        });

        table.on('rangeChanged', place);
        table.on('rangeAdded', place);
        table.on('rangeRemoved', place);
        table.on('renderComplete', place);
        table.on('scrollVertical', place);
        table.on('scrollHorizontal', place);
        table.element.addEventListener('mouseup', place);
        table.element.addEventListener('keyup', place);
        window.addEventListener('resize', place);

        place();
    }

    /* ---------- ช่องเลือกจากรายการ ----------
     * คลิกแล้วเห็นตัวเลือกทั้งหมดทันที และพิมพ์เพื่อกรองได้ในช่องเดียวกัน
     *   autocomplete  พิมพ์เพื่อค้นหา
     *   listOnEmpty   ยังไม่ได้พิมพ์อะไร ก็กางรายการทั้งหมดให้เลือก
     *   freetext:false บังคับให้ได้ค่าจากรายการเท่านั้น กันพิมพ์ชื่อที่ไม่มีอยู่จริง
     */
    function pickerParams(values, extra) {
        var p = {
            values: values,
            autocomplete: true,
            listOnEmpty: true,
            freetext: false,
            clearable: false,
            maxWidth: false,
            placeholderEmpty: 'ไม่พบรายการที่ค้นหา',
            verticalNavigation: 'hybrid',
            elementAttributes: { placeholder: 'พิมพ์เพื่อค้นหา หรือเลือกจากรายการ' }
        };
        var k;
        for (k in extra) {
            if (extra.hasOwnProperty(k)) { p[k] = extra[k]; }
        }
        return p;
    }

    /* ---------- แปลงตารางค้นชื่อ ให้เป็นรายการตัวเลือกของ editor ----------
     * ต้องส่งเป็น array ของ {label, value} ไม่ใช่ object ธรรมดา
     * เพราะ key ของ object ใน JavaScript เป็นข้อความเสมอ พอเทียบกับค่าในช่อง
     * ที่เป็นตัวเลข (company_id) จะไม่ตรงกัน รายการที่เลือกอยู่เลยไม่ถูกไฮไลต์
     */
    /* ============================================================
     *  กันกรอบที่เลือกไว้ค้างชี้ไปยังช่องที่หายไปแล้ว
     *
     *  Tabulator จำพื้นที่ที่เลือกเป็นตำแหน่ง "แถวที่ N คอลัมน์ที่ M"
     *  พอจอแคบลงแล้วคอลัมน์ถูกยุบเก็บ หรือพิมพ์ค้นหาแล้วแถวหายไป
     *  ตำแหน่งเดิมจะไม่มีอยู่จริง ตอนวาดกรอบใหม่จึงพัง
     *  (TypeError: Cannot read properties of undefined อ่าน getElement)
     *  แล้วตารางค้างไม่วาดต่อ
     *
     *  จึงต้องล้างกรอบทิ้งทุกครั้งที่ชุดแถวหรือชุดคอลัมน์เปลี่ยน
     *  resetRanges() จะย้ายกรอบกลับไปช่องแรกที่มองเห็นได้ให้เอง
     * ============================================================ */
    function guardRanges(table) {

        /* Tabulator ต้องมีกรอบอยู่เสมออย่างน้อยหนึ่งอัน จะไม่มีเลยไม่ได้
           แต่ตอนที่ผู้ใช้ยังไม่ได้แตะอะไร ไม่ควรเห็นกรอบนั้น
           จึงซ่อนด้วย class แล้วค่อยเปิดตอนผู้ใช้ลงมือเลือกจริง */
        function sleep()  { table.element.classList.add('ranges-idle'); }
        function wake()   { table.element.classList.remove('ranges-idle'); }

        sleep();
        table.element.addEventListener('mousedown', wake);
        table.element.addEventListener('keydown', wake);

        function reset() {
            var mod = table.modules ? table.modules.selectRange : null;
            if (!mod || typeof mod.resetRanges !== 'function') { return; }
            try { mod.resetRanges(); } catch (e) { /* ตารางยังสร้างไม่เสร็จ ข้ามไป */ }

            /* ล้างเพราะชุดข้อมูลเปลี่ยน ไม่ใช่เพราะผู้ใช้เลือกใหม่ จึงซ่อนกรอบกลับไป */
            sleep();
        }

        table.on('columnVisibilityChanged', reset);   // จอแคบลง คอลัมน์ถูกยุบเก็บ
        table.on('dataFiltered', reset);              // พิมพ์ค้นหา
        table.on('pageLoaded', reset);                // เปลี่ยนหน้า
        table.on('dataSorted', reset);                // เรียงลำดับใหม่
    }

    /* ============================================================
     *  ตรวจข้อมูลในตารางก่อนบันทึก — ชี้ถึงช่องที่ผิด
     *
     *  ของเดิมทุกหน้าตรวจได้แค่ระดับ "มีแถวที่ยังไม่ได้กรอกชื่อ" แล้วขึ้นข้อความรวม
     *  ผู้ใช้ที่แก้ไป 20 แถวต้องไล่หาเองว่าแถวไหน ซึ่งเป็นงานที่คอมพิวเตอร์ควรทำให้
     *
     *  ตรงนี้จึงรับ "กฎรายคอลัมน์" แล้วคืนรายการปัญหาพร้อมตัวช่อง
     *  หน้าเว็บเอาไปทำเครื่องหมายแดง ชี้เมาส์ดูเหตุผล และเลื่อนไปที่ช่องแรกที่ผิดได้
     *
     *  กฎเขียนแบบ  { ชื่อฟิลด์: function (value, rowData) { return '' หรือ 'ข้อความผิด'; } }
     *  คืน '' = ผ่าน  คืนข้อความ = ไม่ผ่าน
     *
     *  ตรวจเฉพาะแถวที่ยังไม่บันทึก (_state = new / edited) เพราะแถวที่ไม่ได้แตะ
     *  ถ้าข้อมูลเก่าไม่ผ่านกฎใหม่ ผู้ใช้จะบันทึกอะไรไม่ได้เลยทั้งที่ไม่ได้ทำอะไรผิด
     *
     *  กฎชุดนี้ต้องตรงกับที่ backend ตรวจเสมอ ที่นี่มีไว้ให้รู้ผลเร็วขึ้นเท่านั้น
     *  ไม่ได้แทนการตรวจฝั่งเซิร์ฟเวอร์ (ยิง API ตรงยังต้องโดนปฏิเสธเหมือนเดิม)
     * ============================================================ */
    function validate(table, rules) {
        var problems = [];
        if (!table) { return problems; }

        var rows = table.getRows();
        for (var i = 0; i < rows.length; i++) {
            var data = rows[i].getData();
            if (data._state !== 'new' && data._state !== 'edited') { continue; }

            for (var field in rules) {
                if (!rules.hasOwnProperty(field)) { continue; }

                var msg = rules[field](data[field], data);
                if (msg) {
                    problems.push({
                        row:     rows[i],
                        field:   field,
                        message: msg,
                        line:    rows[i].getPosition(true)
                    });
                }
            }
        }
        return problems;
    }

    /* ทำเครื่องหมายช่องที่ผิด แล้วพาไปที่ช่องแรก */
    function markInvalid(table, problems) {
        clearInvalid(table);
        if (!problems || problems.length === 0) { return; }

        for (var i = 0; i < problems.length; i++) {
            var cell = problems[i].row.getCell(problems[i].field);
            if (!cell) { continue; }

            var node = cell.getElement();
            if (!node) { continue; }
            node.classList.add('cell-invalid');
            node.setAttribute('title', problems[i].message);
        }

        /* พาไปที่ปัญหาแรก — แถวอาจอยู่คนละหน้าของตาราง scrollToRow จัดการให้เอง
           ห่อ try ไว้เพราะถ้าแถวถูกกรองออกอยู่ Tabulator จะโยน error */
        try { table.scrollToRow(problems[0].row, 'center', false); } catch (e) {}
    }

    function clearInvalid(table) {
        if (!table) { return; }
        var nodes = table.element.querySelectorAll('.cell-invalid');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].classList.remove('cell-invalid');
            nodes[i].removeAttribute('title');
        }
    }

    /* สรุปปัญหาเป็นข้อความเดียว โดยบอกเลขแถวให้ไปดูได้ถูกที่
       รวมข้อความซ้ำเข้าด้วยกัน ไม่งั้น 20 แถวที่ผิดเรื่องเดียวกันจะยาวเป็นหางว่าว */
    function invalidSummary(problems, limit) {
        if (!problems || problems.length === 0) { return ''; }
        limit = limit || 3;

        var seen = {};
        var lines = [];
        for (var i = 0; i < problems.length; i++) {
            var m = problems[i].message;
            if (!seen[m]) { seen[m] = []; }
            seen[m].push(problems[i].line);
        }

        for (var msg in seen) {
            if (!seen.hasOwnProperty(msg)) { continue; }
            var at = seen[msg];
            var where = (at.length > limit)
                        ? ('แถวที่ ' + at.slice(0, limit).join(', ') + ' และอีก ' + (at.length - limit) + ' แถว')
                        : ('แถวที่ ' + at.join(', '));
            lines.push(where + ': ' + msg);
        }

        if (lines.length > limit) {
            var extra = lines.length - limit;
            lines = lines.slice(0, limit);
            lines.push('และอีก ' + extra + ' เรื่อง');
        }
        return lines.join(' · ');
    }


    function pickerValues(map, numeric) {
        var out = [], key;
        for (key in map) {
            if (!map.hasOwnProperty(key)) { continue; }
            out.push({ label: map[key], value: numeric ? Number(key) : key });
        }
        return out;
    }

    /* ---------- ดึงกล่องลอยของตารางกลับเข้าจอ ----------
     * Tabulator วางกล่องรายการ/เมนูไว้ที่ body โดยคำนวณจากช่องที่กด
     * ถ้ากล่องกว้างเกินจะพลิกไปเอาขอบขวาของกล่องชนขอบซ้ายของช่อง ทำให้หลุดออกซ้าย
     * ตัวนี้บีบความกว้างแล้วดึงกลับเข้าขอบจอ
     * (จอแคบมี CSS !important คุมอีกชั้น เพราะ Tabulator เขียนตำแหน่งทับทุกครั้งที่เปิด) */

    var POPUP_SEL = '.tabulator-edit-list, .tabulator-menu, .tabulator-popup';
    var EDGE = 8;

    function fitPopup(el) {
        if (!el || !el.getBoundingClientRect) { return; }

        var vw = window.innerWidth;
        el.style.maxWidth = (vw - EDGE * 2) + 'px';     /* บีบก่อนวัด */

        var r = el.getBoundingClientRect();
        if (r.width <= 0) { return; }

        if (r.left < EDGE) {
            el.style.right = 'auto';
            el.style.left  = EDGE + 'px';
        } else if (r.right > vw - EDGE) {
            el.style.left  = 'auto';
            el.style.right = EDGE + 'px';
        }

        /* กันยาวเกินจนล้นก้นจอแล้วเลื่อนไปดูรายการล่าง ๆ ไม่ได้ */
        var r2 = el.getBoundingClientRect();
        var room = window.innerHeight - r2.top - EDGE;
        if (room > 80 && r2.height > room) {
            el.style.maxHeight = room + 'px';
            el.style.overflowY = 'auto';
        }
    }

    function watchPopups() {
        if (!window.MutationObserver) { return; }

        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1 || !n.matches) { continue; }
                    if (n.matches(POPUP_SEL)) {
                        /* รอให้ Tabulator วางตำแหน่งเสร็จก่อน ไม่งั้นโดนเขียนทับ */
                        (function (el) {
                            requestAnimationFrame(function () { fitPopup(el); });
                        }(n));
                    }
                }
            }
        }).observe(document.body, { childList: true });
    }

    /* ============================================================
     *  โครงร่างจาง ๆ ระหว่างรอข้อมูลตารางรอบแรก
     * ============================================================
     *  กล่องตารางเป็นพื้นที่ว่างเปล่าตั้งแต่หน้าโหลดเสร็จจนกว่าข้อมูลจะมาถึง
     *  ซึ่งบนเน็ตช้ากินเวลาหลายวินาที ระหว่างนั้นผู้ใช้แยกไม่ออกว่า
     *  "กำลังโหลดอยู่" หรือ "ไม่มีข้อมูลเลย" แล้วมักกดรีเฟรชซ้ำ
     *
     *  ไม่ต้องมีโค้ดเก็บกวาด เพราะ Tabulator ล้างของเดิมในกล่องทิ้งตอนสร้างตาราง
     *  (ตรวจกับ v6.3.1 ที่โปรเจกต์ใช้อยู่แล้ว) โครงร่างจึงหายไปเองพอดีตอนตารางโผล่
     *
     *  หน้าตาอยู่ที่ .grid-skeleton ใน assets/css/app.css วาดด้วย CSS ล้วน
     *  ไม่มีรูปและไม่มีตัวอักษร จึงไม่ต้องแปลและไม่มีอะไรให้โหลดเพิ่ม
     * ============================================================ */
    var SKELETON_ROWS = 8;

    /* กันค้าง: ถ้าตารางสร้างไม่สำเร็จ (สคริปต์พัง / API ล่ม) โครงร่างจะวิ่งอยู่ตลอด
       ซึ่งแปลว่า "ยังทำงานอยู่" ทั้งที่ไม่มีอะไรทำแล้ว แย่กว่ากล่องว่าง ๆ
       ครบเวลาแล้วยังอยู่ที่เดิมก็เอาออก ปล่อยให้เห็นตามจริงว่าไม่มีอะไร */
    var SKELETON_GIVE_UP_MS = 20000;

    function paintSkeleton() {
        var box = document.getElementById('grid');
        if (!box || box.children.length > 0) { return; }

        var html = '<div class="grid-skeleton" aria-hidden="true">'
                 +   '<div class="grid-skeleton-head"></div>';

        for (var i = 0; i < SKELETON_ROWS; i++) {
            html += '<div class="grid-skeleton-row"></div>';
        }

        box.innerHTML = html + '</div>';

        window.setTimeout(function () {
            var left = box.querySelector('.grid-skeleton');
            if (left) { box.removeChild(left); }
        }, SKELETON_GIVE_UP_MS);
    }


    function onReady() {
        watchPopups();
        paintSkeleton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }


    /* ---------- ปรับจำนวนปุ่มเลขหน้าเมื่อความกว้างจอเปลี่ยน ----------
     * หมุนมือถือหรือย่อ/ขยายหน้าต่างแล้วปุ่มต้องเปลี่ยนตามทันที
     * ไม่ใช่ต้องโหลดหน้าใหม่ถึงจะได้จำนวนที่ถูกต้อง
     *
     * Tabulator อ่าน paginationButtonCount ครั้งเดียวตอนสร้างตาราง แล้วคัดลอก
     * ไปเก็บไว้ในโมดูล page ของตัวเอง การแก้ค่าใน options เฉย ๆ จึงไม่มีผล
     * กับตารางที่วาดไปแล้ว ต้องแก้ที่โมดูลด้วยแล้วสั่งวาดแถบล่างใหม่
     *
     * แก้ทั้งสองที่เพราะคนละหน้าที่กัน: ในโมดูลคือค่าที่ใช้วาดตอนนี้
     * ส่วนใน options คือค่าที่ตารางจะอ่านถ้ามีอะไรสั่งตั้งต้นโมดูลใหม่
     */
    function syncPageButtons() {
        if (!window.Tabulator || !window.Tabulator.findTable) { return; }

        var want   = pageButtonCount();
        var tables = window.Tabulator.findTable('.tabulator') || [];

        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            var page  = table.modules ? table.modules.page : null;

            if (!page || page.count === want) { continue; }

            /* ตารางที่ปิดการแบ่งหน้าไว้ (เช่นตารางกรอกของในหน้าทำเอกสาร)
               ไม่มีแถบล่างให้ปรับ และสั่ง setPage ใส่จะกลายเป็น error เปล่า ๆ */
            if (!table.options.pagination) { continue; }

            var now = table.getPage();
            if (!now) { continue; }

            page.count = want;
            table.options.paginationButtonCount = want;

            /* สั่งไปหน้าเดิมเพื่อให้วาดแถบแบ่งหน้าใหม่ ไม่ได้เปลี่ยนหน้าที่ดูอยู่
               รับ error ทิ้งไว้ด้วย เพราะเป็น Promise และเรื่องนี้ล้มเหลวได้
               โดยไม่กระทบอะไร (อย่างมากคือปุ่มยังเป็นชุดเดิมจนกดเปลี่ยนหน้า) */
            var done = table.setPage(now);
            if (done && done.then) { done.then(null, function () {}); }
        }
    }

    var resizeTimer = null;

    window.addEventListener('resize', function () {
        /* หน่วงไว้ก่อน การลากขอบหน้าต่างยิง resize รัวมาก
           ถ้าวาดแถบล่างใหม่ทุกครั้งจะกระตุกเห็นได้ชัด */
        if (resizeTimer) { window.clearTimeout(resizeTimer); }
        resizeTimer = window.setTimeout(syncPageButtons, 150);
    });


    window.GridCommon = {
        options:          options,
        fitPopup:         fitPopup,
        guardRanges:      guardRanges,
        enableFillHandle: enableFillHandle,
        pickerValues:     pickerValues,

        validate:        validate,
        markInvalid:     markInvalid,
        clearInvalid:    clearInvalid,
        invalidSummary:  invalidSummary,

        filterText:   filterText,
        filterPick:   filterPick,
        filterOwn:    filterOwn,
        filterMin:    filterMin,
        filterStatus: filterStatus,
        enableFilterToggles: enableFilterToggles,
        langs:        langs,
        control:      CONTROL,
        labelled:     labelled,
        col:          col,
        pickerParams: pickerParams
    };
})();
