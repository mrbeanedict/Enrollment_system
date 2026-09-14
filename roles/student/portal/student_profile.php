<?php
// Reachable from both the Student Portal and the Student LMS (each a
// distinct session, see lms_login.php) — Profile has no single fixed
// home, so which one to use is taken from an explicit `portal` signal
// on the request rather than guessed from cookie presence/validity
// (see student_lessons.php etc. for why guessing caused real bugs).
// Absent the param, this defaults to the Student Portal — every
// existing `href="student_profile"` link keeps working unchanged.
$is_lms_mode = ($_GET['portal'] ?? $_POST['portal'] ?? '') === 'lms';
session_name($is_lms_mode ? 'STUDENT_LMS_SESSID' : 'STUDENT_SESSID');
session_start();
require_once __DIR__ . '/../../../bootstrap.php';

require_login('student', $is_lms_mode ? APP_URL . '/roles/lms/lms_login' : 'student_login');

// Appended to every internal redirect/link on this page so LMS context
// survives tab switches and post-save redirects.
$portal_qs = $is_lms_mode ? '&portal=lms' : '';

$student_id      = (int) $_SESSION['student_id'];
$user_student_id = (int) $_SESSION['user_student_id'];

$success = '';
$error   = '';

// Which nav section is active. Falls back to 'contact' so a bad/missing
// ?tab= value never renders an empty panel.
$valid_tabs = $is_lms_mode ? ['contact', 'login'] : ['contact', 'guardian', 'login'];
$active_tab = in_array($_GET['tab'] ?? '', $valid_tabs, true) ? $_GET['tab'] : 'contact';

// ── Upload / change profile photo ───────────────────────────────────────────
// Same validation shape as admission.php's "2x2 Picture" requirement:
// extension allow-list only (no MIME sniffing elsewhere in this app),
// 3 MB cap. Files are never served directly — see student_photo.php and
// uploads/profile_photos/.htaccess.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    $photo_allowed_ext = ['jpg', 'jpeg', 'png'];
    $photo_max_bytes    = 3 * 1024 * 1024;

    $file = $_FILES['photo'] ?? null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a photo to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Could not upload the photo. Please try again.';
    } elseif ($file['size'] > $photo_max_bytes) {
        $error = 'Photo must be 3 MB or smaller.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $photo_allowed_ext, true)) {
            $error = 'Photo must be a JPG or PNG file.';
        } else {
            $stored_name = uniqid('student_', true) . '.' . $ext;
            $dest_dir    = __DIR__ . '/../../../uploads/profile_photos/';
            $dest        = $dest_dir . $stored_name;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $old_stmt = $conn->prepare("SELECT photo_path FROM users_student WHERE student_id = ?");
                $old_stmt->bind_param('i', $student_id);
                $old_stmt->execute();
                $old_photo = $old_stmt->get_result()->fetch_assoc()['photo_path'] ?? null;
                $old_stmt->close();

                $upd = $conn->prepare("UPDATE users_student SET photo_path = ? WHERE student_id = ?");
                $upd->bind_param('si', $stored_name, $student_id);

                if ($upd->execute()) {
                    $upd->close();
                    // Replace, not append — remove the previous photo file
                    // now that the DB points at the new one.
                    if ($old_photo && is_file($dest_dir . $old_photo)) {
                        @unlink($dest_dir . $old_photo);
                    }
                    header("Location: student_profile?saved=photo&tab=contact$portal_qs"); exit();
                } else {
                    $upd->close();
                    @unlink($dest);
                    $error = 'Could not save the photo. Please try again.';
                }
            } else {
                $error = 'Could not upload the photo. Please try again.';
            }
        }
    }
    $active_tab = 'contact';
}

// ── Remove profile photo ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    $old_stmt = $conn->prepare("SELECT photo_path FROM users_student WHERE student_id = ?");
    $old_stmt->bind_param('i', $student_id);
    $old_stmt->execute();
    $old_photo = $old_stmt->get_result()->fetch_assoc()['photo_path'] ?? null;
    $old_stmt->close();

    $upd = $conn->prepare("UPDATE users_student SET photo_path = NULL WHERE student_id = ?");
    $upd->bind_param('i', $student_id);
    $upd->execute();
    $upd->close();

    if ($old_photo) {
        $dest_dir = __DIR__ . '/../../../uploads/profile_photos/';
        if (is_file($dest_dir . $old_photo)) {
            @unlink($dest_dir . $old_photo);
        }
    }

    header("Location: student_profile?saved=photo_removed&tab=contact$portal_qs"); exit();
}

// ── Update contact info ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact'])) {
    $email   = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['full_address'] ?? '');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
        $active_tab = 'contact';
    } elseif ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
        $error = 'Contact number must be in the format 09XXXXXXXXX.';
        $active_tab = 'contact';
    } else {
        $stmt = $conn->prepare("UPDATE students SET email = ?, contact_number = ? WHERE student_id = ?");
        $stmt->bind_param('ssi', $email, $contact, $student_id);
        $stmt->execute();
        $stmt->close();

        // Still just the one free-text line the student can edit here —
        // needs_update stays set until a fuller address form backfills
        // barangay/city/province (see student_addresses).
        $addr_stmt = $conn->prepare("
            INSERT INTO student_addresses (student_id, address_line, needs_update)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE address_line = VALUES(address_line), needs_update = 1
        ");
        $addr_stmt->bind_param('is', $student_id, $address);
        $addr_stmt->execute();
        $addr_stmt->close();

        $log = $conn->prepare("INSERT INTO student_activity_log (student_id, activity_type, ip_address) VALUES (?, 'profile_update', ?)");
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $log->bind_param('is', $student_id, $ip);
        $log->execute();
        $log->close();

        header("Location: student_profile?saved=contact&tab=contact$portal_qs"); exit();
    }
}

// ── Update guardian info ─────────────────────────────────────────────────────
// Upserts into the `student_guardians` table (one row per student_id).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_guardian'])) {
    $g_family        = trim($_POST['guardian_family_name'] ?? '');
    $g_first         = trim($_POST['guardian_first_name'] ?? '');
    $g_middle        = trim($_POST['guardian_middle_name'] ?? '');
    $g_suffix        = trim($_POST['guardian_suffix'] ?? '');
    $g_relationship  = $_POST['guardian_relationship'] ?? '';
    $g_occupation    = trim($_POST['guardian_occupation'] ?? '');
    $g_contact       = trim($_POST['guardian_contact_number'] ?? '');
    $g_same_address  = isset($_POST['guardian_same_address']) ? 1 : 0;
    $g_address       = $g_same_address ? '' : trim($_POST['guardian_address'] ?? '');

    $allowed_relationships = ['Parent', 'Relative', 'Legal Guardian', 'Other'];
    if (!in_array($g_relationship, $allowed_relationships, true)) {
        $g_relationship = 'Other';
    }

    $name_pattern = '/^[A-Za-z\s\'\-]+$/';
    if ($g_family === '' || $g_first === '') {
        $error = 'Guardian family name and first name are required.';
        $active_tab = 'guardian';
    } elseif (!preg_match($name_pattern, $g_family) || !preg_match($name_pattern, $g_first)) {
        $error = 'Guardian family name and first name can only contain letters.';
        $active_tab = 'guardian';
    } elseif ($g_middle !== '' && !preg_match($name_pattern, $g_middle)) {
        $error = 'Guardian middle name can only contain letters.';
        $active_tab = 'guardian';
    } elseif ($g_suffix !== '' && !preg_match('/^[A-Za-z0-9.\s]+$/', $g_suffix)) {
        $error = 'Guardian suffix can only contain letters, numbers, and periods.';
        $active_tab = 'guardian';
    } elseif ($g_contact !== '' && !preg_match('/^09\d{9}$/', $g_contact)) {
        $error = 'Guardian contact number must be in the format 09XXXXXXXXX.';
        $active_tab = 'guardian';
    } elseif (!$g_same_address && $g_address === '') {
        $error = 'Please enter the guardian\'s address, or check "same address as student."';
        $active_tab = 'guardian';
    } else {
        $check = $conn->prepare("SELECT guardian_id FROM student_guardians WHERE student_id = ?");
        $check->bind_param('i', $student_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $stmt = $conn->prepare("UPDATE student_guardians SET family_name = ?, first_name = ?, middle_name = ?, suffix = ?, relationship = ?, occupation = ?, contact_number = ?, same_address_as_student = ?, full_address = ? WHERE student_id = ?");
            $stmt->bind_param('sssssssisi', $g_family, $g_first, $g_middle, $g_suffix, $g_relationship, $g_occupation, $g_contact, $g_same_address, $g_address, $student_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO student_guardians (student_id, family_name, first_name, middle_name, suffix, relationship, occupation, contact_number, same_address_as_student, full_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssssis', $student_id, $g_family, $g_first, $g_middle, $g_suffix, $g_relationship, $g_occupation, $g_contact, $g_same_address, $g_address);
        }
        $stmt->execute();
        $stmt->close();

        $log = $conn->prepare("INSERT INTO student_activity_log (student_id, activity_type, ip_address) VALUES (?, 'guardian_update', ?)");
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $log->bind_param('is', $student_id, $ip);
        $log->execute();
        $log->close();

        header("Location: student_profile?saved=guardian&tab=guardian$portal_qs"); exit();
    }
}

// ── Change username ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_username'])) {
    $new_username = trim($_POST['new_username'] ?? '');
    $current      = $_POST['current_password_username'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash FROM users_student WHERE user_student_id = ?");
    $stmt->bind_param('i', $user_student_id);
    $stmt->execute();
    $stmt->bind_result($current_hash);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current, $current_hash)) {
        $error = 'Your current password is incorrect.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $new_username)) {
        $error = 'Username must be 3-50 characters and can only contain letters, numbers, underscores, and dots.';
    } else {
        $check = $conn->prepare("SELECT user_student_id FROM users_student WHERE username = ? AND user_student_id != ?");
        $check->bind_param('si', $new_username, $user_student_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Username \"$new_username\" is already taken.";
        } else {
            $upd = $conn->prepare("UPDATE users_student SET username = ? WHERE user_student_id = ?");
            $upd->bind_param('si', $new_username, $user_student_id);
            $upd->execute();
            $upd->close();

            $_SESSION['username'] = $new_username;

            $log = $conn->prepare("INSERT INTO student_activity_log (student_id, activity_type, ip_address) VALUES (?, 'username_change', ?)");
            $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
            $log->bind_param('is', $student_id, $ip);
            $log->execute();
            $log->close();

            header("Location: student_profile?saved=username&tab=login$portal_qs"); exit();
        }
        $check->close();
    }
}

// ── Change password ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash FROM users_student WHERE user_student_id = ?");
    $stmt->bind_param('i', $user_student_id);
    $stmt->execute();
    $stmt->bind_result($current_hash);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current, $current_hash)) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $new_hash = password_hash($new, PASSWORD_BCRYPT);
        $upd = $conn->prepare("UPDATE users_student SET password_hash = ? WHERE user_student_id = ?");
        $upd->bind_param('si', $new_hash, $user_student_id);
        $upd->execute();
        $upd->close();

        $log = $conn->prepare("INSERT INTO student_activity_log (student_id, activity_type, ip_address) VALUES (?, 'password_change', ?)");
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $log->bind_param('is', $student_id, $ip);
        $log->execute();
        $log->close();

        header("Location: student_profile?saved=password&tab=login$portal_qs"); exit();
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === 'contact')  { $success = 'Contact information updated.'; }
if (isset($_GET['saved']) && $_GET['saved'] === 'guardian') { $success = 'Guardian information updated.'; }
if (isset($_GET['saved']) && $_GET['saved'] === 'password') { $success = 'Password changed successfully.'; }
if (isset($_GET['saved']) && $_GET['saved'] === 'username') { $success = 'Username changed successfully.'; }
if (isset($_GET['saved']) && $_GET['saved'] === 'photo')         { $success = 'Profile photo updated.'; }
if (isset($_GET['saved']) && $_GET['saved'] === 'photo_removed') { $success = 'Profile photo removed.'; }

// Query real columns from the `students` table, plus photo_path (lives on
// `users_student`, one login account per student — see student_photo.php
// for the same join key) and address_line (lives on `student_addresses`,
// still just the one free-text line collected at admission — see the
// needs_update flag there for the barangay/city/province backfill).
$stmt = $conn->prepare("
    SELECT s.family_name, s.given_name, s.middle_name, s.suffix, s.student_number, s.sex, s.date_of_birth,
           s.email, s.contact_number, sa.address_line AS full_address, us.photo_path
    FROM students s
    JOIN users_student us ON us.student_id = s.student_id
    LEFT JOIN student_addresses sa ON sa.student_id = s.student_id
    WHERE s.student_id = ?
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Course/strand line for the left card comes from the student's most recent
// enrollment record (admission_strand -> strands, section_id -> sections).
$enroll_stmt = $conn->prepare("
    SELECT e.admission_grade_level, st.strand_code, sec.section_name
    FROM enrollments e
    LEFT JOIN strands st  ON st.strand_id = e.admission_strand
    LEFT JOIN sections sec ON sec.section_id = e.section_id
    WHERE e.student_id = ?
    ORDER BY e.enrollment_date DESC, e.enrollment_id DESC
    LIMIT 1
");
$enroll_stmt->bind_param('i', $student_id);
$enroll_stmt->execute();
$enrollment = $enroll_stmt->get_result()->fetch_assoc();
$enroll_stmt->close();

// Guardian info lives in its own one-row-per-student `student_guardians` table.
$g_stmt = $conn->prepare("
    SELECT guardian_id, family_name, first_name, middle_name, suffix, relationship,
           occupation, contact_number, same_address_as_student, full_address
    FROM student_guardians WHERE student_id = ?
");
$g_stmt->bind_param('i', $student_id);
$g_stmt->execute();
$guardian = $g_stmt->get_result()->fetch_assoc() ?: [];
$g_stmt->close();

$act_stmt = $conn->prepare("
    SELECT activity_type, ip_address, created_at
    FROM student_activity_log
    WHERE student_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$act_stmt->bind_param('i', $student_id);
$act_stmt->execute();
$activity = $act_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$act_stmt->close();

$activity_labels = [
    'login'            => 'Logged in',
    'password_change'  => 'Changed password',
    'profile_update'   => 'Updated contact info',
    'guardian_update'  => 'Updated guardian info',
    'username_change'  => 'Changed username',
    'password_reset_requested' => 'Requested a password reset link',
    'password_reset'   => 'Reset password via email link',
];

$initials = strtoupper(mb_substr($student['given_name'] ?? '?', 0, 1) . mb_substr($student['family_name'] ?? '', 0, 1));
$course_line = '';
if ($enrollment) {
    $course_line = trim(
        ($enrollment['strand_code'] ?? '') .
        (!empty($enrollment['admission_grade_level']) ? ' • Grade ' . $enrollment['admission_grade_level'] : '') .
        (!empty($enrollment['section_name']) ? ' - ' . $enrollment['section_name'] : '')
    );
}
if ($course_line === '') { $course_line = 'Senior High School'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — SHS Enrollment</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_student.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_student.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/css_lms.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/css_lms.css') ?>">
</head>
<body class="student-layout<?= $is_lms_mode ? ' lms-layout lms-warm-bg' : '' ?>">
  <?php include_once $is_lms_mode ? BASE_PATH . '/shared/includes/lms_navbar.php' : BASE_PATH . '/shared/includes/student_sidebar.php'; ?>

  <div class="student-main">
    <div class="student-topbar">
      <div class="student-topbar-left">
        <div class="student-topbar-title">
          My Profile
          <span class="student-topbar-subtitle">Account details and activity</span>
        </div>
      </div>
      <?php if (!$is_lms_mode): ?>
        <?php include BASE_PATH . '/shared/includes/student_topbar_right.php'; ?>
      <?php endif; ?>
    </div>

    <div class="student-content">

      <?php if ($success): ?><div class="notice notice-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if ($error):   ?><div class="notice notice-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="account-layout">

        <!-- ── Left: avatar, name, course, and section nav ────────────────── -->
        <div class="account-nav-card">
          <div class="account-avatar-wrap">
            <?php if (!empty($student['photo_path'])): ?>
              <img class="account-avatar" src="student_photo?v=<?= urlencode($student['photo_path']) ?><?= $portal_qs ?>" alt="" style="object-fit:cover;">
            <?php else: ?>
              <div class="account-avatar"><?= htmlspecialchars($initials) ?></div>
            <?php endif; ?>
            <button type="button" class="account-avatar-edit" aria-label="Change photo" title="Change photo" onclick="document.getElementById('photoFileInput').click()">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536M9 11l6.586-6.586a2 2 0 112.828 2.828L11.828 13.828a4 4 0 01-1.897 1.06l-2.65.756.755-2.649a4 4 0 011.06-1.897L9 11z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 15v3a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h3"/></svg>
            </button>
            <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
              <input type="file" name="photo" id="photoFileInput" accept="image/jpeg,image/png" style="display:none;" onchange="document.getElementById('photoUploadForm').submit()">
              <input type="hidden" name="upload_photo" value="1">
              <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
            </form>
          </div>
          <?php if (!empty($student['photo_path'])): ?>
            <form method="POST" id="photoRemoveForm" style="margin:8px 0 4px;">
              <input type="hidden" name="remove_photo" value="1">
              <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
              <button type="submit" class="account-avatar-remove-link" data-confirm="Remove your profile photo?" data-icon="warning">Remove photo</button>
            </form>
          <?php endif; ?>
          <div class="account-name"><?= htmlspecialchars(trim($student['given_name'] . ' ' . $student['family_name'])) ?></div>
          <div class="account-course"><?= htmlspecialchars($course_line) ?></div>

          <nav class="account-nav">
            <a class="account-nav-link<?= $active_tab === 'contact' ? ' active' : '' ?>" href="?tab=contact<?= $portal_qs ?>" data-tab="contact">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              Contact Information
            </a>
            <?php if (!$is_lms_mode): ?>
            <a class="account-nav-link<?= $active_tab === 'guardian' ? ' active' : '' ?>" href="?tab=guardian<?= $portal_qs ?>" data-tab="guardian">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
              Guardian Information
            </a>
            <?php endif; ?>
            <a class="account-nav-link<?= $active_tab === 'login' ? ' active' : '' ?>" href="?tab=login<?= $portal_qs ?>" data-tab="login">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Login &amp; Password
            </a>
          </nav>
        </div>

        <!-- ── Right: tab panels ───────────────────────────────────────────── -->
        <div class="account-tab-content">

        <div class="account-tab-panel<?= $active_tab === 'contact' ? ' active' : '' ?>" data-tab-panel="contact">

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                <div class="student-panel-title">Student Information</div>
              </div>
            </div>
            <div class="student-stat-grid">
              <div class="student-stat-card">
                <div class="student-stat-body">
                  <div class="student-stat-label">Name</div>
                  <div class="student-stat-value"><?= htmlspecialchars(trim($student['given_name'] . ' ' . $student['middle_name'] . ' ' . $student['family_name'] . ' ' . $student['suffix'])) ?></div>
                </div>
              </div>
              <div class="student-stat-card">
                <div class="student-stat-body">
                  <div class="student-stat-label">Student Number</div>
                  <div class="student-stat-value"><?= htmlspecialchars($student['student_number'] ?? 'Not yet assigned') ?></div>
                </div>
              </div>
              <div class="student-stat-card">
                <div class="student-stat-body">
                  <div class="student-stat-label">Sex</div>
                  <div class="student-stat-value"><?= htmlspecialchars($student['sex']) ?></div>
                </div>
              </div>
              <div class="student-stat-card">
                <div class="student-stat-body">
                  <div class="student-stat-label">Date of Birth</div>
                  <div class="student-stat-value"><?= htmlspecialchars(date('M j, Y', strtotime($student['date_of_birth']))) ?></div>
                </div>
              </div>
            </div>
            <p class="field-hint" style="margin-top:.9rem;">Name, Student Number, and birth details are verified by school staff and can't be edited here. Contact the Records office if any of this needs correction.</p>
          </div>

          <div class="student-panel-block" style="margin-top:1.5rem;">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></span>
                <div class="student-panel-title">Contact Information</div>
              </div>
            </div>
            <form method="POST" action="student_profile" class="student-form" data-confirm="Save your updated contact information?" data-icon="question">
          <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
          <div class="student-form-row">
            <div>
              <label for="email">Email Address</label>
              <input type="email" id="email" name="email" maxlength="100" value="<?= htmlspecialchars($student['email'] ?? '') ?>" placeholder="you@example.com">
            </div>
            <div>
              <label for="contact_number">Contact Number</label>
              <input type="text" id="contact_number" name="contact_number" data-restrict="digits" pattern="09\d{9}" maxlength="11" title="Format: 09XXXXXXXXX" value="<?= htmlspecialchars($student['contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
            </div>
          </div>
          <label for="full_address">Address</label>
          <input type="text" id="full_address" name="full_address" maxlength="150" value="<?= htmlspecialchars($student['full_address'] ?? '') ?>" placeholder="House No., Street, Barangay, City">

              <button type="submit" name="update_contact" class="btn-student-primary">Save Contact Info</button>
            </form>
          </div>

        </div><!-- /panel: contact -->

        <?php if (!$is_lms_mode): ?>
        <div class="account-tab-panel<?= $active_tab === 'guardian' ? ' active' : '' ?>" data-tab-panel="guardian">

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></span>
                <div class="student-panel-title">Guardian Information</div>
              </div>
            </div>
            <form method="POST" action="student_profile" class="student-form" data-confirm="Save your updated guardian information?" data-icon="question">
              <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
              <div class="student-form-row">
                <div>
                  <label for="guardian_family_name">Family Name</label>
                  <input type="text" id="guardian_family_name" name="guardian_family_name" required pattern="[A-Za-z\s'\-]+" title="Letters only" data-restrict="letters" value="<?= htmlspecialchars($guardian['family_name'] ?? '') ?>" placeholder="Dela Cruz">
                </div>
                <div>
                  <label for="guardian_first_name">First Name</label>
                  <input type="text" id="guardian_first_name" name="guardian_first_name" required pattern="[A-Za-z\s'\-]+" title="Letters only" data-restrict="letters" value="<?= htmlspecialchars($guardian['first_name'] ?? '') ?>" placeholder="Juan">
                </div>
              </div>
              <div class="student-form-row">
                <div>
                  <label for="guardian_middle_name">Middle Name</label>
                  <input type="text" id="guardian_middle_name" name="guardian_middle_name" pattern="[A-Za-z\s'\-]+" title="Letters only" data-restrict="letters" value="<?= htmlspecialchars($guardian['middle_name'] ?? '') ?>" placeholder="Optional">
                </div>
                <div>
                  <label for="guardian_suffix">Suffix</label>
                  <input type="text" id="guardian_suffix" name="guardian_suffix" pattern="[A-Za-z0-9.\s]+" title="Letters, numbers, and periods only" data-restrict="name-suffix" value="<?= htmlspecialchars($guardian['suffix'] ?? '') ?>" placeholder="Jr., Sr., III, etc. (optional)">
                </div>
              </div>
              <div class="student-form-row">
                <div>
                  <label for="guardian_relationship">Relationship to Student</label>
                  <?php $rel = $guardian['relationship'] ?? ''; ?>
                  <select id="guardian_relationship" name="guardian_relationship">
                    <option value="Parent" <?= $rel === 'Parent' ? 'selected' : '' ?>>Parent</option>
                    <option value="Relative" <?= $rel === 'Relative' ? 'selected' : '' ?>>Relative</option>
                    <option value="Legal Guardian" <?= $rel === 'Legal Guardian' ? 'selected' : '' ?>>Legal Guardian</option>
                    <option value="Other" <?= $rel === 'Other' || $rel === '' ? 'selected' : '' ?>>Other</option>
                  </select>
                </div>
                <div>
                  <label for="guardian_occupation">Occupation</label>
                  <input type="text" id="guardian_occupation" name="guardian_occupation" value="<?= htmlspecialchars($guardian['occupation'] ?? '') ?>" placeholder="Optional">
                </div>
              </div>
              <label for="guardian_contact_number">Contact Number</label>
              <input type="text" id="guardian_contact_number" name="guardian_contact_number" data-restrict="digits" pattern="09\d{9}" maxlength="11" title="Format: 09XXXXXXXXX" value="<?= htmlspecialchars($guardian['contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX">

              <label class="checkbox-row" style="margin-top:.9rem;">
                <input type="checkbox" id="guardian_same_address" name="guardian_same_address" value="1" <?= !empty($guardian['same_address_as_student']) ? 'checked' : '' ?>>
                Same address as student
              </label>

              <div id="guardian-address-wrap" style="<?= !empty($guardian['same_address_as_student']) ? 'display:none;' : '' ?>">
                <label for="guardian_address">Guardian Address</label>
                <input type="text" id="guardian_address" name="guardian_address" maxlength="150" <?= empty($guardian['same_address_as_student']) ? 'required' : '' ?> value="<?= htmlspecialchars($guardian['full_address'] ?? '') ?>" placeholder="House No., Street, Barangay, City">
              </div>

              <button type="submit" name="update_guardian" class="btn-student-primary">Save Guardian Info</button>
            </form>
          </div>

          <script>
            (function () {
              var chk = document.getElementById('guardian_same_address');
              var wrap = document.getElementById('guardian-address-wrap');
              var addressInput = document.getElementById('guardian_address');
              if (chk && wrap) {
                chk.addEventListener('change', function () {
                  wrap.style.display = chk.checked ? 'none' : '';
                  if (addressInput) {
                    if (chk.checked) { addressInput.removeAttribute('required'); }
                    else { addressInput.setAttribute('required', 'required'); }
                  }
                });
              }
            })();
          </script>

        </div><!-- /panel: guardian -->
        <?php endif; ?>

        <div class="account-tab-panel<?= $active_tab === 'login' ? ' active' : '' ?>" data-tab-panel="login">

          <div class="student-panel-block">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></span>
            <div class="student-panel-title">Change Username</div>
          </div>
        </div>
        <p class="field-hint" style="margin-bottom:.9rem;">Current username: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></p>
        <form method="POST" action="student_profile" class="student-form" data-confirm="Change your username now?" data-icon="question">
          <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
          <div class="student-form-row">
            <div>
              <label for="new_username">New Username</label>
              <input type="text" id="new_username" name="new_username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.]{3,50}" placeholder="e.g. juandelacruz">
            </div>
            <div>
              <label for="current_password_username">Current Password</label>
              <div class="pw-field">
                <input type="password" id="current_password_username" name="current_password_username" required autocomplete="current-password">
                <button type="button" class="btn-eye-toggle" data-target="current_password_username" aria-label="Show password">
                  <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
                  <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.774 3.162 10.066 7.5a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                </button>
              </div>
            </div>
          </div>

              <button type="submit" name="change_username" class="btn-student-primary">Change Username</button>
            </form>
          </div>

          <div class="student-panel-block" style="margin-top:1.5rem;">
        <div class="student-panel-header">
          <div class="student-panel-header-left">
            <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
            <div class="student-panel-title">Change Password</div>
          </div>
        </div>
        <form method="POST" action="student_profile" class="student-form" data-confirm="Change your password now?" data-icon="question">
          <?php if ($is_lms_mode): ?><input type="hidden" name="portal" value="lms"><?php endif; ?>
          <div class="student-form-row">
            <div>
              <label for="current_password">Current Password</label>
              <div class="pw-field">
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                <button type="button" class="btn-eye-toggle" data-target="current_password" aria-label="Show password">
                  <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>
                  <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.774 3.162 10.066 7.5a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                </button>
              </div>
            </div>
            <div></div>
          </div>
          <div class="student-form-row">
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

              <button type="submit" name="change_password" class="btn-student-primary">Change Password</button>
            </form>
          </div>

          <div class="student-panel-block" style="margin-top:1.5rem;">
            <div class="student-panel-header">
              <div class="student-panel-header-left">
                <span class="student-panel-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                <div class="student-panel-title">Recent Activity</div>
              </div>
            </div>
            <?php if (empty($activity)): ?>
              <p class="empty-state">No activity recorded yet.</p>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr><th>Activity</th><th>IP Address</th><th>When</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($activity as $a): ?>
                  <tr>
                    <td class="td-name"><?= htmlspecialchars($activity_labels[$a['activity_type']] ?? $a['activity_type']) ?></td>
                    <td class="td-meta"><?= htmlspecialchars($a['ip_address'] ?? '—') ?></td>
                    <td class="td-meta"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($a['created_at']))) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
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

    // Client-side tab switching for the account nav — no reload needed when
    // just browsing sections. Direct links (?tab=...) still work for
    // bookmarking and for post-save redirects, since PHP sets the initial
    // active tab server-side too.
    document.querySelectorAll('.account-nav-link[data-tab]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var tab = link.dataset.tab;

        document.querySelectorAll('.account-nav-link[data-tab]').forEach(function (l) {
          l.classList.toggle('active', l.dataset.tab === tab);
        });
        document.querySelectorAll('.account-tab-panel').forEach(function (p) {
          p.classList.toggle('active', p.dataset.tabPanel === tab);
        });

        var tabQs = new URLSearchParams(location.search);
        tabQs.set('tab', tab);
        history.replaceState(null, '', '?' + tabQs.toString());
      });
    });

    // Real-time input filtering — a UX convenience on top of the
    // pattern/required attributes and server-side checks, which stay the
    // actual enforcement boundary (a pasted value or a direct POST still
    // has to pass those). keydown blocks the disallowed key outright (so
    // it never appears at all, not even for an instant); the input
    // listener is a fallback that strips anything that still got in via
    // paste, drag-drop, or autofill.
    var RESTRICT_PATTERNS = {
      letters:       { allowKey: /[A-Za-z\s'\-]/,   strip: /[^A-Za-z\s'\-]/g },
      'name-suffix': { allowKey: /[A-Za-z0-9.\s]/,  strip: /[^A-Za-z0-9.\s]/g },
      digits:        { allowKey: /[0-9]/,           strip: /[^0-9]/g }
    };
    document.querySelectorAll('[data-restrict]').forEach(function (input) {
      var rule = RESTRICT_PATTERNS[input.dataset.restrict];
      if (!rule) return;
      input.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return; // allow copy/paste/select-all shortcuts
        if (e.key.length > 1) return; // allow Backspace, Delete, arrows, Tab, etc.
        if (!rule.allowKey.test(e.key)) e.preventDefault();
      });
      input.addEventListener('input', function () {
        var cleaned = input.value.replace(rule.strip, '');
        if (cleaned !== input.value) input.value = cleaned;
      });
    });
  </script>

</body>
</html>