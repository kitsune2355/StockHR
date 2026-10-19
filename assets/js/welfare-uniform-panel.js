/* ============================================================
 *  assets/js/welfare-uniform-panel.js — แผง "ชุดฟอร์มจากเงื่อนไขสวัสดิการ" (อ่านอย่างเดียว)
 *
 *  ชุดฟอร์มรอบเริ่มงานตั้งที่ระบบสวัสดิการ (rules.html แท็บชุดฟอร์ม) แล้วส่งมาที่สต็อก
 *  แผงนี้แค่แสดงให้รู้ว่าแผนกนี้ได้อะไร — แก้ไขที่นี่ไม่ได้ และไม่ได้ใส่ลงใบเบิกให้
 *
 *  ใช้ใน pages/new_staff_kit.php และ pages/new_staff_request.php
 *    WelfareUniformPanel.render(node, data, opts)
 *      data = ช่อง "uniform" จาก api/new_staff.php (ดู welfare_uniform_for_department)
 *      opts.central = true  กำลังดูชุดกลาง (ไม่มีหน่วยงานในระบบสวัสดิการ)
 *      opts.context = "kit" | "request"  ปรับข้อความให้เข้ากับหน้า
 * ============================================================ */
(function () {
  "use strict";

  function esc(text) {
    return String(text === null || text === undefined ? "" : text).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function qty(v) {
    var n = Number(v) || 0;
    return n % 1 === 0 ? String(n) : n.toFixed(2);
  }

  function note(icon, text, tone) {
    var cls =
      tone === "warn"
        ? "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
        : "border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300";
    return (
      '<div class="flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ' + cls + '">' +
      '<i data-lucide="' + icon + '" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>' +
      "<span>" + esc(text) + "</span></div>"
    );
  }

  function itemHtml(it) {
    var names = it.names && it.names.length ? it.names.join(" / ") : "";
    return (
      '<li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-1.5">' +
      '<span class="num w-10 flex-none text-right font-semibold text-slate-800 dark:text-slate-100">×' + qty(it.qty) + "</span>" +
      '<span class="min-w-0 flex-1">' +
      (names
        ? '<span class="text-slate-800 dark:text-slate-100">' + esc(names) + "</span>"
        : '<span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">ยังไม่ได้จับคู่สินค้าในสต็อก</span>') +
      ' <span class="font-mono text-xs text-slate-400">' + esc(it.item_code) + "</span>" +
      "</span></li>"
    );
  }

  function ruleHtml(rule) {
    var items = rule.items || [];
    return (
      '<div class="rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700">' +
      '<div class="text-sm font-medium text-slate-700 dark:text-slate-200">' +
      esc(rule.label || "เงื่อนไข #" + rule.rule_id) +
      "</div>" +
      (items.length
        ? '<ul class="mt-1 divide-y divide-slate-100 text-sm dark:divide-slate-800">' + items.map(itemHtml).join("") + "</ul>"
        : '<div class="mt-1 text-sm text-slate-400">ไม่มีชุดฟอร์มในเงื่อนไขนี้</div>') +
      "</div>"
    );
  }

  function siteHtml(site, many) {
    var head = many
      ? '<div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">หน่วยงาน ' + esc(site.site_name) + "</div>"
      : "";
    var body;
    if (!site.synced) {
      body = note("clock", "ยังไม่ได้รับเงื่อนไขชุดฟอร์มของหน่วยงานนี้จากระบบสวัสดิการ", "warn");
    } else if (!site.rules || site.rules.length === 0) {
      body = note("info", "ระบบสวัสดิการไม่ได้ตั้งชุดฟอร์มรอบเริ่มงานให้หน่วยงานนี้");
    } else {
      body =
        (site.rules.length > 1
          ? '<div class="text-xs text-slate-500 dark:text-slate-400">ได้ตามเงื่อนไขที่ตรงกับพนักงาน (ตำแหน่ง / กลุ่มงาน / ระดับ) — ระบบสวัสดิการเลือกให้ตอนส่งเบิก</div>'
          : "") +
        site.rules.map(ruleHtml).join("");
    }
    var when = site.synced
      ? '<div class="text-xs text-slate-400">รับข้อมูลล่าสุด ' + esc(site.synced_label || site.synced_at) + "</div>"
      : "";
    return '<div class="space-y-2">' + head + body + when + "</div>";
  }

  function bodyHtml(data, opts) {
    if (!data || !data.ready) {
      return note("database", "ฐานข้อมูลยังไม่พร้อมแสดงชุดฟอร์ม — ต้องรัน sql/082_welfare_uniform_start.sql", "warn");
    }
    if (opts.central) {
      return note("info", "ชุดฟอร์มตั้งแยกตามหน่วยงานในระบบสวัสดิการ — เลือกแผนกเพื่อดูชุดฟอร์มของแผนกนั้น");
    }
    if (!data.managed) {
      return note("info", "แผนกนี้ไม่ได้ผูกกับหน่วยงานของระบบสวัสดิการ จึงไม่มีชุดฟอร์มรอบเริ่มงาน");
    }
    var many = data.sites.length > 1;
    return data.sites
      .map(function (s) {
        return siteHtml(s, many);
      })
      .join("");
  }

  function render(node, data, opts) {
    if (!node) {
      return;
    }
    opts = opts || {};
    var sub =
      opts.context === "request"
        ? "พนักงานใหม่ได้ชุดฟอร์มผ่านระบบสวัสดิการ (ใบ NRQ) — ไม่ต้องใส่ในใบนี้"
        : "ตั้งที่ระบบสวัสดิการ หน้าเงื่อนไขสวัสดิการ (rules.html) แท็บชุดฟอร์ม — แก้ที่นี่ไม่ได้";

    node.innerHTML =
      '<div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">' +
      '<span class="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300">' +
      '<i data-lucide="shirt" class="h-[18px] w-[18px]" aria-hidden="true"></i></span>' +
      '<span class="min-w-0 flex-1">' +
      '<span class="block font-semibold text-slate-800 dark:text-slate-100">ชุดฟอร์มจากเงื่อนไขสวัสดิการ ' +
      '<span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">อ่านอย่างเดียว</span></span>' +
      '<span class="block text-sm text-slate-500 dark:text-slate-400">' + esc(sub) + "</span>" +
      "</span></div>" +
      '<div class="space-y-3 p-5">' + bodyHtml(data, opts) + "</div>";

    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  window.WelfareUniformPanel = { render: render };
})();
