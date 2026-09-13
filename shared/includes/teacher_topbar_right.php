<?php
// Requires: $conn open, session active, teacher_sidebar.php already included
// (for $sb_display_name / $sb_photo_path). Self-contained like
// teacher_sidebar.php — queries its own department name so any page can
// drop this in without first loading that data itself.
//
// Output: the topbar-right notification bell + profile chip/dropdown used on
// every roles/teacher/*.php page, so the topbar look stays identical
// everywhere instead of drifting page to page — same pattern as
// studentportal/student_topbar_right.php. All locals are tbr_-prefixed (or
// scoped to this file) so including this never overwrites a variable the
// calling page already set (e.g. $ico_* icons defined by teacher_sidebar.php
// or by the page itself).

$tbr_teacher_id = (int) ($_SESSION['teacher_id'] ?? 0);

$tbr_department_name = null;
$tbr_dept_stmt = mysqli_prepare($conn, "
    SELECT d.department_name
    FROM teachers t
    LEFT JOIN departments d ON d.department_id = t.department_id
    WHERE t.teacher_id = ?
");
mysqli_stmt_bind_param($tbr_dept_stmt, "i", $tbr_teacher_id);
mysqli_stmt_execute($tbr_dept_stmt);
mysqli_stmt_bind_result($tbr_dept_stmt, $tbr_department_name);
mysqli_stmt_fetch($tbr_dept_stmt);
mysqli_stmt_close($tbr_dept_stmt);

$tbr_profile_name = $sb_display_name ?? ($_SESSION['username'] ?? 'Teacher');
$tbr_profile_role = $tbr_department_name ? ucfirst($tbr_department_name) : 'Teacher';

$tbr_profile_href = APP_URL . '/roles/teacher/teacher_profile';
$tbr_logout_href  = APP_URL . '/roles/teacher/teacher_logout';
$tbr_photo_href   = APP_URL . '/roles/teacher/teacher_photo';

$tbr_ico_bell     = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
$tbr_ico_chevdown = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>';

// ── Notification bell — same shape as staff/staff_notifications.php,
// scoped to recipient_type='teacher' (see notify.php) and this teacher's
// user_id (teacher_login.php sets $_SESSION['user_id'] the same as the
// staff session, just under a distinct TEACHER_SESSID cookie).
$tbr_notif_user_id = (int) ($_SESSION['user_id'] ?? 0);
$tbr_notifs = [];
$tbr_unread = 0;
$tbr_n_stmt = mysqli_prepare($conn, "
    SELECT notification_id, message, link, is_read, created_at
    FROM notifications WHERE user_id = ? AND recipient_type = 'teacher' ORDER BY created_at DESC LIMIT 10
");
mysqli_stmt_bind_param($tbr_n_stmt, "i", $tbr_notif_user_id);
mysqli_stmt_execute($tbr_n_stmt);
$tbr_notifs = mysqli_stmt_get_result($tbr_n_stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($tbr_n_stmt);

$tbr_uc_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND recipient_type = 'teacher' AND is_read = 0");
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
<div class="teacher-topbar-right">
  <div class="teacher-notif-wrap">
    <button type="button" class="teacher-topbar-notif" id="teacherNotifBell" aria-label="Notifications" title="Notifications">
      <?= $tbr_ico_bell ?>
      <?php if ($tbr_unread > 0): ?><span class="teacher-notif-badge"><?= $tbr_unread > 9 ? '9+' : $tbr_unread ?></span><?php endif; ?>
    </button>
    <div class="teacher-notif-dropdown" id="teacherNotifDropdown">
      <div class="teacher-notif-header">Notifications</div>
      <?php if (!$tbr_notifs): ?>
        <div class="teacher-notif-empty">No notifications yet.</div>
      <?php else: ?>
        <?php foreach ($tbr_notifs as $tbr_n): ?>
          <a href="<?= APP_URL . "/" . htmlspecialchars($tbr_n["link"]) ?>" class="teacher-notif-item<?= $tbr_n['is_read'] ? '' : ' unread' ?>">
            <div class="teacher-notif-msg"><?= htmlspecialchars($tbr_n['message']) ?></div>
            <div class="teacher-notif-time"><?= tbr_time_ago($tbr_n['created_at']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="teacher-topbar-profile-wrapper" id="teacherProfileWrapper">
    <button type="button" class="teacher-topbar-profile" id="teacherProfileToggle" aria-haspopup="true" aria-expanded="false">
      <span class="teacher-topbar-profile-avatar">
        <?php if (!empty($sb_photo_path)): ?>
          <img src="<?= htmlspecialchars($tbr_photo_href) ?>" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <?= strtoupper(substr($tbr_profile_name, 0, 1)) ?>
        <?php endif; ?>
      </span>
      <span class="teacher-topbar-profile-text">
        <span class="teacher-topbar-profile-name"><?= htmlspecialchars($tbr_profile_name) ?></span>
        <span class="teacher-topbar-profile-role"><?= htmlspecialchars($tbr_profile_role) ?></span>
      </span>
      <span class="teacher-topbar-profile-chevron"><?= $tbr_ico_chevdown ?></span>
    </button>
    <div class="teacher-profile-dropdown" id="teacherProfileDropdown">
      <a href="<?= htmlspecialchars($tbr_profile_href) ?>" class="teacher-profile-dropdown-item"><span>Profile</span></a>
      <a href="<?= htmlspecialchars($tbr_logout_href) ?>" class="teacher-profile-dropdown-item teacher-profile-dropdown-logout" id="teacherDropdownLogoutBtn"><span>Logout</span></a>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var notifBell = document.getElementById('teacherNotifBell');
    var notifDropdown = document.getElementById('teacherNotifDropdown');
    if (notifBell && notifDropdown) {
      var notifMarked = false;
      notifBell.addEventListener('click', function (e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('open');
        if (notifDropdown.classList.contains('open') && !notifMarked) {
          notifMarked = true;
          fetch('<?= APP_URL ?>/ajax/teacher_mark_notifications_read', { method: 'POST' });
          var badge = notifBell.querySelector('.teacher-notif-badge');
          if (badge) badge.remove();
        }
      });
      document.addEventListener('click', function (e) {
        if (!notifDropdown.contains(e.target) && e.target !== notifBell) notifDropdown.classList.remove('open');
      });
    }

    var wrapper  = document.getElementById('teacherProfileWrapper');
    var toggle   = document.getElementById('teacherProfileToggle');
    var dropdown = document.getElementById('teacherProfileDropdown');

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

    // Distinct id from teacher_sidebar.php's own #teacherLogoutBtn — this is
    // a second, independent logout affordance (the dropdown's), so it gets
    // its own listener rather than sharing/overriding the sidebar's.
    var dropdownLogoutBtn = document.getElementById('teacherDropdownLogoutBtn');
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
          confirmButtonColor: '#1E4D3B',
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
