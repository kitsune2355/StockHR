<?php
/* ============================================================
 *  pages/change_password.php — เปลี่ยนรหัสผ่านของบัญชีตัวเอง
 *
 *  ทุกสิทธิ์ที่ล็อกอินอยู่เข้าหน้านี้ได้ แต่เปลี่ยนได้เฉพาะรหัสผ่านของตัวเอง
 *  ชื่อผู้ใช้ไม่ได้ส่งมาจากฟอร์ม แต่ api/account.php อ่านจาก session เอง
 *  ต่อให้มีคนยิงชื่อคนอื่นมาก็ไม่ถูกใช้
 *
 *  ต้องกรอกรหัสผ่านปัจจุบันก่อนเสมอ กันคนอื่นมาเปลี่ยนตอนที่ลุกจากเครื่องไป
 *
 *  ไฟล์นี้เป็นแค่โครงหน้า — ไม่มี SQL
 *  เขียนให้รองรับ PHP 5.2.6
 * ============================================================ */

if (!isset($sess_username)) { exit('ไม่มีสิทธิ์เข้าถึง'); }

require_once dirname(dirname(__FILE__)) . '/backend/user.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
}
$csrf_token = $_SESSION['csrf_token'];

$display_name = ($sess_full_name !== '') ? $sess_full_name : $sess_username;

$panel = 'rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900';
$label = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300';
$input = 'input-control h-11 w-full rounded-xl border border-slate-200 bg-white px-4 pr-11 text-sm text-slate-800 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:ring-brand-900';
$btn   = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-4 focus:ring-brand-200 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-brand-900';
?>

<div class="mx-auto w-full max-w-lg">

  <!-- ===== บัญชีที่กำลังใช้งาน ===== -->
  <div class="mb-4 flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-brand-600 text-base font-semibold text-white">
      <?php echo htmlspecialchars(app_initials($display_name), ENT_QUOTES, 'UTF-8'); ?>
    </span>
    <span class="min-w-0">
      <span class="block truncate font-semibold text-slate-800 dark:text-slate-100">
        <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
        <?php echo htmlspecialchars($sess_username, ENT_QUOTES, 'UTF-8'); ?>
        · <?php echo htmlspecialchars(app_role_label($sess_role), ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </span>
  </div>

  <form id="pwForm" class="<?php echo $panel; ?>" autocomplete="off" novalidate>

    <h2 class="mb-1 text-lg font-semibold text-slate-800 dark:text-slate-100">เปลี่ยนรหัสผ่าน</h2>
    <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
      เปลี่ยนได้เฉพาะรหัสผ่านของบัญชีนี้เท่านั้น
      ถ้าเพิ่งได้บัญชีใหม่ รหัสผ่านตั้งต้นคือ
      <b class="num text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars(USER_DEFAULT_PASSWORD, ENT_QUOTES, 'UTF-8'); ?></b>
    </p>

    <div class="space-y-4">

      <div>
        <label for="currentPassword" class="<?php echo $label; ?>">รหัสผ่านปัจจุบัน</label>
        <div class="relative">
          <input type="password" id="currentPassword" name="currentPassword" autocomplete="current-password"
                 placeholder="รหัสผ่านที่ใช้อยู่ตอนนี้" class="<?php echo $input; ?>">
          <button type="button" class="pw-toggle" data-target="currentPassword" aria-label="แสดงรหัสผ่าน">
            <i data-lucide="eye" class="pw-show h-4 w-4" aria-hidden="true"></i>
            <i data-lucide="eye-off" class="pw-hide h-4 w-4" aria-hidden="true" hidden></i>
          </button>
        </div>
      </div>

      <div>
        <label for="newPassword" class="<?php echo $label; ?>">รหัสผ่านใหม่</label>
        <div class="relative">
          <input type="password" id="newPassword" name="newPassword" autocomplete="new-password"
                 placeholder="รหัสผ่านที่ต้องการใช้ต่อจากนี้" class="<?php echo $input; ?>">
          <button type="button" class="pw-toggle" data-target="newPassword" aria-label="แสดงรหัสผ่าน">
            <i data-lucide="eye" class="pw-show h-4 w-4" aria-hidden="true"></i>
            <i data-lucide="eye-off" class="pw-hide h-4 w-4" aria-hidden="true" hidden></i>
          </button>
        </div>
      </div>

      <div>
        <label for="confirmPassword" class="<?php echo $label; ?>">ยืนยันรหัสผ่านใหม่</label>
        <div class="relative">
          <input type="password" id="confirmPassword" name="confirmPassword" autocomplete="new-password"
                 placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง" class="<?php echo $input; ?>">
          <button type="button" class="pw-toggle" data-target="confirmPassword" aria-label="แสดงรหัสผ่าน">
            <i data-lucide="eye" class="pw-show h-4 w-4" aria-hidden="true"></i>
            <i data-lucide="eye-off" class="pw-hide h-4 w-4" aria-hidden="true" hidden></i>
          </button>
        </div>
      </div>
    </div>

    <!-- ข้อความผิดพลาดค้างไว้ในหน้า เพราะผู้ใช้ต้องอ่านแล้วแก้ในฟอร์มต่อทันที
         ถ้าเป็นข้อผิดพลาดที่เซิร์ฟเวอร์ตอบกลับมา จะมีกล่องเด้งกลางจอซ้ำอีกชั้น (ดู change-password.js) -->
    <div id="pwError" hidden
         class="mt-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
      <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true"></i>
      <span id="pwErrorText"></span>
    </div>

    <div class="mt-6 flex items-center justify-end gap-2">
      <button type="submit" id="btnChange" class="<?php echo $btn; ?>">
        <i data-lucide="key-round" class="btn-icon h-4 w-4" aria-hidden="true"></i>
        <i data-lucide="loader-circle" class="btn-spinner h-4 w-4 animate-spin" aria-hidden="true" hidden></i>
        เปลี่ยนรหัสผ่าน
      </button>
    </div>
  </form>
</div>

<script>
window.ACCOUNT = {
    csrfToken: <?php echo str_replace('<', '\u003C', json_encode($csrf_token)); ?>,
    endpoint:  'api/account.php'
};
</script>
<script src="<?php echo asset_url('assets/js/change-password.js', $ASSET_BASE); ?>"></script>
