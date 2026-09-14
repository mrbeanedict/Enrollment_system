<?php
// Folder-tab top nav for the Student LMS — included by every LMS page
// (lms_home.php, my_courses.php, student_lessons.php, etc.). Every link
// below uses the absolute APP_URL prefix instead of a depth-counted
// relative path.
// Session/data contract: STUDENT_LMS_SESSID, $conn.

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

// Plain pill tab — the primary, always-visible tabs (Overview, My Courses).
// Replaced the earlier organic folder-tab silhouette (see git history):
// that shape read as decorative and hurt scanability, so this is a flat,
// standard tab instead — solid fill on the active state is the only signal
// needed to tell current page from the rest.
function lmsNavTab($href, $label, $current, $match) {
    $active = ($current === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="lms-navtab' . $active . '"><span class="lms-navtab-label">' . htmlspecialchars($label) . '</span></a>';
}

// Plain link inside a dropdown panel (the Academics tab's own submenu, and
// the profile menu).
function lmsNavPanelLink($href, $label, $current, $match) {
    $active = ($current === $match) ? ' active' : '';
    echo '<a href="' . $href . '" class="lms-navtab-panel-link' . $active . '">' . htmlspecialchars($label) . '</a>';
}

$ico_chevron = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>';
$ico_bell    = '<svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';

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

// Same enrollment -> section lookup lms_sidebar.php uses for its profile
// chip's role line.
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

// ── Notification bell — same shape as staff/staff_notifications.php,
// scoped to recipient_type='student' (see notify.php). Same underlying
// rows as the Student Portal's bell (student_topbar_right.php) — just a
// separate session/cookie (STUDENT_LMS_SESSID) for the same person, so
// marking read here is also reflected there and vice versa.
$lms_notif_user_id = (int) ($_SESSION['user_student_id'] ?? 0);
$lms_notifs = [];
$lms_unread = 0;
$lms_n_stmt = mysqli_prepare($conn, "
    SELECT notification_id, message, link, is_read, created_at
    FROM notifications WHERE user_id = ? AND recipient_type = 'student' ORDER BY created_at DESC LIMIT 10
");
mysqli_stmt_bind_param($lms_n_stmt, "i", $lms_notif_user_id);
mysqli_stmt_execute($lms_n_stmt);
$lms_notifs = mysqli_stmt_get_result($lms_n_stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($lms_n_stmt);

$lms_uc_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND recipient_type = 'student' AND is_read = 0");
mysqli_stmt_bind_param($lms_uc_stmt, "i", $lms_notif_user_id);
mysqli_stmt_execute($lms_uc_stmt);
mysqli_stmt_bind_result($lms_uc_stmt, $lms_unread);
mysqli_stmt_fetch($lms_uc_stmt);
mysqli_stmt_close($lms_uc_stmt);

function lms_time_ago($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>

<!-- SweetAlert2 -->
<script src="<?= APP_URL ?>/assets/js/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/tab_guard.js?v=<?= filemtime(__DIR__ . '/../../assets/js/tab_guard.js') ?>" data-token="<?= htmlspecialchars($_SESSION['sg_tab_token'] ?? '', ENT_QUOTES) ?>" data-storage-key="sg_tab_token_lms" data-logout-url="<?= APP_URL ?>/roles/lms/lms_logout"></script>

<div class="page-loader" id="pageLoader"><div class="page-loader-spinner"></div></div>

<nav class="lms-foldernav" id="lmsFolderNav">
  <div class="lms-foldernav-inner">
    <a href="<?= APP_URL ?>/roles/lms/lms_home" class="lms-foldernav-logo">
      <img src="<?= APP_URL ?>/assets/images/log_ui.png" alt="">
      <span class="lms-foldernav-logo-text">Greenfield Senior High School</span>
    </a>

    <button type="button" class="lms-foldernav-toggle" id="lmsFolderNavToggle" aria-label="Toggle menu"><span></span><span></span><span></span></button>

    <div class="lms-foldernav-tabs" id="lmsFolderNavTabs">
      <?php lmsNavTab(APP_URL . '/roles/lms/lms_home', 'Overview', $current, 'lms_home.php'); ?>
      <?php lmsNavTab(APP_URL . '/roles/lms/my_courses', 'My Courses', $current, 'my_courses.php'); ?>

      <div class="lms-navtab-dropdown<?= $academicsActive ? ' open-active' : '' ?>" id="lmsAcademicsDropdown">
        <button type="button" class="lms-navtab lms-navtab-toggle<?= $academicsActive ? ' active' : '' ?>" id="lmsAcademicsToggle">
          <span class="lms-navtab-label">Academics</span> <?= $ico_chevron ?>
        </button>
        <div class="lms-navtab-panel">
          <?php lmsNavPanelLink(APP_URL . '/roles/lms/student_lessons', 'Lessons', $current, 'student_lessons.php'); ?>
          <?php lmsNavPanelLink(APP_URL . '/roles/lms/student_discussions', 'Discussions', $current, 'student_discussions.php'); ?>
          <?php lmsNavPanelLink(APP_URL . '/roles/lms/student_assignments', 'Assignments', $current, 'student_assignments.php'); ?>
          <?php lmsNavPanelLink(APP_URL . '/roles/lms/student_quizzes', 'Quizzes', $current, 'student_quizzes.php'); ?>
        </div>
      </div>
    </div>

    <div class="lms-foldernav-right">
      <div class="lms-notif-wrap">
        <button type="button" class="lms-foldernav-notif" id="lmsNotifBell" aria-label="Notifications" title="Notifications">
          <?= $ico_bell ?>
          <?php if ($lms_unread > 0): ?><span class="lms-foldernav-notif-dot"></span><?php endif; ?>
        </button>
        <div class="lms-notif-dropdown" id="lmsNotifDropdown">
          <div class="lms-notif-header">Notifications</div>
          <?php if (!$lms_notifs): ?>
            <div class="lms-notif-empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($lms_notifs as $lms_n): ?>
              <a href="<?= APP_URL . '/' . htmlspecialchars($lms_n['link']) ?>" class="lms-notif-item<?= $lms_n['is_read'] ? '' : ' unread' ?>">
                <div class="lms-notif-msg"><?= htmlspecialchars($lms_n['message']) ?></div>
                <div class="lms-notif-time"><?= lms_time_ago($lms_n['created_at']) ?></div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <span class="lms-foldernav-divider"></span>

      <div class="lms-foldernav-profile-wrapper" id="lmsProfileWrapper">
        <button type="button" class="lms-foldernav-profile" id="lmsProfileToggle" aria-haspopup="true" aria-expanded="false" aria-label="<?= htmlspecialchars($profile_name) ?> — account menu" title="<?= htmlspecialchars($profile_name) ?>">
          <span class="lms-foldernav-avatar">
            <?php if (!empty($sb_photo_path)): ?>
              <img src="<?= APP_URL ?>/roles/student/portal/student_photo?portal=lms" alt="">
            <?php else: ?>
              <?= strtoupper(substr($profile_name, 0, 1)) ?>
            <?php endif; ?>
          </span>
          <span class="lms-foldernav-profile-text">
            <span class="lms-foldernav-profile-name"><?= htmlspecialchars($profile_name) ?></span>
            <span class="lms-foldernav-profile-role"><?= htmlspecialchars($profile_role) ?></span>
          </span>
          <span class="lms-foldernav-profile-chevron"><?= $ico_chevron ?></span>
        </button>
        <div class="lms-foldernav-dropdown" id="lmsProfileDropdown">
          <a href="<?= APP_URL ?>/roles/student/portal/student_profile?portal=lms" class="lms-foldernav-dropdown-item"><span>Profile</span></a>
          <a href="<?= APP_URL ?>/roles/lms/lms_logout" class="lms-foldernav-dropdown-item lms-foldernav-dropdown-logout" id="lmsFolderLogoutBtn"><span>Logout</span></a>
        </div>
      </div>
    </div>
  </div>
</nav>

<script>
(function () {
  const navToggle    = document.getElementById('lmsFolderNavToggle');
  const navTabs       = document.getElementById('lmsFolderNavTabs');
  const academicsDD   = document.getElementById('lmsAcademicsDropdown');
  const academicsBtn  = document.getElementById('lmsAcademicsToggle');

  navToggle.addEventListener('click', function () {
    navTabs.classList.toggle('open');
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
    var notifBell = document.getElementById('lmsNotifBell');
    var notifDropdown = document.getElementById('lmsNotifDropdown');
    if (notifBell && notifDropdown) {
      var notifMarked = false;
      notifBell.addEventListener('click', function (e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('open');
        if (notifDropdown.classList.contains('open') && !notifMarked) {
          notifMarked = true;
          fetch('<?= APP_URL ?>/ajax/lms_mark_notifications_read', { method: 'POST' });
          var dot = notifBell.querySelector('.lms-foldernav-notif-dot');
          if (dot) dot.remove();
        }
      });
      document.addEventListener('click', function (e) {
        if (!notifDropdown.contains(e.target) && e.target !== notifBell) notifDropdown.classList.remove('open');
      });
    }

    var profileWrapper  = document.getElementById('lmsProfileWrapper');
    var profileToggle   = document.getElementById('lmsProfileToggle');
    var profileDropdown = document.getElementById('lmsProfileDropdown');

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

    var logoutBtn = document.getElementById('lmsFolderLogoutBtn');
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
