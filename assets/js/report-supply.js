/* ============================================================
 *  report-supply.js — รายละเอียดการสั่งซื้อ-เบิกใช้ ประจำเดือน
 *
 *  ตารางไขว้ที่อ่านเป็นแถวละสินค้า ไล่จากซ้ายไปขวาตามลำดับเวลาจริง
 *      ยกมา  +  ซื้อ  -  เบิก (กางเป็นคอลัมน์ตามหน่วยงาน)  =  คงเหลือ
 *
 *  วาดเป็นตาราง HTML เอง ไม่ใช้ Tabulator เหมือนหน้ารายงานอื่น เพราะ
 *  หัวตารางเป็นสองชั้น และจำนวนคอลัมน์เปลี่ยนไปตามหน่วยงานที่เบิกในเดือนนั้น
 *  (ดูเหตุผลเต็มที่หัวไฟล์ pages/report_supply.php)
 *
 *  โครงคอลัมน์สร้างครั้งเดียวจากข้อมูลที่ได้มา แล้วใช้ชุดเดียวกันทั้งการวาด
 *  บนจอและการส่งออก Excel — ไฟล์ที่ได้จึงหน้าตาเหมือนที่ตาเห็นเสมอ
 *  ถ้าแยกกันเขียนสองที่ วันหนึ่งจะเพิ่มคอลัมน์แล้วลืมแก้อีกฝั่ง
 *
 *  เขียนแบบ ES5 ให้เข้ากับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
  "use strict";

  var CFG = window.REPORTSUPPLY;
  if (!CFG) {
    return;
  }

  var DATA = null;

  /* ---------- คอลัมน์ที่ผู้ใช้ปิดไว้ ----------
   * เก็บเป็น "รายการที่ปิด" ไม่ใช่ "รายการที่เปิด" เพราะเดือนใหม่มีหน่วยงาน
   * ไม่เหมือนเดือนเก่า ถ้าเก็บเป็นรายการที่เปิด หน่วยงานที่เพิ่งโผล่มา
   * จะถูกซ่อนไปโดยที่ผู้ใช้ไม่ได้สั่ง ค่าปริยายคือเห็นทุกคอลัมน์เสมอ
   */
  var hiddenCols = {};

  /* ---------- ความกว้างคอลัมน์ (rem) ----------
   * ค่าปริยายแยกตามชนิดของช่อง ไม่ได้ปักไว้รายคอลัมน์ เพราะจำนวนคอลัมน์
   * ไม่คงที่ (หน่วยงานกางตามเดือน) จะเขียนไว้ล่วงหน้าไม่ได้
   *
   * ผู้ใช้ลากขอบเพื่อย่อ/ขยายได้ ค่าที่ลากไว้เก็บใน colWidth ทับค่าปริยาย
   * เก็บด้วยกุญแจที่ไม่ขึ้นกับลำดับ (เช่น 'open:1') เดือนใหม่ที่คอลัมน์
   * ขยับตำแหน่งจึงยังได้ความกว้างเดิมที่เคยตั้งไว้
   */
  var COL_W = {
    seq: 2.75,
    code: 6.25,
    name: 14.375,
    unit: 3.625,
    qty: 4.75,
    money: 6.25,
    text: 6.5,
  };

  var COL_MIN = 2; /* ย่อจนอ่านไม่ออกไม่มีประโยชน์ */
  var COL_MAX = 40;

  var colWidth = {}; /* กุญแจคอลัมน์ -> ความกว้าง (rem) ที่ผู้ใช้ตั้งเอง */

  function widthOf(key, fallback) {
    var w = colWidth[key];
    return typeof w === "number" && w > 0 ? w : fallback;
  }

  /* style="left:..." ของช่องที่ตรึง ช่องธรรมดาได้สตริงว่าง */
  function fixStyle(col) {
    if (!col || typeof col.left !== "number") {
      return "";
    }
    /* ปัดทศนิยมทิ้ง ไม่งั้นการบวกความกว้างทีละคอลัมน์จะได้ 37.004999999999995
           ซึ่งยาวเกินจำเป็นในทุกช่องของตารางสามร้อยกว่าแถว */
    return ' style="left:' + Math.round(col.left * 1000) / 1000 + 'rem"';
  }

  /* ช่องนี้ตรึงอยู่หรือเปล่า (ดูจากคลาส sp-fix-N ที่ติดมา) */
  function fixKey(cls) {
    if (!cls) {
      return "";
    }
    var m = (" " + cls + " ").match(/ sp-fix-([1-9]) /);
    return m ? m[0].replace(/ /g, "") : "";
  }

  function el(id) {
    return document.getElementById(id);
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

  function esc(text) {
    return String(text === null || text === undefined ? "" : text).replace(
      /[&<>"']/g,
      function (c) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[c];
      },
    );
  }

  /* เงินสองตำแหน่งเสมอ แม้ลงท้ายด้วย .00 — คอลัมน์เงินต้องเรียงจุดทศนิยมตรงกัน */
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

  /* จำนวนนับไม่ต้องมี .00 ห้อยท้าย ให้ตรงกับ stock_format() ฝั่ง PHP */
  function qty(v) {
    var n = Number(v);
    if (isNaN(n)) {
      n = 0;
    }
    if (n === Math.floor(n)) {
      return n.toLocaleString("en-US");
    }
    return n.toLocaleString("en-US", { maximumFractionDigits: 2 });
  }

  function thaiMonth(ym) {
    return window.ThaiDate ? window.ThaiDate.month(ym) : String(ym);
  }

  function thaiShort(d) {
    return window.ThaiDate ? window.ThaiDate.short(d, "") : String(d || "");
  }

  /* ============================================================
   *  โครงคอลัมน์
   * ============================================================
   *  fixed  = คอลัมน์ซ้ายสุดที่ตรึงไว้ ไม่มีหัวสองชั้น
   *  groups = กลุ่มที่มีหัวสองชั้น กลุ่มละสองคอลัมน์ (จำนวน / เป็นเงิน)
   *
   *  แต่ละกลุ่มบอกเองว่ามีกี่ช่องและแต่ละช่องเอาค่ามาจากไหน (cols)
   *  ส่วนที่วาดกับส่วนที่ส่งออก Excel จึงวนตามนั้นได้โดยไม่ต้องรู้ว่า
   *  ค่ามาจากคอลัมน์ตรง ๆ จากหน่วยงานที่กางออกมา หรือจากล็อตราคา
   *
   *  ที่ต้องยืดหยุ่นเพราะกลุ่ม "ซื้อเข้า" มีสามช่อง (มีราคาแทรกกลาง)
   *  ส่วนกลุ่มอื่นมีสองช่องตามเดิม
   */
  /* รวม object เข้าด้วยกันแบบตื้น ๆ ใช้เติม cls ให้ช่องที่ตรึงไว้
       (หน้านี้ไม่ได้โหลด grid-common.js เพราะไม่ได้ใช้ตาราง Tabulator) */
  function G_col() {
    var out = {},
      i,
      k;
    for (i = 0; i < arguments.length; i++) {
      for (k in arguments[i]) {
        if (arguments[i].hasOwnProperty(k)) {
          out[k] = arguments[i][k];
        }
      }
    }
    return out;
  }
  var G = { col: G_col };

  function buildCols(data) {
    var fixed = [
      { key: "seq", title: "ลำดับ", cls: "sp-fix sp-fix-1 num" },
      { key: "code", title: "รหัสสินค้า", cls: "sp-fix sp-fix-2 sp-code" },
      { key: "name", title: "รายการ", cls: "sp-fix sp-fix-3" },
      { key: "unit", title: "หน่วย", cls: "sp-fix sp-fix-4" },
    ];
    for (var fw = 0; fw < fixed.length; fw++) {
      fixed[fw].w = widthOf(fixed[fw].key, COL_W[fixed[fw].key]);
    }

    var groups = [];

    /* ช่องมาตรฐานของทุกกลุ่ม: จำนวนกับเป็นเงิน
           kind บอกวิธีแสดงและบอกว่ารวมท้ายตารางได้ไหม
             qty / money  = ตัวเลข รวมได้
             text         = ข้อความ รวมไม่ได้ (เช่นรายการราคา) */
    /* lot = วิธีหยิบค่าตอนวาด "แถวย่อยรายราคา" ช่องไหนไม่มี lot
           แปลว่าแตกตามราคาไม่ได้ (ยกมา / ปรับปรุง / คงเหลือ) แถวย่อยจะเว้นว่าง
           เพราะของที่ยกมาจากเดือนก่อนไม่รู้แล้วว่าซื้อมาล็อตละเท่าไร */
    function qtyCol(field, lotField) {
      return {
        title: "จำนวน",
        kind: "qty",
        get: function (r) {
          return Number(r[field] || 0);
        },
        lot: lotField
          ? function (l) {
              return Number(l[lotField] || 0);
            }
          : null,
      };
    }
    function moneyCol(field, lotField) {
      return {
        title: "เป็นเงิน",
        kind: "money",
        get: function (r) {
          return Number(r[field] || 0);
        },
        lot: lotField
          ? function (l) {
              return Number(l[lotField] || 0);
            }
          : null,
      };
    }

    function plain(key, title, sub, tone, a, b) {
      groups.push({
        key: key,
        title: title,
        sub: sub,
        tone: tone,
        cols: [qtyCol(a), moneyCol(b)],
      });
    }

    /* ---------- สองกลุ่มแรกตรึงไว้ทางซ้ายเหมือนสี่คอลัมน์แรก ----------
     * ตารางกว้างหลายสิบคอลัมน์ พอเลื่อนไปดูหน่วยงานที่อยู่ขวาสุด
     * ก็ไม่เห็นแล้วว่าของตัวนี้ยกมาเท่าไรและเดือนนี้ซื้อเข้ามาเท่าไร
     * ซึ่งเป็นสองตัวเลขที่ต้องเทียบด้วยตลอดเวลาที่ไล่อ่าน
     *
     * แลกมาด้วยพื้นที่ตรึงเกือบ 900px ซึ่งกินจอพอสมควร
     * จอที่แคบกว่า 1280px จึงปลดการตรึงทั้งหมด (ดู app.css)
     */
    groups.push({
      key: "open",
      title: "คงเหลือยกมา",
      sub: "ณ " + thaiShort(data.range.prev_end),
      tone: "open",
      fixCls: "sp-fix-g-open",
      cols: [
        G.col(qtyCol("open_qty"), { cls: "sp-fix sp-fix-5" }),
        G.col(moneyCol("open_val"), { cls: "sp-fix sp-fix-6" }),
      ],
    });

    /* ---------- ซื้อเข้า มีช่องราคาแทรกกลาง ----------
     * ของตัวเดียวกันในเดือนเดียวซื้อมาคนละราคาได้ ถ้าบอกแค่จำนวนรวม
     * กับเงินรวม คนอ่านจะเอาสองค่านั้นมาหารกันเพื่อหาราคา แล้วได้เลข
     * ที่ไม่เคยซื้อจริงสักครั้ง (100 ชิ้นที่ 10 กับ 100 ชิ้นที่ 20
     * หารกันได้ 15) จึงกางราคาที่ซื้อจริงออกมาให้เห็นทุกล็อต
     */
    groups.push({
      key: "buy",
      title: "ซื้อเข้า",
      sub: "รับเข้าในเดือน",
      tone: "buy",
      fixCls: "sp-fix-g-buy",
      cols: [
        G.col(qtyCol("buy_qty", "buy_qty"), { cls: "sp-fix sp-fix-7" }),
        {
          key: "buyprice",
          title: "ราคา/หน่วย",
          kind: "text",
          cls: "sp-lots sp-fix sp-fix-8",
          get: function (r) {
            return lotsText(r.buy_lots);
          },
          html: function (r) {
            return lotsHtml(r);
          },
          lot: function (l) {
            return money(l.price);
          },
        },
        G.col(moneyCol("buy_val", "buy_val"), { cls: "sp-fix sp-fix-9" }),
      ],
    });

    /* คอลัมน์ปรับปรุงยอดโผล่เฉพาะเดือนที่มีการปรับจริง
           เดือนปกติจะได้ตารางหน้าตาเหมือนใบที่ใช้กันอยู่เป๊ะ ๆ */
    if (data.hasAdjust) {
      plain(
        "adj",
        "ปรับปรุงยอด",
        "เพิ่ม/ลดนอกเอกสาร",
        "adj",
        "adj_qty",
        "adj_val",
      );
    }

    /* หน่วยงานที่เบิกในเดือนนี้ กลุ่มละหน่วย
           ปิด key ของหน่วยงานไว้ใน closure ทีละตัว ไม่ใช้ตัวแปรวนซ้ำร่วมกัน
           ไม่งั้นทุกกลุ่มจะไปอ่านค่าของหน่วยงานสุดท้ายเหมือนกันหมด */
    function deptGroup(dep, index) {
      return {
        key: "d" + dep.id,
        title: dep.name,
        sub: dep.company,
        /* สลับสีทีละหน่วยงาน เพราะหน่วยงานหนึ่งกินสองคอลัมน์ติดกัน
                   ถ้าพื้นสีเดียวกันหมด ตาจะจับคู่ผิดทันทีที่เลื่อนไปไกล ๆ
                   แล้วอ่านจำนวนของหน่วยหนึ่งคู่กับเงินของอีกหน่วยหนึ่ง */
        tone: index % 2 === 0 ? "dept" : "dept-alt",
        cols: [
          {
            title: "จำนวน",
            kind: "qty",
            get: function (r) {
              var c = r.dept["d" + dep.id];
              return c ? Number(c.qty) : 0;
            },
            lot: function (l) {
              var c = l.dept["d" + dep.id];
              return c ? Number(c.qty) : 0;
            },
          },
          {
            title: "เป็นเงิน",
            kind: "money",
            get: function (r) {
              var c = r.dept["d" + dep.id];
              return c ? Number(c.val) : 0;
            },
            lot: function (l) {
              var c = l.dept["d" + dep.id];
              return c ? Number(c.val) : 0;
            },
          },
        ],
      };
    }
    for (var i = 0; i < data.departments.length; i++) {
      groups.push(deptGroup(data.departments[i], i));
    }

    groups.push({
      key: "out",
      title: "รวมเบิกทั้งหมด",
      sub: "ทุกหน่วยงาน",
      tone: "out",
      cols: [qtyCol("out_qty", "out_qty"), moneyCol("out_val", "out_val")],
    });
    plain(
      "close",
      "คงเหลือปลายเดือน",
      "ยกไปเดือนถัดไป",
      "close",
      "close_qty",
      "close_val",
    );

    /* ---------- ตัดกลุ่มและช่องที่ผู้ใช้ปิดไว้ออก ---------- */
    var keep = [];
    for (var g = 0; g < groups.length; g++) {
      if (hiddenCols[groups[g].key]) {
        continue;
      }

      var gc = [];
      for (var e = 0; e < groups[g].cols.length; e++) {
        var ck = groups[g].cols[e].key;
        if (ck && hiddenCols[ck]) {
          continue;
        }
        gc.push(groups[g].cols[e]);
      }
      if (gc.length === 0) {
        continue;
      }

      groups[g].cols = gc;
      keep.push(groups[g]);
    }
    groups = keep;

    /* ---------- กุญแจและความกว้างของทุกช่องในกลุ่ม ----------
           กุญแจใช้ชื่อกลุ่มบวกลำดับช่องในกลุ่ม ไม่ใช่ลำดับในตาราง
           เดือนที่หน่วยงานสลับตำแหน่งจึงยังจำความกว้างเดิมได้ */
    for (var gw = 0; gw < groups.length; gw++) {
      for (var cw = 0; cw < groups[gw].cols.length; cw++) {
        var col = groups[gw].cols[cw];
        col.wkey = groups[gw].key + ":" + cw;
        col.w = widthOf(col.wkey, COL_W[col.kind] || COL_W.money);
      }
    }

    /* ---------- ตำแหน่งซ้ายของช่องที่ตรึง ----------
     * ไล่บวกความกว้าง "ที่ใช้จริง" ของช่องที่เหลืออยู่ ตามลำดับซ้ายไปขวา
     * ปักไว้ใน CSS ไม่ได้ เพราะทั้งการซ่อนคอลัมน์และการลากย่อ/ขยาย
     * ทำให้ตำแหน่งของตัวที่เหลือเปลี่ยนไปตลอด
     */
    var at = 0;
    for (var f2 = 0; f2 < fixed.length; f2++) {
      fixed[f2].left = at;
      at += fixed[f2].w;
    }
    for (var g2 = 0; g2 < groups.length; g2++) {
      var head = null;
      for (var e2 = 0; e2 < groups[g2].cols.length; e2++) {
        if (!fixKey(groups[g2].cols[e2].cls)) {
          continue;
        }
        if (head === null) {
          head = at;
        }
        groups[g2].cols[e2].left = at;
        at += groups[g2].cols[e2].w;
      }
      groups[g2].left = head; /* ใช้กับหัวกลุ่มชั้นบน */
    }

    var width = fixed.length;
    var totalW = 0;
    for (var f3 = 0; f3 < fixed.length; f3++) {
      totalW += fixed[f3].w;
    }
    for (var g3 = 0; g3 < groups.length; g3++) {
      width += groups[g3].cols.length;
      for (var c3 = 0; c3 < groups[g3].cols.length; c3++) {
        totalW += groups[g3].cols[c3].w;
      }
    }

    return {
      fixed: fixed,
      groups: groups,
      width: width,
      totalW: Math.round(totalW * 1000) / 1000,
    };
  }

  /* ---------- ข้อความสรุปราคาที่ซื้อจริงในเดือนนั้น ----------
   * ราคาเดียว        ->  "10.00"
   * หลายราคา        ->  "10.00 x100 · 20.00 x100"
   *
   * ติดจำนวนไว้ด้วยเฉพาะตอนมีหลายราคา เพราะราคาเดียวจำนวนก็อยู่ในช่องข้าง ๆ
   * อยู่แล้ว เขียนซ้ำมีแต่จะทำให้ตารางที่กว้างอยู่แล้วอ่านยากขึ้นเปล่า ๆ
   */
  function lotsText(lots) {
    if (!lots || lots.length === 0) {
      return "";
    }
    if (lots.length === 1) {
      return money(lots[0].price);
    }

    var out = [];
    for (var i = 0; i < lots.length; i++) {
      out.push(money(lots[i].price) + " x" + qty(lots[i].qty));
    }
    return out.join(" · ");
  }

  /* ---------- ช่องราคาบนจอ ----------
   * ของตัวเดียวกันในเดือนเดียวมีได้หลายราคา ถ้าเขียนต่อกันในช่องเดียว
   * ข้อความจะยาวกว่าช่องตัวเลขข้าง ๆ หลายเท่า ดันจนคอลัมน์อื่นอ่านยาก
   * และยังตอบไม่ได้อยู่ดีว่าหน่วยงานไหนเบิกของราคาไหนไป
   *
   * จึงกางเป็น "แถวย่อยใต้สินค้า" แทน หนึ่งราคาหนึ่งแถว ตรงคอลัมน์กับ
   * แถวหลักทุกช่อง อ่านลงมาตามแนวตั้งได้เลยว่าราคานั้นซื้อมาเท่าไร
   * แล้วหน่วยงานไหนเบิกไปเท่าไร
   *
   * ราคาเดียวไม่ต้องมีปุ่ม เพราะแถวย่อยจะได้ตัวเลขชุดเดิมกับแถวหลักเป๊ะ ๆ
   */
  function lotsHtml(row) {
    var lots = row.buy_lots;
    var many = row.price_rows && row.price_rows.length > 1;

    if (!many) {
      if (!lots || lots.length === 0) {
        return '<span class="sp-zero">-</span>';
      }
      return esc(money(lots[0].price));
    }

    return (
      '<button type="button" class="sp-lots-btn" aria-expanded="false"' +
      ' data-pid="' +
      row.product_id +
      '" title="' +
      esc(lotsText(lots)) +
      '">' +
      row.price_rows.length +
      " ราคา</button>"
    );
  }

  /* ---------- แถวที่จะแสดง หลังผ่านตัวกรองในหน้า ---------- */
  function visibleRows() {
    var movedOnly = el("spMovedOnly").checked;
    var term = el("spSearch")
      .value.toLowerCase()
      .replace(/^\s+|\s+$/g, "");

    var out = [];
    for (var i = 0; i < DATA.rows.length; i++) {
      var r = DATA.rows[i];
      if (movedOnly && !r.moved) {
        continue;
      }
      if (term !== "") {
        var hay = (r.product_code + " " + r.product_name).toLowerCase();
        if (hay.indexOf(term) < 0) {
          continue;
        }
      }
      out.push(r);
    }
    return out;
  }

  /* ============================================================
   *  วาดตาราง
   * ============================================================ */
  function cellNum(value, isMoney) {
    /* ศูนย์แสดงเป็นขีด ไม่ใช่เลข 0 — ตารางนี้มีช่องว่างเป็นส่วนใหญ่
           ถ้าพิมพ์ 0 เต็มไปหมด ตัวเลขที่มีค่าจริงจะจมหายไปในกอง */
    if (!value) {
      return '<span class="sp-zero">-</span>';
    }
    return isMoney ? money(value) : qty(value);
  }

  /* ---------- แถวย่อยรายราคา ----------
   * ซ่อนไว้ก่อน กดปุ่มในช่องราคาถึงจะโผล่ ทุกช่องตรงคอลัมน์กับแถวหลัก
   * อ่านลงมาตามแนวตั้งได้ว่าราคานั้นซื้อมาเท่าไร หน่วยงานไหนเบิกไปเท่าไร
   *
   * ช่องที่แตกตามราคาไม่ได้ (ยกมา / ปรับปรุง / คงเหลือ) เว้นว่างไว้เฉย ๆ
   * ไม่ใส่ขีด เพราะขีดแปลว่า "ศูนย์" ซึ่งคนละความหมายกับ "แยกไม่ได้"
   * ของที่ยกมาจากเดือนก่อนไม่มีใครรู้แล้วว่าตอนนั้นซื้อมาล็อตละเท่าไร
   */
  function lotRows(cols, r) {
    var lots = r.price_rows;
    if (!lots || lots.length < 2) {
      return "";
    }

    var out = "";
    for (var i = 0; i < lots.length; i++) {
      var l = lots[i];

      out +=
        '<tr class="sp-lot-row" data-pid="' +
        r.product_id +
        '" hidden>' +
        '<td class="sp-fix sp-fix-1"' +
        fixStyle(cols.fixed[0]) +
        "></td>" +
        '<td class="sp-fix sp-fix-2"' +
        fixStyle(cols.fixed[1]) +
        "></td>" +
        '<td class="sp-fix sp-fix-3 sp-lot-label"' +
        fixStyle(cols.fixed[2]) +
        ">" +
        "&#8627; ราคา " +
        esc(money(l.price)) +
        "</td>" +
        '<td class="sp-fix sp-fix-4"' +
        fixStyle(cols.fixed[3]) +
        "></td>";

      for (var g = 0; g < cols.groups.length; g++) {
        var gc = cols.groups[g].cols;
        for (var e = 0; e < gc.length; e++) {
          var lcls =
            " sp-g-" + cols.groups[g].tone + (gc[e].cls ? " " + gc[e].cls : "");

          if (!gc[e].lot) {
            out +=
              '<td class="' + lcls.substr(1) + '"' + fixStyle(gc[e]) + "></td>";
            continue;
          }

          var v = gc[e].lot(l);
          if (gc[e].kind === "text") {
            out +=
              '<td class="' +
              lcls.substr(1) +
              '"' +
              fixStyle(gc[e]) +
              ">" +
              esc(v) +
              "</td>";
            continue;
          }

          out +=
            '<td class="num' +
            (gc[e].kind === "money" ? " money" : "") +
            lcls +
            '"' +
            fixStyle(gc[e]) +
            ">" +
            cellNum(v, gc[e].kind === "money") +
            "</td>";
        }
      }
      out += "</tr>";
    }
    return out;
  }

  /* ---------- ช่องว่าง ๆ ของสี่คอลัมน์แรกในแถวย่อย ---------- */
  function fixBlank(label) {
    return (
      '<td class="sp-fix sp-fix-1"></td>' +
      '<td class="sp-fix sp-fix-2"></td>' +
      '<td class="sp-fix sp-fix-3 sp-lot-label">' +
      label +
      "</td>" +
      '<td class="sp-fix sp-fix-4"></td>'
    );
  }

  /* ---------- ถังรวมยอด รูปร่างเดียวกับ cols.groups ---------- */
  function zeros(cols) {
    var out = [];
    for (var g = 0; g < cols.groups.length; g++) {
      var one = [];
      for (var e = 0; e < cols.groups[g].cols.length; e++) {
        one.push(0);
      }
      out.push(one);
    }
    return out;
  }

  /* ---------- แถวรวมของหมวด ----------
   * ใช้ทั้งหมวดหมู่ย่อยและหมวดหมู่หลัก ต่างกันแค่ระดับสีกับคำนำหน้า
   * ช่องที่เป็นข้อความ (ราคา/หน่วย) เว้นว่าง เพราะเอาราคามาบวกกันไม่มีความหมาย
   */
  function totalRow(cols, level, label, acc) {
    var cls = level === "cat" ? "sp-sum-cat" : "sp-sum-sub";
    /* ห่อป้ายด้วย span ที่ตรึงได้เหมือนป้ายหมวด ช่องนี้พาดสี่คอลัมน์
           ซึ่งกว้างพอที่ข้อความยาว ๆ จะเลยขอบไปตอนเลื่อนตาราง */
    var out =
      '<tr class="' +
      cls +
      '">' +
      '<td colspan="' +
      cols.fixed.length +
      '" class="sp-fix sp-fix-all sp-sum-label">' +
      '<span class="sp-cat-label">' +
      esc(
        (level === "cat" ? "รวมหมวดหมู่หลัก · " : "รวมหมวดหมู่ย่อย · ") + label,
      ) +
      "</span></td>";

    for (var g = 0; g < cols.groups.length; g++) {
      var gc = cols.groups[g].cols;
      for (var e = 0; e < gc.length; e++) {
        var base =
          " sp-g-" + cols.groups[g].tone + (gc[e].cls ? " " + gc[e].cls : "");
        if (gc[e].kind === "text") {
          out +=
            '<td class="' + base.substr(1) + '"' + fixStyle(gc[e]) + "></td>";
          continue;
        }
        out +=
          '<td class="num' +
          (gc[e].kind === "money" ? " money" : "") +
          base +
          '"' +
          fixStyle(gc[e]) +
          ">" +
          cellNum(acc[g][e], gc[e].kind === "money") +
          "</td>";
      }
    }
    return out + "</tr>";
  }

  /* ---------- ที่จับสำหรับลากย่อ/ขยาย ----------
       ใส่ไว้ในหัวคอลัมน์ทุกช่องของหัวชั้นล่าง และในสี่คอลัมน์แรกที่พาดสองชั้น
       data-wkey บอกว่ากำลังลากคอลัมน์ไหน จะได้จำความกว้างไว้ถูกตัว */
  function grip(wkey) {
    return (
      '<span class="sp-grip" data-wkey="' +
      esc(wkey) +
      '"' +
      ' title="ลากเพื่อย่อ/ขยาย · ดับเบิลคลิกเพื่อคืนความกว้างเดิม"></span>'
    );
  }

  function draw(cols, rows) {
    var html = "";

    /* ---------- ความกว้างของทุกคอลัมน์ ----------
     * ประกาศครั้งเดียวใน colgroup แล้วให้ตารางเป็น table-layout: fixed
     * เบราว์เซอร์จึงไม่ต้องวัดเนื้อหาทุกช่องเพื่อเดาความกว้าง
     * (สามร้อยกว่าแถวคูณสี่สิบคอลัมน์ การวัดใหม่ทุกครั้งที่ลากคือคอขวด)
     * และการลากย่อ/ขยายก็แค่แก้ตัวเลขในนี้ตัวเดียว
     */
    html += "<colgroup>";
    for (var cg = 0; cg < cols.fixed.length; cg++) {
      html += '<col style="width:' + cols.fixed[cg].w + 'rem">';
    }
    for (var cgg = 0; cgg < cols.groups.length; cgg++) {
      var cgc = cols.groups[cgg].cols;
      for (var cgi = 0; cgi < cgc.length; cgi++) {
        html += '<col style="width:' + cgc[cgi].w + 'rem">';
      }
    }
    html += "</colgroup>";

    /* ---------- หัวสองชั้น ---------- */
    html += "<thead><tr>";
    for (var f = 0; f < cols.fixed.length; f++) {
      html +=
        '<th rowspan="2" class="' +
        cols.fixed[f].cls +
        '"' +
        fixStyle(cols.fixed[f]) +
        ">" +
        esc(cols.fixed[f].title) +
        grip(cols.fixed[f].key) +
        "</th>";
    }
    for (var g = 0; g < cols.groups.length; g++) {
      var grp = cols.groups[g];
      html +=
        '<th colspan="' +
        grp.cols.length +
        '" class="sp-g sp-g-' +
        grp.tone +
        (grp.fixCls && typeof grp.left === "number"
          ? " sp-fix " + grp.fixCls
          : "") +
        '"' +
        (typeof grp.left === "number" && grp.fixCls
          ? ' style="left:' + grp.left + 'rem"'
          : "") +
        (grp.sub ? ' title="' + esc(grp.sub) + '"' : "") +
        ">" +
        esc(grp.title) +
        (grp.sub ? "<small>" + esc(grp.sub) + "</small>" : "") +
        "</th>";
    }
    html += "</tr><tr>";
    for (var s = 0; s < cols.groups.length; s++) {
      var sc = cols.groups[s].cols;
      for (var k = 0; k < sc.length; k++) {
        html +=
          '<th class="num sp-sub sp-g-' +
          cols.groups[s].tone +
          (sc[k].cls ? " " + sc[k].cls : "") +
          '"' +
          fixStyle(sc[k]) +
          ">" +
          esc(sc[k].title) +
          grip(sc[k].wkey) +
          "</th>";
      }
    }
    html += "</tr></thead>";

    if (rows.length === 0) {
      html +=
        '<tbody><tr><td colspan="' +
        cols.width +
        '" class="rc-empty">' +
        "ไม่มีรายการที่ตรงกับตัวกรอง</td></tr></tbody>";
      el("spTable").style.width = cols.totalW + "rem";
      el("spTable").innerHTML = html;
      return;
    }

    var gsum = zeros(cols); /* ยอดรวมทั้งใบ */
    var subAcc = null; /* ยอดรวมหมวดหมู่ย่อยที่กำลังเปิดอยู่ */
    var catAcc = null; /* ยอดรวมหมวดหมู่หลักที่กำลังเปิดอยู่ */

    var body = "<tbody>";
    var lastCat = null;
    var lastSub = null;
    var seq = 0;

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];

      /* ---------- เปลี่ยนหมวดหมู่หลัก : ปิดหมวดย่อยที่ค้างก่อน แล้วค่อยปิดหมวดหลัก ----------
               ลำดับสำคัญ ถ้าปิดหมวดหลักก่อน ยอดรวมหมวดย่อยกลุ่มสุดท้ายจะหายไป */
      if (r.category_name !== lastCat) {
        if (lastSub !== null) {
          body += totalRow(cols, "sub", lastSub, subAcc);
        }
        if (lastCat !== null) {
          body += totalRow(cols, "cat", lastCat, catAcc);
        }

        lastCat = r.category_name;
        lastSub = null;
        catAcc = zeros(cols);

        body +=
          '<tr class="sp-cat"><td colspan="' +
          cols.width +
          '">' +
          '<span class="sp-cat-label">' +
          esc("รายการ" + lastCat) +
          "</span></td></tr>";
      }

      if (r.sub_category_name !== lastSub) {
        if (lastSub !== null) {
          body += totalRow(cols, "sub", lastSub, subAcc);
        }

        lastSub = r.sub_category_name;
        subAcc = zeros(cols);

        body +=
          '<tr class="sp-subcat"><td colspan="' +
          cols.width +
          '">' +
          '<span class="sp-cat-label">' +
          esc(lastSub) +
          "</span></td></tr>";
      }

      seq++;
      body +=
        "<tr" +
        (r.moved ? ' class="is-moved"' : "") +
        ">" +
        '<td class="sp-fix sp-fix-1 num"' +
        fixStyle(cols.fixed[0]) +
        ">" +
        seq +
        "</td>" +
        '<td class="sp-fix sp-fix-2 sp-code"' +
        fixStyle(cols.fixed[1]) +
        ">" +
        esc(r.product_code) +
        "</td>" +
        '<td class="sp-fix sp-fix-3"' +
        fixStyle(cols.fixed[2]) +
        ' title="' +
        esc(r.product_name) +
        '">' +
        esc(r.product_name) +
        "</td>" +
        '<td class="sp-fix sp-fix-4"' +
        fixStyle(cols.fixed[3]) +
        ">" +
        esc(r.unit_name) +
        "</td>";

      for (var c = 0; c < cols.groups.length; c++) {
        var gc = cols.groups[c].cols;
        for (var e = 0; e < gc.length; e++) {
          var val = gc[e].get(r);
          var cls =
            " sp-g-" + cols.groups[c].tone + (gc[e].cls ? " " + gc[e].cls : "");

          if (gc[e].kind === "text") {
            var inner = gc[e].html
              ? gc[e].html(r)
              : val === ""
                ? '<span class="sp-zero">-</span>'
                : esc(val);
            body +=
              '<td class="' +
              cls.substr(1) +
              '"' +
              fixStyle(gc[e]) +
              ">" +
              inner +
              "</td>";
            continue;
          }

          var num = Number(val);
          gsum[c][e] += num;
          catAcc[c][e] += num;
          subAcc[c][e] += num;

          body +=
            '<td class="num' +
            (gc[e].kind === "money" ? " money" : "") +
            cls +
            '"' +
            fixStyle(gc[e]) +
            ">" +
            cellNum(num, gc[e].kind === "money") +
            "</td>";
        }
      }
      body += "</tr>";

      body += lotRows(cols, r);
    }

    /* ปิดหมวดสุดท้ายที่ยังค้างอยู่ */
    if (lastSub !== null) {
      body += totalRow(cols, "sub", lastSub, subAcc);
    }
    if (lastCat !== null) {
      body += totalRow(cols, "cat", lastCat, catAcc);
    }
    body += "</tbody>";

    /* ---------- ยอดรวมทั้งใบ ----------
           รวมจากแถวที่แสดงอยู่จริง ไม่ใช่ยอดรวมทั้งเดือนที่ backend ส่งมา
           กรองอะไรไว้ ยอดท้ายตารางก็ตรงกับที่ตาเห็นเสมอ */
    var foot =
      "<tfoot><tr>" +
      '<td colspan="' +
      cols.fixed.length +
      '" class="sp-fix sp-fix-all sp-sum-label">' +
      '<span class="sp-cat-label">รวมทั้งหมด ' +
      qty(rows.length) +
      " รายการ</span></td>";

    for (var t = 0; t < gsum.length; t++) {
      var tc = cols.groups[t].cols;
      for (var w = 0; w < tc.length; w++) {
        var tcls =
          " sp-g-" + cols.groups[t].tone + (tc[w].cls ? " " + tc[w].cls : "");
        if (tc[w].kind === "text") {
          /* ช่องราคาไม่มียอดรวม เอาราคาหลาย ๆ ตัวมาบวกกันไม่มีความหมาย */
          foot +=
            '<td class="' + tcls.substr(1) + '"' + fixStyle(tc[w]) + "></td>";
          continue;
        }
        foot +=
          '<td class="num' +
          (tc[w].kind === "money" ? " money" : "") +
          tcls +
          '"' +
          fixStyle(tc[w]) +
          ">" +
          cellNum(gsum[t][w], tc[w].kind === "money") +
          "</td>";
      }
    }
    foot += "</tr></tfoot>";

    /* ---------- ตารางต้องบอกความกว้างของตัวเอง ----------
     * table-layout: fixed กับ width: auto เบราว์เซอร์จะบีบตารางให้เท่ากรอบ
     * ที่ครอบอยู่ แล้วเฉลี่ยความกว้างคอลัมน์เอง ไม่สนใจ <colgroup> เลย
     * ผลคือทุกคอลัมน์แคบเท่ากันหมดและตำแหน่งที่ตรึงไว้ก็เพี้ยนตาม
     *
     * บอกความกว้างรวมไปตรง ๆ ตารางจึงกว้างตามคอลัมน์จริง แล้วกรอบ .sp-wrap
     * ค่อยเลื่อนเอา ซึ่งเป็นพฤติกรรมเดิมก่อนเปลี่ยนมาใช้ fixed */
    el("spTable").style.width = cols.totalW + "rem";
    el("spTable").innerHTML = html + body + foot;
  }

  /* ============================================================
   *  ลากขอบหัวคอลัมน์เพื่อย่อ/ขยาย
   * ============================================================
   *  ระหว่างลากแก้แค่ <col> ตัวเดียวกับตำแหน่งของคอลัมน์ที่ตรึงอยู่
   *  ไม่ได้วาดตารางใหม่ เพราะสามร้อยกว่าแถวคูณสี่สิบคอลัมน์ วาดใหม่ทุกครั้ง
   *  ที่เมาส์ขยับจะกระตุกจนใช้ไม่ได้
   *
   *  ปล่อยเมาส์แล้วค่อยวาดใหม่รอบเดียว ให้ทุกอย่างกลับมาตรงกันแน่นอน
   * ============================================================ */
  var dragCol = null;

  function remPx() {
    var v = parseFloat(getComputedStyle(document.documentElement).fontSize);
    return v > 0 ? v : 16;
  }

  /* ลำดับของคอลัมน์นี้ในตาราง ใช้หา <col> ที่ต้องแก้ */
  function colIndexOf(cols, wkey) {
    var i = 0,
      g,
      e;
    for (i = 0; i < cols.fixed.length; i++) {
      if (cols.fixed[i].key === wkey) {
        return i;
      }
    }
    i = cols.fixed.length;
    for (g = 0; g < cols.groups.length; g++) {
      for (e = 0; e < cols.groups[g].cols.length; e++) {
        if (cols.groups[g].cols[e].wkey === wkey) {
          return i;
        }
        i++;
      }
    }
    return -1;
  }

  function startDrag(ev, wkey) {
    var cols = buildCols(DATA);
    var idx = colIndexOf(cols, wkey);
    if (idx < 0) {
      return;
    }

    var colEls = el("spTable").getElementsByTagName("col");
    if (!colEls[idx]) {
      return;
    }

    dragCol = {
      wkey: wkey,
      idx: idx,
      el: colEls[idx],
      from: ev.clientX,
      base: parseFloat(colEls[idx].style.width) || 6,
      unit: remPx(),
    };
    document.body.className += " sp-resizing";
    ev.preventDefault();
  }

  function moveDrag(ev) {
    if (!dragCol) {
      return;
    }

    var w = dragCol.base + (ev.clientX - dragCol.from) / dragCol.unit;
    if (w < COL_MIN) {
      w = COL_MIN;
    }
    if (w > COL_MAX) {
      w = COL_MAX;
    }

    dragCol.w = Math.round(w * 100) / 100;
    dragCol.el.style.width = dragCol.w + "rem";
  }

  function endDrag() {
    if (!dragCol) {
      return;
    }

    if (typeof dragCol.w === "number") {
      colWidth[dragCol.wkey] = dragCol.w;
    }
    dragCol = null;
    document.body.className = document.body.className.replace(
      /\s*sp-resizing/,
      "",
    );

    /* วาดใหม่รอบเดียว ตำแหน่งของคอลัมน์ที่ตรึงทางซ้ายจะได้ขยับตามความกว้างใหม่ */
    render();
  }

  /* ---------- วาดใหม่ทั้งหน้าเมื่อข้อมูลหรือตัวกรองเปลี่ยน ---------- */
  function render() {
    if (!DATA) {
      return;
    }

    var cols = buildCols(DATA);
    var rows = visibleRows();

    draw(cols, rows);

    setText(
      "spCount",
      "แสดง " +
        qty(rows.length) +
        " จาก " +
        qty(DATA.rows.length) +
        " รายการ" +
        " · มีความเคลื่อนไหวในเดือนนี้ " +
        qty(DATA.summary.moved) +
        " รายการ" +
        " · หน่วยงานที่เบิก " +
        qty(DATA.departments.length) +
        " หน่วยงาน",
    );
  }

  /* หัวรายงานกับกล่องยอดสรุปเป็นส่วนที่ถอดออกได้ ใครจัดหน้าใหม่แล้วเอาออก
       รายงานต้องยังทำงานเหมือนเดิม ไม่ใช่พังทั้งหน้าเพราะเขียนข้อความลงช่อง
       ที่ไม่มีอยู่แล้ว — ตารางคือของจริงของหน้านี้ ที่เหลือเป็นของแถม */
  function setText(id, text) {
    var node = el(id);
    if (node) {
      node.textContent = text;
    }
  }

  function renderHead() {
    var s = DATA.summary;

    setText("spMonthText", thaiMonth(DATA.range.month));
    setText("sumOpenDate", thaiShort(DATA.range.prev_end));

    setText("sumOpen", money(s.open_val));
    setText("sumBuy", money(s.buy_val));
    setText("sumBuyQty", qty(s.buy_qty));
    setText("sumOut", money(s.out_val));
    setText("sumOutQty", qty(s.out_qty));
    setText("sumDept", qty(DATA.departments.length));
    setText("sumClose", money(s.close_val));
    setText("sumCloseQty", qty(s.close_qty));
  }

  /* ============================================================
   *  ตัวเลือกคอลัมน์
   * ============================================================
   *  เดือนที่มีหน่วยงานเบิกสิบกว่าหน่วย ตารางกว้างสี่สิบกว่าคอลัมน์
   *  คนที่เปิดมาดูแค่หน่วยงานตัวเองต้องเลื่อนผ่านของคนอื่นทั้งแถว
   *
   *  ให้ปิดคอลัมน์ที่ไม่ได้ดูได้เอง ตัวเลือกมีผลทั้งบนจอและในไฟล์ Excel
   *  เพราะทั้งสองทางใช้โครงคอลัมน์ชุดเดียวกัน
   *
   *  เก็บเป็น "รายการที่ปิด" ไม่ใช่ "รายการที่เปิด" — เปลี่ยนเดือนแล้ว
   *  หน่วยงานที่เพิ่งโผล่มาจะได้แสดงเองโดยไม่ต้องไปติ๊กเพิ่ม
   */
  function colChoices() {
    var out = [];

    out.push({ key: "open", label: "คงเหลือยกมา" });
    out.push({ key: "buy", label: "ซื้อเข้า" });
    out.push({
      key: "buyprice",
      label: "ราคา/หน่วย (ในกลุ่มซื้อเข้า)",
      child: true,
    });
    if (DATA.hasAdjust) {
      out.push({ key: "adj", label: "ปรับปรุงยอด" });
    }

    for (var i = 0; i < DATA.departments.length; i++) {
      var d = DATA.departments[i];
      out.push({
        key: "d" + d.id,
        label: trimText(d.name),
        note: d.company,
        dept: true,
      });
    }

    out.push({ key: "out", label: "รวมเบิกทั้งหมด" });
    out.push({ key: "close", label: "คงเหลือปลายเดือน" });
    return out;
  }

  function trimText(v) {
    return String(v === null || v === undefined ? "" : v).replace(
      /^\s+|\s+$/g,
      "",
    );
  }

  function renderCols() {
    var box = el("spCols");
    if (!box || !DATA) {
      return;
    }

    var list = colChoices();
    var html =
      '<div class="sp-cols-head">' +
      "<span>เลือกคอลัมน์ที่จะแสดง</span>" +
      '<button type="button" id="spColsAll" class="sp-cols-link">เลือกทั้งหมด</button>' +
      '<button type="button" id="spColsDept" class="sp-cols-link">เฉพาะหน่วยงาน</button>' +
      '</div><div class="sp-cols-list">';

    var lastDept = false;
    for (var i = 0; i < list.length; i++) {
      var c = list[i];

      if (c.dept && !lastDept) {
        html += '<div class="sp-cols-sep">หน่วยงานที่เบิกในเดือนนี้</div>';
      }
      lastDept = !!c.dept;

      html +=
        '<label class="sp-cols-item' +
        (c.child ? " is-child" : "") +
        '">' +
        '<input type="checkbox" data-col="' +
        esc(c.key) +
        '"' +
        (hiddenCols[c.key] ? "" : " checked") +
        ">" +
        "<span>" +
        esc(c.label) +
        (c.note ? "<small>" + esc(c.note) + "</small>" : "") +
        "</span></label>";
    }
    box.innerHTML = html + "</div>";
  }

  /* จำนวนคอลัมน์ที่ปิดอยู่ ไว้ขึ้นตัวเลขบนปุ่มให้รู้ว่ากำลังซ่อนอะไรอยู่
       ไม่งั้นคนเปิดหน้ามาเจอตารางไม่ครบแล้วนึกว่ารายงานพัง */
  function colsHiddenCount() {
    var n = 0;
    for (var k in hiddenCols) {
      if (hiddenCols.hasOwnProperty(k) && hiddenCols[k]) {
        n++;
      }
    }
    return n;
  }

  function updateColsBadge() {
    var b = el("spColsCount");
    if (!b) {
      return;
    }
    var n = colsHiddenCount();
    b.textContent = String(n);
    setHidden(b, n === 0);
  }

  /* ============================================================
   *  โหลดข้อมูล
   * ============================================================ */
  function load(month) {
    setHidden(el("spBody"), true);
    setHidden(el("spLoading"), false);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", CFG.endpoint, true);
    xhr.setRequestHeader("Content-Type", "application/json; charset=utf-8");

    xhr.onload = function () {
      var res = null;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        res = null;
      }

      if (!res || !res.ok) {
        setHidden(el("spLoading"), true);
        var msg = res && res.message ? res.message : "ดึงข้อมูลรายงานไม่สำเร็จ";
        if (typeof window.appError === "function") {
          window.appError(msg);
        }
        return;
      }

      /* กำลังเดินคู่มืออยู่บนข้อมูลสาธิต ห้ามวาดทับกลางคัน
               พักไว้ให้ demoTeardown เอาขึ้นจอตอนปิดคู่มือ */
      if (demoOn) {
        demoBackup.data = res;
        return;
      }

      DATA = res;
      setHidden(el("spLoading"), true);
      setHidden(el("spBody"), false);
      renderHead();
      renderCols();
      updateColsBadge();
      render();
    };
    xhr.onerror = function () {
      setHidden(el("spLoading"), true);
      if (typeof window.appError === "function") {
        window.appError("ติดต่อเซิร์ฟเวอร์ไม่ได้");
      }
    };
    xhr.send(JSON.stringify({ action: "supply", month: month }));
  }

  /* ============================================================
   *  ส่งออก Excel
   * ============================================================
   *  ใช้โครงคอลัมน์ชุดเดียวกับที่วาดบนจอ ไฟล์ที่ได้จึงตรงกับที่ตาเห็น
   *  รวมถึงหัวสองชั้นและคอลัมน์หน่วยงานที่กางตามเดือน
   *
   *  ศูนย์ส่งออกเป็นช่องว่าง ไม่ใช่เลข 0 ให้เหมือนบนจอ และ SUM ใน Excel
   *  ยังทำงานได้ตามปกติเพราะช่องว่างไม่ถูกนับ
   *
   *  โหลด SheetJS ตอนกดเท่านั้น เพราะหนัก 273 KB และคนส่วนใหญ่แค่เปิดดู
   */
  function busy(on) {
    var btn = el("btnExport");
    btn.disabled = on;
    setHidden(btn.querySelector(".btn-icon"), on);
    setHidden(btn.querySelector(".btn-spinner"), !on);
  }

  function num0(v) {
    return v ? Number(v) : "";
  }

  /* บรรทัดรวมของหมวด ในไฟล์ Excel */
  function sheetTotal(cols, level, label, acc) {
    var line = [
      (level === "cat" ? "รวมหมวดหมู่หลัก · " : "รวมหมวดหมู่ย่อย · ") + label,
      "",
      "",
      "",
    ];
    for (var g = 0; g < cols.groups.length; g++) {
      var gc = cols.groups[g].cols;
      for (var e = 0; e < gc.length; e++) {
        line.push(gc[e].kind === "text" ? "" : num0(acc[g][e]));
      }
    }
    return line;
  }

  /* แถวย่อยรายราคาถูกส่งออกเสมอ และตั้ง outline ให้ Excel ยุบ/กางได้ */
  function buildSheet(cols, rows) {
    var aoa = [];
    var merges = [];
    var levels = []; /* ระดับ outline ของแต่ละแถว ไว้ทำกลุ่มยุบได้ */
    var rowKinds = [];
    var width = cols.width;

    /* แถวที่ 1 : ชื่อรายงาน พาดยาวทั้งใบ */
    var title = [
      "รายละเอียดการสั่งซื้อ - เบิกใช้ ประจำเดือน " +
        thaiMonth(DATA.range.month),
    ];
    while (title.length < width) {
      title.push("");
    }
    aoa.push(title);
    rowKinds.push("title");
    merges.push({ s: { r: 0, c: 0 }, e: { r: 0, c: width - 1 } });

    /* แถวที่ 2-3 : หัวสองชั้น */
    var h1 = [],
      h2 = [],
      col = 0,
      i;

    for (i = 0; i < cols.fixed.length; i++) {
      h1.push(cols.fixed[i].title);
      h2.push("");
      merges.push({ s: { r: 1, c: col }, e: { r: 2, c: col } });
      col++;
    }
    for (i = 0; i < cols.groups.length; i++) {
      var g = cols.groups[i];
      var gc = g.cols;

      h1.push(g.title + (g.sub ? " (" + g.sub + ")" : ""));
      h2.push(gc[0].title);
      for (var j = 1; j < gc.length; j++) {
        h1.push("");
        h2.push(gc[j].title);
      }
      merges.push({ s: { r: 1, c: col }, e: { r: 1, c: col + gc.length - 1 } });
      col += gc.length;
    }
    aoa.push(h1);
    aoa.push(h2);
    rowKinds.push("header");
    rowKinds.push("header");

    /* ---------- เนื้อตาราง พร้อมหัวหมวดคั่นเหมือนบนจอ ---------- */
    var gsum = [];
    for (i = 0; i < cols.groups.length; i++) {
      var zz = [];
      for (var y = 0; y < cols.groups[i].cols.length; y++) {
        zz.push(0);
      }
      gsum.push(zz);
    }

    var subAcc = null;
    var catAcc = null;
    var lastCat = null;
    var lastSub = null;
    var seq = 0;

    for (var r = 0; r < rows.length; r++) {
      var row = rows[r];

      if (row.category_name !== lastCat) {
        if (lastSub !== null) {
          merges.push({
            s: { r: aoa.length, c: 0 },
            e: { r: aoa.length, c: 3 },
          });
          aoa.push(sheetTotal(cols, "sub", lastSub, subAcc));
          rowKinds.push("subtotal");
        }
        if (lastCat !== null) {
          merges.push({
            s: { r: aoa.length, c: 0 },
            e: { r: aoa.length, c: 3 },
          });
          aoa.push(sheetTotal(cols, "cat", lastCat, catAcc));
          rowKinds.push("subtotal");
        }

        lastCat = row.category_name;
        lastSub = null;
        catAcc = zeros(cols);

        var cat = ["รายการ" + lastCat];
        while (cat.length < width) {
          cat.push("");
        }
        merges.push({
          s: { r: aoa.length, c: 0 },
          e: { r: aoa.length, c: width - 1 },
        });
        aoa.push(cat);
        rowKinds.push("category");
      }

      if (row.sub_category_name !== lastSub) {
        if (lastSub !== null) {
          merges.push({
            s: { r: aoa.length, c: 0 },
            e: { r: aoa.length, c: 3 },
          });
          aoa.push(sheetTotal(cols, "sub", lastSub, subAcc));
          rowKinds.push("subtotal");
        }

        lastSub = row.sub_category_name;
        subAcc = zeros(cols);

        var sub2 = ["  " + lastSub];
        while (sub2.length < width) {
          sub2.push("");
        }
        merges.push({
          s: { r: aoa.length, c: 0 },
          e: { r: aoa.length, c: width - 1 },
        });
        aoa.push(sub2);
        rowKinds.push("subcategory");
      }

      seq++;
      var line = [seq, row.product_code, row.product_name, row.unit_name];
      for (i = 0; i < cols.groups.length; i++) {
        var bc = cols.groups[i].cols;
        for (var e = 0; e < bc.length; e++) {
          var val = bc[e].get(row);

          /* ช่องราคาส่งออกเป็นข้อความ เพราะหนึ่งช่องมีได้หลายราคา
                       ("10.00 x100 · 20.00 x100") ยัดเป็นตัวเลขไม่ได้อยู่แล้ว */
          if (bc[e].kind === "text") {
            line.push(val);
            continue;
          }

          var nv = Number(val);
          gsum[i][e] += nv;
          catAcc[i][e] += nv;
          subAcc[i][e] += nv;
          line.push(num0(nv));
        }
      }
      aoa.push(line);
      rowKinds.push("product");

      /* ---------- แถวย่อยรายราคา ----------
               ในไฟล์กางเสมอ ไม่มีปุ่มให้กด และคนที่เปิดไฟล์มักเปิดมาเพื่อ
               ไล่ดูรายละเอียดพอดี ยอดรวมท้ายตารางไม่นับแถวพวกนี้
               เพราะมันถูกนับไปแล้วในแถวหลัก */
      var plots = row.price_rows;
      if (plots && plots.length > 1) {
        for (var pl = 0; pl < plots.length; pl++) {
          var lot = plots[pl];
          var sub = ["", "", "    ราคา " + money(lot.price), ""];

          for (var gi = 0; gi < cols.groups.length; gi++) {
            var lc = cols.groups[gi].cols;
            for (var li = 0; li < lc.length; li++) {
              if (!lc[li].lot) {
                sub.push("");
                continue;
              }
              var lv = lc[li].lot(lot);
              sub.push(lc[li].kind === "text" ? lv : num0(lv));
            }
          }
          /* ระดับ 1 = ลูกของแถวสินค้าข้างบน Excel จะวาดปุ่มยุบให้เอง */
          levels[aoa.length] = 1;
          aoa.push(sub);
          rowKinds.push("lot");
        }
      }
    }

    /* ปิดหมวดสุดท้ายที่ยังค้างอยู่ */
    if (lastSub !== null) {
      merges.push({ s: { r: aoa.length, c: 0 }, e: { r: aoa.length, c: 3 } });
      aoa.push(sheetTotal(cols, "sub", lastSub, subAcc));
      rowKinds.push("subtotal");
    }
    if (lastCat !== null) {
      merges.push({ s: { r: aoa.length, c: 0 }, e: { r: aoa.length, c: 3 } });
      aoa.push(sheetTotal(cols, "cat", lastCat, catAcc));
      rowKinds.push("subtotal");
    }

    /* ---------- แถวรวม ---------- */
    /* ป้าย "รวม" พาดสี่ช่องแรกให้เท่ากับที่ทำบนจอ ถ้าปล่อยให้อยู่ช่องเดียว
           มันจะไปชนกับตัวเลขคอลัมน์แรกจนอ่านไม่ออกว่าอันไหนเป็นป้ายอันไหนเป็นยอด */
    var sum = ["รวมทั้งหมด " + rows.length + " รายการ", "", "", ""];
    merges.push({ s: { r: aoa.length, c: 0 }, e: { r: aoa.length, c: 3 } });
    for (i = 0; i < gsum.length; i++) {
      var sc = cols.groups[i].cols;
      for (var w = 0; w < sc.length; w++) {
        sum.push(sc[w].kind === "text" ? "" : num0(gsum[i][w]));
      }
    }
    aoa.push(sum);
    rowKinds.push("total");

    var ws = XLSX.utils.aoa_to_sheet(aoa);
    ws["!merges"] = merges;
    ws["!rowKinds"] = rowKinds;

    ws["!freeze"] = {
      xSplit: 4,
      ySplit: 3,
      topLeftCell: "E4",
      activePane: "bottomRight",
    };

    var tones = {
      open: "DCEEFF",
      buy: "DDF4E4",
      adj: "FFF0C2",
      dept: "FDE3D7",
      "dept-alt": "F8E8C8",
      out: "F9D8D8",
      close: "D9F0EC",
    };
    var colTone = ["fixed", "fixed", "fixed", "fixed"];
    for (i = 0; i < cols.groups.length; i++) {
      for (var toneCol = 0; toneCol < cols.groups[i].cols.length; toneCol++) {
        colTone.push(cols.groups[i].tone || "fixed");
      }
    }
    for (var sr = 0; sr < aoa.length; sr++) {
      for (var sc = 0; sc < width; sc++) {
        var cell = ws[XLSX.utils.encode_cell({ r: sr, c: sc })];
        if (!cell) {
          continue;
        }
        var fill = tones[colTone[sc]] || "FFFFFF";
        var header = rowKinds[sr] === "header" || rowKinds[sr] === "title";
        cell.s = {
          fill: {
            fgColor: {
              rgb: header && rowKinds[sr] === "title" ? "1F4E78" : fill,
            },
          },
          font: {
            bold:
              header ||
              rowKinds[sr] === "category" ||
              rowKinds[sr] === "subcategory" ||
              rowKinds[sr] === "total",
            color: { rgb: rowKinds[sr] === "title" ? "FFFFFF" : "1F2937" },
          },
          alignment: {
            vertical: "center",
            horizontal: sc < 4 ? "left" : "right",
            wrapText: true,
          },
        };
      }
    }

    /* ---------- กลุ่มยุบได้ใน Excel ----------
     * ตั้ง level ให้แถวย่อยรายราคา แล้ว Excel จะวาดปุ่ม +/- ที่ขอบซ้ายให้เอง
     * คนเปิดไฟล์ยุบดูเฉพาะยอดรวมของสินค้า หรือกางดูรายราคาก็ได้
     * เหมือนที่ทำบนหน้าจอ
     *
     * summaryBelow = 0 บอก Excel ว่าแถวสรุปอยู่ "ข้างบน" กลุ่ม
     * (แถวสินค้าอยู่เหนือแถวย่อยของมัน) ถ้าไม่บอก ปุ่มยุบจะไปโผล่ผิดแถว
     */
    var rowsMeta = [];
    var any = false;
    for (var lv = 0; lv < aoa.length; lv++) {
      if (levels[lv]) {
        rowsMeta[lv] = { level: levels[lv] };
        any = true;
      }
    }
    if (any) {
      ws["!rows"] = rowsMeta;
      ws["!outline"] = { above: true };
    }

    /* ความกว้างคอลัมน์: ชื่อสินค้ากว้างสุด ที่เหลือพอให้ตัวเลขไม่ขึ้น #### */
    var widths = [{ wch: 6 }, { wch: 14 }, { wch: 46 }, { wch: 9 }];
    for (i = 0; i < cols.groups.length; i++) {
      var wc = cols.groups[i].cols;
      for (var q2 = 0; q2 < wc.length; q2++) {
        /* ช่องราคาที่มีหลายล็อตยาวกว่าช่องตัวเลขปกติมาก ต้องกว้างพิเศษ */
        widths.push({
          wch: wc[q2].kind === "text" ? 24 : wc[q2].kind === "money" ? 13 : 10,
        });
      }
    }
    ws["!cols"] = widths;

    return ws;
  }

  function loadExcelJs(done, fail) {
    if (window.ExcelJS) {
      done();
      return;
    }

    var s = document.createElement("script");
    s.src =
      window.APP_LIB && window.APP_LIB.exceljs
        ? window.APP_LIB.exceljs
        : "https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js";
    s.onload = function () {
      window.ExcelJS ? done() : fail();
    };
    s.onerror = fail;
    document.head.appendChild(s);
  }

  function excelJsColor(rgb) {
    return "FF" + String(rgb || "FFFFFF").replace(/^FF/i, "");
  }

  function writeExcelJs(sheet, filename, done) {
    var range = XLSX.utils.decode_range(sheet["!ref"]);
    var wb = new ExcelJS.Workbook();
    var out = wb.addWorksheet("สั่งซื้อ-เบิกใช้", {
      views: [
        {
          state: "frozen",
          xSplit: 4,
          ySplit: 3,
          topLeftCell: "E4",
          activePane: "bottomRight",
        },
      ],
    });
    var r, c, key, source, target;

    for (r = range.s.r; r <= range.e.r; r++) {
      for (c = range.s.c; c <= range.e.c; c++) {
        key = XLSX.utils.encode_cell({ r: r, c: c });
        source = sheet[key];
        if (!source) {
          continue;
        }
        target = out.getCell(r + 1, c + 1);
        target.value = source.v === undefined ? "" : source.v;
        if (source.s) {
          target.font = {
            bold: !!source.s.font.bold,
            color: {
              argb: excelJsColor(
                source.s.font.color && source.s.font.color.rgb,
              ),
            },
          };
          target.fill = {
            type: "pattern",
            pattern: "solid",
            fgColor: {
              argb: excelJsColor(
                source.s.fill &&
                  source.s.fill.fgColor &&
                  source.s.fill.fgColor.rgb,
              ),
            },
          };
          target.alignment = {
            vertical: source.s.alignment.vertical || "middle",
            horizontal:
              r < 3
                ? r === 0
                  ? "left"
                  : "center"
                : source.s.alignment.horizontal || "left",
            wrapText: !!source.s.alignment.wrapText,
          };
        }
      }
    }

    var rowFills = {
      category: "FFD9E2F3",
      subcategory: "FFEEF2F7",
      subtotal: "FFE8EAF0",
      total: "FFB8D8D5",
    };
    var rowKinds = sheet["!rowKinds"] || [];
    for (r = range.s.r; r <= range.e.r; r++) {
      if (!rowFills[rowKinds[r]]) {
        continue;
      }
      for (c = range.s.c; c <= range.e.c; c++) {
        target = out.getCell(r + 1, c + 1);
        target.fill = {
          type: "pattern",
          pattern: "solid",
          fgColor: { argb: rowFills[rowKinds[r]] },
        };
        target.font = { bold: true, color: { argb: "FF1F2937" } };
      }
    }

    out.getRow(1).height = 24;
    out.getRow(2).height = 36;
    out.getRow(3).height = 24;

    var merges = sheet["!merges"] || [];
    for (var m = 0; m < merges.length; m++) {
      var merge = merges[m];
      out.mergeCells(
        merge.s.r + 1,
        merge.s.c + 1,
        merge.e.r + 1,
        merge.e.c + 1,
      );
    }

    var widths = sheet["!cols"] || [];
    for (c = 0; c < widths.length; c++) {
      out.getColumn(c + 1).width = widths[c].wch || 10;
    }

    var rows = sheet["!rows"] || [];
    for (r = 0; r < rows.length; r++) {
      if (rows[r] && rows[r].level) {
        out.getRow(r + 1).outlineLevel = rows[r].level;
      }
    }
    out.properties.outlineProperties = {
      summaryBelow: false,
      summaryRight: true,
    };

    wb.xlsx
      .writeBuffer()
      .then(function (buffer) {
        var url = URL.createObjectURL(
          new Blob([buffer], {
            type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
          }),
        );
        var a = document.createElement("a");
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function () {
          URL.revokeObjectURL(url);
        }, 30000);
        done();
      })
      .catch(function () {
        if (typeof window.appError === "function") {
          window.appError("สร้างไฟล์ Excel ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง");
        }
        busy(false);
      });
  }

  function exportExcel() {
    if (!DATA) {
      return;
    }
    busy(true);

    var go = function () {
      var cols = buildCols(DATA);
      var sheet = buildSheet(cols, visibleRows());
      loadExcelJs(
        function () {
          writeExcelJs(
            sheet,
            "สั่งซื้อ-เบิกใช้_" + DATA.range.month + ".xlsx",
            function () {
              busy(false);
            },
          );
        },
        function () {
          busy(false);
          if (typeof window.appError === "function") {
            window.appError("โหลดตัวช่วยสร้างไฟล์ Excel ไม่สำเร็จ");
          }
        },
      );
    };

    if (window.XLSX) {
      go();
      return;
    }

    var s = document.createElement("script");
    s.src = "assets/js/xlsx.mini.min.js";
    s.onload = go;
    s.onerror = function () {
      busy(false);
      if (typeof window.appError === "function") {
        window.appError("โหลดตัวช่วยสร้างไฟล์ Excel ไม่สำเร็จ");
      }
    };
    document.head.appendChild(s);
  }

  /* ============================================================
   *  ชุดข้อมูลสาธิตสำหรับคู่มือการใช้งาน
   * ============================================================
   *  คู่มือของหน้านี้พูดถึงสามอย่างที่ต้องมี "ของจริงให้ดู" ถึงจะเข้าใจ
   *  คือแถวที่บวกลบกันลงตัว คอลัมน์ที่กางตามหน่วยงาน และแถวคั่นหมวด
   *
   *  แต่คู่มือเปิดเองอัตโนมัติตอนเข้าหน้าครั้งแรก ซึ่งเป็นจังหวะที่ข้อมูล
   *  ยังโหลดไม่เสร็จ ทั้งตารางและยอดสรุปยังถูกซ่อนอยู่ ขั้นที่ชี้ไปตรงนั้น
   *  จะถูกตัดทิ้งทั้งหมด (app-guide.js กรองขั้นที่เป้าหมายมองไม่เห็นออก)
   *  เหลือคู่มือที่อธิบายแต่แถบเครื่องมือ ซึ่งเป็นส่วนที่ไม่ต้องอธิบายเลย
   *
   *  และต่อให้โหลดทัน เดือนที่ยังไม่มีใครเบิกอะไรก็ไม่มีคอลัมน์หน่วยงาน
   *  สักคอลัมน์ ขั้นที่อธิบายว่า "คอลัมน์ตรงกลางคือหน่วยงาน" จึงชี้ไปที่
   *  ตารางที่ไม่มีสิ่งที่กำลังพูดถึงอยู่เลย
   *
   *  ตรงนี้จึงใส่ข้อมูลตัวอย่างให้ระหว่างเปิดคู่มือ แล้วคืนสภาพเดิมตอนปิด
   *  (ตัวเรียกอยู่ที่ assets/js/app-guide.js — ดู window.GuideDemo)
   *
   *  ตัวเลขในชุดสาธิตบวกลบกันลงตัวจริงทุกแถวและทุกคอลัมน์ เพราะคู่มือ
   *  ขั้นแรกบอกว่ามันลงตัว ถ้าใส่เลขมั่ว ๆ คนที่ลองบวกตามจะเลิกเชื่อ
   *  ทั้งคู่มือตั้งแต่ขั้นแรก
   *
   *  ---------------------------------------------------------------
   *  ไม่แตะข้อมูลจริงถ้ามีให้ดูอยู่แล้ว
   *  ---------------------------------------------------------------
   *  เดือนที่โหลดเสร็จแล้วและมีหน่วยงานเบิกจริง จะเดินคู่มือบนข้อมูลจริง
   *  ซึ่งดีกว่าเสมอ ของสมมติมีไว้ใช้เฉพาะตอนที่ไม่มีอะไรให้ชี้เท่านั้น
   * ============================================================ */
  var demoOn = false;
  var demoBackup = null;

  /* วันสุดท้ายของเดือนก่อน — วันที่ 0 ของเดือนนี้คือวันสุดท้ายของเดือนที่แล้ว */
  function prevMonthEnd(ym) {
    var p = String(ym).split("-");
    var d = new Date(Number(p[0]), Number(p[1]) - 1, 0);
    var m = d.getMonth() + 1;
    var day = d.getDate();
    return (
      d.getFullYear() +
      "-" +
      (m < 10 ? "0" + m : m) +
      "-" +
      (day < 10 ? "0" + day : day)
    );
  }

  function demoData() {
    var month = DATA && DATA.range ? DATA.range.month : CFG.month;

    /* ---------- แถวแรกคือตัวชูโรง ----------
           ซื้อมาสองราคาในเดือนเดียว จะได้เห็นทั้งปุ่ม "2 ราคา" และแถวย่อย
           ตัวเลขทุกชั้นบวกกันลงตัวจริง: ล็อตรวมกันได้เท่ายอดซื้อ
           แถวย่อยของแต่ละหน่วยงานรวมกันได้เท่ายอดเบิกของหน่วยงานนั้น
           และ ยกมา + ซื้อ - เบิก = คงเหลือ */
    var rows = [
      {
        product_id: -101,
        product_code: "EX-PLB-001",
        product_name: "เสื้อฟอร์ม ผู้ชาย PRO (ดำ) ไซซ์ M",
        unit_name: "ตัว",
        category_id: -1,
        category_name: "ตัวอย่าง: เสื้อฟอร์มพนักงาน",
        sub_category_name: "ตัวอย่าง: เสื้อฟอร์ม",
        open_qty: 67,
        open_val: 5561,
        buy_qty: 70,
        buy_val: 5810,
        adj_qty: 0,
        adj_val: 0,
        buy_lots: [
          { price: 80, qty: 40, val: 3200 },
          { price: 87, qty: 30, val: 2610 },
        ],
        price_rows: [
          {
            price: 80,
            buy_qty: 40,
            buy_val: 3200,
            out_qty: 30,
            out_val: 2400,
            dept: {
              "d-1": { qty: 10, val: 800 },
              "d-2": { qty: 20, val: 1600 },
            },
          },
          {
            price: 87,
            buy_qty: 30,
            buy_val: 2610,
            out_qty: 58,
            out_val: 5046,
            dept: {
              "d-2": { qty: 28, val: 2436 },
              "d-3": { qty: 30, val: 2610 },
            },
          },
        ],
        dept: {
          "d-1": { qty: 10, val: 800 },
          "d-2": { qty: 48, val: 4036 },
          "d-3": { qty: 30, val: 2610 },
        },
        out_qty: 88,
        out_val: 7446,
        close_qty: 49,
        close_val: 3925,
        moved: true,
      },

      {
        product_id: -102,
        product_code: "EX-PLN-002",
        product_name: "เสื้อฟอร์ม ผู้หญิง PRO (น้ำเงิน) ไซซ์ L",
        unit_name: "ตัว",
        category_id: -1,
        category_name: "ตัวอย่าง: เสื้อฟอร์มพนักงาน",
        sub_category_name: "ตัวอย่าง: เสื้อฟอร์ม",
        open_qty: 60,
        open_val: 330,
        buy_qty: 0,
        buy_val: 0,
        adj_qty: 0,
        adj_val: 0,
        buy_lots: [],
        price_rows: [],
        dept: { "d-1": { qty: 12, val: 66 } },
        out_qty: 12,
        out_val: 66,
        close_qty: 48,
        close_val: 264,
        moved: true,
      },

      /* แถวที่ไม่มีอะไรเกิดขึ้นเลย มีไว้ให้ขั้น "ดูเฉพาะรายการที่ขยับ" มีของหายจริง */
      {
        product_id: -103,
        product_code: "EX-SHO-003",
        product_name: "รองเท้าเซฟตี้ เบอร์ 42",
        unit_name: "คู่",
        category_id: -1,
        category_name: "ตัวอย่าง: อุปกรณ์เซฟตี้",
        sub_category_name: "ตัวอย่าง: รองเท้าเซฟตี้",
        open_qty: 24,
        open_val: 432,
        buy_qty: 0,
        buy_val: 0,
        adj_qty: 0,
        adj_val: 0,
        buy_lots: [],
        price_rows: [],
        dept: {},
        out_qty: 0,
        out_val: 0,
        close_qty: 24,
        close_val: 432,
        moved: false,
      },

      {
        product_id: -104,
        product_code: "EX-SHT-004",
        product_name: "เสื้อช่างเทคนิค ไซซ์ L",
        unit_name: "ตัว",
        category_id: -2,
        category_name: "ตัวอย่าง: ชุดช่างเทคนิค",
        sub_category_name: "ตัวอย่าง: เสื้อช่างเทคนิค",
        open_qty: 4,
        open_val: 1200,
        buy_qty: 10,
        buy_val: 3000,
        adj_qty: 0,
        adj_val: 0,
        buy_lots: [{ price: 300, qty: 10, val: 3000 }],
        price_rows: [],
        dept: { "d-2": { qty: 3, val: 900 } },
        out_qty: 3,
        out_val: 900,
        close_qty: 11,
        close_val: 3300,
        moved: true,
      },

      {
        product_id: -105,
        product_code: "EX-PNT-005",
        product_name: "กางเกงช่างเทคนิค เอว 34",
        unit_name: "ตัว",
        category_id: -2,
        category_name: "ตัวอย่าง: ชุดช่างเทคนิค",
        sub_category_name: "ตัวอย่าง: กางเกงช่างเทคนิค",
        open_qty: 8,
        open_val: 760,
        buy_qty: 12,
        buy_val: 1140,
        adj_qty: 0,
        adj_val: 0,
        buy_lots: [{ price: 95, qty: 12, val: 1140 }],
        price_rows: [],
        dept: { "d-3": { qty: 15, val: 1425 } },
        out_qty: 15,
        out_val: 1425,
        close_qty: 5,
        close_val: 475,
        moved: true,
      },
    ];

    /* ยอดสรุปบวกจากแถวจริง ไม่เขียนตัวเลขไว้เอง
           เคยเขียนมือแล้วต้องมาไล่แก้ทุกครั้งที่ปรับตัวเลขสาธิต
           ซึ่งเป็นจังหวะที่ลืมง่ายที่สุด แล้วคู่มือจะสอนเลขที่ไม่ลงตัว */
    var sum = {
      open_qty: 0,
      open_val: 0,
      buy_qty: 0,
      buy_val: 0,
      adj_qty: 0,
      adj_val: 0,
      out_qty: 0,
      out_val: 0,
      close_qty: 0,
      close_val: 0,
      moved: 0,
    };

    for (var i = 0; i < rows.length; i++) {
      for (var k in sum) {
        if (k !== "moved" && sum.hasOwnProperty(k)) {
          sum[k] += Number(rows[i][k] || 0);
        }
      }
      if (rows[i].moved) {
        sum.moved++;
      }
    }

    return {
      range: {
        month: month,
        from: month + "-01",
        to: "",
        prev_end: prevMonthEnd(month),
      },
      departments: [
        { id: -1, name: "บัญชี", company: "บริษัทตัวอย่าง จำกัด" },
        { id: -2, name: "ทรัพยากรอาคาร", company: "บริษัทตัวอย่าง จำกัด" },
        { id: -3, name: "รักษาความสะอาด", company: "บริษัทตัวอย่าง จำกัด" },
      ],
      hasAdjust: false,
      rows: rows,
      summary: sum,
    };
  }

  function demoSetup() {
    if (demoOn) {
      return false;
    }

    /* มีข้อมูลจริงที่มีหน่วยงานเบิกอยู่แล้ว เดินคู่มือบนของจริงดีกว่า */
    if (DATA && DATA.departments && DATA.departments.length > 0) {
      return false;
    }

    demoBackup = DATA;
    demoOn = true;

    DATA = demoData();

    /* ตัวกรองต้องกลับไปเป็นค่าเริ่มต้นระหว่างสาธิต ไม่งั้นคนที่ติ๊ก
           "เฉพาะรายการที่มีความเคลื่อนไหว" ค้างไว้ จะไม่เห็นแถวที่ขั้นนั้นพูดถึง */
    demoBackup = {
      data: demoBackup,
      moved: el("spMovedOnly").checked,
      search: el("spSearch").value,
    };
    el("spMovedOnly").checked = false;
    el("spSearch").value = "";

    setHidden(el("spLoading"), true);
    setHidden(el("spBody"), false);
    renderHead();
    render();
    demoBanner(true);

    if (window.lucide) {
      window.lucide.createIcons();
    }
    return true;
  }

  function demoTeardown() {
    if (!demoOn) {
      return;
    }

    var b = demoBackup;
    demoOn = false;
    demoBackup = null;

    el("spMovedOnly").checked = b.moved;
    el("spSearch").value = b.search;
    demoBanner(false);

    /* ข้อมูลจริงมาถึงระหว่างสาธิตก็ถูกพักไว้ใน backup (ดู load)
           ตรงนี้จึงเป็นจุดเดียวที่วาดของจริงขึ้นจอ ไม่ว่ามันจะมาถึงตอนไหน */
    DATA = b.data;

    if (DATA) {
      setHidden(el("spLoading"), true);
      setHidden(el("spBody"), false);
      renderHead();
      render();
    } else {
      setHidden(el("spBody"), true);
      setHidden(el("spLoading"), false);
    }
  }

  function demoBanner(on) {
    var old = el("spDemoNote");
    if (old && old.parentNode) {
      old.parentNode.removeChild(old);
    }
    if (!on) {
      return;
    }

    var wrap = el("spWrap");
    if (!wrap || !wrap.parentNode) {
      return;
    }

    var note = document.createElement("p");
    note.id = "spDemoNote";
    note.className = "pd-demo-note no-print";
    note.innerHTML =
      '<i data-lucide="flask-conical" class="h-4 w-4" aria-hidden="true"></i>' +
      "<span><b>ตัวเลขตัวอย่างสำหรับสาธิตเท่านั้น</b> " +
      "ไม่ใช่ข้อมูลจริงของคลัง — ปิดคู่มือแล้วตารางจะกลับเป็นของเดือนที่เลือกไว้</span>";

    wrap.parentNode.insertBefore(note, wrap);
  }

  window.GuideDemo = {
    page: "report_supply",
    setup: demoSetup,
    teardown: demoTeardown,
  };

  /* ============================================================
   *  เริ่มทำงาน
   * ============================================================ */
  function init() {
    el("spMonth").addEventListener("change", function () {
      /* เปลี่ยนเดือนระหว่างเปิดคู่มืออยู่ = ตั้งใจจะดูของจริงแล้ว
               ถ้าไม่ปิดโหมดสาธิตก่อน ข้อมูลที่โหลดมาจะถูกพักไว้เฉย ๆ
               แล้วจอค้างอยู่ที่ตัวเลขสมมติทั้งที่ผู้ใช้สั่งเปลี่ยนเดือนไปแล้ว */
      demoTeardown();
      if (this.value) {
        load(this.value);
      }
    });

    el("spMovedOnly").addEventListener("change", render);

    /* พิมพ์แล้ววาดใหม่ทันทีได้ เพราะกรองในหน้าล้วน ๆ ไม่ได้ยิงเซิร์ฟเวอร์
           แต่ยังหน่วงไว้นิดหนึ่ง สามร้อยกว่าแถวถ้าวาดทุกตัวอักษรจะสะดุด */
    var timer = null;
    el("spSearch").addEventListener("input", function () {
      if (timer) {
        clearTimeout(timer);
      }
      timer = setTimeout(render, 150);
    });

    /* ---------- กดกาง/ยุบรายละเอียดราคา ----------
     * ผูกไว้ที่ตัวตารางจุดเดียว ไม่ผูกทีละปุ่ม เพราะตารางถูกวาดใหม่ทั้งก้อน
     * ทุกครั้งที่เปลี่ยนเดือนหรือพิมพ์ค้นหา ถ้าผูกรายปุ่มจะต้องผูกใหม่ทุกรอบ
     * และ listener เก่าจะค้างอยู่กับ DOM ที่ถูกทิ้งไปแล้ว
     */
    el("spTable").addEventListener("click", function (ev) {
      var btn = ev.target.closest ? ev.target.closest(".sp-lots-btn") : null;
      if (!btn) {
        return;
      }

      var pid = btn.getAttribute("data-pid");
      var open = btn.getAttribute("aria-expanded") === "true";
      var kids = el("spTable").querySelectorAll(
        'tr.sp-lot-row[data-pid="' + pid + '"]',
      );

      for (var i = 0; i < kids.length; i++) {
        if (open) {
          kids[i].setAttribute("hidden", "");
        } else {
          kids[i].removeAttribute("hidden");
        }
      }
      btn.setAttribute("aria-expanded", open ? "false" : "true");
    });

    /* ---------- ตัวเลือกคอลัมน์ ---------- */
    if (el("btnCols")) {
      el("btnCols").addEventListener("click", function () {
        var box = el("spCols");
        setHidden(box, !box.hasAttribute("hidden") ? true : false);
      });
    }

    /* ปิดแผงเมื่อคลิกที่อื่น — แผงบังตารางอยู่ ถ้าไม่ปิดให้จะต้องกดปุ่มซ้ำทุกครั้ง */
    document.addEventListener("click", function (ev) {
      var box = el("spCols");
      if (!box || box.hasAttribute("hidden")) {
        return;
      }
      if (box.contains(ev.target)) {
        return;
      }
      if (el("btnCols") && el("btnCols").contains(ev.target)) {
        return;
      }
      setHidden(box, true);
    });

    if (el("spCols")) {
      el("spCols").addEventListener("change", function (ev) {
        var key = ev.target.getAttribute
          ? ev.target.getAttribute("data-col")
          : null;
        if (!key) {
          return;
        }

        if (ev.target.checked) {
          delete hiddenCols[key];
        } else {
          hiddenCols[key] = true;
        }

        updateColsBadge();
        render();
      });

      el("spCols").addEventListener("click", function (ev) {
        var id = ev.target.id;
        if (id !== "spColsAll" && id !== "spColsDept") {
          return;
        }

        hiddenCols = {};
        if (id === "spColsDept") {
          /* เหลือเฉพาะคอลัมน์หน่วยงาน — ใช้ตอนอยากดูว่าใครเบิกอะไรล้วน ๆ */
          var list = colChoices();
          for (var i = 0; i < list.length; i++) {
            if (!list[i].dept) {
              hiddenCols[list[i].key] = true;
            }
          }
        }
        renderCols();
        updateColsBadge();
        render();
      });
    }

    /* ---------- ลากย่อ/ขยายคอลัมน์ ---------- */
    el("spTable").addEventListener("mousedown", function (ev) {
      var g = ev.target;
      if (!g || !g.className || String(g.className).indexOf("sp-grip") < 0) {
        return;
      }
      startDrag(ev, g.getAttribute("data-wkey"));
    });

    document.addEventListener("mousemove", moveDrag);
    document.addEventListener("mouseup", endDrag);

    /* ดับเบิลคลิกที่ขอบ = คืนความกว้างเดิมของคอลัมน์นั้น */
    el("spTable").addEventListener("dblclick", function (ev) {
      var g = ev.target;
      if (!g || !g.className || String(g.className).indexOf("sp-grip") < 0) {
        return;
      }
      delete colWidth[g.getAttribute("data-wkey")];
      render();
    });

    el("btnPrint").addEventListener("click", function () {
      window.print();
    });
    el("btnExport").addEventListener("click", exportExcel);

    load(CFG.month);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
