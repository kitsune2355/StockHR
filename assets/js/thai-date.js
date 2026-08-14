/* ============================================================
 *  assets/js/thai-date.js — วันที่แบบไทยสำหรับแสดงผลบนหน้าจอ
 *
 *      ThaiDate.short('2026-08-13')             -> 13 ส.ค. 69
 *      ThaiDate.long('2026-08-13')              -> 13 สิงหาคม 2569
 *      ThaiDate.dateTime('2026-08-13 14:32:07') -> 13 ส.ค. 69 14:32
 *      ThaiDate.month('2026-08')                -> สิงหาคม 2569
 *
 *  แสดงผลอย่างเดียว ห้ามส่งผลลัพธ์กลับไปบันทึก — ในตาราง Tabulator
 *  ต้องใช้ผ่าน formatter เท่านั้น เพราะการเรียง ตัวกรองช่วงวันที่ และการส่งออก
 *  ล้วนอ่านจากค่าดิบ ถ้าแปลงทิ้ง "13 ส.ค." จะถูกเรียงมาก่อน "2 ก.ย."
 *
 *  ไม่ใช้ new Date(string) เพราะ '2026-08-13' ถูกตีความเป็น UTC ส่วน
 *  '2026-08-13 14:32' เป็นเวลาท้องถิ่น เพี้ยนกันได้หนึ่งวันในเขตเวลาไทย
 *  วันที่เอกสารเป็นวันตามปฏิทิน ไม่ใช่จุดเวลา จึงอ่านตัวเลขจากข้อความตรง ๆ
 *
 *  คู่แฝดฝั่งเซิร์ฟเวอร์คือ backend/thai_date.php แก้ที่ไหนต้องแก้อีกที่
 *
 *  เขียนแบบ ES5 ให้ตรงกับไฟล์อื่นในโปรเจกต์
 * ============================================================ */
(function () {
    'use strict';

    var SHORT = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
                     'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    var LONG  = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
                     'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    var EMPTY = '—';


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

        var m = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
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
            year:  y,
            month: mo,
            day:   d,
            time:  (m[4] !== undefined) ? (m[4] + ':' + m[5]) : ''
        };
    }

    function shortDate(value, empty) {
        var p = parse(value);
        if (!p) { return (empty === undefined) ? EMPTY : empty; }

        return p.day + ' ' + SHORT[p.month] + ' ' + String(p.year + 543).slice(-2);
    }

    function longDate(value, empty) {
        var p = parse(value);
        if (!p) { return (empty === undefined) ? '' : empty; }

        return p.day + ' ' + LONG[p.month] + ' ' + (p.year + 543);
    }

    /* วินาทีถูกตัดทิ้งตั้งแต่ตอน parse เพราะไม่มีใครใช้ตัดสินอะไร
       แต่กินที่ในคอลัมน์และดึงสายตาไปจากตัวเลขที่มีความหมาย */
    function dateTime(value, empty) {
        var p = parse(value);
        if (!p) { return (empty === undefined) ? EMPTY : empty; }

        var out = shortDate(value, empty);
        return p.time ? (out + ' ' + p.time) : out;
    }

    /* รับได้ทั้ง '2026-08' และ '2026-08-13' เพราะหน้ารายงานส่งมาคนละแบบ */
    function month(value, empty) {
        var parts = String(value === null || value === undefined ? '' : value).trim().split('-');
        if (parts.length < 2) { return (empty === undefined) ? EMPTY : empty; }

        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10);
        if (!y || !m || m < 1 || m > 12) { return (empty === undefined) ? EMPTY : empty; }

        return LONG[m] + ' ' + (y + 543);
    }


    /* ---------- ป้ายหัวกลุ่มของรายการที่จัดตามวัน ----------
     * 'วันนี้' / 'เมื่อวาน' / '21 ก.ค. 2569'
     *
     * สองวันล่าสุดใช้คำแทนตัวเลข เพราะเป็นช่วงที่คนอ่านต้องรู้ว่า "เพิ่งเกิด"
     * ส่วนที่เก่ากว่านั้นใช้วันที่จริง เพราะ "3 วันที่แล้ว" ต้องนั่งนับย้อนเอง
     *
     * ปีเต็มไม่ใช่สองหลัก ต่างจาก short() เพราะหัวกลุ่มมีอันเดียวคุมหลายรายการ
     * จึงไม่ได้เบียดที่ และเป็นจุดอ้างอิงเดียวที่บอกปีของทั้งกลุ่ม
     */
    function dayLabel(value, empty) {
        var p = parse(value);
        if (!p) { return (empty === undefined) ? EMPTY : empty; }

        /* เทียบเป็น "วันตามปฏิทิน" ไม่ใช่ส่วนต่างของเวลา
           ไม่งั้นตอนตีหนึ่งของวันใหม่ ของเมื่อวานสี่ทุ่มจะยังนับเป็น "วันนี้" */
        var now = new Date();
        var y   = now.getFullYear(), m = now.getMonth() + 1, d = now.getDate();

        if (p.year === y && p.month === m && p.day === d) { return 'วันนี้'; }

        var yest = new Date(y, m - 1, d - 1);
        if (p.year === yest.getFullYear() && p.month === yest.getMonth() + 1
            && p.day === yest.getDate()) { return 'เมื่อวาน'; }

        return p.day + ' ' + SHORT[p.month] + ' ' + (p.year + 543);
    }

    /* ใช้จับกลุ่ม ไม่ได้เอาไปแสดง จึงต้องเป็นค่าที่ไม่ขึ้นกับว่าตอนนี้วันอะไร
       (ถ้าจับกลุ่มด้วย dayLabel ตรง ๆ พอข้ามเที่ยงคืนคาหน้าจอ กลุ่ม 'วันนี้'
        กับกลุ่มวันที่จริงของวันเดียวกันจะกลายเป็นคนละกลุ่ม) */
    function dayKey(value) {
        var p = parse(value);
        return p ? (p.year + '-' + p.month + '-' + p.day) : '';
    }


    window.ThaiDate = {
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
