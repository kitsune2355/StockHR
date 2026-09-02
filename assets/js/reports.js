/* ============================================================
 *  reports.js — หน้ารายงาน 3 แบบ ใช้ไฟล์เดียวกัน
 *      stock     ยอดคงเหลือ
 *      low       ต่ำกว่าจุดสั่งซื้อ (ยอดคงเหลือที่กรองไว้แล้ว)
 *      movement  ความเคลื่อนไหว
 *
 *  ทุกหน้าเป็นการอ่านอย่างเดียว ไม่มีการแก้ในตาราง
 *  จึงเปิดให้ทุกสิทธิ์ที่ล็อกอินดูได้ และเน้นที่การกรอง + ส่งออก Excel
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.REPORT;
  if (!CFG) {
    return;
  }

  var MODE = CFG.mode; // stock | low | movement
  var table = null;

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

  function setHidden(node, hide) {
    if (!node) {
      return;
    }
    if (hide) {
      node.setAttribute("hidden", "");
    } else {
      node.removeAttribute("hidden");
    }
  }

  function fmt(v) {
    var n = Number(v);
    if (isNaN(n)) {
      return "0";
    }
    if (n === Math.floor(n)) {
      return n.toLocaleString("en-US");
    }
    return (Math.round(n * 100) / 100).toLocaleString("en-US");
  }

  /* เงินแสดงสองตำแหน่งเสมอ แม้ลงท้ายด้วย .00 คอลัมน์ตัวเลขจะได้เรียง
       จุดทศนิยมตรงกันทุกบรรทัด เหมือนตารางเงินที่อื่นในระบบ */
  function money(v) {
    var n = Number(v);
    if (isNaN(n)) {
      n = 0;
    }
    return n.toLocaleString("en-US", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
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
        fail("เซิร์ฟเวอร์ตอบกลับมาไม่ถูกต้อง");
        return;
      }
      if (!res.ok) {
        fail(res.message || "ดึงข้อมูลไม่สำเร็จ");
        return;
      }
      done(res);
    };
    xhr.onerror = function () {
      fail("ติดต่อเซิร์ฟเวอร์ไม่ได้");
    };
    xhr.send(JSON.stringify(payload));
  }

  /* ============================================================
   *  โหลดข้อมูล
   * ============================================================ */
  function load() {
    var payload = { action: MODE === "movement" ? "movement" : MODE };

    if (MODE !== "movement") {
      var categoryName = el("repCategory") ? el("repCategory").value : "";
      var subId = el("repSubCategory")
        ? Number(el("repSubCategory").value) || 0
        : 0;
      payload.category_id = 0;
      payload.sub_category_id = subId;

      if (categoryName !== "") {
        var list = CFG.categories || [];
        for (var i = 0; i < list.length; i++) {
          if (String(list[i].category_name) === String(categoryName)) {
            payload.category_id = Number(list[i].category_id) || 0;
            break;
          }
        }
      }
    }

    if (MODE === "movement") {
      payload.date_from = el("dateFrom").value;
      payload.date_to = el("dateTo").value;
      payload.move_type = el("moveType") ? el("moveType").value : "";
    }

    post(
      payload,
      function (res) {
        /* ติดสถานะสต๊อกให้ทุกแถวตั้งแต่ตอนโหลด เพื่อให้กรองด้วย field ได้
               (ดูเหตุผลที่ไม่ใช้ฟังก์ชันกรองใน applyFilters) */
        if (MODE !== "movement" && res.rows) {
          for (var i = 0; i < res.rows.length; i++) {
            res.rows[i]._state = stateKey(res.rows[i]);
          }
        }

        /* ครั้งแรกส่งข้อมูลเข้าไปตอนสร้างตารางเลย
               Tabulator 6 สั่ง setData ก่อน tableBuilt ไม่ได้ จะพังตอนคำนวณความสูง */
        if (!table) {
          build(res);
        } else {
          table.setData(res.rows || []);
        }

        /* โหลดข้อมูลชุดใหม่แล้วต้องกรองซ้ำ ไม่งั้นค่าที่ค้างในแถบตัวกรอง
               จะไม่มีผลกับข้อมูลชุดใหม่ ผู้ใช้จะเห็นแถวที่ควรถูกกรองออก */
        applyFilters();
        renderSummary(res);

        setHidden(el("repLoading"), true);
        setHidden(el("repBody"), false);
        if (window.lucide) {
          window.lucide.createIcons();
        }
      },
      function (message) {
        setHidden(el("repLoading"), true);
        if (typeof window.appError === "function") {
          window.appError(message);
        }
      },
    );
  }

  /* ---------- แถบสรุปด้านบน ---------- */
  function renderSummary(res) {
    var s = res.summary || {};
    var tiles;

    if (MODE === "movement") {
      tiles = [
        { label: "รายการเคลื่อนไหว", value: fmt(s.lines), tone: "" },
        { label: "รับเข้ารวม", value: fmt(s.qty_in), tone: "in" },
        { label: "จ่ายออกรวม", value: fmt(s.qty_out), tone: "out" },
      ];
      /* แสดงเป็นวันที่ไทย (15 ก.ค. 69) ไม่ใช่ค่าดิบจากฐานข้อมูล (2026-07-15)
               ค่าที่ส่งไปกรองยังเป็นรูปแบบเดิมทุกอย่าง เปลี่ยนแค่สิ่งที่คนอ่าน
               ถ้า ThaiDate โหลดไม่ขึ้นด้วยเหตุใด ให้ตกกลับไปใช้ค่าดิบ
               ดีกว่าไม่แสดงช่วงวันที่เลย เพราะเป็นข้อมูลที่ต้องรู้ว่ากำลังดูช่วงไหนอยู่ */
      if (res.range && el("rangeText")) {
        var thai = window.ThaiDate;
        var from = thai ? thai.short(res.range.from) : res.range.from;
        var to = thai ? thai.short(res.range.to) : res.range.to;
        el("rangeText").textContent = "ช่วง " + from + " ถึง " + to;
      }
    } else {
      tiles = [
        { label: "รายการสินค้า", value: fmt(s.products), tone: "" },
        { label: "จำนวนรวมทั้งคลัง", value: fmt(s.total_qty), tone: "in" },
        { label: "ต่ำกว่าจุดสั่งซื้อ", value: fmt(s.low_count), tone: "low" },
        { label: "ของหมด", value: fmt(s.out_count), tone: "out" },
      ];
    }

    var html = "";
    for (var i = 0; i < tiles.length; i++) {
      html +=
        '<div class="rep-tile' +
        (tiles[i].tone ? " is-" + tiles[i].tone : "") +
        '">' +
        '<span class="rep-tile-label">' +
        escapeHtml(tiles[i].label) +
        "</span>" +
        '<span class="rep-tile-value num">' +
        tiles[i].value +
        "</span>" +
        "</div>";
    }
    el("repSummary").innerHTML = html;
  }

  /* ============================================================
   *  ตาราง
   * ============================================================ */
  function build(res) {
    var G = window.GridCommon;

    table = new Tabulator(
      "#grid",
      G.options({
        data: res.rows || [],
        layout: "fitColumns",
        height: "100%",
        placeholder: "ไม่พบข้อมูลตามเงื่อนไขที่เลือก",
        columns: MODE === "movement" ? movementColumns(res) : stockColumns(),
        initialSort:
          MODE === "movement"
            ? [{ column: "move_date", dir: "desc" }]
            : [{ column: "product_code", dir: "asc" }],

        paginationSize: 25,
        paginationSizeSelector: [25, 50, 100, 200],
      }),
    );

    G.guardRanges(table);
    G.enableFilterToggles(table);

    if (window.GridExport) {
      /* attach() รับอ็อบเจกต์ก้อนเดียว ไม่ใช่ (table, options)
               และ button ต้องเป็นตัวโหนดจริง ไม่ใช่ชื่อ id
               ของเดิมเรียกผิดรูปแบบ ปุ่มส่งออกของหน้ารายงานจึงกดแล้วเงียบ */
      window.GridExport.attach({
        table: table,
        button: el("btnExport"),
        filename: CFG.exportName,
        sheetName: CFG.exportName,
      });
    }
  }

  /* ---------- คอลัมน์ของรายงานยอดคงเหลือ ---------- */
  function stockColumns() {
    var G = window.GridCommon;

    return [
      G.col(G.control, {
        formatter: "responsiveCollapse",
        width: 36,
        minWidth: 36,
        hozAlign: "center",
        resizable: false,
        headerSort: false,
        responsive: 0,
      }),

      G.col(G.filterText("ค้นรหัส"), {
        title: "รหัสสินค้า",
        field: "product_code",
        width: 140,
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          return (
            '<span class="grid-user">' + escapeHtml(cell.getValue()) + "</span>"
          );
        },
      }),

      G.col(G.filterText("ค้นชื่อ"), {
        title: "ชื่อสินค้า",
        field: "product_name",
        minWidth: 220,
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          return escapeHtml(cell.getValue());
        },
      }),

      G.col(G.filterOwn("ทั้งหมด"), {
        title: "หมวดหมู่หลัก",
        field: "category_name",
        minWidth: 140,
        responsive: 4,
        download: true,
        clipboard: true,
      }),

      G.col(G.filterOwn("ทั้งหมด"), {
        title: "หมวดหมู่ย่อย",
        field: "sub_category_name",
        minWidth: 140,
        responsive: 5,
        download: true,
        clipboard: true,
      }),

      {
        title: "คงเหลือ",
        field: "qty",
        width: 116,
        hozAlign: "right",
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var d = cell.getRow().getData();
          var v = Number(cell.getValue());

          /* สีบอกสถานะทันทีโดยไม่ต้องเทียบกับคอลัมน์จุดสั่งซื้อเอง
                       แดง = หมด, ส้ม = ถึงจุดต้องสั่งแล้ว */
          var cls = "doc-stock";
          if (v <= 0) {
            cls += " is-out";
          } else if (d.min_qty > 0 && v <= d.min_qty) {
            cls += " is-low";
          }

          return '<b class="' + cls + '">' + fmt(v) + "</b>";
        },
      },

      {
        title: "หน่วย",
        field: "unit_name",
        width: 92,
        responsive: 3,
        download: true,
        clipboard: true,
      },

      {
        title: "จุดสั่งซื้อ",
        field: "min_qty",
        width: 106,
        hozAlign: "right",
        responsive: 6,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var v = Number(cell.getValue());
          return v > 0 ? fmt(v) : '<span class="grid-empty">—</span>';
        },
      },

      {
        title: "สถานะ",
        field: "_state",
        width: 124,
        hozAlign: "center",
        responsive: 1,
        headerSort: false,
        accessorDownload: function (v, d) {
          return stateText(d);
        },
        accessorClipboard: function (v, d) {
          return stateText(d);
        },
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var d = cell.getRow().getData();
          var tone =
            { out: "is-bad", low: "is-wait", ok: "is-ok" }[d._state] || "is-ok";
          return (
            '<span class="doc-pill ' + tone + '">' + stateText(d) + "</span>"
          );
        },
      },
    ];
  }

  function stateText(d) {
    return { out: "ของหมด", low: "ต้องสั่งเพิ่ม", ok: "ปกติ" }[
      d._state || stateKey(d)
    ];
  }

  function fillSubCategoryOptions() {
    var box = el("repSubCategory");
    if (!box) {
      return;
    }

    var cat = el("repCategory") ? el("repCategory").value : "";
    var current = String(box.value || "");
    var reset = false;

    if (cat !== "") {
      var list = CFG.subCategories || [];
      for (var i = 0; i < list.length; i++) {
        if (String(list[i].category_name) === String(cat)) {
          continue;
        }
        if (current !== "" && String(list[i].sub_category_id) === current) {
          reset = true;
          break;
        }
      }
    }

    if (cat !== "" && reset) {
      box.value = "";
    }

    var keep = String(box.value || "");
    var keepValid = false;

    box.innerHTML = '<option value="">ทุกหมวดหมู่ย่อย</option>';

    var list = CFG.subCategories || [];
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      if (cat !== "" && String(item.category_name) !== String(cat)) {
        continue;
      }

      var option = document.createElement("option");
      option.value = String(item.sub_category_id);
      option.textContent =
        cat !== ""
          ? String(item.sub_category_name)
          : String(item.category_name || "") +
            " › " +
            String(item.sub_category_name || "");
      box.appendChild(option);

      if (keep !== "" && String(item.sub_category_id) === keep) {
        keepValid = true;
      }
    }

    if (cat !== "") {
      box.value = "";
    } else {
      box.value = keepValid ? keep : "";
    }

    if (window.SelectSearch) {
      window.SelectSearch.refresh(box);
    }
  }

  /* ---------- คอลัมน์ของรายงานความเคลื่อนไหว ---------- */
  function movementColumns(res) {
    var G = window.GridCommon;
    var types = res && res.moveTypes ? res.moveTypes : {};

    return [
      G.col(G.control, {
        formatter: "responsiveCollapse",
        width: 36,
        minWidth: 36,
        hozAlign: "center",
        resizable: false,
        headerSort: false,
        responsive: 0,
      }),

      {
        title: "วันเวลา",
        field: "move_date",
        width: 160,
        responsive: 0,
        download: true,
        clipboard: true,
        /* แสดง "13 ส.ค. 69 14:32" แต่เรียงและกรองด้วยค่าดิบเหมือนเดิม
                   รายงานนี้เรียงตามเวลาเป็นหลัก ถ้าไปแปลงค่าในข้อมูลจะพังทันที */
        formatter: window.ThaiDate ? window.ThaiDate.cellTime : undefined,
      },

      G.col(
        G.labelled(function (v, d) {
          return d ? d.move_type_label : v;
        }),
        G.filterPick(types),
        {
          title: "ประเภท",
          field: "move_type",
          width: 130,
          hozAlign: "center",
          responsive: 1,
          download: true,
          clipboard: true,
          formatter: function (cell) {
            var v = cell.getValue();
            var tone =
              v === "receive" || v === "cancel_issue"
                ? "is-ok"
                : v === "issue" || v === "cancel_receive"
                  ? "is-info"
                  : "is-wait";
            return (
              '<span class="doc-pill ' +
              tone +
              '">' +
              escapeHtml(cell.getRow().getData().move_type_label) +
              "</span>"
            );
          },
        },
      ),

      G.col(G.filterText("ค้นเลขที่"), {
        title: "เลขที่เอกสาร",
        field: "doc_no",
        width: 150,
        responsive: 3,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var v = cell.getValue();
          return v
            ? '<span class="grid-user">' + escapeHtml(v) + "</span>"
            : '<span class="grid-empty">—</span>';
        },
      }),

      G.col(G.filterText("ค้นรหัส"), {
        title: "รหัสสินค้า",
        field: "product_code",
        width: 140,
        responsive: 2,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          return (
            '<span class="grid-user">' + escapeHtml(cell.getValue()) + "</span>"
          );
        },
      }),

      G.col(G.filterText("ค้นชื่อ"), {
        title: "ชื่อสินค้า",
        field: "product_name",
        minWidth: 200,
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          return escapeHtml(cell.getValue());
        },
      }),

      {
        title: "รับเข้า",
        field: "qty_in",
        width: 100,
        hozAlign: "right",
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var v = Number(cell.getValue());
          return v > 0
            ? '<b class="doc-in">+' + fmt(v) + "</b>"
            : '<span class="grid-empty">—</span>';
        },
      },

      {
        title: "จ่ายออก",
        field: "qty_out",
        width: 100,
        hozAlign: "right",
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var v = Number(cell.getValue());
          return v > 0
            ? '<b class="doc-out">-' + fmt(v) + "</b>"
            : '<span class="grid-empty">—</span>';
        },
      },

      /* ---------- ราคาที่ใช้จริงในรายการนั้น ----------
       * เป็นราคา ณ วันที่ทำรายการที่ถูกแช่แข็งไว้ ไม่ใช่ราคาสินค้าวันนี้
       * เปิดรายการของเดือนที่แล้วกี่ครั้งก็ได้ตัวเลขเดิมเสมอ
       *
       * ทั้งคู่โผล่เฉพาะสิทธิ์ที่ดูตัวเลขเงินได้ (ฝั่งเซิร์ฟเวอร์ตัดข้อมูล
       * ไม่ส่งมาให้อยู่แล้ว ตรงนี้แค่ไม่สร้างคอลัมน์เปล่าค้างไว้)
       */
      G.col(
        { visible: !!(res && res.showCost) },
        {
          title: "ราคา/หน่วย",
          field: "unit_cost",
          width: 118,
          hozAlign: "right",
          responsive: 4,
          download: true,
          clipboard: true,
          cssClass: "num",
          formatter: function (cell) {
            var v = Number(cell.getValue());
            if (!v) {
              return '<span class="grid-empty">—</span>';
            }
            return '<span class="doc-money">' + money(v) + "</span>";
          },
        },
      ),

      G.col(
        { visible: !!(res && res.showCost) },
        {
          title: "เป็นเงิน",
          field: "total_cost",
          width: 124,
          hozAlign: "right",
          responsive: 4,
          download: true,
          clipboard: true,
          cssClass: "num",
          formatter: function (cell) {
            var v = Number(cell.getValue());
            if (!v) {
              return '<span class="grid-empty">—</span>';
            }
            return '<b class="doc-money">' + money(v) + "</b>";
          },
        },
      ),

      {
        title: "คงเหลือหลังรายการ",
        field: "balance_after",
        width: 150,
        hozAlign: "right",
        responsive: 4,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          return '<b class="num">' + fmt(cell.getValue()) + "</b>";
        },
      },

      {
        title: "หน่วยงาน",
        field: "department_name",
        minWidth: 140,
        responsive: 6,
        download: true,
        clipboard: true,
        formatter: function (cell) {
          var v = cell.getValue();
          return v ? escapeHtml(v) : '<span class="grid-empty">—</span>';
        },
      },

      G.col(G.filterText("ค้นผู้ทำ"), {
        title: "ผู้ทำรายการ",
        field: "created_by",
        width: 130,
        responsive: 5,
        download: true,
        clipboard: true,
      }),
    ];
  }

  /* ============================================================
   *  เริ่มทำงาน
   * ============================================================ */
  /* ---------- ตัวกรองที่ทำงานในหน้า ----------
   * ค้นหา / หมวดหมู่ / สถานะสต๊อก กรองจากข้อมูลที่โหลดมาแล้ว จึงเห็นผลทันที
   * ต่างจากช่วงวันที่กับประเภทรายการ ที่ต้องยิงถามเซิร์ฟเวอร์ใหม่
   *
   * รวมทุกเงื่อนไขไว้ในการเรียก setFilter ครั้งเดียว เพราะ Tabulator
   * เก็บตัวกรองชุดเดียว ถ้าเรียกแยกกันคนละที่ อันหลังจะลบอันแรกทิ้ง
   */
  function applyFilters() {
    if (!table) {
      return;
    }

    var q = el("gridSearch") ? el("gridSearch").value.trim() : "";
    var cat = el("repCategory") ? el("repCategory").value : "";
    var sub = el("repSubCategory") ? el("repSubCategory").value : "";
    var state = el("repState") ? el("repState").value : "";

    var rules = [];

    if (q !== "") {
      /* array ซ้อนใน array = เงื่อนไขแบบ "หรือ" ของ Tabulator
               ช่องค้นเดียวจึงหาได้ทั้งรหัส ชื่อ และเลขที่เอกสารพร้อมกัน */
      rules.push([
        { field: "product_code", type: "like", value: q },
        { field: "product_name", type: "like", value: q },
        { field: "doc_no", type: "like", value: q },
      ]);
    }

    if (cat !== "") {
      rules.push({ field: "category_name", type: "=", value: cat });
    }

    if (sub !== "") {
      rules.push({ field: "sub_category_id", type: "=", value: Number(sub) });
    }

    /* กรองจากช่อง _state ที่คำนวณใส่ไว้ตอนโหลด ไม่ใช่ส่งฟังก์ชันเข้าไป
           Tabulator รับฟังก์ชันได้เฉพาะตอนเรียก setFilter ด้วยฟังก์ชันเดี่ยว ๆ
           ถ้าใส่ปนใน array ของเงื่อนไข มันจะทิ้งทั้งข้อแล้วขึ้น
           "Filter Error - No such filter type found" ใน console */
    if (state !== "") {
      rules.push({ field: "_state", type: "=", value: state });
    }

    if (rules.length === 0) {
      table.clearFilter(true);
    } else {
      table.setFilter(rules);
    }
  }

  /* ปกติ / ต้องสั่งเพิ่ม / ของหมด — ใช้ทั้งตอนกรอง ตอนแสดงป้าย และตอนส่งออก
       คำนวณที่เดียว ทั้งสามที่จึงบอกตรงกันเสมอ */
  function stateKey(row) {
    if (Number(row.qty) <= 0) {
      return "out";
    }
    if (row.min_qty > 0 && Number(row.qty) <= Number(row.min_qty)) {
      return "low";
    }
    return "ok";
  }

  function init() {
    var live = ["gridSearch", "repCategory", "repSubCategory", "repState"];
    for (var i = 0; i < live.length; i++) {
      if (!el(live[i])) {
        continue;
      }
      el(live[i]).addEventListener("input", applyFilters);
      el(live[i]).addEventListener("change", applyFilters);
    }

    if (el("repCategory")) {
      el("repCategory").addEventListener("change", function () {
        el("repSubCategory").value = "";
        fillSubCategoryOptions();
        applyFilters();
      });
    }

    if (el("repSubCategory")) {
      el("repSubCategory").addEventListener("change", applyFilters);
    }

    /* ช่องพวกนี้เปลี่ยนขอบเขตข้อมูล ต้องดึงจากเซิร์ฟเวอร์ใหม่ */
    var reload = ["dateFrom", "dateTo", "moveType"];
    for (var j = 0; j < reload.length; j++) {
      if (el(reload[j])) {
        el(reload[j]).addEventListener("change", load);
      }
    }
    if (el("btnReload")) {
      el("btnReload").addEventListener("click", load);
    }

    fillSubCategoryOptions();
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
