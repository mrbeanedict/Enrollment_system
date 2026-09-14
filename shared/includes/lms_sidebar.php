<?php
// Requires: $conn open, session active (STUDENT_LMS_SESSID)
//
// Top navbar for the LMS-only portal (the regular Student Portal keeps
// its own sidebar, see student_sidebar.php — this file is unrelated to
// that one). See lms_login.php for how this session differs from the
// full Student Portal's STUDENT_SESSID. Every link below uses the
// absolute APP_URL prefix instead of a depth-counted relative path.

// Auth guard — required in case this partial is ever requested directly.
if (session_status() === PHP_SESSION_NONE) {
    session_name('STUDENT_LMS_SESSID');
    session_start();
}
if (!require_login('student', null, 'ignore', 'bool') || !isset($_SESSION['student_id'])) {
    header("Location: lms_login");
    exit();
}
if (!isset($conn)) {
    require_once __DIR__ . '/../../bootstrap.php';
}

$current = basename($_SERVER['PHP_SELF']);
$academicsPages = ['student_lessons.php', 'student_discussions.php', 'student_assignments.php', 'student_quizzes.php', 'take_quiz.php'];
$academicsActive = in_array($current, $academicsPages, true);

function lmsNavLink($href, $label, $current, $match) {
    $active = ($current === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="lms-nav-link' . $active . '">' . htmlspecialchars($label) . '</a>';
}

$ico_chevron = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>';

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

// Same enrollment -> section lookup student_dashboard.php uses for its
// profile chip's role line.
$sb_section_name = null;
$sec_stmt = mysqli_prepare($conn, "
    SELECT sec.section_name
    FROM enrollments e JOIN sections sec ON sec.section_id = e.section_id
    WHERE e.student_id = ?
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 1
");
mysqli_stmt_bind_param($sec_stmt, "i", $_SESSION['student_id']);
mysqli_stmt_execute($sec_stmt);
mysqli_stmt_bind_result($sec_stmt, $sb_section_name);
mysqli_stmt_fetch($sec_stmt);
mysqli_stmt_close($sec_stmt);

$profile_name = trim(($sb_given_name ?? '') . ' ' . ($sb_family_name ?? '')) ?: ($_SESSION['username'] ?? 'Student');
$profile_role = $sb_section_name ?: 'Student';
?>

<!-- SweetAlert2 -->
<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-storage-key="sg_tab_token_lms" data-logout-url="<?= APP_URL ?>/roles/lms/lms_logout"></script>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<nav class="lms-navbar" id="lmsNavbar">
  <div class="lms-navbar-inner">
    <a href="<?= APP_URL ?>/roles/lms/lms_home" class="lms-navbar-brand">
      <span class="lms-navbar-brand-mark">
        <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12.083 12.083 0 0121 15.5c0 2.485-4.03 4.5-9 4.5s-9-2.015-9-4.5c0-1.579.768-2.966 1.84-4.42L12 14z"/></svg>
      </span>
      <span class="lms-navbar-brand-text">
        <span class="lms-navbar-brand-name">Greenfield Senior High School</span>
        <span class="lms-navbar-brand-role">Student LMS</span>
      </span>
    </a>

    <button type="button" class="lms-navbar-toggle" id="lmsNavToggle" aria-label="Toggle menu"><span></span><span></span><span></span></button>

    <div class="lms-navbar-menu" id="lmsNavMenu">
      <div class="lms-navbar-links">
        <?php lmsNavLink(APP_URL . '/roles/lms/lms_home', 'Home', $current, 'lms_home.php'); ?>
        <?php lmsNavLink(APP_URL . '/roles/lms/my_courses', 'My Courses', $current, 'my_courses.php'); ?>

        <div class="lms-nav-dropdown" id="lmsAcademicsDropdown">
          <button type="button" class="lms-nav-link lms-nav-dropdown-toggle<?= $academicsActive ? ' active' : '' ?>" id="lmsAcademicsToggle">
            Academics <?= $ico_chevron ?>
          </button>
          <div class="lms-nav-dropdown-panel">
            <?php lmsNavLink(APP_URL . '/roles/lms/student_lessons', 'Lessons', $current, 'student_lessons.php'); ?>
            <?php lmsNavLink(APP_URL . '/roles/lms/student_discussions', 'Discussions', $current, 'student_discussions.php'); ?>
            <?php lmsNavLink(APP_URL . '/roles/lms/student_assignments', 'Assignments', $current, 'student_assignments.php'); ?>
            <?php lmsNavLink(APP_URL . '/roles/lms/student_quizzes', 'Quizzes', $current, 'student_quizzes.php'); ?>
          </div>
        </div>
      </div>

      <div class="student-topbar-profile-wrapper" id="studentProfileWrapper">
        <button type="button" class="student-topbar-profile" id="studentProfileToggle" aria-haspopup="true" aria-expanded="false">
          <span class="student-topbar-profile-avatar">
            <?php if (!empty($sb_photo_path)): ?>
              <img src="<?= APP_URL ?>/roles/student/portal/student_photo?portal=lms" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
            <?php else: ?>
              <?= strtoupper(substr($profile_name, 0, 1)) ?>
            <?php endif; ?>
          </span>
          <span class="student-topbar-profile-text">
            <span class="student-topbar-profile-name"><?= htmlspecialchars($profile_name) ?></span>
            <span class="student-topbar-profile-role"><?= htmlspecialchars($profile_role) ?></span>
          </span>
          <span class="student-topbar-profile-chevron"><?= $ico_chevron ?></span>
        </button>
        <div class="student-profile-dropdown" id="studentProfileDropdown">
          <a href="<?= APP_URL ?>/roles/student/portal/student_profile?portal=lms" class="student-profile-dropdown-item"><span>Profile</span></a>
          <a href="<?= APP_URL ?>/roles/lms/lms_logout" class="student-profile-dropdown-item student-profile-dropdown-logout" id="studentLogoutBtn"><span>Logout</span></a>
        </div>
      </div>
    </div>
  </div>
</nav>

<script>
(function () {

  const navToggle   = document.getElementById('lmsNavToggle');
  const navMenu      = document.getElementById('lmsNavMenu');
  const academicsDD  = document.getElementById('lmsAcademicsDropdown');
  const academicsBtn = document.getElementById('lmsAcademicsToggle');

  navToggle.addEventListener('click', function () {
    navMenu.classList.toggle('open');
  });

  academicsBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    academicsDD.classList.toggle('open');
  });

  document.addEventListener('click', function (e) {
    if (!academicsDD.contains(e.target)) {
      academicsDD.classList.remove('open');
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var profileWrapper  = document.getElementById('studentProfileWrapper');
    var profileToggle   = document.getElementById('studentProfileToggle');
    var profileDropdown = document.getElementById('studentProfileDropdown');

    if (profileWrapper && profileToggle && profileDropdown) {
      function closeProfileDropdown() {
        profileWrapper.classList.remove('open');
        profileToggle.setAttribute('aria-expanded', 'false');
      }
      function openProfileDropdown() {
        profileWrapper.classList.add('open');
        profileToggle.setAttribute('aria-expanded', 'true');
      }

      profileToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (profileWrapper.classList.contains('open')) closeProfileDropdown(); else openProfileDropdown();
      });

      document.addEventListener('click', function (e) {
        if (profileWrapper.classList.contains('open') && !profileWrapper.contains(e.target)) closeProfileDropdown();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && profileWrapper.classList.contains('open')) {
          closeProfileDropdown();
          profileToggle.focus();
        }
      });
    }

    var logoutBtn = document.getElementById('studentLogoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (typeof Swal === 'undefined') {
          if (window.confirm('Log out? You will be returned to the login page.')) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = '<?= APP_URL ?>/roles/lms/lms_logout';
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
            window.location.href = '<?= APP_URL ?>/roles/lms/lms_logout';
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
