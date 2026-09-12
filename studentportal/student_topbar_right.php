<?php
// Requires: $conn open, session active, student_sidebar.php already included
// (for $sb_given_name / $sb_family_name / $sb_photo_path / $base). Self-contained
// like student_sidebar.php — queries its own section_name so any page can drop
// this in without first loading an enrollment record itself.
//
// Output: the topbar-right notification bell + profile chip/dropdown used on
// every studentportal/*.php page, so the topbar look stays identical everywhere
// instead of drifting page to page.

$tbr_student_id = (int) ($_SESSION['student_id'] ?? 0);

$tbr_section_name = null;
$tbr_sec_stmt = mysqli_prepare($conn, "
    SELECT sec.section_name
    FROM enrollments e
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    WHERE e.student_id = ?
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 1
");
mysqli_stmt_bind_param($tbr_sec_stmt, "i", $tbr_student_id);
mysqli_stmt_execute($tbr_sec_stmt);
mysqli_stmt_bind_result($tbr_sec_stmt, $tbr_section_name);
mysqli_stmt_fetch($tbr_sec_stmt);
mysqli_stmt_close($tbr_sec_stmt);

$tbr_profile_name = trim(($sb_given_name ?? '') . ' ' . ($sb_family_name ?? '')) ?: ($_SESSION['username'] ?? 'Student');
$tbr_profile_role = $tbr_section_name ?: 'Student';

// student_profile.php is reachable from both the Student Portal and the
// Student LMS (distinct sessions — see that file's own comment); when
// rendered from the LMS side, the dropdown must log out of the LMS
// session, not silently redirect into the separate student-portal one.
$tbr_is_lms       = !empty($is_lms_mode);
$tbr_profile_href = $base . 'studentportal/student_profile' . ($tbr_is_lms ? '?portal=lms' : '');
$tbr_logout_href  = $tbr_is_lms ? ($base . 'learningportal/lms_logout') : ($base . 'studentportal/student_logout');
$tbr_photo_href   = $base . 'studentportal/student_photo' . ($tbr_is_lms ? '?portal=lms' : '');

$tbr_ico_bell     = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
$tbr_ico_chevdown = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>';

// ── Notification bell — same shape as staff/staff_notifications.php,
// scoped to recipient_type='student' (see notify.php). This file is
// shared by both the Student Portal (STUDENT_SESSID) and the Student LMS
// (STUDENT_LMS_SESSID, via $is_lms_mode) — whichever session the including
// page already started is the one active here, and both store the same
// $_SESSION['user_student_id'] for the same person, so this works
// unchanged either way. Only the mark-read endpoint differs (it must
// match the currently-active session's cookie name).
$tbr_notif_user_id = (int) ($_SESSION['user_student_id'] ?? 0);
$tbr_mark_read_url = $base . 'ajax/' . ($tbr_is_lms ? 'lms_mark_notifications_read' : 'student_mark_notifications_read');

$tbr_notifs = [];
$tbr_unread = 0;
$tbr_n_stmt = mysqli_prepare($conn, "
    SELECT notification_id, message, link, is_read, created_at
    FROM notifications WHERE user_id = ? AND recipient_type = 'student' ORDER BY created_at DESC LIMIT 10
");
mysqli_stmt_bind_param($tbr_n_stmt, "i", $tbr_notif_user_id);
mysqli_stmt_execute($tbr_n_stmt);
$tbr_notifs = mysqli_stmt_get_result($tbr_n_stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($tbr_n_stmt);

$tbr_uc_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND recipient_type = 'student' AND is_read = 0");
mysqli_stmt_bind_param($tbr_uc_stmt, "i", $tbr_notif_user_id);
mysqli_stmt_execute($tbr_uc_stmt);
mysqli_stmt_bind_result($tbr_uc_stmt, $tbr_unread);
mysqli_stmt_fetch($tbr_uc_stmt);
mysqli_stmt_close($tbr_uc_stmt);

function tbr_time_ago($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>
<div class="student-topbar-right">
  <div class="student-notif-wrap">
    <button type="button" class="student-topbar-notif" id="studentNotifBell" aria-label="Notifications" title="Notifications">
      <?= $tbr_ico_bell ?>
      <?php if ($tbr_unread > 0): ?><span class="student-topbar-notif-badge"><?= $tbr_unread > 9 ? '9+' : $tbr_unread ?></span><?php endif; ?>
    </button>
    <div class="student-notif-dropdown" id="studentNotifDropdown">
      <div class="student-notif-header">Notifications</div>
      <?php if (!$tbr_notifs): ?>
        <div class="student-notif-empty">No notifications yet.</div>
      <?php else: ?>
        <?php foreach ($tbr_notifs as $tbr_n): ?>
          <a href="<?= $base . htmlspecialchars($tbr_n['link']) ?>" class="student-notif-item<?= $tbr_n['is_read'] ? '' : ' unread' ?>">
            <div class="student-notif-msg"><?= htmlspecialchars($tbr_n['message']) ?></div>
            <div class="student-notif-time"><?= tbr_time_ago($tbr_n['created_at']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <span class="student-topbar-divider"></span>
  <div class="student-topbar-profile-wrapper" id="studentProfileWrapper">
    <button type="button" class="student-topbar-profile" id="studentProfileToggle" aria-haspopup="true" aria-expanded="false">
      <span class="student-topbar-profile-avatar">
        <?php if (!empty($sb_photo_path)): ?>
          <img src="<?= htmlspecialchars($tbr_photo_href) ?>" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <?= strtoupper(substr($tbr_profile_name, 0, 1)) ?>
        <?php endif; ?>
      </span>
      <span class="student-topbar-profile-text">
        <span class="student-topbar-profile-name"><?= htmlspecialchars($tbr_profile_name) ?></span>
        <span class="student-topbar-profile-role"><?= htmlspecialchars($tbr_profile_role) ?></span>
      </span>
      <span class="student-topbar-profile-chevron"><?= $tbr_ico_chevdown ?></span>
    </button>
    <div class="student-profile-dropdown" id="studentProfileDropdown">
      <a href="<?= htmlspecialchars($tbr_profile_href) ?>" class="student-profile-dropdown-item"><span>Profile</span></a>
      <a href="<?= htmlspecialchars($tbr_logout_href) ?>" class="student-profile-dropdown-item student-profile-dropdown-logout" id="studentDropdownLogoutBtn"><span>Logout</span></a>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var notifBell = document.getElementById('studentNotifBell');
    var notifDropdown = document.getElementById('studentNotifDropdown');
    if (notifBell && notifDropdown) {
      var notifMarked = false;
      notifBell.addEventListener('click', function (e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('open');
        if (notifDropdown.classList.contains('open') && !notifMarked) {
          notifMarked = true;
          fetch('<?= $tbr_mark_read_url ?>', { method: 'POST' });
          var badge = notifBell.querySelector('.student-topbar-notif-badge');
          if (badge) badge.remove();
        }
      });
      document.addEventListener('click', function (e) {
        if (!notifDropdown.contains(e.target) && e.target !== notifBell) notifDropdown.classList.remove('open');
      });
    }

    var wrapper  = document.getElementById('studentProfileWrapper');
    var toggle   = document.getElementById('studentProfileToggle');
    var dropdown = document.getElementById('studentProfileDropdown');

    if (wrapper && toggle && dropdown) {
      function closeDropdown() {
        wrapper.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
      function openDropdown() {
        wrapper.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
      }

      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (wrapper.classList.contains('open')) closeDropdown(); else openDropdown();
      });

      document.addEventListener('click', function (e) {
        if (wrapper.classList.contains('open') && !wrapper.contains(e.target)) closeDropdown();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && wrapper.classList.contains('open')) {
          closeDropdown();
          toggle.focus();
        }
      });
    }

    var dropdownLogoutBtn = document.getElementById('studentDropdownLogoutBtn');
    if (dropdownLogoutBtn) {
      dropdownLogoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var url = dropdownLogoutBtn.getAttribute('href');
        if (typeof Swal === 'undefined') {
          if (window.confirm('Log out? You will be returned to the login page.')) {
            document.getElementById('pageLoader').classList.add('show');
            window.location.href = url;
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
            window.location.href = url;
          }
        });
      });
    }
  });
</script>
