<?php
/* ============================================================
 *  login.php — หน้าเข้าสู่ระบบ (presentation layer เท่านั้น)
 *
 *  - ฟอร์ม POST ไปที่ config_ctrl/checkmember.php (ตัวตรวจสอบสิทธิ์เดิมของระบบ)
 *  - ข้อความ error อ่านจาก $_SESSION['login_error'] ที่ checkmember.php ตั้งไว้
 *  - ไฟล์นี้ไม่มี query / business logic ใด ๆ
 *  - UI: Tailwind CSS + ไอคอน Lucide (vendor ไว้ใน assets/js/ ใช้งานออฟไลน์ได้)
 * ============================================================ */

error_reporting(E_ALL ^ E_NOTICE);
@session_name('STOCKHR_SID'); @session_start();

// ล็อกอินอยู่แล้ว ไม่ต้องเข้าหน้านี้ซ้ำ
if (!empty($_SESSION['sess_username']) && !empty($_SESSION['sess_role'])) {
    header('Location: main.php');
    exit();
}

// ข้อความผิดพลาดจาก checkmember.php (อ่านแล้วล้างทิ้ง กัน error ค้างข้ามรอบ)
$login_error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>เข้าสู่ระบบ | ระบบสต็อก Uniform Happyland</title>

<?php include 'includes/head_assets.php'; ?>
</head>

<body class="min-h-screen bg-[#f7f8fc] font-sans text-slate-800 antialiased">

<!-- แถบตกแต่งพื้นหลัง (ลอยช้า ๆ ไม่รบกวนสายตา) -->
<div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
  <div class="animate-float-slow absolute -left-24 -top-24 h-96 w-96 rounded-full bg-blue-200/50 blur-3xl"></div>
  <div class="animate-float absolute -bottom-32 -right-16 h-[28rem] w-[28rem] rounded-full bg-emerald-200/50 blur-3xl"></div>
</div>

<main class="relative flex min-h-screen items-center justify-center p-4 sm:p-6">
  <div class="animate-fade-in grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-card ring-1 ring-brand-100 lg:grid-cols-2">

    <!-- ===== ฝั่งแบรนด์ ===== -->
    <section class="animate-pan-gradient relative flex flex-col overflow-hidden bg-gradient-to-br from-grad-start via-brand-600 to-grad-end bg-[length:200%_200%] p-8 text-white lg:p-12">

      <!-- ลวดลายพื้นหลังจาง ๆ -->
      <div class="pointer-events-none absolute inset-0 opacity-20" aria-hidden="true">
        <div class="animate-float-slow absolute -right-16 -top-16 h-64 w-64 rounded-full border-[3rem] border-white/40"></div>
        <div class="animate-float absolute -bottom-24 -left-10 h-56 w-56 rounded-full border-[2.5rem] border-white/30"></div>
      </div>

      <div class="relative">
        <!-- โลโก้บริษัท -->
        <div class="animate-pop-in relative inline-flex">
          <span class="animate-ring-pulse absolute inset-0 rounded-3xl bg-white/50" aria-hidden="true"></span>
          <div class="relative flex h-24 w-24 items-center justify-center rounded-3xl bg-white p-2.5 shadow-xl shadow-brand-900/25">
            <img src="<?php echo asset_url('assets/img/hl_logo-removebg-preview.png', $ASSET_BASE); ?>" alt="Happyland Group"
                 class="logo-img h-full w-full object-contain" width="96" height="96">
            <!-- ใช้เมื่อยังไม่มีไฟล์โลโก้ หรือโหลดรูปไม่สำเร็จ -->
            <i data-lucide="warehouse" class="logo-fallback absolute h-10 w-10 text-brand-600" hidden aria-hidden="true"></i>
          </div>
        </div>

        <h2 class="animate-fade-up mt-6 text-2xl font-bold leading-relaxed [animation-delay:120ms] lg:text-3xl">
          ระบบสต็อก Uniform
        </h2>
        <p class="animate-fade-up mt-2 max-w-sm text-sm leading-relaxed text-white/85 [animation-delay:200ms]">
          จัดการรับเข้า เบิก–จ่าย และยอดคงเหลือของเสื้อฟอร์มพนักงาน แยกตามแบบและไซซ์ พร้อมรายงานสรุปแบบเรียลไทม์
        </p>
      </div>

      <ul class="relative mt-8 hidden space-y-4 text-sm lg:block">
        <li class="animate-slide-in-left flex items-center gap-3 [animation-delay:300ms]">
          <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-white/20">
            <i data-lucide="package-search" class="h-3.5 w-3.5" stroke-width="2.2" aria-hidden="true"></i>
          </span>
          <span class="text-white/90">ตรวจสอบยอดคงเหลือได้ทันที</span>
        </li>
        <li class="animate-slide-in-left flex items-center gap-3 [animation-delay:380ms]">
          <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-white/20">
            <i data-lucide="arrow-down-up" class="h-3.5 w-3.5" stroke-width="2.2" aria-hidden="true"></i>
          </span>
          <span class="text-white/90">บันทึกรับเข้า–จ่ายออก ตรวจย้อนหลังได้</span>
        </li>
        <li class="animate-slide-in-left flex items-center gap-3 [animation-delay:460ms]">
          <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-white/20">
            <i data-lucide="bell-ring" class="h-3.5 w-3.5" stroke-width="2.2" aria-hidden="true"></i>
          </span>
          <span class="text-white/90">แจ้งเตือนสินค้าต่ำกว่าจุดสั่งซื้อ</span>
        </li>
      </ul>

      <p class="animate-fade-in relative mt-auto hidden pt-10 text-xs tracking-wider text-white/70 [animation-delay:600ms] lg:block">
        HAPPYLAND GROUP &middot; UNIFORM STOCK SYSTEM
      </p>
    </section>

    <!-- ===== ฝั่งฟอร์ม ===== -->
    <section class="flex flex-col p-8 lg:p-12">

      <header class="animate-fade-up mb-6 [animation-delay:150ms]">
        <h1 class="text-2xl font-bold text-slate-900 lg:text-3xl">เข้าสู่ระบบ</h1>
        <p class="mt-1 text-sm text-slate-500">กรอกชื่อผู้ใช้งานและรหัสผ่านของคุณเพื่อเริ่มใช้งาน</p>
      </header>

      <?php if ($login_error !== ''): ?>
      <div class="animate-fade-up mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
           role="alert">
        <i data-lucide="circle-alert" class="mt-0.5 h-5 w-5 flex-none" aria-hidden="true"></i>
        <span><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <?php endif; ?>

      <form id="loginForm" method="post" action="config_ctrl/checkmember.php" novalidate class="space-y-5">

        <!-- ฟิลด์ที่ checkmember.php อ่านเพิ่มเติม (คงไว้ตาม contract เดิม) -->
        <input type="hidden" name="LoginTypeH" value="">
        <input type="hidden" name="server_id" value="">

        <!-- ชื่อผู้ใช้งาน -->
        <div class="animate-fade-up [animation-delay:230ms]" data-field="user">
          <label for="LogInName" class="mb-2 block text-sm font-semibold text-slate-700">ชื่อผู้ใช้งาน</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-slate-400 transition-colors"
                  aria-hidden="true">
              <i data-lucide="user-round" class="h-5 w-5" stroke-width="1.8"></i>
            </span>
            <input type="text" id="LogInName" name="LogInName" required
                   placeholder="กรอกชื่อผู้ใช้งาน" autocomplete="username"
                   autocapitalize="off" spellcheck="false" aria-describedby="err-user"
                   class="input-control h-12 w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-[15px] text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100">
          </div>
          <p id="err-user" role="alert" hidden class="animate-fade-up mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
            <i data-lucide="circle-x" class="h-3.5 w-3.5 flex-none" aria-hidden="true"></i>
            <span class="err-text"></span>
          </p>
        </div>

        <!-- รหัสผ่าน -->
        <div class="animate-fade-up [animation-delay:310ms]" data-field="pass">
          <label for="LogInPassWord" class="mb-2 block text-sm font-semibold text-slate-700">รหัสผ่าน</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-12 items-center justify-center text-slate-400 transition-colors"
                  aria-hidden="true">
              <i data-lucide="lock-keyhole" class="h-5 w-5" stroke-width="1.8"></i>
            </span>
            <input type="password" id="LogInPassWord" name="LogInPassWord" required
                   placeholder="กรอกรหัสผ่าน" autocomplete="current-password"
                   aria-describedby="err-pass caps-hint"
                   class="input-control h-12 w-full rounded-xl border border-slate-200 bg-white pl-12 pr-12 text-[15px] text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100">

            <button type="button" id="togglePassword" aria-controls="LogInPassWord" aria-pressed="false"
                    aria-label="แสดงรหัสผ่าน" title="แสดงรหัสผ่าน"
                    class="absolute inset-y-0 right-1 my-auto flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-100">
              <!-- ซ่อนอยู่ → ตาปิด (กดเพื่อแสดง) -->
              <i data-lucide="eye-closed" class="icon-eye-closed h-5 w-5" aria-hidden="true"></i>
              <!-- กำลังแสดง → ตาเปิด (กดเพื่อซ่อน) -->
              <i data-lucide="eye" class="icon-eye-open h-5 w-5" aria-hidden="true" hidden></i>
            </button>
          </div>

          <p id="caps-hint" hidden class="animate-fade-in mt-1.5 flex items-center gap-1.5 text-xs text-amber-600">
            <i data-lucide="arrow-big-up" class="h-3.5 w-3.5 flex-none" aria-hidden="true"></i>
            เปิด Caps Lock อยู่
          </p>
          <p id="err-pass" role="alert" hidden class="animate-fade-up mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
            <i data-lucide="circle-x" class="h-3.5 w-3.5 flex-none" aria-hidden="true"></i>
            <span class="err-text"></span>
          </p>
        </div>

        <!-- ปุ่มเข้าสู่ระบบ -->
        <button type="submit" id="submitBtn"
                class="animate-fade-up flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 text-[15px] font-semibold text-white shadow-lg shadow-brand-600/25 transition-all duration-200 [animation-delay:390ms] hover:-translate-y-0.5 hover:bg-brand-700 hover:shadow-xl hover:shadow-brand-600/30 focus:outline-none focus:ring-4 focus:ring-brand-200 active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:translate-y-0">
          <i data-lucide="log-in" class="btn-icon h-[18px] w-[18px]" aria-hidden="true"></i>
          <i data-lucide="loader-circle" class="btn-spinner h-[18px] w-[18px] animate-spin" aria-hidden="true" hidden></i>
          <span class="btn-label">เข้าสู่ระบบ</span>
        </button>
      </form>

      <p class="animate-fade-in mt-6 flex items-start gap-2 border-t border-slate-100 pt-5 text-sm text-slate-500 [animation-delay:480ms]">
        <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-none text-slate-400" aria-hidden="true"></i>
        <span>ลืมรหัสผ่าน หรือเข้าใช้งานไม่ได้ กรุณาติดต่อผู้ดูแลระบบ</span>
      </p>

      <p class="animate-fade-in mt-auto pt-6 text-xs text-slate-400 [animation-delay:560ms]">
        &copy; <?php echo thai_date_format(date('Y-m-d'), 'Y'); ?> Happyland Group. สงวนลิขสิทธิ์
      </p>
    </section>

  </div>
</main>

<script src="<?php echo asset_url('assets/js/login.js', $ASSET_BASE); ?>"></script>
</body>
</html>
