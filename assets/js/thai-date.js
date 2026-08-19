/* ============================================================
 *  assets/js/thai-date.js — วันที่แบบไทยสำหรับแสดงผลบนหน้าจอ
 *
 *      ThaiDate.short('2026-08-13')             -> 13 ส.ค. 69
 *      ThaiDate.long('2026-08-13')              -> 13 สิงหาคม 2569
 *      ThaiDate.dateTime('2026-08-13 14:32:07') -> 13 ส.ค. 69 14:32
 *      ThaiDate.month('2026-08')                -> สิงหาคม 2569
 *      ThaiDate.dayLabel('2026-08-13')          -> วันนี้ / เมื่อวาน / 13 ส.ค. 2569
 *      ThaiDate.format(v, 'stamp')              -> ใช้รูปแบบอื่นใน config ได้ทุกตัว
 *
 *  ---------- ไฟล์นี้ไม่ได้เก็บรูปแบบวันที่ไว้เอง ----------
 *  ชื่อเดือน ชื่อวัน ปีพุทธ และรูปแบบทั้งหมด มาจาก thai_date_config()
 *  ใน backend/thai_date.php ที่เดียว โดย includes/head_assets.php พ่นลงหน้า
 *  เป็น window.THAI_DATE_CONFIG ก่อนโหลดไฟล์นี้
 *
 *  แก้รูปแบบวันที่ต้องแก้ที่ backend/thai_date.php ที่เดียว ทั้ง PHP และ
 *  JavaScript เปลี่ยนตามพร้อมกัน ไม่มีทางหลุดไปคนละแบบเพราะลืมแก้อีกที่
 *
 *  ---------- ใช้แสดงผลเท่านั้น ----------
 *  ห้ามส่งผลลัพธ์กลับไปบันทึก — ในตาราง Tabulator ต้องใช้ผ่าน formatter เท่านั้น
 *  เพราะการเรียง ตัวกรองช่วงวันที่ และการส่งออก ล้วนอ่านจากค่าดิบ
 *  ถ้าแปลงทิ้ง "13 ส.ค." จะถูกเรียงมาก่อน "2 ก.ย."
 *
 *  ไม่ใช้ new Date(string) เพราะ '2026-08-13' ถูกตีความเป็น UTC ส่วน
 *  '2026-08-13 14:32' เป็นเวลาท้องถิ่น เพี้ยนกันได้หนึ่งวันในเขตเวลาไทย
 *  วันที่เอกสารเป็นวันตามปฏิทิน ไม่ใช่จุดเวลา จึงอ่านตัวเลขจากข้อความตรง ๆ
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    /* ---------- ค่าที่ฝั่งเซิร์ฟเวอร์ส่งมาให้ ----------
     * ถ้าไม่มี แปลว่าหน้านั้นไม่ได้ include head_assets.php
     * เตือนไว้ครั้งเดียวแล้วแสดงค่าดิบไป ดีกว่าหน้าจอขึ้นขีดกลางทั้งหน้า
     * โดยไม่มีอะไรบอกว่าเพราะอะไร
     */
    var CFG = window.THAI_DATE_CONFIG || null;

    if (!CFG) {
        if (window.console && console.warn) {
            console.warn('thai-date.js: ไม่พบ window.THAI_DATE_CONFIG '
                       + '(หน้านี้ต้อง include includes/head_assets.php) — จะแสดงวันที่แบบดิบแทน');
        }
    }


    /* ---------- แยกตัวเลขออกจากข้อความ ----------
     * คืน null ถ้าไม่ใช่วันที่ที่ใช้ได้ ผู้เรียกจะได้เลือกเองว่าจะแสดงอะไรแทน
     *
     * ดัก 0000-00-00 กับ 1000-01-01 เพราะเป็นค่าว่างที่สคีมานี้ใช้จริง
     * ปล่อยผ่านแล้วหน้าจอจะขึ้นวันที่ที่ดูเหมือนของจริงทั้งที่ไม่มีข้อมูล
     */
    function parse(value) {
        if (value === null || value === undefined) { return null; }

        var text = String(value).trim();
        if (text === '') { return null; }

        var m = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!m) { return null; }

        var y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);

        /* ปีต้องอยู่ในช่วงที่เป็นไปได้จริงของระบบคลัง
         *
         * ขอบล่างกันค่าว่างของสคีมา (0000 กับ 1000)
         * ขอบบนกันค่าที่เผลอเก็บเป็น พ.ศ. ลงคอลัมน์ ค.ศ. เช่น '2569-08-13'
         * ซึ่งถ้าปล่อยผ่านจะกลายเป็น "13 ส.ค. 12" (พ.ศ. 3112) — ตัวเลขที่ดูเหมือน
         * ของจริงแต่ผิด ซึ่งอันตรายกว่าการขึ้นขีดกลางให้เห็นว่าข้อมูลมีปัญหา
         *
         * ช่วงนี้ยังทำให้ผลตรงกับฝั่ง PHP ด้วย เพราะ PHP 5.2 บนระบบ 32 บิต
         * แปลงวันที่เกินปี 2038 ไม่ได้อยู่แล้ว
         */
        if (y < 1900 || y > 2200 || mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }

        return {
            year:     y,
            month:    mo,
            day:      d,
            hour:     (m[4] !== undefined) ? Number(m[4]) : 0,
            minute:   (m[5] !== undefined) ? Number(m[5]) : 0,
            second:   (m[6] !== undefined) ? Number(m[6]) : 0,
            hasTime:  (m[4] !== undefined)
        };
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function pad4(n) {
        var s = String(Number(n));
        while (s.length < 4) { s = '0' + s; }
        return s;
    }

    /* วันในสัปดาห์ตามปฏิทินจริง สร้าง Date จากตัวเลขที่แยกไว้แล้ว
       ไม่ใช่จากข้อความ จึงไม่โดนเรื่องเขตเวลาเหมือน new Date(string) */
    function weekday(p) {
        return new Date(p.year, p.month - 1, p.day).getDay();
    }

    function emptyText(empty) {
        if (empty !== undefined)  { return empty; }
        return CFG ? CFG.empty : '';
    }


    /* ---------- ตัวแปลงกลาง: วันที่ + รูปแบบ -> ข้อความ ----------
     * ต้องให้ผลตรงกับ thai_date_format() ฝั่ง PHP ทุกตัวอักษร
     * $format รับได้ทั้งชื่อรูปแบบใน config และตัวรูปแบบเอง
     */
    function format(value, name, empty) {
        var p = parse(value);
        if (!p) { return emptyText(empty); }

        /* ไม่มี config ก็ยังต้องอ่านออก — คืนค่าดิบไปก่อน */
        if (!CFG) { return String(value); }

        var pattern = (CFG.formats && CFG.formats[name] !== undefined)
                    ? CFG.formats[name] : name;

        var year = p.year + Number(CFG.era);
        var out  = '';

        for (var i = 0; i < pattern.length; i++) {
            var c = pattern.charAt(i);

            if (c === '\\') {                                // \ นำหน้า = เอาตัวถัดไปตรงตัว
                i++;
                if (i < pattern.length) { out += pattern.charAt(i); }
                continue;
            }

            switch (c) {
                case 'j': out += p.day;                          break;
                case 'd': out += pad2(p.day);                    break;
                case 'n': out += p.month;                        break;
                case 'm': out += pad2(p.month);                  break;
                case 'M': out += CFG.months_short[p.month];      break;
                case 'F': out += CFG.months_long[p.month];       break;
                case 'Y': out += year;                           break;
                case 'y': out += String(year).slice(-2);         break;
                case 'G': out += p.hour;                         break;
                case 'H': out += pad2(p.hour);                   break;
                case 'i': out += pad2(p.minute);                 break;
                case 's': out += pad2(p.second);                 break;
                case 'D': out += CFG.days_short[weekday(p)];     break;
                default:  out += c;
            }
        }

        return out;
    }


    function shortDate(value, empty) {
        return format(value, 'short', empty);
    }

    /* ค่าว่างเป็นข้อความเปล่าไม่ใช่ขีดกลาง ให้ตรงกับ thai_date_long() ฝั่ง PHP */
    function longDate(value, empty) {
        return format(value, 'long', (empty === undefined) ? '' : empty);
    }

    /* ค่าที่ไม่มีเวลาติดมา (คอลัมน์ DATE) ไม่เติม 00:00 ให้ เพราะเป็นการแต่งข้อมูล
       คนอ่านจะนึกว่าเอกสารถูกบันทึกตอนเที่ยงคืน */
    function dateTime(value, empty) {
        var p = parse(value);
        if (!p) { return emptyText(empty); }

        return format(value, p.hasTime ? 'date_time' : 'short', empty);
    }

    /* รับได้ทั้ง '2026-08' และ '2026-08-13' เพราะหน้ารายงานส่งมาคนละแบบ
       เติมวันที่ 1 และเติมศูนย์หน้าเลขเดือนให้ เพราะบางที่ส่ง '2026-8' มา
       ต้องเติมให้เหมือน thai_month() ฝั่ง PHP ไม่งั้นสองฝั่งรับค่าไม่เท่ากัน */
    function month(value, empty) {
        var parts = String(value === null || value === undefined ? '' : value).trim().split('-');
        var text  = parts.join('-');

        if (parts.length >= 2 && Number(parts[0]) > 0) {
            text = pad4(parts[0]) + '-' + pad2(Number(parts[1]))
                 + '-' + pad2(parts.length > 2 ? Number(parts[2]) : 1);
        }

        return format(text, 'month', empty);
    }


    /* ---------- ป้ายหัวกลุ่มของรายการที่จัดตามวัน ----------
     * 'วันนี้' / 'เมื่อวาน' / '21 ก.ค. 2569'
     * คู่แฝดฝั่งเซิร์ฟเวอร์คือ thai_day_label()
     */
    function dayLabel(value, empty) {
        var p = parse(value);
        if (!p) { return emptyText(empty); }

        /* เทียบเป็น "วันตามปฏิทิน" ไม่ใช่ส่วนต่างของเวลา
           ไม่งั้นตอนตีหนึ่งของวันใหม่ ของเมื่อวานสี่ทุ่มจะยังนับเป็น "วันนี้" */
        var now = new Date();
        var y   = now.getFullYear(), m = now.getMonth() + 1, d = now.getDate();

        if (CFG && p.year === y && p.month === m && p.day === d) { return CFG.today; }

        var yest = new Date(y, m - 1, d - 1);
        if (CFG && p.year === yest.getFullYear() && p.month === yest.getMonth() + 1
            && p.day === yest.getDate()) { return CFG.yesterday; }

        return format(value, 'day_label', empty);
    }

    /* ใช้จับกลุ่ม ไม่ได้เอาไปแสดง จึงต้องเป็นค่าที่ไม่ขึ้นกับว่าตอนนี้วันอะไร
       (ถ้าจับกลุ่มด้วย dayLabel ตรง ๆ พอข้ามเที่ยงคืนคาหน้าจอ กลุ่ม 'วันนี้'
        กับกลุ่มวันที่จริงของวันเดียวกันจะกลายเป็นคนละกลุ่ม) */
    function dayKey(value) {
        var p = parse(value);
        return p ? (p.year + '-' + p.month + '-' + p.day) : '';
    }


    window.ThaiDate = {
        format:   format,
        short:    shortDate,
        long:     longDate,
        dateTime: dateTime,
        month:    month,
        dayLabel: dayLabel,
        dayKey:   dayKey,

        /* ---------- formatter สำเร็จรูปสำหรับ Tabulator ----------
         * ใช้เป็น formatter: ThaiDate.cell ในนิยามคอลัมน์ได้เลย
         * ค่าดิบยังอยู่ในข้อมูล การเรียงและตัวกรองจึงทำงานเหมือนเดิม
         */
        cell: function (cell) {
            return shortDate(cell.getValue());
        },

        cellTime: function (cell) {
            return dateTime(cell.getValue());
        }
    };
}());
