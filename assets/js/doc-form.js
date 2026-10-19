/* ============================================================
 *  doc-form.js — หน้าทำเอกสาร ใช้ร่วมกัน 3 ประเภท
 *      รับเข้า (receive) / ใบเบิก (request) / ใบจ่าย (issue)
 *
 *  ทั้งสามหน้ามีโครงเดียวกัน: ส่วนหัวเอกสาร + ตารางรายการสินค้า + ปุ่มบันทึก
 *  ต่างกันแค่ช่องในส่วนหัว และกฎของจำนวนที่กรอกได้ จึงรวมไว้ไฟล์เดียว
 *  แล้วให้หน้าส่ง window.DOCFORM.docType มาบอกว่าเป็นประเภทไหน
 *
 *  กฎจำนวนของแต่ละประเภท
 *    รับเข้า  กรอกได้ไม่จำกัด (ของเข้ามาเท่าไรก็เท่านั้น)
 *    ใบเบิก  เตือนถ้าขอเกินยอดคงเหลือ แต่ยังส่งได้
 *            เพราะกว่าจะอนุมัติและจ่ายจริง ของอาจเข้ามาเพิ่มแล้ว
 *    ใบจ่าย  กรอกเกิน "ที่ขอไว้และยังไม่ได้จ่าย" ไม่ได้ และเกินยอดคงเหลือไม่ได้
 *
 *  ฝั่ง backend ตรวจซ้ำทุกข้ออยู่แล้ว ตรงนี้ทำเพื่อให้รู้ตัวก่อนกดบันทึก
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.DOCFORM;
  if (!CFG) {
    return;
  }

  var TYPE = CFG.docType;
  var table = null;

  /* ตัวเก็บรายการสินค้าของเอกสาร มีสองแบบตามประเภทหน้า
         ใบเบิก        -> ตัวเลือกสองฝั่ง (doc-transfer.js) เพราะคนเบิกไม่ได้จำรหัส
         รับเข้า/จ่าย  -> ตารางแบบ Excel เพราะคนคลังพิมพ์รหัสได้เร็วกว่าเลื่อนหา
       ทั้งสองแบบมีหน้าตาเหมือนกัน (getRows/setRows/clear/setProducts)
       โค้ดส่วนที่เหลือจึงไม่ต้องรู้ว่าข้างในเป็นอะไร */
  var items = null;
  var DATA = { products: [], departments: [], requests: [] };
  var byId = {}; // product_id -> ข้อมูลสินค้า
  var picked = null; // ใบเบิกที่เลือกอยู่ (เฉพาะหน้าใบจ่าย)
  var seq = 0;

  /* โหมดแก้ไขใบเบิก: เปิดหน้านี้ด้วย ?doc_id= จากปุ่ม "แก้ไขใบเบิก" ในหน้ารายการ
       EDIT_ID = 0 คือสร้างใหม่ตามปกติ, >0 คือกำลังแก้ใบนั้น (ส่ง action:"update")
       editSaved กันกล่องเตือน beforeunload ตอน redirect กลับหน้ารายการหลังบันทึกแก้ไข */
  var EDIT_ID = num(CFG.editDocId);
  var editSaved = false;

  /* หน่วยงานต้นสังกัดของคนที่กำลังใช้งาน — มาจากข้อมูลผู้ใช้ ไม่ใช่จากฟอร์ม */
  var MY_DEPT = 0;
  var MY_DEPT_NAME = "";
  var LOCK_DEPT = false;

  function el(id) {
    return document.getElementById(id);
  }
  function has(id) {
    return !!el(id);
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

  function num(v) {
    var n = Number(v);
    return isNaN(n) ? 0 : n;
  }

  /* เงินแสดง 2 ตำแหน่งเสมอ ต่างจากจำนวนนับที่ตัด .00 ทิ้ง
       เพราะคอลัมน์เงินต้องเรียงจุดทศนิยมตรงกันทุกบรรทัด */
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

  /* จำนวนแบบไม่มี .00 ห้อยท้าย ให้ตรงกับ stock_format() ฝั่ง PHP */
  function fmt(v) {
    var n = num(v);
    if (n === Math.floor(n)) {
      return String(n);
    }
    return String(Math.round(n * 100) / 100);
  }

  /* วันที่แบบไทยไว้อ่านบนหน้าจอเท่านั้น ค่าที่ส่งกลับ backend ยังเป็น Y-m-d เหมือนเดิม
       ถ้า thai-date.js ไม่ถูกโหลด ก็แสดงค่าดิบไปก่อน ดีกว่าปล่อยให้ว่าง */
  function dateShort(value) {
    return window.ThaiDate ? window.ThaiDate.short(value, "") : value || "";
  }

  function dateLong(value) {
    return window.ThaiDate ? window.ThaiDate.long(value, "") : value || "";
  }

  /* ---------- ป้ายชื่อใบเบิกในช่องเลือก ----------
   * ชื่อหน่วยงานซ้ำกันข้ามบริษัทได้ เช่น จัดซื้อ มีอยู่ทุกบริษัท
   * ถ้าไม่บอกชื่อบริษัทกำกับ คนจ่ายของจะเลือกผิดใบโดยไม่รู้ตัว
   * และวันที่ขอช่วยแยกใบของหน่วยงานเดียวกันที่ขอมาคนละรอบ
   *
   * ข้อความนี้เป็นตัวที่ช่องค้นหาใช้จับคู่ด้วย พิมพ์ชื่อบริษัทแล้วจึงหาเจอ
   */
  function requestLabel(r) {
    var who =
      (r.company_name ? r.company_name + " · " : "") +
      (r.department_name || "ไม่ระบุหน่วยงาน");
    var when = dateShort(r.doc_date);

    return (
      r.doc_no +
      " — " +
      who +
      (when ? " · " + when : "") +
      " (ค้างจ่าย " +
      fmt(r.pending_qty) +
      ")"
    );
  }

  /* ============================================================
   *  โหลดข้อมูลตั้งต้น
   * ============================================================ */
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

  function load() {
    post(
      { action: "bootstrap", doc_type: TYPE, edit_doc_id: EDIT_ID },
      function (res) {
        DATA.products = res.products || [];
        DATA.departments = res.departments || [];
        DATA.requests = res.requests || [];

        MY_DEPT = Number(res.myDept || 0);
        MY_DEPT_NAME = res.myDeptName || "";
        LOCK_DEPT = !!res.lockDept;

        byId = {};
        for (var i = 0; i < DATA.products.length; i++) {
          byId[DATA.products[i].product_id] = DATA.products[i];
        }

        fillHeader(res.today);

        if (TYPE === "request") {
          items = window.DocTransfer.create({
            products: DATA.products,
            onChange: refreshTotals,
          });
        } else {
          buildTable();
          items = tableItems();
        }

        setHidden(el("formBody"), false);
        setHidden(el("formLoading"), true);
        clearError();

        if (EDIT_ID > 0) {
          applyEditMode(res);
        }

        refreshTotals();
        setupScanner();

        if (window.lucide) {
          window.lucide.createIcons();
        }
      },
      function (message) {
        setHidden(el("formLoading"), true);
        showError(message);
      },
    );
  }

  /* ---------- เติมข้อมูลใบเบิกเดิมลงฟอร์ม (โหมดแก้ไข) ----------
   * bootstrap ฝั่ง API กรองสินค้าตามหน่วยงานของ "ใบที่กำลังแก้" มาให้แล้ว
   * DATA.products/byId จึงตรงกับใบนี้ ไม่ต้อง reloadProducts ตอนเปิด
   *
   * แก้ไม่ได้ (ไม่ใช่เจ้าของ / ใบถูกตัดสินไปแล้ว) -> API ส่ง editDoc=null + editError
   * บอกเหตุผลแล้วปิดปุ่มบันทึก ไม่เติมอะไรลงฟอร์ม
   */
  function applyEditMode(res) {
    if (!res.editDoc) {
      showError(res.editError || "แก้ไขใบเบิกนี้ไม่ได้");
      el("btnSave").disabled = true;
      return;
    }

    var d = res.editDoc;

    if (has("departmentId")) {
      el("departmentId").value = String(d.department_id);
      if (window.SelectSearch) {
        window.SelectSearch.refresh(el("departmentId"));
      }
    }
    if (d.doc_date) {
      el("docDate").value = d.doc_date;
    }
    el("docNote").value = d.note || "";

    var rows = [];
    var src = d.items || [];
    for (var i = 0; i < src.length; i++) {
      rows.push({
        product_id: num(src[i].product_id),
        qty: num(src[i].qty),
        note: src[i].note || "",
      });
    }
    items.setRows(rows);

    setHidden(el("editBanner"), false);
    el("editBannerText").textContent =
      "กำลังแก้ไขใบเบิก " +
      d.doc_no +
      " — บันทึกแล้วจะส่งให้ผู้อนุมัติพิจารณาใหม่ และลิงก์อนุมัติเดิมจะใช้ไม่ได้";
  }

  /* ---------- โหลดรายการสินค้าใหม่ตามหน่วยงานที่เลือก ----------
   * เรียกเฉพาะตอนผู้ดูแลระบบเปลี่ยนหน่วยงานในฟอร์ม
   *
   * ล้างของที่เลือกไว้ทิ้งด้วย เพราะสินค้าที่เลือกไปแล้วอาจไม่อยู่ในรายการ
   * ของหน่วยงานใหม่ ถ้าปล่อยค้างไว้จะไปโดนปฏิเสธตอนกดบันทึกแทน
   * ซึ่งเสียเวลากว่าและอธิบายยากกว่าการล้างให้ตั้งแต่ตอนเปลี่ยน
   */
  function reloadProducts(deptId) {
    if (deptId <= 0) {
      return;
    }

    post(
      { action: "bootstrap", doc_type: TYPE, department_id: deptId },
      function (res) {
        DATA.products = res.products || [];

        byId = {};
        for (var i = 0; i < DATA.products.length; i++) {
          byId[DATA.products[i].product_id] = DATA.products[i];
        }

        /* รายการสินค้าเปลี่ยนไปทั้งชุด ตารางค้นรหัสของเครื่องอ่านต้องสร้างใหม่ด้วย
               ไม่งั้นยิงสินค้าของหน่วยงานเดิมแล้วจะยังหาเจอ ทั้งที่หน่วยงานใหม่เบิกไม่ได้ */
        indexByCode();

        var had = items ? items.getRows().length : 0;

        if (items && items.setProducts) {
          items.setProducts(DATA.products);
        }
        if (items && items.clear) {
          items.clear();
        }

        refreshTotals();

        if (had > 0 && typeof window.appInfo === "function") {
          window.appInfo(
            "เปลี่ยนหน่วยงานแล้ว — ล้างรายการที่เลือกไว้ " +
              had +
              " รายการ เพราะสินค้าที่เบิกได้ของแต่ละหน่วยงานไม่เหมือนกัน",
          );
        }
      },
      function (message) {
        showError(message);
      },
    );
  }

  /* ---------- ส่วนหัวเอกสาร ---------- */
  function fillHeader(today) {
    el("docDate").value = today;
    el("docDate").max = today; // ลงวันที่ล่วงหน้าไม่ได้ backend ก็ปฏิเสธ

    if (has("departmentId")) {
      var sel = el("departmentId");
      for (var i = 0; i < DATA.departments.length; i++) {
        var d = DATA.departments[i];
        var op = document.createElement("option");
        op.value = d.department_id;
        op.textContent = d.department_name;

        /* ชื่อบริษัทแยกไปเป็นบรรทัดรอง แทนการต่อท้ายด้วย " — "
                   ช่องนี้กว้างไม่พอสำหรับชื่อเต็ม ข้อความจะถูกตัดตรงชื่อบริษัทพอดี
                   ซึ่งคือส่วนเดียวที่แยก "จัดซื้อ" ของสองบริษัทออกจากกันได้ */
        if (d.label_sub) {
          op.setAttribute("data-sub", d.label_sub);
        }

        sel.appendChild(op);
      }

      /* ---------- หน่วยงานของผู้ขอเบิก ----------
               เลือกให้ล่วงหน้าจากหน่วยงานต้นสังกัดในข้อมูลผู้ใช้
               คนทั่วไปเปลี่ยนไม่ได้ (backend บังคับซ้ำใน request_save อยู่แล้ว)
               ผู้ดูแลระบบยังเปลี่ยนได้ เพราะต้องทำใบเบิกแทนหน่วยงานอื่นเวลามีปัญหา */
      if (MY_DEPT > 0) {
        sel.value = String(MY_DEPT);
      }

      if (LOCK_DEPT) {
        sel.setAttribute("disabled", "disabled");
        setHidden(el("deptLocked"), false);
        el("deptLockedText").textContent =
          'เบิกในนามหน่วยงาน "' +
          MY_DEPT_NAME +
          '" ตามที่ตั้งไว้ในข้อมูลผู้ใช้';
      } else if (MY_DEPT <= 0) {
        /* ไม่มีหน่วยงานต้นสังกัด ต้องเลือกเอง และควรรู้ว่าทำไม */
        setHidden(el("deptLocked"), false);
        el("deptLockedText").textContent =
          "บัญชีของคุณยังไม่ได้ระบุหน่วยงานต้นสังกัด — เลือกหน่วยงานที่ขอเบิกเอง " +
          "หรือแจ้งผู้ดูแลระบบให้ตั้งค่าให้";
      }

      /* เติม option เสร็จแล้วต้องบอกช่องค้นหาให้อ่านรายการใหม่
               ไม่งั้นหน้ากากจะยังเป็นรายการว่างตอนที่หน้าเพิ่งโหลด */
      if (window.SelectSearch) {
        window.SelectSearch.refresh(sel);
      }

      /* ---------- เปลี่ยนหน่วยงานแล้วรายการสินค้าต้องเปลี่ยนตาม ----------
       * แต่ละหน่วยงานเบิกได้ไม่เหมือนกัน (ดู backend/product_department.php)
       * ผู้ดูแลระบบที่ทำใบแทนหน่วยงานอื่นจึงต้องเห็นรายการของหน่วยงานนั้นจริง ๆ
       * ไม่ใช่เห็นของหน่วยงานตัวเองแล้วเลือกไปจนโดนปฏิเสธตอนกดบันทึก
       *
       * ผูกเฉพาะตอนที่เปลี่ยนได้จริง — ช่องที่ถูกล็อกไม่มีทางเกิด event นี้อยู่แล้ว
       * แต่ผูกไว้ก็เปลืองเปล่า และทำให้อ่านโค้ดแล้วเข้าใจผิดว่าคนทั่วไปก็เปลี่ยนได้
       */
      if (TYPE === "request" && !LOCK_DEPT) {
        sel.addEventListener("change", function () {
          reloadProducts(Number(this.value) || 0);
        });
      }
    }

    if (has("refDocId")) {
      var rs = el("refDocId");
      for (var j = 0; j < DATA.requests.length; j++) {
        var r = DATA.requests[j];
        var o2 = document.createElement("option");
        o2.value = r.doc_id;
        o2.textContent = requestLabel(r);
        rs.appendChild(o2);
      }

      if (DATA.requests.length === 0) {
        setHidden(el("noRequest"), false);
        el("btnSave").disabled = true;
      }
      if (window.SelectSearch) {
        window.SelectSearch.refresh(rs);
      }
      rs.addEventListener("change", pickRequest);
    }
  }

  /* ---------- เลือกใบเบิกแล้วดึงรายการที่ยังค้างจ่ายมาเติมให้ ----------
   * ทำให้อัตโนมัติเพราะใบจ่ายต้องอยู่ในกรอบของใบเบิกเสมอ
   * ถ้าให้พิมพ์เองจะพิมพ์สินค้าที่ไม่ได้อยู่ในใบเบิกแล้วโดนปฏิเสธตอนบันทึก
   */
  function pickRequest() {
    var id = num(el("refDocId").value);
    picked = null;

    for (var i = 0; i < DATA.requests.length; i++) {
      if (DATA.requests[i].doc_id === id) {
        picked = DATA.requests[i];
        break;
      }
    }

    items.clear();
    setHidden(el("noRequest"), !!picked);
    setHidden(el("reqInfo"), !picked);

    if (!picked) {
      refreshTotals();
      return;
    }

    el("reqInfoText").textContent =
      "ผู้ขอเบิก " +
      (picked.requester_name || picked.requester) +
      " · บริษัท " +
      (picked.company_name || "ไม่ระบุ") +
      " · หน่วยงาน " +
      (picked.department_name || "ไม่ระบุ") +
      " · วันที่ขอ " +
      (dateLong(picked.doc_date) || "ไม่ระบุ");

    if (has("receiverName") && el("receiverName").value === "") {
      el("receiverName").value =
        picked.requester_name || picked.requester || "";
    }

    /* ---------- เติมจำนวนตั้งต้นให้เท่าที่จ่ายได้จริง ----------
           เดิมเติมยอดค้างมาเต็มทุกบรรทัดโดยไม่ดูว่ามีของหรือเปล่า
           ใบเบิกที่ขอของหมดสต๊อกมาด้วยจึงกดบันทึกไม่ผ่านทั้งใบ
           ทั้งที่บรรทัดแรกมีของพร้อมจ่ายอยู่แล้ว

           เติมเท่าที่มีแทน บรรทัดที่ของหมดจะได้ศูนย์ แล้วถูกข้ามตอนบันทึก
           ยอดค้างของมันยังอยู่ในใบเบิกครบ ทำใบจ่ายใบใหม่ได้หลังของเข้า */
    var rows = [];
    var short = 0;
    for (var j = 0; j < picked.items.length; j++) {
      var it = picked.items[j];
      if (it.qty_pending <= 0) {
        continue;
      } // บรรทัดนี้จ่ายครบแล้ว

      var have = byId[it.product_id] ? num(byId[it.product_id].qty) : 0;
      var give = have < num(it.qty_pending) ? have : num(it.qty_pending);
      if (give < 0) {
        give = 0;
      }
      if (give < num(it.qty_pending)) {
        short++;
      }

      rows.push({
        _id: ++seq,
        product_id: it.product_id,
        qty: give,
        max_qty: it.qty_pending,
        note: "",
      });
    }
    items.setRows(rows);

    /* บอกไปเลยว่ารอบนี้จ่ายไม่ครบ ไม่ต้องให้ผู้ใช้ไล่หาเองว่าทำไมบางช่องเป็นศูนย์ */
    if (short > 0) {
      el("reqInfoText").textContent +=
        " · ของไม่พอจ่าย " +
        fmt(short) +
        " รายการ จะค้างไว้ในใบเบิกให้จ่ายรอบหน้า";
    }
  }

  /* ============================================================
   *  ตารางรายการสินค้า
   * ============================================================ */
  function productValues() {
    var out = [];
    for (var i = 0; i < DATA.products.length; i++) {
      var p = DATA.products[i];
      out.push({
        label:
          p.product_code +
          " — " +
          p.product_name +
          "  (คงเหลือ " +
          fmt(p.qty) +
          " " +
          p.unit_name +
          ")",
        value: p.product_id,
      });
    }
    return out;
  }

  /* จำนวนสูงสุดที่บรรทัดนี้กรอกได้ — ติดลบ = ไม่จำกัด

       เดิมใช้ 0 เป็นค่า "ไม่จำกัด" ซึ่งชนกับกรณีของหมดจริง ๆ ที่เพดานก็เป็น 0
       ทุกที่ที่เช็ค cap > 0 จึงข้ามการตรวจไปเงียบ ๆ เมื่อของหมด
       แล้วปล่อยให้ไปล้มที่ฝั่งเซิร์ฟเวอร์ ซึ่งย้อนทั้งใบทิ้งรวมบรรทัดที่จ่ายได้
       ใช้ -1 เป็นค่าไม่จำกัดแทน เพดานจะได้เป็นจำนวนจริงเสมอ */
  function ceilingOf(row) {
    if (TYPE !== "issue") {
      return -1;
    }

    var stock = byId[row.product_id] ? num(byId[row.product_id].qty) : 0;
    var asked = num(row.max_qty);
    /* จ่ายได้ไม่เกินทั้งของที่ขอ และของที่มีอยู่จริง เอาค่าน้อยกว่า */
    return asked > 0 && asked < stock ? asked : stock;
  }

  /* Tabulator 6 สั่ง addRow / setData ก่อน tableBuilt ไม่ได้ จะพังตอนคำนวณความสูง
       ตารางสร้างเสร็จเร็วมาก แต่ถ้าผู้ใช้กด "เพิ่มรายการ" ทันทีที่หน้าโผล่ก็ทันได้
       จึงพักคำสั่งไว้ก่อนแล้วค่อยยิงตอนตารางพร้อม แทนที่จะปล่อยให้ error เงียบ ๆ */
  var ready = false;
  var waiting = [];

  function whenReady(fn) {
    if (ready) {
      fn();
      return;
    }
    waiting.push(fn);
  }

  function buildTable() {
    var G = window.GridCommon;

    table = new Tabulator(
      "#items",
      G.options({
        data: [],
        layout: "fitColumns",
        height: "100%",
        placeholder: 'ยังไม่มีรายการ — กดปุ่ม "เพิ่มรายการ" เพื่อเริ่ม',
        index: "_id",
        columns: buildColumns(),

        /* ห้ามยุบคอลัมน์บนจอแคบ — ค่าปริยายของทั้งระบบคือ 'collapse'
               ซึ่งเหมาะกับตารางที่มีไว้อ่าน แต่ตารางนี้เป็นฟอร์มที่ต้องกรอก
               และช่องที่ถูกยุบแก้ไม่ได้ (มือถือจะสแกนได้แต่กรอกจำนวนไม่ได้)
               ปิดการยุบแล้วให้เลื่อนแนวนอนในกรอบตารางแทน */
        responsiveLayout: false,

        /* ตารางนี้เป็นฟอร์ม ไม่ใช่ตารางข้อมูล จึงไม่ต้องแบ่งหน้าและไม่ต้องเรียง
               ลำดับบรรทัดคือลำดับที่ผู้ใช้กรอก ซึ่งจะกลายเป็นลำดับในเอกสาร */
        pagination: false,
        movableRows: true,

        /* ---------- ใบรับเข้า: คลิกเดียวแก้ได้ ----------
         * ค่าปริยายของทุกตารางในระบบคือดับเบิลคลิก (ดู grid-common.js)
         * ซึ่งถูกสำหรับตารางที่มีไว้อ่านเป็นหลัก การเผลอคลิกโดนช่องจะได้
         * ไม่เข้าโหมดแก้ไข และคลิกเดียวยังเหลือไว้ให้ "เลือกช่อง" เพื่อคัดลอก
         *
         * แต่ใบรับเข้าเป็นฟอร์มเปล่าที่ต้องกรอกทุกช่องอยู่แล้ว — สินค้า จำนวน
         * ราคา หมายเหตุ รวมสี่ช่องต่อแถว การบังคับดับเบิลคลิกจึงเป็นการกดเพิ่ม
         * เปล่า ๆ แถวละสี่ครั้ง ซึ่งรู้สึกได้ชัดมากตอนรับของเข้าทีละหลายสิบรายการ
         *
         * สิ่งที่แลกไป: คลิกช่องเดียวจะเข้าโหมดแก้ไขทันที เลือกช่องเดี่ยว ๆ
         * ไว้กด Ctrl+C ไม่ได้อีก (ลากคลุมข้ามช่องยังทำได้ เพราะ click ของ
         * เบราว์เซอร์ยิงที่กล่องแม่ ไม่ใช่ที่ช่องต้นทาง) ซึ่งคุ้มกับฟอร์มที่
         * คนใช้มา "กรอก" ไม่ได้มา "คัดลอกออก"
         *
         * ใบจ่ายออกใช้ตารางตัวเดียวกันแต่ยังเป็นดับเบิลคลิกเหมือนเดิม
         * เพราะที่นั่นรายการมาจากใบเบิก จำนวนมาจากการยิงบาร์โค้ด
         * ตารางมีไว้ "ไล่ตรวจของที่หยิบ" การเผลอคลิกโดนแล้วเข้าโหมดแก้ไข
         * ระหว่างไล่ตรวจ เสียมากกว่าได้
         */
        editTriggerEvent: TYPE === "receive" ? "click" : "dblclick",
      }),
    );

    table.on("tableBuilt", function () {
      ready = true;
      while (waiting.length > 0) {
        waiting.shift()();
      }
    });

    table.on("cellEdited", function (cell) {
      var row = cell.getRow();
      if (cell.getField() === "product_id") {
        /* เปลี่ยนสินค้าแล้วเพดานเดิมใช้ไม่ได้ ล้างจำนวนให้กรอกใหม่
                   ส่วนราคา เติมราคาซื้อล่าสุดของสินค้านั้นให้เป็นค่าตั้งต้น
                   ส่วนใหญ่ซื้อราคาเดิม จะได้ไม่ต้องพิมพ์ซ้ำ แต่แก้ได้ถ้าราคาขยับ */
        var p = byId[cell.getValue()];
        row.update({ qty: 0, unit_cost: p && p.unit_cost ? p.unit_cost : 0 });

        /* เลือกสินค้าที่มีบรรทัดอยู่แล้ว = ตั้งใจจะกรอกของตัวนั้น ไม่ใช่ตั้งใจได้สองบรรทัด
                   ปล่อยให้ซ้ำไว้จะไปโผล่ตอนกดบันทึก (collect ห้ามสินค้าซ้ำในใบเดียวกัน)
                   ซึ่งเป็นจังหวะที่แย่ที่สุด เพราะกรอกมาทั้งใบแล้วค่อยมารู้

                   หน่วงหนึ่งจังหวะก่อนลบบรรทัด ให้ Tabulator ปิดตัวแก้ไขของช่องนี้ให้เสร็จก่อน
                   ลบทิ้งกลางคันแล้วมันจะไปอ้างถึงบรรทัดที่ไม่มีอยู่แล้ว */
        var dupPid = cell.getValue();
        setTimeout(function () {
          var merged = mergeDuplicate(row, dupPid);
          /* ไม่ซ้ำ = บรรทัดนี้ยังอยู่ตามเดิม เปิดช่องจำนวนให้กรอกต่อทันที
                   (กรณีซ้ำ mergeDuplicate เปิดช่องจำนวนของบรรทัดที่เก็บไว้ให้แล้ว) */
          if (!merged && num(dupPid) > 0) {
            try {
              row.getCell("qty").edit();
            } catch (e) {
              /* แก้ไม่ได้ก็ไม่เป็นไร */
            }
          }
        }, 0);
      }
      row.reformat();
      refreshTotals();
    });

    table.on("rowMoved", refreshTotals);
    table.on("dataChanged", refreshTotals);

    G.guardRanges(table);
  }

  /* ---------- เลือกสินค้าซ้ำกับบรรทัดอื่น : รวมกลับเข้าบรรทัดเดิม ----------
   *
   * ใบหนึ่งมีสินค้าตัวเดียวกันได้บรรทัดเดียว (backend ตรวจซ้ำอีกชั้น) การมีสองบรรทัด
   * จึงไม่ใช่ทางเลือก แต่เป็นสิ่งที่ต้องแก้ก่อนบันทึกอยู่ดี — เอาออกตั้งแต่ตอนที่ยังรู้ว่า
   * ผู้ใช้กำลังทำอะไรอยู่ ดีกว่าเก็บไว้ไปแจ้งตอนกดบันทึก
   *
   * ไม่ต้องห่วงจำนวนของบรรทัดที่ถูกลบ เพราะการเปลี่ยนสินค้าล้างจำนวนทิ้งไปแล้วก่อนหน้านี้
   * (ดู cellEdited) ส่วนหมายเหตุยกไปให้บรรทัดที่เก็บไว้ถ้าตรงนั้นยังว่าง จะได้ไม่หายไปด้วย
   *
   * การยิงบาร์โค้ดใช้กติกาเดียวกันอยู่แล้ว (ดู scanAdd) ต่างกันแค่ยิงคือบวกจำนวน
   * ส่วนเลือกเองคือย้ายไปกรอกจำนวนที่บรรทัดเดิม
   */
  function mergeDuplicate(row, pid) {
    if (!table || num(pid) <= 0) {
      return false;
    }

    var rows = table.getRows();
    var mine = row.getData()._id;
    var keep = null;

    for (var i = 0; i < rows.length; i++) {
      var d = rows[i].getData();
      if (d._id === mine) {
        continue;
      }
      if (num(d.product_id) === num(pid)) {
        keep = rows[i];
        break;
      }
    }
    if (!keep) {
      return false;
    }

    var note = String(row.getData().note || "").trim();
    if (note !== "" && String(keep.getData().note || "").trim() === "") {
      keep.update({ note: note });
    }

    /* จำเลขบรรทัดไว้ก่อนลบ แล้วเลื่อนลงหนึ่งถ้าบรรทัดที่เก็บไว้อยู่ใต้บรรทัดที่หายไป
           อ่านหลังลบก็ได้เลขที่ถูก แต่ต้องไปเชื่อจังหวะการจัดลำดับใหม่ของ Tabulator */
    var keepNo = keep.getPosition(true);
    if (keepNo > row.getPosition(true)) {
      keepNo--;
    }

    row.delete();
    keep.reformat();
    refreshTotals();
    flashRow(keep);

    var p = byId[num(pid)];
    showNote(
      true,
      (p ? p.product_code + " " + p.product_name : "สินค้านี้") +
        " มีอยู่แล้วในบรรทัดที่ " +
        keepNo +
        " — รวมให้เป็นบรรทัดเดียว กรอกจำนวนต่อได้เลย",
    );

    /* เปิดช่องจำนวนของบรรทัดที่เก็บไว้ให้เลย เพราะนั่นคือสิ่งที่ผู้ใช้กำลังจะทำต่อ
           ถ้าไม่เปิดให้ จะกลายเป็นว่าบรรทัดหายไปเฉย ๆ แล้วต้องไปหาเองว่าทำต่อตรงไหน */
    setTimeout(function () {
      try {
        keep.getCell("qty").edit();
      } catch (e) {
        /* แก้ไม่ได้ก็ไม่เป็นไร */
      }
    }, 0);

    return true;
  }

  /* ห่อตารางให้มีหน้าตาเดียวกับตัวเลือกสองฝั่ง */
  function tableItems() {
    return {
      getRows: function () {
        return table ? table.getData() : [];
      },
      setRows: function (rows) {
        whenReady(function () {
          table.setData(rows);
          refreshTotals();
        });
      },
      clear: function () {
        whenReady(function () {
          table.clearData();
          refreshTotals();
        });
      },
      /* ยอดคงเหลือใหม่อยู่ใน byId แล้ว ตารางอ่านจากตรงนั้นตอน format
               จึงแค่สั่งวาดใหม่ ไม่ต้องยัดข้อมูลกลับเข้าไป */
      setProducts: function () {
        whenReady(function () {
          table.redraw(true);
        });
      },
    };
  }

  function buildColumns() {
    var G = window.GridCommon;

    /* บนจอแคบตารางเลื่อนแนวนอนอยู่แล้ว (ดู responsiveLayout ใน buildTable)
           บีบคอลัมน์ที่ยืดหยุ่นได้ให้แคบลง จะได้เห็นหลายคอลัมน์พร้อมกันมากขึ้น
           และปัดหาน้อยลง คอลัมน์ตัวเลขไม่บีบ เพราะแคบกว่านี้จะกดยาก */
    var narrow = (window.innerWidth || 1024) < 640;
    var wName = narrow ? 190 : 260;
    var wNote = narrow ? 110 : 150;

    return [
      G.col(G.control, {
        title: "#",
        field: "_seq",
        width: 46,
        hozAlign: "right",
        headerSort: false,
        minWidth: 36,
        formatter: function (cell) {
          return cell.getRow().getPosition(true);
        },
      }),

      {
        title: "สินค้า",
        field: "product_id",
        minWidth: wName,
        editor: "list",
        editorParams: G.pickerParams(productValues()),
        /* หน้าใบจ่ายเลือกสินค้าเองไม่ได้ ต้องมาจากใบเบิกเท่านั้น */
        editable: function () {
          return TYPE !== "issue";
        },
        formatter: function (cell) {
          var p = byId[cell.getValue()];
          if (!p) {
            return '<span class="grid-empty">— เลือกสินค้า —</span>';
          }
          return (
            '<span class="grid-user">' +
            escapeHtml(p.product_code) +
            "</span> " +
            escapeHtml(p.product_name)
          );
        },
      },

      {
        title: "คงเหลือ",
        field: "_stock",
        width: 108,
        hozAlign: "right",
        headerSort: false,
        formatter: function (cell) {
          var p = byId[cell.getRow().getData().product_id];
          if (!p) {
            return "";
          }

          /* ย้ำยอดคงเหลือไว้ข้างช่องกรอก เพราะเป็นตัวเลขที่ต้องดูทุกครั้งก่อนกรอกจำนวน
                       ต่ำกว่าจุดสั่งซื้อให้เป็นสีส้ม หมดให้เป็นสีแดง */
          var cls = "doc-stock";
          if (p.qty <= 0) {
            cls += " is-out";
          } else if (p.min_qty > 0 && p.qty <= p.min_qty) {
            cls += " is-low";
          }

          return '<span class="' + cls + '">' + fmt(p.qty) + "</span>";
        },
      },

      {
        title: "จำนวน",
        field: "qty",
        width: 120,
        hozAlign: "right",
        editor: "number",
        editorParams: { min: 0, step: 1, selectContents: true },
        formatter: function (cell) {
          var row = cell.getRow().getData();
          var v = num(cell.getValue());
          if (v <= 0) {
            /* ในใบจ่าย ศูนย์คือ "รอบนี้ไม่จ่ายบรรทัดนี้" ซึ่งเป็นการตัดสินใจ
                           ไม่ใช่ช่องที่ลืมกรอก เลข 0 จาง ๆ สื่อความต่างนี้ไม่ได้ */
            if (TYPE === "issue") {
              return (
                '<span class="grid-empty" title="รอบนี้ยังไม่จ่ายบรรทัดนี้' +
                ' ยอดจะค้างอยู่ในใบเบิก ทำใบจ่ายใหม่ได้หลังรับของเข้ามา">' +
                "ค้างไว้</span>"
              );
            }
            return '<span class="grid-empty">0</span>';
          }

          var cap = ceilingOf(row);
          var bad = cap >= 0 && v > cap + 0.00001;

          /* ใบเบิกขอเกินของที่มีได้ แต่ต้องเห็นว่ากำลังขอเกิน */
          if (!bad && TYPE === "request") {
            var p = byId[row.product_id];
            if (p && v > p.qty) {
              return (
                '<span class="doc-qty is-over" title="ขอเกินยอดคงเหลือ ' +
                'ส่งได้ แต่ตอนจ่ายจริงอาจได้ไม่ครบ">' +
                fmt(v) +
                "</span>"
              );
            }
          }

          return (
            '<span class="doc-qty' +
            (bad ? " is-bad" : "") +
            '">' +
            fmt(v) +
            "</span>"
          );
        },
      },

      {
        title: "หน่วย",
        field: "_unit",
        width: 92,
        headerSort: false,
        formatter: function (cell) {
          var p = byId[cell.getRow().getData().product_id];
          return p ? escapeHtml(p.unit_name) : "";
        },
      },

      /* ---------- ราคาซื้อ : เฉพาะใบรับเข้า ----------
               เป็นราคาที่ซื้อจริงของครั้งนี้ ไม่ใช่ราคามาตรฐานของสินค้า
               ระบบเก็บราคานี้ไว้กับรายการนั้นถาวร และจำเป็นราคาซื้อล่าสุดของสินค้า
               ของตัวเดียวกันคนละครั้งกรอกคนละราคาได้ตามจริง */
      G.col(
        { visible: TYPE === "receive" },
        {
          title: "ราคา/หน่วย",
          field: "unit_cost",
          width: 118,
          hozAlign: "right",
          editor: "number",
          editorParams: { min: 0, step: 0.01, selectContents: true },
          formatter: function (cell) {
            var v = num(cell.getValue());
            if (v <= 0) {
              return '<span class="grid-empty">— ยังไม่ระบุ —</span>';
            }
            return '<span class="doc-money">' + money(v) + "</span>";
          },
        },
      ),

      G.col({ visible: TYPE === "receive" }, G.control, {
        title: "เป็นเงิน",
        field: "_line_total",
        width: 118,
        hozAlign: "right",
        headerSort: false,
        formatter: function (cell) {
          var d = cell.getRow().getData();
          var t = num(d.qty) * num(d.unit_cost);
          if (t <= 0) {
            return '<span class="grid-empty">—</span>';
          }
          return '<b class="doc-money">' + money(t) + "</b>";
        },
      }),

      {
        title: "หมายเหตุ",
        field: "note",
        minWidth: wNote,
        editor: "input",
        formatter: function (cell) {
          var v = cell.getValue();
          return v && String(v).trim() !== ""
            ? escapeHtml(v)
            : '<span class="grid-empty">—</span>';
        },
      },

      G.col(G.control, {
        title: "",
        field: "_remove",
        width: 46,
        hozAlign: "center",
        headerSort: false,
        resizable: false,
        formatter: function () {
          /* ใบจ่ายลบบรรทัดได้ ถ้าคราวนี้ยังไม่จ่ายรายการนั้น
                       (จ่ายบางส่วนก่อน ที่เหลือค้างไว้ในใบเบิกให้จ่ายทีหลัง) */
          return '<button type="button" class="grid-remove" title="เอาบรรทัดนี้ออก">&#10005;</button>';
        },
        cellClick: function (e, cell) {
          cell.getRow().delete();
          refreshTotals();
        },
      }),
    ];
  }

  /* ============================================================
   *  ยิงบาร์โค้ดหรือ QR
   *
   *  เครื่องอ่านถอดรหัสให้เสร็จก่อนส่งออกมาเป็นการกดปุ่ม ตรงนี้จึงได้รับแค่ตัวอักษร
   *  ไม่รู้และไม่ต้องรู้ว่ามาจากรหัสแบบไหน โค้ดชุดนี้ใช้ร่วมกันได้ทั้งคู่
   *
   *  product_code อยู่ในมือแล้วตั้งแต่โหลดหน้า (DATA.products)
   *  ค้นในหน้าเว็บทันที ไม่ต้องถามเซิร์ฟเวอร์ — ยิงรัว ๆ ได้ไม่สะดุด
   *
   *  ---------------------------------------------------------------
   *  ยิงแล้วเกิดอะไรขึ้น ขึ้นกับว่าอยู่หน้าไหน
   *  ---------------------------------------------------------------
   *    รับเข้า   เพิ่มบรรทัดใหม่ ถ้ามีบรรทัดของสินค้านั้นแล้วให้บวกจำนวนแทน
   *    เบิก      เพิ่มเข้าฝั่งที่เลือก หรือบวกจำนวนถ้าเลือกไว้แล้ว
   *    จ่ายออก   ไม่เพิ่มบรรทัด เพราะรายการมาจากใบเบิกและแก้ไม่ได้
   *              ใช้ "ตรวจ" แทน — ยิงของที่หยิบมาแล้วดูว่าตรงกับใบเบิกไหม
   *              ถ้าไม่มีในใบ แปลว่าหยิบผิดตัว ซึ่งเป็นตอนที่ควรรู้ที่สุด
   * ============================================================ */
  var scanner = null;
  var byCode = {}; // product_code (ตัวพิมพ์ใหญ่) -> ข้อมูลสินค้า

  function indexByCode() {
    byCode = {};
    for (var i = 0; i < DATA.products.length; i++) {
      var p = DATA.products[i];
      if (p.product_code) {
        byCode[String(p.product_code).toUpperCase()] = p;
      }
    }
  }

  /* เสียงตอบรับ — สำคัญกว่าที่คิด เพราะคนยิงมองของอยู่ ไม่ได้มองจอ
       สร้างเสียงเองด้วย WebAudio ไม่ใช้ไฟล์เสียง จะได้ไม่ต้องแนบไฟล์เพิ่ม
       และไม่ต้องรอโหลด ซึ่งจะทำให้เสียงมาช้ากว่าการยิงครั้งถัดไป */
  var audioCtx = null;

  function beep(ok) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) {
        return;
      }
      if (!audioCtx) {
        audioCtx = new Ctx();
      }

      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);

      /* เจอ = เสียงสูงสั้น  ไม่เจอ = เสียงต่ำยาวกว่า แยกออกได้โดยไม่ต้องมองจอ */
      osc.frequency.value = ok ? 1320 : 220;
      gain.gain.value = 0.06;
      osc.start();
      osc.stop(audioCtx.currentTime + (ok ? 0.07 : 0.22));
    } catch (e) {
      /* เสียงไม่ใช่สิ่งจำเป็น ห้ามทำให้การยิงพัง */
    }
  }

  /* ตัวรับผลการยิงล่าสุด กล่องกล้องตั้งไว้ตอนเปิด
       ต้องแจ้งผ่านตัวนี้ ไม่ใช่ให้ไปอ่าน #scanMsg เองหลังเรียก onScan
       เพราะหน้ารับเข้าเขียนข้อความหลัง addRow เสร็จ ซึ่งเป็นคนละจังหวะ */
  var scanResultHook = null;

  /* กล่องข้อความใต้แถบเครื่องมือ ใช้ร่วมกันทั้งการยิงและการเลือกสินค้าเอง
       แยกออกมาจาก scanSay เพราะการเลือกเองไม่ควรมีเสียง และไม่ควรดึงโฟกัสกลับไป
       ช่องยิง — ตอนนั้นกำลังจะเปิดช่องจำนวนให้กรอกต่อ */
  function showNote(ok, text) {
    var box = el("scanMsg");
    if (!box) {
      return;
    }

    box.textContent = text;
    box.className = "doc-scan-msg " + (ok ? "is-ok" : "is-bad");
    setHidden(box, false);

    if (showNote._timer) {
      clearTimeout(showNote._timer);
    }
    showNote._timer = setTimeout(
      function () {
        setHidden(box, true);
      },
      ok ? 2500 : 6000,
    );
  }

  function scanSay(ok, text) {
    beep(ok);
    if (scanResultHook) {
      scanResultHook(ok, text);
    }
    showNote(ok, text);

    /* ดึงโฟกัสกลับมาที่ช่องยิงเสมอ การวาดตารางใหม่หลังเพิ่มบรรทัด
           อาจย้ายโฟกัสไปที่อื่นได้ ถ้าไม่ดึงกลับ การยิงครั้งถัดไปจะตกไปในช่องอื่น */
    if (scanner) {
      scanner.focus();
    }
  }

  function onScan(code) {
    var product = byCode[String(code).toUpperCase()];

    if (!product) {
      /* แยกสองกรณีให้ชัด เพราะทางแก้คนละเรื่องกัน
               ไม่มีในระบบเลย = ฉลากผิดหรือสินค้ายังไม่ได้ลงทะเบียน
               มีในระบบแต่ไม่อยู่ในรายการของหน้านี้ = เรื่องสิทธิ์ของหน่วยงาน */
      scanSay(
        false,
        'ไม่พบรหัส "' +
          code +
          '" ในรายการสินค้าของหน้านี้ ' +
          (TYPE === "request"
            ? "อาจเป็นสินค้าที่หน่วยงานนี้ไม่มีสิทธิ์เบิก"
            : "ตรวจดูว่าฉลากตรงกับสินค้าหรือไม่"),
      );
      return;
    }

    if (TYPE === "issue") {
      scanCheck(product);
      return;
    }
    scanAdd(product);
  }

  /* ---------- รับเข้า / เบิก : เพิ่มหรือบวกจำนวน ---------- */
  function scanAdd(product) {
    /* ฝั่งเบิกใช้ตัวเลือกสองฝั่ง ซึ่งมีวิธีเพิ่มของตัวเอง */
    if (items && typeof items.scan === "function") {
      var res = items.scan(product.product_id);
      scanSay(
        true,
        product.product_code +
          " " +
          product.product_name +
          (res.action === "increment"
            ? " — จำนวนเป็น " + fmt(res.qty) + " " + product.unit_name
            : " — เพิ่มแล้ว 1 " + product.unit_name),
      );
      refreshTotals();
      return;
    }

    if (!table) {
      return;
    }

    /* มีบรรทัดของสินค้านี้อยู่แล้วให้บวกจำนวน ไม่สร้างบรรทัดซ้ำ
           คนยิงของกองเดียวกันหลายชิ้นจะได้ไม่ต้องมานั่งรวมบรรทัดทีหลัง */
    var rows = table.getRows();
    for (var i = 0; i < rows.length; i++) {
      var d = rows[i].getData();
      if (Number(d.product_id) === Number(product.product_id)) {
        var next = num(d.qty) + 1;
        rows[i].update({ qty: next });
        rows[i].reformat();
        refreshTotals();
        scanSay(
          true,
          product.product_code +
            " " +
            product.product_name +
            " — จำนวนเป็น " +
            fmt(next) +
            " " +
            product.unit_name,
        );
        return;
      }
    }

    whenReady(function () {
      table
        .addRow({
          _id: ++seq,
          product_id: product.product_id,
          qty: 1,
          max_qty: 0,
          unit_cost: product.unit_cost ? product.unit_cost : 0,
          note: "",
        })
        .then(function (row) {
          row.reformat();
          refreshTotals();
          scanSay(
            true,
            product.product_code +
              " " +
              product.product_name +
              " — เพิ่มแล้ว 1 " +
              product.unit_name,
          );
        });
    });
  }

  /* ---------- จ่ายออก : ยิงเพื่อนับของที่หยิบจริง ----------
   *
   * หน้านี้เพิ่มบรรทัดเองไม่ได้ รายการต้องมาจากใบเบิกเท่านั้น
   * การยิงจึงไม่ใช่การ "เพิ่มของ" แต่เป็นการ "นับของที่หยิบใส่กล่องไปแล้ว"
   * ยิงหนึ่งครั้ง = หยิบหนึ่งชิ้น ตรงกับสิ่งที่มือทำอยู่พอดี
   *
   * เคยลองเปิดช่องจำนวนให้พิมพ์ต่อหลังยิง แต่ใช้ไม่ได้จริง
   * เพราะโฟกัสย้ายไปอยู่ในช่องจำนวนแล้ว พอยิงชิ้นถัดไป ตัวอักษรของรหัส
   * จะตกลงไปในช่องจำนวนแทน จำนวนที่กรอกไว้ก็เละ (เจอตอนทดสอบจริง)
   * จึงให้โฟกัสอยู่ที่ช่องยิงตลอด แล้วนับขึ้นทีละชิ้นแทน
   * ใครอยากพิมพ์จำนวนตรง ๆ ก็ยังคลิกที่ช่องได้เหมือนเดิม
   */
  function scanCheck(product) {
    if (!table) {
      return;
    }

    var rows = table.getRows();
    for (var i = 0; i < rows.length; i++) {
      var d = rows[i].getData();
      if (Number(d.product_id) !== Number(product.product_id)) {
        continue;
      }

      var ceiling = ceilingOf(d);
      var now = num(d.qty);

      if (ceiling >= 0 && now >= ceiling) {
        flashRow(rows[i]);
        scanSay(
          false,
          product.product_code +
            " " +
            product.product_name +
            (ceiling <= 0
              ? " — ของหมดคลัง จ่ายบรรทัดนี้ไม่ได้ ต้องรับของเข้ามาก่อน"
              : " — หยิบครบ " +
                fmt(ceiling) +
                " " +
                product.unit_name +
                " แล้ว ไม่ต้องหยิบเพิ่ม"),
        );
        return;
      }

      rows[i].update({ qty: now + 1 });
      rows[i].reformat();
      flashRow(rows[i]);
      refreshTotals();

      scanSay(
        true,
        product.product_code +
          " " +
          product.product_name +
          " — หยิบแล้ว " +
          fmt(now + 1) +
          " จาก " +
          fmt(d.max_qty) +
          " " +
          product.unit_name,
      );
      return;
    }

    scanSay(
      false,
      "หยิบผิดตัว — " +
        product.product_code +
        " " +
        product.product_name +
        " ไม่มีอยู่ในใบเบิกใบนี้",
    );
  }

  /* กะพริบบรรทัดครั้งเดียวให้รู้ว่าเป็นบรรทัดไหน แล้วเลื่อนไปให้เห็น */
  function flashRow(row) {
    var elRow = row.getElement();
    if (!elRow) {
      return;
    }

    elRow.classList.remove("row-scanned");
    void elRow.offsetWidth; /* บังคับให้ animation เริ่มใหม่ */
    elRow.classList.add("row-scanned");

    try {
      row.scrollTo().then(
        function () {},
        function () {},
      );
    } catch (e) {
      /* ไม่สำคัญ */
    }
  }

  function setupScanner() {
    var input = el("scanInput");
    if (!input || !window.BarcodeScan) {
      return;
    }

    indexByCode();

    scanner = window.BarcodeScan.attach({
      input: input,
      onScan: onScan,
    });

    initScanCam();

    /* พอโหลดเสร็จให้เคอร์เซอร์รออยู่ที่ช่องยิงเลย เดินมาถึงก็ยิงได้ทันที
           ยกเว้นหน้าเบิกที่คนส่วนใหญ่ไม่ได้ใช้เครื่องอ่าน และช่องแรกที่ควรโฟกัส
           คือหน่วยงานที่ขอเบิก ไม่ใช่ช่องยิง */
    if (TYPE !== "request") {
      input.focus();
    }
  }

  /* ---------- ส่อง QR ด้วยกล้อง ----------
   * ทางเข้าที่สองของรหัสสินค้า สำหรับคนที่เปิดหน้านี้จากมือถือที่ชั้นวาง
   * รหัสที่ส่องได้วิ่งเข้า onScan ตัวเดียวกับช่องยิง กติกาจึงเหมือนกันเป๊ะ
   * ปุ่มไม่ซ่อนแม้เปิดกล้องไม่ได้ — กดแล้วบอกเหตุผล ดีกว่าหายไปเฉย ๆ */
  function initScanCam() {
    var btn = el("btnScanCam");
    var box = el("scanCam");
    var video = el("scanCamVideo");
    if (!btn || !box || !video || !window.QRCAM) {
      return;
    }

    /* #appMain ตั้ง overflow: clip ไว้ กล่องลอยจึงต้องออกไปอยู่ใต้ body
           ไม่งั้นจะโดนตัดขอบจนคลุมไม่เต็มหน้าต่าง */
    if (box.parentElement !== document.body) {
      document.body.appendChild(box);
    }

    function say(text, ok) {
      var m = el("scanCamMsg");
      if (!m) {
        return;
      }
      m.textContent = text;
      m.className =
        "scan-cam-msg" +
        (ok === true ? " is-ok" : ok === false ? " is-bad" : "");
    }

    var closeTimer = null;

    function close() {
      if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = null;
      }
      scanResultHook = null;
      window.QRCAM.stop();
      setHidden(box, true);
      /* คืนโฟกัสให้ช่องยิง คนที่มีเครื่องยิงอยู่แล้วจะได้ยิงต่อได้เลย */
      if (scanner) {
        scanner.focus();
      }
    }

    function open() {
      if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = null;
      }
      setHidden(box, false);
      say("กำลังเปิดกล้อง…");

      /* ส่องติดแล้วปิดกล้องให้เอง — ปิดเฉพาะตอนสำเร็จ
               ส่องผิดตัวหรือหยิบครบแล้วยังเปิดค้าง เพราะต้องเล็งตัวใหม่ต่อทันที
               หยุดกล้องทันทีแต่หน่วงก่อนซ่อนกล่อง เว้นให้อ่านผล */
      scanResultHook = function (ok, text) {
        say(text, ok);
        if (!ok) {
          return;
        }

        window.QRCAM.stop(); /* กันอ่านซ้ำระหว่างรอปิด */
        if (closeTimer) {
          clearTimeout(closeTimer);
        }
        closeTimer = setTimeout(close, 1200);
      };

      window.QRCAM.start({
        video: video,
        /* ---------- บอกให้ตรงว่ากล้องของเครื่องนี้อ่านอะไรได้ ----------
         * เครื่องที่มีตัวถอดรหัสในตัว (Android เป็นหลัก) อ่านได้ทั้ง QR
         * และบาร์โค้ดแท่ง ส่วนเครื่องที่ต้องใช้ตัวสำรอง (iPhone,
         * Chrome บน Windows) อ่านได้เฉพาะ QR — ดูเหตุผลใน qr-cam.js
         *
         * เขียนให้ตรงตั้งแต่แรกสำคัญกว่าที่คิด ถ้าบอกว่าอ่านบาร์โค้ดได้
         * ทั้งที่เครื่องนั้นอ่านไม่ได้ ผู้ใช้จะเล็งค้างไว้เป็นนาทีแล้วสรุปว่า
         * ฉลากพิมพ์ผิดหรือระบบพัง ทั้งที่ทางออกคือใช้เครื่องยิงหรือส่อง QR */
        onReady: function (able) {
          say(
            able && able.barcode
              ? "เล็ง QR หรือบาร์โค้ดที่ฉลากให้อยู่ในกรอบ"
              : "เล็ง QR ให้อยู่ในกรอบ — กล้องของเครื่องนี้อ่านบาร์โค้ดแท่งไม่ได้ ให้ใช้เครื่องยิงแทน",
          );
        },
        /* ส่งเข้าเส้นทางเดียวกับช่องยิง กติกาทุกอย่างจึงเหมือนกันเป๊ะ
                   ผลจะย้อนกลับมาที่ scanResultHook ด้านบน */
        onCode: onScan,
        onError: function (msg) {
          say(msg, false);
        },
      });
    }

    btn.addEventListener("click", function () {
      if (window.QRCAM.running()) {
        close();
      } else {
        open();
      }
    });
    if (el("scanCamClose")) {
      el("scanCamClose").addEventListener("click", close);
    }
    if (el("scanCamFlip")) {
      el("scanCamFlip").addEventListener("click", function () {
        window.QRCAM.flip();
      });
    }

    /* กดพื้นที่มืดรอบกล่องเพื่อปิด เหมือนกล่องลอยอื่นในระบบ */
    box.addEventListener("click", function (e) {
      if (e.target === box) {
        close();
      }
    });
    document.addEventListener("keydown", function (e) {
      if (
        (e.key === "Escape" || e.keyCode === 27) &&
        !box.hasAttribute("hidden")
      ) {
        close();
      }
    });
  }

  /* ---------- เพิ่มบรรทัดว่าง ---------- */
  function addRow() {
    whenReady(function () {
      table
        .addRow({
          _id: ++seq,
          product_id: 0,
          qty: 0,
          max_qty: 0,
          unit_cost: 0,
          note: "",
        })
        .then(function (row) {
          row.getCell("product_id").edit(); // เปิดช่องเลือกสินค้าให้เลย
          refreshTotals();
        });
    });
  }

  /* ---------- สรุปท้ายตาราง ---------- */
  function refreshTotals() {
    if (!items) {
      return;
    }

    var rows = items.getRows();
    var lines = 0;
    var total = 0;
    for (var i = 0; i < rows.length; i++) {
      /* นับทุกแถวที่เลือกสินค้าแล้ว ไม่ใช่เฉพาะ id ที่เป็นบวก
               แถวที่ยังไม่ได้เลือกสินค้ามี product_id เป็น 0 หรือค่าว่างเท่านั้น
               ส่วนค่าติดลบใช้กับรายการตัวอย่างในคู่มือ ซึ่งต้องนับให้เห็นบนจอ
               ไม่งั้นยอด "เป็นเงิน" จะขึ้นตัวเลขแต่ "บรรทัด/จำนวน" ขึ้นศูนย์
               (ปุ่มบันทึกถูกปิดไว้ระหว่างสาธิตอยู่แล้ว จึงไม่มีทางส่งของสมมติออกไป) */
      if (num(rows[i].product_id) !== 0 && num(rows[i].qty) > 0) {
        lines++;
        total += num(rows[i].qty);
      }
    }

    el("sumLines").textContent = String(lines);
    el("sumQty").textContent = fmt(total);

    /* ระหว่างสาธิตต้องคงสภาพปิดไว้ ไม่ให้ refreshTotals เปิดกลับให้ */
    el("btnSave").disabled = lines === 0 || !!demoBackup;

    /* ใบรับเข้ามีเงิน จึงต้องเห็นยอดรวมก่อนกดบันทึก
           เป็นตัวเลขที่คนคลังเอาไปเทียบกับใบกำกับที่ถืออยู่ในมือ */
    if (el("sumMoney")) {
      var baht = 0;
      for (var m = 0; m < rows.length; m++) {
        baht += num(rows[m].qty) * num(rows[m].unit_cost);
      }
      el("sumMoney").textContent = money(baht);
    }
  }

  /* ============================================================
   *  ตรวจก่อนส่ง แล้วบันทึก
   * ============================================================ */
  function showError(message) {
    el("formErrorText").textContent = message;
    setHidden(el("formError"), false);
    if (typeof window.appError === "function") {
      window.appError(message, "บันทึกเอกสารไม่สำเร็จ");
    }
  }

  function clearError() {
    setHidden(el("formError"), true);
  }

  function collect() {
    var rows = items.getRows();
    var out = [];
    var seen = {};

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var pid = num(r.product_id);
      var qty = num(r.qty);

      if (pid <= 0 && qty <= 0) {
        continue;
      } // บรรทัดว่าง ข้ามไปเงียบ ๆ

      if (pid <= 0) {
        return { error: "บรรทัดที่ " + (i + 1) + ": ยังไม่ได้เลือกสินค้า" };
      }

      /* ใบจ่ายรองรับการจ่ายบางส่วนอยู่แล้ว (ดู issue_save ใน backend/issue.php)
               บรรทัดที่เป็นศูนย์จึงไม่ใช่ข้อผิดพลาด แต่แปลว่ารอบนี้ไม่จ่ายตัวนี้
               ไม่ส่งไป ยอดค้างของมันในใบเบิกจะไม่ถูกแตะ และใบเบิกไม่ถูกปิด */
      if (qty <= 0) {
        if (TYPE === "issue") {
          continue;
        }
        return {
          error: "บรรทัดที่ " + (i + 1) + ": กรุณากรอกจำนวนที่มากกว่า 0",
        };
      }

      if (seen[pid]) {
        return {
          error:
            "บรรทัดที่ " + (i + 1) + ": สินค้าซ้ำกับบรรทัดที่ " + seen[pid],
        };
      }
      seen[pid] = i + 1;

      var cap = ceilingOf(r);
      if (cap >= 0 && qty > cap + 0.00001) {
        var p = byId[pid];
        var who = p
          ? p.product_code + " " + p.product_name
          : "บรรทัดที่ " + (i + 1);
        return {
          error:
            cap <= 0
              ? who +
                ": ของหมดคลัง จ่ายไม่ได้ — ตั้งจำนวนเป็น 0 เพื่อค้างไว้จ่ายรอบหน้า"
              : who + ": จ่ายได้ไม่เกิน " + fmt(cap),
        };
      }

      var line = { product_id: pid, qty: qty, note: r.note || "" };
      if (TYPE === "receive") {
        var cost = num(r.unit_cost);
        if (cost <= 0) {
          var pp = byId[pid];
          return {
            error:
              (pp
                ? pp.product_code + " " + pp.product_name
                : "บรรทัดที่ " + (i + 1)) +
              ": กรุณากรอกราคาต่อหน่วย — ต้องใช้คิดค่าใช้จ่ายส่งบัญชี",
          };
        }
        line.unit_cost = cost;
      }
      out.push(line);
    }

    if (out.length === 0) {
      return {
        error:
          TYPE === "issue"
            ? "ยังไม่มีรายการไหนที่จ่ายได้ — ของในใบเบิกนี้หมดคลังทั้งหมด ต้องรับของเข้ามาก่อน"
            : "กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ",
      };
    }
    return { items: out };
  }

  function busy(on) {
    var btn = el("btnSave");
    btn.disabled = on;
    setHidden(btn.querySelector(".btn-icon"), on);
    setHidden(btn.querySelector(".btn-spinner"), !on);
  }

  function save() {
    clearError();

    var got = collect();
    if (got.error) {
      showError(got.error);
      return;
    }

    if (has("departmentId") && num(el("departmentId").value) <= 0) {
      showError("กรุณาเลือกหน่วยงานที่ขอเบิก");
      el("departmentId").focus();
      return;
    }
    if (has("refDocId") && num(el("refDocId").value) <= 0) {
      showError("กรุณาเลือกใบเบิกที่จะจ่ายของ");
      el("refDocId").focus();
      return;
    }
    if (has("receiverName") && el("receiverName").value.trim() === "") {
      showError("กรุณากรอกชื่อผู้รับของ");
      el("receiverName").focus();
      return;
    }

    var payload = {
      action: EDIT_ID > 0 ? "update" : "save",
      doc_type: TYPE,
      csrf_token: CFG.csrfToken,
      doc_date: el("docDate").value,
      note: el("docNote").value,
      items: got.items,
    };
    if (EDIT_ID > 0) {
      payload.doc_id = EDIT_ID;
    }
    if (has("departmentId")) {
      payload.department_id = num(el("departmentId").value);
    }
    if (has("refDocId")) {
      payload.ref_doc_id = num(el("refDocId").value);
    }
    if (has("receiverName")) {
      payload.receiver_name = el("receiverName").value;
    }

    confirmSave(payload, got.items.length);
  }

  function confirmSave(payload, lines) {
    var text = CFG.confirmText.replace("{n}", String(lines));

    var go = function (yes) {
      if (!yes) {
        return;
      }
      busy(true);

      post(
        payload,
        function (res) {
          busy(false);
          if (typeof window.appSuccess === "function") {
            window.appSuccess(res.message);
          } else {
            alert(res.message);
          }

          /* โหมดแก้ไข: กลับไปหน้ารายการใบเบิกเพื่อดูสถานะ/ไทม์ไลน์ที่อัปเดตแล้ว
                   ไม่ล้างฟอร์มไว้ทำใบใหม่แบบตอนสร้าง */
          if (EDIT_ID > 0) {
            editSaved = true;
            window.location.href = "main.php?page=request_list";
            return;
          }

          /* เอกสารบันทึกแล้วแก้ไม่ได้ จึงล้างหน้าให้พร้อมทำใบถัดไป
                   ไม่พาไปหน้าประวัติ เพราะส่วนใหญ่ทำต่อกันหลายใบรวดเดียว */
          resetForm();
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
          title: CFG.confirmTitle,
          text: text,
          confirmText: "ยืนยันบันทึก",
        })
        .then(go);
    } else {
      go(window.confirm(text));
    }
  }

  function resetForm() {
    items.clear();
    el("docNote").value = "";
    if (has("receiverName")) {
      el("receiverName").value = "";
    }

    if (has("refDocId")) {
      /* ใบเบิกที่เพิ่งจ่ายไปอาจปิดแล้ว ต้องดึงรายการใหม่ ไม่ใช่ใช้ของเดิมค้างไว้ */
      el("refDocId").innerHTML = '<option value="">— เลือกใบเบิก —</option>';
      setHidden(el("reqInfo"), true);
      post(
        { action: "bootstrap", doc_type: TYPE },
        function (res) {
          DATA.requests = res.requests || [];
          DATA.products = res.products || [];
          byId = {};
          for (var i = 0; i < DATA.products.length; i++) {
            byId[DATA.products[i].product_id] = DATA.products[i];
          }
          for (var j = 0; j < DATA.requests.length; j++) {
            var r = DATA.requests[j];
            var op = document.createElement("option");
            op.value = r.doc_id;
            op.textContent = requestLabel(r);
            el("refDocId").appendChild(op);
          }
          setHidden(el("noRequest"), DATA.requests.length > 0);
          if (window.SelectSearch) {
            window.SelectSearch.refresh(el("refDocId"));
          }
        },
        function () {
          /* โหลดไม่ได้ก็ปล่อยให้ผู้ใช้รีเฟรชเอง */
        },
      );
    } else {
      /* หน้าอื่นก็ต้องรู้ยอดคงเหลือใหม่หลังบันทึก ไม่งั้นตัวเลขที่แสดงจะค้างของเก่า */
      post(
        { action: "bootstrap", doc_type: TYPE },
        function (res) {
          DATA.products = res.products || [];
          byId = {};
          for (var i = 0; i < DATA.products.length; i++) {
            byId[DATA.products[i].product_id] = DATA.products[i];
          }
          items.setProducts(DATA.products);
        },
        function () {},
      );
    }

    refreshTotals();
  }

  /* ============================================================
   *  เริ่มทำงาน
   * ============================================================ */
  function init() {
    if (el("btnAddRow")) {
      el("btnAddRow").addEventListener("click", addRow);
    }
    el("btnSave").addEventListener("click", save);
    el("docNote").addEventListener("input", clearError);

    /* กัน Enter ในช่องหัวเอกสารไปกดปุ่มบันทึกโดยไม่ตั้งใจ */
    var form = el("docForm");
    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
      });
    }

    /* เตือนก่อนออกจากหน้าถ้ายังมีรายการค้างอยู่
           เอกสารที่กรอกไปครึ่งทางแล้วหายเพราะเผลอกดปุ่มย้อนกลับ เจ็บกว่าเตือนเกิน */
    window.addEventListener("beforeunload", function (e) {
      if (!items) {
        return;
      }

      /* รายการตัวอย่างในคู่มือไม่ใช่งานของผู้ใช้ ไม่ต้องเตือนตอนออกจากหน้า
               ยังคงใช้ > 0 ตรงนี้ เพราะ id ติดลบมีแต่ของสาธิตเท่านั้น
               editSaved = เพิ่งกดบันทึกแก้ไขสำเร็จ กำลัง redirect เอง ไม่ต้องเตือน */
      if (demoBackup || editSaved) {
        return;
      }

      var rows = items.getRows();
      for (var i = 0; i < rows.length; i++) {
        if (num(rows[i].product_id) > 0) {
          e.preventDefault();
          e.returnValue = "";
          return "";
        }
      }
    });

    load();
  }

  /* ============================================================
   *  ชุดข้อมูลสาธิตสำหรับคู่มือการใช้งาน
   * ============================================================
   *  หน้าทำเอกสารเปิดมาแล้วตารางรายการว่างเปล่าเสมอ คู่มือที่อธิบายว่า
   *  "กรอกจำนวน ราคาจะเติมให้อัตโนมัติ แล้วดูยอดรวมด้านล่าง"
   *  จึงชี้ไปที่ตารางที่ไม่มีบรรทัดเดียว ผู้ใช้ต้องจินตนาการเอาเองทั้งหมด
   *
   *  ตรงนี้ใส่บรรทัดตัวอย่างระหว่างเปิดคู่มือ แล้วคืนสภาพเดิมตอนปิด
   *  (ตัวเรียกอยู่ที่ assets/js/app-guide.js — ดู window.GuideDemo)
   *
   *  ---------------------------------------------------------------
   *  ทำเฉพาะตอนที่ผู้ใช้ยังไม่ได้กรอกอะไรไว้
   *  ---------------------------------------------------------------
   *  ถ้ามีของกรอกค้างอยู่แล้วจะไม่แตะเลย เพราะการล้างของที่คนกำลังทำอยู่
   *  เพื่อโชว์ตัวอย่าง คือการทำงานของเขาหายไปเพื่อประโยชน์ของคู่มือ
   *  ซึ่งไม่คุ้มกันไม่ว่ากรณีไหน
   * ============================================================ */
  var demoBackup = null;

  var DEMO_PRODUCTS = [
    {
      product_id: -101,
      product_code: "EX-PLB-001",
      product_name: "เสื้อฟอร์ม ผู้ชาย PRO (ดำ) ไซซ์ M",
      unit_name: "ตัว",
      qty: 120,
      min_qty: 20,
      max_qty: 0,
      unit_cost: 118,
      category_id: -1,
      category_name: "ตัวอย่าง",
      sub_category_name: "เสื้อฟอร์ม",
    },
    {
      product_id: -102,
      product_code: "EX-PLN-002",
      product_name: "เสื้อฟอร์ม ผู้หญิง PRO (น้ำเงิน) ไซซ์ L",
      unit_name: "ตัว",
      qty: 60,
      min_qty: 24,
      max_qty: 0,
      unit_cost: 5.5,
      category_id: -1,
      category_name: "ตัวอย่าง",
      sub_category_name: "เสื้อฟอร์ม",
    },
  ];

  function demoSetup() {
    if (demoBackup) {
      return true;
    }
    if (!items) {
      return false;
    }

    /* มีของกรอกค้างอยู่ = ไม่แตะ ปล่อยให้คู่มืออธิบายกับของจริงของเขาเอง */
    var now = items.getRows();
    for (var k = 0; k < now.length; k++) {
      if (num(now[k].product_id) > 0) {
        return false;
      }
    }

    demoBackup = {
      products: DATA.products,
      byId: byId,
      saveOff: el("btnSave") ? el("btnSave").disabled : false,
      requests: DATA.requests,
      refValue: has("refDocId") ? el("refDocId").value : "",
      refHtml: has("refDocId") ? el("refDocId").innerHTML : "",
      noReqOff: has("noRequest") ? el("noRequest").hidden : true,
      receiver: has("receiverName") ? el("receiverName").value : "",
    };

    DATA.products = DEMO_PRODUCTS.concat(DATA.products);
    for (var i = 0; i < DEMO_PRODUCTS.length; i++) {
      byId[DEMO_PRODUCTS[i].product_id] = DEMO_PRODUCTS[i];
    }
    items.setProducts(DATA.products);

    /* ใบรับเข้ามีช่องราคาให้กรอก จึงใส่ราคามาด้วยเพื่อให้เห็นทั้งยอดต่อบรรทัด
           และยอดรวมเป็นเงิน ส่วนใบเบิกไม่มีเรื่องเงิน ใส่แค่จำนวนพอ */
    if (TYPE === "receive") {
      items.setRows([
        {
          _id: 9001,
          product_id: -101,
          qty: 10,
          max_qty: 0,
          unit_cost: 118,
          note: "",
        },
        {
          _id: 9002,
          product_id: -102,
          qty: 24,
          max_qty: 0,
          unit_cost: 5.5,
          note: "ตัวอย่าง: ใส่หมายเหตุรายบรรทัดได้",
        },
      ]);
    } else if (TYPE === "issue") {
      /* ---------- ใบจ่าย ----------
       * หน้านี้เริ่มจากใบเบิกเสมอ ตารางรายการจึงว่างจนกว่าจะเลือกใบ
       * และถ้าตอนนั้นไม่มีใบเบิกที่รอจ่ายอยู่เลย จะขึ้นว่า "ไม่มีใบเบิกรอจ่าย"
       * คู่มือเลยอธิบายกับหน้าจอที่ไม่มีอะไรให้ดูสักอย่าง
       *
       * ใส่ใบเบิกสมมติเข้าไปแล้วเรียก pickRequest() ตัวจริง
       * หน้าจอที่ได้จึงเหมือนตอนใช้งานจริงทุกอย่าง ไม่ใช่ภาพวาดแยกต่างหาก
       */
      DATA.requests = [
        {
          doc_id: -9001,
          doc_no: "ตัวอย่าง-RQ-0000",
          doc_date: CFG.today || "",
          requester: "__demo__",
          requester_name: "ชื่อผู้ขอเบิกตัวอย่าง",
          company_name: "บริษัทตัวอย่าง",
          department_name: "หน่วยงานตัวอย่าง",
          pending_qty: 17,
          items: [
            { product_id: -101, qty: 10, qty_issued: 5, qty_pending: 5 },
            { product_id: -102, qty: 12, qty_issued: 0, qty_pending: 12 },
          ],
        },
      ].concat(DATA.requests || []);

      if (has("refDocId")) {
        var op = document.createElement("option");
        op.value = "-9001";
        op.textContent = requestLabel(DATA.requests[0]);
        el("refDocId").appendChild(op);
        el("refDocId").value = "-9001";
        if (window.SelectSearch) {
          window.SelectSearch.refresh(el("refDocId"));
        }
      }
      setHidden(el("noRequest"), true);

      pickRequest(); // เดินเส้นทางเดียวกับตอนผู้ใช้เลือกใบเบิกจริง
    } else {
      items.setRows([
        { product_id: -101, qty: 5, note: "" },
        { product_id: -102, qty: 12, note: "ตัวอย่าง: ระบุเหตุผลที่ขอเบิกได้" },
      ]);
    }

    if (el("btnSave")) {
      el("btnSave").disabled = true;
    }
    demoBanner(true);

    if (window.lucide) {
      window.lucide.createIcons();
    }
    return true;
  }

  function demoTeardown() {
    if (!demoBackup) {
      return;
    }

    var b = demoBackup;
    demoBackup = null;

    items.clear();

    DATA.products = b.products;
    DATA.requests = b.requests;
    byId = b.byId;
    items.setProducts(DATA.products);

    if (has("refDocId")) {
      el("refDocId").innerHTML = b.refHtml;
      el("refDocId").value = b.refValue;
      if (window.SelectSearch) {
        window.SelectSearch.refresh(el("refDocId"));
      }
    }
    if (has("noRequest")) {
      setHidden(el("noRequest"), b.noReqOff);
    }
    if (has("receiverName")) {
      el("receiverName").value = b.receiver;
    }

    /* ใบจ่ายต้องล้างแถบข้อมูลใบเบิกด้วย ไม่งั้นจะค้างบอกว่ากำลังจ่ายใบสมมติอยู่
           picked ถูกตั้งโดย pickRequest() ตอนสาธิต จึงต้องคืนเป็นไม่ได้เลือกอะไร */
    if (TYPE === "issue") {
      picked = null;
      setHidden(el("reqInfo"), true);
    }

    if (el("btnSave")) {
      el("btnSave").disabled = b.saveOff;
    }
    demoBanner(false);
    refreshTotals();
  }

  function demoBanner(on) {
    var old = el("dfDemoNote");
    if (old && old.parentNode) {
      old.parentNode.removeChild(old);
    }
    if (!on) {
      return;
    }

    var host = el("items") || el("tfLeft");
    if (!host) {
      return;
    }

    var wrap = host.closest
      ? host.closest(".tf-wrap, section, div")
      : host.parentNode;
    if (!wrap || !wrap.parentNode) {
      wrap = host;
    }

    var note = document.createElement("p");
    note.id = "dfDemoNote";
    note.className = "pd-demo-note";
    note.innerHTML =
      '<i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>' +
      "<span><b>รายการตัวอย่างสำหรับสาธิตเท่านั้น</b> " +
      "ไม่ใช่สินค้าจริง และจะไม่ถูกบันทึก — ปิดคู่มือแล้วรายการจะหายไปเอง</span>";

    wrap.parentNode.insertBefore(note, wrap);
  }

  window.GuideDemo = {
    page: {
      receive: "receive_new",
      request: "request_new",
      issue: "issue_new",
    }[TYPE],
    setup: demoSetup,
    teardown: demoTeardown,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
