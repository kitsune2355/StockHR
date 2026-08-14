/* ============================================================
 *  dashboard-chart.js — กราฟความเคลื่อนไหวบนหน้าภาพรวมระบบ
 *
 *  วาดด้วย ECharts และเลือกช่วงเวลากับความละเอียดได้เอง
 *
 *  ---------------------------------------------------------------
 *  ทำไมเปลี่ยนจากแท่ง CSS มาเป็น ECharts
 *  ---------------------------------------------------------------
 *  ของเดิม PHP วาดแท่ง 7 วันตายตัวด้วย height เป็นเปอร์เซ็นต์
 *  ซึ่งพอแล้วสำหรับ 7 แท่ง แต่พังทันทีเมื่อต้องรองรับสิ่งที่เพิ่มเข้ามา:
 *  เลือกช่วงเองได้ สลับรายวัน/เดือน/ปี ชี้ค้างเพื่อดูตัวเลข และ 300 แท่ง
 *
 *  ---------------------------------------------------------------
 *  โหลดไลบรารีตอนกดใช้จริง
 *  ---------------------------------------------------------------
 *  ECharts หนัก 648 KB จึงไม่โหลดพร้อมทุกหน้า
 *  ที่อยู่ไฟล์อยู่ใน window.APP_LIB.echarts (ตั้งที่ includes/head_assets.php)
 *  ใช้วิธีเดียวกับ SheetJS ในไฟล์ grid-export.js
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.DASHCHART;
    if (!CFG) { return; }

    var box = document.getElementById('bnChart');
    if (!box) { return; }

    var chart   = null;
    var unit    = 'day';
    var loading = false;

    function el(id) { return document.getElementById(id); }

    function fmt(v) {
        var n = Number(v);
        if (isNaN(n)) { return '0'; }
        if (n === Math.floor(n)) { return n.toLocaleString('en-US'); }
        return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
    }


    /* ---------- โหลด ECharts ตอนใช้จริง ---------- */
    var libState = 'idle';
    var waiting  = [];

    function loadLib(done, fail) {
        if (window.echarts) { libState = 'ready'; done(); return; }

        waiting.push({ done: done, fail: fail });
        if (libState === 'loading') { return; }

        var url = (window.APP_LIB && window.APP_LIB.echarts)
                  ? window.APP_LIB.echarts : 'assets/js/echarts.common.min.js';
        libState = 'loading';

        var tag = document.createElement('script');
        tag.src = url;

        tag.onload = function () {
            libState = window.echarts ? 'ready' : 'idle';
            flush(libState === 'ready');
        };
        tag.onerror = function () {
            libState = 'idle';        // ให้ลองใหม่ได้ เผื่อเน็ตสะดุดชั่วคราว
            flush(false);
        };
        document.head.appendChild(tag);
    }

    function flush(ok) {
        var list = waiting;
        waiting = [];
        for (var i = 0; i < list.length; i++) {
            if (ok) { list[i].done(); } else { list[i].fail(); }
        }
    }


    /* ---------- วันที่ ----------
     * คิดจาก CFG.today ที่ PHP ส่งมา ไม่ใช่นาฬิกาของเครื่องผู้ใช้
     * เครื่องที่ตั้งวันเวลาเพี้ยนจะได้ไม่ขอช่วงที่ไม่ตรงกับข้อมูลจริงในเซิร์ฟเวอร์
     */
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function iso(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function today() {
        var p = String(CFG.today || '').split('-');
        if (p.length === 3) { return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])); }
        return new Date();
    }

    /* ปุ่มลัดตั้งทั้งช่วงและความละเอียดพร้อมกัน
       เพราะ "3 ปี" ที่ดูรายวันคือ 1,095 แท่ง ซึ่งไม่มีใครอ่านออก
       ผู้ใช้ยังกดเปลี่ยนความละเอียดเองทีหลังได้ ถ้าอยากดูละเอียดกว่านั้นจริง ๆ */
    var RANGES = {
        '7d':  { days: 6,   unit: 'day'   },
        '30d': { days: 29,  unit: 'day'   },
        'ytd': { ytd: true, unit: 'month' },
        '3y':  { years: 2,  unit: 'year'  }
    };

    function applyRange(key) {
        var r = RANGES[key];
        if (!r) { return; }

        var to   = today();
        var from = today();

        if (r.days)  { from.setDate(from.getDate() - r.days); }
        if (r.ytd)   { from = new Date(to.getFullYear(), 0, 1); }
        if (r.years) { from = new Date(to.getFullYear() - r.years, 0, 1); }

        el('bnFrom').value = iso(from);
        el('bnTo').value   = iso(to);

        setUnit(r.unit);
    }

    function setUnit(u) {
        unit = u;
        mark('.bn-cf-unit', 'data-unit', u);
    }

    /* ทำเครื่องหมายปุ่มที่เลือกอยู่ในกลุ่มหนึ่ง ๆ */
    function mark(groupSel, attr, value) {
        var group = document.querySelector(groupSel);
        if (!group) { return; }

        var btns = group.querySelectorAll('.bn-cf-btn');
        for (var i = 0; i < btns.length; i++) {
            var on = (btns[i].getAttribute(attr) === value);
            btns[i].classList.toggle('is-on', on);
            btns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }


    /* ---------- ขอข้อมูล ---------- */
    function load() {
        if (loading) { return; }
        loading = true;

        var payload = {
            action:     'series',
            csrf_token: CFG.csrfToken,
            unit:       unit,
            date_from:  el('bnFrom').value,
            date_to:    el('bnTo').value
        };

        fetch(CFG.endpoint, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              loading = false;
              if (!res || !res.ok) { fail(res && res.message ? res.message : 'โหลดข้อมูลกราฟไม่สำเร็จ'); return; }
              draw(res);
          })['catch'](function () {
              loading = false;
              fail('เชื่อมต่อไม่ได้ กรุณาลองใหม่');
          });
    }

    function fail(message) {
        var blank = el('bnChartBlank');
        if (blank) { blank.textContent = message; blank.hidden = false; }
        box.style.display = 'none';
    }


    /* ---------- วาด ---------- */
    function draw(res) {
        var pts = res.points || [];

        var hasData = false;
        for (var i = 0; i < pts.length; i++) {
            if (pts[i]['in'] > 0 || pts[i].out > 0) { hasData = true; break; }
        }

        var blank = el('bnChartBlank');

        /* ไม่มีความเคลื่อนไหวเลย = บอกไปตรง ๆ
           กราฟที่ทุกแท่งสูงศูนย์ดูเหมือนกราฟเสีย มากกว่าดูเหมือน "ไม่มีข้อมูล" */
        if (!hasData) {
            if (blank) {
                blank.textContent = 'ยังไม่มีการรับเข้าหรือจ่ายออกในช่วงที่เลือก';
                blank.hidden = false;
            }
            box.style.display = 'none';
            sumText(0, 0, res);
            return;
        }

        if (blank) { blank.hidden = true; }
        box.style.display = '';

        loadLib(function () {
            if (!chart) { chart = window.echarts.init(box, null, { renderer: 'canvas' }); }
            chart.setOption(option(pts, res), true);
            chart.resize();
            sumText(res.total_in, res.total_out, res);
        }, function () {
            fail('โหลดตัวสร้างกราฟไม่สำเร็จ กรุณาโหลดหน้านี้ใหม่');
        });
    }

    function sumText(tin, tout, res) {
        var e = el('bnChartSum');
        if (!e) { return; }

        var word = { day: 'วัน', month: 'เดือน', year: 'ปี' }[res.unit] || 'ช่วง';
        var n    = (res.points || []).length;

        e.innerHTML = 'รวม ' + n + ' ' + word
                    + ' <b class="doc-in">+' + fmt(tin) + '</b>'
                    + ' <span class="bn-dot">·</span>'
                    + ' <b class="doc-out">−' + fmt(tout) + '</b>'
                    + (res.truncated
                       ? ' <span class="bn-cf-warn">(ช่วงยาวเกิน แสดงได้ '
                         + n + ' ' + word + 'แรก — ลองเปลี่ยนเป็นรายเดือนหรือรายปี)</span>'
                       : '');
    }


    /* ---------- ตัวเลือกของ ECharts ----------
     * ใช้กราฟแท่งเมื่อจุดไม่เยอะ และเปลี่ยนเป็นเส้นเมื่อจุดเกิน 60
     * แท่งสามร้อยแท่งจะบางกว่าหนึ่งพิกเซลจนกลายเป็นแถบทึบอ่านอะไรไม่ได้
     * ส่วนเส้นยังบอกแนวโน้มได้แม้จุดเยอะ
     */
    function option(pts, res) {
        var dark  = document.documentElement.classList.contains('dark');
        var line  = pts.length > 60;

        var axis  = dark ? '#475569' : '#cbd5e1';
        var text  = dark ? '#94a3b8' : '#64748b';
        var split = dark ? 'rgba(148,163,184,.15)' : 'rgba(148,163,184,.22)';

        var labels = [];
        var inArr  = [];
        var outArr = [];
        for (var i = 0; i < pts.length; i++) {
            /* แกนนอนใช้ป้ายสั้น (จ/อ/พ หรือ ม.ค.) ส่วนป้ายเต็มไปโผล่ตอนชี้ค้าง
               ถ้าใช้ป้ายเต็มบนแกน จะซ้อนกันจนต้องเอียง 45 องศาแล้วอ่านยากกว่าเดิม */
            labels.push(res.unit === 'day' && pts.length <= 14 ? pts[i].short : pts[i].label);
            inArr.push(pts[i]['in']);
            outArr.push(pts[i].out);
        }

        return {
            /* ปิด animation ตอนจุดเยอะ เพราะการไล่วาดสามร้อยจุดทำให้รู้สึกหน่วง */
            animation: pts.length <= 60,

            grid: { left: 8, right: 12, top: 16, bottom: 4, containLabel: true },

            tooltip: {
                trigger: 'axis',
                axisPointer: { type: line ? 'line' : 'shadow' },
                backgroundColor: dark ? '#1e293b' : '#ffffff',
                borderColor: dark ? '#334155' : '#e2e8f0',
                borderWidth: 1,
                textStyle: { color: dark ? '#e2e8f0' : '#0f172a', fontSize: 12 },
                /* จัดข้อความเอง เพื่อให้ชื่อช่วงเป็นป้ายเต็มพร้อมปี พ.ศ.
                   ไม่ใช่ป้ายสั้นบนแกนที่บอกไม่ได้ว่าเป็นวันไหนของเดือนไหน */
                formatter: function (params) {
                    if (!params || !params.length) { return ''; }
                    var idx = params[0].dataIndex;
                    var p   = pts[idx];
                    return '<b>' + p.label + '</b><br>'
                         + 'เข้า ' + fmt(p['in']) + '<br>'
                         + 'ออก ' + fmt(p.out);
                }
            },

            xAxis: {
                type: 'category',
                data: labels,
                axisLine:  { lineStyle: { color: axis } },
                axisTick:  { show: false },
                axisLabel: {
                    color: text,
                    fontSize: 11,
                    /* ให้ ECharts ตัดสินเองว่าจะเว้นป้ายไหน เมื่อป้ายเยอะจนชนกัน */
                    interval: 'auto',
                    hideOverlap: true
                }
            },

            yAxis: {
                type: 'value',
                axisLine:  { show: false },
                axisTick:  { show: false },
                axisLabel: { color: text, fontSize: 11 },
                splitLine: { lineStyle: { color: split } }
            },

            series: [
                {
                    name: 'เข้า',
                    type: line ? 'line' : 'bar',
                    data: inArr,
                    smooth: line,
                    symbol: 'none',
                    barMaxWidth: 18,
                    itemStyle: { color: '#029488', borderRadius: line ? 0 : [3, 3, 0, 0] },
                    lineStyle:  { width: 2 },
                    areaStyle:  line ? { opacity: 0.12 } : null
                },
                {
                    name: 'ออก',
                    type: line ? 'line' : 'bar',
                    data: outArr,
                    smooth: line,
                    symbol: 'none',
                    barMaxWidth: 18,
                    itemStyle: { color: '#f59e0b', borderRadius: line ? 0 : [3, 3, 0, 0] },
                    lineStyle:  { width: 2 },
                    areaStyle:  line ? { opacity: 0.12 } : null
                }
            ]
        };
    }


    /* ---------- เริ่มทำงาน ---------- */
    function init() {
        applyRange('7d');
        mark('.bn-cf-quick', 'data-range', '7d');

        var quick = document.querySelector('.bn-cf-quick');
        if (quick) {
            quick.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.bn-cf-btn') : null;
                if (!b) { return; }
                applyRange(b.getAttribute('data-range'));
                mark('.bn-cf-quick', 'data-range', b.getAttribute('data-range'));
                load();
            });
        }

        var units = document.querySelector('.bn-cf-unit');
        if (units) {
            units.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.bn-cf-btn') : null;
                if (!b) { return; }
                setUnit(b.getAttribute('data-unit'));
                load();
            });
        }

        /* แก้วันที่เองแล้วถือว่าไม่ตรงกับปุ่มลัดอันไหนอีก จึงล้างไฮไลต์ทิ้ง
           ไม่งั้นจะเห็นปุ่ม "7 วัน" ติดอยู่ทั้งที่กราฟเป็นช่วงอื่นไปแล้ว */
        var onDate = function () {
            mark('.bn-cf-quick', 'data-range', '');
            load();
        };
        if (el('bnFrom')) { el('bnFrom').addEventListener('change', onDate); }
        if (el('bnTo'))   { el('bnTo').addEventListener('change', onDate); }

        /* ---------- ปรับขนาดกราฟตามกรอบ ----------
         * ECharts จำขนาดตอนสร้างไว้ ไม่รู้เองว่ากล่องเปลี่ยนขนาดแล้ว
         *
         * ใช้ ResizeObserver เป็นหลัก เพราะกล่องเปลี่ยนขนาดได้โดยที่หน้าต่างไม่ขยับ
         * เช่นตอนพับเมนูข้าง ซึ่ง window.resize จับไม่ได้เลย
         * (วัดได้จริงตอนทดสอบ: กรอบกว้าง 316px แต่ canvas ค้างอยู่ที่ 50px)
         *
         * หน่วงหนึ่งเฟรมก่อนสั่ง เพราะตอนเหตุการณ์ยิง เบราว์เซอร์ยังจัดหน้าไม่เสร็จ
         * วัดขนาดตอนนั้นจะได้ค่ากลางทาง
         */
        var resize = function () {
            if (!chart) { return; }
            window.setTimeout(function () { chart.resize(); }, 0);
        };

        if (window.ResizeObserver) {
            new window.ResizeObserver(resize).observe(box);
        }
        window.addEventListener('resize', resize);

        /* สลับโหมดสว่าง/มืดแล้วต้องวาดใหม่ เพราะสีเส้นกริดกับตัวอักษรฝังอยู่ในตัวเลือก */
        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (chart) { load(); }
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        }

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
