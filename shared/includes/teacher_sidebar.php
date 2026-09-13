<?php
// Requires: $conn open, session active
//
// Included from the other roles/teacher/teacher_*.php pages (same
// folder). Every link below uses the absolute APP_URL prefix (defined in
// shared/config/database.php) instead of a depth-counted relative path —
// same reasoning as shared/includes/staff_sidebar.php.

// Auth guard — required in case this partial is ever requested directly.
// Teachers now sign in through their own teacher_login.php, with a
// distinct TEACHER_SESSID cookie — same reasoning as the student portal's
// STUDENT_SESSID — so a teacher login never collides with an admin/staff
// session in the same browser.
if (session_status() === PHP_SESSION_NONE) {
    session_name('TEACHER_SESSID');
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'teacher' || !isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login");
    exit();
}
if (!isset($conn)) {
    require_once __DIR__ . '/../../bootstrap.php';
}

$current = basename($_SERVER['PHP_SELF']);

function teacherNavLink($href, $label, $icon, $current, $match) {
    $active = ($current === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="teacher-nav-link' . $active . '" title="' . htmlspecialchars($label) . '">'
        . $icon . '<span class="teacher-nav-link-text">' . $label . '</span></a>';
}

// ---------------------------------------------------------------
// Icons — shared with teacher_dashboard.php via this include, so they're
// only defined once.
// ---------------------------------------------------------------
$ico_dashboard = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4v7H3zm7-9h4v16h-4zm7 5h4v11h-4z"/></svg>';
$ico_calendar  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
$ico_cap       = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg>';
$ico_book      = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>';
$ico_users     = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-3-3.87M9 20H4v-1a4 4 0 013-3.87m6-5.13a4 4 0 11-8 0 4 4 0 018 0zm6 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
$ico_schedule  = $ico_calendar;
$ico_profile   = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
$ico_class     = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>';
$ico_announce  = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13.5c1.5 0 3-1.5 3-3.5s-1.5-3.5-3-3.5M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>';
$ico_logout    = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>';

$sb_stmt = mysqli_prepare($conn, "
    SELECT t.given_name, t.family_name, u.photo_path
    FROM teachers t
    JOIN users u ON u.user_id = t.user_id
    WHERE t.teacher_id = ?
");
mysqli_stmt_bind_param($sb_stmt, "i", $_SESSION['teacher_id']);
mysqli_stmt_execute($sb_stmt);
mysqli_stmt_bind_result($sb_stmt, $sb_given_name, $sb_family_name, $sb_photo_path);
mysqli_stmt_fetch($sb_stmt);
mysqli_stmt_close($sb_stmt);

$sb_display_name = trim(($sb_given_name ?? '') . ' ' . ($sb_family_name ?? '')) ?: ($_SESSION['username'] ?? 'Teacher');

?>

<!-- SweetAlert2 -->
<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-logout-url="<?= APP_URL ?>/roles/teacher/teacher_logout"></script>

<!-- Sidebar overlay -->
<div class="teacher-sidebar-overlay" id="teacherSidebarOverlay"></div>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<aside class="teacher-sidebar" id="teacherSidebar">

  <div class="teacher-sidebar-brand">
    <div class="sidebar-logo-crop">
      <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Logo">
    </div>
    <div class="teacher-sidebar-brand-text">
      <div class="teacher-sidebar-brand-name">Greenfield Senior High School</div>
      <div class="teacher-sidebar-brand-role">Teacher Portal</div>
    </div>
  </div>

  <nav class="teacher-sidebar-nav">
    <span class="teacher-nav-label">Overview</span>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_dashboard', 'Dashboard', $ico_dashboard, $current, 'teacher_dashboard.php'); ?>

    <span class="teacher-nav-label">Teaching</span>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_classes', 'My Classes', $ico_cap, $current, 'teacher_classes.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_schedule', 'My Schedule', $ico_schedule, $current, 'teacher_schedule.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_gradebook', 'Assessment', $ico_book, $current, 'teacher_gradebook.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_grade_management', 'Grade Management', $ico_dashboard, $current, 'teacher_grade_management.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_attendance', 'Attendance', $ico_calendar, $current, 'teacher_attendance.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_lessons', 'Lessons', $ico_class, $current, 'teacher_lessons.php'); ?>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_discussions', 'Discussions', $ico_users, $current, 'teacher_discussions.php'); ?>

    <span class="teacher-nav-label">Updates</span>
    <?php teacherNavLink(APP_URL . '/roles/teacher/teacher_profile', 'My Profile', $ico_profile, $current, 'teacher_profile.php'); ?>
  </nav>

  <div class="teacher-sidebar-footer">
    <div class="teacher-sidebar-user">
      <div class="teacher-sidebar-avatar">
        <?php if (!empty($sb_photo_path)): ?>
          <img src="<?= APP_URL ?>/roles/teacher/teacher_photo" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <?= strtoupper(substr($sb_given_name ?? $sb_display_name, 0, 1)) ?>
        <?php endif; ?>
      </div>
      <span class="teacher-sidebar-username"><?= htmlspecialchars($sb_display_name) ?></span>
    </div>
    <a href="<?= APP_URL ?>/roles/teacher/teacher_logout" class="teacher-btn-logout" id="teacherLogoutBtn" title="Log out">
      <?= $ico_logout ?> <span class="teacher-btn-logout-text">Log out</span>
    </a>
  </div>

</aside>

<script>
(function () {

  const teacherSidebar  = document.getElementById('teacherSidebar');
  const teacherOverlay  = document.getElementById('teacherSidebarOverlay');
  const TEACHER_SIDEBAR_KEY = 'sidebarOpen';
  const TEACHER_COLLAPSE_KEY = 'teacherSidebarCollapsed';

  function openTeacherSidebar()  { teacherSidebar.classList.add('open');  teacherOverlay.classList.add('open');  localStorage.setItem(TEACHER_SIDEBAR_KEY, '1'); }
  function closeTeacherSidebar() { teacherSidebar.classList.remove('open'); teacherOverlay.classList.remove('open'); localStorage.setItem(TEACHER_SIDEBAR_KEY, '0'); }
  function toggleTeacherSidebar() { teacherSidebar.classList.contains('open') ? closeTeacherSidebar() : openTeacherSidebar(); }

  // Restore open/closed state from the previous page (multi-page app —
  // every navigation is a full reload, so state has to persist here).
  if (localStorage.getItem(TEACHER_SIDEBAR_KEY) === '1') { openTeacherSidebar(); }
  // Clear any collapsed flag left over from when this sidebar had a
  // collapse toggle — that control no longer exists, so a leftover '1'
  // here would strand the sidebar in mini-rail mode with no way back.
  localStorage.removeItem(TEACHER_COLLAPSE_KEY);

  // Logo/brand area closes the sidebar when open.
  const teacherBrand = teacherSidebar.querySelector('.teacher-sidebar-brand');
  if (teacherBrand) {
    teacherBrand.style.cursor = 'pointer';
    teacherBrand.addEventListener('click', closeTeacherSidebar);
  }

  document.addEventListener('DOMContentLoaded', function () {

    // Inject hamburger button into existing .teacher-topbar — but only if
    // the page hasn't already placed its own toggle button.
    var teacherTopbar = document.querySelector('.teacher-topbar');
    if (teacherTopbar && !teacherTopbar.querySelector('.btn-teacher-sidebar-toggle')) {
      var btn = document.createElement('button');
      btn.className = 'btn-teacher-sidebar-toggle';
      btn.setAttribute('aria-label', 'Toggle menu');
      btn.innerHTML = '<span></span><span></span><span></span>';
      btn.addEventListener('click', toggleTeacherSidebar);

      var left = teacherTopbar.querySelector('.teacher-topbar-left');
      if (left) { left.insertBefore(btn, left.firstChild); }
      else { teacherTopbar.prepend(btn); }
    }

    teacherOverlay.addEventListener('click', closeTeacherSidebar);

    // Logout — SweetAlert (falls back to native confirm() if Swal failed to load)
    var logoutBtn = document.getElementById('teacherLogoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof Swal === 'undefined') {
          if (window.confirm('Log out? You will be returned to the login page.')) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= APP_URL ?>/roles/teacher/teacher_logout';
          }
          return;
        }
        Swal.fire({
          title: 'Log out?',
          text: 'You will be returned to the login page.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, log out',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= APP_URL ?>/roles/teacher/teacher_logout';
          }
        });
      });
    }

    // Global SweetAlert for data-confirm forms and links (falls back to native confirm())
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      var lastSubmitter = null;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { lastSubmitter = btn; });
      });

      form.addEventListener('submit', function (e) {
        // Resubmitting via requestSubmit() re-fires this listener — let it through once.
        if (form.dataset.confirmed === '1') {
          form.dataset.confirmed = '';
          return;
        }
        e.preventDefault();
        var msg  = this.dataset.confirm || 'Are you sure?';
        var icon = this.dataset.icon   || 'warning';
        var self = this;

        function doSubmit() {
          self.dataset.confirmed = '1';
          if (lastSubmitter && typeof self.requestSubmit === 'function') {
            self.requestSubmit(lastSubmitter);
          } else {
            HTMLFormElement.prototype.submit.call(self);
          }
        }

        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) doSubmit();
          return;
        }
        Swal.fire({
          title: 'Confirm Action',
          text: msg,
          icon: icon,
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) doSubmit();
        });
      });
    });

    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var href = this.href;
        var msg  = this.dataset.confirm || 'Are you sure?';
        if (typeof Swal === 'undefined') {
          if (window.confirm(msg)) window.location.href = href;
          return;
        }
        Swal.fire({
          title: 'Confirm',
          text: msg,
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#1E4D3B',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) window.location.href = href;
        });
      });
    });
  });
})();
</script>
