/* ============================================================
 *  agencies.js — ตารางตั้งค่าหน่วยงานแบบแก้ในช่องได้ (Tabulator)
 *
 *  1) ค้นหาทันทีที่พิมพ์ — กรองในเครื่อง ไม่วิ่งกลับเซิร์ฟเวอร์
 *  2) คลิกที่ช่องแล้วแก้ได้เลย  สถานะกดสวิตช์เปิด/ปิด
 *  3) เพิ่มทีละหลายแถว หรือวางจาก Excel ทั้งก้อน
 *  4) แก้สะสมไว้ก่อน แล้วกด "บันทึก" ส่งทั้งชุดครั้งเดียวไปที่ api/agencies.php
 *
 *  สถานะของแต่ละแถว เก็บไว้ใน _state
 *      clean   ยังไม่ถูกแตะ
 *      new     แถวที่เพิ่งเพิ่ม ยังไม่มีในฐานข้อมูล
 *      edited  แถวเดิมที่ถูกแก้
 *  ตราบใดที่ยังไม่กดบันทึก ฐานข้อมูลยังไม่ถูกแตะเลย ยกเลิกได้ตลอด
 *
 *  หมายเหตุ: ตารางนี้ไม่มีปุ่มลบข้อมูลที่บันทึกแล้ว
 *  มีแต่ปุ่มเอาแถวใหม่ที่เพิ่งเพิ่มออก (แถวที่ยังไม่เคยลงฐานข้อมูล)
 *  เพราะถ้าไม่มีเลย ผู้ใช้กดเพิ่มแถวเกินมาแล้วจะเอาออกไม่ได้
 *  ต้องกดยกเลิกการแก้ไขทั้งหมดทิ้ง ซึ่งเสียงานที่พิมพ์ไว้
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.AGENCIES;
    if (!CFG || typeof Tabulator === 'undefined') { return; }

    var isCompany = (CFG.tab === 'company');
    var idField   = isCompany ? 'company_id' : 'department_id';
    var nameField = isCompany ? 'company_name' : 'department_name';
    var nameLabel = isCompany ? 'ชื่อบริษัท' : 'ชื่อแผนก';

    var table    = null;
    var newSeq   = 0;
    var original = {};   // เก็บค่าเดิมไว้เทียบ เผื่อผู้ใช้แก้แล้วแก้กลับเป็นค่าเดิม

    /* ตอนเราสั่งโหลดหน้าใหม่เอง ไม่ต้องเตือนว่ามีงานค้าง
       ต้องใช้ตัวแปรนี้ เพราะ handler ที่ผูกด้วย addEventListener
       ลบไม่ได้ด้วยการเซ็ต window.onbeforeunload = null */
    var skipUnloadWarning = false;

    function reloadPage() {
        skipUnloadWarning = true;
        window.location.reload();
    }

    /* ---------- ตัวช่วยเล็ก ๆ ---------- */
    function setHidden(el, hide) {
        if (!el) { return; }
        if (hide) { el.setAttribute('hidden', ''); } else { el.removeAttribute('hidden'); }
    }

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ---------- เตรียมข้อมูลตั้งต้น ---------- */
    function buildRows() {
        var src = isCompany ? CFG.companies : CFG.departments;
        var rows = [];

        for (var i = 0; i < src.length; i++) {
            var r = src[i];
            var row = {
                _key:   'r' + r[idField],
                _state: 'clean',
                is_active: Number(r.is_active)
            };
            row[idField]   = Number(r[idField]);
            row[nameField] = r[nameField];

            if (isCompany) {
                row.logo_file = r.logo_file || '';
                row.form_code = r.form_code || '';
            }
            if (!isCompany) { row.company_id = Number(r.company_id); }

            rows.push(row);
            original[row._key] = snapshotOf(row);
        }
        return rows;
    }

    /* ค่าที่ใช้เทียบว่าแถวถูกแก้หรือยัง — key ต้องเป็นชื่อ field จริงของคอลัมน์
       เพราะ applyRowClass เอาไปไล่เทียบทีละช่องเพื่อระบายเฉพาะช่องที่เปลี่ยน */
    function snapshotOf(row) {
        var s = { is_active: Number(row.is_active) };
        s[nameField] = row[nameField];

        /* รหัสแบบฟอร์มนับเป็นค่าที่ต้องกดบันทึก ต่างจาก logo_file ที่บันทึกทันทีตอนอัปโหลด
           ถ้าไม่นับ แก้แล้วปุ่มบันทึกจะไม่เปิด แล้วสิ่งที่พิมพ์ไปจะหายตอนออกจากหน้า */
        if (isCompany)  { s.form_code = String(row.form_code || ''); }
        if (!isCompany) { s.company_id = Number(row.company_id); }
        return s;
    }

    function snapshot(row) {
        return JSON.stringify(snapshotOf(row));
    }

    /* ตัวเลือกบริษัทสำหรับช่องเลือกต้นสังกัด */
    function companyLookup() {
        var map = {};
        for (var i = 0; i < CFG.companyOptions.length; i++) {
            map[CFG.companyOptions[i].company_id] = CFG.companyOptions[i].company_name;
        }
        return map;
    }

    /* ============================================================
     *  โลโก้บริษัท — อัปโหลดทันทีที่เลือกไฟล์
     * ============================================================ */

    /* ช่องเลือกไฟล์ตัวเดียวใช้ร่วมกันทุกแถว สร้างครั้งเดียวตอนใช้ครั้งแรก
       ถ้าสร้างช่องไว้ทุกแถว ตารางสิบกว่าบริษัทจะมี input ค้างอยู่สิบกว่าตัวเปล่า ๆ */
    var logoInput = null;
    var logoRow   = null;

    function ensureLogoInput() {
        if (logoInput) { return logoInput; }

        logoInput = document.createElement('input');
        logoInput.type   = 'file';
        logoInput.accept = 'image/png,image/jpeg,image/gif';
        logoInput.hidden = true;
        document.body.appendChild(logoInput);

        logoInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0 && logoRow) {
                uploadLogo(logoRow, this.files[0]);
            }
            /* ล้างค่าไว้เสมอ ไม่งั้นเลือกไฟล์เดิมซ้ำจะไม่เกิด event change อีก */
            this.value = '';
        });

        return logoInput;
    }

    function pickLogo(row) {
        logoRow = row;
        ensureLogoInput().click();
    }

    function uploadLogo(row, file) {
        var data = row.getData();

        /* ตรวจขนาดที่ฝั่งนี้ก่อนยิง ไฟล์ 10 MB ที่จะโดนปฏิเสธอยู่ดี
           ไม่ควรถูกอัปโหลดขึ้นไปให้เสียเวลาและแบนด์วิดท์ก่อน
           (ฝั่งเซิร์ฟเวอร์ตรวจซ้ำอยู่แล้ว ตรงนี้แค่ช่วยให้รู้เร็วขึ้น) */
        if (file.size > 2 * 1024 * 1024) {
            if (window.appError) { window.appError('ไฟล์ใหญ่เกิน 2 MB กรุณาย่อรูปก่อนอัปโหลด'); }
            return;
        }

        var form = new FormData();
        form.append('action', 'upload');
        form.append('company_id', data.company_id);
        form.append('csrf_token', CFG.csrfToken);
        form.append('logo', file);

        if (window.appLoading) { window.appLoading('กำลังอัปโหลด…'); }

        fetch('api/company_logo.php', {
            method: 'POST', credentials: 'same-origin', body: form
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              if (window.appClose) { window.appClose(); }

              if (!res || !res.ok) {
                  if (window.appError) { window.appError(res && res.message ? res.message : 'อัปโหลดไม่สำเร็จ'); }
                  return;
              }

              /* อัปเดตเฉพาะช่องโลโก้ ไม่แตะสถานะแถว เพราะรูปถูกบันทึกลงฐานข้อมูล
                 ไปแล้วจริง ๆ ถ้าไปทำเครื่องหมายว่า "แก้แล้วยังไม่บันทึก"
                 ผู้ใช้จะเข้าใจผิดว่าต้องกดบันทึกอีกครั้ง

                 และไม่แตะ original[] ด้วย เพราะ snapshotOf() ไม่ได้นับ logo_file
                 เป็นค่าที่ต้องกดบันทึก ถ้าเผลอยัดคีย์นี้เข้าไปในค่าเดิม
                 การเทียบจะไม่มีวันตรงกันอีก แล้วแถวจะค้างสถานะ "แก้แล้ว" ไม่หาย */
              row.update({ logo_file: res.logoFile });

              if (window.renderIcons) { window.renderIcons(); }
              if (window.appSuccess) { window.appSuccess(res.message); }
          })['catch'](function () {
              if (window.appClose) { window.appClose(); }
              if (window.appError) { window.appError('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); }
          });
    }

    function removeLogo(row) {
        var data = row.getData();

        var go = function () {
            var form = new FormData();
            form.append('action', 'remove');
            form.append('company_id', data.company_id);
            form.append('csrf_token', CFG.csrfToken);

            fetch('api/company_logo.php', {
                method: 'POST', credentials: 'same-origin', body: form
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  if (!res || !res.ok) {
                      if (window.appError) { window.appError(res && res.message ? res.message : 'ลบไม่สำเร็จ'); }
                      return;
                  }
                  row.update({ logo_file: '' });
                  if (window.renderIcons) { window.renderIcons(); }
                  if (window.appSuccess) { window.appSuccess(res.message); }
              })['catch'](function () {
                  if (window.appError) { window.appError('เชื่อมต่อไม่ได้ กรุณาลองใหม่'); }
              });
        };

        if (typeof window.appConfirmDanger === 'function') {
            window.appConfirmDanger({
                title: 'ลบโลโก้',
                text: 'เอาโลโก้ของ "' + (data.company_name || '') + '" ออก? '
                    + 'เอกสารที่พิมพ์หลังจากนี้จะไม่มีหัวกระดาษ',
                confirmText: 'ลบโลโก้'
            }).then(function (ok) { if (ok) { go(); } });
        } else if (window.confirm('ลบโลโก้?')) {
            go();
        }
    }


    /* ---------- คำนวณสถานะแถวใหม่หลังผู้ใช้แก้ค่า ---------- */
    function markChanged(row) {
        var data = row.getData();
        if (data._state === 'new') { return; }

        /* แก้แล้วแก้กลับเป็นค่าเดิม ไม่ต้องนับว่าเปลี่ยน */
        data._state = (snapshot(data) === JSON.stringify(original[data._key])) ? 'clean' : 'edited';
        applyRowClass(row);
    }

    function applyRowClass(row) {
        var elRow = row.getElement();
        var data  = row.getData();
        var st    = data._state;

        /* แถวใหม่ระบายทั้งแถว เพราะทั้งแถวคือของใหม่จริง ๆ */
        elRow.classList.remove('row-new');
        if (st === 'new') { elRow.classList.add('row-new'); }

        /* ส่วนแถวที่แก้ ระบายเฉพาะช่องที่ค่าเปลี่ยน จะได้เห็นทันทีว่าแก้คอลัมน์ไหนไว้
           ถ้าระบายทั้งแถวจะรู้แค่ว่าแถวนี้ถูกแตะ แต่ต้องไล่หาเองว่าแตะตรงไหน */
        var was   = (st === 'edited') ? original[data._key] : null;
        var now   = was ? snapshotOf(data) : null;
        var cells = row.getCells();

        for (var i = 0; i < cells.length; i++) {
            var el = cells[i].getElement();
            if (!el) { continue; }

            var f = cells[i].getField();
            var changed = !!(was && f && was.hasOwnProperty(f) && String(now[f]) !== String(was[f]));

            if (changed) { el.classList.add('cell-edited'); } else { el.classList.remove('cell-edited'); }
        }
    }

    /* ---------- คอลัมน์ ---------- */
    function buildColumns() {
        var cols = [];
        var G    = window.GridCommon;

        /* ปุ่มกางคอลัมน์ที่ถูกยุบเก็บตอนจอแคบ */
        cols.push(G.col(G.control, {
            formatter: 'responsiveCollapse', width: 36, minWidth: 36,
            hozAlign: 'center', resizable: false, headerSort: false,
            /* ปุ่มนี้ต้องอยู่เสมอ ถ้าถูกยุบไปด้วยจะไม่มีทางเปิดดูคอลัมน์ที่ยุบไว้ */
            responsive: 0
        }));

        cols.push(G.col(G.control, {
            title: '#', field: '_seq', width: 60, hozAlign: 'right', headerSort: false,
            resizable: false, cssClass: 'cell-seq', responsive: 6,
            formatter: function (cell) {
                if (cell.getRow().getData()._state === 'new') {
                    return '<span class="grid-tag grid-tag-new">ใหม่</span>';
                }
                return cell.getRow().getPosition(true);
            }
        }));

        cols.push({
            title: nameLabel, field: nameField, editor: 'input', minWidth: 200,
            responsive: 0, download: true, clipboard: true,
            headerFilter: 'input', headerFilterPlaceholder: 'ค้นชื่อ', headerFilterFunc: 'like',
            formatter: function (cell) {
                var v = cell.getValue();
                if (v === null || v === undefined || String(v).trim() === '') {
                    return '<span class="grid-empty">— ยังไม่ได้กรอก —</span>';
                }
                return escapeHtml(v);
            }
        });

        /* ---------- โลโก้บริษัท ----------
         * ใช้เป็นหัวกระดาษบนใบเบิกที่พิมพ์ออกมา จึงต้องตั้งได้ที่นี่
         *
         * ไม่ใช้ระบบ "แก้แล้วค่อยกดบันทึกทีเดียว" เหมือนคอลัมน์อื่นในตารางนี้
         * เพราะไฟล์ต้องส่งแบบ multipart ซึ่งรวมไปกับ JSON ก้อนเดียวไม่ได้
         * กดแล้วอัปโหลดทันทีเลย และแถบ "ยังไม่บันทึก" จะไม่ขึ้นสำหรับช่องนี้
         */
        if (isCompany) {
            /* ---------- รหัสแบบฟอร์ม ----------
             * ข้อความมุมขวาล่างของเอกสารที่พิมพ์ เช่น FM-AD-01 : Rev 00 : 01/10/64
             * แต่ละบริษัทมีรหัสและรอบแก้ไขของตัวเอง ตามระบบเอกสารควบคุมขององค์กร */
            cols.push({
                title: 'รหัสแบบฟอร์ม', field: 'form_code', editor: 'input',
                minWidth: 200, responsive: 4, download: true, clipboard: true,
                headerFilter: 'input', headerFilterPlaceholder: 'ค้นรหัส', headerFilterFunc: 'like',
                formatter: function (cell) {
                    var v = cell.getValue();
                    if (v === null || v === undefined || String(v).trim() === '') {
                        return '<span class="grid-empty">— ไม่มี —</span>';
                    }
                    return '<span class="rc-code">' + escapeHtml(v) + '</span>';
                }
            });

            cols.push(G.col(G.control, {
                title: 'โลโก้', field: 'logo_file', width: 130, hozAlign: 'center',
                headerSort: false, responsive: 3, download: false,
                /* ---------- ช่องโลโก้เป็น "ไทล์" ไม่ใช่ปุ่มเรียงกัน ----------
                 * แบบเดิมวางรูปกับปุ่มสองปุ่มต่อกันในแนวนอน กินความกว้างมาก
                 * และตาต้องอ่านทีละชิ้นว่าอันไหนคืออะไร
                 *
                 * แบบใหม่ยุบเหลือกล่องเดียวขนาดเท่ากันทุกแถว รูปอยู่กลางกล่อง
                 * ปุ่มจัดการซ่อนไว้ก่อน โผล่มาเป็นชั้นทับตอนเอาเมาส์ชี้
                 * ได้ตารางที่แถวเรียงกันเป็นระเบียบ และยังกดใช้งานได้ครบเหมือนเดิม
                 *
                 * บนจอสัมผัสที่ไม่มี hover ปุ่มจะแสดงตลอด (ดู @media (hover: none))
                 */
                formatter: function (cell) {
                    var file  = cell.getValue();
                    var isNew = (cell.getRow().getData()._state === 'new');

                    if (isNew) {
                        return '<span class="logo-tile is-locked" title="บันทึกบริษัทก่อน จึงจะใส่โลโก้ได้">'
                             + '<i data-lucide="lock" class="h-4 w-4"></i></span>';
                    }

                    if (!file) {
                        return '<button type="button" class="logo-tile is-empty" data-logo="pick"'
                             + ' title="กดเพื่ออัปโหลดโลโก้">'
                             + '<i data-lucide="image-plus" class="h-[18px] w-[18px]"></i>'
                             + '<span class="logo-tile-cap">เพิ่มรูป</span>'
                             + '</button>';
                    }

                    return '<span class="logo-tile has-img">'
                         + '<img src="assets/img/company/' + encodeURIComponent(file) + '" alt="">'
                         + '<span class="logo-acts">'
                         + '<button type="button" data-logo="pick" title="เปลี่ยนรูป" aria-label="เปลี่ยนรูป">'
                         + '<i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>'
                         + '<button type="button" data-logo="del" class="is-del" title="ลบโลโก้" aria-label="ลบโลโก้">'
                         + '<i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button>'
                         + '</span>'
                         + '</span>';
                },
                cellClick: function (e, cell) {
                    var row = cell.getRow();
                    if (row.getData()._state === 'new') { return; }

                    /* อ่านเจตนาจาก data-logo ไม่ใช่จากชื่อคลาสของปุ่ม
                       คลาสมีไว้จัดหน้าตา ถ้าวันหลังเปลี่ยนดีไซน์แล้วคลาสเปลี่ยนตาม
                       ปุ่มจะเงียบไปเฉย ๆ โดยไม่มีอะไรฟ้อง */
                    var hit = e.target.closest ? e.target.closest('[data-logo]') : null;
                    if (!hit) { return; }

                    if (hit.getAttribute('data-logo') === 'del') { removeLogo(row); }
                    else                                          { pickLogo(row); }
                }
            }));
        }

        if (!isCompany) {
            var lookup = companyLookup();

            /* ในไฟล์ Excel และตอนคัดลอก ต้องเป็นชื่อบริษัท ไม่ใช่เลข id ที่คนอ่านไม่รู้เรื่อง */
            var companyText = function (value) { return lookup[value] || ''; };

            cols.push(G.col(G.labelled(companyText), G.filterPick(lookup), {
                title: 'บริษัทต้นสังกัด', field: 'company_id', minWidth: 220,
                responsive: 2, download: true, clipboard: true,
                /* คลิกแล้วกางรายการบริษัททั้งหมด และพิมพ์เพื่อกรองได้ในช่องเดียวกัน */
                editor: 'list',
                editorParams: G.pickerParams(G.pickerValues(lookup, true)),
                formatter: function (cell) {
                    var name = lookup[cell.getValue()];
                    if (!name) { return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>'; }
                    return '<span class="grid-pill">' + escapeHtml(name) + '</span>';
                }
            }));
        }

        /* ---------- สถานะ : สวิตช์กดเปิด/ปิด ----------
         * ไม่ใช้ dropdown แล้ว กดที่สวิตช์สลับได้ทันทีในคลิกเดียว
         * ใส่ role="switch" + aria-checked เพื่อให้โปรแกรมอ่านหน้าจอรู้สถานะจริง
         */
        cols.push(G.col(G.labelled(function (value) {
            return (Number(value) === 1) ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
        }), G.filterStatus(), {
            title: 'สถานะ', field: 'is_active', width: 160, hozAlign: 'center',
            headerSort: true, cssClass: 'cell-switch',
            responsive: 1, download: true, clipboard: true,
            formatter: function (cell) {
                var on = Number(cell.getValue()) === 1;
                return '<span class="grid-switch-wrap">'
                     + '<button type="button" role="switch" aria-checked="' + (on ? 'true' : 'false') + '"'
                     + ' class="grid-switch' + (on ? ' is-on' : '') + '"'
                     + ' title="' + (on ? 'กดเพื่อปิดใช้งาน' : 'กดเพื่อเปิดใช้งาน') + '">'
                     + '<span class="grid-switch-knob"></span></button>'
                     + '<span class="grid-switch-text">' + (on ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span>'
                     + '</span>';
            },
            cellClick: function (e, cell) {
                var row = cell.getRow();
                cell.setValue(Number(cell.getValue()) === 1 ? 0 : 1);
                markChanged(row);
                refreshPending();
            }
        }));

        /* ---------- เอาแถวใหม่ออก (เฉพาะแถวที่ยังไม่เคยบันทึก) ---------- */
        cols.push(G.col(G.control, {
            title: '', field: '_remove', width: 56, hozAlign: 'center', headerSort: false,
            resizable: false, responsive: 0,
            formatter: function (cell) {
                if (cell.getRow().getData()._state !== 'new') { return ''; }
                return '<button type="button" class="grid-remove" title="เอาแถวนี้ออก">&#10005;</button>';
            },
            cellClick: function (e, cell) {
                var row = cell.getRow();
                if (row.getData()._state !== 'new') { return; }
                row.delete();
                refreshPending();
            }
        }));

        return cols;
    }

    /* ---------- นับและแสดงจำนวนที่ยังไม่บันทึก ---------- */
    function collect() {
        var creates = [], updates = [];
        if (!table) { return { creates: creates, updates: updates }; }

        var rows = table.getData();
        for (var i = 0; i < rows.length; i++) {
            if (rows[i]._state === 'new')    { creates.push(rows[i]); }
            if (rows[i]._state === 'edited') { updates.push(rows[i]); }
        }
        return { creates: creates, updates: updates };
    }

    function refreshPending() {
        var c     = collect();
        var total = c.creates.length + c.updates.length;

        var btnSave   = el('btnSave');
        var btnRevert = el('btnRevert');
        var badge     = el('pendingCount');

        btnSave.disabled   = (total === 0);
        btnRevert.disabled = (total === 0);
        badge.textContent  = total;
        setHidden(badge, total === 0);

        var parts = [];
        if (c.creates.length) { parts.push('เพิ่มใหม่ ' + c.creates.length + ' แถว'); }
        if (c.updates.length) { parts.push('แก้ไข ' + c.updates.length + ' แถว'); }

        setHidden(el('pendingBar'), total === 0);
        if (total > 0) {
            el('pendingText').textContent = 'ยังไม่ได้บันทึก: ' + parts.join(' · ') + ' — กดปุ่มบันทึกเพื่อยืนยัน';
        }
    }

    /* ---------- แสดงผลการบันทึก ----------
     * สำเร็จ = toast มุมขวาบน หายเอง ไม่ขวางการทำงานต่อ
     * ผิดพลาด = กล่องกลางจอ ต้องกดรับทราบ เพราะมีเรื่องต้องแก้
     */
    function showResult(ok, text) {
        if (typeof window.appSuccess !== 'function') {   // เผื่อ swal โหลดไม่ขึ้น
            alert(text);
            return;
        }
        if (ok) { window.appSuccess(text); } else { window.appError(text); }
    }

    /* ============================================================
     *  สร้างตาราง
     * ============================================================ */
    function init() {

        table = new Tabulator('#grid', window.GridCommon.options({
            data: buildRows(),
            columns: buildColumns(),
            placeholder: 'ยังไม่มีข้อมูล กดปุ่ม "เพิ่มแถว" เพื่อเริ่มต้น',

            /* วางจาก Excel ได้ทั้งก้อน — แถวที่วางเข้ามาถือเป็นแถวใหม่
               ต่างจากหน้าผู้ใช้งานที่วางทับช่องที่เลือกไว้ เพราะหน้านี้ใช้เติมข้อมูล
               ตั้งต้นทีละมาก ๆ จากไฟล์ที่ฝ่ายบุคคลส่งมา */
            clipboardPasteAction: 'insert',

            rowFormatter: applyRowClass
        }));

        /* ---------- แก้ค่าในช่อง ---------- */
        window.GridCommon.guardRanges(table);
        window.GridCommon.enableFilterToggles(table);

        /* ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไปแบบ Excel */
        window.GridCommon.enableFillHandle(table, function (rows) {
            for (var i = 0; i < rows.length; i++) { markChanged(rows[i]); }
            refreshPending();
        }, {
            /* สถานะไม่มีช่องพิมพ์ แต่ลากเติมได้ ใช้ปิด/เปิดใช้งานทีเดียวหลายแถว */
            is_active: true
        });

        table.on('cellEdited', function (cell) {
            markChanged(cell.getRow());
            refreshPending();
        });

        /* ---------- วางข้อมูลจาก Excel ---------- */
        table.on('clipboardPasted', function () {
            var rows = table.getRows();
            for (var i = 0; i < rows.length; i++) {
                var d = rows[i].getData();
                if (!d._key) {
                    d._key   = 'n' + (++newSeq);
                    d._state = 'new';
                    if (d.is_active === undefined || d.is_active === '') { d.is_active = 1; }
                    applyRowClass(rows[i]);
                }
            }
            table.redraw(true);
            refreshPending();
        });

        table.on('tableBuilt', function () { refreshPending(); });

        /* ---------- วาดไอคอนใหม่ทุกครั้งที่ตารางวาดแถว ----------
         * formatter คืน <i data-lucide="..."> ออกมาเป็นข้อความ ไม่ใช่ไอคอนสำเร็จรูป
         * ต้องมีคนเรียก lucide มาแปลงเป็น <svg> ให้ ไม่งั้นจะเห็นเป็นช่องว่างเปล่า
         *
         * ต้องผูกกับ renderComplete ไม่ใช่เรียกครั้งเดียวตอนสร้างตาราง
         * เพราะ Tabulator วาดเฉพาะแถวที่อยู่ในจอ (virtual DOM) แถวที่เลื่อนเข้ามาทีหลัง
         * จะเป็น <i> เปล่า ๆ ที่ยังไม่ผ่านการแปลง และการเรียงลำดับหรือกรอง
         * ก็ทำให้ทุกแถววาดใหม่หมดเช่นกัน */
        table.on('renderComplete', function () {
            if (typeof window.renderIcons === 'function') { window.renderIcons(); }
            else if (window.lucide) { window.lucide.createIcons(); }
        });

        /* ---------- ค้นหาทันทีที่พิมพ์ ---------- */
        var search = el('gridSearch');
        var lookup = isCompany ? null : companyLookup();

        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();

            if (term === '') {
                table.clearFilter();
                return;
            }

            table.setFilter(function (data) {
                var hay = String(data[nameField] || '').toLowerCase();
                if (!isCompany) {
                    hay += ' ' + String(lookup[data.company_id] || '').toLowerCase();
                }
                return hay.indexOf(term) !== -1;
            });
        });

        /* ---------- เพิ่มทีละหลายแถว ---------- */
        el('btnAddRows').addEventListener('click', function () {
            var count = parseInt(el('addCount').value, 10);
            if (isNaN(count) || count < 1) { count = 1; }
            if (count > 50) { count = 50; }

            for (var i = 0; i < count; i++) {
                var blank = { _key: 'n' + (++newSeq), _state: 'new', is_active: 1 };
                blank[idField]   = 0;
                blank[nameField] = '';
                if (!isCompany) {
                    blank.company_id = CFG.companyOptions.length ? Number(CFG.companyOptions[0].company_id) : 0;
                }
                table.addRow(blank, true);
            }

            refreshPending();

            /* พาไปหน้าแรกแล้วเปิดช่องชื่อของแถวบนสุดให้พิมพ์ต่อได้ทันที */
            table.setPage(1).then(function () {
                var rows = table.getRows('active');
                if (rows.length) { rows[0].getCell(nameField).edit(); }
            });
        });

        /* ---------- ยกเลิกการแก้ไขทั้งหมด ---------- */
        el('btnRevert').addEventListener('click', function () {
            var c     = collect();
            var total = c.creates.length + c.updates.length;

            window.appConfirmDanger({
                title:       'ยกเลิกการแก้ไข',
                text:        'การแก้ไขที่ยังไม่ได้บันทึก ' + total + ' รายการจะหายไปทั้งหมด ต้องการดำเนินการต่อหรือไม่',
                confirmText: 'ยกเลิกการแก้ไข',
                cancelText:  'กลับไปแก้ต่อ'
            }).then(function (ok) {
                if (ok) { reloadPage(); }
            });
        });

        /* ---------- ส่งออก Excel ---------- */
        if (window.GridExport) {
            window.GridExport.attach({
                table:     table,
                button:    el('btnExport'),
                filename:  isCompany ? 'รายชื่อบริษัท' : 'รายชื่อแผนก',
                sheetName: isCompany ? 'บริษัท' : 'แผนก',
                pending:   function () {
                    var c = collect();
                    return c.creates.length + c.updates.length;
                }
            });
        }

        /* ---------- บันทึกทั้งชุด ---------- */
        el('btnSave').addEventListener('click', save);

        /* ---------- เตือนก่อนออกจากหน้าถ้ายังไม่บันทึก ---------- */
        window.addEventListener('beforeunload', function (e) {
            if (skipUnloadWarning) { return; }
            var c = collect();
            if (c.creates.length + c.updates.length > 0) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    }

    /* ============================================================
     *  ส่งไปบันทึก
     * ============================================================ */
    function save() {
        var btn = el('btnSave');
        var c   = collect();

        /* ตรวจฝั่งหน้าเว็บก่อน จะได้ไม่ต้องยิงไปให้เซิร์ฟเวอร์ปฏิเสธทีละแถว
           และชี้ได้ว่าผิดช่องไหน ไม่ใช่บอกแค่ว่า "มี 3 แถวที่ยังไม่ได้กรอก"
           กฎตรงกับ company_validate() / department_validate() ในชั้น backend */
        var G     = window.GridCommon;
        var rules = {};

        rules[nameField] = function (v) {
            var s = String(v || '').trim();
            if (s === '') { return 'กรุณากรอก' + nameLabel; }
            if (s.length > 200) { return nameLabel + 'ยาวเกิน 200 ตัวอักษร'; }

            /* ชื่อซ้ำในตารางเดียวกัน — ฐานข้อมูลไม่ได้บังคับ unique ไว้
               แต่ชื่อหน่วยงานซ้ำกันทำให้คนเลือกผิดตอนทำใบเบิก */
            var rows = table.getData(), same = 0;
            for (var i = 0; i < rows.length; i++) {
                if (String(rows[i][nameField] || '').trim() === s) { same++; }
            }
            if (same > 1) { return nameLabel + ' "' + s + '" ซ้ำกับอีกแถวในตารางนี้'; }
            return '';
        };

        /* แผนกต้องสังกัดบริษัท ไม่งั้นจะไปโผล่ในรายการเลือกของทุกบริษัท */
        if (CFG.tab === 'department') {
            rules.company_id = function (v) {
                return Number(v) > 0 ? '' : 'กรุณาเลือกบริษัทต้นสังกัด';
            };
        }

        var problems = G.validate(table, rules);
        if (problems.length > 0) {
            G.markInvalid(table, problems);
            showResult(false, G.invalidSummary(problems));
            return;
        }
        G.clearInvalid(table);

        btn.disabled = true;
        setHidden(btn.querySelector('.btn-icon'), true);
        setHidden(btn.querySelector('.btn-spinner'), false);

        var payload = {
            action: 'save',
            csrf_token: CFG.csrfToken,
            tab: CFG.tab,
            creates: c.creates,
            updates: c.updates,
            deletes: []
        };

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');

        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }

            if (!res) {
                finishSave(false, 'เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่');
                return;
            }
            if (!res.ok) {
                finishSave(false, res.message || 'บันทึกไม่สำเร็จ');
                return;
            }

            if (res.failed > 0) {
                var lines = [];
                for (var i = 0; i < res.errors.length && i < 3; i++) {
                    var e = res.errors[i];
                    lines.push((e.label ? '"' + e.label + '" ' : '') + e.message);
                }
                if (res.errors.length > 3) { lines.push('และอีก ' + (res.errors.length - 3) + ' รายการ'); }
                finishSave(false, 'บันทึกสำเร็จ ' + res.saved + ' รายการ ไม่สำเร็จ ' + res.failed + ' รายการ — ' + lines.join(' · '));
                return;
            }

            /* สำเร็จหมด โหลดหน้าใหม่เพื่อดึงข้อมูลล่าสุดพร้อม id ของแถวที่เพิ่งเพิ่ม */
            try { sessionStorage.setItem('agenciesSaved', 'บันทึกเรียบร้อย ' + res.saved + ' รายการ'); } catch (err) {}
            reloadPage();
        };

        xhr.onerror = function () {
            finishSave(false, 'ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่');
        };

        xhr.send(JSON.stringify(payload));
    }

    function finishSave(ok, message) {
        var btn = el('btnSave');
        setHidden(btn.querySelector('.btn-icon'), false);
        setHidden(btn.querySelector('.btn-spinner'), true);
        btn.disabled = false;
        showResult(ok, message);
        refreshPending();
    }

    /* ---------- ข้อความจากการบันทึกรอบที่แล้ว ---------- */
    function showSavedMessage() {
        var msg = null;
        try { msg = sessionStorage.getItem('agenciesSaved'); } catch (err) {}
        if (msg) {
            try { sessionStorage.removeItem('agenciesSaved'); } catch (err) {}
            showResult(true, msg);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); showSavedMessage(); });
    } else {
        init();
        showSavedMessage();
    }
})();
