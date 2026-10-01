/* ============================================================
 *  users.js — ตารางจัดการผู้ใช้งานแบบแก้ในช่องได้ (Tabulator)
 *
 *  โครงเดียวกับ agencies.js ต่างกันที่ชุดคอลัมน์และกฎเฉพาะของผู้ใช้:
 *
 *    - ชื่อผู้ใช้ (username) แก้ได้เฉพาะตอนสร้างใหม่
 *      เพราะเป็น primary key ที่ตาราง created_by / updated_by อ้างอยู่
 *      การเปลี่ยนภายหลังกระทบข้อมูลหลายที่ ถ้าจำเป็นต้องทำที่ฐานข้อมูลโดยตรง
 *
 *    - รหัสผ่าน : ไม่มีคอลัมน์ให้พิมพ์ในตารางแล้ว
 *      บัญชีใหม่ได้รหัสตั้งต้นจากระบบ แล้วเจ้าของบัญชีไปเปลี่ยนเองที่หน้า
 *      "เปลี่ยนรหัสผ่าน" ส่วนคนที่ลืมรหัส ผู้ดูแลกดปุ่ม "คืนค่าตั้งต้น" ให้ทีละคน
 *      (ช่องพิมพ์รหัสผ่านในตารางเคยถูกลากคัดลอกข้ามบัญชี ทำให้หลายคนได้รหัสเดียวกัน)
 *
 *    - บัญชีตัวเองมีเครื่องหมายกำกับ และปิดใช้งาน/เปลี่ยนสิทธิ์ตัวเองไม่ได้
 *      (ฝั่ง backend ปฏิเสธอยู่แล้ว ตรงนี้กันไว้ตั้งแต่หน้าจอเพื่อไม่ให้เสียเวลา)
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.USERS;
  if (!CFG || typeof Tabulator === "undefined") {
    return;
  }

  var table = null;
  var newSeq = 0;
  var original = {};
  var skipUnloadWarning = false;

  function reloadPage() {
    skipUnloadWarning = true;
    window.location.reload();
  }

  function setHidden(el, hide) {
    if (!el) {
      return;
    }
    if (hide) {
      el.setAttribute("hidden", "");
    } else {
      el.removeAttribute("hidden");
    }
  }

  function el(id) {
    return document.getElementById(id);
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* ---------- ตัวเลือกที่ใช้ในช่อง dropdown ---------- */
  function companyLookup() {
    var map = { 0: "— ไม่ระบุ —" };
    for (var i = 0; i < CFG.companyOptions.length; i++) {
      map[CFG.companyOptions[i].company_id] =
        CFG.companyOptions[i].company_name;
    }
    return map;
  }

  /* หน่วยงานส่งมาเป็น object (รหัส -> ชื่อ + ชื่อบริษัท) ต่างจากบริษัทที่เป็น list
       จึงคัดลอกทีละ key แทนที่จะวน index */
  function deptLookup() {
    var map = { 0: "— ไม่ระบุ —" };
    var src = CFG.deptOptions || {};
    var k;
    for (k in src) {
      if (src.hasOwnProperty(k)) {
        map[k] = src[k];
      }
    }
    return map;
  }

  function normalizeDeptIds(ids, fallback) {
    var out = [];
    ids = Array.isArray(ids) ? ids : Number(fallback) > 0 ? [fallback] : [];
    for (var i = 0; i < ids.length; i++) {
      var id = Number(ids[i]);
      if (id > 0 && out.indexOf(id) === -1) {
        out.push(id);
      }
    }
    return out;
  }

  /* popup เลือกหลายหน่วยงานพร้อมค้นหา
       ต้องวางไว้ที่ body เพราะ cell ของ Tabulator มี overflow ซ่อนอยู่ */
  function departmentEditor(cell, onRendered, success, cancel) {
    var current = normalizeDeptIds(cell.getValue());
    var src = CFG.deptOptions || {};
    var anchor = cell.getElement().getBoundingClientRect();
    var popup = document.createElement("div");
    var search = document.createElement("input");
    var list = document.createElement("div");
    var actions = document.createElement("div");
    var done = document.createElement("button");
    var abort = document.createElement("button");
    var hidden = document.createElement("input");
    var closed = false;

    popup.className = "user-dept-popup";
    search.className = "user-dept-search";
    search.type = "search";
    search.placeholder = "ค้นหาหน่วยงาน...";
    search.autocomplete = "off";
    list.className = "user-dept-popup-list";
    actions.className = "user-dept-popup-actions";
    done.type = "button";
    done.className = "user-dept-popup-done";
    done.textContent = "ตกลง";
    abort.type = "button";
    abort.className = "user-dept-popup-cancel";
    abort.textContent = "ยกเลิก";
    hidden.type = "text";
    hidden.style.display = "none";

    popup.style.left =
      Math.max(8, Math.min(anchor.left, window.innerWidth - 368)) + "px";
    popup.style.top =
      Math.min(anchor.bottom + 4, window.innerHeight - 360) + "px";

    function closePopup() {
      if (closed) {
        return;
      }
      closed = true;
      if (popup.parentNode) {
        popup.parentNode.removeChild(popup);
      }
      document.removeEventListener("mousedown", outsideClick, true);
    }

    function outsideClick(event) {
      if (!popup.contains(event.target) && event.target !== cell.getElement()) {
        closePopup();
        cancel();
      }
    }

    function drawList() {
      var term = String(search.value || "")
        .toLowerCase()
        .trim();
      var key;
      list.innerHTML = "";
      for (key in src) {
        if (!src.hasOwnProperty(key)) {
          continue;
        }
        if (
          term !== "" &&
          String(src[key]).toLowerCase().indexOf(term) === -1
        ) {
          continue;
        }
        var id = Number(key);
        var label = document.createElement("label");
        var input = document.createElement("input");
        input.type = "checkbox";
        input.value = id;
        input.checked = current.indexOf(id) !== -1;
        input.addEventListener("change", function (event) {
          var value = Number(event.target.value);
          if (event.target.checked && current.indexOf(value) === -1) {
            current.push(value);
          }
          if (!event.target.checked) {
            current = current.filter(function (item) {
              return item !== value;
            });
          }
        });
        label.appendChild(input);
        label.appendChild(document.createTextNode(" " + src[key]));
        list.appendChild(label);
      }
      if (!list.children.length) {
        list.innerHTML =
          '<div class="user-dept-popup-empty">ไม่พบหน่วยงาน</div>';
      }
    }

    done.addEventListener("click", function (event) {
      event.preventDefault();
      event.stopPropagation();
      closePopup();
      success(current);
    });
    abort.addEventListener("click", function (event) {
      event.preventDefault();
      event.stopPropagation();
      closePopup();
      cancel();
    });
    popup.addEventListener("mousedown", function (event) {
      event.stopPropagation();
    });
    search.addEventListener("input", drawList);

    actions.appendChild(abort);
    actions.appendChild(done);
    popup.appendChild(search);
    popup.appendChild(list);
    popup.appendChild(actions);
    document.body.appendChild(popup);
    document.addEventListener("mousedown", outsideClick, true);
    search.focus();
    drawList();
    onRendered(function () {
      search.focus();
    });
    return hidden;
  }

  /* ---------- เตรียมข้อมูลตั้งต้น ---------- */
  function buildRows() {
    var rows = [];
    for (var i = 0; i < CFG.users.length; i++) {
      var u = CFG.users[i];
      var row = {
        _key: "r" + u.username,
        _state: "clean",
        username: u.username,
        full_name: u.full_name,
        email: u.email,
        role: u.role,
        agency_id: Number(u.agency_id),
        dept_ids: normalizeDeptIds(u.dept_ids, u.dept_id),
        is_active: Number(u.is_active),
      };
      rows.push(row);
      original[row._key] = snapshotOf(row);
    }
    return rows;
  }

  /* ค่าที่ใช้เทียบว่าแถวถูกแก้หรือยัง — key ต้องเป็นชื่อ field จริงของคอลัมน์
       เพราะ applyRowClass เอาไปไล่เทียบทีละช่องเพื่อระบายเฉพาะช่องที่เปลี่ยน
       รหัสผ่านไม่อยู่ในตารางแล้ว จึงไม่มีอะไรต้องเทียบ */
  function snapshotOf(row) {
    return {
      full_name: row.full_name,
      email: row.email,
      role: row.role,
      agency_id: Number(row.agency_id),
      dept_ids: normalizeDeptIds(row.dept_ids),
      is_active: Number(row.is_active),
    };
  }

  function snapshot(row) {
    return JSON.stringify(snapshotOf(row));
  }

  function isSelf(row) {
    return row.username === CFG.me;
  }

  /* ---------- แถวของบัญชีสิทธิ์สูงสุด ----------
   * ล็อกทั้งแถว ไม่ว่าคนที่เปิดหน้านี้จะเป็นใคร รวมถึงเจ้าของบัญชีเอง
   * ฝั่ง backend ปฏิเสธอยู่แล้ว (ดู user_guard ใน backend/user.php)
   * ล็อกที่หน้าจอด้วยเพื่อไม่ให้พิมพ์แก้ไปทั้งตารางแล้วเพิ่งมารู้ตอนกดบันทึก
   */
  function isTopAdmin(row) {
    return row._state !== "new" && row.role === "super_admin";
  }

  /* ใช้เป็นค่า editable ของทุกคอลัมน์ที่แก้ได้ */
  function editableUnlessTop(cell) {
    return !isTopAdmin(cell.getRow().getData());
  }

  function roleOptionsHas(role) {
    return CFG.roleOptions && CFG.roleOptions.hasOwnProperty(role);
  }

  function markChanged(row) {
    var data = row.getData();
    if (data._state === "new") {
      return;
    }

    data._state =
      snapshot(data) === JSON.stringify(original[data._key])
        ? "clean"
        : "edited";
    applyRowClass(row);
  }

  function applyRowClass(row) {
    var elRow = row.getElement();
    var data = row.getData();
    var st = data._state;

    /* แถวใหม่ระบายทั้งแถว เพราะทั้งแถวคือของใหม่จริง ๆ */
    elRow.classList.remove("row-new");
    if (st === "new") {
      elRow.classList.add("row-new");
    }

    /* แถวที่แก้ไม่ได้เลย ทำเครื่องหมายไว้ให้รู้ตั้งแต่ก่อนกด ไม่ใช่กดแล้วเงียบ */
    elRow.classList.remove("row-locked");
    if (isTopAdmin(data)) {
      elRow.classList.add("row-locked");
    }

    /* ส่วนแถวที่แก้ ระบายเฉพาะช่องที่ค่าเปลี่ยน จะได้เห็นทันทีว่าแก้คอลัมน์ไหนไว้
           ถ้าระบายทั้งแถวจะรู้แค่ว่าแถวนี้ถูกแตะ แต่ต้องไล่หาเองว่าแตะตรงไหน */
    var was = st === "edited" ? original[data._key] : null;
    var now = was ? snapshotOf(data) : null;
    var cells = row.getCells();

    for (var i = 0; i < cells.length; i++) {
      var el = cells[i].getElement();
      if (!el) {
        continue;
      }

      var f = cells[i].getField();
      var changed = !!(
        was &&
        f &&
        was.hasOwnProperty(f) &&
        String(now[f]) !== String(was[f])
      );

      if (changed) {
        el.classList.add("cell-edited");
      } else {
        el.classList.remove("cell-edited");
      }
    }
  }

  /* ---------- คอลัมน์ ---------- */
  function buildColumns() {
    var G = window.GridCommon;
    var companies = companyLookup();

    /* roleLabels = ทุกสิทธิ์ (ใช้แสดงชื่อ รวม super_admin ของบัญชี admin)
           roleOptions = เฉพาะที่เลือกได้จาก dropdown (ไม่มี super_admin) */
    var roleLabels = CFG.roleLabels || CFG.roleOptions;
    var roles = CFG.roleOptions;

    /* ข้อความที่ต้องได้ตอนคัดลอกและตอนส่งออก — ไม่ใช่รหัสที่เก็บในฐานข้อมูล */
    var depts = deptLookup();

    var roleText = function (v) {
      return roleLabels[v] || v || "";
    };
    var companyText = function (v) {
      return Number(v) === 0 ? "" : companies[v] || "";
    };
    var deptText = function (v) {
      return Number(v) === 0 ? "" : depts[v] || "";
    };
    var statusText = function (v) {
      return Number(v) === 1 ? "เปิดใช้งาน" : "ปิดใช้งาน";
    };

    /* responsive: เลขยิ่งมาก ยิ่งถูกยุบเก็บก่อนเมื่อพื้นที่ไม่พอ
           คอลัมน์ที่ยุบไปแล้วยังกดดูและแก้ได้จากปุ่ม + หัวแถว
           ตั้งค่าเรียงตามความสำคัญ: ชื่อผู้ใช้กับชื่อเต็มอยู่เสมอ
           ส่วนอีเมลกับบริษัทยอมให้หายก่อนเพราะดูทีหลังได้ */
    return [
      G.col(G.control, {
        formatter: "responsiveCollapse",
        width: 36,
        minWidth: 36,
        hozAlign: "center",
        resizable: false,
        headerSort: false,
        /* ปุ่มนี้ต้องอยู่เสมอ ถ้าถูกยุบไปด้วยจะไม่มีทางเปิดดูคอลัมน์ที่ยุบไว้ */
        responsive: 0,
      }),

      G.col(G.control, {
        title: "#",
        field: "_seq",
        width: 48,
        hozAlign: "right",
        headerSort: false,
        resizable: false,
        responsive: 6,
        formatter: function (cell) {
          if (cell.getRow().getData()._state === "new") {
            return '<span class="grid-tag grid-tag-new">ใหม่</span>';
          }
          return cell.getRow().getPosition(true);
        },
      }),

      {
        title: "ชื่อผู้ใช้",
        field: "username",
        minWidth: 110,
        responsive: 0,
        download: true,
        clipboard: true,
        headerFilter: "input",
        headerFilterPlaceholder: "ค้นชื่อผู้ใช้",
        headerFilterFunc: "like",

        /* แถวเดิมเป็น primary key ห้ามแก้ แต่ยังต้องเลือกข้อความไปคัดลอกได้
                   จึงเปิดช่องแก้ให้ตามปกติแล้วใส่ readonly แทนที่จะปิดการแก้ไปเลย
                   ถ้าใช้ editable:false ดับเบิลคลิกจะไม่เกิดอะไรขึ้น และลากเลือก
                   ข้อความในช่องก็ไม่ได้ เพราะโหมดเลือกช่วงแบบ Excel ปิด user-select
                   ของทุกช่องไว้ (ไม่งั้นลากคลุมข้ามช่องไม่ได้)
                   ผลคือชื่อผู้ใช้กลายเป็นช่องเดียวในตารางที่คัดลอกทีละคำไม่ได้เลย */
        editor: "input",
        editorParams: function (cell) {
          if (cell.getRow().getData()._state === "new") {
            return {};
          }
          return {
            elementAttributes: {
              readonly: "readonly",
              title:
                "ชื่อผู้ใช้แก้ไม่ได้ — เลือกข้อความแล้ว Ctrl+C เพื่อคัดลอก",
            },
          };
        },
        formatter: function (cell) {
          var v = cell.getValue();
          var row = cell.getRow().getData();

          if (!v || String(v).trim() === "") {
            return '<span class="grid-empty">— ยังไม่ได้กรอก —</span>';
          }
          var html = '<span class="grid-user">' + escapeHtml(v) + "</span>";
          if (row._state !== "new" && row.username === CFG.me) {
            html += ' <span class="grid-tag grid-tag-self">คุณ</span>';
          }
          return html;
        },
      },

      {
        title: "ชื่อ-นามสกุล",
        field: "full_name",
        editor: "input",
        minWidth: 140,
        responsive: 0,
        download: true,
        clipboard: true,
        editable: editableUnlessTop,
        headerFilter: "input",
        headerFilterPlaceholder: "ค้นชื่อ",
        headerFilterFunc: "like",
        formatter: function (cell) {
          var v = cell.getValue();
          if (!v || String(v).trim() === "") {
            return '<span class="grid-empty">— ยังไม่ได้กรอก —</span>';
          }
          return escapeHtml(v);
        },
      },

      {
        title: "อีเมล",
        field: "email",
        editor: "input",
        minWidth: 150,
        responsive: 5,
        download: true,
        clipboard: true,
        editable: editableUnlessTop,
        headerFilter: "input",
        headerFilterPlaceholder: "ค้นอีเมล",
        headerFilterFunc: "like",
        formatter: function (cell) {
          var v = cell.getValue();
          if (!v || String(v).trim() === "") {
            return '<span class="grid-empty">—</span>';
          }
          return escapeHtml(v);
        },
      },

      G.col(G.labelled(roleText), G.filterPick(roleLabels), {
        title: "สิทธิ์",
        field: "role",
        width: 140,
        responsive: 2,
        download: true,
        clipboard: true,
        /* คลิกแล้วกางตัวเลือกสิทธิ์ทั้งหมด พิมพ์เพื่อกรองได้ */
        editor: "list",
        editorParams: G.pickerParams(roles),
        /* แก้ไม่ได้ถ้าเป็นบัญชีตัวเอง (กันลดสิทธิ์ตัวเองจนกู้ไม่ได้)
                   หรือเป็นบัญชีสิทธิ์สูงสุด (ล็อกทั้งแถว) */
        editable: function (cell) {
          var row = cell.getRow().getData();
          return !isSelf(row) && !isTopAdmin(row);
        },
        formatter: function (cell) {
          var v = cell.getValue();
          if (!v) {
            return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>';
          }

          var cls =
            v === "super_admin" ? "grid-pill grid-pill-top" : "grid-pill";
          return (
            '<span class="' +
            cls +
            '">' +
            escapeHtml(roleLabels[v] || v) +
            "</span>"
          );
        },
      }),

      /* หน่วยงานที่ดูแล — เลือกได้หลายหน่วยงานสำหรับผู้อนุมัติหนึ่งคน */
      G.col(
        /* ผู้อนุมัติหนึ่งคนดูแลได้หลายหน่วยงาน พอชื่อยาว ๆ ต่อกันด้วยจุลภาค
           ในไฟล์ Excel จะอ่านยาก จึงส่งออกแบบขึ้นบรรทัดใหม่ต่อหนึ่งหน่วยงาน
           (grid-export.js เปิด "ตัดคำ" ให้เซลล์ที่มีหลายบรรทัดเอง)
           ส่วนคัดลอกไปวาง ยังใช้จุลภาคเพื่อไม่ให้แตกเป็นหลายแถวตอน paste */
        (function () {
          var names = function (v) {
            var ids = normalizeDeptIds(v), out = [];
            for (var i = 0; i < ids.length; i++) {
              if (depts[ids[i]]) { out.push(depts[ids[i]]); }
            }
            return out;
          };
          return {
            accessorDownload: function (v) { return names(v).join("\n"); },
            accessorClipboard: function (v) { return names(v).join(", "); },
          };
        })(),
        G.filterOwn(),
        {
          title: "หน่วยงานที่ดูแล",
          field: "dept_ids",
          minWidth: 190,
          responsive: 3,
          variableHeight: true,
          download: true,
          clipboard: true,
          editor: departmentEditor,
          editable: editableUnlessTop,
          formatter: function (cell) {
            var row = cell.getRow().getData();
            var ids = normalizeDeptIds(cell.getValue());
            var chips = [];
            for (var i = 0; i < ids.length; i++) {
              if (depts[ids[i]]) {
                chips.push(
                  '<span class="user-dept-chip user-dept-chip-' +
                    (i % 4) +
                    '">' +
                    escapeHtml(depts[ids[i]]) +
                    "</span>",
                );
              }
            }
            if (chips.length === 0) {
              return row.role === "approver"
                ? '<span class="grid-warn">— ต้องระบุ —</span>'
                : '<span class="grid-empty">— ไม่ระบุ —</span>';
            }
            return (
              '<span class="user-dept-chips">' + chips.join("") + "</span>"
            );
          },
        },
      ),

      G.col(G.labelled(companyText), G.filterPick(companies), {
        title: "บริษัท",
        field: "agency_id",
        minWidth: 130,
        responsive: 5,
        download: true,
        clipboard: true,
        /* คลิกแล้วกางรายชื่อบริษัททั้งหมด และพิมพ์เพื่อกรองได้ในช่องเดียวกัน */
        editor: "list",
        editorParams: G.pickerParams(G.pickerValues(companies, true)),
        editable: editableUnlessTop,
        formatter: function (cell) {
          var name = companies[cell.getValue()];
          if (!name || Number(cell.getValue()) === 0) {
            return '<span class="grid-empty">— ไม่ระบุ —</span>';
          }
          return escapeHtml(name);
        },
      }),

      /* ---------- คืนรหัสผ่านเป็นค่าตั้งต้น ----------
               ไม่มีคอลัมน์ให้พิมพ์รหัสผ่านแล้ว เพราะช่องพิมพ์ในตารางถูกลากคัดลอก
               ข้ามบัญชีได้ ทำให้หลายบัญชีได้รหัสผ่านเดียวกันโดยไม่รู้ตัว
               เหลือเป็นปุ่มกดทีละคน ต้องยืนยันก่อน และไม่ต้องคิดรหัสใหม่เอง */
      G.col(G.control, {
        title: "รหัสผ่าน",
        field: "_reset",
        width: 130,
        hozAlign: "center",
        responsive: 3,
        headerSort: false,
        resizable: false,
        formatter: function (cell) {
          var data = cell.getRow().getData();

          if (data._state === "new") {
            return '<span class="grid-empty">— ใช้รหัสตั้งต้น —</span>';
          }

          /* บัญชีผู้ดูแลระบบสูงสุดคืนรหัสผ่านจากตรงนี้ไม่ได้
                       ไม่งั้นผู้ดูแลระบบทั่วไปกดปุ่มเดียวก็ยึดบัญชีสูงสุดได้
                       เพราะรู้อยู่แล้วว่ารหัสตั้งต้นคืออะไร (ฝั่ง backend ปฏิเสธอยู่แล้ว
                       ตรงนี้ไม่แสดงปุ่มด้วย จะได้ไม่ต้องกดแล้วเจอข้อความปฏิเสธ) */
          if (isTopAdmin(data)) {
            return (
              '<span class="grid-empty" title="เจ้าของบัญชีต้องเปลี่ยนเองที่หน้าเปลี่ยนรหัสผ่าน">' +
              "— </span>"
            );
          }

          return (
            '<button type="button" class="grid-linkbtn" title="คืนรหัสผ่านเป็นค่าตั้งต้น">' +
            "คืนค่าตั้งต้น</button>"
          );
        },
        cellClick: function (e, cell) {
          var data = cell.getRow().getData();
          if (data._state === "new" || isTopAdmin(data)) {
            return;
          }
          resetPassword(data.username);
        },
      }),

      G.col(G.labelled(statusText), G.filterStatus(), {
        title: "สถานะ",
        field: "is_active",
        width: 132,
        hozAlign: "center",
        responsive: 1,
        download: true,
        clipboard: true,
        cssClass: "cell-switch",
        formatter: function (cell) {
          var on = Number(cell.getValue()) === 1;
          var data = cell.getRow().getData();
          var lock = isSelf(data) || isTopAdmin(data);

          var why = "กดเพื่อ" + (on ? "ปิด" : "เปิด") + "ใช้งาน";
          if (isTopAdmin(data)) {
            why = "บัญชีผู้ดูแลระบบสูงสุดปิดใช้งานไม่ได้";
          } else if (isSelf(data)) {
            why = "ปิดใช้งานบัญชีตัวเองไม่ได้";
          }

          return (
            '<span class="grid-switch-wrap">' +
            '<button type="button" role="switch" aria-checked="' +
            (on ? "true" : "false") +
            '"' +
            ' class="grid-switch' +
            (on ? " is-on" : "") +
            (lock ? " is-locked" : "") +
            '"' +
            ' title="' +
            why +
            '">' +
            '<span class="grid-switch-knob"></span></button>' +
            '<span class="grid-switch-text">' +
            (on ? "เปิดใช้งาน" : "ปิดใช้งาน") +
            "</span>" +
            "</span>"
          );
        },
        cellClick: function (e, cell) {
          var row = cell.getRow();
          var data = row.getData();

          if (isTopAdmin(data)) {
            showResult(false, "บัญชีผู้ดูแลระบบสูงสุดแก้ไขจากหน้านี้ไม่ได้");
            return;
          }
          if (isSelf(data)) {
            showResult(
              false,
              "ปิดใช้งานบัญชีตัวเองไม่ได้ — กันล็อกตัวเองออกจากระบบ",
            );
            return;
          }
          cell.setValue(Number(cell.getValue()) === 1 ? 0 : 1);
          markChanged(row);
          refreshPending();
        },
      }),

      G.col(G.control, {
        title: "",
        field: "_remove",
        width: 46,
        hozAlign: "center",
        headerSort: false,
        resizable: false,
        responsive: 0,
        formatter: function (cell) {
          if (cell.getRow().getData()._state !== "new") {
            return "";
          }
          return '<button type="button" class="grid-remove" title="เอาแถวนี้ออก">&#10005;</button>';
        },
        cellClick: function (e, cell) {
          var row = cell.getRow();
          if (row.getData()._state !== "new") {
            return;
          }
          row.delete();
          refreshPending();
        },
      }),
    ];
  }

  /* ---------- นับและแสดงจำนวนที่ยังไม่บันทึก ---------- */
  function collect() {
    var creates = [],
      updates = [];
    if (!table) {
      return { creates: creates, updates: updates };
    }

    var rows = table.getData();
    for (var i = 0; i < rows.length; i++) {
      if (rows[i]._state === "new") {
        creates.push(rows[i]);
      }
      if (rows[i]._state === "edited") {
        updates.push(rows[i]);
      }
    }
    return { creates: creates, updates: updates };
  }

  function refreshPending() {
    var c = collect();
    var total = c.creates.length + c.updates.length;

    el("btnSave").disabled = total === 0;
    el("btnRevert").disabled = total === 0;

    var badge = el("pendingCount");
    badge.textContent = total;
    setHidden(badge, total === 0);

    var parts = [];
    if (c.creates.length) {
      parts.push("เพิ่มใหม่ " + c.creates.length + " คน");
    }
    if (c.updates.length) {
      parts.push("แก้ไข " + c.updates.length + " คน");
    }

    setHidden(el("pendingBar"), total === 0);
    if (total > 0) {
      el("pendingText").textContent =
        "ยังไม่ได้บันทึก: " + parts.join(" · ") + " — กดปุ่มบันทึกเพื่อยืนยัน";
    }
  }

  /* ---------- คืนรหัสผ่านของผู้ใช้คนหนึ่งเป็นค่าตั้งต้น ----------
       ทำทันทีเมื่อยืนยัน ไม่รวมกับปุ่มบันทึก เพราะเป็นคนละเรื่องกับการแก้ข้อมูล
       และไม่ควรค้างไว้ปนกับการแก้อย่างอื่นจนเผลอกดบันทึกไปพร้อมกัน */
  function resetPassword(username) {
    window
      .appConfirmDanger({
        icon: "warning",
        title: "คืนรหัสผ่านเป็นค่าตั้งต้น",
        text:
          'รหัสผ่านของ "' +
          username +
          '" จะถูกตั้งใหม่เป็น ' +
          CFG.defaultPassword +
          " ทันที รหัสเดิมจะใช้ไม่ได้อีก",
        confirmText: "คืนค่าตั้งต้น",
        cancelText: "ยกเลิก",
      })
      .then(function (ok) {
        if (!ok) {
          return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open("POST", CFG.endpoint, true);
        xhr.setRequestHeader("Content-Type", "application/json; charset=utf-8");

        xhr.onload = function () {
          var res = null;
          try {
            res = JSON.parse(xhr.responseText);
          } catch (err) {
            res = null;
          }

          if (!res) {
            showResult(false, "เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่");
            return;
          }
          if (!res.ok) {
            showResult(false, res.message || "คืนรหัสผ่านไม่สำเร็จ");
            return;
          }
          showResult(true, res.message);
        };
        xhr.onerror = function () {
          showResult(
            false,
            "ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่",
          );
        };

        xhr.send(
          JSON.stringify({
            action: "reset_password",
            csrf_token: CFG.csrfToken,
            username: username,
          }),
        );
      });
  }

  /* สำเร็จ = toast มุมขวาบน / ผิดพลาด = กล่องกลางจอที่ต้องกดรับทราบ */
  function showResult(ok, text) {
    if (typeof window.appSuccess !== "function") {
      // เผื่อ swal โหลดไม่ขึ้น
      alert(text);
      return;
    }
    if (ok) {
      window.appSuccess(text);
    } else {
      window.appError(text);
    }
  }

  /* ============================================================
   *  สร้างตาราง
   * ============================================================ */
  function init() {
    table = new Tabulator(
      "#grid",
      window.GridCommon.options({
        data: buildRows(),
        columns: buildColumns(),
        placeholder: 'ยังไม่มีผู้ใช้งาน กดปุ่ม "เพิ่มผู้ใช้" เพื่อเริ่มต้น',

        /* คอลัมน์ "หน่วยงานที่ดูแล" ตั้ง variableHeight ไว้ (ชิปหลายอันขึ้นบรรทัดใหม่ได้)
           ตัวจัดวางแถวปกติของ Tabulator (renderVertical: "virtual") กะความสูงแถวล่วงหน้า
           แล้ววัดจริงทีหลังระหว่างเลื่อน พอมีแถวสูงไม่เท่ากันปนกัน มันจะคำนวณใหม่
           แล้วดึง scrollTop กลับเองกลางอากาศ — เกิดอาการ "เลื่อนลงแล้วเด้งขึ้น" ที่สังเกตเจอ
           ซึ่งเห็นชัดเฉพาะหน้า 2 ขึ้นไปเพราะความสูงแถวของแต่ละหน้าไม่เท่ากัน
           ตารางนี้มีไม่เกิน 100 แถวต่อหน้าอยู่แล้ว (paginationSizeSelector สูงสุด 100)
           เปลี่ยนไปวาดแบบ "basic" (ใส่ทุกแถวลง DOM ตรง ๆ ไม่ประเมินความสูงล่วงหน้า)
           ตัดปัญหานี้ทิ้งไปเลย โดยไม่กระทบประสิทธิภาพที่ขนาดข้อมูลระดับนี้ */
        renderVertical: 'basic',

        /* ตารางนี้คัดลอกออกได้ แต่ "วางทับไม่ได้"
               การวางเขียนค่าลงหลายช่องรวดเดียวโดยไล่ตามลำดับคอลัมน์ที่เห็นอยู่
               ซึ่งอันตรายมากกับตารางบัญชีผู้ใช้:
                 - ถ้าเลื่อนไปโดนคอลัมน์รหัสผ่าน จะได้บัญชีหลายคนที่รหัสผ่านตรงกัน
                 - ถ้าเลื่อนไปโดนคอลัมน์ชื่อผู้ใช้ จะกลายเป็นไปแก้บัญชีผิดคน
                   (ตอนบันทึกใช้ชื่อผู้ใช้ในแถวเป็นตัวชี้ว่าจะอัปเดตใคร)
               และการวางไม่ยิง cellEdited ด่านตรวจที่มีอยู่จึงไม่ทำงาน
               ประโยชน์ที่ได้ไม่คุ้มความเสี่ยง เพราะบัญชีสร้างทีละคนอยู่แล้ว */
        clipboard: "copy",

        rowFormatter: applyRowClass,
      }),
    );

    window.GridCommon.guardRanges(table);
    window.GridCommon.enableFilterToggles(table);

    /* ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไปแบบ Excel */
    window.GridCommon.enableFillHandle(
      table,
      function (rows) {
        for (var i = 0; i < rows.length; i++) {
          markChanged(rows[i]);
        }
        refreshPending();
      },
      {
        /* สถานะไม่มีช่องพิมพ์ แต่ลากเติมได้ ใช้ปิดใช้งานทีเดียวหลายแถว
               ยกเว้นแถวของบัญชีสิทธิ์สูงสุด ที่ต้องรอดจากการลากทับทุกกรณี
               (คอลัมน์อื่นกันด้วย editable อยู่แล้ว คอลัมน์นี้ไม่มี editor จึงต้องกันตรงนี้) */
        is_active: function (cell) {
          return !isTopAdmin(cell.getRow().getData());
        },

        /* ชื่อผู้ใช้ของบัญชีเดิมห้ามเติมทับ ด้วยเหตุผลเดียวกับที่แก้ไม่ได้ */
        username: function (cell) {
          return cell.getRow().getData()._state === "new";
        },

        /* รหัสผ่านห้ามลากเติมเด็ดขาด
               รหัสผ่านเป็นของเฉพาะคน การคัดลอกลงแถวอื่นไม่มีกรณีไหนที่ถูกต้องเลย
               ถ้าเผลอลากผ่านคอลัมน์นี้ บัญชีที่โดนลากจะได้รหัสผ่านเดียวกันหมด
               แล้วพอกดบันทึกก็เปลี่ยนรหัสผ่านของคนอื่นไปด้วยโดยไม่รู้ตัว */
        /* ไม่มีคอลัมน์รหัสผ่านในตารางแล้ว แต่กันไว้เผื่อมีคนเผลอเพิ่มกลับมา */
        new_password: false,
      },
    );

    table.on("cellEdited", function (cell) {
      var row = cell.getRow();

      /* ช่องชื่อผู้ใช้ของแถวเดิมเปิดให้แค่คัดลอก (readonly) ค่าจึงไม่ควรเปลี่ยน
               ถ้าเปลี่ยนได้แปลว่ามีอะไรผิดพลาด ดึงกลับเป็นค่าเดิมทันที
               เพราะ username เป็น primary key ที่ created_by / updated_by อ้างอยู่ */
      if (cell.getField() === "username" && row.getData()._state !== "new") {
        var was = String(row.getData()._key).substring(1); // _key = 'r' + ชื่อผู้ใช้เดิม
        if (cell.getValue() !== was) {
          cell.setValue(was);
        }
        return;
      }

      markChanged(row);
      refreshPending();
    });

    table.on("tableBuilt", function () {
      refreshPending();
    });

    /* ---------- ค้นหาทันทีที่พิมพ์ ---------- */
    var search = el("gridSearch");
    search.addEventListener("input", function () {
      var term = search.value.trim().toLowerCase();

      if (term === "") {
        table.clearFilter();
        return;
      }

      table.setFilter(function (data) {
        var hay =
          String(data.username || "").toLowerCase() +
          " " +
          String(data.full_name || "").toLowerCase() +
          " " +
          String(data.email || "").toLowerCase();
        return hay.indexOf(term) !== -1;
      });
    });

    /* ---------- เพิ่มทีละหลายแถว ---------- */
    el("btnAddRows").addEventListener("click", function () {
      var count = parseInt(el("addCount").value, 10);
      if (isNaN(count) || count < 1) {
        count = 1;
      }
      if (count > 50) {
        count = 50;
      }

      /* สิทธิ์เริ่มต้นเป็นระดับต่ำสุดเสมอ ให้คนตั้งค่าเลือกยกระดับเอง
               ปลอดภัยกว่าเผลอสร้างบัญชีสิทธิ์สูงโดยไม่ตั้งใจ */
      var defaultRole = "general";
      if (!roleOptionsHas(defaultRole)) {
        for (var key in CFG.roleOptions) {
          if (CFG.roleOptions.hasOwnProperty(key)) {
            defaultRole = key;
            break;
          }
        }
      }

      for (var i = 0; i < count; i++) {
        table.addRow(
          {
            _key: "n" + ++newSeq,
            _state: "new",
            username: "",
            full_name: "",
            email: "",
            role: defaultRole,
            agency_id: 0,
            dept_ids: [],
            is_active: 1,
          },
          true,
        );
      }

      refreshPending();

      table.setPage(1).then(function () {
        var rows = table.getRows("active");
        if (rows.length) {
          rows[0].getCell("username").edit();
        }
      });
    });

    el("btnRevert").addEventListener("click", function () {
      var c = collect();
      var total = c.creates.length + c.updates.length;

      window
        .appConfirmDanger({
          title: "ยกเลิกการแก้ไข",
          text:
            "การแก้ไขที่ยังไม่ได้บันทึก " +
            total +
            " รายการจะหายไปทั้งหมด ต้องการดำเนินการต่อหรือไม่",
          confirmText: "ยกเลิกการแก้ไข",
          cancelText: "กลับไปแก้ต่อ",
        })
        .then(function (ok) {
          if (ok) {
            reloadPage();
          }
        });
    });

    /* ---------- ส่งออก Excel ---------- */
    if (window.GridExport) {
      window.GridExport.attach({
        table: table,
        button: el("btnExport"),
        filename: "รายชื่อผู้ใช้งาน",
        sheetName: "ผู้ใช้งาน",
        pending: function () {
          var c = collect();
          return c.creates.length + c.updates.length;
        },
      });
    }

    el("btnSave").addEventListener("click", save);

    window.addEventListener("beforeunload", function (e) {
      if (skipUnloadWarning) {
        return;
      }
      var c = collect();
      if (c.creates.length + c.updates.length > 0) {
        e.preventDefault();
        e.returnValue = "";
        return "";
      }
    });
  }

  /* ============================================================
   *  ส่งไปบันทึก
   * ============================================================ */
  function save() {
    var btn = el("btnSave");
    var c = collect();

    /* ตรวจฝั่งหน้าเว็บก่อน จะได้รู้ผลเร็วขึ้น และชี้ได้ว่าผิดช่องไหน
           กฎต้องตรงกับ user_validate() ใน backend/user.php เสมอ
           (เซิร์ฟเวอร์ยังตรวจซ้ำอยู่ดี ตรงนี้ไม่ใช่ด่านความปลอดภัย) */
    var G = window.GridCommon;
    var problems = G.validate(table, {
      username: function (v, d) {
        if (d._state !== "new") {
          return "";
        } // แถวเดิมแก้ชื่อผู้ใช้ไม่ได้อยู่แล้ว
        var s = String(v || "").trim();
        if (s === "") {
          return "กรุณากรอกชื่อผู้ใช้";
        }
        if (s.length < 3 || s.length > 50) {
          return "ชื่อผู้ใช้ต้องยาว 3-50 ตัวอักษร";
        }
        if (!/^[A-Za-z0-9._-]+$/.test(s)) {
          return "ชื่อผู้ใช้ใช้ได้เฉพาะ A-Z, a-z, 0-9, จุด, ขีดกลาง และขีดล่าง";
        }
        /* ซ้ำกับแถวอื่นในตารางเดียวกัน — backend จับได้ตอน INSERT แต่ช้ากว่า
                   และถ้าซ้ำหลายแถวจะได้ error ทีละใบจนงง */
        var same = 0;
        var rows = table.getData();
        for (var i = 0; i < rows.length; i++) {
          if (
            String(rows[i].username || "")
              .trim()
              .toLowerCase() === s.toLowerCase()
          ) {
            same++;
          }
        }
        if (same > 1) {
          return 'ชื่อผู้ใช้ "' + s + '" ซ้ำกับอีกแถวในตารางนี้';
        }
        return "";
      },

      full_name: function (v) {
        var s = String(v || "").trim();
        if (s === "") {
          return "กรุณากรอกชื่อ-นามสกุล";
        }
        if (s.length > 150) {
          return "ชื่อ-นามสกุลยาวเกิน 150 ตัวอักษร";
        }
        return "";
      },

      email: function (v) {
        var s = String(v || "").trim();
        if (s === "") {
          return "";
        } // อีเมลไม่บังคับ
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s)) {
          return "รูปแบบอีเมลไม่ถูกต้อง";
        }
        return "";
      },

      role: function (v) {
        if (!v || !roleOptionsHas(v)) {
          /* super_admin ไม่มีใน dropdown แต่เป็นค่าที่ถูกต้องของบัญชีเดิม */
          if (v === "super_admin") {
            return "";
          }
          return "กรุณาเลือกสิทธิ์การใช้งาน";
        }
        return "";
      },

      /* ผู้อนุมัติต้องมีอย่างน้อยหนึ่งหน่วยงาน */
      dept_ids: function (v, d) {
        if (d.role === "approver" && normalizeDeptIds(v).length === 0) {
          return "ผู้อนุมัติต้องระบุหน่วยงาน เพราะอนุมัติได้เฉพาะใบเบิกของหน่วยงานตัวเอง";
        }
        return "";
      },
    });

    if (problems.length > 0) {
      G.markInvalid(table, problems);
      showResult(false, G.invalidSummary(problems));
      return;
    }
    G.clearInvalid(table);

    btn.disabled = true;
    setHidden(btn.querySelector(".btn-icon"), true);
    setHidden(btn.querySelector(".btn-spinner"), false);

    var payload = {
      action: "save",
      csrf_token: CFG.csrfToken,
      creates: c.creates,
      updates: c.updates,
    };

    var xhr = new XMLHttpRequest();
    xhr.open("POST", CFG.endpoint, true);
    xhr.setRequestHeader("Content-Type", "application/json; charset=utf-8");

    xhr.onload = function () {
      var res = null;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (err) {
        res = null;
      }

      if (!res) {
        finishSave(false, "เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่");
        return;
      }
      if (!res.ok) {
        finishSave(false, res.message || "บันทึกไม่สำเร็จ");
        return;
      }

      if (res.failed > 0) {
        var lines = [];
        for (var i = 0; i < res.errors.length && i < 3; i++) {
          var e = res.errors[i];
          lines.push((e.label ? '"' + e.label + '" ' : "") + e.message);
        }
        if (res.errors.length > 3) {
          lines.push("และอีก " + (res.errors.length - 3) + " รายการ");
        }
        finishSave(
          false,
          "บันทึกสำเร็จ " +
            res.saved +
            " รายการ ไม่สำเร็จ " +
            res.failed +
            " รายการ — " +
            lines.join(" · "),
        );
        return;
      }

      try {
        sessionStorage.setItem(
          "usersSaved",
          "บันทึกเรียบร้อย " + res.saved + " รายการ",
        );
      } catch (err) {}
      reloadPage();
    };

    xhr.onerror = function () {
      finishSave(
        false,
        "ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองใหม่",
      );
    };

    xhr.send(JSON.stringify(payload));
  }

  function finishSave(ok, message) {
    var btn = el("btnSave");
    setHidden(btn.querySelector(".btn-icon"), false);
    setHidden(btn.querySelector(".btn-spinner"), true);
    btn.disabled = false;
    showResult(ok, message);
    refreshPending();
  }

  function showSavedMessage() {
    var msg = null;
    try {
      msg = sessionStorage.getItem("usersSaved");
    } catch (err) {}
    if (msg) {
      try {
        sessionStorage.removeItem("usersSaved");
      } catch (err) {}
      showResult(true, msg);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      init();
      showSavedMessage();
    });
  } else {
    init();
    showSavedMessage();
  }
})();
