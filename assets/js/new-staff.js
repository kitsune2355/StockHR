/* ============================================================
 *  new-staff.js — หน้า "เบิกพนักงานใหม่" (pages/new_staff_request.php)
 *
 *  ขั้นที่ 2 (ชุดพื้นฐาน) เก็บสถานะใน KIT แล้ววาดแถวจากตัวนี้
 *    วาดใหม่เฉพาะตอนโครงสร้างเปลี่ยน (ติ๊ก/ล้างฟอร์ม)
 *    ตอนพิมพ์จำนวนหรือหมายเหตุแค่อัปเดตค่า เคอร์เซอร์จะได้ไม่กระโดด
 *
 *  ขั้นที่ 3 (วัสดุถาวรและมีมูลค่า) ใช้ DocTransfer ตัวเดียวกับหน้าทำรายการเบิก
 *    ของในชุดพื้นฐานถูกตัดออกจากฝั่งซ้าย จะได้ไม่มีสินค้าซ้ำสองบรรทัด
 *
 *  ธง "ต้องคืน" บนจอเป็นแค่การแสดงผล ค่าจริงเซิร์ฟเวอร์ตั้งเองตอนบันทึก
 *  (ชุดพื้นฐานตาม tb_new_staff_kit, ขั้นที่ 3 ต้องคืนทุกรายการ — ดู backend/new_staff.php)
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.NEWSTAFF || {};

  var PRODUCTS = []; // สินค้าที่หน่วยงานนี้เลือกได้ + ของในชุด
  var byId = {};
  var KIT_DEFAULT = []; // ชุดตามที่ตั้งไว้ ใช้ตอนล้างฟอร์ม
  var KIT = []; // { product_id, on, qty, note, must_return }
  var transfer = null; // DocTransfer ของขั้นที่ 3
  var MY_DEPT = 0;
  var MY_DEPT_NAME = "";
  var LOCK_DEPT = false;
  var dirty = false;
  var saved = false;

  function el(id) {
    return document.getElementById(id);
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  function num(v) {
    var n = Number(v);
    return isNaN(n) ? 0 : n;
  }

  function fmt(v) {
    var n = num(v);
    return n === Math.floor(n) ? String(n) : String(Math.round(n * 100) / 100);
  }

  function setHidden(node, hide) {
    if (!node) {
      return;
    }
    if (hide) {
      node.setAttribute("hidden", "hidden");
    } else {
      node.removeAttribute("hidden");
    }
  }

  function icons() {
    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  function post(payload, done, fail) {
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
        fail("เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง กรุณาลองใหม่");
        return;
      }
      if (!res.ok) {
        fail(res.message || "ทำรายการไม่สำเร็จ");
        return;
      }
      done(res);
    };
    xhr.onerror = function () {
      fail("ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจการเชื่อมต่อ");
    };
    xhr.send(JSON.stringify(payload));
  }

  function showError(message) {
    el("formErrorText").textContent = message;
    setHidden(el("formError"), false);
    el("formError").scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function clearError() {
    setHidden(el("formError"), true);
  }

  function setProducts(list) {
    PRODUCTS = list || [];
    byId = {};
    for (var i = 0; i < PRODUCTS.length; i++) {
      byId[PRODUCTS[i].product_id] = PRODUCTS[i];
    }
  }

  /* สินค้าฝั่งซ้ายของขั้นที่ 3 = ทุกตัวที่เลือกได้ ยกเว้นของในชุดพื้นฐาน */
  function extraProducts() {
    var inKit = {};
    for (var i = 0; i < KIT_DEFAULT.length; i++) {
      inKit[KIT_DEFAULT[i].product_id] = true;
    }
    return PRODUCTS.filter(function (p) {
      return !inKit[p.product_id];
    });
  }

  /* ============================================================
   *  ขั้นที่ 2 ชุดพื้นฐาน
   * ============================================================ */
  function kitRowHtml(idx, line) {
    var p = byId[line.product_id] || {};
    var unit = p.unit_name || "";
    var dis = line.on ? "" : " disabled";

    var html =
      '<div class="ns-row flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition' +
      (line.on ? "" : " opacity-50") +
      '" data-idx="' +
      idx +
      '">';

    /* เลขลำดับตรงกับ "รายการที่ 1-4" ในกรอบหมายเหตุ */
    html +=
      '<span class="num flex h-7 w-7 flex-none items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">' +
      (idx + 1) +
      "</span>" +
      '<input type="checkbox" class="grid-check-native h-4 w-4 flex-none" data-f="on"' +
      (line.on ? " checked" : "") +
      ' aria-label="เบิก ' +
      escapeHtml(p.product_name || "") +
      '">';

    html +=
      '<div class="min-w-0 flex-1 basis-56">' +
      '<div class="font-medium text-slate-800 dark:text-slate-100">' +
      escapeHtml(p.product_name || "รหัสสินค้า " + line.product_id) +
      "</div>" +
      '<div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">' +
      '<span class="font-mono">' +
      escapeHtml(p.product_code || "") +
      "</span>" +
      '<span>· คงเหลือ <b class="num">' +
      fmt(p.qty) +
      "</b> " +
      escapeHtml(unit) +
      "</span>";
    if (line.must_return) {
      html +=
        '<span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">' +
        '<i data-lucide="undo-2" class="h-3 w-3" aria-hidden="true"></i>ต้องคืน</span>';
    }
    html += "</div></div>";

    html +=
      '<div class="flex flex-none flex-col items-start">' +
      '<div class="flex items-center gap-2">' +
      '<div class="flex h-10 items-center overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">' +
      '<button type="button" class="flex h-full w-9 items-center justify-center text-slate-500 hover:bg-slate-50 disabled:opacity-40 dark:hover:bg-slate-800" data-step="-1" aria-label="ลดจำนวน"' +
      dis +
      ">" +
      '<i data-lucide="minus" class="h-4 w-4" aria-hidden="true"></i></button>' +
      '<input type="number" inputmode="decimal" min="0" step="1" data-f="qty" value="' +
      fmt(line.qty) +
      '"' +
      ' class="num h-full w-14 border-x border-slate-200 bg-transparent text-center text-sm text-slate-800 focus:outline-none dark:border-slate-700 dark:text-slate-100" aria-label="จำนวน"' +
      dis +
      ">" +
      '<button type="button" class="flex h-full w-9 items-center justify-center text-slate-500 hover:bg-slate-50 disabled:opacity-40 dark:hover:bg-slate-800" data-step="1" aria-label="เพิ่มจำนวน"' +
      dis +
      ">" +
      '<i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i></button>' +
      "</div>" +
      '<span class="w-12 text-sm text-slate-500 dark:text-slate-400">' +
      escapeHtml(unit) +
      "</span>" +
      "</div>" +
      '<span class="ns-over mt-1 hidden text-xs text-amber-600 dark:text-amber-300">เกินยอดคงเหลือ — คลังอาจจ่ายได้ไม่ครบ</span>' +
      "</div>";

    html +=
      '<input type="text" maxlength="300" data-f="note" value="' +
      escapeHtml(line.note || "") +
      '"' +
      ' placeholder="หมายเหตุ (ถ้ามี)"' +
      ' class="input-control h-10 min-w-0 flex-1 basis-48 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900"' +
      dis +
      ">";

    return html + "</div>";
  }

  function renderKit() {
    var html = "";
    for (var i = 0; i < KIT.length; i++) {
      html += kitRowHtml(i, KIT[i]);
    }
    el("kitList").innerHTML = html;
    setHidden(el("kitEmpty"), KIT.length > 0);
    icons();
    refresh();
  }

  function resetKit() {
    KIT = [];
    for (var i = 0; i < KIT_DEFAULT.length; i++) {
      KIT.push({
        product_id: KIT_DEFAULT[i].product_id,
        on: true,
        qty: KIT_DEFAULT[i].default_qty,
        note: "",
        must_return: !!KIT_DEFAULT[i].must_return,
      });
    }
  }

  function bindKit() {
    var box = el("kitList");

    box.addEventListener("input", function (e) {
      var row = e.target.closest(".ns-row");
      var f = e.target.getAttribute("data-f");
      if (!row || (f !== "qty" && f !== "note")) {
        return;
      }
      KIT[Number(row.getAttribute("data-idx"))][f] = e.target.value;
      dirty = true;
      refresh();
    });

    box.addEventListener("change", function (e) {
      var row = e.target.closest(".ns-row");
      if (!row || e.target.getAttribute("data-f") !== "on") {
        return;
      }
      KIT[Number(row.getAttribute("data-idx"))].on = e.target.checked;
      dirty = true;
      renderKit();
    });

    box.addEventListener("click", function (e) {
      var btn = e.target.closest("button[data-step]");
      var row = e.target.closest(".ns-row");
      if (!btn || !row) {
        return;
      }
      var line = KIT[Number(row.getAttribute("data-idx"))];
      var next = Math.max(
        0,
        num(line.qty) + Number(btn.getAttribute("data-step")),
      );
      line.qty = next;
      row.querySelector('[data-f="qty"]').value = fmt(next);
      dirty = true;
      refresh();
    });
  }

  /* ============================================================
   *  สรุป
   * ============================================================ */

  /* บรรทัดที่จะส่งจริง: ชุดพื้นฐานที่ติ๊กไว้ + ของในขั้นที่ 3 ทั้งหมด */
  function collectLines() {
    var out = [];
    var i;
    for (i = 0; i < KIT.length; i++) {
      if (KIT[i].on) {
        out.push({
          product_id: KIT[i].product_id,
          qty: num(KIT[i].qty),
          note: KIT[i].note,
          must_return: KIT[i].must_return ? 1 : 0,
        });
      }
    }
    var extra = transfer ? transfer.getRows() : [];
    for (i = 0; i < extra.length; i++) {
      out.push({
        product_id: extra[i].product_id,
        qty: num(extra[i].qty),
        note: extra[i].note || "",
        must_return: 1,
      });
    }
    return out;
  }

  function refresh() {
    var all = collectLines();
    var qty = 0;
    var ret = 0;
    for (var i = 0; i < all.length; i++) {
      qty += num(all[i].qty);
      if (all[i].must_return) {
        ret++;
      }
    }
    el("sumLines").textContent = String(all.length);
    el("sumQty").textContent = fmt(qty);
    el("sumReturn").textContent = String(ret);
    el("btnSave").disabled = all.length === 0;

    var rows = el("kitList").querySelectorAll(".ns-row");
    for (var j = 0; j < rows.length; j++) {
      var line = KIT[Number(rows[j].getAttribute("data-idx"))];
      var p = byId[line.product_id] || {};
      var over = line.on && num(line.qty) > num(p.qty);
      rows[j].querySelector(".ns-over").classList.toggle("hidden", !over);
    }
  }

  /* ============================================================
   *  หน่วยงาน
   * ============================================================ */
  function fillDepartments(list) {
    var sel = el("departmentId");
    for (var i = 0; i < list.length; i++) {
      var d = list[i];
      var op = document.createElement("option");
      op.value = d.department_id;
      op.textContent = d.department_name;
      if (d.label_sub) {
        op.setAttribute("data-sub", d.label_sub);
      }
      sel.appendChild(op);
    }
    if (MY_DEPT > 0) {
      sel.value = String(MY_DEPT);
    }

    if (LOCK_DEPT) {
      sel.setAttribute("disabled", "disabled");
      setHidden(el("deptLocked"), false);
      el("deptLockedText").textContent =
        'ทำใบในนามหน่วยงาน "' +
        MY_DEPT_NAME +
        '" ตามข้อมูลผู้ใช้ของคุณ — ' +
        "ถ้าพนักงานใหม่อยู่หน่วยงานอื่น ให้หัวหน้าหน่วยงานนั้นหรือผู้ดูแลระบบเป็นผู้ทำใบ";
    } else if (MY_DEPT <= 0) {
      setHidden(el("deptLocked"), false);
      el("deptLockedText").textContent =
        "บัญชีของคุณยังไม่ได้ระบุหน่วยงานต้นสังกัด — เลือกหน่วยงานของพนักงานใหม่เอง";
    }

    if (window.SelectSearch) {
      window.SelectSearch.refresh(sel);
    }

    if (!LOCK_DEPT) {
      sel.addEventListener("change", function () {
        reloadProducts(num(this.value));
      });
    }
  }

  /* เปลี่ยนหน่วยงาน = ของในขั้นที่ 3 ที่เลือกได้เปลี่ยน
     และชุดพื้นฐานเปลี่ยนตามชุดของแผนก (ดู sql/080) — ขั้นที่ 2 ตั้งใหม่ตามชุดของแผนกนั้น
     ของที่หน่วยงานใหม่เบิกไม่ได้ หรือกลายเป็นของในชุดแล้ว ต้องเอาออกจากขั้นที่ 3
     ไม่งั้นจะโดนปฏิเสธตอนส่ง หรือได้สองบรรทัดของสินค้าเดียวกัน */
  function reloadProducts(deptId) {
    post(
      { action: "bootstrap", department_id: deptId },
      function (res) {
        setProducts(res.products);
        KIT_DEFAULT = res.kit || [];
        resetKit();
        var inKit = {};
        for (var k = 0; k < KIT_DEFAULT.length; k++) {
          inKit[KIT_DEFAULT[k].product_id] = true;
        }
        var kept = [];
        var rows = transfer.getRows();
        for (var i = 0; i < rows.length; i++) {
          if (byId[rows[i].product_id] && !inKit[rows[i].product_id]) {
            kept.push(rows[i]);
          }
        }
        transfer.setProducts(extraProducts());
        transfer.setRows(kept);
        renderKit();
        var dropped = rows.length - kept.length;
        if (dropped > 0 && typeof window.appSuccess === "function") {
          window.appSuccess(
            "เอาออก " + dropped + " รายการ เพราะหน่วยงานที่เลือกเบิกไม่ได้ หรืออยู่ในชุดพื้นฐานของแผนกแล้ว",
          );
        }
      },
      function (message) {
        showError(message);
      },
    );
  }

  /* ============================================================
   *  ส่งใบ
   * ============================================================ */
  function busy(on) {
    var btn = el("btnSave");
    btn.disabled = on;
    setHidden(btn.querySelector(".btn-icon"), on);
    setHidden(btn.querySelector(".btn-spinner"), !on);
  }

  function need(id, message) {
    var node = el(id);
    if (String(node.value).trim() === "") {
      showError(message);
      node.focus();
      return false;
    }
    return true;
  }

  function save() {
    clearError();

    if (!need("empName", "กรุณากรอกชื่อ-นามสกุลพนักงานใหม่")) {
      return;
    }
    if (!need("empPosition", "กรุณากรอกตำแหน่งของพนักงานใหม่")) {
      return;
    }
    if (num(el("departmentId").value) <= 0) {
      showError("กรุณาเลือกหน่วยงาน/ฝ่ายของพนักงานใหม่");
      return;
    }
    if (!need("startDate", "กรุณาระบุวันที่เริ่มงาน")) {
      return;
    }

    var lines = collectLines();
    if (lines.length === 0) {
      showError(
        "ยังไม่มีรายการที่จะเบิก — ติ๊กรายการพื้นฐาน หรือเลือกของในขั้นที่ 3",
      );
      return;
    }
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].qty <= 0) {
        var p = byId[lines[i].product_id] || {};
        showError(
          '"' +
            (p.product_name || "") +
            '" จำนวนต้องมากกว่า 0 — ถ้าไม่ต้องการให้เอาออก',
        );
        return;
      }
    }

    var name = el("empName").value.trim();
    var payload = {
      action: "save",
      csrf_token: CFG.csrfToken,
      doc_date: el("docDate").value,
      department_id: num(el("departmentId").value),
      note: el("docNote").value,
      employee_name: name,
      employee_code: el("empCode").value.trim(),
      position_name: el("empPosition").value.trim(),
      start_date: el("startDate").value,
      items: lines,
    };

    var text =
      "ส่งใบเบิกของ " +
      name +
      " จำนวน " +
      lines.length +
      " รายการให้ผู้อนุมัติ";
    var go = function (yes) {
      if (!yes) {
        return;
      }
      busy(true);
      post(
        payload,
        function (res) {
          saved = true;
          if (typeof window.appSuccess === "function") {
            window.appSuccess(res.message);
          }
          /* ไปหน้ารายการใบเบิก เห็นสถานะและพิมพ์ใบให้พนักงานได้ต่อทันที */
          setTimeout(function () {
            window.location.href = "main.php?page=new_staff_list";
          }, 700);
        },
        function (message) {
          busy(false);
          showError(message);
        },
      );
    };

    if (typeof window.appConfirm === "function") {
      window
        .appConfirm({
          title: "ยืนยันส่งใบเบิกพนักงานใหม่",
          text: text,
          confirmText: "ส่งใบเบิก",
        })
        .then(go);
    } else {
      go(window.confirm(text));
    }
  }

  function resetForm() {
    var go = function (yes) {
      if (!yes) {
        return;
      }
      el("empName").value = "";
      el("empCode").value = "";
      el("empPosition").value = "";
      el("startDate").value = "";
      el("docNote").value = "";
      resetKit();
      transfer.clear();
      dirty = false;
      clearError();
      renderKit();
    };
    if (!dirty) {
      go(true);
      return;
    }
    if (typeof window.appConfirm === "function") {
      window
        .appConfirm({
          title: "ล้างฟอร์ม?",
          text: "ข้อมูลที่กรอกไว้จะหายทั้งหมด",
          confirmText: "ล้างฟอร์ม",
        })
        .then(go);
    } else {
      go(window.confirm("ล้างฟอร์ม?"));
    }
  }

  /* ============================================================
   *  เริ่มทำงาน
   * ============================================================ */
  function fillTerms(terms) {
    var html = "";
    for (var i = 0; i < terms.length; i++) {
      if (String(terms[i]).trim() !== "") {
        html += "<li>" + escapeHtml(terms[i]) + "</li>";
      }
    }
    el("termsList").innerHTML = html;
    setHidden(el("termsBox"), html === "");
  }

  function load() {
    post(
      { action: "bootstrap" },
      function (res) {
        MY_DEPT = num(res.myDept);
        MY_DEPT_NAME = res.myDeptName || "";
        LOCK_DEPT = !!res.lockDept;
        el("docDate").value = res.today;

        setProducts(res.products);
        KIT_DEFAULT = res.kit || [];
        resetKit();
        fillDepartments(res.departments || []);
        fillTerms(res.terms || []);

        setHidden(el("nsLoading"), true);
        setHidden(el("nsBody"), false);
        setHidden(el("nsBar"), false);

        transfer = window.DocTransfer.create({
          products: extraProducts(),
          /* create() เรียก onChange ตอนวาดครั้งแรกด้วย ตอนนั้น transfer ยังเป็น null
             จึงไม่นับเป็นการแก้ฟอร์ม — ไม่งั้นแค่เปิดหน้าแล้วปิดก็โดนถามยืนยันออก */
          onChange: function () {
            if (transfer) {
              dirty = true;
            }
            refresh();
          },
        });

        renderKit();
      },
      function (message) {
        el("nsLoading").textContent = message;
      },
    );
  }

  function init() {
    bindKit();
    el("btnSave").addEventListener("click", save);
    el("btnReset").addEventListener("click", resetForm);

    el("nsForm").addEventListener("submit", function (e) {
      e.preventDefault();
    });
    el("nsForm").addEventListener("input", function (e) {
      if (e.target.id && e.target.id.indexOf("tf") !== 0) {
        dirty = true;
        clearError();
      }
    });

    window.addEventListener("beforeunload", function (e) {
      if (dirty && !saved) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
