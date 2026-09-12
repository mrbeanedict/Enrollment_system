<?php
session_name('TEACHER_SESSID');
session_start();
include_once '../config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../teacherportal/teacher_login");
    exit();
}
guard_password_change('teacher_change_password', 'teacher');

$success_message = '';
$error_message   = '';
$profile         = null;

// Which nav section is active. Falls back to 'contact' so a bad/missing
// ?tab= value never renders an empty panel — same pattern as
// studentportal/student_profile.php.
$valid_tabs = ['contact', 'login'];
$active_tab = in_array($_GET['tab'] ?? '', $valid_tabs, true) ? $_GET['tab'] : 'contact';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $teacher_id = (int) $_SESSION['teacher_id'];
    $user_id    = (int) $_SESSION['user_id'];

    // ── Upload / change profile photo ───────────────────────────────────────
    // Same validation shape as admission.php's "2x2 Picture" requirement:
    // extension allow-list only (no MIME sniffing elsewhere in this app),
    // 3 MB cap. Files are never served directly — see teacher_photo.php
    // and uploads/profile_photos/.htaccess.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
        $photo_allowed_ext = ['jpg', 'jpeg', 'png'];
        $photo_max_bytes    = 3 * 1024 * 1024;

        $file = $_FILES['photo'] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $error_message = 'Please choose a photo to upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'Could not upload the photo. Please try again.';
        } elseif ($file['size'] > $photo_max_bytes) {
            $error_message = 'Photo must be 3 MB or smaller.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $photo_allowed_ext, true)) {
                $error_message = 'Photo must be a JPG or PNG file.';
            } else {
                $stored_name = uniqid('teacher_', true) . '.' . $ext;
                $dest_dir    = __DIR__ . '/../uploads/profile_photos/';
                $dest        = $dest_dir . $stored_name;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $old_stmt = mysqli_prepare($conn, "SELECT photo_path FROM users WHERE user_id = ?");
                    mysqli_stmt_bind_param($old_stmt, "i", $user_id);
                    mysqli_stmt_execute($old_stmt);
                    $old_photo = mysqli_fetch_assoc(mysqli_stmt_get_result($old_stmt))['photo_path'] ?? null;
                    mysqli_stmt_close($old_stmt);

                    $upd_stmt = mysqli_prepare($conn, "UPDATE users SET photo_path = ? WHERE user_id = ?");
                    mysqli_stmt_bind_param($upd_stmt, "si", $stored_name, $user_id);

                    if (mysqli_stmt_execute($upd_stmt)) {
                        mysqli_stmt_close($upd_stmt);
                        // Replace, not append — remove the previous photo
                        // file now that the DB points at the new one.
                        if ($old_photo && is_file($dest_dir . $old_photo)) {
                            @unlink($dest_dir . $old_photo);
                        }
                        header("Location: teacher_profile?updated=photo&tab=contact"); exit();
                    } else {
                        mysqli_stmt_close($upd_stmt);
                        @unlink($dest);
                        $error_message = 'Could not save the photo. Please try again.';
                    }
                } else {
                    $error_message = 'Could not upload the photo. Please try again.';
                }
            }
        }
        $active_tab = 'contact';
    }

    // ── Remove profile photo ────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
        $old_stmt = mysqli_prepare($conn, "SELECT photo_path FROM users WHERE user_id = ?");
        mysqli_stmt_bind_param($old_stmt, "i", $user_id);
        mysqli_stmt_execute($old_stmt);
        $old_photo = mysqli_fetch_assoc(mysqli_stmt_get_result($old_stmt))['photo_path'] ?? null;
        mysqli_stmt_close($old_stmt);

        $upd_stmt = mysqli_prepare($conn, "UPDATE users SET photo_path = NULL WHERE user_id = ?");
        mysqli_stmt_bind_param($upd_stmt, "i", $user_id);
        mysqli_stmt_execute($upd_stmt);
        mysqli_stmt_close($upd_stmt);

        if ($old_photo) {
            $dest_dir = __DIR__ . '/../uploads/profile_photos/';
            if (is_file($dest_dir . $old_photo)) {
                @unlink($dest_dir . $old_photo);
            }
        }

        header("Location: teacher_profile?updated=photo_removed&tab=contact"); exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact'])) {
        $email   = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } elseif ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
            $error_message = 'Contact number must be in the format 09XXXXXXXXX.';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE teachers SET email = ?, contact_number = ? WHERE teacher_id = ?");
            mysqli_stmt_bind_param($stmt, "ssi", $email, $contact, $teacher_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            header("Location: teacher_profile?updated=contact&tab=contact");
            exit();
        }
        $active_tab = 'contact';
    }

    // Post/Redirect/Get here too (this used to fall through and re-render
    // inline) — otherwise refreshing right after a password change would
    // resubmit the form, and the page had no way to land back on the
    // "Login & Password" tab instead of resetting to "Contact".
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $pw_stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
        mysqli_stmt_bind_param($pw_stmt, "i", $user_id);
        mysqli_stmt_execute($pw_stmt);
        $pw_row = mysqli_fetch_assoc(mysqli_stmt_get_result($pw_stmt));
        mysqli_stmt_close($pw_stmt);

        if (!$pw_row || !password_verify($current, $pw_row['password_hash'])) {
            $error_message = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error_message = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error_message = 'New password and confirmation do not match.';
        } else {
            $new_hash = password_hash($new, PASSWORD_BCRYPT);
            $upd_stmt = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($upd_stmt, "si", $new_hash, $user_id);
            mysqli_stmt_execute($upd_stmt);
            mysqli_stmt_close($upd_stmt);

            $_SESSION['tp_flash'] = 'Password updated successfully.';
            header("Location: teacher_profile?tab=login");
            exit();
        }
        $active_tab = 'login';
    }

    $prof_stmt = mysqli_prepare($conn, "
        SELECT t.family_name, t.given_name, t.middle_name, t.suffix, t.email, t.contact_number, u.photo_path,
               u.username, d.department_name
        FROM teachers t
        JOIN users u ON u.user_id = t.user_id
        LEFT JOIN departments d ON d.department_id = t.department_id
        WHERE t.teacher_id = ?
    ");
    mysqli_stmt_bind_param($prof_stmt, "i", $teacher_id);
    mysqli_stmt_execute($prof_stmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($prof_stmt));
    mysqli_stmt_close($prof_stmt);

    if (isset($_GET['updated']) && $_GET['updated'] === 'photo') {
        $success_message = 'Profile photo updated.';
    } elseif (isset($_GET['updated']) && $_GET['updated'] === 'photo_removed') {
        $success_message = 'Profile photo removed.';
    } elseif (isset($_GET['updated'])) {
        $success_message = 'Contact info updated successfully.';
    }
    if (!empty($_SESSION['tp_flash'])) {
        $success_message = $_SESSION['tp_flash'];
        unset($_SESSION['tp_flash']);
    }

    $initials = strtoupper(substr($profile['given_name'] ?? 'T', 0, 1) . substr($profile['family_name'] ?? '', 0, 1));
    $full_name = trim($profile['given_name'] . ' ' . $profile['family_name']);

    // Mirrors studentportal/student_profile.php's course_line: read-only
    // summary shown under the name in the left nav card.
    $course_line = $profile['department_name'] ? ucfirst($profile['department_name']) : 'Teacher';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — SHS Enrollment</title>
    <link rel="stylesheet" href="../css/css_teacher.css?v=<?= filemtime(__DIR__ . '/../css/css_teacher.css') ?>">
</head>
<body class="teacher-layout">
<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
  <p>You are not logged in. Please <a href="teacher_login">log in</a> to access this page.</p>

<?php else: ?>
  <?php include_once 'teacher_sidebar.php'; ?>
  <div class="teacher-main">
    <div class="teacher-topbar">
      <div class="teacher-topbar-left">
        <div class="teacher-topbar-title">
          My Profile
          <span class="teacher-topbar-subtitle">Your contact info and account security</span>
        </div>
      </div>
      <?php include 'teacher_topbar_right.php'; ?>
    </div>

    <div class="teacher-content">

      <?php if ($success_message): ?>
        <div class="notice notice-success"><?= htmlspecialchars($success_message) ?></div>
      <?php endif; ?>
      <?php if ($error_message): ?>
        <div class="notice notice-error"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <div class="account-layout">

        <!-- ── Left: avatar, name, and section nav ─────────────────────────── -->
        <div class="account-nav-card">
          <div class="account-avatar-wrap">
            <?php if (!empty($profile['photo_path'])): ?>
              <img class="account-avatar" src="teacher_photo?v=<?= urlencode($profile['photo_path']) ?>" alt="" style="object-fit:cover;">
            <?php else: ?>
              <div class="account-avatar"><?= htmlspecialchars($initials) ?></div>
            <?php endif; ?>
            <button type="button" class="account-avatar-edit" aria-label="Change photo" title="Change photo" onclick="document.getElementById('photoFileInput').click()">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536M9 11l6.586-6.586a2 2 0 112.828 2.828L11.828 13.828a4 4 0 01-1.897 1.06l-2.65.756.755-2.649a4 4 0 011.06-1.897L9 11z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 15v3a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h3"/></svg>
            </button>
            <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
              <input type="file" name="photo" id="photoFileInput" accept="image/jpeg,image/png" style="display:none;" onchange="document.getElementById('photoUploadForm').submit()">
              <input type="hidden" name="upload_photo" value="1">
            </form>
          </div>
          <?php if (!empty($profile['photo_path'])): ?>
            <form method="POST" id="photoRemoveForm" style="margin:8px 0 4px;">
              <input type="hidden" name="remove_photo" value="1">
              <button type="submit" class="account-avatar-remove-link" data-confirm="Remove your profile photo?" data-icon="warning">Remove photo</button>
            </form>
          <?php endif; ?>
          <div class="account-name"><?= htmlspecialchars($full_name) ?></div>
          <div class="account-course"><?= htmlspecialchars($course_line) ?></div>

          <nav class="account-nav">
            <a class="account-nav-link<?= $active_tab === 'contact' ? ' active' : '' ?>" href="?tab=contact" data-tab="contact">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              Contact Information
            </a>
            <a class="account-nav-link<?= $active_tab === 'login' ? ' active' : '' ?>" href="?tab=login" data-tab="login">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Login &amp; Password
            </a>
          </nav>
        </div>

        <!-- ── Right: tab panels ────────────────────────────────────────────── -->
        <div class="account-tab-content">

          <div class="account-tab-panel<?= $active_tab === 'contact' ? ' active' : '' ?>" data-tab-panel="contact">

            <div class="teacher-panel-block">
              <div class="teacher-panel-header">
                <div class="teacher-panel-header-left">
                  <div class="teacher-panel-title">Teacher Information</div>
                </div>
              </div>
              <div class="teacher-stat-grid">
                <div class="teacher-stat-card">
                  <div class="teacher-stat-body">
                    <div class="teacher-stat-label">Name</div>
                    <div class="teacher-stat-value"><?= htmlspecialchars(trim($profile['given_name'] . ' ' . $profile['middle_name'] . ' ' . $profile['family_name'] . ' ' . $profile['suffix'])) ?></div>
                  </div>
                </div>
                <div class="teacher-stat-card">
                  <div class="teacher-stat-body">
                    <div class="teacher-stat-label">Username</div>
                    <div class="teacher-stat-value"><?= htmlspecialchars($profile['username']) ?></div>
                  </div>
                </div>
                <div class="teacher-stat-card">
                  <div class="teacher-stat-body">
                    <div class="teacher-stat-label">Department</div>
                    <div class="teacher-stat-value"><?= htmlspecialchars($profile['department_name'] ? ucfirst($profile['department_name']) : '—') ?></div>
                  </div>
                </div>
              </div>
              <p class="field-hint" style="margin-top:.9rem;">Name and Department are managed by the Coordinator office. Contact them if any of this needs correction.</p>
            </div>

            <div class="teacher-panel-block" style="margin-top:1.5rem;">
              <div class="teacher-panel-header">
                <div class="teacher-panel-header-left">
                  <div class="teacher-panel-title">Contact Information</div>
                </div>
              </div>

              <form method="POST" action="teacher_profile" class="teacher-form" data-confirm="Save your updated contact information?" data-icon="question">
                <div class="teacher-form-row">
                  <div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required maxlength="100" placeholder="you@example.com" value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
                  </div>
                  <div>
                    <label for="contact_number">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" data-restrict="digits" pattern="09\d{9}" maxlength="11" title="Format: 09XXXXXXXXX" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($profile['contact_number'] ?? '') ?>">
                  </div>
                </div>

                <button type="submit" name="update_contact" class="btn-teacher-primary">Save Changes</button>
              </form>
            </div>
          </div><!-- /panel: contact -->

          <div class="account-tab-panel<?= $active_tab === 'login' ? ' active' : '' ?>" data-tab-panel="login">
            <div class="teacher-panel-block">
              <div class="teacher-panel-header">
                <div class="teacher-panel-header-left">
                  <div class="teacher-panel-title">Change Password</div>
                </div>
              </div>

              <p class="field-hint" style="margin-bottom:.8rem;">Current username: <strong><?= htmlspecialchars($profile['username']) ?></strong></p>

              <form method="POST" action="teacher_profile" class="teacher-form" data-confirm="Change your password now?" data-icon="question">
                <label for="current_password">Current Password</label>
                <div class="pw-field">
                  <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                  <button type="button" class="btn-eye-toggle" data-target="current_password" aria-label="Show password">
                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.774 3.162 10.066 7.5a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                  </button>
                </div>

                <div class="teacher-form-row">
                  <div>
                    <label for="new_password">New Password</label>
                    <div class="pw-field">
                      <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                      <button type="button" class="btn-eye-toggle" data-target="new_password" aria-label="Show password">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.774 3.162 10.066 7.5a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                      </button>
                    </div>
                  </div>
                  <div>
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="pw-field">
                      <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                      <button type="button" class="btn-eye-toggle" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.774 3.162 10.066 7.5a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                      </button>
                    </div>
                  </div>
                </div>

                <button type="submit" name="change_password" class="btn-teacher-primary">Change Password</button>
              </form>
            </div>
          </div><!-- /panel: login -->

        </div><!-- /account-tab-content -->

      </div><!-- /account-layout -->

    </div>
  </div>

  <script>
    document.querySelectorAll('.btn-eye-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.target);
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.classList.toggle('is-visible', !showing);
        btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      });
    });

    // Client-side tab switching — no reload needed when just browsing
    // sections; direct ?tab= links (and refresh) still work with no JS at
    // all since the active panel is also chosen server-side above.
    (function () {
      var navLinks = document.querySelectorAll('.account-nav-link[data-tab]');
      var panels    = document.querySelectorAll('.account-tab-panel[data-tab-panel]');
      navLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          var tab = link.dataset.tab;
          navLinks.forEach(function (l) { l.classList.toggle('active', l.dataset.tab === tab); });
          panels.forEach(function (p) { p.classList.toggle('active', p.dataset.tabPanel === tab); });
          history.replaceState(null, '', '?tab=' + tab);
        });
      });
    })();

    // Real-time input filtering — a UX convenience on top of the
    // pattern attribute and server-side check, which stay the actual
    // enforcement boundary (a pasted value or a direct POST still has
    // to pass those). keydown blocks the disallowed key outright (so it
    // never appears at all, not even for an instant); the input listener
    // is a fallback that strips anything that still got in via paste,
    // drag-drop, or autofill.
    document.querySelectorAll('[data-restrict="digits"]').forEach(function (input) {
      input.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return; // allow copy/paste/select-all shortcuts
        if (e.key.length > 1) return; // allow Backspace, Delete, arrows, Tab, etc.
        if (!/[0-9]/.test(e.key)) e.preventDefault();
      });
      input.addEventListener('input', function () {
        var cleaned = input.value.replace(/[^0-9]/g, '');
        if (cleaned !== input.value) input.value = cleaned;
      });
    });
  </script>

<?php endif; ?>
</body>
</html>
