/* ============================================================
 *  app-icons.js — ตัวจัดการไอคอน Lucide ของทั้งระบบ
 *
 *  Lucide จะแทนที่ทุก element ที่มี data-lucide="ชื่อไอคอน" ด้วย <svg>
 *  โดยคัดลอก attribute เดิม (class / hidden / stroke-width) ไปให้ svg ด้วย
 *  จึงสั่งขนาดและสีผ่าน utility ของ Tailwind ได้ตามปกติ
 *
 *  วิธีใช้ในหน้า:
 *      <i data-lucide="user-round" class="h-5 w-5 text-slate-400"></i>
 *
 *  ถ้าเพิ่ง render HTML ใหม่ด้วย JS/AJAX ให้เรียกซ้ำเพื่อวาดไอคอนในส่วนนั้น:
 *      renderIcons();              // ทั้งหน้า
 *      renderIcons(elementNode);   // เฉพาะบางส่วน
 *
 *  ไฟล์นี้ถูกโหลดใน includes/head_assets.php ก่อนสคริปต์ของแต่ละหน้าเสมอ
 *  และวาดไอคอนตอน DOMContentLoaded ทำให้สคริปต์ท้ายหน้าที่รอ ready
 *  มองเห็น <svg> ที่แทนที่แล้ว ไม่ใช่ <i> ตัวเดิม
 * ============================================================ */
(function () {
    'use strict';

    function renderIcons(root) {
        if (typeof window.lucide === 'undefined' || typeof lucide.createIcons !== 'function') {
            return;
        }
        lucide.createIcons(root ? { root: root } : {});
    }

    window.renderIcons = renderIcons;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { renderIcons(); });
    } else {
        renderIcons();
    }
})();
