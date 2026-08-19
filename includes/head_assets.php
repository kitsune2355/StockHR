<?php
/* ============================================================
 *  includes/head_assets.php
 *  ชุด asset กลางของทุกหน้าในระบบ (font + Tailwind + CSS พื้นฐาน)
 *
 *  วิธีใช้ — วางไว้ภายใน <head> ของทุกหน้า
 *
 *    หน้าที่อยู่ root:
 *        <?php include __DIR__ . '/includes/head_assets.php'; ?>
 *
 *    หน้าที่อยู่โฟลเดอร์ย่อย (ต้องบอกทางกลับไป root ผ่าน $ASSET_BASE):
 *        <?php $ASSET_BASE = '../'; include __DIR__ . '/../includes/head_assets.php'; ?>
 *
 *  เพิ่ม/เปลี่ยน font หรือ library ให้แก้ที่ไฟล์นี้ที่เดียว ทุกหน้าเปลี่ยนตาม
 * ============================================================ */

$ASSET_BASE = isset($ASSET_BASE) ? $ASSET_BASE : '';

/* ---------- ติดเลขเวอร์ชันท้าย URL ของไฟล์ asset ----------
 * ปัญหาที่แก้: Apache ไม่ได้ส่ง Cache-Control มาด้วย เบราว์เซอร์จึงเดาอายุ
 * cache เอง ผลคือแก้ CSS/JS ไปแล้วผู้ใช้ยังได้ไฟล์เก่า ต้องกด Ctrl+F5 เอง
 *
 * วิธีแก้: ต่อ ?v=<เวลาที่ไฟล์ถูกแก้ล่าสุด> ท้าย URL
 * ไฟล์เปลี่ยนเมื่อไหร่ URL เปลี่ยนตาม เบราว์เซอร์จึงถูกบังคับให้โหลดใหม่
 * ส่วนไฟล์ที่ไม่ได้แก้ URL คงเดิม จึงยังใช้ cache ได้เต็มที่
 */
if (!function_exists('asset_url')) {
    function asset_url($path, $base = '')
    {
        $full = dirname(dirname(__FILE__)) . '/' . $path;   // includes/ -> รากโปรเจกต์
        $ver  = @filemtime($full);
        return $base . $path . ($ver ? '?v=' . $ver : '');
    }
}

/* ---------- แปลงค่า PHP เป็นค่า JavaScript ที่ฝังในหน้าได้อย่างปลอดภัย ----------
 * ปัญหาที่แก้: ถ้าข้อมูลมีข้อความว่า </script> อยู่ข้างใน เบราว์เซอร์จะปิดแท็ก
 * ตรงนั้นทันที แล้วเนื้อหาที่เหลือหลุดออกมาเป็น HTML — เป็นช่องโหว่ XSS ตรง ๆ
 *
 * ทางแก้มาตรฐานคือธง JSON_HEX_TAG ซึ่งมีตั้งแต่ PHP 5.3
 * เครื่องนี้เป็น 5.2.6 จึงต้องแทนที่เครื่องหมาย < เอง
 * \u003C เป็นรูปแบบที่ JSON รองรับ ค่าที่ได้ยังเท่าเดิมทุกประการ
 */
if (!function_exists('app_json')) {
    function app_json($value)
    {
        return str_replace('<', '\u003C', json_encode($value));
    }
}

/* รูปแบบวันที่ของทั้งระบบ — ต้อง include ที่นี่ด้วย เพราะหน้าที่ไม่ได้ผ่าน main.php
   (เช่น login.php) ก็ต้องได้ค่าชุดเดียวกันไปให้ฝั่ง JavaScript
   ไฟล์นี้มีแต่ฟังก์ชัน ไม่แตะฐานข้อมูลและไม่พ่นอะไรออกมา จึง include ซ้ำได้ปลอดภัย */
require_once dirname(dirname(__FILE__)) . '/backend/thai_date.php';
?>
<!-- ตั้งค่าโหมดสี + สถานะเมนูย่อ ก่อนวาดหน้า
     ต้องอยู่บนสุดและเป็นสคริปต์แบบ inline เพื่อไม่ให้เห็นจอกะพริบขาวก่อนเปลี่ยนเป็นโหมดมืด
     หรือเห็นเมนูกางเต็มแวบหนึ่งก่อนหดกลับ -->
<script>
(function () {
    var el = document.documentElement;
    try {
        var theme = localStorage.getItem('hl-theme');
        if (theme === 'dark' || (theme === null && window.matchMedia &&
            window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            el.classList.add('dark');
        }
        if (localStorage.getItem('hl-sidebar') === 'collapsed') {
            el.classList.add('sidebar-collapsed');
        }
    } catch (e) { /* โหมดส่วนตัวบางเบราว์เซอร์ห้ามใช้ localStorage — ใช้ค่าเริ่มต้นไป */ }
})();
</script>

<!-- ไอคอนบนแท็บเบราว์เซอร์ -->
<link rel="icon" type="image/png" href="<?php echo asset_url('assets/img/hl_logo-removebg-preview.png', $ASSET_BASE); ?>">

<!-- ฟอนต์หลักของระบบ: Prompt (Google Fonts) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

<!-- Tailwind (vendor ไว้ในเครื่อง ใช้งานออฟไลน์ได้) + design token กลาง -->
<script src="<?php echo asset_url('assets/js/tailwind.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/tailwind.config.js', $ASSET_BASE); ?>"></script>

<!-- ไอคอน Lucide (vendor ไว้ในเครื่อง)
     วิธีใช้ในหน้า: <i data-lucide="ชื่อไอคอน" class="h-5 w-5"></i>
     แล้วเรียก lucide.createIcons() หนึ่งครั้งหลัง DOM พร้อม (assets/js/app-icons.js จัดการให้แล้ว) -->
<script src="<?php echo asset_url('assets/js/lucide.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/app-icons.js', $ASSET_BASE); ?>"></script>

<!-- วันที่แบบไทยสำหรับแสดงผล (ThaiDate.short / .long / .dateTime / .month)
     โหลดทุกหน้าเพราะเล็กมากและมีวันที่ให้แสดงแทบทุกหน้า
     ใช้แสดงผลเท่านั้น ค่าที่ส่งขึ้น API และที่เก็บในฐานข้อมูลยังเป็น YYYY-MM-DD

     รูปแบบวันที่ทั้งหมดมาจาก thai_date_config() ใน backend/thai_date.php ที่เดียว
     พ่นลงหน้าเป็น window.THAI_DATE_CONFIG ให้ thai-date.js อ่านไปใช้
     ฝั่งหน้าจอจึงไม่ได้เก็บชื่อเดือนหรือรูปแบบไว้ซ้ำอีกชุด
     แก้ที่ไฟล์ PHP ที่เดียว ทั้งสองฝั่งเปลี่ยนตามพร้อมกัน -->
<script>
window.THAI_DATE_CONFIG = <?php echo app_json(thai_date_config()); ?>;
</script>
<script src="<?php echo asset_url('assets/js/thai-date.js', $ASSET_BASE); ?>"></script>

<!-- กล่องข้อความกลางของระบบ (SweetAlert2, MIT)
     หน้าอื่นเรียกผ่าน appConfirm / appSuccess / appError ใน swal-theme.js
     ไม่เรียก Swal.fire() ตรง ๆ เพื่อให้หน้าตาและข้อความปุ่มเหมือนกันทั้งระบบ -->
<script src="<?php echo asset_url('assets/js/sweetalert2.all.min.js', $ASSET_BASE); ?>"></script>
<script src="<?php echo asset_url('assets/js/swal-theme.js', $ASSET_BASE); ?>"></script>

<!-- ตารางแบบแก้ในช่องได้ (Tabulator, MIT)
     โหลดเฉพาะ CSS ที่นี่เพราะเล็กและใช้หลายหน้า
     ส่วนไฟล์ JS หนัก 430 KB จึงให้หน้าที่ใช้จริงโหลดเอง -->
<link rel="stylesheet" href="<?php echo asset_url('assets/css/tabulator.min.css', $ASSET_BASE); ?>">

<!-- ที่อยู่ของไลบรารีที่โหลดตอนกดใช้จริงเท่านั้น (lazy)
     ตอนนี้มีตัวเดียวคือ SheetJS (Apache-2.0) ที่ใช้เขียนไฟล์ .xlsx ตอนส่งออกตาราง
     หนัก 270 KB จึงไม่โหลดพร้อมหน้า คนที่แค่เข้ามาดูข้อมูลไม่ต้องรับน้ำหนักส่วนนี้
     ตัวโหลดอยู่ใน assets/js/grid-export.js -->
<script>
window.APP_LIB = {
    xlsx:    '<?php echo asset_url('assets/js/xlsx.mini.min.js', $ASSET_BASE); ?>',
    /* ECharts 5.6.1 (Apache-2.0) — ตัวรุ่น common มีกราฟแท่ง/เส้น/วงกลม
       พร้อมส่วนประกอบที่ใช้จริงครบ หนัก 648 KB จึงโหลดตอนเปิดหน้าที่มีกราฟเท่านั้น
       ไม่ลากมาทุกหน้าเหมือน tabulator/lucide */
    echarts: '<?php echo asset_url('assets/js/echarts.common.min.js', $ASSET_BASE); ?>'
};
</script>

<!-- คู่มือการใช้งานแบบพาชี้ทีละจุด (driver.js, MIT — vendor ไว้ในเครื่อง)
     ตัวไลบรารีเปิดใช้ผ่าน assets/js/app-guide.js เท่านั้น หน้าอื่นไม่เรียกตรง
     เนื้อหาแต่ละขั้นตอนมาจากตาราง tb_guide_step ไม่ได้ฝังอยู่ในโค้ด -->
<link rel="stylesheet" href="<?php echo asset_url('assets/css/driver.min.css', $ASSET_BASE); ?>">
<script src="<?php echo asset_url('assets/js/driver.min.js', $ASSET_BASE); ?>"></script>

<!-- สไตล์พื้นฐานที่ใช้ร่วมกันทุกหน้า — ต้องมาหลัง tabulator เพื่อทับสไตล์เริ่มต้นได้ -->
<link rel="stylesheet" href="<?php echo asset_url('assets/css/app.css', $ASSET_BASE); ?>">
