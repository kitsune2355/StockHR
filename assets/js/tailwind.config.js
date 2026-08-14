/* ============================================================
 *  tailwind.config.js — design token กลางของระบบ
 *  โหลดต่อจาก assets/js/tailwind.min.js ทุกหน้า
 *  แก้สี / ฟอนต์ / animation ที่นี่ที่เดียว ทุกหน้าเปลี่ยนตาม
 * ============================================================ */
tailwind.config = {

    /* โหมดมืดสั่งด้วย class 'dark' ที่ <html>
       ตั้งค่าตั้งแต่ก่อนวาดหน้าใน includes/head_assets.php เพื่อไม่ให้จอกะพริบ */
    darkMode: 'class',

    theme: {
        extend: {
            colors: {
                /* brand = เขียวโทนเย็น (สีหลักของระบบ) ยึดจาก #029488 เป็นระดับ 500 */
                brand: {
                    50:  '#effbf9',
                    100: '#d5f5f1',
                    200: '#a9ebe4',
                    300: '#71dbd2',
                    400: '#33c1b8',
                    500: '#029488',
                    600: '#02776f',
                    700: '#055f59',
                    800: '#084c48',
                    900: '#0a3f3c'
                }
            },

            fontFamily: {
                /* Prompt = ฟอนต์หลัก (โหลดจาก Google Fonts ใน includes/head_assets.php)
                   ตัวถัด ๆ ไปคือฟอนต์สำรองในเครื่อง กรณีโหลดไม่ได้ */
                sans: ['Prompt', 'Sarabun', 'Noto Sans Thai', 'Leelawadee UI', 'Segoe UI', 'Tahoma', 'sans-serif']
            },

            boxShadow: {
                card: '0 24px 60px -12px rgba(10, 63, 60, .22)'
            },

            /* ---------- animation กลางของระบบ ----------
               กลุ่มแรก = เล่นครั้งเดียวตอนเข้าหน้า (entrance)
               กลุ่มหลัง = เล่นวนเบา ๆ เฉพาะงานตกแต่งพื้นหลัง ห้ามใช้กับข้อมูล
               ทุกตัวถูกปิดอัตโนมัติเมื่อผู้ใช้ตั้งค่า prefers-reduced-motion */
            keyframes: {
                'fade-up': {
                    '0%':   { opacity: '0', transform: 'translateY(14px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' }
                },
                'fade-in': {
                    '0%':   { opacity: '0' },
                    '100%': { opacity: '1' }
                },
                'slide-in-left': {
                    '0%':   { opacity: '0', transform: 'translateX(-18px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' }
                },
                'pop-in': {
                    '0%':   { opacity: '0', transform: 'scale(.86)' },
                    '65%':  { opacity: '1', transform: 'scale(1.03)' },
                    '100%': { opacity: '1', transform: 'scale(1)' }
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%':      { transform: 'translateY(-16px)' }
                },
                'pan-gradient': {
                    '0%, 100%': { backgroundPosition: '0% 50%' },
                    '50%':      { backgroundPosition: '100% 50%' }
                },
                'ring-pulse': {
                    '0%':   { transform: 'scale(1)',    opacity: '.5' },
                    '100%': { transform: 'scale(1.45)', opacity: '0' }
                }
            },

            animation: {
                'fade-up':       'fade-up .55s cubic-bezier(.22,1,.36,1) both',
                'fade-in':       'fade-in .7s ease-out both',
                'slide-in-left': 'slide-in-left .5s cubic-bezier(.22,1,.36,1) both',
                'pop-in':        'pop-in .7s cubic-bezier(.22,1,.36,1) both',
                'float':         'float 7s ease-in-out infinite',
                'float-slow':    'float 11s ease-in-out infinite',
                'pan-gradient':  'pan-gradient 16s ease-in-out infinite',
                'ring-pulse':    'ring-pulse 2.8s ease-out infinite'
            }
        }
    }
};
