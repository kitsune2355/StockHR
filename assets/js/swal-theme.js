/* ============================================================
 *  swal-theme.js — กล่องข้อความกลางของระบบ (ครอบ SweetAlert2)
 *
 *  ทุกหน้าเรียกผ่านฟังก์ชันในไฟล์นี้ ไม่เรียก Swal.fire() ตรง ๆ
 *  เหตุผล:
 *    - ข้อความปุ่ม สี ฟอนต์ และท่าทางของกล่อง จะเหมือนกันทั้งระบบ
 *    - อยากเปลี่ยนสไตล์ทีหลัง แก้ที่ไฟล์นี้ไฟล์เดียว
 *    - ถ้าวันหนึ่งเปลี่ยนไปใช้ library อื่น แก้แค่ที่นี่ หน้าอื่นไม่ต้องแตะ
 *
 *  ฟังก์ชันที่ใช้ได้:
 *      appConfirm(opts)          -> Promise<boolean>   กล่องยืนยัน
 *      appConfirmDanger(opts)    -> Promise<boolean>   กล่องยืนยันงานที่ย้อนไม่ได้
 *      appSuccess(text)          -> Promise            แจ้งสำเร็จ (toast มุมขวาบน)
 *      appError(text, title)     -> Promise            แจ้งผิดพลาด (กล่องกลางจอ)
 *      appWarning(text, title)   -> Promise            แจ้งเตือน
 *      appInfo(text, title)      -> Promise            แจ้งข้อมูล
 *      appLoading(text)          -> void               กล่องกำลังทำงาน (ปิดเองไม่ได้)
 *      appClose()                -> void               ปิดกล่องที่เปิดอยู่
 *
 *  หมายเหตุ: กล่องเตือน "ออกจากหน้าทั้งที่ยังไม่บันทึก" ใช้ swal ไม่ได้
 *  เพราะเป็นกล่องของเบราว์เซอร์เอง (beforeunload) ที่หน้าเว็บสั่งหน้าตาไม่ได้
 * ============================================================ */
(function () {
    'use strict';

    if (typeof Swal === 'undefined') { return; }

    /* ---------- ค่าที่ใช้ร่วมทุกกล่อง ---------- */
    var BASE = {
        buttonsStyling: false,          // ใช้ปุ่มสไตล์ของระบบเอง ไม่ใช้สีเริ่มต้นของ library
        reverseButtons: true,           // ปุ่มยืนยันอยู่ขวา ตามที่คนไทยคุ้นบนเว็บทั่วไป
        heightAuto: false,              // กัน library ไปแก้ความสูง body ซึ่งทำ layout เต็มจอเพี้ยน
        customClass: {
            popup:       'app-swal',
            confirmButton: 'app-swal-confirm',
            cancelButton:  'app-swal-cancel',
            denyButton:    'app-swal-deny'
        }
    };

    function merge(target, extra) {
        var out = {};
        var k;
        for (k in target) { if (target.hasOwnProperty(k)) { out[k] = target[k]; } }
        for (k in extra)  { if (extra.hasOwnProperty(k))  { out[k] = extra[k]; } }
        return out;
    }


    /* ============================================================
     *  กล่องยืนยัน
     * ============================================================ */
    function confirmBox(opts, danger) {
        opts = opts || {};

        return Swal.fire(merge(BASE, {
            icon:               opts.icon || (danger ? 'warning' : 'question'),
            title:              opts.title || 'ยืนยันการทำรายการ',
            text:               opts.text || '',
            html:               opts.html || undefined,
            showCancelButton:   true,
            confirmButtonText:  opts.confirmText || (danger ? 'ยืนยันลบ' : 'ยืนยัน'),
            cancelButtonText:   opts.cancelText || 'ยกเลิก',
            focusCancel:        danger,     // งานที่ย้อนไม่ได้ ให้โฟกัสปุ่มยกเลิกไว้ก่อน
            customClass: merge(BASE.customClass, {
                confirmButton: danger ? 'app-swal-danger' : 'app-swal-confirm'
            })
        })).then(function (result) {
            return result.isConfirmed === true;
        });
    }

    window.appConfirm = function (opts) {
        if (typeof opts === 'string') { opts = { text: opts }; }
        return confirmBox(opts, false);
    };

    window.appConfirmDanger = function (opts) {
        if (typeof opts === 'string') { opts = { text: opts }; }
        return confirmBox(opts, true);
    };


    /* ============================================================
     *  แจ้งผล
     *  งานที่สำเร็จใช้ toast มุมขวาบน ไม่ขวางการทำงานต่อ
     *  ส่วนงานที่ผิดพลาดใช้กล่องกลางจอ เพราะต้องให้ผู้ใช้อ่านจริง ๆ
     * ============================================================ */
    window.appSuccess = function (text, title) {
        var opts = merge(BASE, {
            toast:             true,
            position:          'top-end',
            icon:              'success',
            title:             title || text,
            text:              title ? text : undefined,
            showConfirmButton: false,
            timer:             3200,
            timerProgressBar:  true,
            customClass: merge(BASE.customClass, { popup: 'app-swal app-swal-toast' })
        });

        /* toast ไม่ได้บังทั้งจอ จึงไม่มีเรื่องความสูง body ให้ต้องกัน
           ถ้าปล่อยคีย์นี้ติดไปด้วย library จะขึ้นคำเตือนใน console ทุกครั้งที่แจ้งผล */
        delete opts.heightAuto;

        return Swal.fire(opts);
    };

    window.appError = function (text, title) {
        return Swal.fire(merge(BASE, {
            icon:              'error',
            title:             title || 'ทำรายการไม่สำเร็จ',
            text:              text,
            confirmButtonText: 'ตกลง'
        }));
    };

    window.appWarning = function (text, title) {
        return Swal.fire(merge(BASE, {
            icon:              'warning',
            title:             title || 'โปรดตรวจสอบ',
            text:              text,
            confirmButtonText: 'ตกลง'
        }));
    };

    window.appInfo = function (text, title) {
        return Swal.fire(merge(BASE, {
            icon:              'info',
            title:             title || 'แจ้งให้ทราบ',
            text:              text,
            confirmButtonText: 'ตกลง'
        }));
    };


    /* ============================================================
     *  กล่องกำลังทำงาน
     * ============================================================ */
    window.appLoading = function (text) {
        Swal.fire(merge(BASE, {
            title:              text || 'กำลังบันทึก...',
            allowOutsideClick:  false,
            allowEscapeKey:     false,
            showConfirmButton:  false,
            didOpen: function () { Swal.showLoading(); }
        }));
    };

    window.appClose = function () {
        if (Swal.isVisible()) { Swal.close(); }
    };
})();
