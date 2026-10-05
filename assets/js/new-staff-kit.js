/* ============================================================
 *  new-staff-kit.js — หน้าตั้งค่าอุปกรณ์พนักงานใหม่ (pages/new_staff_kit.php)
 *
 *  แก้ทุกอย่างในหน่วยความจำก่อน (ROWS + ช่องหมายเหตุ) แล้วบันทึกทั้งชุดทีเดียว
 *  เพราะชุดกับหมายเหตุต้องตรงกัน — บันทึกทีละแถวจะมีช่วงที่สองอย่างไม่ตรงกัน
 *  และฟอร์มเบิกที่เปิดอยู่ตอนนั้นจะได้ชุดครึ่ง ๆ กลาง ๆ
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.NEWSTAFFKIT || {};

  var PRODUCTS = [];
  var byId = {};
  var ROWS = []; // { product_id, product_code, product_name, unit_name, balance, product_active, default_qty, must_return }
  var SNAPSHOT = ""; // สถานะล่าสุดที่บันทึกแล้ว ใช้บอกว่ามีการแก้ไขค้างไหม และใช้ "ยกเลิกการแก้ไข"
  var saving = false;

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

  /* ---------- สถานะปัจจุบันเป็นข้อความ ไว้เทียบกับที่บันทึกล่าสุด ---------- */
  function state() {
    return JSON.stringify({
      rows: ROWS.map(function (r) {
        return [r.product_id, num(r.default_qty), r.must_return ? 1 : 0];
      }),
      terms: el("termsInput").value,
    });
  }

  function isDirty() {
    return state() !== SNAPSHOT;
  }

  function refreshBar() {
    var d = isDirty();
    setHidden(el("dirtyNote"), !d);
    el("btnUndo").disabled = !d || saving;
    el("btnSave").disabled = !d || saving;
  }

  /* ============================================================
   *  รายการในชุด
   * ============================================================ */
  function rowHtml(i, r) {
    var last = i === ROWS.length - 1;
    var arrow =
      "flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent dark:hover:bg-slate-800";

    return (
      '<div class="kit-row grid grid-cols-[2.5rem_1fr] items-center gap-3 px-5 py-3 md:grid-cols-[2.5rem_1fr_9rem_7rem_5.5rem]" data-idx="' +
      i +
      '">' +
      // ลำดับ
      '<span class="num mx-auto flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">' +
      (i + 1) +
      "</span>" +
      // สินค้า
      '<div class="min-w-0">' +
      '<div class="font-medium text-slate-800 dark:text-slate-100">' +
      escapeHtml(r.product_name) +
      "</div>" +
      '<div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">' +
      '<span class="font-mono">' +
      escapeHtml(r.product_code) +
      "</span>" +
      '<span>· คงเหลือ <b class="num">' +
      fmt(r.balance) +
      "</b> " +
      escapeHtml(r.unit_name) +
      "</span>" +
      (r.product_active
        ? ""
        : '<span class="grid-tag grid-tag-del">สินค้าถูกปิดใช้งาน — จะไม่โผล่ในฟอร์ม</span>') +
      "</div></div>" +
      // จำนวนตั้งต้น
      '<div class="col-start-2 flex items-center gap-2 md:col-start-auto">' +
      '<span class="text-xs text-slate-500 md:hidden">จำนวน</span>' +
      '<input type="number" min="0" step="1" inputmode="decimal" data-f="default_qty" value="' +
      fmt(r.default_qty) +
      '"' +
      ' class="input-control num h-10 w-20 rounded-xl border border-slate-200 bg-white px-3 text-center text-sm text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900" aria-label="จำนวนตั้งต้น">' +
      '<span class="text-sm text-slate-500 dark:text-slate-400">' +
      escapeHtml(r.unit_name) +
      "</span>" +
      "</div>" +
      // ต้องคืน
      '<div class="col-start-2 grid-switch-wrap md:col-start-auto">' +
      '<button type="button" role="switch" data-f="must_return" aria-checked="' +
      (r.must_return ? "true" : "false") +
      '"' +
      ' class="grid-switch' +
      (r.must_return ? " is-on" : "") +
      '" aria-label="ต้องคืนเมื่อพ้นสภาพ">' +
      '<span class="grid-switch-knob"></span></button>' +
      '<span class="grid-switch-text">' +
      (r.must_return ? "ต้องคืน" : "ไม่ต้องคืน") +
      "</span>" +
      "</div>" +
      // จัดการ
      '<div class="col-start-2 flex items-center justify-end gap-0.5 md:col-start-auto">' +
      '<button type="button" data-act="up" class="' +
      arrow +
      '" aria-label="เลื่อนขึ้น"' +
      (i === 0 ? " disabled" : "") +
      ">" +
      '<i data-lucide="chevron-up" class="h-4 w-4" aria-hidden="true"></i></button>' +
      '<button type="button" data-act="down" class="' +
      arrow +
      '" aria-label="เลื่อนลง"' +
      (last ? " disabled" : "") +
      ">" +
      '<i data-lucide="chevron-down" class="h-4 w-4" aria-hidden="true"></i></button>' +
      '<button type="button" data-act="remove" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label="เอาออกจากชุด">' +
      '<i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i></button>' +
      "</div>" +
      "</div>"
    );
  }

  function render() {
    var html = "";
    for (var i = 0; i < ROWS.length; i++) {
      html += rowHtml(i, ROWS[i]);
    }
    el("kitList").innerHTML = html;
    el("kitCount").textContent = String(ROWS.length);
    setHidden(el("kitEmpty"), ROWS.length > 0);
    fillPicker();
    renderTerms();
    icons();
    refreshBar();
  }

  /* ตัวเลือกสินค้า: ตัดของที่อยู่ในชุดแล้วออก */
  function fillPicker() {
    var sel = el("kitPick");
    var taken = {};
    for (var i = 0; i < ROWS.length; i++) {
      taken[ROWS[i].product_id] = true;
    }
    sel.innerHTML = '<option value="">— ค้นหาสินค้าที่จะเพิ่มในชุด —</option>';
    for (var j = 0; j < PRODUCTS.length; j++) {
      var p = PRODUCTS[j];
      if (taken[p.product_id]) {
        continue;
      }
      var op = document.createElement("option");
      op.value = p.product_id;
      op.textContent = p.product_code + "  " + p.product_name;
      op.setAttribute(
        "data-sub",
        "คงเหลือ " + fmt(p.qty) + " " + (p.unit_name || ""),
      );
      sel.appendChild(op);
    }
    el("btnKitAdd").disabled = true;
    if (window.SelectSearch) {
      window.SelectSearch.refresh(sel);
    }
  }

  function addRow() {
    var p = byId[num(el("kitPick").value)];
    if (!p) {
      return;
    }
    ROWS.push({
      product_id: p.product_id,
      product_code: p.product_code,
      product_name: p.product_name,
      unit_name: p.unit_name || "",
      balance: p.qty,
      product_active: true,
      default_qty: 1,
      must_return: false,
    });
    clearError();
    render();
  }

  function bindList() {
    var box = el("kitList");

    box.addEventListener("input", function (e) {
      var row = e.target.closest(".kit-row");
      if (!row || e.target.getAttribute("data-f") !== "default_qty") {
        return;
      }
      ROWS[Number(row.getAttribute("data-idx"))].default_qty = e.target.value;
      refreshBar();
    });

    box.addEventListener("click", function (e) {
      var btn = e.target.closest("button");
      var row = e.target.closest(".kit-row");
      if (!btn || !row) {
        return;
      }
      var i = Number(row.getAttribute("data-idx"));

      if (btn.getAttribute("data-f") === "must_return") {
        ROWS[i].must_return = !ROWS[i].must_return;
        render();
        return;
      }

      var act = btn.getAttribute("data-act");
      if (act === "up" && i > 0) {
        ROWS.splice(i - 1, 0, ROWS.splice(i, 1)[0]);
      } else if (act === "down" && i < ROWS.length - 1) {
        ROWS.splice(i + 1, 0, ROWS.splice(i, 1)[0]);
      } else if (act === "remove") {
        ROWS.splice(i, 1);
      } else {
        return;
      }
      render();

      /* ให้โฟกัสตามแถวที่ย้าย กดลูกศรต่อได้เลยโดยไม่ต้องหาปุ่มใหม่ */
      if (act === "up" || act === "down") {
        var to = act === "up" ? i - 1 : i + 1;
        var moved = box.querySelector(
          '.kit-row[data-idx="' + to + '"] [data-act="' + act + '"]',
        );
        if (moved && !moved.disabled) {
          moved.focus();
        }
      }
    });
  }

  /* ============================================================
   *  หมายเหตุ
   * ============================================================ */
  function termLines() {
    return el("termsInput")
      .value.split("\n")
      .map(function (s) {
        return s.trim();
      })
      .filter(function (s) {
        return s !== "";
      });
  }

  function renderTerms() {
    var lines = termLines();
    var html = "";
    for (var i = 0; i < lines.length; i++) {
      html += "<li>" + escapeHtml(lines[i]) + "</li>";
    }
    el("termsPreview").innerHTML =
      html ||
      '<li class="list-none -ml-4 text-slate-400">ยังไม่มีหมายเหตุ</li>';
    el("termsLen").textContent = String(el("termsInput").value.length);

    /* หมายเหตุเดิมอ้าง "รายการที่ 1-4" ถ้าจำนวนในชุดไม่ใช่ 4 แล้วลืมแก้
       ใบที่พนักงานเซ็นจะอ้างรายการที่ไม่มีอยู่จริง เตือนไว้ ไม่บังคับ */
    var m = el("termsInput").value.match(/รายการที่\s*1\s*-\s*(\d+)/);
    var warn = m && Number(m[1]) !== ROWS.length;
    setHidden(el("termsWarn"), !warn);
    if (warn) {
      el("termsWarnText").textContent =
        'หมายเหตุอ้าง "รายการที่ 1-' +
        m[1] +
        '" แต่ชุดมี ' +
        ROWS.length +
        " รายการ — ตรวจเลขในหมายเหตุให้ตรงกับชุดก่อนบันทึก";
    }
  }

  /* ============================================================
   *  บันทึก / ยกเลิก
   * ============================================================ */
  function busy(on) {
    saving = on;
    var btn = el("btnSave");
    setHidden(btn.querySelector(".btn-icon"), on);
    setHidden(btn.querySelector(".btn-spinner"), !on);
    refreshBar();
  }

  function save() {
    clearError();
    for (var i = 0; i < ROWS.length; i++) {
      if (num(ROWS[i].default_qty) <= 0) {
        showError(
          "ลำดับที่ " +
            (i + 1) +
            " (" +
            ROWS[i].product_name +
            "): จำนวนตั้งต้นต้องมากกว่า 0",
        );
        return;
      }
    }

    var payload = {
      action: "kit_save",
      csrf_token: CFG.csrfToken,
      terms: el("termsInput").value,
      rows: ROWS.map(function (r) {
        return {
          product_id: r.product_id,
          default_qty: num(r.default_qty),
          must_return: r.must_return ? 1 : 0,
        };
      }),
    };

    var go = function (yes) {
      if (!yes) {
        return;
      }
      busy(true);
      post(
        payload,
        function (res) {
          /* ตัดบรรทัดว่างแบบเดียวกับที่เซิร์ฟเวอร์ทำ จะได้ไม่ขึ้นว่ายังแก้ค้างอยู่ */
          el("termsInput").value = termLines().join("\n");
          SNAPSHOT = state();
          busy(false);
          render();
          if (typeof window.appSuccess === "function") {
            window.appSuccess(res.message);
          }
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
          title: "บันทึกอุปกรณ์พนักงานใหม่",
          text:
            "ใบเบิกพนักงานใหม่ที่ทำหลังจากนี้จะใช้ชุด " +
            ROWS.length +
            " รายการนี้ — ใบที่ส่งไปแล้วไม่เปลี่ยน",
          confirmText: "บันทึก",
        })
        .then(go);
    } else {
      go(window.confirm("บันทึกอุปกรณ์พนักงานใหม่?"));
    }
  }

  var LOADED = null;

  function apply(res) {
    PRODUCTS = res.products || [];
    byId = {};
    for (var i = 0; i < PRODUCTS.length; i++) {
      byId[PRODUCTS[i].product_id] = PRODUCTS[i];
    }
    ROWS = (res.kit || []).map(function (r) {
      return {
        product_id: r.product_id,
        product_code: r.product_code,
        product_name: r.product_name,
        unit_name: r.unit_name,
        balance: r.balance,
        product_active: r.product_active,
        default_qty: r.default_qty,
        must_return: r.must_return,
      };
    });
    el("termsInput").value = res.terms || "";
    SNAPSHOT = state();
    render();
  }

  function load() {
    post(
      { action: "kit_get" },
      function (res) {
        LOADED = res;
        setHidden(el("kitLoading"), true);
        setHidden(el("kitBody"), false);
        setHidden(el("kitBar"), false);
        apply(res);
      },
      function (message) {
        el("kitLoading").textContent = message;
      },
    );
  }

  function init() {
    bindList();
    el("kitPick").addEventListener("change", function () {
      el("btnKitAdd").disabled = num(this.value) <= 0;
    });
    el("btnKitAdd").addEventListener("click", addRow);
    el("termsInput").addEventListener("input", function () {
      renderTerms();
      refreshBar();
    });
    el("btnSave").addEventListener("click", save);
    el("btnUndo").addEventListener("click", function () {
      /* คืนเป็นค่าที่บันทึกไว้ล่าสุด — โหลดใหม่จากเซิร์ฟเวอร์ ยอดคงเหลือจะได้สดด้วย */
      clearError();
      load();
    });

    window.addEventListener("beforeunload", function (e) {
      if (LOADED && isDirty()) {
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
