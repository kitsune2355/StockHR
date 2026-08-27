/* ============================================================
 *  catalog.js — ตารางทะเบียนสินค้าแบบแก้ในช่องได้ (Tabulator)
 *
 *  ใช้ร่วมกัน 4 ตาราง แยกด้วย CATALOG.tab
 *      category      หมวดหมู่หลัก      (pages/product_category.php)
 *      sub_category  หมวดหมู่ย่อย      (pages/product_category.php)
 *      unit          หน่วยนับ          (pages/product_category.php)
 *      product       ทะเบียนสินค้า     (pages/product_list.php)
 *
 *  โครงเหมือน agencies.js ทุกอย่าง ต่างกันแค่ชุดคอลัมน์:
 *    - แก้สะสมไว้ก่อน แล้วกด "บันทึก" ส่งทั้งชุดครั้งเดียวไปที่ api/catalog.php
 *    - สถานะของแต่ละแถวเก็บใน _state : clean / new / edited
 *    - ตราบใดที่ยังไม่กดบันทึก ฐานข้อมูลยังไม่ถูกแตะเลย ยกเลิกได้ตลอด
 *
 *  ไม่มีปุ่มลบข้อมูลที่บันทึกแล้ว มีแต่ปุ่มเอาแถวใหม่ที่เพิ่งเพิ่มออก
 *  เหมือนหน้าอื่นในระบบ — ของที่บันทึกแล้วให้ "ปิดใช้งาน" แทนการลบ
 *  เพราะรหัสสินค้าและหมวดหมู่ถูกอ้างจากเอกสารรับเข้า/เบิกออก
 *
 *  ยกเว้นแท็บหน่วยนับ ที่ลบได้เฉพาะหน่วยที่ยังไม่มีสินค้าใช้เลย (used = 0)
 *  เพราะหน้าทะเบียนสินค้าสร้างหน่วยใหม่ให้อัตโนมัติเมื่อพิมพ์ชื่อที่ยังไม่มี
 *  พิมพ์ผิดครั้งเดียวก็ได้หน่วยขยะที่ไม่มีใครใช้ค้างอยู่ในรายการให้เลือกตลอดไป
 *  ถ้าไม่มีทางลบ ก็ไม่มีทางเก็บกวาด — ส่วนหน่วยที่มีสินค้าใช้อยู่ยังลบไม่ได้เหมือนเดิม
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.CATALOG;
  if (!CFG || typeof Tabulator === "undefined") {
    return;
  }

  var TAB = CFG.tab;

  /* ---------- นิยามของแต่ละตาราง รวมไว้ที่เดียว ----------
   * idField   ชื่อคอลัมน์ primary key
   * source    ข้อมูลตั้งต้นที่หน้าเว็บฝังมา
   * fields    คอลัมน์ที่ต้องเทียบว่าถูกแก้หรือยัง (key = ชื่อ field จริง)
   * unit      คำเรียกจำนวนแถวในข้อความ เช่น "เพิ่มใหม่ 2 หมวด"
   */
  var SPEC = {
    category: {
      idField: "category_id",
      source: CFG.categories,
      fields: ["category_code", "category_name", "is_active"],
      unit: "หมวด",
      empty: 'ยังไม่มีหมวดหมู่หลัก กดปุ่ม "เพิ่มแถว" เพื่อเริ่มต้น',
    },
    sub_category: {
      idField: "sub_category_id",
      source: CFG.subCategories,
      fields: [
        "sub_category_code",
        "sub_category_name",
        "category_id",
        "is_active",
      ],
      unit: "หมวดย่อย",
      empty: 'ยังไม่มีหมวดหมู่ย่อย กดปุ่ม "เพิ่มแถว" เพื่อเริ่มต้น',
    },
    unit: {
      idField: "unit_id",
      source: CFG.units,
      fields: ["unit_name", "is_active"],
      unit: "หน่วย",
      empty: 'ยังไม่มีหน่วยนับ กดปุ่ม "เพิ่มแถว" เพื่อเริ่มต้น',
      /* แท็บเดียวที่ลบของที่บันทึกแล้วได้ ดูเหตุผลที่หัวไฟล์ */
      canDelete: true,
    },
    product: {
      idField: "product_id",
      source: CFG.products,
      /* old_code ไม่มีคอลัมน์ให้เห็นแล้ว แต่ยังต้องอยู่ในชุดนี้
               เพื่อให้ค่าเดิมถูกส่งกลับไปพร้อมตอนบันทึก ไม่งั้นแก้แถวไหน
               รหัสเดิมของแถวนั้นจะถูกล้างเป็นค่าว่างทันที */
      fields: [
        "old_code",
        "product_name",
        "category_id",
        "sub_category_id",
        "unit_id",
        "unit_cost",
        "min_qty",
        "max_qty",
        "is_active",
      ],
      unit: "รายการ",
      empty: 'ยังไม่มีสินค้า กดปุ่ม "เพิ่มสินค้า" เพื่อเริ่มต้น',
    },
  };

  var SPEC_NOW = SPEC[TAB];
  if (!SPEC_NOW) {
    return;
  }

  var table = null;
  var newSeq = 0;
  var original = {}; // ค่าเดิมของแต่ละแถว ใช้เทียบว่าถูกแก้ตรงไหน

  /* ตอนเราสั่งโหลดหน้าใหม่เอง ไม่ต้องเตือนว่ามีงานค้าง */
  var skipUnloadWarning = false;

  function reloadPage() {
    skipUnloadWarning = true;
    window.location.reload();
  }

  /* ---------- ตัวช่วยเล็ก ๆ ---------- */
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

  function money(value) {
    var n = Number(value);
    if (isNaN(n)) {
      return "0.00";
    }
    return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  /* ---------- ตารางค้นชื่อจาก options ที่หน้าเว็บส่งมา ---------- */
  function lookupOf(list, keyField, labelField) {
    var map = {};
    for (var i = 0; i < list.length; i++) {
      map[list[i][keyField]] = list[i][labelField];
    }
    return map;
  }

  var catLookup = lookupOf(
    CFG.catOptions || [],
    "category_id",
    "category_name",
  );
  var unitLookup = lookupOf(CFG.unitOptions || [], "unit_id", "unit_name");

  /* หมวดหมู่ย่อยใช้ชื่อสั้นทั้งในช่องและรายการตัวเลือก */
  var subLookup = lookupOf(
    CFG.subOptions || [],
    "sub_category_id",
    "sub_category_name",
  );
  var subPicker = lookupOf(
    CFG.subOptions || [],
    "sub_category_id",
    "sub_category_name",
  );

  function subPickerFor(categoryId) {
    var values = {};
    var list = CFG.subOptions || [];
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i].category_id) === Number(categoryId)) {
        values[list[i].sub_category_id] = list[i].sub_category_name;
      }
    }
    return values;
  }

  /* ---------- เตรียมข้อมูลตั้งต้น ---------- */
  function buildRows() {
    var src = SPEC_NOW.source || [];
    var rows = [];

    for (var i = 0; i < src.length; i++) {
      var row = { _key: "r" + src[i][SPEC_NOW.idField], _state: "clean" };
      copyInto(row, src[i]);
      rows.push(row);
      original[row._key] = snapshotOf(row);
    }
    return rows;
  }

  /* คัดมาเฉพาะคอลัมน์ที่ตารางนี้ใช้ ไม่ลากทุกคีย์จาก backend มาทั้งก้อน */
  function copyInto(row, src) {
    row[SPEC_NOW.idField] = Number(src[SPEC_NOW.idField]);

    for (var i = 0; i < SPEC_NOW.fields.length; i++) {
      var f = SPEC_NOW.fields[i];
      row[f] = src[f];
    }

    /* คอลัมน์เสริมของสินค้า */
    if (TAB === "product") {
      row.product_code = src.product_code;
      row.category_id = Number(src.category_id || 0);

      /* รูปสินค้าถูกบันทึกทันทีตอนอัปโหลด จึงต้องอยู่นอกชุดที่ snapshotOf() เทียบ
         ไม่งั้นแค่เพิ่มรูปจะทำให้แถวขึ้นว่า "แก้แล้วยังไม่บันทึก" ทั้งที่บันทึกไปแล้ว */
      row.images = src.images || [];
    }
    if (TAB === "category") {
      row.sub_count = Number(src.sub_count);
    }
    if (TAB === "unit") {
      /* จำนวนสินค้าที่ใช้หน่วยนี้ — เป็นตัวตัดสินว่าลบได้ไหม
         มาจากฝั่งเซิร์ฟเวอร์ตอนโหลดหน้า ไม่ได้นับใหม่ฝั่งนี้ */
      row.used = Number(src.used || 0);
    } else if (TAB !== "product") {
      row.product_count = Number(src.product_count || 0);
    }
  }

  function syncProductCategory(row) {
    if (TAB !== "product") {
      return;
    }

    var data = row.getData();
    var list = CFG.subOptions || [];
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i].sub_category_id) === Number(data.sub_category_id)) {
        if (Number(data.category_id) !== Number(list[i].category_id)) {
          data.sub_category_id = 0;
          row.getCell("sub_category_id").setValue(0);
        }
        return;
      }
    }
  }

  /* ค่าที่ใช้เทียบว่าแถวถูกแก้หรือยัง — key ต้องเป็นชื่อ field จริงของคอลัมน์
       เพราะ applyRowClass เอาไปไล่เทียบทีละช่องเพื่อระบายเฉพาะช่องที่เปลี่ยน */
  function snapshotOf(row) {
    var s = {};
    for (var i = 0; i < SPEC_NOW.fields.length; i++) {
      var f = SPEC_NOW.fields[i];
      var v = row[f];
      /* ตัวเลขกับข้อความต้องเทียบในรูปแบบเดียวกันเสมอ
               ไม่งั้น 5 กับ "5" จะถูกนับว่าเปลี่ยนทั้งที่ค่าเท่ากัน */
      s[f] = v === null || v === undefined ? "" : String(v);
    }
    return s;
  }

  function snapshot(row) {
    return JSON.stringify(snapshotOf(row));
  }

  /* ---------- คำนวณสถานะแถวใหม่หลังผู้ใช้แก้ค่า ---------- */
  function markChanged(row) {
    var data = row.getData();
    if (data._state === "new" || data._state === "removed") {
      return;
    }

    /* แก้แล้วแก้กลับเป็นค่าเดิม ไม่ต้องนับว่าเปลี่ยน */
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

    /* แถวใหม่ระบายทั้งแถว เพราะทั้งแถวคือของใหม่จริง ๆ
       แถวที่กดลบไว้ก็ระบายทั้งแถวเหมือนกัน เพราะทั้งแถวกำลังจะหายไป */
    elRow.classList.remove("row-new");
    elRow.classList.remove("row-removed");
    if (st === "new") {
      elRow.classList.add("row-new");
    } else if (st === "removed") {
      elRow.classList.add("row-removed");
    }

    /* ส่วนแถวที่แก้ ระบายเฉพาะช่องที่ค่าเปลี่ยน จะได้เห็นทันทีว่าแก้คอลัมน์ไหนไว้ */
    var was = st === "edited" ? original[data._key] : null;
    var now = was ? snapshotOf(data) : null;
    var cells = row.getCells();

    for (var i = 0; i < cells.length; i++) {
      var cellEl = cells[i].getElement();
      if (!cellEl) {
        continue;
      }

      var f = cells[i].getField();
      var changed = !!(was && f && was.hasOwnProperty(f) && now[f] !== was[f]);

      if (changed) {
        cellEl.classList.add("cell-edited");
      } else {
        cellEl.classList.remove("cell-edited");
      }
    }
  }

  /* ============================================================
   *  ชิ้นส่วนคอลัมน์ที่ทุกตารางใช้เหมือนกัน
   * ============================================================ */
  var G = window.GridCommon;

  function colCollapse() {
    return G.col(G.control, {
      formatter: "responsiveCollapse",
      width: 36,
      minWidth: 36,
      hozAlign: "center",
      resizable: false,
      headerSort: false,
      /* ปุ่มนี้ต้องอยู่เสมอ ถ้าถูกยุบไปด้วยจะไม่มีทางเปิดดูคอลัมน์ที่ยุบไว้ */
      responsive: 0,
    });
  }

  function colSeq() {
    return G.col(G.control, {
      title: "#",
      field: "_seq",
      width: 52,
      hozAlign: "right",
      headerSort: false,
      resizable: false,
      /* เลขลำดับเป็นของที่ยุบทิ้งได้ก่อนใครเพื่อน (เลขยิ่งมาก ยิ่งถูกยุบก่อน)
         เพราะเป็นลำดับบนจอ ไม่ใช่ข้อมูลจริงของสินค้า */
      responsive: 7,
      cssClass: "cell-seq",
      formatter: function (cell) {
        var st = cell.getRow().getData()._state;
        if (st === "new") {
          return '<span class="grid-tag grid-tag-new">ใหม่</span>';
        }
        if (st === "removed") {
          return '<span class="grid-tag grid-tag-del">ลบ</span>';
        }
        return cell.getRow().getPosition(true);
      },
    });
  }

  function colStatus(width) {
    return G.col(
      G.labelled(function (v) {
        return Number(v) === 1 ? "เปิดใช้งาน" : "ปิดใช้งาน";
      }),
      {
        title: "สถานะ",
        field: "is_active",
        ...G.filterStatus(),
        width: width || 140,
        hozAlign: "center",
        responsive: 1,
        download: true,
        clipboard: true,
        cssClass: "cell-switch",
        formatter: function (cell) {
          var on = Number(cell.getValue()) === 1;
          return (
            '<span class="grid-switch-wrap">' +
            '<button type="button" role="switch" aria-checked="' +
            (on ? "true" : "false") +
            '"' +
            ' class="grid-switch' +
            (on ? " is-on" : "") +
            '"' +
            ' title="' +
            (on ? "กดเพื่อปิดใช้งาน" : "กดเพื่อเปิดใช้งาน") +
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
          /* แถวที่กดลบไว้กำลังจะหายไปทั้งแถว การเปิด/ปิดใช้งานจึงไม่มีความหมาย */
          if (row.getData()._state === "removed") {
            return;
          }
          cell.setValue(Number(cell.getValue()) === 1 ? 0 : 1);
          markChanged(row);
          refreshPending();
        },
      },
    );
  }

  /* ---------- ปุ่มท้ายแถว ----------
   * ทำงานสองแบบ ขึ้นกับว่าแถวนั้นบันทึกไปแล้วหรือยัง
   *
   *   แถวใหม่ที่ยังไม่บันทึก  -> กดแล้วหายไปเลย ไม่ต้องถาม ไม่มีอะไรเสียหาย
   *   แถวที่บันทึกแล้ว        -> เฉพาะแท็บที่ canDelete และของชิ้นนั้นไม่มีใครใช้
   *                             กดแล้ว "ทำเครื่องหมายว่าจะลบ" ไม่ได้ลบทันที
   *                             จริง ๆ จะลบตอนกดบันทึก และกดย้อนคืนก่อนได้
   *
   * ที่ไม่ลบทันทีเพราะทั้งหน้านี้ทำงานแบบสะสมการแก้แล้วค่อยส่งครั้งเดียว
   * ถ้าปุ่มนี้ปุ่มเดียวยิงถึงฐานข้อมูลเลย ปุ่ม "ยกเลิกการแก้ไข" จะกู้คืนให้ไม่ได้
   */
  function colRemove() {
    return G.col(G.control, {
      title: "",
      field: "_remove",
      width: 50,
      hozAlign: "center",
      headerSort: false,
      resizable: false,
      responsive: 0,
      cssClass: "cell-keep",
      formatter: function (cell) {
        var d = cell.getRow().getData();

        if (d._state === "new") {
          return '<button type="button" class="grid-remove" title="เอาแถวนี้ออก">&#10005;</button>';
        }
        if (d._state === "removed") {
          return '<button type="button" class="grid-undo" title="ไม่ลบแล้ว เอากลับคืน">&#8634;</button>';
        }
        if (!SPEC_NOW.canDelete) {
          return "";
        }
        if (Number(d.used) > 0) {
          return "";
        }
        return '<button type="button" class="grid-remove" title="ลบหน่วยนี้">&#10005;</button>';
      },
      cellClick: function (e, cell) {
        var row = cell.getRow();
        var d = row.getData();

        if (d._state === "new") {
          row.delete();
          refreshPending();
          return;
        }

        /* กดย้อนคืน */
        if (d._state === "removed") {
          d._state =
            snapshot(d) === JSON.stringify(original[d._key])
              ? "clean"
              : "edited";
          row.reformat();
          applyRowClass(row);
          refreshPending();
          return;
        }

        if (!SPEC_NOW.canDelete || Number(d.used) > 0) {
          return;
        }

        d._state = "removed";
        row.reformat();
        applyRowClass(row);
        refreshPending();
      },
    });
  }

  /* ช่องข้อความธรรมดาที่ว่างแล้วต้องเตือน */
  function textCell(placeholder) {
    return function (cell) {
      var v = cell.getValue();
      if (v === null || v === undefined || String(v).trim() === "") {
        return '<span class="grid-empty">' + placeholder + "</span>";
      }
      return escapeHtml(v);
    };
  }

  /* ตัวเลขนับจำนวนที่แสดงอย่างเดียว — เอาไว้ดูว่าลบได้ไหม */
  function countCell(zeroText) {
    return function (cell) {
      var n = Number(cell.getValue()) || 0;
      if (n === 0) {
        return '<span class="grid-empty">' + zeroText + "</span>";
      }
      return '<span class="num">' + n + "</span>";
    };
  }

  /* ============================================================
   *  รูปสินค้า (สูงสุด 3 รูป)
   * ============================================================
   *  ต่างจากช่องอื่นในตารางตรงที่บันทึกทันทีตอนอัปโหลด ไม่รอกดปุ่มบันทึก
   *  เพราะเป็นไฟล์ ไม่ใช่ข้อความ — เก็บค้างไว้ในหน่วยความจำจนกว่าจะกดบันทึก
   *  แปลว่าต้องแบกไฟล์หลาย MB ไว้ และถ้าปิดหน้าไปก่อนก็หายทั้งหมด
   *
   *  วิธีเดียวกับโลโก้บริษัทในหน้าหน่วยงาน (assets/js/agencies.js)
   * ============================================================ */
  var PIMG_MAX = 3;
  var PIMG_BYTES = 3 * 1024 * 1024;

  var pimgInput = null;
  var pimgRow = null;

  function ensureImageInput() {
    if (pimgInput) {
      return pimgInput;
    }

    pimgInput = document.createElement("input");
    pimgInput.type = "file";
    pimgInput.accept = "image/png,image/jpeg,image/gif";

    /* เลือกได้ทีละหลายไฟล์ คนที่ถ่ายรูปสินค้ามาสามมุมจะได้ไม่ต้องกดเพิ่มทีละรูป
       ส่วนที่เกินช่องว่างที่เหลือจะถูกตัดทิ้งพร้อมบอกผู้ใช้ (ดู uploadImages) */
    pimgInput.multiple = true;
    pimgInput.hidden = true;
    document.body.appendChild(pimgInput);

    pimgInput.addEventListener("change", function () {
      if (this.files && this.files.length > 0 && pimgRow) {
        uploadImages(pimgRow, this.files);
      }
      /* ล้างค่าไว้เสมอ ไม่งั้นเลือกไฟล์เดิมซ้ำจะไม่เกิด event change อีก */
      this.value = "";
    });

    return pimgInput;
  }

  function imagesOf(row) {
    return row.getData().images || [];
  }

  /* เขียนรายการรูปชุดใหม่ที่เซิร์ฟเวอร์ตอบกลับมาลงในแถว
     ไม่แตะ _state และไม่แตะ original[] เพราะรูปถูกบันทึกลงฐานข้อมูลไปแล้วจริง
     ถ้าไปทำเครื่องหมายว่า "แก้แล้วยังไม่บันทึก" ผู้ใช้จะเข้าใจผิดว่าต้องกดบันทึกอีก */
  function setImages(row, images) {
    row.update({ images: images || [] });
    if (typeof window.renderIcons === "function") {
      window.renderIcons(row.getElement());
    }
  }

  /* ---------- ยิงคำสั่งไปหา api/product_image.php ----------
   * ไม่เปิด/ปิดกล่อง "กำลังทำงาน" เอง เพราะการอัปโหลดหลายไฟล์ต้องคุมกล่องเดียว
   * ให้ค้างไว้ตลอดทั้งชุด ผู้เรียกจึงเป็นคนเปิดปิดเอง
   *
   * ---------------------------------------------------------------
   * แยกสาเหตุที่ล้มเหลวออกจากกันให้ชัด
   * ---------------------------------------------------------------
   * ของเดิมใช้ .then(r => r.json()) แล้วมี .catch เดียวจบ ซึ่งกลืนสามเรื่อง
   * ที่ต่างกันสิ้นเชิงมารวมเป็นข้อความเดียวว่า "ติดต่อเซิร์ฟเวอร์ไม่ได้":
   *     1. ต่อเซิร์ฟเวอร์ไม่ติดจริง ๆ
   *     2. เซิร์ฟเวอร์ตอบมาแล้วแต่ไม่ใช่ JSON
   *     3. โค้ดฝั่งหน้าจอเองพังหลังได้รับคำตอบที่ถูกต้องแล้ว
   *
   * ข้อ 2 เกิดได้ง่ายบนเครื่องนี้ เพราะ php.ini ตั้ง display_errors ไว้
   * และไม่มี output buffering — warning บรรทัดเดียวจะถูกพ่นออกไปนำหน้า JSON
   * ทำให้ JSON.parse พัง ทั้งที่สถานะเป็น 200 และงานฝั่งเซิร์ฟเวอร์อาจสำเร็จไปแล้ว
   *
   * ข้อ 3 อันตรายที่สุด เพราะรายงานว่าเป็นปัญหาเครือข่ายทั้งที่เป็นบั๊กของเราเอง
   * แล้วไล่หาสาเหตุผิดทางทั้งหมด
   *
   * จึงอ่านคำตอบเป็นข้อความก่อนแล้วค่อยแปลงเอง ถ้าแปลงไม่ได้ก็ส่งของจริง
   * ที่เซิร์ฟเวอร์พ่นมาให้ผู้ใช้เห็นเลย จะได้รู้สาเหตุโดยไม่ต้องเปิด DevTools
   */
  function pimgPost(form, done, fail) {
    form.append("csrf_token", CFG.csrfToken);

    var status = 0;

    fetch("api/product_image.php", {
      method: "POST",
      credentials: "same-origin",
      body: form,
    })
      .then(function (r) {
        status = r.status;
        return r.text();
      })
      .then(function (text) {
        var res = null;
        try {
          res = JSON.parse(text);
        } catch (err) {
          res = null;
        }

        if (res === null) {
          fail(
            "เซิร์ฟเวอร์ตอบกลับมาไม่ใช่ JSON (สถานะ " +
              status +
              ") — " +
              String(text).replace(/\s+/g, " ").slice(0, 200),
          );
          return;
        }

        if (!res.ok) {
          fail(res.message || "ทำรายการไม่สำเร็จ");
          return;
        }

        /* งานฝั่งเซิร์ฟเวอร์สำเร็จแล้ว ตั้งแต่บรรทัดนี้ไปถ้าพังคือบั๊กของหน้าจอ
           ต้องไม่ปล่อยให้ตกไปที่ .catch ข้างล่างแล้วถูกรายงานว่าเน็ตมีปัญหา */
        try {
          done(res);
        } catch (err) {
          fail(
            "บันทึกขึ้นเซิร์ฟเวอร์แล้ว แต่หน้าจอแสดงผลต่อไม่ได้: " +
              (err && err.message ? err.message : String(err)),
          );
        }
      })
      .catch(function (err) {
        fail(
          "ติดต่อเซิร์ฟเวอร์ไม่ได้" +
            (err && err.message ? " (" + err.message + ")" : ""),
        );
      });
  }

  /* ---------- อัปโหลดรูปทีละชุด ----------
   * เลือกได้หลายไฟล์ แต่ใส่ได้เท่าที่ยังมีช่องว่างเหลือเท่านั้น
   * ที่เกินมาถูกตัดทิ้งโดยไม่ถือว่าผิดพลาด แล้วบอกท้ายงานว่าใช้ไปกี่รูป
   * ตัดทิ้งเงียบ ๆ ไม่ได้ เพราะผู้ใช้เลือกมา 8 ไฟล์แล้วเห็นแค่ 3 จะนึกว่าระบบพัง
   *
   * ส่งทีละไฟล์ต่อกันเป็นทอด ไม่ยิงพร้อมกัน เพราะฝั่งเซิร์ฟเวอร์หาช่องว่าง
   * ช่องแรกที่ยังไม่มีรูปให้เอง ถ้ายิงพร้อมกันทุกคำขอจะเห็นช่องว่างช่องเดียวกัน
   * แล้วชนกันที่ UNIQUE (product_id, sort_no) — จะมีรูปเดียวที่รอดเข้าไป
   */
  function uploadImages(row, fileList) {
    var data = row.getData();
    var free = PIMG_MAX - imagesOf(row).length;

    if (free <= 0) {
      if (window.appError) {
        window.appError(
          "สินค้านี้มีรูปครบ " + PIMG_MAX + " รูปแล้ว กรุณาลบรูปเดิมออกก่อน",
        );
      }
      return;
    }

    /* คัดตามลำดับที่ผู้ใช้เลือกมา เอาเท่าที่ช่องว่างรับไหว
       ไฟล์ใหญ่เกินถูกข้ามโดยไม่กินช่อง — ตรวจที่ฝั่งนี้ก่อนยิง
       ไฟล์ 10 MB ที่จะโดนปฏิเสธอยู่ดีไม่ควรถูกส่งขึ้นไปให้เสียเวลาและแบนด์วิดท์
       (ฝั่งเซิร์ฟเวอร์ตรวจซ้ำอยู่แล้ว ตรงนี้แค่ช่วยให้รู้เร็วขึ้น) */
    var use = [];
    var tooBig = 0;

    for (var i = 0; i < fileList.length && use.length < free; i++) {
      if (fileList[i].size > PIMG_BYTES) {
        tooBig++;
        continue;
      }
      use.push(fileList[i]);
    }

    var skipped = fileList.length - use.length - tooBig;

    if (use.length === 0) {
      if (window.appError) {
        window.appError(
          "ไฟล์ที่เลือกใหญ่เกิน 3 MB ทั้งหมด กรุณาย่อรูปก่อนอัปโหลด",
        );
      }
      return;
    }

    var okCount = 0;
    var lastErr = "";

    /* ---------- สรุปผลท้ายงาน ----------
     * กฎเหล็กของส่วนนี้: จบงานแล้วต้องเปิดกล่อง SweetAlert "กล่องเดียว" เท่านั้น
     *
     * SweetAlert มีกล่องเดียวทั้งหน้า การสั่งเปิดกล่องที่สองไม่ว่าจะเป็น toast
     * หรือกล่องกลางจอ จะไปแทนที่กล่องแรกเสมอ และการสั่ง Swal.close() แล้วเปิดใหม่
     * ติด ๆ กัน ทำให้กล่องใหม่โดนจังหวะปิดของกล่องเก่าปิดตามไปด้วย
     *
     * เคยเขียนเป็น "เปิดกล่องจัดการรูป แล้วแจ้งผลอีกกล่อง" ซึ่งได้ผลไม่แน่นอน
     * แล้วแต่จังหวะ — บางครั้งข้อความสาเหตุขึ้นมาแวบเดียวแล้วโดนกลบหายไป
     * จนไล่หาสาเหตุที่อัปโหลดไม่ได้ไม่เจอ
     *
     * ตอนนี้จึงเหลือทางเดียว:
     *     ล้มเหลวทั้งหมด -> กล่องแจ้งสาเหตุอย่างเดียว ไม่เปิดกล่องจัดการรูปซ้อน
     *                       (ไม่มีอะไรเปลี่ยน ผู้ใช้กดที่ช่องรูปเข้ามาใหม่ได้)
     *     สำเร็จบางส่วน  -> กล่องจัดการรูปกล่องเดียว โดยเอาสรุปไปแปะไว้ในกล่องนั้นเลย
     */
    function finish() {
      if (okCount === 0) {
        /* ไม่ต้องเรียก appClose ก่อน — Swal.fire แทนที่กล่อง "กำลังอัปโหลด" ให้เอง
           สั่งปิดเองก่อนจะกลายเป็นการปิดกล่องที่กำลังจะเปิด */
        if (window.appError) {
          window.appError(lastErr || "อัปโหลดไม่สำเร็จ");
        } else if (window.appClose) {
          window.appClose();
        }
        return;
      }

      var note = [];
      if (skipped > 0) {
        note.push("ข้าม " + skipped + " รูป เพราะครบ " + PIMG_MAX + " รูปแล้ว");
      }
      if (tooBig > 0) {
        note.push("ข้าม " + tooBig + " รูป เพราะไฟล์ใหญ่เกิน 3 MB");
      }
      if (lastErr !== "") {
        note.push("บางรูปไม่สำเร็จ: " + lastErr);
      }

      var msg = "เพิ่มรูปแล้ว " + okCount + " รูป";
      if (note.length > 0) {
        msg += " · " + note.join(" · ");
      }

      openImageBox(row, msg);
    }

    function sendNext(n) {
      if (n >= use.length) {
        finish();
        return;
      }

      if (window.appLoading) {
        window.appLoading("กำลังอัปโหลด " + (n + 1) + "/" + use.length + "…");
      }

      var form = new FormData();
      form.append("action", "upload");
      form.append("product_id", data.product_id);
      form.append("image", use[n]);

      pimgPost(
        form,
        function (res) {
          okCount++;
          setImages(row, res.images);
          sendNext(n + 1);
        },
        function (message) {
          /* รูปหนึ่งพลาดไม่ควรทำให้ที่เหลือไม่ได้ขึ้นเลย ทำต่อแล้วสรุปท้ายงาน */
          lastErr = message;
          sendNext(n + 1);
        },
      );
    }

    sendNext(0);
  }

  function removeImage(row, imageId) {
    var form = new FormData();
    form.append("action", "remove");
    form.append("image_id", imageId);

    if (window.appLoading) {
      window.appLoading("กำลังลบรูป…");
    }

    pimgPost(
      form,
      function (res) {
        if (window.appClose) {
          window.appClose();
        }
        setImages(row, res.images);
        openImageBox(row);
      },
      function (message) {
        if (window.appClose) {
          window.appClose();
        }
        if (window.appError) {
          window.appError(message);
        }
      },
    );
  }

  /* ---------- กล่องจัดการรูปของสินค้าหนึ่งรายการ ----------
   * แสดงช่องว่างครบ 3 ช่องเสมอ ไม่ใช่โชว์เฉพาะรูปที่มี
   * ผู้ใช้จะได้เห็นทันทีว่าใส่ได้อีกกี่รูป โดยไม่ต้องนับเอง
   */
  function openImageBox(row, note) {
    if (!window.Swal) {
      return;
    }

    var data = row.getData();
    var imgs = imagesOf(row);

    /* สรุปผลของรอบที่เพิ่งทำ แปะไว้ในกล่องเดียวกัน ไม่แยกไปเป็นอีกกล่อง
       เพราะ SweetAlert มีกล่องเดียวทั้งหน้า เปิดซ้อนแล้วกล่องหลังจะกลบกล่องแรก */
    var html = note ? '<p class="pimg-note">' + escapeHtml(note) + "</p>" : "";

    html +=
      '<p class="pimg-lead">ใส่ได้สูงสุด ' +
      PIMG_MAX +
      " รูป " +
      "รูปแรกคือรูปที่เอาไปแสดงในหน้าทำรายการเบิก<br>" +
      "รองรับ JPG, PNG และ GIF ไฟล์ละไม่เกิน 3 MB</p>" +
      '<div class="pimg-slots">';

    for (var i = 0; i < PIMG_MAX; i++) {
      if (i < imgs.length) {
        /* ตัวรูปเป็นปุ่มกดดูใหญ่ได้ ส่วนปุ่มลบลอยทับอยู่มุมขวาบนแยกกัน
           รูปในช่อง 5 เซนติเมตรดูไม่ออกว่าเป็นสินค้าตัวไหน ถ้าไม่มีทางขยาย
           ผู้ใช้ต้องลบทิ้งแล้วอัปโหลดใหม่เพื่อดูว่าใช่รูปที่ต้องการหรือเปล่า */
        html +=
          '<div class="pimg-slot has-img">' +
          '<button type="button" class="pimg-zoom" data-pimg-zoom="' +
          i +
          '"' +
          ' title="กดเพื่อดูรูปใหญ่" aria-label="ดูรูปใหญ่">' +
          '<img src="' +
          escapeHtml(imgs[i].url) +
          '" alt=""></button>' +
          (i === 0 ? '<span class="pimg-badge">รูปหลัก</span>' : "") +
          '<button type="button" class="pimg-del" data-pimg-del="' +
          imgs[i].image_id +
          '"' +
          ' title="ลบรูปนี้" aria-label="ลบรูปนี้">&#10005;</button>' +
          "</div>";
      } else {
        html +=
          '<button type="button" class="pimg-slot is-add" data-pimg-add="1">' +
          '<i data-lucide="image-plus" class="h-5 w-5"></i>' +
          "<span>เพิ่มรูป</span></button>";
      }
    }
    html += "</div>";

    window.Swal.fire({
      title: "รูปของ " + (data.product_name || data.product_code || "สินค้า"),
      html: html,
      width: 560,
      confirmButtonText: "ปิด",
      confirmButtonColor: "#4a5568",
      buttonsStyling: false,
      heightAuto: false,
      customClass: { popup: "app-swal", confirmButton: "app-swal-confirm" },
      didOpen: function () {
        var popup = window.Swal.getPopup();
        if (!popup) {
          return;
        }

        if (typeof window.renderIcons === "function") {
          window.renderIcons(popup);
        }

        var adds = popup.querySelectorAll("[data-pimg-add]");
        for (var a = 0; a < adds.length; a++) {
          adds[a].onclick = function () {
            pimgRow = row;
            ensureImageInput().click();
          };
        }

        /* ดูรูปใหญ่ — ส่งรูปทั้งชุดไป กดลูกศรเลื่อนดูรูปอื่นของสินค้าตัวเดียวกันต่อได้
           กล่องดูรูปเป็น element ของตัวเอง ไม่ใช่ SweetAlert ซ้อน
           กล่องจัดการนี้จึงยังเปิดค้างอยู่ข้างหลัง ปิดแล้วกลับมาที่เดิมได้ทันที */
        var zooms = popup.querySelectorAll("[data-pimg-zoom]");
        for (var z = 0; z < zooms.length; z++) {
          (function (btn) {
            btn.onclick = function () {
              if (window.ImageView) {
                window.ImageView.open(
                  imagesOf(row),
                  Number(btn.getAttribute("data-pimg-zoom")),
                );
              }
            };
          })(zooms[z]);
        }

        var dels = popup.querySelectorAll("[data-pimg-del]");
        for (var d = 0; d < dels.length; d++) {
          (function (btn) {
            btn.onclick = function () {
              var id = btn.getAttribute("data-pimg-del");

              /* ลบรูปย้อนคืนไม่ได้ ไฟล์ถูกลบออกจากดิสก์ทันที จึงต้องถามก่อน */
              if (typeof window.appConfirm === "function") {
                window
                  .appConfirm({
                    title: "ลบรูปนี้",
                    text: "ลบแล้วเอากลับคืนไม่ได้ ต้องอัปโหลดใหม่",
                    confirmText: "ลบรูป",
                  })
                  .then(function (ok) {
                    if (ok) {
                      removeImage(row, id);
                    }
                  });
              } else {
                removeImage(row, id);
              }
            };
          })(dels[d]);
        }
      },
    });
  }

  /* ============================================================
   *  คอลัมน์ของแต่ละตาราง
   * ============================================================ */
  function buildColumns() {
    if (TAB === "category") {
      return [
        colCollapse(),
        colSeq(),
        {
          title: "รหัส",
          field: "category_code",
          ...G.filterText("ค้นรหัส"),
          editor: "input",
          width: 120,
          responsive: 0,
          download: true,
          clipboard: true,
          cssClass: "cell-code",
          formatter: function (cell) {
            var v = cell.getValue();
            if (!v) {
              return '<span class="grid-empty">— ยังไม่ได้กรอก —</span>';
            }
            return '<span class="grid-user">' + escapeHtml(v) + "</span>";
          },
        },
        {
          title: "ชื่อหมวดหมู่หลัก",
          field: "category_name",
          ...G.filterText("ค้นชื่อ"),
          editor: "input",
          minWidth: 220,
          responsive: 0,
          download: true,
          clipboard: true,
          formatter: textCell("— ยังไม่ได้กรอก —"),
        },
        G.col(G.control, {
          title: "หมวดย่อย",
          field: "sub_count",
          ...G.filterMin("ตั้งแต่"),
          width: 110,
          hozAlign: "right",
          responsive: 4,
          headerSort: true,
          formatter: countCell("—"),
        }),
        G.col(G.control, {
          title: "สินค้า",
          field: "product_count",
          ...G.filterMin("ตั้งแต่"),
          width: 100,
          hozAlign: "right",
          responsive: 5,
          headerSort: true,
          formatter: countCell("—"),
        }),
        colStatus(),
        colRemove(),
      ];
    }

    if (TAB === "sub_category") {
      return [
        colCollapse(),
        colSeq(),
        {
          title: "รหัส",
          field: "sub_category_code",
          ...G.filterText("ค้นรหัส"),
          editor: "input",
          width: 130,
          responsive: 0,
          download: true,
          clipboard: true,
          cssClass: "cell-code",
          formatter: function (cell) {
            var v = cell.getValue();
            if (!v) {
              return '<span class="grid-empty">— ยังไม่ได้กรอก —</span>';
            }
            return '<span class="grid-user">' + escapeHtml(v) + "</span>";
          },
        },
        {
          title: "ชื่อหมวดหมู่ย่อย",
          field: "sub_category_name",
          ...G.filterText("ค้นชื่อ"),
          editor: "input",
          minWidth: 220,
          responsive: 0,
          download: true,
          clipboard: true,
          formatter: textCell("— ยังไม่ได้กรอก —"),
        },
        G.col(
          G.labelled(function (v) {
            return catLookup[v] || "";
          }),
          {
            title: "หมวดหมู่หลัก",
            field: "category_id",
            ...G.filterPick(catLookup),
            minWidth: 200,
            responsive: 2,
            download: true,
            clipboard: true,
            editor: "list",
            editorParams: G.pickerParams(G.pickerValues(catLookup, true)),
            formatter: function (cell) {
              var name = catLookup[cell.getValue()];
              if (!name) {
                return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>';
              }
              return '<span class="grid-pill">' + escapeHtml(name) + "</span>";
            },
          },
        ),
        G.col(G.control, {
          title: "สินค้า",
          field: "product_count",
          ...G.filterMin("ตั้งแต่"),
          width: 100,
          hozAlign: "right",
          responsive: 5,
          headerSort: true,
          formatter: countCell("—"),
        }),
        colStatus(),
        colRemove(),
      ];
    }

    if (TAB === "unit") {
      return [
        colCollapse(),
        colSeq(),
        {
          title: "ชื่อหน่วยนับ",
          field: "unit_name",
          ...G.filterText("ค้นชื่อหน่วย"),
          editor: "input",
          /* แถวที่กดลบไว้ห้ามแก้ชื่อ — แก้ไปก็ไม่มีผล เพราะทั้งแถวจะหายตอนบันทึก */
          editable: function (cell) {
            return cell.getRow().getData()._state !== "removed";
          },
          minWidth: 220,
          responsive: 0,
          download: true,
          clipboard: true,
          formatter: textCell("— ยังไม่ได้กรอก —"),
        },
        /* จำนวนสินค้าที่ใช้หน่วยนี้ ไม่ใช่แค่ข้อมูลประกอบ
           แต่เป็นตัวบอกว่าแถวนี้มีปุ่มลบหรือไม่ จึงตั้งให้ยุบทีหลังสุด */
        G.col(G.control, {
          title: "สินค้าที่ใช้",
          field: "used",
          ...G.filterMin("ตั้งแต่"),
          width: 145,
          hozAlign: "right",
          responsive: 3,
          headerSort: true,
          formatter: function (cell) {
            var n = Number(cell.getValue()) || 0;
            if (cell.getRow().getData()._state === "new") {
              return '<span class="grid-empty">—</span>';
            }
            if (n === 0) {
              return '<span class="grid-empty">ยังไม่มีใครใช้</span>';
            }
            return '<span class="num">' + n + "</span>";
          },
        }),
        colStatus(),
        colRemove(),
      ];
    }

    /* ---------- ทะเบียนสินค้า ---------- */
    return [
      colCollapse(),
      {
        /* ---------- รูปสินค้า ----------
         * คนเบิกส่วนใหญ่ไม่ใช่คนคลัง จำรหัสไม่ได้ และชื่อสินค้าหลายตัว
         * ก็ใกล้กันจนแยกไม่ออกจากตัวหนังสืออย่างเดียว รูปช่วยตรงนั้น
         *
         * ช่องนี้ไม่นับเป็นค่าที่ต้องกดบันทึก — รูปถูกบันทึกทันทีตอนอัปโหลด
         * เหมือนโลโก้บริษัทในหน้าหน่วยงาน (ดู uploadImage ข้างล่าง)
         * จึงไม่อยู่ใน SPEC.product.fields และไม่ถูก snapshotOf() นับ
         */
        title: "รูป",
        field: "images",
        width: 116,
        hozAlign: "center",
        headerSort: false,
        responsive: 4,
        download: false,
        clipboard: false,
        ...G.control,
        formatter: function (cell) {
          var row = cell.getRow().getData();

          /* แถวใหม่ยังไม่มี product_id จริง จะแนบรูปให้ไม่ได้
             (ฝั่งเซิร์ฟเวอร์ปฏิเสธซ้ำอยู่แล้ว ตรงนี้บอกเหตุผลให้เห็นก่อนกด) */
          /* ---------- ไอคอนในช่องนี้วาดด้วย CSS ไม่ใช่ <i data-lucide> ----------
           * lucide แปลง <i> เป็น <svg> ให้ครั้งเดียวตอนโหลดหน้าเสร็จ
           * แต่ Tabulator สร้าง HTML ของเซลล์ทีหลัง และสร้างใหม่ทุกครั้งที่
           * เรียง กรอง เลื่อน หรือเปลี่ยนหน้า ไอคอนจึงไม่เคยถูกวาดเลย
           * เห็นแต่คำว่า "เพิ่มรูป" ลอย ๆ ไม่มีรูปไอคอน
           *
           * จะแก้ด้วยการสั่ง renderIcons ใหม่ทุกครั้งที่ตารางวาดก็ได้
           * แต่ต้องไล่แปลงทั้งตารางทุกครั้งที่เลื่อน ซึ่งไม่คุ้มกับไอคอนสองตัว
           * (เหตุผลเดียวกับรูปย่อในหน้าเบิก ดู .tf-thumb.is-none)
           */
          if (row._state === "new") {
            return (
              '<span class="pimg-cell is-locked" title="บันทึกสินค้าก่อน จึงจะใส่รูปได้">' +
              '<span class="pimg-ico is-lock" aria-hidden="true"></span></span>'
            );
          }

          var imgs = row.images || [];

          if (imgs.length === 0) {
            return (
              '<button type="button" class="pimg-cell is-empty" data-pimg="open"' +
              ' title="กดเพื่อเพิ่มรูป (ใส่ได้สูงสุด 3 รูป)">' +
              '<span class="pimg-ico is-add" aria-hidden="true"></span>' +
              "</button>"
            );
          }

          /* โชว์รูปแรกเป็นตัวแทน แล้วบอกจำนวนที่เหลือด้วยตัวเลขมุมขวา
             เรียงรูปเล็กสามรูปในช่องกว้าง 116px จะเล็กจนดูไม่ออกว่าเป็นอะไร */
          return (
            '<button type="button" class="pimg-cell has-img" data-pimg="open"' +
            ' title="กดเพื่อจัดการรูป (มี ' +
            imgs.length +
            ' รูป)">' +
            '<img src="' +
            escapeHtml(imgs[0].url) +
            '" alt="" loading="lazy">' +
            (imgs.length > 1
              ? '<span class="pimg-more num">' + imgs.length + "</span>"
              : "") +
            "</button>"
          );
        },
      },
      {
        /* รหัสสินค้าระบบสร้างให้ แก้ไม่ได้ แต่ยังต้องเลือกข้อความไปคัดลอกได้
                   จึงเปิดช่องแก้แล้วใส่ readonly แทนที่จะปิดการแก้ไปเลย
                   (โหมดเลือกช่วงแบบ Excel ปิด user-select ของทุกช่องไว้
                    ถ้าไม่เปิดช่อง จะเป็นคอลัมน์เดียวที่คัดลอกทีละคำไม่ได้) */
        title: "รหัสสินค้า",
        field: "product_code",
        ...G.filterText("ค้นรหัส"),
        width: 140,
        responsive: 0,
        download: true,
        clipboard: true,
        cssClass: "cell-code",
        editor: "input",
        editorParams: {
          elementAttributes: {
            readonly: "readonly",
            title: "รหัสสินค้าแก้ไม่ได้ — เลือกข้อความแล้ว Ctrl+C เพื่อคัดลอก",
          },
        },
        formatter: function (cell) {
          var v = cell.getValue();
          if (!v) {
            return '<span class="grid-empty">— สร้างให้ตอนบันทึก —</span>';
          }
          return '<span class="grid-user">' + escapeHtml(v) + "</span>";
        },
      },
      {
        title: "ชื่อสินค้า",
        field: "product_name",
        ...G.filterText("ค้นชื่อ"),
        editor: "input",
        minWidth: 210,
        responsive: 0,
        download: true,
        clipboard: true,
        formatter: textCell("— ยังไม่ได้กรอก —"),
      },
      G.col(
        G.labelled(function (v) {
          return catLookup[v] || "";
        }),
        {
          title: "หมวดหมู่หลัก",
          field: "category_id",
          ...G.filterPick(catLookup),
          minWidth: 165,
          responsive: 5,
          download: true,
          clipboard: true,
          editor: "list",
          editorParams: G.pickerParams(G.pickerValues(catLookup, true)),
          formatter: function (cell) {
            var name = catLookup[cell.getValue()];
            if (!name) {
              return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>';
            }
            return '<span class="grid-pill">' + escapeHtml(name) + "</span>";
          },
        },
      ),
      G.col(
        G.labelled(function (v) {
          return subLookup[v] || "";
        }),
        {
          title: "หมวดหมู่ย่อย",
          field: "sub_category_id",
          ...G.filterPick(subPicker),
          minWidth: 165,
          responsive: 2,
          download: true,
          clipboard: true,
          editor: "list",
          editorParams: function (cell) {
            return G.pickerParams(
              G.pickerValues(
                subPickerFor(cell.getRow().getData().category_id),
                true,
              ),
            );
          },
          formatter: function (cell) {
            var name = subLookup[cell.getValue()];
            if (!name) {
              return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>';
            }
            return '<span class="grid-pill">' + escapeHtml(name) + "</span>";
          },
        },
      ),
      {
        /* ชื่อคอลัมน์บอกให้ชัดว่าเป็น "ราคาซื้อ" ไม่ใช่ต้นทุนที่รายงานใช้คิดเงิน
           ค่านี้ถูกเขียนทับด้วยราคาของใบรับเข้าที่ใหม่ที่สุดทุกครั้งที่บันทึกรับเข้า
           (ดู receive_reprice ใน backend/receive.php) แก้เองที่นี่ก็ได้ ใช้เป็น
           ราคาตั้งต้นของสินค้าที่ยังไม่เคยซื้อ

           รายงานก็ใช้ราคานี้เหมือนกัน แต่เป็นราคา ณ วันที่ทำรายการซึ่งแช่แข็งไว้แล้ว
           แก้ค่านี้วันนี้จึงไม่ทำให้ตัวเลขของเดือนที่ผ่านมาขยับ */
        title: "ราคาซื้อล่าสุด",
        field: "unit_cost",
        ...G.filterMin("ราคาตั้งแต่"),
        width: 115,
        hozAlign: "right",
        responsive: 3,
        download: true,
        clipboard: true,
        cssClass: "num",
        editor: "number",
        editorParams: { min: 0, step: 0.25 },
        formatter: function (cell) {
          var n = Number(cell.getValue()) || 0;
          /* 0 แปลว่ายังไม่ได้ตั้งราคา ไม่ใช่ของแจก จึงแสดงต่างจากตัวเลขจริง */
          if (n === 0) {
            return '<span class="grid-empty">— ยังไม่ระบุ —</span>';
          }
          return money(n);
        },
      },
      G.col(
        G.labelled(function (v) {
          return unitLookup[v] || "";
        }),
        {
          title: "หน่วยนับ",
          field: "unit_id",
          ...G.filterPick(unitLookup),
          width: 105,
          responsive: 4,
          download: true,
          clipboard: true,
          editor: "list",
          editorParams: G.pickerParams(G.pickerValues(unitLookup, true)),
          formatter: function (cell) {
            var name = unitLookup[cell.getValue()];
            if (!name) {
              return '<span class="grid-empty">— ยังไม่ได้เลือก —</span>';
            }
            return escapeHtml(name);
          },
        },
      ),
      {
        title: "จุดสั่งซื้อ",
        field: "min_qty",
        ...G.filterMin("ตั้งแต่"),
        width: 100,
        hozAlign: "right",
        responsive: 6,
        download: true,
        clipboard: true,
        cssClass: "num",
        editor: "number",
        editorParams: { min: 0, step: 1 },
        formatter: function (cell) {
          var n = Number(cell.getValue()) || 0;
          if (n === 0) {
            return '<span class="grid-empty">—</span>';
          }
          return money(n);
        },
      },
      colStatus(135),
      colRemove(),
    ];
  }

  /* ---------- นับและแสดงจำนวนที่ยังไม่บันทึก ---------- */
  function collect() {
    var creates = [],
      updates = [],
      deletes = [];
    if (!table) {
      return { creates: creates, updates: updates, deletes: deletes };
    }

    var rows = table.getData();
    for (var i = 0; i < rows.length; i++) {
      if (rows[i]._state === "new") {
        creates.push(rows[i]);
      }
      if (rows[i]._state === "edited") {
        updates.push(rows[i]);
      }
      if (rows[i]._state === "removed") {
        deletes.push(Number(rows[i][SPEC_NOW.idField]));
      }
    }
    return { creates: creates, updates: updates, deletes: deletes };
  }

  function pendingTotal(c) {
    return c.creates.length + c.updates.length + c.deletes.length;
  }

  function refreshPending() {
    var c = collect();
    var total = pendingTotal(c);

    el("btnSave").disabled = total === 0;
    el("btnRevert").disabled = total === 0;

    var badge = el("pendingCount");
    badge.textContent = total;
    setHidden(badge, total === 0);

    var parts = [];
    if (c.creates.length) {
      parts.push("เพิ่มใหม่ " + c.creates.length + " " + SPEC_NOW.unit);
    }
    if (c.updates.length) {
      parts.push("แก้ไข " + c.updates.length + " " + SPEC_NOW.unit);
    }
    if (c.deletes.length) {
      parts.push("ลบ " + c.deletes.length + " " + SPEC_NOW.unit);
    }

    setHidden(el("pendingBar"), total === 0);
    if (total > 0) {
      el("pendingText").textContent =
        "ยังไม่ได้บันทึก: " + parts.join(" · ") + " — กดปุ่มบันทึกเพื่อยืนยัน";
    }
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
      G.options({
        data: buildRows(),
        columns: buildColumns(),
        placeholder: SPEC_NOW.empty,

        /* วางจาก Excel ได้ทั้งก้อน — แถวที่วางเข้ามาถือเป็นแถวใหม่
               ใช้เติมทะเบียนสินค้าทีละมาก ๆ จากไฟล์ที่ฝ่ายจัดซื้อส่งมา */
        clipboardPasteAction: "insert",

        rowFormatter: applyRowClass,
      }),
    );

    G.guardRanges(table);
    G.enableFilterToggles(table);

    /* ลากจุดมุมขวาล่างเพื่อคัดลอกค่าลงแถวถัดไปแบบ Excel */
    G.enableFillHandle(
      table,
      function (rows) {
        for (var i = 0; i < rows.length; i++) {
          syncProductCategory(rows[i]);
          markChanged(rows[i]);
        }
        refreshPending();
      },
      {
        /* สถานะไม่มีช่องพิมพ์ แต่ลากเติมได้ ใช้ปิด/เปิดใช้งานทีเดียวหลายแถว
               ส่วนรหัสสินค้าระบบสร้างให้ ห้ามเติมทับ */
        is_active: true,
        product_code: false,
      },
    );

    table.on("cellEdited", function (cell) {
      syncProductCategory(cell.getRow());
      markChanged(cell.getRow());
      refreshPending();
    });

    /* ---------- กดที่ช่องรูปสินค้า ----------
     * ผูกที่ตัวตารางที่เดียว ไม่ผูกกับ formatter ของแต่ละช่อง
     * เพราะ Tabulator วาด HTML ของช่องใหม่ทุกครั้งที่ข้อมูลเปลี่ยน
     * ตัวจับเหตุการณ์ที่ผูกไว้กับปุ่มเดิมจะหลุดไปพร้อมกับ HTML ชุดเก่า
     */
    if (TAB === "product" && table.element) {
      table.element.addEventListener("click", function (e) {
        var btn = e.target.closest
          ? e.target.closest('[data-pimg="open"]')
          : null;
        if (!btn) {
          return;
        }

        var rowEl = btn.closest(".tabulator-row");
        if (!rowEl) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        var rows = table.getRows();
        for (var i = 0; i < rows.length; i++) {
          if (rows[i].getElement() === rowEl) {
            openImageBox(rows[i]);
            return;
          }
        }
      });
    }

    /* วางจาก Excel — แถวที่วางเข้ามายังไม่มี _key ให้ถือเป็นแถวใหม่ */
    table.on("clipboardPasted", function () {
      var rows = table.getRows();
      for (var i = 0; i < rows.length; i++) {
        var d = rows[i].getData();
        if (!d._key) {
          d._key = "n" + ++newSeq;
          d._state = "new";
          if (d.is_active === undefined || d.is_active === "") {
            d.is_active = 1;
          }
          applyRowClass(rows[i]);
        }
        syncProductCategory(rows[i]);
      }
      table.redraw(true);
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
        return haystack(data).indexOf(term) !== -1;
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

      for (var i = 0; i < count; i++) {
        table.addRow(blankRow(), true);
      }

      refreshPending();

      /* พาไปหน้าแรกแล้วเปิดช่องแรกของแถวบนสุดให้พิมพ์ต่อได้ทันที */
      table.setPage(1).then(function () {
        var rows = table.getRows("active");
        if (rows.length) {
          rows[0].getCell(firstEditableField()).edit();
        }
      });
    });

    /* ---------- ยกเลิกการแก้ไขทั้งหมด ---------- */
    el("btnRevert").addEventListener("click", function () {
      var c = collect();
      var total = pendingTotal(c);

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
        filename: exportName(),
        sheetName: exportName(),
        pending: function () {
          return pendingTotal(collect());
        },
      });
    }

    el("btnSave").addEventListener("click", save);

    /* ---------- เตือนก่อนออกจากหน้าถ้ายังไม่บันทึก ---------- */
    window.addEventListener("beforeunload", function (e) {
      if (skipUnloadWarning) {
        return;
      }
      if (pendingTotal(collect()) > 0) {
        e.preventDefault();
        e.returnValue = "";
        return "";
      }
    });
  }

  /* ---------- ข้อความที่ใช้ค้นหาของแต่ละแถว ----------
   * รวมชื่อหมวดหมู่/หน่วยนับเข้าไปด้วย เพราะผู้ใช้ค้นจากคำที่เห็นบนจอ
   * ไม่ได้ค้นจากเลข id ที่เก็บอยู่จริง
   */
  function haystack(data) {
    if (TAB === "category") {
      return (
        String(data.category_code || "") +
        " " +
        String(data.category_name || "")
      ).toLowerCase();
    }
    if (TAB === "sub_category") {
      return (
        String(data.sub_category_code || "") +
        " " +
        String(data.sub_category_name || "") +
        " " +
        String(catLookup[data.category_id] || "")
      ).toLowerCase();
    }
    if (TAB === "unit") {
      return String(data.unit_name || "").toLowerCase();
    }
    return (
      String(data.product_code || "") +
      " " +
      String(data.product_name || "") +
      " " +
      String(catLookup[data.category_id] || "") +
      " " +
      String(subLookup[data.sub_category_id] || "") +
      " " +
      String(unitLookup[data.unit_id] || "")
    ).toLowerCase();
  }

  function firstEditableField() {
    if (TAB === "category") {
      return "category_code";
    }
    if (TAB === "sub_category") {
      return "sub_category_code";
    }
    if (TAB === "unit") {
      return "unit_name";
    }
    return "product_name";
  }

  function exportName() {
    if (TAB === "category") {
      return "หมวดหมู่หลัก";
    }
    if (TAB === "sub_category") {
      return "หมวดหมู่ย่อย";
    }
    if (TAB === "unit") {
      return "หน่วยนับ";
    }
    return "ทะเบียนสินค้า";
  }

  /* แถวเปล่าของแต่ละตาราง — ค่าตั้งต้นเลือกให้ปลอดภัยไว้ก่อน */
  function blankRow() {
    var row = { _key: "n" + ++newSeq, _state: "new", is_active: 1 };
    row[SPEC_NOW.idField] = 0;

    if (TAB === "category") {
      row.category_code = "";
      row.category_name = "";
      row.sub_count = 0;
      row.product_count = 0;
    } else if (TAB === "sub_category") {
      row.sub_category_code = "";
      row.sub_category_name = "";
      row.category_id = firstKey(CFG.catOptions, "category_id");
      row.product_count = 0;
    } else if (TAB === "unit") {
      row.unit_name = "";
      row.used = 0;
    } else {
      row.product_code = "";
      row.old_code = "";
      row.product_name = "";
      row.category_id = firstKey(CFG.catOptions, "category_id");
      row.sub_category_id = firstSubKey(row.category_id);
      row.unit_id = firstKey(CFG.unitOptions, "unit_id");
      row.unit_cost = 0;
      row.min_qty = 0;
      row.max_qty = 0;
    }
    return row;
  }

  function firstKey(list, field) {
    return list && list.length ? Number(list[0][field]) : 0;
  }

  function firstSubKey(categoryId) {
    var list = CFG.subOptions || [];
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i].category_id) === Number(categoryId)) {
        return Number(list[i].sub_category_id);
      }
    }
    return 0;
  }

  /* ============================================================
   *  ส่งไปบันทึก
   * ============================================================ */
  function save() {
    var btn = el("btnSave");
    var c = collect();

    /* ตรวจฝั่งหน้าเว็บก่อน จะได้รู้ผลเร็วขึ้น และชี้ได้ว่าผิดช่องไหน
       กฎต้องตรงกับ *_validate() ในชั้น backend เสมอ */
    var G = window.GridCommon;
    var problems = G.validate(table, validationRules());
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
      tab: TAB,
      creates: c.creates,
      updates: c.updates,
      deletes: c.deletes,
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

      /* สำเร็จหมด โหลดหน้าใหม่เพื่อดึงข้อมูลล่าสุดพร้อมรหัสของแถวที่เพิ่งเพิ่ม */
      try {
        sessionStorage.setItem(
          "catalogSaved",
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

  /* ============================================================
   *  กฎตรวจของแต่ละแท็บ — ตรงกับ backend/category.php, sub_category.php, product.php
   *  คืน '' = ผ่าน  คืนข้อความ = ไม่ผ่าน (ดู GridCommon.validate)
   * ============================================================ */
  function validationRules() {
    /* รหัสหมวดหมู่เอาไปประกอบเป็นรหัสสินค้า จึงจำกัดอักขระให้ปลอดภัยกับการค้นหา */
    function codeRule(field, label) {
      return function (v, d) {
        var s = String(v || "").trim();
        if (s === "") {
          return "กรุณากรอก" + label;
        }
        if (!/^[A-Za-z0-9\-]+$/.test(s)) {
          return label + "ใช้ได้เฉพาะ A-Z, 0-9 และขีดกลาง";
        }
        if (s.length > 20) {
          return label + "ยาวเกิน 20 ตัวอักษร";
        }

        /* ซ้ำกับแถวอื่นในตารางเดียวกัน จับตั้งแต่ตอนนี้ดีกว่าไปโดนปฏิเสธทีละใบ */
        var rows = table.getData(),
          same = 0;
        for (var i = 0; i < rows.length; i++) {
          if (
            String(rows[i][field] || "")
              .trim()
              .toUpperCase() === s.toUpperCase()
          ) {
            same++;
          }
        }
        if (same > 1) {
          return label + ' "' + s + '" ซ้ำกับอีกแถวในตารางนี้';
        }
        return "";
      };
    }

    function nameRule(label, max) {
      return function (v) {
        var s = String(v || "").trim();
        if (s === "") {
          return "กรุณากรอก" + label;
        }
        if (s.length > max) {
          return label + "ยาวเกิน " + max + " ตัวอักษร";
        }
        return "";
      };
    }

    /* ตัวเลขที่เป็นจำนวนหรือเงิน ต้องไม่ติดลบและต้องเป็นตัวเลขจริง */
    function numRule(label) {
      return function (v) {
        if (v === "" || v === null || v === undefined) {
          return "";
        }
        if (isNaN(Number(v))) {
          return label + "ต้องเป็นตัวเลข";
        }
        if (Number(v) < 0) {
          return label + "ต้องไม่ติดลบ";
        }
        return "";
      };
    }

    if (TAB === "category") {
      return {
        category_code: codeRule("category_code", "รหัสหมวดหมู่หลัก"),
        category_name: nameRule("ชื่อหมวดหมู่หลัก", 150),
      };
    }

    if (TAB === "sub_category") {
      return {
        sub_category_code: codeRule("sub_category_code", "รหัสหมวดหมู่ย่อย"),
        sub_category_name: nameRule("ชื่อหมวดหมู่ย่อย", 150),
        category_id: function (v) {
          return Number(v) > 0 ? "" : "กรุณาเลือกหมวดหมู่หลัก";
        },
      };
    }

    if (TAB === "unit") {
      return {
        unit_name: function (v) {
          var bad = nameRule("ชื่อหน่วยนับ", 50)(v);
          if (bad) {
            return bad;
          }

          /* ชื่อหน่วยมี unique index อยู่ ถ้าปล่อยไปจะโดนปฏิเสธทีละแถวจากฝั่งเซิร์ฟเวอร์
             จับตรงนี้ก่อนได้ผลดีกว่า เพราะชี้ได้ว่าซ้ำกันตรงช่องไหน
             เทียบแบบยุบช่องว่างเหมือน unit_validate() ไม่งั้น "ชิ้น " จะดูเหมือนไม่ซ้ำ
             แต่พอบันทึกจริงกลับชนกับ "ชิ้น" ที่มีอยู่แล้ว */
          var key = String(v).trim().replace(/\s+/g, " ");
          var rows = table.getData(),
            same = 0;
          for (var i = 0; i < rows.length; i++) {
            if (rows[i]._state === "removed") {
              continue;
            }
            if (
              String(rows[i].unit_name || "")
                .trim()
                .replace(/\s+/g, " ") === key
            ) {
              same++;
            }
          }
          if (same > 1) {
            return 'ชื่อหน่วยนับ "' + key + '" ซ้ำกับอีกแถวในตารางนี้';
          }
          return "";
        },
      };
    }

    /* ---------- สินค้า ---------- */
    return {
      product_name: nameRule("ชื่อสินค้า", 300),
      category_id: function (v) {
        return Number(v) > 0 ? "" : "กรุณาเลือกหมวดหมู่หลัก";
      },
      sub_category_id: function (v) {
        return Number(v) > 0 ? "" : "กรุณาเลือกหมวดหมู่ย่อย";
      },
      unit_id: function (v) {
        return Number(v) > 0 ? "" : "กรุณาเลือกหน่วยนับ";
      },
      unit_cost: numRule("ราคาซื้อ"),
      min_qty: numRule("จุดสั่งซื้อ"),
      max_qty: function (v, d) {
        var bad = numRule("จำนวนสูงสุด")(v);
        if (bad) {
          return bad;
        }
        /* max = 0 แปลว่ายังไม่กำหนดเพดาน จึงไม่ต้องเทียบกับจุดสั่งซื้อ */
        if (Number(v) > 0 && Number(v) < Number(d.min_qty || 0)) {
          return "จำนวนสูงสุดต้องไม่น้อยกว่าจุดสั่งซื้อ";
        }
        return "";
      },
      old_code: function (v) {
        var s = String(v || "").trim();
        return s.length > 30 ? "รหัสเดิมยาวเกิน 30 ตัวอักษร" : "";
      },
    };
  }

  /* ตรวจเฉพาะข้อที่บอกได้ตั้งแต่หน้าจอ ที่เหลือปล่อยให้ backend ตัดสิน
     (ยังเก็บไว้เผื่อที่อื่นเรียกใช้ ตัวหลักตอนนี้คือ validationRules) */
  function validateAll(rows) {
    var problems = [];
    var blankName = 0,
      blankCode = 0,
      badCode = 0,
      noPick = 0;

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];

      if (TAB === "category" || TAB === "sub_category") {
        var codeField =
          TAB === "category" ? "category_code" : "sub_category_code";
        var nameField =
          TAB === "category" ? "category_name" : "sub_category_name";
        var code = String(r[codeField] || "").trim();

        if (code === "") {
          blankCode++;
        } else if (!/^[A-Za-z0-9\-]+$/.test(code)) {
          badCode++;
        }
        if (String(r[nameField] || "").trim() === "") {
          blankName++;
        }
        if (TAB === "sub_category" && !Number(r.category_id)) {
          noPick++;
        }
      } else {
        if (String(r.product_name || "").trim() === "") {
          blankName++;
        }
        if (
          !Number(r.category_id) ||
          !Number(r.sub_category_id) ||
          !Number(r.unit_id)
        ) {
          noPick++;
        }
      }
    }

    if (blankCode) {
      problems.push("มี " + blankCode + " แถวที่ยังไม่ได้กรอกรหัส");
    }
    if (badCode) {
      problems.push(
        "มี " +
          badCode +
          " แถวที่รหัสมีอักขระที่ใช้ไม่ได้ (ใช้ได้เฉพาะ A-Z 0-9 และ -)",
      );
    }
    if (blankName) {
      problems.push("มี " + blankName + " แถวที่ยังไม่ได้กรอกชื่อ");
    }
    if (noPick) {
      problems.push(
        "มี " + noPick + " แถวที่ยังไม่ได้เลือกหมวดหมู่หรือหน่วยนับ",
      );
    }

    return problems;
  }

  function finishSave(ok, message) {
    var btn = el("btnSave");
    setHidden(btn.querySelector(".btn-icon"), false);
    setHidden(btn.querySelector(".btn-spinner"), true);
    btn.disabled = false;
    showResult(ok, message);
    refreshPending();
  }

  /* ---------- ข้อความจากการบันทึกรอบที่แล้ว ---------- */
  function showSavedMessage() {
    var msg = null;
    try {
      msg = sessionStorage.getItem("catalogSaved");
    } catch (err) {}
    if (msg) {
      try {
        sessionStorage.removeItem("catalogSaved");
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
