/* ============================================================
 *  assets/js/doc-attach.js — ไฟล์แนบของเอกสาร (รูป/ไฟล์ใบบิลของใบรับเข้า)
 *
 *  ใช้สองที่:
 *    - หน้าบันทึกรับเข้า (doc-form.js)  เลือกไฟล์ไว้ก่อน แล้วอัปโหลดหลังบันทึกใบสำเร็จ
 *    - กล่องรายละเอียดในประวัติรับเข้า (doc-list.js)  ดู / ดาวน์โหลด / แนบเพิ่ม / ลบ
 *
 *  ตรวจชนิด/ขนาดที่ฝั่งนี้ก่อนเพื่อบอกผู้ใช้ทันที แต่ตัวตัดสินจริงคือเซิร์ฟเวอร์
 *  (backend/doc_attachment.php อ่านหัวไฟล์จริง ไม่เชื่อนามสกุล)
 *  อัปโหลดทีละไฟล์ต่อคำขอ — หลายไฟล์ใหญ่ในคำขอเดียวชน post_max_size ของเซิร์ฟเวอร์
 * ============================================================ */
(function () {
  "use strict";

  var ENDPOINT = "api/attachment.php";
  var MAX_BYTES = 10 * 1024 * 1024;
  var MAX_FILES = 10;
  var OK_TYPES = ["image/jpeg", "image/png", "image/gif", "application/pdf"];
  var OK_EXT = /\.(jpe?g|png|gif|pdf)$/i;

  function esc(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function size(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes >= 1024 * 1024) {
      return (bytes / (1024 * 1024)).toFixed(1) + " MB";
    }
    return Math.max(1, Math.round(bytes / 1024)) + " KB";
  }

  function isPdf(nameOrMime) {
    return /pdf/i.test(String(nameOrMime));
  }

  /* คืนข้อความผิดพลาด หรือ "" ถ้าผ่าน */
  function check(file) {
    var typeOk = OK_TYPES.indexOf(file.type) >= 0 || OK_EXT.test(file.name);
    if (!typeOk) {
      return "“" + file.name + "” ไม่ใช่รูปภาพ (JPG, PNG, GIF) หรือ PDF";
    }
    if (file.size > MAX_BYTES) {
      return "“" + file.name + "” ใหญ่เกิน 10 MB";
    }
    if (file.size <= 0) {
      return "“" + file.name + "” เป็นไฟล์ว่าง";
    }
    return "";
  }

  /* อัปโหลดทีละไฟล์ตามลำดับ
   * onEach(i, ok, message, attachments) ทุกไฟล์ / done(okCount, failMessages, attachments) ตอนจบ */
  function upload(docId, files, csrfToken, onEach, done) {
    var okCount = 0;
    var fails = [];
    var last = null;
    var i = 0;

    function next() {
      if (i >= files.length) {
        done(okCount, fails, last);
        return;
      }
      var file = files[i];
      var fd = new FormData();
      fd.append("action", "upload");
      fd.append("csrf_token", csrfToken);
      fd.append("doc_id", String(docId));
      fd.append("file", file, file.name);

      var xhr = new XMLHttpRequest();
      xhr.open("POST", ENDPOINT, true);
      xhr.onload = function () {
        var res = null;
        try {
          res = JSON.parse(xhr.responseText);
        } catch (e) {
          res = null;
        }
        var ok = !!(res && res.ok);
        var msg = res && res.message ? res.message : "อัปโหลดไม่สำเร็จ";
        if (ok) {
          okCount++;
          last = res.attachments || last;
        } else {
          fails.push("“" + file.name + "”: " + msg);
        }
        if (onEach) {
          onEach(i, ok, msg, last);
        }
        i++;
        next();
      };
      xhr.onerror = function () {
        fails.push("“" + file.name + "”: ติดต่อเซิร์ฟเวอร์ไม่ได้");
        if (onEach) {
          onEach(i, false, "ติดต่อเซิร์ฟเวอร์ไม่ได้", last);
        }
        i++;
        next();
      };
      xhr.send(fd);
    }

    next();
  }

  function remove(attachId, csrfToken, done, fail) {
    var fd = new FormData();
    fd.append("action", "delete");
    fd.append("csrf_token", csrfToken);
    fd.append("attach_id", String(attachId));
    var xhr = new XMLHttpRequest();
    xhr.open("POST", ENDPOINT, true);
    xhr.onload = function () {
      var res = null;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        res = null;
      }
      if (res && res.ok) {
        done(res);
      } else {
        fail(res && res.message ? res.message : "ลบไฟล์ไม่สำเร็จ");
      }
    };
    xhr.onerror = function () {
      fail("ติดต่อเซิร์ฟเวอร์ไม่ได้");
    };
    xhr.send(fd);
  }

  /* ภาพย่อ/ไอคอน ของไฟล์หนึ่งรายการ (src = URL รูป, ไม่ใช่รูป = ไอคอนเอกสาร) */
  function thumb(src, pdf) {
    if (src && !pdf) {
      return '<span class="att-thumb"><img src="' + esc(src) + '" alt="" loading="lazy"></span>';
    }
    return (
      '<span class="att-thumb is-file"><i data-lucide="' +
      (pdf ? "file-text" : "file") +
      '" class="h-5 w-5" aria-hidden="true"></i></span>'
    );
  }

  /* ============================================================
   *  พรีวิวรูป/PDF ในหน้า (ไม่ต้องเปิดแท็บใหม่)
   * ============================================================
   *  preview(items, start)  items = [{ url, name, kind: "image"|"pdf", download? }]
   *  ใบบิลหนึ่งใบอาจมีทั้งรูปถ่ายและ PDF จึงเลื่อนดูต่อกันในกล่องเดียว (← → / Esc ปิด)
   *  ใช้กล่องของตัวเอง ไม่ใช้ ImageView เพราะตัวนั้นรับเฉพาะรูป
   *  PDF ใช้ตัวอ่าน PDF ในเบราว์เซอร์ (iframe) — มือถือบางรุ่นไม่แสดงในหน้า
   *  จึงมีปุ่ม "เปิดในแท็บใหม่" ไว้เสมอเป็นทางสำรอง
   */
  var pv = null;
  var pvItems = [];
  var pvAt = 0;
  var pvLastFocus = null;

  function pvBuild() {
    if (pv) {
      return;
    }
    pv = document.createElement("div");
    pv.className = "apv";
    pv.setAttribute("hidden", "");
    pv.setAttribute("role", "dialog");
    pv.setAttribute("aria-modal", "true");
    pv.setAttribute("aria-label", "พรีวิวไฟล์แนบ");
    pv.innerHTML =
      '<div class="apv-bar">' +
      '<span class="apv-name"></span><span class="apv-count num"></span>' +
      '<a class="apv-btn apv-tab" target="_blank" rel="noopener" title="เปิดในแท็บใหม่" aria-label="เปิดในแท็บใหม่"><i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i></a>' +
      '<a class="apv-btn apv-dl" title="ดาวน์โหลด" aria-label="ดาวน์โหลด"><i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i></a>' +
      '<button type="button" class="apv-btn apv-close" title="ปิด (Esc)" aria-label="ปิด"><i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button>' +
      "</div>" +
      '<div class="apv-stage"></div>' +
      '<button type="button" class="apv-nav is-prev" aria-label="ไฟล์ก่อนหน้า"><i data-lucide="chevron-left" class="h-5 w-5" aria-hidden="true"></i></button>' +
      '<button type="button" class="apv-nav is-next" aria-label="ไฟล์ถัดไป"><i data-lucide="chevron-right" class="h-5 w-5" aria-hidden="true"></i></button>';
    document.body.appendChild(pv);

    pv.addEventListener("click", function (e) {
      var t = e.target;
      if (t === pv || t.classList.contains("apv-stage") || (t.closest && t.closest(".apv-close"))) {
        pvClose();
      } else if (t.closest && t.closest(".apv-nav.is-prev")) {
        pvGo(pvAt - 1);
      } else if (t.closest && t.closest(".apv-nav.is-next")) {
        pvGo(pvAt + 1);
      }
    });
    document.addEventListener("keydown", function (e) {
      if (!pv || pv.hidden) {
        return;
      }
      if (e.key === "Escape") {
        e.stopPropagation(); // ไม่ให้ Esc ไปปิดกล่องรายละเอียดที่อยู่ข้างหลังด้วย
        pvClose();
      } else if (e.key === "ArrowLeft") {
        pvGo(pvAt - 1);
      } else if (e.key === "ArrowRight") {
        pvGo(pvAt + 1);
      }
    }, true);
    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  function pvGo(i) {
    if (!pvItems.length) {
      return;
    }
    pvAt = (i + pvItems.length) % pvItems.length;
    var it = pvItems[pvAt];
    var stage = pv.querySelector(".apv-stage");

    if (it.kind === "image") {
      stage.innerHTML = '<img class="apv-img" alt="">';
      stage.firstChild.src = it.url;
    } else if (it.kind === "pdf") {
      stage.innerHTML = '<iframe class="apv-pdf" title="' + esc(it.name) + '"></iframe>';
      stage.firstChild.src = it.url;
    } else {
      stage.innerHTML = '<p class="apv-none">ไฟล์ชนิดนี้พรีวิวไม่ได้ — กดเปิดในแท็บใหม่หรือดาวน์โหลด</p>';
    }

    pv.querySelector(".apv-name").textContent = it.name;
    pv.querySelector(".apv-count").textContent = pvItems.length > 1 ? pvAt + 1 + " / " + pvItems.length : "";
    pv.querySelector(".apv-tab").href = it.url;
    var dl = pv.querySelector(".apv-dl");
    dl.href = it.download || it.url;
    if (it.download) {
      dl.removeAttribute("download");
    } else {
      dl.setAttribute("download", it.name); // ไฟล์ที่ยังไม่อัปโหลด (blob:) ตั้งชื่อตอนบันทึกลงเครื่อง
    }
    var many = pvItems.length > 1;
    pv.querySelector(".apv-nav.is-prev").hidden = !many;
    pv.querySelector(".apv-nav.is-next").hidden = !many;
  }

  function pvClose() {
    if (!pv || pv.hidden) {
      return;
    }
    pv.setAttribute("hidden", "");
    pv.querySelector(".apv-stage").innerHTML = ""; // หยุดโหลด PDF/รูปที่ค้างอยู่
    document.documentElement.classList.remove("apv-open");
    if (pvLastFocus && pvLastFocus.focus) {
      pvLastFocus.focus();
    }
  }

  function preview(items, start) {
    if (!items || !items.length) {
      return;
    }
    pvBuild();
    pvItems = items;
    pvLastFocus = document.activeElement;
    pv.removeAttribute("hidden");
    document.documentElement.classList.add("apv-open");
    pvGo(start || 0);
    pv.querySelector(".apv-close").focus();
  }

  window.DocAttach = {
    MAX_FILES: MAX_FILES,
    esc: esc,
    size: size,
    isPdf: isPdf,
    check: check,
    upload: upload,
    remove: remove,
    thumb: thumb,
    preview: preview,
  };
})();
