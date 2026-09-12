<?php
// Requires: $conn open, session active
//
// This partial lives in studentportal/ — one folder below the project
// root — and is included from the other studentportal/*.php pages (same
// folder). $base is computed first, before anything else, so it can be
// reused by the early auth-redirect below as well as by every
// root-relative asset link further down.
$studentPartialRoot = realpath(dirname(__DIR__));
$studentCallerDir    = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
$base = ($studentCallerDir !== false && $studentCallerDir === $studentPartialRoot) ? '' : '../';

// Auth guard — required in case this partial is ever requested directly.
// Same distinct cookie name as every other student-portal page (see
// student_login.php) — keeps this session independent from admin/staff.
if (session_status() === PHP_SESSION_NONE) {
    session_name('STUDENT_SESSID');
    session_start();
}
if (!isset($_SESSION['user_student_id']) || ($_SESSION['role'] ?? '') !== 'student' || !isset($_SESSION['student_id'])) {
    header("Location: student_login");
    exit();
}
if (!isset($conn)) {
    include_once __DIR__ . '/../config.php';
}

$current = basename($_SERVER['PHP_SELF']);

function studentNavLink($href, $label, $icon, $current, $match) {
    $active = ($current === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="student-nav-link' . $active . '" title="' . htmlspecialchars($label) . '">'
       . $icon . '<span class="student-nav-link-text">' . htmlspecialchars($label) . '</span></a>';
}

$ico_dashboard      = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13h4v7H3zm7-9h4v16h-4zm7 5h4v11h-4z"/></svg>';
$ico_enrollment     = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h4M5 6a2 2 0 012-2h6l4 4v10a2 2 0 01-2 2H7a2 2 0 01-2-2V6z"/></svg>';
$ico_schedule       = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3m8-3v3M4 8h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zm3 8h2m4 0h2m-8 4h2m4 0h2"/></svg>';
$ico_grades         = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg>';
$ico_payment        = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
$ico_accountability = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7-8h.01M5 6a2 2 0 012-2h10a2 2 0 012 2v14l-3-2-3 2-3-2-3 2V6z"/></svg>';
$ico_logout         = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>';

$stu_stmt = mysqli_prepare($conn, "
    SELECT s.family_name, s.given_name, us.photo_path
    FROM students s
    JOIN users_student us ON us.student_id = s.student_id
    WHERE s.student_id = ?
");
mysqli_stmt_bind_param($stu_stmt, "i", $_SESSION['student_id']);
mysqli_stmt_execute($stu_stmt);
mysqli_stmt_bind_result($stu_stmt, $sb_family_name, $sb_given_name, $sb_photo_path);
mysqli_stmt_fetch($stu_stmt);
mysqli_stmt_close($stu_stmt);

// $base was already computed at the top of this file (before the auth
// guard, so it could be reused there too).
?>

<!-- SweetAlert2 -->
<script src="<?= $base ?>js/sweetalert2.all.min.js"></script>
<script src="<?= $base ?>js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-storage-key="sg_tab_token_student" data-logout-url="<?= $base ?>studentportal/student_logout"></script>

<!-- Sidebar overlay -->
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<aside class="student-sidebar" id="studentSidebar">

  <div class="student-sidebar-inner">

    <a class="student-sidebar-brand" href="<?= $base ?>studentportal/student_dashboard" title="Go to Dashboard">
      <span class="student-sidebar-logo-wrap">
        <img class="student-sidebar-logo" src="<?= $base ?>images/logo_sidebar_v3.png" alt="Greenfield Senior High School">
      </span>
    </a>

    <nav class="student-sidebar-nav">
      <span class="student-nav-label">Overview</span>
      <?php studentNavLink($base . 'studentportal/student_dashboard', 'Dashboard', $ico_dashboard, $current, 'student_dashboard.php'); ?>

      <span class="student-nav-label">Academics</span>
      <?php studentNavLink($base . 'studentportal/student_schedule', 'My Schedule', $ico_schedule, $current, 'student_schedule.php'); ?>
      <?php studentNavLink($base . 'studentportal/student_grades', 'My Grades', $ico_grades, $current, 'student_grades.php'); ?>

      <span class="student-nav-label">Finance</span>
      <?php studentNavLink($base . 'studentportal/student_payments', 'My Payments', $ico_payment, $current, 'student_payments.php'); ?>
      <?php studentNavLink($base . 'studentportal/student_pay_online', 'Pay Online', $ico_payment, $current, 'student_pay_online.php'); ?>
      <?php studentNavLink($base . 'studentportal/student_accountabilities', 'Accountabilities', $ico_accountability, $current, 'student_accountabilities.php'); ?>
    </nav>

    <div class="student-sidebar-footer">
      <div class="student-sidebar-user">
        <div class="student-sidebar-avatar">
          <?php if (!empty($sb_photo_path)): ?>
            <img src="<?= $base ?>studentportal/student_photo" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
          <?php else: ?>
            <?= strtoupper(substr($sb_given_name ?? 'S', 0, 1)) ?>
          <?php endif; ?>
        </div>
        <span class="student-sidebar-username"><?= htmlspecialchars(trim(($sb_given_name ?? '') . ' ' . ($sb_family_name ?? '')) ?: ($_SESSION['username'] ?? 'Student')) ?></span>
      </div>
      <a href="<?= $base ?>studentportal/student_logout" class="student-btn-logout" id="studentLogoutBtn" title="Log out">
        <?= $ico_logout ?> <span class="btn-logout-text">Log out</span>
      </a>
    </div>

  </div>

</aside>

<script>
(function () {

  const studentSidebar   = document.getElementById('studentSidebar');
  const studentOverlay   = document.getElementById('studentSidebarOverlay');
  const STUDENT_COLLAPSE_KEY = 'studentSidebarCollapsed';
  const studentMobileQuery = window.matchMedia('(max-width: 900px)');
  let studentToggleBtn = null;

  // ── Desktop: expanded (default) <-> mini floating icon rail ──
  function setStudentSidebarCollapsed(collapsed) {
    studentSidebar.classList.toggle('collapsed', collapsed);
    localStorage.setItem(STUDENT_COLLAPSE_KEY, collapsed ? '1' : '0');
    if (studentToggleBtn && !studentMobileQuery.matches) {
      studentToggleBtn.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      studentToggleBtn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
  }

  if (localStorage.getItem(STUDENT_COLLAPSE_KEY) === '1') { setStudentSidebarCollapsed(true); }

  // ── Mobile: off-canvas open/close (always shows the full, expanded layout) ──
  function openStudentSidebar()  { studentSidebar.classList.add('mobile-open');  studentOverlay.classList.add('open'); }
  function closeStudentSidebar() { studentSidebar.classList.remove('mobile-open'); studentOverlay.classList.remove('open'); }
  function toggleStudentSidebar() { studentSidebar.classList.contains('mobile-open') ? closeStudentSidebar() : openStudentSidebar(); }

  // ── Single topbar hamburger drives both behaviours, picked by viewport ──
  function handleStudentSidebarToggle() {
    if (studentMobileQuery.matches) {
      toggleStudentSidebar();
    } else {
      setStudentSidebarCollapsed(!studentSidebar.classList.contains('collapsed'));
    }
  }

  const studentBrand = studentSidebar.querySelector('.student-sidebar-brand');
  if (studentBrand) {
    studentBrand.style.cursor = 'pointer';
    studentBrand.addEventListener('click', closeStudentSidebar);
  }

  document.addEventListener('DOMContentLoaded', function () {

    var studentTopbar = document.querySelector('.student-topbar');
    if (studentTopbar && !studentTopbar.querySelector('.btn-student-sidebar-toggle')) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn-student-sidebar-toggle';
      btn.setAttribute('aria-label', 'Toggle menu');
      btn.setAttribute('title', 'Toggle menu');
      btn.innerHTML = '<span></span><span></span><span></span>';
      btn.addEventListener('click', handleStudentSidebarToggle);
      studentToggleBtn = btn;

      var left = studentTopbar.querySelector('.student-topbar-left');
      if (left) { left.insertBefore(btn, left.firstChild); }
      else { studentTopbar.prepend(btn); }
    }

    studentOverlay.addEventListener('click', closeStudentSidebar);

    var logoutBtn = document.getElementById('studentLogoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof Swal === 'undefined') {
          if (window.confirm('Log out? You will be returned to the login page.')) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= $base ?>studentportal/student_logout';
          }
          return;
        }
        Swal.fire({
          title: 'Log out?',
          text: 'You will be returned to the login page.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#386641',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, log out',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= $base ?>studentportal/student_logout';
          }
        });
      });
    }

    // Global SweetAlert for data-confirm forms (falls back to native confirm())
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      var lastSubmitter = null;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { lastSubmitter = btn; });
      });

      form.addEventListener('submit', function (e) {
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
          title: 'Confirm',
          text: msg,
          icon: icon,
          showCancelButton: true,
          confirmButtonColor: '#386641',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, proceed',
          cancelButtonText: 'Cancel'
        }).then(function (result) {
          if (result.isConfirmed) doSubmit();
        });
      });
    });
  });
})();
</script>